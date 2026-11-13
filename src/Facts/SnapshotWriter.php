<?php

declare(strict_types=1);

namespace Waynestate\Squire\Facts;

use Waynestate\Squire\ToolError;

/**
 * Builds the .squire/facts.json snapshot from the CREED policy and the ADVISOR project data in vendor. Offline by
 * design: nothing here phones home, so a laptop and a CI runner stamp the same bytes for the same inputs. The
 * scheduled bot (a later slice) runs it and commits; CI only checks what landed.
 */
final class SnapshotWriter
{
    private const CREE_PACKAGE = 'waynestate/creed';

    private const ADVISOR_PACKAGE = 'waynestate/advisor';

    /** @var list<string> the project keys a snapshot keeps, in order */
    private const PROJECT_KEYS = ['id', 'name', 'tagline', 'version', 'package'];

    private function __construct() {}

    /**
     * The full snapshot text for the given sources, ready to write. A missing or broken source is a ToolError
     * naming the path, because the fix is a composer require, not a guess.
     */
    public static function build(string $from, string $today, string $generator): string
    {
        $policyPath = $from . '/creed/policy/policy.json';
        $policy = self::readJson($policyPath);
        $versions = $policy['components']['php']['versions'] ?? null;
        if (!is_array($versions)) {
            throw new ToolError(sprintf('%s: components.php.versions must be an object.', $policyPath));
        }

        $supported = [];
        foreach ($versions as $version => $entry) {
            if (!is_array($entry)) {
                throw new ToolError(sprintf('%s: components.php.versions.%s must be an object.', $policyPath, $version));
            }
            $until = $entry['security_until'] ?? null;
            if (!is_string($until) || $until === '') {
                throw new ToolError(sprintf('%s: components.php.versions.%s needs a security_until date.', $policyPath, $version));
            }
            if ($until < $today) {
                continue;
            }
            $row = ['version' => (string) $version, 'security_until' => $until];
            $image = $entry['images']['docker'] ?? null;
            if (is_string($image) && $image !== '') {
                $row['image'] = $image;
            }
            $supported[] = $row;
        }
        usort($supported, static fn(array $a, array $b): int => version_compare($a['version'], $b['version']) ?: strcmp($a['version'], $b['version']));

        $projectsPath = $from . '/advisor/data/projects.json';
        $projectsData = self::readJson($projectsPath);
        $list = $projectsData['projects'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw new ToolError(sprintf('%s: projects must be a list.', $projectsPath));
        }

        $projects = [];
        $pin = '';
        foreach ($list as $project) {
            if (!is_array($project)) {
                throw new ToolError(sprintf('%s: every project must be an object.', $projectsPath));
            }
            $row = [];
            foreach (self::PROJECT_KEYS as $key) {
                $value = $project[$key] ?? '';
                $row[$key] = is_scalar($value) ? (string) $value : '';
            }
            $projects[] = $row;
            if ($pin === '' && $row['id'] === 'preflight') {
                $pin = $row['version'];
            }
        }

        $facts = [
            'projects' => $projects,
            'preflight_pin' => $pin,
            'php_supported' => $supported,
        ];

        $snapshot = [
            'schema_version' => 1,
            'generated_on' => $today,
            'generator' => $generator,
            'sources' => [
                'creed' => [
                    'package' => self::CREE_PACKAGE,
                    'version' => self::installedVersion($from, self::CREE_PACKAGE),
                    'path' => 'policy/policy.json',
                ],
                'advisor' => [
                    'package' => self::ADVISOR_PACKAGE,
                    'version' => self::installedVersion($from, self::ADVISOR_PACKAGE),
                    'path' => 'data/projects.json',
                ],
            ],
            'facts' => $facts,
            'regions' => Renderers::regions($facts),
        ];

        return json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * The version Composer recorded for the package, or "unknown" when installed.json or the entry is missing.
     * The file may be absent: a partial vendor is a state to report, not a crash.
     */
    private static function installedVersion(string $from, string $package): string
    {
        $path = dirname(rtrim($from, '/\\')) . '/composer/installed.json';
        if (!is_file($path)) {
            return 'unknown';
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return 'unknown';
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['packages'] ?? null)) {
            return 'unknown';
        }
        foreach ($data['packages'] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $package) {
                $version = $entry['version'] ?? null;

                return is_scalar($version) && (string) $version !== '' ? (string) $version : 'unknown';
            }
        }

        return 'unknown';
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('No such file: %s', $path));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ToolError(sprintf('Cannot read %s.', $path));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('%s is not valid JSON: %s', $path, json_last_error_msg()));
        }

        return $data;
    }
}

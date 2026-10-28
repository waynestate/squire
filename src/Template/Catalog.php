<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\ToolError;

/**
 * The list of files `squire init` writes. The files themselves live under templates/ and are real scripts, so
 * ShellCheck and PSScriptAnalyzer can lint them in CI as they are.
 */
final class Catalog
{
    public const TARGETS = ['raw', 'laravel'];

    public const GROUPS = [Template::GROUP_RELEASE, Template::GROUP_PARITY];

    // A Vue/Inertia app is a layer on raw or laravel, not a third target: the PHP app still deploys the same way, and
    // the export keeps its raw/ and laravel/ layout for Sourdough.
    public const LAYERS = [Template::LAYER_VUE];

    public function __construct(private readonly string $templatesDir) {}

    /**
     * Every template SQUIRE knows, for every target.
     *
     * @return list<Template>
     */
    public static function all(): array
    {
        $release = Template::GROUP_RELEASE;
        $parity = Template::GROUP_PARITY;

        return [
            new Template('build.sh', $release, true),
            new Template('deployments/001-first-release.sh', $release, true),
            new Template('scripts/release.sh', $release, true),
            new Template('health.php', $release),
            new Template('public/up.php', $release, false, 'raw'),

            new Template('docker-compose.squire.yml', $parity),
            new Template('.squire/Dockerfile', $parity),
            new Template('.squire/Dockerfile.dockerignore', $parity),
            new Template('.squire/base-fallback.Dockerfile', $parity),
            new Template('.squire/fallback/citadel-apache-entrypoint', $parity, true),
            new Template('.squire/fallback/citadel-deploy', $parity, true),
            new Template('.squire/haproxy.cfg', $parity),
            new Template('.squire/php.ini', $parity),
            new Template('.squire/vhost.conf', $parity),
            new Template('scripts/parity.sh', $parity, true),
            new Template('scripts/parity.ps1', $parity),
            new Template('.env.squire.example', $parity, false, 'raw'),
            new Template('.env.squire.example', $parity, false, 'laravel'),

            new Template('.npmrc', $release, false, null, Template::LAYER_VUE),
            new Template('docker-compose.squire.yml', $parity, false, null, Template::LAYER_VUE),
        ];
    }

    /**
     * The templates for one target and the chosen groups.
     *
     * @param list<string> $groups
     *
     * @return list<Template>
     */
    public function select(string $target, array $groups, ?string $layer = null): array
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new ToolError(sprintf('Unknown target "%s". Allowed values: %s.', $target, implode(', ', self::TARGETS)));
        }
        foreach ($groups as $group) {
            if (!in_array($group, self::GROUPS, true)) {
                throw new ToolError(sprintf('Unknown group "%s". Allowed values: %s.', $group, implode(', ', self::GROUPS)));
            }
        }

        if ($layer !== null && !in_array($layer, self::LAYERS, true)) {
            throw new ToolError(sprintf('Unknown layer "%s". Allowed values: %s.', $layer, implode(', ', self::LAYERS)));
        }

        $layerDestinations = [];
        if ($layer !== null) {
            foreach (self::all() as $template) {
                if ($template->layer === $layer && in_array($template->group, $groups, true)) {
                    $layerDestinations[$template->destination] = true;
                }
            }
        }

        $selected = [];
        foreach (self::all() as $template) {
            if ($template->layer !== null ? ($layer === null || $template->layer !== $layer) : ($template->target !== null && $template->target !== $target)) {
                continue;
            }
            if (!in_array($template->group, $groups, true)) {
                continue;
            }
            if ($layer !== null && $template->layer === null && isset($layerDestinations[$template->destination])) {
                continue; // the layer's copy of this file replaces the common one
            }
            $selected[] = $template;
        }

        return $selected;
    }

    /**
     * The templates for one layer and the chosen groups.
     *
     * @param list<string> $groups
     *
     * @return list<Template>
     */
    public function selectLayer(string $layer, array $groups): array
    {
        if (!in_array($layer, self::LAYERS, true)) {
            throw new ToolError(sprintf('Unknown layer "%s". Allowed values: %s.', $layer, implode(', ', self::LAYERS)));
        }
        foreach ($groups as $group) {
            if (!in_array($group, self::GROUPS, true)) {
                throw new ToolError(sprintf('Unknown group "%s". Allowed values: %s.', $group, implode(', ', self::GROUPS)));
            }
        }

        $selected = [];
        foreach (self::all() as $template) {
            if ($template->layer !== $layer) {
                continue;
            }
            if (!in_array($template->group, $groups, true)) {
                continue;
            }
            $selected[] = $template;
        }

        return $selected;
    }

    /**
     * The template's content, with LF line endings so a checkout with CRLF compares equal.
     */
    public function content(Template $template): string
    {
        $path = $this->templatesDir . '/' . $template->source();
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new ToolError(sprintf('Template file is missing from the package: %s', $template->source()));
        }

        return self::normalize($content);
    }

    public static function normalize(string $content): string
    {
        return str_replace("\r\n", "\n", $content);
    }

    /**
     * Guess the target from the app: a Laravel app has an artisan file.
     */
    public static function detectTarget(string $appPath): string
    {
        return is_file($appPath . '/artisan') ? 'laravel' : 'raw';
    }

    /**
     * Guess the layer from the app: a Vue app has vue in its package.json dependencies.
     */
    public static function detectLayer(string $appPath): ?string
    {
        $path = $appPath . '/package.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        foreach (['dependencies', 'devDependencies'] as $section) {
            $packages = $data[$section] ?? null;
            if (is_array($packages) && array_key_exists('vue', $packages)) {
                return Template::LAYER_VUE;
            }
        }

        return null;
    }
}

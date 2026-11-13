<?php

declare(strict_types=1);

namespace Waynestate\Squire\Facts;

/**
 * Renders the squire-facts regions from a facts array. Pure functions: same facts in, same bytes out, so the
 * snapshot writer and the tests lean on the same lines without either one touching a file.
 */
final class Renderers
{
    private function __construct() {}

    /**
     * The cast, one line per project: - **Name**: tagline. No newline after the last line, the way every region
     * body reads.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function castMd(array $facts): string
    {
        $lines = [];
        foreach (self::projects($facts) as $project) {
            $lines[] = '- **' . self::text($project['name']) . '**: ' . self::text($project['tagline']);
        }

        return implode("\n", $lines);
    }

    /**
     * The supported PHP lines, one per version: - PHP 8.3, security fixes until 2027-12-31 (image php:8.3-cli-trixie).
     * The image parenthetical appears only when the policy names one.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function phpSupportedMd(array $facts): string
    {
        $lines = [];
        foreach (self::phpSupported($facts) as $row) {
            $line = '- PHP ' . self::text($row['version']) . ', security fixes until ' . self::text($row['security_until']);
            $image = self::text($row['image'] ?? '');
            if ($image !== '') {
                $line .= ' (image ' . $image . ')';
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * The bare preflight version, for example 0.8.0; an empty string when there is no preflight project.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function preflightPin(array $facts): string
    {
        return self::text($facts['preflight_pin'] ?? '');
    }

    /**
     * The regions map for a facts array, sorted by key. Without a preflight project the preflight-pin region is
     * left out, the way the pin itself reads "".
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, string>
     */
    public static function regions(array $facts): array
    {
        $regions = [
            'cast-md' => self::castMd($facts),
            'php-supported-md' => self::phpSupportedMd($facts),
        ];
        if (self::preflightPin($facts) !== '') {
            $regions['preflight-pin'] = self::preflightPin($facts);
        }
        ksort($regions);

        return $regions;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private static function projects(array $facts): array
    {
        $projects = $facts['projects'] ?? [];

        return is_array($projects) ? array_values($projects) : [];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private static function phpSupported(array $facts): array
    {
        $rows = $facts['php_supported'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

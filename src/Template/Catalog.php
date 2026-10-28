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
        ];
    }

    /**
     * The templates for one target and the chosen groups.
     *
     * @param list<string> $groups
     *
     * @return list<Template>
     */
    public function select(string $target, array $groups): array
    {
        if (!in_array($target, self::TARGETS, true)) {
            throw new ToolError(sprintf('Unknown target "%s". Allowed values: %s.', $target, implode(', ', self::TARGETS)));
        }
        foreach ($groups as $group) {
            if (!in_array($group, self::GROUPS, true)) {
                throw new ToolError(sprintf('Unknown group "%s". Allowed values: %s.', $group, implode(', ', self::GROUPS)));
            }
        }

        $selected = [];
        foreach (self::all() as $template) {
            if ($template->target !== null && $template->target !== $target) {
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
}

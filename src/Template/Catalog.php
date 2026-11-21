<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * The list of files `squire init` writes. The files themselves live under templates/ and are real scripts, so
 * ShellCheck and PSScriptAnalyzer can lint them in CI as they are.
 */
final class Catalog
{
    public const TARGETS = ['raw', 'laravel', 'static'];

    /**
     * A target may inherit another target's files: static is a raw app with a front controller for a static site,
     * so it takes everything raw takes, and its own two files replace raw's front controller and .htaccess.
     * `--target=auto` never picks static; it must be asked for.
     */
    public const EXTENDS = ['static' => 'raw'];

    /** Every group Squire knows; `--only` may name any of them. */
    public const GROUPS = [Template::GROUP_RELEASE, Template::GROUP_IMAGES];

    /** The groups a fresh `squire init` writes when no manifest and no --only say otherwise: release only. Images are opt-in. */
    public const DEFAULT_GROUPS = [Template::GROUP_RELEASE];

    /**
     * The local-run kit's files, which left Squire in 0.12.0. Squire never deletes them: Parity reads them once
     * (`parity init --from-squire`), and then the owner deletes them. init and update report them, drop them from
     * the record, and doctor (S35) notes any that are still on disk. Like a squire returning borrowed armor: it
     * says where it goes, and lets the knight carry it there.
     */
    public const LEFTOVER_KIT_FILES = [
        'docker-compose.squire.yml',
        '.squire/haproxy.cfg',
        'scripts/parity.sh',
        'scripts/parity.ps1',
        '.env.squire.example',
    ];

    /**
     * The groups to work with, from `--only` or a record. A record that names the old `parity` group reads as
     * `images` (the image files it held stay with Squire). `--only=parity` is a usage error: the kit is gone.
     *
     * @param list<string> $groups
     *
     * @return list<string>
     */
    public static function resolveGroups(array $groups, bool $fromRecord): array
    {
        $resolved = [];
        foreach ($groups as $group) {
            if ($group === Template::LEGACY_GROUP_PARITY) {
                if (!$fromRecord) {
                    throw new ToolError(sprintf(
                        'The parity group left Squire in 0.12.0. Parity runs Citadel on your computer: parity init --from-squire, then parity up (%s). The image files are --only=images.',
                        Squire::PARITY_URL,
                    ));
                }
                $group = Template::GROUP_IMAGES;
            }
            if (!in_array($group, $resolved, true)) {
                $resolved[] = $group;
            }
        }

        return $resolved;
    }

    // A Vue/Inertia app is a layer on raw or laravel, not a third target: the PHP app still deploys the same way, and
    // the export keeps its raw/ and laravel/ layout for Sourdough.
    public const LAYERS = [Template::LAYER_VUE];

    public function __construct(private readonly string $templatesDir) {}

    /**
     * Every template Squire knows, for every target.
     *
     * @return list<Template>
     */
    public static function all(): array
    {
        $release = Template::GROUP_RELEASE;
        $images = Template::GROUP_IMAGES;

        return [
            new Template('build.sh', $release, true),
            new Template('deployments/001-first-release.sh', $release, true),
            new Template('scripts/release.sh', $release, true),
            new Template('health.php', $release),
            new Template('oracle-check.php', $release),
            // Line endings (CREED every-os-4, PROCTOR gitattributes-eol). An app with its own keeps it.
            new Template('.gitattributes', $release, false, null, appMayOwn: true),
            new Template('public/up.php', $release, false, 'raw'),
            new Template('public/index.php', $release, false, 'raw', appMayOwn: true),
            new Template('public/.htaccess', $release, false, 'raw', appMayOwn: true),
            new Template('public/index.php', $release, false, 'static'),
            new Template('public/.htaccess', $release, false, 'static'),

            // The image files: the mirror of the Dockerfile Citadel generates, the fallback base for when the real
            // image is out of reach, and the PHP settings both share. Opt-in (`--only=release,images`).
            new Template('.squire/Dockerfile', $images),
            new Template('.squire/Dockerfile.dockerignore', $images),
            new Template('.squire/base-fallback.Dockerfile', $images),
            new Template('.squire/apache-hardening.conf', $images),
            new Template('.squire/fallback/citadel-apache-entrypoint', $images, true),
            new Template('.squire/fallback/citadel-deploy', $images, true),
            new Template('.squire/php.ini', $images),
            new Template('.squire/php-web.ini', $images),
            new Template('.squire/vhost.conf', $images),
            // The portable image (ADR-0010): the same base, baked code, one image for web, worker, scheduler and
            // migrate, a non-root user in group 0 on 8080. Citadel never reads these files.
            new Template('.squire/portable.Dockerfile', $images),
            new Template('.squire/portable.Dockerfile.dockerignore', $images),
            new Template('.squire/portable/squire-entrypoint.sh', $images, true),
            new Template('.squire/portable/ports.conf', $images),
            new Template('.squire/portable/vhost.conf', $images),

            new Template('.npmrc', $release, false, null, Template::LAYER_VUE),
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

        $inherited = self::EXTENDS[$target] ?? null;
        $selected = [];
        foreach (self::all() as $template) {
            if ($template->layer !== null) {
                if ($layer === null || $template->layer !== $layer) {
                    continue;
                }
            } elseif ($template->target !== null && $template->target !== $target && $template->target !== $inherited) {
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

        // A target's own file replaces the one it inherits at the same path (static's front controller beats raw's).
        if ($inherited !== null) {
            $own = [];
            foreach ($selected as $template) {
                if ($template->target === $target) {
                    $own[$template->destination] = true;
                }
            }
            $selected = array_values(array_filter(
                $selected,
                static fn(Template $t): bool => $t->target !== $inherited || !isset($own[$t->destination]),
            ));
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
     * The template's content, with LF line endings so a checkout with CRLF compares equal. Every {{PHP_LINE}}
     * becomes the app's PHP line; the legacy default keeps callers that do not name one on the line they have.
     * {{PHP_FALLBACK_IMAGE}} becomes the official php image the line's Citadel image is built on (D-113), so the
     * fallback base follows the app's line: a line Squire has not pinned rides the pin of the known line with its
     * minor, and a minor Squire does not know is refused by name.
     */
    public function content(Template $template, string $phpLine = Squire::LEGACY_PHP_LINE): string
    {
        $path = $this->templatesDir . '/' . $template->source();
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new ToolError(sprintf('Template file is missing from the package: %s', $template->source()));
        }

        return str_replace(
            ['{{PHP_LINE}}', '{{PHP_FALLBACK_IMAGE}}'],
            [$phpLine, self::fallbackImage($phpLine)],
            self::normalize($content),
        );
    }

    /**
     * The fallback base image for a PHP line: the line's own pin when Squire has one, else the pin of the known
     * line with the same minor (8.5r4 rides 8.5r3's image). A minor Squire does not know is a tool error naming
     * the lines it does.
     */
    private static function fallbackImage(string $phpLine): string
    {
        if (isset(Squire::FALLBACK_PHP_IMAGES[$phpLine])) {
            return Squire::FALLBACK_PHP_IMAGES[$phpLine];
        }
        $minor = explode('r', $phpLine, 2)[0];
        foreach (Squire::FALLBACK_PHP_IMAGES as $line => $image) {
            if (explode('r', $line, 2)[0] === $minor) {
                return $image;
            }
        }

        throw new ToolError(sprintf(
            'No fallback base image for PHP line "%s". Squire knows: %s.',
            $phpLine,
            implode(', ', array_keys(Squire::FALLBACK_PHP_IMAGES)),
        ));
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

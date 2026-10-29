<?php

declare(strict_types=1);

namespace Waynestate\Squire;

use Composer\InstalledVersions;

/**
 * Names and versions shared by the CLI, the manifest and the reports.
 */
final class Squire
{
    public const TOOL = 'squire';

    /**
     * The version SQUIRE reports when it is not installed as a Composer package:
     * a dev checkout of this repository, or a copy without one. An installed
     * copy reports its real release version instead.
     */
    public const VERSION = '0.2.1';

    /** The package name SQUIRE publishes under. */
    private const PACKAGE = 'waynestate/squire';

    /** The file in an app that records which templates SQUIRE wrote, so `init --check` can report drift. */
    public const MANIFEST = '.squire.json';

    private function __construct() {}

    /** The absolute path of the bundled templates. */
    public static function templatesDir(): string
    {
        return dirname(__DIR__) . '/templates';
    }

    /**
     * The version SQUIRE prints and records: the installed package version
     * when SQUIRE runs as a Composer dependency, the fallback constant
     * otherwise.
     */
    public static function version(): string
    {
        $installed = self::installedVersion();

        return $installed ?? self::VERSION;
    }

    /**
     * The pretty version Composer recorded for this package, or null when
     * SQUIRE is not installed as a release: no entry at all (a phar or a
     * manual copy), or the placeholder Composer stamps on a version-less
     * root package (a dev checkout of this very repository). A leading "v"
     * is dropped, the way the release tags read.
     */
    private static function installedVersion(): ?string
    {
        try {
            // When this repository is itself the root package, Composer
            // reports a root version (1.0.0, dev-main) that is not a release.
            if (InstalledVersions::getRootPackage()['name'] === self::PACKAGE) {
                return null;
            }
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\Throwable) {
            return null;
        }
        if ($pretty === null || $pretty === '' || str_contains($pretty, 'no-version-set')) {
            return null;
        }

        return preg_replace('/^v/i', '', $pretty) ?? $pretty;
    }
}

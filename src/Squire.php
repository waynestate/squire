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
     * The version Squire reports when it is not installed as a Composer package:
     * a dev checkout of this repository, or a copy without one. An installed
     * copy reports its real release version instead.
     */
    public const VERSION = '0.12.2';

    /** The package name Squire publishes under. */
    private const PACKAGE = 'waynestate/squire';

    /** The file in an app that records which templates Squire wrote, so `init --check` can report drift. */
    public const MANIFEST = '.squire.json';

    /** The PHP line every app written before the {{PHP_LINE}} token ran on; a manifest without a php key means it. */
    public const LEGACY_PHP_LINE = '8.4r5';

    /** The PHP line new apps are written on. */
    public const DEFAULT_PHP_LINE = '8.5r3';

    /** the official php image each Citadel line is built on, for the fallback base. */
    public const FALLBACK_PHP_IMAGES = [
        '8.4r5' => 'docker.io/library/php:8.4.27-apache@sha256:884f64b47174de2c0e813270fe7b24a18605729fcebe201302d98c38805530d0',
        '8.5r3' => 'docker.io/library/php:8.5.12-apache@sha256:08af36df144462d84c7f16190557eec06cb9f656882685610b712a15eec54d46',
        '8.6r1' => 'docker.io/library/php:8.6.0-apache@sha256:0b06b358a7d59cca80e0528aad0e49b43208c3a74754ffcd8a12d7727058511e',
    ];

    /** Where Parity lives: the stack's own tool for running Citadel on your computer (ADR-0022). */
    public const PARITY_URL = 'https://git.wayne.edu/enterprise-apps/parity';

    /**
     * The one line `init` and `update` print when the app still has parity kit files. The kit was deprecated in
     * 0.10.5 and left in 0.12.0 (Adam, 2026-11-20, ahead of ADR-0022's 2027-01-08 date).
     */
    public const PARITY_POINTER = 'Parity: the parity kit left Squire in 0.12.0, and Squire deleted none of its files. Run `parity init --from-squire` (it carries the kit\'s settings over), then delete the kit-leftover files above: ' . self::PARITY_URL;

    private function __construct() {}

    /** The absolute path of the bundled templates. */
    public static function templatesDir(): string
    {
        return dirname(__DIR__) . '/templates';
    }

    /**
     * The version Squire prints and records: the installed package version
     * when Squire runs as a Composer dependency, the fallback constant
     * otherwise.
     */
    public static function version(): string
    {
        $installed = self::installedVersion();

        return $installed ?? self::VERSION;
    }

    /**
     * The pretty version Composer recorded for this package, or null when
     * Squire is not installed as a release: no entry at all (a phar or a
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

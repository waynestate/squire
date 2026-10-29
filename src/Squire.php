<?php

declare(strict_types=1);

namespace Waynestate\Squire;

/**
 * Names and versions shared by the CLI, the manifest and the reports.
 */
final class Squire
{
    public const TOOL = 'squire';

    public const VERSION = '0.2.0';

    /** The file in an app that records which templates SQUIRE wrote, so `init --check` can report drift. */
    public const MANIFEST = '.squire.json';

    private function __construct() {}

    /** The absolute path of the bundled templates. */
    public static function templatesDir(): string
    {
        return dirname(__DIR__) . '/templates';
    }
}

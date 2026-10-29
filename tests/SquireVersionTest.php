<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Squire\Squire;

final class SquireVersionTest extends TestCase
{
    public function testTheFallbackMatchesTheLatestChangelogRelease(): void
    {
        // The fallback is only right on the day of a release. A release that
        // forgets to bump it must fail here, not go stale silently in every
        // dev checkout report.
        $lines = file(__DIR__ . '/../CHANGELOG.md', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, 'CHANGELOG.md must be readable from the repository root');

        $latest = null;
        foreach ($lines as $line) {
            // The first heading carrying a semver release number is the
            // latest release; "## Unreleased" carries none, so it is skipped.
            if (preg_match('/^## \[?(\d+\.\d+\.\d+)\]?/', $line, $match) === 1) {
                $latest = $match[1];
                break;
            }
        }

        self::assertNotNull($latest, 'CHANGELOG.md must contain a release heading such as "## 0.2.0"');
        self::assertSame(
            Squire::VERSION,
            $latest,
            'Squire::VERSION must match the latest CHANGELOG release; bump it in the release commit.',
        );
    }
}

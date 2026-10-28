<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Template\Diff;

final class DiffTest extends SquireTestCase
{
    public function testEqualContentHasNoDiff(): void
    {
        self::assertSame('', Diff::unified("#!/bin/sh\necho hi\n", "#!/bin/sh\necho hi\n", 'a/x', 'b/x'));
    }

    public function testCrlfAndLfAreEqual(): void
    {
        self::assertSame('', Diff::unified("#!/bin/sh\r\necho hi\r\n", "#!/bin/sh\necho hi\n", 'a/x', 'b/x'));
    }

    public function testAChangedLineShowsMinusAndPlus(): void
    {
        $diff = Diff::unified("#!/bin/sh\n# version one\n", "#!/bin/sh\n# my edit\n", 'a/build.sh (template)', 'b/build.sh (on disk)');

        self::assertStringContainsString("--- a/build.sh (template)\n", $diff);
        self::assertStringContainsString("+++ b/build.sh (on disk)\n", $diff);
        self::assertStringContainsString('@@ -1,2 +1,2 @@', $diff);
        self::assertStringContainsString('-# version one', $diff);
        self::assertStringContainsString('+# my edit', $diff);
    }

    public function testAnInsertionIntoAnEmptyFile(): void
    {
        $diff = Diff::unified('', "a\n", 'a/x', 'b/x');

        self::assertStringContainsString('@@ -0,0 +1,1 @@', $diff);
        self::assertStringContainsString('+a', $diff);
    }

    public function testADeletionToAnEmptyFile(): void
    {
        $diff = Diff::unified("a\n", '', 'a/x', 'b/x');

        self::assertStringContainsString('@@ -1,1 +0,0 @@', $diff);
        self::assertStringContainsString('-a', $diff);
    }

    public function testContextIsThreeLines(): void
    {
        $old = implode("\n", range(1, 20)) . "\n";
        $new = str_replace("10\n", "TEN\n", $old);

        $diff = Diff::unified($old, $new, 'a/x', 'b/x');

        self::assertStringContainsString('@@ -7,7 +7,7 @@', $diff);
        self::assertStringContainsString('-10', $diff);
        self::assertStringContainsString('+TEN', $diff);
        self::assertStringNotContainsString("\n15", $diff, 'line 15 is outside the three-line context');
    }

    public function testTwoFarApartChangesGiveTwoHunks(): void
    {
        $lines = range(1, 30);
        $lines[4] = 'FIVE';
        $lines[24] = 'TWENTY-FIVE';
        $old = implode("\n", range(1, 30)) . "\n";
        $new = implode("\n", $lines) . "\n";

        $diff = Diff::unified($old, $new, 'a/x', 'b/x');

        self::assertSame(2, substr_count($diff, "\n@@ "));
        self::assertStringContainsString('-5', $diff);
        self::assertStringContainsString('+FIVE', $diff);
        self::assertStringContainsString('-25', $diff);
        self::assertStringContainsString('+TWENTY-FIVE', $diff);
    }

    public function testNearbyChangesMergeIntoOneHunk(): void
    {
        $old = implode("\n", range(1, 10)) . "\n";
        $new = str_replace(["2\n", "4\n"], ["TWO\n", "FOUR\n"], $old);

        $diff = Diff::unified($old, $new, 'a/x', 'b/x');

        self::assertSame(1, substr_count($diff, "\n@@ "));
    }
}

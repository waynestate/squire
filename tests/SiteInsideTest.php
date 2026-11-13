<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Console\SiteCommand;

/**
 * SiteCommand::inside(): resolves --out against --root, keeps a Windows drive-letter prefix on the result, and
 * refuses paths that leave the root or name it.
 */
final class SiteInsideTest extends SquireTestCase
{
    public function test_drive_letter_root_keeps_its_prefix(): void
    {
        self::assertSame('C:/work/site/public', SiteCommand::inside('C:/work/site', 'public'));
    }

    public function test_lowercase_drive_letter_root_keeps_its_prefix(): void
    {
        self::assertSame('c:/work/site/out/web', SiteCommand::inside('c:/work/site', 'out/web'));
    }

    public function test_absolute_root_keeps_its_slash(): void
    {
        self::assertSame('/srv/site/public', SiteCommand::inside('/srv/site', 'public'));
    }

    public function test_dotdot_climbing_to_the_root_is_null(): void
    {
        self::assertNull(SiteCommand::inside('C:/work/site', '..'));
    }

    public function test_dotdot_through_a_subfolder_is_null(): void
    {
        self::assertNull(SiteCommand::inside('C:/work/site', 'public/../..'));
    }

    public function test_the_root_itself_is_null(): void
    {
        self::assertNull(SiteCommand::inside('C:/work/site', '.'));
    }

    public function test_an_absolute_out_on_another_drive_is_null(): void
    {
        self::assertNull(SiteCommand::inside('C:/work/site', 'D:/elsewhere'));
    }

    public function test_an_absolute_out_is_null(): void
    {
        self::assertNull(SiteCommand::inside('/srv/site', '/etc'));
    }
}

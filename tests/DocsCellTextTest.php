<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use ReflectionClass;

/**
 * The docs builder's cellText: Symfony console style tags are stripped from table cells, other angle text stays.
 */
final class DocsCellTextTest extends SquireTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/bin/docs-site.php';
    }

    /** The private cellText result for the given value. */
    private function cellText(mixed $value): string
    {
        $class = new ReflectionClass(\DocsSite::class);
        $method = $class->getMethod('cellText');

        return (string) $method->invoke($class->newInstanceWithoutConstructor(), $value);
    }

    public function testInfoTagsAreStripped(): void
    {
        self::assertSame('list', $this->cellText('<info>list</info>'));
        self::assertSame('done now', $this->cellText('<info>done</info> <comment>now</comment>'));
        self::assertSame('failed badly?', $this->cellText('<error>failed</error> <question>badly?</question>'));
        self::assertSame('red bold', $this->cellText('<fg=red>red</> <fg=yellow;options=bold>bold</>'));
        self::assertSame('plain', $this->cellText('<info>plain'));
        self::assertSame('a \| b', $this->cellText('<info>a | b</info>'));
    }

    public function testOtherAngleTextIsKept(): void
    {
        self::assertSame('a < b and c > d', $this->cellText('a < b and c > d'));
        self::assertSame('use the <path> argument', $this->cellText('use the <path> argument'));
        self::assertSame('keep <b>bold</b>', $this->cellText('keep <b>bold</b>'));
        self::assertSame('x  y', $this->cellText('x </> y'));
    }
}

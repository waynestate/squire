<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Template;
use Waynestate\Squire\ToolError;

final class CatalogTest extends SquireTestCase
{
    public function testEveryTemplateFileInThePackageIsInTheCatalogAndTheOtherWayRound(): void
    {
        $onDisk = [];
        $root = Squire::templatesDir();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $onDisk[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            }
        }

        $inCatalog = array_map(static fn(Template $t): string => $t->source(), Catalog::all());

        sort($onDisk);
        sort($inCatalog);
        self::assertSame($onDisk, $inCatalog, 'templates/ and Catalog::all() must list the same files');
    }

    /** @return array<string, array{string}> */
    public static function targets(): array
    {
        return ['raw' => ['raw'], 'laravel' => ['laravel']];
    }

    #[DataProvider('targets')]
    public function testEachTargetGetsOneCopyOfEachDestination(string $target): void
    {
        $templates = (new Catalog(Squire::templatesDir()))->select($target, Catalog::GROUPS);
        $destinations = array_map(static fn(Template $t): string => $t->destination, $templates);

        self::assertSame($destinations, array_values(array_unique($destinations)));
        self::assertContains('build.sh', $destinations);
        self::assertContains('scripts/release.sh', $destinations);
        self::assertContains('deployments/001-first-release.sh', $destinations);
        self::assertContains('health.php', $destinations);
        self::assertContains('docker-compose.squire.yml', $destinations);
        self::assertContains('scripts/parity.sh', $destinations);
        self::assertContains('scripts/parity.ps1', $destinations);
    }

    public function testRawPhpGetsItsOwnHealthEndpointAndLaravelDoesNot(): void
    {
        $catalog = new Catalog(Squire::templatesDir());
        $raw = array_map(static fn(Template $t): string => $t->destination, $catalog->select('raw', Catalog::GROUPS));
        $laravel = array_map(static fn(Template $t): string => $t->destination, $catalog->select('laravel', Catalog::GROUPS));

        self::assertContains('public/up.php', $raw);
        self::assertNotContains('public/up.php', $laravel);
    }

    public function testTheGroupOptionNarrowsTheSelection(): void
    {
        $catalog = new Catalog(Squire::templatesDir());
        $release = array_map(static fn(Template $t): string => $t->destination, $catalog->select('raw', ['release']));

        self::assertContains('build.sh', $release);
        self::assertNotContains('docker-compose.squire.yml', $release);
    }

    public function testAnUnknownTargetOrGroupIsAToolError(): void
    {
        $catalog = new Catalog(Squire::templatesDir());

        $this->expectException(ToolError::class);
        $catalog->select('django', ['release']);
    }

    public function testAnUnknownGroupIsAToolError(): void
    {
        $this->expectException(ToolError::class);
        (new Catalog(Squire::templatesDir()))->select('raw', ['nonsense']);
    }

    public function testContentIsReturnedWithLfLineEndings(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/common');
        file_put_contents($dir . '/common/build.sh', "a\r\nb\r\n");

        self::assertSame("a\nb\n", (new Catalog($dir))->content(new Template('build.sh', 'release')));
    }

    public function testTheTargetIsDetectedFromTheArtisanFile(): void
    {
        self::assertSame('raw', Catalog::detectTarget($this->app()));
        self::assertSame('laravel', Catalog::detectTarget($this->app([], true)));
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * `squire facts --snapshot` (D-109, slice S2): the snapshot writer, fed by the CREED policy and the ADVISOR
 * project data in vendor. The fixture stands in for a vendor tree; the golden file stands in for the future.
 */
final class FactsSnapshotTest extends SquireTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/facts-snapshot';

    private const TODAY = '2026-11-11';

    /** A temp dir holding a copy of the fixture vendor tree, so --path points at the app and --from at its vendor. */
    private function vendorApp(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(self::FIXTURES, $dir);

        return $dir;
    }

    private function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
            $this->put($to, $relative, (string) file_get_contents($file->getPathname()));
        }
    }

    /** The snapshot the fixture vendor tree stamps for a given day, or null when the run did not write one. */
    private function snapshotData(string $dir): ?array
    {
        $raw = @file_get_contents($dir . '/.squire/facts.json');
        if ($raw === false) {
            return null;
        }
        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }

    public function testSnapshotMatchesTheGoldenFile(): void
    {
        $dir = $this->vendorApp();

        $result = $this->runSquire(['facts', '--snapshot', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('squire facts: wrote .squire/facts.json from creed 0.1.11 and advisor 0.3.1', $result['stdout']);
        $written = (string) file_get_contents($dir . '/.squire/facts.json');
        $normalized = str_replace('"generator": "squire facts ' . Squire::version() . '",', '"generator": "squire facts TEST",', $written);
        self::assertSame((string) file_get_contents(self::FIXTURES . '/expected-facts.json'), $normalized, 'byte for byte, generator aside');
    }

    public function testEndOfLifePhpIsLeftOut(): void
    {
        $dir = $this->vendorApp();

        $result = $this->runSquire(['facts', '--snapshot', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        $data = $this->snapshotData($dir);
        self::assertIsArray($data);
        self::assertSame(['8.3', '8.4'], array_column($data['facts']['php_supported'], 'version'));
        self::assertStringNotContainsString('8.1', $data['regions']['php-supported-md']);
    }

    public function testRegionsAreFilledAfterTheSnapshot(): void
    {
        $dir = $this->vendorApp();
        $this->put($dir, 'README.md', "# App\n\nThe cast:\n<!-- squire-facts:cast-md:start -->\n- Stale entry\n<!-- squire-facts:cast-md:end -->\n");

        $result = $this->runSquire(['facts', '--snapshot', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('squire facts: filled 1 region(s) in 1 file(s)', $result['stdout']);
        $readme = (string) file_get_contents($dir . '/README.md');
        self::assertStringContainsString(
            "- **WayneUI**: The interface layer every other project builds on.\n- **CREED**: The standards handbook of the WSU app stack.\n- **Preflight**: CI templates that fly on any GitLab.",
            $readme,
        );
    }

    public function testSnapshotCheckReportsAStaleFile(): void
    {
        $dir = $this->vendorApp();
        $stale = '{"schema_version":1,"generated_on":"2026-11-10","generator":"squire facts TEST","regions":{"cast-md":"- stale"}}' . "\n";
        $this->put($dir, '.squire/facts.json', $stale);

        $result = $this->runSquire(['facts', '--snapshot', '--check', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(1, $result['exit'], $result['stdout']);
        self::assertStringContainsString('.squire/facts.json is out of date (run vendor/bin/squire facts --snapshot)', $result['stdout']);
        self::assertSame($stale, (string) file_get_contents($dir . '/.squire/facts.json'), '--check writes nothing');
    }

    public function testSnapshotCheckPassesWhenCurrent(): void
    {
        $dir = $this->vendorApp();
        $first = $this->runSquire(['facts', '--snapshot', '--today=' . self::TODAY, '--path=' . $dir]);
        self::assertSame(0, $first['exit'], $first['stderr']);

        $result = $this->runSquire(['facts', '--snapshot', '--check', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertStringContainsString('squire facts: 0 region(s) in step', $result['stdout']);
    }

    public function testMissingPolicyIsAToolError(): void
    {
        $dir = $this->vendorApp();
        self::removeTree($dir . '/vendor/waynestate/creed');

        $result = $this->runSquire(['facts', '--snapshot', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('policy.json', $result['stderr']);
        self::assertNull($this->snapshotData($dir), 'a failed build writes nothing');
    }

    public function testMissingInstalledJsonGivesUnknown(): void
    {
        $dir = $this->vendorApp();
        unlink($dir . '/vendor/composer/installed.json');

        $result = $this->runSquire(['facts', '--snapshot', '--today=' . self::TODAY, '--path=' . $dir]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('squire facts: wrote .squire/facts.json from creed unknown and advisor unknown', $result['stdout']);
        $data = $this->snapshotData($dir);
        self::assertIsArray($data);
        self::assertSame('unknown', $data['sources']['creed']['version']);
        self::assertSame('unknown', $data['sources']['advisor']['version']);
    }

    public function testBadTodayIsAToolError(): void
    {
        $dir = $this->vendorApp();

        $result = $this->runSquire(['facts', '--snapshot', '--today=11/11/2026', '--path=' . $dir]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('--today', $result['stderr']);
        self::assertNull($this->snapshotData($dir), 'a bad date writes nothing');
    }
}

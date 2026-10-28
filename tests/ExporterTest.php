<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Exporter;

final class ExporterTest extends SquireTestCase
{
    private function exporter(): Exporter
    {
        return new Exporter(new Catalog(Squire::templatesDir()));
    }

    public function testExportThenCheckPasses(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        self::assertSame([], $this->exporter()->check($dir));
    }

    public function testCheckFailsNamingTheChangedFile(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        $path = $dir . '/raw/health.php';
        $content = (string) file_get_contents($path);
        self::assertNotSame('', $content);
        // Change exactly one byte.
        $content[0] = $content[0] === '<' ? '>' : '<';
        file_put_contents($path, $content);

        $drifted = $this->exporter()->check($dir);
        self::assertContains('raw/health.php', $drifted);
    }

    public function testCheckReportsAMissingFile(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);
        unlink($dir . '/laravel/build.sh');

        $drifted = $this->exporter()->check($dir);
        self::assertContains('laravel/build.sh (missing)', $drifted);
    }

    public function testCheckReportsAnUnexpectedFile(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);
        file_put_contents($dir . '/raw/notes.txt', "hand edit\n");

        $drifted = $this->exporter()->check($dir);
        self::assertContains('raw/notes.txt (unexpected)', $drifted);
    }

    public function testAnExecutableTemplateKeepsItsModeInTheManifest(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('files', $manifest);

        self::assertSame('755', $manifest['files']['raw/build.sh']['mode']);
        self::assertSame('644', $manifest['files']['raw/health.php']['mode']);
        self::assertSame('755', $manifest['files']['laravel/scripts/parity.sh']['mode']);
        self::assertArrayHasKey('sha256', $manifest['files']['raw/build.sh']);
    }

    public function testTheCatalogListsEachKindsFilesAndTargetPaths(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        $catalog = json_decode((string) file_get_contents($dir . '/catalog.json'), true);
        self::assertIsArray($catalog);
        self::assertArrayHasKey('raw', $catalog);
        self::assertArrayHasKey('laravel', $catalog);

        $rawPaths = array_column($catalog['raw'], 'path');
        $laravelPaths = array_column($catalog['laravel'], 'path');
        self::assertContains('public/up.php', $rawPaths);
        self::assertNotContains('public/up.php', $laravelPaths);
        self::assertContains('build.sh', $rawPaths);
        self::assertContains('build.sh', $laravelPaths);

        // Every listed file exists in the export, at its target path.
        foreach (['raw', 'laravel'] as $kind) {
            foreach ($catalog[$kind] as $entry) {
                self::assertFileExists($dir . '/' . $kind . '/' . $entry['path']);
            }
        }
    }

    public function testTheExportIncludesTheVueLayer(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        self::assertFileExists($dir . '/vue/.npmrc');
        self::assertStringContainsString('min-release-age=1', (string) file_get_contents($dir . '/vue/.npmrc'));

        $catalog = json_decode((string) file_get_contents($dir . '/catalog.json'), true);
        self::assertIsArray($catalog);
        self::assertArrayHasKey('vue', $catalog);
        self::assertSame(['.npmrc'], array_column($catalog['vue'], 'path'));

        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('vue/.npmrc', $manifest['files']);
        self::assertSame('644', $manifest['files']['vue/.npmrc']['mode']);
    }

    public function testTheManifestRecordsTheSquireVersion(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame($this->exporter()->version(), $manifest['version']);
        self::assertNotSame('', $manifest['version']);
    }

    public function testTheBinScriptExportsThenChecks(): void
    {
        $dir = $this->tempDir();
        $bin = dirname(__DIR__) . '/bin/export-templates.php';

        $export = $this->runScript($bin, ['--to=' . $dir]);
        self::assertSame(0, $export['exit'], $export['stdout'] . $export['stderr']);

        $ok = $this->runScript($bin, ['--to=' . $dir, '--check']);
        self::assertSame(0, $ok['exit'], $ok['stdout'] . $ok['stderr']);

        $path = $dir . '/raw/health.php';
        $content = (string) file_get_contents($path);
        $content[0] = $content[0] === '<' ? '>' : '<';
        file_put_contents($path, $content);

        $drift = $this->runScript($bin, ['--to=' . $dir, '--check']);
        self::assertSame(1, $drift['exit']);
        self::assertStringContainsString('raw/health.php', $drift['stdout'] . $drift['stderr']);
    }

    public function testTheBinScriptRequiresATo(): void
    {
        $bin = dirname(__DIR__) . '/bin/export-templates.php';

        $result = $this->runScript($bin, []);
        self::assertSame(2, $result['exit']);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runScript(string $bin, array $args): array
    {
        $cmd = [PHP_BINARY, $bin, ...$args];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

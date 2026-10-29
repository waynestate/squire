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
        self::assertSame('644', $manifest['files']['static/public/index.php']['mode']);
        self::assertSame('644', $manifest['files']['static/public/.htaccess']['mode']);
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
        self::assertArrayHasKey('static', $catalog);

        $rawPaths = array_column($catalog['raw'], 'path');
        $laravelPaths = array_column($catalog['laravel'], 'path');
        $staticPaths = array_column($catalog['static'], 'path');
        self::assertContains('public/up.php', $rawPaths);
        self::assertNotContains('public/up.php', $laravelPaths);
        self::assertContains('build.sh', $rawPaths);
        self::assertContains('build.sh', $laravelPaths);

        // Static is raw plus the front controller: every raw path, and only those two additions.
        foreach ($rawPaths as $path) {
            self::assertContains($path, $staticPaths);
        }
        self::assertSame(count($rawPaths) + 2, count($staticPaths));
        self::assertContains('public/index.php', $staticPaths);
        self::assertContains('public/.htaccess', $staticPaths);
        self::assertNotContains('public/index.php', $laravelPaths);

        // Every listed file exists in the export, at its target path.
        foreach (['raw', 'laravel', 'static'] as $kind) {
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
        self::assertFileDoesNotExist($dir . '/vue/scripts/release.sh');
        self::assertFileExists($dir . '/vue/docker-compose.squire.yml');
        self::assertStringContainsString('image: ${SQUIRE_NODE_IMAGE:-node:22-bookworm-slim}', (string) file_get_contents($dir . '/vue/docker-compose.squire.yml'));

        $catalog = json_decode((string) file_get_contents($dir . '/catalog.json'), true);
        self::assertIsArray($catalog);
        self::assertArrayHasKey('vue', $catalog);
        self::assertSame(['.npmrc', 'docker-compose.squire.yml'], array_column($catalog['vue'], 'path'));

        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('vue/.npmrc', $manifest['files']);
        self::assertArrayNotHasKey('vue/scripts/release.sh', $manifest['files']);
        self::assertArrayHasKey('vue/docker-compose.squire.yml', $manifest['files']);
        self::assertSame('644', $manifest['files']['vue/.npmrc']['mode']);
        self::assertSame('644', $manifest['files']['vue/docker-compose.squire.yml']['mode']);
    }

    public function testEveryTargetExportKeepsTheCommonReleaseAndComposeFiles(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        foreach (Catalog::TARGETS as $kind) {
            $release = (string) file_get_contents($dir . '/' . $kind . '/scripts/release.sh');
            $compose = (string) file_get_contents($dir . '/' . $kind . '/docker-compose.squire.yml');

            self::assertStringContainsString('step "frontend install and build"', $release, $kind);
            self::assertStringNotContainsString('node:22-bookworm-slim', $compose, $kind);
        }
    }

    public function testTheStaticExportCarriesTheFrontControllerForAStaticSite(): void
    {
        $dir = $this->tempDir();
        $this->exporter()->export($dir);

        $index = (string) file_get_contents($dir . '/static/public/index.php');
        self::assertStringContainsString("use function Waynestate\\Helmet\\helmet_boot;", $index);
        self::assertStringContainsString("getenv('SQUIRE_STATIC_ROOT')", $index);
        self::assertStringContainsString("'preset' => 'citadel'", $index);

        $htaccess = (string) file_get_contents($dir . '/static/public/.htaccess');
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringContainsString('Options -Indexes', $htaccess);
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

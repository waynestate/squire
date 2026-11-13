<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Site\Pin;
use Waynestate\Squire\Site\TagExport;
use Waynestate\Squire\ToolError;

/**
 * The site build's pin parsing and tag export (ADR-0023): site.json reads cleanly, and a pinned tag's full tree lands
 * in a folder with its export-ignore files (CHANGELOG.md, README.md) kept.
 */
final class SiteTagExportTest extends SquireTestCase
{
    public function test_pin_parses_library_and_tag(): void
    {
        $dir = $this->tempDir();
        $this->put($dir, 'site.json', json_encode([
            'library' => 'enterprise-apps/demo',
            'tag' => 'v1.2.0',
            'files' => ['index.html', 'assets/site.css'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $pin = Pin::fromFile($dir . '/site.json');
        self::assertSame('enterprise-apps/demo', $pin->library());
        self::assertSame('v1.2.0', $pin->tag());
        self::assertSame(['index.html', 'assets/site.css'], $pin->files());

        // files is optional and defaults to an empty list.
        $this->put($dir, 'site-minimal.json', '{"library": "enterprise-apps/demo", "tag": "v1.2.0"}' . "\n");
        $minimal = Pin::fromFile($dir . '/site-minimal.json');
        self::assertSame([], $minimal->files());
    }

    public function test_pin_missing_file_throws_toolerror(): void
    {
        $dir = $this->tempDir();
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('#site\.json#');
        Pin::fromFile($dir . '/site.json');
    }

    public function test_pin_bad_json_throws_toolerror(): void
    {
        $dir = $this->tempDir();
        $this->put($dir, 'site.json', '{not json' . "\n");
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('#site\.json#');
        Pin::fromFile($dir . '/site.json');
    }

    public function test_pin_missing_tag_throws_toolerror(): void
    {
        $dir = $this->tempDir();
        $this->put($dir, 'site.json', '{"library": "enterprise-apps/demo"}' . "\n");
        $this->expectException(ToolError::class);
        $this->expectExceptionMessageMatches('#site\.json#');
        Pin::fromFile($dir . '/site.json');
    }

    public function test_export_keeps_export_ignored_changelog_and_readme(): void
    {
        $repo = $this->repo();
        $dest = $this->tempDir() . '/export';
        $written = TagExport::export($repo, 'v1.0.0', $dest);
        self::assertSame(5, $written);
        // The export-ignore files are the ones git archive would have dropped; the site build keeps them.
        self::assertFileExists($dest . '/CHANGELOG.md');
        self::assertFileExists($dest . '/README.md');
        self::assertFileExists($dest . '/src/index.php');
        self::assertFileExists($dest . '/app/config.php');
        self::assertFileExists($dest . '/.gitattributes');
        self::assertSame("# Changelog\n\n## 1.0.0\n\n- First release.\n", (string) file_get_contents($dest . '/CHANGELOG.md'));
        self::assertSame("# Demo app\n", (string) file_get_contents($dest . '/README.md'));
    }

    public function test_export_uses_pinned_tag_not_latest(): void
    {
        $repo = $this->repo();
        $dest = $this->tempDir() . '/export';
        TagExport::export($repo, 'v1.0.0', $dest);
        // src/index.php changed in v1.1.0; exporting v1.0.0 must keep the v1.0.0 content.
        self::assertSame("<?php echo 'v1';\n", (string) file_get_contents($dest . '/src/index.php'));
        self::assertSame("# Changelog\n\n## 1.0.0\n\n- First release.\n", (string) file_get_contents($dest . '/CHANGELOG.md'));
    }

    public function test_export_unknown_tag_throws_toolerror(): void
    {
        $repo = $this->repo();
        $dest = $this->tempDir() . '/export';
        $this->expectException(ToolError::class);
        $this->expectExceptionMessage('v9.9.9');
        TagExport::export($repo, 'v9.9.9', $dest);
    }

    /** A throwaway repo with v1.0.0 and v1.1.0; .gitattributes marks CHANGELOG.md and README.md export-ignore. */
    private function repo(): string
    {
        $this->requireGit();
        $dir = $this->tempDir();
        $this->git($dir, ['init']);
        $this->put($dir, '.gitattributes', "CHANGELOG.md export-ignore\nREADME.md export-ignore\n");
        $this->put($dir, 'CHANGELOG.md', "# Changelog\n\n## 1.0.0\n\n- First release.\n");
        $this->put($dir, 'README.md', "# Demo app\n");
        $this->put($dir, 'src/index.php', "<?php echo 'v1';\n");
        $this->put($dir, 'app/config.php', "<?php return ['name' => 'demo'];\n");
        $this->git($dir, ['add', '-A']);
        $this->commit($dir, 'v1.0.0');
        $this->git($dir, ['tag', 'v1.0.0']);
        $this->put($dir, 'src/index.php', "<?php echo 'v2';\n");
        $this->put($dir, 'CHANGELOG.md', "# Changelog\n\n## 1.1.0\n\n- Second release.\n\n## 1.0.0\n\n- First release.\n");
        $this->git($dir, ['add', '-A']);
        $this->commit($dir, 'v1.1.0');
        $this->git($dir, ['tag', 'v1.1.0']);

        return $dir;
    }

    /** Runs git with the given arguments inside the dir. */
    /** @param list<string> $args */
    private function git(string $dir, array $args): array
    {
        $process = @proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Commits what is staged in the dir without relying on a global committer identity. */
    private function commit(string $dir, string $message): void
    {
        $result = $this->git($dir, [
            '-c', 'user.name=Test',
            '-c', 'user.email=test@example.invalid',
            '-c', 'commit.gpgsign=false',
            'commit', '-m', $message,
        ]);
        self::assertSame(0, $result['code'], $result['stderr']);
    }

    private function requireGit(): void
    {
        $process = @proc_open(['git', '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            self::markTestSkipped('git is not installed.');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
}

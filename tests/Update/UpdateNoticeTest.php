<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests\Update;

use PHPUnit\Framework\TestCase;
use Waynestate\Squire\Update\InstallKind;
use Waynestate\Squire\Update\LatestReleaseFetcher;
use Waynestate\Squire\Update\PackagistFetcher;
use Waynestate\Squire\Update\UpdateCache;
use Waynestate\Squire\Update\UpdateNotice;

/**
 * The update notice: one stderr line when a newer stable release exists,
 * with the command that updates this install. Every test uses an injected
 * fetcher and clock and a temp cache dir, so nothing here touches the
 * network or the real cache. The env each test passes pins the CI and
 * opt-out variables, so the ambient environment of the runner or container
 * cannot silence the check.
 */
final class UpdateNoticeTest extends TestCase
{
    private const NOW = 1_763_000_000;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    public function testLineForPhar(): void
    {
        // Squire ships no PHAR, so a PHAR copy points at the Composer
        // global command, the only supported install path.
        $env = $this->env();

        [$notice, $fetcher] = $this->notice(InstallKind::Phar, [['version' => '0.11.3']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: composer global update waynestate/squire\n",
            $sink['text'],
        );
        self::assertSame(1, $fetcher->calls);
    }

    public function testLineForSourceCheckout(): void
    {
        // A checkout with a .git of its own (folder or file) outside any
        // vendor/ folder is a source checkout.
        $root = $this->makeTempDir() . '/checkout';
        mkdir($root . '/bin', 0o755, true);
        mkdir($root . '/src', 0o755, true);
        file_put_contents($root . '/.git', "# worktree gitdir marker\n");
        $env = $this->env();

        self::assertSame(InstallKind::SourceCheckout, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::SourceCheckout, [['version' => '0.11.3']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame("squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testLineForComposerGlobal(): void
    {
        // <global home>/vendor/waynestate/squire, with the global home
        // named by COMPOSER_HOME.
        $home = $this->makeTempDir() . '/composer-home';
        $root = $home . '/vendor/waynestate/squire';
        mkdir($root . '/bin', 0o755, true);
        $env = $this->env(['COMPOSER_HOME' => $home]);

        self::assertSame(InstallKind::ComposerGlobal, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::ComposerGlobal, [['version' => '0.11.3']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: composer global update waynestate/squire\n",
            $sink['text'],
        );
    }

    public function testLineForProjectDependency(): void
    {
        // <project>/vendor/waynestate/squire, a project that is not
        // Composer's global home, pinning a constraint that still allows the
        // newest release.
        $project = $this->makeTempDir() . '/app';
        $root = $project . '/vendor/waynestate/squire';
        mkdir($root . '/bin', 0o755, true);
        file_put_contents($project . '/composer.json', (string) json_encode([
            'require' => ['waynestate/squire' => '^0.11'],
        ], JSON_PRETTY_PRINT));
        $env = $this->env();

        self::assertSame(InstallKind::ProjectDependency, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::ProjectDependency, [['version' => '0.11.3']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: composer update waynestate/squire\n",
            $sink['text'],
        );
    }

    public function testLineForPinnedProjectDependency(): void
    {
        // A ^ or ~ pin that excludes the newest release switches the line to
        // a require of the newest major.minor; --dev only when the pin sits
        // in require-dev.
        $project = $this->makeTempDir() . '/app';
        $root = $project . '/vendor/waynestate/squire';
        mkdir($root . '/bin', 0o755, true);
        file_put_contents($project . '/composer.json', (string) json_encode([
            'require' => ['waynestate/squire' => '^0.11'],
        ], JSON_PRETTY_PRINT));
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::ProjectDependency, [['version' => '0.12.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 0.12.0 is the newest release. Update: composer require waynestate/squire:^0.12\n",
            $sink['text'],
        );

        // The same pin in require-dev adds --dev.
        file_put_contents($project . '/composer.json', (string) json_encode([
            'require-dev' => ['waynestate/squire' => '~0.11'],
        ], JSON_PRETTY_PRINT));
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::ProjectDependency, [['version' => '0.12.0']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 0.12.0 is the newest release. Update: composer require waynestate/squire:^0.12 --dev\n",
            $sink['text'],
        );
    }

    public function testLineForUnknownInstall(): void
    {
        // No .git, not under any vendor/: unknown, which falls back to the
        // Composer global command.
        $root = $this->makeTempDir() . '/loose';
        mkdir($root . '/bin', 0o755, true);
        $env = $this->env();

        self::assertSame(InstallKind::Unknown, InstallKind::detect($root, $env));

        [$notice] = $this->notice(InstallKind::Unknown, [['version' => '0.11.3']], self::NOW, $root);
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: composer global update waynestate/squire\n",
            $sink['text'],
        );
    }

    public function testCachedResultMakesNoFetch(): void
    {
        // A cache checked within the last day is printed from, without a
        // fetch, even when the fetcher would answer differently.
        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.11.3');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame("squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: git pull\n", $sink['text']);
        self::assertSame(0, $fetcher->calls);
    }

    public function testStaleCacheFetchesOnce(): void
    {
        // A cache older than a day fetches once and rewrites the file.
        $env = $this->env();
        $path = $this->seedCache($env, self::NOW - 86401, '0.11.2');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '0.11.3']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame("squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: git pull\n", $sink['text']);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(['checked_at' => self::NOW, 'latest' => '0.11.3'], (new UpdateCache('squire'))->read($path));
    }

    public function testOfflineIsSilent(): void
    {
        // No cache and a failed fetch: nothing is printed, and the empty
        // result is stamped so the next command does not try again for a
        // day.
        $env = $this->env();
        $path = (new UpdateCache('squire'))->path($env);

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(['checked_at' => self::NOW, 'latest' => null], (new UpdateCache('squire'))->read($path));
    }

    public function testMalformedJsonIsSilent(): void
    {
        // Malformed p2 bodies parse to null, and a null fetch stays silent
        // with the stamp refreshed.
        self::assertNull(PackagistFetcher::parse('not json'));
        self::assertNull(PackagistFetcher::parse('{"packages": {}}'));
        self::assertNull(PackagistFetcher::parse('{"packages": {"waynestate/squire": "nope"}}'));
        self::assertSame(
            [['version' => '0.11.3']],
            PackagistFetcher::parse('{"packages": {"waynestate/squire": [{"version": "0.11.3"}]}}'),
        );

        $env = $this->env();
        $path = (new UpdateCache('squire'))->path($env);

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(['checked_at' => self::NOW, 'latest' => null], (new UpdateCache('squire'))->read($path));
    }

    public function testCiEnvIsSilent(): void
    {
        // CI set to anything other than empty, 0 or false stays silent
        // before the cache is even read.
        $env = $this->env(['CI' => '1']);
        $this->seedCache($env, self::NOW, '0.11.3');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(0, $fetcher->calls);
    }

    public function testOptOutEnvIsSilent(): void
    {
        $env = $this->env(['WAYNE_NO_UPDATE_CHECK' => '1']);
        $this->seedCache($env, self::NOW, '0.11.3');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(0, $fetcher->calls);
    }

    public function testQuietIsSilent(): void
    {
        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.11.3');

        [$notice, $fetcher] = $this->notice(InstallKind::SourceCheckout, [['version' => '9.9.9']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list', '--quiet'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
        self::assertSame(0, $fetcher->calls);

        // The short form is quiet too.
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list', '-q'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
    }

    public function testStdoutUntouchedByJsonFormat(): void
    {
        // Squire has --format=json (doctor), so stdout must stay
        // byte-identical with the check on and off in both shapes. Run the
        // real binary twice per shape, copied into a project layout so the
        // detection follows the copy: once opted out, once with a fresh
        // seeded cache, so the notice prints from the cache and never
        // touches the network. CI and the opt-out are pinned to empty so an
        // ambient environment cannot silence the check.
        $home = $this->copyLayout();

        // 99.0.0 is far newer than any running release, so the notice
        // prints whatever version the copy carries; a major bump appends
        // the upgrading link.
        $cacheDir = $home . '/cache';
        $file = $cacheDir . '/waynestate/squire/update-check.json';
        mkdir(dirname($file), 0o755, true);
        file_put_contents($file, json_encode(['checked_at' => time(), 'latest' => '99.0.0']) . "\n");

        $env = ['CI' => '', 'WAYNE_NO_UPDATE_CHECK' => '', 'XDG_CACHE_HOME' => $cacheDir];
        [$stdoutOff, $stderrOff, $exitOff] = $this->runBinary($home, [...$env, 'WAYNE_NO_UPDATE_CHECK' => '1'], ['--version']);
        [$stdoutOn, $stderrOn, $exitOn] = $this->runBinary($home, $env, ['--version']);

        self::assertSame(0, $exitOff, "stderr: {$stderrOff}");
        self::assertSame($exitOff, $exitOn, "stderr: {$stderrOn}");
        self::assertSame($stdoutOff, $stdoutOn, "stderr: {$stderrOn}");

        // The JSON shape: list supports --format=json too, and its stdout
        // must be untouched as well.
        [$jsonOff, $jsonErrOff, $jsonExitOff] = $this->runBinary($home, [...$env, 'WAYNE_NO_UPDATE_CHECK' => '1'], ['list', '--format=json']);
        [$jsonOn, $jsonErrOn, $jsonExitOn] = $this->runBinary($home, $env, ['list', '--format=json']);

        self::assertSame($jsonExitOff, $jsonExitOn, "stderr: {$jsonErrOn}");
        self::assertSame($jsonOff, $jsonOn, "stderr: {$jsonErrOn}");

        // The notice went to stderr, on one line. The running version is
        // whatever the copy carries, so assert the shape only: the seed is
        // far newer than any release, and the trailing update command
        // depends on the copied layout.
        $lines = array_values(array_filter(explode("\n", $stderrOn), static fn(string $line): bool => $line !== ''));
        self::assertCount(1, $lines, "stderr: {$stderrOn}");
        self::assertStringStartsWith('squire ', $lines[0]);
        self::assertStringContainsString(
            ' is out of date: 99.0.0 is the newest release. Update: ',
            $lines[0],
        );
    }

    public function testNormalisesVPrefix(): void
    {
        // Tags may start with v or not: both normalise, and the line prints
        // the version without the v.
        $env = $this->env();
        $entries = [
            ['version' => 'v0.11.3'],
            ['version' => '0.11.2'],
            ['version' => 'v0.11.1'],
        ];

        [$notice] = $this->notice(InstallKind::SourceCheckout, $entries, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame("squire 0.11.2 is out of date: 0.11.3 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testIgnoresPrereleases(): void
    {
        // dev, alpha, beta and rc versions (any case) are dropped, so the
        // line names the highest stable release: here 0.11.1, not the
        // prereleases of 0.11.3 or 1.0.0 that sort above it.
        $env = $this->env();
        $entries = [
            ['version' => '1.0.0-beta1'],
            ['version' => '1.0.0-RC1'],
            ['version' => 'dev-main'],
            ['version' => '0.11.3-alpha'],
            ['version' => '0.11.2-BETA'],
            ['version' => '0.11.1'],
        ];

        [$notice] = $this->notice(InstallKind::SourceCheckout, $entries, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.0', $write);

        self::assertSame("squire 0.11.0 is out of date: 0.11.1 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testMajorBumpAddsUpgradingLink(): void
    {
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::SourceCheckout, [['version' => '1.0.0']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame(
            "squire 0.11.2 is out of date: 1.0.0 is the newest release. Update: git pull Upgrading: https://squire.apps.wayne.edu/docs/upgrading/\n",
            $sink['text'],
        );

        // A minor bump stays on the same major and adds no link.
        $env = $this->env();

        [$notice] = $this->notice(InstallKind::SourceCheckout, [['version' => '0.12.0']], self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame("squire 0.11.2 is out of date: 0.12.0 is the newest release. Update: git pull\n", $sink['text']);
    }

    public function testNotNewerIsSilent(): void
    {
        // A running version equal to, or newer than, the cached latest,
        // prints nothing.
        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.11.2');

        [$notice] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame('', $sink['text']);

        $env = $this->env();
        $this->seedCache($env, self::NOW, '0.11.1');

        [$notice] = $this->notice(InstallKind::SourceCheckout, null, self::NOW, '');
        [$write, $sink] = $this->stderr();

        $notice->run($env, ['squire', 'list'], '0.11.2', $write);

        self::assertSame('', $sink['text']);
    }

    /**
     * The environment a test passes to the notice: the temp cache dir plus
     * the CI and opt-out variables pinned to empty, so the ambient
     * environment (the gate container sets both) cannot reach the check.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        return array_merge(
            ['CI' => '', 'WAYNE_NO_UPDATE_CHECK' => '', 'XDG_CACHE_HOME' => $this->cacheDir()],
            $extra,
        );
    }

    private function cacheDir(): string
    {
        return $this->makeTempDir() . '/cache';
    }

    /**
     * @param array<string, string> $env
     */
    private function seedCache(array $env, int $checkedAt, ?string $latest): string
    {
        $cache = new UpdateCache('squire');
        $path = $cache->path($env);
        self::assertTrue($cache->write($path, $checkedAt, $latest));

        return $path;
    }

    /**
     * @return array{0: callable(string): void, 1: \ArrayObject<string, string>}
     */
    private function stderr(): array
    {
        $sink = new \ArrayObject(['text' => '']);
        $write = static function (string $message) use ($sink): void {
            $sink['text'] .= $message;
        };

        return [$write, $sink];
    }

    /**
     * @param list<array<string, mixed>>|null $entries
     * @return array{0: UpdateNotice, 1: FixedFetcher}
     */
    private function notice(InstallKind $kind, ?array $entries, int $now, string $packageRoot): array
    {
        $fetcher = new FixedFetcher($entries);
        $notice = new UpdateNotice(
            'squire',
            $packageRoot,
            $kind,
            new UpdateCache('squire'),
            $fetcher,
            static fn(): int => $now,
        );

        return [$notice, $fetcher];
    }

    /**
     * Copy bin/squire into a project layout under a fresh temp dir, with a
     * stub vendor/autoload.php pointing at this repository's autoloader, so
     * the detection follows the copy. Returns the project dir.
     */
    private function copyLayout(): string
    {
        $home = $this->makeTempDir();
        $root = $home . '/vendor/waynestate/squire';
        mkdir($root . '/bin', 0o755, true);
        copy(dirname(__DIR__) . '/../bin/squire', $root . '/bin/squire');
        file_put_contents($home . '/vendor/autoload.php', "<?php require '" . dirname(__DIR__) . "/../vendor/autoload.php';\n");

        return $home;
    }

    /**
     * Run the copied bin/squire with the given arguments and capture stdout
     * and stderr separately, the way the other tests run the real binary.
     *
     * @param array<string, string> $extraEnv
     * @param list<string>          $args
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function runBinary(string $home, array $extraEnv, array $args): array
    {
        $env = getenv();
        if ($env === false) {
            $env = [];
        }
        // Clear the two variables that can silence the notice, so the child
        // sees only what the test pins, not the runner's ambient CI or
        // opt-out; the caller's values below still win.
        $env['CI'] = '';
        $env['WAYNE_NO_UPDATE_CHECK'] = '';
        foreach ($extraEnv as $key => $value) {
            $env[$key] = $value;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open([PHP_BINARY, $home . '/vendor/waynestate/squire/bin/squire', ...$args], $descriptors, $pipes, null, $env);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [$stdout, $stderr, $exit === false ? -1 : $exit];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/squire-update-test-' . bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

/**
 * A LatestReleaseFetcher that counts its calls and answers with fixed
 * entries, so the tests need no network.
 */
final class FixedFetcher implements LatestReleaseFetcher
{
    public int $calls = 0;

    /**
     * @param list<array<string, mixed>>|null $entries
     */
    public function __construct(private readonly ?array $entries) {}

    public function fetch(): ?array
    {
        $this->calls++;

        return $this->entries;
    }
}

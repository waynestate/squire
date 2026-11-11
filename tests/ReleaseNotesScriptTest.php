<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

/**
 * Runs scripts/release-notes.sh, the one shared cut of a tag's CHANGELOG.md section that both release platforms
 * call. Needs sh, which the composer image has; the tests skip themselves when it is missing.
 */
final class ReleaseNotesScriptTest extends SquireTestCase
{
    private string $script = '';

    protected function setUp(): void
    {
        if (!self::hasProgram('sh')) {
            self::markTestSkipped('sh is not installed.');
        }
        $this->script = dirname(__DIR__) . '/scripts/release-notes.sh';
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runScript(array $args): array
    {
        $process = proc_open(['sh', $this->script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** A two-release changelog: 1.2.0 with two bullets and an inner blank line, then 1.1.0 with one bullet. */
    private function changelog(): string
    {
        $dir = $this->tempDir();
        $this->put($dir, 'CHANGELOG.md', <<<'MD'
            # Changelog

            ## Unreleased

            - Nothing yet.

            ## 1.2.0 (2026-01-02)

            - First bullet of 1.2.0.

            - Second bullet of 1.2.0.

            ## 1.1.0 (2026-01-01)

            - The only bullet of 1.1.0.
            MD);

        return $dir . '/CHANGELOG.md';
    }

    public function testItPrintsTheTagsSectionWithoutEitherHeading(): void
    {
        $file = $this->changelog();
        $result = $this->runScript(['1.2.0', $file]);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame("- First bullet of 1.2.0.\n\n- Second bullet of 1.2.0.\n", $result['stdout']);
        self::assertStringNotContainsString('## ', $result['stdout']);
        self::assertStringNotContainsString('1.1.0', $result['stdout']);
    }

    public function testALeadingVIsIgnored(): void
    {
        $file = $this->changelog();
        $withV = $this->runScript(['v1.2.0', $file]);
        $withoutV = $this->runScript(['1.2.0', $file]);
        self::assertSame(0, $withV['exit'], $withV['stderr']);
        self::assertSame($withoutV['stdout'], $withV['stdout']);
    }

    public function testTheLastSectionRunsToTheEndOfTheFile(): void
    {
        $file = $this->changelog();
        $result = $this->runScript(['1.1.0', $file]);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame("- The only bullet of 1.1.0.\n", $result['stdout']);
    }

    public function testAMissingSectionFailsWithTheExpectedHeading(): void
    {
        $file = $this->changelog();
        $result = $this->runScript(['9.9.9', $file]);
        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('has no section for this tag: expected a heading like "## 9.9.9 (".', $result['stderr']);
    }

    public function testAnEmptySectionFails(): void
    {
        $dir = $this->tempDir();
        $this->put($dir, 'CHANGELOG.md', <<<'MD'
            # Changelog

            ## 2.0.0 (2026-02-01)


            ## 1.2.0 (2026-01-02)

            - A bullet.
            MD);
        $result = $this->runScript(['2.0.0', $dir . '/CHANGELOG.md']);
        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('section for 2.0.0 is empty', $result['stderr']);
    }

    public function testAPrefixOfAnotherVersionDoesNotMatch(): void
    {
        $file = $this->changelog();
        $result = $this->runScript(['1.2', $file]);
        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
    }

    public function testNoTagIsAUsageError(): void
    {
        $result = $this->runScript([]);
        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('usage: release-notes.sh TAG [CHANGELOG]', $result['stderr']);
    }

    public function testTheRealChangelogHasNotesForTheNewestRelease(): void
    {
        $result = $this->runScript(['0.10.8', dirname(__DIR__) . '/CHANGELOG.md']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString('mysql56-migrate', $result['stdout']);
    }

    public function testBothPlatformsCutTheNotesWithTheScript(): void
    {
        $github = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');
        self::assertStringContainsString('sh scripts/release-notes.sh "$GITHUB_REF_NAME"', $github);
        self::assertStringNotContainsString('awk', $github);
        $ci = (string) file_get_contents(dirname(__DIR__) . '/.gitlab-ci.yml');
        $job = strpos($ci, "\ngitlab-release:\n");
        self::assertIsInt($job, 'the gitlab-release job is missing from .gitlab-ci.yml');
        $rest = substr($ci, $job);
        self::assertStringContainsString("\n    - if: \$CI_COMMIT_TAG", $rest);
        self::assertStringContainsString('sh scripts/release-notes.sh "$CI_COMMIT_TAG"', $rest);
    }
}

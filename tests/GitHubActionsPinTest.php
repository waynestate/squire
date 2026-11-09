<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every action and reusable workflow the GitHub workflows run is pinned by a full commit SHA, with the version it
 * stands for in a trailing comment. A tag like `actions/checkout@v4` can be moved to other code by whoever controls
 * that repository (tags were rewritten to point at a secret-dumping commit in the 2025 tj-actions incident); a commit
 * SHA cannot. Renovate (helpers:pinGitHubActionDigests, from the Preflight preset) moves the SHA and the comment
 * together. The squire checks the buckles before the knight rides, not after he falls off.
 */
final class GitHubActionsPinTest extends TestCase
{
    private const WORKFLOWS = __DIR__ . '/../.github/workflows';

    /** How to fix a finding, in one place. */
    private const HOW = 'Pin it as owner/repo@<40-character commit SHA> # <version tag>: '
        . 'git ls-remote https://github.com/<owner>/<repo>.git refs/tags/<tag> \'refs/tags/<tag>^{}\' '
        . '(an annotated tag lists its commit on the ^{} line). See MAINTAINING.md, CI.';

    /** @return list<string> */
    private function workflowFiles(): array
    {
        $files = array_merge(
            glob(self::WORKFLOWS . '/*.yml') ?: [],
            glob(self::WORKFLOWS . '/*.yaml') ?: [],
        );
        sort($files);

        return $files;
    }

    /**
     * Every `uses:` value in the workflows, as [file:line, value, trailing comment].
     *
     * @return list<array{string, string, string}>
     */
    private function uses(): array
    {
        $found = [];
        foreach ($this->workflowFiles() as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $i => $line) {
                if (preg_match('/^\s*(?:-\s+)?uses:\s*([\'"]?)([^\s\'"#]+)\1\s*(?:#\s*(.*?))?\s*$/', $line, $m) === 1) {
                    $found[] = [basename($file) . ':' . ($i + 1), $m[2], $m[3] ?? ''];
                }
            }
        }

        return $found;
    }

    public function testTheWorkflowsRunAtLeastOneAction(): void
    {
        self::assertNotSame([], $this->workflowFiles(), 'no workflows under .github/workflows');
        self::assertNotSame([], $this->uses(), 'the workflows name no `uses:` at all, so this test checks nothing');
    }

    public function testEveryActionIsPinnedByACommitShaWithItsVersionInAComment(): void
    {
        $findings = [];
        foreach ($this->uses() as [$where, $value, $comment]) {
            if (str_starts_with($value, './')) {
                continue; // an action in this repository is pinned by the commit that runs it
            }
            if (str_starts_with($value, 'docker://')) {
                if (preg_match('/@sha256:[0-9a-f]{64}$/', $value) !== 1) {
                    $findings[] = $where . ': ' . $value . ' (a docker:// action needs an @sha256: digest)';
                }
                continue;
            }
            if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[^@\s]+)?@([0-9a-f]{40})$#', $value) !== 1) {
                $findings[] = $where . ': ' . $value . ' (not pinned by a 40-character commit SHA)';
                continue;
            }
            if (preg_match('/^v?\d+(?:\.\d+)*$/', $comment) !== 1) {
                $findings[] = $where . ': ' . $value . ' (no "# <version>" comment naming the tag the SHA stands for)';
            }
        }

        self::assertSame([], $findings, "Unpinned actions:\n  " . implode("\n  ", $findings) . "\n" . self::HOW);
    }

    public function testOneActionIsPinnedToOneShaAcrossTheWorkflows(): void
    {
        $shas = [];
        foreach ($this->uses() as [$where, $value, $comment]) {
            if (preg_match('#^([^@]+)@([0-9a-f]{40})$#', $value, $m) === 1) {
                $shas[$m[1]][$m[2] . ' # ' . $comment][] = $where;
            }
        }

        $split = [];
        foreach ($shas as $action => $pins) {
            if (count($pins) > 1) {
                $split[] = $action . ': ' . implode(', ', array_keys($pins));
            }
        }

        self::assertSame([], $split, "An action runs at two versions; bump every copy together:\n  " . implode("\n  ", $split));
    }
}

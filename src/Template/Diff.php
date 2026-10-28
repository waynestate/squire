<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

/**
 * A small unified diff, for `update` to show what an edited file has changed against the template.
 * CRLF and LF compare equal, the same way the manifest hashes do, so a CRLF checkout is not a diff.
 */
final class Diff
{
    private const CONTEXT = 3;

    /** Above this many line-pairs the LCS table gets too big; fall back to a whole-file diff. */
    private const MAX_CELLS = 1_000_000;

    private const NO_NEWLINE = '\ No newline at end of file';

    /**
     * A unified diff from $old to $new: the `-` lines are in $old only, the `+` lines in $new only.
     * Empty string when the two are equal.
     */
    public static function unified(string $old, string $new, string $oldLabel, string $newLabel): string
    {
        $oldLines = self::lines($old);
        $newLines = self::lines($new);
        $hunks = self::hunks($oldLines, $newLines, self::endsWithNewline($old), self::endsWithNewline($new));
        if ($hunks === []) {
            return '';
        }

        $out = [
            sprintf('--- %s', $oldLabel),
            sprintf('+++ %s', $newLabel),
        ];
        foreach ($hunks as $hunk) {
            $out[] = $hunk;
        }

        return implode("\n", $out) . "\n";
    }

    private static function endsWithNewline(string $content): bool
    {
        $content = Catalog::normalize($content);

        return $content !== '' && str_ends_with($content, "\n");
    }

    /**
     * The content as lines. A trailing newline ends the last line; it is not an extra empty line.
     *
     * @return list<string>
     */
    private static function lines(string $content): array
    {
        $content = Catalog::normalize($content);
        if ($content === '') {
            return [];
        }
        $lines = explode("\n", $content);
        if ($lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string> the hunk texts, each starting with its @@ header
     */
    private static function hunks(array $a, array $b, bool $aEndsNewline, bool $bEndsNewline): array
    {
        $ops = self::ops($a, $b);
        $n = count($ops);

        // The changed regions, grown by the context lines, merged where the contexts overlap.
        $regions = [];
        for ($i = 0; $i < $n;) {
            if ($ops[$i][0] === '=') {
                $i++;
                continue;
            }
            $start = $i;
            while ($i < $n && $ops[$i][0] !== '=') {
                $i++;
            }
            $regions[] = [max(0, $start - self::CONTEXT), min($n, $i + self::CONTEXT)];
        }
        $merged = [];
        foreach ($regions as $region) {
            if ($merged !== [] && $region[0] <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $region[1]);
            } else {
                $merged[] = $region;
            }
        }

        $hunks = [];
        foreach ($merged as $region) {
            $from = $region[0];
            $to = $region[1];
            $oldStart = 0;
            $newStart = 0;
            $oldCount = 0;
            $newCount = 0;
            $body = [];
            $lastOld = -1;
            $lastNew = -1;
            for ($i = $from; $i < $to; $i++) {
                $op = $ops[$i][0];
                if ($op === '=' || $op === '-') {
                    if ($oldCount === 0) {
                        $oldStart = $ops[$i][1];
                    }
                    $oldCount++;
                }
                if ($op === '=' || $op === '+') {
                    if ($newCount === 0) {
                        $newStart = $ops[$i][2];
                    }
                    $newCount++;
                }
                $body[] = ($op === '=' ? ' ' : $op) . $ops[$i][3];
                if ($op === '=' || $op === '-') {
                    $lastOld = count($body) - 1;
                }
                if ($op === '=' || $op === '+') {
                    $lastNew = count($body) - 1;
                }
            }

            // A file whose last line is shown and that lacks a trailing newline gets the marker after that line.
            $oldMarker = $oldCount > 0 && $oldStart + $oldCount === count($a) + 1 && !$aEndsNewline;
            $newMarker = $newCount > 0 && $newStart + $newCount === count($b) + 1 && !$bEndsNewline;
            if ($oldMarker) {
                array_splice($body, $lastOld + 1, 0, [self::NO_NEWLINE]);
                if ($newMarker && $lastNew > $lastOld) {
                    $lastNew++;
                }
            }
            if ($newMarker) {
                array_splice($body, $lastNew + 1, 0, [self::NO_NEWLINE]);
            }

            $hunks[] = sprintf('@@ -%d,%d +%d,%d @@', $oldStart, $oldCount, $newStart, $newCount) . "\n" . implode("\n", $body);
        }

        return $hunks;
    }

    /**
     * The edit script as ops: [op, aLine, bLine, text]. For `-` ops aLine is 1-based in $a and bLine the number of
     * $b lines consumed so far; for `+` ops it is the other way round. That is what the @@ headers need.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    private static function ops(array $a, array $b): array
    {
        $ops = [];
        $ia = 0;
        $ib = 0;
        foreach (self::script($a, $b) as $step) {
            if ($step === 'eq') {
                $ia++;
                $ib++;
                $ops[] = ['=', $ia, $ib, $a[$ia - 1]];
            } elseif ($step === 'del') {
                $ia++;
                $ops[] = ['-', $ia, $ib, $a[$ia - 1]];
            } else {
                $ib++;
                $ops[] = ['+', $ia, $ib, $b[$ib - 1]];
            }
        }

        return $ops;
    }

    /**
     * The edit script from a longest-common-subsequence table.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string> 'eq' | 'del' | 'ins'
     */
    private static function script(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return array_fill(0, $m, 'ins');
        }
        if ($m === 0) {
            return array_fill(0, $n, 'del');
        }
        if ($n * $m > self::MAX_CELLS) {
            return array_merge(array_fill(0, $n, 'del'), array_fill(0, $m, 'ins'));
        }

        // $dp[$i][$j] = the length of the longest common suffix of $a[$i:] and $b[$j:].
        $dp = [];
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $script = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $script[] = 'eq';
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $script[] = 'del';
                $i++;
            } else {
                $script[] = 'ins';
                $j++;
            }
        }
        while ($i < $n) {
            $script[] = 'del';
            $i++;
        }
        while ($j < $m) {
            $script[] = 'ins';
            $j++;
        }

        return $script;
    }
}

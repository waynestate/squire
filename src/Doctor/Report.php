<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor;

use Waynestate\Squire\Squire;

/**
 * Turns findings into text or JSON. Messages are plain and exact: they are read in a terminal and in CI logs.
 */
final class Report
{
    /** @param list<Finding> $findings */
    public static function text(string $path, array $findings): string
    {
        $lines = [];
        foreach ($findings as $finding) {
            $where = $finding->file === null ? '' : sprintf(' [%s]', $finding->file);
            $lines[] = sprintf('%-4s %-4s %s%s', strtoupper($finding->status), $finding->rule, $finding->message, $where);
        }

        $counts = self::counts($findings);
        $lines[] = sprintf(
            'RESULT: %s (%d fail, %d warn, %d info, %d pass)',
            Doctor::exitCode($findings) === 0 ? 'pass' : 'fail',
            $counts[Finding::FAIL],
            $counts[Finding::WARN],
            $counts[Finding::INFO],
            $counts[Finding::PASS],
        );

        return implode("\n", $lines) . "\n";
    }

    /** @param list<Finding> $findings */
    public static function json(string $path, array $findings): string
    {
        $exit = Doctor::exitCode($findings);
        $data = [
            'tool' => Squire::TOOL,
            'version' => Squire::version(),
            'path' => $path,
            'result' => $exit === 0 ? 'pass' : 'fail',
            'exit' => $exit,
            'counts' => self::counts($findings),
            'findings' => array_map(static fn(Finding $f): array => $f->toArray(), $findings),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    private static function counts(array $findings): array
    {
        $counts = [Finding::FAIL => 0, Finding::WARN => 0, Finding::INFO => 0, Finding::PASS => 0];
        foreach ($findings as $finding) {
            ++$counts[$finding->status];
        }

        return $counts;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\TestCase;

/**
 * scripts/mysql-preupgrade-checks.sql promises to be read-only, so it can be pointed at a
 * copy of anyone's data without a second thought. This test holds it to that: comments
 * and string literals are set aside, and every statement left must be a SELECT or a
 * SET SESSION. A squire may polish the armor; he never sells the horse.
 */
final class MysqlPreupgradeChecksTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../scripts/mysql-preupgrade-checks.sql';

    /** The four checks, in the order the guide lists them. */
    private const CHECKS = [
        'old_temporal_columns',
        'long_names_over_64',
        'charset_not_utf8mb4',
        'rejected_by_8_4',
    ];

    /** Words that would make the script write, lock, run hidden code or reach outside the server. */
    private const DENIED = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE',
        'REPLACE', 'RENAME', 'LOAD', 'CALL', 'PREPARE', 'EXECUTE', 'HANDLER', 'LOCK', 'INTO',
        'OUTFILE', 'DUMPFILE', 'FLUSH', 'KILL', 'SHUTDOWN', 'INSTALL', 'GLOBAL', 'PERSIST',
    ];

    public function testTheHeaderSaysReadOnlyAndRunOnACopy(): void
    {
        $header = '';
        foreach (preg_split('/\R/', $this->raw()) ?: [] as $line) {
            if (! str_starts_with($line, '--')) {
                break;
            }
            $header .= $line . "\n";
        }

        self::assertStringContainsString('READ-ONLY', $header, 'The script must open by saying it is read-only.');
        self::assertMatchesRegularExpression('/on a copy of the data/i', $header, 'The header must say to run it on a copy.');
    }

    public function testEveryStatementIsASelectOrASessionSet(): void
    {
        $statements = $this->statements();
        self::assertNotEmpty($statements, 'The script has no statements.');

        foreach ($statements as $statement) {
            self::assertMatchesRegularExpression(
                '/^(SELECT\b|SET\s+SESSION\s+\w+\s*=)/i',
                $statement,
                'Only SELECT and SET SESSION statements are allowed, found: ' . substr($statement, 0, 80),
            );
        }
    }

    public function testNoStatementUsesAWordThatWritesOrEscapes(): void
    {
        foreach ($this->statements() as $statement) {
            foreach (self::DENIED as $word) {
                self::assertDoesNotMatchRegularExpression(
                    '/\b' . $word . '\b/i',
                    $statement,
                    $word . ' has no place in a read-only script: ' . substr($statement, 0, 80),
                );
            }
        }
    }

    public function testThereAreNoExecutableComments(): void
    {
        self::assertStringNotContainsString('/*!', $this->raw(), 'A /*! ... */ comment runs as SQL; the test cannot read it as a comment.');
        self::assertStringNotContainsString('/*+', $this->raw(), 'Keep optimizer hints out; the script should read the same on every version.');
    }

    public function testEachCheckNameAppearsExactlyOnce(): void
    {
        $code = $this->strip($this->raw(), false);

        foreach (self::CHECKS as $check) {
            self::assertSame(1, substr_count($code, "'" . $check . "'"), $check . ' must be named exactly once in the SQL.');
        }

        preg_match_all("/'([a-z0-9_]+)' AS check_name/", $code, $found);
        self::assertEqualsCanonicalizing(self::CHECKS, $found[1], 'The script names a check the guide does not list, or misses one.');
    }

    public function testEveryCheckReturnsTheFourColumnsInOrder(): void
    {
        $selects = array_values(array_filter(
            $this->statements(),
            static fn(string $statement): bool => str_starts_with(strtoupper($statement), 'SELECT'),
        ));
        self::assertCount(count(self::CHECKS), $selects, 'Each check is one top-level SELECT.');

        foreach ($selects as $select) {
            self::assertMatchesRegularExpression(
                '/^SELECT\s+.+?\s+AS check_name,\s+.+?\s+AS schema_name,\s+.+?\s+AS object_name,\s+.+?\s+AS detail\s+FROM\b/is',
                $select,
                'A check must return check_name, schema_name, object_name, detail: ' . substr($select, 0, 80),
            );
        }
    }

    public function testTheOldTemporalCheckRunsLastBecauseItsSetFailsOnEight(): void
    {
        $statements = $this->statements(false);
        $last = (string) end($statements);
        $setBefore = (string) prev($statements);

        self::assertStringContainsString("'old_temporal_columns' AS check_name", $last);
        self::assertMatchesRegularExpression('/^SET SESSION show_old_temporals\s*=\s*ON$/i', $setBefore);
    }

    public function testTheGuideLinksTheScriptAndNamesEveryCheck(): void
    {
        $guide = (string) file_get_contents(__DIR__ . '/../docs/guides/mysql-upgrade-path.md');

        self::assertStringContainsString('scripts/mysql-preupgrade-checks.sql', $guide);
        foreach (self::CHECKS as $check) {
            self::assertStringContainsString('`' . $check . '`', $guide, 'The guide does not explain ' . $check . '.');
        }
    }

    private function raw(): string
    {
        self::assertFileExists(self::SCRIPT);

        return str_replace("\r\n", "\n", (string) file_get_contents(self::SCRIPT));
    }

    /**
     * The statements with comments removed, trimmed, one per semicolon. String literals are
     * blanked unless $blankStrings is false. Either way the split happens at the semicolons
     * of the blanked text, so a semicolon inside a literal never ends a statement; blanking
     * keeps every length, so both texts line up character for character.
     *
     * @return list<string>
     */
    private function statements(bool $blankStrings = true): array
    {
        $blanked = $this->strip($this->raw(), true);
        $code = $blankStrings ? $blanked : $this->strip($this->raw(), false);
        self::assertSame(strlen($blanked), strlen($code));

        $statements = [];
        $start = 0;
        $length = strlen($blanked);
        for ($at = 0; $at <= $length; $at++) {
            if ($at < $length && $blanked[$at] !== ';') {
                continue;
            }
            $statement = trim((string) preg_replace('/\s+/', ' ', substr($code, $start, $at - $start)));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $start = $at + 1;
        }

        return $statements;
    }

    /**
     * Removes "-- ", "#" and block comments. With $blankStrings, the inside of each quoted
     * literal becomes spaces, so words and semicolons in text are never read as SQL.
     */
    private function strip(string $sql, bool $blankStrings): string
    {
        $out = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $i + 1;
                while ($end < $length) {
                    if ($sql[$end] === '\\' && $char !== '`') {
                        $end += 2;

                        continue;
                    }
                    if ($sql[$end] === $char) {
                        if ($end + 1 < $length && $sql[$end + 1] === $char) {
                            $end += 2;

                            continue;
                        }
                        break;
                    }
                    $end++;
                }
                self::assertLessThan($length, $end, 'An unclosed quote in the script.');
                $inner = substr($sql, $i + 1, $end - $i - 1);
                $out .= $char . ($blankStrings && $char !== '`' ? str_repeat(' ', strlen($inner)) : $inner) . $char;
                $i = $end;

                continue;
            }

            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $length : $eol - 1;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                self::assertNotFalse($close, 'An unclosed block comment in the script.');
                $out .= ' ';
                $i = $close + 1;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }
}

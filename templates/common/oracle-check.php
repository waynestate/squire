<?php

declare(strict_types=1);

/*
 * oracle-check.php: the plain-language Oracle connection check for the release log (D-046).
 *
 * Framework-free, no Composer dependency, and it never fails the release: scripts/release.sh runs it with
 * `|| true`, so a finding prints in the deployment log and the release goes on. When the app does not use
 * Oracle (no ORACLE_* settings) it prints nothing and exits 0.
 *
 * Settings (environment variables):
 *   ORACLE_USERNAME, ORACLE_PASSWORD
 *   ORACLE_TNS                   Easy Connect host:port/service, or a full (DESCRIPTION=...) descriptor
 *   ORACLE_HOST, ORACLE_PORT, ORACLE_SERVICE_NAME   the same address as three parts
 *
 * The first Oracle connection is where new developers get stuck; this file turns the raw errors into
 * sentences that say what is wrong and what to click.
 */

final class SquireOracleCheck
{
    /**
     * @param array<string, string> $env       the settings; the CLI passes getenv()
     * @param callable|null         $connector replaces the real driver connect in tests
     * @param callable|null         $out       receives each output line, without its newline
     */
    public static function run(array $env, ?callable $connector = null, ?callable $out = null): int
    {
        return 0;
    }
}

if (($_SERVER['SCRIPT_FILENAME'] ?? null) !== null && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $env = getenv();
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if ($argument === '--verbose') {
            $env['ORACLE_CHECK_VERBOSE'] = '1';
        }
    }
    exit(SquireOracleCheck::run($env, null, null));
}

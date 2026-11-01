<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SquireOracleCheck;

/**
 * Drives templates/common/oracle-check.php with an injected connector and a capturing printer, so no real
 * Oracle, driver or network beyond 127.0.0.1 is ever touched.
 */
final class OracleCheckTest extends SquireTestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $logins = [];

    /** @var list<resource> */
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/templates/common/oracle-check.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if (is_resource($server)) {
                fclose($server);
            }
        }
        $this->servers = [];
        parent::tearDown();
    }

    /** A listening TCP server on 127.0.0.1; returns its port. */
    private function listen(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, (string) $error);
        $this->servers[] = $server;
        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** A port that listened a moment ago and now refuses connections. */
    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, (string) $error);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function out(): callable
    {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    private function connector(bool $ok = true, ?int $code = null, ?string $globalName = 'WSU_TEST'): callable
    {
        return function (string $user, string $password, string $connectString) use ($ok, $code, $globalName): array {
            $this->logins[] = [$user, $password, $connectString];

            return [
                'ok' => $ok,
                'code' => $code,
                'message' => $ok ? '' : 'ORA-' . (string) $code,
                'globalName' => $ok ? $globalName : null,
            ];
        };
    }

    /** @param array<string, string> $env */
    private function check(array $env, ?callable $connector = null): int
    {
        $this->lines = [];
        $this->logins = [];

        return SquireOracleCheck::run($env, $connector, $this->out());
    }

    /** @return array<string, string> */
    private function hostEnv(int $port, string $username = 'dev_user', string $password = 's3cretvalue'): array
    {
        return [
            'ORACLE_USERNAME' => $username,
            'ORACLE_PASSWORD' => $password,
            'ORACLE_HOST' => '127.0.0.1',
            'ORACLE_PORT' => (string) $port,
            'ORACLE_SERVICE_NAME' => 'wstatest',
        ];
    }

    public function testNotConfiguredIsSilentAndReturnsZero(): void
    {
        self::assertSame(0, $this->check([]));
        self::assertSame([], $this->lines);

        self::assertSame(0, $this->check(['PATH' => '/usr/bin', 'ORACLE_PASSWORD' => 'a password alone']));
        self::assertSame([], $this->lines, 'none of ORACLE_USERNAME, ORACLE_TNS or ORACLE_HOST is set');
    }

    public function testVerbosePrintsTheNotConfiguredLine(): void
    {
        self::assertSame(0, $this->check(['ORACLE_CHECK_VERBOSE' => '1']));
        self::assertSame(['[oracle] not configured (no ORACLE_* settings), skipping'], $this->lines);
    }

    public function testEveryEmptyRequiredSettingIsNamed(): void
    {
        $exit = $this->check(['ORACLE_USERNAME' => 'dev_user']);

        self::assertSame(1, $exit);
        self::assertSame([
            "[oracle] ORACLE_PASSWORD is empty. Set it in the app's environment settings (on Citadel, the dashboard).",
            "[oracle] ORACLE_HOST is empty. Set it in the app's environment settings (on Citadel, the dashboard).",
            "[oracle] ORACLE_PORT is empty. Set it in the app's environment settings (on Citadel, the dashboard).",
            "[oracle] ORACLE_SERVICE_NAME is empty. Set it in the app's environment settings (on Citadel, the dashboard).",
        ], $this->lines);
        self::assertSame([], $this->logins);
    }

    public function testTheTnsFormOnlyRequiresUsernamePasswordAndTns(): void
    {
        $exit = $this->check(['ORACLE_USERNAME' => 'dev_user', 'ORACLE_TNS' => 'db.example.invalid:1521/wstatest']);

        self::assertSame(1, $exit);
        self::assertSame(["[oracle] ORACLE_PASSWORD is empty. Set it in the app's environment settings (on Citadel, the dashboard)."], $this->lines);
    }

    public function testAnUnresolvableHostStopsBeforeAnyLogin(): void
    {
        $env = $this->hostEnv(1521);
        $env['ORACLE_HOST'] = 'no-such-host.invalid';

        $exit = $this->check($env, $this->connector());

        self::assertSame(1, $exit);
        self::assertSame(['[oracle] the host in ORACLE_HOST (or ORACLE_TNS) does not resolve from here. Check the spelling, and that you are on the campus network or VPN.'], $this->lines);
        self::assertSame([], $this->logins);
    }

    public function testAClosedPortIsReportedWithinSecondsAndSkipsTheLogin(): void
    {
        $env = $this->hostEnv($this->closedPort());
        $started = microtime(true);
        $exit = $this->check($env, $this->connector());
        $elapsed = microtime(true) - $started;

        self::assertSame(1, $exit);
        self::assertSame(["[oracle] Oracle did not answer at the host and port in your settings within 3 seconds. On Citadel: on the app's dashboard, tick the Oracle service under Services Available and click Request Access Changes. Locally: check the VPN."], $this->lines);
        self::assertLessThan(5.0, $elapsed, 'a closed port must not hang the release for the 60 second default_socket_timeout');
        self::assertSame([], $this->logins, 'a dead port must not cost a login attempt');
    }

    public function testAListeningPortReachesTheLoginWithTheEasyConnectString(): void
    {
        $port = $this->listen();

        $exit = $this->check($this->hostEnv($port), $this->connector());

        self::assertSame(0, $exit);
        self::assertSame([['dev_user', 's3cretvalue', "127.0.0.1:{$port}/wstatest"]], $this->logins);
    }

    public function testEasyConnectTnsIsParsedAndPassedThroughUnchanged(): void
    {
        $port = $this->listen();
        $tns = "127.0.0.1:{$port}/wstatest";
        $env = ['ORACLE_USERNAME' => 'dev_user', 'ORACLE_PASSWORD' => 's3cretvalue', 'ORACLE_TNS' => $tns];

        $exit = $this->check($env, $this->connector());

        self::assertSame(0, $exit);
        self::assertSame([$tns], array_column($this->logins, 2));
    }

    public function testAFullDescriptorTnsIsParsedForThePortCheck(): void
    {
        $port = $this->listen();
        $tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL=tcp)(HOST=127.0.0.1)(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME=wstatest)))";
        $env = ['ORACLE_USERNAME' => 'dev_user', 'ORACLE_PASSWORD' => 's3cretvalue', 'ORACLE_TNS' => $tns];

        $exit = $this->check($env, $this->connector());

        self::assertSame(0, $exit);
        self::assertSame([$tns], array_column($this->logins, 2));
    }

    public function testADriverlessPhpIsToldWhereToGetOne(): void
    {
        if (extension_loaded('oci8')) {
            self::markTestSkipped('oci8 is loaded, so the missing-driver path cannot be reached.');
        }

        $exit = $this->check($this->hostEnv($this->listen()));

        self::assertSame(1, $exit);
        self::assertSame(['[oracle] Oracle is set up for this app, but this PHP has no Oracle driver (oci8). Deploy to Citadel, which has it, or use the WSU Sail or Docker image with Oracle.'], $this->lines);
        self::assertSame([], $this->logins);
    }

    /** @return array<string, array{int|null, string}> */
    public static function oracleErrors(): array
    {
        return [
            'TNS not found' => [12154, '[oracle] Oracle could not find the connect name in ORACLE_TNS. Use host:port/service or a full descriptor.'],
            'service unavailable' => [12514, '[oracle] the Oracle listener answered but that service is not available. Check ORACLE_SERVICE_NAME; the database may be in maintenance.'],
            'no listener' => [12541, '[oracle] there is no Oracle listener at that address. Check the host and port; the database may be down.'],
            'bad credentials' => [1017, '[oracle] Oracle refused the username or password. Check ORACLE_USERNAME and ORACLE_PASSWORD.'],
            'insufficient privileges' => [1031, '[oracle] the login worked, but this account lacks permission on that table or view (ORA-1031). Ask the data owner for the grant.'],
            'table or view missing' => [942, '[oracle] the login worked, but this account lacks permission on that table or view (ORA-942). Ask the data owner for the grant.'],
            'anything else' => [28001, '[oracle] Oracle returned ORA-28001. Search that code, and check the settings above.'],
            'no code at all' => [null, '[oracle] Oracle returned ORA-unknown. Search that code, and check the settings above.'],
        ];
    }

    #[DataProvider('oracleErrors')]
    public function testEachOracleErrorGetsItsSentence(?int $code, string $expected): void
    {
        $exit = $this->check($this->hostEnv($this->listen()), $this->connector(false, $code));

        self::assertSame(1, $exit);
        self::assertSame([$expected], $this->lines);
    }

    public function testASuccessfulLoginNamesTheDatabaseAndTheVersionsFound(): void
    {
        $exit = $this->check($this->hostEnv($this->listen()), $this->connector(true, null, 'WSU_TEST'));

        self::assertSame(0, $exit);
        self::assertCount(1, $this->lines);
        // The versions are whatever this PHP carries; in the CI image, which has no oci8, both read unknown.
        self::assertMatchesRegularExpression('/^\[oracle\] connected to WSU_TEST \(oci8 [0-9a-z.]+, client [0-9a-z.]+\)$/', $this->lines[0]);
    }

    public function testSettingValuesNeverReachTheOutput(): void
    {
        $scenarios = [
            fn(): int => $this->check(['ORACLE_USERNAME' => 's3cretuser', 'ORACLE_PASSWORD' => 's3cretvalue']),
            fn(): int => $this->check($this->hostEnv($this->closedPort(), 's3cretuser', 's3cretvalue'), $this->connector()),
            fn(): int => $this->check($this->hostEnv($this->listen(), 's3cretuser', 's3cretvalue'), $this->connector(false, 1017)),
            fn(): int => $this->check($this->hostEnv($this->listen(), 's3cretuser', 's3cretvalue'), $this->connector()),
        ];

        foreach ($scenarios as $scenario) {
            $scenario();
            $output = implode("\n", $this->lines);
            self::assertStringNotContainsString('s3cretuser', $output);
            self::assertStringNotContainsString('s3cretvalue', $output);
        }
    }
}

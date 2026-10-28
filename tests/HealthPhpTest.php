<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;

/**
 * Runs the health.php template against a throw-away PHP built-in server on 127.0.0.1.
 */
final class HealthPhpTest extends SquireTestCase
{
    /** @var resource|null */
    private $server;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
        parent::tearDown();
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** Starts a server whose /up answers with the given status; returns the port. */
    private function serve(string $docRoot, int $status): int
    {
        $port = $this->freePort();
        $this->put($docRoot, 'router.php', "<?php\nhttp_response_code({$status});\necho 'x';\n");
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $docRoot . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/squire-health-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/squire-health-server.log', 'a']],
            $pipes,
            $docRoot,
        );
        for ($i = 0; $i < 50; ++$i) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($probe !== false) {
                fclose($probe);

                return $port;
            }
            usleep(100_000);
        }
        self::fail('The test server did not start.');
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, stdout: string}
     */
    private function health(string $app, array $env): array
    {
        $process = proc_open(
            [PHP_BINARY, $app . '/health.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            ['PATH' => (string) getenv('PATH'), ...$env],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout];
    }

    private function appWithHealth(bool $vendor = true): string
    {
        $app = $this->tempDir();
        copy(Squire::templatesDir() . '/common/health.php', $app . '/health.php');
        if ($vendor) {
            $this->put($app, 'vendor/autoload.php', "<?php\n");
        }

        return $app;
    }

    public function testHealthyWhenTheEndpointAnswers200(): void
    {
        $app = $this->appWithHealth();
        $port = $this->serve($this->tempDir(), 200);

        $result = $this->health($app, ['SQUIRE_HEALTH_PORT' => (string) $port]);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertStringContainsString('healthy: /up.php answered HTTP 200', $result['stdout']);
    }

    public function testUnhealthyOnAnyOtherStatus(): void
    {
        $app = $this->appWithHealth();
        $port = $this->serve($this->tempDir(), 503);

        $result = $this->health($app, ['SQUIRE_HEALTH_PORT' => (string) $port]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('answered HTTP 503, expected 200', $result['stdout']);
    }

    public function testUnhealthyWhenNothingListens(): void
    {
        $result = $this->health($this->appWithHealth(), ['SQUIRE_HEALTH_PORT' => (string) $this->freePort(), 'SQUIRE_HEALTH_TIMEOUT' => '1']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('cannot connect', $result['stdout']);
    }

    public function testUnhealthyWhenTheReleaseDidNotFinish(): void
    {
        $result = $this->health($this->appWithHealth(false), []);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('vendor/autoload.php is missing', $result['stdout']);
    }

    public function testLaravelAppsDefaultToUpAndRawAppsToUpPhp(): void
    {
        $app = $this->appWithHealth();
        $port = $this->serve($this->tempDir(), 200);
        $raw = $this->health($app, ['SQUIRE_HEALTH_PORT' => (string) $port]);
        self::assertStringContainsString('/up.php', $raw['stdout']);

        $this->put($app, 'artisan', "<?php\n");
        $laravel = $this->health($app, ['SQUIRE_HEALTH_PORT' => (string) $port]);
        self::assertStringContainsString('healthy: /up answered', $laravel['stdout']);
    }

    public function testAMalformedPathIsRejectedBeforeAnythingIsSent(): void
    {
        $result = $this->health($this->appWithHealth(), ['SQUIRE_HEALTH_PATH' => "/up\r\nX-Evil: 1"]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('must be a path that starts with /', $result['stdout']);
    }
}

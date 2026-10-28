<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;

/**
 * Runs the raw up.php template against a throw-away PHP built-in server on 127.0.0.1.
 */
final class UpPhpTest extends SquireTestCase
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

    /** Serves the app's public/ directory; returns the port. */
    private function serve(string $app): int
    {
        $port = $this->freePort();
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $app . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/squire-up-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/squire-up-server.log', 'a']],
            $pipes,
            $app,
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
     * @return array{status: int, body: string}
     */
    private function get(int $port, string $path): array
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 5);
        self::assertNotFalse($socket, (string) $error);
        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        $parts = explode("\r\n\r\n", $raw, 2);
        $headers = $parts[0] ?? '';
        preg_match('#^HTTP/\S+\s+(\d{3})#m', $headers, $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'body' => $parts[1] ?? ''];
    }

    /** A raw app with the up.php template in public/ and, optionally, a HELMET stub in vendor/. */
    private function upApp(bool $helmet): string
    {
        $app = $this->tempDir();
        $this->put($app, 'public/up.php', (string) file_get_contents(Squire::templatesDir() . '/raw/public/up.php'));
        if ($helmet) {
            $this->put($app, 'vendor/autoload.php', "<?php\nnamespace Waynestate\\Helmet;\n\nfunction helmet_up(): never\n{\n    echo 'HELMET-UP';\n    exit(0);\n}\n");
        }

        return $app;
    }

    public function testAnswersOkWith200WhenThereIsNoVendorDir(): void
    {
        $port = $this->serve($this->upApp(false));

        $response = $this->get($port, '/up.php');

        self::assertSame(200, $response['status']);
        self::assertSame("ok\n", $response['body']);
    }

    public function testDefersToHelmetWhenItIsInstalled(): void
    {
        $port = $this->serve($this->upApp(true));

        $response = $this->get($port, '/up.php');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('HELMET-UP', $response['body']);
        self::assertStringNotContainsString('ok', $response['body']);
    }
}

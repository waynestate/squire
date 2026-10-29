<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;

/**
 * Runs the raw target's front controller (templates/raw/public/index.php) as the router of a throw-away PHP built-in
 * server on 127.0.0.1, so every request reaches it, the way public/.htaccess sends them on Citadel.
 */
final class RawFrontControllerTest extends SquireTestCase
{
    /** @var resource|null */
    private $server;

    protected function tearDown(): void
    {
        $this->stop();
        parent::tearDown();
    }

    private function stop(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * A raw app: the front controller in public/, an asset, a PHP file that must never run, a secret beside public/,
     * and an entry that names itself and the path it was asked for.
     *
     * @param array<string, string> $extra relative path => content
     */
    private function rawApp(array $extra = [], bool $helmet = false): string
    {
        $app = $this->tempDir();
        $this->put($app, 'public/index.php', (string) file_get_contents(Squire::templatesDir() . '/raw/public/index.php'));
        $this->put($app, 'public/style.css', "body { color: #0c5449; }\n");
        $this->put($app, 'public/page.html', "<!doctype html><title>Page</title><script>go()</script>\n");
        $this->put($app, 'public/secret.php', "<?php\necho 'EXECUTED';\n");
        $this->put($app, 'public/dump.sql', "DROP TABLE knights;\n");
        $this->put($app, 'public/.htaccess', "Options -Indexes\n");
        $this->put($app, 'outside.php', "<?php\necho 'OUTSIDE-RAN';\n");
        $this->put($app, 'outside.txt', "OUTSIDE-TEXT\n");
        $this->put($app, 'app.php', "<?php\nheader('X-Entry: app');\necho 'ENTRY ' . (\$_SERVER['REQUEST_METHOD'] ?? '') . ' ' . (\$_SERVER['REQUEST_URI'] ?? '');\n");
        if ($helmet) {
            $this->put($app, 'vendor/autoload.php', "<?php\nnamespace Waynestate\\Helmet;\n\nfunction helmet_boot(array \$options): array\n{\n    header('X-Helmet: ' . (\$options['preset'] ?? 'none'));\n\n    return ['nonce' => 'n0nce'];\n}\n");
        }
        foreach ($extra as $path => $content) {
            $this->put($app, $path, $content);
        }

        return $app;
    }

    /**
     * Serves the app with public/index.php as the router; returns the port.
     *
     * @param array<string, string> $env
     */
    private function serve(string $app, array $env = []): int
    {
        $port = $this->freePort();
        $log = sys_get_temp_dir() . '/squire-raw-front-controller.log';
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $app . '/public', $app . '/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $app,
            $env + getenv(),
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
     * @return array{status: int, headers: string, body: string}
     */
    private function request(int $port, string $path, string $method = 'GET'): array
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 5);
        self::assertNotFalse($socket, (string) $error);
        fwrite($socket, "{$method} {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        $parts = explode("\r\n\r\n", $raw, 2);
        $headers = $parts[0] ?? '';
        preg_match('#^HTTP/\S+\s+(\d{3})#m', $headers, $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'headers' => $headers, 'body' => $parts[1] ?? ''];
    }

    public function testServesAStaticFileWithItsType(): void
    {
        $port = $this->serve($this->rawApp());

        $response = $this->request($port, '/style.css');

        self::assertSame(200, $response['status']);
        self::assertMatchesRegularExpression('#^Content-Type: text/css; charset=utf-8#mi', $response['headers']);
        self::assertMatchesRegularExpression('#^ETag: "#mi', $response['headers']);
        self::assertSame("body { color: #0c5449; }\n", $response['body']);
        self::assertStringNotContainsString('ENTRY', $response['body']);
    }

    public function testServedFilesCarryHelmetAndHtmlGetsTheNonce(): void
    {
        $port = $this->serve($this->rawApp([], true));

        $css = $this->request($port, '/style.css');
        self::assertMatchesRegularExpression('#^X-Helmet: citadel#mi', $css['headers']);

        $page = $this->request($port, '/page.html');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<script nonce="n0nce">go()</script>', $page['body']);
    }

    public function testFallsBackToTheEntryForAnythingThatIsNotAFile(): void
    {
        $port = $this->serve($this->rawApp());

        foreach (['/', '/index.php', '/knights/42?sort=name', '/missing.css'] as $path) {
            $response = $this->request($port, $path);
            self::assertSame(200, $response['status'], $path);
            self::assertStringStartsWith('ENTRY GET ', $response['body'], $path);
            self::assertMatchesRegularExpression('#^X-Entry: app#mi', $response['headers'], $path);
        }
        self::assertSame('ENTRY GET /knights/42?sort=name', $this->request($port, '/knights/42?sort=name')['body']);
    }

    public function testAWriteToAFilePathBelongsToTheApp(): void
    {
        $port = $this->serve($this->rawApp());

        $response = $this->request($port, '/style.css', 'POST');

        self::assertSame(200, $response['status']);
        self::assertSame('ENTRY POST /style.css', $response['body']);
    }

    public function testTheEntryBootsHelmetItselfAndIsNeverBootedTwice(): void
    {
        $port = $this->serve($this->rawApp([], true));

        $response = $this->request($port, '/knights');

        self::assertSame(200, $response['status']);
        self::assertStringStartsWith('ENTRY', $response['body']);
        self::assertDoesNotMatchRegularExpression('#^X-Helmet:#mi', $response['headers']);
    }

    public function testSquireAppEntryPointsAtAnotherEntry(): void
    {
        $app = $this->rawApp(['src/bootstrap.php' => "<?php\necho 'BOOTSTRAP ' . (\$_SERVER['REQUEST_URI'] ?? '');\n"]);
        $port = $this->serve($app, ['SQUIRE_APP_ENTRY' => 'src/bootstrap.php']);

        self::assertSame('BOOTSTRAP /knights', $this->request($port, '/knights')['body']);
    }

    public function testAnEntryOutsideTheAppOrMissingIsA500AndNothingRuns(): void
    {
        $port = $this->serve($this->rawApp(), ['SQUIRE_APP_ENTRY' => '../outside.php']);
        $outside = $this->request($port, '/knights');
        self::assertSame(500, $outside['status']);
        self::assertStringNotContainsString('OUTSIDE-RAN', $outside['body']);
        self::assertStringContainsString('SQUIRE_APP_ENTRY', $outside['body']);
        $this->stop();

        $port = $this->serve($this->rawApp(), ['SQUIRE_APP_ENTRY' => 'public/index.php']);
        self::assertSame(500, $this->request($port, '/knights')['status']);
        $this->stop();

        $port = $this->serve($this->rawApp(), ['SQUIRE_APP_ENTRY' => 'nope.php']);
        self::assertSame(500, $this->request($port, '/knights')['status']);
    }

    public function testPathTraversalNeverLeavesPublic(): void
    {
        $port = $this->serve($this->rawApp());

        foreach (['/../outside.txt', '/%2e%2e/outside.txt', '/..%2foutside.txt', '/css/%2e%2e/%2e%2e/outside.txt', '/%2e%2e/app.php', '/.htaccess', '/%2ehtaccess', '/style.css%00.txt'] as $path) {
            $response = $this->request($port, $path);
            self::assertSame(404, $response['status'], $path);
            self::assertStringNotContainsString('ENTRY', $response['body'], $path);
            self::assertStringNotContainsString('OUTSIDE', $response['body'], $path);
            self::assertStringNotContainsString('Options', $response['body'], $path);
        }
    }

    public function testPhpInPublicIsNeverRunOrShown(): void
    {
        $port = $this->serve($this->rawApp());

        foreach (['/secret.php', '/SECRET.PHP', '/secret.phar', '/up.php', '/secret.php/extra'] as $path) {
            $response = $this->request($port, $path);
            self::assertStringNotContainsString('EXECUTED', $response['body'], $path);
            self::assertStringNotContainsString('<?php', $response['body'], $path);
        }
        self::assertSame(404, $this->request($port, '/secret.php')['status']);
        self::assertSame(404, $this->request($port, '/SECRET.PHP')['status']);
        self::assertSame(404, $this->request($port, '/secret.phar')['status']);
    }

    public function testAFileThatIsNotAnAssetIsA404(): void
    {
        $port = $this->serve($this->rawApp(['public/notes.md' => "# private\n"]));

        foreach (['/dump.sql', '/notes.md'] as $path) {
            $response = $this->request($port, $path);
            self::assertSame(404, $response['status'], $path);
            self::assertStringNotContainsString('DROP TABLE', $response['body'], $path);
            self::assertStringNotContainsString('private', $response['body'], $path);
        }
    }

    public function testTheSharedChecksAndMimeMapMatchTheStaticTarget(): void
    {
        $raw = (string) file_get_contents(Squire::templatesDir() . '/raw/public/index.php');
        $static = (string) file_get_contents(Squire::templatesDir() . '/static/public/index.php');

        foreach ([
            'the MIME map' => '/\$mime = \[\n.*?\n\];/s',
            'the path checks' => '/if \(str_contains\(\$path, "\\\\0"\)\) \{.*?\'phar\'\], true\)\) \{\n    \$notFound\(\);\n\}/s',
            'the nonce step' => '/static function \(array \$match\) use \(\$nonce\): string \{.*?\n\s*\},/s',
        ] as $what => $pattern) {
            self::assertSame(1, preg_match($pattern, $static, $fromStatic), 'static: ' . $what);
            self::assertSame(1, preg_match($pattern, $raw, $fromRaw), 'raw: ' . $what);
            self::assertSame(
                preg_replace('/^ +/m', '', $fromStatic[0]),
                preg_replace('/^ +/m', '', $fromRaw[0]),
                $what . ' drifted from the static target',
            );
        }
    }
}

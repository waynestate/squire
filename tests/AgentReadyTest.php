<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;

/**
 * bin/agent-ready.php and the agent-ready kit (ADR-0019): the generator against a small site, run the way a
 * site's CI runs it, and the static front controller's Markdown negotiation against a PHP built-in server.
 */
final class AgentReadyTest extends SquireTestCase
{
    private const PROMPT = "You are a coding agent. Read https://demo.apps.wayne.edu/llms.txt and ask ADVISOR.\n"
        . "Install it with `composer require test/demo`.\n"
        . "Ask me before you guess.";

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

    private static function generator(): string
    {
        return dirname(Squire::templatesDir()) . '/bin/agent-ready.php';
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    private function generate(string ...$args): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::generator()], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function page(string $prompt = self::PROMPT): string
    {
        $escaped = htmlspecialchars($prompt, ENT_NOQUOTES);

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8">
            <title>DEMO: a test page</title>
            <meta name="description" content="A page for the tests.">
            <link rel="alternate" type="text/markdown" href="index.md">
            <link rel="stylesheet" href="assets/agent-ready.css">
            <script src="assets/agent-ready.js" defer></script>
            </head><body>
            <nav>Skip me</nav>
            <main>
            <h1>DEMO</h1>
            <p>Run <code>composer require test/demo</code>, then read <a href="guide.html">the guide</a>.</p>
            <p><span>1</span><em>Spaced</em><span aria-hidden="true">·</span><span>apart</span></p>
            <table><tr><th>Name</th><th>Value</th></tr><tr><td>a|b</td><td>security_until</td></tr></table>
            <section class="ar-prompt" id="prompt-demo" data-ar-prompt>
              <h3 class="ar-prompt__title">Get started with DEMO</h3>
              <p class="ar-prompt__desc">One line.</p>
              <pre class="ar-prompt__text"><code>{$escaped}</code></pre>
            </section>
            </main></body></html>
            HTML;
    }

    /** A repo with a site, a README, the kit, and this generator. */
    private function site(string $prompt = self::PROMPT): string
    {
        $dir = $this->tempDir();
        $this->put($dir, 'composer.json', '{"name":"test/demo","homepage":"https://demo.apps.wayne.edu/","support":{"source":"https://github.com/waynestate/demo"}}');
        $this->put($dir, 'README.md', "# DEMO\n\n**A demo.** See [the site](site/index.html).\n\n<div align=\"center\"><img src=\"x.png\" alt=\"x\"></div>\n\n## Install\n\n```\ncomposer require test/demo\n```\n");
        $this->put($dir, 'site/index.html', $this->page($prompt));
        $this->put($dir, 'site/guide.html', '<!doctype html><title>Guide</title><link rel="alternate" type="text/markdown" href="guide.md"><main><h1>Guide</h1><p>Text.</p></main>');
        $this->put($dir, 'site/hidden.html', '<!doctype html><title>Hidden</title><meta name="robots" content="noindex"><main><p>No.</p></main>');
        $result = $this->generate('--sync-to=' . $dir);
        self::assertSame(0, $result['code'], $result['stderr']);

        return $dir;
    }

    public function testTheRecordedHashesAreTheKitsBytes(): void
    {
        $source = (string) file_get_contents(self::generator());
        $kit = dirname(Squire::templatesDir()) . '/kit/site';
        foreach (['assets/agent-ready.css', 'assets/agent-ready.js'] as $relative) {
            $hash = hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($kit . '/' . $relative)));
            self::assertStringContainsString("'{$relative}' => '{$hash}'", $source, "Run php bin/agent-ready.php --rehash after editing {$relative}.");
        }
    }

    public function testKitFilesCarryNoDashesThatAreNotHyphens(): void
    {
        $kit = dirname(Squire::templatesDir()) . '/kit/site/assets';
        foreach ([self::generator(), $kit . '/agent-ready.css', $kit . '/agent-ready.js'] as $file) {
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', (string) file_get_contents($file), $file);
        }
    }

    public function testWritesTheFilesAndThenChecksClean(): void
    {
        $dir = $this->site();
        $write = $this->generate('--root=' . $dir);
        self::assertSame(0, $write['code'], $write['stderr']);
        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(0, $check['code'], $check['stderr']);

        $twin = (string) file_get_contents($dir . '/site/index.md');
        self::assertStringStartsWith("---\ntitle: \"DEMO: a test page\"\n", $twin);
        self::assertStringContainsString('generator: Squire agent-ready', $twin);
        self::assertStringContainsString('[the guide](https://demo.apps.wayne.edu/guide.html)', $twin);
        self::assertStringContainsString('1 *Spaced* apart', $twin);
        self::assertStringContainsString('| a\|b | security_until |', $twin);
        self::assertStringContainsString("```text\n" . self::PROMPT . "\n```", $twin);
        self::assertStringNotContainsString('Skip me', $twin);
        self::assertFileExists($dir . '/site/guide.md');
        self::assertFileDoesNotExist($dir . '/site/hidden.md');

        $robots = (string) file_get_contents($dir . '/site/robots.txt');
        self::assertStringContainsString("Content-Signal: search=yes, ai-input=yes, ai-train=no\n", $robots);
        self::assertStringContainsString("Sitemap: https://demo.apps.wayne.edu/sitemap.xml\n", $robots);

        $sitemap = (string) file_get_contents($dir . '/site/sitemap.xml');
        self::assertStringContainsString('<loc>https://demo.apps.wayne.edu/</loc>', $sitemap);
        self::assertStringContainsString('<loc>https://demo.apps.wayne.edu/guide.html</loc>', $sitemap);
        self::assertStringNotContainsString('hidden', $sitemap);

        $llms = (string) file_get_contents($dir . '/site/llms.txt');
        self::assertStringStartsWith("# DEMO\n\n> A page for the tests.\n", $llms);
        self::assertStringContainsString('- [DEMO: a test page](https://demo.apps.wayne.edu/index.md)', $llms);
        self::assertStringContainsString('[Get started with DEMO](https://demo.apps.wayne.edu/#prompt-demo)', $llms);
        self::assertStringContainsString('[the site](https://github.com/waynestate/demo/blob/HEAD/site/index.html)', $llms);
        self::assertStringContainsString('https://advisor.apps.wayne.edu/llms.txt', $llms);
        self::assertStringNotContainsString('<img', (string) file_get_contents($dir . '/site/llms-full.txt'));
    }

    public function testCheckFailsOnDriftAndWriteRepairsIt(): void
    {
        $dir = $this->site();
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        file_put_contents($dir . '/site/llms.txt', "# edited by hand, by SQUIRE's agent-ready kit\n");

        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(1, $check['code']);
        self::assertStringContainsString('drift: llms.txt (out of date)', $check['stderr']);

        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        self::assertSame(0, $this->generate('--root=' . $dir, '--check')['code']);
    }

    public function testCheckFailsWhenTheKitIsEditedInTheSite(): void
    {
        $dir = $this->site();
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        file_put_contents($dir . '/site/assets/agent-ready.css', "/* forked */\n", FILE_APPEND);

        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(1, $check['code']);
        self::assertStringContainsString("assets/agent-ready.css differs from SQUIRE's kit", $check['stderr']);
    }

    public function testCheckFailsAPromptThatDriftsFromTheDocsOrBreaksAShell(): void
    {
        $dir = $this->site("Do the thing with `composer require other/package` and say \"hi\".");
        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(1, $check['code']);
        self::assertStringContainsString('runs `composer require other/package`, which is not in the README', $check['stderr']);
        self::assertStringContainsString('contains a double quote', $check['stderr']);
        self::assertStringContainsString('does not point at https://demo.apps.wayne.edu/llms.txt', $check['stderr']);
        self::assertStringContainsString('does not point at ADVISOR', $check['stderr']);
    }

    public function testCheckFailsAPageWithoutItsMarkdownLink(): void
    {
        $dir = $this->site();
        $this->put($dir, 'site/guide.html', '<!doctype html><title>Guide</title><main><p>Text.</p></main>');
        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(1, $check['code']);
        self::assertStringContainsString('guide.html: add <link rel="alternate" type="text/markdown" href="guide.md">', $check['stderr']);
    }

    public function testAHandWrittenRobotsFileIsNeverOverwritten(): void
    {
        $dir = $this->site();
        $this->put($dir, 'site/robots.txt', "User-agent: *\nDisallow:\n");
        $write = $this->generate('--root=' . $dir);
        self::assertSame(1, $write['code']);
        self::assertStringContainsString('robots.txt exists and was not written by this tool', $write['stderr']);
        self::assertSame("User-agent: *\nDisallow:\n", file_get_contents($dir . '/site/robots.txt'));
    }

    public function testATwinWhosePageIsGoneIsRemoved(): void
    {
        $dir = $this->site();
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        unlink($dir . '/site/guide.html');
        $this->put($dir, 'site/index.html', str_replace('guide.html', 'index.html', $this->page()));

        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(1, $check['code']);
        self::assertStringContainsString('drift: guide.md (no longer generated)', $check['stderr']);
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        self::assertFileDoesNotExist($dir . '/site/guide.md');
    }

    public function testALegacyLabelIsStillRecognised(): void
    {
        $dir = $this->site();
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        // An older release wrote the label in capitals; a file headed with it is still this tool's.
        $twin = $dir . '/site/guide.md';
        file_put_contents($twin, str_replace('generator: Squire agent-ready', 'generator: SQUIRE agent-ready', (string) file_get_contents($twin)));
        unlink($dir . '/site/guide.html');
        $this->put($dir, 'site/index.html', str_replace('guide.html', 'index.html', $this->page()));

        $check = $this->generate('--root=' . $dir, '--check');
        self::assertSame(1, $check['code']);
        self::assertStringContainsString('drift: guide.md (no longer generated)', $check['stderr']);
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        self::assertFileDoesNotExist($twin);
    }

    public function testSyncRefusesFromACopyWithoutTheKit(): void
    {
        $dir = $this->site();
        $process = proc_open(
            [PHP_BINARY, $dir . '/bin/agent-ready.php', '--sync-to=' . $dir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(2, proc_close($process));
        self::assertStringContainsString('--sync-to runs from SQUIRE', $stderr);
    }

    // ------------------------------------------------------------ the front controller

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** A static app: deploy/public/index.php, a HELMET stub, and site/ beside it; returns the port. */
    private function serveStatic(): int
    {
        $dir = $this->site();
        self::assertSame(0, $this->generate('--root=' . $dir)['code']);
        $this->put($dir, 'site/README.md', "# private\n");
        $this->put($dir, 'deploy/public/index.php', (string) file_get_contents(Squire::templatesDir() . '/static/public/index.php'));
        $this->put($dir, 'deploy/vendor/autoload.php', "<?php\nnamespace Waynestate\\Helmet;\n\nfunction helmet_boot(array \$options): array\n{\n    return ['nonce' => 'n0nce'];\n}\n");

        $port = $this->freePort();
        $log = sys_get_temp_dir() . '/squire-agent-ready-server.log';
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/deploy/public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $dir . '/deploy/public',
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

    /** @return array{status: int, headers: string, body: string} */
    private function get(int $port, string $path, string $accept = ''): array
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 5);
        self::assertNotFalse($socket, (string) $error);
        $request = "GET {$path} HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        if ($accept !== '') {
            $request .= "Accept: {$accept}\r\n";
        }
        fwrite($socket, $request . "\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        $parts = explode("\r\n\r\n", $raw, 2);
        preg_match('#^HTTP/\S+\s+(\d{3})#', $parts[0], $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'headers' => $parts[0], 'body' => $parts[1] ?? ''];
    }

    public function testTheFrontControllerNegotiatesTheTwin(): void
    {
        $port = $this->serveStatic();

        $html = $this->get($port, '/', 'text/html,application/xhtml+xml,*/*;q=0.8');
        self::assertSame(200, $html['status']);
        self::assertStringContainsString('Content-Type: text/html', $html['headers']);
        self::assertStringContainsString('Link: </index.md>; rel="alternate"; type="text/markdown"', $html['headers']);
        self::assertStringContainsString('Vary: Accept', $html['headers']);
        self::assertStringContainsString('<script nonce="n0nce"', $html['body']);

        $markdown = $this->get($port, '/', 'text/markdown');
        self::assertSame(200, $markdown['status']);
        self::assertStringContainsString('Content-Type: text/markdown; charset=utf-8', $markdown['headers']);
        self::assertStringContainsString('Content-Location: /index.md', $markdown['headers']);
        self::assertStringStartsWith("---\ntitle:", $markdown['body']);

        $preferred = $this->get($port, '/guide.html', 'text/markdown, text/html;q=0.5');
        self::assertStringContainsString('Content-Type: text/markdown', $preferred['headers']);
        $htmlFirst = $this->get($port, '/guide.html', 'text/html, text/markdown;q=0.5');
        self::assertStringContainsString('Content-Type: text/html', $htmlFirst['headers']);

        $byName = $this->get($port, '/index.md');
        self::assertSame(200, $byName['status']);
        self::assertStringContainsString('Content-Type: text/markdown', $byName['headers']);

        self::assertSame(404, $this->get($port, '/README.md')['status']);

        foreach (['/robots.txt', '/llms.txt', '/llms-full.txt'] as $path) {
            $file = $this->get($port, $path);
            self::assertSame(200, $file['status'], $path);
            self::assertStringContainsString('Content-Type: text/plain; charset=utf-8', $file['headers'], $path);
        }
        $sitemap = $this->get($port, '/sitemap.xml');
        self::assertSame(200, $sitemap['status']);
        self::assertStringContainsString('Content-Type: application/xml', $sitemap['headers']);
    }
}

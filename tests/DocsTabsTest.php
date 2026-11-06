<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * Code tabs in the docs builder: consecutive fenced blocks whose info string carries tab="LABEL" become one
 * labeled set, every panel visible and named without JavaScript, and a lone such block stays a plain code block.
 */
final class DocsTabsTest extends SquireTestCase
{
    private static function generator(): string
    {
        return dirname(Squire::templatesDir()) . '/bin/docs-site.php';
    }

    /** @return array{code: int, stdout: string, stderr: string} */
    private function invoke(array $args): array
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

    /** A temp copy of the docs-site fixture with the generator and kit synced in. */
    private function repo(): string
    {
        $dir = $this->tempDir();
        $this->copyTree(dirname(__DIR__) . '/tests/fixtures/docs-site', $dir);
        $result = $this->invoke(['--sync-to=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        return $dir;
    }

    private function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
            $this->put($to, $relative, (string) file_get_contents($file->getPathname()));
        }
    }

    /** Adds a docs page under docs/ and a nav entry for it, so the build stays whole. */
    private function guidePage(string $dir, string $path, string $title, string $content): void
    {
        $this->put($dir, 'docs/' . $path . '.md', $content);
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['items'][] = ['title' => $title, 'path' => $path];
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
    }

    /** Builds the repo clean and returns the rendered HTML of the given guide page. */
    private function builtPage(string $dir, string $path): string
    {
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringNotContainsString('finding:', $result['stderr']);

        return (string) file_get_contents($dir . '/site/docs/' . $path . '.html');
    }

    public function testConsecutiveTabFencesBecomeOneSet(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\nPick a runner.\n\n```bash tab=\"composer\"\ncomposer install\n```\n\n```bash tab=\"sail\"\n./vendor/bin/sail up\n```\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        self::assertStringContainsString('<div class="docs-tabs" data-tabs="composer|sail">', $page);
        self::assertStringContainsString(
            "<div class=\"docs-tabs__panel\" data-tab-label=\"composer\"><p class=\"docs-tabs__label\">composer</p><pre tabindex=\"0\"><code class=\"language-bash\">composer install\n</code></pre></div>",
            $page,
        );
        self::assertStringContainsString(
            "<div class=\"docs-tabs__panel\" data-tab-label=\"sail\"><p class=\"docs-tabs__label\">sail</p><pre tabindex=\"0\"><code class=\"language-bash\">./vendor/bin/sail up\n</code></pre></div>",
            $page,
        );
        // One set holds both panels: between the set opening and the second panel only the first panel appears.
        $start = strpos($page, '<div class="docs-tabs" data-tabs="composer|sail">');
        $second = strpos($page, '<div class="docs-tabs__panel" data-tab-label="sail">');
        self::assertIsInt($start);
        self::assertIsInt($second);
        $between = substr($page, $start, $second - $start);
        self::assertSame(1, substr_count($between, 'docs-tabs__panel'));
        self::assertSame(1, substr_count($between, '<pre tabindex="0">'));
    }

    public function testKeyIsLowercasedLabelsJoinedByPipe(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\n```sh tab=\"Composer\"\necho one\n```\n\n```sh tab=\"SAIL\"\necho two\n```\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        self::assertStringContainsString('<div class="docs-tabs" data-tabs="composer|sail">', $page);
        self::assertStringContainsString('data-tab-label="Composer"', $page);
        self::assertStringContainsString('data-tab-label="SAIL"', $page);
    }

    public function testPanelsAreLabeledAndVisibleWithoutJs(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\n```bash tab=\"one\"\necho one\n```\n\n```bash tab=\"two\"\necho two\n```\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        self::assertStringContainsString('<p class="docs-tabs__label">one</p>', $page);
        self::assertStringContainsString('<p class="docs-tabs__label">two</p>', $page);
        // No hidden attribute on a panel or its label: without JavaScript every example stays readable.
        self::assertDoesNotMatchRegularExpression('/docs-tabs__panel[^>]*hidden/', $page);
        self::assertDoesNotMatchRegularExpression('/docs-tabs__label"[^>]*hidden/', $page);
    }

    public function testLoneTabFenceIsAPlainBlock(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\nA lone tab fence is a plain block.\n\n```bash tab=\"composer\"\ncomposer install\n```\n\nAfter the block.\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        self::assertStringNotContainsString('docs-tabs', $page);
        self::assertStringContainsString("<pre tabindex=\"0\"><code class=\"language-bash\">composer install\n</code></pre>", $page);
        self::assertStringNotContainsString('tab=', $page);
    }

    public function testSeparatedTabFencesAreSeparateSets(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\n```bash tab=\"a\"\necho a\n```\n\n```bash tab=\"b\"\necho b\n```\n\nA paragraph breaks the run.\n\n```bash tab=\"c\"\necho c\n```\n\n```bash tab=\"d\"\necho d\n```\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        self::assertSame(2, substr_count($page, '<div class="docs-tabs" '));
        self::assertStringContainsString('data-tabs="a|b"', $page);
        self::assertStringContainsString('data-tabs="c|d"', $page);
        self::assertSame(4, substr_count($page, 'docs-tabs__panel'));
    }

    public function testCodeIsEscapedAndLanguageClassKept(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\n```html tab=\"markup\"\n<b>&\"q\"</b>\n```\n\n```html tab=\"plain\"\n<p>i</p>\n```\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        // Escaped exactly like a plain fenced block, and the language class survives the tab="..." word.
        self::assertStringContainsString('<code class="language-html">', $page);
        self::assertStringContainsString('&lt;b&gt;&amp;&quot;q&quot;&lt;/b&gt;', $page);
        self::assertStringContainsString('&lt;p&gt;i&lt;/p&gt;', $page);
        self::assertStringNotContainsString('<b>&"q"</b>', $page);
        self::assertSame(2, substr_count($page, '<code class="language-html">'));
    }

    public function testTabLabelsMayContainSpaces(): void
    {
        $dir = $this->repo();
        $this->guidePage($dir, 'guides/tabs', 'Tabs', "# Tabs\n\n## Linux / macOS\n\n```bash tab=\"Linux / macOS\"\necho hello\n```\n\n```powershell tab=\"Windows\"\nWrite-Output hello\n```\n");
        $page = $this->builtPage($dir, 'guides/tabs');
        // One set keyed by the lowercased labels, and both tab buttons read their full labels.
        self::assertStringContainsString('<div class="docs-tabs" data-tabs="linux / macos|windows">', $page);
        self::assertStringContainsString(
            "<div class=\"docs-tabs__panel\" data-tab-label=\"Linux / macOS\"><p class=\"docs-tabs__label\">Linux / macOS</p><pre tabindex=\"0\"><code class=\"language-bash\">echo hello\n</code></pre></div>",
            $page,
        );
        self::assertStringContainsString(
            "<div class=\"docs-tabs__panel\" data-tab-label=\"Windows\"><p class=\"docs-tabs__label\">Windows</p><pre tabindex=\"0\"><code class=\"language-powershell\">Write-Output hello\n</code></pre></div>",
            $page,
        );
        // The ids the builder derives from these labels stay valid: the slug of a spaced label has no space,
        // and no id attribute on the page carries one.
        self::assertStringContainsString('id="linux-macos"', $page);
        self::assertDoesNotMatchRegularExpression('/\bid="[^"]*\s/', $page);
    }

    public function testTabInfoSpecEdges(): void
    {
        require_once dirname(__DIR__) . '/bin/docs-site.php';
        $class = new \ReflectionClass(\DocsSite::class);
        $site = $class->newInstanceWithoutConstructor();
        $method = $class->getMethod('tabInfo');
        $tabInfo = static fn(string $info): array => $method->invoke($site, $info);
        $forty = str_repeat('a', 40);
        $cases = [
            ['bash tab="Linux / macOS"', ['bash', 'Linux / macOS', 'bash']],
            ['powershell tab="Windows"', ['powershell', 'Windows', 'powershell']],
            ['bash tab=""', ['bash', null, 'bash']],
            ['bash tab="   "', ['bash', null, 'bash']],
            ['bash tab="' . str_repeat('a', 41) . '"', ['bash', null, 'bash']],
            ['bash tab="' . $forty . '"', ['bash', $forty, 'bash']],
            ['bash tab="a"', ['bash', 'a', 'bash']],
            ['tab="Linux / macOS" bash', ['bash', 'Linux / macOS', 'bash']],
            ['bash run tab="Linux / macOS"', ['bash', 'Linux / macOS', 'bash run']],
            ['bash exit=1 tab="Windows"', ['bash', 'Windows', 'bash exit=1']],
            ['sh tab="a" b', ['sh', 'a', 'sh b']],
            ['bash', ['bash', null, 'bash']],
            ['', ['', null, '']],
            ['bash tab="a" tab="b"', ['bash', 'a', 'bash tab="b"']],
        ];
        foreach ($cases as [$info, $want]) {
            self::assertSame($want, $tabInfo($info), 'info: ' . $info);
        }
    }
}

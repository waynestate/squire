<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Waynestate\Squire\Squire;

/**
 * The docs builder's theme hook: the optional theme key of docs/docs.json wears the site's brand on the pages, a css
 * file copied to site/docs/theme/ and linked after docs.css, and a header partial that replaces the generic docs
 * header, run the way a repo's CI runs the builder.
 */
final class DocsThemeTest extends SquireTestCase
{
    /** A small, valid theme header partial: a mark, the project name, and a site nav with a docs home link. */
    private const HEADER = "<header class=\"site-header\">\n"
        . "  <a class=\"site-mark\" href=\"index.html#top\"><img src=\"brand/mark.png\" alt=\"\"></a>\n"
        . "  <span class=\"site-name\">{{project}}</span>\n"
        . "  <nav aria-label=\"Site\">\n"
        . "    <a href=\"docs/index.html\">Docs</a>\n"
        . "  </nav>\n"
        . "</header>\n";

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

    /** Writes docs/docs.json with the given top-level keys. */
    private function docsJson(string $dir, array $config): void
    {
        $this->put($dir, 'docs/docs.json', json_encode($config, JSON_PRETTY_PRINT) . "\n");
    }

    /** Every file under site/docs/, as relative path => content, for whole-tree comparison. */
    private function tree(string $dir): array
    {
        $root = $dir . '/site/docs';
        $out = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $out[$relative] = (string) file_get_contents($file->getPathname());
        }
        ksort($out);

        return $out;
    }

    /** The first header element of a built page: the theme header, once the generic one is replaced. */
    private function headerOf(string $html): string
    {
        if (preg_match('/<header\b.*?<\/header>/s', $html, $m) !== 1) {
            self::fail('the built page has no header element');
        }

        return $m[0];
    }

    public function testNoThemeIsByteIdenticalToAThemelessBuild(): void
    {
        $plain = $this->repo();
        $result = $this->invoke(['--root=' . $plain]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $before = $this->tree($plain);

        // An empty theme key changes nothing, even with theme files present but unconfigured.
        $themed = $this->repo();
        $this->put($themed, 'docs/theme.css', '/* theme */' . "\n");
        $this->put($themed, 'docs/_header.html', self::HEADER);
        $this->docsJson($themed, ['theme' => (object) []]);
        $result = $this->invoke(['--root=' . $themed]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame($before, $this->tree($themed));
    }

    public function testCssOnlyIsCopiedAndLinkedAfterDocsCss(): void
    {
        $dir = $this->repo();
        $css = "/* theme */\n:root { --dk-brand: #123456; }\n";
        $this->put($dir, 'docs/theme.css', $css);
        $this->docsJson($dir, ['theme' => ['css' => 'theme.css']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame($css, (string) file_get_contents($dir . '/site/docs/theme/theme.css'));

        $root = (string) file_get_contents($dir . '/site/docs/index.html');
        $nested = (string) file_get_contents($dir . '/site/docs/guides/start.html');
        self::assertStringContainsString('<link rel="stylesheet" href="../assets/docs.css">' . "\n" . '<link rel="stylesheet" href="../docs/theme/theme.css">', $root);
        self::assertStringContainsString('<link rel="stylesheet" href="../../assets/docs.css">' . "\n" . '<link rel="stylesheet" href="../../docs/theme/theme.css">', $nested);
        // The generic header stays when only the css is themed.
        self::assertStringContainsString('<header class="docs-header">', $root);
    }

    public function testCssInASubfolderFlattensToItsBasename(): void
    {
        $dir = $this->repo();
        $css = '/* sub */' . "\n";
        $this->put($dir, 'docs/theme/site.css', $css);
        $this->docsJson($dir, ['theme' => ['css' => 'theme/site.css']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame($css, (string) file_get_contents($dir . '/site/docs/theme/site.css'));
        $root = (string) file_get_contents($dir . '/site/docs/index.html');
        self::assertStringContainsString('<link rel="stylesheet" href="../docs/theme/site.css">', $root);
    }

    public function testCheckFailsWhenTheThemeCssDriftsOrIsMissing(): void
    {
        $dir = $this->repo();
        $css = '/* theme */' . "\n";
        $this->put($dir, 'docs/theme.css', $css);
        $this->docsJson($dir, ['theme' => ['css' => 'theme.css']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(0, $result['code'], $result['stderr']);

        file_put_contents($dir . '/site/docs/theme/theme.css', $css . 'tampered');
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/docs/theme/theme.css (out of date)', $result['stderr']);

        unlink($dir . '/site/docs/theme/theme.css');
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/docs/theme/theme.css (missing)', $result['stderr']);
    }

    public function testRemovedThemeCssIsALeftover(): void
    {
        $dir = $this->repo();
        $css = '/* theme */' . "\n";
        $this->put($dir, 'docs/theme.css', $css);
        $this->docsJson($dir, ['theme' => ['css' => 'theme.css']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        // Drop the css from docs.json while the copy remains: --check reports the leftover, a plain build removes it.
        $this->docsJson($dir, ['theme' => (object) []]);
        $result = $this->invoke(['--root=' . $dir, '--check']);
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('stale: site/docs/theme/theme.css (no longer generated)', $result['stderr']);

        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertFileDoesNotExist($dir . '/site/docs/theme/theme.css');
    }

    public function testHeaderOnlyReplacesTheGenericHeader(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'docs/_header.html', self::HEADER);
        $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        $root = (string) file_get_contents($dir . '/site/docs/index.html');
        $nested = (string) file_get_contents($dir . '/site/docs/guides/start.html');
        foreach (['root' => $root, 'nested' => $nested] as $label => $page) {
            self::assertStringNotContainsString('docs-header', $page, $label);
            self::assertStringContainsString('<header class="site-header">', $page, $label);
        }
        // The skip link is still the first element in the body, right before the theme header.
        self::assertStringContainsString('<a class="wsu-skip-link" href="#main">Skip to content</a>' . "\n" . '<header class="site-header">', $root);
        // The mobile menu and the sidebar nav stay.
        self::assertStringContainsString('<details class="docs-menu">', $root);
        self::assertStringContainsString('docs-nav--sidebar', $root);
    }

    public function testHeaderLinksAreRewrittenToThePageDepth(): void
    {
        $dir = $this->repo();
        $partial = '<header class="t">' . "\n"
            . '<a href="#frag"></a>' . "\n"
            . '<a href="https://example.com/x"></a>' . "\n"
            . '<a href="mailto:a@example.com"></a>' . "\n"
            . '<a href="//cdn.example.com/x"></a>' . "\n"
            . '<a href="/abs.html"></a>' . "\n"
            . '<a href="index.html#start"></a>' . "\n"
            . '<a href="docs/index.html"></a>' . "\n"
            . '<img src="brand/mark-256.png" alt="">' . "\n"
            . '<img srcset="brand/m-1x.png 1x, brand/m-2x.png 2x" alt="">' . "\n"
            . '</header>' . "\n";
        $this->put($dir, 'docs/_header.html', $partial);
        $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        $root = $this->headerOf((string) file_get_contents($dir . '/site/docs/index.html'));
        $nested = $this->headerOf((string) file_get_contents($dir . '/site/docs/guides/start.html'));
        // Fragment, scheme, protocol-relative and absolute URLs are left alone on every page.
        foreach (['root' => $root, 'nested' => $nested] as $label => $page) {
            self::assertStringContainsString('href="#frag"', $page, $label);
            self::assertStringContainsString('href="https://example.com/x"', $page, $label);
            self::assertStringContainsString('href="mailto:a@example.com"', $page, $label);
            self::assertStringContainsString('href="//cdn.example.com/x"', $page, $label);
            self::assertStringContainsString('href="/abs.html"', $page, $label);
        }
        // Relative URLs climb to site/ at the page depth, each srcset url on its own.
        self::assertStringContainsString('href="../index.html#start"', $root);
        self::assertStringContainsString('src="../brand/mark-256.png"', $root);
        self::assertStringContainsString('srcset="../brand/m-1x.png 1x, ../brand/m-2x.png 2x"', $root);
        self::assertStringContainsString('href="../../index.html#start"', $nested);
        self::assertStringContainsString('src="../../brand/mark-256.png"', $nested);
        self::assertStringContainsString('srcset="../../brand/m-1x.png 1x, ../../brand/m-2x.png 2x"', $nested);
    }

    public function testAriaCurrentMarksOnlyTheFirstDocsHomeLink(): void
    {
        $dir = $this->repo();
        $partial = "<header class=\"t\">\n"
            . '<a href="docs/index.html">Docs</a>' . "\n"
            . '<a href="docs/">Docs home</a>' . "\n"
            . '<a href="index.html#top">Site</a>' . "\n"
            . '</header>' . "\n";
        $this->put($dir, 'docs/_header.html', $partial);
        $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        foreach (['site/docs/index.html', 'site/docs/guides/start.html'] as $page) {
            $header = $this->headerOf((string) file_get_contents($dir . '/' . $page));
            self::assertSame(1, substr_count($header, 'aria-current'), $page);
            self::assertStringContainsString('aria-current="page">Docs</a>', $header, $page);
            self::assertStringNotContainsString('aria-current="page">Docs home</a>', $header, $page);
            self::assertStringNotContainsString('aria-current="page">Site</a>', $header, $page);
        }
    }

    public function testAriaCurrentIsLeftAloneWhenPresent(): void
    {
        $dir = $this->repo();
        $partial = '<header class="t"><a href="docs/index.html" aria-current="true">Docs</a></header>' . "\n";
        $this->put($dir, 'docs/_header.html', $partial);
        $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        $header = $this->headerOf((string) file_get_contents($dir . '/site/docs/index.html'));
        self::assertSame(1, substr_count($header, 'aria-current'));
        self::assertStringContainsString('aria-current="true"', $header);
        self::assertStringNotContainsString('aria-current="page"', $header);
    }

    public function testPlaceholdersAreFilledAndEscaped(): void
    {
        $dir = $this->repo();
        // A project title with HTML in it, so the escaping is visible.
        /** @var array<string, mixed> $nav */
        $nav = json_decode((string) file_get_contents($dir . '/docs/nav.json'), true);
        $nav['title'] = 'Acme & Co <Co>';
        $this->put($dir, 'docs/nav.json', json_encode($nav, JSON_PRETTY_PRINT) . "\n");
        $partial = '<header class="t"><span>{{project}}</span><span>{{version}}</span></header>' . "\n";
        $this->put($dir, 'docs/_header.html', $partial);
        $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(0, $result['code'], $result['stderr']);

        $root = (string) file_get_contents($dir . '/site/docs/index.html');
        self::assertStringContainsString('<span>Acme &amp; Co &lt;Co&gt;</span><span>1.2.3</span>', $root);
    }

    public function testUnknownPlaceholderFailsTheBuild(): void
    {
        $dir = $this->repo();
        $this->put($dir, 'docs/_header.html', '<header class="t">{{other}}</header>' . "\n");
        $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
        $result = $this->invoke(['--root=' . $dir]);
        self::assertSame(2, $result['code']);
        self::assertStringContainsString('{{other}}', $result['stderr']);
    }

    public function testBrokenThemeConfigsFailNamingTheKey(): void
    {
        $cases = [
            [['theme' => 'theme.css'], 'the "theme" key must be an object'],
            [['theme' => ['theme.css']], 'the "theme" key must be an object'],
            [['theme' => ['css' => 'theme.css', 'font' => 'font.css']], 'the theme key "font" is unknown'],
            [['theme' => ['css' => 42]], 'theme.css must be a non-empty string'],
            [['theme' => ['css' => '']], 'theme.css must be a non-empty string'],
            [['theme' => ['css' => '/theme.css']], 'theme.css must be a plain relative path'],
            [['theme' => ['css' => 'theme\\x.css']], 'theme.css must be a plain relative path'],
            [['theme' => ['css' => 'C:/x.css']], 'theme.css must be a plain relative path'],
            [['theme' => ['css' => '../theme.css']], 'theme.css must be a plain relative path'],
            [['theme' => ['css' => 'missing.css']], 'theme.css points at docs/missing.css, which does not exist'],
            [['theme' => ['header' => 'missing.html']], 'theme.header points at docs/missing.html, which does not exist'],
        ];
        foreach ($cases as [$config, $needle]) {
            $dir = $this->repo();
            $this->put($dir, 'docs/theme.css', '/* t */' . "\n");
            $this->put($dir, 'docs/_header.html', self::HEADER);
            $this->docsJson($dir, $config);
            $result = $this->invoke(['--root=' . $dir]);
            self::assertSame(2, $result['code'], json_encode($config));
            self::assertStringContainsString($needle, $result['stderr']);
        }
    }

    public function testHeaderGuardsFailTheBuild(): void
    {
        $cases = [
            ['<div class="t">no header here</div>' . "\n", 'exactly one <header> element'],
            ["<header class=\"a\">A</header>\n<header class=\"b\">B</header>\n", 'exactly one <header> element'],
            ['<header class="a">never closed' . "\n", 'one closed <header> element'],
            ['<header class="t"><script>var x = 1;</script></header>' . "\n", 'a <script> element'],
            ['<header class="t" id="main"></header>' . "\n", 'an id="main" attribute'],
            ['<header class="t"><a class="wsu-skip-link" href="#x">Skip</a></header>' . "\n", 'a wsu-skip-link'],
            ['<header class="t"><details class="docs-menu">Menu</details></header>' . "\n", 'a docs-menu'],
            ['<header class="t"><main>Body</main></header>' . "\n", 'a <main> element'],
        ];
        foreach ($cases as [$partial, $needle]) {
            $dir = $this->repo();
            $this->put($dir, 'docs/_header.html', $partial);
            $this->docsJson($dir, ['theme' => ['header' => '_header.html']]);
            $result = $this->invoke(['--root=' . $dir]);
            self::assertSame(2, $result['code'], $partial);
            self::assertStringContainsString($needle, $result['stderr']);
        }
    }
}

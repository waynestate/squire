<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use ReflectionMethod;
use Waynestate\Squire\Doctor\Project;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Exporter;

/**
 * The scandir walks behind the four tools that used to lean on PHP's recursive directory iterator, which lists only
 * some files on Docker Desktop mounts. One nested tree per consumer: a folder with more than forty files, deep
 * nesting, dot entries and the skip-list folders, asserting exactly what each walk sees.
 */
final class DirectoryWalkTest extends SquireTestCase
{
    /** The agent-ready fixture's guide pages in site/guides/. */
    private const GUIDES = 45;

    /** The docs fixture's guide sources in docs/guides/. */
    private const DOC_PAGES = 41;

    /** The docs fixture's built pages in site/docs/guides/. */
    private const BUILT = 42;

    /** The doctor fixture's busy folder files in lots/. */
    private const DOCTOR_LOTS = 44;

    /** The agent-ready fixture's base URL, from composer.json's homepage. */
    private const BASE = 'https://walk.apps.wayne.edu/';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/bin/agent-ready.php';
        require_once dirname(__DIR__) . '/bin/docs-site.php';
    }

    public function testAgentReadyDiscoversEveryPageAndOnlyThem(): void
    {
        $dir = $this->agentReadyRepo();
        $result = $this->generate('--root=' . $dir);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        // llms.txt lists every discovered page and nothing else, front page first then path order.
        $llms = (string) file_get_contents($dir . '/site/llms.txt');
        $pagesSection = preg_split('/^## /m', $llms)[1] ?? '';
        self::assertStringStartsWith('Pages', $pagesSection);
        preg_match_all('/^- \[[^\]]*\]\(([^)]+)\)/m', $pagesSection, $matches);
        $expected = [];
        foreach (self::sitePages() as $relative) {
            $expected[] = self::BASE . substr($relative, 0, -5) . '.md';
        }
        self::assertSame($expected, $matches[1]);

        // Twins were written for every discovered page and for nothing skipped.
        self::assertFileExists($dir . '/site/guides/g45.md');
        self::assertFileExists($dir . '/site/guides/deep/nested/leaf.md');
        self::assertFileDoesNotExist($dir . '/site/assets/skipme.md');
        self::assertFileDoesNotExist($dir . '/site/brand/logo.md');
        self::assertFileDoesNotExist($dir . '/site/.dotfile.md');

        // The walk itself: forward slashes, sorted, skip-list and dot entries out.
        $walk = new ReflectionMethod(\AgentReady::class, 'walk');
        $sorted = self::sitePages();
        sort($sorted);
        self::assertSame($sorted, $walk->invoke(null, (new \AgentReady($dir))->siteDir(), 'html'));
    }

    public function testTheDocsBuilderWalksSourcesAndBuiltSite(): void
    {
        $dir = $this->docsRoot();
        $walk = new ReflectionMethod(\DocsSite::class, 'walk');

        // Dot and underscore entries stay out, deep nesting stays in, everything sorted.
        self::assertSame(self::docPages(), $walk->invoke(null, $dir . '/docs', 'md'));
        self::assertSame(self::builtPages(), $walk->invoke(null, $dir . '/site/docs', 'html'));

        // The examples walk is the same walk under the other class.
        $examplesWalk = new ReflectionMethod(\DocsExamples::class, 'walk');
        self::assertSame(self::docPages(), $examplesWalk->invoke(null, $dir . '/docs', 'md'));

        // The theme scan skips nothing: hidden theme files are leftovers too.
        $themeFiles = new ReflectionMethod(\DocsSite::class, 'themeFiles');
        self::assertSame(['.hidden/h.css', 'sub/two.css', 'theme.css'], $themeFiles->invoke(null, $dir . '/site/docs/theme'));

        // Leftovers ties the two together: every marked built page plus every theme file.
        $expected = [];
        foreach (self::builtPages() as $page) {
            $expected[] = 'site/docs/' . $page;
        }
        $expected[] = 'site/docs/theme/.hidden/h.css';
        $expected[] = 'site/docs/theme/sub/two.css';
        $expected[] = 'site/docs/theme/theme.css';
        sort($expected);
        self::assertSame($expected, (new \DocsSite($dir))->leftovers([], []));
    }

    public function testTheExamplesCopyNestedFilesAndCleanUp(): void
    {
        $dir = $this->docsRoot();
        $before = $this->exampleDirs();

        $result = (new \DocsExamples($dir))->run();

        // Both blocks ran against the copied cwd: the nested file and the hidden one came across, vendor did not matter.
        self::assertSame([], $result['findings'], implode("\n", $result['findings']));
        self::assertSame('Examples: 2 run, 0 skipped', $result['summary']);
        self::assertSame($before, $this->exampleDirs(), 'the example working directory was left behind');
    }

    public function testTheExporterSeesEveryNestedFile(): void
    {
        $dir = $this->tempDir();
        $exporter = new Exporter(new Catalog(Squire::templatesDir()));
        $exporter->export($dir);
        self::assertSame([], $exporter->check($dir));

        // Strays nested where an iterator might miss them; .git stays invisible.
        $this->put($dir, 'raw/deep/nested/extra.txt', "stray\n");
        $this->put($dir, 'laravel/scripts/parity-notes.md', "stray\n");
        $this->put($dir, '.git/hooks/post-commit', "stray\n");

        self::assertSame([
            'laravel/scripts/parity-notes.md (unexpected)',
            'raw/deep/nested/extra.txt (unexpected)',
        ], $exporter->check($dir));
    }

    public function testTheDoctorSeesEveryNestedFile(): void
    {
        $dir = $this->doctorApp();
        $expected = [
            '/.hidden/secret.php',
            '/public/index.php',
            '/src/App/Home.php',
            '/src/App/Service/Deep/Leaf.php',
            '/src/App/Service/Greeter.php',
            '/vendor/composer/installed.php',
        ];
        for ($n = 1; $n <= self::DOCTOR_LOTS; $n++) {
            $expected[] = '/lots/l' . self::pad($n) . '.php';
        }
        sort($expected);

        // No dot or vendor skipping: the doctor sees what is there.
        self::assertSame($expected, (new Project($dir))->filesUnder('', ['php']));
    }

    public function testTheDoctorLimitStopsTheWalk(): void
    {
        $dir = $this->doctorApp();

        // scandir lists alphabetically, so the capped set is deterministic: the first five in walk order.
        self::assertSame([
            '/.hidden/secret.php',
            '/lots/l01.php',
            '/lots/l02.php',
            '/lots/l03.php',
            '/lots/l04.php',
        ], (new Project($dir))->filesUnder('', ['php'], 5));
    }

    /** A repo whose site exercises the agent-ready walk: a busy folder, deep nesting, skip-list and dot entries. */
    private function agentReadyRepo(): string
    {
        $dir = $this->tempDir();
        $this->put($dir, 'composer.json', '{"name":"test/walk","homepage":"https://walk.apps.wayne.edu/","support":{"source":"https://github.com/waynestate/walk"}}');
        $this->put($dir, 'README.md', "# Walk\n\n**A test site.**\n\n## Install\n\n```\ncomposer require test/walk\n```\n");
        $this->put($dir, 'site/index.html', $this->frontPage());
        for ($n = 1; $n <= self::GUIDES; $n++) {
            $this->put($dir, 'site/guides/g' . self::pad($n) . '.html', $this->guidePage('Guide ' . $n, 'g' . self::pad($n) . '.html'));
        }
        $this->put($dir, 'site/guides/deep/nested/leaf.html', $this->guidePage('Leaf', 'leaf.html'));
        // Skip-list folders and dot entries: none of these may be discovered.
        $this->put($dir, 'site/assets/skipme.html', $this->guidePage('Skipped', 'skipme.html'));
        $this->put($dir, 'site/brand/logo.html', $this->guidePage('Skipped', 'logo.html'));
        $this->put($dir, 'site/.hiddendir/hidden.html', $this->guidePage('Hidden', 'hidden.html'));
        $this->put($dir, 'site/.dotfile.html', $this->guidePage('Hidden', '.dotfile.html'));
        $this->put($dir, 'site/notes.md', "not a page\n");
        $result = $this->generate('--sync-to=' . $dir);
        self::assertSame(0, $result['code'], $result['stderr']);

        return $dir;
    }

    /** A docs root: sources, a built site and an examples cwd, each with a busy folder, deep nesting and strays. */
    private function docsRoot(): string
    {
        $dir = $this->tempDir();
        $this->put($dir, 'dump.php', "<?php fwrite(STDOUT, (string) file_get_contents((string) \$argv[1]));\n");
        $this->put($dir, 'docs/nav.json', '{"items":[]}');
        $this->put($dir, 'docs/docs.json', '{"examples":{"cwd":"fixture","alias":{"dump":"php dump.php"}}}');
        $this->put($dir, 'docs/index.md', $this->examplePage());
        for ($n = 1; $n <= self::DOC_PAGES; $n++) {
            $this->put($dir, 'docs/guides/d' . self::pad($n) . '.md', "# Guide {$n}\n\nText.\n");
        }
        $this->put($dir, 'docs/guides/deep/nested/leaf.md', "# Leaf\n\nText.\n");
        // Dot and underscore entries: the builder must not see them.
        $this->put($dir, 'docs/.dot.md', "# Hidden\n");
        $this->put($dir, 'docs/_private.md', "# Private\n");
        $this->put($dir, 'docs/.hiddendir/h.md', "# Hidden\n");
        $this->put($dir, 'docs/_underdir/u.md', "# Private\n");
        // The examples cwd: nested files, a hidden file and a vendor folder the copy skips.
        $this->put($dir, 'fixture/deep/nested/file.txt', "hello from deep\n");
        $this->put($dir, 'fixture/.hidden/h.txt', "hidden content\n");
        $this->put($dir, 'fixture/vendor/v.txt', "skipped\n");
        // A built site: marked pages plus theme files, with dot and underscore strays.
        $this->put($dir, 'site/docs/index.html', $this->builtPage('Front'));
        for ($n = 1; $n <= self::BUILT; $n++) {
            $this->put($dir, 'site/docs/guides/g' . self::pad($n) . '.html', $this->builtPage('Built ' . $n));
        }
        $this->put($dir, 'site/docs/guides/deep/leaf.html', $this->builtPage('Built leaf'));
        $this->put($dir, 'site/docs/assets/skip.html', $this->builtPage('Built asset'));
        $this->put($dir, 'site/docs/theme/theme.css', ".t {}\n");
        $this->put($dir, 'site/docs/theme/sub/two.css', ".u {}\n");
        $this->put($dir, 'site/docs/theme/.hidden/h.css', ".h {}\n");
        $this->put($dir, 'site/docs/.hidden/h.html', $this->builtPage('Stray'));
        $this->put($dir, 'site/docs/_private/p.html', $this->builtPage('Stray'));
        $this->put($dir, 'site/docs/.dot.html', $this->builtPage('Stray'));
        $this->put($dir, 'site/docs/notes.txt', "not a page\n");

        return $dir;
    }

    /** An app tree for the doctor: deep nesting, a busy folder, and dot and vendor entries it must still see. */
    private function doctorApp(): string
    {
        $dir = $this->tempDir();
        $this->put($dir, 'src/App/Home.php', "<?php\n");
        $this->put($dir, 'src/App/Service/Greeter.php', "<?php\n");
        $this->put($dir, 'src/App/Service/Deep/Leaf.php', "<?php\n");
        $this->put($dir, 'public/index.php', "<?php\n");
        $this->put($dir, '.hidden/secret.php', "<?php\n");
        for ($n = 1; $n <= self::DOCTOR_LOTS; $n++) {
            $this->put($dir, 'lots/l' . self::pad($n) . '.php', "<?php\n");
        }
        $this->put($dir, 'vendor/composer/installed.php', "<?php\n");
        $this->put($dir, '.git/config', "[core]\n");
        $this->put($dir, 'notes.txt', "not php\n");

        return $dir;
    }

    /** The front page: the prompt block the generator requires, with the kit loaded. */
    private function frontPage(): string
    {
        $prompt = 'You are a coding agent. Read https://walk.apps.wayne.edu/llms.txt and ask ADVISOR.' . "\n"
            . 'Install it with `composer require test/walk`.' . "\n"
            . 'Ask me before you guess.';
        $escaped = htmlspecialchars($prompt, ENT_NOQUOTES);

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>Walk</title>
<meta name="description" content="A test site for the directory walk.">
<link rel="alternate" type="text/markdown" href="index.md">
<link rel="stylesheet" href="assets/agent-ready.css">
<script src="assets/agent-ready.js" defer></script>
</head><body>
<main>
<h1>Walk</h1>
<p>Run <code>composer require test/walk</code>, then read the guides.</p>
<section class="ar-prompt" id="prompt-walk" data-ar-prompt>
  <h3 class="ar-prompt__title">Get started with Walk</h3>
  <p class="ar-prompt__desc">One line.</p>
  <pre class="ar-prompt__text"><code>{$escaped}</code></pre>
</section>
</main></body></html>
HTML;
    }

    /** A plain page: a title and its Markdown twin link, no prompt. */
    private function guidePage(string $title, string $file): string
    {
        return '<!doctype html>' . "\n"
            . '<html lang="en"><head><meta charset="utf-8">' . "\n"
            . '<title>' . $title . '</title>' . "\n"
            . '<link rel="alternate" type="text/markdown" href="' . str_replace('.html', '.md', $file) . '">' . "\n"
            . '</head><body><main><h1>' . $title . '</h1><p>Guide text.</p></main></body></html>';
    }

    /** A built page carrying the builder's marker in its head, so leftovers finds it. */
    private function builtPage(string $title): string
    {
        return '<!doctype html><html><head><title>' . $title . '</title><!-- Generated by bin/docs-site.php --></head><body><main><h1>' . $title . '</h1></main></body></html>';
    }

    /** The source page that runs the examples: two command blocks against the copied cwd. */
    private function examplePage(): string
    {
        return "# Examples\n\n"
            . "```bash run\n"
            . "dump deep/nested/file.txt\n"
            . "```\n\n"
            . "```output\n"
            . "hello from deep\n"
            . "```\n\n"
            . "```bash run\n"
            . "dump .hidden/h.txt\n"
            . "```\n\n"
            . "```output\n"
            . "hidden content\n"
            . "```\n";
    }

    /** The fixture site's pages in the generator's list order: front page first, then path order. */
    private static function sitePages(): array
    {
        $pages = ['index.html', 'guides/deep/nested/leaf.html'];
        for ($n = 1; $n <= self::GUIDES; $n++) {
            $pages[] = 'guides/g' . self::pad($n) . '.html';
        }

        return $pages;
    }

    /** The Markdown sources under docs/, sorted: the walk's exact output. */
    private static function docPages(): array
    {
        $pages = [];
        for ($n = 1; $n <= self::DOC_PAGES; $n++) {
            $pages[] = 'guides/d' . self::pad($n) . '.md';
        }
        $pages[] = 'guides/deep/nested/leaf.md';
        $pages[] = 'index.md';
        sort($pages);

        return $pages;
    }

    /** The built pages under site/docs/, sorted: the walk's exact output. */
    private static function builtPages(): array
    {
        $pages = [];
        for ($n = 1; $n <= self::BUILT; $n++) {
            $pages[] = 'guides/g' . self::pad($n) . '.html';
        }
        $pages[] = 'assets/skip.html';
        $pages[] = 'guides/deep/leaf.html';
        $pages[] = 'index.html';
        sort($pages);

        return $pages;
    }

    /** How many squire-examples-* working directories sit in the temp folder right now. */
    private function exampleDirs(): int
    {
        $count = 0;
        foreach (glob(sys_get_temp_dir() . '/squire-examples-*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $count++;
            }
        }

        return $count;
    }

    /** Runs this Squire's agent-ready generator and returns the exit code and output. */
    private function generate(string ...$args): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, dirname(Squire::templatesDir()) . '/bin/agent-ready.php'], $args),
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

    private static function pad(int $n): string
    {
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }
}

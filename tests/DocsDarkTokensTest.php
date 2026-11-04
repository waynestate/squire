<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

/**
 * Dark mode in the docs kit: docs.css holds every color behind a --dk-* alias as var(--wsu-token, fallback),
 * and the fallbacks follow WayneUI's own resolution order (explicit data-theme first, then the reader's
 * system setting, else light) with every text pair at WCAG AA contrast in both themes.
 */
final class DocsDarkTokensTest extends SquireTestCase
{
    /** The twelve color aliases the kit resolves through WayneUI tokens. */
    private const COLOR_ALIASES = [
        'bg',
        'ink',
        'muted',
        'rule',
        'accent',
        'on-accent',
        'note',
        'tip',
        'warning',
        'danger',
        'code-bg',
        'focus',
    ];

    /** Text pairs that must reach the 4.5:1 AA floor in every theme, as [foreground, background]. */
    private const AA_PAIRS = [
        ['ink', 'bg'],
        ['muted', 'bg'],
        ['accent', 'bg'],
        ['note', 'bg'],
        ['tip', 'bg'],
        ['warning', 'bg'],
        ['danger', 'bg'],
        ['ink', 'code-bg'],
        ['on-accent', 'accent'],
    ];

    private const LIGHT_BLOCK = '/^:root \{\n(?<body>.*?)^\}/ms';

    private const DARK_BLOCK = '/^:root\[data-theme="dark"\] \{\n(?<body>.*?)^\}/ms';

    private const MEDIA_BLOCK = '/@media \(prefers-color-scheme: dark\) \{\n(?<body>.*?)^\}/ms';

    public function testLightPairsMeetAa(): void
    {
        $this->pairsMeetAa('light', $this->aliases($this->capture(self::LIGHT_BLOCK)));
    }

    public function testDarkPairsMeetAa(): void
    {
        $this->pairsMeetAa('dark', $this->aliases($this->capture(self::DARK_BLOCK)));
    }

    public function testMediaBlockMatchesExplicitDarkBlock(): void
    {
        $explicit = $this->aliases($this->capture(self::DARK_BLOCK));
        $media = $this->aliases($this->capture(self::MEDIA_BLOCK));
        ksort($explicit);
        ksort($media);

        self::assertSame(
            $explicit,
            $media,
            'the prefers-color-scheme block must carry the same colour aliases and values as :root[data-theme="dark"]',
        );
    }

    public function testMediaBlockRespectsExplicitLight(): void
    {
        $inner = $this->capture(self::MEDIA_BLOCK);

        self::assertStringContainsString(
            ':root:not([data-theme="light"])',
            $inner,
            'the dark fallbacks must not override an explicit light choice',
        );
    }

    public function testNoColourLiteralsOutsideRootBlocks(): void
    {
        $rest = $this->css();
        foreach ([self::MEDIA_BLOCK, self::DARK_BLOCK, self::LIGHT_BLOCK] as $block) {
            $rest = (string) preg_replace($block, '', $rest);
        }

        $literals = [];
        foreach ([
            '/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/',
            '/rgba?\(/i',
            '/hsla?\(/i',
        ] as $pattern) {
            preg_match_all($pattern, $rest, $hits);
            $literals = array_merge($literals, $hits[0]);
        }

        self::assertSame([], $literals, 'color literals outside the three alias blocks: ' . implode(', ', $literals));
    }

    public function testColorSchemeDeclared(): void
    {
        self::assertMatchesRegularExpression(
            '/color-scheme:\s*light\s*;/',
            $this->capture(self::LIGHT_BLOCK),
            'the light :root block must declare color-scheme: light',
        );
        self::assertMatchesRegularExpression(
            '/color-scheme:\s*dark\s*;/',
            $this->capture(self::DARK_BLOCK),
            ':root[data-theme="dark"] must declare color-scheme: dark',
        );
        self::assertMatchesRegularExpression(
            '/color-scheme:\s*dark\s*;/',
            $this->capture(self::MEDIA_BLOCK),
            'the prefers-color-scheme block must declare color-scheme: dark',
        );
    }

    /** The current contents of the kit stylesheet. */
    private function css(): string
    {
        $path = dirname(__DIR__) . '/kit/docs/docs.css';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The named body group of the first block the pattern matches in docs.css. */
    private function capture(string $pattern): string
    {
        $css = $this->css();
        self::assertMatchesRegularExpression($pattern, $css, 'docs.css lost the block matched by ' . $pattern);
        preg_match($pattern, $css, $matches);

        return (string) $matches['body'];
    }

    /** @return array<string, string> alias suffix => hex fallback */
    private function aliases(string $body): array
    {
        $aliases = [];
        preg_match_all(
            '/--dk-(?<name>[a-z-]+):\s*var\(\s*--wsu-[a-z0-9-]+\s*,\s*(?<hex>#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}))\s*\)/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $aliases[$match['name']] = strtolower($match['hex']);
        }

        return $aliases;
    }

    /** Asserts the set carries every color alias and every required pair reaches 4.5:1. */
    private function pairsMeetAa(string $theme, array $aliases): void
    {
        foreach (self::COLOR_ALIASES as $alias) {
            self::assertArrayHasKey($alias, $aliases, $theme . ' set is missing --dk-' . $alias);
        }

        foreach (self::AA_PAIRS as [$foreground, $background]) {
            $ratio = $this->contrast($aliases[$foreground], $aliases[$background]);
            self::assertGreaterThanOrEqual(
                4.5,
                $ratio,
                $theme . ': ' . $foreground . ' on ' . $background . ' is ' . number_format($ratio, 2) . ':1, below the 4.5:1 AA floor',
            );
        }
    }

    /** WCAG 2.x relative luminance of a hex colour. */
    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channels = [];
        for ($i = 0; $i < 3; $i++) {
            $channel = hexdec(substr($hex, $i * 2, 2)) / 255;
            $channels[] = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** The WCAG contrast ratio between two hex colours, from 1 to 21. */
    private function contrast(string $first, string $second): float
    {
        $a = $this->luminance($first);
        $b = $this->luminance($second);
        [$high, $low] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($high + 0.05) / ($low + 0.05);
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;

/**
 * The hardening the image files promise, checked in the files themselves. scripts/contract-image.sh checks the same
 * promises again inside the built image (SQUIRE-40), where a file can no longer lie about what it does.
 */
final class ImageHardeningTest extends SquireTestCase
{
    private function read(string $source): string
    {
        return (string) file_get_contents(Squire::templatesDir() . '/' . $source);
    }

    /** The file's non-comment, non-blank lines, trimmed. @return list<string> */
    private function directives(string $source, string $comment = '#'): array
    {
        $lines = [];
        foreach (explode("\n", $this->read($source)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, $comment)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function catalogHas(string $destination): bool
    {
        foreach (Catalog::all() as $template) {
            if ($template->destination === $destination) {
                return true;
            }
        }

        return false;
    }

    // --- SQUIRE-9: Apache says less about itself ---------------------------------------------------------------------

    public function testTheApacheHardeningSetsTheThreeDirectives(): void
    {
        $directives = $this->directives('common/.squire/apache-hardening.conf');

        self::assertContains('ServerTokens Prod', $directives);
        self::assertContains('ServerSignature Off', $directives);
        self::assertContains('TraceEnable Off', $directives);
        self::assertTrue($this->catalogHas('.squire/apache-hardening.conf'), 'init writes the hardening file with the parity kit');
    }

    public function testTheFallbackBaseInstallsTheHardeningAfterDebiansSecurityConf(): void
    {
        $base = $this->read('common/.squire/base-fallback.Dockerfile');

        // zz- sorts after Debian's security.conf (ServerTokens OS, ServerSignature On), so these values win.
        self::assertStringContainsString('COPY apache-hardening.conf "$APACHE_CONFDIR/conf-available/zz-squire-hardening.conf"', $base);
        self::assertStringContainsString('a2enconf zz-squire-hardening', $base);
    }

    public function testTheAppImageLeavesApacheHardeningToTheBase(): void
    {
        // The app Dockerfile runs on the real Citadel image locally; hardening it there would fake a parity it lacks.
        self::assertStringNotContainsString('apache-hardening', $this->read('common/.squire/Dockerfile'));
    }
}

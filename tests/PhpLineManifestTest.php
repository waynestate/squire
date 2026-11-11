<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Manifest;
use Waynestate\Squire\Template\Template;
use Waynestate\Squire\ToolError;

/**
 * The manifest's php key and the {{PHP_LINE}} token: the plumbing that lets an app record which PHP line it runs on.
 */
final class PhpLineManifestTest extends SquireTestCase
{
    public function testTheManifestPhpKeyRoundTrips(): void
    {
        $app = $this->tempDir();
        (new Manifest(Squire::version(), 'raw', ['release'], [], null, '8.5r3'))->save($app);

        $loaded = Manifest::load($app);
        self::assertNotNull($loaded);
        self::assertSame('8.5r3', $loaded->php);
        $data = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertArrayHasKey('php', $data);
        self::assertSame('8.5r3', $data['php']);
    }

    public function testAManifestWithoutPhpLoadsAsNull(): void
    {
        $app = $this->tempDir();
        (new Manifest(Squire::version(), 'raw', ['release'], []))->save($app);

        $data = json_decode((string) file_get_contents($app . '/' . Squire::MANIFEST), true);
        self::assertArrayNotHasKey('php', $data);
        $loaded = Manifest::load($app);
        self::assertNotNull($loaded);
        self::assertNull($loaded->php);
    }

    public function testABadPhpValueIsRefused(): void
    {
        $app = $this->tempDir();
        file_put_contents($app . '/' . Squire::MANIFEST, json_encode([
            'version' => Squire::VERSION,
            'target' => 'raw',
            'groups' => ['release'],
            'files' => [],
            'php' => 'latest',
        ]) . "\n");

        try {
            Manifest::load($app);
            self::fail('a php value that is not a line was accepted');
        } catch (ToolError $error) {
            self::assertStringContainsString(Squire::MANIFEST, $error->getMessage());
        }
    }

    public function testContentReplacesTheToken(): void
    {
        $catalog = new Catalog(Squire::templatesDir());
        $compose = new Template('docker-compose.squire.yml', Template::GROUP_PARITY);

        $next = $catalog->content($compose, '8.5r3');
        self::assertStringContainsString('citadel/php-apache:8.5r3', $next);
        self::assertStringNotContainsString('{{PHP_LINE}}', $next);

        $legacy = $catalog->content($compose);
        self::assertStringContainsString('citadel/php-apache:8.4r5', $legacy);
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use Waynestate\Squire\Template\Manifest;

/**
 * Squire's own deploy layer is one Squire wrote: every hash its .squire.json records must match the file it holds,
 * or `squire update` on this repo refuses the file as locally edited. The hashes are taken the way Squire takes
 * them (Manifest::hash normalizes line endings), so a CRLF checkout of parity.ps1 is not drift.
 */
final class OwnDeployManifestTest extends SquireTestCase
{
    public function testOwnDeployManifestHashesMatchTheFiles(): void
    {
        $manifestPath = dirname(__DIR__) . '/deploy/.squire.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest, 'deploy/.squire.json is not a JSON object');
        self::assertArrayHasKey('files', $manifest, 'deploy/.squire.json has no files object');
        self::assertIsArray($manifest['files'], 'the files object in deploy/.squire.json is not an object');
        self::assertNotEmpty($manifest['files'], 'deploy/.squire.json records no files at all');

        foreach ($manifest['files'] as $path => $recorded) {
            $file = dirname(__DIR__) . '/deploy/' . $path;
            self::assertFileExists($file, 'deploy/' . $path . ' is recorded in deploy/.squire.json but missing from the repo');
            self::assertSame(
                $recorded,
                Manifest::hash((string) file_get_contents($file)),
                'deploy/' . $path . ' no longer matches the hash its own manifest records, so squire update would refuse it',
            );
        }
    }
}

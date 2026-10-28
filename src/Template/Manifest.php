<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * `.squire.json` in the app: which SQUIRE wrote which files, and the hash of what it wrote. The hash is how
 * `init --check` tells "the template moved on" apart from "someone edited the file".
 */
final readonly class Manifest
{
    /**
     * @param array<string, string> $files destination => sha256 of the content SQUIRE wrote
     * @param list<string>          $groups
     */
    public function __construct(
        public string $version,
        public string $target,
        public array $groups,
        public array $files,
    ) {}

    public static function load(string $appPath): ?self
    {
        $path = $appPath . '/' . Squire::MANIFEST;
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || !isset($data['target'], $data['files']) || !is_array($data['files'])) {
            throw new ToolError(sprintf('%s is not a SQUIRE manifest. Delete it and run `squire init` again.', Squire::MANIFEST));
        }

        $files = [];
        foreach ($data['files'] as $file => $hash) {
            if (is_string($file) && is_string($hash)) {
                $files[$file] = $hash;
            }
        }
        $groups = [];
        foreach (is_array($data['groups'] ?? null) ? $data['groups'] : [] as $group) {
            if (is_string($group)) {
                $groups[] = $group;
            }
        }

        return new self(
            is_string($data['version'] ?? null) ? $data['version'] : 'unknown',
            is_string($data['target']) ? $data['target'] : 'raw',
            $groups,
            $files,
        );
    }

    public function save(string $appPath): void
    {
        $files = $this->files;
        ksort($files);
        $data = [
            'squire' => 'Written by SQUIRE. Commit this file: `vendor/bin/squire init --check` reads it to report drift.',
            'version' => $this->version,
            'target' => $this->target,
            'groups' => $this->groups,
            'files' => $files,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($appPath . '/' . Squire::MANIFEST, $json . "\n") === false) {
            throw new ToolError(sprintf('Cannot write %s in %s.', Squire::MANIFEST, $appPath));
        }
    }

    public static function hash(string $content): string
    {
        return hash('sha256', Catalog::normalize($content));
    }
}

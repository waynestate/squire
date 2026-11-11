<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

use Waynestate\Squire\Squire;
use Waynestate\Squire\ToolError;

/**
 * `.squire.json` in the app: which Squire wrote which files, and the hash of what it wrote. The hash is how
 * `init --check` tells "the template moved on" apart from "someone edited the file".
 */
final readonly class Manifest
{
    /**
     * @param array<string, string> $files destination => sha256 of the content Squire wrote
     * @param list<string>          $groups
     * @param string|null          $layer
     * @param string|null          $php the PHP line the app runs on (8.4r5, 8.5r3); null when Squire predates it
     */
    public function __construct(
        public string $version,
        public string $target,
        public array $groups,
        public array $files,
        public ?string $layer = null,
        public ?string $php = null,
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
            throw new ToolError(sprintf('%s is not a Squire manifest. Delete it and run `squire init` again.', Squire::MANIFEST));
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

        $layer = is_string($data['layer'] ?? null) ? $data['layer'] : null;
        $php = $data['php'] ?? null;
        if ($php !== null && (!is_string($php) || preg_match('/^\d+\.\d+r\d+$/', $php) !== 1)) {
            throw new ToolError(sprintf('%s records php "%s", which is not a PHP line such as 8.5r3.', Squire::MANIFEST, is_string($php) ? $php : json_encode($php)));
        }

        return new self(
            is_string($data['version'] ?? null) ? $data['version'] : 'unknown',
            is_string($data['target']) ? $data['target'] : 'raw',
            $groups,
            $files,
            $layer,
            $php,
        );
    }

    public function save(string $appPath): void
    {
        $files = $this->files;
        ksort($files);
        $data = [
            'squire' => 'Written by Squire. Commit this file: `vendor/bin/squire init --check` reads it to report drift.',
            'version' => $this->version,
            'target' => $this->target,
            'groups' => $this->groups,
        ];
        if ($this->layer !== null) {
            $data['layer'] = $this->layer;
        }
        if ($this->php !== null) {
            $data['php'] = $this->php;
        }
        $data['files'] = $files;

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

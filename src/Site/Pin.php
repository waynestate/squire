<?php

declare(strict_types=1);

namespace Waynestate\Squire\Site;

use stdClass;
use Waynestate\Squire\ToolError;

/**
 * The site.json pin of a site repository: which library the site is built from, at which tag, and (optionally) which
 * files the site owns. ADR-0023.
 */
final class Pin
{
    /**
     * @param list<string> $files
     */
    private function __construct(
        private readonly string $library,
        private readonly string $tag,
        private readonly array $files,
    ) {}

    /**
     * Reads and validates the site.json pin at $path.
     *
     * The file is JSON: {"library": "enterprise-apps/<name>", "tag": "vX.Y.Z", "files": ["optional/path", ...]}.
     * library and tag are required non-empty strings; files is optional and defaults to [].
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ToolError(sprintf('Cannot read %s: the file does not exist.', $path));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ToolError(sprintf('Cannot read %s.', $path));
        }
        $data = json_decode($raw);
        if (!$data instanceof stdClass) {
            $problem = json_last_error() === JSON_ERROR_NONE
                ? 'the top level is not a JSON object'
                : 'it is not valid JSON (' . json_last_error_msg() . ')';
            throw new ToolError(sprintf('Cannot parse %s as a site pin: %s.', $path, $problem));
        }
        $library = $data->library ?? null;
        if (!is_string($library) || $library === '') {
            throw new ToolError(sprintf('Invalid site pin %s: library must be a non-empty string.', $path));
        }
        $tag = $data->tag ?? null;
        if (!is_string($tag) || $tag === '') {
            throw new ToolError(sprintf('Invalid site pin %s: tag must be a non-empty string.', $path));
        }
        $files = [];
        if (isset($data->files)) {
            if (!is_array($data->files)) {
                throw new ToolError(sprintf('Invalid site pin %s: files must be a list of strings.', $path));
            }
            foreach ($data->files as $file) {
                if (!is_string($file)) {
                    throw new ToolError(sprintf('Invalid site pin %s: files must be a list of strings.', $path));
                }
                $files[] = $file;
            }
        }

        return new self($library, $tag, $files);
    }

    /** The library the site is built from, for example enterprise-apps/demo. */
    public function library(): string
    {
        return $this->library;
    }

    /** The tag the site is pinned to, for example v1.2.0. */
    public function tag(): string
    {
        return $this->tag;
    }

    /**
     * The files the site owns, in the order the pin lists them.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return $this->files;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

/**
 * One file SQUIRE can write into an app.
 */
final readonly class Template
{
    public const GROUP_RELEASE = 'release';

    public const GROUP_PARITY = 'parity';

    /**
     * @param string      $destination path inside the app, with forward slashes
     * @param string      $group       GROUP_RELEASE or GROUP_PARITY
     * @param bool        $executable  whether init marks the file executable
     * @param string|null $target      null for every target, or 'raw' / 'laravel' for a target-only file
     */
    public function __construct(
        public string $destination,
        public string $group,
        public bool $executable = false,
        public ?string $target = null,
    ) {}

    /** The source path relative to the templates directory. */
    public function source(): string
    {
        return ($this->target ?? 'common') . '/' . $this->destination;
    }
}

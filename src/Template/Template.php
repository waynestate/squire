<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

/**
 * One file Squire can write into an app.
 */
final readonly class Template
{
    public const GROUP_RELEASE = 'release';

    /** The image files: the Citadel mirror, the fallback base and the portable image. Opt-in. */
    public const GROUP_IMAGES = 'images';

    /**
     * The local-run kit's old group. It left Squire in 0.12.0 (Parity runs Citadel on your computer now): a record
     * that still names it reads as `images`, and `--only=parity` is refused with a pointer at Parity.
     */
    public const LEGACY_GROUP_PARITY = 'parity';

    public const LAYER_VUE = 'vue';

    /**
     * @param string      $destination path inside the app, with forward slashes
     * @param string      $group       GROUP_RELEASE or GROUP_IMAGES
     * @param bool        $executable  whether init marks the file executable
     * @param string|null $target null for every target, or 'raw' / 'laravel' / 'static' for a target-only file
     * @param string|null $layer null for every layer, or `vue` for a layer file
     * @param bool        $appMayOwn   whether the app may keep its own file at this path: init writes the template only
     *                                 when the path is free, and a file Squire did not write there is the app's own,
     *                                 never drift and never overwritten, not even by --force
     */
    public function __construct(
        public string $destination,
        public string $group,
        public bool $executable = false,
        public ?string $target = null,
        public ?string $layer = null,
        public bool $appMayOwn = false,
    ) {}

    /** The source path relative to the templates directory. */
    public function source(): string
    {
        return ($this->layer ?? $this->target ?? 'common') . '/' . $this->destination;
    }
}

<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

/**
 * What `init`, `init --check` or `update` found or did for one file.
 */
final readonly class FileResult
{
    // Write mode.
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const OVERWRITTEN = 'overwritten';

    public const SKIPPED_MODIFIED = 'skipped-modified';

    public const SKIPPED_EXISTS = 'skipped-exists';

    // Check mode.
    public const OK = 'ok';

    public const MISSING = 'missing';

    public const OUTDATED = 'outdated';

    public const MODIFIED = 'modified';

    public const UNTRACKED = 'untracked';

    // Write and check mode: a file the app may own (a raw app's own front controller) that Squire did not write.
    public const APP_OWNED = 'app-owned';

    // Update mode.
    public const CURRENT = 'current';

    public const RESTORED = 'restored';

    public const EDITED = 'edited, not changed';

    public function __construct(
        public string $path,
        public string $state,
        public string $detail,
        public ?string $diff = null,
    ) {}

    /** True when the file matches the current template (or was just made to), or is the app's own to keep. */
    public function isCurrent(): bool
    {
        return in_array($this->state, [self::CREATED, self::UPDATED, self::UNCHANGED, self::OVERWRITTEN, self::OK, self::CURRENT, self::RESTORED, self::APP_OWNED], true);
    }
}

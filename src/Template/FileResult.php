<?php

declare(strict_types=1);

namespace Waynestate\Squire\Template;

/**
 * What `init` (or `init --check`) found or did for one file.
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

    public function __construct(
        public string $path,
        public string $state,
        public string $detail,
    ) {}

    /** True when the file matches the current template (or was just made to). */
    public function isCurrent(): bool
    {
        return in_array($this->state, [self::CREATED, self::UPDATED, self::UNCHANGED, self::OVERWRITTEN, self::OK], true);
    }
}

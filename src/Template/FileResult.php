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

    /**
     * Check mode only: the deploy layer's composer.json has no name, or a name that reads as a stack package
     * (`waynestate/*`); PROCTOR's CiPipelinesCheck would treat it as a stack package and turn the deploy proctor
     * job red (D-171). Never counted by isCurrent().
     */
    public const DEPLOY_NAME = 'deploy-name';

    // Write and check mode: a file the app may own (a raw app's own front controller) that Squire did not write.
    public const APP_OWNED = 'app-owned';

    // Update mode.
    public const CURRENT = 'current';

    public const RESTORED = 'restored';

    /** A template file a newer release added to a recorded group: update wrote it and recorded it. */
    public const ADDED = 'added';

    public const EDITED = 'edited, not changed';

    /**
     * Init and update: a parity kit file the record named. The kit left Squire in 0.12.0; the file is never deleted
     * for the app, only dropped from the record and reported, so the owner can carry it over to Parity and delete it.
     */
    public const KIT_LEFTOVER = 'kit-leftover';

    /** The detail every leftover kit file carries. */
    public const KIT_LEFTOVER_DETAIL = 'the parity kit left Squire in 0.12.0; not deleted: run `parity init --from-squire`, then delete it';

    public function __construct(
        public string $path,
        public string $state,
        public string $detail,
        public ?string $diff = null,
    ) {}

    /** True when the file matches the current template (or was just made to), or is the app's own to keep. */
    public function isCurrent(): bool
    {
        return in_array($this->state, [self::CREATED, self::UPDATED, self::UNCHANGED, self::OVERWRITTEN, self::OK, self::CURRENT, self::RESTORED, self::ADDED, self::APP_OWNED, self::KIT_LEFTOVER], true);
    }
}

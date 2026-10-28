<?php

declare(strict_types=1);

namespace Waynestate\Squire;

/**
 * SQUIRE itself could not do its job (bad option, unreadable path, broken manifest). The CLI maps it to exit code 2,
 * which is never a finding about the app.
 */
final class ToolError extends \RuntimeException {}

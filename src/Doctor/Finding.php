<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor;

/**
 * One result of one rule check. The rule id is the practice number in design/best-practices/squire.md (S1 to S78).
 */
final readonly class Finding
{
    public const PASS = 'pass';

    public const INFO = 'info';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public string $rule,
        public string $status,
        public string $message,
        public ?string $file = null,
    ) {}

    public static function pass(string $rule, string $message, ?string $file = null): self
    {
        return new self($rule, self::PASS, $message, $file);
    }

    public static function info(string $rule, string $message, ?string $file = null): self
    {
        return new self($rule, self::INFO, $message, $file);
    }

    public static function warn(string $rule, string $message, ?string $file = null): self
    {
        return new self($rule, self::WARN, $message, $file);
    }

    public static function fail(string $rule, string $message, ?string $file = null): self
    {
        return new self($rule, self::FAIL, $message, $file);
    }

    /** @return array{rule: string, status: string, message: string, file: string|null} */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'status' => $this->status, 'message' => $this->message, 'file' => $this->file];
    }
}

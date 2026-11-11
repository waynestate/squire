<?php

declare(strict_types=1);

namespace Waynestate\Squire\Facts;

use Waynestate\Squire\ToolError;

/**
 * The committed .squire/facts.json snapshot, loaded and validated. Slice S1 only reads it; slice S2 writes it
 * from CREED and ADVISOR data, which is why every problem here is a tool error (exit 2) and never a finding.
 */
final class Snapshot
{
    /** @var array<string, string> region key => exact body text, no trailing newline */
    private array $regions;

    /** @param array<string, string> $regions */
    private function __construct(array $regions)
    {
        $this->regions = $regions;
    }

    /** Loads <root>/.squire/facts.json; a missing or broken file is a ToolError naming the problem in one line. */
    public static function load(string $root): self
    {
        $path = $root . '/.squire/facts.json';
        if (!is_file($path)) {
            throw new ToolError(sprintf('No .squire/facts.json in %s.', $root));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ToolError(sprintf('Cannot read %s/.squire/facts.json.', $root));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ToolError(sprintf('.squire/facts.json in %s is not valid JSON: %s', $root, json_last_error_msg()));
        }

        $version = $data['schema_version'] ?? null;
        if ($version !== 1) {
            $shown = $version === null || is_scalar($version) ? var_export($version, true) : 'a non-scalar value';
            throw new ToolError(sprintf('.squire/facts.json in %s: schema_version must be 1, got %s.', $root, $shown));
        }

        $regions = $data['regions'] ?? null;
        if (!is_array($regions)) {
            throw new ToolError(sprintf('.squire/facts.json in %s: regions must be an object of strings.', $root));
        }

        $clean = [];
        foreach ($regions as $key => $value) {
            if (!is_string($value)) {
                throw new ToolError(sprintf('.squire/facts.json in %s: regions must be an object of strings.', $root));
            }
            // A JSON object key that looks numeric decodes to an int; keep keys as written.
            $clean[(string) $key] = $value;
        }

        return new self($clean);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->regions);
    }

    /** @return array<string, string> every region key and its exact body text */
    public function all(): array
    {
        return $this->regions;
    }
}

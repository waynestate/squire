<?php

declare(strict_types=1);

namespace Waynestate\Squire\Doctor\Check;

use Waynestate\Squire\Doctor\Check;
use Waynestate\Squire\Doctor\Finding;
use Waynestate\Squire\Doctor\Project;

/**
 * S35 to S37, S67, S70 and S71: the local setup matches Citadel (the real image, a proxy, MySQL 5.6, the health-check
 * timing) and pins what it pulls.
 */
final class ParityCheck implements Check
{
    private const COMPOSE = 'docker-compose.squire.yml';

    public function run(Project $project): array
    {
        $compose = $project->read(self::COMPOSE);
        if ($compose === null) {
            return [Finding::info('S35', 'No local parity setup (docker-compose.squire.yml). `squire init --only=parity` writes one.')];
        }

        $findings = [];

        $findings[] = preg_match('#citadel/php-apache:\d#', $compose) === 1
            ? Finding::pass('S35', 'The app runs on a Citadel PHP image.', self::COMPOSE)
            : Finding::warn('S35', 'The compose file does not name a citadel/php-apache image, so PHP and php.ini may differ from production.', self::COMPOSE);

        $findings[] = str_contains($compose, 'haproxy') && str_contains($project->read('.squire/haproxy.cfg') ?? '', 'X-Forwarded-Proto')
            ? Finding::pass('S36', 'A proxy adds X-Forwarded-For and X-Forwarded-Proto in front of the app.', self::COMPOSE)
            : Finding::warn('S36', 'No proxy in front of the local app, so proxy trust and HTTPS detection cannot be tested.', self::COMPOSE);

        $findings[] = preg_match('#image:\s*\S*mysql:5\.6#', $compose) === 1
            ? Finding::pass('S37', 'MySQL 5.6 runs locally, the version of the shared Citadel server.', self::COMPOSE)
            : Finding::warn('S37', 'The local database is not MySQL 5.6, so its limits (no JSON, 191-character indexes) will not surface locally.', self::COMPOSE);

        $unpinned = $this->unpinnedImages($compose);
        $findings[] = $unpinned === []
            ? Finding::pass('S67', 'Every image in the compose file is pinned by digest.', self::COMPOSE)
            : Finding::warn(
                'S67',
                'Not pinned by digest: ' . implode(', ', $unpinned) . '. Run `scripts/parity.sh pin` and put the digests in .env.squire.',
                self::COMPOSE,
            );

        $findings[] = str_contains($compose, 'interval: 5m') && str_contains($compose, 'timeout: 15s')
            ? Finding::pass('S71', "The health check uses Citadel's timing (5 minutes, 15 seconds).", self::COMPOSE)
            : Finding::warn('S71', "The health check does not use Citadel's timing (interval 5m, timeout 15s), which hides a slow /up until it is deployed.", self::COMPOSE);

        $findings[] = str_contains($compose, 'cap_drop') && str_contains($compose, 'no-new-privileges')
            ? Finding::pass('S70', 'Containers drop capabilities and cannot gain privileges.', self::COMPOSE)
            : Finding::warn('S70', 'Add `cap_drop: [ALL]` and `no-new-privileges:true` to the local containers.', self::COMPOSE);

        $findings[] = str_contains($compose, 'docker.sock')
            ? Finding::fail('S41', 'The Docker socket is mounted into a container; that is root on the host.', self::COMPOSE)
            : Finding::pass('S41', 'The Docker socket is not mounted.', self::COMPOSE);

        return $findings;
    }

    /**
     * Image references with no @sha256 digest. Interpolated defaults (${VAR:-ref}) count as the reference.
     *
     * @return list<string>
     */
    private function unpinnedImages(string $compose): array
    {
        $refs = [];
        if (preg_match_all('/^\s*(?:image|BASE_IMAGE):\s*(.+)$/m', $compose, $matches) > 0) {
            foreach ($matches[1] as $value) {
                $value = trim($value);
                if (str_starts_with($value, 'squire-local/')) {
                    continue;
                }
                if (preg_match('/\$\{[A-Za-z0-9_]+:-([^}]+)\}/', $value, $m) === 1) {
                    $value = $m[1];
                }
                if ($value === '' || str_starts_with($value, 'squire-local/') || str_starts_with($value, '$')) {
                    continue;
                }
                $refs[] = $value;
            }
        }

        $unpinned = [];
        foreach (array_unique($refs) as $ref) {
            if (!str_contains($ref, '@sha256:')) {
                $unpinned[] = $ref;
            }
        }

        return $unpinned;
    }
}

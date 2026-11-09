<?php

declare(strict_types=1);

namespace Waynestate\Squire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Template;

/**
 * The portable image (ADR-0010; WELD W8, W13): one image that runs as web, worker, scheduler or migrate, as a numeric
 * non-root user in group 0, on port 8080, beside a Citadel mirror that does not change. The file tests read what
 * ships; the entrypoint tests run it in bash with stand-ins for php, apache2-foreground and id, so they need no
 * Docker. docs/guides/portable-image.md shows the same in a real container, as an arbitrary UID.
 */
final class PortableImageTest extends SquireTestCase
{
    private const ENTRYPOINT = 'common/.squire/portable/squire-entrypoint.sh';
    private const DOCKERFILE = 'common/.squire/portable.Dockerfile';
    private const ROLES = ['migrate', 'web', 'worker', 'scheduler'];

    private function read(string $source): string
    {
        return Catalog::normalize((string) file_get_contents(Squire::templatesDir() . '/' . $source));
    }

    // --- the Citadel path stays exactly as it was -------------------------------------------------------------------

    public function testTheCitadelMirrorKeepsItsOneProcessRootStartAndPort80(): void
    {
        $citadel = $this->read('common/.squire/Dockerfile');
        self::assertStringEndsWith("ENTRYPOINT [\"citadel-apache-entrypoint\"]\nCMD [\"apache2-foreground\"]\n", $citadel, 'Citadel runs one process type; its mirror says so');
        self::assertDoesNotMatchRegularExpression('/^USER /m', $citadel, 'the Citadel image starts as root and drops to prisoner; its mirror must not pretend otherwise');
        self::assertStringNotContainsString('portable', $citadel);
        self::assertStringContainsString('<VirtualHost *:80>', $this->read('common/.squire/vhost.conf'));
        self::assertStringContainsString('EXPOSE 80', $this->read('common/.squire/base-fallback.Dockerfile'));
    }

    /** @return iterable<string, array{string}> */
    public static function composeFiles(): iterable
    {
        yield 'common' => ['common/docker-compose.squire.yml'];
        yield 'vue' => ['vue/docker-compose.squire.yml'];
    }

    /**
     * The services of a compose file, name => its indented block.
     *
     * @return array<string, string>
     */
    private function services(string $compose): array
    {
        $body = substr($compose, (int) strpos($compose, "\nservices:\n") + strlen("\nservices:\n"));
        $body = substr($body, 0, (int) strpos($body, "\nvolumes:\n"));
        preg_match_all('/^  ([a-z][a-z0-9-]*):\n((?:(?:    .*|\s*|  #.*)\n)*)/m', $body . "\n", $m, PREG_SET_ORDER);
        $services = [];
        foreach ($m as $match) {
            $services[$match[1]] = $match[2];
        }

        return $services;
    }

    #[DataProvider('composeFiles')]
    public function testTheDefaultComposeStackIsStillTheCitadelParityStack(string $source): void
    {
        $services = $this->services($this->read($source));
        foreach (['logs-init', 'app', 'proxy', 'mysql'] as $name) {
            self::assertArrayHasKey($name, $services, $source . ' lost the ' . $name . ' service');
            self::assertStringNotContainsString('<<: *portable', $services[$name], $source . ': ' . $name . ' is a parity service');
            self::assertStringNotContainsString('profiles:', $services[$name], $source . ': ' . $name . ' starts with a plain `up`, as before');
        }
        self::assertStringContainsString('dockerfile: .squire/Dockerfile', $this->read($source), 'the parity app still builds the Citadel mirror');
    }

    // --- one image, four process types (W8) ------------------------------------------------------------------------

    #[DataProvider('composeFiles')]
    public function testThePortableProfileRunsOneImageAsFourRoles(string $source): void
    {
        $compose = $this->read($source);
        $services = $this->services($compose);

        $anchor = substr($compose, (int) strpos($compose, 'x-portable: &portable'));
        $anchor = substr($anchor, 0, (int) strpos($anchor, "\nservices:\n"));
        self::assertStringContainsString('image: squire-local/portable:${SQUIRE_PROJECT:-squire-app}', $anchor, 'one image for every role');
        self::assertStringContainsString('build: *portable-build', $anchor);
        self::assertStringContainsString('profiles: [portable]', $anchor, 'nothing portable starts unless asked for');
        self::assertStringContainsString('user: "${SQUIRE_PORTABLE_USER:-10001:0}"', $anchor);
        self::assertStringContainsString('cap_drop: [ALL]', $anchor);
        self::assertStringNotContainsString('cap_add', $anchor, 'a non-root process on 8080 needs no capability at all');
        self::assertStringContainsString('no-new-privileges:true', $anchor);
        self::assertStringContainsString('dockerfile: ${SQUIRE_KIT_DIR:-.}/.squire/portable.Dockerfile', $compose);

        foreach (self::ROLES as $role) {
            self::assertArrayHasKey($role, $services, $source . ' has no ' . $role . ' service');
            self::assertStringContainsString('<<: *portable', $services[$role], $role . ' takes the shared portable block');
            self::assertStringContainsString('SQUIRE_ROLE: ' . $role, $services[$role], $role . ' differs only by its role');
            self::assertStringNotContainsString('image:', $services[$role], $role . ' must not name its own image');
            self::assertStringNotContainsString('build:', $services[$role], $role . ' must not build its own image');
        }
        self::assertStringContainsString('"127.0.0.1:${SQUIRE_PORTABLE_HTTP_PORT:-8081}:8080"', $services['web']);
        self::assertStringContainsString('QUEUE_WORKER_MODE: none', $services['scheduler'], 'the worker service has the queue, so the scheduler must not start short workers too');
        self::assertStringContainsString('restart: "no"', $services['migrate'], 'migrations run once per release (W24)');
        foreach (['web', 'worker', 'scheduler'] as $role) {
            self::assertStringContainsString("migrate:\n        condition: service_completed_successfully", $services[$role], $role . ' waits for the migrations');
            self::assertStringContainsString('restart: unless-stopped', $services[$role], $role . ' comes back after queue:work --max-time ends it');
        }
    }

    public function testTheVueComposeCarriesTheSamePortableProfile(): void
    {
        $common = $this->read('common/docker-compose.squire.yml');
        $vue = $this->read('vue/docker-compose.squire.yml');
        foreach (['x-portable-build: &portable-build', '  # --- the portable profile'] as $start) {
            $a = substr($common, (int) strpos($common, $start));
            $b = substr($vue, (int) strpos($vue, $start));
            $end = $start === '  # --- the portable profile' ? "\nvolumes:\n" : "\nservices:\n";
            self::assertSame(substr($a, 0, (int) strpos($a, $end)), substr($b, 0, (int) strpos($b, $end)), 'the two compose files keep one portable profile');
        }
    }

    public function testThePortableFilesShipWithTheParityKit(): void
    {
        $found = [];
        foreach (Catalog::all() as $template) {
            if (str_contains($template->destination, 'portable')) {
                self::assertSame(Template::GROUP_PARITY, $template->group, $template->destination);
                self::assertNull($template->target, $template->destination . ' is the same for raw, Laravel and static apps');
                $found[$template->destination] = $template->executable;
            }
        }
        ksort($found);
        self::assertSame([
            '.squire/portable.Dockerfile' => false,
            '.squire/portable.Dockerfile.dockerignore' => false,
            '.squire/portable/ports.conf' => false,
            '.squire/portable/squire-entrypoint.sh' => true,
            '.squire/portable/vhost.conf' => false,
        ], $found);
    }

    // --- non-root, group 0, 8080 (W13) -----------------------------------------------------------------------------

    public function testThePortableImageRunsAsANumericNonRootUserInGroupZeroOn8080(): void
    {
        $dockerfile = $this->read(self::DOCKERFILE);

        self::assertMatchesRegularExpression('/^USER 10001:0$/m', $dockerfile, 'a numeric UID (no /etc/passwd entry) in group 0');
        self::assertSame(1, preg_match_all('/^USER /m', $dockerfile), 'one USER line, after every root step');
        self::assertGreaterThan(strrpos($dockerfile, "\nRUN "), strpos($dockerfile, "\nUSER "), 'nothing runs as root after USER');
        self::assertStringContainsString("EXPOSE 8080\n", $dockerfile);
        self::assertStringContainsString("STOPSIGNAL SIGTERM\n", $dockerfile, 'the base image stops on SIGWINCH, which a PHP worker ignores');
        self::assertStringEndsWith("ENTRYPOINT [\"squire-entrypoint\"]\nCMD []\n", $dockerfile, 'CMD [] so SQUIRE_ROLE decides, and the base image\'s apache2-foreground is not inherited');
        self::assertStringContainsString('CMD ["squire-entrypoint", "health"]', $dockerfile);
        self::assertStringNotContainsString('www-data', $dockerfile);
        self::assertStringNotContainsString('--chown', $dockerfile, 'files belong to root and group 0, never to a named user');
        self::assertStringContainsString('HOME=/tmp', $dockerfile);

        // The folders it writes: Apache's run, lock and log folders, and the app's (OpenShift's recipe, chgrp then g=u).
        self::assertSame(2, substr_count($dockerfile, 'chgrp -R 0 "$dir";'), 'Apache\'s folders and the app\'s');
        self::assertSame(2, substr_count($dockerfile, 'chmod -R g=u,ug+rwX "$dir";'));
        self::assertStringContainsString('for dir in "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"; do', $dockerfile);
        self::assertStringContainsString('if [ -z "$dirs" ] && [ -f artisan ]; then dirs="storage bootstrap/cache"; fi;', $dockerfile);
    }

    public function testApacheListensOnSquirePortAndLogsToTheContainer(): void
    {
        $ports = $this->read('common/.squire/portable/ports.conf');
        self::assertMatchesRegularExpression('/^Listen \$\{SQUIRE_PORT\}$/m', $ports);
        self::assertSame(1, preg_match_all('/^Listen /m', $ports), 'no Listen 80 and no 443 left');

        $vhost = $this->read('common/.squire/portable/vhost.conf');
        self::assertStringContainsString('<VirtualHost *:${SQUIRE_PORT}>', $vhost);
        self::assertStringContainsString('DocumentRoot ${CITADEL_PUBLIC_DIR}', $vhost, 'the same document root as Citadel');
        self::assertStringContainsString('CustomLog /proc/self/fd/1 combined', $vhost);
        self::assertSame(2, substr_count($vhost, 'ErrorLog /proc/self/fd/2'), 'the server and the site both log to stderr');
        self::assertStringNotContainsString('rotatelogs', $vhost);
    }

    public function testThePortableImageSharesTheCitadelMirrorsPhpSettingsAndBuildStep(): void
    {
        $portable = $this->read(self::DOCKERFILE);
        $citadel = $this->read('common/.squire/Dockerfile');
        foreach ([
            'COPY .squire/php.ini /usr/local/etc/php/php.ini',
            'COPY .squire/php-web.ini /usr/local/etc/php/php-web.ini',
            'RUN { cat /usr/local/etc/php/php.ini; echo; cat /usr/local/etc/php/php-web.ini; } > /usr/local/etc/php/php-apache2handler.ini',
            'COPY build.sh /citadel/build/',
            'RUN /bin/sh /citadel/build/build.sh && rm -Rf /citadel/build/',
        ] as $line) {
            self::assertStringContainsString($line, $citadel, 'the Citadel mirror still has: ' . $line);
            $kitLine = preg_replace('/^COPY (\S)/', 'COPY ${SQUIRE_KIT_DIR}/$1', $line);
            self::assertStringContainsString((string) $kitLine, $portable, 'the portable image repeats: ' . $line);
        }
        self::assertStringContainsString('ARG SQUIRE_OPCACHE_VALIDATE=0', $portable, 'baked code: opcache never checks files (SQUIRE-10)');
        self::assertStringContainsString('FROM ${BASE_IMAGE}', $portable, 'the same base as the Citadel mirror');
        self::assertDoesNotMatchRegularExpression('/^# *syntax=/m', $portable, 'a frontend image would be one more unpinned pull (SQUIRE-30)');
    }

    public function testTheImageBuildRunsTheReleaseWithoutItsRunTimeSteps(): void
    {
        $dockerfile = $this->read(self::DOCKERFILE);
        $run = substr($dockerfile, (int) strpos($dockerfile, 'RUN --mount=type=cache,target=/tmp/squire-cache'));
        $run = substr($run, 0, (int) strpos($run, "\n\n"));

        self::assertStringContainsString('bash "/citadel/src/${SQUIRE_KIT_DIR}/scripts/release.sh"', $run);
        foreach (['SQUIRE_MIGRATE=0', 'SQUIRE_ARTISAN_OPTIMIZE=0', 'SQUIRE_ARTISAN_RESTART=0', 'SQUIRE_SMOKE=0', 'SQUIRE_REQUIRE_ENV=0', 'SQUIRE_CACHE_DIR=/tmp/squire-cache'] as $setting) {
            self::assertStringContainsString($setting, $run, 'the image build runs the release with ' . $setting);
        }
        self::assertStringNotContainsString('artisan', $run, 'no config:cache at build: it would freeze the build machine\'s environment (W10)');
        self::assertStringContainsString('SQUIRE_APP_DIR=/citadel/src', $dockerfile, 'the release and health.php find the app even when the kit is in a subfolder');
    }

    public function testTheFrontendBuildsNodeModulesNeverReachALayer(): void
    {
        $dockerfile = $this->read(self::DOCKERFILE);
        $run = substr($dockerfile, (int) strpos($dockerfile, 'RUN --mount=type=cache,target=/tmp/squire-cache'));
        $run = substr($run, 0, (int) strpos($run, "\n\n"));

        // Removed in the RUN that made it: a later RUN would only hide the folder under a layer that still holds it.
        self::assertStringContainsString('rm -rf /citadel/src/node_modules', $run, 'the release RUN drops node_modules/ after the build');
        self::assertStringContainsString('if [ "$SQUIRE_KEEP_NODE_MODULES" != "1" ]', $run, 'only SQUIRE_KEEP_NODE_MODULES=1 keeps it');
        self::assertStringContainsString("ARG SQUIRE_KEEP_NODE_MODULES=0\nRUN --mount=type=cache", $dockerfile, 'off by default, declared for the release RUN');
    }

    public function testThePortableBuildContextKeepsSecretsAndRebuiltFoldersOut(): void
    {
        $ignore = $this->read('common/.squire/portable.Dockerfile.dockerignore');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $ignore)), static fn(string $l): bool => $l !== '' && $l[0] !== '#'));
        foreach (['**/.env', '**/.env.*', '**/auth.json', '**/secrets', '**/.git', 'vendor', '**/node_modules'] as $pattern) {
            self::assertContains($pattern, $lines, 'the portable context excludes ' . $pattern);
        }
        foreach ($lines as $line) {
            if (str_starts_with($line, '!')) {
                self::assertDoesNotMatchRegularExpression('#env|auth|secret|\.git(/|$)|\.pem|\.key#', $line, 'nothing secret is let back in: ' . $line);
            }
        }
    }

    // --- the entrypoint, run in bash ----------------------------------------------------------------------------------

    private function requireBash(): void
    {
        if (!self::hasProgram('bash')) {
            self::markTestSkipped('bash is not installed; the portable entrypoint needs it.');
        }
    }

    /**
     * Stand-ins for the programs the entrypoint calls. Each writes one line to $STUB_LOG.
     *
     * @return array{bin: string, log: string}
     */
    private function stubs(): array
    {
        $dir = $this->tempDir();
        $log = $dir . '/calls.log';
        $stub = static function (string $name, string $body) use ($dir): void {
            file_put_contents($dir . '/bin/' . $name, "#!/usr/bin/env bash\n" . $body . "\n");
            chmod($dir . '/bin/' . $name, 0o755);
        };
        mkdir($dir . '/bin');
        $stub('php', 'printf "php %s | port=%s | secret=%s\n" "$*" "${SQUIRE_HEALTH_PORT:-}" "${DB_PASSWORD:-}" >>"$STUB_LOG"');
        $stub('id', 'case "$1" in -u) echo "${STUB_UID:-10001}" ;; -g) echo 0 ;; esac');
        $stub('apache2-foreground', implode("\n", [
            'printf "apache %s | port=%s\n" "$*" "${SQUIRE_PORT:-}" >>"$STUB_LOG"',
            'if [ "${STUB_APACHE_WAIT:-0}" = "1" ]; then',
            '    trap \'echo graceful >>"$STUB_LOG"; exit 0\' WINCH',
            '    trap \'echo hard-stop >>"$STUB_LOG"; exit 9\' TERM',
            '    echo ready >>"$STUB_LOG"',
            '    while :; do sleep 0.05; done',
            'fi',
        ]));

        return ['bin' => $dir . '/bin', 'log' => $log];
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     * @return array{exit: int, calls: string, stderr: string, stdout: string}
     */
    private function entrypoint(array $args, array $env = [], bool $laravel = true): array
    {
        $stubs = $this->stubs();
        $app = $this->tempDir();
        if ($laravel) {
            file_put_contents($app . '/artisan', "<?php\n");
        }
        $process = proc_open(
            ['bash', Squire::templatesDir() . '/' . self::ENTRYPOINT, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            [
                'PATH' => $stubs['bin'] . ':' . (string) getenv('PATH'),
                'STUB_LOG' => $stubs['log'],
                'SQUIRE_APP_DIR' => $app,
                'SQUIRE_ROLE_FILE' => $app . '/role',
                'SQUIRE_SECRETS_DIR' => $app . '/no-secrets',
                ...$env,
            ],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'calls' => is_file($stubs['log']) ? (string) file_get_contents($stubs['log']) : '', 'stderr' => $stderr, 'stdout' => $stdout];
    }

    /** @return iterable<string, array{list<string>, array<string, string>, string}> */
    public static function roles(): iterable
    {
        yield 'web by default' => [[], [], "php artisan optimize | port= | secret=\napache  | port=8080\n"];
        yield 'web by SQUIRE_ROLE' => [[], ['SQUIRE_ROLE' => 'web'], "php artisan optimize | port= | secret=\napache  | port=8080\n"];
        yield 'the base image command is the web role' => [['apache2-foreground'], [], "php artisan optimize | port= | secret=\napache  | port=8080\n"];
        yield 'worker' => [['worker'], [], "php artisan optimize | port= | secret=\nphp artisan queue:work --tries=3 --max-time=3600 --sleep=3 | port= | secret=\n"];
        yield 'worker by SQUIRE_ROLE, extra options passed on' => [['--queue=high,default'], ['SQUIRE_ROLE' => 'worker'], "php artisan optimize | port= | secret=\nphp artisan queue:work --tries=3 --max-time=3600 --sleep=3 --queue=high,default | port= | secret=\n"];
        yield 'options alone are Apache\'s on the web role' => [['-DSQUIRE_TEST'], [], "php artisan optimize | port= | secret=\napache -DSQUIRE_TEST | port=8080\n"];
        yield 'scheduler' => [['scheduler'], [], "php artisan optimize | port= | secret=\nphp artisan schedule:work | port= | secret=\n"];
        yield 'migrate runs no optimize' => [['migrate'], [], "php artisan migrate --force | port= | secret=\n"];
        yield 'the argument wins over SQUIRE_ROLE' => [['scheduler'], ['SQUIRE_ROLE' => 'worker'], "php artisan optimize | port= | secret=\nphp artisan schedule:work | port= | secret=\n"];
        yield 'SQUIRE_ARTISAN_OPTIMIZE=0 skips optimize' => [['worker'], ['SQUIRE_ARTISAN_OPTIMIZE' => '0'], "php artisan queue:work --tries=3 --max-time=3600 --sleep=3 | port= | secret=\n"];
        yield 'any other command is run as it is' => [['php', 'artisan', 'about'], [], "php artisan about | port= | secret=\n"];
        yield 'PORT is honored' => [[], ['PORT' => '9000'], "php artisan optimize | port= | secret=\napache  | port=9000\n"];
        yield 'SQUIRE_PORT wins over PORT' => [[], ['PORT' => '9000', 'SQUIRE_PORT' => '8888'], "php artisan optimize | port= | secret=\napache  | port=8888\n"];
        yield 'a named migrate command, still with no optimize' => [['migrate'], ['SQUIRE_MIGRATE_COMMAND' => 'php bin/migrate.php'], "php bin/migrate.php | port= | secret=\n"];
        yield 'a named worker command replaces the default' => [['worker', '--once'], ['SQUIRE_WORKER_COMMAND' => 'php bin/queue.php  --sleep=5'], "php artisan optimize | port= | secret=\nphp bin/queue.php --sleep=5 --once | port= | secret=\n"];
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    #[DataProvider('roles')]
    public function testTheRolePicksTheProcess(array $args, array $env, string $calls): void
    {
        $this->requireBash();
        $result = $this->entrypoint($args, $env);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame($calls, $result['calls']);
    }

    public function testARawPhpAppNamesItsOwnWorkerAndSchedulerAndHasNothingToMigrate(): void
    {
        $this->requireBash();

        $worker = $this->entrypoint(['worker'], [], false);
        self::assertSame(64, $worker['exit']);
        self::assertStringContainsString('has no artisan, so there is no default queue worker; set SQUIRE_WORKER_COMMAND', $worker['stderr']);
        self::assertSame('', $worker['calls'], 'nothing ran');

        $scheduler = $this->entrypoint(['scheduler'], [], false);
        self::assertSame(64, $scheduler['exit']);
        self::assertStringContainsString('set SQUIRE_SCHEDULER_COMMAND', $scheduler['stderr']);

        $named = $this->entrypoint(['worker'], ['SQUIRE_WORKER_COMMAND' => 'php bin/worker.php'], false);
        self::assertSame(0, $named['exit'], $named['stderr']);
        self::assertSame("php bin/worker.php | port= | secret=\n", $named['calls'], 'no artisan, so no optimize either');

        $migrate = $this->entrypoint(['migrate'], [], false);
        self::assertSame(0, $migrate['exit'], 'a one-shot migrate job with nothing to do succeeds, so the other services start');
        self::assertStringContainsString('nothing to migrate', $migrate['stderr']);

        $web = $this->entrypoint([], [], false);
        self::assertSame("apache  | port=8080\n", $web['calls'], 'a raw PHP app goes straight to Apache');
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badSettings(): iterable
    {
        yield 'a privileged port as a non-root user' => [['PORT' => '80'], 'cannot listen below 1024; use 8080'];
        yield 'a port that is not a number' => [['SQUIRE_PORT' => '80a'], "must be a port number; got '80a'"];
        yield 'a port out of range' => [['SQUIRE_PORT' => '70000'], 'must be 65535 or lower'];
        yield 'an unknown role' => [['SQUIRE_ROLE' => 'cron'], "SQUIRE_ROLE must be web, worker, scheduler or migrate; got 'cron'"];
        yield 'an empty named command' => [['SQUIRE_ROLE' => 'worker', 'SQUIRE_WORKER_COMMAND' => ' '], 'SQUIRE_WORKER_COMMAND is set but empty'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('badSettings')]
    public function testABadSettingStopsTheContainerWithAPlainMessage(array $env, string $message): void
    {
        $this->requireBash();
        $result = $this->entrypoint([], $env);

        self::assertSame(64, $result['exit'], $result['stderr']);
        self::assertStringStartsWith('[squire] FAILED: ', $result['stderr']);
        self::assertStringContainsString($message, $result['stderr']);
        self::assertStringNotContainsString('apache', $result['calls'], 'Apache never starts on a bad setting');
    }

    public function testRootMayStillListenOnAPrivilegedPort(): void
    {
        $this->requireBash();
        $result = $this->entrypoint([], ['PORT' => '80', 'STUB_UID' => '0']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertStringContainsString("apache  | port=80\n", $result['calls']);
    }

    public function testTheHealthCheckAsksHealthPhpForWebAndPassesTheOtherRoles(): void
    {
        $this->requireBash();

        $web = $this->entrypoint(['health'], ['PORT' => '9001', 'SQUIRE_KIT_DIR' => 'deploy']);
        self::assertSame(0, $web['exit'], $web['stderr']);
        self::assertMatchesRegularExpression('#^php \S+/deploy/health\.php \| port=9001 \| secret=\n$#', $web['calls'], 'health.php in the kit folder, on the port Apache listens on');

        $stubs = $this->stubs();
        $app = $this->tempDir();
        file_put_contents($app . '/role', "worker\n");
        $process = proc_open(
            ['bash', Squire::templatesDir() . '/' . self::ENTRYPOINT, 'health'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            ['PATH' => $stubs['bin'] . ':' . (string) getenv('PATH'), 'STUB_LOG' => $stubs['log'], 'SQUIRE_APP_DIR' => $app, 'SQUIRE_ROLE_FILE' => $app . '/role'],
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame("healthy: the worker role has no HTTP endpoint; it is healthy while its process runs\n", $out, 'the role the container started with, read back from the role file');
        self::assertFileDoesNotExist($stubs['log'], 'a worker\'s health check calls nothing');
    }

    public function testTheRoleIsLeftForTheHealthCheckAndTheSecretsAreLoadedFirst(): void
    {
        $this->requireBash();
        $stubs = $this->stubs();
        $app = $this->tempDir();
        file_put_contents($app . '/artisan', "<?php\n");
        mkdir($app . '/secrets');
        file_put_contents($app . '/secrets/DB_PASSWORD', "from-a-file\n");
        $process = proc_open(
            ['bash', Squire::templatesDir() . '/' . self::ENTRYPOINT, 'scheduler'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            ['PATH' => $stubs['bin'] . ':' . (string) getenv('PATH'), 'STUB_LOG' => $stubs['log'], 'SQUIRE_APP_DIR' => $app, 'SQUIRE_ROLE_FILE' => $app . '/role', 'SQUIRE_SECRETS_DIR' => $app . '/secrets'],
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $err);

        self::assertSame("scheduler\n", (string) file_get_contents($app . '/role'));
        self::assertStringContainsString('php artisan schedule:work | port= | secret=from-a-file', (string) file_get_contents($stubs['log']), 'the scheduler sees the mounted secret');
        self::assertStringNotContainsString('from-a-file', $err, 'and its value is never printed');
    }

    public function testTheEntrypointCarriesTheSameSecretsLoader(): void
    {
        $block = static function (string $text): string {
            $start = (int) strpos($text, '# squire-secrets: begin');

            return substr($text, $start, (int) strpos($text, '# squire-secrets: end') - $start);
        };
        $entrypoint = $this->read(self::ENTRYPOINT);
        self::assertNotSame('', $block($entrypoint));
        self::assertSame($block($this->read('common/scripts/release.sh')), $block($entrypoint), 'one loader, three copies kept identical');
        self::assertLessThan(strpos($entrypoint, "\ncase \"\$role\" in\n    web) run_web"), strpos($entrypoint, "\nsquire_load_secrets || exit 1\n"), 'secrets before any role starts');
    }

    public function testSigtermIsAGracefulStopForApacheAndItsStatusIsTheContainers(): void
    {
        $this->requireBash();
        if (!function_exists('proc_terminate')) {
            self::markTestSkipped('proc_terminate is not available.');
        }
        $stubs = $this->stubs();
        $app = $this->tempDir();
        $process = proc_open(
            ['bash', Squire::templatesDir() . '/' . self::ENTRYPOINT, 'web'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            ['PATH' => $stubs['bin'] . ':' . (string) getenv('PATH'), 'STUB_LOG' => $stubs['log'], 'STUB_APACHE_WAIT' => '1', 'SQUIRE_APP_DIR' => $app, 'SQUIRE_ROLE_FILE' => $app . '/role'],
        );
        self::assertIsResource($process);
        for ($i = 0; $i < 100 && !str_contains(is_file($stubs['log']) ? (string) file_get_contents($stubs['log']) : '', "ready\n"); ++$i) {
            usleep(50_000);
        }
        self::assertStringContainsString("ready\n", (string) file_get_contents($stubs['log']), 'Apache started');

        proc_terminate($process, 15);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        $calls = (string) file_get_contents($stubs['log']);
        self::assertStringContainsString("graceful\n", $calls, 'SIGTERM reached Apache as SIGWINCH, its graceful stop: ' . $err);
        self::assertStringNotContainsString('hard-stop', $calls);
        self::assertSame(0, $exit, 'the container exits with Apache\'s own status');
    }
}

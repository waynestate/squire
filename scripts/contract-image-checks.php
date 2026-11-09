<?php

/*
 * scripts/contract-image-checks.php: the hardening half of the image contract, run INSIDE the built
 * app image, so a file can no longer promise what the running image does not do.
 *
 * scripts/contract-image.sh pipes this file to `php` in the running container (as the citadel user) once the
 * release has run; any driver that can exec PHP in the container can do the same. It reads three environment
 * variables: CONTRACT_SECRET_MD5 (the md5 of the secret file the driver mounted), CONTRACT_SECRET_NAME (that
 * file's name) and CONTRACT_APP_DIR (the app root, default /citadel/src).
 *
 * It asks Apache on 127.0.0.1:80 for the fixture's probe page, which reports the web's own PHP settings, and checks
 * Apache's own answers: one PASS or FAIL line per assertion, exit 1 on any FAIL. Values are never printed, only
 * whether they matched. A squire checks the straps before the knight rides, not after he falls off.
 */

declare(strict_types=1);

$failures = 0;
$report = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'PASS: ' : 'FAIL: '), $what, "\n";
    if (!$ok) {
        $failures++;
    }
};

/** @return array{status: int, headers: array<string, string>, body: string} */
$request = static function (string $method, string $path): array {
    $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0]]);
    $body = @file_get_contents('http://127.0.0.1:80' . $path, false, $context);
    $lines = $http_response_header ?? [];
    $status = 0;
    $headers = [];
    foreach ($lines as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body === false ? '' : $body];
};

// --- Apache says less about itself ----------------------------------------------------------------------------------

$up = $request('GET', '/up.php');
$report($up['status'] === 200, 'GET /up.php answers 200 inside the container (got ' . $up['status'] . ')');
$server = $up['headers']['server'] ?? '';
$report($server === 'Apache', 'the Server header is exactly "Apache", no version or OS (ServerTokens Prod; got "' . $server . '")');
$report(!isset($up['headers']['x-powered-by']), 'no X-Powered-By header (expose_php Off)');

$trace = $request('TRACE', '/up.php');
$report($trace['status'] === 405, 'TRACE answers 405 (TraceEnable Off; got ' . $trace['status'] . ')');
$report(!str_contains($trace['body'], '<address>'), "Apache's own error page has no version footer (ServerSignature Off)");

// --- the web's PHP, from the fixture's probe page -------------------------------------------------------------------

$probe = $request('GET', '/squire-contract-probe');
/** @var array<string, mixed>|null $web */
$web = json_decode($probe['body'], true);
$report(is_array($web) && ($web['sapi'] ?? '') === 'apache2handler', 'the probe page runs under mod_php (got status ' . $probe['status'] . ', sapi ' . (string) (is_array($web) ? ($web['sapi'] ?? '?') : '?') . ')');
$web = is_array($web) ? $web : [];

// opcache, by how the code arrives
$report(($web['opcache'] ?? false) === true, 'opcache is on for the web');
// The code here is mounted and pulled in place, so the web must check files again (validate_timestamps 1, every 2 s).
// contract-image.sh checks the baked case (an app image built with SQUIRE_OPCACHE_VALIDATE=0) on its own.
$report(($web['validate_timestamps'] ?? null) === '1' && ($web['revalidate_freq'] ?? null) === '2', 'code pulled in place: the web checks files every 2 seconds (got validate_timestamps ' . var_export($web['validate_timestamps'] ?? null, true) . ', revalidate_freq ' . var_export($web['revalidate_freq'] ?? null, true) . ')');
$report(($web['max_files'] ?? null) === '20000', 'opcache.max_accelerated_files is 20000 for the web');

// The web loses the shell functions; the CLI keeps them
$disabled = array_map('trim', explode(',', (string) ($web['disabled'] ?? '')));
$missing = array_diff(['exec', 'passthru', 'shell_exec', 'system', 'pcntl_exec', 'phpinfo', 'show_source', 'highlight_file'], $disabled);
$report($missing === [], 'the web disables the shell functions' . ($missing === [] ? '' : ' (missing: ' . implode(', ', $missing) . ')'));
$report(($web['exec'] ?? true) === false, 'exec does not exist on the web');
$report(($web['proc_open'] ?? false) === true && ($web['popen'] ?? false) === true, 'proc_open and popen stay on the web (mail, Laravel Process)');

$cliDisabled = (string) ini_get('disable_functions');
$report($cliDisabled === '' && function_exists('exec') && function_exists('proc_open'), 'the CLI disables nothing (Composer scripts, artisan, the health check)');

// A secret mounted as a file reaches the web
$secretName = (string) getenv('CONTRACT_SECRET_NAME');
$secretMd5 = (string) getenv('CONTRACT_SECRET_MD5');
if ($secretName !== '' && $secretMd5 !== '') {
    $report(($web['secret_md5'] ?? null) === $secretMd5, 'the web sees the secret mounted at /run/secrets/' . $secretName . ' as a variable');
} else {
    $report(false, 'the driver passed CONTRACT_SECRET_NAME and CONTRACT_SECRET_MD5');
}

// A Composer script ran in the release, with the shell the CLI keeps (it writes "ok" through shell_exec).
$appDir = (string) (getenv('CONTRACT_APP_DIR') ?: '/citadel/src');
$marker = $appDir . '/.contract-composer-script';
$report(is_file($marker) && trim((string) file_get_contents($marker)) === 'ok', 'a Composer post-install script ran in the release and could shell out');

exit($failures === 0 ? 0 : 1);

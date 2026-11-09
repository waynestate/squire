<?php

/*
 * scripts/contract-image-probe.php: the fixture app's entry in the image contract. scripts/contract-image.sh
 * copies it into the fixture as app.php, so the raw front controller runs it for any path that is not a file, and
 * scripts/contract-image-checks.php reads it to see the web's own PHP settings. Secrets are reported as an md5,
 * never as a value. It exists only inside the throwaway fixture.
 */

declare(strict_types=1);

$secret = getenv('SQUIRE_CONTRACT_SECRET');

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'sapi' => PHP_SAPI,
    'opcache' => extension_loaded('Zend OPcache') && ini_get('opcache.enable') === '1',
    'validate_timestamps' => ini_get('opcache.validate_timestamps'),
    'revalidate_freq' => ini_get('opcache.revalidate_freq'),
    'max_files' => ini_get('opcache.max_accelerated_files'),
    'disabled' => ini_get('disable_functions'),
    'exec' => function_exists('exec'),
    'proc_open' => function_exists('proc_open'),
    'popen' => function_exists('popen'),
    'secret_md5' => $secret === false ? null : md5($secret),
]);

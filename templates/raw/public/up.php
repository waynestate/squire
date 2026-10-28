<?php

declare(strict_types=1);

/*
 * up.php: a dependency-free health endpoint for apps with no framework (SQUIRE S55).
 *
 * It answers 200 and nothing else: no session, no database, no includes. If the app uses HELMET, HELMET's /up
 * handler replaces this file; set SQUIRE_HEALTH_PATH=/up and SQUIRE_SMOKE_URL to match.
 */

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "ok\n";

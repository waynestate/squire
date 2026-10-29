<?php

declare(strict_types=1);

/*
 * index.php: the front controller for a raw PHP app (no framework).
 *
 * Every request that public/.htaccess sends here gets one of three answers:
 *
 *   1. a real file in public/ (CSS, images, fonts, a page): served by this
 *      file, with HELMET's headers when HELMET is installed;
 *   2. a 404 for what must never leave the machine: a dot file (.htaccess,
 *      .git), a path with a NUL byte, a .php or .phar other than this one,
 *      and any file type that is not a site asset (a .sql dump, a .bak);
 *   3. everything else: the app's entry, one PHP file outside public/.
 *
 * The only PHP this file ever runs is that entry. A .php in public/ is never
 * executed or shown by it (public/up.php answers on its own, because the
 * .htaccess lets Apache run it straight).
 *
 * The entry is SQUIRE_APP_ENTRY when that variable is set and non-empty: a
 * path relative to the app directory, the parent of public/ (for example
 * src/bootstrap.php). Otherwise it is app.php. It must stay inside the app
 * directory and end in .php. It runs in this file's scope, after nothing has
 * been sent, so it boots its own HELMET with its own settings (CSRF, logging,
 * trusted proxies) exactly as it would from its own index.php.
 *
 * HELMET notes: HELMET belongs to whoever answers. This file boots it (with
 * the citadel preset) only for the answers it gives itself, the files and
 * the 404s, and never before the entry, so the app is never booted twice.
 * HTML served from public/ gets HELMET's CSP nonce on every <script> tag,
 * the way the static target does it.
 *
 * The file checks, the MIME map and the nonce step are the static target's
 * (templates/static/public/index.php), kept in step by Squire's tests. A
 * squire carries the same shield on both arms.
 */

$squireAppRoot = realpath(__DIR__ . '/..');

$squireHelmet = static function (): ?array {
    if (is_file(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
    if (!function_exists('Waynestate\Helmet\helmet_boot')) {
        return null;
    }

    // HELMET's citadel preset names the platform's front proxy.
    return \Waynestate\Helmet\helmet_boot([
        'preset' => 'citadel',
    ]);
};

$notFound = static function () use ($squireHelmet): never {
    $squireHelmet();
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Not found</title></head><body>'
        . '<h1>Not found</h1><p>The page you asked for is not here.</p>'
        . '</body></html>';
    exit;
};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// This file's own name is the front door, the same as /.
if ($path === '/' . basename(__FILE__)) {
    $path = '/';
}

// Reject what must never leave the machine: a NUL byte, any dot segment
// (.htaccess, .., ./), and the extensions that are not site assets.
if (str_contains($path, "\0")) {
    $notFound();
}
foreach (explode('/', $path) as $segment) {
    if ($segment !== '' && str_starts_with($segment, '.')) {
        $notFound();
    }
}
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (in_array($extension, ['php', 'phar'], true)) {
    $notFound();
}

// MIME types the front pages ship. Text types carry a charset.
$mime = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg' => 'image/svg+xml; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
    'woff' => 'font/woff',
    'ttf' => 'font/ttf',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/markdown; charset=utf-8',
    'xml' => 'application/xml; charset=utf-8',
    'webmanifest' => 'application/manifest+json; charset=utf-8',
    'pdf' => 'application/pdf',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
];

// A file is only a file for reading: a POST, PUT or DELETE always belongs to the app.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$publicRoot = realpath(__DIR__);
if ($publicRoot !== false && $path !== '/' && ($method === 'GET' || $method === 'HEAD')) {
    // Resolve inside public/. A directory maps to its index.html, when it has one.
    $resolved = realpath($publicRoot . $path);
    if ($resolved !== false && is_dir($resolved)) {
        $resolved = realpath($resolved . '/index.html') ?: false;
    }

    if ($resolved !== false && is_file($resolved)) {
        // Something is there. It leaves only from inside public/, only as a known asset type, and a .md only
        // when it is a page's twin (the .html of the same name sits beside it). A symlink out of public/ is a 404.
        $served = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        if (!str_starts_with($resolved, $publicRoot . DIRECTORY_SEPARATOR)
            || !isset($mime[$served])
            || ($served === 'md' && !is_file(substr($resolved, 0, -3) . '.html'))
        ) {
            $notFound();
        }

        $helmet = $squireHelmet();

        // HTML: the CSP nonce changes per request, so the page is rewritten on the
        // fly and never cached. Every <script start tag without a nonce gets one.
        if ($served === 'html') {
            $body = file_get_contents($resolved);
            if ($body === false) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'The page could not be read.';
                exit;
            }
            if ($helmet !== null) {
                $nonce = $helmet['nonce'];
                $body = preg_replace_callback(
                    '/<script(?=[\s>])([^>]*)>/i',
                    static function (array $match) use ($nonce): string {
                        if (preg_match('/\bnonce\s*=/i', $match[1])) {
                            return $match[0];
                        }
                        return '<script nonce="' . $nonce . '"' . $match[1] . '>';
                    },
                    $body,
                );
            }
            header('Content-Type: ' . $mime['html']);
            header('Cache-Control: no-cache');
            header('Content-Length: ' . strlen($body));
            if ($method === 'GET') {
                echo $body;
            }
            exit;
        }

        // Everything else: a stable identity, a cache life of an hour, and a 304
        // when the client already holds this copy.
        $stat = stat($resolved);
        $etag = '"' . sha1($stat['mtime'] . '-' . $stat['size']) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT';

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $matches = $ifNoneMatch !== ''
            && ($ifNoneMatch === '*' || in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true));
        if ($matches) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModified);
            exit;
        }

        header('Content-Type: ' . $mime[$served]);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModified);
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . $stat['size']);
        if ($method === 'GET') {
            readfile($resolved);
        }
        exit;
    }
}

// Not a file: the app's turn. The entry is the one PHP file this front controller will run.
$squireEntryName = getenv('SQUIRE_APP_ENTRY');
$squireEntryName = $squireEntryName === false || $squireEntryName === '' ? 'app.php' : $squireEntryName;
$squireEntry = $squireAppRoot === false ? false : realpath($squireAppRoot . '/' . $squireEntryName);
if ($squireEntry === false
    || !str_starts_with($squireEntry, $squireAppRoot . DIRECTORY_SEPARATOR)
    || strtolower(pathinfo($squireEntry, PATHINFO_EXTENSION)) !== 'php'
    || $squireEntry === realpath(__FILE__)
    || !is_file($squireEntry)
) {
    // Say what to fix without saying where anything lives.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'The app entry is missing. Add app.php beside public/, or set SQUIRE_APP_ENTRY to a .php file in the app.';
    exit;
}

// Leave the entry a clean scope: only $squireEntry survives, and the entry may ignore it.
unset($squireAppRoot, $squireHelmet, $notFound, $path, $segment, $extension, $mime, $method, $publicRoot, $resolved, $squireEntryName);

require $squireEntry;

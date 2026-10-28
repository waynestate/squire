#!/usr/bin/env php
<?php

declare(strict_types=1);

use Waynestate\Squire\Squire;
use Waynestate\Squire\Template\Catalog;
use Waynestate\Squire\Template\Exporter;
use Waynestate\Squire\ToolError;

// SQUIRE is a Composer package: its autoloader is either its own vendor/ or the host app's.
$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

$autoloaded = false;
foreach ($autoloaders as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer install` first.\n");
    exit(2);
}

$usage = "Usage: php bin/export-templates.php --to=<dir> [--check]\n"
    . "\n"
    . "Export a generated copy of SQUIRE's templates (for raw and laravel apps, plus the Vue layer) into <dir>, with a\n"
    . "catalog.json and a manifest.json. With --check, compare an existing copy with a fresh export\n"
    . "and exit 1 on drift.\n";

$to = null;
$check = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--to=')) {
        $to = substr($arg, 5);
    } elseif ($arg === '--check') {
        $check = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, $usage);
        exit(0);
    } else {
        fwrite(STDERR, $usage);
        exit(2);
    }
}

if ($to === null || $to === '') {
    fwrite(STDERR, "Missing --to=<dir>.\n\n" . $usage);
    exit(2);
}

$exporter = new Exporter(new Catalog(Squire::templatesDir()));

try {
    if ($check) {
        $drifted = $exporter->check($to);
        if ($drifted === []) {
            fwrite(STDOUT, sprintf("OK: %s matches SQUIRE %s.\n", $to, $exporter->version()));
            exit(0);
        }
        fwrite(STDOUT, sprintf("DRIFT: %d file(s) in %s differ from SQUIRE %s:\n", count($drifted), $to, $exporter->version()));
        foreach ($drifted as $file) {
            fwrite(STDOUT, '  ' . $file . "\n");
        }
        exit(1);
    }

    $written = $exporter->export($to);
    fwrite(STDOUT, sprintf("Exported %d files to %s (SQUIRE %s).\n", count($written), $to, $exporter->version()));
    exit(0);
} catch (ToolError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

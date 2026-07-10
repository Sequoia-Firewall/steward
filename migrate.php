#!/usr/bin/env php
<?php
// CLI migration runner — apply all pending database migrations.
// Usage: php migrate.php
//
// Run this on the server after deploying a new version:
//   php /var/www/html/steward/migrate.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

define('MONEY_SETUP_MODE', true); // Prevent setup redirect
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

echo 'Steward ' . APP_VERSION . ' — Migration Runner' . PHP_EOL;
echo str_repeat('-', 44) . PHP_EOL;

$pending = getPendingMigrations();

if (empty($pending)) {
    echo 'Database is up to date. No migrations to apply.' . PHP_EOL;
    exit(0);
}

$count = count($pending);
echo $count . ' pending migration' . ($count !== 1 ? 's' : '') . ':' . PHP_EOL . PHP_EOL;

$failed = 0;
foreach ($pending as $ver => $path) {
    $label = 'v' . str_pad($ver, 3, '0', STR_PAD_LEFT) . '  ' . basename($path);
    echo '  Applying ' . $label . ' ... ';
    $result = runMigration($ver, $path);
    if ($result['ok']) {
        echo 'OK' . PHP_EOL;
    } else {
        echo 'FAILED' . PHP_EOL;
        foreach ($result['errors'] as $err) {
            echo '    Error: ' . $err . PHP_EOL;
        }
        $failed++;
    }
}

echo PHP_EOL;
if ($failed > 0) {
    echo $failed . ' migration(s) failed. Review the errors above.' . PHP_EOL;
    exit(1);
}

echo 'All migrations applied successfully.' . PHP_EOL;
exit(0);

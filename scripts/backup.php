<?php
/**
 * CLI backup script — called by cron, never directly via web.
 * Usage: php /var/www/html/steward/scripts/backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('MONEY_CLI_MODE', true);
$appRoot = dirname(__DIR__);
require_once $appRoot . '/config/app.php';
require_once $appRoot . '/config/database.php';
require_once $appRoot . '/includes/functions.php';

$dir    = rtrim(getSetting('backup_dir', '/var/backups/steward'), '/');
$retain = max(1, (int)getSetting('backup_retain', '14'));

if (!is_dir($dir)) {
    if (!@mkdir($dir, 0750, true)) {
        fwrite(STDERR, "Cannot create backup directory: $dir\n");
        exit(1);
    }
}

if (!is_writable($dir)) {
    fwrite(STDERR, "Backup directory is not writable: $dir\n");
    exit(1);
}

$filename = $dir . '/steward-backup-' . date('Y-m-d_His') . '.sql';
$fh = fopen($filename, 'w');
if (!$fh) {
    fwrite(STDERR, "Cannot create backup file: $filename\n");
    exit(1);
}

$db     = getDB();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

fwrite($fh, "-- Steward Backup\n");
fwrite($fh, "-- Generated     : " . date('Y-m-d H:i:s T') . "\n");
fwrite($fh, "-- Database      : " . DB_NAME . "\n");
fwrite($fh, "-- Tables        : " . count($tables) . "\n");
fwrite($fh, "-- Schema-Version: " . getAppSchemaVersion() . "\n");
fwrite($fh, "\n");
fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
fwrite($fh, "SET time_zone='+00:00';\n");
fwrite($fh, "\n");

foreach ($tables as $table) {
    fwrite($fh, "-- --------------------------------------------------------\n");
    fwrite($fh, "-- Table: `$table`\n");
    fwrite($fh, "-- --------------------------------------------------------\n\n");
    fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");

    $ddl = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    fwrite($fh, $ddl[1] . ";\n\n");

    $stmt = $db->query("SELECT * FROM `$table`");
    $cols  = null;
    $batch = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($cols === null) {
            $cols = array_keys($row);
        }
        $vals = array_map(
            fn($v) => $v === null ? 'NULL' : $db->quote($v),
            array_values($row)
        );
        $batch[] = '(' . implode(', ', $vals) . ')';

        if (count($batch) >= 500) {
            fwrite($fh, 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n");
            fwrite($fh, implode(",\n", $batch) . ";\n\n");
            $batch = [];
        }
    }

    if (!empty($batch)) {
        fwrite($fh, 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n");
        fwrite($fh, implode(",\n", $batch) . ";\n\n");
    }
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fwrite($fh, "-- End of backup\n");
fclose($fh);

// Prune oldest backups beyond retain limit
$files = glob($dir . '/steward-backup-*.sql');
if ($files) {
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, $retain) as $old) {
        unlink($old);
    }
}

setSetting('backup_last_run', date('Y-m-d H:i:s'));
logActivity('auto_backup', 'Backup saved: ' . basename($filename), 0, 'system');

echo 'Backup complete: ' . $filename . "\n";

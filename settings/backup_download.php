<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

set_time_limit(0);
// Discard any buffered output so headers send cleanly
while (ob_get_level()) ob_end_clean();

$filename = 'steward-backup-' . date('Y-m-d_His') . '.sql';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$db     = getDB();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

echo "-- Steward Backup\n";
echo "-- Generated     : " . date('Y-m-d H:i:s T') . "\n";
echo "-- Database      : " . DB_NAME . "\n";
echo "-- Tables        : " . count($tables) . "\n";
echo "-- Schema-Version: " . getAppSchemaVersion() . "\n";
echo "\n";
echo "SET FOREIGN_KEY_CHECKS=0;\n";
echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
echo "SET time_zone='+00:00';\n";
echo "\n";

foreach ($tables as $table) {
    echo "-- --------------------------------------------------------\n";
    echo "-- Table: `$table`\n";
    echo "-- --------------------------------------------------------\n\n";

    echo "DROP TABLE IF EXISTS `$table`;\n";

    $ddl = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    echo $ddl[1] . ";\n\n";

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
            echo 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n";
            echo implode(",\n", $batch) . ";\n\n";
            $batch = [];
        }
    }

    if (!empty($batch)) {
        echo 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n";
        echo implode(",\n", $batch) . ";\n\n";
    }
}

echo "SET FOREIGN_KEY_CHECKS=1;\n";
echo "-- End of backup\n";

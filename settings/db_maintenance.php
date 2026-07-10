<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
verifyCsrf();

$action = $_POST['action'] ?? '';
$allowed = ['CHECK', 'ANALYZE', 'OPTIMIZE'];
$op = strtoupper($action);
if (!in_array($op, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

$db = getDB();

// Enumerate tables in the current database
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (empty($tables)) {
    echo json_encode(['ok' => true, 'rows' => []]);
    exit;
}

// Optimize rewrites/rebuilds tables in place — always take a safety backup first,
// whether triggered manually here or by any future automated maintenance run.
$backup = null;
if ($op === 'OPTIMIZE') {
    set_time_limit(0);
    $backup = createServerBackup();
    if (!$backup['ok']) {
        echo json_encode([
            'ok'                       => false,
            'status'                   => $backup['status'] ?? 'backup_failed',
            'error'                    => 'Backup failed, Optimize aborted: ' . $backup['error'],
            'can_configure_backup_dir' => isAdmin(),
            'backup_settings_url'      => isAdmin() ? (BASE_PATH . '/settings/backup') : null,
        ]);
        exit;
    }
    logActivity('auto_backup', 'Pre-optimize safety backup saved: ' . $backup['file']);
}

// Build quoted table list — names come from SHOW TABLES (trusted, no user input)
$quoted = implode(', ', array_map(fn($t) => '`' . $t . '`', $tables));
$sql    = "$op TABLE $quoted";

try {
    $rows    = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    logActivity('maintenance_error', "DB $op failed: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'DB operation failed: ' . $e->getMessage()]);
    exit;
}

$results = [];
foreach ($rows as $r) {
    // Normalize key casing (MySQL returns mixed case)
    $table   = $r['Table']    ?? $r['table']    ?? '';
    $msgType = $r['Msg_type'] ?? $r['msg_type'] ?? '';
    $msgText = $r['Msg_text'] ?? $r['msg_text'] ?? '';

    // Strip database prefix from table name (db.tablename → tablename)
    if (str_contains($table, '.')) {
        $table = substr($table, strpos($table, '.') + 1);
    }

    // Collapse InnoDB's verbose-but-harmless note into a cleaner message
    if ($msgType === 'note' && str_contains($msgText, 'recreate')) {
        $msgText = 'Rebuilt & optimized (InnoDB)';
        $msgType = 'status';
    }

    $results[] = [
        'table'    => $table,
        'msg_type' => $msgType,
        'msg_text' => $msgText,
    ];
}

logActivity('db_maintenance', "Ran $op on " . count($tables) . ' tables');
echo json_encode([
    'ok'          => true,
    'op'          => $op,
    'rows'        => $results,
    'backup_file' => $backup['file'] ?? null,
]);

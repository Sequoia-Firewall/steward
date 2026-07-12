<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

// Detect post_max_size overflow: when exceeded, PHP empties $_POST and $_FILES entirely,
// which makes the CSRF check fail with a confusing error. Catch it explicitly first.
$contentLength  = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxSetting = ini_get('post_max_size');
$postMaxBytes   = (int)$postMaxSetting;
$unit           = strtoupper(substr(trim($postMaxSetting), -1));
if ($unit === 'G') $postMaxBytes *= 1073741824;
elseif ($unit === 'M') $postMaxBytes *= 1048576;
elseif ($unit === 'K') $postMaxBytes *= 1024;
if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    setFlash('error', "The backup file is too large to upload (server limit: {$postMaxSetting}). Contact the server administrator to raise post_max_size.");
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

verifyCsrf();

// ── Validate upload ────────────────────────────────────────────
$upload = $_FILES['backup_file'] ?? null;

if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
    $code = $upload['error'] ?? -1;
    $msg  = match($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The backup file exceeds the maximum upload size allowed by the server.',
        UPLOAD_ERR_NO_FILE                         => 'No file was selected.',
        default                                    => 'File upload failed (error ' . $code . ').',
    };
    setFlash('error', $msg);
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

$ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
if ($ext !== 'sql') {
    setFlash('error', 'Invalid file type. Only .sql backup files are accepted.');
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

$sql = file_get_contents($upload['tmp_name']);
if ($sql === false || trim($sql) === '') {
    setFlash('error', 'The uploaded file is empty or could not be read.');
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

// ── Parse SQL into individual statements ──────────────────────
function parseSqlStatements(string $sql): array {
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $inString   = false;
    $strChar    = '';

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($inString) {
            $current .= $c;
            if ($c === '\\') {
                // Escaped character — consume the next char too
                if ($i + 1 < $len) $current .= $sql[++$i];
            } elseif ($c === $strChar) {
                $inString = false;
            }
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $inString = true;
            $strChar  = $c;
            $current .= $c;
            continue;
        }

        // Single-line comment (-- ...)
        if ($c === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            continue;
        }

        if ($c === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $c;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

$statements = parseSqlStatements($sql);
if (empty($statements)) {
    setFlash('error', 'No SQL statements found in the uploaded file.');
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

// ── Execute ───────────────────────────────────────────────────
set_time_limit(0);

$db      = getDB();
$errors  = [];
$success = 0;

foreach ($statements as $stmt) {
    try {
        $db->exec($stmt);
        $success++;
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
    }
}

if (!empty($errors)) {
    $preview = implode(' | ', array_slice($errors, 0, 3));
    $more    = count($errors) > 3 ? ' (+' . (count($errors) - 3) . ' more)' : '';
    setFlash('error', 'Restore completed with ' . count($errors) . ' error(s): ' . $preview . $more);
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

// ── Parse backup schema version ────────────────────────────────
$backupVersion = 0;
if (preg_match('/^--\s*Schema-Version:\s*(\d+)/m', $sql, $vm)) {
    $backupVersion = (int)$vm[1];
}

// ── Bootstrap schema_migrations table ─────────────────────────
// (may have been dropped if the backup pre-dates migration tracking)
$db->exec(
    "CREATE TABLE IF NOT EXISTS `schema_migrations` (
       `version`    SMALLINT UNSIGNED NOT NULL,
       `filename`   VARCHAR(100)      NOT NULL,
       `applied_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (`version`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// If the restored backup didn't include schema_migrations rows, seed up to its version
$seeded = (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ($seeded === 0 && $backupVersion > 0) {
    foreach (getMigrationFiles() as $ver => $path) {
        if ($ver <= $backupVersion) {
            $db->prepare('INSERT IGNORE INTO schema_migrations (version, filename, applied_at) VALUES (?, ?, NOW())')
               ->execute([$ver, basename($path)]);
        }
    }
}

// ── Run any pending migrations ─────────────────────────────────
$pending    = getPendingMigrations();
$migRan     = [];
$migFailed  = [];
foreach ($pending as $ver => $path) {
    $result = runMigration($ver, $path);
    if ($result['ok']) {
        $migRan[] = 'v' . $ver . ' ' . basename($path);
    } else {
        $migFailed[] = 'v' . $ver . ': ' . implode('; ', $result['errors']);
    }
}

// ── Invalidate every other session ─────────────────────────────
// A restore can replace user rows, roles, and passwords outright, so any
// session issued before this point may no longer reflect reality. Bump the
// global epoch so requireLogin() force-logs-out everyone; re-stamp our own
// session immediately after so the admin performing the restore isn't
// booted before they see the result.
$newEpoch = (string)time();
setSetting('session_epoch', $newEpoch);
$_SESSION['session_epoch'] = $newEpoch;

// ── Flash result ───────────────────────────────────────────────
$msg = 'Restore completed — ' . $success . ' statement(s) executed.';
if (!empty($migRan)) {
    $msg .= ' Applied ' . count($migRan) . ' migration(s): ' . implode(', ', $migRan) . '.';
}
if (!empty($migFailed)) {
    setFlash('error', $msg . ' Migration errors: ' . implode(' | ', $migFailed));
} else {
    setFlash('success', $msg);
}

header('Location: ' . BASE_PATH . '/settings/backup');
exit;

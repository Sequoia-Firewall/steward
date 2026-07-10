<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!hash_equals(csrfToken(), $_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — please reload the page and try again.']);
    exit;
}

$schedule = $_POST['backup_schedule'] ?? 'off';
$dir      = trim($_POST['backup_dir']    ?? '/var/backups/steward');
$retain   = max(1, min(365, (int)($_POST['backup_retain'] ?? 14)));
$hour     = max(0, min(23,  (int)($_POST['backup_hour']   ?? 2)));
$dow      = max(0, min(6,   (int)($_POST['backup_dow']    ?? 0)));
$dom      = max(1, min(28,  (int)($_POST['backup_dom']    ?? 1)));

if (!in_array($schedule, ['off', 'daily', 'weekly', 'monthly'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid schedule']);
    exit;
}

if (str_contains($dir, "\0") || !str_starts_with($dir, '/')) {
    echo json_encode(['ok' => false, 'error' => 'Directory must be an absolute path']);
    exit;
}

setSetting('backup_schedule', $schedule);
setSetting('backup_dir',      $dir);
setSetting('backup_retain',   (string)$retain);
setSetting('backup_hour',     (string)$hour);
setSetting('backup_dow',      (string)$dow);
setSetting('backup_dom',      (string)$dom);

// Build cron line
$phpBin = PHP_BINARY ?: '/usr/bin/php';
$script = dirname(__DIR__) . '/scripts/backup.php';
$cronLine = '';

if ($schedule !== 'off') {
    $expr = match($schedule) {
        'daily'   => "0 $hour * * *",
        'weekly'  => "0 $hour * * $dow",
        'monthly' => "0 $hour $dom * *",
    };
    $cronLine = "$expr $phpBin $script";
}

$crontabOk = updateMoneyBackupCron($cronLine);

$dirOk = is_dir($dir) && is_writable($dir);

logActivity('backup_schedule', "Backup schedule set to: $schedule");
echo json_encode([
    'ok'         => true,
    'crontab_ok' => $crontabOk,
    'dir_ok'     => $dirOk,
    'cron_line'  => $cronLine,
    'php_bin'    => $phpBin,
    'script'     => $script,
]);

// -------------------------------------------------------------------------

function updateMoneyBackupCron(string $newLine): bool
{
    exec('crontab -l 2>/dev/null', $existing, $rc);
    $marker = '# steward-backup';

    // Strip any existing money-manager backup block
    $filtered = [];
    $skipNext = false;
    foreach ($existing as $line) {
        if ($line === $marker) { $skipNext = true; continue; }
        if ($skipNext)         { $skipNext = false; continue; }
        $filtered[] = $line;
    }

    if ($newLine !== '') {
        $filtered[] = $marker;
        $filtered[] = $newLine;
    }

    $input = implode("\n", $filtered);
    if ($input !== '' && !str_ends_with($input, "\n")) {
        $input .= "\n";
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('crontab -', $descriptors, $pipes);
    if (!is_resource($proc)) return false;

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc) === 0;
}

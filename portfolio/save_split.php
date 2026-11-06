<?php
// Record or delete a security-level stock split (investment_splits). Share
// counts are unaffected — those come from each account's 'split' transaction.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$db           = getDB();
$action       = $_POST['action'] ?? 'save';
$investmentId = (int)($_POST['investment_id'] ?? 0);

$stmt = $db->prepare('SELECT id, name FROM investments WHERE id = ? AND is_active = 1');
$stmt->execute([$investmentId]);
$inv = $stmt->fetch();
if (!$inv) {
    echo json_encode(['ok' => false, 'error' => 'Investment not found.']);
    exit;
}

if ($action === 'delete') {
    $stmt = $db->prepare('DELETE FROM investment_splits WHERE id = ? AND investment_id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0), $investmentId]);
    if (!$stmt->rowCount()) {
        echo json_encode(['ok' => false, 'error' => 'Split not found.']);
        exit;
    }
    logActivity('investment_split_delete', sprintf('Deleted a recorded split for "%s"', $inv['name']));
    echo json_encode(['ok' => true]);
    exit;
}

$date      = trim($_POST['split_date'] ?? '');
$newShares = (float)($_POST['new_shares'] ?? 0);
$oldShares = (float)($_POST['old_shares'] ?? 0);

if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    echo json_encode(['ok' => false, 'error' => 'Enter a valid ex-date.']);
    exit;
}
if ($newShares <= 0 || $oldShares <= 0 || abs($newShares - $oldShares) < 1e-9) {
    echo json_encode(['ok' => false, 'error' => 'Enter the split as new shares for old shares, e.g. 3 for 1.']);
    exit;
}

$ratio = round($newShares / $oldShares, 8);
$db->prepare(
    'INSERT INTO investment_splits (investment_id, split_date, ratio) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE ratio = VALUES(ratio)'
)->execute([$investmentId, $date, $ratio]);
logActivity('investment_split_save', sprintf('Recorded a %s-for-%s split for "%s" on %s',
    rtrim(rtrim(number_format($newShares, 4, '.', ''), '0'), '.'),
    rtrim(rtrim(number_format($oldShares, 4, '.', ''), '0'), '.'), $inv['name'], $date));

echo json_encode(['ok' => true]);

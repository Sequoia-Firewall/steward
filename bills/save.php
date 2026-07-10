<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
    exit;
}

verifyCsrf();

$id            = (int)($_POST['id']             ?? 0);
$name          = trim($_POST['name']            ?? '');
$type          = $_POST['type']                 ?? 'bill';
$accountId     = (int)($_POST['account_id']     ?? 0);
$toAccountId   = (int)($_POST['to_account_id']  ?? 0) ?: null;
$amount        = (float)($_POST['amount']       ?? 0);
$isEstimated   = isset($_POST['is_estimated'])  ? 1 : 0;
$frequency     = $_POST['frequency']            ?? 'monthly';
$nextDueDate   = $_POST['next_due_date']         ?? '';
$categoryId    = (int)($_POST['category_id']    ?? 0) ?: null;
$subcategoryId = (int)($_POST['subcategory_id'] ?? 0) ?: null;
$notes         = trim($_POST['notes']           ?? '');

// Validate
if (!$name)        { echo json_encode(['ok' => false, 'error' => 'Name is required.']); exit; }
if (!$accountId)   { echo json_encode(['ok' => false, 'error' => 'Account is required.']); exit; }
if ($amount <= 0)  { echo json_encode(['ok' => false, 'error' => 'Amount must be greater than zero.']); exit; }
if (!$nextDueDate) { echo json_encode(['ok' => false, 'error' => 'Next due date is required.']); exit; }

$validTypes = ['bill', 'deposit', 'transfer'];
$validFreq  = ['once', 'weekly', 'biweekly', 'twice_monthly', 'monthly', 'bimonthly', 'quarterly', 'yearly'];
if (!in_array($type, $validTypes, true)) $type = 'bill';
if (!in_array($frequency, $validFreq, true)) $frequency = 'monthly';

if ($type === 'transfer') {
    if (!$toAccountId) { echo json_encode(['ok' => false, 'error' => 'Destination account is required for transfers.']); exit; }
    if ($toAccountId === $accountId) { echo json_encode(['ok' => false, 'error' => 'Source and destination accounts must differ.']); exit; }
    $db = getDB();
    $fromAcct = getAccount($accountId);
    $toAcct   = getAccount($toAccountId);
    if (!$fromAcct || !isCashAccount($fromAcct['type'])) { echo json_encode(['ok' => false, 'error' => 'Transfers can only be made between cash accounts (Checking, Savings, Credit Card).']); exit; }
    if (!$toAcct   || !isCashAccount($toAcct['type']))   { echo json_encode(['ok' => false, 'error' => 'Transfers can only be made between cash accounts (Checking, Savings, Credit Card).']); exit; }
    $categoryId    = getSystemCategoryId('{Cash Transfer}');
    $subcategoryId = null;
}

try {
    $db = getDB();
    if ($id) {
        $stmt = $db->prepare(
            'UPDATE scheduled_bills
             SET name=?, type=?, account_id=?, to_account_id=?, category_id=?, subcategory_id=?,
                 amount=?, is_estimated=?, frequency=?, next_due_date=?, notes=?
             WHERE id=?'
        );
        $stmt->execute([$name, $type, $accountId, $toAccountId, $categoryId, $subcategoryId,
                        $amount, $isEstimated, $frequency, $nextDueDate, $notes ?: null, $id]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO scheduled_bills
             (name, type, account_id, to_account_id, category_id, subcategory_id, amount, is_estimated, frequency, next_due_date, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$name, $type, $accountId, $toAccountId, $categoryId, $subcategoryId,
                        $amount, $isEstimated, $frequency, $nextDueDate, $notes ?: null]);
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}

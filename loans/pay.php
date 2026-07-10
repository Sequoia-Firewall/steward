<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/loan_utils.php';
requireLogin();

if (!canEdit()) {
    setFlash('error', 'Permission denied.');
    header('Location: ' . BASE_PATH . '/loans/index');
    exit;
}

verifyCsrf();

$accountId     = (int)($_POST['account_id']     ?? 0);
$paymentDate   = trim($_POST['payment_date']    ?? date('Y-m-d'));
$paymentAmount = (float)($_POST['payment_amount'] ?? 0);
$redirect      = $_POST['redirect'] ?? 'schedule';

$account = getAccount($accountId);
if (!$account || $account['type'] !== 'Loan') {
    setFlash('error', 'Loan account not found.');
    header('Location: ' . BASE_PATH . '/loans/index');
    exit;
}

$loan = getLoanDetails($accountId);
if (!$loan) {
    setFlash('error', 'Loan details not found.');
    header('Location: ' . BASE_PATH . '/loans/index');
    exit;
}

if ($paymentAmount <= 0) {
    setFlash('error', 'Payment amount must be greater than zero.');
    header('Location: ' . BASE_PATH . ($redirect === 'index' ? '/loans/index' : '/loans/schedule?id=' . $accountId));
    exit;
}

$db   = getDB();
$stmt = $db->prepare(
    'SELECT a.opening_balance + COALESCE(SUM(t.amount), 0) AS balance,
            (SELECT COUNT(*) FROM transactions t2 WHERE t2.account_id = a.id) AS payments_made
     FROM accounts a
     LEFT JOIN transactions t ON t.account_id = a.id
     WHERE a.id = ?'
);
$stmt->execute([$accountId]);
$row          = $stmt->fetch();
$outstanding  = -(float)$row['balance'];   // positive = still owed
$paymentsMade = (int)$row['payments_made'];
$paymentNum   = $paymentsMade + 1;

if ($outstanding <= 0.005) {
    setFlash('info', 'This loan is already fully paid off.');
    header('Location: ' . BASE_PATH . ($redirect === 'index' ? '/loans/index' : '/loans/schedule?id=' . $accountId));
    exit;
}

$monthlyRate = (float)$loan['annual_rate'] / 100 / 12;
$interest    = round($outstanding * $monthlyRate, 2);
$principal   = round($paymentAmount - $interest, 2);

if ($principal > $outstanding) {
    $principal = round($outstanding, 2);
}

if ($principal < 0) {
    setFlash('error', sprintf(
        'Payment amount is less than the interest due (%s). Minimum payment: %s.',
        formatMoney($interest),
        formatMoney($interest + 0.01)
    ));
    header('Location: ' . BASE_PATH . ($redirect === 'index' ? '/loans/index' : '/loans/schedule?id=' . $accountId));
    exit;
}

$memo = sprintf('Payment #%d — Principal: %s, Interest: %s',
    $paymentNum,
    formatMoney($principal),
    formatMoney($interest)
);

try {
    $db->prepare(
        "INSERT INTO transactions (account_id, type, transaction_date, payee, memo, amount, cleared_status)
         VALUES (?, 'deposit', ?, ?, ?, ?, '')"
    )->execute([$accountId, $paymentDate, $account['name'], $memo, $principal]);

    setFlash('success', sprintf(
        'Payment #%d recorded — Principal: %s, Interest: %s.',
        $paymentNum, formatMoney($principal), formatMoney($interest)
    ));
} catch (Exception $e) {
    setFlash('error', 'Failed to record payment. Please try again.');
}

$dest = $redirect === 'index'
    ? BASE_PATH . '/loans/index'
    : BASE_PATH . '/loans/schedule?id=' . $accountId;
header('Location: ' . $dest);
exit;

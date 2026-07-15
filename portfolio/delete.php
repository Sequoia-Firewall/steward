<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isAdmin()) {
    setFlash('error', 'Only administrators can delete investments.');
    header('Location: ' . BASE_PATH . '/portfolio/index');
    exit;
}

verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $db = getDB();

    $nameStmt = $db->prepare('SELECT name FROM investments WHERE id = ?');
    $nameStmt->execute([$id]);
    $name = $nameStmt->fetchColumn();

    if ($name !== false) {
        $heldStmt = $db->prepare(
            'SELECT COALESCE(SUM(CASE
                WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                WHEN it.activity IN (\'sell\',\'remove\')                                        THEN -it.quantity
                ELSE 0
             END), 0)
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             JOIN accounts a     ON a.id = t.account_id
             WHERE it.investment_id = ?
               AND a.is_investment_cash = 0'
        );
        $heldStmt->execute([$id]);
        $heldQty = (float)$heldStmt->fetchColumn();

        $db->prepare('UPDATE investments SET is_active = 0 WHERE id = ?')->execute([$id]);

        if ($heldQty > 0.000001) {
            $qtyDisplay = rtrim(rtrim(number_format($heldQty, 6), '0'), '.');
            logActivity('investment_deactivate', sprintf('Deactivated "%s" (%s shares still held)', $name, $qtyDisplay));
            setFlash('warning', '"' . $name . '" deactivated while ' . $qtyDisplay . ' shares are still held — Net Worth keeps counting them, but the Portfolio and holdings reports will not.');
        } else {
            logActivity('investment_deactivate', sprintf('Deactivated "%s"', $name));
            setFlash('success', '"' . $name . '" deactivated. Its transactions and price history are kept — recording new activity for it reactivates it automatically.');
        }
    }
}

header('Location: ' . BASE_PATH . '/portfolio/index');
exit;

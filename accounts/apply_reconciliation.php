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

$accountId    = (int)($_POST['account_id']   ?? 0);
$rawAdj       = $_POST['adjustments']        ?? '[]';
$rawPriceUpd  = $_POST['price_updates']      ?? '[]';
$adjustments  = json_decode($rawAdj,      true);
$priceUpdates = json_decode($rawPriceUpd, true);

$statementDate = trim($_POST['statement_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate)) {
    $statementDate = date('Y-m-d');
}

if (!$accountId || !is_array($adjustments) || !is_array($priceUpdates)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$account = getAccount($accountId);
if (!$account || $account['type'] !== 'Investment' || $account['is_investment_cash']) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account.']);
    exit;
}

try {
    $db        = getDB();
    $applied   = 0;
    $newTxnIds = [];

    foreach ($adjustments as $adj) {
        $investmentId = (int)($adj['investment_id'] ?? 0);
        $newQty       = (float)($adj['new_qty']      ?? -1);

        if (!$investmentId || $newQty < 0) continue;

        // Get current net qty and investment name
        $qStmt = $db->prepare(
            'SELECT COALESCE(SUM(CASE
                 WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                 WHEN it.activity IN (\'sell\',\'remove\')                                         THEN -it.quantity
                 ELSE 0
             END), 0) AS net_qty,
             i.name AS inv_name
             FROM investments i
             JOIN investment_transactions it ON it.investment_id = i.id
             JOIN transactions t ON t.id = it.transaction_id
             WHERE i.id = ? AND i.is_active = 1 AND t.account_id = ?
             GROUP BY i.id, i.name'
        );
        $qStmt->execute([$investmentId, $accountId]);
        $row = $qStmt->fetch();
        if (!$row) continue;

        $currentQty = (float)$row['net_qty'];
        $diff       = $newQty - $currentQty;
        if (abs($diff) < 0.000001) continue;

        $activity = $diff > 0 ? 'add' : 'remove';
        $qty      = abs($diff);
        $sign     = $diff > 0 ? '+' : '-';
        $memo     = 'Number of shares adjusted by user (' . $sign
                    . rtrim(rtrim(number_format($qty, 6), '0'), '.') . ')';

        $db->beginTransaction();

        $db->prepare(
            'INSERT INTO transactions
             (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
             VALUES (?, CURDATE(), ?, \'investment\', 0, \'\', ?, ?)'
        )->execute([$accountId, $row['inv_name'], $memo, currentUserId()]);
        $txnId = (int)$db->lastInsertId();

        $db->prepare(
            'INSERT INTO investment_transactions
             (transaction_id, investment_id, activity, quantity, price, commission)
             VALUES (?, ?, ?, ?, 0, 0)'
        )->execute([$txnId, $investmentId, $activity, $qty]);

        $db->commit();
        $newTxnIds[] = $txnId;
        $applied++;
    }

    // ── Price updates ──────────────────────────────────────────────────────
    $pricesApplied = 0;
    $upsertPrice   = $db->prepare(
        'INSERT INTO investment_prices (investment_id, price_date, close_price, source)
         VALUES (?, ?, ?, \'statement\')
         ON DUPLICATE KEY UPDATE close_price = VALUES(close_price), source = \'statement\', updated_at = NOW()'
    );
    foreach ($priceUpdates as $pu) {
        $investmentId = (int)($pu['investment_id'] ?? 0);
        $price        = (float)($pu['price']        ?? 0);
        $priceDate    = trim($pu['price_date']       ?? '');

        if (!$investmentId || $price <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $priceDate)) continue;

        // Verify the investment belongs to this account
        $chk = $db->prepare(
            'SELECT 1 FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             WHERE it.investment_id = ? AND t.account_id = ? LIMIT 1'
        );
        $chk->execute([$investmentId, $accountId]);
        if (!$chk->fetch()) continue;

        $upsertPrice->execute([$investmentId, $priceDate, $price]);
        $pricesApplied++;
    }

    // ── Mark investment transactions cleared through the statement date ────
    // Unlike bank imports (auto-stamped 'cleared' with no verification), a
    // clean holdings reconciliation actually checks net share quantity
    // against the statement, so it's a real verification signal — safe to
    // exclude these from the duplicate-transactions maintenance check.
    $clearSql    = "UPDATE transactions
                     SET cleared_status = 'cleared', updated_at = NOW()
                     WHERE account_id = ? AND cleared_status = '' AND (transaction_date <= ?";
    $clearParams = [$accountId, $statementDate];
    if (!empty($newTxnIds)) {
        $ph = implode(',', array_fill(0, count($newTxnIds), '?'));
        $clearSql     .= " OR id IN ($ph)";
        $clearParams   = array_merge($clearParams, $newTxnIds);
    }
    $clearSql .= ')';
    $db->prepare($clearSql)->execute($clearParams);

    // ── Stamp last-reconciled date/balance ─────────────────────────────────
    // Balance = current total market value (qty * latest price) across active
    // holdings, reflecting any adjustments/price updates just applied above.
    $qtyStmt = $db->prepare(
        'SELECT i.id,
            COALESCE(SUM(CASE
                WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                WHEN it.activity IN (\'sell\',\'remove\')                                         THEN -it.quantity
                ELSE 0
            END), 0) AS net_qty
         FROM investments i
         JOIN investment_transactions it ON it.investment_id = i.id
         JOIN transactions t ON t.id = it.transaction_id
         WHERE i.is_active = 1 AND t.account_id = ?
         GROUP BY i.id
         HAVING net_qty > 0.000001'
    );
    $qtyStmt->execute([$accountId]);
    $latestPrices = getLatestInvestmentPrices();

    $totalValue = 0.0;
    foreach ($qtyStmt->fetchAll() as $h) {
        $price = $latestPrices[(int)$h['id']]['price'] ?? null;
        if ($price !== null) $totalValue += $price * (float)$h['net_qty'];
    }

    $db->prepare(
        'UPDATE accounts SET last_reconciled_date = ?, last_reconciled_balance = ? WHERE id = ?'
    )->execute([$statementDate, $totalValue, $accountId]);

    echo json_encode([
        'ok'                      => true,
        'applied'                 => $applied,
        'prices_applied'          => $pricesApplied,
        'last_reconciled_date'    => $statementDate,
        'last_reconciled_balance' => $totalValue,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}

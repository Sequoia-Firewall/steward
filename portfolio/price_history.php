<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$investmentId = (int)($_GET['investment_id'] ?? 0);
if (!$investmentId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid investment']);
    exit;
}

$realOnly = ($_GET['real_only'] ?? '') === '1';

$db = getDB();

$invStmt = $db->prepare('SELECT id, name, symbol, type FROM investments WHERE id = ? AND is_active = 1');
$invStmt->execute([$investmentId]);
$investment = $invStmt->fetch();
if (!$investment) {
    echo json_encode(['ok' => false, 'error' => 'Investment not found']);
    exit;
}

// Charts get split-adjusted prices; the manual price editor and CSV download
// (real_only) get the stored rows as they are
$priceRows = getInvestmentPriceHistory($investmentId, $realOnly, !$realOnly);
$splits    = array_map(fn($sp) => ['date' => $sp['date'], 'ratio' => $sp['ratio']],
                       getInvestmentSplits([$investmentId])[$investmentId] ?? []);

// Dividend dates (cash or reinvested, any account) for the chart's D markers
$divStmt = $db->prepare(
    "SELECT DISTINCT t.transaction_date
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     WHERE it.investment_id = ? AND it.activity IN ('div', 'reinvest_div', 'reinvest_cap')
     ORDER BY t.transaction_date"
);
$divStmt->execute([$investmentId]);
$dividends = $divStmt->fetchAll(PDO::FETCH_COLUMN);

$holdingStmt = $db->prepare(
    'SELECT
       COALESCE(SUM(CASE
         WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
         WHEN it.activity IN (\'sell\',\'remove\')                                        THEN -it.quantity
         ELSE 0
       END), 0) AS net_quantity,
       MAX(CASE WHEN it.activity = \'buy\' THEN t.transaction_date END) AS last_purchase
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     WHERE it.investment_id = ?'
);
$holdingStmt->execute([$investmentId]);
$holding = $holdingStmt->fetch();

$acctStmt = $db->prepare(
    'SELECT a.id, a.name,
       COALESCE(SUM(CASE
         WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
         WHEN it.activity IN (\'sell\',\'remove\')                                        THEN -it.quantity
         ELSE 0
       END), 0) AS net_quantity
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     JOIN accounts a     ON a.id = t.account_id
     WHERE it.investment_id = ? AND a.is_investment_cash = 0
     GROUP BY a.id, a.name
     HAVING net_quantity > 0.000001
     ORDER BY a.name'
);
$acctStmt->execute([$investmentId]);
$acctHoldings = $acctStmt->fetchAll();

echo json_encode([
    'ok'         => true,
    'investment' => [
        'id'            => (int)$investment['id'],
        'name'          => $investment['name'],
        'symbol'        => $investment['symbol'],
        'type'          => $investment['type'],
        'shares_owned'  => (float)$holding['net_quantity'],
        'last_purchase' => $holding['last_purchase'],
        'accounts'      => array_map(fn($a) => [
            'id'       => (int)$a['id'],
            'name'     => $a['name'],
            'quantity' => (float)$a['net_quantity'],
        ], $acctHoldings),
    ],
    'prices'    => $priceRows,
    'splits'    => $splits,
    'dividends' => $dividends,
]);

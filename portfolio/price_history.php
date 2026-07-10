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

$db = getDB();

$invStmt = $db->prepare('SELECT id, name, symbol, type FROM investments WHERE id = ? AND is_active = 1');
$invStmt->execute([$investmentId]);
$investment = $invStmt->fetch();
if (!$investment) {
    echo json_encode(['ok' => false, 'error' => 'Investment not found']);
    exit;
}

$priceStmt = $db->prepare(
    'SELECT price_date, open_price, high_price, low_price, close_price, volume, vwap, source
     FROM investment_prices
     WHERE investment_id = ?
     ORDER BY price_date ASC'
);
$priceStmt->execute([$investmentId]);
$rows = $priceStmt->fetchAll();

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
    'prices' => array_map(fn($p) => [
        'date'   => $p['price_date'],
        'open'   => $p['open_price']  !== null ? (float)$p['open_price']  : null,
        'high'   => $p['high_price']  !== null ? (float)$p['high_price']  : null,
        'low'    => $p['low_price']   !== null ? (float)$p['low_price']   : null,
        'close'  => (float)$p['close_price'],
        'volume' => $p['volume']      !== null ? (int)$p['volume']        : null,
        'vwap'   => $p['vwap']        !== null ? (float)$p['vwap']        : null,
        'source' => $p['source'],
    ], $rows),
]);

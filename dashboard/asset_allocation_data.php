<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();

// ── Account filter ─────────────────────────────────────────────
$allAccounts = $db->query(
    "SELECT id FROM accounts WHERE type = 'Investment' AND is_investment_cash = 0 AND is_active = 1"
)->fetchAll(PDO::FETCH_COLUMN);

$allAcctIds = array_map('intval', $allAccounts);
$acctParam  = trim($_GET['accts'] ?? '');

if ($acctParam === '' || $acctParam === 'all') {
    $acctWhere  = '';
    $acctParams = [];
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allAcctIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allAcctIds)) {
        $acctWhere  = '';
        $acctParams = [];
    } else {
        $ph         = implode(',', array_fill(0, count($parsed), '?'));
        $acctWhere  = "AND a.id IN ($ph)";
        $acctParams = $parsed;
    }
}

// ── Holdings query ─────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT
        i.id AS inv_id, i.name AS inv_name, i.symbol, i.type AS inv_type,
        a.id AS acct_id, a.name AS acct_name,
        COALESCE(SUM(CASE
            WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
            WHEN it.activity IN ('sell','remove')                                   THEN -it.quantity
            ELSE 0
        END), 0) AS net_qty,
        SUM(CASE WHEN it.activity IN ('buy','add','reinvest_div','reinvest_cap')
            THEN it.quantity * it.price + it.commission ELSE 0 END) AS buy_cost,
        SUM(CASE WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap')
            THEN it.quantity ELSE 0 END) AS buy_qty
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN investments   i ON i.id = it.investment_id
     JOIN accounts      a ON a.id = t.account_id
     WHERE a.is_investment_cash = 0 AND i.is_active = 1
       $acctWhere
     GROUP BY i.id, i.name, i.symbol, i.type, a.id, a.name
     HAVING net_qty > 0.000001"
);
$stmt->execute($acctParams);
$rawRows = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();

// ── Aggregate ─────────────────────────────────────────────────
$byType    = [];
$byAccount = [];
$totalMV   = 0.0;

foreach ($rawRows as $r) {
    $invId   = (int)$r['inv_id'];
    $qty     = (float)$r['net_qty'];
    $buyQty  = (float)$r['buy_qty'];
    $buyCost = (float)$r['buy_cost'];
    $avgCost = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $price   = $latestPrices[$invId]['price'] ?? null;
    $mv      = $price !== null ? $price * $qty : $avgCost * $qty;

    $type    = $r['inv_type']  ?: 'Other';
    $account = $r['acct_name'] ?: 'Unknown';

    if (!isset($byType[$type]))       $byType[$type]       = 0.0;
    if (!isset($byAccount[$account])) $byAccount[$account] = 0.0;
    $byType[$type]       += $mv;
    $byAccount[$account] += $mv;
    $totalMV             += $mv;
}

arsort($byType);
arsort($byAccount);

$palette = ['#1a5fb4','#e66000','#1a7a3c','#c0392b','#8e44ad','#16a085','#d4ac0d','#5d6d7e','#ca6f1e','#117a65'];

$typeLabels  = array_keys($byType);
$typeValues  = array_values(array_map(fn($v) => round($v, 2), $byType));
$typeColors  = array_values(array_slice($palette, 0, count($byType)));

$acctLabels  = array_keys($byAccount);
$acctValues  = array_values(array_map(fn($v) => round($v, 2), $byAccount));
$acctColors  = array_values(array_slice($palette, 0, count($byAccount)));

echo json_encode([
    'ok'         => true,
    'by_type'    => ['labels' => $typeLabels, 'values' => $typeValues, 'colors' => $typeColors],
    'by_account' => ['labels' => $acctLabels, 'values' => $acctValues, 'colors' => $acctColors],
    'total_mv'   => round($totalMV, 2),
]);

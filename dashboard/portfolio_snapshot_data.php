<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();

$knownTypes = ['Index','Stock','Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Cryptocurrency','Other'];

// ── Account filter ─────────────────────────────────────────────
$allAccounts = $db->query(
    "SELECT id FROM accounts WHERE type = 'Investment' AND is_investment_cash = 0 AND is_active = 1"
)->fetchAll(PDO::FETCH_COLUMN);

$allAcctIds = array_map('intval', $allAccounts);
$acctParam  = trim($_GET['accts'] ?? '');

if ($acctParam === '' || $acctParam === 'all') {
    $selectedAcctIds = $allAcctIds;
    $acctWhere       = '';
    $acctParams      = [];
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allAcctIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allAcctIds)) {
        $selectedAcctIds = $allAcctIds;
        $acctWhere       = '';
        $acctParams      = [];
    } else {
        $selectedAcctIds = $parsed;
        $ph              = implode(',', array_fill(0, count($selectedAcctIds), '?'));
        $acctWhere       = "AND a.id IN ($ph)";
        $acctParams      = $selectedAcctIds;
    }
}

// ── Type exclusion ─────────────────────────────────────────────
$excludeParam = trim($_GET['exclude_types'] ?? '');
$excludeTypes = $excludeParam !== '' ? array_values(array_filter(
    array_map('trim', explode(',', $excludeParam)),
    fn($t) => in_array($t, $knownTypes, true)
)) : [];

$typeWhere  = '';
$typeParams = [];
if (!empty($excludeTypes)) {
    $tph       = implode(',', array_fill(0, count($excludeTypes), '?'));
    $typeWhere = "AND i.type NOT IN ($tph)";
    $typeParams = $excludeTypes;
}

// ── Holdings query ─────────────────────────────────────────────
$queryParams = array_merge($acctParams, $typeParams);
$stmt = $db->prepare(
    "SELECT
        i.id            AS inv_id,
        i.name          AS inv_name,
        i.symbol,
        i.type          AS inv_type,
        COALESCE(SUM(CASE
            WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
            WHEN it.activity IN ('sell','remove')                                   THEN -it.quantity
            ELSE 0
        END), 0) AS net_qty
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN investments   i ON i.id = it.investment_id
     JOIN accounts      a ON a.id = t.account_id
     WHERE a.is_investment_cash = 0 AND i.is_active = 1
       $acctWhere
       $typeWhere
     GROUP BY i.id, i.name, i.symbol, i.type
     HAVING net_qty > 0.000001"
);
$stmt->execute($queryParams);
$rawRows = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();

// ── Build holdings ─────────────────────────────────────────────
$holdings = [];
$totalMV  = 0.0;

foreach ($rawRows as $r) {
    $invId = (int)$r['inv_id'];
    $qty   = (float)$r['net_qty'];
    $price = $latestPrices[$invId]['price'] ?? null;
    if ($price === null) continue;
    $mv = $price * $qty;
    $holdings[] = [
        'name'        => $r['inv_name'],
        'symbol'      => $r['symbol'],
        'inv_type'    => $r['inv_type'],
        'qty'         => $qty,
        'marketValue' => $mv,
    ];
    $totalMV += $mv;
}

usort($holdings, fn($a, $b) => $b['marketValue'] <=> $a['marketValue']);

$palette    = ['#1a5fb4','#e66000','#1a7a3c','#c0392b','#8e44ad','#16a085','#d4ac0d','#5d6d7e','#ca6f1e','#117a65'];
$otherColor = '#aab0bc';

$top10 = array_slice($holdings, 0, 10);
$rest  = array_slice($holdings, 10);
$otherMV = array_sum(array_column($rest, 'marketValue'));

$labels = [];
$values = [];
$colors = [];

foreach ($top10 as $i => $h) {
    $labels[] = $h['symbol'] ?: $h['name'];
    $values[] = round($h['marketValue'], 2);
    $colors[] = $palette[$i] ?? $otherColor;
}
if ($otherMV > 0.001) {
    $labels[] = 'Other';
    $values[] = round($otherMV, 2);
    $colors[] = $otherColor;
}

$tableRows = [];
foreach ($holdings as $rank => $h) {
    $tableRows[] = [
        'rank'        => $rank + 1,
        'name'        => $h['name'],
        'symbol'      => $h['symbol'],
        'inv_type'    => $h['inv_type'],
        'qty'         => round($h['qty'], 6),
        'marketValue' => round($h['marketValue'], 2),
        'pct'         => $totalMV > 0 ? round($h['marketValue'] / $totalMV * 100, 1) : 0,
    ];
}

echo json_encode(['ok' => true, 'labels' => $labels, 'values' => $values, 'colors' => $colors, 'total_mv' => round($totalMV, 2), 'rows' => $tableRows]);

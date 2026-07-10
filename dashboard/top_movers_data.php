<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$period = $_GET['period'] ?? '';

switch ($period) {
    case '7days': $startDate = date('Y-m-d', strtotime('-7 days')); break;
    case 'month': $startDate = date('Y-m-01');                       break;
    case 'year':  $startDate = date('Y-01-01');                      break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid period']); exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT i.id, i.name, i.symbol, i.type,
                cur.close_price              AS current_price,
                hist.close_price             AS start_price,
                COALESCE(h.qty, 0)           AS qty
         FROM investments i
         INNER JOIN (
             SELECT ip.investment_id, ip.close_price
             FROM investment_prices ip
             INNER JOIN (
                 SELECT investment_id, MAX(price_date) AS mx
                 FROM investment_prices
                 GROUP BY investment_id
             ) m ON m.investment_id = ip.investment_id AND m.mx = ip.price_date
         ) cur ON cur.investment_id = i.id
         LEFT JOIN (
             SELECT ip.investment_id, ip.close_price
             FROM investment_prices ip
             INNER JOIN (
                 SELECT investment_id, MAX(price_date) AS mx
                 FROM investment_prices
                 WHERE price_date <= ?
                 GROUP BY investment_id
             ) m ON m.investment_id = ip.investment_id AND m.mx = ip.price_date
         ) hist ON hist.investment_id = i.id
         LEFT JOIN (
             SELECT it.investment_id,
                    SUM(CASE
                        WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
                        WHEN it.activity IN ('sell','remove')                                   THEN -it.quantity
                        ELSE 0
                    END) AS qty
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             JOIN accounts a     ON a.id = t.account_id
             WHERE a.is_investment_cash = 0
             GROUP BY it.investment_id
         ) h ON h.investment_id = i.id
         WHERE i.is_active = 1 AND i.type != 'Index'
         ORDER BY i.name"
    );
    $stmt->execute([$startDate]);
    $rawRows = $stmt->fetchAll();
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error']); exit;
}

$rows = [];
foreach ($rawRows as $r) {
    $cur    = (float)$r['current_price'];
    $hist   = $r['start_price'] !== null ? (float)$r['start_price'] : null;
    $qty    = (float)$r['qty'];
    $chg    = $hist !== null ? round($cur - $hist, 4)          : null;
    $chgPct = ($chg !== null && $hist > 0) ? round($chg / $hist * 100, 2) : null;
    $mktVal = round($qty * $cur, 2);
    $valChg = $chg !== null ? round($qty * $chg, 2) : null;
    $rows[] = [
        'id'      => (int)$r['id'],
        'name'    => $r['name'],
        'symbol'  => $r['symbol'],
        'type'    => $r['type'],
        'qty'     => $qty,
        'price'   => $cur,
        'chg'     => $chg,
        'chg_pct' => $chgPct,
        'mkt_val' => $mktVal,
        'val_chg' => $valChg,
    ];
}

echo json_encode(['ok' => true, 'rows' => $rows]);

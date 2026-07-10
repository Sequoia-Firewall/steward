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

$accountId = (int)($_POST['account_id'] ?? 0);
$account   = getAccount($accountId);

if (!$account || $account['type'] !== 'Investment' || $account['is_investment_cash']) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account.']);
    exit;
}

if (empty($_FILES['csv_file']['tmp_name'])) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded.']);
    exit;
}

function parseBrokerAmount(string $s): ?float {
    $s = trim($s);
    if ($s === '' || $s === '--' || $s === 'N/A') return null;
    $neg = str_starts_with($s, '-');
    $s   = ltrim($s, '+-');
    $s   = str_replace(['$', ','], '', $s);
    return is_numeric($s) ? ($neg ? -(float)$s : (float)$s) : null;
}

// Normalize a ticker symbol to alphanumeric-only for key matching.
// BRK-B → brkb, BRK.B → brkb, BRKB → brkb — all resolve to the same key.
function symKey(string $sym): string {
    return strtolower(preg_replace('/[^A-Z0-9]/i', '', $sym));
}

// ── Load our current holdings ─────────────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare(
    'SELECT
        i.id, i.name, i.symbol,
        COALESCE(SUM(CASE
            WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
            WHEN it.activity IN (\'sell\',\'remove\')                                         THEN -it.quantity
            ELSE 0
        END), 0) AS net_qty,
        SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'reinvest_div\',\'reinvest_cap\')
            THEN it.quantity * it.price + it.commission ELSE 0 END) AS buy_cost,
        SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\')
            THEN it.quantity ELSE 0 END) AS buy_qty
     FROM investments i
     JOIN investment_transactions it ON it.investment_id = i.id
     JOIN transactions t ON t.id = it.transaction_id
     WHERE i.is_active = 1 AND t.account_id = ?
     GROUP BY i.id, i.name, i.symbol
     HAVING net_qty > 0.000001'
);
$stmt->execute([$accountId]);
$ourHoldings = $stmt->fetchAll();

// Index by normalized symbol key AND by lowercase name for fallback matching.
$ourByKey  = [];
$ourByName = [];
foreach ($ourHoldings as $h) {
    $k = symKey($h['symbol'] ?? '');
    if ($k !== '') $ourByKey[$k] = $h;
    $nk = strtolower(trim($h['name'] ?? ''));
    if ($nk !== '') $ourByName[$nk] = $h;
}

$latestPrices = getLatestInvestmentPrices();
$results      = [];
$seenKeys     = [];  // normalized symbol keys seen in CSV
$seenNameKeys = [];  // lowercase name keys seen in CSV

// ── CSV format detection ──────────────────────────────────────────────────
// Holdings-style CSVs (Merrill Edge, Schwab, etc.) have a header row containing
// both a quantity-type keyword and an identifier-type keyword.
// Fidelity-style CSVs have an account number in column 0 of each data row.

$content  = file_get_contents($_FILES['csv_file']['tmp_name']);
$allLines = preg_split('/\r?\n|\r/', $content);

$qtyWords  = ['quantity', 'shares', 'qty', 'units'];
$idWords   = ['symbol', 'ticker', 'cusip', 'description', 'security name', 'security'];
$headerIdx = -1;
foreach ($allLines as $i => $line) {
    if (trim($line) === '') continue;
    $cells = array_map(fn($c) => strtolower(trim($c)), str_getcsv($line));
    if (!empty(array_intersect($cells, $qtyWords)) && !empty(array_intersect($cells, $idWords))) {
        $headerIdx = $i;
        break;
    }
}

// ── Account number match check ────────────────────────────────────────────
$ourAccountNumber = trim($account['account_number'] ?? '');
$csvAccountNumber = null;
$accountMatch     = false;

if ($headerIdx >= 0) {
    // Holdings-style: look for account number in the preamble
    for ($i = 0; $i < $headerIdx; $i++) {
        // "Selected account(s):CMA-Edge XXX-00000" → capture trailing token
        if (preg_match('/selected\s+account[^:]*:\s*(.+)/i', $allLines[$i], $m)) {
            $csvAccountNumber = trim($m[1]);
            break;
        }
    }
} else {
    // Fidelity-style: account number comes from first non-blank data row, column 0
    // (determined below during parsing)
}

if ($csvAccountNumber !== null && $ourAccountNumber !== '') {
    $accountMatch = stripos($csvAccountNumber, $ourAccountNumber) !== false
                 || stripos($ourAccountNumber, $csvAccountNumber) !== false;
}

// ── Parse CSV rows ────────────────────────────────────────────────────────

if ($headerIdx >= 0) {
    // ── Holdings-style CSV ────────────────────────────────────────────────
    $headers  = array_map('trim', str_getcsv($allLines[$headerIdx]));
    $colIndex = array_flip($headers);

    // Case-insensitive column finder
    $findCol = function(array $candidates) use ($headers, $colIndex): int {
        foreach ($candidates as $name) {
            if (isset($colIndex[$name])) return $colIndex[$name];
            foreach ($headers as $i => $h) {
                if (strcasecmp(trim($h), $name) === 0) return $i;
            }
        }
        return -1;
    };

    $symCol   = $findCol(['Symbol', 'Ticker', 'CUSIP']);
    $descCol  = $findCol(['Description', 'Security Name', 'Name']);
    $qtyCol   = $findCol(['Quantity', 'Shares', 'Qty', 'Units']);
    $priceCol = $findCol(['Price', 'Last Price', 'Unit Price']);
    $valueCol = $findCol(['Value', 'Market Value', 'Mkt Value']);

    $nCols = count($headers);

    for ($i = $headerIdx + 1; $i < count($allLines); $i++) {
        $line = $allLines[$i];
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        while (count($row) < $nCols) $row[] = '';
        $g = fn($idx) => $idx >= 0 ? trim($row[$idx] ?? '') : '';

        $rawSym  = $g($symCol);
        $secName = $g($descCol);
        if ($secName === '') continue;

        // Skip cash / totals / pending rows
        if (preg_match('/^(cash\s+balance|total|pending\s+activity|balances?)$/i', trim($rawSym))) continue;

        $qtyStr = $g($qtyCol);
        $qty    = (float)str_replace([',', '$'], '', $qtyStr);
        if ($qty <= 0) continue;

        // Clean symbol (keep dots for CUSIP-style); strip if it's a category label (>9 chars)
        $symbol = strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $rawSym));
        if (strlen($symbol) > 9) $symbol = '';

        // Derive price from Value/Qty if available (handles bond face-value pricing)
        $priceStr = $g($priceCol);
        $valStr   = $g($valueCol);
        $price    = (float)str_replace([',', '$'], '', $priceStr);
        $mktVal   = (float)str_replace([',', '$'], '', $valStr);
        if ($mktVal > 0 && $qty > 0) $price = $mktVal / $qty;

        $sk  = symKey($symbol);
        $nk  = strtolower($secName);
        $key = $sk !== '' ? $sk : $nk;

        $seenKeys[]     = $key;
        $seenNameKeys[] = $nk;

        // Match to our holdings: symbol key first, then name fallback
        $h = ($sk !== '' && isset($ourByKey[$sk]))  ? $ourByKey[$sk]
           : (isset($ourByName[$nk])                ? $ourByName[$nk]
           : null);

        if ($h !== null) {
            $invId      = (int)$h['id'];
            $ourQty     = (float)$h['net_qty'];
            $buyQty     = (float)$h['buy_qty'];
            $buyCost    = (float)$h['buy_cost'];
            $ourAvgCost = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
            $ourPrice   = $latestPrices[$invId]['price'] ?? null;

            $qtyDiff      = $qty - $ourQty;
            $priceDiff    = ($price > 0 && $ourPrice !== null) ? $price - $ourPrice : null;
            $hasQtyDiff   = abs($qtyDiff) >= 0.001;
            $hasPriceDiff = $priceDiff !== null && abs($priceDiff) >= 0.01;

            $results[] = [
                'investment_id'  => $invId,
                'symbol'         => $h['symbol'] ?: $symbol,
                'name'           => $h['name'],
                'description'    => $secName,
                'status'         => ($hasQtyDiff || $hasPriceDiff) ? 'diff' : 'match',
                'has_qty_diff'   => $hasQtyDiff,
                'has_cost_diff'  => false,
                'has_price_diff' => $hasPriceDiff,
                'stmt_qty'       => $qty,
                'our_qty'        => $ourQty,
                'qty_diff'       => $qtyDiff,
                'stmt_avg_cost'  => null,
                'our_avg_cost'   => $ourAvgCost,
                'cost_diff'      => null,
                'stmt_price'     => $price > 0 ? $price : null,
                'our_price'      => $ourPrice,
                'price_diff'     => $priceDiff,
            ];
        } else {
            $results[] = [
                'investment_id'  => null,
                'symbol'         => $symbol,
                'name'           => null,
                'description'    => $secName,
                'status'         => 'not_in_register',
                'has_qty_diff'   => false,
                'has_cost_diff'  => false,
                'has_price_diff' => false,
                'stmt_qty'       => $qty,
                'our_qty'        => null,
                'qty_diff'       => null,
                'stmt_avg_cost'  => null,
                'our_avg_cost'   => null,
                'cost_diff'      => null,
                'stmt_price'     => $price > 0 ? $price : null,
                'our_price'      => null,
                'price_diff'     => null,
            ];
        }
    }

} else {
    // ── Fidelity-style CSV (account number in column 0 per row) ──────────
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    fgetcsv($handle); // skip header row

    $csvRows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 4) continue;
        $acctNum = trim($row[0]);
        if ($acctNum === '') continue;
        if ($csvAccountNumber === null) {
            $csvAccountNumber = $acctNum;
            if ($ourAccountNumber !== '') {
                $accountMatch = stripos($csvAccountNumber, $ourAccountNumber) !== false
                             || stripos($ourAccountNumber, $csvAccountNumber) !== false;
            }
        }
        if ($acctNum !== $csvAccountNumber) continue;
        $csvRows[] = $row;
    }
    fclose($handle);

    if (empty($csvRows)) {
        echo json_encode(['ok' => false, 'error' => 'No valid data rows found in the CSV.']);
        exit;
    }

    foreach ($csvRows as $row) {
        while (count($row) < 16) $row[] = '';

        $rawSymbol = trim($row[2]);
        $symbol    = strtoupper(rtrim($rawSymbol, '*'));
        $qtyRaw    = trim($row[4]);

        if ($symbol === '') continue;

        $sk  = symKey($symbol);
        $nk  = strtolower(trim($row[3]));

        $seenKeys[]     = $sk;
        $seenNameKeys[] = $nk;

        if ($qtyRaw === '') {
            $results[] = [
                'investment_id'  => null,
                'symbol'         => $symbol,
                'name'           => null,
                'description'    => trim($row[3]),
                'status'         => 'no_qty',
                'stmt_qty'       => null,
                'our_qty'        => null,
                'qty_diff'       => null,
                'has_qty_diff'   => false,
                'stmt_avg_cost'  => null,
                'our_avg_cost'   => null,
                'cost_diff'      => null,
                'has_cost_diff'  => false,
                'stmt_price'     => parseBrokerAmount($row[5]),
                'our_price'      => null,
                'price_diff'     => null,
                'has_price_diff' => false,
            ];
            continue;
        }

        $stmtQty     = (float)$qtyRaw;
        $stmtPrice   = parseBrokerAmount($row[5]);
        $stmtAvgCost = parseBrokerAmount($row[14]);

        $h = isset($ourByKey[$sk])  ? $ourByKey[$sk]
           : (isset($ourByName[$nk]) ? $ourByName[$nk]
           : null);

        if ($h !== null) {
            $invId      = (int)$h['id'];
            $ourQty     = (float)$h['net_qty'];
            $buyQty     = (float)$h['buy_qty'];
            $buyCost    = (float)$h['buy_cost'];
            $ourAvgCost = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
            $ourPrice   = $latestPrices[$invId]['price'] ?? null;

            $qtyDiff      = $stmtQty - $ourQty;
            $costDiff     = $stmtAvgCost !== null ? $stmtAvgCost - $ourAvgCost : null;
            $priceDiff    = ($stmtPrice !== null && $ourPrice !== null) ? $stmtPrice - $ourPrice : null;
            $hasQtyDiff   = abs($qtyDiff) >= 0.001;
            $hasCostDiff  = $costDiff  !== null && abs($costDiff)  >= 0.01;
            $hasPriceDiff = $priceDiff !== null && abs($priceDiff) >= 0.01;

            $results[] = [
                'investment_id'  => $invId,
                'symbol'         => $h['symbol'],
                'name'           => $h['name'],
                'description'    => trim($row[3]),
                'status'         => ($hasQtyDiff || $hasCostDiff || $hasPriceDiff) ? 'diff' : 'match',
                'has_qty_diff'   => $hasQtyDiff,
                'has_cost_diff'  => $hasCostDiff,
                'has_price_diff' => $hasPriceDiff,
                'stmt_qty'       => $stmtQty,
                'our_qty'        => $ourQty,
                'qty_diff'       => $qtyDiff,
                'stmt_avg_cost'  => $stmtAvgCost,
                'our_avg_cost'   => $ourAvgCost,
                'cost_diff'      => $costDiff,
                'stmt_price'     => $stmtPrice,
                'our_price'      => $ourPrice,
                'price_diff'     => $priceDiff,
            ];
        } else {
            $results[] = [
                'investment_id'  => null,
                'symbol'         => $symbol,
                'name'           => null,
                'description'    => trim($row[3]),
                'status'         => 'not_in_register',
                'has_qty_diff'   => false,
                'has_cost_diff'  => false,
                'has_price_diff' => false,
                'stmt_qty'       => $stmtQty,
                'our_qty'        => null,
                'qty_diff'       => null,
                'stmt_avg_cost'  => $stmtAvgCost,
                'our_avg_cost'   => null,
                'cost_diff'      => null,
                'stmt_price'     => $stmtPrice,
                'our_price'      => null,
                'price_diff'     => null,
            ];
        }
    }
}

// ── Holdings in our register absent from the statement ────────────────────
foreach ($ourHoldings as $h) {
    $sk  = symKey($h['symbol'] ?? '');
    $nk  = strtolower(trim($h['name'] ?? ''));
    if (in_array($sk,  $seenKeys,     true)) continue;
    if (in_array($nk,  $seenNameKeys, true)) continue;

    $invId      = (int)$h['id'];
    $ourQty     = (float)$h['net_qty'];
    $buyQty     = (float)$h['buy_qty'];
    $buyCost    = (float)$h['buy_cost'];
    $ourAvgCost = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $ourPrice   = $latestPrices[$invId]['price'] ?? null;

    $results[] = [
        'investment_id'  => $invId,
        'symbol'         => $h['symbol'],
        'name'           => $h['name'],
        'description'    => null,
        'status'         => 'not_in_statement',
        'has_qty_diff'   => false,
        'has_cost_diff'  => false,
        'has_price_diff' => false,
        'stmt_qty'       => null,
        'our_qty'        => $ourQty,
        'qty_diff'       => null,
        'stmt_avg_cost'  => null,
        'our_avg_cost'   => $ourAvgCost,
        'cost_diff'      => null,
        'stmt_price'     => null,
        'our_price'      => $ourPrice,
        'price_diff'     => null,
    ];
}

echo json_encode([
    'ok'            => true,
    'account_match' => $accountMatch,
    'csv_account'   => $csvAccountNumber,
    'our_account'   => $ourAccountNumber,
    'rows'          => $results,
]);

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$db = getDB();

// ── Dimension registry ──────────────────────────────────────────
$DIMS = [
    'month'       => ['label'=>'Month',           'sort'=>"DATE_FORMAT(t.transaction_date,'%Y-%m')",                'disp'=>"DATE_FORMAT(t.transaction_date,'%b %Y')",                'split'=>false],
    'quarter'     => ['label'=>'Quarter',          'sort'=>"CONCAT(YEAR(t.transaction_date),'-Q',QUARTER(t.transaction_date))",'disp'=>"CONCAT(YEAR(t.transaction_date),' Q',QUARTER(t.transaction_date))",'split'=>false],
    'year'        => ['label'=>'Year',             'sort'=>"YEAR(t.transaction_date)",                              'disp'=>"CAST(YEAR(t.transaction_date) AS CHAR)",                 'split'=>false],
    'account'     => ['label'=>'Account',          'sort'=>"a.name",                                               'disp'=>"a.name",                                                'split'=>false],
    'acct_type'   => ['label'=>'Account Type',     'sort'=>"a.type",                                               'disp'=>"a.type",                                                'split'=>false],
    'category'    => ['label'=>'Category',         'sort'=>"COALESCE(c.name,'Uncategorized')",                     'disp'=>"COALESCE(c.name,'Uncategorized')",                       'split'=>true],
    'subcategory' => ['label'=>'Subcategory',      'sort'=>"CASE WHEN sc.name IS NOT NULL THEN CONCAT(c.name,' - ',sc.name) ELSE COALESCE(c.name,'Uncategorized') END",'disp'=>"CASE WHEN sc.name IS NOT NULL THEN CONCAT(c.name,' - ',sc.name) ELSE COALESCE(c.name,'Uncategorized') END",'split'=>true],
    'payee'       => ['label'=>'Payee',            'sort'=>"IF(t.payee='','(no payee)',t.payee)",                  'disp'=>"IF(t.payee='','(no payee)',t.payee)",                    'split'=>false],
    'txn_type'    => ['label'=>'Transaction Type', 'sort'=>"t.type",                                               'disp'=>"t.type",                                                'split'=>false],
];

$METRIC_LABELS = [
    'sum_out' => 'Total Payments',
    'sum_in'  => 'Total Deposits',
    'sum_net' => 'Net Amount',
    'sum_abs' => 'Total Volume',
    'count'   => '# Transactions',
    'avg'     => 'Average Amount',
];

// ── Report type ─────────────────────────────────────────────────
$validRptTypes = ['summary', 'detail', 'txnlist'];
// Backwards-compat: if ?row= present and no ?type=, default to summary
if (isset($_GET['type']) && in_array($_GET['type'], $validRptTypes)) {
    $rptType = $_GET['type'];
} elseif (isset($_GET['row'])) {
    $rptType = 'summary';
} else {
    $rptType = 'summary';
}

$hasRun = isset($_GET['type']) || isset($_GET['row']);

// ── Shared params ───────────────────────────────────────────────
$startDate  = $_GET['start'] ?? date('Y-01-01');
$endRaw     = $_GET['end']   ?? date('Y-m-d');
$endIsToday = ($endRaw === 'today');
$endDate    = $endIsToday ? date('Y-m-d') : $endRaw;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-m-d');

$reportTitle   = trim($_GET['title'] ?? '');
$acctFilter    = array_filter(array_map('intval', (array)($_GET['acct'] ?? [])));
$validTypes    = ['withdrawal','deposit','transfer','investment'];
$typeFilter    = array_filter((array)($_GET['types'] ?? ['withdrawal','deposit']), fn($v) => in_array($v, $validTypes));
if (empty($typeFilter)) $typeFilter = ['withdrawal','deposit'];

$catFilter     = array_filter(array_map('intval', (array)($_GET['cat'] ?? [])));
$payeeFilter   = trim($_GET['payee'] ?? '');
$amtMin        = ($_GET['amt_min'] ?? '') !== '' ? max(0.0, (float)$_GET['amt_min']) : null;
$amtMax        = ($_GET['amt_max'] ?? '') !== '' ? max(0.0, (float)$_GET['amt_max']) : null;
$clearedFilter = in_array($_GET['cleared'] ?? 'all', ['all','cleared','uncleared']) ? ($_GET['cleared'] ?? 'all') : 'all';

// ── Summary params ──────────────────────────────────────────────
$validDims = array_keys($DIMS);
$validMet  = array_keys($METRIC_LABELS);

$rowDim = in_array($_GET['row']    ?? '', $validDims)             ? $_GET['row']    : 'month';
$colDim = in_array($_GET['col']    ?? '', [...$validDims,'none']) ? $_GET['col']    : 'none';
$metric = in_array($_GET['metric'] ?? '', $validMet)              ? $_GET['metric'] : 'sum_out';
$topN   = max(0, min(200, (int)($_GET['top'] ?? 25)));

if ($colDim !== 'none' && $colDim === $rowDim) $colDim = 'none';

$showDelta = ($_GET['delta'] ?? '0') === '1'
          && $colDim === 'none'
          && in_array($rowDim, ['month','quarter','year']);

$isMoney   = $metric !== 'count';
$metricLbl = $METRIC_LABELS[$metric];

// ── Detail params ───────────────────────────────────────────────
$detailCatType = in_array($_GET['dct'] ?? '', ['expense','income','both']) ? $_GET['dct'] : 'expense';
$detailLevel   = in_array($_GET['dlv'] ?? '', ['cat','subcat','txn'])      ? $_GET['dlv'] : 'txn';
$detailTime    = in_array($_GET['dti'] ?? '', ['none','month','quarter','year']) ? $_GET['dti'] : 'month';
$detailSort    = in_array($_GET['dso'] ?? '', ['total','alpha'])            ? $_GET['dso'] : 'total';

// ── TxnList params ──────────────────────────────────────────────
$txnSort  = in_array($_GET['tsrt'] ?? '', ['date','payee','amount','category','account']) ? $_GET['tsrt'] : 'date';
$txnDir   = ($_GET['tdir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$txnLimit = max(50, min(2000, (int)($_GET['tlim'] ?? 500)));
$validTxnCols = ['date','payee','account','category','subcategory','amount','memo','cleared'];
$txnColsRaw   = (array)($_GET['tcols'] ?? ['date','payee','account','category','amount']);
$txnCols      = array_values(array_intersect($validTxnCols, $txnColsRaw));
if (empty($txnCols)) $txnCols = ['date','payee','account','category','amount'];

// Min transaction date for "All" quick range
$minTxnDate = $db->query("SELECT MIN(transaction_date) FROM transactions")->fetchColumn() ?: '2000-01-01';

// Graph config from a saved report (saved_id) or dashboard widget (fav_id)
$savedGraphConfig = null;
$savedId = max(0, (int)($_GET['saved_id'] ?? 0));
if ($savedId) {
    $sgStmt = $db->prepare("SELECT graph_config FROM favorite_reports WHERE id = ? AND type = 'saved' LIMIT 1");
    $sgStmt->execute([$savedId]);
    $sgRow = $sgStmt->fetch();
    if ($sgRow && !empty($sgRow['graph_config'])) {
        $decoded = json_decode($sgRow['graph_config'], true);
        if (json_last_error() === JSON_ERROR_NONE) $savedGraphConfig = $decoded;
    }
}
if (!$savedGraphConfig) {
    $favId = max(0, (int)($_GET['fav_id'] ?? 0));
    if ($favId) {
        $fgStmt = $db->prepare("SELECT graph_config FROM favorite_reports WHERE id = ? AND type = 'dashboard' LIMIT 1");
        $fgStmt->execute([$favId]);
        $fgRow = $fgStmt->fetch();
        if ($fgRow && !empty($fgRow['graph_config'])) {
            $decoded = json_decode($fgRow['graph_config'], true);
            if (json_last_error() === JSON_ERROR_NONE) $savedGraphConfig = $decoded;
        }
    }
}

// ── Data vars ───────────────────────────────────────────────────
$rowLabels       = [];
$colLabels       = [];
$pivotData       = [];
$rowTotals       = [];
$colTotals       = [];
$grandTotal      = 0;
$deltaData       = [];
$queryRows       = 0;
$detailTree      = [];
$detailTimeCols  = [];
$detailGrandPeriods = [];
$detailGrandTotal   = 0.0;
$txnRows         = [];
$crReportData    = null;

// ── Helper functions ─────────────────────────────────────────────

/**
 * Returns [period_key => display_label] for all periods in the date range.
 * When $period = 'none', returns ['total' => 'Total'].
 */
function crTimeCols(string $start, string $end, string $period): array {
    if ($period === 'none') return ['total' => 'Total'];
    $cols = [];
    $ts   = strtotime($start);
    $te   = strtotime($end);
    if ($ts === false || $te === false) return [];
    while ($ts <= $te) {
        switch ($period) {
            case 'month':
                $key   = date('Y-m', $ts);
                $label = date('M Y', $ts);
                $ts    = strtotime('+1 month', strtotime(date('Y-m-01', $ts)));
                break;
            case 'quarter':
                $y = (int)date('Y', $ts);
                $q = (int)ceil((int)date('m', $ts) / 3);
                $key   = $y . '-Q' . $q;
                $label = $y . ' Q' . $q;
                $nextQ = $q === 4 ? 1 : $q + 1;
                $nextY = $q === 4 ? $y + 1 : $y;
                $ts    = mktime(0, 0, 0, ($nextQ - 1) * 3 + 1, 1, $nextY);
                break;
            case 'year':
                $y   = (int)date('Y', $ts);
                $key   = (string)$y;
                $label = (string)$y;
                $ts    = mktime(0, 0, 0, 1, 1, $y + 1);
                break;
            default:
                return [];
        }
        $cols[$key] = $label;
    }
    return $cols;
}

/**
 * Returns the period key for a given transaction date string.
 * When $period = 'none', returns 'total'.
 */
function crPeriodKey(string $date, string $period): string {
    if ($period === 'none') return 'total';
    $ts = strtotime($date);
    switch ($period) {
        case 'month':   return date('Y-m', $ts);
        case 'quarter': return date('Y', $ts) . '-Q' . (int)ceil((int)date('m', $ts) / 3);
        case 'year':    return date('Y', $ts);
        default:        return 'total';
    }
}

/**
 * Build the shared WHERE clause + params array for summary/txnlist queries
 * that use transaction-level amount filters (ABS(t.amount)).
 */
function crSharedWhere(
    string $startDate, string $endDate,
    array $acctFilter, array $typeFilter, array $catFilter,
    string $payeeFilter, ?float $amtMin, ?float $amtMax,
    string $clearedFilter, bool $hasSplits
): array {
    $where  = ["t.transaction_date BETWEEN ? AND ?"];
    $params = [$startDate, $endDate];

    if (!empty($acctFilter)) {
        $ph = implode(',', array_fill(0, count($acctFilter), '?'));
        $where[] = "t.account_id IN ($ph)";
        array_push($params, ...$acctFilter);
    }

    $ph = implode(',', array_fill(0, count($typeFilter), '?'));
    $where[] = "t.type IN ($ph)";
    array_push($params, ...$typeFilter);

    if (!empty($catFilter) && $hasSplits) {
        $ph = implode(',', array_fill(0, count($catFilter), '?'));
        $where[] = "(ts.category_id IN ($ph) OR ts.subcategory_id IN ($ph))";
        array_push($params, ...$catFilter);
        array_push($params, ...$catFilter);
    }
    if ($payeeFilter !== '') {
        $where[] = "t.payee LIKE ?";
        $params[] = '%' . $payeeFilter . '%';
    }
    if ($amtMin !== null) {
        $where[] = "ABS(t.amount) >= ?";
        $params[] = $amtMin;
    }
    if ($amtMax !== null) {
        $where[] = "ABS(t.amount) <= ?";
        $params[] = $amtMax;
    }
    if ($clearedFilter === 'cleared') {
        $where[] = "t.cleared_status IN ('cleared','reconciled')";
    } elseif ($clearedFilter === 'uncleared') {
        $where[] = "t.cleared_status = ''";
    }

    return [$where, $params];
}

// ── Run the appropriate query ────────────────────────────────────

if ($hasRun) {

    // ────────────────────────────────────────────────────────────
    // SUMMARY MODE
    // ────────────────────────────────────────────────────────────
    if ($rptType === 'summary') {

        $needsSplit = $DIMS[$rowDim]['split']
                   || ($colDim !== 'none' && $DIMS[$colDim]['split'])
                   || !empty($catFilter);

        $joins = "FROM transactions t\n  JOIN accounts a ON a.id = t.account_id";
        if ($needsSplit) {
            $joins .= "\n  JOIN transaction_splits ts ON ts.transaction_id = t.id"
                    . "\n  LEFT JOIN categories c  ON c.id  = ts.category_id"
                    . "\n  LEFT JOIN categories sc ON sc.id = ts.subcategory_id";
        }

        $a = $needsSplit ? 'ts.amount' : 't.amount';
        $metricExpr = match($metric) {
            'sum_out' => "SUM(CASE WHEN $a < 0 THEN ABS($a) ELSE 0 END)",
            'sum_in'  => "SUM(CASE WHEN $a > 0 THEN $a ELSE 0 END)",
            'sum_net' => "SUM($a)",
            'sum_abs' => "SUM(ABS($a))",
            'count'   => "COUNT(DISTINCT t.id)",
            'avg'     => "AVG(ABS(t.amount))",
        };

        $where  = ["t.transaction_date BETWEEN ? AND ?"];
        $params = [$startDate, $endDate];

        if (!empty($acctFilter)) {
            $ph = implode(',', array_fill(0, count($acctFilter), '?'));
            $where[] = "t.account_id IN ($ph)";
            array_push($params, ...$acctFilter);
        }
        $ph = implode(',', array_fill(0, count($typeFilter), '?'));
        $where[] = "t.type IN ($ph)";
        array_push($params, ...$typeFilter);

        if (!empty($catFilter)) {
            $ph = implode(',', array_fill(0, count($catFilter), '?'));
            $where[] = "(ts.category_id IN ($ph) OR ts.subcategory_id IN ($ph))";
            array_push($params, ...$catFilter);
            array_push($params, ...$catFilter);
        }
        if ($payeeFilter !== '') {
            $where[] = "t.payee LIKE ?";
            $params[] = '%' . $payeeFilter . '%';
        }
        if ($amtMin !== null) {
            $where[] = "ABS(t.amount) >= ?";
            $params[] = $amtMin;
        }
        if ($amtMax !== null) {
            $where[] = "ABS(t.amount) <= ?";
            $params[] = $amtMax;
        }
        if ($clearedFilter === 'cleared') {
            $where[] = "t.cleared_status IN ('cleared','reconciled')";
        } elseif ($clearedFilter === 'uncleared') {
            $where[] = "t.cleared_status = ''";
        }

        $wc = implode(' AND ', $where);
        $rs = $DIMS[$rowDim]['sort'];
        $rd = $DIMS[$rowDim]['disp'];
        $isTimeDim = in_array($rowDim, ['month','quarter','year']);

        if ($colDim === 'none') {
            $sql = "SELECT $rs AS row_sort, $rd AS row_label, $metricExpr AS val
                    $joins WHERE $wc
                    GROUP BY row_sort, row_label
                    ORDER BY " . ($isTimeDim ? 'row_sort ASC' : 'val DESC');
        } else {
            $cs = $DIMS[$colDim]['sort'];
            $cd = $DIMS[$colDim]['disp'];
            $sql = "SELECT $rs AS row_sort, $rd AS row_label,
                           $cs AS col_sort, $cd AS col_label,
                           $metricExpr AS val
                    $joins WHERE $wc
                    GROUP BY row_sort, row_label, col_sort, col_label
                    ORDER BY row_sort ASC, col_sort ASC";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $raw = $stmt->fetchAll();
        $queryRows = count($raw);

        $colSortMap = [];
        foreach ($raw as $r) {
            $rl = (string)$r['row_label'];
            $v  = (float)$r['val'];

            if ($colDim === 'none') {
                if (!isset($rowTotals[$rl])) $rowLabels[] = $rl;
                $rowTotals[$rl] = ($rowTotals[$rl] ?? 0) + $v;
                $grandTotal += $v;
            } else {
                $cl = (string)$r['col_label'];
                $colSortMap[$cl] = $r['col_sort'];
                if (!isset($rowTotals[$rl])) $rowLabels[] = $rl;
                $pivotData[$rl][$cl] = ($pivotData[$rl][$cl] ?? 0) + $v;
                $rowTotals[$rl]      = ($rowTotals[$rl] ?? 0) + $v;
                $colTotals[$cl]      = ($colTotals[$cl] ?? 0) + $v;
                $grandTotal         += $v;
            }
        }

        if ($colDim !== 'none') {
            asort($colSortMap);
            $colLabels = array_keys($colSortMap);
        }

        // Top N + Other bucket
        if ($topN > 0 && count($rowLabels) > $topN) {
            if ($isTimeDim) {
                $rowLabels = array_slice($rowLabels, 0, $topN);
            } elseif ($colDim === 'none') {
                $topLabels  = array_slice($rowLabels, 0, $topN);
                $restLabels = array_slice($rowLabels, $topN);
                $otherVal   = array_sum(array_map(fn($rl) => $rowTotals[$rl], $restLabels));
                foreach ($restLabels as $rl) unset($rowTotals[$rl]);
                if ($otherVal > 0) {
                    $rowTotals['Other'] = $otherVal;
                    $topLabels[] = 'Other';
                }
                $rowLabels = $topLabels;
            } else {
                arsort($rowTotals);
                $allLabels  = array_keys($rowTotals);
                $topLabels  = array_slice($allLabels, 0, $topN);
                $restLabels = array_slice($allLabels, $topN);
                $otherRow   = [];
                $otherTotal = 0;
                foreach ($restLabels as $rl) {
                    $otherTotal += $rowTotals[$rl];
                    foreach ($colLabels as $cl) {
                        $otherRow[$cl] = ($otherRow[$cl] ?? 0) + ($pivotData[$rl][$cl] ?? 0);
                    }
                    unset($rowTotals[$rl], $pivotData[$rl]);
                }
                if ($otherTotal > 0) {
                    $rowTotals['Other'] = $otherTotal;
                    $pivotData['Other'] = $otherRow;
                    $topLabels[] = 'Other';
                }
                $rowLabels = $topLabels;
            }
        }

        // Period-over-period delta
        if ($showDelta && count($rowLabels) > 0) {
            $deltaData[$rowLabels[0]] = null;
            for ($i = 1; $i < count($rowLabels); $i++) {
                $curr = $rowTotals[$rowLabels[$i]] ?? 0;
                $prev = $rowTotals[$rowLabels[$i - 1]] ?? 0;
                $deltaData[$rowLabels[$i]] = $prev != 0 ? (($curr - $prev) / abs($prev)) * 100 : null;
            }
        }

    // ────────────────────────────────────────────────────────────
    // DETAIL MODE
    // ────────────────────────────────────────────────────────────
    } elseif ($rptType === 'detail') {

        $detailTimeCols = crTimeCols($startDate, $endDate, $detailTime);

        $where  = ["t.transaction_date BETWEEN ? AND ?", "t.type != 'transfer'"];
        $params = [$startDate, $endDate];

        if (!empty($acctFilter)) {
            $ph = implode(',', array_fill(0, count($acctFilter), '?'));
            $where[] = "t.account_id IN ($ph)";
            array_push($params, ...$acctFilter);
        }
        if ($detailCatType !== 'both') {
            $where[] = "c.type = ?";
            $params[] = $detailCatType;
        }
        if (!empty($catFilter)) {
            $ph = implode(',', array_fill(0, count($catFilter), '?'));
            $where[] = "(ts.category_id IN ($ph) OR ts.subcategory_id IN ($ph))";
            array_push($params, ...$catFilter);
            array_push($params, ...$catFilter);
        }
        if ($payeeFilter !== '') {
            $where[] = "t.payee LIKE ?";
            $params[] = '%' . $payeeFilter . '%';
        }
        if ($amtMin !== null) {
            $where[] = "ABS(ts.amount) >= ?";
            $params[] = $amtMin;
        }
        if ($amtMax !== null) {
            $where[] = "ABS(ts.amount) <= ?";
            $params[] = $amtMax;
        }
        if ($clearedFilter === 'cleared') {
            $where[] = "t.cleared_status IN ('cleared','reconciled')";
        } elseif ($clearedFilter === 'uncleared') {
            $where[] = "t.cleared_status = ''";
        }

        $wc  = implode(' AND ', $where);
        $sql = "SELECT
                  c.id AS cat_id, c.name AS cat_name, c.type AS cat_type,
                  sc.id AS sub_id, sc.name AS sub_name,
                  t.id AS txn_id, t.transaction_date, t.payee,
                  t.memo AS txn_memo, a.name AS account_name,
                  ABS(ts.amount) AS amount, ts.memo AS split_memo
                FROM transaction_splits ts
                JOIN transactions t ON t.id = ts.transaction_id
                JOIN accounts a ON a.id = t.account_id
                JOIN categories c ON c.id = ts.category_id
                LEFT JOIN categories sc ON sc.id = ts.subcategory_id
                WHERE $wc
                ORDER BY c.name, sc.name, t.transaction_date, t.id";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $raw = $stmt->fetchAll();
        $queryRows = count($raw);

        // Build the detail tree
        foreach ($raw as $r) {
            $catId   = (int)$r['cat_id'];
            $subId   = $r['sub_id'] !== null ? (int)$r['sub_id'] : 'direct';
            $amt     = (float)$r['amount'];
            $pk      = crPeriodKey($r['transaction_date'], $detailTime);

            // Init category node
            if (!isset($detailTree[$catId])) {
                $detailTree[$catId] = [
                    'name'    => $r['cat_name'],
                    'type'    => $r['cat_type'],
                    'total'   => 0.0,
                    'periods' => [],
                    'subs'    => [],
                ];
            }
            $detailTree[$catId]['total'] += $amt;
            $detailTree[$catId]['periods'][$pk] = ($detailTree[$catId]['periods'][$pk] ?? 0.0) + $amt;

            // Init sub node
            if (!isset($detailTree[$catId]['subs'][$subId])) {
                $detailTree[$catId]['subs'][$subId] = [
                    'name'    => $r['sub_name'],
                    'total'   => 0.0,
                    'periods' => [],
                    'txns'    => [],
                ];
            }
            $detailTree[$catId]['subs'][$subId]['total'] += $amt;
            $detailTree[$catId]['subs'][$subId]['periods'][$pk] =
                ($detailTree[$catId]['subs'][$subId]['periods'][$pk] ?? 0.0) + $amt;

            // Append transaction if needed
            if ($detailLevel === 'txn') {
                $detailTree[$catId]['subs'][$subId]['txns'][] = [
                    'txn_id'       => $r['txn_id'],
                    'date'         => $r['transaction_date'],
                    'payee'        => $r['payee'],
                    'account_name' => $r['account_name'],
                    'amount'       => $amt,
                    'memo'         => $r['split_memo'] ?: $r['txn_memo'],
                    'period_key'   => $pk,
                ];
            }

            // Grand total accumulation
            $detailGrandPeriods[$pk] = ($detailGrandPeriods[$pk] ?? 0.0) + $amt;
            $detailGrandTotal += $amt;
        }

        // Sort categories
        if ($detailSort === 'total') {
            uasort($detailTree, fn($a, $b) => $b['total'] <=> $a['total']);
        } else {
            uasort($detailTree, fn($a, $b) => strcmp($a['name'], $b['name']));
        }

        // Sort subs within each cat
        foreach ($detailTree as &$cat) {
            if ($detailSort === 'total') {
                uasort($cat['subs'], fn($a, $b) => $b['total'] <=> $a['total']);
            } else {
                uasort($cat['subs'], function($a, $b) {
                    $na = $a['name'] ?? '';
                    $nb = $b['name'] ?? '';
                    return strcmp($na, $nb);
                });
            }
        }
        unset($cat);

    // ────────────────────────────────────────────────────────────
    // TXNLIST MODE
    // ────────────────────────────────────────────────────────────
    } elseif ($rptType === 'txnlist') {

        $where  = ["t.transaction_date BETWEEN ? AND ?"];
        $params = [$startDate, $endDate];

        if (!empty($acctFilter)) {
            $ph = implode(',', array_fill(0, count($acctFilter), '?'));
            $where[] = "t.account_id IN ($ph)";
            array_push($params, ...$acctFilter);
        }
        $ph = implode(',', array_fill(0, count($typeFilter), '?'));
        $where[] = "t.type IN ($ph)";
        array_push($params, ...$typeFilter);

        if (!empty($catFilter)) {
            $ph = implode(',', array_fill(0, count($catFilter), '?'));
            $where[] = "(ts.category_id IN ($ph) OR ts.subcategory_id IN ($ph))";
            array_push($params, ...$catFilter);
            array_push($params, ...$catFilter);
        }
        if ($payeeFilter !== '') {
            $where[] = "t.payee LIKE ?";
            $params[] = '%' . $payeeFilter . '%';
        }
        if ($amtMin !== null) {
            $where[] = "ABS(t.amount) >= ?";
            $params[] = $amtMin;
        }
        if ($amtMax !== null) {
            $where[] = "ABS(t.amount) <= ?";
            $params[] = $amtMax;
        }
        if ($clearedFilter === 'cleared') {
            $where[] = "t.cleared_status IN ('cleared','reconciled')";
        } elseif ($clearedFilter === 'uncleared') {
            $where[] = "t.cleared_status = ''";
        }

        $wc = implode(' AND ', $where);

        $orderExpr = match($txnSort) {
            'payee'    => "t.payee $txnDir, t.transaction_date DESC",
            'amount'   => "ABS(ts.amount) $txnDir, t.transaction_date DESC",
            'category' => "COALESCE(c.name,'') $txnDir, COALESCE(sc.name,'') $txnDir, t.transaction_date DESC",
            'account'  => "a.name $txnDir, t.transaction_date DESC",
            default    => "t.transaction_date $txnDir, t.id $txnDir",
        };

        $sql = "SELECT t.id, t.transaction_date, t.payee, t.type AS txn_type,
                  t.memo AS txn_memo, t.cleared_status,
                  a.name AS account_name,
                  ts.amount AS split_amount, ts.memo AS split_memo,
                  c.name AS category, sc.name AS subcategory
                FROM transaction_splits ts
                JOIN transactions t ON t.id = ts.transaction_id
                JOIN accounts a ON a.id = t.account_id
                LEFT JOIN categories c ON c.id = ts.category_id
                LEFT JOIN categories sc ON sc.id = ts.subcategory_id
                WHERE $wc
                ORDER BY $orderExpr
                LIMIT ?";

        $params[] = $txnLimit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $txnRows   = $stmt->fetchAll();
        $queryRows = count($txnRows);
    }

    // ── CSV export (must come after data is populated) ─────────
    if (($__export = $_GET['export'] ?? '') === 'csv') {
        $filename = ($reportTitle ?: 'custom-report') . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');

        if ($rptType === 'summary') {
            if ($colDim === 'none') {
                $hdrs = [$DIMS[$rowDim]['label'], $metricLbl];
                if ($showDelta) $hdrs[] = 'Δ vs Prior';
                fputcsv($out, $hdrs);
                foreach ($rowLabels as $rl) {
                    $row = [$rl, $isMoney ? number_format((float)$rowTotals[$rl], 2, '.', '') : $rowTotals[$rl]];
                    if ($showDelta) {
                        $d = $deltaData[$rl] ?? null;
                        $row[] = $d !== null ? number_format($d, 1) . '%' : '';
                    }
                    fputcsv($out, $row);
                }
                $totRow = ['Total', $isMoney ? number_format($grandTotal, 2, '.', '') : $grandTotal];
                if ($showDelta) $totRow[] = '';
                fputcsv($out, $totRow);
            } else {
                $headers = [$DIMS[$rowDim]['label']];
                foreach ($colLabels as $cl) $headers[] = $cl;
                $headers[] = 'Total';
                fputcsv($out, $headers);
                foreach ($rowLabels as $rl) {
                    $row = [$rl];
                    foreach ($colLabels as $cl) {
                        $v = $pivotData[$rl][$cl] ?? 0;
                        $row[] = $isMoney ? number_format($v, 2, '.', '') : $v;
                    }
                    $row[] = $isMoney ? number_format($rowTotals[$rl] ?? 0, 2, '.', '') : ($rowTotals[$rl] ?? 0);
                    fputcsv($out, $row);
                }
                $totRow = ['Total'];
                foreach ($colLabels as $cl) $totRow[] = $isMoney ? number_format($colTotals[$cl] ?? 0, 2, '.', '') : ($colTotals[$cl] ?? 0);
                $totRow[] = $isMoney ? number_format($grandTotal, 2, '.', '') : $grandTotal;
                fputcsv($out, $totRow);
            }

        } elseif ($rptType === 'detail') {
            $hdrRow = ['Category', 'Subcategory'];
            if ($detailTime !== 'none') $hdrRow[] = 'Period';
            array_push($hdrRow, 'Date', 'Payee', 'Account', 'Amount', 'Memo');
            fputcsv($out, $hdrRow);
            foreach ($detailTree as $cat) {
                foreach ($cat['subs'] as $sub) {
                    foreach ($sub['txns'] as $txn) {
                        $row = [$cat['name'], $sub['name'] ?? ''];
                        if ($detailTime !== 'none') $row[] = $detailTimeCols[$txn['period_key']] ?? $txn['period_key'];
                        $row[] = date('m/d/Y', strtotime($txn['date']));
                        $row[] = $txn['payee'];
                        $row[] = $txn['account_name'];
                        $row[] = number_format($txn['amount'], 2, '.', '');
                        $row[] = $txn['memo'];
                        fputcsv($out, $row);
                    }
                    if (empty($sub['txns'])) {
                        // cat/subcat level — emit a summary row
                        foreach ($sub['periods'] as $pk => $amt) {
                            $row = [$cat['name'], $sub['name'] ?? ''];
                            if ($detailTime !== 'none') $row[] = $detailTimeCols[$pk] ?? $pk;
                            array_push($row, '', '', '', number_format($amt, 2, '.', ''), '');
                            fputcsv($out, $row);
                        }
                    }
                }
            }

        } elseif ($rptType === 'txnlist') {
            $colHeaders = [];
            foreach ($txnCols as $col) {
                $colHeaders[] = match($col) {
                    'date'       => 'Date',
                    'payee'      => 'Payee',
                    'account'    => 'Account',
                    'category'   => 'Category',
                    'subcategory'=> 'Subcategory',
                    'amount'     => 'Amount',
                    'memo'       => 'Memo',
                    'cleared'    => 'Cleared',
                    default      => ucfirst($col),
                };
            }
            fputcsv($out, $colHeaders);
            foreach ($txnRows as $r) {
                $row = [];
                foreach ($txnCols as $col) {
                    $row[] = match($col) {
                        'date'       => date('m/d/Y', strtotime($r['transaction_date'])),
                        'payee'      => $r['payee'],
                        'account'    => $r['account_name'],
                        'category'   => ($r['category'] ?? '') . (!empty($r['subcategory']) ? ' - ' . $r['subcategory'] : ''),
                        'subcategory'=> $r['subcategory'] ?? '',
                        'amount'     => number_format(abs((float)$r['split_amount']), 2, '.', ''),
                        'memo'       => $r['split_memo'] ?: $r['txn_memo'],
                        'cleared'    => $r['cleared_status'],
                        default      => '',
                    };
                }
                fputcsv($out, $row);
            }
        }

        fclose($out);
        exit;
    }

    // ── Build graph-builder data payload ────────────────────────
    if ($queryRows > 0) {
        if ($rptType === 'summary') {
            $crRoundedPivot = [];
            foreach ($pivotData as $rl => $cols) {
                $crRoundedPivot[$rl] = array_map(fn($v) => round($v, 2), $cols);
            }
            $crReportData = [
                'mode'        => 'summary',
                'rowDimLabel' => $DIMS[$rowDim]['label'],
                'colDimLabel' => $colDim !== 'none' ? $DIMS[$colDim]['label'] : null,
                'metricLbl'   => $metricLbl,
                'isMoney'     => $isMoney,
                'hasPivot'    => $colDim !== 'none',
                'rowLabels'   => $rowLabels,
                'colLabels'   => $colLabels,
                'values'      => array_map(fn($v) => round($v, 2), $rowTotals),
                'pivot'       => $crRoundedPivot,
                'rowTotals'   => array_map(fn($v) => round($v, 2), $rowTotals),
                'colTotals'   => array_map(fn($v) => round($v, 2), $colTotals),
                'grandTotal'  => round($grandTotal, 2),
            ];

        } elseif ($rptType === 'detail') {
            $crCats = [];
            foreach ($detailTree as $cat) {
                $crCats[] = [
                    'name'    => $cat['name'],
                    'total'   => round($cat['total'], 2),
                    'periods' => array_map(fn($v) => round($v, 2), $cat['periods']),
                ];
            }
            $crReportData = [
                'mode'      => 'detail',
                'isMoney'   => true,
                'hasPivot'  => $detailTime !== 'none' && !empty($detailTimeCols),
                'timeCols'  => $detailTimeCols,
                'cats'      => $crCats,
                'grandTotal'=> round($detailGrandTotal, 2),
            ];

        } elseif ($rptType === 'txnlist') {
            $crRows = [];
            foreach ($txnRows as $r) {
                $row = [];
                foreach ($txnCols as $col) {
                    $row[$col] = match($col) {
                        'date'        => $r['transaction_date'],
                        'payee'       => $r['payee'],
                        'account'     => $r['account_name'],
                        'category'    => ($r['category'] ?? '') . (!empty($r['subcategory']) ? ' - ' . $r['subcategory'] : ''),
                        'subcategory' => $r['subcategory'] ?? '',
                        'amount'      => (float)$r['split_amount'],
                        'memo'        => $r['split_memo'] ?: $r['txn_memo'],
                        'cleared'     => $r['cleared_status'],
                        default       => '',
                    };
                }
                $crRows[] = $row;
            }
            $crReportData = [
                'mode'    => 'txnlist',
                'isMoney' => true,
                'cols'    => $txnCols,
                'rows'    => $crRows,
            ];
        }
    }

} // end if ($hasRun)

// ── Sidebar/shared data ──────────────────────────────────────────
$allAccounts = getAccounts(true, true); // include closed for historical report filtering
$allCats     = getAllCategoriesHierarchy();

// Compute ordered list of distinct display-types for accounts (excl. investment-cash)
$_crTypeOrder = ['Checking','Savings','Credit Card','Investment','Retirement','Crypto','Asset','Loan'];
$_crTypesSeen = [];
foreach ($_crTypeOrder as $_t) {
    foreach ($allAccounts as $_a) {
        if ($_a['type'] === 'investment-cash' || !empty($_a['is_investment_cash'])) continue;
        $_dt = ($_a['type'] === 'Investment' && !empty($_a['is_retirement'])) ? 'Retirement' : $_a['type'];
        if ($_dt === $_t && !in_array($_dt, $_crTypesSeen)) { $_crTypesSeen[] = $_dt; break; }
    }
}
// Catch any types not in the ordered list
foreach ($allAccounts as $_a) {
    if ($_a['type'] === 'investment-cash' || !empty($_a['is_investment_cash'])) continue;
    $_dt = ($_a['type'] === 'Investment' && !empty($_a['is_retirement'])) ? 'Retirement' : $_a['type'];
    if (!in_array($_dt, $_crTypesSeen)) $_crTypesSeen[] = $_dt;
}
$crAcctTypes = $_crTypesSeen;

$csvUrl = '?' . http_build_query(array_merge($_GET, ['export' => 'csv']));

$pageTitle   = 'Custom Report' . ($reportTitle ? ' — ' . $reportTitle : '');
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>
<style>
.cr-mode-row { display: none; flex-wrap: wrap; gap: 12px; align-items: flex-start; }
.cr-det-cat-row > td { background: #eef2f7; font-weight: 700; font-size: .83rem; border-top: 1px solid #c8d4e3; }
.cr-det-cat-row > td:first-child { background: #eef2f7; }
.cr-det-sub-row > td { background: #f6f8fb; font-weight: 600; font-size: .82rem; }
.cr-det-sub-row > td:first-child { background: #f6f8fb; }
.cr-det-txn-row > td { font-size: .80rem; color: #333; }
.cr-det-txn-row:hover > td { background: var(--ms-row-sel) !important; }
.cr-det-total-row > td { border-top: 2px solid #c8d4e3; font-weight: 700; background: #eef2f7 !important; }
.cr-det-table { min-width: 500px; margin: 0; }
.cr-det-table thead th { position: sticky; top: 0; z-index: 2; white-space: nowrap; padding: 7px 10px; font-size: .82rem; background: #eef2f7; color: #333; text-transform: none; letter-spacing: 0; }
.cr-det-table th:first-child, .cr-det-table td:first-child { position: sticky; left: 0; z-index: 1; border-right: 1px solid #c8d4e3; }
.cr-det-table thead th:first-child { z-index: 3; }
.cr-det-table td { padding: 5px 10px; vertical-align: middle; }
.cr-det-amt { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.cr-det-label-name { display: block; }
.cr-det-label-meta { display: block; font-size: .75rem; color: #888; font-weight: 400; }
.cr-type-badge { display: inline-block; font-size: .62rem; border-radius: 3px; padding: 0 4px; margin-left: 5px; vertical-align: middle; font-weight: 500; border: 1px solid currentColor; }
.cr-type-badge-exp { color: #c0392b; }
.cr-type-badge-inc { color: #1a7a3c; }
.cr-txn-table { min-width: 400px; font-size: .82rem; margin: 0; }
.cr-txn-table thead th { position: sticky; top: 0; white-space: nowrap; padding: 7px 10px; background: #eef2f7; color: #333; text-transform: none; letter-spacing: 0; }
.cr-txn-table td { padding: 5px 10px; vertical-align: middle; }
.cr-txn-table tbody tr:hover { background: var(--ms-row-sel); }
.cr-txn-debit { color: var(--neg-color); }
.cr-txn-credit { color: var(--pos-color, #1a7a3c); }
/* ── Graph Builder Panel ───────────────────────── */
.cr-graph-panel { background:#fff; border:1px solid var(--ms-border); border-radius:8px; margin-top:18px; overflow:hidden; }
.cr-graph-panel-hdr { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; background:var(--ms-blue); color:#fff; font-weight:600; font-size:.88rem; }
.cr-graph-config { padding:14px 16px; display:flex; flex-direction:column; gap:10px; border-bottom:1px solid var(--ms-border); }
.cr-graph-row { display:flex; align-items:flex-start; gap:12px; flex-wrap:wrap; }
.cr-graph-lbl { min-width:130px; font-size:.78rem; font-weight:600; color:#555; padding-top:6px; }
.cr-graph-ctrl { flex:1; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.cr-grp-series-list { display:flex; flex-wrap:wrap; gap:6px; max-height:120px; overflow-y:auto; padding:4px; border:1px solid #e0e4ea; border-radius:4px; background:#fafbfc; }
.cr-grp-series-cb { display:flex; align-items:center; gap:4px; font-size:.78rem; padding:3px 7px; border-radius:12px; border:1px solid #d0d8e4; background:#fff; cursor:pointer; white-space:nowrap; }
.cr-grp-series-cb input { cursor:pointer; }
.cr-grp-series-cb:has(input:checked) { background:#e8effa; border-color:var(--ms-blue-lt); }
.cr-graph-type-btn { padding:5px 12px; border-radius:5px; border:1px solid #c8d4e3; background:#fff; font-size:.80rem; cursor:pointer; display:flex; align-items:center; gap:5px; }
.cr-graph-type-btn.active { background:var(--ms-blue); color:#fff; border-color:var(--ms-blue); }
.cr-graph-canvas-wrap { padding:18px; background:#fff; }
</style>

<?php $reportFavTitle = $reportTitle ?: 'Custom Report'; $reportFavIcon = 'bi-sliders2'; $reportFavDashOnly = true; ?>
<div class="page-header">
  <h2><i class="bi bi-sliders2"></i> Custom Report<?= $reportTitle ? ' — <span class="cr-title-display">'.h($reportTitle).'</span>' : '' ?></h2>
  <?php if ($hasRun): include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <a href="<?= h($csvUrl) ?>" class="btn btn-sm btn-outline-success" title="Download CSV">
    <i class="bi bi-filetype-csv"></i> CSV
  </a>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyPermalink(this)" title="Copy link to this report">
    <i class="bi bi-link-45deg"></i> Link
  </button>
  <?php
  // ── Save Report button state ─────────────────────────────────
  $__srRawQ = $_SERVER['QUERY_STRING'] ?? '';
  parse_str($__srRawQ, $__srQ);
  unset($__srQ['export'], $__srQ['saved_id'], $__srQ['fav_id']);
  $__srPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $__srBase = defined('BASE_PATH') ? BASE_PATH : '';
  if ($__srBase !== '' && str_starts_with($__srPath, $__srBase)) {
      $__srPath = substr($__srPath, strlen($__srBase));
  }
  $__srUrl    = $__srPath . (!empty($__srQ) ? '?' . http_build_query($__srQ) : '');
  $__srList   = getSavedCustomReports();
  $__srId     = null;
  $__srTitle  = '';
  foreach ($__srList as $__sr) {
      if ($__sr['url'] === $__srUrl) { $__srId = (int)$__sr['id']; $__srTitle = $__sr['title']; break; }
  }
  $__isSaved = $__srId !== null;
  ?>
  <button type="button"
          id="btnSaveReport"
          class="btn btn-sm <?= $__isSaved ? 'btn-warning' : 'btn-outline-secondary' ?>"
          data-saved="<?= $__isSaved ? '1' : '0' ?>"
          data-save-id="<?= (int)$__srId ?>"
          data-url="<?= h($__srUrl) ?>"
          data-default-title="<?= h($reportTitle ?: 'Custom Report') ?>"
          data-csrf="<?= h(csrfToken()) ?>"
          onclick="toggleSaveReport(this)"
          title="<?= $__isSaved ? 'Remove from Saved Reports' : 'Save to Reports index' ?>">
    <i class="bi <?= $__isSaved ? 'bi-bookmark-fill' : 'bi-bookmark-plus' ?>"></i>
    <span><?= $__isSaved ? 'Saved' : 'Save Report' ?></span>
  </button>
  <?php if ($queryRows > 0): ?>
  <button type="button" class="btn btn-sm btn-outline-primary" id="btnCreateGraph" onclick="CRG.toggle(this)">
    <i class="bi bi-bar-chart-line"></i> Create Graph
  </button>
  <?php endif; ?>
  <?php endif; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<!-- ── Builder Form ─────────────────────────────────────────────── -->
<div class="cr-builder <?= $hasRun ? 'cr-builder-collapsed' : '' ?>" id="crBuilder">
  <div class="cr-builder-header" onclick="toggleBuilder()">
    <span><i class="bi bi-sliders2"></i> Report Builder</span>
    <i class="bi bi-chevron-down cr-caret" id="crCaret"></i>
  </div>
  <form method="get" class="cr-builder-body" id="crForm">

    <!-- Row 0: Report Type + Title -->
    <div class="cr-row" style="display:flex">
      <div class="cr-field">
        <label>Report Type</label>
        <select name="type" class="form-select form-select-sm" id="selRptType">
          <option value="summary"  <?= $rptType === 'summary'  ? 'selected' : '' ?>>Summary Pivot</option>
          <option value="detail"   <?= $rptType === 'detail'   ? 'selected' : '' ?>>Category Detail</option>
          <option value="txnlist"  <?= $rptType === 'txnlist'  ? 'selected' : '' ?>>Transaction List</option>
        </select>
      </div>
      <div class="cr-field cr-field-lg">
        <label>Report Title</label>
        <input type="text" name="title" class="form-control form-control-sm"
               placeholder="My Report" value="<?= h($reportTitle) ?>">
      </div>
    </div>

    <!-- Row 1a: Summary — Row Dim, Column Dim, Metric -->
    <div class="cr-row cr-mode-row cr-mode-summary">
      <div class="cr-field">
        <label>Rows <span class="cr-req">*</span></label>
        <select name="row" class="form-select form-select-sm cr-select-dim" id="selRow">
          <?php foreach ($DIMS as $key => $d): ?>
          <option value="<?= $key ?>" <?= $rowDim === $key ? 'selected' : '' ?>><?= h($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cr-field">
        <label>Columns</label>
        <select name="col" class="form-select form-select-sm cr-select-dim" id="selCol">
          <option value="none" <?= $colDim === 'none' ? 'selected' : '' ?>>— None —</option>
          <?php foreach ($DIMS as $key => $d): ?>
          <option value="<?= $key ?>" <?= $colDim === $key ? 'selected' : '' ?>><?= h($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cr-field">
        <label>Metric</label>
        <select name="metric" class="form-select form-select-sm">
          <?php foreach ($METRIC_LABELS as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $metric === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Row 1b: Detail mode params -->
    <div class="cr-row cr-mode-row cr-mode-detail">
      <div class="cr-field">
        <label>Category Type</label>
        <select name="dct" class="form-select form-select-sm">
          <option value="expense" <?= $detailCatType === 'expense' ? 'selected' : '' ?>>Expenses</option>
          <option value="income"  <?= $detailCatType === 'income'  ? 'selected' : '' ?>>Income</option>
          <option value="both"    <?= $detailCatType === 'both'    ? 'selected' : '' ?>>Both</option>
        </select>
      </div>
      <div class="cr-field">
        <label>Detail Level</label>
        <select name="dlv" class="form-select form-select-sm">
          <option value="cat"    <?= $detailLevel === 'cat'    ? 'selected' : '' ?>>Categories Only</option>
          <option value="subcat" <?= $detailLevel === 'subcat' ? 'selected' : '' ?>>+Subcategories</option>
          <option value="txn"    <?= $detailLevel === 'txn'    ? 'selected' : '' ?>>+Transactions</option>
        </select>
      </div>
      <div class="cr-field">
        <label>Time Columns</label>
        <select name="dti" class="form-select form-select-sm">
          <option value="none"    <?= $detailTime === 'none'    ? 'selected' : '' ?>>Total Only</option>
          <option value="month"   <?= $detailTime === 'month'   ? 'selected' : '' ?>>Monthly</option>
          <option value="quarter" <?= $detailTime === 'quarter' ? 'selected' : '' ?>>Quarterly</option>
          <option value="year"    <?= $detailTime === 'year'    ? 'selected' : '' ?>>Yearly</option>
        </select>
      </div>
      <div class="cr-field">
        <label>Sort</label>
        <select name="dso" class="form-select form-select-sm">
          <option value="total" <?= $detailSort === 'total' ? 'selected' : '' ?>>By Total</option>
          <option value="alpha" <?= $detailSort === 'alpha' ? 'selected' : '' ?>>Alphabetical</option>
        </select>
      </div>
    </div>

    <!-- Row 1c: TxnList mode params -->
    <div class="cr-row cr-mode-row cr-mode-txnlist">
      <div class="cr-field">
        <label>Sort By</label>
        <select name="tsrt" class="form-select form-select-sm">
          <option value="date"     <?= $txnSort === 'date'     ? 'selected' : '' ?>>Date</option>
          <option value="payee"    <?= $txnSort === 'payee'    ? 'selected' : '' ?>>Payee</option>
          <option value="amount"   <?= $txnSort === 'amount'   ? 'selected' : '' ?>>Amount</option>
          <option value="category" <?= $txnSort === 'category' ? 'selected' : '' ?>>Category</option>
          <option value="account"  <?= $txnSort === 'account'  ? 'selected' : '' ?>>Account</option>
        </select>
      </div>
      <div class="cr-field">
        <label>Direction</label>
        <select name="tdir" class="form-select form-select-sm">
          <option value="desc" <?= $txnDir === 'desc' ? 'selected' : '' ?>>Newest First</option>
          <option value="asc"  <?= $txnDir === 'asc'  ? 'selected' : '' ?>>Oldest First</option>
        </select>
      </div>
      <div class="cr-field">
        <label>Row Limit</label>
        <select name="tlim" class="form-select form-select-sm">
          <?php foreach ([100, 250, 500, 1000, 2000] as $n): ?>
          <option value="<?= $n ?>" <?= $txnLimit === $n ? 'selected' : '' ?>><?= number_format($n) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cr-field cr-field-lg">
        <label>Columns</label>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach (['date'=>'Date','payee'=>'Payee','account'=>'Account','category'=>'Category','subcategory'=>'Subcategory','amount'=>'Amount','memo'=>'Memo','cleared'=>'Cleared'] as $cv => $cl): ?>
          <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="tcols[]"
                   value="<?= $cv ?>" id="txncol_<?= $cv ?>"
                   <?= in_array($cv, $txnCols) ? 'checked' : '' ?>>
            <label class="form-check-label" for="txncol_<?= $cv ?>"><?= $cl ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Row 2: Date range + quick ranges -->
    <div class="cr-row" style="display:flex">
      <div class="cr-field">
        <label>Date From</label>
        <input type="date" name="start" class="form-control form-control-sm" value="<?= h($startDate) ?>">
      </div>
      <div class="cr-field">
        <label>Date To</label>
        <div class="d-flex gap-1 align-items-center">
          <input type="date" id="crEndDisplay" class="form-control form-control-sm"
                 value="<?= h($endDate) ?>"<?= $endIsToday ? ' disabled' : '' ?>>
          <input type="hidden" name="end" id="crEndValue"
                 value="<?= h($endIsToday ? 'today' : $endDate) ?>">
          <button type="button" id="crTodayBtn"
                  class="btn btn-sm <?= $endIsToday ? 'btn-primary' : 'btn-outline-secondary' ?>"
                  title="Always use today's date" onclick="crToggleToday()">Today</button>
        </div>
      </div>
      <div class="cr-field cr-field-btns cr-quick-ranges">
        <label>Quick Range</label>
        <div class="d-flex flex-wrap gap-1">
          <?php
          $qr = [
            'This Month' => [date('Y-m-01'), date('Y-m-t')],
            'Last Month' => [date('Y-m-01',strtotime('first day of last month')), date('Y-m-t',strtotime('last day of last month'))],
            'This Year'  => [date('Y').'-01-01', date('Y').'-12-31'],
            'Last Year'  => [(date('Y')-1).'-01-01', (date('Y')-1).'-12-31'],
            'Last 90d'   => [date('Y-m-d',strtotime('-89 days')), date('Y-m-d')],
            'All'        => [$minTxnDate, 'today'],
          ];
          foreach ($qr as $lbl => [$s, $e]):
          ?>
          <button type="button" class="btn btn-sm btn-outline-secondary cr-qr-btn"
                  data-start="<?= $s ?>" data-end="<?= $e ?>"><?= $lbl ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Row 3: Accounts, Transaction Types, Categories, Cleared Status -->
    <div class="cr-row" style="display:flex">
      <div class="cr-field">
        <label>Accounts</label>
        <?php if (!empty($crAcctTypes)): ?>
        <div class="d-flex flex-wrap gap-1 mb-1" id="crAcctTypeBtns">
          <?php foreach ($crAcctTypes as $__dt): ?>
          <button type="button" class="btn btn-outline-secondary cr-acct-type-btn"
                  data-type="<?= h($__dt) ?>"
                  style="font-size:.7rem;padding:1px 7px;line-height:1.6"><?= h($__dt) ?></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
          $__acctByType = [];
          foreach ($allAccounts as $__a) {
              if ($__a['type'] === 'investment-cash' || !empty($__a['is_investment_cash'])) continue;
              $__adt = ($__a['type'] === 'Investment' && !empty($__a['is_retirement'])) ? 'Retirement' : $__a['type'];
              $__acctByType[$__adt][] = $__a;
          }
          $__acctGrouped = [];
          foreach ($crAcctTypes as $__t) {
              if (isset($__acctByType[$__t])) $__acctGrouped[$__t] = $__acctByType[$__t];
          }
          $__acctSel = count($acctFilter);
        ?>
        <div class="cr-chk-select" id="crAcctPicker">
          <button type="button" class="cr-chk-select-btn" id="crAcctPickerBtn">
            <span id="crAcctPickerSummary"><?= $__acctSel ? $__acctSel.' account'.($__acctSel>1?'s':'').' selected' : 'All accounts' ?></span>
            <i class="bi bi-chevron-down"></i>
          </button>
          <div class="cr-chk-select-dropdown" id="crAcctDropdown">
            <?php foreach ($__acctGrouped as $__adt => $__accts): ?>
            <div class="cr-chk-group">
              <div class="cr-chk-group-hdr">
                <span><?= h($__adt) ?></span>
                <button type="button" class="cr-chk-grp-toggle">All</button>
              </div>
              <?php foreach ($__accts as $__a): ?>
              <label class="cr-chk-item">
                <input type="checkbox" name="acct[]" value="<?= $__a['id'] ?>"
                       data-type="<?= h($__adt) ?>"
                       <?= in_array($__a['id'], $acctFilter) ? 'checked' : '' ?>>
                <span><?= h(accountDisplayName($__a)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <small class="text-muted">Blank = all accounts</small>
      </div>
      <div class="cr-field">
        <label>Transaction Types</label>
        <?php foreach (['withdrawal'=>'Withdrawals','deposit'=>'Deposits','transfer'=>'Transfers','investment'=>'Investment'] as $tv => $tl): ?>
        <div class="form-check form-check-sm">
          <input class="form-check-input" type="checkbox" name="types[]"
                 value="<?= $tv ?>" id="chk_<?= $tv ?>"
                 <?= in_array($tv, $typeFilter) ? 'checked' : '' ?>>
          <label class="form-check-label" for="chk_<?= $tv ?>"><?= $tl ?></label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="cr-field">
        <label>Categories</label>
        <?php $__catSel = count($catFilter); ?>
        <div class="cr-chk-select" id="crCatPicker">
          <button type="button" class="cr-chk-select-btn" id="crCatPickerBtn">
            <span id="crCatPickerSummary"><?= $__catSel ? $__catSel.' categor'.($__catSel>1?'ies':'y').' selected' : 'All categories' ?></span>
            <i class="bi bi-chevron-down"></i>
          </button>
          <div class="cr-chk-select-dropdown" id="crCatDropdown">
            <?php foreach ($allCats as $__parent): ?>
            <div class="cr-chk-group">
              <div class="cr-chk-group-hdr">
                <label class="cr-chk-item cr-chk-parent">
                  <input type="checkbox" name="cat[]" value="<?= $__parent['id'] ?>"
                         <?= in_array($__parent['id'], $catFilter) ? 'checked' : '' ?>>
                  <span><?= h($__parent['name']) ?></span>
                </label>
                <?php if (!empty($__parent['children'])): ?>
                <button type="button" class="cr-chk-grp-toggle">All</button>
                <?php endif; ?>
              </div>
              <?php foreach ($__parent['children'] as $__ch): ?>
              <label class="cr-chk-item cr-chk-child">
                <input type="checkbox" name="cat[]" value="<?= $__ch['id'] ?>"
                       <?= in_array($__ch['id'], $catFilter) ? 'checked' : '' ?>>
                <span><?= h($__ch['name']) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <small class="text-muted">Blank = all categories</small>
      </div>
      <div class="cr-field">
        <label>Cleared Status</label>
        <select name="cleared" class="form-select form-select-sm">
          <option value="all"       <?= $clearedFilter === 'all'       ? 'selected' : '' ?>>All transactions</option>
          <option value="cleared"   <?= $clearedFilter === 'cleared'   ? 'selected' : '' ?>>Cleared &amp; reconciled only</option>
          <option value="uncleared" <?= $clearedFilter === 'uncleared' ? 'selected' : '' ?>>Uncleared only</option>
        </select>
      </div>
    </div>

    <!-- Row 4a: Summary — Payee, Amount, Top N, Chart, Delta -->
    <div class="cr-row cr-mode-row cr-mode-summary">
      <div class="cr-field cr-field-lg">
        <label>Payee Contains</label>
        <input type="text" name="payee" class="form-control form-control-sm"
               placeholder="e.g. Amazon" value="<?= h($payeeFilter) ?>">
      </div>
      <div class="cr-field">
        <label>Amount Range</label>
        <div class="d-flex gap-1 align-items-center">
          <input type="number" name="amt_min" class="form-control form-control-sm"
                 placeholder="Min $" min="0" step="0.01"
                 value="<?= $amtMin !== null ? h((string)$amtMin) : '' ?>">
          <span class="text-muted">–</span>
          <input type="number" name="amt_max" class="form-control form-control-sm"
                 placeholder="Max $" min="0" step="0.01"
                 value="<?= $amtMax !== null ? h((string)$amtMax) : '' ?>">
        </div>
        <small class="text-muted">Applies to absolute transaction amount</small>
      </div>
      <div class="cr-field">
        <label>Limit Rows</label>
        <select name="top" class="form-select form-select-sm">
          <?php foreach ([10=>'Top 10',25=>'Top 25',50=>'Top 50',100=>'Top 100',0=>'All Rows'] as $n => $lbl): ?>
          <option value="<?= $n ?>" <?= $topN === $n ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Excess rows roll into "Other"</small>
        <div class="form-check form-check-sm mt-2">
          <input class="form-check-input" type="checkbox" name="delta" value="1"
                 id="chkDelta" <?= $showDelta ? 'checked' : '' ?>>
          <label class="form-check-label" for="chkDelta">Show Δ% vs prior period
            <span class="text-muted">(time rows only)</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Row 4b: Detail + TxnList — Payee + Amount only -->
    <div class="cr-row cr-mode-row cr-mode-detail cr-mode-txnlist">
      <div class="cr-field cr-field-lg">
        <label>Payee Contains</label>
        <input type="text" name="payee2" class="form-control form-control-sm cr-payee-mirror"
               placeholder="e.g. Amazon" value="<?= h($payeeFilter) ?>">
      </div>
      <div class="cr-field">
        <label>Amount Range</label>
        <div class="d-flex gap-1 align-items-center">
          <input type="number" name="amt_min2" class="form-control form-control-sm cr-amtmin-mirror"
                 placeholder="Min $" min="0" step="0.01"
                 value="<?= $amtMin !== null ? h((string)$amtMin) : '' ?>">
          <span class="text-muted">–</span>
          <input type="number" name="amt_max2" class="form-control form-control-sm cr-amtmax-mirror"
                 placeholder="Max $" min="0" step="0.01"
                 value="<?= $amtMax !== null ? h((string)$amtMax) : '' ?>">
        </div>
        <small class="text-muted">Applies to absolute split amount</small>
      </div>
    </div>

    <!-- Submit row -->
    <div class="cr-row cr-row-submit" style="display:flex">
      <button type="submit" class="btn btn-primary" id="btnRunReport">
        <i class="bi bi-play-fill"></i> Run Report
      </button>
      <a href="<?= BASE_PATH ?>/reports/custom" class="btn btn-outline-secondary ms-2">
        <i class="bi bi-x-lg"></i> Reset
      </a>
      <?php if ($hasRun): ?>
      <a href="<?= h($csvUrl) ?>" class="btn btn-outline-success ms-auto" id="btnCsvDl">
        <i class="bi bi-filetype-csv"></i> Download CSV
      </a>
      <button type="button" class="btn btn-outline-success ms-2" onclick="exportXlsx()">
        <i class="bi bi-file-earmark-spreadsheet"></i> Download XLSX
      </button>
      <button type="button" class="btn btn-outline-secondary ms-2" onclick="copyPermalink(this)" title="Copy link to this report">
        <i class="bi bi-link-45deg"></i> Copy Link
      </button>
      <?php endif; ?>
    </div>

  </form>
</div>

<?php if ($hasRun): ?>

<?php if ($queryRows === 0): ?>
  <div class="search-no-results">
    <i class="bi bi-inbox"></i>
    <p>No data matched the selected filters.</p>
  </div>

<?php elseif ($rptType === 'summary'): ?>

<!-- ── SUMMARY RESULTS ──────────────────────────────────────────── -->
<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label"><?= h($metricLbl) ?></div>
    <div class="tile-value"><?= $isMoney ? formatMoney($grandTotal) : number_format($grandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Row Groups</div>
    <div class="tile-value"><?= count($rowLabels) ?></div>
  </div>
  <?php if ($colDim !== 'none'): ?>
  <div class="report-tile tile-neutral">
    <div class="tile-label"><?= h($DIMS[$colDim]['label']) ?> Columns</div>
    <div class="tile-value"><?= count($colLabels) ?></div>
  </div>
  <?php endif; ?>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Date Range</div>
    <div class="tile-value" style="font-size:.95rem"><?= formatDate($startDate) ?> – <?= formatDate($endDate) ?></div>
  </div>
</div>

<div class="cr-table-wrap">
  <table class="report-table cr-pivot-table" id="crPivotTable">
    <thead>
      <tr>
        <th><?= h($DIMS[$rowDim]['label']) ?></th>
        <?php if ($colDim === 'none'): ?>
          <th class="text-end"><?= h($metricLbl) ?></th>
          <th class="text-end">%</th>
          <?php if ($showDelta): ?><th class="text-end">Δ vs Prior</th><?php endif; ?>
        <?php else: ?>
          <?php foreach ($colLabels as $cl): ?>
          <th class="text-end"><?= h($cl) ?></th>
          <?php endforeach; ?>
          <th class="text-end cr-total-col">Total</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rowLabels as $rl):
        $rv  = $rowTotals[$rl] ?? 0;
        $pct = $grandTotal != 0 ? $rv / $grandTotal * 100 : 0;
      ?>
      <tr>
        <td class="cr-row-label"><?= h($rl) ?></td>
        <?php if ($colDim === 'none'): ?>
          <td class="text-end <?= ($metric === 'sum_net' && $rv < 0) ? 'amount-debit' : '' ?>">
            <?= $isMoney ? formatMoney($rv) : number_format($rv) ?>
          </td>
          <td class="text-end text-muted"><?= round($pct, 1) ?>%</td>
          <?php if ($showDelta):
            $d    = $deltaData[$rl] ?? null;
            $dCls = $d === null ? '' : ($d >= 0 ? 'amount-credit' : 'amount-debit');
          ?>
          <td class="text-end <?= $dCls ?>">
            <?= $d !== null ? ($d >= 0 ? '+' : '') . number_format($d, 1) . '%' : '<span class="text-muted">—</span>' ?>
          </td>
          <?php endif; ?>
        <?php else: ?>
          <?php foreach ($colLabels as $cl):
            $cv = $pivotData[$rl][$cl] ?? 0;
          ?>
          <td class="text-end <?= $cv == 0 ? 'cr-zero' : '' ?> <?= ($metric === 'sum_net' && $cv < 0) ? 'amount-debit' : '' ?>">
            <?= $cv != 0 ? ($isMoney ? formatMoney($cv) : number_format($cv)) : '' ?>
          </td>
          <?php endforeach; ?>
          <td class="text-end fw-bold cr-total-col <?= ($metric === 'sum_net' && $rv < 0) ? 'amount-debit' : '' ?>">
            <?= $isMoney ? formatMoney($rv) : number_format($rv) ?>
          </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total</td>
        <?php if ($colDim === 'none'): ?>
          <td class="text-end"><?= $isMoney ? formatMoney($grandTotal) : number_format($grandTotal) ?></td>
          <td class="text-end">100%</td>
          <?php if ($showDelta): ?><td></td><?php endif; ?>
        <?php else: ?>
          <?php foreach ($colLabels as $cl): $ct = $colTotals[$cl] ?? 0; ?>
          <td class="text-end"><?= $isMoney ? formatMoney($ct) : number_format($ct) ?></td>
          <?php endforeach; ?>
          <td class="text-end cr-total-col"><?= $isMoney ? formatMoney($grandTotal) : number_format($grandTotal) ?></td>
        <?php endif; ?>
      </tr>
    </tfoot>
  </table>
</div>
<?php if ($topN > 0 && $queryRows >= $topN): ?>
<p class="text-muted small mt-1"><i class="bi bi-info-circle"></i>
  Showing top <?= $topN ?> rows by total. Change "Limit Rows" in the builder to see more.</p>
<?php endif; ?>

<?php elseif ($rptType === 'detail'): ?>

<!-- ── DETAIL RESULTS ───────────────────────────────────────────── -->
<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total</div>
    <div class="tile-value"><?= formatMoney($detailGrandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Categories</div>
    <div class="tile-value"><?= count($detailTree) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Date Range</div>
    <div class="tile-value" style="font-size:.95rem"><?= formatDate($startDate) ?> – <?= formatDate($endDate) ?></div>
  </div>
</div>


<div class="cr-table-wrap">
  <table class="report-table cr-det-table" id="crDetailTable">
    <thead>
      <tr>
        <th>Category / Description</th>
        <?php foreach ($detailTimeCols as $pk => $plbl): ?>
        <th class="cr-det-amt"><?= h($plbl) ?></th>
        <?php endforeach; ?>
        <?php if ($detailTime !== 'none'): ?><th class="cr-det-amt cr-total-col">Total</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($detailTree as $catId => $cat): ?>
      <!-- Category row -->
      <tr class="cr-det-cat-row">
        <td>
          <span class="cr-det-label-name"><?= h($cat['name']) ?><?php if ($detailCatType === 'both'): ?>
            <span class="cr-type-badge <?= $cat['type'] === 'expense' ? 'cr-type-badge-exp' : 'cr-type-badge-inc' ?>">
              <?= $cat['type'] === 'expense' ? 'exp' : 'inc' ?>
            </span><?php endif; ?>
          </span>
        </td>
        <?php foreach ($detailTimeCols as $pk => $plbl):
          $pv = $cat['periods'][$pk] ?? 0;
        ?>
        <td class="cr-det-amt <?= $pv == 0 ? 'cr-zero' : '' ?>"><?= $pv > 0 ? formatMoney($pv) : '' ?></td>
        <?php endforeach; ?>
        <?php if ($detailTime !== 'none'): ?><td class="cr-det-amt cr-total-col"><?= formatMoney($cat['total']) ?></td><?php endif; ?>
      </tr>
      <?php if ($detailLevel !== 'cat'): ?>
        <?php foreach ($cat['subs'] as $subKey => $sub): ?>
        <?php if ($sub['name'] !== null): ?>
        <!-- Sub row -->
        <tr class="cr-det-sub-row">
          <td style="padding-left:20px">
            <span class="cr-det-label-name"><?= h($cat['name'] . ' - ' . $sub['name']) ?></span>
          </td>
          <?php foreach ($detailTimeCols as $pk => $plbl):
            $sv = $sub['periods'][$pk] ?? 0;
          ?>
          <td class="cr-det-amt <?= $sv == 0 ? 'cr-zero' : '' ?>"><?= $sv > 0 ? formatMoney($sv) : '' ?></td>
          <?php endforeach; ?>
          <?php if ($detailTime !== 'none'): ?><td class="cr-det-amt cr-total-col"><?= formatMoney($sub['total']) ?></td><?php endif; ?>
        </tr>
        <?php endif; ?>
        <?php if ($detailLevel === 'txn'): ?>
          <?php foreach ($sub['txns'] as $txn):
            $indent = $sub['name'] !== null ? 46 : 28;
          ?>
          <tr class="cr-det-txn-row">
            <td style="padding-left:<?= $indent ?>px">
              <span class="cr-det-label-name">
                <?= date('m/d', strtotime($txn['date'])) ?>
                &nbsp;<?= h($txn['payee']) ?>
              </span>
              <span class="cr-det-label-meta"><?= h($txn['account_name']) ?><?= $txn['memo'] ? ' — ' . h($txn['memo']) : '' ?></span>
            </td>
            <?php foreach ($detailTimeCols as $pk => $plbl): ?>
            <td class="cr-det-amt"><?= $pk === $txn['period_key'] ? formatMoney($txn['amount']) : '' ?></td>
            <?php endforeach; ?>
            <?php if ($detailTime !== 'none'): ?><td class="cr-det-amt cr-total-col"><?= formatMoney($txn['amount']) ?></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="cr-det-total-row">
        <td><strong>Grand Total</strong></td>
        <?php foreach ($detailTimeCols as $pk => $plbl):
          $gv = $detailGrandPeriods[$pk] ?? 0;
        ?>
        <td class="cr-det-amt"><?= $gv > 0 ? formatMoney($gv) : '' ?></td>
        <?php endforeach; ?>
        <?php if ($detailTime !== 'none'): ?><td class="cr-det-amt cr-total-col"><?= formatMoney($detailGrandTotal) ?></td><?php endif; ?>
      </tr>
    </tfoot>
  </table>
</div>

<?php elseif ($rptType === 'txnlist'): ?>

<!-- ── TXNLIST RESULTS ──────────────────────────────────────────── -->
<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Transactions Shown</div>
    <div class="tile-value"><?= number_format($queryRows) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Date Range</div>
    <div class="tile-value" style="font-size:.95rem"><?= formatDate($startDate) ?> – <?= formatDate($endDate) ?></div>
  </div>
</div>

<?php if ($queryRows >= $txnLimit): ?>
<p class="text-muted small mb-2">
  <i class="bi bi-info-circle"></i>
  Showing first <?= number_format($txnLimit) ?> rows — narrow your filters to see more.
</p>
<?php endif; ?>

<div class="cr-table-wrap">
  <table class="report-table cr-txn-table" id="crTxnTable">
    <thead>
      <tr>
        <?php foreach ($txnCols as $col): ?>
        <th <?= in_array($col, ['amount']) ? 'class="text-end"' : '' ?>>
          <?= match($col) {
            'date'        => 'Date',
            'payee'       => 'Payee',
            'account'     => 'Account',
            'category'    => 'Category',
            'subcategory' => 'Subcategory',
            'amount'      => 'Amount',
            'memo'        => 'Memo',
            'cleared'     => 'Cleared',
            default       => ucfirst($col),
          } ?>
        </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($txnRows as $r): ?>
      <tr>
        <?php foreach ($txnCols as $col): ?>
        <?php
          $amt = (float)$r['split_amount'];
          $isDebit = $amt < 0;
        ?>
        <?php if ($col === 'date'): ?>
          <td><?= date('m/d/Y', strtotime($r['transaction_date'])) ?></td>
        <?php elseif ($col === 'payee'): ?>
          <td><?= h($r['payee']) ?></td>
        <?php elseif ($col === 'account'): ?>
          <td><?= h($r['account_name']) ?></td>
        <?php elseif ($col === 'category'): ?>
          <?php
            $catDisplay = $r['category'] ?? '';
            if (!empty($r['subcategory'])) $catDisplay .= ' - ' . $r['subcategory'];
          ?>
          <td><?= h($catDisplay) ?></td>
        <?php elseif ($col === 'subcategory'): ?>
          <td><?= h($r['subcategory'] ?? '') ?></td>
        <?php elseif ($col === 'amount'): ?>
          <td class="text-end <?= $isDebit ? 'cr-txn-debit' : 'cr-txn-credit' ?>">
            <?= formatMoney(abs($amt)) ?>
          </td>
        <?php elseif ($col === 'memo'): ?>
          <td><?= h($r['split_memo'] ?: $r['txn_memo']) ?></td>
        <?php elseif ($col === 'cleared'): ?>
          <td><?= h($r['cleared_status']) ?></td>
        <?php endif; ?>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; // rptType results ?>

<?php if ($hasRun && $queryRows > 0): ?>
<!-- ── Graph Builder Panel ──────────────────────────────────── -->
<div id="crGraphPanel" class="cr-graph-panel" style="display:none">
  <div class="cr-graph-panel-hdr">
    <span><i class="bi bi-bar-chart-line"></i> Graph Builder</span>
    <button type="button" class="btn btn-sm btn-outline-light btn-sm py-0" onclick="CRG.toggle(document.getElementById('btnCreateGraph'))">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <div class="cr-graph-config">
    <!-- Chart type -->
    <div class="cr-graph-row">
      <div class="cr-graph-lbl">Chart Type</div>
      <div class="cr-graph-ctrl" id="crGrpTypeWrap">
        <?php foreach (['bar'=>['bi-bar-chart','Bar'],'bar-h'=>['bi-bar-chart-horizontal','Horiz.'],'line'=>['bi-graph-up','Line'],'area'=>['bi-graph-up-arrow','Area'],'pie'=>['bi-pie-chart','Pie'],'doughnut'=>['bi-circle','Donut']] as $ct=>[$ico,$lbl]): ?>
        <button type="button" class="cr-graph-type-btn <?= $ct === 'bar' ? 'active' : '' ?>"
                data-type="<?= $ct ?>" onclick="CRG.setType('<?= $ct ?>', this)">
          <i class="bi <?= $ico ?>"></i> <?= $lbl ?>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <!-- X Axis / Group -->
    <div class="cr-graph-row">
      <div class="cr-graph-lbl">X Axis / Group</div>
      <div class="cr-graph-ctrl">
        <select id="crGrpXAxis" class="form-select form-select-sm" style="width:auto" onchange="CRG.onXChange()"></select>
      </div>
    </div>
    <!-- Series (hidden when single-series) -->
    <div class="cr-graph-row" id="crGrpSeriesRow" style="display:none">
      <div class="cr-graph-lbl">Series</div>
      <div class="cr-graph-ctrl flex-column align-items-start gap-2">
        <div id="crGrpSeriesWrap" class="cr-grp-series-list"></div>
        <small class="text-muted">Check series to include in the chart</small>
      </div>
    </div>
    <!-- Max Items -->
    <div class="cr-graph-row">
      <div class="cr-graph-lbl">Max Items</div>
      <div class="cr-graph-ctrl">
        <select id="crGrpMaxN" class="form-select form-select-sm" style="width:auto">
          <option value="10">10</option>
          <option value="15">15</option>
          <option value="20" selected>20</option>
          <option value="50">50</option>
          <option value="999">All</option>
        </select>
        <small class="text-muted">Limit number of X-axis points (or pie slices)</small>
      </div>
    </div>
    <!-- Options -->
    <div class="cr-graph-row">
      <div class="cr-graph-lbl">Options</div>
      <div class="cr-graph-ctrl flex-wrap gap-3">
        <label class="d-flex align-items-center gap-1 mb-0" style="font-size:.82rem">
          <input type="checkbox" id="crGrpStacked"> Stacked
        </label>
        <div class="d-flex align-items-center gap-2">
          <label style="font-size:.82rem;white-space:nowrap">Title:</label>
          <input type="text" id="crGrpTitle" class="form-control form-control-sm" style="width:200px" placeholder="(optional)">
        </div>
      </div>
    </div>
    <!-- Draw -->
    <div class="cr-graph-row">
      <div class="cr-graph-lbl"></div>
      <div class="cr-graph-ctrl">
        <button type="button" class="btn btn-sm btn-primary" onclick="CRG.draw()">
          <i class="bi bi-bar-chart-line"></i> Draw Chart
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="crGrpSaveBtn"
                style="display:none" onclick="CRG.saveImage()">
          <i class="bi bi-download"></i> Save Image
        </button>
      </div>
    </div>
  </div>
  <div id="crGraphCanvas" style="display:none" class="cr-graph-canvas-wrap">
    <canvas id="crGraphChart"></canvas>
  </div>
</div>
<?php endif; ?>

<?php endif; // hasRun ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- SheetJS for XLSX export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// ── Builder mode toggle ───────────────────────────────────────
function updateBuilderMode() {
  const type = document.getElementById('selRptType').value;
  document.querySelectorAll('.cr-mode-row').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.cr-mode-' + type).forEach(el => el.style.display = 'flex');
}
document.getElementById('selRptType').addEventListener('change', updateBuilderMode);
updateBuilderMode(); // init on load

// ── Builder collapse ──────────────────────────────────────────
function toggleBuilder() {
  const wrap  = document.getElementById('crBuilder');
  const caret = document.getElementById('crCaret');
  const open  = !wrap.classList.contains('cr-builder-collapsed');
  if (open) {
    wrap.classList.add('cr-builder-collapsed');
    caret.classList.replace('bi-chevron-down','bi-chevron-up');
  } else {
    wrap.classList.remove('cr-builder-collapsed');
    caret.classList.replace('bi-chevron-up','bi-chevron-down');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const wrap = document.getElementById('crBuilder');
  if (wrap && wrap.classList.contains('cr-builder-collapsed')) {
    const caret = document.getElementById('crCaret');
    if (caret) caret.classList.replace('bi-chevron-down','bi-chevron-up');
  }
});

// ── Sync mirror payee/amount fields ──────────────────────────
// Row 4b mirrors row 4a values so either can be used regardless of mode
(function() {
  function syncField(srcName, mirrorClass) {
    const src = document.querySelector('[name="' + srcName + '"]');
    const mirrors = document.querySelectorAll('.' + mirrorClass);
    if (!src) return;
    mirrors.forEach(m => {
      m.value = src.value;
      m.addEventListener('input', () => { src.value = m.value; });
    });
    src.addEventListener('input', () => {
      mirrors.forEach(m => { if (m !== document.activeElement) m.value = src.value; });
    });
  }
  syncField('payee',   'cr-payee-mirror');
  syncField('amt_min', 'cr-amtmin-mirror');
  syncField('amt_max', 'cr-amtmax-mirror');
})();

// ── Today toggle for Date To ─────────────────────────────────
window.crToggleToday = function() {
  const display  = document.getElementById('crEndDisplay');
  const hidden   = document.getElementById('crEndValue');
  const btn      = document.getElementById('crTodayBtn');
  const isToday  = hidden.value === 'today';
  if (isToday) {
    hidden.value     = display.value;
    display.disabled = false;
    btn.classList.replace('btn-primary', 'btn-outline-secondary');
  } else {
    display.disabled = true;
    hidden.value = 'today';
    btn.classList.replace('btn-outline-secondary', 'btn-primary');
  }
};
document.getElementById('crEndDisplay').addEventListener('change', function() {
  const hidden = document.getElementById('crEndValue');
  if (hidden.value !== 'today') hidden.value = this.value;
});

// ── Quick-range buttons ───────────────────────────────────────
document.querySelectorAll('.cr-qr-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelector('[name="start"]').value = btn.dataset.start;
    const endVal  = btn.dataset.end;
    const display = document.getElementById('crEndDisplay');
    const hidden  = document.getElementById('crEndValue');
    const todayBtn = document.getElementById('crTodayBtn');
    if (endVal === 'today') {
      display.disabled = true;
      hidden.value = 'today';
      todayBtn.classList.replace('btn-outline-secondary', 'btn-primary');
    } else {
      display.value    = endVal;
      display.disabled = false;
      hidden.value     = endVal;
      todayBtn.classList.replace('btn-primary', 'btn-outline-secondary');
    }
  });
});

// ── Checkbox multi-select dropdowns (Accounts + Categories) ──
(function() {
  function toggleDrop(pickerId) {
    const picker = document.getElementById(pickerId);
    if (!picker) return;
    const isOpen = picker.classList.toggle('open');
    if (isOpen) {
      document.querySelectorAll('.cr-chk-select').forEach(p => {
        if (p.id !== pickerId) p.classList.remove('open');
      });
    }
  }
  document.addEventListener('click', function(e) {
    if (!e.target.closest('.cr-chk-select')) {
      document.querySelectorAll('.cr-chk-select').forEach(p => p.classList.remove('open'));
    }
  });

  const acctBtn = document.getElementById('crAcctPickerBtn');
  const catBtn  = document.getElementById('crCatPickerBtn');
  if (acctBtn) acctBtn.addEventListener('click', e => { e.stopPropagation(); toggleDrop('crAcctPicker'); });
  if (catBtn)  catBtn.addEventListener('click',  e => { e.stopPropagation(); toggleDrop('crCatPicker');  });

  function updateAcctSummary() {
    const n   = document.querySelectorAll('#crAcctDropdown input[type=checkbox]:checked').length;
    const lbl = document.getElementById('crAcctPickerSummary');
    if (lbl) lbl.textContent = n === 0 ? 'All accounts' : n + ' account' + (n > 1 ? 's' : '') + ' selected';
  }
  function updateCatSummary() {
    const n   = document.querySelectorAll('#crCatDropdown input[type=checkbox]:checked').length;
    const lbl = document.getElementById('crCatPickerSummary');
    if (lbl) lbl.textContent = n === 0 ? 'All categories' : n + ' categor' + (n > 1 ? 'ies' : 'y') + ' selected';
  }

  // Group "All" toggle buttons
  document.querySelectorAll('.cr-chk-grp-toggle').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const group = this.closest('.cr-chk-group');
      // For categories: toggle only children (not the parent checkbox in the header)
      const isCatGroup = !!group.closest('#crCatDropdown');
      const boxes = isCatGroup
        ? Array.from(group.querySelectorAll('.cr-chk-child input[type=checkbox]'))
        : Array.from(group.querySelectorAll('input[type=checkbox]'));
      const allChecked = boxes.length > 0 && boxes.every(b => b.checked);
      boxes.forEach(b => b.checked = !allChecked);
      updateAcctSummary();
      updateCatSummary();
      refreshAcctTypeBtns();
    });
  });

  const acctPicker = document.getElementById('crAcctPicker');
  const catPicker  = document.getElementById('crCatPicker');
  if (acctPicker) acctPicker.addEventListener('change', () => { updateAcctSummary(); refreshAcctTypeBtns(); });
  if (catPicker)  catPicker.addEventListener('change',  updateCatSummary);

  // ── Account type quick-filter buttons ────────────────────
  const acctTypeBtns = document.querySelectorAll('.cr-acct-type-btn');
  function checkboxesOfType(type) {
    return Array.from(document.querySelectorAll(
      '#crAcctDropdown input[type=checkbox][data-type="' + CSS.escape(type) + '"]'
    ));
  }
  function refreshAcctTypeBtns() {
    acctTypeBtns.forEach(btn => {
      const boxes  = checkboxesOfType(btn.dataset.type);
      const allSel = boxes.length > 0 && boxes.every(b => b.checked);
      btn.classList.toggle('btn-secondary',         allSel);
      btn.classList.toggle('btn-outline-secondary', !allSel);
    });
  }
  acctTypeBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      const boxes  = checkboxesOfType(this.dataset.type);
      const allSel = boxes.every(b => b.checked);
      boxes.forEach(b => b.checked = !allSel);
      updateAcctSummary();
      refreshAcctTypeBtns();
    });
  });
  refreshAcctTypeBtns();
})();

// ── Prevent identical row/col (summary) ──────────────────────
document.querySelectorAll('.cr-select-dim').forEach(sel => {
  sel.addEventListener('change', () => {
    const row = document.getElementById('selRow').value;
    const col = document.getElementById('selCol');
    if (col && col.value !== 'none' && col.value === row) {
      col.value = 'none';
    }
  });
});

// ── Graph Builder ─────────────────────────────────────────────
<?php if ($hasRun && $queryRows > 0): ?>
const CRG = (function(){
  const DATA    = <?= json_encode($crReportData) ?>;
  const PALETTE = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac','#86bcb6','#d37295'];

  let chartInst = null;
  let cfgType   = 'bar';
  let cfgXAxis  = null;
  let cfgSeries = null;

  // ── Panel open/close ────────────────────────────
  function toggle(btn) {
    const panel = document.getElementById('crGraphPanel');
    const open  = panel.style.display !== 'none';
    if (open) {
      panel.style.display = 'none';
      if (btn) { btn.innerHTML = '<i class="bi bi-bar-chart-line"></i> Create Graph'; btn.classList.remove('btn-primary'); btn.classList.add('btn-outline-primary'); }
    } else {
      panel.style.display = 'block';
      if (btn) { btn.innerHTML = '<i class="bi bi-x-lg"></i> Close Graph'; btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary'); }
      if (!cfgXAxis) buildConfigUI();
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  // ── Build config UI ─────────────────────────────
  function buildConfigUI() {
    const opts = xAxisOptions();
    const sel  = document.getElementById('crGrpXAxis');
    sel.innerHTML = opts.map(o => `<option value="${o.v}">${h(o.l)}</option>`).join('');
    cfgXAxis = opts[0]?.v ?? null;
    refreshSeriesUI();
  }

  function xAxisOptions() {
    if (DATA.mode === 'summary') {
      if (!DATA.hasPivot) return [{ v:'rows', l: DATA.rowDimLabel }];
      return [
        { v:'rows', l: DATA.rowDimLabel + ' on X — ' + DATA.colDimLabel + ' as series' },
        { v:'cols', l: DATA.colDimLabel + ' on X — ' + DATA.rowDimLabel + ' as series' },
      ];
    }
    if (DATA.mode === 'detail') {
      if (!DATA.hasPivot) return [{ v:'cats', l:'Categories' }];
      return [
        { v:'time', l:'Time Periods on X — Categories as series' },
        { v:'cats', l:'Categories on X — Time Periods as series' },
      ];
    }
    if (DATA.mode === 'txnlist') {
      const hasCat = DATA.cols.includes('category');
      const hasPay = DATA.cols.includes('payee');
      const hasAcc = DATA.cols.includes('account');
      const hasAmt = DATA.cols.includes('amount');
      const hasDt  = DATA.cols.includes('date');
      const opts = [];
      if (hasDt) { opts.push({v:'month',l:'Month'},{v:'quarter',l:'Quarter'},{v:'year',l:'Year'}); }
      if (hasCat) opts.push({v:'category',l:'Category'});
      if (hasPay) opts.push({v:'payee',l:'Payee'});
      if (hasAcc) opts.push({v:'account',l:'Account'});
      return opts;
    }
    return [];
  }

  function availableSeries() {
    if (DATA.mode === 'summary' && DATA.hasPivot) {
      return cfgXAxis === 'rows' ? DATA.colLabels : DATA.rowLabels;
    }
    if (DATA.mode === 'detail' && DATA.hasPivot) {
      return cfgXAxis === 'time' ? DATA.cats.map(c => c.name) : Object.values(DATA.timeCols);
    }
    return [];
  }

  function refreshSeriesUI() {
    const serList = availableSeries();
    const row = document.getElementById('crGrpSeriesRow');
    if (!serList.length) { row.style.display = 'none'; cfgSeries = []; return; }
    row.style.display = 'flex';
    const maxDef = 8;
    if (!cfgSeries || !cfgSeries.length) cfgSeries = serList.slice(0, maxDef);
    const wrap = document.getElementById('crGrpSeriesWrap');
    wrap.innerHTML = serList.map(lbl => {
      const chk = cfgSeries.includes(lbl) ? 'checked' : '';
      return `<label class="cr-grp-series-cb"><input type="checkbox" value="${h(lbl)}" ${chk} onchange="CRG.toggleSeries('${h(lbl)}',this.checked)"> ${h(lbl)}</label>`;
    }).join('');
  }

  function h(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Public event handlers ───────────────────────
  function setType(t, btn) {
    cfgType = t;
    document.querySelectorAll('.cr-graph-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }

  function onXChange() {
    cfgXAxis  = document.getElementById('crGrpXAxis').value;
    cfgSeries = null;
    refreshSeriesUI();
  }

  function toggleSeries(lbl, checked) {
    if (checked && !cfgSeries.includes(lbl)) cfgSeries.push(lbl);
    else if (!checked) cfgSeries = cfgSeries.filter(s => s !== lbl);
  }

  // ── Draw ────────────────────────────────────────
  function draw() {
    cfgXAxis = document.getElementById('crGrpXAxis').value;
    const stacked = document.getElementById('crGrpStacked').checked;
    const title   = document.getElementById('crGrpTitle').value.trim();

    const { labels, datasets } = buildData();
    if (!labels.length || !datasets.length) { showToast('No data to chart with the current settings.', 'warning'); return; }

    const wrap = document.getElementById('crGraphCanvas');
    wrap.style.display = 'block';
    document.getElementById('crGrpSaveBtn').style.display = '';

    if (chartInst) { chartInst.destroy(); chartInst = null; }

    const canvas  = document.getElementById('crGraphChart');
    const isPie   = cfgType === 'pie' || cfgType === 'doughnut';
    const isHBar  = cfgType === 'bar-h';
    const isLine  = cfgType === 'line';
    const isArea  = cfgType === 'area';
    const isMoney = DATA.isMoney !== false;

    const fmtVal = v => {
      const n = parseFloat(v);
      return isMoney ? '$' + n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) : n.toLocaleString();
    };

    if (isLine || isArea) {
      datasets.forEach(d => { d.fill = isArea ? 'origin' : false; d.tension = 0.3; d.pointRadius = 3; });
    }
    if (isPie) {
      datasets.forEach(d => { d.backgroundColor = PALETTE.slice(0, labels.length); d.borderColor = '#fff'; d.borderWidth = 2; });
    }

    chartInst = new Chart(canvas, {
      type: isPie ? cfgType : (isLine || isArea ? 'line' : 'bar'),
      data: { labels, datasets },
      options: {
        indexAxis: isHBar ? 'y' : 'x',
        responsive: true,
        interaction: { mode: isPie ? 'nearest' : 'index', intersect: false },
        plugins: {
          legend: { position: isPie ? 'right' : 'top', labels: { boxWidth: 12 }, display: datasets.length > 1 || isPie },
          title:  { display: !!title, text: title, font: { size: 13 } },
          tooltip: {
            callbacks: {
              label: ctx => {
                const v = ctx.parsed?.y ?? ctx.parsed?.x ?? ctx.parsed;
                return ' ' + ctx.dataset.label + ': ' + fmtVal(v);
              }
            }
          }
        },
        scales: isPie ? {} : {
          x: { stacked },
          y: { stacked, ticks: { callback: v => isMoney ? '$' + v.toLocaleString() : v.toLocaleString() } }
        }
      }
    });

    wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ── Build Chart.js data from config ────────────
  function buildData() {
    if (DATA.mode === 'summary') return buildSummary();
    if (DATA.mode === 'detail')  return buildDetail();
    if (DATA.mode === 'txnlist') return buildTxnList();
    return { labels: [], datasets: [] };
  }

  function ds(label, data, colorIdx) {
    const c = PALETTE[colorIdx % PALETTE.length];
    return { label, data, backgroundColor: c, borderColor: c, borderWidth: 1 };
  }

  function buildSummary() {
    const maxN   = parseInt(document.getElementById('crGrpMaxN').value) || 999;
    const isPie  = cfgType === 'pie' || cfgType === 'doughnut';

    if (!DATA.hasPivot) {
      const labels = DATA.rowLabels.slice(0, maxN);
      return { labels, datasets: [ds(DATA.metricLbl, labels.map(l => DATA.values[l] ?? 0), 0)] };
    }

    if (cfgXAxis === 'rows') {
      const labels = DATA.rowLabels.slice(0, maxN);
      if (isPie) {
        return { labels, datasets: [ds(DATA.metricLbl, labels.map(l => DATA.rowTotals[l] ?? 0), 0)] };
      }
      const active = cfgSeries?.length ? cfgSeries : DATA.colLabels;
      return { labels, datasets: active.map((cl, i) => ds(cl, labels.map(rl => DATA.pivot[rl]?.[cl] ?? 0), i)) };
    } else {
      const labels = DATA.colLabels.slice(0, maxN);
      if (isPie) {
        return { labels, datasets: [ds(DATA.metricLbl, labels.map(l => DATA.colTotals[l] ?? 0), 0)] };
      }
      const active = cfgSeries?.length ? cfgSeries : DATA.rowLabels;
      return { labels, datasets: active.map((rl, i) => ds(rl, labels.map(cl => DATA.pivot[rl]?.[cl] ?? 0), i)) };
    }
  }

  function buildDetail() {
    const maxN  = parseInt(document.getElementById('crGrpMaxN').value) || 999;
    const isPie = cfgType === 'pie' || cfgType === 'doughnut';

    if (!DATA.hasPivot || cfgXAxis === 'cats') {
      const cats   = DATA.cats.slice(0, maxN);
      const labels = cats.map(c => c.name);
      if (!DATA.hasPivot || isPie) {
        return { labels, datasets: [ds('Total', cats.map(c => c.total), 0)] };
      }
      // Multiple time periods as series
      const timeKeys  = Object.keys(DATA.timeCols);
      const timeLabels= Object.values(DATA.timeCols);
      const active    = cfgSeries?.length ? cfgSeries : timeLabels.slice(0, 6);
      const sets = timeKeys
        .filter((_, i) => active.includes(timeLabels[i]))
        .map((pk, i) => ds(DATA.timeCols[pk], cats.map(c => c.periods[pk] ?? 0), i));
      return { labels, datasets: sets };
    }

    // X = time periods, series = categories
    const timeKeys  = Object.keys(DATA.timeCols);
    const timeLabels= Object.values(DATA.timeCols);
    const active    = cfgSeries?.length ? cfgSeries : DATA.cats.slice(0, 8).map(c => c.name);
    const activeCats= DATA.cats.filter(c => active.includes(c.name));
    if (isPie) {
      return { labels: activeCats.map(c => c.name), datasets: [ds('Total', activeCats.map(c => c.total), 0)] };
    }
    return { labels: timeLabels, datasets: activeCats.map((cat, i) => ds(cat.name, timeKeys.map(pk => cat.periods[pk] ?? 0), i)) };
  }

  function buildTxnList() {
    const grpBy = cfgXAxis;
    const maxN  = parseInt(document.getElementById('crGrpMaxN').value) || 20;
    const isPie = cfgType === 'pie' || cfgType === 'doughnut';
    const totals = {};

    DATA.rows.forEach(r => {
      let key = '';
      if      (grpBy === 'month')    key = (r.date || '').substring(0, 7);
      else if (grpBy === 'quarter') {
        if (r.date) { const d = new Date(r.date); key = d.getFullYear() + ' Q' + (Math.floor(d.getMonth()/3)+1); }
      }
      else if (grpBy === 'year')     key = (r.date || '').substring(0, 4);
      else if (grpBy === 'category') key = r.category  || '(none)';
      else if (grpBy === 'payee')    key = r.payee     || '(no payee)';
      else if (grpBy === 'account')  key = r.account   || '';
      if (!key) return;
      totals[key] = (totals[key] || 0) + Math.abs(r.amount || 0);
    });

    let sorted = Object.entries(totals);
    if (['month','quarter','year'].includes(grpBy)) sorted.sort((a,b) => a[0].localeCompare(b[0]));
    else sorted.sort((a,b) => b[1]-a[1]);
    sorted = sorted.slice(0, maxN);

    const labels = sorted.map(([k]) => k);
    const vals   = sorted.map(([,v]) => Math.round(v * 100) / 100);
    return { labels, datasets: [ds('Amount', vals, 0)] };
  }

  // ── Save image ──────────────────────────────────
  function saveImage() {
    if (!chartInst) return;
    const a = document.createElement('a');
    a.href = chartInst.toBase64Image();
    a.download = 'chart-' + new Date().toISOString().slice(0,10) + '.png';
    a.click();
  }

  // ── Get current config (for saving alongside a report) ──────────
  function getConfig() {
    if (!chartInst) return null;
    return {
      type:    cfgType,
      xAxis:   cfgXAxis,
      series:  cfgSeries ? [...cfgSeries] : [],
      stacked: document.getElementById('crGrpStacked')?.checked ?? false,
      title:   document.getElementById('crGrpTitle')?.value.trim() ?? '',
    };
  }

  // ── Restore saved config and auto-draw ───────────────────────────
  function restoreAndDraw(cfg) {
    if (!cfg || !DATA) return;
    // Open panel
    const panel = document.getElementById('crGraphPanel');
    if (!panel) return;
    panel.style.display = 'block';
    const openBtn = document.getElementById('btnCreateGraph');
    if (openBtn) {
      openBtn.innerHTML = '<i class="bi bi-x-lg"></i> Close Graph';
      openBtn.classList.remove('btn-outline-primary');
      openBtn.classList.add('btn-primary');
    }
    // Restore type
    cfgType = cfg.type || 'bar';
    document.querySelectorAll('.cr-graph-type-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.type === cfgType);
    });
    // Build axis/series UI, then override with saved values
    cfgXAxis  = cfg.xAxis || null;
    cfgSeries = cfg.series && cfg.series.length ? [...cfg.series] : null;
    buildConfigUI();
    const xSel = document.getElementById('crGrpXAxis');
    if (xSel && cfg.xAxis) { xSel.value = cfg.xAxis; cfgXAxis = xSel.value; }
    refreshSeriesUI();
    // Stacked + title
    const stackedEl = document.getElementById('crGrpStacked');
    if (stackedEl) stackedEl.checked = !!cfg.stacked;
    const titleEl = document.getElementById('crGrpTitle');
    if (titleEl && cfg.title) titleEl.value = cfg.title;
    // Draw
    draw();
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  return { toggle, setType, onXChange, toggleSeries, draw, saveImage, getConfig, restoreAndDraw };
})();
// Expose graph config to the generic save-as handler in report_fav_btn.php
window.__reportGraphConfig = () => CRG.getConfig();
<?php if ($savedGraphConfig): ?>
CRG.restoreAndDraw(<?= json_encode($savedGraphConfig) ?>);
<?php endif; ?>
<?php endif; ?>

// ── Save / Remove custom report ───────────────────────────────
function toggleSaveReport(btn) {
  const saved  = btn.dataset.saved === '1';
  const span   = btn.querySelector('span');
  const icon   = btn.querySelector('i');

  if (!saved) {
    const titleInput = document.querySelector('input[name="title"]');
    const title = (titleInput ? titleInput.value.trim() : '') || btn.dataset.defaultTitle;
    if (!title) {
      showToast('Please enter a Report Title before saving.', 'warning');
      if (titleInput) titleInput.focus();
      return;
    }

    btn.disabled = true;
    const graphCfg = (typeof CRG !== 'undefined') ? CRG.getConfig() : null;
    const body = new URLSearchParams({
      csrf_token: btn.dataset.csrf,
      action: 'add',
      url:    btn.dataset.url,
      title:  title,
      icon:   'bi-sliders2',
      type:   'saved',
    });
    if (graphCfg) body.append('graph_config', JSON.stringify(graphCfg));
    fetch('<?= BASE_PATH ?>/reports/favorite_save', { method: 'POST', body })
      .then(r => r.json())
      .then(json => {
        if (!json.ok) { showToast(json.error || 'Error saving report.', 'error'); btn.disabled = false; return; }
        btn.dataset.saved  = '1';
        btn.dataset.saveId = json.id;
        btn.classList.replace('btn-outline-secondary', 'btn-warning');
        icon.className = 'bi bi-bookmark-fill';
        span.textContent = 'Saved';
        btn.title = 'Remove from Saved Reports';
        btn.disabled = false;
        const u = new URL(window.location.href);
        u.searchParams.set('saved_id', json.id);
        u.searchParams.delete('fav_id');
        history.replaceState(null, '', u.toString());
      })
      .catch((e) => { console.error(e); showToast('Network error.', 'error'); btn.disabled = false; });

  } else {
    btn.disabled = true;
    const body = new URLSearchParams({
      csrf_token: btn.dataset.csrf,
      action: 'remove',
      id:     btn.dataset.saveId,
    });
    fetch('<?= BASE_PATH ?>/reports/favorite_save', { method: 'POST', body })
      .then(r => r.json())
      .then(json => {
        if (!json.ok) { showToast(json.error || 'Error removing report.', 'error'); btn.disabled = false; return; }
        btn.dataset.saved  = '0';
        btn.dataset.saveId = '';
        btn.classList.replace('btn-warning', 'btn-outline-secondary');
        icon.className = 'bi bi-bookmark-plus';
        span.textContent = 'Save Report';
        btn.title = 'Save to Reports index';
        btn.disabled = false;
        const u = new URL(window.location.href);
        u.searchParams.delete('saved_id');
        u.searchParams.delete('fav_id');
        history.replaceState(null, '', u.toString());
      })
      .catch((e) => { console.error(e); showToast('Network error.', 'error'); btn.disabled = false; });
  }
}

// ── Copy permalink ────────────────────────────────────────────
function copyPermalink(btn) {
  navigator.clipboard.writeText(window.location.href).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
    btn.classList.add('btn-success');
    btn.classList.remove('btn-outline-secondary');
    setTimeout(() => {
      btn.innerHTML = orig;
      btn.classList.remove('btn-success');
      btn.classList.add('btn-outline-secondary');
    }, 2000);
  });
}

// ── XLSX export ───────────────────────────────────────────────
function exportXlsx() {
  const tableId = <?= json_encode($rptType === 'detail' ? 'crDetailTable' : ($rptType === 'txnlist' ? 'crTxnTable' : 'crPivotTable')) ?>;
  const tbl = document.getElementById(tableId);
  if (!tbl) return;
  const wb    = XLSX.utils.book_new();
  const ws    = XLSX.utils.table_to_sheet(tbl);
  const title = <?= json_encode($reportTitle ?: 'custom-report') ?>;
  XLSX.utils.book_append_sheet(wb, ws, 'Report');
  XLSX.writeFile(wb, title + '-<?= date('Y-m-d') ?>.xlsx');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

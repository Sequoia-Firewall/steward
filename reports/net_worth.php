<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Parameters ─────────────────────────────────────────────────
$months = isset($_GET['months']) ? max(3, min(120, (int)$_GET['months'])) : 12;

// ── Build monthly net worth snapshots ──────────────────────────
// For each month in range, sum all account balances as of end-of-month.
// We calculate running balance: opening_balance + sum of transactions up to date.

// Get all active accounts excluding those opted out of net worth
$accounts = $db->query(
    "SELECT id, name, type, is_investment_cash, opening_balance FROM accounts WHERE is_active = 1 AND is_closed = 0 AND exclude_from_net_worth = 0"
)->fetchAll();

if (empty($accounts)) {
    $pageTitle = 'Net Worth'; $currentPage = 'reports';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="page-header"><h2>Net Worth Over Time</h2></div><p class="text-muted">No accounts found.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Build month-end dates (going back $months months from end of current month)
$periodEnd   = new DateTime(date('Y-m-t'));
$periodStart = (clone $periodEnd)->modify('-' . ($months - 1) . ' months')->modify('first day of this month');

$monthPoints = [];
$cursor = clone $periodStart;
while ($cursor <= $periodEnd) {
    $monthPoints[] = $cursor->format('Y-m-t'); // last day of month
    $cursor->modify('+1 month');
}

// Cap transaction cutoff at today so future-dated entries don't skew the current month
$txnCutoff    = min(end($monthPoints), date('Y-m-d'));
$acctIds      = array_column($accounts, 'id');
$placeholders = implode(',', array_fill(0, count($acctIds), '?'));

$stmt = $db->prepare(
    "SELECT account_id,
            DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
            SUM(amount) AS month_total
     FROM transactions
     WHERE account_id IN ($placeholders)
       AND transaction_date <= ?
     GROUP BY account_id, ym
     ORDER BY account_id, ym"
);
$stmt->execute([...$acctIds, $txnCutoff]);
$txnRows = $stmt->fetchAll();

// Build cumulative per account: account_id -> [ym -> running_total]
$cumulative = [];
foreach ($txnRows as $r) {
    $aid = (int)$r['account_id'];
    $cumulative[$aid][$r['ym']] = (float)$r['month_total'];
}

// Build monthly running sum per account
$accountRunning = [];
foreach ($accounts as $acc) {
    $aid     = (int)$acc['id'];
    $running = 0;
    $sorted  = $cumulative[$aid] ?? [];
    ksort($sorted);
    $runMap  = [];
    foreach ($sorted as $ym => $tot) { $running += $tot; $runMap[$ym] = $running; }
    $accountRunning[$aid] = ['opening' => (float)$acc['opening_balance'], 'map' => $runMap];
}

// ── Historical market values for investment accounts ───────────
// Investment accounts (type Investment/Crypto, not cash sub-accounts) must use
// market value (holdings × price) rather than transaction amounts, because
// purchase transactions are recorded as cash outflows and make balances negative.
$investAccts   = array_filter($accounts, fn($a) => isInvestLike($a['type']) && !$a['is_investment_cash']);
$investAcctIds = array_column(array_values($investAccts), 'id');
$histMktValues = []; // eom -> [acct_id -> market_value]

if (!empty($investAcctIds)) {
    $iph   = implode(',', array_fill(0, count($investAcctIds), '?'));
    $iStmt = $db->prepare(
        "SELECT t.account_id, it.investment_id, it.activity, it.quantity, it.price, t.transaction_date
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         WHERE t.account_id IN ($iph)
         ORDER BY t.transaction_date"
    );
    $iStmt->execute($investAcctIds);
    $iTxns  = $iStmt->fetchAll();
    $invIds = array_values(array_unique(array_column($iTxns, 'investment_id')));

    $priceHist = [];
    if (!empty($invIds)) {
        $pph   = implode(',', array_fill(0, count($invIds), '?'));
        $pStmt = $db->prepare(
            "SELECT investment_id, price_date, close_price
             FROM investment_prices WHERE investment_id IN ($pph)
             ORDER BY investment_id, price_date"
        );
        $pStmt->execute($invIds);
        foreach ($pStmt->fetchAll() as $p) {
            $priceHist[(int)$p['investment_id']][] = [$p['price_date'], (float)$p['close_price']];
        }
    }

    // Pre-compute latest price per investment per month-end (single pass each)
    $priceAtEom = []; // inv_id -> [eom -> price]
    foreach ($priceHist as $iid => $plist) {
        $latest = 0.0; $pi = 0; $np = count($plist);
        foreach ($monthPoints as $eom) {
            while ($pi < $np && $plist[$pi][0] <= $eom) { $latest = $plist[$pi][1]; $pi++; }
            $priceAtEom[$iid][$eom] = $latest;
        }
    }

    // Fallback: for investments with no market price, forward-fill last known transaction price
    $noPriceSet = array_flip(array_diff($invIds, array_keys($priceHist)));
    if (!empty($noPriceSet)) {
        $txnPricesByInv = [];
        foreach ($iTxns as $row) {
            $iid = (int)$row['investment_id'];
            if (!isset($noPriceSet[$iid])) continue;
            $p = (float)$row['price'];
            if ($p > 0) $txnPricesByInv[$iid][] = [$row['transaction_date'], $p];
        }
        foreach ($txnPricesByInv as $iid => $plist) {
            $latest = 0.0; $pi = 0; $np = count($plist);
            foreach ($monthPoints as $eom) {
                while ($pi < $np && $plist[$pi][0] <= $eom) { $latest = $plist[$pi][1]; $pi++; }
                if ($latest > 0) $priceAtEom[$iid][$eom] = $latest;
            }
        }
    }

    // Accumulate holdings incrementally (one pass through sorted transactions)
    $holdings = []; // acct_id -> [inv_id -> net_qty]
    $ti = 0; $nt = count($iTxns);
    foreach ($monthPoints as $eom) {
        while ($ti < $nt && $iTxns[$ti]['transaction_date'] <= $eom) {
            $row = $iTxns[$ti++];
            $aid = (int)$row['account_id']; $iid = (int)$row['investment_id'];
            $qty = (float)$row['quantity'];
            if (in_array($row['activity'], ['buy','add','split','reinvest_div','reinvest_cap']))
                $holdings[$aid][$iid] = ($holdings[$aid][$iid] ?? 0.0) + $qty;
            elseif (in_array($row['activity'], ['sell','remove']))
                $holdings[$aid][$iid] = ($holdings[$aid][$iid] ?? 0.0) - $qty;
        }
        foreach ($investAcctIds as $aid) {
            $mv = 0.0;
            foreach (($holdings[$aid] ?? []) as $iid => $qty) {
                if ($qty < 0.000001) continue;
                $mv += $qty * ($priceAtEom[$iid][$eom] ?? 0.0);
            }
            $histMktValues[$eom][$aid] = $mv;
        }
    }
}

// ── Now compute net worth per month point ──────────────────────
$chartLabels = [];
$chartAssets = [];
$chartLiab   = [];
$chartNet    = [];
$tableRows   = [];

foreach ($monthPoints as $eom) {
    $ym    = substr($eom, 0, 7);
    $label = date('M Y', strtotime($eom));

    $assets = 0;
    $liab   = 0;

    foreach ($accounts as $acc) {
        $aid = (int)$acc['id'];

        if (isInvestLike($acc['type']) && !$acc['is_investment_cash']) {
            // Use historical market value for investment accounts
            $balance = $histMktValues[$eom][$aid] ?? 0.0;
        } else {
            $map      = $accountRunning[$aid]['map'];
            $txnTotal = 0;
            foreach ($map as $tym => $tot) { if ($tym <= $ym) $txnTotal = $tot; }
            $balance  = $accountRunning[$aid]['opening'] + $txnTotal;
        }

        if ($acc['type'] === 'Credit Card') {
            if ($balance < 0) $liab   += abs($balance);
            else              $assets += $balance;
        } else {
            if ($balance >= 0) $assets += $balance;
            else               $liab   += abs($balance);
        }
    }

    $net           = $assets - $liab;
    $chartLabels[] = $label;
    $chartAssets[] = round($assets, 2);
    $chartLiab[]   = round($liab,   2);
    $chartNet[]    = round($net,    2);
    $tableRows[]   = ['label' => $label, 'assets' => $assets, 'liab' => $liab, 'net' => $net];
}

$latestNet    = end($chartNet);
$earliestNet  = reset($chartNet);
$netChange    = $latestNet - $earliestNet;

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = array_map(
        fn($r) => [$r['label'],
                   number_format($r['assets'], 2, '.', ''),
                   number_format($r['liab'],   2, '.', ''),
                   number_format($r['net'],    2, '.', '')],
        array_reverse($tableRows)
    );
    outputCsv('net_worth_' . $months . 'mo.csv',
              ['Month', 'Assets', 'Liabilities', 'Net Worth'], $csvRows);
}

$pageTitle   = 'Net Worth Over Time';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Net Worth Over Time'; $reportFavIcon = 'bi-graph-up'; ?>
<div class="page-header">
  <h2><i class="bi bi-graph-up"></i> Net Worth Over Time</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>Period</label>
    <div class="quick-ranges">
      <?php foreach ([6=>'6 Months', 12=>'1 Year', 24=>'2 Years', 60=>'5 Years'] as $m => $lbl): ?>
      <a href="?months=<?= $m ?>" class="btn btn-sm btn-outline-secondary<?= $months == $m ? ' active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="filter-group filter-group-btns">
    <a href="?months=<?= $months ?>&export=csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </a>
  </div>
</form>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Current Net Worth</div>
    <div class="tile-value"><?= formatMoney($latestNet) ?></div>
  </div>
  <div class="report-tile <?= $netChange >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Change (<?= $months ?>mo)</div>
    <div class="tile-value"><?= formatMoney($netChange, true) ?></div>
  </div>
</div>

<div class="report-chart-wrap">
  <canvas id="nwChart" height="100"></canvas>
</div>

<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Month</th>
      <th class="text-end">Assets</th>
      <th class="text-end">Liabilities</th>
      <th class="text-end">Net Worth</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach (array_reverse($tableRows) as $row): ?>
    <tr>
      <td><?= h($row['label']) ?></td>
      <td class="text-end amount-credit"><?= formatMoney($row['assets']) ?></td>
      <td class="text-end amount-debit"><?= formatMoney($row['liab']) ?></td>
      <td class="text-end <?= $row['net'] >= 0 ? 'amount-credit' : 'amount-debit' ?>"><?= formatMoney($row['net']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels = <?= json_encode($chartLabels) ?>;
  const assets = <?= json_encode($chartAssets) ?>;
  const liab   = <?= json_encode($chartLiab) ?>;
  const net    = <?= json_encode($chartNet) ?>;

  new Chart(document.getElementById('nwChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label:'Net Worth', data:net,    borderColor:'#0d6efd', backgroundColor:'rgba(13,110,253,0.1)', fill:true, borderWidth:2, tension:0.3, pointRadius:3 },
        { label:'Assets',    data:assets, borderColor:'#28a745', backgroundColor:'transparent', borderDash:[4,2], borderWidth:1.5, tension:0.3, pointRadius:2 },
        { label:'Liabilities',data:liab, borderColor:'#dc3545', backgroundColor:'transparent', borderDash:[4,2], borderWidth:1.5, tension:0.3, pointRadius:2 },
      ]
    },
    options: {
      responsive:true,
      interaction:{ mode:'index', intersect:false },
      plugins:{ legend:{ position:'top' } },
      scales:{ y:{ ticks:{ callback: v => '$'+v.toLocaleString() } } }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

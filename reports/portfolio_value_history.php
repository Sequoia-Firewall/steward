<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Filters ────────────────────────────────────────────────────
$allAccounts = $db->query(
    "SELECT id, name, 'Investment' AS type FROM accounts
     WHERE type = 'Investment' AND is_investment_cash = 0 AND is_active = 1
     ORDER BY name"
)->fetchAll();

$allAcctIds = array_map('intval', array_column($allAccounts, 'id'));
$acctParam  = trim($_GET['accts'] ?? '');

if ($acctParam === '' || $acctParam === 'all') {
    $selectedAcctIds = $allAcctIds;
    $filteringAccts  = false;
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allAcctIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allAcctIds)) {
        $selectedAcctIds = $allAcctIds;
        $filteringAccts  = false;
    } else {
        $selectedAcctIds = $parsed;
        $filteringAccts  = true;
    }
}

if ($filteringAccts) {
    $ph         = implode(',', array_fill(0, count($selectedAcctIds), '?'));
    $acctWhere  = "AND a.id IN ($ph)";
    $acctParams = $selectedAcctIds;
} else {
    $acctWhere  = '';
    $acctParams = [];
}

// ── All transactions (for timeline) ───────────────────────────
$stmt = $db->prepare(
    "SELECT it.investment_id, t.account_id, t.transaction_date AS txn_date,
            it.activity, it.quantity, it.price, it.commission
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN accounts      a ON a.id = t.account_id
     WHERE a.is_investment_cash = 0
       $acctWhere
     ORDER BY t.transaction_date, it.id"
);
$stmt->execute($acctParams);
$allTxns = $stmt->fetchAll();

// ── Current-holdings table ─────────────────────────────────────
$holdWhere  = "a.is_investment_cash = 0 AND i.is_active = 1";
$holdParams = [];
if ($acctFilter) { $holdWhere .= " AND a.id = ?"; $holdParams[] = $acctFilter; }

$stmt2 = $db->prepare(
    "SELECT
        i.id AS inv_id, i.name AS inv_name, i.symbol, i.type AS inv_type,
        a.name AS acct_name,
        MIN(t.transaction_date) AS first_date,
        COALESCE(SUM(CASE
            WHEN it.activity IN ('buy','add','split') THEN  it.quantity
            WHEN it.activity IN ('sell','remove')     THEN -it.quantity
            ELSE 0
        END), 0) AS net_qty,
        SUM(CASE WHEN it.activity IN ('buy','add')
            THEN it.quantity * it.price + it.commission ELSE 0 END) AS buy_cost,
        SUM(CASE WHEN it.activity IN ('buy','add','split')
            THEN it.quantity ELSE 0 END) AS buy_qty
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN investments   i ON i.id = it.investment_id
     JOIN accounts      a ON a.id = t.account_id
     WHERE a.is_investment_cash = 0 AND i.is_active = 1
       $acctWhere
     GROUP BY i.id, i.name, i.symbol, i.type, a.name
     HAVING net_qty > 0.000001
     ORDER BY i.name, a.name"
);
$stmt2->execute($acctParams);
$holdingRows = $stmt2->fetchAll();

// ── Build timeline chart data ──────────────────────────────────
$chartLabels     = [];
$chartMV         = [];
$chartCost       = [];
$hasPriceHistory = false;

if (!empty($allTxns)) {
    $invIds       = array_values(array_unique(array_column($allTxns, 'investment_id')));
    $placeholders = implode(',', array_fill(0, count($invIds), '?'));

    $priceStmt = $db->prepare(
        "SELECT investment_id, price_date, close_price
         FROM investment_prices
         WHERE investment_id IN ({$placeholders})
         ORDER BY investment_id, price_date"
    );
    $priceStmt->execute($invIds);
    $priceRows = $priceStmt->fetchAll();
    $hasPriceHistory = !empty($priceRows);

    $priceHistory = [];
    foreach ($priceRows as $p) {
        $priceHistory[(int)$p['investment_id']][] = [
            'date'  => $p['price_date'],
            'price' => (float)$p['close_price'],
        ];
    }

    // Monthly snapshots: last day of each month from first transaction to today
    $snapDates = [];
    $ym    = date('Y-m', strtotime($allTxns[0]['txn_date']));
    $ymEnd = date('Y-m');
    while ($ym <= $ymEnd) {
        $lastDay    = date('Y-m-t', strtotime($ym . '-01'));
        $snapDates[] = $lastDay;
        $ym = substr(date('Y-m-d', strtotime($lastDay . ' +1 day')), 0, 7);
    }

    // Walk through transactions and prices in one pass per snapshot
    $sharesHeld   = [];
    $cumCostBasis = 0.0;
    $txnIdx       = 0;
    $txnCount     = count($allTxns);
    $priceCursors = array_fill_keys($invIds, 0);
    $lastPrice    = array_fill_keys($invIds, null);

    foreach ($snapDates as $snapDate) {
        while ($txnIdx < $txnCount && $allTxns[$txnIdx]['txn_date'] <= $snapDate) {
            $t   = $allTxns[$txnIdx];
            $k   = $t['investment_id'] . ':' . $t['account_id'];
            $qty = (float)$t['quantity'];
            $act = $t['activity'];
            if (!isset($sharesHeld[$k])) $sharesHeld[$k] = 0.0;
            if (in_array($act, ['buy', 'add'])) {
                $sharesHeld[$k] += $qty;
                $cumCostBasis   += $qty * (float)$t['price'] + (float)$t['commission'];
            } elseif (in_array($act, ['sell', 'remove'])) {
                $sharesHeld[$k] -= $qty;
            } elseif ($act === 'split') {
                $sharesHeld[$k] += $qty;
            }
            $txnIdx++;
        }

        foreach ($invIds as $invId) {
            if (!isset($priceHistory[$invId])) continue;
            $prices = $priceHistory[$invId];
            $c = $priceCursors[$invId];
            while ($c < count($prices) && $prices[$c]['date'] <= $snapDate) {
                $lastPrice[$invId] = $prices[$c]['price'];
                $c++;
            }
            $priceCursors[$invId] = $c;
        }

        $mv = 0.0;
        foreach ($sharesHeld as $key => $shares) {
            if ($shares < 0.000001) continue;
            $invId = (int)explode(':', $key)[0];
            $p     = $lastPrice[$invId] ?? null;
            if ($p !== null) $mv += $shares * $p;
        }

        $chartLabels[] = date('M Y', strtotime($snapDate));
        $chartMV[]     = round($mv, 2);
        $chartCost[]   = round($cumCostBasis, 2);
    }
}

// ── Holdings table rows ────────────────────────────────────────
$latestPrices    = getLatestInvestmentPrices();
$today           = date('Y-m-d');
$tableRows       = [];
$totalCostBasis  = 0.0;
$totalCurrentVal = 0.0;

foreach ($holdingRows as $r) {
    $invId     = (int)$r['inv_id'];
    $qty       = (float)$r['net_qty'];
    $buyQty    = (float)$r['buy_qty'];
    $buyCost   = (float)$r['buy_cost'];
    $avgCost   = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $costBasis = $avgCost * $qty;

    $price      = $latestPrices[$invId]['price'] ?? null;
    $currentVal = $price !== null ? $price * $qty : null;
    $gainLoss   = $currentVal !== null ? $currentVal - $costBasis : null;

    $daysHeld  = max(1, (int)((strtotime($today) - strtotime($r['first_date'])) / 86400));
    $yearsHeld = $daysHeld / 365.25;

    $annualReturn = null;
    if ($currentVal !== null && $costBasis > 0 && $yearsHeld > 0) {
        $annualReturn = (pow($currentVal / $costBasis, 1.0 / $yearsHeld) - 1) * 100;
    }

    $totalCostBasis  += $costBasis;
    if ($currentVal !== null) $totalCurrentVal += $currentVal;

    $tableRows[] = [
        'inv_name'    => $r['inv_name'],
        'symbol'      => $r['symbol'],
        'inv_type'    => $r['inv_type'],
        'acct_name'   => $r['acct_name'],
        'first_date'  => $r['first_date'],
        'days_held'   => $daysHeld,
        'costBasis'   => $costBasis,
        'currentVal'  => $currentVal,
        'gainLoss'    => $gainLoss,
        'annualReturn'=> $annualReturn,
    ];
}

$totalGainLoss = $totalCurrentVal - $totalCostBasis;
$totalReturn   = $totalCostBasis > 0 ? ($totalGainLoss / $totalCostBasis) * 100 : null;

// Overall annualized return from first-ever purchase to today
$overallAnnual = null;
if (!empty($holdingRows) && $totalCurrentVal > 0 && $totalCostBasis > 0) {
    $overallFirst  = min(array_column($holdingRows, 'first_date'));
    $overallYears  = max(1 / 365, (strtotime($today) - strtotime($overallFirst)) / 86400 / 365.25);
    $overallAnnual = (pow($totalCurrentVal / $totalCostBasis, 1.0 / $overallYears) - 1) * 100;
}

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($tableRows as $r) {
        $csvRows[] = [
            $r['inv_name'],
            $r['symbol'],
            $r['inv_type'],
            $r['acct_name'],
            $r['first_date'],
            $r['days_held'],
            number_format($r['costBasis'],  2, '.', ''),
            $r['currentVal']   !== null ? number_format($r['currentVal'],   2, '.', '') : '',
            $r['gainLoss']     !== null ? number_format($r['gainLoss'],     2, '.', '') : '',
            $r['annualReturn'] !== null ? number_format($r['annualReturn'], 2, '.', '') : '',
        ];
    }
    outputCsv(
        'portfolio_value_history_' . date('Y-m-d') . '.csv',
        ['Security','Symbol','Type','Account','First Purchase','Days Held',
         'Cost Basis','Current Value','Gain/Loss','Ann. Return %'],
        $csvRows
    );
}

$pageTitle   = 'Portfolio Value History';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Portfolio Value History'; $reportFavIcon = 'bi-graph-up-arrow'; ?>
<div class="page-header">
  <h2><i class="bi bi-graph-up-arrow"></i> Portfolio Value History</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
  </div>
</form>

<?php if (empty($allTxns)): ?>
<p class="text-muted">No investment transactions found.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Current Value</div>
    <div class="tile-value"><?= formatMoney($totalCurrentVal) ?></div>
  </div>
  <div class="report-tile">
    <div class="tile-label">Amount Invested</div>
    <div class="tile-value"><?= formatMoney($totalCostBasis) ?></div>
  </div>
  <div class="report-tile <?= $totalGainLoss >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Gain / Loss</div>
    <div class="tile-value"><?= ($totalGainLoss >= 0 ? '+' : '-') . formatMoney(abs($totalGainLoss)) ?></div>
  </div>
  <?php if ($totalReturn !== null): ?>
  <div class="report-tile <?= $totalReturn >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Total Return</div>
    <div class="tile-value"><?= ($totalReturn >= 0 ? '+' : '') . number_format($totalReturn, 2) ?>%</div>
  </div>
  <?php endif; ?>
  <?php if ($overallAnnual !== null): ?>
  <div class="report-tile <?= $overallAnnual >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Annualized Return</div>
    <div class="tile-value"><?= ($overallAnnual >= 0 ? '+' : '') . number_format($overallAnnual, 2) ?>%/yr</div>
  </div>
  <?php endif; ?>
</div>

<?php if ($hasPriceHistory && !empty($chartLabels)): ?>
<div class="report-chart-wrap" style="max-height:320px">
  <canvas id="valueHistoryChart"></canvas>
</div>
<?php else: ?>
<div class="alert alert-info py-2 px-3 mb-3" style="font-size:.875rem">
  <i class="bi bi-info-circle"></i>
  No price history found. Add prices via the Portfolio page to see the value timeline chart.
</div>
<?php endif; ?>

<?php if (!empty($tableRows)): ?>
<h3 class="report-section-title">Holdings Detail</h3>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th class="sortable" data-col="security">Security <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="sortable" data-col="account">Account <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="sortable" data-col="firstdate">First Purchase <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="daysheld">Days Held <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="costbasis">Cost Basis <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="currentval">Current Value <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="gainloss">Gain / Loss <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="annreturn">Ann. Return <i class="bi bi-arrow-down-up sort-icon"></i></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($tableRows as $r):
      $glCls = $r['gainLoss']    !== null ? ($r['gainLoss']    >= 0 ? 'amount-credit' : 'amount-debit') : '';
      $arCls = $r['annualReturn'] !== null ? ($r['annualReturn'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
    ?>
    <tr data-security="<?= h(strtolower($r['inv_name'])) ?>"
        data-account="<?= h(strtolower($r['acct_name'])) ?>"
        data-firstdate="<?= h($r['first_date']) ?>"
        data-daysheld="<?= $r['days_held'] ?>"
        data-costbasis="<?= $r['costBasis'] ?>"
        data-currentval="<?= $r['currentVal'] ?? '' ?>"
        data-gainloss="<?= $r['gainLoss'] ?? '' ?>"
        data-annreturn="<?= $r['annualReturn'] ?? '' ?>">
      <td>
        <strong><?= h($r['inv_name']) ?></strong>
        <?php if ($r['symbol']): ?>
        <span class="text-muted small ms-1"><?= h($r['symbol']) ?></span>
        <?php endif; ?>
      </td>
      <td class="text-muted small"><?= h($r['acct_name']) ?></td>
      <td class="text-muted small"><?= formatDate($r['first_date']) ?></td>
      <td class="text-end text-muted"><?= number_format($r['days_held']) ?></td>
      <td class="text-end"><?= formatMoney($r['costBasis']) ?></td>
      <td class="text-end">
        <?= $r['currentVal'] !== null ? formatMoney($r['currentVal']) : '<span class="text-muted">—</span>' ?>
      </td>
      <td class="text-end">
        <?php if ($r['gainLoss'] !== null): ?>
          <span class="<?= $glCls ?>"><?= ($r['gainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($r['gainLoss'])) ?></span>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td class="text-end">
        <?php if ($r['annualReturn'] !== null): ?>
          <span class="<?= $arCls ?>"><?= ($r['annualReturn'] >= 0 ? '+' : '') . number_format($r['annualReturn'], 2) ?>%/yr</span>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <?php $totGlCls = $totalGainLoss >= 0 ? 'amount-credit' : 'amount-debit'; ?>
    <tr>
      <td colspan="4"><strong>Total</strong></td>
      <td class="text-end"><strong><?= formatMoney($totalCostBasis) ?></strong></td>
      <td class="text-end"><strong><?= formatMoney($totalCurrentVal) ?></strong></td>
      <td class="text-end">
        <span class="<?= $totGlCls ?>"><strong><?= ($totalGainLoss >= 0 ? '+' : '-') . formatMoney(abs($totalGainLoss)) ?></strong></span>
      </td>
      <td class="text-end">
        <?php if ($overallAnnual !== null):
          $totArCls = $overallAnnual >= 0 ? 'amount-credit' : 'amount-debit'; ?>
        <span class="<?= $totArCls ?>"><strong><?= ($overallAnnual >= 0 ? '+' : '') . number_format($overallAnnual, 2) ?>%/yr</strong></span>
        <?php endif; ?>
      </td>
    </tr>
  </tfoot>
</table>
<?php endif; ?>

<?php endif; ?>

<?php if ($hasPriceHistory && !empty($chartLabels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const mvData   = <?= json_encode($chartMV) ?>;
  const costData = <?= json_encode($chartCost) ?>;

  new Chart(document.getElementById('valueHistoryChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Portfolio Value',
          data: mvData,
          borderColor: '#1a5fb4',
          backgroundColor: 'rgba(26,95,180,0.08)',
          borderWidth: 2,
          pointRadius: labels.length <= 36 ? 3 : 0,
          fill: true,
          tension: 0.3,
        },
        {
          label: 'Amount Invested',
          data: costData,
          borderColor: '#6c757d',
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [5, 4],
          pointRadius: 0,
          fill: false,
          tension: 0,
        },
      ]
    },
    options: {
      animation: false,
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 20 } },
        tooltip: {
          callbacks: {
            label: c => ' ' + c.dataset.label + ': $' +
              c.raw.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
          }
        }
      },
      scales: {
        x: {
          ticks: { font: { size: 10 }, maxRotation: 45, autoSkip: true, maxTicksLimit: 18 },
          grid:  { display: false }
        },
        y: {
          ticks: {
            font: { size: 10 },
            callback: v => '$' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' :
                                   v >= 1000    ? (v/1000).toFixed(0)+'k'    : v)
          },
          grid: { color: '#eee' }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php if (!empty($tableRows)): ?>
<script>
(function () {
  const numCols = new Set(['daysheld', 'costbasis', 'currentval', 'gainloss', 'annreturn']);
  let sortCol = null, sortDir = 'asc';

  function getVal(row, col) {
    const raw = row.dataset[col];
    if (raw === '' || raw === undefined || raw === null) return null;
    return numCols.has(col) ? parseFloat(raw) : raw;
  }

  const table = document.querySelector('.report-table');
  if (!table) return;
  const tbody = table.querySelector('tbody');

  table.querySelectorAll('th.sortable').forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const col = th.dataset.col;
      sortDir = (sortCol === col && sortDir === 'asc') ? 'desc' : 'asc';
      sortCol = col;

      table.querySelectorAll('th.sortable').forEach(t => {
        const icon = t.querySelector('.sort-icon');
        if (!icon) return;
        icon.className = 'bi sort-icon ' + (t.dataset.col === col
          ? (sortDir === 'asc' ? 'bi-sort-up-alt' : 'bi-sort-down-alt')
          : 'bi-arrow-down-up');
      });

      const rows = [...tbody.querySelectorAll('tr')];
      const dir  = sortDir === 'asc' ? 1 : -1;
      rows.sort((a, b) => {
        const av = getVal(a, col), bv = getVal(b, col);
        if (av === bv)   return 0;
        if (av === null) return 1;
        if (bv === null) return -1;
        return typeof av === 'string' ? dir * av.localeCompare(bv) : dir * (av - bv);
      });
      rows.forEach(r => tbody.appendChild(r));
    });
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

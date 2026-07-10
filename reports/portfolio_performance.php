<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Account filter ─────────────────────────────────────────────
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

// ── Holdings query ─────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT
        i.id            AS inv_id,
        i.name          AS inv_name,
        i.symbol,
        i.type          AS inv_type,
        a.id            AS acct_id,
        a.name          AS acct_name,
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
     HAVING net_qty > 0.000001
     ORDER BY a.name, i.name"
);
$stmt->execute($acctParams);
$rawRows = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();

// ── Build display rows ─────────────────────────────────────────
$rows              = [];
$totalCostBasis    = 0.0;
$totalMarketValue  = 0.0;
$totalGainLoss     = 0.0;
$anyMissingPrice   = false;

foreach ($rawRows as $r) {
    $invId     = (int)$r['inv_id'];
    $qty       = (float)$r['net_qty'];
    $buyQty    = (float)$r['buy_qty'];
    $buyCost   = (float)$r['buy_cost'];
    $avgCost   = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $costBasis = $avgCost * $qty;

    $price       = $latestPrices[$invId]['price']      ?? null;
    $priceDate   = $latestPrices[$invId]['price_date'] ?? null;
    $marketValue = $price !== null ? $price * $qty : null;
    $gainLoss    = $marketValue !== null ? $marketValue - $costBasis : null;
    $gainLossPct = ($gainLoss !== null && $costBasis > 0) ? ($gainLoss / $costBasis) * 100 : null;

    if ($marketValue !== null) $totalMarketValue += $marketValue;
    $totalCostBasis += $costBasis;
    if ($gainLoss !== null) $totalGainLoss += $gainLoss;
    if ($price === null) $anyMissingPrice = true;

    $rows[] = [
        'invId'       => $invId,
        'inv_name'    => $r['inv_name'],
        'symbol'      => $r['symbol'],
        'inv_type'    => $r['inv_type'],
        'acct_name'   => $r['acct_name'],
        'qty'         => $qty,
        'avgCost'     => $avgCost,
        'costBasis'   => $costBasis,
        'price'       => $price,
        'priceDate'   => $priceDate,
        'marketValue' => $marketValue,
        'gainLoss'    => $gainLoss,
        'gainLossPct' => $gainLossPct,
    ];
}

$totalReturn = $totalCostBasis > 0 ? ($totalGainLoss / $totalCostBasis) * 100 : null;

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($rows as $r) {
        $csvRows[] = [
            $r['inv_name'],
            $r['symbol'],
            $r['inv_type'],
            $r['acct_name'],
            number_format($r['qty'], 6, '.', ''),
            $r['avgCost']     !== null ? number_format($r['avgCost'],     2, '.', '') : '',
            $r['costBasis']   !== null ? number_format($r['costBasis'],   2, '.', '') : '',
            $r['price']       !== null ? number_format($r['price'],       2, '.', '') : '',
            $r['priceDate']   ?? '',
            $r['marketValue'] !== null ? number_format($r['marketValue'], 2, '.', '') : '',
            $r['gainLoss']    !== null ? number_format($r['gainLoss'],    2, '.', '') : '',
            $r['gainLossPct'] !== null ? number_format($r['gainLossPct'], 2, '.', '') : '',
        ];
    }
    outputCsv(
        'portfolio_performance_' . date('Y-m-d') . '.csv',
        ['Security', 'Symbol', 'Type', 'Account', 'Shares', 'Avg Cost', 'Cost Basis',
         'Price', 'Price Date', 'Market Value', 'Gain/Loss', 'Return %'],
        $csvRows
    );
}

// ── Chart data — return % per holding (sorted descending) ──────
$chartRows = array_filter($rows, fn($r) => $r['gainLossPct'] !== null);
usort($chartRows, fn($a, $b) => $b['gainLossPct'] <=> $a['gainLossPct']);
$chartLabels = array_map(fn($r) => $r['symbol'] ?: $r['inv_name'], $chartRows);
$chartValues = array_map(fn($r) => round($r['gainLossPct'], 2), $chartRows);
$chartColors = array_map(fn($v) => $v >= 0 ? 'rgba(26,122,60,0.75)' : 'rgba(192,57,43,0.75)', $chartValues);

$pageTitle   = 'Portfolio Performance';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Portfolio Performance'; $reportFavIcon = 'bi-briefcase'; ?>
<div class="page-header">
  <h2><i class="bi bi-briefcase"></i> Portfolio Performance</h2>
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

<?php if (empty($rows)): ?>
<p class="text-muted">No current holdings found.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Market Value</div>
    <div class="tile-value"><?= formatMoney($totalMarketValue) ?></div>
  </div>
  <div class="report-tile">
    <div class="tile-label">Cost Basis</div>
    <div class="tile-value"><?= formatMoney($totalCostBasis) ?></div>
  </div>
  <div class="report-tile <?= $totalGainLoss >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Unrealized Gain / Loss</div>
    <div class="tile-value"><?= ($totalGainLoss >= 0 ? '+' : '') . formatMoney(abs($totalGainLoss)) ?></div>
  </div>
  <?php if ($totalReturn !== null): ?>
  <div class="report-tile <?= $totalReturn >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Total Return</div>
    <div class="tile-value"><?= ($totalReturn >= 0 ? '+' : '') . number_format(abs($totalReturn), 2) ?>%</div>
  </div>
  <?php endif; ?>
</div>

<?php if ($anyMissingPrice): ?>
<div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.875rem">
  <i class="bi bi-exclamation-triangle"></i>
  Some securities have no current price — totals may be incomplete.
</div>
<?php endif; ?>

<?php if (!empty($chartRows)): ?>
<div class="report-chart-wrap" style="max-height:<?= max(120, count($chartRows) * 32) ?>px">
  <canvas id="perfChart"></canvas>
</div>
<?php endif; ?>

<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Security</th>
      <th>Account</th>
      <th class="text-end">Shares</th>
      <th class="text-end">Avg Cost</th>
      <th class="text-end">Cost Basis</th>
      <th class="text-end">Price</th>
      <th class="text-end">Market Value</th>
      <th class="text-end">Gain / Loss</th>
      <th class="text-end">Return %</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r):
      $glCls   = $r['gainLoss'] !== null ? ($r['gainLoss'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
      $pctSign = $r['gainLossPct'] !== null && $r['gainLossPct'] >= 0 ? '+' : '';
      $glSign  = $r['gainLoss']    !== null && $r['gainLoss']    >= 0 ? '+' : '-';
    ?>
    <?php $secSlug = !empty($r['symbol']) ? urlencode($r['symbol']) : $r['invId']; ?>
    <tr>
      <td>
        <a href="<?= BASE_PATH ?>/portfolio/security/<?= $secSlug ?>" class="inv-name-link">
          <strong><?= h($r['inv_name']) ?></strong>
          <?php if ($r['symbol']): ?>
          <span class="text-muted small ms-1"><?= h($r['symbol']) ?></span>
          <?php endif; ?>
        </a>
        <div class="text-muted small"><?= h($r['inv_type']) ?></div>
      </td>
      <td class="text-muted small"><?= h($r['acct_name']) ?></td>
      <td class="text-end"><?= rtrim(rtrim(number_format($r['qty'], 6), '0'), '.') ?></td>
      <td class="text-end"><?= $r['avgCost'] > 0 ? formatMoney($r['avgCost']) : '—' ?></td>
      <td class="text-end"><?= $r['costBasis'] > 0 ? formatMoney($r['costBasis']) : '—' ?></td>
      <td class="text-end">
        <?php if ($r['price'] !== null): ?>
        <button class="btn btn-link p-0 inv-price"
                data-id="<?= $r['invId'] ?>"
                data-name="<?= h($r['inv_name']) ?>"
                data-symbol="<?= h($r['symbol'] ?? '') ?>"
                title="Click for price history"><?= formatMoney($r['price']) ?></button>
        <?php else: ?>
        <span class="text-muted">—</span>
        <?php endif; ?>
        <?php if ($r['priceDate']): ?>
        <div class="text-muted" style="font-size:.7rem"><?= formatDate($r['priceDate']) ?></div>
        <?php endif; ?>
      </td>
      <td class="text-end"><?= $r['marketValue'] !== null ? formatMoney($r['marketValue']) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end <?= $glCls ?>">
        <?php if ($r['gainLoss'] !== null): ?>
          <?= $glSign ?><?= formatMoney(abs($r['gainLoss'])) ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td class="text-end <?= $glCls ?>">
        <?php if ($r['gainLossPct'] !== null): ?>
          <?= $pctSign ?><?= number_format(abs($r['gainLossPct']), 2) ?>%
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
      <td></td>
      <td class="text-end"><strong><?= formatMoney($totalMarketValue) ?></strong></td>
      <td class="text-end <?= $totGlCls ?>">
        <strong><?= ($totalGainLoss >= 0 ? '+' : '-') ?><?= formatMoney(abs($totalGainLoss)) ?></strong>
      </td>
      <td class="text-end <?= $totGlCls ?>">
        <?php if ($totalReturn !== null): ?>
        <strong><?= ($totalReturn >= 0 ? '+' : '') ?><?= number_format(abs($totalReturn), 2) ?>%</strong>
        <?php endif; ?>
      </td>
    </tr>
  </tfoot>
</table>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php if (!empty($chartRows)): ?>
<script>
(function(){
  const labels = <?= json_encode($chartLabels) ?>;
  const values = <?= json_encode($chartValues) ?>;
  const colors = <?= json_encode($chartColors) ?>;
  new Chart(document.getElementById('perfChart'), {
    type: 'bar',
    data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
    options: {
      indexAxis: 'y',
      animation: false,
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => (c.raw >= 0 ? '+' : '') + c.raw.toFixed(2) + '%' } },
      },
      scales: {
        x: {
          ticks: { callback: v => (v >= 0 ? '+' : '') + v + '%', font: { size: 10 } },
          grid: { color: '#eee' },
        },
        y: { ticks: { font: { size: 11 } }, grid: { display: false } },
      },
    },
  });
})();
</script>
<?php endif; ?>
<script>
const BASE_PATH  = <?= json_encode(BASE_PATH) ?>;
const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.inv-price[data-id]');
  if (!btn) return;
  openPriceHistory(btn.dataset.id, btn.dataset.name, btn.dataset.symbol);
});
</script>
<?php include __DIR__ . '/../includes/price_history_modal.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

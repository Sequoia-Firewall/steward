<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Account filter ─────────────────────────────────────────────
$allAccounts = $db->query(
    "SELECT id, name, 'Investment' AS type FROM accounts
     WHERE type = 'Investment' AND is_investment_cash = 0 AND is_active = 1 AND is_closed = 0
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
     WHERE a.is_investment_cash = 0 AND a.is_closed = 0 AND i.is_active = 1
       $acctWhere
     GROUP BY i.id, i.name, i.symbol, i.type, a.id, a.name
     HAVING net_qty > 0.000001
     ORDER BY i.name"
);
$stmt->execute($acctParams);
$rawRows = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();

// ── Aggregate by type and by account ──────────────────────────
$byType    = [];
$byAccount = [];
$totalMV   = 0.0;

foreach ($rawRows as $r) {
    $invId     = (int)$r['inv_id'];
    $qty       = (float)$r['net_qty'];
    $buyQty    = (float)$r['buy_qty'];
    $buyCost   = (float)$r['buy_cost'];
    $avgCost   = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $price     = $latestPrices[$invId]['price'] ?? null;
    $mv        = $price !== null ? $price * $qty : $avgCost * $qty; // fallback to cost if no price

    $type    = $r['inv_type']  ?: 'Other';
    $account = $r['acct_name'] ?: 'Unknown';

    if (!isset($byType[$type])) $byType[$type] = ['mv' => 0.0, 'count' => 0, 'names' => []];
    $byType[$type]['mv']    += $mv;
    $byType[$type]['count'] += 1;
    if (!in_array($r['inv_name'], $byType[$type]['names'])) $byType[$type]['names'][] = $r['inv_name'];

    if (!isset($byAccount[$account])) $byAccount[$account] = ['mv' => 0.0, 'count' => 0];
    $byAccount[$account]['mv']    += $mv;
    $byAccount[$account]['count'] += 1;

    $totalMV += $mv;
}

arsort($byType);
arsort($byAccount);

// Colour palette
$palette = ['#1a5fb4','#e66000','#1a7a3c','#c0392b','#8e44ad','#16a085','#d4ac0d','#5d6d7e','#ca6f1e','#117a65'];

$typeLabels  = array_keys($byType);
$typeValues  = array_map(fn($v) => round($v['mv'], 2), $byType);
$typeColors  = array_values(array_slice($palette, 0, count($byType)));

$acctLabels  = array_keys($byAccount);
$acctValues  = array_map(fn($v) => round($v['mv'], 2), $byAccount);
$acctColors  = array_values(array_slice($palette, 0, count($byAccount)));

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($byType as $type => $data) {
        $pct = $totalMV > 0 ? number_format($data['mv'] / $totalMV * 100, 1) : '0.0';
        $csvRows[] = ['By Type', $type, $data['count'], number_format($data['mv'], 2, '.', ''), $pct . '%'];
    }
    foreach ($byAccount as $acct => $data) {
        $pct = $totalMV > 0 ? number_format($data['mv'] / $totalMV * 100, 1) : '0.0';
        $csvRows[] = ['By Account', $acct, $data['count'], number_format($data['mv'], 2, '.', ''), $pct . '%'];
    }
    outputCsv(
        'asset_allocation_' . date('Y-m-d') . '.csv',
        ['Group', 'Name', 'Holdings', 'Market Value', '% of Portfolio'],
        $csvRows
    );
}

$pageTitle   = 'Asset Allocation';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Asset Allocation'; $reportFavIcon = 'bi-pie-chart'; ?>
<div class="page-header">
  <h2><i class="bi bi-pie-chart"></i> Asset Allocation</h2>
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

<?php if (empty($rawRows)): ?>
<p class="text-muted">No holdings found.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Portfolio Value</div>
    <div class="tile-value"><?= formatMoney($totalMV) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Asset Types</div>
    <div class="tile-value"><?= count($byType) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Holdings</div>
    <div class="tile-value"><?= count($rawRows) ?></div>
  </div>
</div>

<div class="alloc-charts-row" style="gap:72px">
  <div class="alloc-chart-block">
    <div class="alloc-chart-title">By Asset Type</div>
    <div class="alloc-chart-wrap"><canvas id="typeChart"></canvas></div>
    <div class="alloc-legend" id="typeLegend"></div>
  </div>
  <?php if (count($byAccount) > 1): ?>
  <div class="alloc-chart-block">
    <div class="alloc-chart-title">By Account</div>
    <div class="alloc-chart-wrap"><canvas id="acctChart"></canvas></div>
    <div class="alloc-legend" id="acctLegend"></div>
  </div>
  <?php endif; ?>
</div>

<h3 class="report-section-title">By Asset Type</h3>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Type</th>
      <th class="text-end">Holdings</th>
      <th class="text-end">Market Value</th>
      <th class="text-end">% of Portfolio</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($byType as $type => $data): ?>
    <tr>
      <td><?= h($type) ?></td>
      <td class="text-end text-muted"><?= $data['count'] ?></td>
      <td class="text-end"><?= formatMoney($data['mv']) ?></td>
      <td class="text-end"><?= $totalMV > 0 ? number_format($data['mv'] / $totalMV * 100, 1) . '%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td><strong>Total</strong></td>
      <td class="text-end"><strong><?= array_sum(array_column($byType, 'count')) ?></strong></td>
      <td class="text-end"><strong><?= formatMoney($totalMV) ?></strong></td>
      <td class="text-end"><strong>100.0%</strong></td>
    </tr>
  </tfoot>
</table>

<?php if (count($byAccount) > 1): ?>
<h3 class="report-section-title">By Account</h3>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Account</th>
      <th class="text-end">Holdings</th>
      <th class="text-end">Market Value</th>
      <th class="text-end">% of Portfolio</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($byAccount as $acctName => $data): ?>
    <tr>
      <td><?= h($acctName) ?></td>
      <td class="text-end text-muted"><?= $data['count'] ?></td>
      <td class="text-end"><?= formatMoney($data['mv']) ?></td>
      <td class="text-end"><?= $totalMV > 0 ? number_format($data['mv'] / $totalMV * 100, 1) . '%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function(){
  const totalMV = <?= round($totalMV, 2) ?>;
  function buildDonut(canvasId, legendId, labels, values, colors) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    const chart = new Chart(ctx, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
      options: {
        animation: false,
        cutout: '62%',
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: c => {
                const pct = totalMV > 0 ? (c.raw / totalMV * 100).toFixed(1) : 0;
                return ' $' + c.raw.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });

    // Custom legend
    const leg = document.getElementById(legendId);
    if (!leg) return;
    labels.forEach((lbl, i) => {
      const pct = totalMV > 0 ? (values[i] / totalMV * 100).toFixed(1) : 0;
      const div = document.createElement('div');
      div.className = 'alloc-leg-item';
      div.innerHTML = `<span class="alloc-leg-dot" style="background:${colors[i]}"></span>
                       <span class="alloc-leg-label">${lbl}</span>
                       <span class="alloc-leg-pct">${pct}%</span>`;
      leg.appendChild(div);
    });
  }

  buildDonut('typeChart', 'typeLegend',
    <?= json_encode($typeLabels) ?>,
    <?= json_encode(array_values($typeValues)) ?>,
    <?= json_encode($typeColors) ?>
  );
  buildDonut('acctChart', 'acctLegend',
    <?= json_encode($acctLabels) ?>,
    <?= json_encode(array_values($acctValues)) ?>,
    <?= json_encode($acctColors) ?>
  );
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Known investment types ─────────────────────────────────────
$knownTypes = ['Index','Stock','Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Cryptocurrency','Other'];

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

// ── Investment type exclusion ──────────────────────────────────
$excludeRaw   = $_GET['exclude_types'] ?? '';
if (is_array($excludeRaw)) {
    $excludeTypes = array_values(array_filter($excludeRaw, fn($t) => in_array($t, $knownTypes, true)));
} else {
    $excludeTypes = array_values(array_filter(
        array_map('trim', explode(',', (string)$excludeRaw)),
        fn($t) => in_array($t, $knownTypes, true)
    ));
}

$typeWhere  = '';
$typeParams = [];
if (!empty($excludeTypes)) {
    $tph       = implode(',', array_fill(0, count($excludeTypes), '?'));
    $typeWhere = "AND i.type NOT IN ($tph)";
    $typeParams = $excludeTypes;
}

// ── Holdings query (grouped by investment, not per-account) ────
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
       $typeWhere
     GROUP BY i.id, i.name, i.symbol, i.type
     HAVING net_qty > 0.000001"
);
$stmt->execute($queryParams);
$rawRows = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();

// ── Build holdings array ───────────────────────────────────────
$holdings = [];
$totalMV   = 0.0;

foreach ($rawRows as $r) {
    $invId = (int)$r['inv_id'];
    $qty   = (float)$r['net_qty'];
    $price = $latestPrices[$invId]['price'] ?? null;
    if ($price === null) continue; // skip no-price holdings
    $mv = $price * $qty;
    $holdings[] = [
        'invId'       => $invId,
        'inv_name'    => $r['inv_name'],
        'symbol'      => $r['symbol'],
        'inv_type'    => $r['inv_type'],
        'qty'         => $qty,
        'marketValue' => $mv,
    ];
    $totalMV += $mv;
}

// Sort descending by market value
usort($holdings, fn($a, $b) => $b['marketValue'] <=> $a['marketValue']);

// ── Top 10 + Other ─────────────────────────────────────────────
$palette = ['#1a5fb4','#e66000','#1a7a3c','#c0392b','#8e44ad','#16a085','#d4ac0d','#5d6d7e','#ca6f1e','#117a65'];
$otherColor = '#aab0bc';

$top10       = array_slice($holdings, 0, 10);
$restHoldings = array_slice($holdings, 10);
$otherMV     = array_sum(array_column($restHoldings, 'marketValue'));

$chartLabels = [];
$chartValues = [];
$chartColors = [];

foreach ($top10 as $i => $h) {
    $chartLabels[] = $h['symbol'] ?: $h['inv_name'];
    $chartValues[] = round($h['marketValue'], 2);
    $chartColors[] = $palette[$i] ?? $otherColor;
}
if ($otherMV > 0.001) {
    $chartLabels[] = 'Other';
    $chartValues[] = round($otherMV, 2);
    $chartColors[] = $otherColor;
}

$pageTitle   = 'Portfolio Snapshot';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Portfolio Snapshot'; $reportFavIcon = 'bi-bar-chart-fill'; ?>
<div class="page-header">
  <h2><i class="bi bi-bar-chart-fill"></i> Portfolio Snapshot</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group">
    <label>Exclude Types</label>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($knownTypes as $kt): ?>
      <label class="d-flex align-items-center gap-1" style="font-size:.85rem;font-weight:400">
        <input type="checkbox" name="exclude_types[]" value="<?= h($kt) ?>"
               <?= in_array($kt, $excludeTypes, true) ? 'checked' : '' ?>>
        <?= h($kt) ?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
  </div>
</form>

<?php if (empty($holdings)): ?>
<p class="text-muted">No holdings with price data found.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Portfolio Value</div>
    <div class="tile-value"><?= formatMoney($totalMV) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Holdings</div>
    <div class="tile-value"><?= count($holdings) ?></div>
  </div>
</div>

<div class="report-chart-wrap" style="max-width:360px;margin:0 auto 28px">
  <canvas id="snapshotChart"></canvas>
</div>

<table class="table table-sm report-table">
  <thead>
    <tr>
      <th class="text-end" style="width:2.5rem">#</th>
      <th>Security</th>
      <th>Type</th>
      <th class="text-end">Shares</th>
      <th class="text-end">Market Value</th>
      <th class="text-end">% of Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($holdings as $rank => $h): ?>
    <tr>
      <td class="text-end text-muted"><?= $rank + 1 ?></td>
      <td>
        <?php $secSlug = !empty($h['symbol']) ? urlencode($h['symbol']) : $h['invId']; ?>
        <a href="<?= BASE_PATH ?>/portfolio/security/<?= $secSlug ?>" class="inv-name-link">
          <strong><?= h($h['inv_name']) ?></strong>
          <?php if ($h['symbol']): ?><span class="text-muted small ms-1"><?= h($h['symbol']) ?></span><?php endif; ?>
        </a>
      </td>
      <td class="text-muted small"><?= h($h['inv_type']) ?></td>
      <td class="text-end"><?= rtrim(rtrim(number_format($h['qty'], 6), '0'), '.') ?></td>
      <td class="text-end"><?= formatMoney($h['marketValue']) ?></td>
      <td class="text-end"><?= $totalMV > 0 ? number_format($h['marketValue'] / $totalMV * 100, 1) . '%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="4"><strong>Total</strong></td>
      <td class="text-end"><strong><?= formatMoney($totalMV) ?></strong></td>
      <td class="text-end"><strong>100.0%</strong></td>
    </tr>
  </tfoot>
</table>

<div class="mt-3 d-print-none">
  <button type="button" id="btnShowOnDash" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-grid-3x3-gap"></i> Show on Dashboard
  </button>
</div>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php if (!empty($holdings)): ?>
<script>
(function(){
  const labels = <?= json_encode($chartLabels) ?>;
  const values = <?= json_encode($chartValues) ?>;
  const colors = <?= json_encode($chartColors) ?>;
  const totalMV = <?= round($totalMV, 2) ?>;

  const ctx = document.getElementById('snapshotChart');
  if (ctx) {
    new Chart(ctx, {
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
                return ' ' + c.label + ': $' + parseFloat(c.raw).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });
  }

  // Show on Dashboard button
  const btn = document.getElementById('btnShowOnDash');
  if (btn) {
    btn.addEventListener('click', async function() {
      btn.disabled = true;
      const acctInput = document.getElementById('acctHidden');
      const accts = acctInput ? acctInput.value : '';
      const excludeChecked = Array.from(document.querySelectorAll('input[name="exclude_types[]"]:checked')).map(cb => cb.value);
      const excludeTypes = excludeChecked.join(',');

      try {
        const res = await fetch(<?= json_encode(BASE_PATH) ?> + '/dashboard/widget_visibility', {
          method: 'POST',
          body: new URLSearchParams({
            csrf_token: <?= json_encode(csrfToken()) ?>,
            action: 'show',
            widget: 'portfolio_snapshot',
            accts: accts,
            exclude_types: excludeTypes
          })
        }).then(r => r.json());

        if (res.ok) {
          const a = document.createElement('a');
          a.className = 'btn btn-sm btn-success';
          a.href = <?= json_encode(BASE_PATH) ?> + '/index';
          a.innerHTML = '<i class="bi bi-check-lg"></i> On Dashboard — View ›';
          btn.replaceWith(a);
        } else {
          showToast(res.error || 'Error updating dashboard.', 'error');
          btn.disabled = false;
        }
      } catch(e) {
        console.error(e);
        showToast('Network error.', 'error');
        btn.disabled = false;
      }
    });
  }
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/price_history_modal.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

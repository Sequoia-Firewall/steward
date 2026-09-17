<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Investments with price history (non-index) ─────────────────
$allInvestments = $db->query(
    "SELECT DISTINCT i.id, i.name, i.symbol, i.type
     FROM investments i
     INNER JOIN investment_prices ip ON ip.investment_id = i.id
     WHERE i.is_active = 1 AND i.type != 'Index'
     ORDER BY i.name"
)->fetchAll();

// ── Indexes with price history ─────────────────────────────────
$allIndexes = $db->query(
    "SELECT DISTINCT i.id, i.name, i.symbol
     FROM investments i
     INNER JOIN investment_prices ip ON ip.investment_id = i.id
     WHERE i.is_active = 1 AND i.type = 'Index'
     ORDER BY i.name"
)->fetchAll();

$allInvIds = array_map('intval', array_column($allInvestments, 'id'));
$allIdxIds = array_map('intval', array_column($allIndexes,     'id'));

// ── Parse params ────────────────────────────────────────────────
$defaultFrom = date('Y-m-d', strtotime('-1 year'));
$defaultTo   = date('Y-m-d');

$fromDate = $_GET['from'] ?? $defaultFrom;
$toDate   = $_GET['to']   ?? $defaultTo;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = $defaultTo;
if ($toDate < $fromDate) $toDate = $fromDate;

$invParam  = trim($_GET['invs'] ?? '');
$idxParam  = trim($_GET['idxs'] ?? '');
$hasParams = isset($_GET['invs']) || isset($_GET['from']);

if (!$hasParams && !empty($allInvIds)) {
    // Default: first few investments alphabetically
    $selectedInvIds = array_slice($allInvIds, 0, min(5, count($allInvIds)));
} elseif ($invParam === '') {
    $selectedInvIds = [];
} else {
    $selectedInvIds = array_values(array_filter(
        array_map('intval', explode(',', $invParam)),
        fn($id) => in_array($id, $allInvIds, true)
    ));
}

if ($idxParam === '') {
    $selectedIdxIds = [];
} else {
    $selectedIdxIds = array_values(array_filter(
        array_map('intval', explode(',', $idxParam)),
        fn($id) => in_array($id, $allIdxIds, true)
    ));
}

$allSelectedIds = array_merge($selectedInvIds, $selectedIdxIds);

// ── Metadata map ────────────────────────────────────────────────
$invMeta = [];
foreach (array_merge($allInvestments, $allIndexes) as $inv) {
    $invMeta[(int)$inv['id']] = $inv;
}

// ── Build normalized % return series ───────────────────────────
$palette = [
    '#1a5fb4', '#e01b24', '#2ec27e', '#e5a50a', '#9141ac',
    '#f66151', '#62a0ea', '#33d17a', '#ffa348', '#c061cb',
    '#865e3c', '#3584e4',
];

$perf  = getInvestmentPerformanceSeries($allSelectedIds, $fromDate, $toDate);
$dates = $perf['dates'];

// Cost & Profit Analysis + Total Return overlay data — securities only (indexes
// aren't "held", so they have no cost basis/distributions to fold in).
$cpa = getInvestmentCostProfitAnalysis($selectedInvIds, $fromDate, $toDate);

$seriesData = [];
$colorIdx   = 0;

foreach ($allSelectedIds as $id) {
    $s = $perf['series'][$id] ?? null;
    if ($s === null) continue;

    $meta    = $invMeta[$id] ?? ['name' => "Investment $id", 'symbol' => '', 'type' => ''];
    $label   = $meta['symbol'] ?: $meta['name'];
    $isIndex = in_array($id, $selectedIdxIds, true);
    $color   = $palette[$colorIdx % count($palette)];
    $colorIdx++;

    $totalReturnValues = null;
    if (!$isIndex && isset($cpa[$id])) {
        $totalReturnValues = investmentTotalReturnOverlay($dates, $s['values'], $s['basePrice'], $cpa[$id]['distributions']);
    }

    $seriesData[] = [
        'id'                => $id,
        'label'             => $label,
        'fullName'          => $meta['name'],
        'symbol'            => $meta['symbol'],
        'isIndex'           => $isIndex,
        'color'             => $color,
        'values'            => $s['values'],
        'totalReturnValues' => $totalReturnValues,
        'firstDate'         => $s['firstDate'],
        'lastDate'          => $s['lastDate'],
        'basePrice'         => $s['basePrice'],
        'lastPrice'         => $s['lastPrice'],
        'periodReturn'      => $s['periodReturn'],
        'annualReturn'      => $s['annualReturn'],
    ];
}

// ── Filter button labels ────────────────────────────────────────
$invLabelArr = [];
foreach ($selectedInvIds as $id) {
    $m = $invMeta[$id] ?? null;
    if ($m) $invLabelArr[] = $m['symbol'] ?: $m['name'];
}
$invBtnLabel = empty($selectedInvIds) ? 'None'
    : (count($selectedInvIds) === 1 ? $invLabelArr[0] : count($selectedInvIds) . ' Securities');

$idxLabelArr = [];
foreach ($selectedIdxIds as $id) {
    $m = $invMeta[$id] ?? null;
    if ($m) $idxLabelArr[] = $m['symbol'] ?: $m['name'];
}
$idxBtnLabel = empty($selectedIdxIds) ? 'None'
    : (count($selectedIdxIds) === 1 ? $idxLabelArr[0] : count($selectedIdxIds) . ' Indexes');

$pageTitle   = 'Investment Performance';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Investment Performance'; $reportFavIcon = 'bi-graph-up-arrow'; ?>
<div class="page-header">
  <h2><i class="bi bi-graph-up-arrow"></i> Investment Performance</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="perfForm">

  <div class="filter-group">
    <label>Securities</label>
    <input type="hidden" name="invs" id="invsHidden" value="<?= h($invParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown" style="min-width:130px">
        <span id="invsBtnLabel"><?= h($invBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu p-2" style="max-height:300px;overflow-y:auto;min-width:230px">
        <?php if (empty($allInvestments)): ?>
        <li class="px-2 text-muted small">No investments with price history</li>
        <?php else: ?>
        <?php foreach ($allInvestments as $inv): ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" class="inv-chk" value="<?= (int)$inv['id'] ?>"
                   data-label="<?= h($inv['symbol'] ?: $inv['name']) ?>"
                   <?= in_array((int)$inv['id'], $selectedInvIds, true) ? 'checked' : '' ?>>
            <span><?= h($inv['name']) ?></span>
            <?php if ($inv['symbol']): ?>
            <span class="ms-auto text-muted small text-nowrap"><?= h($inv['symbol']) ?></span>
            <?php endif; ?>
          </label>
        </li>
        <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>

  <?php if (!empty($allIndexes)): ?>
  <div class="filter-group">
    <label>Compare to Index</label>
    <input type="hidden" name="idxs" id="idxsHidden" value="<?= h($idxParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown" style="min-width:130px">
        <span id="idxsBtnLabel"><?= h($idxBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu p-2" style="max-height:300px;overflow-y:auto;min-width:210px">
        <?php foreach ($allIndexes as $inv): ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" class="idx-chk" value="<?= (int)$inv['id'] ?>"
                   data-label="<?= h($inv['symbol'] ?: $inv['name']) ?>"
                   <?= in_array((int)$inv['id'], $selectedIdxIds, true) ? 'checked' : '' ?>>
            <span><?= h($inv['name']) ?></span>
            <?php if ($inv['symbol']): ?>
            <span class="ms-auto text-muted small text-nowrap"><?= h($inv['symbol']) ?></span>
            <?php endif; ?>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php endif; ?>

  <div class="filter-group">
    <label>From</label>
    <input type="date" name="from" id="dateFrom" value="<?= h($fromDate) ?>"
           class="form-control form-control-sm" style="width:auto">
  </div>
  <div class="filter-group">
    <label>To</label>
    <input type="date" name="to" id="dateTo" value="<?= h($toDate) ?>"
           class="form-control form-control-sm" style="width:auto">
  </div>

  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
  </div>
</form>

<div class="mb-3 d-flex gap-1 flex-wrap align-items-center">
  <span class="text-muted small me-1">Range:</span>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-months="1">1M</button>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-months="3">3M</button>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-months="6">6M</button>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-ytd="1">YTD</button>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-months="12">1Y</button>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-months="24">2Y</button>
  <button type="button" class="btn btn-xs btn-outline-secondary date-preset" data-months="60">5Y</button>
  <?php if (!empty(array_filter($seriesData, fn($s) => $s['totalReturnValues'] !== null))): ?>
  <label class="ms-2 small d-flex align-items-center gap-1" style="cursor:pointer">
    <input type="checkbox" id="includeDividends" class="form-check-input mt-0">
    Include Dividends
  </label>
  <?php endif; ?>
</div>

<?php if (empty($allSelectedIds)): ?>
<p class="text-muted">Select one or more securities above to compare performance.</p>
<?php elseif (empty($seriesData)): ?>
<div class="alert alert-info py-2 px-3" style="font-size:.875rem">
  <i class="bi bi-info-circle"></i>
  No price history found for the selected securities in this date range.
  Add prices via the Portfolio page.
</div>
<?php else: ?>

<div class="report-chart-wrap" style="max-height:420px">
  <canvas id="perfChart"></canvas>
</div>

<h3 class="report-section-title mt-4">Performance Summary</h3>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Security</th>
      <th class="text-end">Start Date</th>
      <th class="text-end">Start Price</th>
      <th class="text-end">End Date</th>
      <th class="text-end">End Price</th>
      <th class="text-end">Period Return</th>
      <th class="text-end">Ann. Return</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($seriesData as $s):
      $rCls = $s['periodReturn'] >= 0 ? 'amount-credit' : 'amount-debit';
      $aCls = $s['annualReturn'] !== null ? ($s['annualReturn'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
    ?>
    <tr>
      <td>
        <span class="d-inline-block me-2 flex-shrink-0" style="width:12px;height:12px;border-radius:2px;
              vertical-align:middle;
              <?= $s['isIndex']
                  ? 'background:transparent;border:2px dashed ' . h($s['color'])
                  : 'background:' . h($s['color']) ?>"></span>
        <strong><?= h($s['fullName']) ?></strong>
        <?php if ($s['symbol']): ?>
        <span class="text-muted small ms-1"><?= h($s['symbol']) ?></span>
        <?php endif; ?>
        <?php if ($s['isIndex']): ?>
        <span class="badge bg-secondary ms-1" style="font-size:.65rem">Index</span>
        <?php endif; ?>
      </td>
      <td class="text-end text-muted small"><?= formatDate($s['firstDate']) ?></td>
      <td class="text-end"><?= formatMoney($s['basePrice']) ?></td>
      <td class="text-end text-muted small"><?= formatDate($s['lastDate']) ?></td>
      <td class="text-end"><?= formatMoney($s['lastPrice']) ?></td>
      <td class="text-end <?= $rCls ?>">
        <strong><?= ($s['periodReturn'] >= 0 ? '+' : '') . number_format($s['periodReturn'], 2) ?>%</strong>
      </td>
      <td class="text-end <?= $aCls ?>">
        <?php if ($s['annualReturn'] !== null): ?>
          <?= ($s['annualReturn'] >= 0 ? '+' : '') . number_format($s['annualReturn'], 2) ?>%/yr
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if (!empty($cpa)): ?>
<h3 class="report-section-title mt-4">Cost &amp; Profit Analysis</h3>
<p class="text-muted small">
  For securities currently held as of <?= formatDate($toDate) ?>. Total Profit and Total Return %
  are approximations for this date range — they divide the change in unrealized gain/loss, plus
  distributions received, plus realized gains/losses on any shares sold during the period, by the
  <em>average</em> of the start- and end-of-period cost basis, not a true money-weighted return.
</p>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Security</th>
      <th class="text-end">Shares</th>
      <th class="text-end">Avg Cost</th>
      <th class="text-end">Cost Basis</th>
      <th class="text-end">Market Value</th>
      <th class="text-end">Unrealized G/L</th>
      <th class="text-end">Div/Interest</th>
      <th class="text-end">Reinvested</th>
      <th class="text-end">Total Distrib.</th>
      <th class="text-end">Realized G/L</th>
      <th class="text-end">Total Profit</th>
      <th class="text-end">Total Return</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($cpa as $iid => $r):
      $meta    = $invMeta[$iid] ?? ['name' => "Investment $iid", 'symbol' => ''];
      $uglCls  = $r['unrealizedGainLoss'] !== null ? ($r['unrealizedGainLoss'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
      $rglCls  = $r['realizedGainLoss']    >= 0 ? 'amount-credit' : 'amount-debit';
      $tpCls   = $r['totalProfit']        !== null ? ($r['totalProfit']        >= 0 ? 'amount-credit' : 'amount-debit') : '';
      $trCls   = $r['totalReturnPct']     !== null ? ($r['totalReturnPct']     >= 0 ? 'amount-credit' : 'amount-debit') : '';
    ?>
    <tr>
      <td>
        <?php if (!empty($r['distributions'])): ?>
        <button type="button" class="btn btn-link p-0 cpa-dist-link" data-id="<?= $iid ?>">
          <strong><?= h($meta['name']) ?></strong>
        </button>
        <?php else: ?>
        <strong><?= h($meta['name']) ?></strong>
        <?php endif; ?>
        <?php if ($meta['symbol']): ?>
        <span class="text-muted small ms-1"><?= h($meta['symbol']) ?></span>
        <?php endif; ?>
      </td>
      <td class="text-end"><?= rtrim(rtrim(number_format($r['qty'], 6), '0'), '.') ?></td>
      <td class="text-end"><?= $r['avgCost'] > 0 ? formatMoney($r['avgCost']) : '—' ?></td>
      <td class="text-end"><?= formatMoney($r['costBasis']) ?></td>
      <td class="text-end"><?= $r['marketValue'] !== null ? formatMoney($r['marketValue']) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end <?= $uglCls ?>">
        <?php if ($r['unrealizedGainLoss'] !== null): ?>
          <?= ($r['unrealizedGainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($r['unrealizedGainLoss'])) ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
      <td class="text-end"><?= $r['dividendsInterest'] > 0 ? formatMoney($r['dividendsInterest']) : '—' ?></td>
      <td class="text-end"><?= $r['reinvestedDistributions'] > 0 ? formatMoney($r['reinvestedDistributions']) : '—' ?></td>
      <td class="text-end"><?= $r['totalDistributions'] > 0 ? formatMoney($r['totalDistributions']) : '—' ?></td>
      <td class="text-end <?= $r['realizedGainLoss'] != 0 ? $rglCls : '' ?>">
        <?php if ($r['realizedGainLoss'] != 0): ?>
          <?= ($r['realizedGainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($r['realizedGainLoss'])) ?>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="text-end <?= $tpCls ?>">
        <?php if ($r['totalProfit'] !== null): ?>
          <?= ($r['totalProfit'] >= 0 ? '+' : '-') . formatMoney(abs($r['totalProfit'])) ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
      <td class="text-end <?= $trCls ?>">
        <?php if ($r['totalReturnPct'] !== null): ?>
          <strong><?= ($r['totalReturnPct'] >= 0 ? '+' : '') . number_format($r['totalReturnPct'], 2) ?>%</strong>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if (count($cpa) > 1):
    $totCostBasis   = 0.0; $totMarketValue = 0.0; $hasNullMarket = false;
    $totUnrealized  = 0.0; $hasNullUnrealized = false;
    $totDivInt      = 0.0; $totReinvested  = 0.0; $totDistrib = 0.0;
    $totRealized    = 0.0; $totProfit      = 0.0; $hasNullProfit = false;
    $totReturnBase  = 0.0;
    foreach ($cpa as $r) {
        $totCostBasis  += $r['costBasis'];
        if ($r['marketValue'] === null) $hasNullMarket = true; else $totMarketValue += $r['marketValue'];
        if ($r['unrealizedGainLoss'] === null) $hasNullUnrealized = true; else $totUnrealized += $r['unrealizedGainLoss'];
        $totDivInt     += $r['dividendsInterest'];
        $totReinvested += $r['reinvestedDistributions'];
        $totDistrib    += $r['totalDistributions'];
        $totRealized   += $r['realizedGainLoss'];
        if ($r['totalProfit'] === null) $hasNullProfit = true; else $totProfit += $r['totalProfit'];
        $totReturnBase += $r['returnBase'];
    }
    // Matches the per-row methodology: divide aggregate profit by the SUM of
    // each row's own average of start/end cost basis, not by the summed
    // end-of-period cost basis alone — the latter overstates the portfolio
    // total return for the same reason it overstates any individual row.
    $totReturnPct = (!$hasNullProfit && $totReturnBase > 0.000001) ? ($totProfit / $totReturnBase) * 100 : null;
    $totUglCls = $hasNullUnrealized ? '' : ($totUnrealized >= 0 ? 'amount-credit' : 'amount-debit');
    $totRglCls = $totRealized >= 0 ? 'amount-credit' : 'amount-debit';
    $totTpCls  = $hasNullProfit ? '' : ($totProfit >= 0 ? 'amount-credit' : 'amount-debit');
    $totTrCls  = $totReturnPct !== null ? ($totReturnPct >= 0 ? 'amount-credit' : 'amount-debit') : '';
  ?>
  <tfoot>
    <tr class="fw-bold">
      <td>Total</td>
      <td class="text-end">—</td>
      <td class="text-end">—</td>
      <td class="text-end"><?= formatMoney($totCostBasis) ?></td>
      <td class="text-end"><?= $hasNullMarket ? '<span class="text-muted">—</span>' : formatMoney($totMarketValue) ?></td>
      <td class="text-end <?= $totUglCls ?>">
        <?php if (!$hasNullUnrealized): ?>
          <?= ($totUnrealized >= 0 ? '+' : '-') . formatMoney(abs($totUnrealized)) ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
      <td class="text-end"><?= $totDivInt > 0 ? formatMoney($totDivInt) : '—' ?></td>
      <td class="text-end"><?= $totReinvested > 0 ? formatMoney($totReinvested) : '—' ?></td>
      <td class="text-end"><?= $totDistrib > 0 ? formatMoney($totDistrib) : '—' ?></td>
      <td class="text-end <?= $totRealized != 0 ? $totRglCls : '' ?>">
        <?php if ($totRealized != 0): ?>
          <?= ($totRealized >= 0 ? '+' : '-') . formatMoney(abs($totRealized)) ?>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="text-end <?= $totTpCls ?>">
        <?php if (!$hasNullProfit): ?>
          <?= ($totProfit >= 0 ? '+' : '-') . formatMoney(abs($totProfit)) ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
      <td class="text-end <?= $totTrCls ?>">
        <?php if ($totReturnPct !== null): ?>
          <strong><?= ($totReturnPct >= 0 ? '+' : '') . number_format($totReturnPct, 2) ?>%</strong>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
<?php endif; ?>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php if (!empty($seriesData)): ?>
<script>
(function(){
  const labels     = <?= json_encode(array_map(fn($d) => date('M j, Y', strtotime($d)), $dates)) ?>;
  const priceData  = <?= json_encode(array_map(fn($s) => $s['values'],            $seriesData)) ?>;
  const totalData  = <?= json_encode(array_map(fn($s) => $s['totalReturnValues'], $seriesData)) ?>;
  const datasets   = <?= json_encode(array_map(fn($s) => [
      'label'       => $s['label'],
      'data'        => $s['values'],
      'borderColor' => $s['color'],
      'backgroundColor' => 'transparent',
      'borderWidth' => $s['isIndex'] ? 1.5 : 2,
      'borderDash'  => $s['isIndex'] ? [6, 3] : [],
      'pointRadius' => 0,
      'tension'     => 0.1,
      'spanGaps'    => true,
  ], $seriesData)) ?>;

  const perfChart = new Chart(document.getElementById('perfChart'), {
    type: 'line',
    data: { labels, datasets },
    options: {
      animation: false,
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'top',
          labels: { font: { size: 12 }, boxWidth: 24 }
        },
        tooltip: {
          callbacks: {
            label: c => ' ' + c.dataset.label + ': ' +
              (c.raw !== null ? (c.raw >= 0 ? '+' : '') + c.raw.toFixed(2) + '%' : '—')
          }
        }
      },
      scales: {
        x: {
          ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
          grid:  { display: false }
        },
        y: {
          ticks: {
            font: { size: 10 },
            callback: v => (v >= 0 ? '+' : '') + v.toFixed(1) + '%'
          },
          grid: { color: '#eee' }
        }
      }
    }
  });

  const divChk = document.getElementById('includeDividends');
  if (divChk) {
    divChk.addEventListener('change', () => {
      perfChart.data.datasets.forEach((ds, i) => {
        ds.data = (divChk.checked && totalData[i]) ? totalData[i] : priceData[i];
      });
      perfChart.update();
    });
  }
})();
</script>
<?php endif; ?>

<script>
(function(){
  // Securities multi-select
  const invsHidden   = document.getElementById('invsHidden');
  const invsBtnLabel = document.getElementById('invsBtnLabel');
  const invChks      = Array.from(document.querySelectorAll('.inv-chk'));

  function updateInvs() {
    const checked = invChks.filter(c => c.checked);
    invsHidden.value = checked.map(c => c.value).join(',');
    invsBtnLabel.textContent = checked.length === 0 ? 'None'
      : checked.length === 1 ? checked[0].dataset.label
      : checked.length + ' Securities';
  }
  invChks.forEach(c => c.addEventListener('change', updateInvs));

  // Index multi-select
  const idxsHidden   = document.getElementById('idxsHidden');
  const idxsBtnLabel = document.getElementById('idxsBtnLabel');
  const idxChks      = Array.from(document.querySelectorAll('.idx-chk'));

  function updateIdxs() {
    const checked = idxChks.filter(c => c.checked);
    idxsHidden.value = checked.map(c => c.value).join(',');
    idxsBtnLabel.textContent = checked.length === 0 ? 'None'
      : checked.length === 1 ? checked[0].dataset.label
      : checked.length + ' Indexes';
  }
  idxChks.forEach(c => c.addEventListener('change', updateIdxs));

  // Date presets
  const fromInput = document.getElementById('dateFrom');
  const toInput   = document.getElementById('dateTo');
  const today     = new Date().toISOString().slice(0, 10);

  document.querySelectorAll('.date-preset').forEach(btn => {
    btn.addEventListener('click', () => {
      toInput.value = today;
      if (btn.dataset.ytd) {
        fromInput.value = today.slice(0, 4) + '-01-01';
      } else {
        const months = parseInt(btn.dataset.months);
        const d = new Date();
        d.setMonth(d.getMonth() - months);
        fromInput.value = d.toISOString().slice(0, 10);
      }
      document.getElementById('perfForm').submit();
    });
  });
})();
</script>

<?php if (!empty($cpa)): ?>
<div class="modal fade" id="cpaDistModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cpaDistModalTitle">Distributions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
          <tbody id="cpaDistModalBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  const CPA_BY_ID = <?= json_encode(array_combine(
      array_map('strval', array_keys($cpa)),
      array_map(fn($iid) => [
          'name'   => $invMeta[$iid]['name']   ?? '',
          'symbol' => $invMeta[$iid]['symbol'] ?? '',
          'rows'   => $cpa[$iid]['distributions'],
      ], array_keys($cpa))
  )) ?>;
  const DIST_LABELS = { div: 'Dividend', int: 'Interest', reinvest_div: 'Reinvest Div.', reinvest_cap: 'Reinvest Cap Gain' };

  document.querySelectorAll('.cpa-dist-link').forEach(btn => {
    btn.addEventListener('click', () => {
      const data = CPA_BY_ID[btn.dataset.id];
      if (!data) return;
      document.getElementById('cpaDistModalTitle').textContent =
        data.name + (data.symbol ? ' (' + data.symbol + ')' : '') + ' — Distributions';
      document.getElementById('cpaDistModalBody').innerHTML = data.rows.map(r => {
        const [y, m, d] = r.date.split('-');
        return '<tr><td>' + m + '/' + d + '/' + y + '</td><td>' + (DIST_LABELS[r.activity] || r.activity) +
               '</td><td class="text-end">' + r.amount.toLocaleString('en-US', { style: 'currency', currency: 'USD' }) + '</td></tr>';
      }).join('');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('cpaDistModal')).show();
    });
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fund_holdings_fetcher.php';
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
        COALESCE(SUM(CASE
            WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
            WHEN it.activity IN ('sell','remove')                                   THEN -it.quantity
            ELSE 0
        END), 0) AS net_qty
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN investments   i ON i.id = it.investment_id
     JOIN accounts      a ON a.id = t.account_id
     WHERE a.is_investment_cash = 0 AND a.is_closed = 0 AND i.is_active = 1
       $acctWhere
     GROUP BY i.id, i.name, i.symbol, i.type
     HAVING net_qty > 0.000001
     ORDER BY i.name"
);
$stmt->execute($acctParams);
$rawRows = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();
$fetcher      = new FundHoldingsFetcher($db);

// Investment types whose constituents we look up
$fundTypes = ['ETF', 'Mutual Fund', 'Index'];

// ── Separate positions into direct holdings vs. funds ──────────
$directPositions = [];
$fundPositions   = [];
$portfolioMV     = 0.0;

foreach ($rawRows as $r) {
    $invId = (int)$r['inv_id'];
    $qty   = (float)$r['net_qty'];
    $price = $latestPrices[$invId]['price'] ?? null;
    if ($price === null) continue;

    $mv  = $price * $qty;
    $portfolioMV += $mv;

    $pos = [
        'inv_id'   => $invId,
        'inv_name' => $r['inv_name'],
        'symbol'   => strtoupper(trim($r['symbol'] ?? '')),
        'inv_type' => $r['inv_type'],
        'qty'      => $qty,
        'price'    => $price,
        'mv'       => $mv,
    ];

    if (in_array($r['inv_type'], $fundTypes, true) && $pos['symbol'] !== '') {
        $fundPositions[] = $pos;
    } else {
        $directPositions[] = $pos;
    }
}

// ── Fetch any missing fund holdings in parallel ────────────────
$fundSymbols = array_column($fundPositions, 'symbol');
$fetcher->batchFetch($fundSymbols); // no-op if all cached

// ── Build effective exposure map ───────────────────────────────
// keyed by uppercase ticker (or inv_name for symbol-less positions)
$exposure = [];

foreach ($directPositions as $p) {
    $key = $p['symbol'] ?: $p['inv_name'];
    if (!isset($exposure[$key])) {
        $exposure[$key] = [
            'symbol'             => $key,
            'name'               => $p['inv_name'],
            'direct_value'       => 0.0,
            'via_funds_value'    => 0.0,
            'fund_contributions' => [],
        ];
    }
    $exposure[$key]['direct_value'] += $p['mv'];
}

$fundCoverage = []; // per-fund metadata for the coverage table
$fundsMV      = 0.0;
$coveredMV    = 0.0;

foreach ($fundPositions as $p) {
    $holdings  = $fetcher->getHoldings($p['symbol']);
    $sumWeight = array_sum(array_column($holdings, 'weight_pct'));
    $fundsMV  += $p['mv'];
    $coveredMV += $p['mv'] * min($sumWeight, 100.0) / 100.0;

    $fundCoverage[$p['symbol']] = [
        'inv_name'   => $p['inv_name'],
        'inv_type'   => $p['inv_type'],
        'mv'         => $p['mv'],
        'count'      => count($holdings),
        'sum_weight' => $sumWeight,
        'fetched_at' => $fetcher->getFetchedAt($p['symbol']),
        'has_data'   => !empty($holdings),
    ];

    foreach ($holdings as $h) {
        $sym            = strtoupper($h['constituent_symbol']);
        $weight         = (float)$h['weight_pct'];
        $effectiveValue = $p['mv'] * $weight / 100.0;

        if (!isset($exposure[$sym])) {
            $exposure[$sym] = [
                'symbol'             => $sym,
                'name'               => $h['constituent_name'] ?: $sym,
                'direct_value'       => 0.0,
                'via_funds_value'    => 0.0,
                'fund_contributions' => [],
            ];
        }
        $exposure[$sym]['via_funds_value'] += $effectiveValue;
        $exposure[$sym]['fund_contributions'][] = [
            'fund_symbol' => $p['symbol'],
            'fund_name'   => $p['inv_name'],
            'weight_pct'  => $weight,
            'value'       => $effectiveValue,
        ];
    }
}

// Sort each position's fund contributions by value desc
foreach ($exposure as &$e) {
    usort($e['fund_contributions'], fn($a, $b) => $b['value'] <=> $a['value']);
    $e['total_value'] = $e['direct_value'] + $e['via_funds_value'];
}
unset($e);

// Sort exposure by total value desc
uasort($exposure, fn($a, $b) => $b['total_value'] <=> $a['total_value']);
$exposureList = array_values($exposure);

$top50     = array_slice($exposureList, 0, 50);
$otherRows = array_slice($exposureList, 50);
$otherMV   = array_sum(array_column($otherRows, 'total_value'));

$totalIdentified = array_sum(array_column($exposureList, 'total_value'));

$needsData = array_filter($fundCoverage, fn($f) => !$f['has_data'] || $f['sum_weight'] < 1.0);
$csrfTok   = csrfToken();

$pageTitle   = 'Stock Exposure';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>
<style>
.exposure-expand-btn { cursor: pointer; user-select: none; }
.exposure-expand-btn .bi { transition: transform .15s; font-size: .75rem; color: #8899aa; }
.exposure-expand-btn.open .bi { transform: rotate(90deg); }
.fund-detail-row td { background: #f8fafc; font-size: .82rem; padding: 4px 8px 4px 2.5rem; }
.fund-detail-row td:first-child { padding-left: 2.5rem; }
.coverage-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
.refreshing { opacity: .5; pointer-events: none; }
</style>

<div class="page-header">
  <h2><i class="bi bi-layers"></i> Stock Exposure</h2>
  <?php $reportFavTitle = 'Stock Exposure'; $reportFavIcon = 'bi-layers'; ?>
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
  </div>
</form>

<?php if ($portfolioMV <= 0): ?>
<p class="text-muted">No investment holdings with price data found.</p>
<?php else: ?>

<?php
$identifiedPct  = $portfolioMV > 0 ? $totalIdentified / $portfolioMV * 100 : 0;
$fundCount      = count($fundPositions);
$hasFunds       = $fundCount > 0;
?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Portfolio Value</div>
    <div class="tile-value"><?= formatMoney($portfolioMV) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Identified Exposure</div>
    <div class="tile-value"><?= formatMoney($totalIdentified) ?></div>
    <div class="tile-sub text-muted"><?= number_format($identifiedPct, 1) ?>% of portfolio</div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Unique Securities</div>
    <div class="tile-value"><?= count($exposureList) ?></div>
    <div class="tile-sub text-muted"><?= count($directPositions) ?> direct &middot; <?= count($exposureList) - count($directPositions) ?> via funds</div>
  </div>
</div>

<?php if (!empty($needsData)): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3 d-print-none" id="noDataBanner">
  <i class="bi bi-exclamation-triangle-fill"></i>
  <div>
    Holdings data unavailable for <strong><?= count($needsData) ?></strong>
    fund<?= count($needsData) !== 1 ? 's' : '' ?>
    (<?= h(implode(', ', array_keys($needsData))) ?>).
    Constituent-level exposure for these funds is not reflected above.
  </div>
  <button type="button" class="btn btn-sm btn-warning ms-auto"
          id="btnFetchMissing"
          data-symbols="<?= h(implode(',', array_keys($needsData))) ?>">
    <i class="bi bi-download"></i> Fetch Holdings
  </button>
</div>
<?php endif; ?>

<?php if (empty($exposureList)): ?>
<p class="text-muted">No exposure data available.</p>
<?php else: ?>

<h5 class="mt-3 mb-2" style="font-size:.95rem;font-weight:600">
  Top <?= count($top50) ?> Securities by Effective Exposure
  <span class="text-muted fw-normal" style="font-size:.8rem">
    — direct holdings + weighted fund constituents
  </span>
</h5>

<table class="table table-sm report-table" id="exposureTable">
  <thead>
    <tr>
      <th style="width:2rem" class="text-end">#</th>
      <th>Symbol / Security</th>
      <th class="text-end">Direct</th>
      <th class="text-end">Via Funds</th>
      <th class="text-end">Total Exposure</th>
      <th class="text-end">% Portfolio</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($top50 as $rank => $e): ?>
    <?php $hasContribs = !empty($e['fund_contributions']); ?>
    <tr class="<?= $hasContribs ? 'exposure-expand-btn' : '' ?>"
        <?= $hasContribs ? 'onclick="toggleDetail(this)"' : '' ?>>
      <td class="text-end text-muted"><?= $rank + 1 ?></td>
      <td>
        <?php if ($hasContribs): ?>
          <i class="bi bi-chevron-right me-1"></i>
        <?php endif; ?>
        <strong><?= h($e['symbol']) ?></strong>
        <?php if ($e['name'] !== $e['symbol']): ?>
          <span class="text-muted small ms-1"><?= h($e['name']) ?></span>
        <?php endif; ?>
      </td>
      <td class="text-end">
        <?= $e['direct_value'] > 0 ? formatMoney($e['direct_value']) : '<span class="text-muted">—</span>' ?>
      </td>
      <td class="text-end">
        <?= $e['via_funds_value'] > 0 ? formatMoney($e['via_funds_value']) : '<span class="text-muted">—</span>' ?>
      </td>
      <td class="text-end fw-semibold"><?= formatMoney($e['total_value']) ?></td>
      <td class="text-end">
        <?= $portfolioMV > 0 ? number_format($e['total_value'] / $portfolioMV * 100, 2) . '%' : '—' ?>
      </td>
    </tr>
    <?php if ($hasContribs): ?>
    <tr class="fund-detail-row d-none" id="detail-<?= $rank ?>">
      <td colspan="6">
        <table class="table table-sm mb-0" style="font-size:.82rem">
          <thead class="text-muted">
            <tr>
              <th>Fund</th>
              <th class="text-end">Weight in Fund</th>
              <th class="text-end">Effective Value</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($e['direct_value'] > 0): ?>
            <tr>
              <td><em>Direct holding</em></td>
              <td class="text-end">—</td>
              <td class="text-end"><?= formatMoney($e['direct_value']) ?></td>
            </tr>
            <?php endif; ?>
            <?php foreach ($e['fund_contributions'] as $fc): ?>
            <tr>
              <td><?= h($fc['fund_symbol']) ?>
                <span class="text-muted ms-1"><?= h($fc['fund_name']) ?></span>
              </td>
              <td class="text-end"><?= number_format($fc['weight_pct'], 2) ?>%</td>
              <td class="text-end"><?= formatMoney($fc['value']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($otherMV > 0.01): ?>
    <tr class="text-muted">
      <td class="text-end">—</td>
      <td><em>Other (<?= count($otherRows) ?> securities)</em></td>
      <td class="text-end">—</td>
      <td class="text-end">—</td>
      <td class="text-end"><?= formatMoney($otherMV) ?></td>
      <td class="text-end"><?= $portfolioMV > 0 ? number_format($otherMV / $portfolioMV * 100, 2) . '%' : '—' ?></td>
    </tr>
    <?php endif; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="4"><strong>Identified Total</strong></td>
      <td class="text-end"><strong><?= formatMoney($totalIdentified) ?></strong></td>
      <td class="text-end"><strong><?= $portfolioMV > 0 ? number_format($totalIdentified / $portfolioMV * 100, 1) . '%' : '—' ?></strong></td>
    </tr>
  </tfoot>
</table>

<?php endif; ?>

<?php if (!empty($fundCoverage)): ?>
<h5 class="mt-4 mb-2" style="font-size:.95rem;font-weight:600">Fund Holdings Coverage</h5>
<table class="table table-sm report-table" id="coverageTable">
  <thead>
    <tr>
      <th>Fund</th>
      <th>Type</th>
      <th class="text-end">Market Value</th>
      <th class="text-end">Holdings Tracked</th>
      <th class="text-end">Coverage</th>
      <th class="text-end d-print-none">Last Fetched</th>
      <th class="d-print-none"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($fundCoverage as $sym => $fc): ?>
    <?php
        $coveragePct = min((float)$fc['sum_weight'], 100.0);
        $dotColor = $coveragePct >= 50 ? '#2ecc71' : ($coveragePct >= 10 ? '#f39c12' : '#e74c3c');
    ?>
    <tr id="cov-row-<?= h($sym) ?>">
      <td>
        <strong><?= h($sym) ?></strong>
        <span class="text-muted small ms-1"><?= h($fc['inv_name']) ?></span>
      </td>
      <td class="text-muted small"><?= h($fc['inv_type']) ?></td>
      <td class="text-end"><?= formatMoney($fc['mv']) ?></td>
      <td class="text-end" id="cov-count-<?= h($sym) ?>"><?= $fc['count'] ?></td>
      <td class="text-end" id="cov-pct-<?= h($sym) ?>">
        <span class="coverage-dot" style="background:<?= $dotColor ?>"></span>
        <?= $fc['has_data'] ? number_format($coveragePct, 1) . '%' : '<em class="text-muted">no data</em>' ?>
      </td>
      <td class="text-end text-muted small d-print-none" id="cov-ts-<?= h($sym) ?>">
        <?= $fc['fetched_at'] ? date('M j, Y', strtotime($fc['fetched_at'])) : '—' ?>
      </td>
      <td class="d-print-none text-end">
        <button type="button" class="btn btn-xs btn-outline-secondary refresh-fund-btn"
                data-symbol="<?= h($sym) ?>" style="font-size:.72rem;padding:1px 7px">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p class="text-muted small d-print-none">
  Coverage = sum of tracked constituent weights. Most ETFs report top 10–25 holdings via Yahoo Finance,
  which captures the largest concentrations. Use Refresh to update stale data.
</p>
<?php endif; ?>

<?php endif; ?>

<script>
const CSRF = <?= json_encode($csrfTok) ?>;
const BASE = <?= json_encode(BASE_PATH) ?>;

function toggleDetail(row) {
  row.classList.toggle('open');
  const next = row.nextElementSibling;
  if (next && next.classList.contains('fund-detail-row')) {
    next.classList.toggle('d-none');
  }
}

async function refreshFunds(symbols, btns) {
  btns.forEach(b => b.classList.add('refreshing'));
  try {
    const res = await fetch(BASE + '/api/refresh_fund_holdings', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: CSRF, symbols: symbols.join(',') })
    }).then(r => r.json());

    if (!res.ok) { showToast(res.error || 'Refresh failed.', 'error'); return; }

    res.results.forEach(r => {
      const countEl = document.getElementById('cov-count-' + r.symbol);
      const pctEl   = document.getElementById('cov-pct-'   + r.symbol);
      const tsEl    = document.getElementById('cov-ts-'    + r.symbol);
      if (countEl) countEl.textContent = r.count;
      if (pctEl)   pctEl.innerHTML = r.coverage_pct.toFixed(1) + '%';
      if (tsEl && r.fetched_at) {
        const d = new Date(r.fetched_at);
        tsEl.textContent = d.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
      }
    });

    showToast('Holdings data refreshed. Reload to update the exposure table.', 'success');
    document.getElementById('noDataBanner')?.remove();
  } catch(e) {
    console.error(e);
    showToast('Network error during refresh.', 'error');
  } finally {
    btns.forEach(b => b.classList.remove('refreshing'));
  }
}

// Per-fund refresh buttons
document.querySelectorAll('.refresh-fund-btn').forEach(btn => {
  btn.addEventListener('click', () => refreshFunds([btn.dataset.symbol], [btn]));
});

// "Fetch Holdings" banner button
const fetchBtn = document.getElementById('btnFetchMissing');
if (fetchBtn) {
  fetchBtn.addEventListener('click', function() {
    const syms = this.dataset.symbols.split(',').filter(Boolean);
    const rowBtns = syms.map(s => document.querySelector(`.refresh-fund-btn[data-symbol="${s}"]`)).filter(Boolean);
    refreshFunds(syms, rowBtns.concat([this]));
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

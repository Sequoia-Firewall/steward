<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fund_holdings_fetcher.php';
require_once __DIR__ . '/../includes/sector_fetcher.php';
requireLogin();

$db = getDB();

// ── Account filter ─────────────────────────────────────────────
$allAccounts = $db->query(
    "SELECT id, name FROM accounts
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
    $ph        = implode(',', array_fill(0, count($selectedAcctIds), '?'));
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

$latestPrices    = getLatestInvestmentPrices();
$holdingsFetcher = new FundHoldingsFetcher($db);
$sectorFetcher   = new SectorFetcher($db);

$fundTypes = ['ETF', 'Mutual Fund', 'Index'];

// ── Separate into direct vs. fund positions ────────────────────
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
        'mv'       => $mv,
    ];

    if (in_array($r['inv_type'], $fundTypes, true) && $pos['symbol'] !== '') {
        $fundPositions[] = $pos;
    } else {
        $directPositions[] = $pos;
    }
}

// ── Auto-fetch missing fund holdings ──────────────────────────
$fundSymbols = array_column($fundPositions, 'symbol');
$holdingsFetcher->batchFetch($fundSymbols);

// ── Build effective exposure map ───────────────────────────────
// [uppercase_symbol => ['name'=>..., 'value'=>float]]
$exposure = [];

foreach ($directPositions as $p) {
    $key = $p['symbol'] ?: $p['inv_name'];
    $exposure[$key] = ($exposure[$key] ?? 0.0) + $p['mv'];
}

foreach ($fundPositions as $p) {
    $holdings = $holdingsFetcher->getHoldings($p['symbol']);
    foreach ($holdings as $h) {
        $sym            = strtoupper($h['constituent_symbol']);
        $effectiveValue = $p['mv'] * (float)$h['weight_pct'] / 100.0;
        $exposure[$sym] = ($exposure[$sym] ?? 0.0) + $effectiveValue;
    }
}

arsort($exposure); // sort by value desc

$totalIdentified = array_sum($exposure);
$stockSymbols    = array_keys($exposure);

// ── Classify and filter each symbol ──────────────────────────
// Non-equity symbols get a pseudo-sector; garbage gets dropped.
$filteredExposure = [];
$pseudoSectors    = []; // [sym => pseudo-sector string]

foreach ($exposure as $sym => $value) {
    $nonEquity = classifyNonEquity($sym);
    if ($nonEquity === '__filter__') continue; // drop garbage tickers
    if ($nonEquity !== null) {
        $pseudoSectors[$sym] = $nonEquity;
    }
    $filteredExposure[$sym] = $value;
}

$totalIdentified = array_sum($filteredExposure);
$stockSymbols    = array_keys($filteredExposure);

// ── Sector lookup (equity symbols only) ──────────────────────
$equitySymbols = array_filter($stockSymbols,
    fn($s) => !isset($pseudoSectors[$s]) && strlen($s) <= 15 && !str_contains($s, ' ')
);
$sectorMap = $sectorFetcher->getSectors(array_values($equitySymbols));

// ── Group exposure by sector ──────────────────────────────────
$bySector          = [];
$unclassifiedValue = 0.0;

foreach ($filteredExposure as $sym => $value) {
    if (isset($pseudoSectors[$sym])) {
        $sector = $pseudoSectors[$sym];
    } else {
        $info   = $sectorMap[$sym] ?? null;
        $sector = $info ? $info['sector'] : 'Unknown';
    }

    if (!isset($bySector[$sector])) {
        $bySector[$sector] = ['value' => 0.0, 'securities' => []];
    }
    $bySector[$sector]['value']        += $value;
    $bySector[$sector]['securities'][]  = ['symbol' => $sym, 'value' => $value];

    if ($sector === 'Unknown') $unclassifiedValue += $value;
}

// Sort sectors by value desc
uasort($bySector, fn($a, $b) => $b['value'] <=> $a['value']);

// Sort securities within each sector by value desc (already sorted but defensive)
foreach ($bySector as &$s) {
    usort($s['securities'], fn($a, $b) => $b['value'] <=> $a['value']);
}
unset($s);

// ── Chart colors (one per GICS sector) ───────────────────────
$SECTOR_COLORS = [
    'Information Technology' => '#4e79a7',
    'Health Care'            => '#f28e2b',
    'Financials'             => '#e15759',
    'Consumer Discretionary' => '#76b7b2',
    'Communication Services' => '#59a14f',
    'Industrials'            => '#edc948',
    'Consumer Staples'       => '#b07aa1',
    'Energy'                 => '#ff9da7',
    'Utilities'              => '#9c755f',
    'Real Estate'            => '#bab0ac',
    'Materials'              => '#a0cbe8',
    'Cash & Equivalents'     => '#86c5da',
    'Fixed Income'           => '#c8a96e',
    'Crypto'                 => '#f07b4f',
    'Diversified ETF'        => '#9b84b8',
    'Other Funds'            => '#b0b8c8',
    'Unknown'                => '#cccccc',
];

$chartLabels = [];
$chartValues = [];
$chartColors = [];
foreach ($bySector as $sector => $data) {
    $chartLabels[] = $sector;
    $chartValues[] = round($data['value'], 2);
    $chartColors[] = $SECTOR_COLORS[$sector] ?? '#888888';
}

$coveredPct  = $portfolioMV > 0 ? $totalIdentified / $portfolioMV * 100 : 0;
$classifiedPct = $totalIdentified > 0 ? (1 - $unclassifiedValue / $totalIdentified) * 100 : 0;
$sectorCount = count(array_filter(array_keys($bySector), fn($s) => $s !== 'Unknown'));

// Unknown equity symbols that could be fetched from AlphaVantage
$unknownSymbols = array_values(array_filter(
    array_keys($filteredExposure),
    fn($s) => !isset($pseudoSectors[$s])
              && isset($sectorMap[$s]) && in_array($sectorMap[$s]['source'], ['none', 'no_data'], true)
              && strlen($s) <= 15 && !str_contains($s, ' ')
));

$csrfTok     = csrfToken();
$pageTitle   = 'Sector Exposure';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>
<style>
.sector-expand-btn { cursor: pointer; user-select: none; }
.sector-expand-btn .bi-chevron-right { transition: transform .15s; font-size: .75rem; color: #8899aa; }
.sector-expand-btn.open .bi-chevron-right { transform: rotate(90deg); }
.sector-detail-row td { background: #f8fafc; font-size: .82rem; padding: 3px 8px 3px 2.5rem; }
.sector-bar { height: 6px; border-radius: 3px; display: inline-block; vertical-align: middle; }
</style>

<div class="page-header">
  <h2><i class="bi bi-pie-chart-fill"></i> Sector Exposure</h2>
  <?php $reportFavTitle = 'Sector Exposure'; $reportFavIcon = 'bi-pie-chart-fill'; ?>
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

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Portfolio Value</div>
    <div class="tile-value"><?= formatMoney($portfolioMV) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Identified Exposure</div>
    <div class="tile-value"><?= formatMoney($totalIdentified) ?></div>
    <div class="tile-sub text-muted"><?= number_format($coveredPct, 1) ?>% of portfolio</div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Sectors Identified</div>
    <div class="tile-value"><?= $sectorCount ?></div>
    <div class="tile-sub text-muted"><?= number_format($classifiedPct, 1) ?>% of exposure classified</div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Securities</div>
    <div class="tile-value"><?= count($exposure) ?></div>
  </div>
</div>

<?php if ($unclassifiedValue > 0 && $classifiedPct < 80): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-3 d-print-none" id="classifyBanner">
  <i class="bi bi-info-circle-fill"></i>
  <div>
    <strong><?= number_format(100 - $classifiedPct, 1) ?>%</strong> of identified exposure
    (<strong><?= formatMoney($unclassifiedValue) ?></strong>) has no sector classification.
    Click <strong>Classify More</strong> to fetch sector data from AlphaVantage for up to 20 symbols.
  </div>
  <button type="button" class="btn btn-sm btn-info ms-auto" id="btnClassify">
    <i class="bi bi-tags"></i> Classify More
  </button>
</div>
<?php endif; ?>

<?php if (empty($bySector)): ?>
<p class="text-muted">No sector data available.</p>
<?php else: ?>

<div class="alloc-charts-row" style="gap:56px;margin-bottom:24px">
  <div class="alloc-chart-block">
    <div class="alloc-chart-title">Sector Breakdown</div>
    <div class="alloc-chart-wrap"><canvas id="sectorChart"></canvas></div>
    <div class="alloc-legend" id="sectorLegend"></div>
  </div>
</div>

<h5 class="mt-2 mb-2" style="font-size:.95rem;font-weight:600">By GICS Sector</h5>
<table class="table table-sm report-table" id="sectorTable">
  <thead>
    <tr>
      <th>Sector</th>
      <th class="text-end">Effective Value</th>
      <th class="text-end">% of Identified</th>
      <th class="text-end">% of Portfolio</th>
      <th class="text-end"># Securities</th>
      <th>Top 3 Holdings</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($bySector as $sector => $data): ?>
    <?php
        $color     = $SECTOR_COLORS[$sector] ?? '#888';
        $pctIdent  = $totalIdentified > 0 ? $data['value'] / $totalIdentified * 100 : 0;
        $pctPort   = $portfolioMV     > 0 ? $data['value'] / $portfolioMV     * 100 : 0;
        $top3      = array_slice($data['securities'], 0, 3);
        $hasMore   = count($data['securities']) > 3;
        $rowId     = 'sec-' . preg_replace('/[^a-z0-9]/', '-', strtolower($sector));
    ?>
    <tr class="sector-expand-btn" onclick="toggleSector(this)">
      <td>
        <i class="bi bi-chevron-right me-1"></i>
        <span class="sector-bar me-2" style="width:<?= min(round($pctIdent * 1.5), 60) ?>px;background:<?= $color ?>"></span>
        <?= h($sector) ?>
      </td>
      <td class="text-end fw-semibold"><?= formatMoney($data['value']) ?></td>
      <td class="text-end"><?= number_format($pctIdent, 1) ?>%</td>
      <td class="text-end text-muted"><?= number_format($pctPort, 1) ?>%</td>
      <td class="text-end text-muted"><?= count($data['securities']) ?></td>
      <td class="text-muted small">
        <?= h(implode(', ', array_map(fn($s) => $s['symbol'], $top3))) ?>
        <?= $hasMore ? '…' : '' ?>
      </td>
    </tr>
    <tr class="sector-detail-row d-none" id="<?= $rowId ?>">
      <td colspan="6">
        <table class="table table-sm mb-0" style="font-size:.82rem">
          <thead class="text-muted">
            <tr>
              <th>Symbol</th>
              <th class="text-end">Effective Value</th>
              <th class="text-end">% of Sector</th>
              <th class="text-end">% of Portfolio</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['securities'] as $sec): ?>
            <?php $info = $sectorMap[$sec['symbol']] ?? null; ?>
            <tr>
              <td>
                <strong><?= h($sec['symbol']) ?></strong>
                <?php if ($info && $info['industry']): ?>
                  <span class="text-muted ms-1"><?= h($info['industry']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= formatMoney($sec['value']) ?></td>
              <td class="text-end"><?= $data['value'] > 0 ? number_format($sec['value'] / $data['value'] * 100, 1) . '%' : '—' ?></td>
              <td class="text-end"><?= $portfolioMV > 0 ? number_format($sec['value'] / $portfolioMV * 100, 2) . '%' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td><strong>Total Identified</strong></td>
      <td class="text-end"><strong><?= formatMoney($totalIdentified) ?></strong></td>
      <td class="text-end"><strong>100.0%</strong></td>
      <td class="text-end"><strong><?= number_format($coveredPct, 1) ?>%</strong></td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>

<p class="text-muted small d-print-none mt-1">
  Exposure combines direct stock holdings with weighted ETF/fund constituents.
  Sector classification uses static GICS data for S&P 500 stocks, with AlphaVantage OVERVIEW as fallback.
</p>

<?php endif; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function(){
  const CSRF         = <?= json_encode($csrfTok) ?>;
  const BASE         = <?= json_encode(BASE_PATH) ?>;
  const totalIdent   = <?= round($totalIdentified, 2) ?>;
  const totalPort    = <?= round($portfolioMV, 2) ?>;
  const unknownSyms  = <?= json_encode(array_slice($unknownSymbols, 0, 20)) ?>;

  // ── Donut chart ──────────────────────────────────────────────
  const ctx = document.getElementById('sectorChart');
  if (ctx && totalIdent > 0) {
    const labels = <?= json_encode($chartLabels) ?>;
    const values = <?= json_encode($chartValues) ?>;
    const colors = <?= json_encode($chartColors) ?>;

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
                const pct = totalIdent > 0 ? (c.raw / totalIdent * 100).toFixed(1) : 0;
                return ' ' + c.label + ': $' + c.raw.toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:0}) + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });

    const leg = document.getElementById('sectorLegend');
    if (leg) {
      labels.forEach((lbl, i) => {
        const pct = totalIdent > 0 ? (values[i] / totalIdent * 100).toFixed(1) : 0;
        const div = document.createElement('div');
        div.className = 'alloc-leg-item';
        div.innerHTML = `<span class="alloc-leg-dot" style="background:${colors[i]}"></span>
                         <span class="alloc-leg-label">${lbl}</span>
                         <span class="alloc-leg-pct">${pct}%</span>`;
        leg.appendChild(div);
      });
    }
  }

  // ── Row expand/collapse ──────────────────────────────────────
  window.toggleSector = function(row) {
    row.classList.toggle('open');
    const next = row.nextElementSibling;
    if (next && next.classList.contains('sector-detail-row')) {
      next.classList.toggle('d-none');
    }
  };

  // ── Classify More button ─────────────────────────────────────
  const classifyBtn = document.getElementById('btnClassify');
  if (classifyBtn) {
    classifyBtn.addEventListener('click', async function() {
      this.disabled = true;
      this.innerHTML = '<i class="bi bi-hourglass-split"></i> Fetching…';
      try {
        if (!unknownSyms.length) { showToast('No additional symbols to classify.', 'info'); return; }
        const res = await fetch(BASE + '/api/refresh_sector_data', {
          method: 'POST',
          body: new URLSearchParams({ csrf_token: CSRF, symbols: unknownSyms.join(',') })
        }).then(r => r.json());
        if (res.ok) {
          showToast('Sector data updated. Reload to see changes.', 'success');
          document.getElementById('classifyBanner')?.remove();
        } else {
          showToast(res.error || 'Fetch failed.', 'error');
          this.disabled = false;
          this.innerHTML = '<i class="bi bi-tags"></i> Classify More';
        }
      } catch(e) {
        showToast('Network error.', 'error');
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-tags"></i> Classify More';
      }
    });
  }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

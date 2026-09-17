<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// Every non-index security that has at least one transaction, regardless of
// is_active — a fully-sold security's investments row is untouched by
// is_active (that flag only reflects an explicit delete/archive on
// portfolio/delete.php), so unlike portfolio/security.php this report must
// not filter on it, or a no-longer-held security would 404.
$allSecurities = $db->query(
    "SELECT DISTINCT i.id, i.name, i.symbol, i.type, i.is_active
     FROM investments i
     JOIN investment_transactions it ON it.investment_id = i.id
     WHERE i.type != 'Index'
     ORDER BY i.name"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle   = 'Security History';
$currentPage = 'reports';

if (empty($allSecurities)) {
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="page-header">
      <h2><i class="bi bi-clock-history"></i> Security History</h2>
      <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-chevron-left"></i> All Reports
      </a>
    </div>
    <p class="text-muted">No investment transactions recorded yet.</p>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$reqId = (int)($_GET['id'] ?? 0);
$inv   = null;
foreach ($allSecurities as $s) {
    if ((int)$s['id'] === $reqId) { $inv = $s; break; }
}
if (!$inv) $inv = $allSecurities[0];

$invId   = (int)$inv['id'];
$invName = $inv['name'];
$symbol  = $inv['symbol'] ?? '';

// All transactions for this investment across all accounts, oldest first
$txnStmt = $db->prepare(
    'SELECT t.id, t.transaction_date, t.payee, t.memo, t.cleared_status, t.amount,
            it.activity, it.quantity, it.price AS inv_price, it.commission,
            a.id AS account_id, a.name AS account_name
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     JOIN accounts a     ON a.id = t.account_id
     WHERE it.investment_id = ?
     ORDER BY t.transaction_date ASC, t.id ASC'
);
$txnStmt->execute([$invId]);
$transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

$actLabels = [
    'buy'          => 'Buy',
    'sell'         => 'Sell',
    'add'          => 'Add',
    'remove'       => 'Remove',
    'split'        => 'Split',
    'reinvest_div' => 'Reinvest Div',
    'reinvest_cap' => 'Reinvest Cap',
    'div'          => 'Dividend',
    'int'          => 'Interest',
];

$firstTxnDate = $transactions[0]['transaction_date'] ?? date('Y-m-d');
$today        = date('Y-m-d');

// Same shared functions as portfolio/security.php, for consistency
$allHoldings  = getInvestmentHoldings();
$allCostBases = getInvestmentCostBases();
$allPrices    = getLatestInvestmentPrices();
$invHeld  = $allHoldings[$invId]  ?? [];
$basisRow = $allCostBases[$invId] ?? null;
$priceRow = $allPrices[$invId]    ?? null;

$latestPrice   = $priceRow ? (float)$priceRow['price'] : null;
$sharesOwned   = array_sum(array_column($invHeld, 'quantity'));
$currentlyHeld = $sharesOwned > 0.000001;

$shareBalance = [];
foreach ($invHeld as $hld) {
    $qty = (float)$hld['quantity'];
    $shareBalance[(int)$hld['account_id']] = [
        'qty'   => $qty,
        'name'  => $hld['account_name'],
        'value' => $latestPrice !== null ? $qty * $latestPrice : null,
    ];
}

// The last date this security was sold/disposed anywhere — used below to cap
// stale end-prices for a no-longer-held security (see note near $effectivePerfTo).
$lastSellStmt = $db->prepare(
    "SELECT MAX(t.transaction_date) AS last_sell
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     JOIN accounts a     ON a.id = t.account_id
     WHERE a.is_investment_cash = 0 AND it.investment_id = ?
       AND it.activity IN ('sell','remove')"
);
$lastSellStmt->execute([$invId]);
$lastSellDate = $lastSellStmt->fetchColumn() ?: null;

// Lifetime at-a-glance figures — cost/profit analysis isn't vulnerable to the
// stale-price issue below (a fully-exited position's market value is forced to
// 0 regardless of any later stray price row), so no date capping needed here.
$lifetimeCpa = getInvestmentCostProfitAnalysis([$invId], $firstTxnDate, $today, true)[$invId] ?? null;

// ── Selected report range (defaults to the security's entire lifetime) ──
$perfFrom = $_GET['from'] ?? $firstTxnDate;
$perfTo   = $_GET['to']   ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $perfFrom)) $perfFrom = $firstTxnDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $perfTo))   $perfTo   = $today;
if ($perfTo < $perfFrom) $perfTo = $perfFrom;

// Price fetching only runs for currently-held/watchlist securities, so a
// sold-off security's price history can go stale for months and then pick up
// a one-off later manual/fetched price. Performance Summary below is purely
// price-based (getInvestmentPerformanceSeries has no holding-quantity check),
// so for a security that isn't currently held, cap the effective end date at
// its last sell date instead of trusting a later price that no longer
// reflects an actual position. Cost & Profit Analysis doesn't need this cap
// (see $lifetimeCpa note above), so it still uses the uncapped $perfTo.
$priceCapApplied = false;
$effectivePerfTo = $perfTo;
if (!$currentlyHeld && $lastSellDate !== null && $lastSellDate < $perfTo) {
    $effectivePerfTo = $lastSellDate;
    $priceCapApplied = true;
}

$perfSeries = getInvestmentPerformanceSeries([$invId], $perfFrom, $effectivePerfTo);
$perfRow    = $perfSeries['series'][$invId] ?? null;
$cpaRow     = getInvestmentCostProfitAnalysis([$invId], $perfFrom, $perfTo, true)[$invId] ?? null;

// Buy/sell transactions for the inline price history chart markers
$chartTxns = [];
foreach ($transactions as $txn) {
    $act = $txn['activity'] ?? '';
    if (!in_array($act, ['buy', 'sell'])) continue;
    $chartTxns[] = [
        'date'     => $txn['transaction_date'],
        'activity' => $act,
        'quantity' => (float)($txn['quantity'] ?? 0),
        'price'    => (float)($txn['inv_price'] ?? 0),
    ];
}

// Full price history, embedded server-side so the chart renders immediately
// (including for print), rather than fetched async like the live portfolio page.
$priceHistory = getInvestmentPriceHistory($invId);

include __DIR__ . '/../includes/header.php';
?>
<script>
const BASE_PATH        = '<?= BASE_PATH ?>';
const SEC_NAME         = <?= json_encode($invName) ?>;
const SEC_SYMBOL       = <?= json_encode($symbol) ?>;
const SEC_TRANSACTIONS = <?= json_encode(array_map(fn($t) => [
    'date'       => $t['transaction_date'],
    'account'    => $t['account_name'],
    'activity'   => $t['activity'] ?? '',
    'qty'        => (float)($t['quantity']   ?? 0),
    'price'      => (float)($t['inv_price']  ?? 0),
    'commission' => (float)($t['commission'] ?? 0),
    'amount'     => (float)($t['amount']     ?? 0),
    'memo'       => $t['memo'] ?? '',
    'cleared'    => $t['cleared_status'] ?? '',
], $transactions)) ?>;
const CHART_TXNS    = <?= json_encode($chartTxns) ?>;
const PRICE_HISTORY = <?= json_encode($priceHistory) ?>;
</script>

<?php $reportFavTitle = 'Security History — ' . ($symbol ?: $invName); $reportFavIcon = 'bi-clock-history'; ?>
<div class="page-header">
  <h2>
    <i class="bi bi-clock-history"></i> Security History
  </h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters mb-3 d-print-none" id="secPickForm">
  <div class="filter-group">
    <label>Security</label>
    <select name="id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <?php foreach ($allSecurities as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $invId ? 'selected' : '' ?>>
        <?= h($s['name']) ?><?= $s['symbol'] ? ' (' . h($s['symbol']) . ')' : '' ?><?= !$s['is_active'] ? ' — archived' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="page-header" style="margin-top:-0.5rem">
  <h3 class="mb-0">
    <?php if ($symbol): ?><span class="inv-symbol me-2"><?= h($symbol) ?></span><?php endif; ?>
    <?= h($invName) ?>
    <span class="badge bg-secondary ms-2 fw-normal" style="font-size:.6em;vertical-align:middle"><?= h($inv['type']) ?></span>
    <?php if (!$currentlyHeld): ?>
    <span class="badge bg-light text-muted border ms-1 fw-normal" style="font-size:.6em;vertical-align:middle">No longer held</span>
    <?php endif; ?>
  </h3>
  <?php if ($inv['is_active']): ?>
  <a href="<?= BASE_PATH ?>/portfolio/security?slug=<?= urlencode($symbol ?: (string)$invId) ?>" class="btn btn-outline-secondary btn-sm d-print-none">
    <i class="bi bi-graph-up"></i> Live Page
  </a>
  <?php endif; ?>
</div>

<!-- Lifetime at-a-glance -->
<div class="dash-section">
  <h4 class="section-title"><i class="bi bi-stars"></i> Lifetime at a Glance</h4>
  <?php if ($lifetimeCpa === null): ?>
  <p class="text-muted small">No cost/profit history to show.</p>
  <?php else:
    $lRglCls = $lifetimeCpa['realizedGainLoss']   >= 0 ? 'amount-credit' : 'amount-debit';
    $lUglCls = $lifetimeCpa['unrealizedGainLoss'] !== null ? ($lifetimeCpa['unrealizedGainLoss'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
    $lTpCls  = $lifetimeCpa['totalProfit']        !== null ? ($lifetimeCpa['totalProfit']        >= 0 ? 'amount-credit' : 'amount-debit') : '';
  ?>
  <div class="d-flex gap-4 flex-wrap">
    <div>
      <div class="text-muted small">Realized Profit</div>
      <div class="fs-5 fw-semibold <?= $lRglCls ?>">
        <?= ($lifetimeCpa['realizedGainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($lifetimeCpa['realizedGainLoss'])) ?>
      </div>
    </div>
    <div>
      <div class="text-muted small">Unrealized G/L</div>
      <div class="fs-5 fw-semibold <?= $lUglCls ?>">
        <?php if ($lifetimeCpa['unrealizedGainLoss'] !== null): ?>
          <?= ($lifetimeCpa['unrealizedGainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($lifetimeCpa['unrealizedGainLoss'])) ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </div>
    </div>
    <div>
      <div class="text-muted small">Dividends &amp; Interest</div>
      <div class="fs-5 fw-semibold"><?= formatMoney($lifetimeCpa['totalDistributions']) ?></div>
    </div>
    <div>
      <div class="text-muted small">Total Profit</div>
      <div class="fs-5 fw-semibold <?= $lTpCls ?>">
        <?php if ($lifetimeCpa['totalProfit'] !== null): ?>
          <?= ($lifetimeCpa['totalProfit'] >= 0 ? '+' : '-') . formatMoney(abs($lifetimeCpa['totalProfit'])) ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Price History -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="section-title mb-0"><i class="bi bi-graph-up"></i> Price History</h4>
    <div class="ph-range-btns d-print-none" id="secPhRangeBtns">
      <button class="ph-range-btn" data-range="1M">1M</button>
      <button class="ph-range-btn" data-range="3M">3M</button>
      <button class="ph-range-btn" data-range="6M">6M</button>
      <button class="ph-range-btn" data-range="1Y">1Y</button>
      <button class="ph-range-btn ph-range-active" data-range="ALL">All</button>
    </div>
  </div>
  <div id="secPhEmpty" style="display:none" class="text-center text-muted py-4">
    <i class="bi bi-graph-up" style="font-size:2rem"></i>
    <p class="mt-2 mb-0">No price history available.</p>
  </div>
  <div id="secPhContent" class="report-chart-wrap" style="position:relative;height:260px">
    <canvas id="secPhChart"></canvas>
  </div>
  <div class="d-flex align-items-center gap-3 mt-2 small text-muted">
    <span><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#1a7a3c;color:#fff;font-weight:700;font-size:9px;line-height:14px;text-align:center">B</span> Buy</span>
    <span><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#c0392b;color:#fff;font-weight:700;font-size:9px;line-height:14px;text-align:center">S</span> Sell</span>
  </div>
</div>

<!-- Performance & Cost Analysis -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="section-title mb-0"><i class="bi bi-graph-up-arrow"></i> Performance &amp; Cost Analysis</h4>
  </div>

  <form method="get" class="report-filters mb-2 d-print-none" id="secPerfForm">
    <input type="hidden" name="id" value="<?= $invId ?>">
    <div class="filter-group">
      <label>From</label>
      <input type="date" name="from" id="secPerfFrom" value="<?= h($perfFrom) ?>"
             class="form-control form-control-sm" style="width:auto">
    </div>
    <div class="filter-group">
      <label>To</label>
      <input type="date" name="to" id="secPerfTo" value="<?= h($perfTo) ?>"
             class="form-control form-control-sm" style="width:auto">
    </div>
    <div class="filter-group filter-group-btns">
      <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </div>
  </form>
  <div class="mb-3 d-flex gap-1 flex-wrap align-items-center d-print-none">
    <span class="text-muted small me-1">Range:</span>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="1">1M</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="3">3M</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="6">6M</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-ytd="1">YTD</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="12">1Y</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="24">2Y</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="60">5Y</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-all="1">Lifetime</button>
  </div>

  <?php if ($perfRow === null): ?>
  <p class="text-muted small">No price history in this date range.</p>
  <?php else: ?>
  <h5 class="report-section-title" style="font-size:.95rem">Performance Summary</h5>
  <?php if ($priceCapApplied): ?>
  <p class="text-muted small">
    <i class="bi bi-info-circle"></i>
    Priced as of <?= formatDate($lastSellDate) ?>, this security's last sell date — not <?= formatDate($perfTo) ?>,
    since it's no longer held and any price recorded after the sale wouldn't reflect an actual position.
  </p>
  <?php endif; ?>
  <table class="table table-sm report-table mb-4">
    <thead>
      <tr>
        <th class="text-end">Start Date</th><th class="text-end">Start Price</th>
        <th class="text-end">End Date</th><th class="text-end">End Price</th>
        <th class="text-end">Period Return</th><th class="text-end">Ann. Return</th>
      </tr>
    </thead>
    <tbody>
      <?php $rCls = $perfRow['periodReturn'] >= 0 ? 'amount-credit' : 'amount-debit';
            $aCls = $perfRow['annualReturn'] !== null ? ($perfRow['annualReturn'] >= 0 ? 'amount-credit' : 'amount-debit') : ''; ?>
      <tr>
        <td class="text-end text-muted small"><?= formatDate($perfRow['firstDate']) ?></td>
        <td class="text-end"><?= formatMoney($perfRow['basePrice']) ?></td>
        <td class="text-end text-muted small"><?= formatDate($perfRow['lastDate']) ?></td>
        <td class="text-end"><?= formatMoney($perfRow['lastPrice']) ?></td>
        <td class="text-end <?= $rCls ?>"><strong><?= ($perfRow['periodReturn'] >= 0 ? '+' : '') . number_format($perfRow['periodReturn'], 2) ?>%</strong></td>
        <td class="text-end <?= $aCls ?>">
          <?php if ($perfRow['annualReturn'] !== null): ?>
            <?= ($perfRow['annualReturn'] >= 0 ? '+' : '') . number_format($perfRow['annualReturn'], 2) ?>%/yr
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>

  <?php if ($cpaRow === null): ?>
  <p class="text-muted small">No cost/profit activity in this date range.</p>
  <?php else: ?>
  <h5 class="report-section-title" style="font-size:.95rem">Cost &amp; Profit Analysis</h5>
  <p class="text-muted small">
    <?php if ($cpaRow['qty'] <= 0.000001): ?>
    Fully sold during this range — shares and cost basis are 0, but realized profit from the sale(s) is shown below.
    <?php else: ?>
    Total Profit and Total Return % are approximations for this date range — see
    <a href="<?= BASE_PATH ?>/reports/investment_performance">Investment Performance</a> for the full methodology note.
    <?php endif; ?>
  </p>
  <table class="table table-sm report-table mb-2">
    <thead>
      <tr>
        <th class="text-end">Shares</th><th class="text-end">Avg Cost</th><th class="text-end">Cost Basis</th>
        <th class="text-end">Market Value</th><th class="text-end">Unrealized G/L</th>
        <th class="text-end">Div/Interest</th><th class="text-end">Reinvested</th>
        <th class="text-end">Total Distrib.</th><th class="text-end">Realized G/L</th>
        <th class="text-end">Total Profit</th><th class="text-end">Total Return</th>
      </tr>
    </thead>
    <tbody>
      <?php
        $uglCls = $cpaRow['unrealizedGainLoss'] !== null ? ($cpaRow['unrealizedGainLoss'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
        $rglCls = $cpaRow['realizedGainLoss']    >= 0 ? 'amount-credit' : 'amount-debit';
        $tpCls  = $cpaRow['totalProfit']        !== null ? ($cpaRow['totalProfit']        >= 0 ? 'amount-credit' : 'amount-debit') : '';
        $trCls  = $cpaRow['totalReturnPct']     !== null ? ($cpaRow['totalReturnPct']     >= 0 ? 'amount-credit' : 'amount-debit') : '';
      ?>
      <tr>
        <td class="text-end"><?= rtrim(rtrim(number_format($cpaRow['qty'], 6), '0'), '.') ?></td>
        <td class="text-end"><?= $cpaRow['avgCost'] > 0 ? formatMoney($cpaRow['avgCost']) : '—' ?></td>
        <td class="text-end"><?= formatMoney($cpaRow['costBasis']) ?></td>
        <td class="text-end"><?= $cpaRow['marketValue'] !== null ? formatMoney($cpaRow['marketValue']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end <?= $uglCls ?>">
          <?php if ($cpaRow['unrealizedGainLoss'] !== null): ?>
            <?= ($cpaRow['unrealizedGainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($cpaRow['unrealizedGainLoss'])) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end"><?= $cpaRow['dividendsInterest'] > 0 ? formatMoney($cpaRow['dividendsInterest']) : '—' ?></td>
        <td class="text-end"><?= $cpaRow['reinvestedDistributions'] > 0 ? formatMoney($cpaRow['reinvestedDistributions']) : '—' ?></td>
        <td class="text-end"><?= $cpaRow['totalDistributions'] > 0 ? formatMoney($cpaRow['totalDistributions']) : '—' ?></td>
        <td class="text-end <?= $cpaRow['realizedGainLoss'] != 0 ? $rglCls : '' ?>">
          <?php if ($cpaRow['realizedGainLoss'] != 0): ?>
            <?= ($cpaRow['realizedGainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($cpaRow['realizedGainLoss'])) ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="text-end <?= $tpCls ?>">
          <?php if ($cpaRow['totalProfit'] !== null): ?>
            <?= ($cpaRow['totalProfit'] >= 0 ? '+' : '-') . formatMoney(abs($cpaRow['totalProfit'])) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end <?= $trCls ?>">
          <?php if ($cpaRow['totalReturnPct'] !== null): ?>
            <strong><?= ($cpaRow['totalReturnPct'] >= 0 ? '+' : '') . number_format($cpaRow['totalReturnPct'], 2) ?>%</strong>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>

  <?php if (!empty($cpaRow['sales'])): ?>
  <details class="small">
    <summary class="text-muted" style="cursor:pointer">
      <?= count($cpaRow['sales']) ?> sale<?= count($cpaRow['sales']) !== 1 ? 's' : '' ?> in this range (realized G/L included in Total Profit above)
    </summary>
    <table class="table table-sm mt-2 mb-0">
      <thead><tr><th>Date</th><th class="text-end">Shares</th><th class="text-end">Price</th><th class="text-end">Proceeds</th><th class="text-end">Cost Basis</th><th class="text-end">Gain/Loss</th></tr></thead>
      <tbody>
        <?php foreach ($cpaRow['sales'] as $s): ?>
        <tr>
          <td><?= formatDate($s['date']) ?></td>
          <td class="text-end"><?= rtrim(rtrim(number_format($s['qty'], 6), '0'), '.') ?></td>
          <td class="text-end"><?= formatMoney($s['price']) ?></td>
          <td class="text-end"><?= formatMoney($s['proceeds']) ?></td>
          <td class="text-end"><?= formatMoney($s['costBasis']) ?></td>
          <td class="text-end <?= $s['gainLoss'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
            <?= ($s['gainLoss'] >= 0 ? '+' : '-') . formatMoney(abs($s['gainLoss'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>

  <?php if (!empty($cpaRow['distributions'])): ?>
  <details class="small">
    <summary class="text-muted" style="cursor:pointer">
      <?= count($cpaRow['distributions']) ?> distribution transaction<?= count($cpaRow['distributions']) !== 1 ? 's' : '' ?> in this range
    </summary>
    <table class="table table-sm mt-2 mb-0">
      <thead><tr><th>Date</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($cpaRow['distributions'] as $d): ?>
        <tr>
          <td><?= formatDate($d['date']) ?></td>
          <td><?= h($actLabels[$d['activity']] ?? $d['activity']) ?></td>
          <td class="text-end"><?= formatMoney($d['amount']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Transaction History -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h4 class="section-title mb-0"><i class="bi bi-list-ul"></i> Transaction History</h4>
    <?php if (!empty($transactions)): ?>
    <button class="btn btn-outline-secondary btn-sm d-print-none" onclick="exportSecurityCSV()">
      <i class="bi bi-download"></i> Export CSV
    </button>
    <?php endif; ?>
  </div>
  <?php if (empty($transactions)): ?>
  <p class="text-muted py-3 text-center mb-0">
    No transactions recorded for this investment.
  </p>
  <?php else: ?>
  <div class="register-grid-wrapper">
    <table class="register-grid" id="securityHistoryTable">
      <thead>
        <tr>
          <th class="col-date">Date</th>
          <th>Account</th>
          <th class="col-cat">Activity</th>
          <th class="text-end" style="width:90px">Qty</th>
          <th class="text-end" style="width:90px">Price/Sh</th>
          <th class="text-end" style="width:80px">Comm</th>
          <th class="text-end" style="width:100px">Total</th>
          <th class="col-c" title="Cleared Status">C</th>
          <th>Memo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $txn):
          $activity = $txn['activity'] ?? '';
          $qty      = (float)($txn['quantity']   ?? 0);
          $price    = (float)($txn['inv_price']  ?? 0);
          $comm     = (float)($txn['commission'] ?? 0);
          $acctId   = (int)$txn['account_id'];

          $total = 0.0;
          if ($activity === 'buy')                            $total = $qty * $price + $comm;
          elseif ($activity === 'sell')                       $total = max(0.0, $qty * $price - $comm);
          elseif ($activity === 'div' || $activity === 'int') $total = abs((float)($txn['amount'] ?? 0));

          $actLabel = $actLabels[$activity] ?? ucfirst($activity);

          $clearedIcon = match($txn['cleared_status']) {
              'cleared'    => '<span class="cleared-c" title="Cleared">c</span>',
              'reconciled' => '<span class="cleared-r" title="Reconciled">R</span>',
              default      => '',
          };
        ?>
        <tr class="register-row <?= h($txn['cleared_status']) ?>">
          <td class="col-date"><?= formatDate($txn['transaction_date']) ?></td>
          <td class="small">
            <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acctId ?>" class="inv-account-link">
              <?= h($txn['account_name']) ?>
            </a>
          </td>
          <td class="col-cat">
            <?php if ($activity): ?>
              <span class="inv-activity-badge act-<?= h($activity) ?>"><?= h($actLabel) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?= $qty != 0
                ? rtrim(rtrim(number_format($qty, 6), '0'), '.')
                : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-end text-nowrap">
            <?= ($price > 0 && !in_array($activity, ['add', 'remove', 'split']))
                ? formatMoney($price)
                : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-end text-nowrap">
            <?= $comm > 0 ? formatMoney($comm) : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="text-end text-nowrap">
            <?= $total > 0 ? formatMoney($total) : '<span class="text-muted">—</span>' ?>
          </td>
          <td class="col-c"><?= $clearedIcon ?></td>
          <td class="text-muted small"><?= h($txn['memo'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex gap-3 flex-wrap mt-2 px-1">
    <span class="text-muted small">
      <?= count($transactions) ?> transaction<?= count($transactions) !== 1 ? 's' : '' ?>
      across
      <?= count(array_unique(array_column($transactions, 'account_id'))) ?>
      account<?= count(array_unique(array_column($transactions, 'account_id'))) !== 1 ? 's' : '' ?>
    </span>
    <?php foreach ($shareBalance as $acctId => $hld): ?>
    <span class="text-muted small">
      <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acctId ?>" class="inv-account-link"><?= h($hld['name']) ?></a>:
      <strong><?= rtrim(rtrim(number_format($hld['qty'], 6), '0'), '.') ?> shares</strong>
      <?php if ($hld['value'] !== null): ?>
      (<strong class="inv-mktval"><?= formatMoney($hld['value']) ?></strong>)
      <?php endif; ?>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  const INV_NAME = SEC_NAME + (SEC_SYMBOL ? ' (' + SEC_SYMBOL + ')' : '');
  let secChart    = null;
  let activeRange = 'ALL';

  // Custom plugin: draws B/S circles on the price line at transaction dates
  const txnMarkerPlugin = {
    id: 'secTxnMarkers',
    afterDatasetsDraw(chart) {
      const meta = chart.getDatasetMeta(0);
      if (!meta || !meta.data.length) return;
      const markers = chart.data.txnMarkers;
      if (!markers || !markers.length) return;

      const labels   = chart.data.labels;
      const ptMap    = {};
      meta.data.forEach((pt, i) => { ptMap[labels[i]] = { x: pt.x, y: pt.y }; });

      const ctx = chart.ctx;
      ctx.save();
      ctx.font         = 'bold 10px sans-serif';
      ctx.textAlign    = 'center';
      ctx.textBaseline = 'middle';

      markers.forEach(m => {
        const pt = findNearest(m.date, labels, ptMap);
        if (!pt) return;
        const isBuy = m.activity === 'buy';
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, 9, 0, Math.PI * 2);
        ctx.fillStyle = isBuy ? '#1a7a3c' : '#c0392b';
        ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.fillText(isBuy ? 'B' : 'S', pt.x, pt.y);
      });
      ctx.restore();
    },
  };

  function findNearest(date, labels, ptMap) {
    if (ptMap[date]) return ptMap[date];
    let best = null, minDiff = Infinity;
    const ts = Date.parse(date);
    labels.forEach(d => {
      const diff = Math.abs(Date.parse(d) - ts);
      if (diff < minDiff) { minDiff = diff; best = d; }
    });
    return best ? ptMap[best] : null;
  }

  function filterByRange(arr, range, key) {
    if (range === 'ALL' || !arr.length) return arr;
    const months  = { '1M': 1, '3M': 3, '6M': 6, '1Y': 12 }[range] || 12;
    const cutoff  = new Date();
    cutoff.setMonth(cutoff.getMonth() - months);
    const cutStr  = cutoff.toISOString().slice(0, 10);
    return arr.filter(item => (item[key] ?? item.date) >= cutStr);
  }

  function renderChart() {
    const prices = filterByRange(PRICE_HISTORY, activeRange, 'date');
    const txns   = filterByRange(CHART_TXNS, activeRange, 'date');

    const canvas = document.getElementById('secPhChart');
    if (secChart) { secChart.destroy(); secChart = null; }

    if (!prices.length) {
      document.getElementById('secPhContent').style.display = 'none';
      document.getElementById('secPhEmpty').style.display   = '';
      return;
    }
    document.getElementById('secPhContent').style.display = '';
    document.getElementById('secPhEmpty').style.display   = 'none';

    const isDown = prices.length > 1 && prices[prices.length - 1].close < prices[0].close;
    const color  = isDown ? '#c0392b' : '#1a7a3c';

    secChart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: prices.map(p => p.date),
        datasets: [{
          label:           INV_NAME,
          data:            prices.map(p => p.close),
          borderColor:     color,
          borderWidth:     1.5,
          pointRadius:     prices.length > 90 ? 0 : 2,
          pointHoverRadius: 4,
          tension:         0.2,
          fill:            false,
        }],
        txnMarkers: txns,
      },
      options: {
        animation:          false,
        responsive:         true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: items => fmtDate(items[0].label),
              label: ctx2  => '$' + ctx2.parsed.y.toFixed(2),
            },
          },
        },
        scales: {
          x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#888' }, grid: { color: '#eee' } },
          y: { ticks: { font: { size: 10 }, color: '#888', callback: v => '$' + v.toFixed(2) }, grid: { color: '#eee' } },
        },
      },
      plugins: [txnMarkerPlugin],
    });
  }

  function fmtDate(iso) {
    const [y, m, d] = iso.split('-');
    return m + '/' + d + '/' + y;
  }

  document.getElementById('secPhRangeBtns').addEventListener('click', e => {
    const btn = e.target.closest('.ph-range-btn');
    if (!btn) return;
    activeRange = btn.dataset.range;
    document.querySelectorAll('#secPhRangeBtns .ph-range-btn').forEach(b =>
      b.classList.toggle('ph-range-active', b.dataset.range === activeRange));
    renderChart();
  });

  if (!PRICE_HISTORY.length) {
    document.getElementById('secPhContent').style.display = 'none';
    document.getElementById('secPhEmpty').style.display   = '';
  } else {
    renderChart();
  }
})();

(function(){
  const fromInput = document.getElementById('secPerfFrom');
  const toInput   = document.getElementById('secPerfTo');
  if (!fromInput || !toInput) return;
  const today     = new Date().toISOString().slice(0, 10);
  const lifeStart = <?= json_encode($firstTxnDate) ?>;

  document.querySelectorAll('.sec-perf-preset').forEach(btn => {
    btn.addEventListener('click', () => {
      toInput.value = today;
      if (btn.dataset.all) {
        fromInput.value = lifeStart;
      } else if (btn.dataset.ytd) {
        fromInput.value = today.slice(0, 4) + '-01-01';
      } else {
        const months = parseInt(btn.dataset.months);
        const d = new Date();
        d.setMonth(d.getMonth() - months);
        fromInput.value = d.toISOString().slice(0, 10);
      }
      document.getElementById('secPerfForm').submit();
    });
  });
})();

function exportSecurityCSV() {
  const ACT_LABELS = {
    buy:          'Buy',
    sell:         'Sell',
    add:          'Add',
    remove:       'Remove',
    split:        'Split',
    reinvest_div: 'Reinvest Div',
    reinvest_cap: 'Reinvest Cap',
    div:          'Dividend',
    int:          'Interest',
  };

  const cols = ['Date','Account','Activity','Shares','Price/Share','Commission','Total','Cleared','Memo'];
  const lines = [cols.map(csvCell).join(',')];

  for (const t of SEC_TRANSACTIONS) {
    const qty    = t.qty;
    const price  = t.price;
    const comm   = t.commission;
    let   total  = '';
    if (t.activity === 'buy')  total = qty * price + comm;
    if (t.activity === 'sell') total = Math.max(0, qty * price - comm);
    if (t.activity === 'div' || t.activity === 'int') total = Math.abs(t.amount);

    lines.push([
      t.date,
      t.account,
      ACT_LABELS[t.activity] ?? t.activity,
      qty  !== 0    ? qty    : '',
      price > 0 && !['add','remove','split'].includes(t.activity) ? price : '',
      comm > 0      ? comm   : '',
      total !== ''  ? total  : '',
      t.cleared === 'reconciled' ? 'R' : t.cleared === 'cleared' ? 'C' : '',
      t.memo,
    ].map(csvCell).join(','));
  }

  const name = SEC_SYMBOL || SEC_NAME;
  const blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = name.replace(/[^a-z0-9]+/gi, '_') + '_transactions.csv';
  a.click();
  URL.revokeObjectURL(url);
}

function csvCell(v) {
  if (v === null || v === undefined || v === '') return '';
  const s = String(v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

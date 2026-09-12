<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: ' . BASE_PATH . '/portfolio/index');
    exit;
}

$db = getDB();

// Numeric slug → look up by ID; otherwise by symbol (case-insensitive)
if (ctype_digit($slug)) {
    $stmt = $db->prepare('SELECT * FROM investments WHERE id = ? AND is_active = 1');
    $stmt->execute([(int)$slug]);
} else {
    $stmt = $db->prepare('SELECT * FROM investments WHERE UPPER(symbol) = UPPER(?) AND is_active = 1');
    $stmt->execute([$slug]);
}
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
    setFlash('error', 'Investment not found.');
    header('Location: ' . BASE_PATH . '/portfolio/index');
    exit;
}

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

// Use the same functions as portfolio/index for consistency (handles splits, investment cash filter)
$allHoldings  = getInvestmentHoldings();
$allCostBases = getInvestmentCostBases();
$allPrices    = getLatestInvestmentPrices();
$invHeld  = $allHoldings[$invId]  ?? [];
$basisRow = $allCostBases[$invId] ?? null;
$priceRow = $allPrices[$invId]    ?? null;

$latestPrice = $priceRow ? (float)$priceRow['price'] : null;

// Build per-account share balance + current value from holdings (same data source as portfolio/index)
$shareBalance = [];
foreach ($invHeld as $hld) {
    $qty = (float)$hld['quantity'];
    $shareBalance[(int)$hld['account_id']] = [
        'qty'   => $qty,
        'name'  => $hld['account_name'],
        'value' => $latestPrice !== null ? $qty * $latestPrice : null,
    ];
}
$sharesOwned     = array_sum(array_column($invHeld, 'quantity'));
$totalCost       = ($basisRow && $sharesOwned > 0.000001) ? $basisRow['avg_cost'] * $sharesOwned : 0.0;
$avgCostPerShare = ($basisRow && $sharesOwned > 0.000001) ? $basisRow['avg_cost'] : 0.0;
$mktValue        = ($latestPrice !== null && $sharesOwned > 0.000001) ? $latestPrice * $sharesOwned : null;
$gainLoss        = ($mktValue !== null && $totalCost > 0) ? $mktValue - $totalCost : null;
$gainPct         = ($gainLoss !== null && $totalCost > 0) ? ($gainLoss / $totalCost) * 100 : null;

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

// ── Performance & Cost Analysis date range ──────────────────────
$defaultPerfFrom = date('Y-m-d', strtotime('-1 year'));
$defaultPerfTo   = date('Y-m-d');
$perfFrom = $_GET['from'] ?? $defaultPerfFrom;
$perfTo   = $_GET['to']   ?? $defaultPerfTo;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $perfFrom)) $perfFrom = $defaultPerfFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $perfTo))   $perfTo   = $defaultPerfTo;
if ($perfTo < $perfFrom) $perfTo = $perfFrom;

// Same shared functions as reports/investment_performance.php, scoped to this
// one security, so the numbers always match that report for the same range.
$perfSeries = getInvestmentPerformanceSeries([$invId], $perfFrom, $perfTo);
$perfRow    = $perfSeries['series'][$invId] ?? null;
$cpaRow     = getInvestmentCostProfitAnalysis([$invId], $perfFrom, $perfTo)[$invId] ?? null;

// Indices with price history — for the comparison dropdown in the price history modal
$phIndicesStmt = $db->query(
    "SELECT id, name, symbol FROM investments
     WHERE type = 'Index' AND is_active = 1
       AND id IN (SELECT DISTINCT investment_id FROM investment_prices)
     ORDER BY name"
);
$phIndices = $phIndicesStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle   = $invName . ($symbol ? ' (' . $symbol . ')' : '') . ' — History';
$currentPage = 'portfolio';

include __DIR__ . '/../includes/header.php';
?>
<script>
const BASE_PATH      = '<?= BASE_PATH ?>';
const CSRF_TOKEN     = '<?= h(csrfToken()) ?>';
const SEC_NAME       = <?= json_encode($invName) ?>;
const SEC_SYMBOL     = <?= json_encode($symbol) ?>;
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
const PH_INDICES = <?= json_encode(array_map(fn($i) => [
    'id'     => (int)$i['id'],
    'name'   => $i['name'],
    'symbol' => $i['symbol'],
], $phIndices)) ?>;
</script>

<div class="page-header">
  <h2>
    <a href="<?= BASE_PATH ?>/portfolio/index" class="text-muted text-decoration-none me-1">
      <i class="bi bi-briefcase"></i>
    </a>
    <i class="bi bi-chevron-right text-muted small me-1"></i>
    <?php if ($symbol): ?>
      <span class="inv-symbol me-2"><?= h($symbol) ?></span>
    <?php endif; ?>
    <?= h($invName) ?>
    <span class="badge bg-secondary ms-2 fw-normal" style="font-size:.65em;vertical-align:middle">
      <?= h($inv['type']) ?>
    </span>
  </h2>
  <div class="d-flex align-items-center gap-2">
    <a href="<?= BASE_PATH ?>/portfolio/index" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> Portfolio
    </a>
  </div>
</div>

<?php if ($inv['memo']): ?>
<div class="text-muted small mb-3 ps-1"><?= h($inv['memo']) ?></div>
<?php endif; ?>

<!-- Shares owned + price history button -->
<div class="d-flex align-items-center gap-3 mb-3 ps-1 flex-wrap">
  <?php if ($sharesOwned > 0): ?>
  <span class="fs-5 fw-semibold">
    <?= rtrim(rtrim(number_format($sharesOwned, 6), '0'), '.') ?> shares owned
  </span>
  <?php if ($mktValue !== null): ?>
  <span class="text-muted" style="font-size:.95rem">
    Value: <strong class="inv-mktval text-body"><?= formatMoney($mktValue) ?></strong>
  </span>
  <?php endif; ?>
  <?php if ($totalCost > 0): ?>
  <span class="text-muted" style="font-size:.95rem">
    Cost: <strong class="text-body"><?= formatMoney($totalCost) ?></strong>
  </span>
  <span class="text-muted" style="font-size:.95rem">
    Avg cost/share: <strong class="text-body"><?= formatMoney($avgCostPerShare) ?></strong>
  </span>
  <?php endif; ?>
  <?php if ($gainLoss !== null): ?>
  <span class="inv-gain <?= $gainLoss >= 0 ? 'gain-pos' : 'gain-neg' ?>" style="font-size:.95rem">
    <?= ($gainLoss >= 0 ? '+' : '') . formatMoney($gainLoss) ?>
    <span class="gain-pct">(<?= ($gainPct >= 0 ? '+' : '') . number_format($gainPct, 1) ?>%)</span>
  </span>
  <?php endif; ?>
  <?php else: ?>
  <span class="text-muted">No shares currently held</span>
  <?php endif; ?>
  <button class="btn btn-outline-secondary btn-sm inv-price"
          data-id="<?= $invId ?>"
          data-name="<?= h($invName) ?>"
          data-symbol="<?= h($symbol) ?>"
          title="View price history">
    <i class="bi bi-graph-up"></i> Price History
  </button>
  <?php if ($symbol): ?>
  <a href="https://finance.yahoo.com/quote/<?= urlencode($symbol) ?>/"
     target="_blank" rel="noopener noreferrer"
     class="text-decoration-none" style="color:#333;font-size:.875rem;">
    <img src="<?= BASE_PATH ?>/assets/img/yahoo-finance.png" width="12" height="12" alt="" style="opacity:.85;vertical-align:baseline;"> Look up on Yahoo!
  </a>
  <?php endif; ?>
</div>

<!-- Transaction History -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h4 class="section-title mb-0"><i class="bi bi-list-ul"></i> Transaction History</h4>
    <?php if (!empty($transactions)): ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="exportSecurityCSV()">
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

<!-- Price History Section -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="section-title mb-0"><i class="bi bi-graph-up"></i> Price History</h4>
    <div class="ph-range-btns" id="secPhRangeBtns">
      <button class="ph-range-btn" data-range="1M">1M</button>
      <button class="ph-range-btn" data-range="3M">3M</button>
      <button class="ph-range-btn" data-range="6M">6M</button>
      <button class="ph-range-btn ph-range-active" data-range="1Y">1Y</button>
      <button class="ph-range-btn" data-range="ALL">All</button>
    </div>
  </div>
  <div id="secPhLoading" class="text-center py-4">
    <span class="spinner-border spinner-border-sm"></span> Loading…
  </div>
  <div id="secPhEmpty" style="display:none" class="text-center text-muted py-4">
    <i class="bi bi-graph-up" style="font-size:2rem"></i>
    <p class="mt-2 mb-0">No price history available.</p>
  </div>
  <div id="secPhContent" style="display:none">
    <div style="position:relative;height:260px">
      <canvas id="secPhChart"></canvas>
    </div>
    <div class="d-flex align-items-center gap-3 mt-2 small text-muted">
      <span><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#1a7a3c;color:#fff;font-weight:700;font-size:9px;line-height:14px;text-align:center">B</span> Buy</span>
      <span><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#c0392b;color:#fff;font-weight:700;font-size:9px;line-height:14px;text-align:center">S</span> Sell</span>
    </div>
  </div>
</div>

<!-- Performance & Cost Analysis -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="section-title mb-0"><i class="bi bi-graph-up-arrow"></i> Performance &amp; Cost Analysis</h4>
  </div>

  <form method="get" class="report-filters mb-2" id="secPerfForm">
    <input type="hidden" name="slug" value="<?= h($slug) ?>">
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
  <div class="mb-3 d-flex gap-1 flex-wrap align-items-center">
    <span class="text-muted small me-1">Range:</span>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="1">1M</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="3">3M</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="6">6M</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-ytd="1">YTD</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="12">1Y</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="24">2Y</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-months="60">5Y</button>
    <button type="button" class="btn btn-xs btn-outline-secondary sec-perf-preset" data-all="1">All</button>
  </div>

  <?php if ($perfRow === null): ?>
  <p class="text-muted small">No price history in this date range.</p>
  <?php else: ?>
  <h5 class="report-section-title" style="font-size:.95rem">Performance Summary</h5>
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
  <p class="text-muted small">Not currently held as of <?= formatDate($perfTo) ?> — no cost/profit analysis to show.</p>
  <?php else: ?>
  <h5 class="report-section-title" style="font-size:.95rem">Cost &amp; Profit Analysis</h5>
  <p class="text-muted small">
    Total Profit and Total Return % are approximations for this date range — see
    <a href="<?= BASE_PATH ?>/reports/investment_performance">Investment Performance</a> for the full methodology note.
  </p>
  <table class="table table-sm report-table mb-2">
    <thead>
      <tr>
        <th class="text-end">Shares</th><th class="text-end">Avg Cost</th><th class="text-end">Cost Basis</th>
        <th class="text-end">Market Value</th><th class="text-end">Unrealized G/L</th>
        <th class="text-end">Div/Interest</th><th class="text-end">Reinvested</th>
        <th class="text-end">Total Distrib.</th><th class="text-end">Total Profit</th><th class="text-end">Total Return</th>
      </tr>
    </thead>
    <tbody>
      <?php
        $uglCls = $cpaRow['unrealizedGainLoss'] !== null ? ($cpaRow['unrealizedGainLoss'] >= 0 ? 'amount-credit' : 'amount-debit') : '';
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

<?php include __DIR__ . '/../includes/price_history_modal.php'; ?>

<script>
(function () {
  const INV_ID     = <?= $invId ?>;
  const INV_NAME   = <?= json_encode($invName . ($symbol ? ' (' . $symbol . ')' : '')) ?>;
  const CHART_TXNS = <?= json_encode($chartTxns) ?>;

  let secChart    = null;
  let allPrices   = [];
  let activeRange = '1Y';

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
    const prices = filterByRange(allPrices, activeRange, 'date');
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

  (async function init() {
    try {
      const res  = await fetch(BASE_PATH + '/portfolio/price_history?investment_id=' + encodeURIComponent(INV_ID));
      const data = await res.json();
      document.getElementById('secPhLoading').style.display = 'none';
      if (!data.ok || !data.prices?.length) {
        document.getElementById('secPhEmpty').style.display = '';
        return;
      }
      allPrices = data.prices;
      document.getElementById('secPhContent').style.display = '';
      renderChart();
    } catch (e) {
      document.getElementById('secPhLoading').innerHTML =
        '<span class="text-danger small">Failed to load price history.</span>';
    }
  })();
})();

(function(){
  const fromInput = document.getElementById('secPerfFrom');
  const toInput   = document.getElementById('secPerfTo');
  if (!fromInput || !toInput) return;
  const today = new Date().toISOString().slice(0, 10);

  document.querySelectorAll('.sec-perf-preset').forEach(btn => {
    btn.addEventListener('click', () => {
      toInput.value = today;
      if (btn.dataset.all) {
        fromInput.value = '2000-01-01';
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

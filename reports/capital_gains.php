<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Filters ────────────────────────────────────────────────────
$yearFilter = (int)($_GET['year'] ?? 0);

$currentYear = (int)date('Y');

// Available years (from sell transactions)
$years = $db->query(
    "SELECT DISTINCT YEAR(t.transaction_date) AS yr
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     WHERE it.activity IN ('sell','remove')
     ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);

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

// ── Fetch all sell transactions ────────────────────────────────
$yearParams = [];
$yearWhere  = '';
if ($yearFilter) { $yearWhere = "AND YEAR(t.transaction_date) = ?"; $yearParams[] = $yearFilter; }

$stmt = $db->prepare(
    "SELECT
        it.id            AS it_id,
        i.id             AS inv_id,
        i.name           AS inv_name,
        i.symbol,
        i.type           AS inv_type,
        a.id             AS acct_id,
        a.name           AS acct_name,
        t.transaction_date AS date,
        it.quantity      AS sell_qty,
        it.price         AS sell_price,
        it.commission    AS sell_commission
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN investments   i ON i.id = it.investment_id
     JOIN accounts      a ON a.id = t.account_id
     WHERE it.activity IN ('sell','remove') AND a.is_investment_cash = 0
       $yearWhere
       $acctWhere
     ORDER BY t.transaction_date DESC, i.name"
);
$stmt->execute(array_merge($yearParams, $acctParams));
$sellRows = $stmt->fetchAll();

// ── For each sold investment+account pair, compute avg cost at time of sale
// We need all buy transactions up to and including the sale date per inv+account.
// Strategy: group sells by (inv_id, acct_id), then run a single cost-basis query per pair.
// To keep it efficient we fetch all buys for the filtered accounts at once.

$invAcctPairs = [];
foreach ($sellRows as $r) {
    $key = $r['inv_id'] . ':' . $r['acct_id'];
    $invAcctPairs[$key] = ['inv_id' => (int)$r['inv_id'], 'acct_id' => (int)$r['acct_id']];
}

// Fetch all buy transactions per inv+acct (all time, so we can reconstruct running cost basis)
$buysByPair = [];
if (!empty($invAcctPairs)) {
    // Build IN clause for pairs — use a temp join approach via PHP
    $pairParams = [];
    $pairWhere  = [];
    foreach ($invAcctPairs as $pair) {
        $pairWhere[]  = "(it.investment_id = ? AND a.id = ?)";
        $pairParams[] = $pair['inv_id'];
        $pairParams[] = $pair['acct_id'];
    }
    $pairSql = implode(' OR ', $pairWhere);

    $buyStmt = $db->prepare(
        "SELECT
            it.investment_id AS inv_id,
            a.id             AS acct_id,
            t.transaction_date AS date,
            it.activity,
            it.quantity,
            it.price,
            it.commission
         FROM investment_transactions it
         JOIN transactions t ON t.id  = it.transaction_id
         JOIN accounts     a ON a.id  = t.account_id
         WHERE ({$pairSql})
           AND it.activity IN ('buy','add','split','reinvest_div','reinvest_cap','sell','remove')
         ORDER BY t.transaction_date ASC, it.id ASC"
    );
    $buyStmt->execute($pairParams);
    foreach ($buyStmt->fetchAll() as $b) {
        $key = $b['inv_id'] . ':' . $b['acct_id'];
        $buysByPair[$key][] = $b;
    }
}

// ── Compute avg cost at time of each sell ──────────────────────
// Running avg cost: recalculate after each buy/sell in chronological order.
// For each sell transaction (by it_id), snapshot the avg cost just before the sale.

// Build a map of sell it_id → avg cost at time of sale
$avgCostAtSale = [];

foreach ($invAcctPairs as $key => $pair) {
    $txns = $buysByPair[$key] ?? [];
    $runningQty  = 0.0;
    $runningCost = 0.0;

    foreach ($txns as $tx) {
        $qty  = (float)$tx['quantity'];
        $act  = $tx['activity'];

        if (in_array($act, ['buy','add','reinvest_div','reinvest_cap'])) {
            $cost = $qty * (float)$tx['price'] + (float)$tx['commission'];
            $runningCost += $cost;
            $runningQty  += $qty;
        } elseif ($act === 'split') {
            // Split: adjust qty only, cost basis stays same (avg cost per share drops)
            $runningQty += $qty;
        } elseif (in_array($act, ['sell','remove'])) {
            $avgCost = $runningQty > 0 ? $runningCost / $runningQty : 0.0;
            // We need to identify which sell row this maps to —
            // since sells are ordered same way, store per (inv_id, acct_id, date, qty)
            // Use a compound key since we can't match by it_id here
            $saleKey = $key . ':' . $tx['date'] . ':' . rtrim(rtrim(number_format($qty, 8, '.', ''), '0'), '.');
            $avgCostAtSale[$saleKey] = $avgCost;

            // Reduce running cost basis
            $runningCost -= $avgCost * $qty;
            $runningQty  -= $qty;
            if ($runningQty < 0.000001) { $runningQty = 0.0; $runningCost = 0.0; }
        }
    }
}

// ── Build display rows ─────────────────────────────────────────
$rows               = [];
$totalProceeds      = 0.0;
$totalCostBasis     = 0.0;
$totalGainLoss      = 0.0;
$totalGainLossShort = 0.0; // placeholder — we don't track holding period
$totalCommissions   = 0.0;

foreach ($sellRows as $r) {
    $key      = $r['inv_id'] . ':' . $r['acct_id'];
    $sellQty  = (float)$r['sell_qty'];
    $sellPrc  = (float)$r['sell_price'];
    $sellComm = (float)$r['sell_commission'];
    $proceeds = $sellQty * $sellPrc - $sellComm;

    $saleKey  = $key . ':' . $r['date'] . ':' . rtrim(rtrim(number_format($sellQty, 8, '.', ''), '0'), '.');
    $avgCost  = $avgCostAtSale[$saleKey] ?? null;
    $costBasis = $avgCost !== null ? $avgCost * $sellQty : null;
    $gainLoss  = $costBasis !== null ? $proceeds - $costBasis : null;
    $gainLossPct = ($gainLoss !== null && $costBasis > 0) ? ($gainLoss / $costBasis) * 100 : null;

    $totalProceeds    += $proceeds;
    $totalCommissions += $sellComm;
    if ($costBasis !== null) $totalCostBasis += $costBasis;
    if ($gainLoss  !== null) $totalGainLoss  += $gainLoss;

    $rows[] = [
        'inv_name'    => $r['inv_name'],
        'symbol'      => $r['symbol'],
        'inv_type'    => $r['inv_type'],
        'acct_name'   => $r['acct_name'],
        'date'        => $r['date'],
        'sell_qty'    => $sellQty,
        'sell_price'  => $sellPrc,
        'proceeds'    => $proceeds,
        'avgCost'     => $avgCost,
        'costBasis'   => $costBasis,
        'gainLoss'    => $gainLoss,
        'gainLossPct' => $gainLossPct,
        'commission'  => $sellComm,
    ];
}

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($rows as $r) {
        $csvRows[] = [
            $r['inv_name'],
            $r['symbol'],
            $r['inv_type'],
            $r['acct_name'],
            $r['date'],
            number_format($r['sell_qty'],  6, '.', ''),
            number_format($r['sell_price'],2, '.', ''),
            number_format($r['proceeds'],  2, '.', ''),
            $r['avgCost']   !== null ? number_format($r['avgCost'],   2, '.', '') : '',
            $r['costBasis'] !== null ? number_format($r['costBasis'], 2, '.', '') : '',
            $r['gainLoss']  !== null ? number_format($r['gainLoss'],  2, '.', '') : '',
            $r['gainLossPct'] !== null ? number_format($r['gainLossPct'], 2, '.', '') : '',
        ];
    }
    outputCsv(
        'capital_gains_' . date('Y-m-d') . '.csv',
        ['Security','Symbol','Type','Account','Date Sold','Shares','Sale Price',
         'Proceeds','Avg Cost','Cost Basis','Gain/Loss','Return %'],
        $csvRows
    );
}

$pageTitle   = 'Capital Gains';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Capital Gains'; $reportFavIcon = 'bi-cash-coin'; ?>
<div class="page-header">
  <h2><i class="bi bi-cash-coin"></i> Capital Gains</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>Year</label>
    <select name="year" class="form-select form-select-sm">
      <option value="0">All Years</option>
      <?php foreach ($years as $yr): ?>
      <option value="<?= $yr ?>" <?= $yearFilter == $yr ? 'selected' : '' ?>><?= $yr ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <?php if ($yearFilter || $filteringAccts): ?>
    <a href="<?= BASE_PATH ?>/reports/capital_gains" class="btn btn-sm btn-outline-secondary">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($rows)): ?>
<p class="text-muted">No sale transactions found.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total Proceeds</div>
    <div class="tile-value"><?= formatMoney($totalProceeds) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total Cost Basis</div>
    <div class="tile-value"><?= formatMoney($totalCostBasis) ?></div>
  </div>
  <div class="report-tile <?= $totalGainLoss >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Total Gain / Loss</div>
    <div class="tile-value">
      <?= ($totalGainLoss >= 0 ? '+' : '') . formatMoney($totalGainLoss) ?>
    </div>
  </div>
  <?php if ($totalCommissions > 0): ?>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Commissions</div>
    <div class="tile-value"><?= formatMoney($totalCommissions) ?></div>
  </div>
  <?php endif; ?>
</div>

<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Security</th>
      <th>Type</th>
      <th>Account</th>
      <th class="text-end">Date Sold</th>
      <th class="text-end">Shares</th>
      <th class="text-end">Sale Price</th>
      <th class="text-end">Proceeds</th>
      <th class="text-end">Avg Cost</th>
      <th class="text-end">Cost Basis</th>
      <th class="text-end">Gain / Loss</th>
      <th class="text-end">Return %</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r):
      $glCls   = $r['gainLoss'] !== null ? ($r['gainLoss'] >= 0 ? 'gain-pos' : 'gain-neg') : '';
      $glSign  = $r['gainLoss'] !== null && $r['gainLoss'] < 0 ? '-' : '+';
      $pctSign = $r['gainLossPct'] !== null && $r['gainLossPct'] >= 0 ? '+' : '';
    ?>
    <tr>
      <td>
        <strong><?= h($r['inv_name']) ?></strong>
        <?php if ($r['symbol']): ?>
        <span class="text-muted small ms-1"><?= h($r['symbol']) ?></span>
        <?php endif; ?>
      </td>
      <td class="text-muted small"><?= h($r['inv_type']) ?></td>
      <td class="text-muted small"><?= h($r['acct_name']) ?></td>
      <td class="text-end"><?= formatDate($r['date']) ?></td>
      <td class="text-end"><?= rtrim(rtrim(number_format($r['sell_qty'], 6), '0'), '.') ?></td>
      <td class="text-end"><?= formatMoney($r['sell_price']) ?></td>
      <td class="text-end"><?= formatMoney($r['proceeds']) ?></td>
      <td class="text-end"><?= $r['avgCost'] !== null ? formatMoney($r['avgCost']) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end"><?= $r['costBasis'] !== null ? formatMoney($r['costBasis']) : '<span class="text-muted">—</span>' ?></td>
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
    <?php $totGlCls = $totalGainLoss >= 0 ? 'gain-pos' : 'gain-neg'; ?>
    <tr>
      <td colspan="6"><strong>Total</strong></td>
      <td class="text-end"><strong><?= formatMoney($totalProceeds) ?></strong></td>
      <td></td>
      <td class="text-end"><strong><?= formatMoney($totalCostBasis) ?></strong></td>
      <td class="text-end <?= $totGlCls ?>">
        <strong><?= ($totalGainLoss >= 0 ? '+' : '-') ?><?= formatMoney(abs($totalGainLoss)) ?></strong>
      </td>
      <td></td>
    </tr>
  </tfoot>
</table>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

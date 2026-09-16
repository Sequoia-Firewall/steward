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
       -- A zero-price 'remove' is a holdings-reconciliation share adjustment, not a
       -- real sale — exclude it here (display only; the cost-basis replay below still
       -- processes it so later real sales keep the correct running avg cost).
       AND NOT (it.activity = 'remove' AND it.price = 0)
       $yearWhere
       $acctWhere
     ORDER BY t.transaction_date DESC, i.name"
);
$stmt->execute(array_merge($yearParams, $acctParams));
$sellRows = $stmt->fetchAll();

// ── Avg cost at time of each sale ───────────────────────────────
// Uses the shared chronological replay engine (same one Holdings, Portfolio
// Performance, and the security page use) instead of a separate copy of this
// logic, so a sale's cost basis here always matches what those pages show for
// the same shares. Runs unfiltered (all accounts, no date cutoff) since a
// sale's cost basis depends on its full purchase history, not just what this
// report's filters happen to include; $avgCostAtSale is then looked up per
// sale by investment_transactions.id below.
_investmentCostBasisPools(null, $avgCostAtSale);

// ── Build display rows ─────────────────────────────────────────
$rows               = [];
$totalProceeds      = 0.0;
$totalCostBasis     = 0.0;
$totalGainLoss      = 0.0;
$totalGainLossShort = 0.0; // placeholder — we don't track holding period
$totalCommissions   = 0.0;

foreach ($sellRows as $r) {
    $sellQty  = (float)$r['sell_qty'];
    $sellPrc  = (float)$r['sell_price'];
    $sellComm = (float)$r['sell_commission'];
    $proceeds = $sellQty * $sellPrc - $sellComm;

    $avgCost   = $avgCostAtSale[(int)$r['it_id']] ?? null;
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
      <th class="sortable" data-col="security">Security <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="sortable" data-col="type">Type <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="sortable" data-col="account">Account <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="date">Date Sold <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="shares">Shares <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="saleprice">Sale Price <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="proceeds">Proceeds <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="avgcost">Avg Cost <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="costbasis">Cost Basis <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="gainloss">Gain / Loss <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="gainlosspct">Return % <i class="bi bi-arrow-down-up sort-icon"></i></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r):
      $glCls   = $r['gainLoss'] !== null ? ($r['gainLoss'] >= 0 ? 'gain-pos' : 'gain-neg') : '';
      $glSign  = $r['gainLoss'] !== null && $r['gainLoss'] < 0 ? '-' : '+';
      $pctSign = $r['gainLossPct'] !== null && $r['gainLossPct'] >= 0 ? '+' : '';
    ?>
    <tr data-security="<?= h(strtolower($r['inv_name'])) ?>"
        data-type="<?= h(strtolower($r['inv_type'])) ?>"
        data-account="<?= h(strtolower($r['acct_name'])) ?>"
        data-date="<?= h($r['date']) ?>"
        data-shares="<?= $r['sell_qty'] ?>"
        data-saleprice="<?= $r['sell_price'] ?>"
        data-proceeds="<?= $r['proceeds'] ?>"
        data-avgcost="<?= $r['avgCost'] ?? '' ?>"
        data-costbasis="<?= $r['costBasis'] ?? '' ?>"
        data-gainloss="<?= $r['gainLoss'] ?? '' ?>"
        data-gainlosspct="<?= $r['gainLossPct'] ?? '' ?>">
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

<script>
(function () {
  const numCols = new Set(['date', 'shares', 'saleprice', 'proceeds', 'avgcost', 'costbasis', 'gainloss', 'gainlosspct']);
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

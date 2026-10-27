<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Filters ────────────────────────────────────────────────────
$yearFilter = (int)($_GET['year'] ?? 0);
// Retirement accounts are left out unless asked for: their sales aren't taxable events
$includeRet = !empty($_GET['ret']);

$currentYear = (int)date('Y');

$allAccounts = $db->query(
    "SELECT id, name, 'Investment' AS type FROM accounts
     WHERE type = 'Investment' AND is_investment_cash = 0 AND is_active = 1"
     . ($includeRet ? '' : ' AND is_retirement = 0') . "
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

// ── Realized sales (FIFO) ──────────────────────────────────────
// getRealizedSales() matches each sale against the oldest lots still held (the same
// lots Holdings and Portfolio use) and returns only real disposals: every sell, plus
// a remove that is a security expiring worthless. It runs over all accounts and the
// full history, since a sale's basis depends on every earlier purchase, not just what
// this report's filters include.
$eligibleSales = array_values(array_filter(getRealizedSales(),
    fn($s) => in_array($s['account_id'], $allAcctIds, true)));

// Available years: from sales in the accounts this report can show
$years = array_values(array_unique(array_map(fn($s) => (int)substr($s['date'], 0, 4), $eligibleSales)));
rsort($years);

$invMeta  = [];
foreach ($db->query('SELECT id, name, symbol, type FROM investments')->fetchAll() as $i) $invMeta[(int)$i['id']] = $i;
$acctName = array_column($allAccounts, 'name', 'id');

// ── Build display rows: one per sale per holding term (Form 8949 style) ──
$rows       = [];
$totals     = ['proceeds' => 0.0, 'basis' => 0.0, 'gain' => 0.0];
$termTotals = ['short' => $totals, 'long' => $totals];
$totalCommissions = 0.0;

foreach (array_reverse($eligibleSales) as $s) {
    if ($yearFilter && (int)substr($s['date'], 0, 4) !== $yearFilter) continue;
    if ($filteringAccts && !in_array($s['account_id'], $selectedAcctIds, true)) continue;
    $inv = $invMeta[$s['investment_id']] ?? ['name' => '?', 'symbol' => '', 'type' => ''];
    $totalCommissions += $s['commission'];

    foreach (['short', 'long'] as $term) {
        $qty = $s['qty_' . $term];
        if ($qty <= 0.0000001) continue;
        $lots     = array_values(array_filter($s['lots'], fn($l) => ($l['long_term'] ? 'long' : 'short') === $term));
        $acqDates = array_values(array_unique(array_filter(array_column($lots, 'acquired'))));
        sort($acqDates);
        $heldDays = array_map(fn($d) => (int)round((strtotime($s['date']) - strtotime($d)) / 86400), $acqDates);
        $proceeds = $s['proceeds_' . $term];
        $basis    = $s['basis_' . $term];
        $gain     = $s['gain_' . $term];

        $termTotals[$term]['proceeds'] += $proceeds;
        $termTotals[$term]['basis']    += $basis;
        $termTotals[$term]['gain']     += $gain;

        $rows[] = [
            'inv_name'    => $inv['name'],
            'symbol'      => $inv['symbol'],
            'inv_type'    => $inv['type'],
            'acct_name'   => $acctName[$s['account_id']] ?? '',
            'acquired'    => count($acqDates) === 1 ? $acqDates[0] : (count($acqDates) > 1 ? 'Various' : null),
            'held_min'    => $heldDays ? min($heldDays) : null,
            'held_max'    => $heldDays ? max($heldDays) : null,
            'term'        => $term,
            'date'        => $s['date'],
            'sell_qty'    => $qty,
            'sell_price'  => $s['price'],
            'proceeds'    => $proceeds,
            'costPerShare'=> $basis / $qty,
            'costBasis'   => $basis,
            'gainLoss'    => $gain,
            'gainLossPct' => $basis > 0 ? $gain / $basis * 100 : null,
            'lots'        => $lots,
        ];
    }
}
foreach (['proceeds', 'basis', 'gain'] as $k) {
    $totals[$k] = $termTotals['short'][$k] + $termTotals['long'][$k];
}
$totalProceeds  = $totals['proceeds'];
$totalCostBasis = $totals['basis'];
$totalGainLoss  = $totals['gain'];

// Days held, shown compactly: "245 d" under a year, "1.4 yr" after
function cgHeld(?int $d): string {
    if ($d === null) return '—';
    return $d < 365 ? $d . ' d' : number_format($d / 365.25, 1) . ' yr';
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
            $r['acquired'] ?? '',
            $r['date'],
            $r['held_min'] === null ? '' : ($r['held_min'] === $r['held_max'] ? $r['held_min'] : $r['held_min'] . '-' . $r['held_max']),
            $r['term'] === 'long' ? 'Long-term' : 'Short-term',
            number_format($r['sell_qty'],  6, '.', ''),
            number_format($r['sell_price'],2, '.', ''),
            number_format($r['proceeds'],  2, '.', ''),
            number_format($r['costPerShare'], 4, '.', ''),
            number_format($r['costBasis'], 2, '.', ''),
            number_format($r['gainLoss'],  2, '.', ''),
            $r['gainLossPct'] !== null ? number_format($r['gainLossPct'], 2, '.', '') : '',
        ];
    }
    outputCsv(
        'capital_gains_' . date('Y-m-d') . '.csv',
        ['Security','Symbol','Type','Account','Acquired','Date Sold','Days Held','Term','Shares','Sale Price',
         'Proceeds','Cost/Share','Cost Basis','Gain/Loss','Return %'],
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
  <div class="filter-group">
    <label class="form-check-label small d-flex align-items-center gap-1" style="margin-top:1.6rem">
      <input type="checkbox" class="form-check-input mt-0" name="ret" value="1" <?= $includeRet ? 'checked' : '' ?>
             onchange="this.form.querySelector('[name=accts]') && (this.form.querySelector('[name=accts]').value = ''); this.form.submit()">
      Include Retirement Accounts
    </label>
  </div>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <?php if ($yearFilter || $filteringAccts || $includeRet): ?>
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
  <?php foreach (['short' => 'Short-Term', 'long' => 'Long-Term'] as $tk => $tl): $tg = $termTotals[$tk]['gain']; ?>
  <div class="report-tile <?= $tg >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label"><?= $tl ?> Gain / Loss</div>
    <div class="tile-value"><?= ($tg >= 0 ? '+' : '') . formatMoney($tg) ?></div>
  </div>
  <?php endforeach; ?>
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
      <th class="text-end sortable" data-col="acquired">Acquired <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="date">Date Sold <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="held">Held <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="sortable" data-col="term">Term <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="shares">Shares <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="saleprice">Sale Price <i class="bi bi-arrow-down-up sort-icon"></i></th>
      <th class="text-end sortable" data-col="proceeds">Proceeds <i class="bi bi-arrow-down-up sort-icon"></i></th>
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
        data-acquired="<?= h($r['acquired'] ?? '') ?>"
        data-date="<?= h($r['date']) ?>"
        data-held="<?= $r['held_min'] ?? '' ?>"
        data-term="<?= h($r['term']) ?>"
        data-shares="<?= $r['sell_qty'] ?>"
        data-saleprice="<?= $r['sell_price'] ?>"
        data-proceeds="<?= $r['proceeds'] ?>"
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
      <td class="text-end"<?= $r['acquired'] === 'Various' ? ' title="' . h(implode(', ', array_map(fn($l) => $l['acquired'] ? formatDate($l['acquired']) . ' (' . rtrim(rtrim(number_format($l['qty'], 6), '0'), '.') . ' sh)' : '', $r['lots']))) . '"' : '' ?>>
        <?= $r['acquired'] === null ? '<span class="text-muted">—</span>' : ($r['acquired'] === 'Various' ? 'Various' : formatDate($r['acquired'])) ?>
      </td>
      <td class="text-end"><?= formatDate($r['date']) ?></td>
      <td class="text-end text-nowrap"><?= $r['held_min'] === $r['held_max'] ? cgHeld($r['held_min']) : cgHeld($r['held_min']) . '–' . cgHeld($r['held_max']) ?></td>
      <td><span class="badge <?= $r['term'] === 'long' ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= $r['term'] === 'long' ? 'Long' : 'Short' ?></span></td>
      <td class="text-end"><?= rtrim(rtrim(number_format($r['sell_qty'], 6), '0'), '.') ?></td>
      <td class="text-end"><?= formatMoney($r['sell_price']) ?></td>
      <td class="text-end"><?= formatMoney($r['proceeds']) ?></td>
      <td class="text-end"><?= formatMoney($r['costBasis']) ?></td>
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
    <?php foreach (['short' => 'Short-term subtotal', 'long' => 'Long-term subtotal'] as $tk => $tl):
      $tt = $termTotals[$tk]; $ttCls = $tt['gain'] >= 0 ? 'gain-pos' : 'gain-neg'; ?>
    <tr class="text-muted">
      <td colspan="9"><?= $tl ?></td>
      <td class="text-end"><?= formatMoney($tt['proceeds']) ?></td>
      <td class="text-end"><?= formatMoney($tt['basis']) ?></td>
      <td class="text-end <?= $ttCls ?>"><?= ($tt['gain'] >= 0 ? '+' : '-') . formatMoney(abs($tt['gain'])) ?></td>
      <td></td>
    </tr>
    <?php endforeach; ?>
    <?php $totGlCls = $totalGainLoss >= 0 ? 'gain-pos' : 'gain-neg'; ?>
    <tr>
      <td colspan="9"><strong>Total</strong></td>
      <td class="text-end"><strong><?= formatMoney($totalProceeds) ?></strong></td>
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
  const numCols = new Set(['held', 'shares', 'saleprice', 'proceeds', 'costbasis', 'gainloss', 'gainlosspct']);
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

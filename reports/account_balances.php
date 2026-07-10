<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Account filter (all types) ────────────────────────────────
$allAccounts = $db->query(
    "SELECT id, name, type FROM accounts
     WHERE is_active = 1 AND is_closed = 0 AND is_investment_cash = 0
     ORDER BY type, name"
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

// ── As-of date ────────────────────────────────────────────────
$today = date('Y-m-d');
$rawDate = trim($_GET['as_of'] ?? '');
$asOf = ($rawDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) ? $rawDate : $today;
$isHistorical = ($asOf < $today);

// ── Fetch all active accounts with balance as of $asOf ────────
$acctStmt = $db->prepare(
    "SELECT a.*,
            a.opening_balance + COALESCE(
                (SELECT SUM(t.amount) FROM transactions t
                 WHERE t.account_id = a.id AND t.transaction_date <= :asOf), 0
            ) AS current_balance
     FROM accounts a
     WHERE a.is_active = 1 AND a.is_closed = 0
     ORDER BY a.name"
);
$acctStmt->execute([':asOf' => $asOf]);
$accounts = $acctStmt->fetchAll();

// ── Compute market value per investment account as of $asOf ───
$holdingsStmt = $db->prepare(
    "SELECT
        a.id AS acct_id,
        i.id AS inv_id,
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
     WHERE a.is_investment_cash = 0 AND a.is_active = 1 AND a.is_closed = 0 AND i.is_active = 1
       AND t.transaction_date <= :asOf
     GROUP BY a.id, i.id
     HAVING net_qty > 0.000001"
);
$holdingsStmt->execute([':asOf' => $asOf]);

// ── Latest prices on or before $asOf ─────────────────────────
$priceStmt = $db->prepare(
    "SELECT investment_id, close_price, price_date
     FROM (
         SELECT investment_id, close_price, price_date,
                ROW_NUMBER() OVER (PARTITION BY investment_id ORDER BY price_date DESC) AS rn
         FROM investment_prices
         WHERE price_date <= :asOf
     ) ranked
     WHERE rn = 1"
);
$priceStmt->execute([':asOf' => $asOf]);
$latestPrices = [];
foreach ($priceStmt->fetchAll() as $row) {
    $latestPrices[(int)$row['investment_id']] = (float)$row['close_price'];
}

// Build market value per account_id
$acctMarketValue = [];
foreach ($holdingsStmt->fetchAll() as $h) {
    $acctId  = (int)$h['acct_id'];
    $invId   = (int)$h['inv_id'];
    $qty     = (float)$h['net_qty'];
    $buyQty  = (float)$h['buy_qty'];
    $buyCost = (float)$h['buy_cost'];
    $avgCost = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $price   = $latestPrices[$invId] ?? null;
    $mv      = $price !== null ? $price * $qty : $avgCost * $qty;
    $acctMarketValue[$acctId] = ($acctMarketValue[$acctId] ?? 0.0) + $mv;
}

// ── Index accounts and build linked-cash map ──────────────────
$byId        = [];
$cashByInvId = [];
foreach ($accounts as $a) {
    $byId[(int)$a['id']] = $a;
}
foreach ($accounts as $a) {
    if ($a['is_investment_cash'] && $a['linked_account_id']) {
        $cashByInvId[(int)$a['linked_account_id']] = $a;
    }
}

// ── Group accounts into display sections ──────────────────────
$sections = [
    'checking'   => ['label' => 'Checking',     'icon' => 'bi-bank',            'rows' => []],
    'savings'    => ['label' => 'Savings',       'icon' => 'bi-piggy-bank',      'rows' => []],
    'credit'     => ['label' => 'Credit Cards',  'icon' => 'bi-credit-card',     'rows' => []],
    'invest'     => ['label' => 'Investments',   'icon' => 'bi-graph-up-arrow',  'rows' => []],
    'retirement' => ['label' => 'Retirement',    'icon' => 'bi-piggy-bank-fill', 'rows' => []],
    'asset'      => ['label' => 'Assets',        'icon' => 'bi-safe2',           'rows' => []],
];

foreach ($accounts as $a) {
    if ($a['is_investment_cash']) continue;
    if ($filteringAccts && !in_array((int)$a['id'], $selectedAcctIds)) continue;
    $type = $a['type'];
    if      ($type === 'Checking')                         { $sections['checking']['rows'][]   = $a; }
    elseif  ($type === 'Savings')                          { $sections['savings']['rows'][]     = $a; }
    elseif  ($type === 'Credit Card')                      { $sections['credit']['rows'][]      = $a; }
    elseif  ($type === 'Investment' && !$a['is_retirement']){ $sections['invest']['rows'][]     = $a; }
    elseif  ($type === 'Investment' && $a['is_retirement']) { $sections['retirement']['rows'][] = $a; }
    elseif  ($type === 'Asset')                            { $sections['asset']['rows'][]       = $a; }
}

// ── Display balance helper ────────────────────────────────────
function displayBalance(array $a, array $acctMarketValue): float {
    if ($a['type'] === 'Investment' && !$a['is_investment_cash']) {
        return $acctMarketValue[(int)$a['id']] ?? (float)$a['current_balance'];
    }
    return (float)$a['current_balance'];
}

// ── Section totals ────────────────────────────────────────────
$sectionTotals = [];
foreach ($sections as $key => $sec) {
    $total = 0.0;
    foreach ($sec['rows'] as $a) {
        $total += displayBalance($a, $acctMarketValue);
        $cashRow = $cashByInvId[(int)$a['id']] ?? null;
        if ($cashRow) $total += (float)$cashRow['current_balance'];
    }
    $sectionTotals[$key] = $total;
}

$totalAssets      = $sectionTotals['checking'] + $sectionTotals['savings']
                  + $sectionTotals['invest']   + $sectionTotals['retirement']
                  + $sectionTotals['asset'];
$totalLiabilities = abs(min(0.0, $sectionTotals['credit']));
$netWorth         = $totalAssets - $totalLiabilities;

// % of net worth base: use abs(netWorth) so sign of each account drives the sign of its %
$pctBase = abs($netWorth) > 0.005 ? abs($netWorth) : null;

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    $sectionLabels = [
        'checking'   => 'Checking',
        'savings'    => 'Savings',
        'credit'     => 'Credit Cards',
        'invest'     => 'Investments',
        'retirement' => 'Retirement',
        'asset'      => 'Assets',
    ];
    foreach ($sections as $key => $sec) {
        foreach ($sec['rows'] as $a) {
            $bal    = displayBalance($a, $acctMarketValue);
            $pct    = $pctBase !== null ? round(($key === 'credit' ? -abs($bal) : $bal) / $pctBase * 100, 1) : '';
            $csvRows[] = [$sectionLabels[$key], $a['name'], $a['institution'] ?: '',
                          number_format(abs($bal), 2, '.', ''), $pct !== '' ? $pct . '%' : ''];
            $cashRow = $cashByInvId[(int)$a['id']] ?? null;
            if ($cashRow) {
                $cashBal = (float)$cashRow['current_balance'];
                $cashPct = $pctBase !== null ? round($cashBal / $pctBase * 100, 1) : '';
                $csvRows[] = [$sectionLabels[$key] . ' (Cash)', $cashRow['name'],
                              $cashRow['institution'] ?: '',
                              number_format($cashBal, 2, '.', ''),
                              $cashPct !== '' ? $cashPct . '%' : ''];
            }
        }
    }
    $csvRows[] = ['Total Assets',      '', '', number_format($totalAssets, 2, '.', ''),      ''];
    $csvRows[] = ['Total Liabilities', '', '', number_format($totalLiabilities, 2, '.', ''),  ''];
    $csvRows[] = ['Net Worth',         '', '', number_format($netWorth, 2, '.', ''),          ''];
    outputCsv(
        'account_balances_' . $asOf . '.csv',
        ['Section', 'Account', 'Institution', 'Balance', '% of Net Worth'],
        $csvRows
    );
}

$pageTitle   = 'Account Balances';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Account Balances'; $reportFavIcon = 'bi-wallet2'; ?>
<div class="page-header">
  <h2><i class="bi bi-wallet2"></i> Account Balances</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<!-- Filters -->
<form method="get" class="report-filters mb-4">
  <div class="filter-group">
    <label class="ab-asof-label" for="as_of"><i class="bi bi-calendar3"></i> As of</label>
    <input type="date" id="as_of" name="as_of"
           class="form-control form-control-sm ab-asof-input"
           value="<?= h($asOf) ?>" max="<?= $today ?>">
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <?php if ($asOf !== $today): ?>
    <a href="?" class="btn btn-sm btn-outline-secondary">Today</a>
    <?php endif; ?>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
  </div>
</form>
<?php if ($isHistorical): ?>
<div class="mb-3">
  <span class="ab-asof-badge"><i class="bi bi-clock-history"></i> Historical view: <?= h(date('M j, Y', strtotime($asOf))) ?></span>
</div>
<?php endif; ?>

<div class="report-tiles mb-4">
  <div class="report-tile tile-positive">
    <div class="tile-label">Total Assets</div>
    <div class="tile-value"><?= formatMoney($totalAssets) ?></div>
  </div>
  <div class="report-tile tile-negative">
    <div class="tile-label">Total Liabilities</div>
    <div class="tile-value"><?= formatMoney($totalLiabilities) ?></div>
  </div>
  <div class="report-tile <?= $netWorth >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net Worth</div>
    <div class="tile-value"><?= formatMoney($netWorth) ?></div>
  </div>
</div>

<?php
function renderSection(array $sec, string $key, array $cashByInvId, array $acctMarketValue, float $sectionTotal, ?float $pctBase): void {
    if (empty($sec['rows'])) return;
    $isCredit = $key === 'credit';
    $isInvest = in_array($key, ['invest', 'retirement']);
    ?>
<div class="ab-section">
  <div class="ab-section-header">
    <span class="ab-section-title"><i class="bi <?= $sec['icon'] ?>"></i> <?= $sec['label'] ?></span>
    <span class="ab-section-total <?= $isCredit ? 'gain-neg' : '' ?>"><?= formatMoney(abs($sectionTotal)) ?></span>
  </div>
  <table class="table table-sm report-table ab-table">
    <thead>
      <tr>
        <th>Account</th>
        <th>Institution</th>
        <th class="text-end"><?= $isInvest ? 'Market Value' : 'Balance' ?></th>
        <?php if ($pctBase !== null): ?>
        <th class="text-end">% of Net Worth</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sec['rows'] as $a):
        $bal     = displayBalance($a, $acctMarketValue);
        $balCls  = $isCredit ? 'gain-neg' : (round($bal, MONEY_DECIMALS) < 0 ? 'gain-neg' : '');
        $cashRow = $cashByInvId[(int)$a['id']] ?? null;
        $hasMV   = $isInvest && isset($acctMarketValue[(int)$a['id']]);
        // % uses signed contribution: liabilities reduce net worth so show negative
        $pct = $pctBase !== null ? round(($isCredit ? -abs($bal) : $bal) / $pctBase * 100, 1) : null;
    ?>
      <tr class="ab-row-main">
        <td>
          <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $a['id'] ?>" class="ab-acct-link">
            <?= h($a['name']) ?>
          </a>
          <?php if ($a['is_favorite']): ?>
          <i class="bi bi-star-fill text-warning ms-1" style="font-size:.7rem" title="Favorite"></i>
          <?php endif; ?>
          <?php if (!$hasMV && $isInvest): ?>
          <span class="text-muted ms-1" style="font-size:.72rem" title="No price data — showing cost basis">(est.)</span>
          <?php endif; ?>
        </td>
        <td class="text-muted"><?= h($a['institution']) ?: '—' ?></td>
        <td class="text-end <?= $balCls ?>"><?= formatMoney(abs($bal)) ?></td>
        <?php if ($pctBase !== null): ?>
        <td class="text-end <?= $pct !== null && $pct < 0 ? 'gain-neg' : 'text-muted' ?>">
          <?= $pct !== null ? ($pct >= 0 ? '' : '−') . abs($pct) . '%' : '—' ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php if ($cashRow):
          $cashBal = (float)$cashRow['current_balance'];
          $cashPct = $pctBase !== null ? round($cashBal / $pctBase * 100, 1) : null;
      ?>
      <tr class="ab-row-cash">
        <td class="ab-cash-indent">
          <i class="bi bi-arrow-return-right text-muted me-1"></i>
          <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $cashRow['id'] ?>" class="ab-cash-link">
            <?= h($cashRow['name']) ?>
          </a>
          <span class="badge ab-cash-badge ms-1">Cash</span>
        </td>
        <td class="text-muted"><?= h($cashRow['institution']) ?: '—' ?></td>
        <td class="text-end <?= round($cashBal, MONEY_DECIMALS) < 0 ? 'gain-neg' : '' ?>"><?= formatMoney($cashBal) ?></td>
        <?php if ($pctBase !== null): ?>
        <td class="text-end <?= $cashPct !== null && $cashPct < 0 ? 'gain-neg' : 'text-muted' ?>">
          <?= $cashPct !== null ? ($cashPct >= 0 ? '' : '−') . abs($cashPct) . '%' : '—' ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2"><strong>Section Total</strong></td>
        <td class="text-end <?= $isCredit ? 'gain-neg' : '' ?>"><strong><?= formatMoney(abs($sectionTotal)) ?></strong></td>
        <?php if ($pctBase !== null):
            $secPct = round(($isCredit ? -abs($sectionTotal) : $sectionTotal) / $pctBase * 100, 1);
        ?>
        <td class="text-end <?= $secPct < 0 ? 'gain-neg' : 'text-muted' ?>">
          <strong><?= ($secPct >= 0 ? '' : '−') . abs($secPct) . '%' ?></strong>
        </td>
        <?php endif; ?>
      </tr>
    </tfoot>
  </table>
</div>
    <?php
}

foreach ($sections as $key => $sec) {
    renderSection($sec, $key, $cashByInvId, $acctMarketValue, $sectionTotals[$key], $pctBase);
}
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

// ── Date range ─────────────────────────────────────────────────
$range = $_GET['range'] ?? 'year';
switch ($range) {
    case 'month':
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-t');
        break;
    case 'last30':
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $endDate   = $today;
        break;
    case 'custom':
        $startDate = $_GET['start'] ?? date('Y-01-01');
        $endDate   = $_GET['end']   ?? date('Y-12-31');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-01-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-12-31');
        if ($endDate < $startDate) $endDate = $startDate;
        break;
    case 'year':
    default:
        $range     = 'year';
        $selYear   = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $startDate = $selYear . '-01-01';
        $endDate   = $selYear . '-12-31';
        break;
}

$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [(int)date('Y')];

// ── Account selector ───────────────────────────────────────────
$allAccounts = $db->query(
    "SELECT id, name, type FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 0
     ORDER BY type, name"
)->fetchAll();

$selAcctId = (int)($_GET['acct'] ?? 0);
$validIds  = array_column($allAccounts, 'id');
if ($selAcctId <= 0 || !in_array($selAcctId, $validIds)) {
    $selAcctId = (int)($allAccounts[0]['id'] ?? 0);
}
$selAcct = array_values(array_filter($allAccounts, fn($a) => (int)$a['id'] === $selAcctId))[0] ?? null;

// ── Revenue by top-level category ──────────────────────────────
$revStmt = $db->prepare(
    "SELECT COALESCE(cp.id, c.id) AS cat_id,
            COALESCE(cp.name, c.name) AS cat_name,
            ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories c ON c.id = ts.category_id
     LEFT JOIN categories cp ON cp.id = c.parent_id
     WHERE c.type = 'income'
       AND t.type != 'transfer'
       AND t.account_id = ?
       AND t.transaction_date BETWEEN ? AND ?
     GROUP BY COALESCE(cp.id, c.id), COALESCE(cp.name, c.name)
     ORDER BY total DESC"
);
$revStmt->execute([$selAcctId, $startDate, $endDate]);
$revCats     = $revStmt->fetchAll();
$totalRev    = array_sum(array_column($revCats, 'total'));

// ── Expenses by top-level category ─────────────────────────────
$expStmt = $db->prepare(
    "SELECT COALESCE(cp.id, c.id) AS cat_id,
            COALESCE(cp.name, c.name) AS cat_name,
            ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories c ON c.id = ts.category_id
     LEFT JOIN categories cp ON cp.id = c.parent_id
     WHERE c.type = 'expense'
       AND t.type != 'transfer'
       AND t.account_id = ?
       AND t.transaction_date BETWEEN ? AND ?
     GROUP BY COALESCE(cp.id, c.id), COALESCE(cp.name, c.name)
     ORDER BY total DESC"
);
$expStmt->execute([$selAcctId, $startDate, $endDate]);
$expCats     = $expStmt->fetchAll();
$totalExp    = array_sum(array_column($expCats, 'total'));

// ── Transfers In (by source account) ──────────────────────────
$tInStmt = $db->prepare(
    "SELECT COALESCE(a2.name, 'Unknown Account') AS other_account,
            SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN transactions t2 ON t2.id = t.transfer_pair_id
     LEFT JOIN accounts a2 ON a2.id = t2.account_id
     WHERE t.account_id = ?
       AND t.type = 'transfer'
       AND t.amount > 0
       AND t.transaction_date BETWEEN ? AND ?
     GROUP BY t2.account_id, a2.name
     ORDER BY total DESC"
);
$tInStmt->execute([$selAcctId, $startDate, $endDate]);
$transfersIn  = $tInStmt->fetchAll();
$totalTIn     = array_sum(array_column($transfersIn, 'total'));

// ── Transfers Out (by destination account) ────────────────────
$tOutStmt = $db->prepare(
    "SELECT COALESCE(a2.name, 'Unknown Account') AS other_account,
            ABS(SUM(t.amount)) AS total
     FROM transactions t
     LEFT JOIN transactions t2 ON t2.id = t.transfer_pair_id
     LEFT JOIN accounts a2 ON a2.id = t2.account_id
     WHERE t.account_id = ?
       AND t.type = 'transfer'
       AND t.amount < 0
       AND t.transaction_date BETWEEN ? AND ?
     GROUP BY t2.account_id, a2.name
     ORDER BY total DESC"
);
$tOutStmt->execute([$selAcctId, $startDate, $endDate]);
$transfersOut = $tOutStmt->fetchAll();
$totalTOut    = array_sum(array_column($transfersOut, 'total'));

$netChange = $totalRev - $totalExp + $totalTIn - $totalTOut;

// ── Monthly data for chart ─────────────────────────────────────
function monthlyMap(PDO $db, string $sql, array $params): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT)] = (float)$r['total'];
    }
    return $map;
}

$revMonthly = monthlyMap($db,
    "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
            ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories c ON c.id = ts.category_id
     WHERE c.type = 'income' AND t.type != 'transfer'
       AND t.account_id = ? AND t.transaction_date BETWEEN ? AND ?
     GROUP BY yr, mo",
    [$selAcctId, $startDate, $endDate]
);
$expMonthly = monthlyMap($db,
    "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
            ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories c ON c.id = ts.category_id
     WHERE c.type = 'expense' AND t.type != 'transfer'
       AND t.account_id = ? AND t.transaction_date BETWEEN ? AND ?
     GROUP BY yr, mo",
    [$selAcctId, $startDate, $endDate]
);
$tInMonthly = monthlyMap($db,
    "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
            SUM(t.amount) AS total
     FROM transactions t
     WHERE t.type = 'transfer' AND t.amount > 0
       AND t.account_id = ? AND t.transaction_date BETWEEN ? AND ?
     GROUP BY yr, mo",
    [$selAcctId, $startDate, $endDate]
);
$tOutMonthly = monthlyMap($db,
    "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
            ABS(SUM(t.amount)) AS total
     FROM transactions t
     WHERE t.type = 'transfer' AND t.amount < 0
       AND t.account_id = ? AND t.transaction_date BETWEEN ? AND ?
     GROUP BY yr, mo",
    [$selAcctId, $startDate, $endDate]
);

$chartLabels = $chartRev = $chartExp = $chartTIn = $chartTOut = $chartNet = [];
$cursor = new DateTime(date('Y-m-01', strtotime($startDate)));
$endDt  = new DateTime(date('Y-m-01', strtotime($endDate)));
while ($cursor <= $endDt) {
    $ym = $cursor->format('Y-m');
    $r  = $revMonthly[$ym]  ?? 0;
    $e  = $expMonthly[$ym]  ?? 0;
    $ti = $tInMonthly[$ym]  ?? 0;
    $to = $tOutMonthly[$ym] ?? 0;
    $chartLabels[] = $cursor->format('M Y');
    $chartRev[]    = round($r, 2);
    $chartExp[]    = round($e, 2);
    $chartTIn[]    = round($ti, 2);
    $chartTOut[]   = round($to, 2);
    $chartNet[]    = round($r - $e + $ti - $to, 2);
    $cursor->modify('+1 month');
}

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($revCats as $r)
        $csvRows[] = ['Revenue', $r['cat_name'], number_format((float)$r['total'], 2, '.', '')];
    $csvRows[] = ['Revenue Total', '', number_format($totalRev, 2, '.', '')];
    foreach ($expCats as $r)
        $csvRows[] = ['Expenses', $r['cat_name'], number_format((float)$r['total'], 2, '.', '')];
    $csvRows[] = ['Expenses Total', '', number_format($totalExp, 2, '.', '')];
    foreach ($transfersIn as $r)
        $csvRows[] = ['Transfers In', $r['other_account'], number_format((float)$r['total'], 2, '.', '')];
    $csvRows[] = ['Transfers In Total', '', number_format($totalTIn, 2, '.', '')];
    foreach ($transfersOut as $r)
        $csvRows[] = ['Transfers Out', $r['other_account'], number_format((float)$r['total'], 2, '.', '')];
    $csvRows[] = ['Transfers Out Total', '', number_format($totalTOut, 2, '.', '')];
    $csvRows[] = ['Net Change', '', number_format($netChange, 2, '.', '')];
    $slug = preg_replace('/[^a-z0-9]+/i', '_', $selAcct['name'] ?? 'account');
    outputCsv("account_flow_{$slug}_{$startDate}_{$endDate}.csv",
              ['Section', 'Name', 'Amount'], $csvRows);
}

$pageTitle   = 'Account Flow';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Account Flow'; $reportFavIcon = 'bi-arrow-down-up'; ?>
<div class="page-header">
  <h2><i class="bi bi-arrow-down-up"></i> Account Flow</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="afForm">
  <!-- Account selector -->
  <div class="filter-group">
    <label for="acctSel">Account</label>
    <select name="acct" id="acctSel" class="form-select form-select-sm">
      <?php
      $lastType = '';
      foreach ($allAccounts as $a):
          if ($a['type'] !== $lastType): $lastType = $a['type']; ?>
          <optgroup label="<?= h($a['type']) ?>">
          <?php endif; ?>
          <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id'] === $selAcctId ? 'selected' : '' ?>>
            <?= h($a['name']) ?>
          </option>
      <?php endforeach; ?>
    </select>
  </div>

  <input type="hidden" name="range" id="rangeHidden" value="<?= h($range) ?>">
  <div class="filter-group" id="customDates" style="<?= $range === 'custom' ? '' : 'display:none' ?>">
    <label>From</label>
    <input type="date" name="start" class="form-control form-control-sm"
           value="<?= h($range === 'custom' ? $startDate : '') ?>">
  </div>
  <div class="filter-group" id="customDatesEnd" style="<?= $range === 'custom' ? '' : 'display:none' ?>">
    <label>To</label>
    <input type="date" name="end" class="form-control form-control-sm"
           value="<?= h($range === 'custom' ? $endDate : '') ?>">
  </div>
  <div class="filter-group" id="yearGroup" style="<?= $range === 'year' ? '' : 'display:none' ?>">
    <label>Year</label>
    <select name="year" id="yearSel" class="form-select form-select-sm">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= isset($selYear) && $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <div class="quick-ranges">
      <?php $acctQs = '&acct=' . $selAcctId; ?>
      <a href="?range=month<?= $acctQs ?>"
         class="btn btn-sm btn-outline-secondary<?= $range === 'month' ? ' active' : '' ?>">This Month</a>
      <?php foreach ($years as $y): ?>
      <a href="?range=year&year=<?= $y ?><?= $acctQs ?>"
         class="btn btn-sm btn-outline-secondary<?= ($range === 'year' && isset($selYear) && $selYear == $y) ? ' active' : '' ?>"><?= $y ?></a>
      <?php endforeach; ?>
      <a href="?range=last30<?= $acctQs ?>"
         class="btn btn-sm btn-outline-secondary<?= $range === 'last30' ? ' active' : '' ?>">Last 30 Days</a>
      <a href="#" onclick="setCustomRange(); return false;"
         class="btn btn-sm btn-outline-secondary<?= $range === 'custom' ? ' active' : '' ?>">Custom…</a>
    </div>
  </div>
</form>

<!-- Summary tiles -->
<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Revenue</div>
    <div class="tile-value"><?= formatMoney($totalRev) ?></div>
  </div>
  <div class="report-tile tile-expense">
    <div class="tile-label">Expenses</div>
    <div class="tile-value"><?= formatMoney($totalExp) ?></div>
  </div>
  <div class="report-tile" style="border-top:3px solid #0d6efd">
    <div class="tile-label">Transfers In</div>
    <div class="tile-value" style="color:#0d6efd"><?= formatMoney($totalTIn) ?></div>
  </div>
  <div class="report-tile" style="border-top:3px solid #fd7e14">
    <div class="tile-label">Transfers Out</div>
    <div class="tile-value" style="color:#fd7e14"><?= formatMoney($totalTOut) ?></div>
  </div>
  <div class="report-tile <?= $netChange >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net Change</div>
    <div class="tile-value"><?= formatMoney($netChange, true) ?></div>
  </div>
</div>

<?php if (count($chartLabels) > 1): ?>
<div class="report-chart-wrap">
  <canvas id="afChart" height="100"></canvas>
</div>
<?php endif; ?>

<!-- Revenue section -->
<div class="af-section mb-4">
  <h5 class="af-section-title af-income">
    <i class="bi bi-arrow-down-circle-fill"></i> Revenue
    <span class="af-section-total"><?= formatMoney($totalRev) ?></span>
  </h5>
  <?php if (empty($revCats)): ?>
  <p class="text-muted small ms-1">No revenue recorded for this period.</p>
  <?php else: ?>
  <table class="table table-sm report-table">
    <thead>
      <tr>
        <th>Category</th>
        <th class="text-end">Amount</th>
        <th class="text-end" style="width:6rem">% of Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($revCats as $row):
        $pct = $totalRev > 0 ? round($row['total'] / $totalRev * 100, 1) : 0;
      ?>
      <tr>
        <td><?= h($row['cat_name']) ?></td>
        <td class="text-end amount-credit"><?= formatMoney((float)$row['total']) ?></td>
        <td class="text-end text-muted"><?= $pct ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total Revenue</td>
        <td class="text-end amount-credit"><?= formatMoney($totalRev) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- Expenses section -->
<div class="af-section mb-4">
  <h5 class="af-section-title af-expense">
    <i class="bi bi-arrow-up-circle-fill"></i> Expenses
    <span class="af-section-total"><?= formatMoney($totalExp) ?></span>
  </h5>
  <?php if (empty($expCats)): ?>
  <p class="text-muted small ms-1">No expenses recorded for this period.</p>
  <?php else: ?>
  <table class="table table-sm report-table">
    <thead>
      <tr>
        <th>Category</th>
        <th class="text-end">Amount</th>
        <th class="text-end" style="width:6rem">% of Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($expCats as $row):
        $pct = $totalExp > 0 ? round($row['total'] / $totalExp * 100, 1) : 0;
      ?>
      <tr>
        <td><?= h($row['cat_name']) ?></td>
        <td class="text-end amount-debit"><?= formatMoney((float)$row['total']) ?></td>
        <td class="text-end text-muted"><?= $pct ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total Expenses</td>
        <td class="text-end amount-debit"><?= formatMoney($totalExp) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- Transfers In section -->
<div class="af-section mb-4">
  <h5 class="af-section-title af-tin">
    <i class="bi bi-box-arrow-in-down"></i> Transfers In
    <span class="af-section-total"><?= formatMoney($totalTIn) ?></span>
  </h5>
  <?php if (empty($transfersIn)): ?>
  <p class="text-muted small ms-1">No transfers in for this period.</p>
  <?php else: ?>
  <table class="table table-sm report-table">
    <thead>
      <tr>
        <th>From Account</th>
        <th class="text-end">Amount</th>
        <th class="text-end" style="width:6rem">% of Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($transfersIn as $row):
        $pct = $totalTIn > 0 ? round($row['total'] / $totalTIn * 100, 1) : 0;
      ?>
      <tr>
        <td><?= h($row['other_account']) ?></td>
        <td class="text-end" style="color:#0d6efd"><?= formatMoney((float)$row['total']) ?></td>
        <td class="text-end text-muted"><?= $pct ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total Transfers In</td>
        <td class="text-end" style="color:#0d6efd"><?= formatMoney($totalTIn) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- Transfers Out section -->
<div class="af-section mb-4">
  <h5 class="af-section-title af-tout">
    <i class="bi bi-box-arrow-up"></i> Transfers Out
    <span class="af-section-total"><?= formatMoney($totalTOut) ?></span>
  </h5>
  <?php if (empty($transfersOut)): ?>
  <p class="text-muted small ms-1">No transfers out for this period.</p>
  <?php else: ?>
  <table class="table table-sm report-table">
    <thead>
      <tr>
        <th>To Account</th>
        <th class="text-end">Amount</th>
        <th class="text-end" style="width:6rem">% of Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($transfersOut as $row):
        $pct = $totalTOut > 0 ? round($row['total'] / $totalTOut * 100, 1) : 0;
      ?>
      <tr>
        <td><?= h($row['other_account']) ?></td>
        <td class="text-end" style="color:#fd7e14"><?= formatMoney((float)$row['total']) ?></td>
        <td class="text-end text-muted"><?= $pct ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total Transfers Out</td>
        <td class="text-end" style="color:#fd7e14"><?= formatMoney($totalTOut) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<style>
.af-section-title {
  display: flex;
  align-items: center;
  gap: .5rem;
  font-size: 1rem;
  font-weight: 600;
  padding: .4rem .6rem;
  border-radius: 6px;
  margin-bottom: .5rem;
}
.af-section-total {
  margin-left: auto;
  font-weight: 700;
  font-size: 1rem;
}
.af-income { background: rgba(25,135,84,.08); color:#157347; }
.af-expense { background: rgba(220,53,69,.08); color:#c82333; }
.af-tin  { background: rgba(13,110,253,.08); color:#0a58ca; }
.af-tout { background: rgba(253,126,20,.10); color:#ca6510; }
</style>

<script>
function setCustomRange() {
  document.getElementById('rangeHidden').value = 'custom';
  document.getElementById('customDates').style.display    = '';
  document.getElementById('customDatesEnd').style.display = '';
  document.getElementById('yearGroup').style.display      = 'none';
  const s = document.querySelector('[name="start"]');
  const e = document.querySelector('[name="end"]');
  if (!s.value) s.value = '<?= date('Y-01-01') ?>';
  if (!e.value) e.value = '<?= date('Y-12-31') ?>';
  s.focus();
}
</script>

<?php if (count($chartLabels) > 1): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels = <?= json_encode($chartLabels) ?>;
  const rev    = <?= json_encode($chartRev) ?>;
  const exp    = <?= json_encode($chartExp) ?>;
  const tIn    = <?= json_encode($chartTIn) ?>;
  const tOut   = <?= json_encode($chartTOut) ?>;
  const net    = <?= json_encode($chartNet) ?>;

  new Chart(document.getElementById('afChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Revenue',       data: rev,  stack: 'in',  backgroundColor: 'rgba(25,135,84,.70)',  borderColor: 'rgba(25,135,84,1)',  borderWidth: 1 },
        { label: 'Transfers In',  data: tIn,  stack: 'in',  backgroundColor: 'rgba(13,110,253,.60)', borderColor: 'rgba(13,110,253,1)', borderWidth: 1 },
        { label: 'Expenses',      data: exp,  stack: 'out', backgroundColor: 'rgba(220,53,69,.70)',  borderColor: 'rgba(220,53,69,1)',  borderWidth: 1 },
        { label: 'Transfers Out', data: tOut, stack: 'out', backgroundColor: 'rgba(253,126,20,.70)', borderColor: 'rgba(253,126,20,1)', borderWidth: 1 },
        { label: 'Net',           data: net,  type: 'line', borderColor: '#6f42c1', backgroundColor: 'transparent',
          borderWidth: 2, pointRadius: 4, tension: 0.3, stack: undefined }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { ticks: { callback: v => '$' + v.toLocaleString() } }
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

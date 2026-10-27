<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// Portfolio Withdrawals: money moved from investment accounts into spending accounts
// (see getPortfolioFlows()), by month, split into retirement and non-retirement.

$db    = getDB();
$today = date('Y-m-d');

$defaultStart = date('Y-01-01');
$defaultEnd   = $today;

$startDate = $_GET['start'] ?? $defaultStart;
$endDate   = $_GET['end']   ?? $defaultEnd;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = $defaultEnd;
if ($endDate < $startDate) $endDate = $startDate;

// ── Account filter (investment accounts, grouped; education accounts never count) ──
$allAccounts = $db->query(
    "SELECT id, name, type, is_retirement
     FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 0 AND is_education = 0
       AND type IN ('Investment','Crypto')
     ORDER BY is_retirement, name"
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

if (!$filteringAccts) {
    $acctBtnLabel = 'All Accounts';
} elseif (count($selectedAcctIds) === 1) {
    $m = current(array_filter($allAccounts, fn($a) => (int)$a['id'] === $selectedAcctIds[0]));
    $acctBtnLabel = $m ? $m['name'] : '1 Account';
} else {
    $acctBtnLabel = count($selectedAcctIds) . ' Accounts';
}

// ── Flows ──────────────────────────────────────────────────────
$selectedSet   = array_flip($selectedAcctIds);
$allFlows      = getPortfolioFlows($startDate, $endDate, ['include_excluded' => true]);
$withdrawals   = [];
$contributions = 0.0;
$excludedCount = 0;
foreach ($allFlows as $f) {
    if (!isset($selectedSet[$f['portfolio_account_id']])) continue;
    if ($f['excluded']) { $excludedCount++; continue; }
    if ($f['amount'] > 0) $withdrawals[] = $f;
    else                  $contributions += -$f['amount'];
}

// Every month in the range
$allMonths = [];
$cursor = new DateTime(date('Y-m-01', strtotime($startDate)));
$endDt  = new DateTime(date('Y-m-01', strtotime($endDate)));
while ($cursor <= $endDt) {
    $allMonths[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
}
$numMonths = max(1, count($allMonths));

$monthly   = array_fill_keys($allMonths, ['ret' => 0.0, 'nonret' => 0.0]);
$byAccount = [];
$totRet = 0.0; $totNonRet = 0.0;
foreach ($withdrawals as $f) {
    $ym  = substr($f['date'], 0, 7);
    $key = $f['is_retirement'] ? 'ret' : 'nonret';
    $monthly[$ym][$key] += $f['amount'];
    if ($f['is_retirement']) $totRet += $f['amount']; else $totNonRet += $f['amount'];
    $aid = $f['portfolio_account_id'];
    $byAccount[$aid] ??= ['name' => $f['portfolio_account_name'], 'retirement' => $f['is_retirement'], 'total' => 0.0, 'count' => 0];
    $byAccount[$aid]['total'] += $f['amount'];
    $byAccount[$aid]['count']++;
}
uasort($byAccount, fn($a, $b) => $b['total'] <=> $a['total']);
$grandTotal = $totRet + $totNonRet;

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($monthly as $ym => $m) {
        $csvRows[] = [date('M Y', strtotime($ym . '-01')),
                      number_format($m['nonret'], 2, '.', ''),
                      number_format($m['ret'], 2, '.', ''),
                      number_format($m['nonret'] + $m['ret'], 2, '.', '')];
    }
    $csvRows[] = ['Total', number_format($totNonRet, 2, '.', ''), number_format($totRet, 2, '.', ''),
                  number_format($grandTotal, 2, '.', '')];
    outputCsv('portfolio_withdrawals_' . $startDate . '_' . $endDate . '.csv',
              ['Month', 'Non-Retirement', 'Retirement', 'Total'], $csvRows);
}

$pageTitle   = 'Portfolio Withdrawals';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Portfolio Withdrawals'; $reportFavIcon = 'bi-box-arrow-right'; ?>
<div class="page-header">
  <h2><i class="bi bi-box-arrow-right"></i> Portfolio Withdrawals</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="pwForm">
  <div class="filter-group">
    <label for="startDate">From</label>
    <input type="date" name="start" id="startDate" class="form-control form-control-sm"
           value="<?= h($startDate) ?>">
  </div>
  <div class="filter-group">
    <label for="endDate">To</label>
    <input type="date" name="end" id="endDate" class="form-control form-control-sm"
           value="<?= h($endDate) ?>">
  </div>
  <div class="filter-group">
    <label>Accounts</label>
    <input type="hidden" name="accts" id="pwAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="pwAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:320px;overflow-y:auto;min-width:220px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="pwAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
            <strong>All Accounts</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php
        $prevGroup = '';
        foreach ($allAccounts as $a):
            $group = !empty($a['is_retirement']) ? 'Retirement' : 'Non-Retirement';
            if ($group !== $prevGroup):
                $prevGroup = $group;
        ?>
        <li class="px-3 pt-1 pb-0">
          <label class="d-flex gap-2 align-items-center" style="cursor:pointer;margin:0;padding:2px 0">
            <input type="checkbox" class="pw-type-chk" data-type="<?= h($group) ?>">
            <span class="text-uppercase fw-semibold" style="font-size:.68rem;letter-spacing:.04em;color:#555"><?= h($group) ?></span>
          </label>
        </li>
        <?php endif; ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="pw-acct-chk" value="<?= (int)$a['id'] ?>"
                   data-name="<?= h($a['name']) ?>"
                   data-type="<?= h($group) ?>"
                   <?= in_array((int)$a['id'], $selectedAcctIds, true) ? 'checked' : '' ?>>
            <?= h($a['name']) ?>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <div class="quick-ranges">
      <?php
      $ranges = dateRangeQuickRanges([
        'This Year'  => 'ytd',
        'Last Year'  => 'last_year',
        'Last 12 Mo' => 'cm12',
        'Last 5 Yr'  => 'cy5',
      ]);
      foreach ($ranges as $rangeLabel => [$tok, $s, $e]):
      ?>
      <a href="?dr=<?= $tok ?>&accts=<?= h($acctParam) ?>"
         class="btn btn-sm btn-outline-secondary<?= ($startDate === $s && $endDate === $e) ? ' active' : '' ?>">
        <?= $rangeLabel ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<script>
(function () {
  const allChk  = document.getElementById('pwAcctAll');
  const chkList = Array.from(document.querySelectorAll('.pw-acct-chk'));
  const typChks = Array.from(document.querySelectorAll('.pw-type-chk'));
  const hidden  = document.getElementById('pwAcctHidden');
  const lbl     = document.getElementById('pwAcctLabel');

  function updateTypeState(type) {
    const ofType = chkList.filter(c => c.dataset.type === type);
    const n = ofType.filter(c => c.checked).length;
    const tc = typChks.find(t => t.dataset.type === type);
    if (!tc) return;
    tc.indeterminate = n > 0 && n < ofType.length;
    tc.checked = n === ofType.length;
  }

  function updateAcct() {
    const checked = chkList.filter(c => c.checked);
    const isAll   = checked.length === 0 || checked.length === chkList.length;
    hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
    lbl.textContent = isAll       ? 'All Accounts'
      : checked.length === 1      ? checked[0].dataset.name
      : checked.length + ' Accounts';
    allChk.indeterminate = !isAll && checked.length > 0;
    if (isAll) allChk.checked = checked.length === chkList.length;
    typChks.forEach(tc => updateTypeState(tc.dataset.type));
  }

  allChk.addEventListener('change', function () {
    chkList.forEach(c => c.checked = this.checked);
    typChks.forEach(tc => { tc.checked = this.checked; tc.indeterminate = false; });
    this.indeterminate = false;
    updateAcct();
  });
  typChks.forEach(tc => {
    tc.addEventListener('change', function () {
      chkList.filter(c => c.dataset.type === this.dataset.type).forEach(c => c.checked = this.checked);
      this.indeterminate = false;
      updateAcct();
    });
  });
  chkList.forEach(c => c.addEventListener('change', updateAcct));
  typChks.forEach(tc => updateTypeState(tc.dataset.type));
})();
</script>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Total Withdrawals</div>
    <div class="tile-value"><?= formatMoney($grandTotal) ?></div>
    <div class="tile-sub">avg <?= formatMoney($grandTotal / $numMonths) ?>/mo</div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Non-Retirement</div>
    <div class="tile-value"><?= formatMoney($totNonRet) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Retirement</div>
    <div class="tile-value"><?= formatMoney($totRet) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Contributions</div>
    <div class="tile-value"><?= formatMoney($contributions) ?></div>
    <div class="tile-sub">into these accounts</div>
  </div>
</div>

<?php if ($excludedCount): ?>
<p class="text-muted small">
  <i class="bi bi-slash-circle"></i> <?= $excludedCount ?> transfer<?= $excludedCount !== 1 ? 's' : '' ?>
  in this period <?= $excludedCount !== 1 ? 'are' : 'is' ?> marked “Don't Count as Portfolio Withdrawal” and left out.
</p>
<?php endif; ?>

<?php if (empty($withdrawals)): ?>
<p class="text-muted mt-3">No withdrawals from the selected investment accounts in this period.</p>
<?php else: ?>

<div class="report-chart-wrap">
  <canvas id="pwChart" height="90"></canvas>
</div>

<h3 class="report-section-title">By Month</h3>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Month</th>
      <th class="text-end">Non-Retirement</th>
      <th class="text-end">Retirement</th>
      <th class="text-end">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($monthly as $ym => $m):
      $label = date('M Y', strtotime($ym . '-01'));
      $tot   = $m['nonret'] + $m['ret'];
      $cell  = function (float $v, string $group) use ($ym, $label) {
          if ($v <= 0) return '—';
          return '<a href="#" class="pw-drill" data-ym="' . h($ym) . '" data-group="' . $group . '" data-label="' . h($label) . '">'
               . formatMoney($v) . '</a>';
      };
    ?>
    <tr>
      <td><?= h($label) ?></td>
      <td class="text-end"><?= $cell($m['nonret'], 'nonret') ?></td>
      <td class="text-end"><?= $cell($m['ret'], 'ret') ?></td>
      <td class="text-end fw-semibold"><?= $cell($tot, 'all') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td>Total</td>
      <td class="text-end"><?= formatMoney($totNonRet) ?></td>
      <td class="text-end"><?= formatMoney($totRet) ?></td>
      <td class="text-end"><a href="#" class="pw-drill" data-ym="" data-group="all" data-label="All months"><?= formatMoney($grandTotal) ?></a></td>
    </tr>
  </tfoot>
</table>

<h3 class="report-section-title">By Account</h3>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Account</th>
      <th></th>
      <th class="text-end">Transfers</th>
      <th class="text-end">Total</th>
      <th class="text-end">% of Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($byAccount as $aid => $a): ?>
    <tr>
      <td><a href="<?= BASE_PATH ?>/accounts/register?id=<?= (int)$aid ?>"><?= h($a['name']) ?></a></td>
      <td class="text-muted small"><?= $a['retirement'] ? 'Retirement' : 'Non-Retirement' ?></td>
      <td class="text-end"><?= $a['count'] ?></td>
      <td class="text-end"><a href="#" class="pw-drill" data-ym="" data-group="acct:<?= (int)$aid ?>" data-label="<?= h($a['name']) ?>"><?= formatMoney($a['total']) ?></a></td>
      <td class="text-end"><?= $grandTotal > 0 ? number_format($a['total'] / $grandTotal * 100, 1) : '0.0' ?>%</td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Drill-down: the individual transfers behind an amount -->
<div class="modal fade" id="pwDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-receipt"></i> <span id="pwDetailTitle">Withdrawals</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Date</th><th>From</th><th>To</th><th>Payee / Memo</th><th class="text-end">Amount</th></tr></thead>
          <tbody id="pwDetailBody"></tbody>
        </table>
      </div>
      <div class="modal-footer py-2 d-flex justify-content-between">
        <span class="text-muted small" id="pwDetailCount"></span>
        <span class="fw-bold" id="pwDetailTotal"></span>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const months = <?= json_encode(array_map(fn($ym) => date('M Y', strtotime($ym . '-01')), array_keys($monthly))) ?>;
  const nonret = <?= json_encode(array_map(fn($m) => round($m['nonret'], 2), array_values($monthly))) ?>;
  const ret    = <?= json_encode(array_map(fn($m) => round($m['ret'], 2), array_values($monthly))) ?>;
  new Chart(document.getElementById('pwChart'), {
    type: 'bar',
    data: {
      labels: months,
      datasets: [
        { label: 'Non-Retirement', data: nonret, backgroundColor: 'rgba(13,110,253,0.65)', borderColor: 'rgba(13,110,253,1)', borderWidth: 1 },
        { label: 'Retirement',     data: ret,    backgroundColor: 'rgba(25,135,84,0.65)',  borderColor: 'rgba(25,135,84,1)',  borderWidth: 1 },
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' } },
      scales: { x: { stacked: true }, y: { stacked: true, ticks: { callback: v => '$' + v.toLocaleString() } } }
    }
  });

  const flows = <?= json_encode(array_map(fn($f) => [
      'id' => $f['id'], 'date' => $f['date'], 'amount' => $f['amount'], 'payee' => $f['payee'],
      'memo' => $f['memo'], 'account_id' => $f['account_id'], 'account' => $f['account_name'],
      'from_id' => $f['portfolio_account_id'], 'from' => $f['portfolio_account_name'], 'ret' => $f['is_retirement'],
  ], $withdrawals), JSON_HEX_TAG) ?>;
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const fmt = n => '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtDate = iso => { const [y, m, d] = iso.split('-'); return m + '/' + d + '/' + y; };

  document.addEventListener('click', function (e) {
    const a = e.target.closest('.pw-drill');
    if (!a) return;
    e.preventDefault();
    const ym = a.dataset.ym, group = a.dataset.group;
    const rows = flows.filter(f =>
      (!ym || f.date.startsWith(ym)) &&
      (group === 'all' || (group === 'ret' && f.ret) || (group === 'nonret' && !f.ret) ||
       (group.startsWith('acct:') && f.from_id === parseInt(group.slice(5))))
    );
    document.getElementById('pwDetailTitle').textContent = 'Withdrawals — ' + a.dataset.label;
    document.getElementById('pwDetailBody').innerHTML = rows.map(f =>
      `<tr style="cursor:pointer" onclick="window.location='<?= BASE_PATH ?>/accounts/register?id=${f.account_id}#txn-${f.id}'" title="Open in register">
         <td class="text-nowrap">${fmtDate(f.date)}</td><td>${esc(f.from)}</td><td>${esc(f.account)}</td>
         <td>${esc(f.payee)}${f.memo ? '<div class="text-muted small">' + esc(f.memo) + '</div>' : ''}</td>
         <td class="text-end amount-credit">${fmt(f.amount)}</td></tr>`).join('');
    document.getElementById('pwDetailCount').textContent = rows.length + ' transfer' + (rows.length !== 1 ? 's' : '');
    document.getElementById('pwDetailTotal').textContent = 'Total: ' + fmt(rows.reduce((s, f) => s + f.amount, 0));
    bootstrap.Modal.getOrCreateInstance(document.getElementById('pwDetailModal')).show();
  });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

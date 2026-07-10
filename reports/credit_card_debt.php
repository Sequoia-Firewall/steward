<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

// ── All credit card accounts ───────────────────────────────────
$allCards = $db->query(
    "SELECT id, name, institution, opening_balance
     FROM accounts
     WHERE type = 'Credit Card' AND is_active = 1 AND is_closed = 0
     ORDER BY name"
)->fetchAll();

$allCardIds = array_map('intval', array_column($allCards, 'id'));

// ── Account filter ─────────────────────────────────────────────
$acctParam = trim($_GET['accts'] ?? '');

if ($acctParam === '' || $acctParam === 'all') {
    $selectedIds = $allCardIds;
    $filteringAccts = false;
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allCardIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allCardIds)) {
        $selectedIds    = $allCardIds;
        $filteringAccts = false;
    } else {
        $selectedIds    = $parsed;
        $filteringAccts = true;
    }
}

$selectedCards = array_values(array_filter(
    $allCards, fn($a) => in_array((int)$a['id'], $selectedIds, true)
));

if (!$filteringAccts) {
    $acctBtnLabel = 'All Cards';
} elseif (count($selectedIds) === 1) {
    $m = reset(array_filter($allCards, fn($a) => (int)$a['id'] === $selectedIds[0]));
    $acctBtnLabel = $m ? $m['name'] : '1 Card';
} else {
    $acctBtnLabel = count($selectedIds) . ' Cards';
}

// ── History period ─────────────────────────────────────────────
$months = isset($_GET['months']) ? max(3, min(60, (int)$_GET['months'])) : 12;

// Build month-end points going back $months months
$periodEnd   = new DateTime(date('Y-m-t'));
$periodStart = (clone $periodEnd)->modify('-' . ($months - 1) . ' months')->modify('first day of this month');
$monthPoints = [];
$cur = clone $periodStart;
while ($cur <= $periodEnd) {
    $monthPoints[] = min($cur->format('Y-m-t'), $today);
    $cur->modify('+1 month');
}

// ── Fetch cumulative transaction totals ────────────────────────
// Result: per-account running balance at each month-end
$periodBalances = []; // eom -> [acct_id -> raw_balance]

if (!empty($selectedCards)) {
    $ph   = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = $db->prepare(
        "SELECT account_id,
                DATE_FORMAT(transaction_date,'%Y-%m') AS ym,
                SUM(amount) AS month_total
         FROM transactions
         WHERE account_id IN ($ph) AND transaction_date <= ?
         GROUP BY account_id, ym
         ORDER BY account_id, ym"
    );
    $stmt->execute([...$selectedIds, end($monthPoints)]);

    // Build running-sum maps
    $cumulative = [];
    foreach ($stmt->fetchAll() as $r) {
        $aid = (int)$r['account_id'];
        $cumulative[$aid][$r['ym']] = ($cumulative[$aid][$r['ym']] ?? 0.0) + (float)$r['month_total'];
    }
    $accountRunning = [];
    foreach ($selectedCards as $card) {
        $aid     = (int)$card['id'];
        $running = 0.0;
        $runMap  = [];
        $sorted  = $cumulative[$aid] ?? [];
        ksort($sorted);
        foreach ($sorted as $ym => $tot) { $running += $tot; $runMap[$ym] = $running; }
        $accountRunning[$aid] = ['opening' => (float)$card['opening_balance'], 'map' => $runMap];
    }

    foreach ($monthPoints as $eom) {
        $ym    = substr($eom, 0, 7);
        foreach ($selectedCards as $card) {
            $aid    = (int)$card['id'];
            $map    = $accountRunning[$aid]['map'];
            $txnTot = 0.0;
            foreach ($map as $tym => $tot) { if ($tym > $ym) break; $txnTot = $tot; }
            $periodBalances[$eom][$aid] = $accountRunning[$aid]['opening'] + $txnTot;
        }
    }
}

// Current balances = last period
$currentEom      = end($monthPoints);
$currentBalances = $periodBalances[$currentEom] ?? [];

// ── Summary figures ────────────────────────────────────────────
// Debt = absolute value of negative balance; positive balance = credit
$totalDebt    = 0.0;
$highestDebt  = 0.0;
$highestName  = '';
foreach ($selectedCards as $card) {
    $aid  = (int)$card['id'];
    $bal  = $currentBalances[$aid] ?? 0.0;
    $owed = max(0.0, -$bal);       // positive = amount owed
    $totalDebt += $owed;
    if ($owed > $highestDebt) { $highestDebt = $owed; $highestName = $card['name']; }
}

// ── Monthly total owed per period ──────────────────────────────
$periodOwed = []; // eom -> total owed
foreach ($monthPoints as $eom) {
    $sum = 0.0;
    foreach ($selectedCards as $card) {
        $bal  = $periodBalances[$eom][(int)$card['id']] ?? 0.0;
        $sum += max(0.0, -$bal);
    }
    $periodOwed[$eom] = $sum;
}

// ── Period labels ───────────────────────────────────────────────
$monthLabels = [];
foreach ($monthPoints as $eom) {
    $monthLabels[$eom] = date('M Y', strtotime($eom));
}

// ── CSV export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $hdrs = ['Month'];
    foreach ($selectedCards as $c) $hdrs[] = $c['name'] . ' (Balance)';
    foreach ($selectedCards as $c) $hdrs[] = $c['name'] . ' (Owed)';
    $hdrs[] = 'Total Owed';
    $rows   = [];
    foreach (array_reverse($monthPoints) as $eom) {
        $row = [$monthLabels[$eom]];
        foreach ($selectedCards as $c) {
            $row[] = number_format($periodBalances[$eom][(int)$c['id']] ?? 0.0, 2, '.', '');
        }
        foreach ($selectedCards as $c) {
            $bal   = $periodBalances[$eom][(int)$c['id']] ?? 0.0;
            $row[] = number_format(max(0.0, -$bal), 2, '.', '');
        }
        $row[]  = number_format($periodOwed[$eom], 2, '.', '');
        $rows[] = $row;
    }
    outputCsv('credit_card_debt.csv', $hdrs, $rows);
}

// ── Chart data ─────────────────────────────────────────────────
$chartLabels = array_values($monthLabels);
$palette = [
    '#dc3545','#fd7e14','#6f42c1','#0d6efd','#20c997',
    '#e83e8c','#17a2b8','#ffc107','#28a745','#6c757d',
];

$chartDatasets = [];
foreach ($selectedCards as $i => $card) {
    $aid    = (int)$card['id'];
    $color  = $palette[$i % count($palette)];
    $pts    = [];
    foreach ($monthPoints as $eom) {
        $bal  = $periodBalances[$eom][$aid] ?? 0.0;
        $pts[] = round(max(0.0, -$bal), 2); // owed = positive
    }
    $chartDatasets[] = [
        'label'       => $card['name'],
        'data'        => $pts,
        'borderColor' => $color,
        'backgroundColor' => $color . '22',
        'borderWidth' => 2,
        'tension'     => 0.3,
        'pointRadius' => count($monthPoints) <= 24 ? 3 : 2,
        'fill'        => count($selectedCards) === 1,
    ];
}

// Total owed line (dashed) when multiple cards
if (count($selectedCards) > 1) {
    $totPts = [];
    foreach ($monthPoints as $eom) $totPts[] = round($periodOwed[$eom], 2);
    $chartDatasets[] = [
        'label'       => 'Total Owed',
        'data'        => $totPts,
        'borderColor' => '#212529',
        'backgroundColor' => 'transparent',
        'borderWidth' => 2.5,
        'borderDash'  => [6, 3],
        'tension'     => 0.3,
        'pointRadius' => count($monthPoints) <= 24 ? 3 : 2,
        'fill'        => false,
    ];
}

$pageTitle   = 'Credit Card Debt';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<style>
.ccd-table-wrap { overflow-x: auto; }
.ccd-table th, .ccd-table td { white-space: nowrap; }
.ccd-table th:first-child, .ccd-table td:first-child {
    position: sticky; left: 0; background: var(--bs-body-bg); z-index: 1;
}
.ccd-bar-wrap { display: flex; flex-direction: column; gap: 10px; margin-bottom: 1.5rem; }
.ccd-bar-row  { display: flex; align-items: center; gap: 10px; }
.ccd-bar-name { min-width: 160px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .9rem; text-align: right; }
.ccd-bar-track{ flex: 1; background: var(--bs-secondary-bg); border-radius: 4px; height: 22px; overflow: hidden; }
.ccd-bar-fill { height: 100%; border-radius: 4px; transition: width .4s ease; }
.ccd-bar-amt  { min-width: 100px; font-size: .88rem; font-weight: 500; }
.ccd-zero     { color: var(--bs-secondary); font-style: italic; }
</style>

<?php $reportFavTitle = 'Credit Card Debt'; $reportFavIcon = 'bi-credit-card-2-back'; ?>
<div class="page-header">
  <h2><i class="bi bi-credit-card-2-back"></i> Credit Card Debt</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<?php if (empty($allCards)): ?>
<p class="text-muted mt-3">No active credit card accounts found.</p>
<?php include __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>Cards</label>
    <input type="hidden" name="accts" id="ccdAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="ccdAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu p-2" style="min-width:210px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="ccdAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
            <strong>All Cards</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php foreach ($allCards as $card): ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="ccd-acct-chk" value="<?= (int)$card['id'] ?>"
                   data-name="<?= h($card['name']) ?>"
                   <?= in_array((int)$card['id'], $selectedIds, true) ? 'checked' : '' ?>>
            <span>
              <?= h($card['name']) ?>
              <?php if ($card['institution']): ?>
              <small class="text-muted d-block" style="font-size:.75em"><?= h($card['institution']) ?></small>
              <?php endif; ?>
            </span>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="filter-group">
    <label>History</label>
    <div class="quick-ranges">
      <?php foreach ([6=>'6 mo',12=>'1 yr',24=>'2 yr',36=>'3 yr',60=>'5 yr'] as $m=>$lbl): ?>
      <a href="?months=<?= $m ?><?= $filteringAccts ? '&accts='.urlencode($acctParam) : '' ?>"
         class="btn btn-sm btn-outline-secondary<?= $months===$m?' active':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
  </div>
  <input type="hidden" name="months" value="<?= $months ?>">
</form>

<?php if (empty($selectedCards)): ?>
<p class="text-muted mt-3">No cards selected.</p>
<?php else: ?>

<!-- Summary tiles -->
<div class="report-tiles">
  <div class="report-tile tile-negative">
    <div class="tile-label">Total Owed</div>
    <div class="tile-value"><?= formatMoney($totalDebt) ?></div>
  </div>
  <div class="report-tile">
    <div class="tile-label"><?= count($selectedCards) === 1 ? 'Card' : 'Cards' ?></div>
    <div class="tile-value"><?= count($selectedCards) ?></div>
  </div>
  <?php if (count($selectedCards) > 1 && $highestName): ?>
  <div class="report-tile tile-negative">
    <div class="tile-label">Highest Balance</div>
    <div class="tile-value"><?= formatMoney($highestDebt) ?></div>
    <div class="tile-sub"><?= h($highestName) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- Current balances – horizontal bar chart -->
<?php
$maxOwed = max(0.01, max(array_map(
    fn($card) => max(0.0, -(($currentBalances[(int)$card['id']] ?? 0.0))),
    $selectedCards
)));
?>
<div class="ccd-bar-wrap">
<?php foreach ($selectedCards as $i => $card):
    $aid  = (int)$card['id'];
    $bal  = $currentBalances[$aid] ?? 0.0;
    $owed = max(0.0, -$bal);
    $pct  = $maxOwed > 0 ? round($owed / $maxOwed * 100, 1) : 0;
    $color = $palette[$i % count($palette)];
?>
<div class="ccd-bar-row">
  <div class="ccd-bar-name text-muted" title="<?= h($card['name']) ?>"><?= h($card['name']) ?></div>
  <div class="ccd-bar-track">
    <div class="ccd-bar-fill" style="width:<?= $pct ?>%;background:<?= h($color) ?>"></div>
  </div>
  <div class="ccd-bar-amt <?= $owed == 0 ? 'ccd-zero' : 'amount-debit' ?>">
    <?= $owed > 0 ? formatMoney($owed) : 'no balance' ?>
    <?php if ($bal > 0): ?>
    <small class="text-muted fw-normal"> (credit)</small>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Trend chart -->
<?php if (count($monthPoints) > 1): ?>
<div class="report-chart-wrap mb-4">
  <canvas id="ccdChart" height="90"></canvas>
</div>
<?php endif; ?>

<!-- Monthly table -->
<div class="ccd-table-wrap">
<table class="table table-sm report-table ccd-table">
  <thead>
    <tr>
      <th>Month</th>
      <?php foreach ($selectedCards as $card): ?>
      <th class="text-end"><?= h($card['name']) ?></th>
      <?php endforeach; ?>
      <?php if (count($selectedCards) > 1): ?>
      <th class="text-end">Total Owed</th>
      <?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach (array_reverse($monthPoints) as $eom):
    $isCurrentMonth = ($eom === $currentEom);
  ?>
    <tr<?= $isCurrentMonth ? ' class="table-active fw-semibold"' : '' ?>>
      <td><?= h($monthLabels[$eom]) ?><?= $isCurrentMonth ? ' <span class="badge bg-secondary ms-1" style="font-size:.65em;font-weight:400">current</span>' : '' ?></td>
      <?php foreach ($selectedCards as $card):
        $bal  = $periodBalances[$eom][(int)$card['id']] ?? 0.0;
        $owed = max(0.0, -$bal);
      ?>
      <td class="text-end <?= $owed > 0 ? 'amount-debit' : 'text-muted' ?>">
        <?= $owed > 0 ? formatMoney($owed) : ($bal > 0 ? '<span title="Credit balance">'.formatMoney($bal).'</span>' : '—') ?>
      </td>
      <?php endforeach; ?>
      <?php if (count($selectedCards) > 1): ?>
      <td class="text-end <?= $periodOwed[$eom] > 0 ? 'amount-debit' : 'text-muted' ?>">
        <?= $periodOwed[$eom] > 0 ? formatMoney($periodOwed[$eom]) : '—' ?>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if (count($monthPoints) > 1): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels   = <?= json_encode($chartLabels) ?>;
  const datasets = <?= json_encode($chartDatasets) ?>;

  new Chart(document.getElementById('ccdChart'), {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: ctx => ctx.dataset.label + ': $' +
              ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }),
          },
        },
      },
      scales: {
        y: {
          min: 0,
          ticks: { callback: v => '$' + v.toLocaleString() },
        },
      },
    },
  });
})();
</script>
<?php endif; ?>

<?php endif; // selectedCards ?>

<!-- Account filter JS -->
<script>
(function () {
  const allChk  = document.getElementById('ccdAcctAll');
  const chkList = Array.from(document.querySelectorAll('.ccd-acct-chk'));
  const hidden  = document.getElementById('ccdAcctHidden');
  const lbl     = document.getElementById('ccdAcctLabel');

  function update() {
    const checked = chkList.filter(c => c.checked);
    const isAll   = checked.length === 0 || checked.length === chkList.length;
    hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
    lbl.textContent = isAll         ? 'All Cards'
      : checked.length === 1        ? checked[0].dataset.name
      : checked.length + ' Cards';
    allChk.indeterminate = !isAll && checked.length > 0;
    if (isAll) allChk.checked = checked.length === chkList.length;
  }

  allChk.addEventListener('change', function () {
    chkList.forEach(c => c.checked = this.checked);
    this.indeterminate = false;
    update();
  });
  chkList.forEach(c => c.addEventListener('change', update));
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$id      = (int)($_GET['id'] ?? 0);
$account = getAccount($id);
if (!$account || !isInvestLike($account['type']) || $account['is_investment_cash']) {
    setFlash('error', 'Invalid investment account.');
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}

$db = getDB();

// Per-account holdings with average cost basis
$stmt = $db->prepare(
    'SELECT
        i.id,
        i.name,
        i.symbol,
        i.type,
        COALESCE(SUM(CASE
            WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
            WHEN it.activity IN (\'sell\',\'remove\')                                         THEN -it.quantity
            ELSE 0
        END), 0) AS net_quantity,
        SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'reinvest_div\',\'reinvest_cap\') THEN it.quantity * it.price + it.commission ELSE 0 END) AS buy_cost,
        SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN it.quantity ELSE 0 END) AS buy_qty
     FROM investment_transactions it
     JOIN transactions t ON t.id  = it.transaction_id
     JOIN investments   i ON i.id = it.investment_id
     WHERE t.account_id = ? AND i.is_active = 1
     GROUP BY i.id, i.name, i.symbol, i.type
     HAVING net_quantity > 0.000001
     ORDER BY i.name'
);
$stmt->execute([$id]);
$rawHoldings = $stmt->fetchAll();

$latestPrices = getLatestInvestmentPrices();

// Build display rows + totals
$rows             = [];
$totalMarketValue = 0.0;
$totalCostBasis   = 0.0;
$totalGainLoss    = 0.0;
$anyMissingPrice  = false;

foreach ($rawHoldings as $h) {
    $invId    = (int)$h['id'];
    $qty      = (float)$h['net_quantity'];
    $buyQty   = (float)$h['buy_qty'];
    $buyCost  = (float)$h['buy_cost'];
    $avgCost  = $buyQty > 0 ? $buyCost / $buyQty : 0.0;
    $costBasis = $avgCost * $qty;

    $price       = $latestPrices[$invId]['price']      ?? null;
    $priceDate   = $latestPrices[$invId]['price_date'] ?? null;
    $marketValue = $price !== null ? $price * $qty : null;
    $gainLoss    = $marketValue !== null ? $marketValue - $costBasis : null;
    $gainLossPct = ($gainLoss !== null && $costBasis > 0) ? ($gainLoss / $costBasis) * 100 : null;

    if ($marketValue !== null) $totalMarketValue += $marketValue;
    $totalCostBasis += $costBasis;
    if ($gainLoss !== null) $totalGainLoss += $gainLoss;
    if ($price === null) $anyMissingPrice = true;

    $rows[] = [
        'id'            => $invId,
        'name'          => $h['name'],
        'symbol'        => $h['symbol'],
        'type'          => $h['type'],
        'qty'           => $qty,
        'price'         => $price,
        'price_date'    => $priceDate,
        'market_value'  => $marketValue,
        'cost_basis'    => $costBasis,
        'gain_loss'     => $gainLoss,
        'gain_loss_pct' => $gainLossPct,
    ];
}

$totalGainLossPct = $totalCostBasis > 0 ? ($totalGainLoss / $totalCostBasis) * 100 : null;

$securityRows = array_values(array_filter($rows, fn($r) => $r['type'] !== 'Money Market'));
$mmRows       = array_values(array_filter($rows, fn($r) => $r['type'] === 'Money Market'));

$pageTitle        = $account['name'] . ' — Holdings';
$currentPage      = 'accounts';
$currentAccountId = $id;

include __DIR__ . '/../includes/header.php';
?>

<script>
const BASE_PATH      = <?= json_encode(BASE_PATH) ?>;
const CSRF_TOKEN     = <?= json_encode(csrfToken()) ?>;
const HOLDINGS_ROWS  = <?= json_encode($rows) ?>;
const HOLDINGS_ACCT  = <?= json_encode($account['name']) ?>;
</script>

<div class="page-header">
  <h2>
    <i class="bi bi-pie-chart"></i>
    <?= h($account['name']) ?> — Holdings
    <?php if (!empty($rows)): ?>
    <span class="text-muted fs-5 fw-normal">(<?= count($rows) ?>)</span>
    <?php endif; ?>
    <?php if (!empty($account['last_reconciled_date'])): ?>
    <div class="text-muted small fw-normal mt-1">
      Last reconciled <?= formatDate($account['last_reconciled_date']) ?>
      <?php if ($account['last_reconciled_balance'] !== null): ?>
      &mdash; <?= formatMoney((float)$account['last_reconciled_balance']) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </h2>
  <div class="d-flex gap-2">
    <?php if (canEdit()): ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="openReconcileUpload()">
      <i class="bi bi-file-earmark-spreadsheet"></i> Reconcile with Statement
    </button>
    <?php endif; ?>
    <?php if (canEdit() && !empty($rows)): ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="openManualReconcile()">
      <i class="bi bi-pencil-square"></i> Manual Reconcile
    </button>
    <button class="btn btn-outline-secondary btn-sm" onclick="openAdjustModal()">
      <i class="bi bi-sliders"></i> Adjust Holdings
    </button>
    <?php endif; ?>
    <?php if (!empty($rows)): ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="exportHoldingsCSV()">
      <i class="bi bi-download"></i> Export CSV
    </button>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-list-ul"></i> Register
    </a>
  </div>
</div>

<?php if (empty($rows)): ?>
<div class="dash-section">
  <p class="text-muted">No current holdings found for this account.</p>
</div>
<?php else: ?>

<div class="holdings-table-wrap">
  <?php if ($anyMissingPrice): ?>
  <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.875rem">
    <i class="bi bi-exclamation-triangle"></i>
    Some securities have no price on record — market values and gain/loss may be incomplete.
  </div>
  <?php endif; ?>

  <table class="table table-sm holdings-table">

    <?php if (!empty($securityRows)): ?>
    <!-- ── Securities group ──────────────────────────────────────── -->
    <tbody class="holdings-group-head">
      <tr><td colspan="7" class="holdings-group-label">Securities</td></tr>
      <tr>
        <th class="sortable" data-group="sec" data-col="name">Holdings <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="sec" data-col="price">Last Quote <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="sec" data-col="qty">Shares <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="sec" data-col="mktval">Market Value <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="sec" data-col="cost">Cost Basis <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="sec" data-col="gl">Gain / Loss <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="sec" data-col="glpct">Gain / Loss % <i class="bi bi-arrow-down-up sort-icon"></i></th>
      </tr>
    </tbody>
    <tbody id="tbody-sec">
      <?php foreach ($securityRows as $r): ?>
      <?php
        $glCls  = '';
        if ($r['gain_loss'] !== null) $glCls = $r['gain_loss'] >= 0 ? 'gain-pos' : 'gain-neg';
        $pctCls = $glCls;
        $sign   = ($r['gain_loss'] !== null && $r['gain_loss'] >= 0) ? '+' : ($r['gain_loss'] !== null && $r['gain_loss'] < 0 ? '-' : '');
      ?>
      <tr data-name="<?= h($r['name']) ?>"
          data-price="<?= $r['price'] ?? '' ?>"
          data-qty="<?= $r['qty'] ?>"
          data-mktval="<?= $r['market_value'] ?? '' ?>"
          data-cost="<?= $r['cost_basis'] ?>"
          data-gl="<?= $r['gain_loss'] ?? '' ?>"
          data-glpct="<?= $r['gain_loss_pct'] ?? '' ?>">
        <td>
          <a href="#" class="holdings-name-link"
             data-id="<?= $r['id'] ?>"
             data-name="<?= h($r['name']) ?>"
             data-symbol="<?= h($r['symbol'] ?? '') ?>">
            <strong><?= h($r['name']) ?></strong>
            <?php if ($r['symbol']): ?>
            <span class="text-muted ms-1"><?= h($r['symbol']) ?></span>
            <?php endif; ?>
          </a>
          <div class="holdings-inv-type text-muted"><?= h($r['type']) ?></div>
        </td>
        <td class="text-end">
          <?php if ($r['price'] !== null): ?>
          <a href="#" class="inv-price"
             data-id="<?= $r['id'] ?>"
             data-name="<?= h($r['name']) ?>"
             data-symbol="<?= h($r['symbol'] ?? '') ?>">
            <?= formatMoney($r['price']) ?>
          </a>
          <?php if ($r['price_date']): ?>
          <div class="holdings-price-date text-muted"><?= formatDate($r['price_date']) ?></div>
          <?php endif; ?>
          <?php else: ?>
          <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="text-end"><?= rtrim(rtrim(number_format($r['qty'], 6), '0'), '.') ?></td>
        <td class="text-end">
          <?= $r['market_value'] !== null ? formatMoney($r['market_value']) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end">
          <?= $r['cost_basis'] > 0 ? formatMoney($r['cost_basis']) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end <?= $glCls ?>">
          <?php if ($r['gain_loss'] !== null): ?>
          <?= $sign ?><?= formatMoney(abs($r['gain_loss'])) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end <?= $pctCls ?>">
          <?php if ($r['gain_loss_pct'] !== null): ?>
          <?= $sign ?><?= number_format(abs($r['gain_loss_pct']), 2) ?>%
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endif; ?>

    <?php if (!empty($mmRows)): ?>
    <!-- ── Money Market group ────────────────────────────────────── -->
    <tbody class="holdings-group-head">
      <tr><td colspan="7" class="holdings-group-label holdings-group-label-mm">Money Market</td></tr>
      <tr>
        <th class="sortable" data-group="mm" data-col="name">Holdings <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="mm" data-col="price">Last Quote <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="mm" data-col="qty">Shares <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="mm" data-col="mktval">Market Value <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="mm" data-col="cost">Cost Basis <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="mm" data-col="gl">Gain / Loss <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="text-end sortable" data-group="mm" data-col="glpct">Gain / Loss % <i class="bi bi-arrow-down-up sort-icon"></i></th>
      </tr>
    </tbody>
    <tbody id="tbody-mm">
      <?php foreach ($mmRows as $r): ?>
      <?php
        $glCls  = '';
        if ($r['gain_loss'] !== null) $glCls = $r['gain_loss'] >= 0 ? 'gain-pos' : 'gain-neg';
        $pctCls = $glCls;
        $sign   = ($r['gain_loss'] !== null && $r['gain_loss'] >= 0) ? '+' : ($r['gain_loss'] !== null && $r['gain_loss'] < 0 ? '-' : '');
      ?>
      <tr data-name="<?= h($r['name']) ?>"
          data-price="<?= $r['price'] ?? '' ?>"
          data-qty="<?= $r['qty'] ?>"
          data-mktval="<?= $r['market_value'] ?? '' ?>"
          data-cost="<?= $r['cost_basis'] ?>"
          data-gl="<?= $r['gain_loss'] ?? '' ?>"
          data-glpct="<?= $r['gain_loss_pct'] ?? '' ?>">
        <td>
          <a href="#" class="holdings-name-link"
             data-id="<?= $r['id'] ?>"
             data-name="<?= h($r['name']) ?>"
             data-symbol="<?= h($r['symbol'] ?? '') ?>">
            <strong><?= h($r['name']) ?></strong>
            <?php if ($r['symbol']): ?>
            <span class="text-muted ms-1"><?= h($r['symbol']) ?></span>
            <?php endif; ?>
          </a>
          <div class="holdings-inv-type text-muted"><?= h($r['type']) ?></div>
        </td>
        <td class="text-end">
          <?php if ($r['price'] !== null): ?>
          <a href="#" class="inv-price"
             data-id="<?= $r['id'] ?>"
             data-name="<?= h($r['name']) ?>"
             data-symbol="<?= h($r['symbol'] ?? '') ?>">
            <?= formatMoney($r['price']) ?>
          </a>
          <?php if ($r['price_date']): ?>
          <div class="holdings-price-date text-muted"><?= formatDate($r['price_date']) ?></div>
          <?php endif; ?>
          <?php else: ?>
          <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="text-end"><?= rtrim(rtrim(number_format($r['qty'], 6), '0'), '.') ?></td>
        <td class="text-end">
          <?= $r['market_value'] !== null ? formatMoney($r['market_value']) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end">
          <?= $r['cost_basis'] > 0 ? formatMoney($r['cost_basis']) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end <?= $glCls ?>">
          <?php if ($r['gain_loss'] !== null): ?>
          <?= $sign ?><?= formatMoney(abs($r['gain_loss'])) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end <?= $pctCls ?>">
          <?php if ($r['gain_loss_pct'] !== null): ?>
          <?= $sign ?><?= number_format(abs($r['gain_loss_pct']), 2) ?>%
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endif; ?>

    <tfoot>
      <?php
        $totGlCls = $totalGainLoss >= 0 ? 'gain-pos' : 'gain-neg';
        $totSign  = $totalGainLoss >= 0 ? '+' : '-';
      ?>
      <tr class="holdings-totals">
        <th>Total</th>
        <th></th>
        <th></th>
        <th class="text-end"><?= formatMoney($totalMarketValue) ?></th>
        <th class="text-end"><?= formatMoney($totalCostBasis) ?></th>
        <th class="text-end <?= $totGlCls ?>"><?= $totSign ?><?= formatMoney(abs($totalGainLoss)) ?></th>
        <th class="text-end <?= $totGlCls ?>">
          <?php if ($totalGainLossPct !== null): ?>
          <?= $totSign ?><?= number_format(abs($totalGainLossPct), 2) ?>%
          <?php endif; ?>
        </th>
      </tr>
    </tfoot>
  </table>
</div>

<script>
(function () {
  const sortState = {};
  const numCols   = new Set(['price','qty','mktval','cost','gl','glpct']);

  function getSortVal(row, col) {
    const raw = row.dataset[col];
    if (raw === '' || raw === undefined || raw === null) return null;
    return numCols.has(col) ? parseFloat(raw) : raw;
  }

  document.addEventListener('click', e => {
    const th = e.target.closest('.holdings-table th.sortable');
    if (!th) return;
    const group = th.dataset.group;
    const col   = th.dataset.col;
    if (!group || !col) return;

    const tbody = document.getElementById('tbody-' + group);
    if (!tbody) return;

    const state = sortState[group] || (sortState[group] = { col: null, dir: 'asc' });
    state.dir = (state.col === col && state.dir === 'asc') ? 'desc' : 'asc';
    state.col = col;

    th.closest('tr').querySelectorAll('th.sortable').forEach(t => {
      const icon = t.querySelector('.sort-icon');
      if (!icon) return;
      icon.className = 'bi sort-icon ' + (t.dataset.col === col
        ? (state.dir === 'asc' ? 'bi-sort-up-alt' : 'bi-sort-down-alt')
        : 'bi-arrow-down-up');
    });

    const dir  = state.dir === 'asc' ? 1 : -1;
    const rows = [...tbody.querySelectorAll('tr')];
    rows.sort((a, b) => {
      const av = getSortVal(a, col);
      const bv = getSortVal(b, col);
      if (av === bv) return 0;
      if (av === null) return 1;
      if (bv === null) return -1;
      return typeof av === 'string' ? dir * av.localeCompare(bv) : dir * (av - bv);
    });
    rows.forEach(r => tbody.appendChild(r));
  });
})();

function exportHoldingsCSV() {
  const cols = ['Name','Symbol','Type','Shares','Last Price','Market Value','Cost Basis','Gain/Loss','Gain/Loss %'];
  const lines = [cols.map(csvCell).join(',')];

  for (const r of HOLDINGS_ROWS) {
    lines.push([
      r.name,
      r.symbol ?? '',
      r.type,
      r.qty,
      r.price  ?? '',
      r.market_value  ?? '',
      r.cost_basis,
      r.gain_loss     ?? '',
      r.gain_loss_pct !== null && r.gain_loss_pct !== undefined ? (r.gain_loss_pct / 100) : '',
    ].map(csvCell).join(','));
  }

  const blob = new Blob([lines.join('\r\n')], { type: 'text/csv' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = HOLDINGS_ACCT.replace(/[^a-z0-9]+/gi, '_') + '_holdings.csv';
  a.click();
  URL.revokeObjectURL(url);
}

function csvCell(v) {
  if (v === null || v === undefined || v === '') return '';
  const s = String(v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}
</script>

<?php endif; ?>

<?php if (canEdit()): ?>
<!-- ── Reconcile: Upload Modal ─────────────────────────────── -->
<div class="modal fade" id="reconcileUploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-spreadsheet"></i> Reconcile with Statement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Upload a CSV exported from your brokerage. Share counts will be compared against your register and discrepancies flagged for review.</p>
        <label class="form-label required">Statement CSV File</label>
        <input type="file" id="recon_file" class="form-control" accept=".csv">
        <div id="recon_upload_error" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="recon_upload_btn" onclick="submitReconcileFile()">
          <i class="bi bi-search"></i> Compare
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Reconcile: Manual Entry Modal ──────────────────────────── -->
<div class="modal fade" id="reconcileManualModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Manual Reconcile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">Enter the share count shown on your printed or PDF statement for each holding — price and avg cost are optional. Fields are pre-filled with your current register values; only change what differs.</p>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Security</th>
                <th class="text-end" style="width:140px">Statement Shares</th>
                <th class="text-end" style="width:120px">Statement Price</th>
                <th class="text-end" style="width:140px">Statement Avg Cost</th>
              </tr>
            </thead>
            <tbody id="man_recon_tbody"></tbody>
          </table>
        </div>
        <div id="man_recon_error" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="man_recon_btn" onclick="submitManualReconcile()">
          <i class="bi bi-search"></i> Compare
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Reconcile: Account Mismatch Warning Modal ────────────── -->
<div class="modal fade" id="reconcileWarnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning"></i> Account Number Mismatch</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>The statement doesn't match our account number. Are you sure you want to proceed?</p>
        <table class="table table-sm mb-0">
          <tr><th style="width:40%">Statement account</th><td id="recon_warn_csv_acct" class="font-monospace"></td></tr>
          <tr><th>Our account #</th><td id="recon_warn_our_acct" class="font-monospace"></td></tr>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" onclick="proceedReconcile()">Proceed Anyway</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Reconcile: Results Modal ─────────────────────────────── -->
<div class="modal fade" id="reconcileResultsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard-check"></i> Reconciliation Report</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="px-3 pt-3 pb-2 border-bottom text-muted small" id="recon_summary"></div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
            <thead class="table-light sticky-top">
              <tr>
                <th rowspan="2">Security</th>
                <th rowspan="2">Status</th>
                <th colspan="3" class="text-center border-start">Shares</th>
                <th colspan="3" class="text-center border-start">Avg Cost / Share</th>
                <th colspan="3" class="text-center border-start">Last Price</th>
              </tr>
              <tr>
                <th class="text-end border-start">Statement</th>
                <th class="text-end">Ours</th>
                <th class="text-end">Δ</th>
                <th class="text-end border-start">Statement</th>
                <th class="text-end">Ours</th>
                <th class="text-end">Δ</th>
                <th class="text-end border-start">Statement</th>
                <th class="text-end">Ours</th>
                <th class="text-end">Δ</th>
              </tr>
            </thead>
            <tbody id="recon_tbody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer flex-wrap gap-2">
        <div class="me-auto">
          <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <label class="small mb-0 fw-semibold" for="recon_statement_date">Statement date</label>
            <input type="date" id="recon_statement_date" class="form-control form-control-sm" style="width:auto"
                   value="<?= date('Y-m-d') ?>">
          </div>
          <div class="text-muted small">
            <i class="bi bi-info-circle"></i>
            Cost differences are informational only. Share counts and prices can be applied below.
          </div>
          <div id="recon_price_update_row" class="d-none mt-2 d-flex align-items-center gap-2 flex-wrap">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" id="recon_update_prices" checked>
              <label class="form-check-label small fw-semibold" for="recon_update_prices">
                Update prices from statement (as of statement date)
              </label>
            </div>
          </div>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="recon_apply_btn" onclick="applyReconcileAdjustments()">
          Apply Adjustments
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const RECON_ACCOUNT_ID = <?= $id ?>;
const RECON_CSRF       = <?= json_encode(csrfToken()) ?>;
const RECON_BASE_PATH  = '<?= BASE_PATH ?>';

let _reconData = null;

// ── Upload ──────────────────────────────────────────────────────
function openReconcileUpload() {
  document.getElementById('recon_file').value = '';
  document.getElementById('recon_upload_error').style.display = 'none';
  document.getElementById('recon_upload_btn').disabled = false;
  document.getElementById('recon_upload_btn').innerHTML = '<i class="bi bi-search"></i> Compare';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileUploadModal')).show();
}

async function submitReconcileFile() {
  const fileEl = document.getElementById('recon_file');
  const errEl  = document.getElementById('recon_upload_error');
  const btn    = document.getElementById('recon_upload_btn');

  errEl.style.display = 'none';
  if (!fileEl.files.length) {
    errEl.textContent = 'Please select a CSV file.';
    errEl.style.display = 'block';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Comparing…';

  const data = new FormData();
  data.append('csrf_token', RECON_CSRF);
  data.append('account_id', RECON_ACCOUNT_ID);
  data.append('csv_file',   fileEl.files[0]);

  try {
    const res  = await fetch(RECON_BASE_PATH + '/accounts/reconcile_statement', { method: 'POST', body: data });
    const json = await res.json();
    if (!json.ok) {
      errEl.textContent = json.error || 'Upload failed.';
      errEl.style.display = 'block';
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-search"></i> Compare';
      return;
    }

    _reconData = json;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileUploadModal')).hide();

    if (!json.account_match) {
      document.getElementById('recon_warn_csv_acct').textContent = json.csv_account || '(unknown)';
      document.getElementById('recon_warn_our_acct').textContent = json.our_account || '(not set)';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileWarnModal')).show();
    } else {
      showReconcileResults(json);
    }
  } catch (e) {
    console.error(e);
    errEl.textContent = 'Network error.';
    errEl.style.display = 'block';
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-search"></i> Compare';
  }
}

// ── Warning: proceed ───────────────────────────────────────────
function proceedReconcile() {
  bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileWarnModal')).hide();
  showReconcileResults(_reconData);
}

// ── Manual entry ────────────────────────────────────────────────
function openManualReconcile() {
  const tbody = document.getElementById('man_recon_tbody');
  tbody.innerHTML = HOLDINGS_ROWS.map(r => {
    const avgCost = r.qty > 0 ? r.cost_basis / r.qty : null;
    return `<tr data-id="${r.id}" data-name="${esc(r.name)}" data-symbol="${esc(r.symbol || '')}"
                data-qty="${r.qty}" data-price="${r.price ?? ''}" data-avgcost="${avgCost ?? ''}">
      <td><strong>${esc(r.name)}</strong>${r.symbol ? ' <span class="text-muted">' + esc(r.symbol) + '</span>' : ''}</td>
      <td class="text-end"><input type="number" class="form-control form-control-sm text-end man-qty" step="0.000001" min="0" value="${r.qty}"></td>
      <td class="text-end"><input type="number" class="form-control form-control-sm text-end man-price" step="0.01" min="0" placeholder="optional" value="${r.price ?? ''}"></td>
      <td class="text-end"><input type="number" class="form-control form-control-sm text-end man-cost" step="0.01" min="0" placeholder="optional" value="${avgCost !== null ? avgCost.toFixed(4) : ''}"></td>
    </tr>`;
  }).join('');

  document.getElementById('man_recon_error').style.display = 'none';
  document.getElementById('man_recon_btn').disabled = false;
  document.getElementById('man_recon_btn').innerHTML = '<i class="bi bi-search"></i> Compare';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileManualModal')).show();
}

function submitManualReconcile() {
  const errEl = document.getElementById('man_recon_error');
  errEl.style.display = 'none';

  const rows    = [];
  let hasError  = false;

  document.querySelectorAll('#man_recon_tbody tr').forEach(tr => {
    const qtyEl   = tr.querySelector('.man-qty');
    const priceEl = tr.querySelector('.man-price');
    const costEl  = tr.querySelector('.man-cost');

    const stmtQty = parseFloat(qtyEl.value);
    if (isNaN(stmtQty) || stmtQty < 0) { hasError = true; return; }

    const ourQty     = parseFloat(tr.dataset.qty);
    const ourPrice   = tr.dataset.price   !== '' ? parseFloat(tr.dataset.price)   : null;
    const ourAvgCost = tr.dataset.avgcost !== '' ? parseFloat(tr.dataset.avgcost) : null;
    const stmtPrice  = priceEl.value.trim() !== '' ? parseFloat(priceEl.value) : null;
    const stmtCost   = costEl.value.trim()  !== '' ? parseFloat(costEl.value)  : null;

    const qtyDiff   = stmtQty - ourQty;
    const priceDiff = (stmtPrice !== null && ourPrice   !== null) ? stmtPrice - ourPrice   : null;
    const costDiff  = (stmtCost  !== null && ourAvgCost !== null) ? stmtCost  - ourAvgCost : null;

    const hasQtyDiff   = Math.abs(qtyDiff) >= 0.000001;
    const hasPriceDiff = priceDiff !== null && Math.abs(priceDiff) >= 0.01;
    const hasCostDiff  = costDiff  !== null && Math.abs(costDiff)  >= 0.01;

    rows.push({
      investment_id:  parseInt(tr.dataset.id, 10),
      symbol:         tr.dataset.symbol,
      name:           tr.dataset.name,
      description:    null,
      status:         (hasQtyDiff || hasPriceDiff || hasCostDiff) ? 'diff' : 'match',
      has_qty_diff:   hasQtyDiff,
      has_cost_diff:  hasCostDiff,
      has_price_diff: hasPriceDiff,
      stmt_qty:       stmtQty,
      our_qty:        ourQty,
      qty_diff:       qtyDiff,
      stmt_avg_cost:  stmtCost,
      our_avg_cost:   ourAvgCost,
      cost_diff:      costDiff,
      stmt_price:     stmtPrice,
      our_price:      ourPrice,
      price_diff:     priceDiff,
    });
  });

  if (hasError) {
    errEl.textContent = 'Enter a valid non-negative share count for every security.';
    errEl.style.display = 'block';
    return;
  }

  _reconData = { ok: true, account_match: true, rows };
  bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileManualModal')).hide();
  showReconcileResults(_reconData);
}

// ── Results ────────────────────────────────────────────────────
function showReconcileResults(data) {
  const total      = data.rows.length;
  const diffCount  = data.rows.filter(r => r.status !== 'match').length;
  const qtyAdjs    = data.rows.filter(r => r.has_qty_diff && r.investment_id).length;
  const priceAdjs  = data.rows.filter(r => r.investment_id && r.stmt_price !== null).length;

  let summary = `<strong>${total}</strong> securities compared &nbsp;&bull;&nbsp; ` +
    `<strong>${diffCount}</strong> discrepanc${diffCount !== 1 ? 'ies' : 'y'} &nbsp;&bull;&nbsp; ` +
    `<strong>${qtyAdjs}</strong> share adjustment${qtyAdjs !== 1 ? 's' : ''} available`;
  if (priceAdjs > 0)
    summary += ` &nbsp;&bull;&nbsp; <strong>${priceAdjs}</strong> price${priceAdjs !== 1 ? 's' : ''} available`;
  document.getElementById('recon_summary').innerHTML = summary;

  document.getElementById('recon_tbody').innerHTML = data.rows.map(buildReconRow).join('');

  const priceRow = document.getElementById('recon_price_update_row');
  priceRow.classList.toggle('d-none', priceAdjs === 0);

  const nothingToDo = qtyAdjs === 0 && priceAdjs === 0;
  const applyBtn = document.getElementById('recon_apply_btn');
  applyBtn.disabled = false;
  applyBtn.innerHTML = nothingToDo
    ? '<i class="bi bi-check-circle"></i> Mark Reconciled'
    : '<i class="bi bi-check-circle"></i> Apply &amp; Mark Reconciled';

  bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileResultsModal')).show();
}

function buildReconRow(r) {
  const rowCls = r.status === 'not_in_register'  ? 'table-danger'
               : r.status === 'not_in_statement'  ? 'table-secondary'
               : r.status === 'no_qty'             ? 'table-secondary'
               : '';

  const name = esc(r.name || r.description || r.symbol);
  const sym  = r.name ? `<span class="text-muted">${esc(r.symbol)}</span>` : '';

  let badge;
  if      (r.status === 'match')            badge = '<span class="badge bg-success">Match</span>';
  else if (r.status === 'not_in_register')  badge = '<span class="badge bg-danger">Not in Register</span>';
  else if (r.status === 'not_in_statement') badge = '<span class="badge bg-secondary">Not in Statement</span>';
  else if (r.status === 'no_qty')           badge = '<span class="badge bg-secondary">Cash / MM</span>';
  else {
    badge = '';
    if (r.has_qty_diff)   badge += '<span class="badge bg-warning text-dark me-1">Qty</span>';
    if (r.has_cost_diff)  badge += '<span class="badge bg-warning text-dark me-1">Cost</span>';
    if (r.has_price_diff) badge += '<span class="badge bg-info    text-dark me-1">Price</span>';
  }

  const qCls = r.has_qty_diff   ? ' recon-diff-cell' : '';
  const cCls = r.has_cost_diff  ? ' recon-diff-cell' : '';
  const pCls = r.has_price_diff ? ' recon-diff-cell' : '';

  return `<tr class="${rowCls}">
    <td><strong>${name}</strong>${sym ? '<br>' + sym : ''}</td>
    <td class="text-nowrap">${badge}</td>
    <td class="text-end border-start${qCls}">${fmtQty(r.stmt_qty)}</td>
    <td class="text-end${qCls}">${fmtQty(r.our_qty)}</td>
    <td class="text-end${qCls}">${fmtDiff(r.qty_diff, false)}</td>
    <td class="text-end border-start${cCls}">${fmtMoney(r.stmt_avg_cost)}</td>
    <td class="text-end${cCls}">${fmtMoney(r.our_avg_cost)}</td>
    <td class="text-end${cCls}">${fmtDiff(r.cost_diff, true)}</td>
    <td class="text-end border-start${pCls}">${fmtMoney(r.stmt_price)}</td>
    <td class="text-end${pCls}">${fmtMoney(r.our_price)}</td>
    <td class="text-end${pCls}">${fmtDiff(r.price_diff, true)}</td>
  </tr>`;
}

// ── Apply adjustments ──────────────────────────────────────────
async function applyReconcileAdjustments() {
  if (!_reconData) return;

  const statementDate = document.getElementById('recon_statement_date').value;

  const adjustments = _reconData.rows
    .filter(r => r.has_qty_diff && r.investment_id !== null)
    .map(r => ({ investment_id: r.investment_id, new_qty: r.stmt_qty }));

  let priceUpdates = [];
  const updatePricesEl = document.getElementById('recon_update_prices');
  if (updatePricesEl && updatePricesEl.checked) {
    priceUpdates = _reconData.rows
      .filter(r => r.investment_id !== null && r.stmt_price !== null)
      .map(r => ({ investment_id: r.investment_id, price: r.stmt_price, price_date: statementDate }));
  }

  const btn = document.getElementById('recon_apply_btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Applying…';

  const data = new FormData();
  data.append('csrf_token',     RECON_CSRF);
  data.append('account_id',     RECON_ACCOUNT_ID);
  data.append('adjustments',    JSON.stringify(adjustments));
  data.append('price_updates',  JSON.stringify(priceUpdates));
  data.append('statement_date', statementDate);

  try {
    const res  = await fetch(RECON_BASE_PATH + '/accounts/apply_reconciliation', { method: 'POST', body: data });
    const json = await res.json();
    if (json.ok) {
      bootstrap.Modal.getOrCreateInstance(document.getElementById('reconcileResultsModal')).hide();
      location.reload();
    } else {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> ' + esc(json.error || 'Apply failed');
    }
  } catch (e) {
    console.error(e);
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Network error';
  }
}

// ── Formatting helpers ─────────────────────────────────────────
function fmtQty(n) {
  if (n === null || n === undefined) return '<span class="text-muted">—</span>';
  return parseFloat(parseFloat(n).toFixed(6)).toLocaleString('en-US', { maximumFractionDigits: 6 });
}
function fmtMoney(n) {
  if (n === null || n === undefined) return '<span class="text-muted">—</span>';
  return '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtDiff(n, isMoney) {
  if (n === null || n === undefined || n === 0) return '<span class="text-muted">—</span>';
  const sign = n > 0 ? '+' : '−';
  const cls  = n > 0 ? 'gain-pos' : 'gain-neg';
  const abs  = Math.abs(n);
  const val  = isMoney
    ? '$' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : parseFloat(abs.toFixed(6)).toLocaleString('en-US', { maximumFractionDigits: 6 });
  return `<span class="${cls}">${sign}${val}</span>`;
}
function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
<?php endif; ?>

<?php if (canEdit() && !empty($rows)): ?>
<!-- ── Adjust Holdings Modal ────────────────────────────────── -->
<div class="modal fade" id="adjustHoldingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-sliders"></i> Adjust Holdings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label required">Security</label>
          <select id="adj_investment" class="form-select" onchange="adjSecurityChanged()">
            <?php foreach ($rows as $r): ?>
            <option value="<?= $r['id'] ?>"
                    data-qty="<?= number_format($r['qty'], 6, '.', '') ?>">
              <?= h($r['name']) ?><?= $r['symbol'] ? ' (' . h($r['symbol']) . ')' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Current Shares</label>
          <input type="text" id="adj_current_qty" class="form-control" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label required">New Share Count</label>
          <input type="number" id="adj_new_qty" class="form-control"
                 step="0.000001" min="0" placeholder="0.000000">
        </div>
        <div id="adj_error" class="text-danger small" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="adj_save_btn" onclick="saveAdjustment()">
          <i class="bi bi-check-circle"></i> Save
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const ADJ_ACCOUNT_ID  = <?= $id ?>;
const ADJ_CSRF        = <?= json_encode(csrfToken()) ?>;
const ADJ_BASE_PATH   = '<?= BASE_PATH ?>';

function openAdjustModal() {
  document.getElementById('adj_error').style.display = 'none';
  document.getElementById('adj_new_qty').value = '';
  adjSecurityChanged();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('adjustHoldingModal')).show();
  setTimeout(() => document.getElementById('adj_new_qty').focus(), 300);
}

function adjSecurityChanged() {
  const sel = document.getElementById('adj_investment');
  const opt = sel.options[sel.selectedIndex];
  const raw = parseFloat(opt.dataset.qty);
  document.getElementById('adj_current_qty').value =
    isNaN(raw) ? '' : raw.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

async function saveAdjustment() {
  const errEl    = document.getElementById('adj_error');
  const newQtyEl = document.getElementById('adj_new_qty');
  const newQty   = parseFloat(newQtyEl.value);

  errEl.style.display = 'none';
  if (isNaN(newQty) || newQty < 0) {
    errEl.textContent = 'Enter a valid non-negative share count.';
    errEl.style.display = 'block';
    return;
  }

  const invId = document.getElementById('adj_investment').value;
  const btn   = document.getElementById('adj_save_btn');
  btn.disabled = true;

  const data = new FormData();
  data.append('csrf_token',    ADJ_CSRF);
  data.append('account_id',    ADJ_ACCOUNT_ID);
  data.append('investment_id', invId);
  data.append('new_qty',       newQty);

  try {
    const res  = await fetch(ADJ_BASE_PATH + '/accounts/adjust_holding', { method: 'POST', body: data });
    const json = await res.json();
    if (json.ok) {
      bootstrap.Modal.getOrCreateInstance(document.getElementById('adjustHoldingModal')).hide();
      location.reload();
    } else {
      errEl.textContent = json.error || 'Save failed.';
      errEl.style.display = 'block';
    }
  } catch (e) {
    console.error(e);
    errEl.textContent = 'Network error.';
    errEl.style.display = 'block';
  } finally {
    btn.disabled = false;
  }
}
</script>
<?php endif; ?>

<!-- ── Transaction History Modal ─────────────────────────────── -->
<div class="modal fade" id="txnHistoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="txnHistoryTitle">Transaction History</h5>
          <div class="small text-muted mt-1" id="txnHistorySub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="txnHistoryLoading" class="text-center py-4">
          <span class="spinner-border spinner-border-sm"></span> Loading…
        </div>
        <div id="txnHistoryContent" style="display:none">
          <table class="table table-sm table-hover mb-0" style="font-size:.875rem">
            <thead class="table-light sticky-top">
              <tr>
                <th>Date</th>
                <th>Activity</th>
                <th class="text-end">Shares</th>
                <th class="text-end">Price / Share</th>
                <th class="text-end">Commission</th>
                <th class="text-end">Total</th>
                <th>Memo</th>
              </tr>
            </thead>
            <tbody id="txnHistoryBody"></tbody>
            <tfoot id="txnHistoryFoot" class="table-light fw-semibold"></tfoot>
          </table>
        </div>
        <div id="txnHistoryEmpty" style="display:none" class="text-center text-muted py-4">
          <i class="bi bi-list-ul" style="font-size:2rem"></i>
          <p class="mt-2 mb-0">No transactions found for this security in this account.</p>
        </div>
      </div>
      <div class="modal-footer py-2">
        <a id="txnHistoryRegisterLink" href="#" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-list-ul"></i> View Register
        </a>
        <a id="txnHistorySecurityLink" href="#" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-graph-up"></i> Security Page
        </a>
        <button type="button" class="btn btn-secondary btn-sm ms-auto" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const ACCOUNT_ID = <?= $id ?>;

  const ACTIVITY_LABELS = {
    buy:          'Buy',
    sell:         'Sell',
    add:          'Add Shares',
    remove:       'Remove Shares',
    split:        'Split',
    reinvest_div: 'Reinvest Div.',
    reinvest_cap: 'Reinvest Cap Gain',
  };

  document.addEventListener('click', e => {
    const link = e.target.closest('.holdings-name-link[data-id]');
    if (!link) return;
    e.preventDefault();
    openTxnHistory(link.dataset.id, link.dataset.name, link.dataset.symbol);
  });

  function openTxnHistory(invId, name, symbol) {
    const title = name + (symbol ? ' (' + symbol + ')' : '');
    document.getElementById('txnHistoryTitle').textContent = title;
    document.getElementById('txnHistorySub').textContent   = '';
    document.getElementById('txnHistoryLoading').style.display  = '';
    document.getElementById('txnHistoryContent').style.display  = 'none';
    document.getElementById('txnHistoryEmpty').style.display    = 'none';

    const regLink = document.getElementById('txnHistoryRegisterLink');
    regLink.href  = BASE_PATH + '/accounts/register?id=' + ACCOUNT_ID;

    const secLink = document.getElementById('txnHistorySecurityLink');
    const secSlug = symbol ? encodeURIComponent(symbol) : invId;
    secLink.href  = BASE_PATH + '/portfolio/security/' + secSlug;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('txnHistoryModal')).show();

    fetch(BASE_PATH + '/accounts/holding_txn_history?account_id=' + encodeURIComponent(ACCOUNT_ID) +
          '&investment_id=' + encodeURIComponent(invId))
      .then(r => r.json())
      .then(data => {
        document.getElementById('txnHistoryLoading').style.display = 'none';
        if (!data.ok || !data.transactions.length) {
          document.getElementById('txnHistoryEmpty').style.display = '';
          return;
        }
        renderTxnHistory(data.transactions);
        document.getElementById('txnHistoryContent').style.display = '';
      })
      .catch(() => {
        document.getElementById('txnHistoryLoading').innerHTML =
          '<span class="text-danger">Failed to load transactions.</span>';
      });
  }

  function renderTxnHistory(txns) {
    const isBuy  = a => ['buy','add','reinvest_div','reinvest_cap','split'].includes(a);
    const isSell = a => ['sell','remove'].includes(a);

    let totalShares = 0, totalCost = 0;

    const rows = txns.map(t => {
      const qty   = t.quantity;
      const total = qty * t.price + t.commission;
      const sign  = isSell(t.activity) ? -1 : 1;
      totalShares += sign * qty;
      if (isBuy(t.activity))  totalCost += total;
      if (isSell(t.activity)) totalCost -= total;

      const actLabel = ACTIVITY_LABELS[t.activity] || t.activity;
      const actCls   = isSell(t.activity) ? 'text-danger' : isBuy(t.activity) ? 'text-success' : '';
      const shareStr = fmtQty(qty);
      const sellMark = isSell(t.activity) ? '−' : '';

      return `<tr>
        <td class="text-nowrap">${fmtDate(t.date)}</td>
        <td class="${actCls}">${actLabel}</td>
        <td class="text-end">${sellMark}${shareStr}</td>
        <td class="text-end">${t.price > 0 ? fmtMoney(t.price) : '<span class="text-muted">—</span>'}</td>
        <td class="text-end">${t.commission > 0 ? fmtMoney(t.commission) : '<span class="text-muted">—</span>'}</td>
        <td class="text-end">${sellMark}${fmtMoney(t.price * qty + t.commission)}</td>
        <td class="text-muted small">${thEsc(t.memo || '')}</td>
      </tr>`;
    });

    document.getElementById('txnHistoryBody').innerHTML = rows.join('');

    const sharesStr = parseFloat(totalShares.toFixed(6)).toLocaleString('en-US', { maximumFractionDigits: 6 });
    document.getElementById('txnHistoryFoot').innerHTML =
      `<tr>
        <td colspan="2">Net</td>
        <td class="text-end">${sharesStr}</td>
        <td></td><td></td>
        <td class="text-end">${fmtMoney(Math.abs(totalCost))}</td>
        <td></td>
      </tr>`;

    document.getElementById('txnHistorySub').textContent =
      txns.length + ' transaction' + (txns.length !== 1 ? 's' : '');
  }

  function fmtDate(iso) {
    const [y, m, d] = iso.split('-');
    return m + '/' + d + '/' + y;
  }
  function fmtMoney(n) {
    return '$' + Math.abs(parseFloat(n)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtQty(n) {
    return parseFloat(parseFloat(n).toFixed(6)).toLocaleString('en-US', { maximumFractionDigits: 6 });
  }
  function thEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php include __DIR__ . '/../includes/price_history_modal.php'; ?>

<?php // end of page - footer below ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>

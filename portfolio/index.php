<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$pageTitle   = 'Portfolio';
$currentPage = 'portfolio';
$investments = getAllInvestments();
$holdings    = getInvestmentHoldings();
$prices      = getLatestInvestmentPrices();
$costBases   = getInvestmentCostBases();
$priceProvider = getSetting('price_provider', 'manual');
$lastFetched   = getSetting('price_last_fetched');

// Group by type; track whether any owned exist (to set checkbox default)
$invGrouped  = [];
$anyOwned    = false;
foreach ($investments as $inv) {
    $invGrouped[$inv['type']][] = $inv;
    if ($inv['is_owned']) $anyOwned = true;
}
$invTypeOrder = ['Index','Stock','Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Cryptocurrency','Other'];

// Indices that have price history — offered in the comparison dropdown
$phIndicesStmt = getDB()->query(
    "SELECT id, name, symbol FROM investments
     WHERE type = 'Index' AND is_active = 1
       AND id IN (SELECT DISTINCT investment_id FROM investment_prices)
     ORDER BY name"
);
$phIndices = $phIndicesStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>
<script>
const BASE_PATH    = '<?= BASE_PATH ?>';
const PH_INDICES   = <?= json_encode(array_map(fn($i) => [
    'id'     => (int)$i['id'],
    'name'   => $i['name'],
    'symbol' => $i['symbol'],
], $phIndices)) ?>;
const ALL_INVESTMENTS = <?= json_encode(array_map(fn($i) => [
    'id'     => (int)$i['id'],
    'name'   => $i['name'],
    'symbol' => $i['symbol'],
], $investments)) ?>;
</script>

<div class="page-header">
  <h2><i class="bi bi-briefcase"></i> Portfolio</h2>
  <div class="d-flex align-items-center gap-2">
    <?php if ($priceProvider !== 'manual' && !empty($investments)): ?>
    <button class="btn btn-outline-secondary btn-sm" id="btnRefreshPrices" onclick="refreshPrices('latest')">
      <i class="bi bi-arrow-clockwise"></i> Refresh Prices
    </button>
    <button class="btn btn-outline-secondary btn-sm" id="btnLastFetchSummary" onclick="openLastFetchSummary()" style="display:none">
      <i class="bi bi-list-check"></i> Last Fetch Summary
    </button>
    <button class="btn btn-outline-secondary btn-sm" id="btnFetchHistory" onclick="openHistoryModal()" style="display:none">
      <i class="bi bi-calendar-range"></i> Fetch History
    </button>
    <?php endif; ?>
    <?php if (canEdit()): ?>
    <button class="btn btn-primary btn-sm" onclick="openInvestmentModal()">
      <i class="bi bi-plus-circle"></i> New Investment
    </button>
    <?php endif; ?>
    <?php if (isAdmin() && count($investments) >= 2): ?>
    <button class="btn btn-outline-secondary btn-sm" onclick="openMergeModal()">
      <i class="bi bi-union"></i> Merge
    </button>
    <?php endif; ?>
    <?php if (!empty($investments)): ?>
    <label class="portfolio-unowned-toggle mb-0 ms-auto ps-3">
      <input type="checkbox" id="showUnowned" <?= $anyOwned ? '' : 'checked' ?>>
      <span>Show unowned</span>
    </label>
    <?php endif; ?>
  </div>
</div>
<?php if ($lastFetched): ?>
<div class="portfolio-fetch-time">
  <i class="bi bi-clock"></i> Prices as of <?= h($lastFetched) ?>
</div>
<?php endif; ?>

<?php if (empty($investments)): ?>
<div class="dash-section">
  <p class="text-muted">No investments yet.
    <?php if (canEdit()): ?>
    <a href="#" onclick="openInvestmentModal();return false;">Add your first investment.</a>
    <?php endif; ?>
  </p>
</div>
<?php else: ?>

<?php foreach ($invTypeOrder as $type):
  if (empty($invGrouped[$type])) continue;
  $rows    = $invGrouped[$type];
  $typeKey = preg_replace('/[^a-z0-9]/i', '_', $type);
?>
<section class="dash-section mb-3 portfolio-section" id="section_<?= $typeKey ?>">
  <h4 class="section-title">
    <i class="bi <?= portfolioTypeIcon($type) ?>"></i> <?= h($type) ?>
    <span class="badge bg-secondary ms-2 section-count" id="count_<?= $typeKey ?>">
      <?= count($rows) ?>
    </span>
  </h4>
  <?php if ($type === 'Index'): ?>
  <!-- ── Market Indices table ──────────────────────────────── -->
  <table class="table table-sm dash-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Symbol</th>
        <th>CUSIP</th>
        <th class="text-end">Level</th>
        <th class="text-end">Day Change</th>
        <th class="text-end">Change %</th>
        <th>Country</th>
        <th>Memo</th>
        <?php if (canEdit()): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $inv):
        $invId       = (int)$inv['id'];
        $priceRow    = $prices[$invId] ?? null;
        $latestPrice = $priceRow['price']      ?? null;
        $prevClose   = $priceRow['prev_close'] ?? null;
        $dayChange   = ($latestPrice !== null && $prevClose !== null) ? $latestPrice - $prevClose : null;
        $dayChangePct = ($dayChange !== null && $prevClose > 0) ? ($dayChange / $prevClose) * 100 : null;
      ?>
      <tr class="inv-row inv-index inv-owned" data-owned="1" data-type="index" data-section="<?= $typeKey ?>">
        <td class="fw-medium">
          <?php $secSlug = !empty($inv['symbol']) ? urlencode($inv['symbol']) : $inv['id']; ?>
          <a href="<?= BASE_PATH ?>/portfolio/security/<?= $secSlug ?>" class="inv-name-link"><?= h($inv['name']) ?></a>
        </td>
        <td><?= $inv['symbol'] ? '<span class="inv-symbol">' . h($inv['symbol']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-muted small" style="font-family:monospace"><?= h($inv['cusip'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
        <td class="text-end text-nowrap">
          <?php if ($latestPrice !== null): ?>
            <button class="btn btn-link p-0 inv-price inv-index-price"
                    data-id="<?= $invId ?>"
                    data-name="<?= h($inv['name']) ?>"
                    data-symbol="<?= h($inv['symbol']) ?>"
                    title="<?= h($priceRow['source']) ?> · <?= h($priceRow['price_date']) ?> — click for history">
              <?= number_format($latestPrice, 2) ?>
            </button>
          <?php else: ?>
            <?php if ($inv['symbol'] && $priceProvider !== 'manual' && !$inv['disable_quotes']): ?>
              <button class="btn btn-link btn-sm p-0 inv-fetch-single me-1"
                      data-id="<?= $invId ?>" title="Fetch price">
                <i class="bi bi-cloud-download"></i>
              </button>
            <?php endif; ?>
            <button class="btn btn-link btn-sm p-0 inv-manual-price text-muted"
                    data-id="<?= $invId ?>"
                    data-name="<?= h($inv['name']) ?>"
                    title="Enter price manually">
              <i class="bi bi-pencil"></i>
            </button>
          <?php endif; ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($dayChange !== null): ?>
            <span class="inv-gain <?= $dayChange >= 0 ? 'gain-pos' : 'gain-neg' ?>">
              <?= ($dayChange >= 0 ? '+' : '') . number_format($dayChange, 2) ?>
            </span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($dayChangePct !== null): ?>
            <span class="inv-gain <?= $dayChangePct >= 0 ? 'gain-pos' : 'gain-neg' ?>">
              <?= ($dayChangePct >= 0 ? '+' : '') . number_format($dayChangePct, 2) ?>%
            </span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td><?= h($inv['country']) ?: '<span class="text-muted">—</span>' ?></td>
        <td class="text-muted small"><?= h($inv['memo'] ?? '') ?: '—' ?></td>
        <?php if (canEdit()): ?>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-secondary" title="Edit"
                  onclick="openInvestmentModal(<?= h(json_encode([
                      'id'             => $inv['id'],
                      'name'           => $inv['name'],
                      'symbol'         => $inv['symbol'],
                      'cusip'          => $inv['cusip'] ?? '',
                      'type'           => $inv['type'],
                      'country'        => $inv['country'],
                      'memo'           => $inv['memo'] ?? '',
                      'disable_quotes' => (int)($inv['disable_quotes'] ?? 0),
                      'in_watchlist'   => (int)($inv['in_watchlist']   ?? 0),
                  ])) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-danger ms-1" title="Deactivate"
                  onclick="confirmDeleteInv(<?= $inv['id'] ?>, <?= h(json_encode($inv['name'])) ?>, 0)">
            <i class="bi bi-eye-slash"></i>
          </button>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php else: ?>
  <?php
  $typeTotMktVal = null;
  $typeTotCost   = null;
  $typeTotGain   = null;
  foreach ($rows as $_inv) {
      $_id  = (int)$_inv['id'];
      $_qty = array_sum(array_column($holdings[$_id] ?? [], 'quantity'));
      $_pr  = $prices[$_id]     ?? null;
      $_br  = $costBases[$_id]  ?? null;
      $_lp  = $_pr ? $_pr['price'] : null;
      $_mv  = ($_lp !== null && $_qty > 0) ? $_lp * $_qty : null;
      $_cb  = ($_br && $_qty > 0) ? $_br['avg_cost'] * $_qty : null;
      $_gl  = ($_mv !== null && $_cb !== null) ? $_mv - $_cb : null;
      if ($_mv !== null) $typeTotMktVal = ($typeTotMktVal ?? 0) + $_mv;
      if ($_cb !== null) $typeTotCost   = ($typeTotCost   ?? 0) + $_cb;
      if ($_gl !== null) $typeTotGain   = ($typeTotGain   ?? 0) + $_gl;
  }
  $typeTotGainPct = ($typeTotGain !== null && $typeTotCost > 0)
      ? ($typeTotGain / $typeTotCost) * 100 : null;
  ?>
  <!-- ── Holdings table (stocks, bonds, ETFs, etc.) ────────── -->
  <table class="table table-sm dash-table">
    <thead>
      <tr>
        <th class="sortable" data-col="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="sortable" data-col="symbol">Symbol <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th>CUSIP</th>
        <th class="sortable text-end" data-col="shares">Shares <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th>Held In</th>
        <th class="text-end">Price</th>
        <th class="sortable text-end" data-col="mktval">Mkt Value <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="sortable text-end" data-col="costbasis">Cost Basis <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="sortable text-end" data-col="gainloss">Gain / Loss <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th class="sortable" data-col="country">Country <i class="bi bi-arrow-down-up sort-icon"></i></th>
        <th>Memo</th>
        <?php if (canEdit()): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $inv):
        $owned    = (bool)$inv['is_owned'];
        $invId    = (int)$inv['id'];
        $invHeld  = $holdings[$invId] ?? [];
        $totalQty = array_sum(array_column($invHeld, 'quantity'));
        $priceRow = $prices[$invId]    ?? null;
        $basisRow = $costBases[$invId] ?? null;
        $latestPrice = $priceRow ? $priceRow['price'] : null;
        $mktValue    = ($latestPrice !== null && $totalQty > 0) ? $latestPrice * $totalQty : null;
        $costBasis   = ($basisRow && $totalQty > 0) ? $basisRow['avg_cost'] * $totalQty : null;
        $gainLoss    = ($mktValue !== null && $costBasis !== null) ? $mktValue - $costBasis : null;
        $gainPct     = ($gainLoss !== null && $costBasis > 0) ? ($gainLoss / $costBasis) * 100 : null;
      ?>
      <tr class="inv-row <?= $owned ? 'inv-owned' : 'inv-unowned' ?>"
          data-owned="<?= $owned ? '1' : '0' ?>"
          data-section="<?= $typeKey ?>"
          data-name="<?= h(strtolower($inv['name'])) ?>"
          data-symbol="<?= h(strtolower($inv['symbol'] ?? '')) ?>"
          data-shares="<?= $totalQty ?>"
          data-mktval="<?= $mktValue ?? '' ?>"
          data-costbasis="<?= $costBasis ?? '' ?>"
          data-gainloss="<?= $gainLoss ?? '' ?>"
          data-country="<?= h(strtolower($inv['country'] ?? '')) ?>">
        <td class="fw-medium">
          <?php
          $secSlug = !empty($inv['symbol']) ? urlencode($inv['symbol']) : $inv['id'];
          $secUrl  = BASE_PATH . '/portfolio/security/' . $secSlug;
          ?>
          <a href="<?= $secUrl ?>" class="inv-name-link"><?= h($inv['name']) ?></a>
          <?php if (!$owned): ?>
          <span class="badge inv-not-purchased ms-1">Not in current portfolio</span>
          <?php endif; ?>
        </td>
        <td><?= $inv['symbol'] ? '<span class="inv-symbol">' . h($inv['symbol']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td class="text-muted small" style="font-family:monospace"><?= h($inv['cusip'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
        <td class="text-end text-nowrap">
          <?php if ($totalQty > 0): ?>
            <span class="inv-qty"><?= rtrim(rtrim(number_format($totalQty, 6), '0'), '.') ?></span>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="small">
          <?php if (empty($invHeld)): ?>
            <span class="text-muted">—</span>
          <?php elseif (count($invHeld) === 1): ?>
            <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $invHeld[0]['account_id'] ?>" class="inv-account-link"><?= h($invHeld[0]['account_name']) ?></a>
          <?php else: ?>
            <?php foreach ($invHeld as $hld): ?>
              <div><a href="<?= BASE_PATH ?>/accounts/register?id=<?= $hld['account_id'] ?>" class="inv-account-link"><?= h($hld['account_name']) ?></a>
              <span class="text-muted">(<?= rtrim(rtrim(number_format($hld['quantity'], 6), '0'), '.') ?>)</span></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($latestPrice !== null): ?>
            <button class="btn btn-link p-0 inv-price"
                    data-id="<?= $invId ?>"
                    data-name="<?= h($inv['name']) ?>"
                    data-symbol="<?= h($inv['symbol']) ?>"
                    title="<?= h($priceRow['source']) ?> · <?= h($priceRow['price_date']) ?> — click for history">
              <?= formatMoney($latestPrice) ?>
            </button>
          <?php else: ?>
            <?php if ($inv['symbol'] && $priceProvider !== 'manual' && !$inv['disable_quotes']): ?>
              <button class="btn btn-link btn-sm p-0 inv-fetch-single me-1"
                      data-id="<?= $invId ?>" title="Fetch price">
                <i class="bi bi-cloud-download"></i>
              </button>
            <?php endif; ?>
            <button class="btn btn-link btn-sm p-0 inv-manual-price text-muted"
                    data-id="<?= $invId ?>"
                    data-name="<?= h($inv['name']) ?>"
                    title="Enter price manually">
              <i class="bi bi-pencil"></i>
            </button>
          <?php endif; ?>
        </td>
        <td class="text-end text-nowrap">
          <?= $mktValue !== null ? '<span class="inv-mktval">' . formatMoney($mktValue) . '</span>' : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end text-nowrap">
          <?= $costBasis !== null ? formatMoney($costBasis) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($gainLoss !== null): ?>
            <span class="inv-gain <?= $gainLoss >= 0 ? 'gain-pos' : 'gain-neg' ?>">
              <?= ($gainLoss >= 0 ? '+' : '') . formatMoney($gainLoss) ?>
              <span class="gain-pct">(<?= ($gainPct >= 0 ? '+' : '') . number_format($gainPct, 1) ?>%)</span>
            </span>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= h($inv['country']) ?: '<span class="text-muted">—</span>' ?></td>
        <td class="text-muted small"><?= h($inv['memo'] ?? '') ?: '—' ?></td>
        <?php if (canEdit()): ?>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-secondary" title="Edit"
                  onclick="openInvestmentModal(<?= h(json_encode([
                      'id'             => $inv['id'],
                      'name'           => $inv['name'],
                      'symbol'         => $inv['symbol'],
                      'cusip'          => $inv['cusip'] ?? '',
                      'type'           => $inv['type'],
                      'country'        => $inv['country'],
                      'memo'           => $inv['memo'] ?? '',
                      'disable_quotes' => (int)($inv['disable_quotes'] ?? 0),
                      'in_watchlist'   => (int)($inv['in_watchlist']   ?? 0),
                  ])) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-danger ms-1" title="Deactivate"
                  onclick="confirmDeleteInv(<?= $inv['id'] ?>, <?= h(json_encode($inv['name'])) ?>, <?= (float)($totalQty ?? 0) ?>)">
            <i class="bi bi-eye-slash"></i>
          </button>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php if ($typeTotMktVal !== null || $typeTotCost !== null): ?>
    <tfoot class="portfolio-type-total">
      <tr>
        <td colspan="6" class="text-end pe-3">Total</td>
        <td class="text-end text-nowrap">
          <?= $typeTotMktVal !== null ? formatMoney($typeTotMktVal) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end text-nowrap">
          <?= $typeTotCost !== null ? formatMoney($typeTotCost) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($typeTotGain !== null): ?>
            <span class="<?= $typeTotGain >= 0 ? 'gain-pos' : 'gain-neg' ?>">
              <?= ($typeTotGain >= 0 ? '+' : '') . formatMoney($typeTotGain) ?>
              <?php if ($typeTotGainPct !== null): ?>
                <span class="gain-pct">(<?= ($typeTotGainPct >= 0 ? '+' : '') . number_format($typeTotGainPct, 1) ?>%)</span>
              <?php endif; ?>
            </span>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td colspan="<?= canEdit() ? 3 : 2 ?>"></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  <?php endif; ?>
  <p class="section-empty-msg text-muted small ps-1 mb-0" style="display:none">
    No owned investments in this category.
  </p>
</section>
<?php endforeach; ?>
<?php endif; ?>

<!-- Hidden delete form -->
<form id="deleteInvForm" method="post" action="<?= BASE_PATH ?>/portfolio/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteInvId">
</form>

<!-- Delete confirmation modal -->
<div class="modal fade" id="confirmInvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill"></i> Deactivate Security</h5>
      </div>
      <div class="modal-body confirm-modal-body">
        <p id="confirmInvMsg"></p>
        <div id="confirmInvHoldWarn" class="alert alert-warning small py-2" style="display:none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          You still hold <span id="confirmInvQty"></span> of this security.
          Net Worth will keep counting these shares, but the Portfolio and holdings reports will not show them.
        </div>
        <p class="text-muted small mb-0">Transactions and price history are kept, but the security is hidden
          from the Portfolio and current-holdings reports, and price fetching stops. Importing or recording
          new activity for it reactivates it automatically.</p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmInvBtn">Deactivate</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/investment_modal.php'; ?>

<!-- Fetch Summary Modal -->
<div class="modal fade" id="fetchSummaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--ms-blue);color:#fff">
        <h5 class="modal-title" id="fetchSummaryTitle">
          <i class="bi bi-cloud-check"></i> Fetch Summary
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="fetchSummaryTotals" class="px-3 py-2 border-bottom small text-muted"></div>
        <table class="table table-sm mb-0" id="fetchSummaryTable">
          <thead>
            <tr>
              <th>Symbol</th>
              <th>Name</th>
              <th>Source</th>
              <th class="text-end fetch-summary-col-records">Records</th>
              <th class="text-end">Last Quote</th>
              <th class="text-end">Change %</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="fetchSummaryBody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary btn-sm" id="fetchSummaryClose" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Fetch History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-calendar-range"></i> Fetch Price History</h5>
      </div>
      <div class="modal-body confirm-modal-body">
        <p class="mb-3 text-muted small">Downloads historical daily closing prices for all investments with ticker symbols.</p>
        <div class="row g-2">
          <div class="col">
            <label class="form-label small mb-1">From</label>
            <input type="date" class="form-control form-control-sm" id="histFrom"
                   value="<?= date('Y-m-d', strtotime('-1 year')) ?>">
          </div>
          <div class="col">
            <label class="form-label small mb-1">To</label>
            <input type="date" class="form-control form-control-sm" id="histTo"
                   value="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div id="historyStatus" class="mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnHistoryFetch" onclick="fetchHistory()">
          <i class="bi bi-cloud-download"></i> Fetch
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?= h(csrfToken()) ?>';
const FETCH_SUMMARY_LS_KEY = 'portfolio_lastFetchSummary_v1';
let lastFetchSummaryPayload = null;
let lastFetchSummaryMode = 'latest';

function syncFetchSummaryButtons() {
  const hasSummary = !!(lastFetchSummaryPayload && Array.isArray(lastFetchSummaryPayload.results));
  const summaryBtn = document.getElementById('btnLastFetchSummary');
  const historyBtn = document.getElementById('btnFetchHistory');
  if (summaryBtn) summaryBtn.style.display = hasSummary ? '' : 'none';
  if (historyBtn) historyBtn.style.display = hasSummary ? '' : 'none';
}

function loadSavedFetchSummary() {
  try {
    const raw = localStorage.getItem(FETCH_SUMMARY_LS_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw);
    if (!saved || typeof saved !== 'object' || !saved.data || !Array.isArray(saved.data.results)) return;
    lastFetchSummaryPayload = saved.data;
    lastFetchSummaryMode = saved.mode === 'history' ? 'history' : 'latest';
  } catch (e) {
    // Ignore malformed localStorage values.
  }
}

function saveFetchSummary(data, mode) {
  try {
    localStorage.setItem(FETCH_SUMMARY_LS_KEY, JSON.stringify({
      mode: mode === 'history' ? 'history' : 'latest',
      data,
      saved_at: Date.now(),
    }));
  } catch (e) {
    // Ignore storage write failures.
  }
}

function openLastFetchSummary() {
  if (!lastFetchSummaryPayload) return;
  showFetchSummary(lastFetchSummaryPayload, lastFetchSummaryMode, false);
}

loadSavedFetchSummary();
syncFetchSummaryButtons();

// ── Unowned filter ──────────────────────────────────────────────
(function () {
  const checkbox = document.getElementById('showUnowned');
  if (!checkbox) return;

  const LS_KEY = 'portfolio_showUnowned';
  const saved = localStorage.getItem(LS_KEY);
  if (saved !== null) checkbox.checked = saved === '1';

  function applyFilter() {
    localStorage.setItem(LS_KEY, checkbox.checked ? '1' : '0');
    const show = checkbox.checked;
    const counts = {};
    document.querySelectorAll('.inv-row').forEach(row => {
      const sec     = row.dataset.section;
      const owned   = row.dataset.owned === '1';
      const isIndex = row.dataset.type === 'index';
      const visible = isIndex || owned || show;
      row.style.display = visible ? '' : 'none';
      if (visible) counts[sec] = (counts[sec] || 0) + 1;
    });
    document.querySelectorAll('.portfolio-section').forEach(sec => {
      const key      = sec.id.replace('section_', '');
      const count    = counts[key] || 0;
      const badge    = document.getElementById('count_' + key);
      const emptyMsg = sec.querySelector('.section-empty-msg');
      const table    = sec.querySelector('table');
      if (badge)    badge.textContent = count;
      if (table)    table.style.display = count > 0 ? '' : 'none';
      if (emptyMsg) emptyMsg.style.display = count === 0 ? '' : 'none';
    });
  }

  checkbox.addEventListener('change', applyFilter);
  applyFilter();
})();

// ── Column sorting (persisted across page loads) ────────────────
(function () {
  const LS_KEY  = 'portfolio_sortPref';
  const numCols = new Set(['shares', 'mktval', 'costbasis', 'gainloss']);

  let state = { col: 'mktval', dir: 'desc' };
  try {
    const saved = JSON.parse(localStorage.getItem(LS_KEY));
    if (saved && saved.col) state = saved;
  } catch (e) {}

  function getSortVal(row, col) {
    const raw = row.dataset[col];
    if (raw === '' || raw === undefined || raw === null) return null;
    return numCols.has(col) ? parseFloat(raw) : raw;
  }

  function updateIcons(col, dir) {
    document.querySelectorAll('th.sortable').forEach(t => {
      const icon = t.querySelector('.sort-icon');
      if (!icon) return;
      if (t.dataset.col === col) {
        icon.className = 'bi sort-icon ' + (dir === 'asc' ? 'bi-sort-up-alt' : 'bi-sort-down-alt');
      } else {
        icon.className = 'bi sort-icon bi-arrow-down-up';
      }
    });
  }

  function sortSection(section, col, dir) {
    const table = section.querySelector('table');
    const th    = table && table.querySelector('th.sortable[data-col="' + col + '"]');
    if (!th) return; // this section has no such column (e.g. Index table)

    const tbody = table.querySelector('tbody');
    const rows  = [...tbody.querySelectorAll('tr.inv-row')];
    const d     = dir === 'asc' ? 1 : -1;

    rows.sort((a, b) => {
      const av = getSortVal(a, col);
      const bv = getSortVal(b, col);
      if (av === bv) return 0;
      if (av === null) return 1;
      if (bv === null) return -1;
      return typeof av === 'string' ? d * av.localeCompare(bv) : d * (av - bv);
    });

    rows.forEach(r => tbody.appendChild(r));
  }

  function applySort(col, dir) {
    state = { col, dir };
    localStorage.setItem(LS_KEY, JSON.stringify(state));
    updateIcons(col, dir);
    document.querySelectorAll('.portfolio-section').forEach(sec => sortSection(sec, col, dir));
  }

  document.addEventListener('click', e => {
    const th = e.target.closest('th.sortable');
    if (!th) return;
    const col = th.dataset.col;
    const dir = (state.col === col && state.dir === 'asc') ? 'desc' : 'asc';
    applySort(col, dir);
  });

  applySort(state.col, state.dir);
})();

// ── Price refresh ───────────────────────────────────────────────
async function refreshPrices(mode, investmentId, extraParams) {
  const btn = document.getElementById('btnRefreshPrices');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing…'; }

  const isSingle = investmentId > 0;
  const toast = showToast(
    '<i class="bi bi-arrow-clockwise spin"></i> <strong>Updating quotes…</strong><br>' +
    '<span class="text-muted" style="font-size:.8rem">' +
    (isSingle ? 'Fetching latest price' : 'Fetching latest prices from online provider') +
    '</span>',
    'loading'
  );

  const body = new URLSearchParams({
    csrf_token:    CSRF_TOKEN,
    mode:          mode || 'latest',
    investment_id: investmentId || 0,
    ...(extraParams || {}),
  });

  try {
    const res  = await fetch(BASE_PATH + '/portfolio/fetch_prices', { method: 'POST', body });
    const data = await res.json();
    if (data.ok) {
      const n    = data.updated || 0;
      const skip = data.skipped || 0;
      let msg = '<i class="bi bi-check-circle-fill" style="color:#198754"></i> '
              + '<strong>' + n + ' price' + (n !== 1 ? 's' : '') + ' updated</strong>';
      if (skip) msg += '<br><span class="text-muted" style="font-size:.8rem">' + skip + ' symbol' + (skip !== 1 ? 's' : '') + ' skipped</span>';
      toast.update(msg, 'success', { autoDismiss: 4000 });
      showFetchSummary(data, 'latest');
    } else {
      toast.update(
        '<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> ' +
        '<strong>Update failed</strong><br>' +
        '<span class="text-muted" style="font-size:.8rem">' + (data.error || 'Fetch failed') + '</span>',
        'error'
      );
      showPriceStatus(data.error || 'Fetch failed.', 'error');
    }
  } catch (e) {
    console.error(e);
    toast.update(
      '<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> <strong>Network error</strong>',
      'error'
    );
    showPriceStatus('Network error.', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh Prices'; }
  }
}

function showPriceStatus(msg, type) {
  const cls = type === 'success' ? 'text-success' : type === 'warning' ? 'text-warning' : 'text-danger';
  const el  = document.getElementById('priceStatusMsg') || (() => {
    const d = document.createElement('div');
    d.id = 'priceStatusMsg';
    d.className = 'price-status-msg';
    document.querySelector('.page-header').appendChild(d);
    return d;
  })();
  el.className = 'price-status-msg ' + cls;
  el.textContent = msg;
  el.style.display = '';
  setTimeout(() => { el.style.display = 'none'; }, 5000);
}

// ── Fetch summary modal ─────────────────────────────────────────
function showFetchSummary(data, mode, remember = true) {
  const results  = data.results || [];
  const errCount = results.filter(r => r.status === 'error').length;
  const fbCount  = results.filter(r => r.fallback).length;

  lastFetchSummaryPayload = data;
  lastFetchSummaryMode = mode === 'history' ? 'history' : 'latest';
  if (remember) saveFetchSummary(data, lastFetchSummaryMode);
  syncFetchSummaryButtons();

  // Totals line
  const totals = document.getElementById('fetchSummaryTotals');
  let totHtml = `<strong>${data.updated}</strong> price record${data.updated !== 1 ? 's' : ''} stored`;
  if (data.skipped) totHtml += `, <strong class="text-danger">${data.skipped}</strong> skipped`;
  if (fbCount)      totHtml += `, <strong class="text-warning">${fbCount}</strong> via Yahoo fallback`;
  totals.innerHTML = totHtml;

  // Per-symbol rows
  const tbody = document.getElementById('fetchSummaryBody');
  tbody.innerHTML = '';
  results.forEach(r => {
    const tr = document.createElement('tr');
    let srcHtml = r.source ? esc(r.source) : '<span class="text-muted">—</span>';
    let countHtml = `<span class="text-muted">${r.count ?? 0}</span>`;
    const quote = (r.last_quote !== null && r.last_quote !== undefined) ? Number(r.last_quote) : null;
    const changePct = (r.change_pct !== null && r.change_pct !== undefined) ? Number(r.change_pct) : null;
    const quoteHtml = quote === null || Number.isNaN(quote)
      ? '<span class="text-muted">—</span>'
      : `<span class="text-nowrap">${quote.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })}</span>`;
    const changeHtml = changePct === null || Number.isNaN(changePct)
      ? '<span class="text-muted">—</span>'
      : `<span class="inv-gain ${changePct >= 0 ? 'gain-pos' : 'gain-neg'}">${changePct >= 0 ? '+' : ''}${changePct.toFixed(2)}%</span>`;
    let statusHtml = r.status === 'ok'
      ? `<span class="text-success"><i class="bi bi-check-circle-fill"></i></span>`
      : `<span class="text-danger" title="${esc(r.message)}"><i class="bi bi-x-circle-fill"></i> ${esc(r.message)}</span>`;
    tr.innerHTML =
      `<td><span class="inv-symbol">${esc(r.symbol)}</span></td>` +
      `<td class="small text-muted">${esc(r.name)}</td>` +
      `<td class="small">${srcHtml}</td>` +
      `<td class="text-end small fetch-summary-col-records">${countHtml}</td>` +
      `<td class="text-end small">${quoteHtml}</td>` +
      `<td class="text-end small">${changeHtml}</td>` +
      `<td class="small">${statusHtml}</td>`;
    tbody.appendChild(tr);
  });

  // Show/hide Records column header based on mode
  const recVisible = mode === 'history';
  document.querySelectorAll('#fetchSummaryTable .fetch-summary-col-records').forEach(el => {
    el.style.display = recVisible ? '' : 'none';
  });

  // Hide history modal first if open, then show summary
  const histModal = bootstrap.Modal.getInstance(document.getElementById('historyModal'));
  if (histModal) histModal.hide();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('fetchSummaryModal')).show();
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── History modal ───────────────────────────────────────────────
function openHistoryModal() {
  document.getElementById('historyStatus').style.display = 'none';
  document.getElementById('btnHistoryFetch').disabled = false;
  document.getElementById('btnHistoryFetch').innerHTML = '<i class="bi bi-cloud-download"></i> Fetch';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('historyModal')).show();
}

async function fetchHistory() {
  const btn  = document.getElementById('btnHistoryFetch');
  const from = document.getElementById('histFrom').value;
  const to   = document.getElementById('histTo').value;
  const stat = document.getElementById('historyStatus');

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Fetching…';
  stat.style.display = 'none';

  const toast = showToast(
    '<i class="bi bi-cloud-download spin" style="animation-duration:.9s"></i> <strong>Fetching price history…</strong><br>' +
    '<span class="text-muted" style="font-size:.8rem">Downloading historical bars from ' + from + ' to ' + to + '</span>',
    'loading'
  );

  const body = new URLSearchParams({ csrf_token: CSRF_TOKEN, mode: 'history', from, to });
  try {
    const res  = await fetch(BASE_PATH + '/portfolio/fetch_prices', { method: 'POST', body });
    const data = await res.json();
    if (data.ok) {
      const n    = data.updated || 0;
      const skip = data.skipped || 0;
      let msg = '<i class="bi bi-check-circle-fill" style="color:#198754"></i> '
              + '<strong>' + n + ' price record' + (n !== 1 ? 's' : '') + ' stored</strong>';
      if (skip) msg += '<br><span class="text-muted" style="font-size:.8rem">' + skip + ' symbol' + (skip !== 1 ? 's' : '') + ' skipped</span>';
      toast.update(msg, 'success', { autoDismiss: 5000 });
      showFetchSummary(data, 'history');
    } else {
      toast.update(
        '<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> ' +
        '<strong>Fetch failed</strong><br>' +
        '<span class="text-muted" style="font-size:.8rem">' + (data.error || 'Unknown error') + '</span>',
        'error'
      );
      stat.style.display = '';
      stat.className = 'mt-2 small text-danger';
      stat.textContent = data.error || 'Fetch failed.';
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-cloud-download"></i> Fetch';
    }
  } catch (e) {
    console.error(e);
    toast.update(
      '<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> <strong>Network error</strong>',
      'error'
    );
    stat.style.display = '';
    stat.className = 'mt-2 small text-danger';
    stat.textContent = 'Network error.';
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-cloud-download"></i> Fetch';
  }
}

// ── Single-investment price fetch ───────────────────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('.inv-fetch-single');
  if (!btn) return;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  btn.disabled  = true;
  refreshPrices('latest', btn.dataset.id);
});

// ── Delete confirmation ─────────────────────────────────────────
function confirmDeleteInv(id, name, heldQty) {
  document.getElementById('confirmInvMsg').textContent = 'Deactivate "' + name + '"?';
  const warn = document.getElementById('confirmInvHoldWarn');
  if (heldQty > 0.000001) {
    document.getElementById('confirmInvQty').textContent =
      (+heldQty.toFixed(6)) + ' share' + (heldQty === 1 ? '' : 's');
    warn.style.display = '';
  } else {
    warn.style.display = 'none';
  }
  const btn   = document.getElementById('confirmInvBtn');
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', () => {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmInvModal')).hide();
    document.getElementById('deleteInvId').value = id;
    document.getElementById('deleteInvForm').submit();
  });
  bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmInvModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/price_history_modal.php'; ?>

<!-- Merge Investments Modal -->
<div class="modal fade" id="mergeInvModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-union"></i> Merge Investments</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body confirm-modal-body">
        <p class="text-muted small mb-3">
          Combine two duplicate investment records into one. All transactions and price history
          from the absorbed investment will be moved to the keeper, then the duplicate is removed.
        </p>
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1">Keep (primary)</label>
          <select class="form-select form-select-sm" id="mergeKeepSel"></select>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold mb-1">Absorb (will be deleted)</label>
          <select class="form-select form-select-sm" id="mergeAbsorbSel"></select>
        </div>
        <div id="mergeWarning" class="alert alert-warning small py-2 mb-0" style="display:none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          This cannot be undone. All transactions from the absorbed investment will be
          reassigned to the keeper and the duplicate entry will be permanently removed.
        </div>
        <div id="mergeError" class="text-danger small mt-2" style="display:none"></div>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="mergConfirmBtn" disabled>
          <i class="bi bi-union"></i> Merge
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  function buildInvLabel(inv) {
    return inv.name + (inv.symbol ? ' (' + inv.symbol + ')' : '');
  }

  function populateSel(sel, list, excludeId) {
    sel.innerHTML = '<option value="">— Select investment —</option>';
    list.forEach(inv => {
      if (inv.id === excludeId) return;
      const o = document.createElement('option');
      o.value = inv.id;
      o.textContent = buildInvLabel(inv);
      sel.appendChild(o);
    });
  }

  window.openMergeModal = function () {
    const keepSel   = document.getElementById('mergeKeepSel');
    const absorbSel = document.getElementById('mergeAbsorbSel');
    populateSel(keepSel,   ALL_INVESTMENTS, null);
    populateSel(absorbSel, ALL_INVESTMENTS, null);
    document.getElementById('mergeWarning').style.display  = 'none';
    document.getElementById('mergeError').style.display    = 'none';
    document.getElementById('mergConfirmBtn').disabled     = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mergeInvModal')).show();
  };

  function syncAbsorb() {
    const keepSel   = document.getElementById('mergeKeepSel');
    const absorbSel = document.getElementById('mergeAbsorbSel');
    const keepId    = parseInt(keepSel.value) || null;
    const prevAbsorb = parseInt(absorbSel.value) || null;
    populateSel(absorbSel, ALL_INVESTMENTS, keepId);
    // Restore prior absorb selection if still valid
    if (prevAbsorb && prevAbsorb !== keepId) absorbSel.value = prevAbsorb;
    syncConfirm();
  }

  function syncConfirm() {
    const keepId   = parseInt(document.getElementById('mergeKeepSel').value)   || 0;
    const absorbId = parseInt(document.getElementById('mergeAbsorbSel').value) || 0;
    const ready    = keepId > 0 && absorbId > 0 && keepId !== absorbId;
    document.getElementById('mergeWarning').style.display  = ready ? '' : 'none';
    document.getElementById('mergConfirmBtn').disabled     = !ready;
  }

  document.getElementById('mergeKeepSel').addEventListener('change',   syncAbsorb);
  document.getElementById('mergeAbsorbSel').addEventListener('change', syncConfirm);

  document.getElementById('mergConfirmBtn').addEventListener('click', async function () {
    const keepId   = parseInt(document.getElementById('mergeKeepSel').value)   || 0;
    const absorbId = parseInt(document.getElementById('mergeAbsorbSel').value) || 0;
    const btn      = this;
    btn.disabled   = true;
    btn.innerHTML  = '<span class="spinner-border spinner-border-sm"></span> Merging…';
    document.getElementById('mergeError').style.display = 'none';

    try {
      const body = new URLSearchParams({
        csrf_token: CSRF_TOKEN,
        keep_id:    keepId,
        absorb_id:  absorbId,
      });
      const res  = await fetch(BASE_PATH + '/portfolio/merge', { method: 'POST', body });
      const data = await res.json();
      if (data.ok) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mergeInvModal')).hide();
        location.reload();
      } else {
        document.getElementById('mergeError').textContent    = data.error || 'Merge failed.';
        document.getElementById('mergeError').style.display  = '';
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-union"></i> Merge';
      }
    } catch (e) {
      console.error(e);
      document.getElementById('mergeError').textContent    = 'Network error.';
      document.getElementById('mergeError').style.display  = '';
      btn.disabled  = false;
      btn.innerHTML = '<i class="bi bi-union"></i> Merge';
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

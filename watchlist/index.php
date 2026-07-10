<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$pageTitle   = 'Watchlist';
$currentPage = 'watchlist';

$db = getDB();
$stmt = $db->query(
    "SELECT * FROM investments WHERE is_active = 1 AND in_watchlist = 1 ORDER BY type, name"
);
$investments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$prices      = getLatestInvestmentPrices();
$priceProvider = getSetting('price_provider', 'manual');
$lastFetched   = getSetting('price_last_fetched');

$invGrouped  = [];
foreach ($investments as $inv) {
    $invGrouped[$inv['type']][] = $inv;
}
$invTypeOrder = ['Index','Stock','Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Cryptocurrency','Other'];

// Indices with price history — for comparison dropdown in price history modal
$phIndicesStmt = $db->query(
    "SELECT id, name, symbol FROM investments
     WHERE type = 'Index' AND is_active = 1
       AND id IN (SELECT DISTINCT investment_id FROM investment_prices)
     ORDER BY name"
);
$phIndices = $phIndicesStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>
<script>
const BASE_PATH  = '<?= BASE_PATH ?>';
const PH_INDICES = <?= json_encode(array_map(fn($i) => [
    'id'     => (int)$i['id'],
    'name'   => $i['name'],
    'symbol' => $i['symbol'],
], $phIndices)) ?>;
</script>

<div class="page-header">
  <h2><i class="bi bi-bookmark-star"></i> Watchlist</h2>
  <div class="d-flex align-items-center gap-2">
    <?php if ($priceProvider !== 'manual' && !empty($investments)): ?>
    <button class="btn btn-outline-secondary btn-sm" id="btnRefreshPrices" onclick="refreshPrices()">
      <i class="bi bi-arrow-clockwise"></i> Refresh Prices
    </button>
    <?php endif; ?>
    <?php if (canEdit()): ?>
    <button class="btn btn-primary btn-sm" onclick="openInvestmentModal({in_watchlist:1})">
      <i class="bi bi-plus-circle"></i> New Investment
    </button>
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
  <p class="text-muted mb-1">No investments on your watchlist yet.</p>
  <p class="text-muted small">
    To add an existing investment, edit it in
    <a href="<?= BASE_PATH ?>/portfolio/index">Portfolio</a> and check
    <strong>Add to watchlist</strong>.
    <?php if (canEdit()): ?>
    Or <a href="#" onclick="openInvestmentModal({in_watchlist:1});return false;">create a new one</a> tracked here only.
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
    <span class="badge bg-secondary ms-2"><?= count($rows) ?></span>
  </h4>
  <table class="table table-sm dash-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Symbol</th>
        <th>CUSIP</th>
        <th class="text-end">Price</th>
        <th class="text-end">Day Change</th>
        <th class="text-end">Change %</th>
        <th>Country</th>
        <th>Memo</th>
        <?php if (canEdit()): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $inv):
        $invId        = (int)$inv['id'];
        $priceRow     = $prices[$invId] ?? null;
        $latestPrice  = $priceRow['price']      ?? null;
        $prevClose    = $priceRow['prev_close'] ?? null;
        $dayChange    = ($latestPrice !== null && $prevClose !== null) ? $latestPrice - $prevClose : null;
        $dayChangePct = ($dayChange !== null && $prevClose > 0) ? ($dayChange / $prevClose) * 100 : null;
      ?>
      <tr>
        <td class="fw-medium">
          <?php $secSlug = !empty($inv['symbol']) ? urlencode($inv['symbol']) : $inv['id']; ?>
          <a href="<?= BASE_PATH ?>/portfolio/security/<?= $secSlug ?>" class="inv-name-link"><?= h($inv['name']) ?></a>
          <?php if (!empty($inv['symbol'])): ?>
          <a href="https://finance.yahoo.com/quote/<?= urlencode($inv['symbol']) ?>/"
             target="_blank" rel="noopener noreferrer"
             title="View <?= h($inv['symbol']) ?> on Yahoo Finance"
             class="yahoo-finance-link ms-1">
            <img src="<?= BASE_PATH ?>/assets/img/yahoo-finance.png" width="12" height="12" alt="Yahoo Finance" style="opacity:.65;vertical-align:baseline;">
          </a>
          <?php endif; ?>
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
          <button class="btn btn-sm btn-outline-secondary" title="Edit investment"
                  onclick="openInvestmentModal(<?= h(json_encode([
                      'id'             => $inv['id'],
                      'name'           => $inv['name'],
                      'symbol'         => $inv['symbol'],
                      'cusip'          => $inv['cusip'] ?? '',
                      'type'           => $inv['type'],
                      'country'        => $inv['country'],
                      'memo'           => $inv['memo'] ?? '',
                      'disable_quotes' => (int)($inv['disable_quotes'] ?? 0),
                      'in_watchlist'   => 1,
                  ])) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="btn btn-sm btn-outline-secondary ms-1 wl-remove-btn"
                  data-id="<?= $invId ?>"
                  data-name="<?= h($inv['name']) ?>"
                  title="Remove from watchlist">
            <i class="bi bi-bookmark-x"></i>
          </button>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/investment_modal.php'; ?>
<?php include __DIR__ . '/../includes/price_history_modal.php'; ?>

<script>
const CSRF_TOKEN = '<?= h(csrfToken()) ?>';

// ── Price refresh ────────────────────────────────────────────────
async function refreshPrices() {
  const btn = document.getElementById('btnRefreshPrices');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing…'; }

  const toast = showToast(
    '<i class="bi bi-arrow-clockwise spin"></i> <strong>Updating quotes…</strong>',
    'loading'
  );

  try {
    const body = new URLSearchParams({ csrf_token: CSRF_TOKEN, mode: 'latest', investment_id: 0 });
    const res  = await fetch(BASE_PATH + '/portfolio/fetch_prices', { method: 'POST', body });
    const data = await res.json();
    if (data.ok) {
      const n = data.updated || 0;
      toast.update('<i class="bi bi-check-circle-fill" style="color:#198754"></i> <strong>' + n + ' price' + (n !== 1 ? 's' : '') + ' updated</strong>', 'success', { autoDismiss: 3000 });
      if (n > 0) setTimeout(() => location.reload(), 1500);
    } else {
      toast.update('<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> <strong>Update failed</strong>', 'error');
    }
  } catch (e) {
    console.error(e);
    toast.update('<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> <strong>Network error</strong>', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh Prices'; }
  }
}

// ── Single-investment price fetch ────────────────────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('.inv-fetch-single');
  if (!btn) return;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  btn.disabled  = true;
  (async () => {
    try {
      const body = new URLSearchParams({ csrf_token: CSRF_TOKEN, mode: 'latest', investment_id: btn.dataset.id });
      const res  = await fetch(BASE_PATH + '/portfolio/fetch_prices', { method: 'POST', body });
      const data = await res.json();
      if (data.ok && data.updated > 0) location.reload();
      else { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-download"></i>'; }
    } catch (_) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cloud-download"></i>'; }
  })();
});

// ── Remove from watchlist ────────────────────────────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('.wl-remove-btn');
  if (!btn) return;
  const id   = btn.dataset.id;
  const name = btn.dataset.name;
  appConfirm(
    'Remove from Watchlist',
    `Remove "${name}" from your watchlist? The investment and its price history are kept — only the watchlist flag is cleared.`,
    null,
    async () => {
      try {
        const body = new URLSearchParams({ csrf_token: CSRF_TOKEN, investment_id: id });
        const res  = await fetch(BASE_PATH + '/watchlist/remove', { method: 'POST', body });
        const data = await res.json();
        if (data.ok) location.reload();
        else showToast(data.error || 'Failed to remove.', 'error');
      } catch (e) { console.error(e); showToast('Network error.', 'error'); }
    },
    'Remove'
  );
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
/**
 * Renders the report save/favourite dropdown button.
 *
 * Expects:
 *   $reportFavTitle   string  Default name for this report
 *   $reportFavIcon    string  Bootstrap icon class, e.g. 'bi-pie-chart'
 *
 * Optional flag:
 *   $reportFavDashOnly  bool  When true, renders only the "Add to Dashboard"
 *                             toggle (original single-button behaviour).
 *                             Set this in pages that provide their own
 *                             Save-As and Copy-Link controls (e.g. custom.php).
 *
 * Matches against the current REQUEST_URI (path + query, no domain).
 * The export=csv param is stripped so CSV clicks don't affect saved state.
 */

$_favRawQuery = $_SERVER['QUERY_STRING'] ?? '';
parse_str($_favRawQuery, $_favQ);
unset($_favQ['export'], $_favQ['saved_id'], $_favQ['fav_id']);
$_favPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$_favBase = defined('BASE_PATH') ? BASE_PATH : '';
if ($_favBase !== '' && str_starts_with($_favPath, $_favBase)) {
    $_favPath = substr($_favPath, strlen($_favBase));
}
$_favUrl  = $_favPath . (!empty($_favQ) ? '?' . http_build_query($_favQ) : '');
$_icon    = $reportFavIcon ?? 'bi-file-earmark-bar-graph';
$_title   = $reportFavTitle ?? 'Report';

// Check dashboard favourites
$_dashFavId = null;
foreach (getFavoriteReports() as $_f) {
    if ($_f['url'] === $_favUrl) { $_dashFavId = (int)$_f['id']; break; }
}
$_isDashFaved = $_dashFavId !== null;

// Check saved reports
$_savedFavId    = null;
$_savedFavTitle = null;
foreach (getSavedCustomReports() as $_s) {
    if ($_s['url'] === $_favUrl) { $_savedFavId = (int)$_s['id']; $_savedFavTitle = $_s['title']; break; }
}
$_isSavedFaved = $_savedFavId !== null;
$_anyFaved     = $_isDashFaved || $_isSavedFaved;

if (!empty($reportFavDashOnly)):
// ── Simple mode: single Add-to-Dashboard toggle ────────────────────────────
?>
<button type="button"
        id="btnFavReport"
        class="btn btn-sm <?= $_isDashFaved ? 'btn-warning' : 'btn-outline-secondary' ?>"
        data-faved="<?= $_isDashFaved ? '1' : '0' ?>"
        data-fav-id="<?= (int)$_dashFavId ?>"
        data-url="<?= h($_favUrl) ?>"
        data-title="<?= h($_title) ?>"
        data-icon="<?= h($_icon) ?>"
        data-csrf="<?= h(csrfToken()) ?>"
        onclick="toggleReportFav(this)">
  <i class="bi <?= $_isDashFaved ? 'bi-star-fill' : 'bi-star' ?>"></i>
  <span class="fav-btn-label"><?= $_isDashFaved ? 'On Dashboard' : 'Add to Dashboard' ?></span>
</button>
<script>
function toggleReportFav(btn) {
  const faved  = btn.dataset.faved === '1';
  const label  = btn.querySelector('.fav-btn-label');
  const icon   = btn.querySelector('.bi');
  const action = faved ? 'remove' : 'add';
  btn.disabled = true;
  const body = new URLSearchParams({
    csrf_token: btn.dataset.csrf, action,
    id: btn.dataset.favId, url: btn.dataset.url,
    title: btn.dataset.title, icon: btn.dataset.icon,
  });
  const graphCfg = typeof window.__reportGraphConfig === 'function' ? window.__reportGraphConfig() : null;
  if (graphCfg) body.append('graph_config', JSON.stringify(graphCfg));
  fetch('<?= BASE_PATH ?>/reports/favorite_save.php', { method:'POST', body })
    .then(r => r.json())
    .then(json => {
      if (!json.ok) { showToast(json.error || 'Error saving favourite.', 'error'); btn.disabled = false; return; }
      if (action === 'add') {
        btn.dataset.faved = '1'; btn.dataset.favId = json.id;
        btn.classList.replace('btn-outline-secondary','btn-warning');
        icon.classList.replace('bi-star','bi-star-fill');
        label.textContent = 'On Dashboard';
      } else {
        btn.dataset.faved = '0'; btn.dataset.favId = '';
        btn.classList.replace('btn-warning','btn-outline-secondary');
        icon.classList.replace('bi-star-fill','bi-star');
        label.textContent = 'Add to Dashboard';
      }
      btn.disabled = false;
    })
    .catch((e) => { console.error(e); showToast('Network error.', 'error'); btn.disabled = false; });
}
</script>
<?php else: ?>
<!-- ── Full mode: Save dropdown (Save As, Dashboard, Copy Link) ──────────── -->
<div class="btn-group" id="favBtnGroup">
  <button type="button"
          class="btn btn-sm <?= $_anyFaved ? 'btn-warning' : 'btn-outline-secondary' ?> dropdown-toggle"
          data-bs-toggle="dropdown" aria-expanded="false" id="favDropdownToggle"
          title="Save report options">
    <i class="bi <?= $_anyFaved ? 'bi-star-fill' : 'bi-star' ?>" id="favDropdownIcon"></i>
    <span class="d-none d-md-inline ms-1">Save</span>
  </button>
  <ul class="dropdown-menu dropdown-menu-end">
    <li>
      <button class="dropdown-item" onclick="favOpenSaveAs()">
        <i class="bi <?= $_isSavedFaved ? 'bi-bookmark-fill text-warning' : 'bi-bookmark' ?> me-2" id="favSavedIcon"></i><span id="favSavedLabel"><?= $_isSavedFaved ? h('Saved as: ' . ($_savedFavTitle ?? $_title)) : 'Save As Named Report…' ?></span>
      </button>
    </li>
    <li>
      <button class="dropdown-item" id="favDashItem"
              data-faved="<?= $_isDashFaved ? '1' : '0' ?>"
              data-fav-id="<?= (int)$_dashFavId ?>"
              onclick="favToggleDash(this)">
        <i class="bi <?= $_isDashFaved ? 'bi-grid-fill text-warning' : 'bi-grid' ?> me-2" id="favDashIcon"></i><span id="favDashLabel"><?= $_isDashFaved ? 'Remove from Dashboard' : 'Add to Dashboard' ?></span>
      </button>
    </li>
    <li><hr class="dropdown-divider"></li>
    <li>
      <button class="dropdown-item" onclick="favCopyLink()">
        <i class="bi bi-link-45deg me-2"></i>Copy Link
      </button>
    </li>
  </ul>
</div>

<!-- Save As modal -->
<div class="modal fade" id="favSaveAsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="bi bi-bookmark me-2"></i>Save Report</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-3">
        <label for="favSaveAsName" class="form-label small fw-semibold">Report Name</label>
        <input type="text" class="form-control form-control-sm" id="favSaveAsName"
               value="<?= h($_savedFavTitle ?? $_title) ?>" maxlength="150">
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-primary" id="favSaveAsSubmit">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const CSRF        = <?= json_encode(csrfToken()) ?>;
  const FAV_URL     = <?= json_encode($_favUrl) ?>;
  const FAV_ICON    = <?= json_encode($_icon) ?>;
  const DEFAULT_TTL = <?= json_encode($_title) ?>;
  let savedId   = <?= (int)$_savedFavId ?>;
  let dashId    = <?= (int)$_dashFavId ?>;
  let savedName = <?= json_encode($_savedFavTitle ?? $_title) ?>;

  function syncToggle() {
    const btn  = document.getElementById('favDropdownToggle');
    const icon = document.getElementById('favDropdownIcon');
    if (!btn) return;
    const active = savedId > 0 || dashId > 0;
    btn.classList.toggle('btn-warning', active);
    btn.classList.toggle('btn-outline-secondary', !active);
    icon.classList.toggle('bi-star-fill', active);
    icon.classList.toggle('bi-star', !active);
  }

  /* ── Save As ───────────────────────────────────────────────── */
  window.favOpenSaveAs = function () {
    new bootstrap.Modal(document.getElementById('favSaveAsModal')).show();
    const inp = document.getElementById('favSaveAsName');
    setTimeout(() => { inp.focus(); inp.select(); }, 300);
  };

  function doSaveAs() {
    const inp  = document.getElementById('favSaveAsName');
    const name = inp.value.trim();
    if (!name) { inp.focus(); return; }

    const action = savedId > 0 ? 'rename' : 'add';
    const params = new URLSearchParams({
      csrf_token: CSRF, action,
      id: savedId, url: FAV_URL,
      title: name, icon: FAV_ICON, type: 'saved',
    });
    const graphCfg = typeof window.__reportGraphConfig === 'function' ? window.__reportGraphConfig() : null;
    if (graphCfg) params.append('graph_config', JSON.stringify(graphCfg));
    fetch('<?= BASE_PATH ?>/reports/favorite_save.php', {
      method: 'POST',
      body: params,
    })
    .then(r => r.json())
    .then(json => {
      if (!json.ok) { showToast(json.error || 'Error saving.', 'error'); return; }
      savedId = json.id;
      savedName = name;
      document.getElementById('favSavedIcon').className = 'bi bi-bookmark-fill text-warning me-2';
      document.getElementById('favSavedLabel').textContent = 'Saved as: ' + name;
      syncToggle();
      bootstrap.Modal.getInstance(document.getElementById('favSaveAsModal')).hide();
      showToast('Report saved.', 'success');
    })
    .catch((e) => { console.error(e); showToast('Network error.', 'error'); });
  }

  document.getElementById('favSaveAsSubmit').addEventListener('click', doSaveAs);
  document.getElementById('favSaveAsName').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); doSaveAs(); }
  });

  /* ── Add / Remove from Dashboard ──────────────────────────── */
  window.favToggleDash = function (btn) {
    const faved  = btn.dataset.faved === '1';
    const action = faved ? 'remove' : 'add';
    btn.disabled = true;
    const dashParams = new URLSearchParams({
      csrf_token: CSRF, action,
      id: btn.dataset.favId, url: FAV_URL,
      title: savedName, icon: FAV_ICON, type: 'dashboard',
    });
    const graphCfg = typeof window.__reportGraphConfig === 'function' ? window.__reportGraphConfig() : null;
    if (graphCfg) dashParams.append('graph_config', JSON.stringify(graphCfg));
    fetch('<?= BASE_PATH ?>/reports/favorite_save.php', {
      method: 'POST',
      body: dashParams,
    })
    .then(r => r.json())
    .then(json => {
      if (!json.ok) { showToast(json.error || 'Error.', 'error'); btn.disabled = false; return; }
      if (action === 'add') {
        dashId = json.id;
        btn.dataset.faved = '1'; btn.dataset.favId = json.id;
        document.getElementById('favDashIcon').className  = 'bi bi-grid-fill text-warning me-2';
        document.getElementById('favDashLabel').textContent = 'Remove from Dashboard';
      } else {
        dashId = 0;
        btn.dataset.faved = '0'; btn.dataset.favId = '';
        document.getElementById('favDashIcon').className  = 'bi bi-grid me-2';
        document.getElementById('favDashLabel').textContent = 'Add to Dashboard';
      }
      syncToggle();
      btn.disabled = false;
    })
    .catch((e) => { console.error(e); showToast('Network error.', 'error'); btn.disabled = false; });
  };

  /* ── Copy Link ─────────────────────────────────────────────── */
  window.favCopyLink = function () {
    const url = window.location.origin + '<?= BASE_PATH ?>' + FAV_URL;
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(() => showToast('Link copied to clipboard.', 'success'));
    } else {
      const ta = document.createElement('textarea');
      ta.value = url; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove();
      showToast('Link copied to clipboard.', 'success');
    }
  };

  /* ── Show saved name in page heading ──────────────────────── */
  if (savedId > 0) {
    const h2 = document.querySelector('.page-header h2');
    if (h2) {
      for (const node of h2.childNodes) {
        if (node.nodeType === 3 && node.textContent.trim()) {
          const baseTitle = node.textContent.trim();
          node.textContent = ' ' + savedName;
          const sub = document.createElement('small');
          sub.className = 'ms-2 fw-normal text-muted';
          sub.style.fontSize = '0.6em';
          sub.textContent = '— ' + baseTitle;
          h2.appendChild(sub);
          document.title = savedName + ' — ' + document.title;
          break;
        }
      }
    }
  }
})();
</script>
<?php endif; ?>

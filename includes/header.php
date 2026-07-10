<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$currentPage  = $currentPage  ?? '';
$pageTitle    = $pageTitle    ?? APP_NAME;

// ── About modal data ────────────────────────────────────────
$_aboutStats = [];
if (isLoggedIn() && function_exists('getDB')) {
    try {
        $_adb = getDB();
        $_aboutStats['schema_version'] = getCurrentSchemaVersion();
        $_aboutStats['db_version']    = $_adb->query('SELECT VERSION()')->fetchColumn();
        $_aboutStats['db_name']       = $_adb->query('SELECT DATABASE()')->fetchColumn();
        $_aboutStats['db_size']       = (int)$_adb->query(
            "SELECT COALESCE(SUM(data_length + index_length),0)
               FROM information_schema.tables WHERE table_schema = DATABASE()"
        )->fetchColumn();
        $_aboutStats['accounts']      = (int)$_adb->query('SELECT COUNT(*) FROM accounts WHERE is_investment_cash=0 AND type != \'investment-cash\'')->fetchColumn();
        $_aboutStats['transactions']  = (int)$_adb->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $_aboutStats['investments']   = (int)$_adb->query('SELECT COUNT(*) FROM investments WHERE is_active=1')->fetchColumn();
        $_aboutStats['users']         = (int)$_adb->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();
    } catch (Exception $_e) {}
}
function _fmtBytes(int $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576,    2) . ' MB';
    if ($b >= 1024)       return round($b / 1024,       1) . ' KB';
    return $b . ' B';
}

$_baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
          . BASE_PATH;

$_sidebarBalMode    = function_exists('getSetting') ? (getSetting('sidebar_balance') ?: 'ending') : 'ending';
$_sidebarHideCash      = function_exists('getSetting') && getSetting('sidebar_hide_investment_cash')       === '1';
$_sidebarCashInBal     = function_exists('getSetting') && getSetting('sidebar_cash_in_investment_balance') === '1';
$_navHideLoans      = function_exists('getSetting') && getSetting('nav_hide_loans')       === '1';
$_navHideGoals      = function_exists('getSetting') && getSetting('nav_hide_goals')       === '1';
$_navSearchIconOnly = function_exists('getSetting') && getSetting('nav_search_icon_only') === '1';
$sidebarAccounts  = isLoggedIn() ? getAllAccountsWithBalance($_sidebarBalMode === 'current' ? date('Y-m-d') : null) : [];
?>
<!DOCTYPE html>
<?php
$colorScheme = function_exists('getSetting') ? (getSetting('color_scheme') ?: 'blue') : 'blue';
$validSchemes = ['blue','green','red','gray','brown'];
if (!in_array($colorScheme, $validSchemes, true)) $colorScheme = 'blue';
?>
<html lang="en"<?= $colorScheme !== 'blue' ? ' data-theme="' . $colorScheme . '"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> — <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/jpeg" href="<?= BASE_PATH ?>/assets/img/logo.jpg">
<link rel="apple-touch-icon" href="<?= BASE_PATH ?>/assets/img/logo.jpg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/money.css">
</head>
<?php $_negFmt = getSetting('negative_format', 'color'); ?>
<body<?= $_negFmt === 'parens-bw' ? ' class="neg-no-color"' : '' ?>>
<?php unset($_negFmt); ?>

<?php if (isLoggedIn()): ?>
<!-- ── Top Navigation Bar ──────────────────────────────────── -->
<nav class="topbar">
  <div class="topbar-brand" role="button" onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('aboutModal')).show()" title="About <?= h(APP_NAME) ?>" style="cursor:pointer">
    <img src="<?= BASE_PATH ?>/assets/img/logo.jpg" alt="<?= h(APP_NAME) ?>" class="topbar-logo">
    <div class="topbar-brand-text">
      <span class="topbar-app-name"><?= h(APP_NAME) ?></span>
      <?php
      $instanceName = function_exists('getSetting') ? getSetting('instance_name') : null;
      if (!empty($instanceName)):
      ?>
      <span class="topbar-instance-name"><?= h($instanceName) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <ul class="topbar-nav">
    <li class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/index"><i class="bi bi-house-door"></i> Dashboard</a>
    </li>
    <li class="<?= $currentPage === 'accounts' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/accounts/index"><i class="bi bi-wallet2"></i> Accounts</a>
    </li>
    <?php if (!$_navHideLoans): ?>
    <li class="<?= $currentPage === 'loans' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/loans/index"><i class="bi bi-cash-coin"></i> Loans</a>
    </li>
    <?php endif; ?>
    <li class="<?= $currentPage === 'categories' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/categories/index"><i class="bi bi-tags"></i> Categories</a>
    </li>
    <li class="<?= $currentPage === 'payees' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/payees/index"><i class="bi bi-person-lines-fill"></i> Payees</a>
    </li>
    <li class="<?= $currentPage === 'bills' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/bills/index"><i class="bi bi-calendar-check"></i> Bills</a>
    </li>
    <li class="<?= $currentPage === 'budget' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/budget/index"><i class="bi bi-bar-chart-line"></i> Budget</a>
    </li>
    <?php if (!$_navHideGoals): ?>
    <li class="<?= $currentPage === 'goals' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/goals/index"><i class="bi bi-piggy-bank"></i> Goals</a>
    </li>
    <?php endif; ?>
    <li class="<?= $currentPage === 'forecast' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/forecast/index"><i class="bi bi-graph-up"></i> Forecast</a>
    </li>
    <li class="<?= $currentPage === 'reports' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/reports/index"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
    </li>
    <li class="<?= $currentPage === 'portfolio' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/portfolio/index"><i class="bi bi-briefcase"></i> Portfolio</a>
    </li>
    <li class="<?= $currentPage === 'watchlist' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/watchlist/index"><i class="bi bi-bookmark-star"></i> Watchlist</a>
    </li>
    <li class="<?= $currentPage === 'search' ? 'active' : '' ?>">
      <a href="<?= BASE_PATH ?>/transactions/search"<?= $_navSearchIconOnly ? ' title="Search"' : '' ?>><i class="bi bi-search"></i><?= $_navSearchIconOnly ? '' : ' Search' ?></a>
    </li>
  </ul>
  <div class="topbar-user">
    <div class="topbar-settings-wrap">
      <a href="<?= BASE_PATH ?>/help.html" target="_blank" class="topbar-settings-toggle" title="Help">
        <i class="bi bi-question-circle"></i>
      </a>
    </div>
    <?php if (!isAdmin() && function_exists('canImport') && canImport()): ?>
    <div class="topbar-settings-wrap">
      <a href="<?= BASE_PATH ?>/import/index"
         class="topbar-settings-toggle<?= $currentPage === 'import' ? ' active' : '' ?>"
         title="Import Transactions">
        <i class="bi bi-upload"></i>
      </a>
    </div>
    <?php endif; ?>
    <?php if (isAdmin()):
      // Cache pending-migration count in session for 5 min to avoid per-request queries
      $__migPending = 0;
      if (function_exists('getPendingMigrations')) {
          $__migCacheKey = '_mig_pending_count';
          $__migCacheTs  = '_mig_pending_ts';
          if (!isset($_SESSION[$__migCacheKey]) || (time() - ($_SESSION[$__migCacheTs] ?? 0)) > 300) {
              $_SESSION[$__migCacheKey] = count(getPendingMigrations());
              $_SESSION[$__migCacheTs]  = time();
          }
          $__migPending = (int)$_SESSION[$__migCacheKey];
      }
    ?>
    <div class="topbar-settings-wrap nav-dropdown <?= in_array($currentPage, ['users','settings','import','backup','preferences','migrations','maintenance']) ? 'active' : '' ?>">
      <a href="#" class="topbar-settings-toggle nav-dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Settings">
        <i class="bi bi-gear"></i><?php if ($__migPending > 0): ?> <span class="badge bg-warning text-dark" style="font-size:.6rem;vertical-align:middle;padding:2px 5px"><?= $__migPending ?></span><?php endif; ?> <i class="bi bi-chevron-down nav-dropdown-caret"></i>
      </a>
      <ul class="dropdown-menu dropdown-menu-end nav-dropdown-menu">
        <li>
          <a href="<?= BASE_PATH ?>/users/index" class="dropdown-item<?= $currentPage === 'users' ? ' active' : '' ?>">
            <i class="bi bi-people"></i> Users
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/settings/index" class="dropdown-item<?= $currentPage === 'settings' ? ' active' : '' ?>">
            <i class="bi bi-gear"></i> Online Services
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/settings/preferences" class="dropdown-item<?= $currentPage === 'preferences' ? ' active' : '' ?>">
            <i class="bi bi-sliders"></i> Preferences
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/import/index" class="dropdown-item<?= $currentPage === 'import' ? ' active' : '' ?>">
            <i class="bi bi-upload"></i> Import Transactions
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/settings/backup" class="dropdown-item<?= $currentPage === 'backup' ? ' active' : '' ?>">
            <i class="bi bi-database"></i> Backup / Restore
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/settings/migrations" class="dropdown-item<?= $currentPage === 'migrations' ? ' active' : '' ?>">
            <i class="bi bi-database-gear"></i> Migrations<?php if ($__migPending > 0): ?> <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem"><?= $__migPending ?></span><?php endif; ?>
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/settings/activity_log" class="dropdown-item<?= $currentPage === 'activity_log' ? ' active' : '' ?>">
            <i class="bi bi-clock-history"></i> Activity Log
          </a>
        </li>
        <li>
          <a href="<?= BASE_PATH ?>/settings/maintenance" class="dropdown-item<?= $currentPage === 'maintenance' ? ' active' : '' ?>">
            <i class="bi bi-tools"></i> Integrity / Maintenance
          </a>
        </li>
      </ul>
    </div>
    <?php endif; ?>
    <span class="user-info">
      <i class="bi bi-person-circle"></i>
      <span class="user-info-text">
        <span class="user-name"><?= h($_SESSION['user_fullname'] ?? $_SESSION['username'] ?? '') ?></span>
        <span class="badge role-badge role-<?= h(currentUserRole()) ?>"><?= h(currentUserRole()) ?></span>
      </span>
    </span>
    <a href="<?= BASE_PATH ?>/logout" class="btn-logout" title="Sign Out">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</nav>

<!-- ── About Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="aboutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--ms-blue);color:#fff;padding:.75rem 1.25rem">
        <div class="d-flex align-items-center gap-2">
          <img src="<?= BASE_PATH ?>/assets/img/logo.jpg" alt="" style="width:32px;height:32px;border-radius:6px;object-fit:cover">
          <div>
            <div class="fw-bold" style="font-size:1rem;line-height:1.1"><?= h(APP_NAME) ?></div>
            <div style="font-size:.75rem;opacity:.85">Version <?= h(APP_VERSION) ?></div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:1.25rem">
        <table class="table table-sm table-borderless mb-0" style="font-size:.875rem">
          <tbody>
            <?php if (!empty($instanceName)): ?>
            <tr>
              <td class="text-muted fw-semibold" style="width:45%">Instance</td>
              <td><?= h($instanceName) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
              <td class="text-muted fw-semibold">Base URL</td>
              <td style="word-break:break-all"><?= h($_baseUrl) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Schema Version</td>
              <td>v<?= str_pad((string)($_aboutStats['schema_version'] ?? APP_SCHEMA_VERSION), 3, '0', STR_PAD_LEFT) ?></td>
            </tr>
            <tr><td colspan="2"><hr class="my-1"></td></tr>
            <?php if (!empty($_aboutStats['db_name'])): ?>
            <tr>
              <td class="text-muted fw-semibold">Database</td>
              <td><?= h($_aboutStats['db_name']) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Database Size</td>
              <td><?= _fmtBytes($_aboutStats['db_size'] ?? 0) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">DB Engine</td>
              <td><?= h($_aboutStats['db_version'] ?? '—') ?></td>
            </tr>
            <tr><td colspan="2"><hr class="my-1"></td></tr>
            <tr>
              <td class="text-muted fw-semibold">Accounts</td>
              <td><?= number_format($_aboutStats['accounts'] ?? 0) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Transactions</td>
              <td><?= number_format($_aboutStats['transactions'] ?? 0) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Investments</td>
              <td><?= number_format($_aboutStats['investments'] ?? 0) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Active Users</td>
              <td><?= number_format($_aboutStats['users'] ?? 0) ?></td>
            </tr>
            <tr><td colspan="2"><hr class="my-1"></td></tr>
            <?php endif; ?>
            <tr>
              <td class="text-muted fw-semibold">PHP</td>
              <td><?= h(PHP_VERSION) ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Server</td>
              <td><?= h($_SERVER['SERVER_SOFTWARE'] ?? '—') ?></td>
            </tr>
            <tr><td colspan="2"><hr class="my-1"></td></tr>
            <tr>
              <td class="text-muted fw-semibold">Contact</td>
              <td><a href="mailto:steward@7312.us">steward@7312.us</a></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">License</td>
              <td><a href="<?= BASE_PATH ?>/help.html#license" target="_blank">Personal Use License</a></td>
            </tr>
          </tbody>
        </table>
        <div class="d-grid mt-3">
          <a href="https://www.paypal.com/donate/?hosted_button_id=5NBZHD74FTF5Q"
             target="_blank" rel="noopener" class="btn btn-outline-primary">
            <i class="bi bi-heart-fill"></i> Donate
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Page wrapper ────────────────────────────────────────── -->
<div class="page-wrapper">

  <!-- ── Left Sidebar ───────────────────────────────────────── -->
  <aside class="sidebar">
    <?php if (canEdit()): ?>
    <a href="<?= BASE_PATH ?>/accounts/create" class="btn-new-account">
      <i class="bi bi-plus-circle"></i> New Account
    </a>
    <?php endif; ?>

    <?php
    $grouped   = [];
    $cashById  = []; // investment account id => cash account row
    foreach ($sidebarAccounts as $acc) {
        if ($acc['type'] === 'investment-cash') {
            if ($acc['linked_account_id']) {
                $cashById[(int)$acc['linked_account_id']] = $acc;
            }
            continue;
        }
        if (!empty($acc['hide_from_sidebar'])) continue;
        $groupKey = ($acc['type'] === 'Investment' && !empty($acc['is_retirement']))
            ? 'Retirement' : $acc['type'];
        $grouped[$groupKey][] = $acc;
    }
    $typeOrder = ['Checking', 'Savings', 'Credit Card', 'Investment', 'Retirement', 'Crypto', 'Asset', 'Loan'];
    $typeIcons = [
        'Checking'    => 'bi-bank',
        'Savings'     => 'bi-piggy-bank',
        'Credit Card' => 'bi-credit-card',
        'Investment'  => 'bi-graph-up-arrow',
        'Retirement'  => 'bi-umbrella',
        'Crypto'      => 'bi-currency-bitcoin',
        'Asset'       => 'bi-safe2',
        'Loan'        => 'bi-cash-coin',
    ];
    $orderedGroups = [];
    foreach ($typeOrder as $_t) { if (isset($grouped[$_t])) $orderedGroups[$_t] = $grouped[$_t]; }
    foreach ($grouped as $_t => $_a) { if (!isset($orderedGroups[$_t])) $orderedGroups[$_t] = $_a; }
    foreach ($orderedGroups as $type => $accs):
    ?>
    <div class="sidebar-group" data-group="<?= h($type) ?>">
      <div class="sidebar-group-title">
        <i class="bi <?= $typeIcons[$type] ?? 'bi-wallet2' ?>"></i> <?= h($type) ?>
      </div>
      <?php foreach ($accs as $acc):
        $bal    = (float)$acc['current_balance'];
        if (in_array($type, ['Investment', 'Retirement', 'Crypto']) && $_sidebarHideCash && $_sidebarCashInBal && isset($cashById[(int)$acc['id']])) {
            $bal += (float)$cashById[(int)$acc['id']]['current_balance'];
        }
        $balCls = round($bal, MONEY_DECIMALS) < 0 ? 'neg' : 'pos';
        $active = (isset($currentAccountId) && $currentAccountId == $acc['id']) ? ' active' : '';
      ?>
      <div class="sidebar-acct-row" data-id="<?= $acc['id'] ?>" draggable="true">
        <?php if (canEdit()): ?><span class="sidebar-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span><?php endif; ?>
        <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acc['id'] ?>" class="sidebar-account<?= $active ?>">
          <span class="acct-name">
            <?php if ($acc['is_favorite']): ?><i class="bi bi-star-fill text-warning" title="Favorite"></i><?php endif; ?>
            <?= h($acc['name']) ?>
            <?php if (!empty($acc['exclude_from_net_worth'])): ?><i class="bi bi-slash-circle sidebar-excl-nw" title="Excluded from net worth"></i><?php endif; ?>
          </span>
          <span class="acct-bal <?= $balCls ?>"><?= formatMoney($bal) ?></span>
        </a>
        <?php if (in_array($type, ['Investment', 'Retirement', 'Crypto']) && !$_sidebarHideCash && isset($cashById[(int)$acc['id']])):
          $cash       = $cashById[(int)$acc['id']];
          $cashBal    = (float)$cash['current_balance'];
          $cashActive = (isset($currentAccountId) && $currentAccountId == $cash['id']) ? ' active' : '';
        ?>
        <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $cash['id'] ?>" class="sidebar-account sidebar-cash-account<?= $cashActive ?>">
          <span class="acct-name">
            <i class="bi bi-cash-coin sidebar-cash-icon"></i> <?= h($cash['name']) ?>
          </span>
          <span class="acct-bal <?= round($cashBal, MONEY_DECIMALS) < 0 ? 'neg' : 'pos' ?>"><?= formatMoney($cashBal) ?></span>
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Net Worth summary -->
    <?php
    $totalAssets = 0; $totalLiabilities = 0;
    foreach ($sidebarAccounts as $acc) {
        if (!empty($acc['exclude_from_net_worth'])) continue;
        $b = (float)$acc['current_balance'];
        if ($acc['type'] === 'Credit Card') {
            if ($b < 0) $totalLiabilities += abs($b);
            else $totalAssets += $b;
        } else {
            if ($b >= 0) $totalAssets += $b;
            else $totalLiabilities += abs($b);
        }
    }
    $netWorth = $totalAssets - $totalLiabilities;
    ?>
    <div class="sidebar-net-worth">
      <div class="nw-row"><span>Assets</span><span class="pos"><?= formatMoney($totalAssets) ?></span></div>
      <div class="nw-row"><span>Liabilities</span><span class="neg"><?= formatMoney($totalLiabilities > 0 ? -$totalLiabilities : 0) ?></span></div>
      <div class="nw-row nw-total"><span>Net Worth</span><span class="<?= $netWorth >= 0 ? 'pos' : 'neg' ?>"><?= formatMoney($netWorth) ?></span></div>
    </div>
  </aside>
  <?php if (canEdit()): ?>
  <script>
  (function() {
    const CSRF     = <?= json_encode(csrfToken()) ?>;
    const BASE     = <?= json_encode(BASE_PATH) ?>;
    const sidebar  = document.querySelector('.sidebar');
    if (!sidebar) return;
    let dragEl = null, canDrag = false;
    sidebar.addEventListener('mousedown', e => { canDrag = !!e.target.closest('.sidebar-drag-handle'); });
    sidebar.addEventListener('dragstart', e => {
      const row = e.target.closest('.sidebar-acct-row[data-id]');
      if (!row || !canDrag) { e.preventDefault(); return; }
      dragEl = row; e.dataTransfer.effectAllowed = 'move';
      setTimeout(() => row.classList.add('sortable-drag'), 0);
    });
    sidebar.addEventListener('dragover', e => {
      if (!dragEl) return;
      const row = e.target.closest('.sidebar-acct-row[data-id]');
      if (!row || row === dragEl) return;
      if (row.closest('.sidebar-group') !== dragEl.closest('.sidebar-group')) return;
      e.preventDefault();
      sidebar.querySelectorAll('.sidebar-acct-row.sortable-over').forEach(r => r.classList.remove('sortable-over'));
      row.classList.add('sortable-over');
    });
    sidebar.addEventListener('drop', e => {
      e.preventDefault();
      const row = e.target.closest('.sidebar-acct-row[data-id]');
      sidebar.querySelectorAll('.sidebar-acct-row.sortable-over').forEach(r => r.classList.remove('sortable-over'));
      if (!row || !dragEl || row === dragEl) return;
      if (row.closest('.sidebar-group') !== dragEl.closest('.sidebar-group')) return;
      const group = dragEl.closest('.sidebar-group');
      const all   = [...group.querySelectorAll('.sidebar-acct-row[data-id]')];
      if (all.indexOf(dragEl) < all.indexOf(row)) group.insertBefore(dragEl, row.nextSibling);
      else group.insertBefore(dragEl, row);
      const ids = [...group.querySelectorAll('.sidebar-acct-row[data-id]')].map(r => r.dataset.id).join(',');
      fetch(BASE + '/accounts/reorder', {
        method: 'POST',
        body: new URLSearchParams({ csrf_token: CSRF, ids })
      }).catch(() => {});
    });
    sidebar.addEventListener('dragend', () => {
      if (dragEl) dragEl.classList.remove('sortable-drag');
      sidebar.querySelectorAll('.sidebar-acct-row.sortable-over').forEach(r => r.classList.remove('sortable-over'));
      dragEl = null; canDrag = false;
    });
  })();
  </script>
  <?php endif; ?>

  <!-- ── Main Content ────────────────────────────────────────── -->
  <main class="main-content">
    <?= renderFlash() ?>
    <?php if (isAdmin() && isset($__migPending) && $__migPending > 0 && $currentPage !== 'migrations'): ?>
    <div class="alert alert-warning alert-dismissible d-flex align-items-center gap-2 mb-3 py-2 d-print-none" role="alert">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div class="me-auto"><strong><?= $__migPending ?> pending database migration<?= $__migPending !== 1 ? 's' : '' ?>.</strong> The schema is behind the application version.</div>
      <a href="<?= BASE_PATH ?>/settings/migrations" class="btn btn-sm btn-warning">Apply Now</a>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
<?php else:
    $__loginBgStyle = '';
    $__loginBgTs    = getSetting('login_bg');
    $__loginBgFile  = __DIR__ . '/../assets/img/login_bg_custom.jpg';
    if ($__loginBgTs && file_exists($__loginBgFile)) {
        $__loginBgStyle = ' style="background-image:url(\'' . BASE_PATH . '/assets/img/login_bg_custom.jpg?v=' . rawurlencode($__loginBgTs) . '\')"';
    }
?>
<main class="main-content login-bg"<?= $__loginBgStyle ?>>
<?php endif; ?>

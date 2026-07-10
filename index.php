<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if (isAdmin() && !getSetting('instance_name')) {
    header('Location: ' . BASE_PATH . '/setup.php');
    exit;
}

$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';

$userId = currentUserId();

const WIDGET_DEFAULT_ORDER = [
    'fav_accounts', 'fav_reports',
    'recent_transactions', 'monthly_spending', 'budget',
    'upcoming_bills', 'all_accounts',
    'key_indicators', 'goals', 'loans',
    'market_indexes', 'top_movers', 'portfolio_movers', 'most_active',
    'crypto', 'watchlist',
    'portfolio_snapshot', 'asset_allocation',
    'bookmarks', 'notepad',
];

$savedOrder  = getUserPref($userId, 'dashboard_order',  null);
$savedHidden = getUserPref($userId, 'dashboard_hidden', null);

// Account filters
$_rawAcctRecent = getUserPref($userId, 'dashboard_acct_recent', null) ?? '';
$_rawAcctSpend  = getUserPref($userId, 'dashboard_acct_spend',  null) ?? '';
$acctIdsRecent  = $_rawAcctRecent !== '' ? array_values(array_filter(array_map('intval', explode(',', $_rawAcctRecent)))) : [];
$acctIdsSpend   = $_rawAcctSpend  !== '' ? array_values(array_filter(array_map('intval', explode(',', $_rawAcctSpend))))  : [];

// Data
$favorites       = getFavoriteAccounts();
$allAccounts     = getAllAccountsWithBalance();
$recentTxns      = getRecentTransactions(8, $acctIdsRecent);
$year            = (int)date('Y');
$month           = (int)date('n');
$monthlySpend    = getMonthlySpending($year, $month, $acctIdsSpend);
$monthName       = date('F Y');
$upcomingBills   = getUpcomingBillsByRange(180);
$dashboardBudget = getBudgetDashboardItems();
$favoriteReports = getFavoriteReports();
$totalSpend      = array_sum(array_column($monthlySpend, 'total'));


$pickerAccounts = array_values(array_filter($allAccounts, fn($a) => $a['type'] !== 'investment-cash'));

// Apply user-defined sort to favorite accounts
$favAcctOrder = getUserPref($userId, 'fav_accounts_order', '');
if ($favAcctOrder) {
    $_ordIds = array_values(array_filter(array_map('intval', explode(',', $favAcctOrder))));
    $_byId   = array_column($favorites, null, 'id');
    $_sorted = [];
    foreach ($_ordIds as $_aid) {
        if (isset($_byId[$_aid])) { $_sorted[] = $_byId[$_aid]; unset($_byId[$_aid]); }
    }
    foreach ($_byId as $_acc) $_sorted[] = $_acc;
    $favorites = $_sorted;
}

$widgetOrder = $savedOrder !== null && $savedOrder !== ''
    ? array_values(array_filter(explode(',', $savedOrder), fn($w) => in_array($w, WIDGET_DEFAULT_ORDER, true)))
    : WIDGET_DEFAULT_ORDER;

foreach (WIDGET_DEFAULT_ORDER as $w) {
    if (!in_array($w, $widgetOrder, true)) $widgetOrder[] = $w;
}

$hiddenWidgets = $savedHidden !== null && $savedHidden !== ''
    ? array_values(array_filter(explode(',', $savedHidden), fn($w) => in_array($w, WIDGET_DEFAULT_ORDER, true)))
    : [];

// Hide certain widgets by default only for users who have never configured their dashboard
// ($savedHidden === null means no saved layout at all; '' means explicitly reset/cleared)
if ($savedHidden === null) {
    foreach (['portfolio_snapshot', 'asset_allocation', 'notepad', 'watchlist'] as $_newW) {
        if (!in_array($_newW, $hiddenWidgets, true)) {
            $userSavedOrder = $savedOrder ?? '';
            if (!in_array($_newW, array_map('trim', explode(',', $userSavedOrder)), true)) {
                $hiddenWidgets[] = $_newW;
            }
        }
    }
}

// Always render hidden widgets at the end so visible tiles sort cleanly
$widgetOrder = array_merge(
    array_values(array_filter($widgetOrder, fn($w) => !in_array($w, $hiddenWidgets, true))),
    array_values(array_filter($widgetOrder, fn($w) =>  in_array($w, $hiddenWidgets, true)))
);

// Widget data (only fetch when widget is visible)
$_visSet      = array_flip(array_diff($widgetOrder, $hiddenWidgets));
$dashNetWorth      = isset($_visSet['key_indicators']) ? getDashboardNetWorth(12)      : null;
$dashNetWorthToday = isset($_visSet['key_indicators']) ? getDashboardNetWorthToday()    : null;
$dashCashFlow      = isset($_visSet['key_indicators']) ? getDashboardCashFlow(6)        : null;
$dashGoals    = isset($_visSet['goals'])     ? getDashboardGoals()      : null;
$dashLoans    = isset($_visSet['loans'])     ? getDashboardLoans()      : null;
$dashWatchlist = isset($_visSet['watchlist']) ? getDashboardWatchlist() : null;

$spendWidgetData = isset($_visSet['monthly_spending']) ? [
    'month'      => $monthlySpend,
    'last_month' => getDashboardSpending('last_month', $acctIdsSpend),
    'year'       => getDashboardSpending('year',       $acctIdsSpend),
    '90days'     => getDashboardSpending('90days',     $acctIdsSpend),
] : null;

$dashBookmarks  = isset($_visSet['bookmarks']) ? getUserBookmarks() : [];

$dashNotepad = null;
if (isset($_visSet['notepad'])) {
    $db = getDB();
    $dashNotepad = $db->query(
        "SELECT dn.content, dn.updated_at, u.full_name AS updated_by_name
         FROM dashboard_notes dn
         LEFT JOIN users u ON u.id = dn.updated_by
         WHERE dn.id = 1"
    )->fetch() ?: ['content' => '', 'updated_at' => null, 'updated_by_name' => null];
}

$_investWidgets = ['market_indexes', 'top_movers', 'portfolio_movers', 'most_active', 'crypto', 'portfolio_snapshot', 'asset_allocation'];
$dashPortfolio  = !empty(array_intersect(['market_indexes', 'top_movers', 'portfolio_movers', 'most_active', 'crypto'], array_keys($_visSet)))
    ? getDashboardPortfolioSnapshot() : null;

// Investment accounts for portfolio_snapshot and asset_allocation widget filters
$allInvestmentAccounts = array_values(array_filter($allAccounts, fn($a) => ($a['type'] ?? '') === 'Investment' && !($a['is_investment_cash'] ?? false)));

// Portfolio Snapshot widget prefs
$_psAcctPref = isset($_visSet['portfolio_snapshot']) ? (getUserPref($userId, 'dashboard_ps_accts', '')   ?? '') : '';
$_psExclPref = isset($_visSet['portfolio_snapshot']) ? (getUserPref($userId, 'dashboard_ps_exclude', '') ?? '') : '';
$_psAcctIds  = $_psAcctPref !== '' ? array_map('intval', explode(',', $_psAcctPref)) : [];

// Asset Allocation widget pref
$_aaView = getUserPref($userId, 'dashboard_aa_view', 'type') ?? 'type';

// Sort preferences for widgets with sortable columns (format: "colIdx:asc|desc")
$dashSortPrefs = [
    'watchlist'        => getUserPref($userId, 'dashboard_sort_watchlist', null),
    'top_movers'       => getUserPref($userId, 'dashboard_sort_top_movers', null),
    'portfolio_movers' => getUserPref($userId, 'dashboard_sort_portfolio_movers', null),
];
$priceProvider    = getSetting('price_provider', 'manual');
$priceLastFetched = getSetting('price_last_fetched');

// Online services (price provider) configuration status
$priceProviderKeySettings = ['massive' => 'massive_api_key', 'alphavantage' => 'alphavantage_api_key'];
$onlineServicesConfigured = $priceProvider !== 'manual'
    && (!isset($priceProviderKeySettings[$priceProvider]) || getSetting($priceProviderKeySettings[$priceProvider], '') !== '');

// Precomputed for today period on both Top Movers widgets
$todayMoverRows = $dashPortfolio !== null
    ? array_values(array_map(fn($r) => [
        'id'      => (int)$r['id'],
        'name'    => $r['name'],
        'symbol'  => $r['symbol'],
        'type'    => $r['type'],
        'qty'     => (float)$r['qty'],
        'price'   => (float)$r['price'],
        'chg'     => $r['day_chg'],
        'chg_pct' => $r['day_chg_pct'],
        'mkt_val' => (float)$r['mkt_val'],
        'val_chg' => $r['val_chg'],
      ], array_filter($dashPortfolio, fn($r) => $r['type'] !== 'Index')))
    : [];

$widgetLabels = [
    'fav_accounts'        => 'Favorite Accounts',
    'fav_reports'         => 'Favorite Reports',
    'recent_transactions' => 'Recent Transactions',
    'monthly_spending'    => 'Spending',
    'budget'              => 'Budget — ' . $monthName,
    'upcoming_bills'      => 'Upcoming Bills & Deposits',
    'all_accounts'        => 'All Accounts',
    'key_indicators'      => 'Key Indicators',
    'goals'               => 'Savings Goals',
    'loans'               => 'Loan Payoff',
    'market_indexes'      => 'Market Indexes',
    'top_movers'          => 'Top Movers — Price %',
    'portfolio_movers'    => 'Top Movers — Portfolio Value',
    'most_active'         => 'Most Active Today',
    'crypto'              => 'Crypto',
    'watchlist'           => 'Watchlist',
    'portfolio_snapshot'  => 'Portfolio Snapshot',
    'asset_allocation'    => 'Asset Allocation',
    'bookmarks'           => 'Bookmarks',
    'notepad'             => 'Notepad',
];

$widgetIcons = [
    'fav_accounts'        => 'bi-star-fill',
    'fav_reports'         => 'bi-bookmark-star-fill',
    'recent_transactions' => 'bi-clock-history',
    'monthly_spending'    => 'bi-pie-chart',
    'budget'              => 'bi-bar-chart-line',
    'upcoming_bills'      => 'bi-calendar-check',
    'all_accounts'        => 'bi-wallet2',
    'key_indicators'      => 'bi-speedometer2',
    'goals'               => 'bi-piggy-bank',
    'loans'               => 'bi-bank2',
    'market_indexes'      => 'bi-graph-up',
    'top_movers'          => 'bi-lightning-charge',
    'portfolio_movers'    => 'bi-currency-dollar',
    'most_active'         => 'bi-activity',
    'crypto'              => 'bi-currency-bitcoin',
    'watchlist'           => 'bi-binoculars-fill',
    'portfolio_snapshot'  => 'bi-bar-chart-fill',
    'asset_allocation'    => 'bi-pie-chart-fill',
    'bookmarks'           => 'bi-bookmarks-fill',
    'notepad'             => 'bi-journal-text',
];

include __DIR__ . '/includes/header.php';

// ── Widget wrapper ─────────────────────────────────────────────
function widgetWrap(string $id, string $label, string $icon, bool $hidden, string $headerAction, array $filterAccts, array $selAcctIds, string $inner): void {
    $hidClass = $hidden ? ' dash-tile-hidden' : '';
    $selJson  = h(json_encode(array_values($selAcctIds)));

    echo '<div class="dash-tile' . $hidClass . '" data-widget="' . h($id) . '" data-accts="' . $selJson . '">';

    // Header
    echo '<div class="dash-tile-header">';
    echo '<span class="dash-tile-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>';
    echo '<span class="dash-tile-title"><i class="bi ' . h($icon) . '"></i> ' . h($label) . '</span>';
    echo '<div class="dash-tile-actions">';
    if ($headerAction) echo $headerAction;
    echo '<button class="dash-tile-max-btn" title="Maximize" onclick="maximizeWidget(this)">';
    echo '<i class="bi bi-arrows-fullscreen"></i>';
    echo '</button>';
    echo '<button class="dash-tile-gear-btn" title="Widget settings" onclick="toggleGearPanel(this)">';
    echo '<i class="bi bi-gear-fill"></i>';
    echo '</button>';
    echo '</div>'; // /.dash-tile-actions

    // Gear panel
    echo '<div class="dash-tile-gear-panel">';
    echo '<button class="dash-gear-item" onclick="toggleWidgetHidden(this)">';
    echo '<i class="bi ' . ($hidden ? 'bi-eye' : 'bi-eye-slash') . '"></i> ';
    echo $hidden ? 'Show on dashboard' : 'Hide from dashboard';
    echo '</button>';
    if (!empty($filterAccts)) {
        echo '<div class="dash-gear-divider"></div>';
        echo '<div class="dash-gear-filter-wrap">';
        echo '<div class="dash-filter-header">';
        echo '<span class="dash-filter-title"><i class="bi bi-funnel"></i> Filter Accounts</span>';
        echo '<button type="button" class="dash-filter-quick-btn" onclick="selectAllAccts(this)">All</button>';
        echo '<button type="button" class="dash-filter-quick-btn" onclick="selectNoAccts(this)">None</button>';
        echo '</div>';
        echo '<div class="dash-filter-acct-list">';
        foreach ($filterAccts as $acc) {
            $checked = (empty($selAcctIds) || in_array((int)$acc['id'], $selAcctIds)) ? ' checked' : '';
            echo '<label class="dash-filter-acct-item">';
            echo '<input type="checkbox" value="' . (int)$acc['id'] . '"' . $checked . '> ';
            echo h($acc['name']);
            echo '</label>';
        }
        echo '</div>'; // /.dash-filter-acct-list
        echo '<button class="dash-gear-apply-btn" onclick="saveLayout(this)"><i class="bi bi-check-lg"></i> Apply & Save</button>';
        echo '</div>'; // /.dash-gear-filter-wrap
    }
    echo '</div>'; // /.dash-tile-gear-panel
    echo '</div>'; // /.dash-tile-header

    // Body
    echo '<div class="dash-tile-body">' . $inner . '</div>';
    echo '</div>'; // /.dash-tile
}
?>

<div class="dashboard" id="dashboardRoot">

  <!-- ── Page header ──────────────────────────────────────────── -->
  <div class="page-header">
    <h2><i class="bi bi-house-door"></i> Dashboard</h2>
    <span class="text-muted ms-3"><?= date('l, F j, Y') ?></span>
    <?php if (!$onlineServicesConfigured): ?>
    <span class="text-warning ms-3" title="No price provider is configured in Online Services">
      <i class="bi bi-exclamation-triangle-fill"></i> Online services not configured
      <?php if (isAdmin()): ?><a href="<?= BASE_PATH ?>/settings/index">Set up</a><?php endif; ?>
    </span>
    <?php elseif (!$priceLastFetched): ?>
    <span class="text-muted ms-3"><i class="bi bi-clock-history"></i> No quotes updated</span>
    <?php else: ?>
    <span class="text-muted ms-3"><i class="bi bi-clock"></i> Last updated: <?= h(date('M j, Y g:i A', strtotime($priceLastFetched))) ?></span>
    <?php endif; ?>
    <button id="dashReorderBtn" class="btn btn-sm btn-outline-secondary ms-auto" onclick="enterReorder()">
      <i class="bi bi-arrows-move"></i> Reorder
    </button>
  </div>

  <!-- ── Reorder mode banner ──────────────────────────────────── -->
  <div id="dashReorderBar" class="dash-reorder-bar d-none">
    <i class="bi bi-arrows-move"></i>
    <span>Drag tiles to reorder &nbsp;·&nbsp; Hidden tiles shown faded — use ⚙ to restore</span>
    <div class="ms-auto d-flex gap-2">
      <button class="btn btn-sm btn-outline-danger"  onclick="resetLayout()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      <button class="btn btn-sm btn-primary"          onclick="saveLayout()"><i class="bi bi-check-lg"></i> Done</button>
    </div>
  </div>

  <!-- ── Widget grid ───────────────────────────────────────────── -->
  <div id="dashGrid">

  <?php
  $hiddenSet = array_flip($hiddenWidgets);

  foreach ($widgetOrder as $widgetId):
      $isHidden = isset($hiddenSet[$widgetId]);
      ob_start();
      switch ($widgetId):

          // ── Favorite Accounts ──────────────────────────────────
          case 'fav_accounts': ?>
    <?php if (empty($favorites)): ?>
      <p class="text-muted small">No favorite accounts. <a href="<?= BASE_PATH ?>/accounts/index">Mark an account as favorite.</a></p>
    <?php else: ?>
    <div class="account-cards">
      <?php foreach ($favorites as $acc):
        $bal      = (float)$acc['current_balance'];
        $isCc     = $acc['type'] === 'Credit Card';
        $balCls   = round($bal, MONEY_DECIMALS) < 0 ? 'neg' : 'pos';
        $typeIcon = ['Checking' => 'bi-bank', 'Savings' => 'bi-piggy-bank', 'Credit Card' => 'bi-credit-card', 'Investment' => 'bi-graph-up-arrow'][$acc['type']] ?? 'bi-wallet2';
      ?>
      <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acc['id'] ?>" class="account-card" data-id="<?= (int)$acc['id'] ?>">
        <div class="acct-card-icon"><i class="bi <?= $typeIcon ?>"></i></div>
        <div class="acct-card-body">
          <div class="acct-card-name"><?= h($acc['name']) ?></div>
          <div class="acct-card-inst"><?= h($acc['institution']) ?></div>
          <div class="acct-card-type"><?= h($acc['type']) ?></div>
        </div>
        <div class="acct-card-balance <?= $balCls ?>">
          <span class="balance-label"><?= ($isCc && round($bal, MONEY_DECIMALS) < 0) ? 'Balance Owed' : 'Balance' ?></span>
          <span class="balance-amount"><?= formatMoney($bal) ?></span>
          <?php if ($acc['min_balance'] > 0 && $bal < $acc['min_balance']): ?>
            <span class="badge bg-warning text-dark mt-1"><i class="bi bi-exclamation-triangle"></i> Below Min</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
          <?php break;

          // ── Favorite Reports ───────────────────────────────────
          case 'fav_reports': ?>
    <?php if (empty($favoriteReports)): ?>
      <p class="text-muted small">No favorite reports. <a href="<?= BASE_PATH ?>/reports/index">Browse reports.</a></p>
    <?php else: ?>
    <div class="fav-reports-grid">
      <?php foreach ($favoriteReports as $fr): ?>
      <?php
        $_frUrl = $fr['url'];
        if (!empty($fr['graph_config']) && str_contains($_frUrl, '/reports/custom')) {
            $_frUrl .= (str_contains($_frUrl, '?') ? '&' : '?') . 'fav_id=' . (int)$fr['id'];
        }
      ?>
      <div class="fav-report-card" data-id="<?= (int)$fr['id'] ?>">
        <a href="<?= BASE_PATH . h($_frUrl) ?>" class="fav-report-link">
          <i class="bi <?= h($fr['icon']) ?> fav-report-icon"></i>
          <span class="fav-report-title"><?= h($fr['title']) ?></span>
        </a>
        <?php if (canEdit()): ?>
        <button class="fav-report-remove" title="Remove from dashboard"
                data-id="<?= (int)$fr['id'] ?>"
                data-csrf="<?= h(csrfToken()) ?>"
                onclick="removeFavReport(this)">
          <i class="bi bi-x-lg"></i>
        </button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
          <?php break;

          // ── Recent Transactions ────────────────────────────────
          case 'recent_transactions': ?>
    <?php if (empty($recentTxns)): ?>
      <p class="text-muted">No transactions yet.</p>
    <?php else: ?>
    <table class="table table-sm dash-table">
      <thead><tr>
        <th>Date</th><th>Account</th><th>Payee</th><th class="text-end">Amount</th>
      </tr></thead>
      <tbody>
        <?php foreach ($recentTxns as $txn):
          $amt    = (float)$txn['amount'];
          $amtCls = round($amt, MONEY_DECIMALS) < 0 ? 'amount-debit' : 'amount-credit';
        ?>
        <tr>
          <td class="text-nowrap"><?= formatDate($txn['transaction_date']) ?></td>
          <td><a href="<?= BASE_PATH ?>/accounts/register?id=<?= $txn['account_id'] ?>"><?= h($txn['account_name']) ?></a></td>
          <td><?= h($txn['payee']) ?></td>
          <td class="text-end"><span class="<?= $amtCls ?>"><?= formatMoney($amt, true) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
          <?php break;

          // ── Monthly Spending ───────────────────────────────────
          case 'monthly_spending':
            $spd = $spendWidgetData ?? [
                'month'      => $monthlySpend,
                'last_month' => getDashboardSpending('last_month', $acctIdsSpend),
                'year'       => getDashboardSpending('year',       $acctIdsSpend),
                '90days'     => getDashboardSpending('90days',     $acctIdsSpend),
            ]; ?>
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
      <select class="form-select form-select-sm" style="width:auto;min-width:120px"
              onchange="renderSpending(this.value)">
        <option value="month">This Month</option>
        <option value="last_month">Last Month</option>
        <option value="year">This Year</option>
        <option value="90days">Last 90 Days</option>
      </select>
      <div class="spend-view-btns ms-auto">
        <button class="spend-view-btn active" onclick="setSpendView('bar',this)" title="Bar view"><i class="bi bi-bar-chart-horizontal"></i></button>
        <button class="spend-view-btn" onclick="setSpendView('pie',this)" title="Pie chart"><i class="bi bi-pie-chart-fill"></i></button>
      </div>
      <div class="dropdown" id="spendAcctDrop">
        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                id="spendAcctBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                aria-expanded="false">
          <span id="spendAcctLabel"><?= empty($acctIdsSpend) ? 'All Accounts' : count($acctIdsSpend) . (count($acctIdsSpend) === 1 ? ' Account' : ' Accounts') ?></span>
        </button>
        <div class="dropdown-menu p-2" style="min-width:210px;max-height:220px;overflow-y:auto">
          <?php foreach ($pickerAccounts as $acc):
            $chk = (empty($acctIdsSpend) || in_array((int)$acc['id'], $acctIdsSpend)) ? ' checked' : '';
          ?>
          <label class="dash-filter-acct-item">
            <input type="checkbox" value="<?= (int)$acc['id'] ?>"<?= $chk ?>>
            <?= h($acc['name']) ?>
          </label>
          <?php endforeach; ?>
          <div class="d-flex gap-1 mt-2 border-top pt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="spendSelectAll(true)">All</button>
            <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" onclick="spendSelectAll(false)">None</button>
            <button type="button" class="btn btn-sm btn-primary flex-fill" onclick="applySpendAccts()">Apply</button>
          </div>
        </div>
      </div>
    </div>
    <div id="spendingTrackerBody"></div>
    <canvas id="spendPieCanvas" style="display:none;max-height:240px;margin:0 auto"></canvas>
    <script>
    (function(){
      const spBase    = <?= json_encode(BASE_PATH) ?>;
      const initData  = <?= json_encode(array_map(fn($rows) => array_values($rows), $spd)) ?>;
      const totalAccts = <?= count($pickerAccounts) ?>;
      let dirty        = false;
      let currentPeriod = 'month';
      let viewMode      = 'bar';
      let pieChart      = null;

      const PIE_COLORS = [
        '#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f',
        '#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac',
        '#d37295','#fabfd2','#8cd17d','#86bcb6','#499894',
      ];

      function fmt(n){ return '$'+parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
      function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

      function getChecked() {
        return [...document.querySelectorAll('#spendAcctDrop input[type=checkbox]:checked')].map(cb => +cb.value);
      }

      function destroyPie() {
        if (pieChart) { pieChart.destroy(); pieChart = null; }
      }

      function renderRows(rows) {
        const canvas = document.getElementById('spendPieCanvas');
        const body   = document.getElementById('spendingTrackerBody');
        destroyPie();
        if (canvas) canvas.style.display = 'none';
        if (!body) return;
        if (!rows.length) { body.innerHTML = '<p class="text-muted small">No spending for this period.</p>'; body.style.display = ''; return; }
        const total = rows.reduce((s,r) => s + parseFloat(r.total), 0);
        let html = '<div class="spending-tracker">';
        rows.forEach(r => {
          const pct = total > 0 ? (parseFloat(r.total) / total * 100) : 0;
          html += '<div class="spend-row">'
            + '<div class="spend-label"><span class="spend-cat">'+esc(r.category)+'</span><span class="spend-amt">'+fmt(r.total)+'</span></div>'
            + '<div class="progress spend-bar"><div class="progress-bar" style="width:'+pct.toFixed(1)+'%"></div></div>'
            + '</div>';
        });
        html += '</div>';
        body.innerHTML = html;
        body.style.display = '';
      }

      function renderPie(rows) {
        const canvas = document.getElementById('spendPieCanvas');
        const body   = document.getElementById('spendingTrackerBody');
        destroyPie();
        if (body) { body.innerHTML = ''; body.style.display = 'none'; }
        if (!canvas) return;
        if (!rows.length) {
          if (body) { body.innerHTML = '<p class="text-muted small">No spending for this period.</p>'; body.style.display = ''; }
          canvas.style.display = 'none'; return;
        }
        canvas.style.display = 'block';
        if (typeof Chart === 'undefined') {
          if (body) { body.innerHTML = '<p class="text-muted small">Chart library not loaded.</p>'; body.style.display = ''; }
          canvas.style.display = 'none'; return;
        }
        const labels = rows.map(r => r.category);
        const data   = rows.map(r => parseFloat(r.total));
        const colors = labels.map((_, i) => PIE_COLORS[i % PIE_COLORS.length]);
        pieChart = new Chart(canvas, {
          type: 'pie',
          data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 1 }] },
          options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
              legend: { position: 'right', labels: { font: { size: 11 }, boxWidth: 12, padding: 6 } },
              tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + fmt(ctx.raw) } }
            }
          }
        });
      }

      function render(rows) {
        if (viewMode === 'pie') renderPie(rows);
        else renderRows(rows);
      }

      async function loadSpending() {
        if (!dirty) { render(initData[currentPeriod] || []); return; }
        const accts  = getChecked();
        if (accts.length === 0) { render([]); return; }
        const params = new URLSearchParams({ period: currentPeriod });
        if (accts.length < totalAccts) params.set('acct_ids', accts.join(','));
        try {
          const resp = await fetch(spBase + '/dashboard/spending_data.php?' + params);
          const data = await resp.json();
          if (data.ok) render(data.rows);
        } catch(e) {
          const body = document.getElementById('spendingTrackerBody');
          if (body) { body.innerHTML = '<p class="text-danger small">Error loading data.</p>'; body.style.display = ''; }
        }
      }

      window.renderSpending = function(period) { currentPeriod = period; loadSpending(); };

      window.setSpendView = function(mode, btn) {
        viewMode = mode;
        document.querySelectorAll('.spend-view-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        loadSpending();
      };

      window.spendSelectAll = function(all) {
        document.querySelectorAll('#spendAcctDrop input[type=checkbox]').forEach(cb => cb.checked = all);
      };

      window.applySpendAccts = function() {
        dirty = true;
        const menu = document.querySelector('#spendAcctDrop .dropdown-menu');
        const btn  = document.getElementById('spendAcctBtn');
        if (menu) menu.classList.remove('show');
        if (btn)  { btn.classList.remove('show'); btn.setAttribute('aria-expanded','false'); }
        document.getElementById('spendAcctDrop')?.classList.remove('show');
        const sel = getChecked();
        const lbl = document.getElementById('spendAcctLabel');
        if (lbl) lbl.textContent = (sel.length === 0 || sel.length >= totalAccts) ? 'All Accounts' : sel.length + (sel.length === 1 ? ' Account' : ' Accounts');
        loadSpending();
      };

      render(initData['month'] || []);
    })();
    </script>
          <?php break;

          // ── Budget ─────────────────────────────────────────────
          case 'budget': ?>
    <?php if (!empty($dashboardBudget)): ?>
    <?php
    $budgetSectionLabels = ['income' => 'Income', 'expense' => 'Expenses', 'transfer' => 'Transfers'];
    $currentSection = null;
    ?>
    <div class="dbw-item-list">
      <?php foreach ($dashboardBudget as $item):
        $barCls = $item['raw_pct'] === null ? 'bar-none'
                : ($item['raw_pct'] > 100    ? 'bar-over'
                : ($item['raw_pct'] >= 80    ? 'bar-warn' : 'bar-ok'));
        $section = $item['category_type'] ?? 'expense';
        if ($section !== $currentSection):
            $currentSection = $section;
        ?>
      <div class="dbw-section-label dbw-section-<?= h($section) ?>">
        <?= h($budgetSectionLabels[$section] ?? ucfirst($section)) ?>
      </div>
      <?php endif; ?>
      <div class="dbw-item">
        <div class="dbw-item-name"><?= h($item['name']) ?></div>
        <div class="dbw-item-bar">
          <div class="budget-bar-track">
            <div class="budget-bar-fill <?= $barCls ?>" style="width:<?= round($item['pct'] ?? 0, 1) ?>%"></div>
          </div>
        </div>
        <div class="dbw-item-amounts">
          <span class="<?= ($item['raw_pct'] !== null && $item['raw_pct'] > 100) ? 'amount-debit' : '' ?>">
            <?= formatMoney($item['actual']) ?>
          </span>
          <?php if ($item['budgeted'] > 0): ?>
          <span class="dbw-item-sep">/</span>
          <span class="dbw-item-budget"><?= formatMoney($item['budgeted']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($item['raw_pct'] !== null): ?>
        <div class="dbw-item-pct budget-pct <?= $barCls ?>"><?= round($item['raw_pct']) ?>%</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted">No budget items configured. <a href="<?= BASE_PATH ?>/budget/index">Set up a budget.</a></p>
    <?php endif; ?>
          <?php break;

          // ── Upcoming Bills ─────────────────────────────────────
          case 'upcoming_bills': ?>
    <div class="d-flex align-items-center mb-2">
      <select id="billRangeSelect" class="form-select form-select-sm" style="width:auto;min-width:150px">
        <option value="7">Next 7 days</option>
        <option value="30" selected>Next 30 days</option>
        <option value="month">This month</option>
        <option value="180">Next 6 months</option>
      </select>
    </div>
    <div id="upcomingBillsBody"><!-- filled by JS --></div>
          <?php break;

          // ── All Accounts ───────────────────────────────────────
          case 'all_accounts': ?>
    <?php
    $grouped = [];
    foreach ($allAccounts as $acc) $grouped[$acc['type']][] = $acc;
    foreach ($grouped as $type => $accs):
        $groupTotal    = array_sum(array_map(fn($a) => (float)$a['current_balance'], $accs));
        $groupTotalCls = round($groupTotal, MONEY_DECIMALS) < 0 ? 'amount-debit' : 'amount-credit';
    ?>
    <div class="acct-group-header"><?= h($type) ?></div>
    <table class="table table-sm dash-table mb-3">
      <thead><tr><th>Account</th><th>Institution</th><th>Currency</th><th class="text-end">Balance</th></tr></thead>
      <tbody>
        <?php foreach ($accs as $acc):
          $bal    = (float)$acc['current_balance'];
          $balCls = round($bal, MONEY_DECIMALS) < 0 ? 'amount-debit' : 'amount-credit';
        ?>
        <tr>
          <td>
            <?php if ($acc['is_favorite']): ?><i class="bi bi-star-fill text-warning"></i><?php endif; ?>
            <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acc['id'] ?>"><?= h($acc['name']) ?></a>
          </td>
          <td><?= h($acc['institution']) ?></td>
          <td><?= h($acc['currency']) ?></td>
          <td class="text-end"><span class="<?= $balCls ?>"><?= formatMoney($bal) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="acct-group-total-row">
          <td colspan="3" class="text-end fw-semibold">Total</td>
          <td class="text-end fw-semibold"><span class="<?= $groupTotalCls ?>"><?= formatMoney($groupTotal) ?></span></td>
        </tr>
      </tfoot>
    </table>
    <?php endforeach; ?>
          <?php break;

          // ── Key Indicators ─────────────────────────────────────
          case 'key_indicators':
            $nw  = $dashNetWorth ?? getDashboardNetWorth(12);
            $nwT = $dashNetWorthToday ?? getDashboardNetWorthToday();
            $cf = $dashCashFlow ?? getDashboardCashFlow(6);
            $avgInc = !empty($cf['income'])   ? array_sum($cf['income'])   / count($cf['income'])   : null;
            $avgExp = !empty($cf['expenses']) ? array_sum($cf['expenses']) / count($cf['expenses']) : null;
            $avgNet = ($avgInc !== null && $avgExp !== null) ? $avgInc - $avgExp : null; ?>
    <div class="ki-section">
      <div class="ki-section-title"><i class="bi bi-graph-up-arrow"></i> Net Worth</div>
      <?php if (empty($nw['labels'])): ?>
        <p class="text-muted small mb-0">No account data.</p>
      <?php else:
        $nwChgCls   = $nw['change'] >= 0 ? 'amount-credit' : 'amount-debit';
        $nwChgIcon  = $nw['change'] >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right';
        $nwTodayCls  = $nwT['change'] >= 0 ? 'amount-credit' : 'amount-debit';
        $nwTodayIcon = $nwT['change'] >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right';
      ?>
      <div class="ki-row">
        <span class="ki-label">Current</span>
        <span class="ki-value"><?= formatMoney($nw['current']) ?></span>
      </div>
      <div class="ki-row">
        <span class="ki-label">Today's Change</span>
        <span class="ki-value <?= $nwTodayCls ?>">
          <i class="bi <?= $nwTodayIcon ?>"></i>
          <?= ($nwT['change'] >= 0 ? '+' : '') . formatMoney(abs($nwT['change'])) ?>
        </span>
      </div>
      <div class="ki-row">
        <span class="ki-label">12-mo Change</span>
        <span class="ki-value <?= $nwChgCls ?>">
          <i class="bi <?= $nwChgIcon ?>"></i>
          <?= ($nw['change'] >= 0 ? '+' : '') . formatMoney(abs($nw['change'])) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>
    <div class="ki-divider"></div>
    <div class="ki-section">
      <div class="ki-section-title"><i class="bi bi-arrow-left-right"></i> Cash Flow <span class="ki-section-note">(6-mo avg)</span></div>
      <?php if ($avgInc === null): ?>
        <p class="text-muted small mb-0">No transaction data.</p>
      <?php else: ?>
      <div class="ki-row">
        <span class="ki-label">Avg Income/mo</span>
        <span class="ki-value amount-credit"><?= formatMoney($avgInc) ?></span>
      </div>
      <div class="ki-row">
        <span class="ki-label">Avg Expenses/mo</span>
        <span class="ki-value amount-debit"><?= formatMoney($avgExp) ?></span>
      </div>
      <div class="ki-row ki-row-total">
        <span class="ki-label">Avg Net/mo</span>
        <span class="ki-value <?= $avgNet >= 0 ? 'amount-credit' : 'amount-debit' ?>">
          <?= ($avgNet >= 0 ? '+' : '') . formatMoney(abs($avgNet)) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>
    <?php
      $ki30 = array_filter($upcomingBills, fn($b) => strtotime($b['next_due_date']) <= strtotime('+30 days'));
      $ki30Bills    = array_sum(array_map(fn($b) => $b['type'] !== 'deposit' ? (float)$b['amount'] : 0, $ki30));
      $ki30Deposits = array_sum(array_map(fn($b) => $b['type'] === 'deposit' ? (float)$b['amount'] : 0, $ki30));
      $ki30Net      = $ki30Deposits - $ki30Bills;
    ?>
    <div class="ki-divider"></div>
    <div class="ki-section">
      <div class="ki-section-title"><i class="bi bi-calendar-check"></i> Next 30 Days <span class="ki-section-note">(bills &amp; deposits)</span></div>
      <?php if (empty($ki30)): ?>
        <p class="text-muted small mb-0">No bills or deposits due in the next 30 days.</p>
      <?php else: ?>
      <div class="ki-row">
        <span class="ki-label"><i class="bi bi-arrow-up-circle text-danger" style="font-size:.8rem"></i> Bills</span>
        <span class="ki-value amount-debit"><?= formatMoney($ki30Bills) ?></span>
      </div>
      <div class="ki-row">
        <span class="ki-label"><i class="bi bi-arrow-down-circle text-success" style="font-size:.8rem"></i> Deposits</span>
        <span class="ki-value amount-credit"><?= formatMoney($ki30Deposits) ?></span>
      </div>
      <div class="ki-row ki-row-total">
        <span class="ki-label">Net</span>
        <span class="ki-value <?= $ki30Net >= 0 ? 'amount-credit' : 'amount-debit' ?>">
          <?= ($ki30Net >= 0 ? '+' : '') . formatMoney(abs($ki30Net)) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>
          <?php break;

          // ── Savings Goals ──────────────────────────────────────
          case 'goals':
            $goals = $dashGoals ?? getDashboardGoals();
            $today = date('Y-m-d'); ?>
    <?php if (empty($goals)): ?>
      <p class="text-muted small">No savings goals yet.
        <?php if (canEdit()): ?><a href="<?= BASE_PATH ?>/goals/index">Create your first goal.</a><?php endif; ?>
      </p>
    <?php else: ?>
    <?php foreach ($goals as $g):
      $target    = (float)$g['target_amount'];
      $current   = max(0, (float)$g['effective_current']);
      $pct       = $target > 0 ? min(100, round($current / $target * 100, 1)) : 0;
      $remaining = max(0, $target - $current);
      $done      = $remaining < 0.01;
      $barCls    = $done ? 'bg-success' : ($pct >= 75 ? 'bg-info' : ($pct >= 50 ? 'bg-primary' : 'bg-warning'));
      $dueLabel  = '';
      $dueCls    = 'text-muted';
      if ($g['target_date']) {
          $daysLeft = (int)round((strtotime($g['target_date']) - strtotime($today)) / 86400);
          if ($done)           { $dueLabel = 'Goal reached!'; $dueCls = 'text-success fw-semibold'; }
          elseif ($daysLeft < 0) { $dueLabel = abs($daysLeft).'d overdue'; $dueCls = 'text-danger'; }
          elseif ($daysLeft === 0) { $dueLabel = 'Due today'; $dueCls = 'text-warning fw-semibold'; }
          elseif ($daysLeft <= 30) { $dueLabel = 'Due in '.$daysLeft.'d'; $dueCls = 'text-warning'; }
          else { $dueLabel = date('M Y', strtotime($g['target_date'])); }
      }
    ?>
    <div class="dash-goal-item">
      <div class="dash-goal-header">
        <span class="dash-goal-name"><?= h($g['name']) ?></span>
        <?php if ($dueLabel): ?><span class="dash-goal-due <?= $dueCls ?>"><?= h($dueLabel) ?></span><?php endif; ?>
      </div>
      <div class="progress dash-goal-bar" title="<?= $pct ?>%">
        <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%"></div>
      </div>
      <div class="dash-goal-amounts">
        <span class="<?= $done ? 'amount-credit' : '' ?>"><?= formatMoney($current) ?></span>
        <span class="text-muted"> / <?= formatMoney($target) ?></span>
        <span class="dash-goal-pct"><?= $pct ?>%</span>
        <?php if (!$done && $remaining > 0): ?>
          <span class="text-muted ms-auto small"><?= formatMoney($remaining) ?> to go</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
          <?php break;

          // ── Loan Payoff ────────────────────────────────────────
          case 'loans':
            $loans = $dashLoans ?? getDashboardLoans(); ?>
    <?php if (empty($loans)): ?>
      <p class="text-muted small">No loans found.
        <?php if (canEdit()): ?><a href="<?= BASE_PATH ?>/loans/index">Add a loan account.</a><?php endif; ?>
      </p>
    <?php else: ?>
    <?php foreach ($loans as $loan):
      $original  = (float)$loan['original_amount'];
      $balance   = max(0, -(float)$loan['current_balance']); // loans are negative balances
      if ($balance <= 0) $balance = max(0, (float)$loan['current_balance']);
      $paid      = max(0, $original - $balance);
      $pct       = $original > 0 ? min(100, round($paid / $original * 100, 1)) : 0;
      $payment   = (float)$loan['payment_amount'];
      $rate      = (float)$loan['annual_rate'];
      // Estimate months remaining
      $monthsLeft = 0;
      if ($balance > 0 && $payment > 0) {
          if ($rate <= 0) {
              $monthsLeft = (int)ceil($balance / $payment);
          } else {
              $r = $rate / 100 / 12;
              $monthsLeft = $r > 0 ? (int)ceil(-log(1 - $balance * $r / $payment) / log(1 + $r)) : 0;
          }
      }
      $barCls = $pct >= 90 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : ($pct >= 25 ? 'bg-primary' : 'bg-warning'));
    ?>
    <div class="dash-loan-item">
      <div class="dash-loan-header">
        <span class="dash-loan-name"><?= h($loan['name']) ?></span>
        <?php if ($monthsLeft > 0): ?>
          <span class="text-muted small"><?= $monthsLeft < 12 ? $monthsLeft.'mo' : round($monthsLeft/12,1).'yr' ?> left</span>
        <?php endif; ?>
      </div>
      <div class="progress dash-loan-bar" title="<?= $pct ?>% paid off">
        <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%"></div>
      </div>
      <div class="dash-loan-amounts">
        <span class="text-muted small">Balance:</span>
        <span class="amount-debit"><?= formatMoney($balance) ?></span>
        <span class="text-muted small ms-2">of</span>
        <span><?= formatMoney($original) ?></span>
        <span class="dash-loan-pct ms-auto"><?= $pct ?>% paid</span>
      </div>
      <div class="dash-loan-payment text-muted small">
        <i class="bi bi-calendar-month"></i> <?= formatMoney($payment) ?>/mo
        &nbsp;·&nbsp; <?= number_format($rate, 2) ?>% APR
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
          <?php break;

          // ── Market Indexes ─────────────────────────────────────
          case 'market_indexes':
            $indexes = array_filter($dashPortfolio ?? [], fn($r) => $r['type'] === 'Index');
            usort($indexes, fn($a, $b) => strcmp($a['name'], $b['name'])); ?>
    <?php if ($priceLastFetched): ?>
    <p class="text-muted small mb-1" style="font-size:.72rem">Updated: <?= h(date('m/d/Y g:ia', strtotime($priceLastFetched))) ?></p>
    <?php endif; ?>
    <?php if (empty($indexes)): ?>
      <p class="text-muted small">No index data available. <a href="<?= BASE_PATH ?>/portfolio/index">Manage investments.</a></p>
    <?php else: ?>
    <table class="table table-sm dash-table">
      <thead><tr>
        <th>Index</th>
        <th class="text-end">Price</th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg <i class="bi bi-dash sort-caret"></i></th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg % <i class="bi bi-dash sort-caret"></i></th>
      </tr></thead>
      <tbody>
      <?php foreach ($indexes as $inv):
        $chgCls = $inv['day_chg'] === null ? '' : ($inv['day_chg'] >= 0 ? 'gain-pos' : 'gain-neg');
      ?>
        <tr>
          <td><span class="inv-symbol"><?= h($inv['symbol']) ?></span><span class="text-muted small d-block"><?= h($inv['name']) ?></span></td>
          <td class="text-end"><?= number_format((float)$inv['price'], 2) ?></td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg'] ?? 0 ?>">
            <?= $inv['day_chg'] !== null ? ($inv['day_chg'] >= 0 ? '+' : '') . number_format($inv['day_chg'], 2) : '—' ?>
          </td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg_pct'] ?? 0 ?>">
            <?= $inv['day_chg_pct'] !== null ? ($inv['day_chg_pct'] >= 0 ? '+' : '') . number_format($inv['day_chg_pct'], 2) . '%' : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
          <?php break;

          // ── Top Movers by Price % ──────────────────────────────
          case 'top_movers': ?>
    <div class="mover-period-tabs mb-2" id="priceMoverTabs">
      <button class="mover-period-tab active" onclick="loadPriceMovers('today',this)">Today</button>
      <button class="mover-period-tab" onclick="loadPriceMovers('7days',this)">7 Days</button>
      <button class="mover-period-tab" onclick="loadPriceMovers('month',this)">This Month</button>
      <button class="mover-period-tab" onclick="loadPriceMovers('year',this)">This Year</button>
    </div>
    <div id="topMoversPriceBody"></div>
    <script>
    (function(){
      const _spBase    = <?= json_encode(BASE_PATH) ?>;
      const _todayData = <?= json_encode($todayMoverRows) ?>;
      function _esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
      function _fn(n,d){ return parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:d,maximumFractionDigits:d}); }

      function _renderPrice(allRows) {
        const body = document.getElementById('topMoversPriceBody');
        if (!body) return;
        const rows = allRows.filter(r => r.chg_pct !== null)
          .sort((a,b) => Math.abs(b.chg_pct)-Math.abs(a.chg_pct)).slice(0,10);
        if (!rows.length) { body.innerHTML='<p class="text-muted small">No price change data available.</p>'; return; }
        let h = '<table class="table table-sm dash-table"><thead><tr>'
          +'<th>Symbol</th><th class="text-end">Price</th>'
          +'<th class="text-end sortable" onclick="sortDashTable(this)">Chg <i class="bi bi-dash sort-caret"></i></th>'
          +'<th class="text-end sortable" onclick="sortDashTable(this)">Chg % <i class="bi bi-dash sort-caret"></i></th>'
          +'</tr></thead><tbody>';
        rows.forEach(r => {
          const cls = r.chg >= 0 ? 'gain-pos' : 'gain-neg';
          h += `<tr class="inv-row" data-inv-id="${r.id}" data-inv-name="${_esc(r.name)}" data-inv-symbol="${_esc(r.symbol)}">`
            +`<td><span class="inv-symbol">${_esc(r.symbol)}</span><span class="text-muted small d-block">${_esc(r.name)}</span></td>`
            +`<td class="text-end">${_fn(r.price,2)}</td>`
            +`<td class="text-end ${cls}" data-val="${r.chg}">${(r.chg>=0?'+':'')+_fn(r.chg,2)}</td>`
            +`<td class="text-end ${cls}" data-val="${r.chg_pct}">${(r.chg_pct>=0?'+':'')+_fn(r.chg_pct,2)}%</td>`
            +'</tr>';
        });
        body.innerHTML = h + '</tbody></table>';
        if (window.applySavedDashSort) applySavedDashSort('top_movers', body.querySelector('table'));
      }

      window.loadPriceMovers = async function(period, btn) {
        document.querySelectorAll('#priceMoverTabs .mover-period-tab').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        if (period === 'today') { _renderPrice(_todayData); return; }
        const body = document.getElementById('topMoversPriceBody');
        if (body) body.innerHTML = '<p class="text-muted small">Loading…</p>';
        try {
          const d = await fetch(_spBase+'/dashboard/top_movers_data.php?period='+period).then(r=>r.json());
          if (d.ok) _renderPrice(d.rows);
          else if (body) body.innerHTML = '<p class="text-danger small">Error loading data.</p>';
        } catch(e) { if (body) body.innerHTML = '<p class="text-danger small">Error loading data.</p>'; }
      };

      _renderPrice(_todayData);
    })();
    </script>
          <?php break;

          // ── Top Movers by Portfolio Value ──────────────────────
          case 'portfolio_movers': ?>
    <div class="mover-period-tabs mb-2" id="valueMoverTabs">
      <button class="mover-period-tab active" onclick="loadValueMovers('today',this)">Today</button>
      <button class="mover-period-tab" onclick="loadValueMovers('7days',this)">7 Days</button>
      <button class="mover-period-tab" onclick="loadValueMovers('month',this)">This Month</button>
      <button class="mover-period-tab" onclick="loadValueMovers('year',this)">This Year</button>
    </div>
    <div id="topMoversValueBody"></div>
    <script>
    (function(){
      const _spBase    = <?= json_encode(BASE_PATH) ?>;
      const _todayData = <?= json_encode($todayMoverRows) ?>;
      function _esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
      function _fm(n){ return '$'+parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
      function _fq(n){ const f=parseFloat(n); return f===Math.floor(f)?f.toLocaleString():f.toFixed(4); }

      function _renderValue(allRows) {
        const body = document.getElementById('topMoversValueBody');
        if (!body) return;
        const rows = allRows.filter(r => r.qty > 0 && r.val_chg !== null)
          .sort((a,b) => Math.abs(b.val_chg)-Math.abs(a.val_chg)).slice(0,10);
        if (!rows.length) { body.innerHTML='<p class="text-muted small">No owned holdings with price change data.</p>'; return; }
        let h = '<table class="table table-sm dash-table"><thead><tr>'
          +'<th>Symbol</th><th class="text-end">Qty</th>'
          +'<th class="text-end sortable" onclick="sortDashTable(this)">Mkt Value <i class="bi bi-dash sort-caret"></i></th>'
          +'<th class="text-end sortable" onclick="sortDashTable(this)">Value Chg <i class="bi bi-dash sort-caret"></i></th>'
          +'</tr></thead><tbody>';
        rows.forEach(r => {
          const cls = r.val_chg >= 0 ? 'gain-pos' : 'gain-neg';
          h += `<tr class="inv-row" data-inv-id="${r.id}" data-inv-name="${_esc(r.name)}" data-inv-symbol="${_esc(r.symbol)}">`
            +`<td><span class="inv-symbol">${_esc(r.symbol)}</span><span class="text-muted small d-block">${_esc(r.name)}</span></td>`
            +`<td class="text-end">${_fq(r.qty)}</td>`
            +`<td class="text-end" data-val="${r.mkt_val}">${_fm(r.mkt_val)}</td>`
            +`<td class="text-end ${cls}" data-val="${r.val_chg}">${(r.val_chg>=0?'+':'')+_fm(Math.abs(r.val_chg))}</td>`
            +'</tr>';
        });
        body.innerHTML = h + '</tbody></table>';
        if (window.applySavedDashSort) applySavedDashSort('portfolio_movers', body.querySelector('table'));
      }

      window.loadValueMovers = async function(period, btn) {
        document.querySelectorAll('#valueMoverTabs .mover-period-tab').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        if (period === 'today') { _renderValue(_todayData); return; }
        const body = document.getElementById('topMoversValueBody');
        if (body) body.innerHTML = '<p class="text-muted small">Loading…</p>';
        try {
          const d = await fetch(_spBase+'/dashboard/top_movers_data.php?period='+period).then(r=>r.json());
          if (d.ok) _renderValue(d.rows);
          else if (body) body.innerHTML = '<p class="text-danger small">Error loading data.</p>';
        } catch(e) { if (body) body.innerHTML = '<p class="text-danger small">Error loading data.</p>'; }
      };

      _renderValue(_todayData);
    })();
    </script>
          <?php break;

          // ── Most Active ────────────────────────────────────────
          case 'most_active':
            $activeStocks = array_filter($dashPortfolio ?? [], fn($r) => $r['type'] !== 'Index' && $r['type'] !== 'Cryptocurrency' && $r['volume'] > 0);
            usort($activeStocks, fn($a, $b) => $b['volume'] <=> $a['volume']);
            $activeStocks = array_slice(array_values($activeStocks), 0, 10); ?>
    <?php if (empty($activeStocks)): ?>
      <p class="text-muted small">No volume data available.</p>
    <?php else: ?>
    <table class="table table-sm dash-table">
      <thead><tr>
        <th>Symbol</th>
        <th class="text-end">Price</th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg % <i class="bi bi-dash sort-caret"></i></th>
        <th class="text-end">Volume</th>
      </tr></thead>
      <tbody>
      <?php foreach ($activeStocks as $inv):
        $chgCls = $inv['day_chg'] === null ? '' : ($inv['day_chg'] >= 0 ? 'gain-pos' : 'gain-neg');
      ?>
        <tr class="inv-row" data-inv-id="<?= (int)$inv['id'] ?>" data-inv-name="<?= h($inv['name']) ?>" data-inv-symbol="<?= h($inv['symbol']) ?>">
          <td><span class="inv-symbol"><?= h($inv['symbol']) ?></span><span class="text-muted small d-block"><?= h($inv['name']) ?></span></td>
          <td class="text-end"><?= number_format((float)$inv['price'], 2) ?></td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg_pct'] ?? 0 ?>">
            <?= $inv['day_chg_pct'] !== null ? ($inv['day_chg_pct'] >= 0 ? '+' : '') . number_format($inv['day_chg_pct'], 2) . '%' : '—' ?>
          </td>
          <td class="text-end"><?= number_format($inv['volume']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
          <?php break;

          // ── Crypto ─────────────────────────────────────────────
          case 'crypto':
            $cryptos = array_filter($dashPortfolio ?? [], fn($r) => $r['type'] === 'Cryptocurrency');
            usort($cryptos, fn($a, $b) => strcmp($a['name'], $b['name'])); ?>
    <?php if ($priceLastFetched): ?>
    <p class="text-muted small mb-1" style="font-size:.72rem">Updated: <?= h(date('m/d/Y g:ia', strtotime($priceLastFetched))) ?></p>
    <?php endif; ?>
    <?php if (empty($cryptos)): ?>
      <p class="text-muted small">No cryptocurrency data. <a href="<?= BASE_PATH ?>/portfolio/index">Add investments of type Cryptocurrency.</a></p>
    <?php else: ?>
    <table class="table table-sm dash-table">
      <thead><tr>
        <th>Crypto</th>
        <th class="text-end">Price</th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg <i class="bi bi-dash sort-caret"></i></th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg % <i class="bi bi-dash sort-caret"></i></th>
      </tr></thead>
      <tbody>
      <?php foreach ($cryptos as $inv):
        $chgCls = $inv['day_chg'] === null ? '' : ($inv['day_chg'] >= 0 ? 'gain-pos' : 'gain-neg');
      ?>
        <tr>
          <td>
            <?php if ($inv['symbol']): ?><span class="inv-symbol"><?= h($inv['symbol']) ?></span><?php endif; ?>
            <span class="text-muted small d-block"><?= h($inv['name']) ?></span>
          </td>
          <td class="text-end text-nowrap"><?= formatCryptoPrice((float)$inv['price']) ?></td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg'] ?? 0 ?>">
            <?= $inv['day_chg'] !== null ? ($inv['day_chg'] >= 0 ? '+' : '') . formatCryptoPrice(abs((float)$inv['day_chg'])) : '—' ?>
          </td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg_pct'] ?? 0 ?>">
            <?= $inv['day_chg_pct'] !== null ? ($inv['day_chg_pct'] >= 0 ? '+' : '') . number_format($inv['day_chg_pct'], 2) . '%' : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
          <?php break;

          // ── Watchlist ────────────────────────────────────────
          case 'watchlist':
            $watchRows = $dashWatchlist ?? []; ?>
    <?php if ($priceLastFetched): ?>
    <p class="text-muted small mb-1" style="font-size:.72rem">Updated: <?= h(date('m/d/Y g:ia', strtotime($priceLastFetched))) ?></p>
    <?php endif; ?>
    <?php if (empty($watchRows)): ?>
      <p class="text-muted small">No investments on your watchlist. <a href="<?= BASE_PATH ?>/watchlist/index">Manage watchlist.</a></p>
    <?php else: ?>
    <table class="table table-sm dash-table">
      <thead><tr>
        <th>Name</th>
        <th class="text-end">Price</th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg <i class="bi bi-dash sort-caret"></i></th>
        <th class="text-end sortable" onclick="sortDashTable(this)">Chg % <i class="bi bi-dash sort-caret"></i></th>
      </tr></thead>
      <tbody>
      <?php foreach ($watchRows as $inv):
        $chgCls   = $inv['day_chg'] === null ? '' : ($inv['day_chg'] >= 0 ? 'gain-pos' : 'gain-neg');
        $secSlug  = !empty($inv['symbol']) ? urlencode($inv['symbol']) : $inv['id'];
        $isCrypto = $inv['type'] === 'Cryptocurrency';
      ?>
        <tr>
          <td class="fw-medium">
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
          <td class="text-end text-nowrap">
            <?= $inv['price'] !== null ? ($isCrypto ? formatCryptoPrice((float)$inv['price']) : number_format($inv['price'], 2)) : '—' ?>
          </td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg'] ?? 0 ?>">
            <?php if ($inv['day_chg'] === null): ?>
              —
            <?php elseif ($isCrypto): ?>
              <?= ($inv['day_chg'] >= 0 ? '+' : '-') . formatCryptoPrice(abs((float)$inv['day_chg'])) ?>
            <?php else: ?>
              <?= ($inv['day_chg'] >= 0 ? '+' : '') . number_format($inv['day_chg'], 2) ?>
            <?php endif; ?>
          </td>
          <td class="text-end <?= $chgCls ?>" data-val="<?= $inv['day_chg_pct'] ?? 0 ?>">
            <?= $inv['day_chg_pct'] !== null ? ($inv['day_chg_pct'] >= 0 ? '+' : '') . number_format($inv['day_chg_pct'], 2) . '%' : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
          <?php break;

          // ── Portfolio Snapshot ─────────────────────────────────
          case 'portfolio_snapshot': ?>
<script>
(function(){
  const spBase  = <?= json_encode(BASE_PATH) ?>;
  const csrfTok = <?= json_encode(csrfToken()) ?>;
  const psAccts = <?= json_encode($_psAcctPref) ?>;
  const psExcl  = <?= json_encode($_psExclPref) ?>;
  let psChart   = null;
  const COLORS  = ['#1a5fb4','#e66000','#1a7a3c','#c0392b','#8e44ad','#16a085','#d4ac0d','#5d6d7e','#ca6f1e','#117a65','#aab0bc'];

  async function loadSnapshot() {
    const params = new URLSearchParams();
    if (psAccts) params.set('accts', psAccts);
    if (psExcl)  params.set('exclude_types', psExcl);
    try {
      const d = await fetch(spBase + '/dashboard/portfolio_snapshot_data.php?' + params).then(r=>r.json());
      if (!d.ok) return;
      renderSnapshot(d);
    } catch(e) {}
  }

  function renderSnapshot(d) {
    const wrap = document.querySelector('.dash-tile[data-widget="portfolio_snapshot"] .dash-tile-body');
    if (!wrap) return;
    if (psChart) { psChart.destroy(); psChart = null; }
    if (!d.labels || !d.labels.length) {
      wrap.innerHTML = '<p class="text-muted small">No holdings data.</p>';
      return;
    }
    const fmt = n => '$' + parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});
    wrap.innerHTML = '<div style="position:relative;max-width:220px;margin:0 auto 10px"><canvas id="psDonut"></canvas>'
      + '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none">'
      + '<div style="font-size:.72rem;color:#666">Market Value</div>'
      + '<div style="font-weight:700;font-size:.92rem">' + fmt(d.total_mv) + '</div></div></div>'
      + '<div id="psLegend" style="font-size:.78rem"></div>';
    const ctx = document.getElementById('psDonut');
    psChart = new Chart(ctx, {
      type: 'doughnut',
      data: { labels: d.labels, datasets: [{ data: d.values, backgroundColor: d.colors, borderWidth: 2, borderColor:'#fff' }] },
      options: {
        animation: false, cutout: '62%',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => ' ' + c.label + ': $' + parseFloat(c.raw).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) } }
        }
      }
    });
    const leg = document.getElementById('psLegend');
    d.labels.forEach((lbl,i) => {
      const pct = d.total_mv > 0 ? (d.values[i] / d.total_mv * 100).toFixed(1) : '0';
      const div = document.createElement('div');
      div.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:2px';
      div.innerHTML = `<span style="width:9px;height:9px;border-radius:50%;background:${d.colors[i]};flex-shrink:0"></span>`
        + `<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${lbl}</span>`
        + `<span style="font-weight:600;min-width:36px;text-align:right">${pct}%</span>`;
      leg.appendChild(div);
    });
  }

  loadSnapshot();
})();
</script>
          <?php break;

          // ── Asset Allocation ───────────────────────────────────
          case 'asset_allocation': ?>
<div class="d-flex gap-2 mb-2">
  <button class="btn btn-xs btn-sm aa-view-btn <?= $_aaView === 'type' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="setAAView('type',this)">By Type</button>
  <button class="btn btn-xs btn-sm aa-view-btn <?= $_aaView === 'account' ? 'btn-primary' : 'btn-outline-secondary' ?>" onclick="setAAView('account',this)">By Account</button>
</div>
<div id="aaChartWrap" style="position:relative;max-width:220px;margin:0 auto 10px"><canvas id="aaDonut"></canvas></div>
<div id="aaLegend" style="font-size:.78rem"></div>
<script>
(function(){
  const spBase  = <?= json_encode(BASE_PATH) ?>;
  let aaChart   = null;
  let aaView    = <?= json_encode($_aaView) ?>;
  const COLORS  = ['#1a5fb4','#e66000','#1a7a3c','#c0392b','#8e44ad','#16a085','#d4ac0d','#5d6d7e','#ca6f1e','#117a65'];
  let aaData    = null;

  async function loadAA(accts) {
    const params = new URLSearchParams();
    if (accts && accts.length) params.set('accts', accts.join(','));
    try {
      aaData = await fetch(spBase + '/dashboard/asset_allocation_data.php?' + params).then(r=>r.json());
      if (aaData.ok) renderAA();
    } catch(e) {}
  }

  function renderAA() {
    if (!aaData || !aaData.ok) return;
    const src     = aaView === 'account' ? aaData.by_account : aaData.by_type;
    const totalMV = aaData.total_mv;
    if (aaChart) { aaChart.destroy(); aaChart = null; }
    const leg = document.getElementById('aaLegend');
    if (leg) leg.innerHTML = '';
    if (!src.labels.length) {
      const wrap = document.getElementById('aaChartWrap');
      if (wrap) wrap.innerHTML = '<p class="text-muted small">No data.</p>';
      return;
    }
    const ctx = document.getElementById('aaDonut');
    if (!ctx) return;
    aaChart = new Chart(ctx, {
      type: 'doughnut',
      data: { labels: src.labels, datasets: [{ data: src.values, backgroundColor: src.colors, borderWidth: 2, borderColor:'#fff' }] },
      options: {
        animation: false, cutout: '62%',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: c => {
            const pct = totalMV > 0 ? (c.raw / totalMV * 100).toFixed(1) : 0;
            return ' $' + parseFloat(c.raw).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' (' + pct + '%)';
          }}}
        }
      }
    });
    if (leg) {
      src.labels.forEach((lbl,i) => {
        const pct = totalMV > 0 ? (src.values[i] / totalMV * 100).toFixed(1) : '0';
        const div = document.createElement('div');
        div.style.cssText = 'display:flex;align-items:center;gap:6px;margin-bottom:2px';
        div.innerHTML = `<span style="width:9px;height:9px;border-radius:50%;background:${src.colors[i]};flex-shrink:0"></span>`
          + `<span style="flex:1">${lbl}</span><span style="font-weight:600;min-width:36px;text-align:right">${pct}%</span>`;
        leg.appendChild(div);
      });
    }
  }

  window.setAAView = function(view, btn) {
    aaView = view;
    document.querySelectorAll('.aa-view-btn').forEach(b => {
      b.classList.remove('btn-primary');
      b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary');
    fetch(spBase + '/dashboard/save_layout.php', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: <?= json_encode(csrfToken()) ?>, action: 'save_pref', key: 'dashboard_aa_view', value: view })
    });
    renderAA();
  };

  const tile  = document.querySelector('.dash-tile[data-widget="asset_allocation"]');
  const accts = tile ? JSON.parse(tile.dataset.accts || '[]') : [];
  loadAA(accts);
})();
</script>
          <?php break;

          // ── Bookmarks ──────────────────────────────────────────
          case 'bookmarks': ?>
    <div class="bmk-list" id="bmkList">
    <?php if (empty($dashBookmarks)): ?>
      <p class="bmk-empty">No bookmarks yet. Add one below.</p>
    <?php else: ?>
      <?php foreach ($dashBookmarks as $bmk):
        $bmkIsExt  = (bool)preg_match('/^https?:\/\//i', $bmk['url']);
        $bmkIcon   = $bmkIsExt ? 'bi-box-arrow-up-right' : 'bi-house-door';
        $bmkTarget = $bmkIsExt ? 'target="_blank" rel="noopener noreferrer"' : '';
      ?>
      <div class="bmk-row" data-id="<?= (int)$bmk['id'] ?>" draggable="true">
        <span class="bmk-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
        <a href="<?= h($bmk['url']) ?>" <?= $bmkTarget ?> class="bmk-link <?= $bmkIsExt ? 'bmk-link-ext' : 'bmk-link-int' ?>" data-title="<?= h($bmk['title']) ?>">
          <i class="bi <?= $bmkIcon ?> bmk-link-icon"></i>
          <span class="bmk-title"><?= h($bmk['title']) ?></span>
        </a>
        <div class="bmk-actions">
          <button class="bmk-btn" onclick="bmkEdit(this)" title="Edit"><i class="bi bi-pencil"></i></button>
          <button class="bmk-btn bmk-btn-del" onclick="bmkDelete(this)" title="Delete"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
    <div class="bmk-add-wrap">
      <button class="bmk-add-btn" id="bmkAddBtn" onclick="bmkShowAdd()"><i class="bi bi-plus-circle"></i> Add Bookmark</button>
      <form class="bmk-form d-none" id="bmkAddForm" onsubmit="bmkSaveNew(event)">
        <input type="text" name="title" class="form-control form-control-sm" placeholder="Display name" required maxlength="255">
        <input type="text" name="url"   class="form-control form-control-sm" placeholder="https://example.com or <?= h(BASE_PATH) ?>/reports" required maxlength="2048">
        <small class="text-muted bmk-url-hint"><i class="bi bi-info-circle"></i> External URLs start with <code>https://</code>. Local paths start with <code>/</code> from the server root&nbsp;&mdash;&nbsp;e.g.&nbsp;<code><?= h(BASE_PATH) ?>/reports</code></small>
        <div class="bmk-form-btns">
          <button type="submit" class="btn btn-sm btn-primary">Add</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bmkCancelAdd()">Cancel</button>
        </div>
      </form>
    </div>
          <?php break;

          // ── Notepad ────────────────────────────────────────────
          case 'notepad': ?>
    <div class="notepad-wrap">
      <textarea class="notepad-textarea" id="notepadTextarea" placeholder="Shared notes — visible to all users…"><?= h($dashNotepad['content'] ?? '') ?></textarea>
      <div class="notepad-footer">
        <span class="notepad-status" id="notepadStatus"></span>
        <?php if (!empty($dashNotepad['updated_at'])): ?>
        <span class="notepad-meta">
          Last edited
          <?php if (!empty($dashNotepad['updated_by_name'])): ?>
          by <strong><?= h($dashNotepad['updated_by_name']) ?></strong>
          <?php endif; ?>
          <?= formatDate($dashNotepad['updated_at']) ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
<script>
(function(){
  const ta     = document.getElementById('notepadTextarea');
  const status = document.getElementById('notepadStatus');
  const base   = <?= json_encode(BASE_PATH) ?>;
  const csrf   = <?= json_encode(csrfToken()) ?>;
  let timer    = null;
  let lastSaved = ta.value;

  function save() {
    status.textContent = 'Saving…';
    status.className   = 'notepad-status notepad-saving';
    fetch(base + '/dashboard/notepad.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'csrf_token=' + encodeURIComponent(csrf) + '&content=' + encodeURIComponent(ta.value),
    })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        lastSaved = ta.value;
        status.textContent = 'Saved';
        status.className   = 'notepad-status notepad-saved';
        setTimeout(() => { if (status.textContent === 'Saved') status.textContent = ''; }, 2000);
      } else {
        status.textContent = 'Error saving';
        status.className   = 'notepad-status notepad-error';
      }
    })
    .catch(() => {
      status.textContent = 'Error saving';
      status.className   = 'notepad-status notepad-error';
    });
  }

  ta.addEventListener('input', () => {
    clearTimeout(timer);
    status.textContent = '';
    status.className   = 'notepad-status';
    timer = setTimeout(save, 1500);
  });
})();
</script>
          <?php break;

      endswitch;
      $inner = ob_get_clean();

      $hasFilter    = in_array($widgetId, ['recent_transactions', 'monthly_spending', 'portfolio_snapshot', 'asset_allocation'], true);
      $selAccts     = $widgetId === 'recent_transactions'  ? $acctIdsRecent
                    : ($widgetId === 'monthly_spending'    ? $acctIdsSpend
                    : ($widgetId === 'portfolio_snapshot'  ? $_psAcctIds
                    : []));
      $filterAcctList = in_array($widgetId, ['portfolio_snapshot', 'asset_allocation'], true)
          ? $allInvestmentAccounts
          : ($hasFilter ? $pickerAccounts : []);

      $_portLink    = '<a href="' . BASE_PATH . '/portfolio/index" class="tile-header-link">Portfolio</a>';
      $_refreshBtn  = $priceProvider !== 'manual'
          ? '<button type="button" class="tile-header-link" onclick="refreshPortfolioPrices(this)" title="Refresh prices"><i class="bi bi-arrow-clockwise"></i></button>'
          : '';
      $_invActions  = $_refreshBtn . $_portLink;
      $_watchlistActions = $_refreshBtn . '<a href="' . BASE_PATH . '/watchlist/index" class="tile-header-link">Watchlist</a>';

      $headerAction = match ($widgetId) {
          'monthly_spending'   => '',
          'budget'             => '<a href="' . BASE_PATH . '/budget/index" class="tile-header-link">View Budget</a>',
          'upcoming_bills'     => '<a href="' . BASE_PATH . '/bills/index" class="tile-header-link">Manage Bills</a>',
          'fav_reports'        => '<a href="' . BASE_PATH . '/reports/index" class="tile-header-link">All Reports</a>',
          'fav_accounts'       => '<a href="' . BASE_PATH . '/accounts/index" class="tile-header-link">Accounts</a>',
          'key_indicators'     => '<a href="' . BASE_PATH . '/reports/index" class="tile-header-link">Reports</a>',
          'goals'              => '<a href="' . BASE_PATH . '/goals/index" class="tile-header-link">Manage</a>',
          'loans'              => '<a href="' . BASE_PATH . '/loans/index" class="tile-header-link">Manage</a>',
          'portfolio_snapshot' => '<a href="' . BASE_PATH . '/reports/portfolio_snapshot" class="tile-header-link">Full Report</a>',
          'asset_allocation'   => '<a href="' . BASE_PATH . '/reports/asset_allocation" class="tile-header-link">Full Report</a>',
          'market_indexes',
          'top_movers',
          'portfolio_movers',
          'most_active',
          'crypto'             => $_invActions,
          'watchlist'          => $_watchlistActions,
          default              => '',
      };

      widgetWrap($widgetId, $widgetLabels[$widgetId], $widgetIcons[$widgetId],
                 $isHidden, $headerAction,
                 $filterAcctList, $selAccts, $inner);
  endforeach;
  ?>

  </div><!-- /#dashGrid -->
</div><!-- /.dashboard -->

<script>
(function () {
  // ── Bills widget ──────────────────────────────────────────────
  const today    = new Date(); today.setHours(0,0,0,0);
  const allBills = <?= json_encode(array_values($upcomingBills)) ?>;

  function endOfMonth(d) { return new Date(d.getFullYear(), d.getMonth() + 1, 0); }
  function cutoffDate(range) {
    const d = new Date(today);
    if (range === 'month') return endOfMonth(d);
    d.setDate(d.getDate() + parseInt(range, 10));
    return d;
  }
  function isoToDate(s) { const [y,m,d] = s.split('-').map(Number); return new Date(y,m-1,d); }
  function statusInfo(s) {
    const due  = isoToDate(s);
    const soon = new Date(today); soon.setDate(today.getDate() + 7);
    if (due < today)  return { cls: 'bill-overdue', label: 'Overdue' };
    if (due <= soon)  return { cls: 'bill-soon',    label: 'Due Soon' };
    return              { cls: 'bill-ok',       label: 'Upcoming' };
  }
  function fmtDate(s) { const [y,m,d] = s.split('-'); return m+'/'+d+'/'+y; }
  function fmt(n) { return '$'+parseFloat(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function renderBills(range) {
    const cutoff = cutoffDate(range);
    const rows   = allBills.filter(b => isoToDate(b.next_due_date) <= cutoff);
    const body   = document.getElementById('upcomingBillsBody');
    if (!body) return;
    if (!rows.length) { body.innerHTML = '<p class="text-muted">No bills or deposits due in this period.</p>'; return; }

    let totalBills = 0, totalDeposits = 0;
    rows.forEach(b => { if (b.type === 'deposit') totalDeposits += parseFloat(b.amount); else totalBills += parseFloat(b.amount); });
    const net = totalDeposits - totalBills;

    let html = '<table class="table table-sm dash-table"><thead><tr>'
      + '<th>Name</th><th>Type</th><th>Account</th><th class="text-end">Amount</th><th>Due</th><th>Status</th>'
      + '</tr></thead><tbody>';
    rows.forEach(b => {
      const si      = statusInfo(b.next_due_date);
      const amtCls  = b.type === 'deposit' ? 'amount-credit' : 'amount-debit';
      const badge   = b.type === 'bill'    ? '<span class="badge bill-type-bill"><i class="bi bi-arrow-up-circle"></i> Bill</span>'
                    : b.type === 'deposit' ? '<span class="badge bill-type-deposit"><i class="bi bi-arrow-down-circle"></i> Deposit</span>'
                    :                        '<span class="badge bill-type-transfer"><i class="bi bi-arrow-left-right"></i> Transfer</span>';
      const acct    = b.to_account_name
        ? esc(b.account_name) + ' <span class="text-muted">&rarr; ' + esc(b.to_account_name) + '</span>'
        : esc(b.account_name);
      const estBadge = b.is_estimated == 1 ? ' <span class="bill-est" title="Estimated amount">~est</span>' : '';
      html += '<tr>'
        + '<td class="fw-medium">' + esc(b.name) + '</td>'
        + '<td>' + badge + '</td>'
        + '<td>' + acct + '</td>'
        + '<td class="text-end"><span class="' + amtCls + '">' + fmt(b.amount) + '</span>' + estBadge + '</td>'
        + '<td class="text-nowrap">' + fmtDate(b.next_due_date) + '</td>'
        + '<td><span class="bill-status ' + si.cls + '">' + si.label + '</span></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    html += '<div class="bills-summary-footer">'
      + '<span class="bills-sum-item"><i class="bi bi-arrow-up-circle text-danger"></i> Bills: <span class="amount-debit">'  + fmt(totalBills)    + '</span></span>'
      + '<span class="bills-sum-item"><i class="bi bi-arrow-down-circle text-success"></i> Deposits: <span class="amount-credit">' + fmt(totalDeposits) + '</span></span>'
      + '<span class="bills-sum-item fw-bold">Net: <span class="' + (net >= 0 ? 'amount-credit' : 'amount-debit') + '">' + fmt(net) + '</span></span>'
      + '</div>';
    body.innerHTML = html;
  }

  function initBills() {
    const sel = document.getElementById('billRangeSelect');
    if (!sel) return;
    sel.addEventListener('change', () => renderBills(sel.value));
    renderBills(sel.value);
  }
  window.initBills = initBills;
  initBills();
})();

// ── Dashboard layout ───────────────────────────────────────────
const CSRF     = <?= json_encode(csrfToken()) ?>;
const CSRF_TOKEN = CSRF;
const BASE_PATH = <?= json_encode(BASE_PATH) ?>;
let reorderMode = false;

// ── Investment row → price history modal ───────────────────────
document.addEventListener('click', e => {
  if (e.target.closest('th')) return;
  const row = e.target.closest('tr.inv-row[data-inv-id]');
  if (!row) return;
  if (typeof openPriceHistory === 'function')
    openPriceHistory(row.dataset.invId, row.dataset.invName, row.dataset.invSymbol);
});

// ── Sortable table columns ─────────────────────────────────────
const DASH_SORT_PREFS = <?= json_encode($dashSortPrefs) ?>;

function sortRowsByColumn(table, colIdx, asc) {
  const tbody = table && table.querySelector('tbody');
  if (!tbody) return;
  const rows = [...tbody.querySelectorAll('tr')];
  rows.sort((a, b) => {
    const av = parseFloat(a.cells[colIdx]?.dataset.val ?? 0) || 0;
    const bv = parseFloat(b.cells[colIdx]?.dataset.val ?? 0) || 0;
    return asc ? av - bv : bv - av;
  });
  rows.forEach(r => tbody.appendChild(r));
}

function markSortHeader(table, colIdx, asc) {
  if (!table) return;
  table.querySelectorAll('thead th.sortable').forEach(t => {
    t.classList.remove('sort-asc', 'sort-desc');
    const ic = t.querySelector('.sort-caret');
    if (ic) { ic.className = 'bi bi-dash sort-caret'; }
  });
  const th = [...table.querySelectorAll('thead tr th')][colIdx];
  if (th && th.classList.contains('sortable')) {
    th.classList.add(asc ? 'sort-asc' : 'sort-desc');
    const ic = th.querySelector('.sort-caret');
    if (ic) ic.className = 'bi ' + (asc ? 'bi-caret-up-fill' : 'bi-caret-down-fill') + ' sort-caret';
  }
}

window.applySavedDashSort = function(widgetKey, table) {
  const raw = DASH_SORT_PREFS[widgetKey];
  if (!raw || !table) return;
  const [ciStr, dir] = String(raw).split(':');
  const colIdx = parseInt(ciStr, 10);
  if (isNaN(colIdx)) return;
  const asc = dir === 'asc';
  markSortHeader(table, colIdx, asc);
  sortRowsByColumn(table, colIdx, asc);
};

function sortDashTable(th) {
  const table  = th.closest('table');
  const colIdx = [...th.parentElement.children].indexOf(th);
  const asc    = !th.classList.contains('sort-asc');
  markSortHeader(table, colIdx, asc);
  sortRowsByColumn(table, colIdx, asc);
  const widgetKey = th.closest('.dash-tile')?.dataset.widget;
  if (widgetKey && Object.prototype.hasOwnProperty.call(DASH_SORT_PREFS, widgetKey)) {
    fetch(BASE_PATH + '/dashboard/save_layout.php', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: CSRF, action: 'save_pref', key: 'dashboard_sort_' + widgetKey, value: colIdx + ':' + (asc ? 'asc' : 'desc') })
    });
  }
}

['watchlist', 'top_movers', 'portfolio_movers'].forEach(key => {
  const _t = document.querySelector('.dash-tile[data-widget="' + key + '"] table');
  if (_t) applySavedDashSort(key, _t);
});

// ── Gear panel ─────────────────────────────────────────────────
function toggleGearPanel(btn) {
  const panel = btn.closest('.dash-tile-header').querySelector('.dash-tile-gear-panel');
  if (!panel) return;
  const isOpen = panel.classList.toggle('open');
  btn.classList.toggle('open', isOpen);
}

document.addEventListener('click', e => {
  if (e.target.closest('.dash-tile-gear-btn') || e.target.closest('.dash-tile-gear-panel')) return;
  document.querySelectorAll('.dash-tile-gear-panel.open').forEach(p => {
    p.classList.remove('open');
    p.closest('.dash-tile-header').querySelector('.dash-tile-gear-btn')?.classList.remove('open');
  });
});

// ── Hide / show widget from gear panel ─────────────────────────
function toggleWidgetHidden(btn) {
  const tile   = btn.closest('.dash-tile');
  const hidden = tile.classList.toggle('dash-tile-hidden');
  const icon   = btn.querySelector('i');
  icon.className  = 'bi ' + (hidden ? 'bi-eye' : 'bi-eye-slash');
  btn.lastChild.textContent = ' ' + (hidden ? 'Show on dashboard' : 'Hide from dashboard');
  // Close gear panel
  btn.closest('.dash-tile-gear-panel').classList.remove('open');
  tile.querySelector('.dash-tile-gear-btn')?.classList.remove('open');
  // Move hidden tile to end of grid so visible tiles stay sorted together
  if (hidden) document.getElementById('dashGrid').appendChild(tile);
  saveLayout();
}

// ── Account filter helpers ─────────────────────────────────────
function selectAllAccts(btn) {
  btn.closest('.dash-gear-filter-wrap').querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = true; });
}
function selectNoAccts(btn) {
  btn.closest('.dash-gear-filter-wrap').querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
}
function getWidgetAcctFilter(widgetId) {
  const tile = document.querySelector('#dashGrid .dash-tile[data-widget="' + widgetId + '"]');
  if (!tile) return '';
  const all     = [...tile.querySelectorAll('.dash-filter-acct-list input[type="checkbox"]')];
  const checked = all.filter(cb => cb.checked).map(cb => cb.value);
  return (checked.length === all.length || checked.length === 0) ? '' : checked.join(',');
}

// ── Reorder mode ───────────────────────────────────────────────
function enterReorder() {
  reorderMode = true;
  document.getElementById('dashboardRoot').classList.add('dash-reorder-mode');
  document.getElementById('dashReorderBar').classList.remove('d-none');
  document.getElementById('dashReorderBtn').classList.add('d-none');
  enableDrag();
  enablePinnedSorting();
}

function exitReorder() {
  reorderMode = false;
  document.getElementById('dashboardRoot').classList.remove('dash-reorder-mode');
  document.getElementById('dashReorderBar').classList.add('d-none');
  document.getElementById('dashReorderBtn').classList.remove('d-none');
  disableDrag();
  disablePinnedSorting();
}

// ── Drag and drop (tile grid) ──────────────────────────────────
let dragSrc = null;

function enableDrag() {
  document.querySelectorAll('#dashGrid .dash-tile').forEach(tile => {
    tile.setAttribute('draggable', 'true');
    tile.addEventListener('dragstart',  onDragStart);
    tile.addEventListener('dragover',   onDragOver);
    tile.addEventListener('dragleave',  onDragLeave);
    tile.addEventListener('drop',       onDrop);
    tile.addEventListener('dragend',    onDragEnd);
  });
}
function disableDrag() {
  document.querySelectorAll('#dashGrid .dash-tile').forEach(tile => {
    tile.setAttribute('draggable', 'false');
    tile.removeEventListener('dragstart',  onDragStart);
    tile.removeEventListener('dragover',   onDragOver);
    tile.removeEventListener('dragleave',  onDragLeave);
    tile.removeEventListener('drop',       onDrop);
    tile.removeEventListener('dragend',    onDragEnd);
  });
}
function onDragStart(e) {
  dragSrc = this;
  e.dataTransfer.effectAllowed = 'move';
  this.classList.add('dash-tile-dragging');
}
function onDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  if (this !== dragSrc) this.classList.add('dash-tile-dragover');
}
function onDragLeave() { this.classList.remove('dash-tile-dragover'); }
function onDrop(e) {
  e.preventDefault();
  this.classList.remove('dash-tile-dragover');
  if (this === dragSrc) return;
  const grid  = document.getElementById('dashGrid');
  const tiles = [...grid.querySelectorAll('.dash-tile')];
  if (tiles.indexOf(dragSrc) < tiles.indexOf(this)) grid.insertBefore(dragSrc, this.nextSibling);
  else                                               grid.insertBefore(dragSrc, this);
  window.initBills && window.initBills();
}
function onDragEnd() {
  this.classList.remove('dash-tile-dragging');
  document.querySelectorAll('.dash-tile-dragover').forEach(el => el.classList.remove('dash-tile-dragover'));
}

// ── Pinned-item sorting (accounts / reports within their tiles) ─
let sortableCleanups = [];

function makeSortable(container, itemSel) {
  if (!container) return () => {};
  let dragEl = null;
  function ds(e)  { dragEl = this; e.dataTransfer.effectAllowed = 'move'; setTimeout(() => this.classList.add('sortable-drag'), 0); }
  function dov(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; if (this !== dragEl) this.classList.add('sortable-over'); }
  function dl()   { this.classList.remove('sortable-over'); }
  function dr(e)  { e.preventDefault(); this.classList.remove('sortable-over'); if (!dragEl||this===dragEl) return;
                    const items = [...container.querySelectorAll(itemSel)];
                    if (items.indexOf(dragEl)<items.indexOf(this)) container.insertBefore(dragEl,this.nextSibling);
                    else container.insertBefore(dragEl,this); }
  function de()   { if(dragEl) dragEl.classList.remove('sortable-drag'); container.querySelectorAll(itemSel).forEach(el=>el.classList.remove('sortable-over')); dragEl=null; }
  const items = container.querySelectorAll(itemSel);
  items.forEach(el => {
    el.setAttribute('draggable','true');
    el.addEventListener('dragstart',ds); el.addEventListener('dragover',dov);
    el.addEventListener('dragleave',dl); el.addEventListener('drop',dr); el.addEventListener('dragend',de);
  });
  return () => items.forEach(el => {
    el.setAttribute('draggable','false');
    el.removeEventListener('dragstart',ds); el.removeEventListener('dragover',dov);
    el.removeEventListener('dragleave',dl); el.removeEventListener('drop',dr); el.removeEventListener('dragend',de);
  });
}

function enablePinnedSorting() {
  sortableCleanups.push(makeSortable(document.querySelector('.account-cards'),   '.account-card'));
  sortableCleanups.push(makeSortable(document.querySelector('.fav-reports-grid'), '.fav-report-card'));
}
function disablePinnedSorting() { sortableCleanups.forEach(fn => fn()); sortableCleanups = []; }

// ── Save layout ────────────────────────────────────────────────
function saveLayout(triggerBtn) {
  const tiles  = [...document.querySelectorAll('#dashGrid .dash-tile')];
  const order  = tiles.map(t => t.dataset.widget);
  const hidden = tiles.filter(t => t.classList.contains('dash-tile-hidden')).map(t => t.dataset.widget);

  const favAcctIds = [...document.querySelectorAll('.account-cards .account-card')].map(el => el.dataset.id).filter(Boolean);
  const favRptIds  = [...document.querySelectorAll('.fav-reports-grid .fav-report-card')].map(el => el.dataset.id).filter(Boolean);

  // Disable triggering button while saving
  if (triggerBtn) { triggerBtn.disabled = true; }

  fetch(BASE_PATH + '/dashboard/save_layout', {
    method: 'POST',
    body: new URLSearchParams({
      csrf_token:   CSRF,
      action:       'save',
      order:        order.join(','),
      hidden:       hidden.join(','),
      acct_recent:  getWidgetAcctFilter('recent_transactions'),
      acct_spend:   getWidgetAcctFilter('monthly_spending'),
      fav_accounts: favAcctIds.join(','),
      fav_reports:  favRptIds.join(','),
    })
  })
  .then(r => r.json())
  .then(json => {
    if (json.ok) { exitReorder(); location.reload(); }
    else { showToast(json.error || 'Error saving layout.', 'error'); if (triggerBtn) triggerBtn.disabled = false; }
  })
  .catch((e) => { console.error(e); showToast('Network error saving layout.', 'error'); if (triggerBtn) triggerBtn.disabled = false; });
}

function resetLayout() {
  appConfirm(
    'Reset Layout',
    'Reset dashboard to default layout?',
    null,
    () => {
      fetch(BASE_PATH + '/dashboard/save_layout', {
        method: 'POST',
        body: new URLSearchParams({ csrf_token: CSRF, action: 'reset' })
      })
      .then(r => r.json())
      .then(json => { if (json.ok) location.reload(); else showToast(json.error || 'Error resetting layout.', 'error'); })
      .catch((e) => { console.error(e); showToast('Network error.', 'error'); });
    },
    'Reset'
  );
}

// ── Refresh portfolio prices ───────────────────────────────────
function refreshPortfolioPrices(btn) {
  const origHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
  const toast = showToast(
    '<i class="bi bi-arrow-clockwise spin"></i> <strong>Updating quotes…</strong><br>' +
    '<span class="text-muted" style="font-size:.8rem">Fetching latest prices from online provider</span>',
    'loading'
  );
  fetch(BASE_PATH + '/portfolio/fetch_prices', {
    method: 'POST',
    body: new URLSearchParams({ csrf_token: CSRF, mode: 'latest' })
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      const n    = d.updated || 0;
      const skip = d.skipped || 0;
      let msg = '<i class="bi bi-check-circle-fill" style="color:#198754"></i> '
              + '<strong>' + n + ' price' + (n !== 1 ? 's' : '') + ' updated</strong>';
      if (skip) msg += '<br><span class="text-muted" style="font-size:.8rem">' + skip + ' symbol' + (skip !== 1 ? 's' : '') + ' skipped</span>';
      toast.update(msg, 'success', { autoDismiss: 4000 });
      setTimeout(() => location.reload(), 1200);
    } else {
      toast.update(
        '<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> ' +
        '<strong>Update failed</strong><br>' +
        '<span class="text-muted" style="font-size:.8rem">' + (d.error || 'Unknown error') + '</span>',
        'error'
      );
      btn.disabled = false; btn.innerHTML = origHtml;
    }
  })
  .catch((e) => {
    console.error(e);
    toast.update(
      '<i class="bi bi-exclamation-triangle-fill" style="color:#dc3545"></i> <strong>Network error</strong>',
      'error'
    );
    btn.disabled = false; btn.innerHTML = origHtml;
  });
}

// ── Bookmarks widget ───────────────────────────────────────────
function bmkEsc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function bmkPost(data) {
  data.csrf_token = CSRF;
  return fetch(BASE_PATH + '/dashboard/bookmarks', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data)
  }).then(r => r.json());
}

function bmkIsExternal(url) { return /^https?:\/\//i.test(url); }

function bmkRowHtml(id, title, url) {
  const isExt     = bmkIsExternal(url);
  const icon      = isExt ? 'bi-box-arrow-up-right' : 'bi-house-door';
  const targetAttr = isExt ? 'target="_blank" rel="noopener noreferrer"' : '';
  return `<div class="bmk-row" data-id="${bmkEsc(id)}" draggable="true">
    <span class="bmk-drag-handle" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
    <a href="${bmkEsc(url)}" ${targetAttr} class="bmk-link ${isExt ? 'bmk-link-ext' : 'bmk-link-int'}" data-title="${bmkEsc(title)}">
      <i class="bi ${icon} bmk-link-icon"></i><span class="bmk-title">${bmkEsc(title)}</span>
    </a>
    <div class="bmk-actions">
      <button class="bmk-btn" onclick="bmkEdit(this)" title="Edit"><i class="bi bi-pencil"></i></button>
      <button class="bmk-btn bmk-btn-del" onclick="bmkDelete(this)" title="Delete"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>`;
}

function bmkShowAdd() {
  document.getElementById('bmkAddBtn').classList.add('d-none');
  const form = document.getElementById('bmkAddForm');
  form.classList.remove('d-none');
  form.querySelector('[name="title"]').focus();
}

function bmkCancelAdd() {
  document.getElementById('bmkAddForm').reset();
  document.getElementById('bmkAddForm').classList.add('d-none');
  document.getElementById('bmkAddBtn').classList.remove('d-none');
}

async function bmkSaveNew(e) {
  e.preventDefault();
  const form  = e.target;
  const title = form.querySelector('[name="title"]').value.trim();
  const url   = form.querySelector('[name="url"]').value.trim();
  if (!title || !url) return;
  const btn = form.querySelector('[type="submit"]');
  btn.disabled = true;
  const res = await bmkPost({ action: 'add', title, url });
  btn.disabled = false;
  if (!res.ok) { showToast(res.error || 'Error saving bookmark', 'error'); return; }
  const list = document.getElementById('bmkList');
  list.querySelector('.bmk-empty')?.remove();
  list.insertAdjacentHTML('beforeend', bmkRowHtml(res.id, title, url));
  bmkCancelAdd();
}

function bmkEdit(btn) {
  const row  = btn.closest('.bmk-row');
  const id   = row.dataset.id;
  const link = row.querySelector('.bmk-link');
  const title = link.dataset.title;
  const url   = link.getAttribute('href');
  row.style.display = 'none';
  const editRow = document.createElement('div');
  editRow.className = 'bmk-edit-row';
  editRow.dataset.id = id;
  editRow.innerHTML = `<div class="bmk-form">
    <input type="text" class="form-control form-control-sm" name="title" value="${bmkEsc(title)}" required maxlength="255" placeholder="Display name">
    <input type="text" class="form-control form-control-sm" name="url" value="${bmkEsc(url)}" required maxlength="2048" placeholder="https:// or /path">
    <div class="bmk-form-btns">
      <button class="btn btn-sm btn-primary" onclick="bmkSaveEdit(this)">Save</button>
      <button class="btn btn-sm btn-outline-secondary" onclick="bmkCancelEdit(this)">Cancel</button>
    </div>
  </div>`;
  row.insertAdjacentElement('afterend', editRow);
  editRow.querySelector('[name="title"]').focus();
}

async function bmkSaveEdit(btn) {
  const editRow = btn.closest('.bmk-edit-row');
  const id      = editRow.dataset.id;
  const title   = editRow.querySelector('[name="title"]').value.trim();
  const url     = editRow.querySelector('[name="url"]').value.trim();
  if (!title || !url) return;
  btn.disabled = true;
  const res = await bmkPost({ action: 'update', id, title, url });
  if (!res.ok) { btn.disabled = false; showToast(res.error || 'Error', 'error'); return; }
  const origRow = document.querySelector(`#bmkList .bmk-row[data-id="${CSS.escape(id)}"]`);
  editRow.insertAdjacentHTML('beforebegin', bmkRowHtml(id, title, url));
  origRow?.remove();
  editRow.remove();
}

function bmkCancelEdit(btn) {
  const editRow = btn.closest('.bmk-edit-row');
  const origRow = document.querySelector(`#bmkList .bmk-row[data-id="${CSS.escape(editRow.dataset.id)}"]`);
  if (origRow) origRow.style.display = '';
  editRow.remove();
}

function bmkDelete(btn) {
  const row = btn.closest('.bmk-row');
  const id  = row.dataset.id;
  appConfirm('Delete Bookmark', 'Delete this bookmark?', null, async () => {
    btn.disabled = true;
    const res = await bmkPost({ action: 'delete', id });
    if (!res.ok) { btn.disabled = false; showToast(res.error || 'Error', 'error'); return; }
    row.style.transition = 'opacity .2s';
    row.style.opacity = '0';
    setTimeout(() => row.remove(), 220);
  }, 'Delete');
}

// Bookmark drag-to-reorder (event-delegated, initialised once)
(function() {
  const list = document.getElementById('bmkList');
  if (!list) return;
  let dragEl = null, canDrag = false;
  list.addEventListener('mousedown', e => { canDrag = !!e.target.closest('.bmk-drag-handle'); });
  list.addEventListener('dragstart', e => {
    const row = e.target.closest('.bmk-row[data-id]');
    if (!row || !canDrag) { e.preventDefault(); return; }
    dragEl = row; e.dataTransfer.effectAllowed = 'move';
    setTimeout(() => row.classList.add('sortable-drag'), 0);
  });
  list.addEventListener('dragover', e => {
    if (!dragEl) return;
    const row = e.target.closest('.bmk-row[data-id]');
    if (!row || row === dragEl) return;
    e.preventDefault();
    list.querySelectorAll('.bmk-row.sortable-over').forEach(r => r.classList.remove('sortable-over'));
    row.classList.add('sortable-over');
  });
  list.addEventListener('drop', e => {
    e.preventDefault();
    const row = e.target.closest('.bmk-row[data-id]');
    list.querySelectorAll('.bmk-row.sortable-over').forEach(r => r.classList.remove('sortable-over'));
    if (!row || !dragEl || row === dragEl) return;
    const all = [...list.querySelectorAll('.bmk-row[data-id]')];
    if (all.indexOf(dragEl) < all.indexOf(row)) list.insertBefore(dragEl, row.nextSibling);
    else list.insertBefore(dragEl, row);
    bmkPost({ action: 'reorder', ids: [...list.querySelectorAll('.bmk-row[data-id]')].map(r => r.dataset.id).join(',') });
  });
  list.addEventListener('dragend', () => {
    if (dragEl) dragEl.classList.remove('sortable-drag');
    list.querySelectorAll('.bmk-row.sortable-over').forEach(r => r.classList.remove('sortable-over'));
    dragEl = null; canDrag = false;
  });
})();

// ── Remove favorite report ─────────────────────────────────────
function removeFavReport(btn) {
  appConfirm('Remove Report', 'Remove this report from the dashboard?', null, () => {
    btn.disabled = true;
    fetch(BASE_PATH + '/reports/favorite_save', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: btn.dataset.csrf, action: 'remove', id: btn.dataset.id })
    })
    .then(r => r.json())
    .then(json => {
      if (json.ok) {
        const card = btn.closest('.fav-report-card');
        card.style.transition = 'opacity .25s'; card.style.opacity = '0';
        setTimeout(() => {
          card.remove();
          const grid = document.querySelector('.fav-reports-grid');
          if (grid && !grid.children.length) {
            const tile = document.querySelector('.dash-tile[data-widget="fav_reports"] .dash-tile-body');
            if (tile) tile.innerHTML = '<p class="text-muted small">No favorite reports. <a href="' + BASE_PATH + '/reports/index">Browse reports.</a></p>';
          }
        }, 260);
      } else { showToast(json.error || 'Error removing report.', 'error'); btn.disabled = false; }
    })
    .catch((e) => { console.error(e); showToast('Network error.', 'error'); btn.disabled = false; });
  }, 'Remove');
}
</script>
<?php
$_needChartJs = !empty(array_intersect([...$_investWidgets, 'monthly_spending', 'portfolio_snapshot', 'asset_allocation'], array_keys($_visSet)));
if ($_needChartJs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php endif; ?>
<?php if (!empty(array_intersect($_investWidgets, array_keys($_visSet)))): ?>
<?php $db = getDB(); include __DIR__ . '/includes/price_history_modal.php'; ?>
<?php endif; ?>

<!-- ── Widget maximize modal ────────────────────────────────── -->
<div class="modal fade" id="widgetMaxModal" tabindex="-1" aria-labelledby="widgetMaxModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="widgetMaxModalLabel"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="widgetMaxModalBody" style="padding:1.25rem"></div>
    </div>
  </div>
</div>

<script>
function maximizeWidget(btn) {
  const tile  = btn.closest('.dash-tile');
  const title = tile.querySelector('.dash-tile-title').textContent.trim();
  const body  = tile.querySelector('.dash-tile-body');

  const clone = body.cloneNode(true);

  // Convert any live canvases to static images so charts are visible in the modal
  const srcCanvases   = body.querySelectorAll('canvas');
  const cloneCanvases = clone.querySelectorAll('canvas');
  srcCanvases.forEach((cv, i) => {
    try {
      const img = document.createElement('img');
      img.src   = cv.toDataURL('image/png');
      img.style.cssText = 'width:100%;height:auto;display:block';
      cloneCanvases[i].replaceWith(img);
    } catch (e) {}
  });

  document.getElementById('widgetMaxModalLabel').textContent = title;
  const modalBody = document.getElementById('widgetMaxModalBody');
  modalBody.innerHTML = '';
  modalBody.appendChild(clone);

  bootstrap.Modal.getOrCreateInstance(document.getElementById('widgetMaxModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

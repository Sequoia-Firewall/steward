<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$pageTitle   = 'Preferences';
$currentPage = 'preferences';

$instanceName         = getSetting('instance_name', '');
$usersCanImport          = getSetting('users_can_import') === '1';
$usersCanDeleteTxns      = getSetting('users_can_delete_transactions', '1') === '1';
$usersCanManageBudgets   = getSetting('users_can_manage_budgets', '1') === '1';
$enforceHttps         = getSetting('enforce_https') === '1';
$sessionTimeout       = (int)getSetting('session_timeout_minutes', '0');
$logRetentionDays     = (int)getSetting('log_retention_days', '90');
$sidebarBalance       = getSetting('sidebar_balance', 'ending');
$negativeFormat       = getSetting('negative_format', 'color');
$registerFormTop      = getSetting('register_form_top') === '1';
$registerSortDesc     = getSetting('register_sort_desc') === '1';
$sidebarHideCash      = getSetting('sidebar_hide_investment_cash') === '1';
$sidebarCashInBalance = getSetting('sidebar_cash_in_investment_balance') === '1';
$navHideLoans         = getSetting('nav_hide_loans') === '1';
$navHideGoals         = getSetting('nav_hide_goals') === '1';
$navSearchIconOnly    = getSetting('nav_search_icon_only') === '1';
$colorScheme          = getSetting('color_scheme', 'blue');
$loginBgTs            = getSetting('login_bg');
$hasCustomBg          = $loginBgTs && file_exists(__DIR__ . '/../assets/img/login_bg_custom.jpg');
$timezoneSetting      = getSetting('timezone', 'America/New_York');
$currencySymbol       = getSetting('currency_symbol', '$') ?: '$';
$quoteAutoFetch       = getSetting('quote_auto_fetch') === '1';
$validQuoteTimes      = ['16:15', '16:30', '17:00', '17:30', '18:00'];
$quoteFetchTime       = getSetting('quote_fetch_time', '16:15');
if (!in_array($quoteFetchTime, $validQuoteTimes, true)) $quoteFetchTime = '16:15';
$quoteLastFetched     = getSetting('price_last_fetched');

// Build grouped timezone list
$_tzGroups = [];
foreach (DateTimeZone::listIdentifiers() as $_tz) {
    $_parts = explode('/', $_tz, 2);
    $_group = count($_parts) > 1 ? $_parts[0] : 'Other';
    $_tzGroups[$_group][] = $_tz;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-sliders"></i> Preferences</h1>
      <p class="text-muted mb-0 mt-1">Application-wide settings that control behaviour across all users.</p>
    </div>
  </div>

  <?= renderFlash() ?>

  <form method="post" action="<?= BASE_PATH ?>/settings/preferences_save" id="preferencesForm">
    <?= csrfField() ?>

    <!-- ── General ─────────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header"><strong><i class="bi bi-house"></i> General</strong></div>
      <div class="card-body">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="instance_name">Instance Name</label>
            <p class="text-muted small mb-0">
              A short name for this installation, shown in the top navigation bar beneath
              "<?= h(APP_NAME) ?>". Useful when running multiple instances or to personalise the app.
            </p>
          </div>
          <div class="col-md-8">
            <input type="text" class="form-control" id="instance_name" name="instance_name"
                   value="<?= h($instanceName) ?>"
                   placeholder="e.g. Smith Family Finances"
                   maxlength="100">
            <div class="form-text">Leave blank to show no subtitle.</div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="timezone">Time Zone</label>
            <p class="text-muted small mb-0">
              Controls how dates and times are displayed throughout the application.
            </p>
          </div>
          <div class="col-md-8">
            <select class="form-select" id="timezone" name="timezone">
              <?php foreach ($_tzGroups as $_group => $_tzList): ?>
              <optgroup label="<?= h($_group) ?>">
                <?php foreach ($_tzList as $_tz): ?>
                <option value="<?= h($_tz) ?>"<?= $timezoneSetting === $_tz ? ' selected' : '' ?>><?= h($_tz) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Automatic Quote Retrieval</label>
            <p class="text-muted small mb-0">
              Fetch the latest prices for all active investments each weekday after market close.
              The US market closes at 4:00 PM Eastern Time. Requires a price provider to be configured.
            </p>
          </div>
          <div class="col-md-8 d-flex flex-column gap-2 justify-content-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="quote_auto_fetch" name="quote_auto_fetch" value="1"
                     <?= $quoteAutoFetch ? 'checked' : '' ?>
                     onchange="document.getElementById('quote_time_row').style.display = this.checked ? '' : 'none'">
              <label class="form-check-label" for="quote_auto_fetch">
                Automatically refresh prices each weekday
              </label>
            </div>
            <div id="quote_time_row"<?= $quoteAutoFetch ? '' : ' style="display:none"' ?>>
              <label class="small fw-semibold mb-1 d-block">Fetch time (Eastern Time)</label>
              <select class="form-select form-select-sm" name="quote_fetch_time" style="max-width:200px">
                <?php
                $quoteTimeLabels = [
                    '16:15' => '4:15 PM ET',
                    '16:30' => '4:30 PM ET',
                    '17:00' => '5:00 PM ET',
                    '17:30' => '5:30 PM ET',
                    '18:00' => '6:00 PM ET',
                ];
                foreach ($quoteTimeLabels as $val => $lbl): ?>
                <option value="<?= $val ?>"<?= $quoteFetchTime === $val ? ' selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($quoteLastFetched): ?>
              <div class="form-text">Last fetched: <?= h($quoteLastFetched) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="currency_symbol">Currency Symbol</label>
            <p class="text-muted small mb-0">
              Symbol prepended to all monetary amounts throughout the application.
              Common examples: $ &euro; &pound; &yen; &#8377; &#8361; Fr
            </p>
          </div>
          <div class="col-md-8">
            <input type="text" class="form-control" id="currency_symbol" name="currency_symbol"
                   value="<?= h($currencySymbol) ?>" maxlength="5" style="max-width:80px">
            <div class="form-text">1–5 characters. Default: $</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Permissions ──────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header"><strong><i class="bi bi-shield-check"></i> Permissions</strong></div>
      <div class="card-body">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="users_can_import">User Import &amp; Account Creation</label>
            <p class="text-muted small mb-0">
              When enabled, users with the <strong>User</strong> role can import transactions
              from files and create new accounts. Administrative controls (delete, user
              management, settings) remain restricted to administrators.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="users_can_import" name="users_can_import" value="1"
                     <?= $usersCanImport ? 'checked' : '' ?>>
              <label class="form-check-label" for="users_can_import">
                Allow regular users to import transactions and create accounts
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="users_can_manage_budgets">Budget Management</label>
            <p class="text-muted small mb-0">
              When enabled, users with the <strong>User</strong> role can create and edit
              budgets. When disabled, only administrators can create or modify budgets;
              regular users can still view them.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="users_can_manage_budgets" name="users_can_manage_budgets" value="1"
                     <?= $usersCanManageBudgets ? 'checked' : '' ?>>
              <label class="form-check-label" for="users_can_manage_budgets">
                Allow regular users to create and edit budgets
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="users_can_delete_transactions">Transaction Deletion</label>
            <p class="text-muted small mb-0">
              Controls whether regular users can delete transactions.
              Reconciled transactions are always protected regardless of this setting.
              If users can import but not delete, an administrator will need to remove
              any incorrectly imported transactions.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="users_can_delete_transactions" name="users_can_delete_transactions" value="1"
                     <?= $usersCanDeleteTxns ? 'checked' : '' ?>>
              <label class="form-check-label" for="users_can_delete_transactions">
                Allow regular users to delete transactions
              </label>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Security ──────────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header"><strong><i class="bi bi-shield-lock"></i> Security</strong></div>
      <div class="card-body">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="enforce_https">Enforce HTTPS</label>
            <p class="text-muted small mb-0">
              Redirects all HTTP requests to HTTPS (301). Does not validate the certificate —
              it only prevents unencrypted access to the application.
            </p>
          </div>
          <div class="col-md-8 d-flex flex-column gap-2 justify-content-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="enforce_https" name="enforce_https" value="1"
                     <?= $enforceHttps ? 'checked' : '' ?>>
              <label class="form-check-label" for="enforce_https">
                Redirect HTTP requests to HTTPS
              </label>
            </div>
            <div class="alert alert-warning small py-2 mb-0 d-flex gap-2 align-items-start">
              <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
              <div>
                <strong>Only enable this if HTTPS is already working on your server.</strong>
                If you enable this without a working HTTPS setup you will be locked out.
                To recover, set <code>enforce_https</code> to <code>0</code> directly in the
                <code>settings</code> database table.
              </div>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="session_timeout_minutes">Session Timeout</label>
            <p class="text-muted small mb-0">
              Automatically signs out users after a period of inactivity.
              The timer resets on every page load. Applies to all users.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <select class="form-select" id="session_timeout_minutes" name="session_timeout_minutes" style="max-width:220px">
              <?php
              $timeoutOptions = [
                  0    => 'Never (disabled)',
                  15   => '15 minutes',
                  30   => '30 minutes',
                  60   => '1 hour',
                  120  => '2 hours',
                  240  => '4 hours',
                  480  => '8 hours',
                  1440 => '24 hours',
              ];
              foreach ($timeoutOptions as $val => $label):
              ?>
              <option value="<?= $val ?>" <?= $sessionTimeout === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="log_retention_days">Activity Log Retention</label>
            <p class="text-muted small mb-0">
              How long to keep activity log entries. Older records are removed automatically.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <select class="form-select" id="log_retention_days" name="log_retention_days" style="max-width:220px">
              <?php
              $retentionOptions = [
                  30  => '30 days',
                  90  => '90 days',
                  180 => '6 months',
                  365 => '1 year',
                  0   => 'Forever',
              ];
              foreach ($retentionOptions as $val => $label):
              ?>
              <option value="<?= $val ?>" <?= $logRetentionDays === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Display ───────────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header"><strong><i class="bi bi-layout-sidebar"></i> Display</strong></div>
      <div class="card-body">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Sidebar Account Balance</label>
            <p class="text-muted small mb-0">
              Controls which balance is shown for each account in the left navigation sidebar.
              <strong>Ending Balance</strong> includes all transactions (including future-dated).
              <strong>Current Balance</strong> only includes transactions up to today.
            </p>
          </div>
          <div class="col-md-8 d-flex flex-column gap-2 justify-content-center" style="min-height:56px">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="sidebar_balance" id="sb_ending"
                     value="ending" <?= $sidebarBalance !== 'current' ? 'checked' : '' ?>>
              <label class="form-check-label" for="sb_ending">
                <strong>Ending Balance</strong> — all transactions including future-dated
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="sidebar_balance" id="sb_current"
                     value="current" <?= $sidebarBalance === 'current' ? 'checked' : '' ?>>
              <label class="form-check-label" for="sb_current">
                <strong>Current Balance</strong> — transactions up to today only
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="sidebar_hide_investment_cash">Investment Cash Accounts</label>
            <p class="text-muted small mb-0">
              Investment accounts with a companion cash sub-account show both entries in the
              sidebar by default. Enable this to show only the investment account and hide
              the cash sub-account from the menu.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="sidebar_hide_investment_cash" name="sidebar_hide_investment_cash" value="1"
                     <?= $sidebarHideCash ? 'checked' : '' ?>>
              <label class="form-check-label" for="sidebar_hide_investment_cash">
                Hide companion cash accounts from the sidebar
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="sidebar_cash_in_investment_balance">Investment Balance Includes Cash</label>
            <p class="text-muted small mb-0">
              By default the investment account balance shows holdings market value only.
              Enable this to add the companion cash sub-account balance to the investment
              account's total in the sidebar.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="sidebar_cash_in_investment_balance" name="sidebar_cash_in_investment_balance" value="1"
                     <?= $sidebarCashInBalance ? 'checked' : '' ?>>
              <label class="form-check-label" for="sidebar_cash_in_investment_balance">
                Include cash account balance in investment account total
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Top Menu Items</label>
            <p class="text-muted small mb-0">
              Choose which items appear in the top navigation bar.
            </p>
          </div>
          <div class="col-md-8 d-flex flex-column gap-2 justify-content-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="nav_hide_loans" name="nav_hide_loans" value="1"
                     <?= $navHideLoans ? 'checked' : '' ?>>
              <label class="form-check-label" for="nav_hide_loans">
                Hide <strong>Loans</strong> from top menu
              </label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="nav_hide_goals" name="nav_hide_goals" value="1"
                     <?= $navHideGoals ? 'checked' : '' ?>>
              <label class="form-check-label" for="nav_hide_goals">
                Hide <strong>Goals</strong> from top menu
              </label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="nav_search_icon_only" name="nav_search_icon_only" value="1"
                     <?= $navSearchIconOnly ? 'checked' : '' ?>>
              <label class="form-check-label" for="nav_search_icon_only">
                Display <strong>Search</strong> as icon only (no label)
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Negative Number Format</label>
            <p class="text-muted small mb-0">
              Controls how negative monetary amounts are displayed throughout the application.
            </p>
          </div>
          <div class="col-md-8 d-flex flex-column gap-2 justify-content-center" style="min-height:56px">
            <?php
            $negOpts = [
                'color'     => ['label' => 'Color only',             'preview' => '<span class="amount-debit">$1,234.56</span>',  'desc' => 'Red text, no sign (current default)'],
                'minus'     => ['label' => 'Minus sign + color',     'preview' => '<span class="amount-debit">-$1,234.56</span>', 'desc' => 'Red text with a leading minus sign'],
                'parens'    => ['label' => 'Parentheses + color',    'preview' => '<span class="amount-debit">($1,234.56)</span>','desc' => 'Red text in accounting parentheses'],
                'parens-bw' => ['label' => 'Parentheses, no color',  'preview' => '($1,234.56)',                                  'desc' => 'Black accounting parentheses (classic ledger style)'],
            ];
            foreach ($negOpts as $val => $opt): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="negative_format"
                     id="negfmt_<?= $val ?>" value="<?= $val ?>"
                     <?= $negativeFormat === $val ? 'checked' : '' ?>>
              <label class="form-check-label d-flex align-items-center gap-2" for="negfmt_<?= $val ?>">
                <strong><?= $opt['label'] ?></strong>
                <span class="badge bg-light border text-dark font-monospace"><?= $opt['preview'] ?></span>
                <span class="text-muted small"><?= $opt['desc'] ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="register_form_top">Transaction Form Position</label>
            <p class="text-muted small mb-0">
              Controls where the new transaction entry form appears on account register pages.
              When enabled, the form appears above the transaction history instead of below it.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="register_form_top" name="register_form_top" value="1"
                     <?= $registerFormTop ? 'checked' : '' ?>>
              <label class="form-check-label" for="register_form_top">
                Show transaction entry form above transaction history
              </label>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="register_sort_desc">Register History Sort Order</label>
            <p class="text-muted small mb-0">
              Controls the default sort order for transaction history in all account registers.
              When enabled, the most recent transactions appear at the top.
              Per-account sort choices made by clicking column headers override this default.
            </p>
          </div>
          <div class="col-md-8 d-flex align-items-center" style="min-height:56px">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="register_sort_desc" name="register_sort_desc" value="1"
                     <?= $registerSortDesc ? 'checked' : '' ?>>
              <label class="form-check-label" for="register_sort_desc">
                Show most recent transactions first (newest on top)
              </label>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Appearance ────────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header"><strong><i class="bi bi-palette"></i> Appearance</strong></div>
      <div class="card-body">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Color Scheme</label>
            <p class="text-muted small mb-0">
              Sets the application-wide color theme for all users.
              The page will reload to preview the selected scheme.
            </p>
          </div>
          <div class="col-md-8">
            <div class="color-scheme-picker">
              <?php
              $schemes = [
                  'blue'  => ['label' => 'Blue',  'dark' => '#1a3a5c', 'mid' => '#1e4976', 'lt' => '#2563a8'],
                  'green' => ['label' => 'Green', 'dark' => '#1a4a2a', 'mid' => '#1f5c34', 'lt' => '#2a7a48'],
                  'red'   => ['label' => 'Red',   'dark' => '#5c1a1a', 'mid' => '#7a2020', 'lt' => '#a83a3a'],
                  'gray'  => ['label' => 'Gray',  'dark' => '#2d3748', 'mid' => '#3a4a5c', 'lt' => '#556070'],
                  'brown' => ['label' => 'Brown', 'dark' => '#3d2314', 'mid' => '#5a3420', 'lt' => '#7d4c30'],
              ];
              foreach ($schemes as $key => $s):
                  $checked = ($colorScheme === $key || ($colorScheme === '' && $key === 'blue')) ? 'checked' : '';
              ?>
              <label class="color-scheme-option <?= $checked ? 'selected' : '' ?>" title="<?= $s['label'] ?>">
                <input type="radio" name="color_scheme" value="<?= $key ?>" <?= $checked ?>>
                <span class="color-scheme-swatch">
                  <span style="background:<?= $s['dark'] ?>"></span>
                  <span style="background:<?= $s['mid'] ?>"></span>
                  <span style="background:<?= $s['lt'] ?>"></span>
                </span>
                <span class="color-scheme-label"><?= $s['label'] ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row g-3 align-items-start">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Login Background Image</label>
            <p class="text-muted small mb-0">
              Upload a custom background photo for the sign-in page. JPEG, PNG, or WebP.
              Leave unset to use the default image.
            </p>
          </div>
          <div class="col-md-8 d-flex flex-column gap-2 justify-content-center" style="min-height:56px">
            <?php if ($hasCustomBg): ?>
            <div>
              <img src="<?= BASE_PATH ?>/assets/img/login_bg_custom.jpg?v=<?= h($loginBgTs) ?>"
                   alt="Custom login background"
                   style="max-height:80px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.15)">
            </div>
            <?php else: ?>
            <p class="text-muted small mb-0">Using the default background image.</p>
            <?php endif; ?>
            <div class="d-flex gap-2 flex-wrap">
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      onclick="document.getElementById('loginBgFileInput').click()">
                <i class="bi bi-upload"></i> <?= $hasCustomBg ? 'Replace Image' : 'Upload Image' ?>
              </button>
              <?php if ($hasCustomBg): ?>
              <button type="button" class="btn btn-sm btn-outline-danger"
                      onclick="document.getElementById('loginBgDeleteForm').submit()">
                <i class="bi bi-trash"></i> Remove
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="mt-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> Save Preferences
      </button>
    </div>
  </form>

  <form id="loginBgUploadForm" method="post" action="<?= BASE_PATH ?>/settings/login_bg_upload"
        enctype="multipart/form-data" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" id="loginBgFileInput" name="login_bg_image" accept="image/jpeg,image/png,image/webp">
  </form>
  <form id="loginBgDeleteForm" method="post" action="<?= BASE_PATH ?>/settings/login_bg_upload"
        style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
  </form>
  <script>
  document.getElementById('loginBgFileInput').addEventListener('change', function() {
    if (this.files.length) document.getElementById('loginBgUploadForm').submit();
  });
  </script>


</div>

<script>
document.querySelectorAll('.color-scheme-option input[type="radio"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    document.querySelectorAll('.color-scheme-option').forEach(function(opt) {
      opt.classList.remove('selected');
    });
    if (this.checked) {
      this.closest('.color-scheme-option').classList.add('selected');
    }
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$action = $_POST['action'] ?? '';
$userId = currentUserId();

const VALID_WIDGETS = [
    'fav_accounts', 'fav_reports',
    'recent_transactions', 'monthly_spending', 'budget',
    'upcoming_bills', 'all_accounts',
    'key_indicators', 'goals', 'loans',
    'market_indexes', 'top_movers', 'portfolio_movers', 'most_active',
    'crypto', 'watchlist',
    'portfolio_snapshot', 'asset_allocation',
    'bookmarks', 'notepad',
];

if ($action === 'save') {
    $rawOrder  = $_POST['order']  ?? '';
    $rawHidden = $_POST['hidden'] ?? '';

    $order = array_values(array_filter(
        array_map('trim', explode(',', $rawOrder)),
        fn($w) => in_array($w, VALID_WIDGETS, true)
    ));
    $hidden = array_values(array_filter(
        array_map('trim', explode(',', $rawHidden)),
        fn($w) => in_array($w, VALID_WIDGETS, true)
    ));

    setUserPref($userId, 'dashboard_order',  implode(',', $order));
    setUserPref($userId, 'dashboard_hidden', implode(',', $hidden));

    // Account filters
    $parseIds = fn(string $raw) => $raw !== ''
        ? array_values(array_filter(array_map('intval', explode(',', $raw))))
        : [];
    setUserPref($userId, 'dashboard_acct_recent', implode(',', $parseIds(trim($_POST['acct_recent'] ?? ''))));
    setUserPref($userId, 'dashboard_acct_spend',  implode(',', $parseIds(trim($_POST['acct_spend']  ?? ''))));

    // Favorite account order
    $rawFavAccts = $_POST['fav_accounts'] ?? '';
    if ($rawFavAccts !== '') {
        $acctIds = array_values(array_filter(array_map('intval', explode(',', $rawFavAccts))));
        setUserPref($userId, 'fav_accounts_order', implode(',', $acctIds));
    }

    // Favorite report order
    $rawFavRpts = $_POST['fav_reports'] ?? '';
    if ($rawFavRpts !== '') {
        $rptIds = array_values(array_filter(array_map('intval', explode(',', $rawFavRpts))));
        $db  = getDB();
        $upd = $db->prepare('UPDATE favorite_reports SET sort_order = ? WHERE id = ?');
        foreach ($rptIds as $pos => $id) {
            $upd->execute([$pos, $id]);
        }
    }

    echo json_encode(['ok' => true]);

} elseif ($action === 'save_pref') {
    $key   = trim($_POST['key']   ?? '');
    $value = trim($_POST['value'] ?? '');
    $allowed = ['dashboard_aa_view', 'dashboard_sort_watchlist', 'dashboard_sort_top_movers', 'dashboard_sort_portfolio_movers'];
    if (!in_array($key, $allowed, true)) { echo json_encode(['ok'=>false]); exit; }
    setUserPref($userId, $key, $value);
    echo json_encode(['ok'=>true]);

} elseif ($action === 'reset') {
    setUserPref($userId, 'dashboard_order',       null);
    setUserPref($userId, 'dashboard_hidden',      '');   // '' means explicitly no hidden widgets; null means never configured
    setUserPref($userId, 'dashboard_acct_recent', null);
    setUserPref($userId, 'dashboard_acct_spend',  null);
    setUserPref($userId, 'fav_accounts_order',    null);

    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

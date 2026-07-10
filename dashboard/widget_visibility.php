<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
verifyCsrf();

$userId = currentUserId();
$action = $_POST['action'] ?? '';
$widget = trim($_POST['widget'] ?? '');

$validWidgets = ['portfolio_snapshot', 'asset_allocation'];
if (!in_array($widget, $validWidgets, true)) { echo json_encode(['ok'=>false,'error'=>'Invalid widget']); exit; }

if ($action === 'show') {
    // Remove widget from hidden list
    $hidden = array_filter(
        array_map('trim', explode(',', getUserPref($userId, 'dashboard_hidden', '') ?? '')),
        fn($w) => $w !== '' && $w !== $widget
    );
    setUserPref($userId, 'dashboard_hidden', implode(',', array_values($hidden)));

    // Save widget-specific prefs
    if ($widget === 'portfolio_snapshot') {
        $accts = trim($_POST['accts'] ?? '');
        $excl  = trim($_POST['exclude_types'] ?? '');
        setUserPref($userId, 'dashboard_ps_accts', $accts);
        setUserPref($userId, 'dashboard_ps_exclude', $excl);
    }
    echo json_encode(['ok'=>true]);
} else {
    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
}

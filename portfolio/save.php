<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
    exit;
}

verifyCsrf();

$id             = (int)($_POST['id']             ?? 0);
$name           = trim($_POST['name']           ?? '');
$symbol         = trim($_POST['symbol']         ?? '');
$cusip          = strtoupper(trim($_POST['cusip'] ?? '')) ?: null;
$type           = $_POST['type']                ?? 'Stock';
$country        = trim($_POST['country']        ?? '');
$memo           = trim($_POST['memo']           ?? '') ?: null;
$disableQuotes  = isset($_POST['disable_quotes']) ? 1 : 0;
$inWatchlist    = isset($_POST['in_watchlist'])   ? 1 : 0;

$validTypes = ['Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Stock','Index','Cryptocurrency','Other'];

if (!$name)                             { echo json_encode(['ok'=>false,'error'=>'Name is required.']);         exit; }
if (!in_array($type, $validTypes, true)) { echo json_encode(['ok'=>false,'error'=>'Invalid investment type.']); exit; }
if ($cusip !== null && !preg_match('/^[A-Z0-9]{9}$/', $cusip)) {
    echo json_encode(['ok'=>false,'error'=>'CUSIP must be exactly 9 alphanumeric characters.']); exit;
}

try {
    $db = getDB();
    if ($id) {
        $db->prepare(
            'UPDATE investments SET name=?, symbol=?, cusip=?, type=?, country=?, memo=?, disable_quotes=?, in_watchlist=? WHERE id=?'
        )->execute([$name, $symbol, $cusip, $type, $country, $memo, $disableQuotes, $inWatchlist, $id]);
    } else {
        $db->prepare(
            'INSERT INTO investments (name, symbol, cusip, type, country, memo, disable_quotes, in_watchlist, created_by) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$name, $symbol, $cusip, $type, $country, $memo, $disableQuotes, $inWatchlist, currentUserId()]);
        $id = (int)$db->lastInsertId();
    }
    echo json_encode(['ok' => true, 'investment' => [
        'id'             => $id,
        'name'           => $name,
        'symbol'         => $symbol,
        'cusip'          => $cusip ?? '',
        'type'           => $type,
        'country'        => $country,
        'memo'           => $memo ?? '',
        'disable_quotes' => $disableQuotes,
        'in_watchlist'   => $inWatchlist,
    ]]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

verifyCsrf();

$keepId   = (int)($_POST['keep_id']   ?? 0);
$absorbId = (int)($_POST['absorb_id'] ?? 0);

if ($keepId <= 0 || $absorbId <= 0 || $keepId === $absorbId) {
    echo json_encode(['ok' => false, 'error' => 'Select two different investments']);
    exit;
}

$db = getDB();

$stmt = $db->prepare('SELECT id, name, symbol FROM investments WHERE id IN (?, ?) ORDER BY id');
$stmt->execute([$keepId, $absorbId]);
$found = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($found) !== 2) {
    echo json_encode(['ok' => false, 'error' => 'One or both investments not found']);
    exit;
}
$foundById = array_column($found, null, 'id');
$keeperName = $foundById[$keepId]['name'];

try {
    $db->beginTransaction();

    // Move all transactions to the keeper and rename payee to the keeper's investment name
    $stmt = $db->prepare('UPDATE investment_transactions SET investment_id = ? WHERE investment_id = ?');
    $stmt->execute([$keepId, $absorbId]);
    $txnMoved = $stmt->rowCount();

    $stmt = $db->prepare(
        'UPDATE transactions t
            JOIN investment_transactions it ON it.transaction_id = t.id
            SET t.payee = ?
          WHERE it.investment_id = ?'
    );
    $stmt->execute([$keeperName, $keepId]);

    // Move prices for dates not already present on the keeper
    $stmt = $db->prepare('
        UPDATE investment_prices
           SET investment_id = ?
         WHERE investment_id = ?
           AND price_date NOT IN (
               SELECT price_date FROM (
                   SELECT price_date FROM investment_prices WHERE investment_id = ?
               ) AS sub
           )
    ');
    $stmt->execute([$keepId, $absorbId, $keepId]);
    $pricesMoved = $stmt->rowCount();

    // Drop any remaining prices for the absorbed investment (date conflicts)
    $stmt = $db->prepare('DELETE FROM investment_prices WHERE investment_id = ?');
    $stmt->execute([$absorbId]);

    // Delete the absorbed investment
    $stmt = $db->prepare('DELETE FROM investments WHERE id = ?');
    $stmt->execute([$absorbId]);

    $db->commit();

    echo json_encode([
        'ok'           => true,
        'txn_moved'    => $txnMoved,
        'prices_moved' => $pricesMoved,
    ]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

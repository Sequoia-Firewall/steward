<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$action       = $_POST['action'] ?? 'save';
$investmentId = (int)($_POST['investment_id'] ?? 0);

if (!$investmentId) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields.']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare('SELECT id FROM investments WHERE id = ? AND is_active = 1');
$stmt->execute([$investmentId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Investment not found.']);
    exit;
}

if ($action === 'upload_csv') {
    $rows = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rows) || empty($rows)) {
        echo json_encode(['ok' => false, 'error' => 'No data provided.']);
        exit;
    }

    $upsert = $db->prepare(
        'INSERT INTO investment_prices
           (investment_id, price_date, open_price, high_price, low_price, close_price, volume, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           open_price  = VALUES(open_price),  high_price = VALUES(high_price),
           low_price   = VALUES(low_price),   close_price = VALUES(close_price),
           volume      = VALUES(volume),      source = VALUES(source),
           updated_at  = NOW()'
    );

    $saved   = 0;
    $skipped = 0;
    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            $date  = trim($row['date']  ?? '');
            $close = trim($row['close'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }
            $closeVal = (float)$close;
            if ($closeVal <= 0) { $skipped++; continue; }

            $upsert->execute([
                $investmentId,
                $date,
                ($row['open']   ?? '') !== '' ? (float)$row['open']   : null,
                ($row['high']   ?? '') !== '' ? (float)$row['high']   : null,
                ($row['low']    ?? '') !== '' ? (float)$row['low']    : null,
                $closeVal,
                ($row['volume'] ?? '') !== '' ? (int)$row['volume']   : null,
                ($row['source'] ?? '') !== '' ? $row['source']        : 'manual',
            ]);
            $saved++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => true, 'saved' => $saved, 'skipped' => $skipped]);
    exit;
}

// save / delete actions require price_date
$priceDate = trim($_POST['price_date'] ?? '');
if (!$priceDate) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $priceDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date format.']);
    exit;
}

if ($action === 'delete') {
    $db->prepare('DELETE FROM investment_prices WHERE investment_id = ? AND price_date = ?')
       ->execute([$investmentId, $priceDate]);
    echo json_encode(['ok' => true]);
    exit;
}

// save (add or update)
$price = trim($_POST['price'] ?? '');
if ($price === '') {
    echo json_encode(['ok' => false, 'error' => 'Price is required.']);
    exit;
}
$priceVal = (float)$price;
if ($priceVal <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Price must be greater than zero.']);
    exit;
}

$db->prepare(
    'INSERT INTO investment_prices (investment_id, price_date, close_price, source)
     VALUES (?, ?, ?, \'manual\')
     ON DUPLICATE KEY UPDATE close_price = VALUES(close_price), source = \'manual\', updated_at = NOW()'
)->execute([$investmentId, $priceDate, $priceVal]);

echo json_encode(['ok' => true]);

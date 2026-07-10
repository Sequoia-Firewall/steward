<?php
/**
 * Converter → Steward handoff endpoint.
 *
 * Receives the same JSON payload as export.php, generates the Steward CSV,
 * writes it to storage/tmp/{token}.csv, and returns a JSON redirect URL
 * that fast_import.php can pick up via the pickup_token query parameter.
 *
 * Files are written with a 32-char md5 token and consumed on first read
 * by fast_import.php. Leftover files older than 15 minutes are pruned
 * on each request.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../src/StewardCsvExporter.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new RuntimeException('Invalid request body.');
    }

    $type = $input['type'] ?? 'holdings';

    if ($type === 'history') {
        $transactions = $input['transactions'] ?? [];
        if (empty($transactions)) throw new RuntimeException('No transactions to export.');
        $csv = StewardCsvExporter::generateHistoryCsv($transactions);
    } else {
        $date     = $input['date'] ?? date('Y-m-d');
        $holdings = $input['holdings'] ?? [];
        if (empty($holdings)) throw new RuntimeException('No holdings to export.');
        $csv = StewardCsvExporter::generate($date, $holdings);
    }

    $tmpDir = realpath(__DIR__ . '/../storage/tmp');
    if ($tmpDir === false) {
        throw new RuntimeException('Handoff directory not found.');
    }

    // Prune stale files older than 15 minutes
    foreach (glob($tmpDir . '/*.csv') as $stale) {
        if (filemtime($stale) < time() - 900) {
            @unlink($stale);
        }
    }

    $token    = bin2hex(random_bytes(16));  // 32-char hex
    $filePath = $tmpDir . '/' . $token . '.csv';

    if (file_put_contents($filePath, $csv) === false) {
        throw new RuntimeException('Could not write handoff file.');
    }

    // Derive the app root from this script's own URL (.../<appRoot>/converter/api/send_to_steward.php)
    // rather than hardcoding a base path, so this works under any install path.
    $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/converter/api/send_to_steward.php');
    $appRoot = rtrim(dirname(dirname(dirname($script))), '/');

    echo json_encode([
        'success' => true,
        'url'     => $appRoot . '/import/fast_import.php?pickup_token=' . $token,
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

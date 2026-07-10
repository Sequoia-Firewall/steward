<?php
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
        $csv      = StewardCsvExporter::generateHistoryCsv($transactions);
        $filename = 'steward_history_' . date('Ymd') . '.csv';
    } else {
        $date     = $input['date'] ?? date('Y-m-d');
        $holdings = $input['holdings'] ?? [];
        if (empty($holdings)) throw new RuntimeException('No holdings to export.');
        $csv      = StewardCsvExporter::generate($date, $holdings);
        $filename = 'steward_holdings_' . str_replace('-', '', $date) . '.csv';
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($csv));
    header('Cache-Control: no-store');
    echo $csv;

} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}

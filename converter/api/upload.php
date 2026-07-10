<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../src/CsvReader.php';
require_once __DIR__ . '/../src/BrokerDetector.php';
require_once __DIR__ . '/../src/ValueCleaner.php';
require_once __DIR__ . '/../src/ActionTypeMapper.php';
require_once __DIR__ . '/../src/parsers/FidelityHoldingsParser.php';
require_once __DIR__ . '/../src/parsers/FidelityHistoryParser.php';
require_once __DIR__ . '/../src/parsers/MerrillHoldingsParser.php';
require_once __DIR__ . '/../src/parsers/MerrillHistoryParser.php';

try {
    if (empty($_FILES['file'])) {
        throw new RuntimeException('No file received.');
    }

    $upload = $_FILES['file'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $upload['error']);
    }

    $rows      = CsvReader::readFile($upload['tmp_name']);
    $detection = BrokerDetector::detect($rows);
    $broker    = $detection['broker'];
    $type      = $detection['type'];

    if ($broker === 'unknown' || $type === 'unknown') {
        throw new RuntimeException(
            'Unrecognized file format. ' .
            'Supported: Fidelity Holdings, Fidelity History, Merrill Edge Holdings, Merrill Edge History.'
        );
    }

    $parsed = match (true) {
        $broker === 'fidelity' && $type === 'holdings' => (new FidelityHoldingsParser())->parse($rows),
        $broker === 'fidelity' && $type === 'history'  => (new FidelityHistoryParser())->parse($rows),
        $broker === 'merrill'  && $type === 'holdings' => (new MerrillHoldingsParser())->parse($rows),
        $broker === 'merrill'  && $type === 'history'  => (new MerrillHistoryParser())->parse($rows),
        default => throw new RuntimeException('Unsupported broker/type combination.'),
    };

    $response = [
        'success'    => true,
        'broker'     => $broker,
        'type'       => $type,
        'confidence' => $detection['confidence'],
        'date'       => $parsed['date'],
        'dateSource' => $parsed['date_source'] ?? 'default',
        'accounts'   => $parsed['accounts'],
        'warnings'   => $parsed['warnings'] ?? [],
    ];

    if ($type === 'history') {
        $response['transactions'] = $parsed['transactions'];
    } else {
        $response['holdings'] = $parsed['holdings'];
    }

    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

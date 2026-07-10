<?php
require_once __DIR__ . '/../ValueCleaner.php';

class FidelityHoldingsParser {
    public function parse(array $rows): array {
        $result = [
            'date'        => null,
            'date_source' => 'default',
            'accounts'    => [],
            'holdings'    => [],
            'warnings'    => [],
        ];

        // Find header row: first row where first cell (case-insensitive) is "account number"
        $headerIdx = -1;
        $colMap = [];
        foreach ($rows as $i => $row) {
            if (count($row) < 5) continue;
            if (strtolower($row[0]) === 'account number') {
                $headerIdx = $i;
                foreach ($row as $j => $col) {
                    $colMap[strtolower(trim($col))] = $j;
                }
                break;
            }
        }

        if ($headerIdx === -1) {
            $result['warnings'][] = 'Could not locate Fidelity column header row.';
            return $result;
        }

        $accountsSeen = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            // Single-cell row: check for the date line, then skip
            if (count($row) <= 1) {
                $cell = $row[0] ?? '';
                if (preg_match('/Date downloaded\s+(\w+-\d+-\d{4})/i', $cell, $m)) {
                    $parsed = DateTime::createFromFormat('M-d-Y', $m[1]);
                    if ($parsed) {
                        $result['date'] = $parsed->format('Y-m-d');
                        $result['date_source'] = 'file';
                    }
                }
                continue;
            }

            // Multi-cell footer rows (e.g. legal disclaimer, brokerage notice) —
            // identified by a very long first cell that doesn't start with an account-number pattern
            $firstCell = $row[0] ?? '';
            if (strlen($firstCell) > 50 && !preg_match('/^[A-Z][0-9]/', $firstCell)) {
                // Still scan for date in case it ended up here
                $line = implode(' ', $row);
                if (preg_match('/Date downloaded\s+(\w+-\d+-\d{4})/i', $line, $m)) {
                    $parsed = DateTime::createFromFormat('M-d-Y', $m[1]);
                    if ($parsed) {
                        $result['date'] = $parsed->format('Y-m-d');
                        $result['date_source'] = 'file';
                    }
                }
                continue;
            }

            $symbol = isset($colMap['symbol'])
                ? ValueCleaner::cleanSymbol($row[$colMap['symbol']] ?? '')
                : '';
            if ($symbol === '') continue;

            $acctNum  = ValueCleaner::cleanString($row[$colMap['account number'] ?? 0] ?? '');
            $acctName = ValueCleaner::cleanString($row[$colMap['account name'] ?? 1] ?? '');

            if ($acctNum !== '' && !isset($accountsSeen[$acctNum])) {
                $accountsSeen[$acctNum] = true;
                $result['accounts'][] = ['number' => $acctNum, 'name' => $acctName];
            }

            $qty       = ValueCleaner::cleanNumber($row[$colMap['quantity'] ?? -1] ?? '');
            $price     = ValueCleaner::cleanNumber($row[$colMap['last price'] ?? -1] ?? '');
            $avgCost   = ValueCleaner::cleanNumber($row[$colMap['average cost basis'] ?? -1] ?? '');
            $totalCost = ValueCleaner::cleanNumber($row[$colMap['cost basis total'] ?? -1] ?? '');
            $desc      = ValueCleaner::cleanString($row[$colMap['description'] ?? -1] ?? '');

            // When no cost basis is available (e.g. money-market), append current value to description
            if ($avgCost === '' && $totalCost === '') {
                $curVal = ValueCleaner::cleanNumber($row[$colMap['current value'] ?? -1] ?? '');
                if ($curVal !== '') {
                    $desc = trim($desc . ' [Value: $' . number_format((float)$curVal, 2) . ']');
                }
            }

            $issues = [];
            if ($qty === '')   $issues[] = 'missing quantity';
            if ($price === '') $issues[] = 'missing last price';

            $result['holdings'][] = [
                'account_number'   => $acctNum,
                'account_name'     => $acctName,
                'symbol'           => $symbol,
                'cusip'            => ValueCleaner::isCusip($symbol) ? $symbol : '',
                'description'      => $desc,
                'quantity'         => $qty,
                'last_price'       => $price,
                'avg_cost_basis'   => $avgCost,
                'total_cost_basis' => $totalCost,
                'issues'           => $issues,
            ];
        }

        if (!$result['date']) {
            $result['date'] = date('Y-m-d');
            $result['date_source'] = 'default';
        }

        return $result;
    }
}

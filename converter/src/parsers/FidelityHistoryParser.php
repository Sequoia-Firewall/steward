<?php
require_once __DIR__ . '/../ValueCleaner.php';
require_once __DIR__ . '/../ActionTypeMapper.php';

class FidelityHistoryParser {
    public function parse(array $rows): array {
        $result = [
            'date'         => null,
            'date_source'  => 'default',
            'accounts'     => [],
            'transactions' => [],
            'warnings'     => [],
        ];

        // Header row: first row (within first 10) whose first cell is "run date"
        $headerIdx = -1;
        $colMap    = [];
        foreach ($rows as $i => $row) {
            if ($i > 10 || count($row) < 3) continue;
            if (strtolower(trim($row[0])) === 'run date') {
                $headerIdx = $i;
                foreach ($row as $j => $col) {
                    $colMap[strtolower(trim($col))] = $j;
                }
                break;
            }
        }

        if ($headerIdx === -1) {
            $result['warnings'][] = 'Could not locate Fidelity header row.';
            return $result;
        }

        $hasAccount   = isset($colMap['account number']);
        $accountsSeen = [];
        $unmapped     = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            // Single-cell rows: scan for the "Date downloaded" footer
            if (count($row) <= 1) {
                $this->extractDate($row[0] ?? '', $result);
                continue;
            }

            // Skip rows that don't start with a date (footer disclaimers, blank rows)
            if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim($row[0] ?? ''))) {
                $this->extractDate(implode(' ', $row), $result);
                continue;
            }

            $runDate    = $this->parseDate($row[0]);
            $settleDate = $this->parseDate($row[$colMap['settlement date'] ?? -1] ?? '');
            $fullAction = ValueCleaner::cleanString($row[$colMap['action']        ?? -1] ?? '');
            $symbol     = ValueCleaner::cleanSymbol($row[$colMap['symbol']        ?? -1] ?? '');
            $desc       = ValueCleaner::cleanString($row[$colMap['description']   ?? -1] ?? '');
            $qty        = ValueCleaner::cleanNumber($row[$colMap['quantity']       ?? -1] ?? '');
            $price      = ValueCleaner::cleanNumber($row[$colMap['price ($)']     ?? -1] ?? '');
            $commission = ValueCleaner::cleanNumber($row[$colMap['commission ($)'] ?? -1] ?? '');
            $fees       = ValueCleaner::cleanNumber($row[$colMap['fees ($)']      ?? -1] ?? '');
            $amount     = ValueCleaner::cleanNumber($row[$colMap['amount ($)']    ?? -1] ?? '');

            $acctNum  = $hasAccount ? ValueCleaner::cleanString($row[$colMap['account number'] ?? -1] ?? '') : '';
            $acctName = $hasAccount ? ValueCleaner::cleanString($row[$colMap['account']        ?? -1] ?? '') : '';

            if ($acctNum !== '' && !isset($accountsSeen[$acctNum])) {
                $accountsSeen[$acctNum] = true;
                $result['accounts'][] = ['number' => $acctNum, 'name' => $acctName];
            }

            $mapped     = ActionTypeMapper::fromFidelity($fullAction);
            $actionType = $mapped['code'];
            $rawAction  = $mapped['key'];   // short label, e.g. "YOU SOLD"

            if ($actionType === '' && $rawAction !== '') {
                $unmapped[$rawAction] = true;
            }

            $cusip = ValueCleaner::isCusip($symbol) ? $symbol : '';

            $memo = implode(' | ', array_filter([
                $fullAction,
                $settleDate ? 'Settle: ' . $settleDate : '',
                $amount !== '' ? 'Amt: ' . $amount : '',
            ]));

            $result['transactions'][] = [
                'account_number' => $acctNum,
                'account_name'   => $acctName,
                'date'           => $runDate,
                'settle_date'    => $settleDate,
                'action_type'    => $actionType,
                'raw_action'     => $rawAction,
                'description'    => $desc,
                'symbol'         => $symbol,
                'cusip'          => $cusip,
                'quantity'       => $qty,
                'price'          => $price,
                'commission'     => $commission,
                'fees'           => $fees,
                'amount'         => $amount,
                'memo'           => $memo,
                'issues'         => $actionType === '' ? ['unrecognized action: ' . $rawAction] : [],
            ];
        }

        if (!$result['date']) {
            $result['date'] = date('Y-m-d');
        }

        if (!$hasAccount) {
            $result['warnings'][] = 'Single-account export: no account number in file — assign after import in Steward.';
        }

        if (!empty($unmapped)) {
            $result['warnings'][] = count($unmapped) . ' unrecognized action type(s) — review mappings before exporting.';
        }

        return $result;
    }

    private function extractDate(string $text, array &$result): void {
        if ($result['date_source'] === 'file') return;
        if (preg_match('/Date downloaded\s+(\d{2}\/\d{2}\/\d{4})/i', $text, $m)) {
            $d = DateTime::createFromFormat('m/d/Y', $m[1]);
            if ($d) {
                $result['date']        = $d->format('Y-m-d');
                $result['date_source'] = 'file';
            }
        }
    }

    private function parseDate(string $val): string {
        $d = DateTime::createFromFormat('m/d/Y', trim($val));
        return $d ? $d->format('Y-m-d') : '';
    }
}

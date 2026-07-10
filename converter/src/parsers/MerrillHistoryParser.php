<?php
require_once __DIR__ . '/../ValueCleaner.php';
require_once __DIR__ . '/../ActionTypeMapper.php';

class MerrillHistoryParser {
    public function parse(array $rows): array {
        $result = [
            'date'         => null,
            'date_source'  => 'default',
            'accounts'     => [],
            'transactions' => [],
            'warnings'     => [],
        ];

        // Date from preamble: "Exported on: 05/08/2026 10:30 AM ET"
        foreach (array_slice($rows, 0, 5) as $row) {
            if (empty($row)) continue;
            if (preg_match('/^Exported on:\s*(\d{2}\/\d{2}\/\d{4})/i', $row[0] ?? '', $m)) {
                $d = DateTime::createFromFormat('m/d/Y', $m[1]);
                if ($d) {
                    $result['date']        = $d->format('Y-m-d');
                    $result['date_source'] = 'file';
                }
                break;
            }
        }

        if (!$result['date']) {
            $result['date'] = date('Y-m-d');
        }

        // Preamble account (used only for single-account files)
        $preambleAccount = '';
        foreach (array_slice($rows, 0, 10) as $row) {
            if (empty($row)) continue;
            if (preg_match('/^Selected account\(s\):\s*(.+)$/i', $row[0] ?? '', $m)) {
                $parts = array_map('trim', explode(';', $m[1]));
                if (count($parts) === 1) {
                    $preambleAccount = $parts[0];
                }
                break;
            }
        }

        // Column header row: cells[0] normalized == "trade date"
        $headerIdx = -1;
        $colMap    = [];
        foreach ($rows as $i => $row) {
            if (empty($row)) continue;
            if ($this->norm($row[0] ?? '') === 'trade date') {
                $headerIdx = $i;
                foreach ($row as $j => $col) {
                    $key = $this->norm($col);
                    if ($key !== '') $colMap[$key] = $j;
                }
                break;
            }
        }

        if ($headerIdx === -1) {
            $result['warnings'][] = 'Could not locate Merrill history column header row.';
            return $result;
        }

        // Symbol column key may be "symbol/ cusip" or similar
        $symKey = '';
        foreach ($colMap as $k => $_) {
            if (str_contains($k, 'symbol')) { $symKey = $k; break; }
        }

        $hasAccount   = isset($colMap['account']);
        $accountsSeen = [];
        $unmapped     = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty($row)) continue;

            // Data rows must start with a MM/DD/YYYY date
            if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $row[0] ?? '')) continue;

            $tradeDate  = $this->parseDate($row[0]);
            $settleDate = $this->parseDate($row[$colMap['settlement date'] ?? -1] ?? '');
            $fullDesc   = ValueCleaner::cleanString($row[$colMap['description'] ?? -1] ?? '');
            $qty        = ValueCleaner::cleanNumber($row[$colMap['quantity']    ?? -1] ?? '');
            $price      = ValueCleaner::cleanNumber($row[$colMap['price']       ?? -1] ?? '');
            $amount     = ValueCleaner::cleanNumber($row[$colMap['amount']      ?? -1] ?? '');
            $rawSymbol  = $symKey !== '' ? ValueCleaner::cleanSymbol($row[$colMap[$symKey]] ?? '') : '';

            // Account
            if ($hasAccount) {
                $raw  = ValueCleaner::cleanString($row[$colMap['account'] ?? -1] ?? '');
                $acct = $this->splitAccount($raw);
            } else {
                $acct = ['number' => $preambleAccount, 'name' => $preambleAccount];
            }

            $acctNum  = $acct['number'];
            $acctName = $acct['name'];

            if ($acctNum !== '' && !isset($accountsSeen[$acctNum])) {
                $accountsSeen[$acctNum] = true;
                $result['accounts'][] = ['number' => $acctNum, 'name' => $acctName];
            }

            $cusip = ValueCleaner::isCusip($rawSymbol) ? $rawSymbol : '';

            $mapped     = ActionTypeMapper::fromMerrill($fullDesc);
            $actionType = $mapped['code'];
            $rawAction  = $mapped['key'];  // e.g. "Dividend", "Purchase", "Reinvestment Share(s)"

            if ($actionType === '' && $rawAction !== '') {
                $unmapped[$rawAction] = true;
            }

            $memo = implode(' | ', array_filter([
                $fullDesc,
                $settleDate ? 'Settle: ' . $settleDate : '',
                $amount !== '' ? 'Amt: ' . $amount : '',
            ]));

            $result['transactions'][] = [
                'account_number' => $acctNum,
                'account_name'   => $acctName,
                'date'           => $tradeDate,
                'settle_date'    => $settleDate,
                'action_type'    => $actionType,
                'raw_action'     => $rawAction,
                'description'    => $fullDesc,
                'symbol'         => $rawSymbol,
                'cusip'          => $cusip,
                'quantity'       => $qty,
                'price'          => $price,
                'commission'     => '',
                'fees'           => '',
                'amount'         => $amount,
                'memo'           => $memo,
                'issues'         => $actionType === '' ? ['unrecognized action: ' . $rawAction] : [],
            ];
        }

        if (!empty($unmapped)) {
            $result['warnings'][] = count($unmapped) . ' unrecognized action type(s) — review mappings before exporting.';
        }

        return $result;
    }

    /** Normalize a column header: collapse whitespace, lowercase, trim. */
    private function norm(string $col): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($col)));
    }

    private function parseDate(string $val): string {
        $d = DateTime::createFromFormat('m/d/Y', trim($val));
        return $d ? $d->format('Y-m-d') : '';
    }

    /**
     * Split Merrill account strings like "ROTH_IRA Roth IRA-Edge XXX-00000"
     * into a short name and the fuller account number.
     */
    private function splitAccount(string $raw): array {
        if (preg_match('/^([A-Z_]{2,})\s+(.+)$/', $raw, $m)) {
            return ['number' => trim($m[2]), 'name' => trim($m[1])];
        }
        return ['number' => $raw, 'name' => $raw];
    }
}

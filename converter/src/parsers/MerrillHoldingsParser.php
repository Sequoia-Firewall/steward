<?php
require_once __DIR__ . '/../ValueCleaner.php';

class MerrillHoldingsParser {
    private static array $SKIP_SYMBOLS = [
        'Balances', 'Money accounts', 'Cash balance', 'Cash Balance',
        'Pending activity', 'Total', 'Pending Activity',
    ];

    public function parse(array $rows): array {
        $result = [
            'date'        => null,
            'date_source' => 'default',
            'accounts'    => [],
            'holdings'    => [],
            'warnings'    => [],
        ];

        // Date from preamble line 1: "Exported on: 05/08/2026 10:29 AM ET"
        foreach (array_slice($rows, 0, 5) as $row) {
            if (empty($row)) continue;
            if (preg_match('/^Exported on:\s*(\d{2}\/\d{2}\/\d{4})/i', $row[0] ?? '', $m)) {
                $d = DateTime::createFromFormat('m/d/Y', $m[1]);
                if ($d) {
                    $result['date'] = $d->format('Y-m-d');
                    $result['date_source'] = 'file';
                }
                break;
            }
        }

        if (!$result['date']) {
            $result['date'] = date('Y-m-d');
        }

        // Detect single vs multi by finding the first column-header row.
        // Single: "Symbol" is cells[0].  Multi: cells[0]=="" and "Symbol" is cells[1].
        $isSingle = false;
        foreach ($rows as $row) {
            if (empty($row)) continue;
            $c0 = strtolower(trim($row[0] ?? ''));
            $c1 = strtolower(trim($row[1] ?? ''));
            if ($c0 === 'symbol') { $isSingle = true;  break; }
            if ($c0 === '' && $c1 === 'symbol') { $isSingle = false; break; }
        }

        if ($isSingle) {
            $this->parseSingle($rows, $result);
        } else {
            $this->parseMulti($rows, $result);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Single-account format
    // -------------------------------------------------------------------------
    private function parseSingle(array $rows, array &$result): void {
        // Account from preamble: "Selected account(s):CMA-Edge XXX-00000"
        $acctNumber = '';
        foreach (array_slice($rows, 0, 15) as $row) {
            if (empty($row)) continue;
            if (preg_match('/^Selected account\(s\):\s*(.+)$/i', $row[0] ?? '', $m)) {
                $acctNumber = trim($m[1]);
                break;
            }
        }

        if ($acctNumber !== '') {
            $result['accounts'][] = ['number' => $acctNumber, 'name' => $acctNumber];
        }

        // Column header row: first row where cells[0] (trimmed) == "Symbol"
        $headerMap = [];
        $headerIdx = -1;
        foreach ($rows as $i => $row) {
            if (empty($row)) continue;
            if (strtolower(trim($row[0] ?? '')) === 'symbol') {
                $headerIdx = $i;
                foreach ($row as $j => $col) {
                    $headerMap[strtolower(trim($col))] = $j;
                }
                break;
            }
        }

        if ($headerIdx === -1) {
            $result['warnings'][] = 'Could not locate Merrill column header row.';
            return;
        }

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty($row) || count($row) < 2) continue;

            $symIdx = $headerMap['symbol'] ?? 0;
            $symbol = ValueCleaner::cleanSymbol($row[$symIdx] ?? '');
            if ($symbol === '' || in_array($symbol, self::$SKIP_SYMBOLS)) continue;

            $this->addHolding($result, $row, $headerMap, $acctNumber, $acctNumber, false);
        }
    }

    // -------------------------------------------------------------------------
    // Multi-account format
    // -------------------------------------------------------------------------
    private function parseMulti(array $rows, array &$result): void {
        $currentAcctNum  = '';
        $currentAcctName = '';
        $inSection = false;
        $headerMap = [];
        $headerIdx = -1;
        $accountsSeen = [];

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (empty($row)) continue;

            $c0 = trim($row[0] ?? '');
            $c1 = trim($row[1] ?? '');

            // Section header: non-empty c0, c1 == "Value", skip "All Accounts"
            if ($c0 !== '' && $c1 === 'Value' && strtolower($c0) !== 'all accounts') {
                $sectionLabel = $c0;
                $acctNumber   = $c0;
                $acctName     = $c0;

                // Look ahead for a sub-header that gives the real account name/number.
                // E.g. "ROTH_IRA" section → next data row is "Roth IRA-Edge XXX-00000","$0.00",...
                for ($j = $i + 1; $j < min($i + 4, count($rows)); $j++) {
                    if (empty($rows[$j])) continue;
                    $nr = $rows[$j];
                    $nc0 = trim($nr[0] ?? '');
                    $nc1 = trim($nr[1] ?? '');
                    // Sub-header: cells[0] is a non-empty account identifier, cells[1] starts with "$"
                    if ($nc0 !== '' && str_starts_with($nc1, '$')) {
                        $acctNumber = $nc0;   // fuller identifier like "Roth IRA-Edge XXX-00000"
                        $acctName   = $sectionLabel;  // short label like "ROTH_IRA"
                    }
                    break;
                }

                $currentAcctNum  = $acctNumber;
                $currentAcctName = $acctName;
                $inSection = true;
                $headerMap = [];
                $headerIdx = -1;

                if (!isset($accountsSeen[$currentAcctNum])) {
                    $accountsSeen[$currentAcctNum] = true;
                    $result['accounts'][] = ['number' => $currentAcctNum, 'name' => $currentAcctName];
                }
                continue;
            }

            // Column header row: cells[0]=="" and cells[1]=="Symbol"
            if ($inSection && $c0 === '' && strtolower($c1) === 'symbol') {
                $headerMap = [];
                foreach ($row as $j => $col) {
                    $headerMap[strtolower(trim($col))] = $j;
                }
                $headerIdx = $i;
                continue;
            }

            // Data row within section: cells[0]=="" and cells[1] is non-empty symbol
            if ($inSection && $headerIdx !== -1 && $c0 === '' && $c1 !== '') {
                $symbol = ValueCleaner::cleanSymbol($c1);
                if ($symbol === '' || in_array($symbol, self::$SKIP_SYMBOLS)) continue;

                $this->addHolding($result, $row, $headerMap, $currentAcctNum, $currentAcctName, true);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Build a single holding record from a parsed row
    // -------------------------------------------------------------------------
    private function addHolding(
        array &$result,
        array  $row,
        array  $headerMap,
        string $acctNum,
        string $acctName,
        bool   $isMulti
    ): void {
        $symIdx        = $headerMap['symbol']      ?? ($isMulti ? 1 : 0);
        $descIdx       = $headerMap['description'] ?? null;
        $priceIdx      = $headerMap['price']       ?? null;
        $qtyIdx        = $headerMap['quantity']    ?? null;
        $unitCostIdx   = $headerMap['unit cost']   ?? null;  // avg cost basis (multi only)
        $costBasisIdx  = $headerMap['cost basis']  ?? null;  // total cost basis (multi only)

        $symbol = ValueCleaner::cleanSymbol($row[$symIdx] ?? '');
        if ($symbol === '') return;

        $desc      = $descIdx      !== null ? ValueCleaner::cleanString($row[$descIdx] ?? '')      : '';
        $price     = $priceIdx     !== null ? ValueCleaner::cleanNumber($row[$priceIdx] ?? '')     : '';
        $qty       = $qtyIdx       !== null ? ValueCleaner::cleanNumber($row[$qtyIdx] ?? '')       : '';
        $avgCost   = $unitCostIdx  !== null ? ValueCleaner::cleanNumber($row[$unitCostIdx] ?? '')  : '';
        $totalCost = $costBasisIdx !== null ? ValueCleaner::cleanNumber($row[$costBasisIdx] ?? '') : '';

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
}

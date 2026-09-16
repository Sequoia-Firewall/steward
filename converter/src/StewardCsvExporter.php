<?php
class StewardCsvExporter {
    private static array $HOLDINGS_COLUMNS = [
        'account',
        'as_of_date',
        'symbol',
        'cusip',
        'security_name',
        'quantity',
        'price',
        'market_value',
        'avg_cost_basis',
        'total_cost_basis',
    ];

    private static array $HISTORY_COLUMNS = [
        'account', 'date', 'settle_date', 'action_type', 'description',
        'symbol', 'cusip', 'quantity', 'price', 'commission', 'fees', 'amount', 'memo',
    ];

    // Neutralizes CSV formula injection: prefixes non-numeric values that start with
    // =, +, -, @, tab, or CR with an apostrophe so spreadsheet apps treat them as text.
    // Numeric values (including negatives) are left untouched so re-import stays intact.
    private static function csvSafe(mixed $v): string {
        $v = (string)($v ?? '');
        if ($v !== '' && !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
            return "'" . $v;
        }
        return $v;
    }

    public static function generateHistoryCsv(array $transactions): string {
        $buf = fopen('php://temp', 'r+');
        fputcsv($buf, self::$HISTORY_COLUMNS);
        foreach ($transactions as $t) {
            fputcsv($buf, array_map([self::class, 'csvSafe'], [
                $t['account_number'] ?? '',
                $t['date']           ?? '',
                $t['settle_date']    ?? '',
                $t['action_type']    ?? '',
                $t['description']    ?? '',
                $t['symbol']         ?? '',
                $t['cusip']          ?? '',
                $t['quantity']       ?? '',
                $t['price']          ?? '',
                $t['commission']     ?? '',
                $t['fees']           ?? '',
                $t['amount']         ?? '',
                $t['memo']           ?? '',
            ]));
        }
        rewind($buf);
        $csv = stream_get_contents($buf);
        fclose($buf);
        return $csv;
    }

    public static function generate(string $date, array $holdings): string {
        $buf = fopen('php://temp', 'r+');
        fputcsv($buf, self::$HOLDINGS_COLUMNS);

        foreach ($holdings as $h) {
            $qty    = isset($h['quantity'])   ? (float)$h['quantity']   : null;
            $price  = isset($h['last_price']) ? (float)$h['last_price'] : null;
            $mktVal = ($qty !== null && $price !== null) ? round($qty * $price, 4) : '';
            $acct = ($h['account_number'] ?? '') !== '' ? $h['account_number'] : ($h['account_name'] ?? '');

            fputcsv($buf, array_map([self::class, 'csvSafe'], [
                $acct,
                $date,
                $h['symbol']           ?? '',
                $h['cusip']            ?? '',
                $h['description']      ?? '',
                $qty    ?? '',
                $price  ?? '',
                $mktVal,
                $h['avg_cost_basis']   ?? '',
                $h['total_cost_basis'] ?? '',
            ]));
        }

        rewind($buf);
        $csv = stream_get_contents($buf);
        fclose($buf);
        return $csv;
    }
}

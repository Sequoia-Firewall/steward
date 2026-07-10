<?php
class ActionTypeMapper {
    private static array $FIDELITY = [
        'YOU BOUGHT'                => 'Buy',
        'YOU SOLD'                  => 'Sell',
        'REINVESTMENT'              => 'ReinvDiv',
        'DIVIDEND RECEIVED'         => 'Div',
        'IN LIEU OF FRACTION'       => 'Cash',
        'INTEREST EARNED'           => 'IntInc',
        'LONG-TERM CAP GAIN'        => 'CGLong',
        'SHORT-TERM CAP GAIN'       => 'CGShort',
        'CAPITAL GAINS'             => 'CGLong',
        'TRANSFERRED IN'            => 'ShrsIn',
        'TRANSFERRED OUT'           => 'ShrsOut',
        'ELECTRONIC FUNDS TRANSFER' => 'Cash',
        'DIRECT DEBIT'              => 'Cash',
        'DIRECT DEPOSIT'            => 'Cash',
    ];

    private static array $MERRILL = [
        'Reinvestment Share(s)' => 'ReinvDiv',
        'Reinvestment Program'  => 'Cash',
        'Dividend'              => 'Div',
        'Bank Interest'         => 'IntInc',
        'Interest'              => 'IntInc',
        'Purchase'              => 'Buy',
        'Sale'                  => 'Sell',
        'Withdrawal'            => 'Cash',
        'Deposit'               => 'Cash',
    ];

    /**
     * Returns ['code' => 'Sell', 'key' => 'YOU SOLD'].
     * key is the short label used for grouping in the action-mapping UI.
     * code is '' when unrecognized.
     */
    public static function fromFidelity(string $action): array {
        $upper = strtoupper(trim($action));
        $bestCode = ''; $bestKey = ''; $bestLen = 0;
        foreach (self::$FIDELITY as $prefix => $code) {
            if (str_starts_with($upper, $prefix) && strlen($prefix) > $bestLen) {
                $bestCode = $code; $bestKey = $prefix; $bestLen = strlen($prefix);
            }
        }
        if ($bestCode === '') {
            // Unknown — derive a short key from the first two words
            $words = explode(' ', $upper, 3);
            $bestKey = implode(' ', array_slice($words, 0, 2));
        }
        return ['code' => $bestCode, 'key' => $bestKey];
    }

    /**
     * Same contract as fromFidelity, but for Merrill description strings.
     */
    public static function fromMerrill(string $desc): array {
        $trimmed = trim($desc);
        $bestCode = ''; $bestKey = ''; $bestLen = 0;
        foreach (self::$MERRILL as $prefix => $code) {
            if (str_starts_with($trimmed, $prefix) && strlen($prefix) > $bestLen) {
                $bestCode = $code; $bestKey = $prefix; $bestLen = strlen($prefix);
            }
        }
        if ($bestCode === '') {
            $words = explode(' ', $trimmed, 3);
            $bestKey = implode(' ', array_slice($words, 0, 2));
        }
        return ['code' => $bestCode, 'key' => $bestKey];
    }

    public static function allCodes(): array {
        return ['Buy','Sell','Div','ReinvDiv','IntInc','CGLong','CGShort',
                'ShrsIn','ShrsOut','Cash','StkSplit','MiscInc','MiscExp'];
    }
}

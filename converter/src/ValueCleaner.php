<?php
class ValueCleaner {
    /**
     * Clean a numeric/currency value: strip $, commas, quotes, leading +.
     * For compound values like "+$1.07 +3.18%" or "-$9,062.00 -56.64%", extracts first number.
     * Returns '' for '--', '-- --', empty, or non-parseable values.
     */
    public static function cleanNumber(string $val): string {
        $val = trim($val, " \t\"'");
        if ($val === '' || $val === '--' || $val === '-- --' || $val === '-') {
            return '';
        }
        // Extract first numeric value, preserving sign.
        // Handles: "-207", "-$1,052.66", "+$1,234.56 +12.34%", "$581.80"
        if (preg_match('/^([+-])?\$?([\d,]+\.?\d*)/', $val, $m)) {
            $sign = ($m[1] ?? '') === '-' ? '-' : '';
            return $sign . str_replace(',', '', $m[2]);
        }
        $val = str_replace(['$', ','], '', $val);
        return is_numeric($val) ? $val : '';
    }

    public static function cleanString(string $val): string {
        return trim($val, " \t\"'");
    }

    /**
     * Strip trailing ** from Fidelity money-market symbols and clean whitespace.
     */
    public static function cleanSymbol(string $val): string {
        return rtrim(trim($val, " \t\"'"), '*');
    }

    /**
     * Returns true if the value looks like a CUSIP (exactly 9 uppercase alphanumeric chars).
     */
    public static function isCusip(string $val): bool {
        return (bool) preg_match('/^[0-9A-Z]{9}$/i', $val);
    }
}

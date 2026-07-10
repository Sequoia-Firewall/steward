<?php
class BrokerDetector {
    public static function detect(array $rows): array {
        // Merrill: first non-empty line starts with "Exported on:"
        foreach (array_slice($rows, 0, 5) as $row) {
            if (empty($row)) continue;
            if (str_starts_with($row[0] ?? '', 'Exported on:')) {
                $type = self::detectMerrillType($rows);
                return ['broker' => 'merrill', 'type' => $type, 'confidence' => 95];
            }
        }

        // Fidelity: scan first 10 rows for the header row
        foreach (array_slice($rows, 0, 10) as $row) {
            if (count($row) < 3) continue;
            $lower = array_map(fn($c) => strtolower(trim($c)), $row);
            if (in_array('run date', $lower) && in_array('action', $lower)) {
                return ['broker' => 'fidelity', 'type' => 'history', 'confidence' => 95];
            }
            if (in_array('account number', $lower) && in_array('average cost basis', $lower)) {
                return ['broker' => 'fidelity', 'type' => 'holdings', 'confidence' => 95];
            }
        }

        return ['broker' => 'unknown', 'type' => 'unknown', 'confidence' => 0];
    }

    private static function detectMerrillType(array $rows): string {
        foreach ($rows as $row) {
            if (empty($row)) continue;
            $c0 = strtolower(trim($row[0] ?? ''));
            if ($c0 === 'trade date') return 'history';
            if ($c0 === 'symbol')     return 'holdings';
            if ($c0 === '' && strtolower(trim($row[1] ?? '')) === 'symbol') return 'holdings';
        }
        return 'unknown';
    }
}

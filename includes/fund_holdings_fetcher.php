<?php

class FundHoldingsFetcher {

    private PDO $db;
    private int $cacheDays = 7;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable(): void {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS fund_holdings (
                fund_symbol        VARCHAR(20)   NOT NULL,
                constituent_symbol VARCHAR(20)   NOT NULL,
                constituent_name   VARCHAR(200)  NOT NULL DEFAULT '',
                weight_pct         DECIMAL(10,6) NOT NULL DEFAULT 0,
                fetched_at         DATETIME      NOT NULL,
                source             VARCHAR(50)   NOT NULL DEFAULT '',
                PRIMARY KEY (fund_symbol, constituent_symbol),
                INDEX idx_fund_fetched (fund_symbol, fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    // ── Public API ─────────────────────────────────────────────────

    /** True if fresh cached data exists (within cacheDays). */
    public function hasCachedHoldings(string $symbol): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM fund_holdings
             WHERE fund_symbol = ? AND fetched_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 1"
        );
        $stmt->execute([strtoupper(trim($symbol)), $this->cacheDays]);
        return (bool)$stmt->fetchColumn();
    }

    /** Returns real (non-sentinel) cached holdings for a symbol. */
    public function getHoldings(string $symbol): array {
        $stmt = $this->db->prepare(
            "SELECT constituent_symbol, constituent_name, weight_pct
             FROM fund_holdings
             WHERE fund_symbol = ?
               AND constituent_symbol != '__none__'
               AND fetched_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY weight_pct DESC"
        );
        $stmt->execute([strtoupper(trim($symbol)), $this->cacheDays]);
        return $stmt->fetchAll();
    }

    /** Returns the most recent fetch timestamp for a symbol, or null if never. */
    public function getFetchedAt(string $symbol): ?string {
        $stmt = $this->db->prepare(
            "SELECT MAX(fetched_at) FROM fund_holdings WHERE fund_symbol = ?"
        );
        $stmt->execute([strtoupper(trim($symbol))]);
        $v = $stmt->fetchColumn();
        return $v ?: null;
    }

    /**
     * Fetch holdings for a list of symbols.
     * Strategy: Vanguard API in parallel (no key) → AlphaVantage fallback (uses app key).
     * Skips symbols with fresh cache unless $force = true.
     */
    public function batchFetch(array $symbols, bool $force = false): array {
        $toFetch = [];
        foreach ($symbols as $sym) {
            $sym = strtoupper(trim($sym));
            if ($sym === '') continue;
            if ($force || !$this->hasCachedHoldings($sym)) {
                $toFetch[] = $sym;
            }
        }
        if (empty($toFetch)) return [];

        // ── Step 1: Vanguard API in parallel ──────────────────────
        $urlMap = [];
        foreach ($toFetch as $sym) {
            $urlMap[$sym] = 'https://investor.vanguard.com/investment-products/etfs/profile/api/'
                          . rawurlencode($sym)
                          . '/portfolio-holding/stock?start=1&count=500';
        }
        $rawMap  = $this->curlMultiFetchRaw($urlMap);
        $results = [];
        $needAV  = [];

        foreach ($rawMap as $sym => $raw) {
            $holdings = $this->parseVanguardResponse($raw);
            if (!empty($holdings)) {
                $this->storeHoldings($sym, $holdings, 'vanguard');
                $results[$sym] = count($holdings);
            } else {
                $needAV[] = $sym;
            }
        }

        // ── Step 2: AlphaVantage for non-Vanguard symbols ─────────
        if (!empty($needAV)) {
            $avKey = function_exists('getSetting') ? getSetting('alphavantage_api_key') : null;
            foreach ($needAV as $sym) {
                $holdings = $avKey ? $this->fetchAlphaVantage($sym, $avKey) : null;
                if (!empty($holdings)) {
                    $this->storeHoldings($sym, $holdings, 'alphavantage');
                    $results[$sym] = count($holdings);
                } else {
                    // Sentinel: tried but got nothing — prevents hammering on every load
                    $this->storeHoldings($sym, [], 'no_data');
                    $results[$sym] = 0;
                }
            }
        }

        return $results;
    }

    // ── Parsers ────────────────────────────────────────────────────

    /**
     * Vanguard: investor.vanguard.com/investment-products/etfs/profile/api/{TICKER}/portfolio-holding/stock
     * Returns: {"size":N,"fund":{"entity":[{"ticker":"AAPL","longName":"Apple Inc.","percentWeight":"5.74",...}]}}
     */
    private function parseVanguardResponse(?string $raw): array {
        if (!$raw) return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [];

        $total = (int)($data['size'] ?? 0);
        if ($total === 0) return [];

        $entities = $data['fund']['entity'] ?? [];
        if (!is_array($entities) || empty($entities)) return [];

        $seen = [];
        $out  = [];
        foreach ($entities as $e) {
            $sym = strtoupper(trim($e['ticker'] ?? ''));
            if ($sym === '' || $sym === 'N/A') continue;
            if (isset($seen[$sym])) continue;
            $seen[$sym] = true;
            $out[] = [
                'symbol' => $sym,
                'name'   => trim($e['longName'] ?? $e['shortName'] ?? $sym),
                'weight' => (float)($e['percentWeight'] ?? 0), // already 0–100
            ];
        }
        return $out;
    }

    /**
     * AlphaVantage: ETF_PROFILE endpoint.
     * Returns: {"holdings":[{"symbol":"AAPL","description":"APPLE INC","weight":"0.0574"}]}
     * weight is 0–1; multiply × 100 to get percentage.
     */
    private function fetchAlphaVantage(string $symbol, string $apiKey): array {
        $url = 'https://www.alphavantage.co/query?function=ETF_PROFILE'
             . '&symbol=' . rawurlencode($symbol)
             . '&apikey=' . rawurlencode($apiKey);

        $raw  = $this->httpGetRaw($url);
        if (!$raw) return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [];

        // Rate limit / error responses
        if (isset($data['Information']) || isset($data['Note'])) return [];

        $list = $data['holdings'] ?? [];
        if (!is_array($list) || empty($list)) return [];

        $seen = [];
        $out  = [];
        foreach ($list as $h) {
            $sym = strtoupper(trim($h['symbol'] ?? ''));
            // Skip empty symbols and placeholder entries like "n/a" (cash, futures, etc.)
            if ($sym === '' || $sym === 'N/A') continue;
            // Deduplicate — AV occasionally repeats a symbol; keep first (highest weight)
            if (isset($seen[$sym])) continue;
            $seen[$sym] = true;
            $out[] = [
                'symbol' => $sym,
                'name'   => trim($h['description'] ?? $sym),
                'weight' => (float)($h['weight'] ?? 0) * 100.0, // convert 0–1 to 0–100
            ];
        }
        return $out;
    }

    // ── Storage ────────────────────────────────────────────────────

    private function storeHoldings(string $symbol, array $holdings, string $source): void {
        // Wrap in a transaction so all inserts flush in one fsync instead of one per row
        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM fund_holdings WHERE fund_symbol = ?")->execute([$symbol]);

            if (empty($holdings)) {
                $this->db->prepare(
                    "INSERT IGNORE INTO fund_holdings
                        (fund_symbol, constituent_symbol, constituent_name, weight_pct, fetched_at, source)
                     VALUES (?, '__none__', '', 0, NOW(), ?)"
                )->execute([$symbol, $source]);
            } else {
                $ins = $this->db->prepare(
                    "INSERT IGNORE INTO fund_holdings
                        (fund_symbol, constituent_symbol, constituent_name, weight_pct, fetched_at, source)
                     VALUES (?, ?, ?, ?, NOW(), ?)"
                );
                foreach ($holdings as $h) {
                    $ins->execute([$symbol, $h['symbol'], $h['name'], $h['weight'], $source]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── HTTP ───────────────────────────────────────────────────────

    private function httpGetRaw(string $url, array $extraHeaders = []): ?string {
        $headers = array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json',
        ], $extraHeaders);

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            return $raw ?: null;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", $headers),
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw ?: null;
    }

    private function curlMultiFetchRaw(array $urlMap): array {
        if (empty($urlMap)) return [];

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json',
        ];

        if (!function_exists('curl_multi_init')) {
            $results = [];
            foreach ($urlMap as $key => $url) {
                $results[$key] = $this->httpGetRaw($url);
            }
            return $results;
        }

        $mh      = curl_multi_init();
        $handles = [];
        foreach ($urlMap as $key => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $key => $ch) {
            $raw           = curl_multi_getcontent($ch);
            $results[$key] = $raw ?: null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }
}

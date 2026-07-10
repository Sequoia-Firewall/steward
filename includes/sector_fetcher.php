<?php
require_once __DIR__ . '/stock_sector_map.php';

class SectorFetcher {

    private PDO $db;
    private int $cacheDays = 90;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable(): void {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS stock_sectors (
                symbol      VARCHAR(20)  NOT NULL,
                sector      VARCHAR(100) NOT NULL DEFAULT '',
                industry    VARCHAR(100) NOT NULL DEFAULT '',
                source      VARCHAR(30)  NOT NULL DEFAULT '',
                fetched_at  DATETIME     NOT NULL,
                PRIMARY KEY (symbol),
                INDEX idx_fetched (fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Return sector data for all given symbols.
     * Priority: static map → DB cache → AlphaVantage OVERVIEW (rate-limited).
     * Returns ['AAPL' => ['sector'=>'...','industry'=>'...','source'=>'...'], ...]
     * Missing symbols get ['sector'=>'Unknown','industry'=>'','source'=>'none'].
     */
    public function getSectors(array $symbols): array {
        if (empty($symbols)) return [];

        $upper   = array_map('strtoupper', $symbols);
        $result  = [];
        $missing = [];

        // ── 1. Static map (instant) ───────────────────────────────
        foreach ($upper as $sym) {
            $info = getStaticSector($sym);
            if ($info !== null) {
                $result[$sym] = ['sector' => $info['sector'], 'industry' => $info['industry'], 'source' => 'static'];
            } else {
                $missing[] = $sym;
            }
        }

        if (empty($missing)) return $result;

        // ── 2. DB cache ───────────────────────────────────────────
        $ph    = implode(',', array_fill(0, count($missing), '?'));
        $params = array_merge($missing, [$this->cacheDays]);
        $stmt  = $this->db->prepare(
            "SELECT symbol, sector, industry, source FROM stock_sectors
             WHERE symbol IN ($ph)
               AND fetched_at > DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute($params);
        $cached = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stillMissing = $missing;
        foreach ($cached as $row) {
            $result[$row['symbol']] = ['sector' => $row['sector'], 'industry' => $row['industry'], 'source' => $row['source']];
            $stillMissing = array_values(array_filter($stillMissing, fn($s) => $s !== $row['symbol']));
        }

        // Anything still missing stays Unknown on page load.
        // AV fetching only happens via the explicit fetchMissingFromAV() call.
        foreach ($stillMissing as $sym) {
            $result[$sym] = ['sector' => 'Unknown', 'industry' => '', 'source' => 'none'];
        }

        return $result;
    }

    /**
     * Fetch just the symbols that are not yet in the DB cache or static map,
     * using AlphaVantage OVERVIEW. Returns [symbol => true/false].
     * Used by the AJAX refresh endpoint.
     */
    public function fetchMissingFromAV(array $symbols, int $maxCalls = 20): array {
        $avKey = function_exists('getSetting') ? getSetting('alphavantage_api_key') : null;
        if (!$avKey) return [];

        $upper   = array_map('strtoupper', $symbols);
        $todo    = [];
        foreach ($upper as $sym) {
            if (getStaticSector($sym) !== null) continue; // already in static map
            $todo[] = $sym;
        }
        if (empty($todo)) return [];

        // Check DB cache for recent data
        $ph   = implode(',', array_fill(0, count($todo), '?'));
        $stmt = $this->db->prepare(
            "SELECT symbol FROM stock_sectors WHERE symbol IN ($ph) AND fetched_at > DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute(array_merge($todo, [$this->cacheDays]));
        $haveSyms = array_column($stmt->fetchAll(), 'symbol');
        $todo = array_values(array_diff($todo, $haveSyms));

        $results = [];
        $called  = 0;
        foreach ($todo as $sym) {
            if ($called >= $maxCalls) break;
            $info = $this->fetchAV($sym, $avKey);
            $called++;
            if ($info !== null) {
                $this->storeCache($sym, $info['sector'], $info['industry'], 'alphavantage');
                $results[$sym] = true;
            } else {
                $this->storeCache($sym, 'Unknown', '', 'no_data');
                $results[$sym] = false;
            }
            if ($called < count($todo)) usleep(300000);
        }
        return $results;
    }

    /** Return count of symbols with cached (non-static) sector data. */
    public function getCacheStats(): array {
        $stmt = $this->db->query(
            "SELECT source, COUNT(*) AS cnt FROM stock_sectors GROUP BY source"
        );
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // ── Private ────────────────────────────────────────────────────

    private function fetchAV(string $symbol, string $apiKey): ?array {
        $url = 'https://www.alphavantage.co/query?function=OVERVIEW'
             . '&symbol=' . rawurlencode($symbol)
             . '&apikey=' . rawurlencode($apiKey);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        if (isset($data['Information']) || isset($data['Note'])) return null; // rate limit
        if (empty($data['Sector'])) return null;

        return [
            'sector'   => $data['Sector']   ?? 'Unknown',
            'industry' => $data['Industry'] ?? '',
        ];
    }

    private function storeCache(string $symbol, string $sector, string $industry, string $source): void {
        $this->db->prepare(
            "INSERT INTO stock_sectors (symbol, sector, industry, source, fetched_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE sector=VALUES(sector), industry=VALUES(industry),
                                     source=VALUES(source), fetched_at=NOW()"
        )->execute([$symbol, $sector, $industry, $source]);
    }
}

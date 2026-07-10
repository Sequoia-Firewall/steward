<?php

// Each bar: ['date'=>'YYYY-MM-DD','open'=>float|null,'high'=>float|null,'low'=>float|null,'close'=>float,'volume'=>int|null,'vwap'=>float|null]

abstract class PriceProvider {
    abstract public function getLatestBar(string $symbol): ?array;
    abstract public function getHistoricalBars(string $symbol, string $from, string $to): array;

    // For parallel batch fetching: return the URL to call for getLatestBar.
    abstract public function latestBarUrl(string $symbol): string;

    // Parse the JSON response from latestBarUrl into a bar array (or null).
    abstract public function parseLatestBarData(?array $data): ?array;

    public function getName(): string { return static::class; }

    protected function httpGet(string $url, array $headers = []): ?array {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => implode("\r\n", array_merge([
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: application/json',
            ], $headers)),
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    // Fetch multiple URLs in parallel via curl_multi.
    // $urlMap: [key => url]; returns [key => parsed JSON array|null].
    public static function curlMultiFetch(array $urlMap): array {
        if (empty($urlMap)) return [];

        if (!function_exists('curl_multi_init')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: application/json",
                'timeout'       => 15,
                'ignore_errors' => true,
            ]]);
            $results = [];
            foreach ($urlMap as $key => $url) {
                $raw = @file_get_contents($url, false, $ctx);
                if (!$raw) { $results[$key] = null; continue; }
                $decoded = json_decode($raw, true);
                $results[$key] = is_array($decoded) ? $decoded : null;
            }
            return $results;
        }

        $mh      = curl_multi_init();
        $handles = [];
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json',
        ];

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
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh);
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $raw = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if (!$raw) { $results[$key] = null; continue; }
            $decoded     = json_decode($raw, true);
            $results[$key] = is_array($decoded) ? $decoded : null;
        }
        curl_multi_close($mh);
        return $results;
    }
}

// ── Massive (Polygon-compatible API) ───────────────────────────

class MassiveProvider extends PriceProvider {
    private string $apiKey;
    private string $base = 'https://api.massive.com';

    public function __construct(string $apiKey) { $this->apiKey = $apiKey; }
    public function getName(): string { return 'massive'; }

    private function isOk(?array $data): bool {
        return $data !== null && in_array($data['status'] ?? '', ['OK', 'DELAYED'], true);
    }

    private function bar(array $r, ?int $ts = null): array {
        return [
            'date'   => $ts ? date('Y-m-d', $ts) : date('Y-m-d', (int)($r['t'] / 1000)),
            'open'   => isset($r['o'])  ? (float)$r['o']  : null,
            'high'   => isset($r['h'])  ? (float)$r['h']  : null,
            'low'    => isset($r['l'])  ? (float)$r['l']  : null,
            'close'  => (float)$r['c'],
            'volume' => isset($r['v'])  ? (int)$r['v']    : null,
            'vwap'   => isset($r['vw']) ? (float)$r['vw'] : null,
        ];
    }

    public function latestBarUrl(string $symbol): string {
        return "{$this->base}/v2/aggs/ticker/" . rawurlencode($symbol)
             . '/prev?adjusted=true&apiKey=' . rawurlencode($this->apiKey);
    }

    public function parseLatestBarData(?array $data): ?array {
        if (!$this->isOk($data)) return null;
        $r = $data['results'][0] ?? null;
        return isset($r['c']) ? $this->bar($r) : null;
    }

    public function getLatestBar(string $symbol): ?array {
        return $this->parseLatestBarData($this->httpGet($this->latestBarUrl($symbol)));
    }

    public function getHistoricalBars(string $symbol, string $from, string $to): array {
        $url  = "{$this->base}/v2/aggs/ticker/" . rawurlencode($symbol)
              . "/range/1/day/{$from}/{$to}"
              . '?adjusted=true&sort=asc&limit=50000&apiKey=' . rawurlencode($this->apiKey);
        $data = $this->httpGet($url);
        if (!$this->isOk($data)) return [];
        $out = [];
        foreach ($data['results'] ?? [] as $r) {
            if (!isset($r['t'], $r['c'])) continue;
            $out[] = $this->bar($r);
        }
        return $out;
    }
}

// ── Alpha Vantage ───────────────────────────────────────────────

class AlphaVantageProvider extends PriceProvider {
    private string $apiKey;
    private string $base = 'https://www.alphavantage.co/query';

    public function __construct(string $apiKey) { $this->apiKey = $apiKey; }
    public function getName(): string { return 'alphavantage'; }

    public function latestBarUrl(string $symbol): string {
        return "{$this->base}?function=GLOBAL_QUOTE&symbol=" . rawurlencode($symbol)
             . '&apikey=' . rawurlencode($this->apiKey);
    }

    public function parseLatestBarData(?array $data): ?array {
        $q = $data['Global Quote'] ?? [];
        if (empty($q['05. price'])) return null;
        return [
            'date'   => $q['07. latest trading day'] ?? date('Y-m-d'),
            'open'   => isset($q['02. open'])   ? (float)$q['02. open']   : null,
            'high'   => isset($q['03. high'])   ? (float)$q['03. high']   : null,
            'low'    => isset($q['04. low'])    ? (float)$q['04. low']    : null,
            'close'  => (float)$q['05. price'],
            'volume' => isset($q['06. volume']) ? (int)$q['06. volume']   : null,
            'vwap'   => null,
        ];
    }

    public function getLatestBar(string $symbol): ?array {
        return $this->parseLatestBarData($this->httpGet($this->latestBarUrl($symbol)));
    }

    public function getHistoricalBars(string $symbol, string $from, string $to): array {
        $url  = "{$this->base}?function=TIME_SERIES_DAILY&symbol=" . rawurlencode($symbol)
              . '&outputsize=full&apikey=' . rawurlencode($this->apiKey);
        $data = $this->httpGet($url);
        if (!$data) return [];
        $out = [];
        foreach ($data['Time Series (Daily)'] ?? [] as $date => $bar) {
            if ($date < $from || $date > $to) continue;
            $out[] = [
                'date'   => $date,
                'open'   => isset($bar['1. open'])   ? (float)$bar['1. open']   : null,
                'high'   => isset($bar['2. high'])   ? (float)$bar['2. high']   : null,
                'low'    => isset($bar['3. low'])    ? (float)$bar['3. low']    : null,
                'close'  => (float)($bar['4. close'] ?? 0),
                'volume' => isset($bar['5. volume']) ? (int)$bar['5. volume']   : null,
                'vwap'   => null,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }
}

// ── Yahoo Finance (unofficial, no API key) ──────────────────────

class YahooProvider extends PriceProvider {
    public function getName(): string { return 'yahoo'; }

    public function latestBarUrl(string $symbol): string {
        return 'https://query1.finance.yahoo.com/v8/finance/chart/'
             . rawurlencode($symbol) . '?interval=1d&range=5d';
    }

    public function parseLatestBarData(?array $data): ?array {
        $result = $data['chart']['result'][0] ?? null;
        if (!$result) return null;
        $timestamps = $result['timestamp']              ?? [];
        $q          = $result['indicators']['quote'][0] ?? [];
        $opens      = $q['open']   ?? [];
        $highs      = $q['high']   ?? [];
        $lows       = $q['low']    ?? [];
        $closes     = $q['close']  ?? [];
        $vols       = $q['volume'] ?? [];
        foreach (array_reverse(array_keys($closes), true) as $i) {
            if ($closes[$i] === null) continue;
            return [
                'date'   => date('Y-m-d', $timestamps[$i]),
                'open'   => isset($opens[$i]) ? (float)$opens[$i] : null,
                'high'   => isset($highs[$i]) ? (float)$highs[$i] : null,
                'low'    => isset($lows[$i])  ? (float)$lows[$i]  : null,
                'close'  => (float)$closes[$i],
                'volume' => isset($vols[$i])  ? (int)$vols[$i]    : null,
                'vwap'   => null,
            ];
        }
        return null;
    }

    public function getLatestBar(string $symbol): ?array {
        return $this->parseLatestBarData($this->httpGet($this->latestBarUrl($symbol)));
    }

    public function getHistoricalBars(string $symbol, string $from, string $to): array {
        $url  = 'https://query1.finance.yahoo.com/v8/finance/chart/'
              . rawurlencode($symbol)
              . '?interval=1d&period1=' . strtotime($from)
              . '&period2=' . (strtotime($to) + 86400);
        $data = $this->httpGet($url);
        if (!$data) return [];
        $result = $data['chart']['result'][0] ?? null;
        if (!$result) return [];
        $timestamps = $result['timestamp']              ?? [];
        $q          = $result['indicators']['quote'][0] ?? [];
        $opens      = $q['open']   ?? [];
        $highs      = $q['high']   ?? [];
        $lows       = $q['low']    ?? [];
        $closes     = $q['close']  ?? [];
        $vols       = $q['volume'] ?? [];
        $out = [];
        foreach ($timestamps as $i => $ts) {
            if (($closes[$i] ?? null) === null) continue;
            $out[] = [
                'date'   => date('Y-m-d', $ts),
                'open'   => isset($opens[$i]) ? (float)$opens[$i] : null,
                'high'   => isset($highs[$i]) ? (float)$highs[$i] : null,
                'low'    => isset($lows[$i])  ? (float)$lows[$i]  : null,
                'close'  => (float)$closes[$i],
                'volume' => isset($vols[$i])  ? (int)$vols[$i]    : null,
                'vwap'   => null,
            ];
        }
        return $out;
    }
}

// ── Factory ─────────────────────────────────────────────────────

function getPriceProvider(): ?PriceProvider {
    $provider = getSetting('price_provider', 'manual');
    return match ($provider) {
        'massive'      => new MassiveProvider(getSetting('massive_api_key', '') ?? ''),
        'alphavantage' => new AlphaVantageProvider(getSetting('alphavantage_api_key', '') ?? ''),
        'yahoo'        => new YahooProvider(),
        default        => null,
    };
}

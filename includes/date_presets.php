<?php
/**
 * Relative date-range presets for reports.
 *
 * A report URL can carry `dr=<token>` instead of literal start/end dates. The
 * token is resolved against today on every page load, so saved reports and
 * dashboard favorites stay current instead of freezing on the day they were saved.
 *
 * Tokens:
 *   today, this_month, last_month, this_quarter, last_quarter,
 *   this_year, ytd, last_year, last30, last90,
 *   cmN — the last N calendar months including the current one, through today (cm12)
 *   cyN — the last N calendar years including the current one, through today (cy5)
 *
 * applyDateRangePreset() runs from functions.php on every request: when `dr` is
 * present and valid it overwrites $_GET['start'] / $_GET['end'] (and forces
 * range=custom on reports that pick their range via a `range` param), so the
 * reports themselves keep reading start/end as before.
 */

// Reports whose date range is chosen through `range=month|year|last30|custom`.
const DATE_PRESET_RANGE_SCRIPTS = ['account_flow.php', 'income_expense.php'];

/** Fixed (non-parameterised) tokens and their labels, in reverse-detection order. */
function dateRangeFixedTokens(): array {
    return [
        'today'        => 'Today',
        'this_month'   => 'This Month',
        'last_month'   => 'Last Month',
        'this_quarter' => 'This Quarter',
        'last_quarter' => 'Last Quarter',
        'ytd'          => 'Year to Date',
        'this_year'    => 'This Year',
        'last_year'    => 'Last Year',
        'last30'       => 'Last 30 Days',
        'last90'       => 'Last 90 Days',
    ];
}

/**
 * Resolve a token to [start, end] (Y-m-d), relative to $today (defaults to now).
 * Returns null for an unknown or malformed token.
 */
function resolveDateRangeToken(string $token, ?string $today = null): ?array {
    $t  = $today !== null ? strtotime($today) : time();
    $y  = (int)date('Y', $t);
    $m  = (int)date('n', $t);
    $td = date('Y-m-d', $t);
    $q  = intdiv($m - 1, 3);                       // 0-based quarter

    switch ($token) {
        case 'today':        return [$td, $td];
        case 'this_month':   return [date('Y-m-01', $t), date('Y-m-t', $t)];
        case 'last_month':
            $lm = mktime(0, 0, 0, $m - 1, 1, $y);
            return [date('Y-m-01', $lm), date('Y-m-t', $lm)];
        case 'this_quarter':
            $qs = mktime(0, 0, 0, $q * 3 + 1, 1, $y);
            return [date('Y-m-d', $qs), date('Y-m-t', mktime(0, 0, 0, $q * 3 + 3, 1, $y))];
        case 'last_quarter':
            $qs = mktime(0, 0, 0, $q * 3 - 2, 1, $y);
            return [date('Y-m-d', $qs), date('Y-m-t', mktime(0, 0, 0, $q * 3, 1, $y))];
        case 'this_year':    return ["$y-01-01", "$y-12-31"];
        case 'ytd':          return ["$y-01-01", $td];
        case 'last_year':    return [($y - 1) . '-01-01', ($y - 1) . '-12-31'];
        case 'last30':       return [date('Y-m-d', strtotime('-29 days', $t)), $td];
        case 'last90':       return [date('Y-m-d', strtotime('-89 days', $t)), $td];
    }
    if (preg_match('/^cm([1-9]\d?|1[01]\d|120)$/', $token, $mm)) {
        $n = (int)$mm[1];
        return [date('Y-m-d', mktime(0, 0, 0, $m - ($n - 1), 1, $y)), $td];
    }
    if (preg_match('/^cy([1-9]|[1-4]\d|50)$/', $token, $mm)) {
        $n = (int)$mm[1];
        return [($y - ($n - 1)) . '-01-01', $td];
    }
    return null;
}

function isValidDateRangeToken(string $token): bool {
    return resolveDateRangeToken($token) !== null;
}

/** Human-readable label for a token ("Last Month", "Last 12 Months", …). */
function dateRangeTokenLabel(string $token): string {
    $fixed = dateRangeFixedTokens();
    if (isset($fixed[$token])) return $fixed[$token];
    if (preg_match('/^cm(\d+)$/', $token, $mm)) {
        return (int)$mm[1] === 1 ? 'Month to Date' : 'Last ' . (int)$mm[1] . ' Months';
    }
    if (preg_match('/^cy(\d+)$/', $token, $mm)) {
        return (int)$mm[1] === 1 ? 'Year to Date' : 'Last ' . (int)$mm[1] . ' Years';
    }
    return $token;
}

/**
 * Reverse detection: the token whose range today equals [start, end], or null.
 * Fixed tokens win over cmN/cyN (so Jan 1 → today is "ytd", not "cm10").
 */
function detectDateRangeToken(string $start, string $end, ?string $today = null): ?string {
    foreach (array_keys(dateRangeFixedTokens()) as $tok) {
        if (resolveDateRangeToken($tok, $today) === [$start, $end]) return $tok;
    }
    $td = $today ?? date('Y-m-d');
    if ($end !== $td || !preg_match('/^(\d{4})-(\d{2})-01$/', $start, $mm)) return null;
    $y = (int)substr($td, 0, 4);
    $m = (int)substr($td, 5, 2);
    if ($mm[2] === '01' && (int)$mm[1] < $y) {
        $tok = 'cy' . ($y - (int)$mm[1] + 1);
        if (isValidDateRangeToken($tok)) return $tok;
    }
    $n = ($y - (int)$mm[1]) * 12 + ($m - (int)$mm[2]) + 1;
    return ($n >= 1 && isValidDateRangeToken("cm$n")) ? "cm$n" : null;
}

/**
 * Hook: when `dr` is a valid token, overwrite $_GET start/end with its resolved
 * dates for this request. An invalid token is dropped so it can't leak into URLs.
 */
function applyDateRangePreset(): void {
    $GLOBALS['__dateRangePreset'] = null;
    if (!isset($_GET['dr'])) return;
    $token    = is_string($_GET['dr']) ? $_GET['dr'] : '';
    $resolved = resolveDateRangeToken($token);
    if ($resolved === null) { unset($_GET['dr']); return; }

    [$_GET['start'], $_GET['end']] = $resolved;
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), DATE_PRESET_RANGE_SCRIPTS, true)) {
        $_GET['range'] = 'custom';
    }
    $GLOBALS['__dateRangePreset'] = [
        'token' => $token,
        'start' => $resolved[0],
        'end'   => $resolved[1],
        'label' => dateRangeTokenLabel($token),
    ];
}

/** The preset applied to this request ({token,start,end,label}), or null. */
function currentDateRangePreset(): ?array {
    return $GLOBALS['__dateRangePreset'] ?? null;
}

/**
 * Quick-range buttons: label => token in, label => [token, start, end] out.
 */
function dateRangeQuickRanges(array $labelToToken): array {
    $out = [];
    foreach ($labelToToken as $label => $tok) {
        $r = resolveDateRangeToken($tok);
        if ($r) $out[$label] = [$tok, $r[0], $r[1]];
    }
    return $out;
}

/**
 * Literal and relative forms of a report URL (path + query, no BASE_PATH).
 *
 * $start / $end are the dates the page actually resolved, used for reverse
 * detection on reports that don't put start/end in the query (range=month,
 * range=year&year=…). Returns:
 *   'literal'  — dates spelled out (dr replaced by start/end)
 *   'relative' — dates as a dr token, or null when no token matches
 *   'token'    — that token, or null
 * An as_of equal to today is dropped from both forms (it means "today" anyway).
 */
function reportUrlDateVariants(string $url, ?string $start = null, ?string $end = null): array {
    $path = parse_url($url, PHP_URL_PATH) ?? $url;
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    if (($q['as_of'] ?? null) === date('Y-m-d')) unset($q['as_of']);

    $script        = basename($path);
    if (!str_ends_with($script, '.php')) $script .= '.php';   // pretty URLs drop the extension
    $isRangeScript = in_array($script, DATE_PRESET_RANGE_SCRIPTS, true);
    $build = fn(array $qq) => $path . (!empty($qq) ? '?' . http_build_query($qq) : '');

    $token = null;
    if (isset($q['dr']) && is_string($q['dr']) && isValidDateRangeToken($q['dr'])) {
        $token = $q['dr'];
    } elseif (isset($q['start'], $q['end']) && is_string($q['start']) && is_string($q['end'])) {
        // custom.php uses end=today as its own "always today" marker
        $e = $q['end'] === 'today' ? date('Y-m-d') : $q['end'];
        $token = detectDateRangeToken($q['start'], $e);
    } elseif ($start !== null && $end !== null && array_intersect_key($q, array_flip(['range', 'year']))) {
        $token = detectDateRangeToken($start, $end);
    }

    $base = $q;
    unset($base['dr'], $base['start'], $base['end']);
    if ($isRangeScript) unset($base['range'], $base['year']);

    if ($token === null) {
        return ['literal' => $build($q), 'relative' => null, 'token' => null];
    }
    [$s, $e] = resolveDateRangeToken($token);
    $literal = $isRangeScript ? ['range' => 'custom'] + $base : $base;
    $literal['start'] = $s;
    $literal['end']   = $e;
    // Keep a literal URL without dr as given (preserves custom.php's end=today etc.)
    $literalUrl = isset($q['dr']) ? $build($literal) : $build($q);
    return ['literal' => $literalUrl, 'relative' => $build($base + ['dr' => $token]), 'token' => $token];
}

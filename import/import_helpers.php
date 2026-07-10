<?php
/**
 * Shared helpers for the import pipeline.
 * Included by parse.php and map_csv.php.
 */

/**
 * Generate a deterministic SHA-1 fitid for a row that has no institution-provided one.
 * Prefixed with "GEN:" so the origin is always visible.
 */
function generateFitid(array $row): string {
    $parts = [
        $row['date']       ?? '',
        $row['payee']      ?? '',
        number_format((float)($row['amount']   ?? 0), 2, '.', ''),
        $row['symbol']     ?? '',
        number_format((float)($row['quantity'] ?? 0), 10, '.', ''),
        number_format((float)($row['price']    ?? 0), 10, '.', ''),
        (string)($row['source_row'] ?? ''),
    ];
    return 'GEN:' . sha1(implode('|', $parts));
}

/**
 * Assign a fitid to every row that doesn't already have one.
 */
function assignFitids(array &$rows): void {
    foreach ($rows as &$row) {
        if (empty($row['fitid'])) {
            $row['fitid'] = generateFitid($row);
        }
    }
    unset($row);
}

/**
 * Merge dividend + reinvestment row pairs that represent the same DRIP event.
 *
 * Some source files report a reinvested dividend as two separate lines (a
 * "Dividend"/"Interest" line and a "Reinvestment" line with quantity but no
 * price). Back-filling price on the reinvestment line naively would double
 * count income (both lines would then carry a dollar amount), so instead
 * this folds the dividend amount into the reinvestment row's price and
 * marks the dividend row to be skipped — matching the single-row convention
 * normal manual Reinvest Dividend/Cap Gain entries already use.
 *
 * Only merges the unambiguous 1-dividend-to-1-reinvestment case for a given
 * account + security + date; leaves anything ambiguous untouched.
 *
 * @param array $allRows   All parsed rows, keyed by index. Mutated in place: matched
 *                          reinvestment rows get their 'price' back-filled.
 * @param array $selected  Indices into $allRows selected for import.
 * @param bool  $isMulti   Whether this is a multi-account import.
 * @param bool  $isInv     Default is_investment flag for single-account imports.
 * @param int   $accountId Default account id for single-account imports.
 * @return array{selected: array, skipped_rows: array} Updated selection (dividend legs
 *         that were merged away removed) and report rows describing what was merged.
 */
function mergeDripReinvestmentPairs(array &$allRows, array $selected, bool $isMulti, bool $isInv, int $accountId): array {
    $dripGroups = [];
    foreach ($selected as $rawIdx) {
        $idx = (int)$rawIdx;
        if (!array_key_exists($idx, $allRows)) continue;
        $row = $allRows[$idx];

        $rIsInv = $isMulti ? ($row['is_investment'] ?? $isInv) : $isInv;
        if (!$rIsInv) continue;

        $activity = $row['activity'] ?? '';
        if (!in_array($activity, ['div', 'int', 'reinvest_div', 'reinvest_cap'], true)) continue;

        $rAcctId  = $isMulti ? (int)($row['account_id'] ?? $accountId) : $accountId;
        $symbol   = strtolower(trim($row['symbol'] ?? ''));
        $name     = strtolower(trim($row['payee']  ?? ''));
        $secKey   = $symbol !== '' ? $symbol : $name;
        $groupKey = $rAcctId . '|' . $secKey . '|' . ($row['date'] ?? '');

        $bucket = in_array($activity, ['div', 'int'], true) ? 'div' : 'reinvest';
        $dripGroups[$groupKey][$bucket][] = $idx;
    }

    $dripSkipIdx = [];
    foreach ($dripGroups as $group) {
        if (empty($group['div']) || empty($group['reinvest'])) continue;
        // Stay conservative: only merge the unambiguous 1-dividend-to-1-reinvestment case.
        if (count($group['div']) !== 1 || count($group['reinvest']) !== 1) continue;

        $divIdx   = $group['div'][0];
        $reinvIdx = $group['reinvest'][0];

        $divAmount = abs((float)($allRows[$divIdx]['amount'] ?? 0));
        $qty       = (float)($allRows[$reinvIdx]['quantity'] ?? 0);
        $curPrice  = (float)($allRows[$reinvIdx]['price']    ?? 0);

        if ($divAmount <= 0 || $qty <= 0 || $curPrice > 0) continue;

        $allRows[$reinvIdx]['price'] = round($divAmount / $qty, 6);
        $dripSkipIdx[$divIdx] = true;
    }

    $skippedRows = [];
    foreach ($dripSkipIdx as $skipIdx => $_) {
        $dRow = $allRows[$skipIdx];
        $skippedRows[] = [
            'date'         => $dRow['date']        ?? '',
            'payee'        => $dRow['payee']       ?? '',
            'action_type'  => $dRow['action_type'] ?? '',
            'amount'       => (float)($dRow['amount'] ?? 0),
            'status'       => 'skipped',
            'reason'       => 'Merged into reinvestment (DRIP)',
            'cash_account' => '',
            'cash_amount'  => 0.0,
            'account_name' => $isMulti ? ($dRow['account_name'] ?? '') : '',
        ];
    }

    if (!empty($dripSkipIdx)) {
        $selected = array_values(array_filter($selected, fn($i) => !isset($dripSkipIdx[(int)$i])));
    }

    return ['selected' => $selected, 'skipped_rows' => $skippedRows];
}

/**
 * Mark rows as probable duplicates.
 * Strategy: exact fitid match first (only for institution-provided fitids, not GEN: hashes),
 * then fall back to fuzzy date+amount+payee / date+activity+payee+qty matching.
 */
function markDuplicates(array &$rows, int $accountId, bool $isInvestment): void {
    if ($accountId <= 0) {
        // New account — nothing to match against
        foreach ($rows as &$row) { $row['is_dup'] = false; }
        unset($row);
        return;
    }

    $db = getDB();

    // --- fitid set (institution-provided only) ---
    $fitidSet = [];
    $fitStmt  = $db->prepare('SELECT fitid FROM transactions WHERE account_id = ? AND fitid IS NOT NULL');
    $fitStmt->execute([$accountId]);
    foreach ($fitStmt->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $fitidSet[$f] = true;
    }

    // --- fuzzy set ---
    $fuzzySet = [];
    if (!$isInvestment) {
        $stmt = $db->prepare('SELECT transaction_date, amount, payee FROM transactions WHERE account_id = ?');
        $stmt->execute([$accountId]);
        foreach ($stmt->fetchAll() as $r) {
            $key = $r['transaction_date'] . '|' . number_format((float)$r['amount'], 2) . '|' . mb_strtolower(trim($r['payee']));
            $fuzzySet[$key] = true;
        }
    } else {
        $stmt = $db->prepare(
            'SELECT t.transaction_date, it.activity, t.payee, it.quantity
             FROM transactions t JOIN investment_transactions it ON it.transaction_id = t.id
             WHERE t.account_id = ?'
        );
        $stmt->execute([$accountId]);
        foreach ($stmt->fetchAll() as $r) {
            $key = $r['transaction_date'] . '|' . $r['activity'] . '|' . mb_strtolower(trim($r['payee'])) . '|' . number_format((float)$r['quantity'], 6);
            $fuzzySet[$key] = true;
        }
    }

    foreach ($rows as &$row) {
        // Exact fitid match (skip GEN: hashes — they're deterministic from content, so a
        // re-import of the same file would regenerate the same hash; use fuzzy instead)
        $fitid = $row['fitid'] ?? '';
        if ($fitid !== '' && !str_starts_with($fitid, 'GEN:') && isset($fitidSet[$fitid])) {
            $row['is_dup'] = true;
            continue;
        }

        // Fuzzy fallback
        if (!$isInvestment) {
            $key = $row['date'] . '|' . number_format((float)$row['amount'], 2) . '|' . mb_strtolower(trim($row['payee']));
        } else {
            $key = $row['date'] . '|' . ($row['activity'] ?? '') . '|' . mb_strtolower(trim($row['payee'])) . '|' . number_format((float)($row['quantity'] ?? 0), 6);
        }
        $row['is_dup'] = isset($fuzzySet[$key]);
    }
    unset($row);
}

/**
 * Returns [needs_cash_leg, use_x_account, cash_txn_type] for an investment action type.
 * cash_txn_type: 'transfer'|'deposit'|'withdrawal'|null (null = derive from amount sign).
 * ContribX/WithdrwX are flagged use_x_account but handled as bidirectional pairs in confirm.php.
 */
function investCashRouting(string $actionType): array {
    static $map = [
        'Buy'      => [true,  false, 'transfer'],
        'BuyX'     => [true,  true,  'transfer'],
        'Sell'     => [true,  false, 'transfer'],
        'SellX'    => [true,  true,  'transfer'],
        'ShtSell'  => [true,  false, 'transfer'],
        'CvrShrt'  => [true,  false, 'transfer'],
        'Exercise' => [true,  false, 'transfer'],
        'ExercisX' => [true,  true,  'transfer'],
        'Div'      => [true,  false, 'deposit'],
        'DivX'     => [true,  true,  'deposit'],
        'IntInc'   => [true,  false, 'deposit'],
        'IntIncX'  => [true,  true,  'deposit'],
        'CGLong'   => [true,  false, 'deposit'],
        'CGLongX'  => [true,  true,  'deposit'],
        'CGMid'    => [true,  false, 'deposit'],
        'CGMidX'   => [true,  true,  'deposit'],
        'CGShort'  => [true,  false, 'deposit'],
        'CGShortX' => [true,  true,  'deposit'],
        'MiscInc'  => [true,  false, 'deposit'],
        'MiscIncX' => [true,  true,  'deposit'],
        'MiscExp'  => [true,  false, 'withdrawal'],
        'MiscExpX' => [true,  true,  'withdrawal'],
        'MargInt'  => [true,  false, 'withdrawal'],
        'MargIntX' => [true,  true,  'withdrawal'],
        'RtrnCap'  => [true,  false, 'deposit'],
        'RtrnCapX' => [true,  true,  'deposit'],
        'XIn'      => [true,  false, 'transfer'],
        'XOut'     => [true,  false, 'transfer'],
        'ContribX' => [true,  true,  'transfer'],
        'WithdrwX' => [true,  true,  'transfer'],
        'Cash'     => [true,  false, null],
    ];
    return $map[$actionType] ?? [false, false, null];
}

/** Human-readable label for a canonical action type string. */
function actionTypeLabel(string $actionType): string {
    static $labels = [
        'Buy'      => 'Buy',
        'BuyX'     => 'Buy (X-Acct)',
        'Sell'     => 'Sell',
        'SellX'    => 'Sell (X-Acct)',
        'ShtSell'  => 'Short Sell',
        'CvrShrt'  => 'Cover Short',
        'Exercise' => 'Exercise',
        'ExercisX' => 'Exercise (X-Acct)',
        'Div'      => 'Dividend',
        'DivX'     => 'Dividend (X-Acct)',
        'IntInc'   => 'Interest Income',
        'IntIncX'  => 'Interest (X-Acct)',
        'CGLong'   => 'Cap. Gain LT',
        'CGLongX'  => 'Cap. Gain LT (X)',
        'CGMid'    => 'Cap. Gain MT',
        'CGMidX'   => 'Cap. Gain MT (X)',
        'CGShort'  => 'Cap. Gain ST',
        'CGShortX' => 'Cap. Gain ST (X)',
        'MiscInc'  => 'Misc. Income',
        'MiscIncX' => 'Misc. Income (X)',
        'MiscExp'  => 'Misc. Expense',
        'MiscExpX' => 'Misc. Expense (X)',
        'MargInt'  => 'Margin Interest',
        'MargIntX' => 'Margin Int. (X)',
        'RtrnCap'  => 'Return of Capital',
        'RtrnCapX' => 'Return of Cap. (X)',
        'ReinvDiv' => 'Reinvest Div.',
        'ReinvInt' => 'Reinvest Int.',
        'ReinvLg'  => 'Reinvest LT Cap.',
        'ReinvMd'  => 'Reinvest MT Cap.',
        'ReinvSh'  => 'Reinvest ST Cap.',
        'XIn'      => 'Transfer In',
        'XOut'     => 'Transfer Out',
        'ContribX' => 'Contribution',
        'WithdrwX' => 'Withdrawal',
        'ShrsIn'   => 'Shares In',
        'ShrsOut'  => 'Shares Out',
        'StkSplit' => 'Stock Split',
        'Grant'    => 'Grant',
        'Vest'     => 'Vest',
        'Expire'   => 'Expire',
        'Reprice'  => 'Reprice',
        'Cash'     => 'Cash',
    ];
    return $labels[$actionType] ?? $actionType;
}

/**
 * Normalize a raw QIF N-field action string to its canonical PascalCase form.
 */
function qifCanonicalAction(string $raw): string {
    static $map = [
        'buy'       => 'Buy',       'buyx'      => 'BuyX',
        'sell'      => 'Sell',      'sellx'     => 'SellX',
        'shtsell'   => 'ShtSell',   'cvrshrt'   => 'CvrShrt',
        'reinvdiv'  => 'ReinvDiv',  'reinvint'  => 'ReinvInt',
        'reinvlg'   => 'ReinvLg',   'reinvmd'   => 'ReinvMd',  'reinvsh'  => 'ReinvSh',
        'shrsin'    => 'ShrsIn',    'shrsout'   => 'ShrsOut',
        'stksplit'  => 'StkSplit',
        'grant'     => 'Grant',     'vest'      => 'Vest',
        'exercise'  => 'Exercise',  'exercisx'  => 'ExercisX',
        'expire'    => 'Expire',    'reprice'   => 'Reprice',
        'div'       => 'Div',       'divx'      => 'DivX',
        'intinc'    => 'IntInc',    'intincx'   => 'IntIncX',
        'cglong'    => 'CGLong',    'cglongx'   => 'CGLongX',
        'cgmid'     => 'CGMid',     'cgmidx'    => 'CGMidX',
        'cgshort'   => 'CGShort',   'cgshortx'  => 'CGShortX',
        'miscinc'   => 'MiscInc',   'miscincx'  => 'MiscIncX',
        'miscexp'   => 'MiscExp',   'miscexpx'  => 'MiscExpX',
        'margint'   => 'MargInt',   'margintx'  => 'MargIntX',
        'rtrncap'   => 'RtrnCap',   'rtrncapx'  => 'RtrnCapX',
        'cash'      => 'Cash',
        'xin'       => 'XIn',       'xout'      => 'XOut',
        'contribx'  => 'ContribX',  'withdrwx'  => 'WithdrwX',
    ];
    return $map[strtolower(trim($raw))] ?? trim($raw);
}

/**
 * Map a canonical action_type to the activity bucket stored in investment_transactions.
 */
function actionTypeToActivity(string $actionType): string {
    return match($actionType) {
        'Buy', 'BuyX', 'CvrShrt', 'Exercise', 'ExercisX'                    => 'buy',
        'Sell', 'SellX', 'ShtSell'                                          => 'sell',
        'ReinvDiv', 'ReinvInt'                                              => 'reinvest_div',
        'ReinvLg', 'ReinvMd', 'ReinvSh'                                     => 'reinvest_cap',
        'ShrsOut', 'Expire'                                                 => 'remove',
        'StkSplit'                                                          => 'split',
        'Div', 'DivX', 'CGLong', 'CGLongX', 'CGShort', 'CGShortX',
        'CGMid', 'CGMidX', 'MiscInc', 'MiscIncX', 'RtrnCap', 'RtrnCapX'     => 'div',
        'IntInc', 'IntIncX', 'MargInt', 'MargIntX'                         => 'int',
        default                                                             => 'add',
    };
}

/**
 * Auto-detect action_type and activity from a free-text activity string (CSV imports).
 * Returns [activity, action_type].  Order: most specific first.
 */
function csvActivityToActionType(string $raw): array {
    $s = strtolower(trim($raw));
    if ($s === '')                                                                         return ['add',         ''];
    if (str_contains($s, 'short sale') || $s === 'shtsell')                              return ['sell',        'ShtSell'];
    if (str_contains($s, 'cover short') || $s === 'cvrshrt')                             return ['buy',         'CvrShrt'];
    if (str_contains($s, 'exercisx')  || $s === 'exercisx')                              return ['buy',         'ExercisX'];
    if (str_contains($s, 'exercise')  || $s === 'exercise')                              return ['buy',         'Exercise'];
    if (str_contains($s, 'expire'))                                                       return ['remove',      'Expire'];
    if ($s === 'buyx' || str_contains($s, 'buy x'))                                      return ['buy',         'BuyX'];
    if ($s === 'sellx' || str_contains($s, 'sell x'))                                    return ['sell',        'SellX'];
    if (str_contains($s, 'sell'))                                                         return ['sell',        'Sell'];
    if (str_contains($s, 'reinvdiv')  || (str_contains($s, 'reinv') && str_contains($s, 'div')))             return ['reinvest_div', 'ReinvDiv'];
    if (str_contains($s, 'reinvint')  || (str_contains($s, 'reinv') && str_contains($s, 'int')))             return ['reinvest_div', 'ReinvInt'];
    if (str_contains($s, 'reinvlg')   || (str_contains($s, 'reinv') && str_contains($s, 'long')))            return ['reinvest_cap', 'ReinvLg'];
    if (str_contains($s, 'reinvmd')   || (str_contains($s, 'reinv') && str_contains($s, 'mid')))             return ['reinvest_cap', 'ReinvMd'];
    if (str_contains($s, 'reinvsh')   || (str_contains($s, 'reinv') && str_contains($s, 'cap') && str_contains($s, 'sh'))) return ['reinvest_cap', 'ReinvSh'];
    if (str_contains($s, 'reinv'))                                                        return ['reinvest_div', 'ReinvDiv'];
    if (str_contains($s, 'buy'))                                                          return ['buy',         'Buy'];
    if (str_contains($s, 'stksplit') || str_contains($s, 'stock split') || str_contains($s, 'split')) return ['split', 'StkSplit'];
    if (str_contains($s, 'shrsin')   || str_contains($s, 'shares in')  || str_contains($s, 'transfer in'))  return ['add',    'ShrsIn'];
    if (str_contains($s, 'shrsout')  || str_contains($s, 'shares out') || str_contains($s, 'transfer out')) return ['remove', 'ShrsOut'];
    if (str_contains($s, 'divx')     || $s === 'divx')                                   return ['div',         'DivX'];
    if (str_contains($s, 'div')      || str_contains($s, 'dividend'))                    return ['div',         'Div'];
    if (str_contains($s, 'intincx')  || str_contains($s, 'int inc x'))                  return ['int',         'IntIncX'];
    if (str_contains($s, 'intinc')   || str_contains($s, 'int inc')   || str_contains($s, 'interest income')) return ['int', 'IntInc'];
    if (str_contains($s, 'interest'))                                                     return ['int',         'IntInc'];
    if (str_contains($s, 'cglongx'))                                                      return ['div',         'CGLongX'];
    if (str_contains($s, 'cglong')   || (str_contains($s, 'cap') && str_contains($s, 'gain') && str_contains($s, 'long'))) return ['div', 'CGLong'];
    if (str_contains($s, 'cgshortx'))                                                     return ['div',         'CGShortX'];
    if (str_contains($s, 'cgshort')  || (str_contains($s, 'cap') && str_contains($s, 'gain') && str_contains($s, 'short'))) return ['div', 'CGShort'];
    if (str_contains($s, 'cgmidx'))                                                       return ['div',         'CGMidX'];
    if (str_contains($s, 'cgmid')    || (str_contains($s, 'cap') && str_contains($s, 'gain') && str_contains($s, 'mid')))   return ['div', 'CGMid'];
    if (str_contains($s, 'cap')      && str_contains($s, 'gain'))                        return ['div',         'CGLong'];
    if (str_contains($s, 'margintx') || str_contains($s, 'margin int x'))                return ['int',         'MargIntX'];
    if (str_contains($s, 'margint')  || str_contains($s, 'margin int') || str_contains($s, 'margin interest')) return ['int', 'MargInt'];
    if (str_contains($s, 'rtrncapx'))                                                     return ['div',         'RtrnCapX'];
    if (str_contains($s, 'rtrncap')  || str_contains($s, 'return of cap') || str_contains($s, 'return of capital')) return ['div', 'RtrnCap'];
    if (str_contains($s, 'miscexpx') || str_contains($s, 'misc exp x'))                  return ['add',         'MiscExpX'];
    if (str_contains($s, 'miscexp')  || str_contains($s, 'misc exp'))                    return ['add',         'MiscExp'];
    if (str_contains($s, 'miscincx') || str_contains($s, 'misc inc x'))                  return ['div',         'MiscIncX'];
    if (str_contains($s, 'miscinc')  || str_contains($s, 'misc inc') || str_contains($s, 'misc income')) return ['div', 'MiscInc'];
    if (str_contains($s, 'grant'))                                                        return ['add',         'Grant'];
    if (str_contains($s, 'vest'))                                                         return ['add',         'Vest'];
    if (str_contains($s, 'contribx') || str_contains($s, 'contribution'))                return ['add',         'ContribX'];
    if (str_contains($s, 'withdrwx') || str_contains($s, 'withdrawal'))                  return ['add',         'WithdrwX'];
    if ($s === 'xin'  || str_contains($s, ' xin '))                                      return ['add',         'XIn'];
    if ($s === 'xout' || str_contains($s, ' xout '))                                     return ['add',         'XOut'];
    if (str_contains($s, 'cash'))                                                         return ['add',         'Cash'];
    if (str_contains($s, 'remov') || str_contains($s, 'withdraw'))                       return ['remove',      'ShrsOut'];
    return ['add', ''];
}

/**
 * Return current share quantities for each security held in an account.
 * Key = lowercase ticker symbol, or lowercase name when no symbol is set.
 */
function getAccountHoldings(int $accountId): array {
    if ($accountId <= 0) return [];
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT i.name, i.symbol,
                SUM(CASE
                    WHEN it.activity IN (\'buy\',\'add\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                    WHEN it.activity IN (\'sell\',\'remove\')                               THEN -it.quantity
                    ELSE 0
                END) AS net_qty
         FROM investment_transactions it
         JOIN transactions t ON t.id  = it.transaction_id
         JOIN investments   i ON i.id = it.investment_id
         WHERE t.account_id = ? AND i.is_active = 1
         GROUP BY i.id, i.name, i.symbol
         HAVING ABS(net_qty) > 0.0001'
    );
    $stmt->execute([$accountId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $sym = trim($row['symbol'] ?? '');
        $nm  = trim($row['name']   ?? '');
        // Normalize key: strip all non-alphanumeric so BRK-B and BRKB both → brkb
        $symNorm = strtolower(preg_replace('/[^A-Z0-9]/i', '', $sym));
        $key = $symNorm !== '' ? $symNorm : strtolower($nm);
        if ($key === '') continue;
        $result[$key] = ['qty' => (float)$row['net_qty'], 'name' => $nm, 'symbol' => $sym];
    }
    return $result;
}

/**
 * Compare a holdings snapshot against current DB quantities and return
 * ShrsIn / ShrsOut reconciliation rows for the import pipeline.
 *
 * $snapshot keys = lowercase-symbol-or-name, same convention as getAccountHoldings().
 * Each entry: ['name', 'symbol', 'qty', 'price', 'date']
 */
function reconcileHoldings(array $snapshot, array $currentHoldings, string $asOfDate): array {
    $rows = [];

    // Build name → key maps for fallback matching when the symbol key doesn't align.
    // This handles brokers that omit tickers for money-market / cash-equivalent rows.
    $dbByName = [];
    foreach ($currentHoldings as $dbKey => $cur) {
        $nk = strtolower(trim($cur['name'] ?? ''));
        if ($nk !== '' && !isset($dbByName[$nk])) $dbByName[$nk] = $dbKey;
    }
    $snapByName = [];
    foreach ($snapshot as $snapKey => $snap) {
        $nk = strtolower(trim($snap['name'] ?? ''));
        if ($nk !== '' && !isset($snapByName[$nk])) $snapByName[$nk] = $snapKey;
    }

    $matchedDbKeys = [];

    foreach ($snapshot as $key => $snap) {
        // Primary match by normalized symbol key; fallback to security name.
        if (isset($currentHoldings[$key])) {
            $dbKey = $key;
        } else {
            $nk    = strtolower(trim($snap['name'] ?? ''));
            $dbKey = ($nk !== '' && isset($dbByName[$nk])) ? $dbByName[$nk] : null;
        }

        $curQty = $dbKey !== null ? $currentHoldings[$dbKey]['qty'] : 0.0;
        if ($dbKey !== null) $matchedDbKeys[$dbKey] = true;

        $diff = $snap['qty'] - $curQty;
        if (abs($diff) < 0.0001) continue;

        $rows[] = [
            'date'              => $snap['date']   ?? $asOfDate,
            'payee'             => $snap['name']   ?? $key,
            'symbol'            => $snap['symbol'] ?? '',
            'activity'          => $diff > 0 ? 'add'    : 'remove',
            'action_type'       => $diff > 0 ? 'ShrsIn' : 'ShrsOut',
            'quantity'          => abs($diff),
            'price'             => $snap['price']  ?? 0.0,
            'commission'        => 0.0,
            'amount'            => 0.0,
            'memo'              => 'Holdings reconciliation',
            'transfer_account'  => null,
            'holdings_current'  => $curQty,
            'holdings_snapshot' => $snap['qty'],
            'is_dup'            => false,
        ];
    }

    // Securities in DB but absent from statement → remove remaining quantity.
    // Skip any DB entry already matched above (by key or by name fallback).
    foreach ($currentHoldings as $key => $cur) {
        if ($cur['qty'] <= 0.0001 || isset($matchedDbKeys[$key])) continue;
        // Secondary: DB entry name matched a snapshot entry (already counted above)
        $nk = strtolower(trim($cur['name'] ?? ''));
        if ($nk !== '' && isset($snapByName[$nk])) continue;
        $rows[] = [
            'date'              => $asOfDate,
            'payee'             => $cur['name'],
            'symbol'            => $cur['symbol'],
            'activity'          => 'remove',
            'action_type'       => 'ShrsOut',
            'quantity'          => $cur['qty'],
            'price'             => 0.0,
            'commission'        => 0.0,
            'amount'            => 0.0,
            'memo'              => 'Holdings reconciliation — not in statement',
            'transfer_account'  => null,
            'holdings_current'  => $cur['qty'],
            'holdings_snapshot' => 0.0,
            'is_dup'            => false,
        ];
    }

    return $rows;
}

/**
 * Scan all parsed banking rows, collect every unique category string,
 * and resolve each against the categories table.
 * Returns a map: raw_string → [display, cat_id, sub_id, is_new, parent_name, sub_name]
 */
function buildCategoryMap(array $rows): array {
    $db = getDB();

    $catStrings = [];
    foreach ($rows as $r) {
        if (!empty($r['category'])) $catStrings[$r['category']] = true;
        foreach ($r['splits'] ?? [] as $sp) {
            if (!empty($sp['category'])) $catStrings[$sp['category']] = true;
        }
    }
    if (empty($catStrings)) return [];

    $allCats = $db->query('SELECT id, name, parent_id, type FROM categories WHERE is_active = 1')->fetchAll();
    $byName  = [];
    foreach ($allCats as $c) {
        $byName[strtolower($c['name'])][] = $c;
    }

    $map = [];
    foreach (array_keys($catStrings) as $catStr) {
        $colonPos   = strpos($catStr, ':');
        $parentName = $colonPos !== false ? trim(substr($catStr, 0, $colonPos)) : trim($catStr);
        $subName    = $colonPos !== false ? trim(substr($catStr, $colonPos + 1)) : null;

        $parentRow = null;
        foreach ($byName[strtolower($parentName)] ?? [] as $c) {
            if ($c['parent_id'] === null) { $parentRow = $c; break; }
        }
        if (!$parentRow) $parentRow = ($byName[strtolower($parentName)] ?? [])[0] ?? null;

        $subRow = null;
        if ($subName !== null && $parentRow) {
            foreach ($byName[strtolower($subName)] ?? [] as $c) {
                if ((int)$c['parent_id'] === (int)$parentRow['id']) { $subRow = $c; break; }
            }
        }

        $catId   = $parentRow ? (int)$parentRow['id'] : null;
        $subId   = $subRow    ? (int)$subRow['id']    : null;
        $isNew   = ($parentRow === null) || ($subName !== null && $subRow === null);
        $display = $subName !== null ? ($parentName . ' › ' . $subName) : $parentName;

        $map[$catStr] = [
            'display'     => $display,
            'cat_id'      => $catId,
            'sub_id'      => $subId,
            'is_new'      => $isNew,
            'parent_name' => $parentName,
            'sub_name'    => $subName,
        ];
    }

    return $map;
}

function resolveCatIds(?string $catStr, array $catMap): array {
    if ($catStr === null || !isset($catMap[$catStr])) return [null, null];
    return [$catMap[$catStr]['cat_id'] ?: null, $catMap[$catStr]['sub_id'] ?: null];
}

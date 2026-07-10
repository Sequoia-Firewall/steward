<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

verifyCsrf();

$importMode = $_POST['import_mode'] ?? 'existing';
$newAccount = null;

if ($importMode === 'new') {
    $newAcctName = trim($_POST['new_account_name'] ?? '');
    $newAcctType = $_POST['new_account_type'] ?? 'Checking';
    $newAcctInst = trim($_POST['new_account_institution'] ?? '');
    $newAcctCurr = strtoupper(trim($_POST['new_account_currency'] ?? 'USD')) ?: 'USD';

    if ($newAcctName === '') {
        setFlash('error', 'Account name is required when creating a new account.');
        header('Location: ' . BASE_PATH . '/import/index');
        exit;
    }
    if (!in_array($newAcctType, ['Checking', 'Savings', 'Credit Card', 'Investment'])) {
        $newAcctType = 'Checking';
    }

    $newAccount = [
        'name'            => $newAcctName,
        'type'            => $newAcctType,
        'institution'     => $newAcctInst,
        'currency'        => $newAcctCurr,
        'opening_balance' => 0.0,
    ];

    // Fake a minimal account array so the rest of parse.php works unchanged
    $accountId = 0;
    $account   = ['id' => 0, 'name' => $newAcctName, 'type' => $newAcctType, 'is_investment_cash' => 0];
} else {
    $accountId = (int)($_POST['account_id'] ?? 0);
    $account   = getAccount($accountId);
    if (!$account || $account['is_investment_cash'] || !in_array($account['type'], ['Checking', 'Savings', 'Credit Card', 'Investment'])) {
        setFlash('error', 'Invalid account selected.');
        header('Location: ' . BASE_PATH . '/import/index');
        exit;
    }
}

if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    $errCodes = [1=>'File too large',2=>'File too large',3=>'Partial upload',4=>'No file selected'];
    $msg = $errCodes[$_FILES['import_file']['error'] ?? 4] ?? 'Upload failed';
    setFlash('error', $msg . '. Please try again.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

$file    = $_FILES['import_file'];
$content = file_get_contents($file['tmp_name']);

if ($content === false || trim($content) === '') {
    setFlash('error', 'Uploaded file is empty or unreadable.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

// Detect format from extension, fall back to content sniffing
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (in_array($ext, ['ofx', 'qfx'])) {
    $format = 'ofx';
} elseif ($ext === 'qif') {
    $format = 'qif';
} elseif ($ext === 'csv') {
    $format = 'csv';
} else {
    $head = substr($content, 0, 300);
    if (stripos($head, 'OFXHEADER') !== false || stripos($head, '<OFX>') !== false) {
        $format = 'ofx';
    } elseif (preg_match('/^!Type:/im', $head)) {
        $format = 'qif';
    } else {
        $format = 'csv';
    }
}

$isInvestment = ($account['type'] === 'Investment');

$stmtType = 'transaction_history';
if ($isInvestment && strtolower(trim($_POST['statement_type'] ?? '')) === 'holdings') {
    $stmtType = 'holdings';
}

// Multi-account QIF: bypass single-account validation and route to account mapper
if ($format === 'qif' && $stmtType === 'transaction_history' && isMultiAccountQIF($content)) {
    $parsed = parseQIFMulti($content);
    if (count($parsed['accounts']) >= 2) {
        $_SESSION['import_qif_multi'] = [
            'accounts'        => $parsed['accounts'],
            'rows_by_account' => $parsed['rows_by_account'],
            'format'          => 'QIF',
        ];
        header('Location: ' . BASE_PATH . '/import/map_accounts');
        exit;
    }
}

// Block Transaction History imports for investment accounts with no linked cash account
if ($isInvestment && $importMode !== 'new' && $stmtType === 'transaction_history') {
    $lcStmt = getDB()->prepare('SELECT linked_account_id FROM accounts WHERE id = ? AND is_active = 1');
    $lcStmt->execute([$accountId]);
    if (!(int)($lcStmt->fetchColumn() ?: 0)) {
        setFlash('error', 'Transaction History import requires a linked cash account. Configure one in Account Settings first, or switch to Positions / Holdings import mode.');
        header('Location: ' . BASE_PATH . '/import/index');
        exit;
    }
}

// CSV goes to column mapper
if ($format === 'csv') {
    $_SESSION['import_csv'] = [
        'account_id'     => $accountId,
        'account_name'   => $account['name'],
        'account_type'   => $account['type'],
        'is_investment'  => $isInvestment,
        'statement_type' => $stmtType,
        'content'        => $content,
        'new_account'    => $newAccount,
    ];
    header('Location: ' . BASE_PATH . '/import/map_csv');
    exit;
}

try {
    if ($stmtType === 'holdings') {
        if ($format === 'qif') {
            throw new Exception('Holdings / Positions import is not supported for QIF files. Export as OFX/QFX or CSV instead.');
        }
        $rows = parseOFXHoldings($content, $accountId);
    } else {
        $rows = match($format) {
            'qif' => parseQIF($content, $isInvestment),
            'ofx' => parseOFX($content, $isInvestment),
        };
    }
} catch (Exception $e) {
    setFlash('error', 'Parse error: ' . $e->getMessage());
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

if (empty($rows)) {
    if ($stmtType === 'holdings') {
        setFlash('success', 'Holdings are already up to date — no reconciliation needed.');
    } else {
        setFlash('error', 'No transactions found in the uploaded file.');
    }
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

// Stamp each row with its 1-based position in the file (used in fitid hash)
foreach ($rows as $i => &$row) { $row['source_row'] = $i + 1; }
unset($row);

// Assign fitids; skip duplicate detection for holdings (all rows are reconciliation entries)
assignFitids($rows);
if ($stmtType !== 'holdings') {
    markDuplicates($rows, $accountId, $isInvestment);
}

$catMap = ($format === 'qif' && !$isInvestment) ? buildCategoryMap($rows) : [];

$_SESSION['import'] = [
    'account_id'     => $accountId,
    'account_name'   => $account['name'],
    'account_type'   => $account['type'],
    'is_investment'  => $isInvestment,
    'statement_type' => $stmtType,
    'format'         => strtoupper($format),
    'rows'           => $rows,
    'cat_map'        => $catMap,
    'new_account'    => $newAccount,
];

header('Location: ' . BASE_PATH . '/import/preview');
exit;


// ── QIF Parser ────────────────────────────────────────────────────

function parseQIF(string $content, bool $isInvestment): array {
    $lines       = preg_split('/\r?\n|\r/', $content);
    $rows        = [];
    $cur         = [];
    $active      = false;
    $isSecurity  = false;
    $securityMap = []; // lowercase name → ticker symbol

    foreach ($lines as $raw) {
        $line = rtrim($raw);
        if ($line === '') continue;

        $code  = $line[0];
        $value = substr($line, 1);

        if ($code === '!') {
            $directive = strtolower(trim($value));
            if ($directive === 'type:security') {
                $isSecurity = true;
                $active     = false;
            } elseif ($directive === 'type:memorized') {
                $isSecurity = false;
                $active     = false;
            } elseif (str_starts_with($directive, 'type:')) {
                $isSecurity = false;
                $active     = true;
            } elseif ($directive === 'account' || str_starts_with($directive, 'clear:')) {
                $isSecurity = false;
                $active     = false;
            }
            continue;
        }

        // Collect !Type:Security blocks into the name→symbol map
        if ($isSecurity) {
            if ($code === '^') {
                $secName   = trim($cur['N'][0] ?? '');
                $secSymbol = trim($cur['S'][0] ?? '');
                if ($secName !== '' && $secSymbol !== '') {
                    $securityMap[strtolower($secName)] = $secSymbol;
                }
                $cur = [];
            } else {
                $cur[$code][] = $value;
            }
            continue;
        }

        if (!$active) continue;

        if ($code === '^') {
            if ($cur) {
                $row = $isInvestment ? normalizeQIFInvestment($cur, $securityMap) : normalizeQIFBanking($cur);
                if ($row) $rows[] = $row;
            }
            $cur = [];
            continue;
        }

        // S, E, $ accumulate (one per split line); all other codes keep only first occurrence
        $cur[$code][] = $value;
    }

    if ($cur) {
        $row = $isInvestment ? normalizeQIFInvestment($cur, $securityMap) : normalizeQIFBanking($cur);
        if ($row) $rows[] = $row;
    }

    return $rows;
}

function qifVal(array $cur, string $key): string {
    return trim($cur[$key][0] ?? '');
}

function qifAmount(string $raw): float {
    return (float)str_replace([',', ' ', '$'], '', $raw);
}

function qifDate(string $raw): string {
    // Normalize separators: ' (Quicken year), - all become /
    $raw   = trim(str_replace(["'", '-'], '/', $raw));
    $parts = explode('/', $raw);
    if (count($parts) === 3) {
        // Handle both M/D/Y and D/M/Y — trust M/D/Y (US default)
        [$m, $d, $y] = [(int)$parts[0], (int)$parts[1], (int)$parts[2]];
        if ($y < 100) $y += ($y < 30 ? 2000 : 1900);
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

/** Parse a QIF category/transfer string from L or S field.
 *  Returns ['category' => 'Parent:Sub', 'is_transfer' => false, 'transfer_account' => null]
 *  or      ['category' => null,          'is_transfer' => true,  'transfer_account' => 'Checking']
 */
function qifParseCategory(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return ['category' => null, 'is_transfer' => false, 'transfer_account' => null];

    // Transfer: [Account Name]
    if ($raw[0] === '[' && str_ends_with($raw, ']')) {
        return ['category' => null, 'is_transfer' => true, 'transfer_account' => substr($raw, 1, -1)];
    }

    // Strip class modifier after /  (rarely used: "Food:Groceries/MyClass")
    $cat = explode('/', $raw)[0];
    return ['category' => $cat, 'is_transfer' => false, 'transfer_account' => null];
}

function normalizeQIFBanking(array $cur): ?array {
    $amtRaw = qifVal($cur, 'T');
    if ($amtRaw === '') return null;

    $c       = strtolower(qifVal($cur, 'C'));
    $cleared = match(true) { $c === 'x' => 'reconciled', $c === 'c' || $c === '*' => 'cleared', default => '' };

    // Main category / transfer (L field)
    $lParsed      = qifParseCategory(qifVal($cur, 'L'));
    $category     = $lParsed['category'];
    $isTransfer   = $lParsed['is_transfer'];
    $transferAcct = $lParsed['transfer_account'];

    // Split lines: S[] = category, E[] = memo, $[] = amount (parallel arrays)
    $splitCatRaws  = $cur['S'] ?? [];
    $splitMemos    = $cur['E'] ?? [];
    $splitAmtRaws  = $cur['$'] ?? [];

    $splits = [];
    foreach ($splitCatRaws as $i => $sc) {
        $sp        = qifParseCategory(trim($sc));
        $splits[]  = [
            'category'         => $sp['category'],
            'is_transfer'      => $sp['is_transfer'],
            'transfer_account' => $sp['transfer_account'],
            'memo'             => trim($splitMemos[$i] ?? ''),
            'amount'           => isset($splitAmtRaws[$i]) ? qifAmount($splitAmtRaws[$i]) : 0.0,
        ];
    }

    return [
        'date'             => qifDate(qifVal($cur, 'D')),
        'payee'            => qifVal($cur, 'P'),
        'amount'           => qifAmount($amtRaw),
        'memo'             => qifVal($cur, 'M'),
        'num'              => qifVal($cur, 'N'),
        'cleared'          => $cleared,
        'category'         => $category,
        'is_transfer'      => $isTransfer,
        'transfer_account' => $transferAcct,
        'splits'           => $splits,
    ];
}

function normalizeQIFInvestment(array $cur, array $securityMap = []): ?array {
    // Action types where a security (Y field) is not required
    static $cashOnly = ['Cash','XIn','XOut','ContribX','WithdrwX'];
    static $income   = [
        'Div','DivX','IntInc','IntIncX',
        'CGLong','CGLongX','CGMid','CGMidX','CGShort','CGShortX',
        'MiscInc','MiscIncX','MiscExp','MiscExpX',
        'MargInt','MargIntX','RtrnCap','RtrnCapX',
    ];

    $actionType = qifCanonicalAction(qifVal($cur, 'N'));
    $activity   = actionTypeToActivity($actionType);

    $security = trim(qifVal($cur, 'Y'));

    // Drop the record only if security is absent AND the action type strictly requires one
    $securityOptional = in_array($actionType, $cashOnly) || in_array($actionType, $income);
    if (!$security && !$securityOptional) return null;

    $qty    = (float)str_replace(',', '', qifVal($cur, 'Q'));
    $price  = (float)str_replace(',', '', qifVal($cur, 'I'));
    $comm   = (float)str_replace(',', '', qifVal($cur, 'O'));
    $total  = qifAmount(qifVal($cur, 'T') ?: '0');
    $symbol = $security ? ($securityMap[strtolower($security)] ?? '') : '';

    // Some exporters (e.g. Merrill Edge) always write T as a positive value.
    // Enforce the correct sign so the cash leg is debited/credited correctly:
    // cash-out actions must be negative; cash-in actions must be positive.
    if ($total != 0.0) {
        static $mustBeNeg = ['Buy','BuyX','CvrShrt','Exercise','ExercisX',
                             'ReinvDiv','ReinvInt','ReinvLg','ReinvMd','ReinvSh',
                             'XOut','WithdrwX','MiscExp','MiscExpX','MargInt','MargIntX'];
        static $mustBePos = ['Sell','SellX','ShtSell',
                             'Div','DivX','IntInc','IntIncX',
                             'CGLong','CGLongX','CGMid','CGMidX','CGShort','CGShortX',
                             'MiscInc','MiscIncX','RtrnCap','RtrnCapX','XIn','ContribX'];
        if      (in_array($actionType, $mustBeNeg) && $total > 0.0) $total = -$total;
        elseif  (in_array($actionType, $mustBePos) && $total < 0.0) $total = -$total;
    }

    // Payee: security name when present; otherwise use P field or action label
    $payee = $security ?: (qifVal($cur, 'P') ?: $actionType);

    // Linked account from L field — present on X-type transfers (e.g. BuyX, DivX)
    $lField          = qifVal($cur, 'L');
    $transferAccount = ($lField !== '' && $lField[0] === '[' && str_ends_with($lField, ']'))
        ? substr($lField, 1, -1)
        : null;

    return [
        'date'             => qifDate(qifVal($cur, 'D')),
        'payee'            => $payee,
        'symbol'           => $symbol,
        'activity'         => $activity,
        'action_type'      => $actionType,
        'quantity'         => $qty,
        'price'            => $price,
        'commission'       => $comm,
        'amount'           => $total,
        'memo'             => qifVal($cur, 'M'),
        'transfer_account' => $transferAccount,
    ];
}


// ── OFX Parser (regex-based, no XML extensions required) ─────────

function parseOFX(string $content, bool $isInvestment): array {
    $pos = stripos($content, '<OFX>');
    if ($pos === false) throw new Exception('Could not find <OFX> tag. Is this a valid OFX/QFX file?');
    $body = substr($content, $pos);

    return $isInvestment ? parseOFXInvestment($body) : parseOFXBanking($body);
}

/** Extract value of a leaf tag, e.g. <TRNAMT>-50.25 or <TRNAMT>-50.25</TRNAMT> */
function ofxTag(string $block, string $tag): string {
    if (preg_match('/<' . $tag . '>\s*([^<\r\n]+?)(?:\s*<\/' . $tag . '>)?[\r\n<]/i', $block . "\n", $m)) {
        return trim($m[1]);
    }
    return '';
}

/** Extract all content blocks between <TAG> and </TAG> */
function ofxBlocks(string $content, string $tag): array {
    preg_match_all('/<' . $tag . '>(.*?)<\/' . $tag . '>/is', $content, $m);
    return $m[1] ?? [];
}

function ofxDate(string $raw): string {
    // Strip timezone: 20240115120000[-5:EST] → 20240115
    $raw = preg_replace('/[\[\+\-]\d.*$/', '', trim($raw));
    $raw = substr($raw, 0, 8);
    if (strlen($raw) === 8 && ctype_digit($raw)) {
        return substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

function parseOFXBanking(string $body): array {
    $rows = [];
    foreach (ofxBlocks($body, 'STMTTRN') as $block) {
        $amount = (float)ofxTag($block, 'TRNAMT');
        $name   = ofxTag($block, 'NAME') ?: ofxTag($block, 'PAYEE');
        $date   = ofxDate(ofxTag($block, 'DTPOSTED') ?: ofxTag($block, 'DTUSER'));
        $rows[] = [
            'date'    => $date,
            'payee'   => $name,
            'amount'  => $amount,
            'memo'    => ofxTag($block, 'MEMO'),
            'num'     => ofxTag($block, 'CHECKNUM'),
            'cleared' => 'cleared',
            'fitid'   => ofxTag($block, 'FITID'),
        ];
    }
    return $rows;
}

/** Extract and normalise a single security-based OFX investment block into a row array. */
function parseOFXSecBlock(string $block, array $secMap, string $activity, string $actionType): ?array {
    $invBlock = (preg_match('/<INVTRAN>(.*?)<\/INVTRAN>/is', $block, $im)) ? $im[1] : $block;
    $secBlock = (preg_match('/<SECID>(.*?)<\/SECID>/is',    $block, $sm)) ? $sm[1] : '';

    $fitid  = ofxTag($invBlock, 'FITID');
    $date   = ofxDate(ofxTag($invBlock, 'DTTRADE') ?: ofxTag($invBlock, 'DTSETTLE') ?: ofxTag($invBlock, 'DTPOSTED'));
    $memo   = ofxTag($invBlock, 'MEMO') ?: ofxTag($block, 'MEMO');
    $secId  = ofxTag($secBlock, 'UNIQUEID');
    $name   = $secMap[$secId]['name']   ?? $secId;
    $symbol = $secMap[$secId]['symbol'] ?? '';

    if (!$date) return null;
    if (!$name && !$symbol) return null;

    return [
        'date'        => $date,
        'payee'       => $name ?: $symbol,
        'symbol'      => $symbol,
        'activity'    => $activity,
        'action_type' => $actionType,
        'quantity'    => abs((float)ofxTag($block, 'UNITS')),
        'price'       => abs((float)ofxTag($block, 'UNITPRICE')),
        'commission'  => abs((float)(ofxTag($block, 'COMMISSION') ?: ofxTag($block, 'FEES'))),
        'amount'      => (float)ofxTag($block, 'TOTAL'),
        'memo'        => $memo,
        'fitid'       => $fitid,
    ];
}

function parseOFXInvestment(string $body): array {
    // Build security map from SECLIST
    $secMap = [];
    foreach (ofxBlocks($body, 'SECINFO') as $block) {
        $uid    = ofxTag($block, 'UNIQUEID');
        $name   = ofxTag($block, 'SECNAME');
        $symbol = ofxTag($block, 'TICKER');
        if ($uid) $secMap[$uid] = ['name' => $name, 'symbol' => $symbol];
    }

    $rows = [];

    // ── Buy transactions (INVBUY appears inside BUYMF / BUYSTOCK / BUYDEBT / BUYOPT / BUYOTHER) ──
    foreach (ofxBlocks($body, 'INVBUY') as $block) {
        $row = parseOFXSecBlock($block, $secMap, 'buy', 'Buy');
        if ($row) $rows[] = $row;
    }

    // ── Sell transactions (INVSELL inside SELLMF / SELLSTOCK / SELLDEBT / SELLOPT / SELLOTHER) ──
    foreach (ofxBlocks($body, 'INVSELL') as $block) {
        $row = parseOFXSecBlock($block, $secMap, 'sell', 'Sell');
        if ($row) $rows[] = $row;
    }

    // ── Reinvestment — derive action_type from INCOMETYPE subfield ──
    foreach (ofxBlocks($body, 'REINVEST') as $block) {
        [$activity, $actionType] = match(strtoupper(ofxTag($block, 'INCOMETYPE'))) {
            'INTEREST' => ['reinvest_div', 'ReinvInt'],
            'CGLONG'   => ['reinvest_cap', 'ReinvLg'],
            'CGMID'    => ['reinvest_cap', 'ReinvMd'],
            'CGSHORT'  => ['reinvest_cap', 'ReinvSh'],
            default    => ['reinvest_div', 'ReinvDiv'],
        };
        $row = parseOFXSecBlock($block, $secMap, $activity, $actionType);
        if ($row) $rows[] = $row;
    }

    // ── Income (dividend, interest, cap gains) — derive from INCOMETYPE ──
    foreach (ofxBlocks($body, 'INCOME') as $block) {
        $actionType = match(strtoupper(ofxTag($block, 'INCOMETYPE'))) {
            'DIV'      => 'Div',
            'INTEREST' => 'IntInc',
            'CGLONG'   => 'CGLong',
            'CGMID'    => 'CGMid',
            'CGSHORT'  => 'CGShort',
            default    => 'MiscInc',
        };
        $row = parseOFXSecBlock($block, $secMap, 'add', $actionType);
        if ($row) { $row['quantity'] = 0; $row['price'] = 0; $rows[] = $row; }
    }

    // ── Transfer (shares in or out) — direction from TFERACTION ──
    foreach (ofxBlocks($body, 'TRANSFER') as $block) {
        [$activity, $actionType] = strtoupper(ofxTag($block, 'TFERACTION')) === 'OUT'
            ? ['remove', 'ShrsOut']
            : ['add',    'ShrsIn'];
        $row = parseOFXSecBlock($block, $secMap, $activity, $actionType);
        if ($row) $rows[] = $row;
    }

    // ── Stock split ──
    foreach (ofxBlocks($body, 'SPLIT') as $block) {
        $row = parseOFXSecBlock($block, $secMap, 'split', 'StkSplit');
        if ($row) {
            $oldUnits      = abs((float)ofxTag($block, 'OLDUNITS'));
            $newUnits      = abs((float)ofxTag($block, 'NEWUNITS'));
            $row['quantity'] = $newUnits > 0 ? ($newUnits - $oldUnits) : $row['quantity'];
            $row['price']    = 0;
            $rows[] = $row;
        }
    }

    // ── Return of capital ──
    foreach (ofxBlocks($body, 'RETOFCAP') as $block) {
        $row = parseOFXSecBlock($block, $secMap, 'add', 'RtrnCap');
        if ($row) { $row['quantity'] = 0; $row['price'] = 0; $rows[] = $row; }
    }

    // ── Investment expense ──
    foreach (ofxBlocks($body, 'INVEXPENSE') as $block) {
        $row = parseOFXSecBlock($block, $secMap, 'add', 'MiscExp');
        if ($row) { $row['quantity'] = 0; $row['price'] = 0; $rows[] = $row; }
    }

    // ── Margin interest (no security) ──
    foreach (ofxBlocks($body, 'MARGININTEREST') as $block) {
        $invBlock = (preg_match('/<INVTRAN>(.*?)<\/INVTRAN>/is', $block, $im)) ? $im[1] : $block;
        $date     = ofxDate(ofxTag($invBlock, 'DTTRADE') ?: ofxTag($invBlock, 'DTSETTLE') ?: ofxTag($invBlock, 'DTPOSTED'));
        $memo     = ofxTag($invBlock, 'MEMO') ?: ofxTag($block, 'MEMO');
        if (!$date) continue;
        $rows[] = [
            'date'        => $date,
            'payee'       => $memo ?: 'Margin Interest',
            'symbol'      => '',
            'activity'    => 'add',
            'action_type' => 'MargInt',
            'quantity'    => 0,
            'price'       => 0,
            'commission'  => 0,
            'amount'      => (float)ofxTag($block, 'TOTAL'),
            'memo'        => $memo,
            'fitid'       => ofxTag($invBlock, 'FITID'),
        ];
    }

    usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $rows;
}

/**
 * Parse an OFX/QFX file's INVPOSLIST into a holdings snapshot and reconcile
 * it against the current DB holdings for $accountId.
 * Returns ShrsIn / ShrsOut rows ready for the import pipeline.
 */
function parseOFXHoldings(string $content, int $accountId): array {
    $pos = stripos($content, '<OFX>');
    if ($pos === false) throw new Exception('Could not find <OFX> tag. Is this a valid OFX/QFX file?');
    $body = substr($content, $pos);

    // Security map from SECLIST
    $secMap = [];
    foreach (ofxBlocks($body, 'SECINFO') as $block) {
        $uid    = ofxTag($block, 'UNIQUEID');
        $name   = ofxTag($block, 'SECNAME');
        $symbol = ofxTag($block, 'TICKER');
        if ($uid) $secMap[$uid] = ['name' => $name, 'symbol' => $symbol];
    }

    // Parse all position types
    $snapshot  = [];
    $posTags   = ['POSSTOCK', 'POSMF', 'POSDEBT', 'POSOPT', 'POSOTHER'];
    foreach ($posTags as $tag) {
        foreach (ofxBlocks($body, $tag) as $block) {
            $secBlock = (preg_match('/<SECID>(.*?)<\/SECID>/is', $block, $sm)) ? $sm[1] : '';
            $uid      = ofxTag($secBlock, 'UNIQUEID');
            $name     = $secMap[$uid]['name']   ?? ($uid ?: '');
            $symbol   = $secMap[$uid]['symbol'] ?? '';
            $qty      = (float)ofxTag($block, 'UNITS');
            $price    = abs((float)ofxTag($block, 'UNITPRICE'));
            $dateRaw  = ofxTag($block, 'DTPRICEASOF');
            $date     = $dateRaw ? ofxDate($dateRaw) : date('Y-m-d');

            if ($qty <= 0 || (!$name && !$symbol)) continue;

            $symNorm = strtolower(preg_replace('/[^A-Z0-9]/i', '', $symbol));
            $key = $symNorm !== '' ? $symNorm : strtolower($name);
            $snapshot[$key] = [
                'qty'    => $qty,
                'name'   => $name ?: $symbol,
                'symbol' => $symbol,
                'price'  => $price,
                'date'   => $date,
            ];
        }
    }

    if (empty($snapshot)) {
        throw new Exception('No position records (POSSTOCK / POSMF / etc.) found. This file may not contain a holdings statement (INVPOSLIST).');
    }

    $current = getAccountHoldings($accountId);
    return reconcileHoldings($snapshot, $current, date('Y-m-d'));
}


// ── Multi-account QIF helpers ─────────────────────────────────────

function isMultiAccountQIF(string $content): bool {
    return preg_match_all('/^!Account\b/im', $content) >= 2;
}

/**
 * Parse a multi-account QIF file.
 * Returns:
 *   'accounts'        → [key => ['name', 'qif_type', 'is_investment']]
 *   'rows_by_account' → [key => [rows]]
 * Keys are strtolower(account name).
 */
function parseQIFMulti(string $content): array {
    $lines       = preg_split('/\r?\n|\r/', $content);
    $accounts    = [];
    $rowsByAcct  = [];
    $securityMap = [];

    $curAcctKey  = '';
    $curAcctBuf  = [];
    $inAcctBlock = false;
    $isSecurity  = false;
    $active      = false;
    $cur         = [];

    foreach ($lines as $raw) {
        $line = rtrim($raw);
        if ($line === '') continue;
        $code  = $line[0];
        $value = substr($line, 1);

        if ($code === '!') {
            $directive = strtolower(trim($value));

            if ($directive === 'account') {
                // Flush any buffered transaction row
                if ($cur && $curAcctKey !== '') {
                    $isInv = $accounts[$curAcctKey]['is_investment'] ?? false;
                    $row   = $isInv ? normalizeQIFInvestment($cur, $securityMap) : normalizeQIFBanking($cur);
                    if ($row) $rowsByAcct[$curAcctKey][] = $row;
                }
                $cur         = [];
                $inAcctBlock = true;
                $isSecurity  = false;
                $active      = false;
                $curAcctBuf  = [];
                continue;
            }

            if ($directive === 'type:security') {
                $inAcctBlock = false;
                $isSecurity  = true;
                $active      = false;
                continue;
            }

            if (str_starts_with($directive, 'type:')) {
                $inAcctBlock = false;
                $isSecurity  = false;
                $active      = true;

                if (!empty($curAcctBuf['N'])) {
                    $n   = trim($curAcctBuf['N']);
                    $k   = strtolower($n);
                    $t   = trim($curAcctBuf['T'] ?? '');
                    $inv = in_array(strtolower($t), ['invst', 'port', '401(k)', 'ira', 'roth']);
                    if (!isset($accounts[$k])) {
                        $accounts[$k] = ['name' => $n, 'qif_type' => $t, 'is_investment' => $inv];
                    }
                    $curAcctKey = $k;
                    $curAcctBuf = [];
                }
                continue;
            }

            if (str_starts_with($directive, 'clear:')) {
                $active = false;
            }
            continue;
        }

        if ($inAcctBlock) {
            if ($code === '^') {
                $inAcctBlock = false;
            } else {
                $curAcctBuf[$code] = $value;
            }
            continue;
        }

        if ($isSecurity) {
            if ($code === '^') {
                $secName   = trim($cur['N'][0] ?? '');
                $secSymbol = trim($cur['S'][0] ?? '');
                if ($secName !== '' && $secSymbol !== '') {
                    $securityMap[strtolower($secName)] = $secSymbol;
                }
                $cur = [];
            } else {
                $cur[$code][] = $value;
            }
            continue;
        }

        if (!$active) continue;

        if ($code === '^') {
            if ($cur && $curAcctKey !== '') {
                $isInv = $accounts[$curAcctKey]['is_investment'] ?? false;
                $row   = $isInv ? normalizeQIFInvestment($cur, $securityMap) : normalizeQIFBanking($cur);
                if ($row) $rowsByAcct[$curAcctKey][] = $row;
            }
            $cur = [];
            continue;
        }

        $cur[$code][] = $value;
    }

    if ($cur && $curAcctKey !== '') {
        $isInv = $accounts[$curAcctKey]['is_investment'] ?? false;
        $row   = $isInv ? normalizeQIFInvestment($cur, $securityMap) : normalizeQIFBanking($cur);
        if ($row) $rowsByAcct[$curAcctKey][] = $row;
    }

    // Remove accounts whose transactions all failed to parse
    foreach (array_keys($accounts) as $k) {
        if (empty($rowsByAcct[$k])) unset($accounts[$k]);
    }

    return ['accounts' => $accounts, 'rows_by_account' => $rowsByAcct];
}


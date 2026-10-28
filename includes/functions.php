<?php
require_once __DIR__ . '/../config/database.php';

// ── Account type helpers ────────────────────────────────────────

/** True for account types that behave like an investment register. */
function isInvestLike(string $type): bool {
    return $type === 'Investment' || $type === 'Crypto';
}

/** True for account types that hold a cash balance and can participate in transfers. */
function isCashAccount(string $type): bool {
    return in_array($type, ['Checking', 'Savings', 'Credit Card', 'investment-cash'], true);
}

/** Format an investment quantity: up to 8 decimal places for crypto, 6 otherwise. */
function formatQty(float $qty, bool $crypto = false): string {
    $decimals = $crypto ? 8 : 6;
    return rtrim(rtrim(number_format($qty, $decimals, '.', ','), '0'), '.');
}

/** Format a per-share price: crypto prices under $1 show up to 8 decimal places. */
function formatInvPrice(float $price, bool $crypto = false): string {
    if (!$crypto || abs($price) >= 1) return formatMoney($price);
    $fmt = rtrim(number_format(abs($price), 8, '.', ''), '0');
    if (strlen(substr(strrchr($fmt, '.'), 1)) < 2) $fmt = number_format(abs($price), 2, '.', '');
    return ($price < 0 ? '-' : '') . MONEY_SYMBOL . $fmt;
}

/** Format a price with smart decimal places: more for sub-$1 crypto. */
function formatCryptoPrice(float $price): string {
    if ($price == 0) return '$0.00';
    if ($price >= 1)     return '$' . number_format($price, 2);
    // Count leading zeros after decimal to show ~4 significant digits
    $log     = (int)floor(-log10($price));
    $digits  = max(4, $log + 4);
    return '$' . rtrim(rtrim(number_format($price, min($digits, 10)), '0'), '.');
}

// ── Permission helpers ──────────────────────────────────────────

function canImport(): bool {
    if (isAdmin()) return true;
    return getSetting('users_can_import') === '1';
}

// ── Formatting ─────────────────────────────────────────────────

function formatMoney(float $amount, bool $showSign = false): string {
    static $negFmt = null;
    if ($negFmt === null) $negFmt = getSetting('negative_format', 'color');

    $rounded = round($amount, MONEY_DECIMALS);
    $neg = $rounded < 0;
    $abs = abs($rounded);
    $fmt = MONEY_SYMBOL . number_format($abs, MONEY_DECIMALS, '.', ',');
    if ($showSign && $rounded > 0) $fmt = '+' . $fmt;
    if ($neg) {
        if ($negFmt === 'minus')                              $fmt = '-' . $fmt;
        elseif ($negFmt === 'parens' || $negFmt === 'parens-bw') $fmt = '(' . $fmt . ')';
    }
    return $fmt;
}

function formatDate(string $date): string {
    return date('m/d/Y', strtotime($date));
}

function isoDate(?string $display): string {
    if (!$display) return date('Y-m-d');
    $ts = strtotime($display);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

function h(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Neutralizes CSV formula injection: prefixes non-numeric values that start with
// =, +, -, @, tab, or CR with an apostrophe so spreadsheet apps treat them as text.
// Numeric values (including negatives) are left untouched so re-import stays intact.
function csvFormulaSafe(mixed $s): string {
    $s = (string)($s ?? '');
    if ($s !== '' && !is_numeric($s) && preg_match('/^[=+\-@\t\r]/', $s)) {
        return "'" . $s;
    }
    return $s;
}

// ── Accounts ───────────────────────────────────────────────────

function getAccounts(bool $activeOnly = true, bool $includeClosed = false): array {
    $db    = getDB();
    $where = [];
    if ($activeOnly)    $where[] = 'is_active = 1';
    if (!$includeClosed) $where[] = 'is_closed = 0';
    $sql = 'SELECT * FROM accounts' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name';
    return $db->query($sql)->fetchAll();
}

function accountDisplayName(array $account): string {
    return (!empty($account['is_closed']) ? '[CLOSED] ' : '') . $account['name'];
}

function getAccount(int $id): array|false {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getFavoriteAccounts(): array {
    $db = getDB();
    $accounts = $db->query(
        'SELECT a.*, COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id), 0) + a.opening_balance AS current_balance
         FROM accounts a WHERE a.is_favorite = 1 AND a.is_active = 1 AND a.is_closed = 0 ORDER BY a.name'
    )->fetchAll();
    $mktValues = getInvestmentAccountMarketValues();
    foreach ($accounts as &$acc) {
        if (isInvestLike($acc['type']) && !$acc['is_investment_cash'] && isset($mktValues[(int)$acc['id']])) {
            $acc['current_balance'] = $mktValues[(int)$acc['id']];
        }
    }
    unset($acc);
    return $accounts;
}

function getLinkedAccount(array $account): array|false {
    if (!$account['linked_account_id']) return false;
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM accounts WHERE id = ? AND is_active = 1');
    $stmt->execute([$account['linked_account_id']]);
    return $stmt->fetch();
}

function getLoanDetails(int $accountId): array|false {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM loan_details WHERE account_id = ?');
    $stmt->execute([$accountId]);
    return $stmt->fetch();
}

function getAccountBalance(int $accountId): float {
    $db   = getDB();
    $stmt = $db->prepare('SELECT type, is_investment_cash FROM accounts WHERE id = ? AND is_active = 1');
    $stmt->execute([$accountId]);
    $acc = $stmt->fetch();
    if ($acc && isInvestLike($acc['type']) && !$acc['is_investment_cash']) {
        $mktValues = getInvestmentAccountMarketValues();
        return $mktValues[$accountId] ?? 0.0;
    }
    $stmt = $db->prepare(
        'SELECT a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id), 0) AS balance
         FROM accounts a WHERE a.id = ?'
    );
    $stmt->execute([$accountId]);
    return (float)($stmt->fetchColumn() ?? 0);
}

function getAllAccountsWithBalance(?string $asOf = null, bool $includeClosed = false): array {
    $db          = getDB();
    $closedClause = $includeClosed ? '' : ' AND a.is_closed = 0';
    if ($asOf !== null) {
        $stmt = $db->prepare(
            'SELECT a.*,
                    a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id AND t.transaction_date <= :asOf), 0) AS current_balance
             FROM accounts a WHERE a.is_active = 1' . $closedClause . ' ORDER BY a.type, a.sort_order, a.name'
        );
        $stmt->execute([':asOf' => $asOf]);
        $accounts = $stmt->fetchAll();
    } else {
        $accounts = $db->query(
            'SELECT a.*,
                    a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id), 0) AS current_balance
             FROM accounts a WHERE a.is_active = 1' . $closedClause . ' ORDER BY a.type, a.sort_order, a.name'
        )->fetchAll();
    }
    $mktValues = getInvestmentAccountMarketValues();
    foreach ($accounts as &$acc) {
        if (isInvestLike($acc['type']) && !$acc['is_investment_cash'] && isset($mktValues[(int)$acc['id']])) {
            $acc['current_balance'] = $mktValues[(int)$acc['id']];
        }
    }
    unset($acc);
    return $accounts;
}

// ── Categories ─────────────────────────────────────────────────

function getParentCategories(string $type = ''): array {
    $db  = getDB();
    $sql = 'SELECT * FROM categories WHERE (parent_id IS NULL OR parent_id = id) AND is_active = 1';
    if ($type) $sql .= ' AND type = ' . $db->quote($type);
    $sql .= ' ORDER BY name';
    return $db->query($sql)->fetchAll();
}

function getSubcategories(int $parentId): array {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM categories WHERE parent_id = ? AND id != ? AND is_active = 1 ORDER BY name');
    $stmt->execute([$parentId, $parentId]);
    return $stmt->fetchAll();
}

function getAllCategoriesHierarchy(): array {
    $db      = getDB();
    $parents = $db->query(
        'SELECT * FROM categories WHERE (parent_id IS NULL OR parent_id = id) AND is_active = 1 ORDER BY name'
    )->fetchAll();
    foreach ($parents as &$parent) {
        $stmt = $db->prepare('SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY name');
        $stmt->execute([$parent['id']]);
        $parent['children'] = $stmt->fetchAll();
    }
    return $parents;
}

function getSystemCategoryId(string $name): ?int {
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    $stmt = getDB()->prepare('SELECT id FROM categories WHERE name = ? AND is_system = 1 LIMIT 1');
    $stmt->execute([$name]);
    $row  = $stmt->fetch();
    return $cache[$name] = $row ? (int)$row['id'] : null;
}

function investmentActivityLabel(string $activity): string {
    return match ($activity) {
        'buy'          => 'Buy',
        'sell'         => 'Sell',
        'add'          => 'Add',
        'remove'       => 'Remove',
        'split'        => 'Split',
        'reinvest_div' => 'Reinvest Dividend',
        'reinvest_cap' => 'Reinvest Cap Gain',
        'div'          => 'Dividend',
        'int'          => 'Interest',
        default        => ucfirst($activity),
    };
}

function getCategoryById(int $id): array|false {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ── Transactions ───────────────────────────────────────────────

// If $transferPairId points to an investment-side transaction (buy/sell/div/int/reinvest
// entered in the security register), returns that transaction's id + account so callers can
// block direct edits/deletes of the reciprocal cash leg and link back to the real editor.
function getInvestmentPairInfo(?int $transferPairId): ?array {
    if (!$transferPairId) return null;
    $stmt = getDB()->prepare('SELECT id, account_id, type FROM transactions WHERE id = ?');
    $stmt->execute([$transferPairId]);
    $row = $stmt->fetch();
    if (!$row || $row['type'] !== 'investment') return null;
    return ['investment_txn_id' => (int)$row['id'], 'investment_account_id' => (int)$row['account_id']];
}

function getTransactionSplits(int $transactionId): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT ts.*, c.name AS category_name, sc.name AS subcategory_name
         FROM transaction_splits ts
         LEFT JOIN categories c  ON c.id  = ts.category_id
         LEFT JOIN categories sc ON sc.id = ts.subcategory_id
         WHERE ts.transaction_id = ?
         ORDER BY ts.id'
    );
    $stmt->execute([$transactionId]);
    return $stmt->fetchAll();
}

function getRegisterTransactions(int $accountId, string $sortCol = 'date', string $sortDir = 'asc'): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT t.*,
                c.name  AS category_name,
                sc.name AS subcategory_name,
                pa.name AS paired_account_name,
                pit.activity AS paired_activity
         FROM transactions t
         LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id AND t.is_split = 0
                                        AND ts.id = (SELECT MIN(s2.id) FROM transaction_splits s2 WHERE s2.transaction_id = t.id)
         LEFT JOIN categories c  ON c.id  = ts.category_id
         LEFT JOIN categories sc ON sc.id = ts.subcategory_id
         LEFT JOIN transactions pt ON pt.id = t.transfer_pair_id
         LEFT JOIN accounts pa     ON pa.id = pt.account_id
         LEFT JOIN investment_transactions pit ON pit.transaction_id = pt.id AND pt.type = \'investment\'
         WHERE t.account_id = ?
         ORDER BY t.transaction_date ASC, t.id ASC'
    );
    $stmt->execute([$accountId]);
    $rows = $stmt->fetchAll();

    // Running balance always computed in date order
    $stmt2 = $db->prepare('SELECT opening_balance FROM accounts WHERE id = ?');
    $stmt2->execute([$accountId]);
    $balance = (float)$stmt2->fetchColumn();
    foreach ($rows as &$row) {
        $balance       += (float)$row['amount'];
        $row['balance'] = $balance;
    }
    unset($row);

    // Re-sort for display if not the default date-asc order
    if ($sortCol !== 'date' || $sortDir !== 'asc') {
        usort($rows, function ($a, $b) use ($sortCol, $sortDir) {
            $aVal = match ($sortCol) {
                'num'      => strtolower($a['num']    ?? ''),
                'payee'    => strtolower($a['payee']  ?? ''),
                'category' => strtolower(($a['category_name'] ?? '') . '|' . ($a['subcategory_name'] ?? '')),
                default    => $a['transaction_date'] . sprintf('%010d', $a['id']),
            };
            $bVal = match ($sortCol) {
                'num'      => strtolower($b['num']    ?? ''),
                'payee'    => strtolower($b['payee']  ?? ''),
                'category' => strtolower(($b['category_name'] ?? '') . '|' . ($b['subcategory_name'] ?? '')),
                default    => $b['transaction_date'] . sprintf('%010d', $b['id']),
            };
            $cmp = strcmp($aVal, $bVal);
            return $sortDir === 'desc' ? -$cmp : $cmp;
        });
    }

    return $rows;
}

// Numbers skipped in the account's check-number sequence — e.g. checks 1001,
// 1002, 1005 on file returns [1003, 1004]. Only purely-numeric num values on
// withdrawals count as check numbers (same convention as the duplicate-check-
// number maintenance check), so text reference tags like EFT/DEP/ATM are
// ignored. Capped at a 5,000-number span to avoid a runaway loop if a typo'd
// check number (e.g. a stray 6-digit value) blows out the range.
function findCheckNumberGaps(int $accountId): array {
    $stmt = getDB()->prepare(
        "SELECT DISTINCT CAST(num AS UNSIGNED) AS n
         FROM transactions
         WHERE account_id = ? AND type = 'withdrawal' AND num REGEXP '^[0-9]+$'
         ORDER BY n"
    );
    $stmt->execute([$accountId]);
    $nums = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($nums) < 2) return [];

    $min = $nums[0];
    $max = end($nums);
    if ($max - $min > 5000) return [];

    $present = array_flip($nums);
    $missing = [];
    for ($n = $min; $n <= $max; $n++) {
        if (!isset($present[$n])) $missing[] = $n;
    }
    return $missing;
}

// Compacts a sorted list of integers into range notation, e.g.
// [1003, 1004, 1006] -> "1003-1004, 1006".
function formatNumberRanges(array $numbers): string {
    if (empty($numbers)) return '';
    sort($numbers);
    $ranges = [];
    $start = $prev = $numbers[0];
    foreach (array_slice($numbers, 1) as $n) {
        if ($n === $prev + 1) { $prev = $n; continue; }
        $ranges[] = $start === $prev ? (string)$start : "$start-$prev";
        $start = $prev = $n;
    }
    $ranges[] = $start === $prev ? (string)$start : "$start-$prev";
    return implode(', ', $ranges);
}

function getInvestmentRegisterTransactions(int $accountId, string $sortCol = 'date', string $sortDir = 'asc'): array {
    $db = getDB();
    $orderBy = match($sortCol) {
        'payee' => 't.payee ' . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ', t.transaction_date ASC, t.id ASC',
        'total' => 'ABS(t.amount) ' . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ', t.transaction_date ASC, t.id ASC',
        default => 't.transaction_date ' . ($sortDir === 'desc' ? 'DESC' : 'ASC') . ', t.id ASC',
    };
    $stmt = $db->prepare(
        'SELECT t.*,
                it.activity,
                it.quantity,
                it.price      AS inv_price,
                it.commission,
                it.investment_id,
                pa.id         AS cash_account_id,
                pa.name       AS cash_account_name
         FROM transactions t
         LEFT JOIN investment_transactions it ON it.transaction_id = t.id
         LEFT JOIN transactions pt ON pt.id = t.transfer_pair_id
         LEFT JOIN accounts pa     ON pa.id = pt.account_id
         WHERE t.account_id = ?
         ORDER BY ' . $orderBy
    );
    $stmt->execute([$accountId]);
    $rows = $stmt->fetchAll();

    $stmt2 = $db->prepare('SELECT opening_balance FROM accounts WHERE id = ?');
    $stmt2->execute([$accountId]);
    $balance = (float)$stmt2->fetchColumn();
    foreach ($rows as &$row) {
        $balance       += (float)$row['amount'];
        $row['balance'] = $balance;
    }
    return $rows;
}

// ── Monthly spending tracker ────────────────────────────────────

function getMonthlySpending(int $year, int $month, array $accountIds = []): array {
    $db = getDB();
    if (!empty($accountIds)) {
        $ph   = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $db->prepare(
            "SELECT c.name AS category, COALESCE(p.name, c.name) AS parent_name,
                    -SUM(ts.amount) AS total
             FROM transaction_splits ts
             JOIN transactions t  ON t.id  = ts.transaction_id
             JOIN categories c    ON c.id  = ts.category_id
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE t.type IN ('withdrawal','transfer','deposit')
               AND c.type = 'expense'
               AND YEAR(t.transaction_date)  = ?
               AND MONTH(t.transaction_date) = ?
               AND t.account_id IN ($ph)
             GROUP BY ts.category_id
             HAVING total >= 0.005
             ORDER BY total DESC"
        );
        $stmt->execute([$year, $month, ...$accountIds]);
    } else {
        $stmt = $db->prepare(
            "SELECT c.name AS category, COALESCE(p.name, c.name) AS parent_name,
                    -SUM(ts.amount) AS total
             FROM transaction_splits ts
             JOIN transactions t  ON t.id  = ts.transaction_id
             JOIN categories c    ON c.id  = ts.category_id
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE t.type IN ('withdrawal','transfer','deposit')
               AND c.type = 'expense'
               AND YEAR(t.transaction_date)  = ?
               AND MONTH(t.transaction_date) = ?
             GROUP BY ts.category_id
             HAVING total >= 0.005
             ORDER BY total DESC"
        );
        $stmt->execute([$year, $month]);
    }
    return $stmt->fetchAll();
}

// ── Budget ─────────────────────────────────────────────────────

// The dashboard budget for this month (math: budget/_lib.php), or null:
//   budget_id, items — lines marked for the widget, with this month's available
//   amount (target plus any rollover) and signed actual — and alerts, every
//   line of the budget with an alert, most severe first.
function getBudgetDashboard(): ?array {
    require_once __DIR__ . '/../budget/_lib.php';
    $budget = getDB()->query(
        "SELECT id FROM budgets WHERE show_on_dashboard = 1 AND is_active = 1 ORDER BY id LIMIT 1"
    )->fetch();
    if (!$budget) return null;

    $model = loadBudgetModel((int)$budget['id'], (int)date('Y'), (int)date('n'), ['with_untracked' => false]);
    $items = [];
    foreach ($model['lines'] as $l) {
        if (!$l['show_on_dashboard']) continue;
        $budgeted = $l['mon_available'];
        $actual   = $l['mon_actual'];
        $rawPct   = $budgeted > 0 ? ($actual / $budgeted) * 100 : null;
        $items[] = [
            'id'            => $l['bc_id'],
            'category_id'   => $l['category_id'],
            'name'          => $l['name'],
            'category_type' => $l['cat_type'],
            'entry_type'    => $l['entry_type'],
            'budgeted'      => $budgeted,
            'carry_in'      => $l['carry_in'],
            'actual'        => $actual,
            'remaining'     => $budgeted > 0 ? $budgeted - $actual : null,
            'raw_pct'       => $rawPct,
            'pct'           => $rawPct !== null ? max(0, min($rawPct, 100)) : null,
            'alert'         => $l['alert'],
        ];
    }
    // Income first, then expenses, each by name (as before)
    usort($items, fn($a, $b) => (($a['category_type'] !== 'income') <=> ($b['category_type'] !== 'income'))
                                 ?: strcasecmp($a['name'], $b['name']));
    return ['budget_id' => (int)$budget['id'], 'items' => $items, 'alerts' => $model['alerts']];
}

// ── Portfolio / Investments ────────────────────────────────────

/**
 * Returns market value (price × net shares) per Investment account.
 * Only includes securities that have a latest price on record.
 * Falls back to cost-basis price for securities without a market price.
 */
function getInvestmentAccountMarketValues(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $db = getDB();

    // Net quantity held per account + investment
    $rows = $db->query(
        'SELECT t.account_id, it.investment_id,
                SUM(CASE
                    WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                    WHEN it.activity IN (\'sell\',\'remove\')                                        THEN -it.quantity
                    ELSE 0
                END) AS net_qty
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN accounts a     ON a.id = t.account_id
         WHERE a.type IN (\'Investment\',\'Crypto\') AND a.is_investment_cash = 0
         GROUP BY t.account_id, it.investment_id'
    )->fetchAll();

    if (empty($rows)) return $cache = [];

    $latestPrices = getLatestInvestmentPrices();
    $txnPrices    = getTransactionPriceHistory();
    $positions    = getInvestmentLots()['positions'];

    $values = [];
    foreach ($rows as $row) {
        $accountId    = (int)$row['account_id'];
        $investmentId = (int)$row['investment_id'];
        $qty          = (float)$row['net_qty'];
        // Seed the account in the map even if qty is zero, so callers don't fall
        // back to the raw transaction-sum for accounts with no current holdings.
        if (!isset($values[$accountId])) $values[$accountId] = 0.0;
        if ($qty > 0.000001) {
            // Latest market price; for a fund with no market price yet, its last
            // transaction price; the FIFO cost of the shares held only if it has neither.
            $lastTxn = !empty($txnPrices[$investmentId]) ? end($txnPrices[$investmentId])[1] : null;
            $price   = $latestPrices[$investmentId]['price'] ?? $lastTxn;
            $values[$accountId] += $price !== null
                ? $price * $qty
                : ($positions[$investmentId . ':' . $accountId]['cost'] ?? 0.0);
        }
    }
    return $cache = $values;
}

function getAllInvestments(): array {
    $db = getDB();
    return $db->query(
        'SELECT i.*,
                (
                    SELECT COALESCE(SUM(CASE
                        WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                        WHEN it.activity IN (\'sell\',\'remove\')                                    THEN -it.quantity
                        ELSE 0
                    END), 0)
                    FROM investment_transactions it
                    JOIN transactions t ON t.id = it.transaction_id
                    JOIN accounts a     ON a.id = t.account_id
                    WHERE it.investment_id = i.id
                      AND a.is_investment_cash = 0
                ) > 0.000001 AS is_owned
         FROM investments i
         WHERE i.is_active = 1
         ORDER BY i.type, i.name'
    )->fetchAll();
}

// Securities worth quoting in a bulk fetch: active, quotable, ticker present, and
// either an Index, on the Watchlist, or currently held. Sold-off and un-watchlisted
// securities drop off automatically — no manual cleanup step to remember. Reports never
// need prices outside this set: net worth / account balances / portfolio value history
// all load prices only for investments that appear in investment_transactions.
function getQuotableInvestments(): array {
    $db = getDB();
    return $db->query(
        "SELECT i.id, i.name, i.symbol, i.type
         FROM investments i
         WHERE i.is_active = 1 AND i.disable_quotes = 0 AND i.symbol != ''
           AND (
             i.type = 'Index'
             OR i.in_watchlist = 1
             OR (
               SELECT COALESCE(SUM(CASE
                   WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
                   WHEN it.activity IN ('sell','remove')                                    THEN -it.quantity
                   ELSE 0
               END), 0)
               FROM investment_transactions it
               JOIN transactions t ON t.id = it.transaction_id
               JOIN accounts a     ON a.id = t.account_id
               WHERE it.investment_id = i.id
                 AND a.is_investment_cash = 0
             ) > 0.000001
           )
         ORDER BY i.type, i.name"
    )->fetchAll();
}

function getInvestmentHoldings(): array {
    $db   = getDB();
    $rows = $db->query(
        'SELECT it.investment_id,
                a.id   AS account_id,
                a.name AS account_name,
                SUM(CASE
                    WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                    WHEN it.activity IN (\'sell\',\'remove\')                                    THEN -it.quantity
                    ELSE 0
                END) AS net_quantity
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN accounts a     ON a.id = t.account_id
         WHERE a.is_investment_cash = 0
         GROUP BY it.investment_id, a.id
         HAVING net_quantity > 0.000001
         ORDER BY a.name'
    )->fetchAll();

    $holdings = [];
    foreach ($rows as $row) {
        $holdings[(int)$row['investment_id']][] = [
            'account_id'   => (int)$row['account_id'],
            'account_name' => $row['account_name'],
            'quantity'     => (float)$row['net_quantity'],
        ];
    }
    return $holdings;
}

function portfolioTypeIcon(string $type): string {
    return match ($type) {
        'Stock'              => 'bi-bar-chart-line',
        'Bond'               => 'bi-shield-check',
        'CD or Savings Bond' => 'bi-piggy-bank',
        'Money Market'       => 'bi-cash-stack',
        'Mutual Fund'        => 'bi-pie-chart',
        'ETF'                => 'bi-grid-3x3-gap',
        'Index'              => 'bi-graph-up-arrow',
        'Cryptocurrency'     => 'bi-currency-bitcoin',
        default              => 'bi-briefcase',
    };
}

// ── Settings ───────────────────────────────────────────────────

function getSetting(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (getDB()->query('SELECT key_name, value FROM settings') as $row) {
                $cache[$row['key_name']] = $row['value'];
            }
        } catch (Exception $e) {
            // Table may not exist yet on first install
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setSetting(string $key, ?string $value): void {
    getDB()->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
    )->execute([$key, $value]);
}

// ── Server environment ─────────────────────────────────────────

// Largest upload PHP will accept: the smaller of upload_max_filesize and post_max_size.
function uploadMaxBytes(): int {
    $toBytes = function (string $v): int {
        $v = trim($v);
        if ($v === '') return 0;
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    };
    $limits = array_filter([
        $toBytes((string)ini_get('upload_max_filesize')),
        $toBytes((string)ini_get('post_max_size')),
    ], fn($b) => $b > 0);
    return $limits ? min($limits) : 0;
}

// CLI php binary for cron lines. Under PHP-FPM, PHP_BINARY is the php-fpm daemon.
function cliPhpBinary(): string {
    $bin = PHP_BINDIR . '/php';
    return is_executable($bin) ? $bin : '/usr/bin/php';
}

// ── Activity log ────────────────────────────────────────────────

function logActivity(string $event, string $description, ?int $userId = null, ?string $userName = null): void {
    try {
        $uid   = $userId   ?? (currentUserId() ?: null);
        $uname = $userName ?? ($_SESSION['user_fullname'] ?? $_SESSION['username'] ?? '');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? null;

        // Lazy purge — runs ~1% of the time to keep the table tidy
        $days = (int)getSetting('log_retention_days', '90');
        if ($days > 0 && rand(1, 100) === 1) {
            getDB()->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')
                   ->execute([$days]);
        }

        getDB()->prepare(
            'INSERT INTO activity_log (user_id, user_name, event, description, ip_address) VALUES (?, ?, ?, ?, ?)'
        )->execute([$uid, (string)$uname, $event, $description, $ip]);
    } catch (Exception $e) {
        // Logging must never break the app
    }
}

// ── Investment prices ───────────────────────────────────────────

// Memoized for the duration of one request — several unrelated dashboard/report
// code paths call this independently. Pass $forceRefresh after writing new price
// rows in the same request (see accounts/apply_reconciliation.php) so a stale
// cached result isn't served back.
function getLatestInvestmentPrices(bool $forceRefresh = false): array {
    static $cache = null;
    if ($cache !== null && !$forceRefresh) return $cache;
    try {
        // Max-date-per-investment + correlated prev-close lookup instead of two window
        // functions over the whole table — both use the (investment_id, price_date) index.
        // session_date: statement imports date Friday's close on Saturday (or Sunday), so
        // for day change such a row is Friday's session and the previous close is the last
        // close before that Friday, not Friday itself (which showed 0.00%). Crypto trades
        // every day, so its weekend rows are real sessions.
        $rows = getDB()->query(
            'SELECT latest.investment_id, latest.close_price, latest.price_date, latest.source,
                    COALESCE(latest.volume, 0) AS volume,
                    (
                        SELECT ip2.close_price
                        FROM investment_prices ip2
                        WHERE ip2.investment_id = latest.investment_id
                          AND ip2.price_date < latest.session_date
                        ORDER BY ip2.price_date DESC
                        LIMIT 1
                    ) AS prev_close
             FROM (
                 SELECT ip.investment_id, ip.close_price, ip.price_date, ip.source, ip.volume,
                        CASE
                            WHEN i.type = \'Cryptocurrency\' THEN ip.price_date
                            WHEN DAYOFWEEK(ip.price_date) = 7 THEN ip.price_date - INTERVAL 1 DAY
                            WHEN DAYOFWEEK(ip.price_date) = 1 THEN ip.price_date - INTERVAL 2 DAY
                            ELSE ip.price_date
                        END AS session_date
                 FROM investment_prices ip
                 JOIN investments i ON i.id = ip.investment_id
                 JOIN (
                     SELECT investment_id, MAX(price_date) AS max_date
                     FROM investment_prices
                     GROUP BY investment_id
                 ) m ON m.investment_id = ip.investment_id AND m.max_date = ip.price_date
             ) latest'
        )->fetchAll();
    } catch (Exception $e) {
        return $cache = [];
    }
    $prices = [];
    foreach ($rows as $row) {
        $prices[(int)$row['investment_id']] = [
            'price'      => (float)$row['close_price'],
            'price_date' => $row['price_date'],
            'source'     => $row['source'],
            'volume'     => (float)$row['volume'],
            'prev_close' => $row['prev_close'] !== null ? (float)$row['prev_close'] : null,
        ];
    }
    return $cache = $prices;
}

/**
 * Quote details from stored price history for the watchlist's optional
 * columns, per investment id:
 *   prev_close, prev_volume — the session before the latest one (a weekend
 *     row from a statement import counts as Friday's session, except crypto)
 *   open, low, high, volume — the latest row (null when not stored)
 *   avg_volume — average stored volume over the 3 months up to the latest row
 *   low_52w, high_52w — over the year up to the latest row (lows/highs, else closes)
 *   ret_1m, ret_ytd, ret_1y — % change from the last close on or before one
 *     month ago, the prior year end, and one year ago (null without history)
 * Everything is measured from each investment's latest stored price.
 */
function getPriceHistoryStats(array $invIds): array {
    $invIds = array_values(array_unique(array_map('intval', $invIds)));
    if (!$invIds) return [];
    $phs  = implode(',', array_fill(0, count($invIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT ip.investment_id, ip.price_date, ip.close_price, ip.open_price, ip.high_price, ip.low_price, ip.volume,
                i.type
         FROM investment_prices ip
         JOIN investments i ON i.id = ip.investment_id
         WHERE ip.investment_id IN ($phs)
           AND ip.price_date >= (SELECT MAX(p2.price_date) FROM investment_prices p2 WHERE p2.investment_id = ip.investment_id) - INTERVAL 400 DAY
         ORDER BY ip.investment_id, ip.price_date"
    );
    $stmt->execute($invIds);
    $byInv = [];
    foreach ($stmt->fetchAll() as $r) $byInv[(int)$r['investment_id']][] = $r;

    $num = fn($v) => $v !== null ? (float)$v : null;
    $out = [];
    foreach ($byInv as $id => $rows) {
        $last   = end($rows);
        $lastTs = strtotime($last['price_date']);
        $dow    = (int)date('w', $lastTs);
        $session = $last['type'] !== 'Cryptocurrency' && ($dow === 6 || $dow === 0)
            ? date('Y-m-d', strtotime($dow === 6 ? '-1 day' : '-2 days', $lastTs))
            : $last['price_date'];
        $closeOnOrBefore = function (string $date) use ($rows) {
            $found = null;
            foreach ($rows as $r) { if ($r['price_date'] > $date) break; $found = $r; }
            return $found;
        };
        $prev = null;
        foreach ($rows as $r) { if ($r['price_date'] >= $session) break; $prev = $r; }

        $from3m = date('Y-m-d', strtotime('-3 months', $lastTs));
        $from1y = date('Y-m-d', strtotime('-1 year', $lastTs));
        $vols = []; $lo = null; $hi = null;
        foreach ($rows as $r) {
            if ($r['price_date'] > $from3m && $r['volume'] !== null) $vols[] = (float)$r['volume'];
            if ($r['price_date'] > $from1y) {
                $l = (float)($r['low_price'] ?? $r['close_price']);
                $h = (float)($r['high_price'] ?? $r['close_price']);
                $lo = $lo === null ? $l : min($lo, $l);
                $hi = $hi === null ? $h : max($hi, $h);
            }
        }
        $close = (float)$last['close_price'];
        $ret = function ($base) use ($close) {
            return $base && (float)$base['close_price'] > 0 ? ($close / (float)$base['close_price'] - 1) * 100 : null;
        };
        $out[$id] = [
            'prev_close'  => $prev ? (float)$prev['close_price'] : null,
            'prev_volume' => $prev ? $num($prev['volume']) : null,
            'open'        => $num($last['open_price']),
            'low'         => $num($last['low_price']),
            'high'        => $num($last['high_price']),
            'volume'      => $num($last['volume']),
            'avg_volume'  => $vols ? array_sum($vols) / count($vols) : null,
            'low_52w'     => $lo,
            'high_52w'    => $hi,
            'ret_1m'      => $ret($closeOnOrBefore(date('Y-m-d', strtotime('-1 month', $lastTs)))),
            'ret_ytd'     => $ret($closeOnOrBefore((date('Y', $lastTs) - 1) . '-12-31')),
            'ret_1y'      => $ret($closeOnOrBefore($from1y)),
        ];
    }
    return $out;
}

// Per-share prices from the investment's own transactions: buys, sells, priced adds
// and reinvestments (a quantity-weighted average when several fall on one day).
// Returns [investment_id => [[date, price], ...]] in date order. Used as the price
// when there's no market price — 401(k) trusts and 529 options often have no feed,
// or one that starts years after purchase. Memoized for the request.
function getTransactionPriceHistory(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = getDB()->query(
            "SELECT it.investment_id, t.transaction_date,
                    SUM(it.price * it.quantity) / SUM(it.quantity) AS price
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             WHERE it.activity IN ('buy', 'sell', 'add', 'reinvest_div', 'reinvest_cap')
               AND it.price > 0 AND it.quantity > 0 AND it.investment_id IS NOT NULL
             GROUP BY it.investment_id, t.transaction_date
             ORDER BY it.investment_id, t.transaction_date"
        )->fetchAll();
    } catch (Exception $e) {
        return $cache = [];
    }
    $hist = [];
    foreach ($rows as $r) {
        $hist[(int)$r['investment_id']][] = [$r['transaction_date'], (float)$r['price']];
    }
    return $cache = $hist;
}

// Price of each id on each date in $dates: the latest market close on or before
// that date, or else the latest transaction price on or before it (see
// getTransactionPriceHistory()). Ids with neither on a date are left out for that
// date. Returns [investment_id => [date => ['price'=>, 'price_date'=>, 'source'=>
// 'market'|'transaction']]]. Memoized per request by (ids, dates).
function getInvestmentPricesAtDates(array $ids, array $dates): array {
    static $cache = [];
    $ids   = array_values(array_unique(array_map('intval', $ids)));
    $dates = array_values(array_unique($dates));
    if (empty($ids) || empty($dates)) return [];
    sort($ids);
    sort($dates);
    $key = implode(',', $ids) . '|' . implode(',', $dates);
    if (isset($cache[$key])) return $cache[$key];

    $market = [];
    try {
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDB()->prepare(
            "SELECT investment_id, price_date, close_price
             FROM investment_prices
             WHERE investment_id IN ($ph) AND price_date <= ?
             ORDER BY investment_id, price_date"
        );
        $stmt->execute([...$ids, end($dates)]);
        foreach ($stmt->fetchAll() as $p) {
            $market[(int)$p['investment_id']][] = [$p['price_date'], (float)$p['close_price']];
        }
    } catch (Exception $e) {}
    $txnHist = getTransactionPriceHistory();

    // Walk each sorted list once alongside the sorted dates
    $latestOnOrBefore = function (array $list) use ($dates): array {
        $out = []; $cur = null; $i = 0; $n = count($list);
        foreach ($dates as $d) {
            while ($i < $n && $list[$i][0] <= $d) { $cur = $list[$i]; $i++; }
            if ($cur !== null) $out[$d] = $cur;
        }
        return $out;
    };

    $result = [];
    foreach ($ids as $iid) {
        $m = isset($market[$iid])  ? $latestOnOrBefore($market[$iid])  : [];
        $t = isset($txnHist[$iid]) ? $latestOnOrBefore($txnHist[$iid]) : [];
        foreach ($dates as $d) {
            if (isset($m[$d])) {
                $result[$iid][$d] = ['price' => $m[$d][1], 'price_date' => $m[$d][0], 'source' => 'market'];
            } elseif (isset($t[$d])) {
                $result[$iid][$d] = ['price' => $t[$d][1], 'price_date' => $t[$d][0], 'source' => 'transaction'];
            }
        }
    }
    return $cache[$key] = $result;
}

// Latest close price per investment on or before $asOf, falling back to the latest
// transaction price on or before $asOf for investments with no market price yet
// (entries carry 'source' => 'market'|'transaction'). Unlike getLatestInvestmentPrices()
// this is not memoized — callers pass a specific historical date, not "now".
function getInvestmentPricesAsOf(string $asOf): array {
    try {
        $stmt = getDB()->prepare(
            'SELECT investment_id, close_price, price_date
             FROM (
                 SELECT investment_id, close_price, price_date,
                        ROW_NUMBER() OVER (PARTITION BY investment_id ORDER BY price_date DESC) AS rn
                 FROM investment_prices
                 WHERE price_date <= ?
             ) ranked
             WHERE rn = 1'
        );
        $stmt->execute([$asOf]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
    $prices = [];
    foreach ($rows as $row) {
        $prices[(int)$row['investment_id']] = [
            'price'      => (float)$row['close_price'],
            'price_date' => $row['price_date'],
            'source'     => 'market',
        ];
    }
    return _addTransactionPriceFallback($prices, null, $asOf);
}

// Fills in, for ids missing from $prices, the latest transaction price on or before
// $asOf. $ids limits which ids are considered (null = every id with transaction prices).
function _addTransactionPriceFallback(array $prices, ?array $ids, string $asOf): array {
    foreach (getTransactionPriceHistory() as $iid => $list) {
        if (isset($prices[$iid]) || ($ids !== null && !in_array($iid, $ids, true))) continue;
        $hit = null;
        foreach ($list as $pt) {
            if ($pt[0] > $asOf) break;
            $hit = $pt;
        }
        if ($hit !== null) {
            $prices[$iid] = ['price' => $hit[1], 'price_date' => $hit[0], 'source' => 'transaction'];
        }
    }
    return $prices;
}

// Per-account cost-basis pools from the FIFO lot engine (getInvestmentLots()):
// "invId:acctId" => ['investment_id', 'account_id', 'qty', 'cost'] for the open
// lots as of $asOf (null = all transactions). Holdings, Portfolio, Cost & Profit
// and the other cost-basis helpers all go through this, so they all use FIFO.
//
// $saleBasisOut, if passed an array variable by reference, is filled with the
// FIFO cost per share of each sell/remove (basis of the lots it consumed ÷ shares),
// keyed by investment_transactions.id — callers multiply by the shares sold.
function _investmentCostBasisPools(?string $asOf = null, ?array &$saleBasisOut = null): array {
    $L = getInvestmentLots($asOf);
    $saleBasisOut = [];
    foreach ($L['sales'] as $id => $sale) {
        $saleBasisOut[$id] = $sale['qty'] > 0.0000001 ? $sale['basis'] / $sale['qty'] : 0.0;
    }
    $pools = [];
    foreach ($L['positions'] as $key => $p) {
        $pools[$key] = [
            'investment_id' => $p['investment_id'],
            'account_id'    => $p['account_id'],
            'qty'           => $p['qty'],
            'cost'          => $p['cost'],
        ];
    }
    return $pools;
}

// ── Portfolio withdrawals ─────────────────────────────────────

// Money moved between the portfolio and spending accounts, for households living off
// their investments. One row per spending-side leg of a transfer pair whose other leg
// is in an investment account (Investment or Crypto) or its cash sleeve: plain
// transfers, and the cash side of a buy/sell/dividend paid from or to a bank account.
// A positive amount is a withdrawal (money into spending, counted as income); a
// negative one is a contribution ("To Investments"). Selling inside an account isn't a
// withdrawal — only money that reaches a spending account is. Education accounts
// (accounts.is_education, e.g. 529 plans, or the cash sleeve of one) are left out.
// Spending accounts are Checking, Savings, Banking and Credit Card.
// $opts:
//   'accounts'         => int[]  only legs in these spending accounts (null = all)
//   'include_excluded' => bool   also return legs marked exclude_from_withdrawals
//                                 (each row carries 'excluded')
//   'skip_income'      => bool   leave out dividends/interest paid straight to a
//                                 spending account (use when investment income is
//                                 already being counted, so it isn't counted twice)
// Rows: id, date, amount, payee, memo, account_id, account_name, portfolio_account_id,
//       portfolio_account_name, is_retirement, activity (of the investment leg, if any),
//       excluded.
function getPortfolioFlows(string $from, string $to, array $opts = []): array {
    $where  = [];
    $params = [$from, $to];
    if (isset($opts['accounts'])) {
        $ids = array_values(array_map('intval', $opts['accounts']));
        if (!$ids) return [];
        $where[] = 't.account_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }
    if (empty($opts['include_excluded'])) $where[] = 't.exclude_from_withdrawals = 0';
    if (!empty($opts['skip_income']))     $where[] = "(it.activity IS NULL OR it.activity NOT IN ('div','int'))";
    $extra = $where ? ' AND ' . implode(' AND ', $where) : '';

    $stmt = getDB()->prepare(
        "SELECT t.id, t.transaction_date, t.amount, t.payee, t.memo, t.exclude_from_withdrawals,
                s.id AS account_id, s.name AS account_name,
                COALESCE(par.id, pa.id)                       AS portfolio_account_id,
                COALESCE(par.name, pa.name)                   AS portfolio_account_name,
                COALESCE(par.is_retirement, pa.is_retirement) AS is_retirement,
                it.activity
         FROM transactions t
         JOIN accounts s      ON s.id = t.account_id
         JOIN transactions p  ON p.id = t.transfer_pair_id
         JOIN accounts pa     ON pa.id = p.account_id
         LEFT JOIN accounts par ON pa.is_investment_cash = 1 AND par.id = pa.linked_account_id
         LEFT JOIN investment_transactions it ON it.transaction_id = p.id
         WHERE s.type IN ('Checking','Savings','Banking','Credit Card') AND s.is_investment_cash = 0
           AND ((pa.type IN ('Investment','Crypto') AND pa.is_investment_cash = 0) OR pa.is_investment_cash = 1)
           AND pa.is_education = 0 AND COALESCE(par.is_education, 0) = 0
           AND t.transaction_date BETWEEN ? AND ?$extra
         ORDER BY t.transaction_date, t.id"
    );
    $stmt->execute($params);
    return array_map(fn($r) => [
        'id'                     => (int)$r['id'],
        'date'                   => $r['transaction_date'],
        'amount'                 => (float)$r['amount'],
        'payee'                  => $r['payee'],
        'memo'                   => $r['memo'],
        'account_id'             => (int)$r['account_id'],
        'account_name'           => $r['account_name'],
        'portfolio_account_id'   => (int)$r['portfolio_account_id'],
        'portfolio_account_name' => $r['portfolio_account_name'],
        'is_retirement'          => (bool)$r['is_retirement'],
        'activity'               => $r['activity'],
        'excluded'               => (bool)$r['exclude_from_withdrawals'],
    ], $stmt->fetchAll());
}

// Withdrawals and contributions from getPortfolioFlows() summed per month:
// ['Y-m' => ['withdrawals' => float (positive), 'contributions' => float (positive)]].
function sumPortfolioFlowsByMonth(array $flows): array {
    $out = [];
    foreach ($flows as $f) {
        $ym = substr($f['date'], 0, 7);
        $out[$ym] ??= ['withdrawals' => 0.0, 'contributions' => 0.0];
        if ($f['amount'] > 0) $out[$ym]['withdrawals']   += $f['amount'];
        else                  $out[$ym]['contributions'] += -$f['amount'];
    }
    return $out;
}

// ── FIFO tax lots ─────────────────────────────────────────────

// True when shares acquired on $acquired and disposed of on $sold were held more
// than one year (long-term). The one-year mark is the same month/day a year later,
// clamped to the month's length, so a Feb 29 purchase turns long-term after Feb 28.
function isLongTermHolding(string $acquired, string $sold): bool {
    [$y, $m, $d] = array_map('intval', explode('-', $acquired));
    $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y + 1));
    return $sold > sprintf('%04d-%02d-%02d', $y + 1, $m, min($d, $dim));
}

// FIFO replay of every investment transaction (investment accounts only, not cash
// sub-accounts), per security per account, through $asOf (null = all). Returns:
//   'positions' => ["invId:acctId" => ['investment_id', 'account_id', 'qty', 'cost',
//                    'lots' => [['acquired', 'qty', 'cost', 'it_id'], ...]]]
//     Open lots, oldest acquisition first. A transfer-in (Add) with an
//     acquired_date takes its place by that date, keeping its holding period.
//   'sales' => [it_id => ['it_id', 'investment_id', 'account_id', 'date', 'activity',
//                'action_type', 'qty', 'price', 'commission', 'proceeds', 'basis',
//                'qty_short', 'basis_short', 'qty_long', 'basis_long',
//                'lots' => [['acquired', 'qty', 'cost', 'long_term'], ...]]]
//     Every sell and remove, with the lots it consumed (oldest first). Shares sold
//     beyond what was held carry no basis and count as short-term.
// Rules: buy/reinvest/priced add open a lot at qty × price (+ commission); an
// unpriced add (holdings reconciliation) opens one at the current average cost of
// the open lots, so it doesn't change the cost of shares already held; a split
// adds shares to every open lot in proportion, keeping each lot's date and cost.
// Memoized per request by $asOf.
function getInvestmentLots(?string $asOf = null): array {
    static $cache = [];
    static $rows  = null;
    $ck = $asOf ?? '';
    if (isset($cache[$ck])) return $cache[$ck];

    // All transactions are fetched once per request; each $asOf replays up to its date
    if ($rows === null) {
        try {
            $rows = getDB()->query(
                'SELECT it.id AS it_id, it.investment_id, t.account_id, t.transaction_date, it.activity,
                        it.action_type, it.quantity, it.price, it.commission, it.acquired_date
                 FROM investment_transactions it
                 JOIN transactions t ON t.id = it.transaction_id
                 JOIN accounts a     ON a.id = t.account_id
                 WHERE a.is_investment_cash = 0 AND it.investment_id IS NOT NULL
                 ORDER BY t.transaction_date, it.id'
            )->fetchAll();
        } catch (Exception $e) {
            $rows = [];
        }
    }

    $eps = 0.0000001;
    $pos = []; $sales = [];
    foreach ($rows as $r) {
        if ($asOf !== null && $r['transaction_date'] > $asOf) break;
        $key = $r['investment_id'] . ':' . $r['account_id'];
        if (!isset($pos[$key])) {
            $pos[$key] = ['investment_id' => (int)$r['investment_id'], 'account_id' => (int)$r['account_id'],
                          'qty' => 0.0, 'cost' => 0.0, 'lots' => []];
        }
        $P     = &$pos[$key];
        $qty   = (float)$r['quantity'];
        $price = (float)$r['price'];
        $comm  = (float)$r['commission'];
        $date  = $r['transaction_date'];

        switch ($r['activity']) {
            case 'buy': case 'reinvest_div': case 'reinvest_cap': case 'add':
                if ($qty <= $eps) break;
                if ($r['activity'] === 'add' && $price <= 0.0) {
                    $cost = $P['qty'] > $eps ? $P['cost'] / $P['qty'] * $qty : 0.0;
                } else {
                    $cost = $qty * $price + $comm;
                }
                $acq = ($r['activity'] === 'add' && !empty($r['acquired_date'])) ? $r['acquired_date'] : $date;
                $lot = ['acquired' => $acq, 'qty' => $qty, 'cost' => $cost, 'it_id' => (int)$r['it_id']];
                // Insert by acquisition date (after any lots with the same date)
                $i = count($P['lots']);
                while ($i > 0 && $P['lots'][$i - 1]['acquired'] > $acq) $i--;
                array_splice($P['lots'], $i, 0, [$lot]);
                $P['qty']  += $qty;
                $P['cost'] += $cost;
                break;

            case 'split':
                if ($qty <= $eps) break;
                if ($P['qty'] > $eps) {
                    $ratio = ($P['qty'] + $qty) / $P['qty'];
                    foreach ($P['lots'] as &$l) $l['qty'] *= $ratio;
                    unset($l);
                } else {
                    $P['lots'][] = ['acquired' => $date, 'qty' => $qty, 'cost' => 0.0, 'it_id' => (int)$r['it_id']];
                }
                $P['qty'] += $qty;
                break;

            case 'sell': case 'remove':
                $left = $qty; $used = [];
                $sale = ['qty_short' => 0.0, 'basis_short' => 0.0, 'qty_long' => 0.0, 'basis_long' => 0.0];
                while ($left > $eps && !empty($P['lots'])) {
                    $l    = &$P['lots'][0];
                    $take = min($left, $l['qty']);
                    $part = $l['qty'] > $eps ? $l['cost'] * $take / $l['qty'] : 0.0;
                    $long = isLongTermHolding($l['acquired'], $date);
                    $used[] = ['acquired' => $l['acquired'], 'qty' => $take, 'cost' => $part, 'long_term' => $long];
                    $sale[$long ? 'qty_long' : 'qty_short']     += $take;
                    $sale[$long ? 'basis_long' : 'basis_short'] += $part;
                    $l['qty']  -= $take;
                    $l['cost'] -= $part;
                    $left      -= $take;
                    $P['qty']  -= $take;
                    $P['cost'] -= $part;
                    if ($l['qty'] <= $eps) array_shift($P['lots']);
                    unset($l);
                }
                if ($left > $eps) {
                    // Sold more than was held: no basis for the excess
                    $used[] = ['acquired' => null, 'qty' => $left, 'cost' => 0.0, 'long_term' => false];
                    $sale['qty_short'] += $left;
                    $P['qty'] -= $left;
                }
                if (abs($P['qty']) <= $eps) { $P['qty'] = 0.0; $P['cost'] = 0.0; }
                $sales[(int)$r['it_id']] = [
                    'it_id'         => (int)$r['it_id'],
                    'investment_id' => (int)$r['investment_id'],
                    'account_id'    => (int)$r['account_id'],
                    'date'          => $date,
                    'activity'      => $r['activity'],
                    'action_type'   => $r['action_type'],
                    'qty'           => $qty,
                    'price'         => $price,
                    'commission'    => $comm,
                    'proceeds'      => $r['activity'] === 'sell' ? $qty * $price - $comm : 0.0,
                    'basis'         => $sale['basis_short'] + $sale['basis_long'],
                    'lots'          => $used,
                ] + $sale;
                break;
            // div/int: income, no share/cost effect
        }
        unset($P);
    }
    return $cache[$ck] = ['positions' => $pos, 'sales' => $sales];
}

// Realized sales, FIFO-matched: real disposals only — every sell, plus a remove
// that is a security expiring worthless (action_type 'Expire'). Other removes are
// holdings reconciliations or transfers out, not sales. One flat list, oldest
// first, each entry as in getInvestmentLots()['sales'] plus 'gain' (proceeds −
// basis) and per-term 'proceeds_short'/'proceeds_long'/'gain_short'/'gain_long'
// (proceeds split by share count). Shared by Capital Gains, Cost & Profit Analysis
// and the security pages' Realized Sales section.
function getRealizedSales(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    foreach (getInvestmentLots()['sales'] as $s) {
        if ($s['activity'] === 'remove' && $s['action_type'] !== 'Expire') continue;
        $q = $s['qty'] > 0 ? $s['qty'] : 1.0;
        $s['proceeds_short'] = $s['proceeds'] * $s['qty_short'] / $q;
        $s['proceeds_long']  = $s['proceeds'] * $s['qty_long']  / $q;
        $s['gain_short']     = $s['proceeds_short'] - $s['basis_short'];
        $s['gain_long']      = $s['proceeds_long']  - $s['basis_long'];
        $s['gain']           = $s['proceeds'] - $s['basis'];
        $out[] = $s;
    }
    usort($out, fn($a, $b) => [$a['date'], $a['it_id']] <=> [$b['date'], $b['it_id']]);
    return $cache = $out;
}

// Sums per-account pools (from _investmentCostBasisPools()) up to one qty/cost
// total per investment_id, across all its accounts.
function _aggregatePoolsByInvestment(array $pools): array {
    $agg = [];
    foreach ($pools as $p) {
        $iid = $p['investment_id'];
        if (!isset($agg[$iid])) $agg[$iid] = ['qty' => 0.0, 'cost' => 0.0];
        $agg[$iid]['qty']  += $p['qty'];
        $agg[$iid]['cost'] += $p['cost'];
    }
    return $agg;
}

function getInvestmentCostBases(): array {
    $agg = _aggregatePoolsByInvestment(_investmentCostBasisPools());
    $bases = [];
    foreach ($agg as $iid => $v) {
        $bases[$iid] = [
            'avg_cost' => $v['qty'] > 0.000001 ? $v['cost'] / $v['qty'] : 0.0,
            'buy_cost' => $v['cost'],
        ];
    }
    return $bases;
}

// Same replay as getInvestmentCostBases(), broken out per (investment, account)
// pair — for reports that show one row per holding-per-account and need the
// correct basis without an N+1 query per account. Keyed "investmentId:accountId".
function getInvestmentCostBasesByAccount(?string $asOf = null): array {
    $bases = [];
    foreach (_investmentCostBasisPools($asOf) as $key => $p) {
        $bases[$key] = [
            'avg_cost' => $p['qty'] > 0.000001 ? $p['cost'] / $p['qty'] : 0.0,
            'buy_cost' => $p['cost'],
        ];
    }
    return $bases;
}

// Each id's most recent price on or before $asOf (not necessarily the latest
// price overall) — same max-date-per-investment pattern as
// getLatestInvestmentPrices(), with a date cutoff.
function _investmentPricesAsOf(array $ids, string $asOf): array {
    if (empty($ids)) return [];
    try {
        $db = getDB();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT ip.investment_id, ip.close_price, ip.price_date
             FROM investment_prices ip
             JOIN (
                 SELECT investment_id, MAX(price_date) AS mx
                 FROM investment_prices
                 WHERE investment_id IN ($ph) AND price_date <= ?
                 GROUP BY investment_id
             ) m ON m.investment_id = ip.investment_id AND m.mx = ip.price_date"
        );
        $stmt->execute(array_merge($ids, [$asOf]));
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['investment_id']] = [
            'price'      => (float)$r['close_price'],
            'price_date' => $r['price_date'],
            'source'     => 'market',
        ];
    }
    return _addTransactionPriceFallback($out, array_map('intval', $ids), $asOf);
}

// Builds a normalized % price-return series (rebased to 0% at each id's first
// available price in range) for every id in $ids over [$fromDate, $toDate], plus
// period and (once the range covers enough time) annualized return. Shared by
// reports/investment_performance.php and the security page's Performance
// Summary — both just add their own labels/colors on top of this.
function getInvestmentPerformanceSeries(array $ids, string $fromDate, string $toDate): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $result = ['dates' => [], 'series' => []];
    if (empty($ids)) return $result;

    $db   = getDB();
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT investment_id, price_date, close_price
         FROM investment_prices
         WHERE investment_id IN ($ph)
           AND price_date BETWEEN ? AND ?
         ORDER BY investment_id, price_date"
    );
    $stmt->execute(array_merge($ids, [$fromDate, $toDate]));

    $rawPrices = [];
    $allDates  = [];
    foreach ($stmt->fetchAll() as $p) {
        $rawPrices[(int)$p['investment_id']][$p['price_date']] = (float)$p['close_price'];
        $allDates[$p['price_date']] = true;
    }
    ksort($allDates);
    $dates = array_keys($allDates);
    $result['dates'] = $dates;

    foreach ($ids as $id) {
        if (!isset($rawPrices[$id])) continue;

        $lastPrice = null; $basePrice = null; $firstDate = null; $lastDate = null; $values = [];
        foreach ($dates as $date) {
            if (isset($rawPrices[$id][$date])) {
                $lastPrice = $rawPrices[$id][$date];
                if ($firstDate === null) $firstDate = $date;
                $lastDate = $date;
            }
            if ($lastPrice !== null) {
                if ($basePrice === null) $basePrice = $lastPrice;
                $values[] = round(($lastPrice / $basePrice - 1) * 100, 4);
            } else {
                $values[] = null;
            }
        }
        if ($basePrice === null) continue;

        $periodReturn = round(($lastPrice / $basePrice - 1) * 100, 2);
        $days         = max(1, (int)((strtotime($lastDate) - strtotime($firstDate)) / 86400));
        $years        = $days / 365.25;
        $annualReturn = $years >= (1 / 12) ? round((pow($lastPrice / $basePrice, 1 / $years) - 1) * 100, 2) : null;

        $result['series'][$id] = [
            'values'       => $values,
            'firstDate'    => $firstDate,
            'lastDate'     => $lastDate,
            'basePrice'    => $basePrice,
            'lastPrice'    => $lastPrice,
            'periodReturn' => $periodReturn,
            'annualReturn' => $annualReturn,
        ];
    }
    return $result;
}

// Cost basis, market value, unrealized gain/loss, and distribution income for
// each currently-held (as of $toDate) investment in $ids, over [$fromDate, $toDate].
// "Currently held" and cost/market-value figures are point-in-time snapshots as of
// $toDate; distributions are summed within the date range. Total Profit and Total
// Return % are period-specific — they use the CHANGE in unrealized gain/loss across
// the period (end snapshot minus start snapshot), not the full lifetime unrealized
// gain, so a long-held position's pre-existing gains aren't misattributed to a short
// report window. Return % divides by the AVERAGE of start- and end-of-period cost
// basis (not just start), since using only the start balance badly overstates
// return when a position grows a lot mid-period (small starting position + a big
// later buy divides that buy's short-lived gain by a stale, tiny denominator).
// This averaging is still an approximation, not a true money-weighted/XIRR return.
// Total Profit also folds in realized gains/losses on any shares sold during the
// period (FIFO basis of each sale via _investmentCostBasisPools()'s $saleBasisOut,
// the same lots reports/capital_gains.php uses), so a position that was partly
// or fully traded during the window isn't missing that leg of its profit.
// $includeExited additionally includes ids that were held at the start of the
// period but fully sold out by $toDate (qty 0 at both start-of-window replay and
// end), so a closed-out position's realized profit for the period is still shown
// instead of the row disappearing entirely. With it, Total Return % divides by the
// capital invested instead — start-of-period cost basis plus the cost of buys and
// transfers in during the period — because the start/end average collapses toward
// zero for a position sold out during the period. Callers that want a portfolio-wide
// table scoped to current holdings (e.g. reports/investment_performance.php)
// should leave this false.
function getInvestmentCostProfitAnalysis(array $ids, string $fromDate, string $toDate, bool $includeExited = false): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) return [];

    $dayBeforeFrom = date('Y-m-d', strtotime($fromDate . ' -1 day'));
    $aggEnd   = _aggregatePoolsByInvestment(_investmentCostBasisPools($toDate, $avgCostAtSale));
    $aggStart = _aggregatePoolsByInvestment(_investmentCostBasisPools($dayBeforeFrom));

    $heldIds = array_values(array_filter($ids, fn($id) => ($aggEnd[$id]['qty'] ?? 0.0) > 0.000001));

    $exitedIds = [];
    if ($includeExited) {
        $exitedIds = array_values(array_filter($ids, fn($id) =>
            ($aggEnd[$id]['qty'] ?? 0.0) <= 0.000001 && ($aggStart[$id]['qty'] ?? 0.0) > 0.000001
        ));
    }
    $processIds = array_values(array_unique(array_merge($heldIds, $exitedIds)));
    if (empty($processIds)) return [];

    $priceEnd   = _investmentPricesAsOf($processIds, $toDate);
    $priceStart = _investmentPricesAsOf($processIds, $dayBeforeFrom);

    $db   = getDB();
    $ph   = implode(',', array_fill(0, count($processIds), '?'));
    $distStmt = $db->prepare(
        "SELECT it.investment_id, t.transaction_date, t.amount, it.activity,
                it.quantity, it.price, it.commission
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN accounts a     ON a.id = t.account_id
         WHERE a.is_investment_cash = 0 AND it.investment_id IN ($ph)
           AND it.activity IN ('div','int','reinvest_div','reinvest_cap')
           AND t.transaction_date BETWEEN ? AND ?
         ORDER BY it.investment_id, t.transaction_date, it.id"
    );
    $distStmt->execute(array_merge($processIds, [$fromDate, $toDate]));

    $distByInv = array_fill_keys($processIds, []);
    foreach ($distStmt->fetchAll() as $r) {
        $iid        = (int)$r['investment_id'];
        $isReinvest = in_array($r['activity'], ['reinvest_div', 'reinvest_cap'], true);
        // Cash dividends/interest carry their $ amount on transactions.amount
        // (quantity/price are 0); reinvestments carry it as quantity*price+commission.
        $amount = $isReinvest
            ? (float)$r['quantity'] * (float)$r['price'] + (float)$r['commission']
            : abs((float)$r['amount']);
        $distByInv[$iid][] = [
            'date'       => $r['transaction_date'],
            'activity'   => $r['activity'],
            'reinvested' => $isReinvest,
            'amount'     => $amount,
        ];
    }

    // Realized gains/losses on shares sold during the period. periodGainLoss
    // (below) only tracks the *unrealized* G/L delta, which goes silent on a
    // sale — the lot cost removed from the position cancels out with no record of
    // what the shares actually sold for. $avgCostAtSale (FIFO cost per share of
    // each sale, from the aggEnd replay above) is the same per-sale lookup
    // capital_gains.php uses, so this stays consistent with that report.
    $salesStmt = $db->prepare(
        "SELECT it.id AS it_id, it.investment_id, t.transaction_date,
                it.quantity, it.price, it.commission
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN accounts a     ON a.id = t.account_id
         WHERE a.is_investment_cash = 0 AND it.investment_id IN ($ph)
           AND it.activity IN ('sell','remove')
           -- A 'remove' is a real disposal only when it's an option/security expiring
           -- worthless (action_type 'Expire' — a genuine $0-proceeds capital loss).
           -- Every other 'remove' is a non-cash holdings-reconciliation or transfer-out
           -- adjustment (action_type 'ShrsOut', or NULL from manual entry, which never
           -- collects a price) — not a sale, even when reconciliation happened to
           -- record a nonzero snapshot price. Matches the filter in capital_gains.php.
           AND (it.activity != 'remove' OR it.action_type = 'Expire')
           AND t.transaction_date BETWEEN ? AND ?
         ORDER BY it.investment_id, t.transaction_date, it.id"
    );
    $salesStmt->execute(array_merge($processIds, [$fromDate, $toDate]));

    $salesByInv         = array_fill_keys($processIds, []);
    $realizedGainLossByInv = array_fill_keys($processIds, 0.0);
    foreach ($salesStmt->fetchAll() as $r) {
        $iid      = (int)$r['investment_id'];
        $sellQty  = (float)$r['quantity'];
        $sellPrc  = (float)$r['price'];
        $sellComm = (float)$r['commission'];
        $proceeds = $sellQty * $sellPrc - $sellComm;

        $avgCost   = $avgCostAtSale[(int)$r['it_id']] ?? 0.0;
        $costBasis = $avgCost * $sellQty;
        $gainLoss  = $proceeds - $costBasis;

        $realizedGainLossByInv[$iid] += $gainLoss;
        $salesByInv[$iid][] = [
            'date'      => $r['transaction_date'],
            'qty'       => $sellQty,
            'price'     => $sellPrc,
            'proceeds'  => $proceeds,
            'costBasis' => $costBasis,
            'gainLoss'  => $gainLoss,
        ];
    }

    // Capital put in during the period: cost of buys and transfers in (priced adds)
    $investedByInv = array_fill_keys($processIds, 0.0);
    if ($includeExited) {
        $invStmt = $db->prepare(
            "SELECT it.investment_id, SUM(it.quantity * it.price + it.commission) AS invested
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             JOIN accounts a     ON a.id = t.account_id
             WHERE a.is_investment_cash = 0 AND it.investment_id IN ($ph)
               AND (it.activity = 'buy' OR (it.activity = 'add' AND it.price > 0))
               AND t.transaction_date BETWEEN ? AND ?
             GROUP BY it.investment_id"
        );
        $invStmt->execute(array_merge($processIds, [$fromDate, $toDate]));
        foreach ($invStmt->fetchAll() as $r) $investedByInv[(int)$r['investment_id']] = (float)$r['invested'];
    }

    $out = [];
    foreach ($processIds as $iid) {
        $qty       = $aggEnd[$iid]['qty'];
        $costBasis = $aggEnd[$iid]['cost'];
        $avgCost   = $qty > 0.000001 ? $costBasis / $qty : 0.0;

        $qtyStart       = $aggStart[$iid]['qty']  ?? 0.0;
        $costBasisStart = $aggStart[$iid]['cost'] ?? 0.0;

        $price        = $priceEnd[$iid]['price']      ?? null;
        $priceDate    = $priceEnd[$iid]['price_date'] ?? null;
        $priceAtStart = $priceStart[$iid]['price']    ?? null;

        // A fully-exited position (qty 0) has no market value regardless of
        // whether a later price happens to exist for it — the shares are gone.
        $marketValue      = $qty <= 0.000001 ? 0.0 : ($price !== null ? $price * $qty : null);
        $marketValueStart = $qtyStart <= 0.000001
            ? 0.0
            : ($priceAtStart !== null ? $priceAtStart * $qtyStart : null);

        $unrealizedGainLoss      = $marketValue      !== null ? $marketValue      - $costBasis      : null;
        $unrealizedGainLossStart = $marketValueStart !== null ? $marketValueStart - $costBasisStart : null;
        $periodGainLoss = ($unrealizedGainLoss !== null && $unrealizedGainLossStart !== null)
            ? $unrealizedGainLoss - $unrealizedGainLossStart : null;

        $dividendsInterest = 0.0;
        $reinvestedDist    = 0.0;
        foreach ($distByInv[$iid] as $d) {
            if ($d['reinvested']) $reinvestedDist    += $d['amount'];
            else                  $dividendsInterest += $d['amount'];
        }
        $totalDistributions = $dividendsInterest + $reinvestedDist;
        $realizedGainLoss   = $realizedGainLossByInv[$iid] ?? 0.0;
        $totalProfit = $periodGainLoss !== null
            ? $periodGainLoss + $totalDistributions + $realizedGainLoss : null;

        $returnBase     = $includeExited
            ? $costBasisStart + $investedByInv[$iid]
            : ($costBasisStart + $costBasis) / 2;
        $totalReturnPct = ($totalProfit !== null && $returnBase > 0.000001)
            ? ($totalProfit / $returnBase) * 100 : null;

        $out[$iid] = [
            'qty'                     => $qty,
            'avgCost'                 => $avgCost,
            'costBasis'               => $costBasis,
            'price'                   => $price,
            'priceDate'               => $priceDate,
            'marketValue'             => $marketValue,
            'unrealizedGainLoss'      => $unrealizedGainLoss,
            'dividendsInterest'       => $dividendsInterest,
            'reinvestedDistributions' => $reinvestedDist,
            'totalDistributions'      => $totalDistributions,
            'realizedGainLoss'        => $realizedGainLoss,
            'totalProfit'             => $totalProfit,
            'totalReturnPct'          => $totalReturnPct,
            'returnBase'              => $returnBase,
            'distributions'           => $distByInv[$iid],
            'sales'                   => $salesByInv[$iid],
        ];
    }
    return $out;
}

// One investment's full daily price history, merged with a per-transaction-date
// fallback price wherever there's no real price row for that date — the average
// per-share price paid/received on any buy/sell/reinvest that day. This keeps a
// chart usable even for a security with only a handful of manual/fetched prices.
// $realOnly (used by the manual price editor, which edits/deletes actual
// investment_prices rows) skips the fallback and returns only real price rows.
// Returned rows are ordered by date and shaped for direct JSON/chart use: date,
// open, high, low, close (always present), volume, vwap, source.
function getInvestmentPriceHistory(int $investmentId, bool $realOnly = false): array {
    $db = getDB();

    $priceStmt = $db->prepare(
        'SELECT price_date, open_price, high_price, low_price, close_price, volume, vwap, source
         FROM investment_prices
         WHERE investment_id = ?
         ORDER BY price_date ASC'
    );
    $priceStmt->execute([$investmentId]);
    $rows = $priceStmt->fetchAll();

    if (!$realOnly) {
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['price_date']] = $r;
        }

        $txnStmt = $db->prepare(
            'SELECT t.transaction_date AS price_date,
                    SUM(it.price * it.quantity) / SUM(it.quantity) AS close_price
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             WHERE it.investment_id = ?
               AND it.activity IN (\'buy\', \'sell\', \'add\', \'reinvest_div\', \'reinvest_cap\')
               AND it.price > 0 AND it.quantity > 0
             GROUP BY t.transaction_date'
        );
        $txnStmt->execute([$investmentId]);
        foreach ($txnStmt->fetchAll() as $t) {
            if (isset($byDate[$t['price_date']])) continue;
            $byDate[$t['price_date']] = [
                'price_date'  => $t['price_date'],
                'open_price'  => null,
                'high_price'  => null,
                'low_price'   => null,
                'close_price' => $t['close_price'],
                'volume'      => null,
                'vwap'        => null,
                'source'      => 'transaction',
            ];
        }

        ksort($byDate);
        $rows = array_values($byDate);
    }

    return array_map(fn($p) => [
        'date'   => $p['price_date'],
        'open'   => $p['open_price']  !== null ? (float)$p['open_price']  : null,
        'high'   => $p['high_price']  !== null ? (float)$p['high_price']  : null,
        'low'    => $p['low_price']   !== null ? (float)$p['low_price']   : null,
        'close'  => (float)$p['close_price'],
        'volume' => $p['volume']      !== null ? (int)$p['volume']        : null,
        'vwap'   => $p['vwap']        !== null ? (float)$p['vwap']        : null,
        'source' => $p['source'],
    ], $rows);
}

// Builds a "Total Return" overlay for a getInvestmentPerformanceSeries() line:
// adds cumulative distributions received (as a % of the series' base price) on
// top of its existing normalized price-return values. A distribution dated
// between two chart dates is attributed to the next chart date on or after it
// (there's no price point to plot it at on its own exact date), same convention
// used for the buy/sell markers on the security page's price chart.
function investmentTotalReturnOverlay(array $dates, array $priceReturnValues, float $basePrice, array $distributions): array {
    if ($basePrice <= 0.000001) return $priceReturnValues;
    usort($distributions, fn($a, $b) => $a['date'] <=> $b['date']);

    $out = [];
    $cum = 0.0;
    $di  = 0;
    $n   = count($distributions);
    foreach ($dates as $i => $date) {
        while ($di < $n && $distributions[$di]['date'] <= $date) {
            $cum += $distributions[$di]['amount'];
            $di++;
        }
        $out[] = $priceReturnValues[$i] !== null ? $priceReturnValues[$i] + ($cum / $basePrice) * 100 : null;
    }
    return $out;
}

// ── Scheduled bills / deposits ─────────────────────────────────

function getScheduledBills(): array {
    $db = getDB();
    return $db->query(
        'SELECT sb.*,
                a.name  AS account_name,
                ta.name AS to_account_name,
                c.name  AS category_name,
                sc.name AS subcategory_name
         FROM scheduled_bills sb
         JOIN accounts a          ON a.id  = sb.account_id
         LEFT JOIN accounts ta    ON ta.id = sb.to_account_id
         LEFT JOIN categories c   ON c.id  = sb.category_id
         LEFT JOIN categories sc  ON sc.id = sb.subcategory_id
         WHERE sb.is_active = 1
         ORDER BY sb.next_due_date ASC, sb.name ASC'
    )->fetchAll();
}

function getUpcomingBills(int $days = 7): array {
    return getUpcomingBillsByRange($days);
}

function getUpcomingBillsByRange(int $days = 30): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT sb.*,
                a.name  AS account_name,
                ta.name AS to_account_name,
                c.name  AS category_name,
                sc.name AS subcategory_name
         FROM scheduled_bills sb
         JOIN accounts a          ON a.id  = sb.account_id
         LEFT JOIN accounts ta    ON ta.id = sb.to_account_id
         LEFT JOIN categories c   ON c.id  = sb.category_id
         LEFT JOIN categories sc  ON sc.id = sb.subcategory_id
         WHERE sb.is_active = 1
           AND sb.next_due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY sb.next_due_date ASC'
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// ── Recent transactions ─────────────────────────────────────────

function getRecentTransactions(int $limit = 10, array $accountIds = []): array {
    $db = getDB();
    if (!empty($accountIds)) {
        $ph   = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $db->prepare(
            "SELECT t.*, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE t.account_id IN ($ph)
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT ?"
        );
        $stmt->execute([...$accountIds, $limit]);
    } else {
        $stmt = $db->prepare(
            'SELECT t.*, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
    }
    return $stmt->fetchAll();
}

// ── CSRF helpers ───────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

// ── Flash messages ─────────────────────────────────────────────

function getFavoriteReports(): array {
    return getDB()->query(
        "SELECT id, title, url, icon, graph_config FROM favorite_reports WHERE type = 'dashboard' ORDER BY sort_order ASC, id ASC"
    )->fetchAll();
}

function getSavedCustomReports(): array {
    return getDB()->query(
        "SELECT id, title, url, icon, created_at FROM favorite_reports WHERE type = 'saved' ORDER BY created_at DESC, id DESC"
    )->fetchAll();
}

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ── User Preferences ───────────────────────────────────────────

function getUserPref(int $userId, string $key, ?string $default = null): ?string {
    $db   = getDB();
    $stmt = $db->prepare('SELECT pref_value FROM user_prefs WHERE user_id = ? AND pref_key = ?');
    $stmt->execute([$userId, $key]);
    $row  = $stmt->fetch();
    return $row !== false ? $row['pref_value'] : $default;
}

/**
 * View preferences the browser may save through api/save_pref.php, as
 * key => pattern the value must match. Any other key is rejected.
 */
const VIEW_PREF_PATTERNS = [
    'portfolio_show_unowned' => '/^[01]$/',
    'portfolio_sort'         => '/^[a-z_]{1,30}:(asc|desc)$/',   // column:direction
    'budget_sort'            => '/^(name|used|variance|actual|remaining|last_year):(asc|desc)$/',
    'budget_chart'           => '/^(cumulative|monthly|variance|groups):(expense|income|net)$/',   // tab:series
    // Visible optional columns, comma-separated (empty = none); see viewColumns()
    'portfolio_cols'         => '/^((cusip|country|memo)(,|$))*$/',
    'watchlist_cols'         => '/^((cusip|country|memo|prev_close|open|day_range|volume|prev_volume|avg_volume|range_52w|ret_1m|ret_ytd|ret_1y)(,|$))*$/',
];

/**
 * Which optional columns to show: the user's saved list for $prefKey (see
 * VIEW_PREF_PATTERNS), else $defaults. Returns visible keys in $choosable order.
 */
function viewColumns(string $prefKey, array $choosable, array $defaults): array {
    $raw  = getUserPref(currentUserId(), $prefKey);
    $want = $raw === null ? $defaults : array_filter(explode(',', $raw));
    return array_values(array_intersect($choosable, $want));
}

function setUserPref(int $userId, string $key, ?string $value): void {
    $db = getDB();
    if ($value === null) {
        $stmt = $db->prepare('DELETE FROM user_prefs WHERE user_id = ? AND pref_key = ?');
        $stmt->execute([$userId, $key]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO user_prefs (user_id, pref_key, pref_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()'
        );
        $stmt->execute([$userId, $key, $value]);
    }
}

function outputCsv(string $filename, array $headers, array $rows): never {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_map('csvSafeCell', $headers), ',', '"', '\\');
    foreach ($rows as $row) fputcsv($out, array_map('csvSafeCell', array_values($row)), ',', '"', '\\');
    fclose($out);
    exit;
}

// Guards against CSV/formula injection: a cell whose text starts with a
// formula-triggering character opens as a live formula in Excel/Sheets. Plain
// numbers (e.g. "-1234.56") are left untouched so money columns aren't corrupted.
function csvSafeCell($value) {
    if (!is_string($value) || $value === '') return $value;
    if (!preg_match('/^[=+\-@]/', $value)) return $value;
    if (preg_match('/^-?\d+(\.\d+)?$/', $value)) return $value;
    return "'" . $value;
}

function renderFlash(): string {
    $f = getFlash();
    if (!$f) return '';
    $cls = match($f['type']) {
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        'warning' => 'alert-warning',
        default   => 'alert-info',
    };
    return '<div class="alert ' . $cls . ' alert-dismissible fade show mb-0" role="alert">'
         . h($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── Dashboard widget data helpers ───────────────────────────────

// Reconstructs investment account market values at each given date (YYYY-MM-DD),
// forward-filling the latest known price as of that date for each holding.
function getHistoricalInvestmentMarketValues(array $accounts, array $points, ?string $txnCutoff = null): array {
    // Market value (holdings × price) per investment account at each date in $points:
    // [point => [acct_id => float]]. Prices come from getInvestmentPricesAtDates(), so a
    // security with no market price on a date is valued at its last transaction price.
    // $txnCutoff, if given, ignores transactions after that date (e.g. future-dated ones).
    // getDashboardNetWorth() and getDashboardNetWorthToday() both call this with the
    // same account set but different $points — cache the transaction fetch per request,
    // keyed by the sorted investment-account-ID list and cutoff.
    static $fetchCache = [];

    $investAccts   = array_filter($accounts, fn($a) => isInvestLike($a['type']) && !$a['is_investment_cash']);
    $investAcctIds = array_values(array_unique(array_map('intval', array_column(array_values($investAccts), 'id'))));
    $histMktValues = []; // point -> [acct_id -> float]
    if (empty($investAcctIds) || empty($points)) return $histMktValues;

    sort($investAcctIds);
    $cacheKey = implode(',', $investAcctIds) . '|' . ($txnCutoff ?? '');

    if (isset($fetchCache[$cacheKey])) {
        $iTxns = $fetchCache[$cacheKey];
    } else {
        $iph    = implode(',', array_fill(0, count($investAcctIds), '?'));
        $params = $investAcctIds;
        $cutSql = '';
        if ($txnCutoff !== null) { $cutSql = ' AND t.transaction_date <= ?'; $params[] = $txnCutoff; }
        $iStmt = getDB()->prepare(
            "SELECT t.account_id, it.investment_id, it.activity, it.quantity, t.transaction_date
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             WHERE t.account_id IN ($iph)$cutSql
             ORDER BY t.transaction_date, it.id"
        );
        $iStmt->execute($params);
        $iTxns = $fetchCache[$cacheKey] = $iStmt->fetchAll();
    }

    $sortedPoints = $points;
    sort($sortedPoints);
    $invIds = array_values(array_unique(array_map('intval', array_column($iTxns, 'investment_id'))));
    $prices = getInvestmentPricesAtDates($invIds, $sortedPoints);

    $holdings = [];
    $ti = 0; $nt = count($iTxns);
    foreach ($sortedPoints as $pt) {
        while ($ti < $nt && $iTxns[$ti]['transaction_date'] <= $pt) {
            $row = $iTxns[$ti++];
            $aid = (int)$row['account_id']; $iid = (int)$row['investment_id'];
            $qty = (float)$row['quantity'];
            if (in_array($row['activity'], ['buy','add','split','reinvest_div','reinvest_cap']))
                $holdings[$aid][$iid] = ($holdings[$aid][$iid] ?? 0.0) + $qty;
            elseif (in_array($row['activity'], ['sell','remove']))
                $holdings[$aid][$iid] = ($holdings[$aid][$iid] ?? 0.0) - $qty;
        }
        foreach ($investAcctIds as $aid) {
            $mv = 0.0;
            foreach (($holdings[$aid] ?? []) as $iid => $qty) {
                if ($qty < 0.000001) continue;
                $mv += $qty * ($prices[$iid][$pt]['price'] ?? 0.0);
            }
            $histMktValues[$pt][$aid] = $mv;
        }
    }
    return $histMktValues;
}

function getDashboardNetWorth(int $months = 12): array {
    $db = getDB();
    $accounts = $db->query(
        "SELECT id, type, is_investment_cash, opening_balance FROM accounts
         WHERE is_active = 1 AND exclude_from_net_worth = 0"
    )->fetchAll();
    if (empty($accounts)) return ['labels' => [], 'net' => [], 'current' => 0.0, 'change' => 0.0];

    $periodEnd   = new DateTime(date('Y-m-t'));
    $cursor      = (clone $periodEnd)->modify('-' . ($months - 1) . ' months')->modify('first day of this month');
    $monthPoints = [];
    while ($cursor <= $periodEnd) { $monthPoints[] = $cursor->format('Y-m-t'); $cursor->modify('+1 month'); }

    $acctIds   = array_column($accounts, 'id');
    $ph        = implode(',', array_fill(0, count($acctIds), '?'));
    $txnCutoff = min(end($monthPoints), date('Y-m-d'));
    $stmt      = $db->prepare(
        "SELECT account_id, DATE_FORMAT(transaction_date,'%Y-%m') AS ym, SUM(amount) AS month_total
         FROM transactions WHERE account_id IN ($ph) AND transaction_date <= ?
         GROUP BY account_id, ym ORDER BY account_id, ym"
    );
    $stmt->execute([...$acctIds, $txnCutoff]);

    $cumul = [];
    foreach ($stmt->fetchAll() as $r) $cumul[(int)$r['account_id']][$r['ym']] = (float)$r['month_total'];

    $running = [];
    foreach ($accounts as $acc) {
        $aid = (int)$acc['id']; $map = $cumul[$aid] ?? []; ksort($map);
        $tot = 0; $runMap = [];
        foreach ($map as $ym => $v) { $tot += $v; $runMap[$ym] = $tot; }
        $running[$aid] = ['opening' => (float)$acc['opening_balance'], 'type' => $acc['type'],
                          'is_inv_cash' => (bool)$acc['is_investment_cash'], 'map' => $runMap];
    }

    // Historical market values for investment accounts (same approach as net_worth.php)
    $histMktValues = getHistoricalInvestmentMarketValues($accounts, $monthPoints); // eom -> [acct_id -> float]

    $labels = []; $net = [];
    foreach ($monthPoints as $eom) {
        $ym = substr($eom, 0, 7);
        $assets = 0; $liab = 0;
        foreach ($running as $aid => $info) {
            if (isInvestLike($info['type']) && !$info['is_inv_cash']) {
                $bal = $histMktValues[$eom][$aid] ?? 0.0;
            } else {
                $txnTot = 0;
                foreach ($info['map'] as $tym => $v) { if ($tym <= $ym) $txnTot = $v; }
                $bal = $info['opening'] + $txnTot;
            }
            if ($info['type'] === 'Credit Card') { if ($bal < 0) $liab += abs($bal); else $assets += $bal; }
            else                                 { if ($bal >= 0) $assets += $bal;   else $liab  += abs($bal); }
        }
        $labels[] = date('M y', strtotime($eom));
        $net[]    = round($assets - $liab, 2);
    }
    $current = end($net) ?: 0.0;
    $change  = count($net) > 1 ? round($current - $net[0], 2) : 0.0;
    return ['labels' => $labels, 'net' => $net, 'current' => $current, 'change' => $change];
}

function getDashboardNetWorthToday(): array {
    $db = getDB();
    $accounts = $db->query(
        "SELECT id, type, is_investment_cash, opening_balance FROM accounts
         WHERE is_active = 1 AND exclude_from_net_worth = 0"
    )->fetchAll();
    if (empty($accounts)) return ['current' => 0.0, 'change' => 0.0];

    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $points    = [$yesterday, $today];

    $acctIds = array_column($accounts, 'id');
    $ph      = implode(',', array_fill(0, count($acctIds), '?'));
    $stmt    = $db->prepare(
        "SELECT account_id, transaction_date, SUM(amount) AS day_total
         FROM transactions WHERE account_id IN ($ph) AND transaction_date <= ?
         GROUP BY account_id, transaction_date ORDER BY account_id, transaction_date"
    );
    $stmt->execute([...$acctIds, $today]);

    $cumul = [];
    foreach ($stmt->fetchAll() as $r) $cumul[(int)$r['account_id']][$r['transaction_date']] = (float)$r['day_total'];

    $running = [];
    foreach ($accounts as $acc) {
        $aid = (int)$acc['id']; $map = $cumul[$aid] ?? []; ksort($map);
        $tot = 0; $runMap = [];
        foreach ($map as $d => $v) { $tot += $v; $runMap[$d] = $tot; }
        $running[$aid] = ['opening' => (float)$acc['opening_balance'], 'type' => $acc['type'],
                          'is_inv_cash' => (bool)$acc['is_investment_cash'], 'map' => $runMap];
    }

    $histMktValues = getHistoricalInvestmentMarketValues($accounts, $points);

    $net = [];
    foreach ($points as $pt) {
        $assets = 0; $liab = 0;
        foreach ($running as $aid => $info) {
            if (isInvestLike($info['type']) && !$info['is_inv_cash']) {
                $bal = $histMktValues[$pt][$aid] ?? 0.0;
            } else {
                $txnTot = 0;
                foreach ($info['map'] as $td => $v) { if ($td <= $pt) $txnTot = $v; }
                $bal = $info['opening'] + $txnTot;
            }
            if ($info['type'] === 'Credit Card') { if ($bal < 0) $liab += abs($bal); else $assets += $bal; }
            else                                 { if ($bal >= 0) $assets += $bal;   else $liab  += abs($bal); }
        }
        $net[$pt] = round($assets - $liab, 2);
    }

    $current = $net[$today] ?? 0.0;
    $change  = round($current - ($net[$yesterday] ?? $current), 2);
    return ['current' => $current, 'change' => $change];
}

function getDashboardCashFlow(int $months = 6): array {
    $db = getDB();
    $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    // Same definitions as the Income vs. Expense report's default view (portfolio
    // withdrawals included), so the two agree: categorized splits only — income categories are income, expense categories
    // are expenses (refunds net against them) — on the same accounts (active, not
    // investment, investment-cash, asset or crypto), transfers left out. Opening
    // balances, asset revaluations and other uncategorized amounts aren't income.
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') AS ym,
                ABS(SUM(CASE WHEN c.type = 'income'  THEN ts.amount ELSE 0 END)) AS income,
                ABS(SUM(CASE WHEN c.type = 'expense' THEN ts.amount ELSE 0 END)) AS expenses
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         JOIN categories   c ON c.id = ts.category_id
         JOIN accounts     a ON a.id = t.account_id
         WHERE a.is_active = 1 AND a.is_investment_cash = 0
           AND a.type NOT IN ('Investment','Asset','Crypto')
           AND c.type IN ('income','expense')
           AND t.type != 'transfer'
           AND t.transaction_date >= ?
         GROUP BY ym ORDER BY ym"
    );
    $stmt->execute([$from]);
    $rows = $stmt->fetchAll();

    // Portfolio withdrawals count as income and contributions go on their own line,
    // as in Income vs. Expense (its default view, same accounts)
    $spendIds = array_map('intval', $db->query(
        "SELECT id FROM accounts WHERE is_active = 1 AND is_investment_cash = 0
           AND type NOT IN ('Investment','Asset','Crypto')"
    )->fetchAll(PDO::FETCH_COLUMN));
    $pw = sumPortfolioFlowsByMonth(getPortfolioFlows($from, date('Y-m-t'), ['accounts' => $spendIds]));

    // Build full month list so gaps show as zero
    $labels = []; $income = []; $expenses = []; $toInv = [];
    $byYm   = array_column($rows, null, 'ym');
    $cur    = new DateTime($from);
    $end    = new DateTime(date('Y-m-01'));
    while ($cur <= $end) {
        $ym        = $cur->format('Y-m');
        $labels[]  = $cur->format('M y');
        $income[]  = round((float)($byYm[$ym]['income']   ?? 0) + ($pw[$ym]['withdrawals'] ?? 0), 2);
        $expenses[] = round((float)($byYm[$ym]['expenses'] ?? 0), 2);
        $toInv[]    = round($pw[$ym]['contributions'] ?? 0, 2);
        $cur->modify('+1 month');
    }
    return ['labels' => $labels, 'income' => $income, 'expenses' => $expenses, 'to_investments' => $toInv];
}

function getDashboardGoals(): array {
    $db = getDB();
    return $db->query(
        "SELECT g.id, g.name, g.target_amount, g.target_date, g.notes,
                CASE WHEN g.account_id IS NOT NULL
                     THEN a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id),0)
                     ELSE g.current_amount
                END AS effective_current
         FROM savings_goals g
         LEFT JOIN accounts a ON a.id = g.account_id
         WHERE g.is_active = 1
         ORDER BY g.target_date IS NULL, g.target_date ASC, g.name ASC"
    )->fetchAll();
}

function getDashboardLoans(): array {
    $db = getDB();
    return $db->query(
        "SELECT ld.*, a.name,
                a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id),0) AS current_balance
         FROM loan_details ld
         JOIN accounts a ON a.id = ld.account_id
         WHERE a.is_active = 1
         ORDER BY a.name"
    )->fetchAll();
}

function getDashboardSpending(string $period, array $accountIds = []): array {
    $db     = getDB();
    $params = [];

    if ($period === 'year') {
        $dateSql  = 'YEAR(t.transaction_date) = ?';
        $params[] = (int)date('Y');
    } elseif ($period === '90days') {
        $dateSql = 't.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)';
    } elseif ($period === 'last_month') {
        $lm = new \DateTime('first day of last month');
        $dateSql  = 'YEAR(t.transaction_date) = ? AND MONTH(t.transaction_date) = ?';
        $params[] = (int)$lm->format('Y');
        $params[] = (int)$lm->format('n');
    } else {
        $dateSql  = 'YEAR(t.transaction_date) = ? AND MONTH(t.transaction_date) = ?';
        $params[] = (int)date('Y');
        $params[] = (int)date('n');
    }

    $acctSql = '';
    if (!empty($accountIds)) {
        $ph      = implode(',', array_fill(0, count($accountIds), '?'));
        $acctSql = "AND t.account_id IN ($ph)";
        $params  = array_merge($params, $accountIds);
    }

    $stmt = $db->prepare(
        "SELECT c.name AS category, -SUM(ts.amount) AS total
         FROM transaction_splits ts
         JOIN transactions t ON t.id  = ts.transaction_id
         JOIN categories c   ON c.id  = ts.category_id
         JOIN accounts a     ON a.id  = t.account_id
         WHERE t.type IN ('withdrawal','deposit')
           AND c.type = 'expense'
           AND a.is_investment_cash = 0
           AND $dateSql
           $acctSql
         GROUP BY ts.category_id
         HAVING total >= 0.005
         ORDER BY total DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getDashboardPortfolioSnapshot(): array {
    try {
        $db   = getDB();
        $invs = $db->query(
            "SELECT id, name, symbol, type, country FROM investments WHERE is_active = 1 ORDER BY type, name"
        )->fetchAll();

        $holdingRows = $db->query(
            "SELECT it.investment_id,
                    SUM(CASE
                        WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
                        WHEN it.activity IN ('sell','remove')                                   THEN -it.quantity
                        ELSE 0
                    END) AS qty
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             JOIN accounts a     ON a.id = t.account_id
             WHERE a.is_investment_cash = 0
             GROUP BY it.investment_id"
        )->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    $qtyMap = [];
    foreach ($holdingRows as $h) $qtyMap[(int)$h['investment_id']] = (float)$h['qty'];

    // Reuse the shared, request-cached latest-price lookup instead of a second
    // window-function query over the same table.
    $latestPrices = getLatestInvestmentPrices();

    $out = [];
    foreach ($invs as $i) {
        $iid = (int)$i['id'];
        $p   = $latestPrices[$iid] ?? null;
        if ($p === null) continue; // matches the prior INNER JOIN — only priced securities

        $price     = $p['price'];
        $prevClose = $p['prev_close'];
        $qty       = $qtyMap[$iid] ?? 0.0;
        $dayChg    = $prevClose !== null ? round($price - $prevClose, 4) : null;
        $dayChgPct = ($dayChg !== null && $prevClose != 0)
            ? round($dayChg / $prevClose * 100, 2) : null;
        $mktVal    = round($qty * $price, 2);
        $valChg    = $dayChg !== null ? round($qty * $dayChg, 2) : null;
        $out[] = [
            'id'          => $iid,
            'name'        => $i['name'],
            'symbol'      => $i['symbol'],
            'type'        => $i['type'],
            'country'     => $i['country'],
            'price'       => $price,
            'prev_close'  => $prevClose,
            'price_date'  => $p['price_date'],
            'volume'      => $p['volume'],
            'qty'         => $qty,
            'day_chg'     => $dayChg,
            'day_chg_pct' => $dayChgPct,
            'mkt_val'     => $mktVal,
            'val_chg'     => $valChg,
        ];
    }
    return $out;
}

function getDashboardWatchlist(): array {
    try {
        $rows = getDB()->query(
            "SELECT id, name, symbol, type FROM investments
             WHERE is_active = 1 AND in_watchlist = 1
             ORDER BY name"
        )->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    $prices = getLatestInvestmentPrices();

    return array_map(function (array $r) use ($prices): array {
        $p         = $prices[(int)$r['id']] ?? null;
        $price     = $p['price']      ?? null;
        $prevClose = $p['prev_close'] ?? null;
        $dayChg    = ($price !== null && $prevClose !== null) ? round($price - $prevClose, 4) : null;
        $dayChgPct = ($dayChg !== null && $prevClose) ? round($dayChg / $prevClose * 100, 2) : null;
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'symbol'      => $r['symbol'],
            'type'        => $r['type'],
            'price'       => $price,
            'day_chg'     => $dayChg,
            'day_chg_pct' => $dayChgPct,
        ];
    }, $rows);
}

// Portfolio Withdrawals widget: this year's and last year's withdrawals from the
// portfolio (getPortfolioFlows()), split into retirement and non-retirement, with
// this year's monthly average.
function getDashboardPortfolioWithdrawals(): array {
    $y     = (int)date('Y');
    $empty = ['total' => 0.0, 'ret' => 0.0, 'nonret' => 0.0, 'count' => 0];
    $years = [$y => $empty, $y - 1 => $empty];
    foreach (getPortfolioFlows(($y - 1) . '-01-01', date('Y-m-d')) as $f) {
        if ($f['amount'] <= 0) continue;
        $fy = (int)substr($f['date'], 0, 4);
        $years[$fy]['total'] += $f['amount'];
        $years[$fy][$f['is_retirement'] ? 'ret' : 'nonret'] += $f['amount'];
        $years[$fy]['count']++;
    }
    return ['years' => $years, 'avg' => $years[$y]['total'] / max(1, (int)date('n'))];
}

// Refresh-prices icon button for the dashboard header and the investment widget
// headers — one place for the markup and the rules: shown only to users who can
// edit (portfolio/fetch_prices requires the user or administrator role; a viewer
// would just get an error) and only when an online price provider is set.
function priceRefreshButton(string $class = 'tile-header-link'): string {
    if (!canEdit() || getSetting('price_provider', 'manual') === 'manual') return '';
    return '<button type="button" class="' . h($class) . '" onclick="refreshPortfolioPrices(this)"'
         . ' title="Refresh prices"><i class="bi bi-arrow-clockwise"></i></button>';
}

// Realized Gains widget: FIFO realized gains in taxable accounts only — Investment
// accounts that aren't retirement accounts, the same scope Capital Gains shows by
// default. Returns this year's and last year's totals by holding term, and the most
// recent sales:
//   ['years'  => [year => ['short', 'long', 'total', 'proceeds', 'count']],
//    'recent' => [['date', 'name', 'symbol', 'qty', 'gain', 'term' => 'Short'|'Long'|'Mixed']]]
function getDashboardRealizedGains(int $recentCount = 5): array {
    $taxable = array_map('intval', getDB()->query(
        "SELECT id FROM accounts
         WHERE type = 'Investment' AND is_investment_cash = 0 AND is_retirement = 0"
    )->fetchAll(PDO::FETCH_COLUMN));
    $taxable = array_flip($taxable);

    $thisYear = (int)date('Y');
    $empty    = ['short' => 0.0, 'long' => 0.0, 'total' => 0.0, 'proceeds' => 0.0, 'count' => 0];
    $years    = [$thisYear => $empty, $thisYear - 1 => $empty];
    $sales    = array_values(array_filter(getRealizedSales(), fn($s) => isset($taxable[$s['account_id']])));

    foreach ($sales as $s) {
        $y = (int)substr($s['date'], 0, 4);
        if (!isset($years[$y])) continue;
        $years[$y]['short']    += $s['gain_short'];
        $years[$y]['long']     += $s['gain_long'];
        $years[$y]['total']    += $s['gain'];
        $years[$y]['proceeds'] += $s['proceeds'];
        $years[$y]['count']++;
    }

    $recent = [];
    if ($sales) {
        $inv = [];
        foreach (getDB()->query('SELECT id, name, symbol FROM investments')->fetchAll() as $i) $inv[(int)$i['id']] = $i;
        foreach (array_slice(array_reverse($sales), 0, $recentCount) as $s) {
            $recent[] = [
                'date'   => $s['date'],
                'name'   => $inv[$s['investment_id']]['name']   ?? '',
                'symbol' => $inv[$s['investment_id']]['symbol'] ?? '',
                'qty'    => $s['qty'],
                'gain'   => $s['gain'],
                'term'   => $s['qty_long'] > 0.0000001 ? ($s['qty_short'] > 0.0000001 ? 'Mixed' : 'Long') : 'Short',
            ];
        }
    }
    return ['years' => $years, 'recent' => $recent];
}

// ── Dashboard Bookmarks ────────────────────────────────────────
function ensureBookmarksTable(): void {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS dashboard_bookmarks (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            title      VARCHAR(255) NOT NULL,
            url        VARCHAR(2048) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bmk_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getUserBookmarks(): array {
    ensureBookmarksTable();
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, title, url, sort_order FROM dashboard_bookmarks WHERE user_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([currentUserId()]);
    return $stmt->fetchAll();
}

// ── Schema migrations ───────────────────────────────────────────

function getMigrationFiles(): array {
    $files = glob(__DIR__ . '/../sql/migrations/*.sql') ?: [];
    natsort($files);
    $result = [];
    foreach ($files as $f) {
        if (preg_match('/(\d+)_/', basename($f), $m)) {
            $result[(int)$m[1]] = $f;
        }
    }
    return $result;
}

function getAppliedMigrationVersions(): array {
    try {
        $rows = getDB()->query('SELECT version FROM schema_migrations ORDER BY version')
                       ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function getPendingMigrations(): array {
    $applied = array_flip(getAppliedMigrationVersions());
    $pending = [];
    foreach (getMigrationFiles() as $ver => $path) {
        if (!isset($applied[$ver])) {
            $pending[$ver] = $path;
        }
    }
    return $pending;
}

function getCurrentSchemaVersion(): int {
    $applied = getAppliedMigrationVersions();
    return $applied ? max($applied) : APP_SCHEMA_VERSION;
}

function getAppSchemaVersion(): int {
    $files = getMigrationFiles();
    return $files ? max(array_keys($files)) : APP_SCHEMA_VERSION;
}

/**
 * Create a full SQL backup of the database in the configured backup directory,
 * prune old backups beyond the retention count, and record the run.
 * Shared by the manual "Backup Now" action and any operation (e.g. database
 * maintenance's Optimize Tables) that needs a safety backup beforehand.
 *
 * @return array{ok:bool, file?:string, size?:int, status?:string, error?:string}
 */
function createServerBackup(): array {
    $rawDir = trim((string)(getSetting('backup_dir', '') ?? ''));
    if ($rawDir === '') {
        return [
            'ok'     => false,
            'status' => 'backup_dir_missing',
            'error'  => 'Backup location is not configured yet.',
        ];
    }

    $dir    = rtrim($rawDir, '/');
    $retain = max(1, (int)getSetting('backup_retain', '14'));

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true)) {
            return ['ok' => false, 'error' => "Cannot create directory: $dir"];
        }
    }

    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => "Directory is not writable: $dir"];
    }

    $filename = $dir . '/steward-backup-' . date('Y-m-d_His') . '.sql';
    $fh = fopen($filename, 'w');
    if (!$fh) {
        return ['ok' => false, 'error' => "Cannot write to: $filename"];
    }

    $db     = getDB();
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    fwrite($fh, "-- Steward Backup\n");
    fwrite($fh, "-- Generated     : " . date('Y-m-d H:i:s T') . "\n");
    fwrite($fh, "-- Database      : " . DB_NAME . "\n");
    fwrite($fh, "-- Tables        : " . count($tables) . "\n");
    fwrite($fh, "-- Schema-Version: " . getAppSchemaVersion() . "\n");
    fwrite($fh, "\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET time_zone='+00:00';\n");
    fwrite($fh, "\n");

    foreach ($tables as $table) {
        fwrite($fh, "-- --------------------------------------------------------\n");
        fwrite($fh, "-- Table: `$table`\n");
        fwrite($fh, "-- --------------------------------------------------------\n\n");
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");

        $ddl = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, $ddl[1] . ";\n\n");

        $stmt  = $db->query("SELECT * FROM `$table`");
        $cols  = null;
        $batch = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = array_keys($row);
            }
            $vals = array_map(
                fn($v) => $v === null ? 'NULL' : $db->quote($v),
                array_values($row)
            );
            $batch[] = '(' . implode(', ', $vals) . ')';

            if (count($batch) >= 500) {
                fwrite($fh, 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n");
                fwrite($fh, implode(",\n", $batch) . ";\n\n");
                $batch = [];
            }
        }

        if (!empty($batch)) {
            fwrite($fh, 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . "`) VALUES\n");
            fwrite($fh, implode(",\n", $batch) . ";\n\n");
        }
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($fh, "-- End of backup\n");
    fclose($fh);

    // Prune oldest backups
    $files = glob($dir . '/steward-backup-*.sql');
    if ($files) {
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, $retain) as $old) {
            unlink($old);
        }
    }

    setSetting('backup_last_run', date('Y-m-d H:i:s'));

    return ['ok' => true, 'file' => basename($filename), 'size' => filesize($filename)];
}

function verifyMigrationDdl(string $sql, PDO $db): array {
    $issues = [];
    $dbName = $db->query('SELECT DATABASE()')->fetchColumn();

    // Verify every ADD [COLUMN] actually exists in the schema
    if (preg_match_all(
        '/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?`?(\w+)`?/i',
        $sql, $matches, PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$dbName, $m[1], $m[2]]);
            if (!(int)$stmt->fetchColumn()) {
                $issues[] = "Column `{$m[1]}`.`{$m[2]}` missing after migration — DDL did not apply.";
            }
        }
    }

    // Verify every CREATE TABLE actually exists
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i',
        $sql, $matches
    )) {
        foreach ($matches[1] as $table) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmt->execute([$dbName, $table]);
            if (!(int)$stmt->fetchColumn()) {
                $issues[] = "Table `$table` missing after migration — DDL did not apply.";
            }
        }
    }

    return $issues;
}

function runMigration(int $version, string $path): array {
    $db  = getDB();
    $sql = @file_get_contents($path);
    if ($sql === false) {
        return ['ok' => false, 'errors' => ['Could not read migration file']];
    }
    // Benign MySQL error codes — change is already present
    $benign     = [1060, 1050, 1061, 1091]; // dup col, table exists, dup key, can't drop
    // Strip single-line comments before splitting so semicolons inside -- comments
    // don't produce phantom statements (e.g. "-- note (Bitcoin); 8 decimal…").
    $stripped   = preg_replace('/--[^\r\n]*/', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $stripped)));
    $errors     = [];
    foreach ($statements as $stmt) {
        try {
            $db->exec($stmt);
        } catch (PDOException $e) {
            if (!in_array((int)($e->errorInfo[1] ?? 0), $benign, true)) {
                $errors[] = $e->getMessage();
            }
        }
    }
    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }
    // Verify DDL changes actually landed before recording as applied
    $verifyErrors = verifyMigrationDdl($sql, $db);
    if (!empty($verifyErrors)) {
        return ['ok' => false, 'errors' => $verifyErrors];
    }
    try {
        $db->prepare('INSERT IGNORE INTO schema_migrations (version, filename, applied_at) VALUES (?, ?, NOW())')
           ->execute([$version, basename($path)]);
    } catch (PDOException $e) {}
    return ['ok' => true];
}

// ── Scheduled bills ────────────────────────────────────────────

// $dueDay is the bill's day of month (scheduled_bills.due_day). Month-based frequencies
// land on that day, clamped to the month's length, so Jan 31 → Feb 28 → Mar 31 instead
// of drifting. Without it, the day of $date is used.
function advanceDueDate(string $date, string $frequency, ?int $dueDay = null): ?string {
    $ts = strtotime($date);
    $monthStep = ['monthly' => 1, 'bimonthly' => 2, 'quarterly' => 3, 'yearly' => 12][$frequency] ?? 0;
    if ($monthStep) {
        $day   = $dueDay ?: (int)date('j', $ts);
        $first = mktime(0, 0, 0, (int)date('n', $ts) + $monthStep, 1, (int)date('Y', $ts));
        return date('Y-m-', $first) . sprintf('%02d', min($day, (int)date('t', $first)));
    }
    if ($frequency === 'twice_monthly') {
        $day     = (int)date('j', $ts);
        $year    = (int)date('Y', $ts);
        $month   = (int)date('n', $ts);
        $lastDay = (int)date('t', $ts);
        if ($day < 15) {
            return sprintf('%04d-%02d-15', $year, $month);
        } elseif ($day === 15) {
            return sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
        } else {
            return date('Y-m-15', strtotime('+1 month', mktime(0, 0, 0, $month, 1, $year)));
        }
    }
    return match ($frequency) {
        'weekly'    => date('Y-m-d', strtotime('+1 week',   $ts)),
        'biweekly'  => date('Y-m-d', strtotime('+2 weeks',  $ts)),
        default     => null,
    };
}

// Apply timezone from DB settings (overrides the default in config/app.php)
(function () {
    $tz = getSetting('timezone');
    if ($tz && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($tz);
    }
})();

// Relative date-range presets (dr=<token>) — must run after the timezone is set
require_once __DIR__ . '/date_presets.php';
applyDateRangePreset();

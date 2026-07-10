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

/** Format a crypto/investment quantity with up to 10 significant decimal places. */
function formatQty(float $qty, bool $crypto = false): string {
    $decimals = $crypto ? 10 : 6;
    return rtrim(rtrim(number_format($qty, $decimals, '.', ','), '0'), '.');
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
                    SUM(ABS(ts.amount)) AS total
             FROM transaction_splits ts
             JOIN transactions t  ON t.id  = ts.transaction_id
             JOIN categories c    ON c.id  = ts.category_id
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE t.type IN ('withdrawal','transfer')
               AND c.type != 'transfer'
               AND t.amount < 0
               AND YEAR(t.transaction_date)  = ?
               AND MONTH(t.transaction_date) = ?
               AND t.account_id IN ($ph)
             GROUP BY ts.category_id
             ORDER BY total DESC"
        );
        $stmt->execute([$year, $month, ...$accountIds]);
    } else {
        $stmt = $db->prepare(
            "SELECT c.name AS category, COALESCE(p.name, c.name) AS parent_name,
                    SUM(ABS(ts.amount)) AS total
             FROM transaction_splits ts
             JOIN transactions t  ON t.id  = ts.transaction_id
             JOIN categories c    ON c.id  = ts.category_id
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE t.type IN ('withdrawal','transfer')
               AND c.type != 'transfer'
               AND t.amount < 0
               AND YEAR(t.transaction_date)  = ?
               AND MONTH(t.transaction_date) = ?
             GROUP BY ts.category_id
             ORDER BY total DESC"
        );
        $stmt->execute([$year, $month]);
    }
    return $stmt->fetchAll();
}

// ── Budget ─────────────────────────────────────────────────────

function getBudgetMonthlyAmount(array $bc, int $month, array $monthlyAmtMap): float {
    return match($bc['entry_type']) {
        'annual'   => (float)$bc['amount'] / 12,
        'variable' => (float)($monthlyAmtMap[(int)$bc['id']][$month] ?? 0),
        default    => (float)$bc['amount'],
    };
}

function getBudgetDashboardItems(): array {
    $today     = new DateTime();
    $startDate = $today->format('Y-m-01');
    $endDate   = $today->format('Y-m-t');
    $month     = (int)$today->format('n');
    $db        = getDB();

    $budget = $db->query(
        "SELECT id FROM budgets WHERE show_on_dashboard = 1 AND is_active = 1 ORDER BY id LIMIT 1"
    )->fetch();
    if (!$budget) return [];
    $budgetId = (int)$budget['id'];

    $stmt = $db->prepare(
        "SELECT bc.id, bc.category_id, bc.entry_type, bc.amount, c.name, c.type AS category_type
         FROM budget_categories bc
         JOIN categories c ON c.id = bc.category_id
         WHERE bc.budget_id = ? AND bc.show_on_dashboard = 1
         ORDER BY FIELD(c.type,'income','expense','transfer'), c.name"
    );
    $stmt->execute([$budgetId]);
    $items = $stmt->fetchAll();
    if (empty($items)) return [];

    // Fetch variable monthly amounts for this month
    $bcIds = array_column($items, 'id');
    $phs   = implode(',', array_fill(0, count($bcIds), '?'));
    $mAmt  = $db->prepare(
        "SELECT budget_category_id, amount FROM budget_monthly_amounts
         WHERE budget_category_id IN ($phs) AND month = ?"
    );
    $mAmt->execute([...$bcIds, $month]);
    $monthlyAmtMap = [];
    foreach ($mAmt->fetchAll() as $r) {
        $monthlyAmtMap[(int)$r['budget_category_id']][$month] = (float)$r['amount'];
    }

    // Fetch actuals from budget accounts
    $acctIds = array_column(
        $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?")
           ->execute([$budgetId]) ? [] : [],
        'account_id'
    );
    $acctStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
    $acctStmt->execute([$budgetId]);
    $acctIds = array_column($acctStmt->fetchAll(), 'account_id');

    $actualMap = [];
    if (!empty($acctIds)) {
        $catIds  = array_column($items, 'category_id');
        $catSet  = array_flip($catIds);
        $aPhs    = implode(',', array_fill(0, count($acctIds), '?'));
        $cPhs    = implode(',', array_fill(0, count($catIds), '?'));
        $actStmt = $db->prepare(
            "SELECT ts.category_id,
                    COALESCE(ts.subcategory_id, ts.category_id) AS eff_cat_id,
                    ABS(SUM(ts.amount)) AS actual
             FROM transaction_splits ts
             JOIN transactions t ON t.id = ts.transaction_id
             WHERE t.transaction_date BETWEEN ? AND ?
               AND t.account_id IN ($aPhs)
               AND (ts.category_id IN ($cPhs) OR ts.subcategory_id IN ($cPhs))
             GROUP BY ts.category_id, COALESCE(ts.subcategory_id, ts.category_id)"
        );
        $actStmt->execute([$startDate, $endDate, ...$acctIds, ...$catIds, ...$catIds]);
        foreach ($actStmt->fetchAll() as $row) {
            $eid = (int)$row['eff_cat_id'];
            $cid = (int)$row['category_id'];
            $key = isset($catSet[$eid]) ? $eid : (isset($catSet[$cid]) ? $cid : null);
            if ($key !== null) {
                $actualMap[$key] = ($actualMap[$key] ?? 0.0) + (float)$row['actual'];
            }
        }
    }

    foreach ($items as &$item) {
        $budgeted          = getBudgetMonthlyAmount($item, $month, $monthlyAmtMap);
        $actual            = $actualMap[(int)$item['category_id']] ?? 0;
        $item['budgeted']  = $budgeted;
        $item['actual']    = $actual;
        $item['remaining'] = $budgeted > 0 ? $budgeted - $actual : null;
        $item['raw_pct']   = $budgeted > 0 ? ($actual / $budgeted) * 100 : null;
        $item['pct']       = $item['raw_pct'] !== null ? min($item['raw_pct'], 100) : null;
    }
    unset($item);
    return $items;
}

// ── Portfolio / Investments ────────────────────────────────────

/**
 * Returns market value (price × net shares) per Investment account.
 * Only includes securities that have a latest price on record.
 * Falls back to cost-basis price for securities without a market price.
 */
function getInvestmentAccountMarketValues(): array {
    $db = getDB();

    // Net quantity held per account + investment
    $rows = $db->query(
        'SELECT t.account_id, it.investment_id,
                SUM(CASE
                    WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
                    WHEN it.activity IN (\'sell\',\'remove\')                                        THEN -it.quantity
                    ELSE 0
                END) AS net_qty,
                SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'reinvest_div\',\'reinvest_cap\') THEN it.quantity * it.price ELSE 0 END) /
                NULLIF(SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'reinvest_div\',\'reinvest_cap\') THEN it.quantity ELSE 0 END), 0)
                    AS avg_cost_price
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN accounts a     ON a.id = t.account_id
         WHERE a.type IN (\'Investment\',\'Crypto\') AND a.is_investment_cash = 0
         GROUP BY t.account_id, it.investment_id'
    )->fetchAll();

    if (empty($rows)) return [];

    $latestPrices = getLatestInvestmentPrices();

    $values = [];
    foreach ($rows as $row) {
        $accountId    = (int)$row['account_id'];
        $investmentId = (int)$row['investment_id'];
        $qty          = (float)$row['net_qty'];
        // Seed the account in the map even if qty is zero, so callers don't fall
        // back to the raw transaction-sum for accounts with no current holdings.
        if (!isset($values[$accountId])) $values[$accountId] = 0.0;
        if ($qty > 0.000001) {
            $price = $latestPrices[$investmentId]['price'] ?? (float)($row['avg_cost_price'] ?? 0);
            $values[$accountId] += $price * $qty;
        }
    }
    return $values;
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

function getLatestInvestmentPrices(): array {
    try {
        $rows = getDB()->query(
            'SELECT investment_id, close_price, price_date, source, prev_close
             FROM (
                 SELECT investment_id, close_price, price_date, source,
                        LAG(close_price) OVER (PARTITION BY investment_id ORDER BY price_date) AS prev_close,
                        ROW_NUMBER()     OVER (PARTITION BY investment_id ORDER BY price_date DESC) AS rn
                 FROM investment_prices
             ) ranked
             WHERE rn = 1'
        )->fetchAll();
    } catch (Exception $e) {
        return [];
    }
    $prices = [];
    foreach ($rows as $row) {
        $prices[(int)$row['investment_id']] = [
            'price'      => (float)$row['close_price'],
            'price_date' => $row['price_date'],
            'source'     => $row['source'],
            'prev_close' => $row['prev_close'] !== null ? (float)$row['prev_close'] : null,
        ];
    }
    return $prices;
}

function getInvestmentCostBases(): array {
    try {
        $rows = getDB()->query(
            'SELECT it.investment_id,
                    SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN it.quantity                           ELSE 0 END) AS buy_qty,
                    SUM(CASE WHEN it.activity IN (\'buy\',\'add\',\'reinvest_div\',\'reinvest_cap\')        THEN it.quantity * it.price + it.commission ELSE 0 END) AS buy_cost
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             JOIN accounts a     ON a.id = t.account_id
             WHERE a.is_investment_cash = 0
             GROUP BY it.investment_id'
        )->fetchAll();
    } catch (Exception $e) {
        return [];
    }
    $bases = [];
    foreach ($rows as $row) {
        $buyQty = (float)$row['buy_qty'];
        $bases[(int)$row['investment_id']] = [
            'avg_cost' => $buyQty > 0 ? (float)$row['buy_cost'] / $buyQty : 0.0,
            'buy_cost' => (float)$row['buy_cost'],
        ];
    }
    return $bases;
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
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, array_values($row));
    fclose($out);
    exit;
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
function getHistoricalInvestmentMarketValues(array $accounts, array $points): array {
    $db = getDB();
    $investAccts   = array_filter($accounts, fn($a) => isInvestLike($a['type']) && !$a['is_investment_cash']);
    $investAcctIds = array_column(array_values($investAccts), 'id');
    $histMktValues = []; // point -> [acct_id -> float]
    if (empty($investAcctIds)) return $histMktValues;

    $iph   = implode(',', array_fill(0, count($investAcctIds), '?'));
    $iStmt = $db->prepare(
        "SELECT t.account_id, it.investment_id, it.activity, it.quantity, it.price, t.transaction_date
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         WHERE t.account_id IN ($iph)
         ORDER BY t.transaction_date"
    );
    $iStmt->execute($investAcctIds);
    $iTxns  = $iStmt->fetchAll();
    $invIds = array_values(array_unique(array_column($iTxns, 'investment_id')));

    $priceHist = [];
    if (!empty($invIds)) {
        $pph   = implode(',', array_fill(0, count($invIds), '?'));
        $pStmt = $db->prepare(
            "SELECT investment_id, price_date, close_price
             FROM investment_prices WHERE investment_id IN ($pph)
             ORDER BY investment_id, price_date"
        );
        $pStmt->execute($invIds);
        foreach ($pStmt->fetchAll() as $p) {
            $priceHist[(int)$p['investment_id']][] = [$p['price_date'], (float)$p['close_price']];
        }
    }

    $priceAtPoint = [];
    foreach ($priceHist as $iid => $plist) {
        $latest = 0.0; $pi = 0; $np = count($plist);
        foreach ($points as $pt) {
            while ($pi < $np && $plist[$pi][0] <= $pt) { $latest = $plist[$pi][1]; $pi++; }
            $priceAtPoint[$iid][$pt] = $latest;
        }
    }

    // Fallback: forward-fill last transaction price for investments with no market data
    $noPriceSet = array_flip(array_diff($invIds, array_keys($priceHist)));
    if (!empty($noPriceSet)) {
        $txnPricesByInv = [];
        foreach ($iTxns as $row) {
            $iid = (int)$row['investment_id'];
            if (!isset($noPriceSet[$iid])) continue;
            $p = (float)$row['price'];
            if ($p > 0) $txnPricesByInv[$iid][] = [$row['transaction_date'], $p];
        }
        foreach ($txnPricesByInv as $iid => $plist) {
            $latest = 0.0; $pi = 0; $np = count($plist);
            foreach ($points as $pt) {
                while ($pi < $np && $plist[$pi][0] <= $pt) { $latest = $plist[$pi][1]; $pi++; }
                if ($latest > 0) $priceAtPoint[$iid][$pt] = $latest;
            }
        }
    }

    $holdings = [];
    $ti = 0; $nt = count($iTxns);
    foreach ($points as $pt) {
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
                $mv += $qty * ($priceAtPoint[$iid][$pt] ?? 0.0);
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
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') AS ym,
                SUM(CASE WHEN t.amount > 0 THEN t.amount  ELSE 0 END) AS income,
                SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END) AS expenses
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         WHERE a.type NOT IN ('Investment','Crypto','investment-cash')
           AND t.type NOT IN ('transfer','investment')
           AND t.transaction_date >= ?
         GROUP BY ym ORDER BY ym"
    );
    $stmt->execute([$from]);
    $rows = $stmt->fetchAll();

    // Build full month list so gaps show as zero
    $labels = []; $income = []; $expenses = [];
    $byYm   = array_column($rows, null, 'ym');
    $cur    = new DateTime($from);
    $end    = new DateTime(date('Y-m-01'));
    while ($cur <= $end) {
        $ym        = $cur->format('Y-m');
        $labels[]  = $cur->format('M y');
        $income[]  = round((float)($byYm[$ym]['income']   ?? 0), 2);
        $expenses[] = round((float)($byYm[$ym]['expenses'] ?? 0), 2);
        $cur->modify('+1 month');
    }
    return ['labels' => $labels, 'income' => $income, 'expenses' => $expenses];
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
        "SELECT c.name AS category, SUM(ABS(ts.amount)) AS total
         FROM transaction_splits ts
         JOIN transactions t ON t.id  = ts.transaction_id
         JOIN categories c   ON c.id  = ts.category_id
         JOIN accounts a     ON a.id  = t.account_id
         WHERE t.type = 'withdrawal'
           AND c.type != 'transfer'
           AND t.amount < 0
           AND a.is_investment_cash = 0
           AND $dateSql
           $acctSql
         GROUP BY ts.category_id
         ORDER BY total DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getDashboardPortfolioSnapshot(): array {
    try {
        $rows = getDB()->query(
            "SELECT i.id, i.name, i.symbol, i.type, i.country,
                    p.price, p.prev_close, p.price_date, p.volume,
                    COALESCE(h.qty, 0) AS qty
             FROM investments i
             INNER JOIN (
                 SELECT investment_id,
                        close_price AS price,
                        price_date,
                        COALESCE(volume, 0) AS volume,
                        LAG(close_price) OVER (PARTITION BY investment_id ORDER BY price_date) AS prev_close,
                        ROW_NUMBER()     OVER (PARTITION BY investment_id ORDER BY price_date DESC) AS rn
                 FROM investment_prices
             ) p ON p.investment_id = i.id AND p.rn = 1
             LEFT JOIN (
                 SELECT it.investment_id,
                        SUM(CASE
                            WHEN it.activity IN ('buy','add','split','reinvest_div','reinvest_cap') THEN  it.quantity
                            WHEN it.activity IN ('sell','remove')                                   THEN -it.quantity
                            ELSE 0
                        END) AS qty
                 FROM investment_transactions it
                 JOIN transactions t ON t.id = it.transaction_id
                 JOIN accounts a     ON a.id = t.account_id
                 WHERE a.is_investment_cash = 0
                 GROUP BY it.investment_id
             ) h ON h.investment_id = i.id
             WHERE i.is_active = 1
             ORDER BY i.type, i.name"
        )->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    return array_map(function (array $r): array {
        $price     = (float)$r['price'];
        $prevClose = $r['prev_close'] !== null ? (float)$r['prev_close'] : null;
        $qty       = (float)$r['qty'];
        $dayChg    = $prevClose !== null ? round($price - $prevClose, 4) : null;
        $dayChgPct = ($dayChg !== null && $prevClose != 0)
            ? round($dayChg / $prevClose * 100, 2) : null;
        $mktVal    = round($qty * $price, 2);
        $valChg    = $dayChg !== null ? round($qty * $dayChg, 2) : null;
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'symbol'      => $r['symbol'],
            'type'        => $r['type'],
            'country'     => $r['country'],
            'price'       => $price,
            'prev_close'  => $prevClose,
            'price_date'  => $r['price_date'],
            'volume'      => (float)$r['volume'],
            'qty'         => $qty,
            'day_chg'     => $dayChg,
            'day_chg_pct' => $dayChgPct,
            'mkt_val'     => $mktVal,
            'val_chg'     => $valChg,
        ];
    }, $rows);
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

function advanceDueDate(string $date, string $frequency): ?string {
    $ts = strtotime($date);
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
        'monthly'   => date('Y-m-d', strtotime('+1 month',  $ts)),
        'bimonthly' => date('Y-m-d', strtotime('+2 months', $ts)),
        'quarterly' => date('Y-m-d', strtotime('+3 months', $ts)),
        'yearly'    => date('Y-m-d', strtotime('+1 year',   $ts)),
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

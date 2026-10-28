<?php
/**
 * Budget math shared by the budget view, Budget vs. Actual, the budget list
 * and the dashboard widget.
 *
 * loadBudgetModel() returns every line of a budget with its monthly targets,
 * signed monthly actuals, pacing and projection for one calendar year, plus the
 * untracked activity in the budget's accounts. All amounts are "in the line's
 * direction": spending for expense lines, earnings for income lines. A month
 * where refunds outweigh spending comes out negative.
 *
 * Targets by entry type:
 *   monthly  — amount every month
 *   variable — budget_monthly_amounts for each month
 *   annual   — amount split evenly across due_months; with no due months there
 *              is no monthly target and pacing is linear (amount × months / 12)
 *
 * Rollover (monthly and variable lines with budget_categories.rollover): the
 * month's available amount is its target plus what was left over (or minus
 * what was overspent) in earlier months of the same calendar year. Annual
 * totals and pacing don't change, since carries net out within the year.
 */

/**
 * formatMoney() for a budget amount that may be negative (a net refund):
 * negatives get the `neg` class so they read as negative in every
 * negative-number format, including the default color-only one.
 */
function budgetMoney(float $amount): string {
    $s = formatMoney($amount);
    return round($amount, MONEY_DECIMALS) < 0 ? '<span class="neg">' . $s . '</span>' : $s;
}

/** Parse budget_categories.due_months ("3,9") into sorted unique months 1–12. */
function parseDueMonths(?string $raw): array {
    if ($raw === null || trim($raw) === '') return [];
    $months = array_filter(array_map('intval', explode(',', $raw)), fn($m) => $m >= 1 && $m <= 12);
    $months = array_values(array_unique($months));
    sort($months);
    return $months;
}

/** Normalize posted due months (array or "3,9") to the stored form, or null for none. */
function formatDueMonths($raw): ?string {
    $list = is_array($raw) ? implode(',', $raw) : (string)$raw;
    $months = parseDueMonths($list);
    return $months ? implode(',', $months) : null;
}

/** Monthly targets [1..12] for one budget line. */
function budgetLineTargets(string $entryType, float $amount, array $dueMonths, array $monthMap): array {
    $t = array_fill(1, 12, 0.0);
    if ($entryType === 'monthly') {
        foreach ($t as $m => $_) $t[$m] = $amount;
    } elseif ($entryType === 'variable') {
        foreach ($t as $m => $_) $t[$m] = (float)($monthMap[$m] ?? 0);
    } elseif ($dueMonths) {
        $share = $amount / count($dueMonths);
        foreach ($dueMonths as $m) $t[$m] = $share;
    }
    return $t;
}

/**
 * Pace and bar metrics for a line or a total.
 *
 * $c keys: mon_budget, annual_budget, expected_to_date. Actuals are signed, in
 * the line's direction. Pace compares YTD actual with the scheduled
 * expected-to-date; paceDiff is in percent of the annual budget and positive
 * always means "bad" (overspent / under-earned).
 */
function budgetBarClass(?float $rawPct, bool $isIncome): string {
    if ($rawPct === null) return 'bar-none';
    if ($isIncome) return $rawPct >= 100 ? 'bar-ok' : ($rawPct >= 80 ? 'bar-warn' : 'bar-over');
    return $rawPct > 100 ? 'bar-over' : ($rawPct >= 80 ? 'bar-warn' : 'bar-ok');
}

function computeBudgetRow(array $c, float $monActual, float $ytdActual, bool $isIncome): array {
    $monBudget    = (float)$c['mon_budget'];
    $annualBudget = (float)$c['annual_budget'];
    $expected     = (float)$c['expected_to_date'];

    $monDiff         = round($isIncome ? ($monActual - $monBudget) : ($monBudget - $monActual), MONEY_DECIMALS);
    $annualRemaining = round($annualBudget - $ytdActual, MONEY_DECIMALS);

    $pctExpected   = $annualBudget > 0 ? ($expected  / $annualBudget * 100) : null;
    $pctAnnualUsed = $annualBudget > 0 ? ($ytdActual / $annualBudget * 100) : null;

    $paceDiff = $pctAnnualUsed !== null
        ? ($isIncome ? ($pctExpected - $pctAnnualUsed) : ($pctAnnualUsed - $pctExpected))
        : null;

    if ($paceDiff === null) {
        $paceLabel = null; $paceCls = 'bv-pace-none';
    } elseif ($paceDiff > 15) {
        $paceLabel = $isIncome ? 'Behind Pace' : 'Over Pace';         $paceCls = 'bv-pace-bad';
    } elseif ($paceDiff > 5) {
        $paceLabel = $isIncome ? 'Slightly Behind' : 'Slightly Over'; $paceCls = 'bv-pace-warn';
    } else {
        $paceLabel = 'On Track'; $paceCls = 'bv-pace-good';
    }

    $monRawPct = $monBudget > 0 ? ($monActual / $monBudget * 100) : null;
    $yrRawPct  = $annualBudget > 0 ? ($ytdActual / $annualBudget * 100) : null;

    return [
        'monBudget'       => $monBudget,
        'annualBudget'    => $annualBudget,
        'expected'        => $expected,
        'monDiff'         => $monDiff,
        'annualRemaining' => $annualRemaining,
        'pctExpected'     => $pctExpected,
        'pctAnnualUsed'   => $pctAnnualUsed,
        'paceDiff'        => $paceDiff,
        'paceLabel'       => $paceLabel,
        'paceCls'         => $paceCls,
        'monRawPct'       => $monRawPct,
        'monBarCls'       => budgetBarClass($monRawPct, $isIncome),
        'yrRawPct'        => $yrRawPct,
        'yrBarCls'        => match ($paceCls) {
            'bv-pace-bad'  => 'bar-over',
            'bv-pace-warn' => 'bar-warn',
            'bv-pace-good' => 'bar-ok',
            default        => 'bar-none',
        },
    ];
}

/**
 * Scheduled bill/deposit amounts per category and month, for occurrences from
 * $fromDate through the end of $year in the given accounts. Keyed by the bill's
 * leaf category (subcategory, else category) → [month => positive amount].
 */
function budgetScheduledByCategory(array $acctIds, int $year, string $fromDate): array {
    if (empty($acctIds)) return [];
    $aPhs = implode(',', array_fill(0, count($acctIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT type, category_id, subcategory_id, amount, frequency, next_due_date, due_day
         FROM scheduled_bills
         WHERE is_active = 1 AND type IN ('bill','deposit')
           AND COALESCE(subcategory_id, category_id) IS NOT NULL
           AND account_id IN ($aPhs)"
    );
    $stmt->execute($acctIds);
    $yearEnd = sprintf('%04d-12-31', $year);
    $out = [];
    foreach ($stmt->fetchAll() as $b) {
        $leaf = (int)($b['subcategory_id'] ?? $b['category_id']);
        $date = $b['next_due_date'];
        while ($date <= $yearEnd) {
            if ($date >= $fromDate && (int)substr($date, 0, 4) === $year) {
                $m = (int)substr($date, 5, 2);
                $out[$leaf][$m] = ($out[$leaf][$m] ?? 0.0) + (float)$b['amount'];
            }
            if ($b['frequency'] === 'once') break;
            $next = advanceDueDate($date, $b['frequency'], $b['due_day'] !== null ? (int)$b['due_day'] : null);
            if (!$next || $next <= $date) break;
            $date = $next;
        }
    }
    return $out;
}

/**
 * Which line a split belongs to: its leaf category (subcategory, else
 * category), else the leaf's parent, else the split's own category; null when
 * none is budgeted (untracked). $lineByCat maps category_id → line key.
 */
function budgetLineForSplit(array $lineByCat, int $categoryId, int $leafId, ?int $leafParent) {
    return $lineByCat[$leafId]
        ?? ($leafParent !== null ? ($lineByCat[$leafParent] ?? null) : null)
        ?? $lineByCat[$categoryId]
        ?? null;
}

/**
 * Raw signed split totals for $year in the given accounts:
 * [line key → [month → sum], untracked ts.category_id → [month → sum]].
 */
function budgetSplitTotals(array $acctIds, array $lineByCat, int $year): array {
    $actuals = []; $untracked = [];
    if (!$acctIds) return [$actuals, $untracked];
    $aPhs = implode(',', array_fill(0, count($acctIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT ts.category_id, COALESCE(ts.subcategory_id, ts.category_id) AS leaf_id,
                lc.parent_id AS leaf_parent, MONTH(t.transaction_date) AS m, SUM(ts.amount) AS total
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         LEFT JOIN categories lc ON lc.id = COALESCE(ts.subcategory_id, ts.category_id)
         WHERE t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)
           AND ts.category_id IS NOT NULL
         GROUP BY ts.category_id, leaf_id, lc.parent_id, m"
    );
    $stmt->execute([sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), ...$acctIds]);
    foreach ($stmt->fetchAll() as $s) {
        $m    = (int)$s['m'];
        $line = budgetLineForSplit($lineByCat, (int)$s['category_id'], (int)$s['leaf_id'],
                                   $s['leaf_parent'] !== null ? (int)$s['leaf_parent'] : null);
        if ($line !== null) {
            $actuals[$line][$m] = ($actuals[$line][$m] ?? 0.0) + (float)$s['total'];
        } else {
            $cid = (int)$s['category_id'];
            $untracked[$cid][$m] = ($untracked[$cid][$m] ?? 0.0) + (float)$s['total'];
        }
    }
    return [$actuals, $untracked];
}

/**
 * The full budget model for $year, measured through $month.
 *
 * Options:
 *   with_untracked  (bool, default true)  — untracked activity rows
 *   with_inv_income (bool, default false) — investment income rows (div/int/reinvest)
 *   with_last_year  (bool, default false) — ly_actuals / ly_ytd per line (same
 *                   lines and accounts, previous calendar year)
 *   today           (string Y-m-d)        — for tests; defaults to today
 *
 * Returns null when the budget doesn't exist, else:
 *   budget, acct_ids, year, month, is_current_month,
 *   lines (all), income, expense (lines by category type),
 *   totals => [income => row, expense => row]  (see budgetTotals()),
 *   untracked => [income => [...], expense => [...]], inv_income => [...]
 * Each line: bc_id, category_id, name, display_name, parent_id, parent_name,
 *   cat_type, is_income, entry_type, amount, due_months, rollover,
 *   show_on_dashboard, targets[1..12], actuals[1..12], scheduled[1..12],
 *   projected[1..12], ly_actuals[1..12], ly_ytd, mon_budget, carry_in, mon_available, annual_budget,
 *   expected_to_date, mon_actual, ytd_actual, projected_total,
 *   metrics (computeBudgetRow(), measured against mon_available), alert.
 * `alerts` lists the lines with an alert, most severe first (budgetLineAlert()).
 */
function loadBudgetModel(int $budgetId, int $year, int $month, array $opts = []): ?array {
    $db    = getDB();
    $month = max(1, min(12, $month));
    $today = $opts['today'] ?? date('Y-m-d');
    $withUntracked = $opts['with_untracked'] ?? true;
    $withInvIncome = $opts['with_inv_income'] ?? false;
    $withLastYear  = $opts['with_last_year'] ?? false;

    $bStmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
    $bStmt->execute([$budgetId]);
    $budget = $bStmt->fetch();
    if (!$budget) return null;

    $aStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
    $aStmt->execute([$budgetId]);
    $acctIds = array_map('intval', array_column($aStmt->fetchAll(), 'account_id'));

    $cStmt = $db->prepare(
        "SELECT bc.id AS bc_id, bc.category_id, bc.entry_type, bc.amount, bc.due_months,
                bc.rollover, bc.show_on_dashboard,
                c.name, c.type AS cat_type, c.parent_id, p.name AS parent_name
         FROM budget_categories bc
         JOIN categories c ON c.id = bc.category_id
         LEFT JOIN categories p ON p.id = c.parent_id
         WHERE bc.budget_id = ?
         ORDER BY c.type, COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name"
    );
    $cStmt->execute([$budgetId]);
    $rows = $cStmt->fetchAll();

    $monthMaps = [];
    if ($rows) {
        $ids  = array_column($rows, 'bc_id');
        $phs  = implode(',', array_fill(0, count($ids), '?'));
        $mStmt = $db->prepare(
            "SELECT budget_category_id, month, amount FROM budget_monthly_amounts WHERE budget_category_id IN ($phs)"
        );
        $mStmt->execute($ids);
        foreach ($mStmt->fetchAll() as $r) {
            $monthMaps[(int)$r['budget_category_id']][(int)$r['month']] = (float)$r['amount'];
        }
    }

    // ── Actuals and untracked activity (budgetSplitTotals()) ──
    $lineByCat = [];
    foreach ($rows as $i => $r) $lineByCat[(int)$r['category_id']] = $i;
    [$actuals, $untracked] = budgetSplitTotals($acctIds, $lineByCat, $year);
    if (!$withUntracked) $untracked = [];
    $lyActuals = $withLastYear ? budgetSplitTotals($acctIds, $lineByCat, $year - 1)[0] : [];

    // ── Scheduled bills/deposits (projection floor, current month only) ──
    $isCurrentMonth = $year === (int)substr($today, 0, 4) && $month === (int)substr($today, 5, 2);
    $schedByLine = [];
    if ($isCurrentMonth && $rows) {
        foreach (budgetScheduledByCategory($acctIds, $year, $today) as $leaf => $byMonth) {
            $line = $lineByCat[$leaf] ?? null;
            if ($line === null) {
                $parent = $db->prepare("SELECT parent_id FROM categories WHERE id = ?");
                $parent->execute([$leaf]);
                $pid  = $parent->fetchColumn();
                $line = $pid ? ($lineByCat[(int)$pid] ?? null) : null;
            }
            if ($line === null) continue;
            foreach ($byMonth as $m => $amt) $schedByLine[$line][$m] = ($schedByLine[$line][$m] ?? 0.0) + $amt;
        }
    }

    // ── Lines ──
    $lines = [];
    foreach ($rows as $i => $r) {
        $isIncome  = $r['cat_type'] === 'income';
        $sign      = $isIncome ? 1 : -1;
        $due       = parseDueMonths($r['due_months']);
        $amount    = (float)$r['amount'];
        $targets   = budgetLineTargets($r['entry_type'], $amount, $due, $monthMaps[(int)$r['bc_id']] ?? []);
        $linear    = $r['entry_type'] === 'annual' && !$due;
        $annual    = $linear ? $amount : array_sum($targets);
        $expected  = $linear ? $amount * $month / 12 : array_sum(array_slice($targets, 0, $month, true));

        $act = array_fill(1, 12, 0.0);
        foreach ($actuals[$i] ?? [] as $m => $v) $act[$m] = round($sign * $v, MONEY_DECIMALS);
        $ly = array_fill(1, 12, 0.0);
        foreach ($lyActuals[$i] ?? [] as $m => $v) $ly[$m] = round($sign * $v, MONEY_DECIMALS);
        $sched = array_fill(1, 12, 0.0);
        foreach ($schedByLine[$i] ?? [] as $m => $v) $sched[$m] = $v;
        $ytd = array_sum(array_slice($act, 0, $month, true));

        // Projection: actuals through the selected month, the plan after it.
        // Viewing the current month, the rest of this month and each later
        // month are floored at scheduled bills/deposits. Annual lines without
        // due months spread whatever is left of the annual amount over the
        // remaining months.
        $proj = [];
        for ($m = 1; $m <= 12; $m++) {
            if ($m < $month || ($m === $month && !$isCurrentMonth)) $proj[$m] = $act[$m];
            elseif ($m === $month) $proj[$m] = max($targets[$m], $act[$m] + $sched[$m]);
            else                   $proj[$m] = max($targets[$m], $sched[$m]);
        }
        if ($linear && $month < 12) {
            $left = max(0.0, $amount - array_sum($proj));
            for ($m = $month + 1; $m <= 12; $m++) $proj[$m] += $left / (12 - $month);
        }

        // Envelope rollover: carry earlier months' leftover (or overspend) forward
        $rolls = (bool)$r['rollover'] && $r['entry_type'] !== 'annual';
        $carry = 0.0;
        if ($rolls) {
            for ($m = 1; $m < $month; $m++) $carry += $targets[$m] - $act[$m];
            $carry = round($carry, MONEY_DECIMALS);
        }

        $line = [
            'bc_id'             => (int)$r['bc_id'],
            'category_id'       => (int)$r['category_id'],
            'name'              => $r['name'],
            'display_name'      => $r['parent_name'] ? $r['parent_name'] . ': ' . $r['name'] : $r['name'],
            'parent_id'         => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'parent_name'       => $r['parent_name'],
            'cat_type'          => $r['cat_type'],
            'is_income'         => $isIncome,
            'entry_type'        => $r['entry_type'],
            'amount'            => $amount,
            'due_months'        => $due,
            'rollover'          => $rolls,
            'show_on_dashboard' => (bool)$r['show_on_dashboard'],
            'targets'           => $targets,
            'actuals'           => $act,
            'scheduled'         => $sched,
            'projected'         => $proj,
            'ly_actuals'        => $ly,
            'ly_ytd'            => array_sum(array_slice($ly, 0, $month, true)),
            'mon_budget'        => $targets[$month],
            'carry_in'          => $carry,
            'mon_available'     => $targets[$month] + $carry,
            'annual_budget'     => $annual,
            'expected_to_date'  => $expected,
            'mon_actual'        => $act[$month],
            'ytd_actual'        => $ytd,
            'projected_total'   => array_sum($proj),
        ];
        $line['metrics'] = computeBudgetRow(['mon_budget' => $line['mon_available']] + $line, $line['mon_actual'], $ytd, $isIncome);
        $line['alert']   = budgetLineAlert($line, $month);
        $lines[] = $line;
    }

    $alerts = array_values(array_filter($lines, fn($l) => $l['alert'] !== null));
    usort($alerts, fn($a, $b) => $a['alert']['rank'] <=> $b['alert']['rank']
                              ?: $b['alert']['amount'] <=> $a['alert']['amount']);

    $income  = array_values(array_filter($lines, fn($l) => $l['is_income']));
    $expense = array_values(array_filter($lines, fn($l) => !$l['is_income']));

    return [
        'budget'           => $budget,
        'acct_ids'         => $acctIds,
        'year'             => $year,
        'month'            => $month,
        'is_current_month' => $isCurrentMonth,
        'lines'            => $lines,
        'income'           => $income,
        'expense'          => $expense,
        'totals'           => ['income' => budgetTotals($income, true), 'expense' => budgetTotals($expense, false)],
        'alerts'           => $alerts,
        'untracked'        => $withUntracked ? budgetUntrackedRows($untracked, $month) : ['income' => [], 'expense' => []],
        'inv_income'       => $withInvIncome ? budgetInvestmentIncome($acctIds, $year, $month) : [],
    ];
}

/** Summed line fields plus computeBudgetRow() metrics for a group of lines. */
function budgetTotals(array $lines, bool $isIncome): array {
    $sum = fn(string $k) => array_sum(array_column($lines, $k));
    $t = [
        'mon_budget'       => $sum('mon_budget'),
        'carry_in'         => $sum('carry_in'),
        'mon_available'    => $sum('mon_available'),
        'annual_budget'    => $sum('annual_budget'),
        'expected_to_date' => $sum('expected_to_date'),
        'mon_actual'       => $sum('mon_actual'),
        'ytd_actual'       => $sum('ytd_actual'),
        'projected_total'  => $sum('projected_total'),
        'ly_ytd'           => $sum('ly_ytd'),
    ];
    $t['metrics'] = computeBudgetRow(['mon_budget' => $t['mon_available']] + $t, $t['mon_actual'], $t['ytd_actual'], $isIncome);
    return $t;
}

/**
 * The most severe alert for an expense line, or null. In order:
 *   over_annual — YTD spending past the annual budget
 *   early_90    — 90% of the annual budget used while the line's schedule
 *                 expects less than 75% by now
 *   over_pace   — more than 15 points of the annual budget ahead of schedule
 *   over_month  — this month's spending past this month's available amount
 *                 (lines with a monthly target or rollover only)
 * Returns [kind, level (danger|warning), rank, text, amount] where amount
 * (the overspend or % used) orders alerts of the same kind.
 */
function budgetLineAlert(array $l, int $month): ?array {
    if ($l['is_income']) return null;
    $m       = $l['metrics'];
    $annual  = $l['annual_budget'];
    $monName = date('F', mktime(0, 0, 0, $month, 1));
    if ($annual > 0 && $l['ytd_actual'] > $annual + 0.005) {
        $over = $l['ytd_actual'] - $annual;
        return ['kind' => 'over_annual', 'level' => 'danger', 'rank' => 1, 'amount' => $over,
                'text' => 'Over annual budget by ' . formatMoney($over)];
    }
    if ($annual > 0 && $m['pctAnnualUsed'] >= 90 && $m['pctExpected'] < 75) {
        return ['kind' => 'early_90', 'level' => 'warning', 'rank' => 2, 'amount' => $m['pctAnnualUsed'],
                'text' => round($m['pctAnnualUsed']) . '% of annual budget used by ' . $monName];
    }
    if ($m['paceLabel'] === 'Over Pace') {
        return ['kind' => 'over_pace', 'level' => 'warning', 'rank' => 3, 'amount' => $m['paceDiff'],
                'text' => 'Over pace: ' . round($m['pctAnnualUsed']) . '% used, ' . round($m['pctExpected']) . '% expected by now'];
    }
    // Only lines with a monthly target (or a rollover envelope) can be over for the month
    if (($l['mon_budget'] > 0 || $l['rollover']) && $l['mon_actual'] > $l['mon_available'] + 0.005) {
        $over = $l['mon_actual'] - $l['mon_available'];
        return ['kind' => 'over_month', 'level' => 'warning', 'rank' => 4, 'amount' => $over,
                'text' => 'Over ' . $monName . ' budget by ' . formatMoney($over)];
    }
    return null;
}

/** Untracked splits as display rows split by category type, signed in each type's direction. */
function budgetUntrackedRows(array $untracked, int $month): array {
    $out = ['income' => [], 'expense' => []];
    if (!$untracked) return $out;
    $ids  = array_keys($untracked);
    $phs  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare(
        "SELECT c.id, c.name, c.type, p.name AS parent_name
         FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
         WHERE c.id IN ($phs) AND c.type IN ('income','expense') AND c.name != '--Split--'"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $c) {
        $sign = $c['type'] === 'income' ? 1 : -1;
        $byM  = $untracked[(int)$c['id']];
        $mon  = round($sign * ($byM[$month] ?? 0.0), MONEY_DECIMALS);
        $ytd  = 0.0;
        foreach ($byM as $m => $v) if ($m <= $month) $ytd += $v;
        $ytd  = round($sign * $ytd, MONEY_DECIMALS);
        if ($mon == 0 && $ytd == 0) continue;
        $out[$c['type']][] = [
            'category_id'  => (int)$c['id'],
            'display_name' => $c['parent_name'] ? $c['parent_name'] . ': ' . $c['name'] : $c['name'],
            'mon_actual'   => $mon,
            'ytd_actual'   => $ytd,
        ];
    }
    foreach ($out as &$list) usort($list, fn($a, $b) => strcasecmp($a['display_name'], $b['display_name']));
    unset($list);
    return $out;
}

/**
 * Investment income (div/int/reinvest_div/reinvest_cap) in the budget accounts.
 * These never get transaction_splits rows (see reports/investment_income.php).
 * Cash div/int use abs(transactions.amount); reinvested activity uses
 * quantity × price, since transactions.amount's sign isn't a reliable
 * indicator of magnitude for reinvest_div/reinvest_cap.
 */
function budgetInvestmentIncome(array $acctIds, int $year, int $month): array {
    if (empty($acctIds)) return [];
    $aPhs = implode(',', array_fill(0, count($acctIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT t.transaction_date AS date, it.activity, it.quantity, it.price, t.amount
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         WHERE it.activity IN ('div','int','reinvest_div','reinvest_cap')
           AND t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)"
    );
    $monStart = sprintf('%04d-%02d-01', $year, $month);
    $monEnd   = date('Y-m-t', strtotime($monStart));
    $stmt->execute([sprintf('%04d-01-01', $year), $monEnd, ...$acctIds]);
    $totals = [];
    foreach ($stmt->fetchAll() as $r) {
        $amt = in_array($r['activity'], ['div', 'int'], true)
            ? abs((float)$r['amount'])
            : (float)$r['quantity'] * (float)$r['price'];
        $act = $r['activity'];
        $totals[$act] ??= ['mon' => 0.0, 'ytd' => 0.0];
        $totals[$act]['ytd'] += $amt;
        if ($r['date'] >= $monStart) $totals[$act]['mon'] += $amt;
    }
    $rows = [];
    foreach ($totals as $act => $t) {
        if ($t['ytd'] == 0.0) continue;
        $rows[] = ['display_name' => investmentActivityLabel($act), 'mon_actual' => $t['mon'], 'ytd_actual' => $t['ytd']];
    }
    usort($rows, fn($a, $b) => $b['ytd_actual'] <=> $a['ytd_actual']);
    return $rows;
}

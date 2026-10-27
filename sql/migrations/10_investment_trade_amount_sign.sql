-- One sign convention for investment-side trade amounts: the import convention
-- (cash flow: buy/reinvest negative, sell positive). Manual entry used the opposite.
-- Reconciled rows are included: investment accounts aren't reconciled on amounts and
-- the paired cash legs are untouched, so reconciled cash balances don't change.
-- updated_at is kept as-is. Safe to re-run: a second run matches nothing.
UPDATE transactions t
  JOIN investment_transactions it ON it.transaction_id = t.id
   SET t.amount = -t.amount, t.updated_at = t.updated_at
 WHERE t.type = 'investment'
   AND (   (it.activity IN ('buy', 'reinvest_div', 'reinvest_cap') AND t.amount > 0)
        OR (it.activity = 'sell' AND t.amount < 0));

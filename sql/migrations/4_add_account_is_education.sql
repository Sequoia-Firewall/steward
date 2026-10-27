-- 529 / education accounts are left out of portfolio withdrawals
ALTER TABLE accounts
  ADD COLUMN is_education TINYINT(1) NOT NULL DEFAULT 0 AFTER is_retirement;

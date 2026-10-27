-- Per-transfer opt-out from portfolio withdrawals, set on the spending-side leg
ALTER TABLE transactions
  ADD COLUMN exclude_from_withdrawals TINYINT(1) NOT NULL DEFAULT 0 AFTER transfer_pair_id;

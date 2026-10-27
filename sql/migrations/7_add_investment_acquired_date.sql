-- Original acquisition date for transferred-in lots (holding period / FIFO order)
ALTER TABLE investment_transactions
  ADD COLUMN acquired_date DATE NULL DEFAULT NULL AFTER commission;

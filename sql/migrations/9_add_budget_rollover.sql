-- Envelope rollover for monthly and variable budget lines
ALTER TABLE budget_categories
  ADD COLUMN rollover TINYINT(1) NOT NULL DEFAULT 0 AFTER due_months;

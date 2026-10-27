-- Due months for annual budget lines: comma-separated month numbers 1-12, e.g. '3,9'
ALTER TABLE budget_categories
  ADD COLUMN due_months VARCHAR(40) NULL DEFAULT NULL AFTER amount;

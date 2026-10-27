-- Bills remember their day of month so month-end due dates don't drift
ALTER TABLE scheduled_bills
  ADD COLUMN due_day TINYINT NULL DEFAULT NULL AFTER next_due_date;

UPDATE scheduled_bills SET due_day = DAY(next_due_date) WHERE due_day IS NULL;

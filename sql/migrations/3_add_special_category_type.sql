ALTER TABLE categories MODIFY COLUMN type ENUM('income','expense','transfer','special') NOT NULL DEFAULT 'expense';

INSERT IGNORE INTO categories (name, type, is_system, is_active) VALUES ('{Special}', 'special', 1, 1);

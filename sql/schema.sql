-- Money Management Application Schema
-- PHP/MySQL LAMP Stack

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS investments;
DROP TABLE IF EXISTS transfers;
DROP TABLE IF EXISTS transaction_splits;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50) UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    email           VARCHAR(100),
    full_name       VARCHAR(100),
    role            ENUM('user','viewer','administrator') NOT NULL DEFAULT 'user',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login      TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    type            ENUM('Banking','Credit Card','Investment') NOT NULL,
    institution     VARCHAR(100) DEFAULT '',
    account_number  VARCHAR(50)  DEFAULT '',
    routing_number  VARCHAR(50)  DEFAULT '',
    comment         TEXT,
    is_favorite     TINYINT(1) NOT NULL DEFAULT 0,
    min_balance     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency        VARCHAR(10) NOT NULL DEFAULT 'USD',
    opening_balance         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    last_reconciled_date    DATE          NULL DEFAULT NULL,
    last_reconciled_balance DECIMAL(15,4) NULL DEFAULT NULL,
    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    linked_account_id    INT NULL DEFAULT NULL,      -- Investment↔Cash companion link
    is_investment_cash      TINYINT(1) NOT NULL DEFAULT 0, -- 1 = auto-created cash sub-account
    exclude_from_net_worth  TINYINT(1) NOT NULL DEFAULT 0,
    hide_from_sidebar       TINYINT(1) NOT NULL DEFAULT 0,
    created_by      INT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by)         REFERENCES users(id)    ON DELETE SET NULL,
    FOREIGN KEY (linked_account_id)  REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    parent_id   INT NULL DEFAULT NULL,
    type        ENUM('income','expense','transfer') NOT NULL DEFAULT 'expense',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    is_system   TINYINT(1) NOT NULL DEFAULT 0,
    created_by  INT,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id)  REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT NOT NULL,
    num              VARCHAR(20)  NOT NULL DEFAULT '',
    transaction_date DATE NOT NULL,
    payee            VARCHAR(200) NOT NULL DEFAULT '',
    type             ENUM('withdrawal','deposit','transfer','investment') NOT NULL,
    -- amount: positive = deposit/credit, negative = withdrawal/debit
    amount           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cleared_status   ENUM('','cleared','reconciled') NOT NULL DEFAULT '',
    memo             TEXT,
    is_split         TINYINT(1) NOT NULL DEFAULT 0,
    -- For transfers: the id of the paired transaction in the other account
    transfer_pair_id INT NULL DEFAULT NULL,
    created_by       INT,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id)  REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_splits (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT NOT NULL,
    category_id     INT NULL DEFAULT NULL,
    subcategory_id  INT NULL DEFAULT NULL,
    amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    memo            VARCHAR(255) NOT NULL DEFAULT '',
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id)    REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transfers (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    from_transaction_id  INT NOT NULL,
    to_transaction_id    INT NOT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_from (from_transaction_id),
    UNIQUE KEY uq_to   (to_transaction_id),
    FOREIGN KEY (from_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (to_transaction_id)   REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index for fast balance lookups
CREATE INDEX idx_transactions_account_date ON transactions(account_id, transaction_date, id);
CREATE INDEX idx_splits_transaction ON transaction_splits(transaction_id);

CREATE TABLE investment_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id  INT NOT NULL,
    investment_id   INT NULL DEFAULT NULL,
    activity        ENUM('buy','sell','add','remove','split') NOT NULL DEFAULT 'buy',
    quantity        DECIMAL(15,6) NOT NULL DEFAULT 0.000000,
    price           DECIMAL(15,6) NOT NULL DEFAULT 0.000000,
    commission      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (investment_id)  REFERENCES investments(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE investments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL,
    symbol     VARCHAR(20)  NOT NULL DEFAULT '',
    type       ENUM('Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Stock','Other') NOT NULL DEFAULT 'Stock',
    country    VARCHAR(100) NOT NULL DEFAULT '',
    memo           TEXT DEFAULT NULL,
    disable_quotes TINYINT(1) NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_bills (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    type            ENUM('bill','deposit') NOT NULL DEFAULT 'bill',
    account_id      INT NOT NULL,
    category_id     INT DEFAULT NULL,
    subcategory_id  INT DEFAULT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    frequency       ENUM('once','weekly','biweekly','twice_monthly','monthly','bimonthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    next_due_date   DATE NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    notes           VARCHAR(255) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id)     REFERENCES accounts(id)    ON DELETE CASCADE,
    FOREIGN KEY (category_id)    REFERENCES categories(id)  ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    key_name   VARCHAR(100) NOT NULL,
    value      TEXT         DEFAULT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE investment_prices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    investment_id INT NOT NULL,
    price_date    DATE NOT NULL,
    close_price   DECIMAL(15,8) NOT NULL,
    open_price    DECIMAL(15,8) NULL,
    high_price    DECIMAL(15,8) NULL,
    low_price     DECIMAL(15,8) NULL,
    volume        BIGINT NULL,
    vwap          DECIMAL(15,8) NULL,
    source        VARCHAR(20) NOT NULL DEFAULT 'manual',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inv_date (investment_id, price_date),
    FOREIGN KEY (investment_id) REFERENCES investments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

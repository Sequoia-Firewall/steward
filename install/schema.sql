-- Steward 4.0 — Complete Database Schema
-- Fresh-install DDL. This is the v4.0 baseline; no migrations are needed
-- for a new installation.
--
-- Import with: mysql -u USER -p DATABASE < schema.sql

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS stock_sectors;
DROP TABLE IF EXISTS fund_holdings;
DROP TABLE IF EXISTS dashboard_notes;
DROP TABLE IF EXISTS dashboard_bookmarks;
DROP TABLE IF EXISTS paycheck_template_lines;
DROP TABLE IF EXISTS paycheck_templates;
DROP TABLE IF EXISTS loan_details;
DROP TABLE IF EXISTS savings_goals;
DROP TABLE IF EXISTS user_prefs;
DROP TABLE IF EXISTS budget_monthly_amounts;
DROP TABLE IF EXISTS budget_categories;
DROP TABLE IF EXISTS budget_accounts;
DROP TABLE IF EXISTS budgets;
DROP TABLE IF EXISTS favorite_reports;
DROP TABLE IF EXISTS payees;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS investment_prices;
DROP TABLE IF EXISTS investment_transactions;
DROP TABLE IF EXISTS investments;
DROP TABLE IF EXISTS transfers;
DROP TABLE IF EXISTS transaction_splits;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS scheduled_bills;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS schema_migrations;

SET FOREIGN_KEY_CHECKS = 1;

-- ──────────────────────────────────────────────────────────────
-- Core tables
-- ──────────────────────────────────────────────────────────────

CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email         VARCHAR(100) DEFAULT NULL,
    full_name     VARCHAR(100) DEFAULT NULL,
    role          ENUM('user','viewer','administrator') NOT NULL DEFAULT 'user',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    TIMESTAMP    NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    name                   VARCHAR(100) NOT NULL,
    type                   ENUM('Checking','Savings','Banking','Credit Card','Investment','Asset','Loan','investment-cash','Crypto') NOT NULL DEFAULT 'Checking',
    institution            VARCHAR(100) DEFAULT '',
    account_number         VARCHAR(50)  DEFAULT '',
    routing_number         VARCHAR(50)  DEFAULT '',
    comment                TEXT,
    is_favorite            TINYINT(1)   NOT NULL DEFAULT 0,
    min_balance            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    currency               VARCHAR(10)  NOT NULL DEFAULT 'USD',
    opening_balance        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    last_reconciled_date   DATE          NULL DEFAULT NULL,
    last_reconciled_balance DECIMAL(15,4) NULL DEFAULT NULL,
    is_active              TINYINT(1)   NOT NULL DEFAULT 1,
    created_by             INT          DEFAULT NULL,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    linked_account_id      INT          DEFAULT NULL,
    is_investment_cash     TINYINT(1)   NOT NULL DEFAULT 0,
    exclude_from_net_worth TINYINT(1)   NOT NULL DEFAULT 0,
    is_retirement          TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order             INT          NOT NULL DEFAULT 0,
    is_closed              TINYINT(1)   NOT NULL DEFAULT 0,
    hide_from_sidebar      TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (created_by)        REFERENCES users(id)    ON DELETE SET NULL,
    FOREIGN KEY (linked_account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    parent_id  INT          DEFAULT NULL,
    type       ENUM('income','expense','transfer') NOT NULL DEFAULT 'expense',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    is_system  TINYINT(1)   NOT NULL DEFAULT 0,
    tax_related TINYINT(1)  NOT NULL DEFAULT 0,
    created_by INT          DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id)  REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT          NOT NULL,
    num              VARCHAR(20)  NOT NULL DEFAULT '',
    transaction_date DATE         NOT NULL,
    payee            VARCHAR(200) NOT NULL DEFAULT '',
    type             ENUM('withdrawal','deposit','transfer','investment') NOT NULL,
    amount           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cleared_status   ENUM('','cleared','reconciled') NOT NULL DEFAULT '',
    memo             TEXT,
    fitid            VARCHAR(255) DEFAULT NULL,
    is_split         TINYINT(1)   NOT NULL DEFAULT 0,
    transfer_pair_id INT          DEFAULT NULL,
    created_by       INT          DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_transactions_account_date (account_id, transaction_date, id),
    UNIQUE KEY uq_txn_fitid (account_id, fitid(50)),
    FOREIGN KEY (account_id)  REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_splits (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT          NOT NULL,
    category_id    INT          DEFAULT NULL,
    subcategory_id INT          DEFAULT NULL,
    amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    memo           VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_splits_transaction (transaction_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id)    REFERENCES categories(id)   ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transfers (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    from_transaction_id INT NOT NULL,
    to_transaction_id   INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_from (from_transaction_id),
    UNIQUE KEY uq_to   (to_transaction_id),
    FOREIGN KEY (from_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (to_transaction_id)   REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Investment tables
-- ──────────────────────────────────────────────────────────────

CREATE TABLE investments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(200) NOT NULL,
    symbol         VARCHAR(20)  NOT NULL DEFAULT '',
    cusip          VARCHAR(9)   DEFAULT NULL,
    type           ENUM('Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Stock','Index','Other','Cryptocurrency') NOT NULL DEFAULT 'Stock',
    country        VARCHAR(100) NOT NULL DEFAULT '',
    memo           TEXT,
    disable_quotes TINYINT(1)   NOT NULL DEFAULT 0,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_by     INT          DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    in_watchlist   TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE investment_transactions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    investment_id  INT DEFAULT NULL,
    activity       ENUM('buy','sell','add','remove','split','reinvest_div','reinvest_cap','div','int') NOT NULL DEFAULT 'buy',
    action_type    VARCHAR(20)    DEFAULT NULL,
    quantity       DECIMAL(27,10) NOT NULL DEFAULT 0,
    price          DECIMAL(27,10) NOT NULL DEFAULT 0,
    commission     DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id)  ON DELETE CASCADE,
    FOREIGN KEY (investment_id)  REFERENCES investments(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE investment_prices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    investment_id INT            NOT NULL,
    price_date    DATE           NOT NULL,
    close_price   DECIMAL(15,8) NOT NULL,
    open_price    DECIMAL(15,8) DEFAULT NULL,
    high_price    DECIMAL(15,8) DEFAULT NULL,
    low_price     DECIMAL(15,8) DEFAULT NULL,
    volume        BIGINT        DEFAULT NULL,
    vwap          DECIMAL(15,8) DEFAULT NULL,
    source        VARCHAR(20)    NOT NULL DEFAULT 'manual',
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inv_date (investment_id, price_date),
    FOREIGN KEY (investment_id) REFERENCES investments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed common market indices
INSERT INTO investments (name, symbol, type, country, memo) VALUES
    ('S&P 500',          '^GSPC', 'Index', 'US', 'Large-cap US equity index'),
    ('Dow Jones',        '^DJI',  'Index', 'US', 'Blue-chip US equity index'),
    ('NASDAQ Composite', '^IXIC', 'Index', 'US', 'Technology-weighted US equity index'),
    ('Russell 2000',     '^RUT',  'Index', 'US', 'Small-cap US equity index'),
    ('VIX',              '^VIX',  'Index', 'US', 'CBOE Volatility Index'),
    ('10-Year Treasury', '^TNX',  'Index', 'US', 'US 10-Year Treasury yield');

-- ──────────────────────────────────────────────────────────────
-- Scheduling
-- ──────────────────────────────────────────────────────────────

CREATE TABLE scheduled_bills (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    type           ENUM('bill','deposit','transfer') NOT NULL DEFAULT 'bill',
    account_id     INT NOT NULL,
    to_account_id  INT DEFAULT NULL,
    category_id    INT DEFAULT NULL,
    subcategory_id INT DEFAULT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    is_estimated   TINYINT(1)   NOT NULL DEFAULT 0,
    frequency      ENUM('once','weekly','biweekly','twice_monthly','monthly','bimonthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    next_due_date  DATE         NOT NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    notes          VARCHAR(255) DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id)     REFERENCES accounts(id)   ON DELETE CASCADE,
    FOREIGN KEY (to_account_id)  REFERENCES accounts(id)   ON DELETE SET NULL,
    FOREIGN KEY (category_id)    REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Budgets
-- ──────────────────────────────────────────────────────────────

CREATE TABLE budgets (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(100) NOT NULL,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    show_on_dashboard TINYINT(1)   NOT NULL DEFAULT 0,
    created_by        INT          DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_accounts (
    budget_id  INT NOT NULL,
    account_id INT NOT NULL,
    PRIMARY KEY (budget_id, account_id),
    FOREIGN KEY (budget_id)  REFERENCES budgets(id)   ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_categories (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    budget_id         INT NOT NULL,
    category_id       INT NOT NULL,
    entry_type        ENUM('annual','monthly','variable') NOT NULL DEFAULT 'monthly',
    amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    show_on_dashboard TINYINT(1)    NOT NULL DEFAULT 0,
    UNIQUE KEY uq_bc (budget_id, category_id),
    FOREIGN KEY (budget_id)   REFERENCES budgets(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_monthly_amounts (
    budget_category_id INT           NOT NULL,
    month              TINYINT       NOT NULL,
    amount             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (budget_category_id, month),
    FOREIGN KEY (budget_category_id) REFERENCES budget_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Loans and goals
-- ──────────────────────────────────────────────────────────────

CREATE TABLE loan_details (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_id      INT           NOT NULL UNIQUE,
    original_amount DECIMAL(15,2) NOT NULL,
    annual_rate     DECIMAL(6,4)  NOT NULL,
    term_months     INT           NOT NULL,
    start_date      DATE          NOT NULL,
    payment_amount  DECIMAL(15,2) NOT NULL,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE savings_goals (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100)  NOT NULL,
    target_amount  DECIMAL(15,2) NOT NULL,
    current_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    target_date    DATE          DEFAULT NULL,
    account_id     INT           DEFAULT NULL,
    notes          VARCHAR(255)  DEFAULT NULL,
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    created_by     INT           DEFAULT NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Paycheck templates
-- ──────────────────────────────────────────────────────────────

CREATE TABLE paycheck_templates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL,
    account_id INT          NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_by INT          DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paycheck_template_lines (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    template_id    INT           NOT NULL,
    sort_order     INT           NOT NULL DEFAULT 0,
    label          VARCHAR(200)  NOT NULL,
    category_id    INT           DEFAULT NULL,
    subcategory_id INT           DEFAULT NULL,
    amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (template_id)  REFERENCES paycheck_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id)  REFERENCES categories(id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Payees, reports, settings, prefs
-- ──────────────────────────────────────────────────────────────

CREATE TABLE payees (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(200) NOT NULL UNIQUE,
    address        TEXT,
    phone          VARCHAR(50)  NOT NULL DEFAULT '',
    website        VARCHAR(255) NOT NULL DEFAULT '',
    account_number VARCHAR(100) NOT NULL DEFAULT '',
    note           TEXT,
    category_id    INT          DEFAULT NULL,
    subcategory_id INT          DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id)    REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorite_reports (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(150)  NOT NULL,
    url          TEXT          NOT NULL,
    icon         VARCHAR(60)   NOT NULL DEFAULT 'bi-file-earmark-bar-graph',
    sort_order   INT           NOT NULL DEFAULT 0,
    type         VARCHAR(20)   NOT NULL DEFAULT 'dashboard',
    graph_config TEXT          DEFAULT NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    key_name   VARCHAR(100) NOT NULL PRIMARY KEY,
    value      TEXT         DEFAULT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_prefs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    pref_key   VARCHAR(100) NOT NULL,
    pref_value TEXT         DEFAULT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_pref (user_id, pref_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dashboard_notes (
    id         INT NOT NULL AUTO_INCREMENT,
    content    TEXT NOT NULL,
    updated_by INT NULL,
    updated_at DATETIME     NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_dashboard_notes_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO dashboard_notes (id, content, updated_by, updated_at) VALUES (1, '', NULL, NULL);

CREATE TABLE dashboard_bookmarks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT           NOT NULL,
    title      VARCHAR(255)  NOT NULL,
    url        VARCHAR(2048) NOT NULL,
    sort_order INT           NOT NULL DEFAULT 0,
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bmk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Activity log
-- ──────────────────────────────────────────────────────────────

CREATE TABLE activity_log (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED  DEFAULT NULL,
    user_name   VARCHAR(100)  NOT NULL DEFAULT '',
    event       VARCHAR(50)   NOT NULL,
    description TEXT          NOT NULL,
    ip_address  VARCHAR(45)   DEFAULT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_al_user    (user_id),
    INDEX idx_al_event   (event),
    INDEX idx_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Investment research caches (fund holdings + sector data)
-- ──────────────────────────────────────────────────────────────

CREATE TABLE fund_holdings (
    fund_symbol        VARCHAR(20)   NOT NULL,
    constituent_symbol VARCHAR(20)   NOT NULL,
    constituent_name   VARCHAR(200)  NOT NULL DEFAULT '',
    weight_pct         DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
    fetched_at         DATETIME      NOT NULL,
    source             VARCHAR(50)   NOT NULL DEFAULT '',
    PRIMARY KEY (fund_symbol, constituent_symbol),
    KEY idx_fund_fetched (fund_symbol, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_sectors (
    symbol     VARCHAR(20)  NOT NULL,
    sector     VARCHAR(100) NOT NULL DEFAULT '',
    industry   VARCHAR(100) NOT NULL DEFAULT '',
    source     VARCHAR(30)  NOT NULL DEFAULT '',
    fetched_at DATETIME     NOT NULL,
    PRIMARY KEY (symbol),
    KEY idx_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Schema version tracking (empty on fresh install = baseline v1)
-- ──────────────────────────────────────────────────────────────

CREATE TABLE schema_migrations (
    version    SMALLINT UNSIGNED NOT NULL,
    filename   VARCHAR(100)      NOT NULL,
    applied_at DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ──────────────────────────────────────────────────────────────
-- Seed: Banking Income categories
-- (Securities income — dividends, cap gains, interest — is tracked
-- via the Buy/Sell/Dividend/Interest activity on investment
-- transactions, not a category. This category is for bank/CD/
-- savings/checking interest and cash-sweep rewards instead.)
-- ──────────────────────────────────────────────────────────────

INSERT INTO categories (name, type, parent_id, is_active)
VALUES ('Banking Income', 'income', NULL, 1);

SET @bank_parent = LAST_INSERT_ID();

INSERT INTO categories (name, type, parent_id, is_active) VALUES
    ('Bank Interests', 'income', @bank_parent, 1),
    ('Cash Rewards',   'income', @bank_parent, 1);

-- System category for cash transfers (auto-assigned, protected from rename/delete)
INSERT IGNORE INTO categories (name, type, is_system, is_active) VALUES ('{Cash Transfer}', 'transfer', 1, 1);

-- ──────────────────────────────────────────────────────────────
-- Default admin account
-- Password: Admin123!  Change immediately after first login.
-- ──────────────────────────────────────────────────────────────

INSERT IGNORE INTO users (username, password_hash, email, full_name, role)
VALUES ('admin', '$2y$12$5bHJcbbCqcEatqHU1CU75O3JN2f5tLEJ.VovQuJL7DF5WH9jOU3LO', 'admin@home.local', 'Administrator', 'administrator');

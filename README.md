# Steward

A self-hosted personal finance application built on PHP and MySQL.

## Features

**Accounts & Transactions**
- Checking, savings, credit card, investment, loan, asset, and crypto accounts
- Transaction register with splits, cleared/reconciled status, and transfer matching
- OFX/QFX/CSV import with duplicate detection, plus a Fast Import mode for the native CSV format
- Statement Converter — turns Fidelity / Merrill Edge brokerage exports into an importable CSV
- Paycheck templates for recurring paycheck entry
- Scheduled bills and deposits with automatic posting and a balance Forecast

**Budgeting**
- Monthly and annual budgets by category, with auto-generation from transaction history
- Copy a budget to a new period as a starting point
- Budget vs. actual tracking with dashboard widgets

**Investments**
- Portfolio tracking with cost basis and unrealized gains, across all accounts
- Per-security page with price history and cross-account transaction history
- Watchlist for securities you don't hold
- Historical price fetching (AlphaVantage / Polygon), including scheduled daily refresh
- Investment transactions: buy, sell, reinvest dividends, stock splits, adjustments
- Reconcile holdings against a brokerage statement CSV

**Goals**
- Savings Goals with target amount/date, optional account linking, and dashboard progress bars

**Reports**
- Net Worth, Account Balances, Account Balances History
- Cash Flow, Income vs. Expense, Income Analysis
- Spending by Category, Spending History, Spending Trends (heatmap)
- Budget vs. Actual, Payee Summary, Account Flow
- Portfolio Performance, Portfolio Snapshot, Portfolio Value History
- Asset Allocation, Capital Gains, Investment Performance
- Stock Exposure (effective per-stock exposure through ETFs/funds)
- Sector Exposure (GICS sector breakdown)
- Loan Amortization, Cash Flow Forecast
- Savings Goals Progress, Reconciliation Status
- Custom Report builder (any dimension × metric, CSV/XLSX export)

**Maintenance & Security**
- Database backup/restore (full SQL dump) and database maintenance tools
- Database integrity checks (duplicates, orphans, mismatches) with one-click fixes
- Activity log with configurable retention
- Multi-user with viewer/user/administrator roles
- CSRF protection, session timeout, HTTPS enforcement

## Requirements

### PHP

**Minimum version:** PHP 8.0

| Package | Why |
|---|---|
| `php-mysql` | Database access (PDO + pdo_mysql driver) |
| `php-mbstring` | Multi-byte string functions used throughout |
| `php-curl` | Parallel price fetching (recommended; falls back to sequential `file_get_contents` if missing) |

All other dependencies (`json`, `hash`, `pcre`, `session`, `ctype`, `openssl`, `filter`) are compiled into PHP 8.x by default and require no separate package.

**`allow_url_fopen`** must be `On` in `php.ini` (the default). It is used as the fallback HTTP client when `php-curl` is not installed.

### Apache

- `mod_rewrite` enabled (`a2enmod rewrite`)
- `AllowOverride FileInfo` (or `AllowOverride All`) set for the application directory so `.htaccess` rules take effect

### MySQL

MySQL 5.7+ or MariaDB 10.3+.

## Installation

```bash
# PHP and extensions
sudo apt install php php-mysql php-mbstring php-curl

# Apache
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Deploy the application files to your web root, then visit `/setup/` in a
browser to run the installation wizard. It walks through four steps —
Requirements, Database, Settings, Complete — checks PHP/Apache/MySQL
prerequisites, creates `config/database.php` and `config/app.php`, applies
`install/schema.sql`, and marks all migrations as applied. No manual
migration step is needed on a fresh install.

Optionally load sample data (three demo accounts, 60+ categorized
transactions, and three test users) during setup, or leave it unchecked for
a clean installation.

**After logging in for the first time, delete the `setup/` directory** so
the installer can't be re-run:

```bash
rm -rf setup/
```

## Upgrading from an Earlier Version

After deploying new files, apply any pending schema migrations:

```bash
php migrate.php
```

Or visit **Settings → Database Migrations** in the UI.

Migrations are additive and safe to re-run (all use `IF NOT EXISTS` / `IGNORE`).
Pointing `/setup/` at a database that already has data will never drop
existing tables — it only applies pending migrations and skips sample data.

## Configuration

`config/database.php` (created by the setup wizard) holds your database
credentials — copy `config/database.php.example` if you need to recreate it
by hand. All other settings (instance name, timezone, currency symbol,
session timeout, HTTPS enforcement) are managed through
**Settings → Preferences** in the UI.

## Default Login

If you loaded sample data during setup, the default credentials are:

| Username | Password | Role |
|---|---|---|
| `admin` | `Admin123!` | administrator |
| `john` | `John123!` | user |
| `viewer` | `View123!` | viewer |

**Change these passwords immediately after first login.**

## License

Licensed for personal, non-commercial use only. See [license.txt](license.txt)
for full terms. For commercial licensing or other inquiries, contact
steward@7312.us.

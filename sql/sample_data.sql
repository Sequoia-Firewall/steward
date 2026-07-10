-- Sample data for Steward
-- Passwords: admin=Admin123!  john=John123!  viewer=View123!
-- Covers Jan–Jun 2026. Includes banking, credit, and investment accounts.

-- ── Users ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO users (username, password_hash, email, full_name, role) VALUES
('admin',  '$2y$12$5bHJcbbCqcEatqHU1CU75O3JN2f5tLEJ.VovQuJL7DF5WH9jOU3LO', 'admin@home.local',  'Administrator',  'administrator');
INSERT IGNORE INTO users (username, password_hash, email, full_name, role) VALUES
('john',   '$2y$12$ZeXwxbFOeJJHJuvKl2O.QOZ0IklOYD5awzQ2hHU2taow7iLYL/70C', 'john@home.local',   'John Smith',     'user');
INSERT IGNORE INTO users (username, password_hash, email, full_name, role) VALUES
('viewer', '$2y$12$luu1DKLA9dR3ji2jFYusOufxHmMfs4Rz2d6KoPtnScwWFF6B2zOPu', 'viewer@home.local', 'View Only User', 'viewer');
SET @admin_id = (SELECT id FROM users WHERE username = 'admin');
SET @john_id  = (SELECT id FROM users WHERE username = 'john');

-- ── Accounts ───────────────────────────────────────────────────────────────

-- Banking
INSERT INTO accounts (name, type, institution, account_number, routing_number, is_favorite, currency, opening_balance, comment, created_by) VALUES
('Acme Bank Checking',     'Checking', 'Acme Bank',    '****1234', '021000021', 1, 'USD',  8500.00, 'Primary checking account',       @admin_id);
SET @acc_chk = LAST_INSERT_ID();
INSERT INTO accounts (name, type, institution, account_number, routing_number, is_favorite, currency, opening_balance, comment, created_by) VALUES
('Acme Bank Savings',      'Savings',  'Acme Bank',    '****5678', '021000021', 1, 'USD', 12500.00, 'Emergency fund savings',         @admin_id);
SET @acc_sav = LAST_INSERT_ID();
INSERT INTO accounts (name, type, institution, account_number, routing_number, is_favorite, currency, opening_balance, comment, created_by) VALUES
('Ally High-Yield Savings','Savings',  'Ally Bank',    '****4321', '124003116', 1, 'USD', 15000.00, 'High-yield online savings 4.5%', @admin_id);
SET @acc_hys = LAST_INSERT_ID();

-- Credit cards
INSERT INTO accounts (name, type, institution, account_number, is_favorite, currency, opening_balance, comment, created_by) VALUES
('Visa Platinum Card',      'Credit Card', 'First National Bank', '****9012', 1, 'USD',  -650.00, 'Main credit card',           @admin_id);
SET @acc_cc1 = LAST_INSERT_ID();
INSERT INTO accounts (name, type, institution, account_number, is_favorite, currency, opening_balance, comment, created_by) VALUES
('Chase Sapphire Reserve',  'Credit Card', 'Chase',              '****7788', 1, 'USD', -1200.00, 'Travel rewards card',        @admin_id);
SET @acc_cc2 = LAST_INSERT_ID();

-- Investment: Fidelity Brokerage + cash companion
INSERT INTO accounts (name, type, institution, account_number, is_favorite, currency, opening_balance, is_retirement, comment, created_by) VALUES
('Fidelity Brokerage', 'Investment', 'Fidelity', '****3456', 1, 'USD', 0.00, 0, 'Taxable brokerage account', @admin_id);
SET @acc_brok = LAST_INSERT_ID();
INSERT INTO accounts (name, type, institution, account_number, currency, opening_balance, is_investment_cash, linked_account_id, hide_from_sidebar, created_by) VALUES
('Fidelity Brokerage Cash', 'investment-cash', 'Fidelity', '****3456', 'USD', 20000.00, 1, @acc_brok, 1, @admin_id);
SET @acc_brok_cash = LAST_INSERT_ID();
UPDATE accounts SET linked_account_id = @acc_brok_cash WHERE id = @acc_brok;

-- Investment: Fidelity Roth IRA + cash companion
INSERT INTO accounts (name, type, institution, account_number, is_favorite, currency, opening_balance, is_retirement, comment, created_by) VALUES
('Fidelity Roth IRA', 'Investment', 'Fidelity', '****7890', 1, 'USD', 0.00, 1, '2026 max contribution $7,000', @admin_id);
SET @acc_ira = LAST_INSERT_ID();
INSERT INTO accounts (name, type, institution, account_number, currency, opening_balance, is_investment_cash, linked_account_id, hide_from_sidebar, created_by) VALUES
('Fidelity Roth IRA Cash', 'investment-cash', 'Fidelity', '****7890', 'USD', 7000.00, 1, @acc_ira, 1, @admin_id);
SET @acc_ira_cash = LAST_INSERT_ID();
UPDATE accounts SET linked_account_id = @acc_ira_cash WHERE id = @acc_ira;

-- ── Categories ─────────────────────────────────────────────────────────────

-- Income
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Income', NULL, 'income', @admin_id);
SET @income = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Salary',           @income, 'income', @admin_id),
('Freelance Income', @income, 'income', @admin_id),
('Interest',         @income, 'income', @admin_id),
('Dividends',        @income, 'income', @admin_id),
('Capital Gains',    @income, 'income', @admin_id),
('Other Income',     @income, 'income', @admin_id);
SET @salary      = (SELECT id FROM categories WHERE name = 'Salary'    AND parent_id = @income);
SET @interest_in = (SELECT id FROM categories WHERE name = 'Interest'  AND parent_id = @income);
SET @dividends   = (SELECT id FROM categories WHERE name = 'Dividends' AND parent_id = @income);

-- Housing
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Housing', NULL, 'expense', @admin_id);
SET @housing = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Mortgage/Rent',    @housing, 'expense', @admin_id),
('Home Insurance',   @housing, 'expense', @admin_id),
('Property Tax',     @housing, 'expense', @admin_id),
('Home Maintenance', @housing, 'expense', @admin_id);
SET @mortgage   = (SELECT id FROM categories WHERE name = 'Mortgage/Rent'    AND parent_id = @housing);
SET @home_maint = (SELECT id FROM categories WHERE name = 'Home Maintenance' AND parent_id = @housing);
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Utilities', @housing, 'expense', @admin_id);
SET @utilities = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Electricity',    @utilities, 'expense', @admin_id),
('Water/Sewer',    @utilities, 'expense', @admin_id),
('Gas/Heating',    @utilities, 'expense', @admin_id),
('Internet/Cable', @utilities, 'expense', @admin_id),
('Phone',          @utilities, 'expense', @admin_id);

-- Food & Dining
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Food & Dining', NULL, 'expense', @admin_id);
SET @food = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Groceries',       @food, 'expense', @admin_id),
('Restaurants',     @food, 'expense', @admin_id),
('Coffee & Drinks', @food, 'expense', @admin_id),
('Fast Food',       @food, 'expense', @admin_id);
SET @groceries   = (SELECT id FROM categories WHERE name = 'Groceries'       AND parent_id = @food);
SET @restaurants = (SELECT id FROM categories WHERE name = 'Restaurants'     AND parent_id = @food);
SET @coffee      = (SELECT id FROM categories WHERE name = 'Coffee & Drinks' AND parent_id = @food);

-- Transportation
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Transportation', NULL, 'expense', @admin_id);
SET @transport = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Gas/Fuel',        @transport, 'expense', @admin_id),
('Car Payment',     @transport, 'expense', @admin_id),
('Auto Insurance',  @transport, 'expense', @admin_id),
('Car Maintenance', @transport, 'expense', @admin_id),
('Public Transit',  @transport, 'expense', @admin_id),
('Parking',         @transport, 'expense', @admin_id);
SET @gas_fuel  = (SELECT id FROM categories WHERE name = 'Gas/Fuel'      AND parent_id = @transport);
SET @car_pay   = (SELECT id FROM categories WHERE name = 'Car Payment'   AND parent_id = @transport);
SET @auto_ins  = (SELECT id FROM categories WHERE name = 'Auto Insurance' AND parent_id = @transport);

-- Healthcare
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Healthcare', NULL, 'expense', @admin_id);
SET @healthcare = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Doctor Visits',    @healthcare, 'expense', @admin_id),
('Prescriptions',    @healthcare, 'expense', @admin_id),
('Dental',           @healthcare, 'expense', @admin_id),
('Vision',           @healthcare, 'expense', @admin_id),
('Health Insurance', @healthcare, 'expense', @admin_id);
SET @doctor = (SELECT id FROM categories WHERE name = 'Doctor Visits' AND parent_id = @healthcare);

-- Entertainment
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Entertainment', NULL, 'expense', @admin_id);
SET @entertainment = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Movies/Theater', @entertainment, 'expense', @admin_id),
('Streaming',      @entertainment, 'expense', @admin_id),
('Hobbies',        @entertainment, 'expense', @admin_id),
('Books/Music',    @entertainment, 'expense', @admin_id),
('Sports/Fitness', @entertainment, 'expense', @admin_id);
SET @streaming = (SELECT id FROM categories WHERE name = 'Streaming' AND parent_id = @entertainment);

-- Shopping
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Shopping', NULL, 'expense', @admin_id);
SET @shopping = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Clothing',      @shopping, 'expense', @admin_id),
('Electronics',   @shopping, 'expense', @admin_id),
('Home Goods',    @shopping, 'expense', @admin_id),
('Personal Care', @shopping, 'expense', @admin_id);
SET @electronics = (SELECT id FROM categories WHERE name = 'Electronics' AND parent_id = @shopping);

-- Financial
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Financial', NULL, 'expense', @admin_id);
SET @financial = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Credit Card Payment', @financial, 'expense', @admin_id),
('Savings Transfer',    @financial, 'expense', @admin_id),
('Bank Fees',           @financial, 'expense', @admin_id),
('Taxes',               @financial, 'expense', @admin_id);
SET @cc_payment    = (SELECT id FROM categories WHERE name = 'Credit Card Payment' AND parent_id = @financial);
SET @sav_transfer  = (SELECT id FROM categories WHERE name = 'Savings Transfer'    AND parent_id = @financial);

-- Education
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Education', NULL, 'expense', @admin_id);
SET @education = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Tuition',        @education, 'expense', @admin_id),
('Books/Supplies', @education, 'expense', @admin_id),
('Online Courses', @education, 'expense', @admin_id);

-- Gifts & Donations
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Gifts & Donations', NULL, 'expense', @admin_id);
SET @gifts = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Gifts Given',          @gifts, 'expense', @admin_id),
('Charitable Donations', @gifts, 'expense', @admin_id);

-- Travel
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Travel', NULL, 'expense', @admin_id);
SET @travel = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Flights',             @travel, 'expense', @admin_id),
('Hotels',              @travel, 'expense', @admin_id),
('Vacation/Activities', @travel, 'expense', @admin_id);
SET @flights = (SELECT id FROM categories WHERE name = 'Flights' AND parent_id = @travel);
SET @hotels  = (SELECT id FROM categories WHERE name = 'Hotels'  AND parent_id = @travel);

-- Business
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Business', NULL, 'expense', @admin_id);
SET @business = LAST_INSERT_ID();
INSERT INTO categories (name, parent_id, type, created_by) VALUES
('Office Supplies',       @business, 'expense', @admin_id),
('Business Meals',        @business, 'expense', @admin_id),
('Professional Services', @business, 'expense', @admin_id);

-- Other
INSERT INTO categories (name, parent_id, type, created_by) VALUES ('Other', NULL, 'expense', @admin_id);

-- Additional subcategory lookups (used by savings/credit-card categorization below)
SET @prescriptions       = (SELECT id FROM categories WHERE name = 'Prescriptions'       AND parent_id = @healthcare);
SET @clothing            = (SELECT id FROM categories WHERE name = 'Clothing'            AND parent_id = @shopping);
SET @hobbies             = (SELECT id FROM categories WHERE name = 'Hobbies'             AND parent_id = @entertainment);
SET @sports_fitness      = (SELECT id FROM categories WHERE name = 'Sports/Fitness'      AND parent_id = @entertainment);
SET @business_meals      = (SELECT id FROM categories WHERE name = 'Business Meals'      AND parent_id = @business);
SET @vacation_activities = (SELECT id FROM categories WHERE name = 'Vacation/Activities' AND parent_id = @travel);

-- ── Securities ─────────────────────────────────────────────────────────────

INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Apple Inc.',                        'AAPL',  'Stock',       'US', 'Consumer electronics & software',        @admin_id);
SET @aapl_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Microsoft Corporation',             'MSFT',  'Stock',       'US', 'Cloud, enterprise software & gaming',    @admin_id);
SET @msft_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Alphabet Inc.',                     'GOOGL', 'Stock',       'US', 'Search, advertising & cloud',            @admin_id);
SET @googl_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Vanguard Total Stock Market ETF',   'VTI',   'ETF',         'US', 'Broad US market index, low-cost',        @admin_id);
SET @vti_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Vanguard Total Bond Market ETF',    'BND',   'ETF',         'US', 'Investment-grade US bond index',         @admin_id);
SET @bnd_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Fidelity ZERO Total Market Index',  'FZROX', 'Mutual Fund', 'US', 'Zero expense ratio total market fund',  @admin_id);
SET @fzrox_id = LAST_INSERT_ID();

-- ── Investment price history (Jan–Jun 2026) ────────────────────────────────

INSERT INTO investment_prices (investment_id, price_date, close_price, open_price, high_price, low_price, source) VALUES
-- AAPL
(@aapl_id,  '2026-01-30', 210.45, 208.20, 212.80, 207.50, 'manual'),
(@aapl_id,  '2026-02-27', 215.30, 211.00, 217.90, 210.15, 'manual'),
(@aapl_id,  '2026-03-31', 218.75, 215.50, 220.40, 214.00, 'manual'),
(@aapl_id,  '2026-04-30', 211.80, 219.00, 221.00, 208.30, 'manual'),
(@aapl_id,  '2026-05-29', 225.10, 212.00, 226.50, 210.75, 'manual'),
(@aapl_id,  '2026-06-24', 228.40, 225.00, 229.80, 224.20, 'manual'),
-- MSFT
(@msft_id,  '2026-01-30', 381.20, 377.50, 384.00, 375.80, 'manual'),
(@msft_id,  '2026-02-27', 389.60, 381.00, 392.50, 379.40, 'manual'),
(@msft_id,  '2026-03-31', 393.80, 389.00, 396.20, 387.50, 'manual'),
(@msft_id,  '2026-04-30', 386.40, 393.00, 397.80, 382.10, 'manual'),
(@msft_id,  '2026-05-29', 402.50, 386.00, 405.30, 385.20, 'manual'),
(@msft_id,  '2026-06-24', 409.70, 402.00, 411.90, 401.30, 'manual'),
-- GOOGL
(@googl_id, '2026-01-30', 176.30, 173.80, 178.50, 172.90, 'manual'),
(@googl_id, '2026-02-27', 179.50, 176.00, 181.20, 175.30, 'manual'),
(@googl_id, '2026-03-31', 183.20, 179.00, 185.40, 178.60, 'manual'),
(@googl_id, '2026-04-30', 181.40, 183.00, 185.80, 178.20, 'manual'),
(@googl_id, '2026-05-29', 189.70, 181.00, 191.20, 180.50, 'manual'),
(@googl_id, '2026-06-24', 192.80, 189.00, 194.60, 188.40, 'manual'),
-- VTI
(@vti_id,   '2026-01-30', 276.40, 273.20, 278.90, 272.50, 'manual'),
(@vti_id,   '2026-02-27', 280.10, 276.00, 282.40, 275.30, 'manual'),
(@vti_id,   '2026-03-31', 284.30, 280.00, 286.70, 279.40, 'manual'),
(@vti_id,   '2026-04-30', 277.80, 284.00, 286.50, 274.20, 'manual'),
(@vti_id,   '2026-05-29', 291.50, 277.00, 293.20, 276.80, 'manual'),
(@vti_id,   '2026-06-24', 294.60, 291.00, 296.40, 290.70, 'manual'),
-- BND
(@bnd_id,   '2026-01-30',  73.25,  72.90,  73.60,  72.80, 'manual'),
(@bnd_id,   '2026-02-27',  73.65,  73.20,  73.90,  73.10, 'manual'),
(@bnd_id,   '2026-03-31',  74.10,  73.60,  74.35,  73.50, 'manual'),
(@bnd_id,   '2026-04-30',  73.85,  74.10,  74.50,  73.70, 'manual'),
(@bnd_id,   '2026-05-29',  74.40,  73.80,  74.65,  73.75, 'manual'),
(@bnd_id,   '2026-06-24',  74.60,  74.35,  74.80,  74.30, 'manual'),
-- FZROX
(@fzrox_id, '2026-01-30',  20.08,  19.90,  20.25,  19.85, 'manual'),
(@fzrox_id, '2026-02-27',  20.38,  20.05,  20.55,  19.98, 'manual'),
(@fzrox_id, '2026-03-31',  20.65,  20.35,  20.82,  20.28, 'manual'),
(@fzrox_id, '2026-04-30',  20.18,  20.65,  20.90,  19.92, 'manual'),
(@fzrox_id, '2026-05-29',  21.12,  20.15,  21.30,  20.10, 'manual'),
(@fzrox_id, '2026-06-24',  21.35,  21.08,  21.52,  21.02, 'manual');

-- ── Checking account transactions (Jan–Jun 2026) ───────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
-- January
(@acc_chk, 'DEP',  '2026-01-01', 'Acme Corp Payroll',      'deposit',    3850.00,  'reconciled', 'Jan paycheck',         0, @john_id),
(@acc_chk, '1001', '2026-01-02', 'First National Mortgage','withdrawal', -1450.00, 'reconciled', 'Jan mortgage',         0, @john_id),
(@acc_chk, 'EFT',  '2026-01-04', 'City Power & Light',     'withdrawal',  -125.50, 'reconciled', 'Jan electricity',      0, @john_id),
(@acc_chk, 'EFT',  '2026-01-05', 'Municipal Water',        'withdrawal',   -45.00, 'reconciled', 'Jan water',            0, @john_id),
(@acc_chk, 'EFT',  '2026-01-06', 'Comcast',                'withdrawal',   -89.99, 'reconciled', 'Jan internet',         0, @john_id),
(@acc_chk, 'EFT',  '2026-01-07', 'Toyota Financial',       'withdrawal',  -387.50, 'reconciled', 'Car payment',          0, @john_id),
(@acc_chk, '1002', '2026-01-08', 'Kroger',                 'withdrawal',  -187.34, 'reconciled', 'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-01-12', 'Shell Gas Station',      'withdrawal',   -65.00, 'reconciled', 'Gas',                  0, @john_id),
(@acc_chk, '1003', '2026-01-13', 'Dr. Johnson',            'withdrawal',   -40.00, 'reconciled', 'Office visit copay',   0, @john_id),
(@acc_chk, 'EFT',  '2026-01-14', 'Netflix',                'withdrawal',   -22.99, 'reconciled', 'Streaming',            0, @john_id),
(@acc_chk, 'EFT',  '2026-01-15', 'Acme Bank Savings',      'withdrawal',  -200.00, 'reconciled', 'Monthly savings',      0, @john_id),
(@acc_chk, '1004', '2026-01-15', 'Kroger',                 'withdrawal',  -154.22, 'reconciled', 'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-01-18', 'Shell Gas Station',      'withdrawal',   -58.50, 'reconciled', 'Gas',                  0, @john_id),
(@acc_chk, 'DEP',  '2026-01-20', 'Acme Bank Interest',     'deposit',       12.45, 'reconciled', 'Monthly interest',     0, @john_id),
(@acc_chk, '1005', '2026-01-22', 'Target',                 'withdrawal',  -234.67, 'reconciled', 'Household shopping',   0, @john_id),
(@acc_chk, '1006', '2026-01-25', 'Visa Platinum Payment',  'withdrawal',  -500.00, 'reconciled', 'CC payment',           0, @john_id),
(@acc_chk, '1007', '2026-01-28', 'Whole Foods',            'withdrawal',   -98.45, 'reconciled', 'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-01-30', 'Geico Insurance',        'withdrawal',  -112.00, 'reconciled', 'Auto insurance',       0, @john_id),
-- February
(@acc_chk, 'DEP',  '2026-02-01', 'Acme Corp Payroll',      'deposit',    3850.00,  'reconciled', 'Feb paycheck',         0, @john_id),
(@acc_chk, '1008', '2026-02-02', 'First National Mortgage','withdrawal', -1450.00, 'reconciled', 'Feb mortgage',         0, @john_id),
(@acc_chk, 'EFT',  '2026-02-04', 'City Power & Light',     'withdrawal',  -138.75, 'reconciled', 'Feb electricity',      0, @john_id),
(@acc_chk, 'EFT',  '2026-02-05', 'Municipal Water',        'withdrawal',   -45.00, 'reconciled', 'Feb water',            0, @john_id),
(@acc_chk, 'EFT',  '2026-02-06', 'Comcast',                'withdrawal',   -89.99, 'reconciled', 'Feb internet',         0, @john_id),
(@acc_chk, 'EFT',  '2026-02-07', 'Toyota Financial',       'withdrawal',  -387.50, 'reconciled', 'Car payment',          0, @john_id),
(@acc_chk, '1009', '2026-02-08', 'Kroger',                 'withdrawal',  -201.55, 'reconciled', 'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-02-10', 'Shell Gas Station',      'withdrawal',   -62.00, 'reconciled', 'Gas',                  0, @john_id),
(@acc_chk, 'EFT',  '2026-02-14', 'Netflix',                'withdrawal',   -22.99, 'reconciled', 'Streaming',            0, @john_id),
(@acc_chk, '1010', '2026-02-14', 'Restaurant La Belle',    'withdrawal',   -89.50, 'reconciled', 'Valentine''s dinner',  0, @john_id),
(@acc_chk, 'EFT',  '2026-02-15', 'Acme Bank Savings',      'withdrawal',  -200.00, 'reconciled', 'Monthly savings',      0, @john_id),
(@acc_chk, '1011', '2026-02-16', 'Kroger',                 'withdrawal',  -178.33, 'reconciled', 'Groceries',            0, @john_id),
(@acc_chk, 'DEP',  '2026-02-20', 'Acme Bank Interest',     'deposit',       13.10, 'reconciled', 'Monthly interest',     0, @john_id),
(@acc_chk, '1012', '2026-02-22', 'Target',                 'withdrawal',  -189.44, 'reconciled', 'Shopping',             0, @john_id),
(@acc_chk, '1013', '2026-02-25', 'Visa Platinum Payment',  'withdrawal',  -500.00, 'reconciled', 'CC payment',           0, @john_id),
(@acc_chk, '1014', '2026-02-28', 'Home Depot',             'withdrawal',  -145.67, 'reconciled', 'Home maintenance',     0, @john_id),
(@acc_chk, 'EFT',  '2026-02-28', 'Geico Insurance',        'withdrawal',  -112.00, 'reconciled', 'Auto insurance',       0, @john_id),
-- March
(@acc_chk, 'DEP',  '2026-03-01', 'Acme Corp Payroll',      'deposit',    3850.00,  'reconciled', 'Mar paycheck',         0, @john_id),
(@acc_chk, '1015', '2026-03-02', 'First National Mortgage','withdrawal', -1450.00, 'reconciled', 'Mar mortgage',         0, @john_id),
(@acc_chk, 'EFT',  '2026-03-04', 'City Power & Light',     'withdrawal',  -118.25, 'reconciled', 'Mar electricity',      0, @john_id),
(@acc_chk, 'EFT',  '2026-03-05', 'Municipal Water',        'withdrawal',   -45.00, 'reconciled', 'Mar water',            0, @john_id),
(@acc_chk, 'EFT',  '2026-03-06', 'Comcast',                'withdrawal',   -89.99, 'reconciled', 'Mar internet',         0, @john_id),
(@acc_chk, 'EFT',  '2026-03-07', 'Toyota Financial',       'withdrawal',  -387.50, 'reconciled', 'Car payment',          0, @john_id),
(@acc_chk, '1016', '2026-03-10', 'Kroger',                 'withdrawal',  -215.45, 'reconciled', 'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-03-12', 'Shell Gas Station',      'withdrawal',   -70.00, 'reconciled', 'Gas',                  0, @john_id),
(@acc_chk, 'EFT',  '2026-03-14', 'Netflix',                'withdrawal',   -22.99, 'cleared',    'Streaming',            0, @john_id),
(@acc_chk, 'EFT',  '2026-03-15', 'Acme Bank Savings',      'withdrawal',  -200.00, 'cleared',    'Monthly savings',      0, @john_id),
(@acc_chk, '1017', '2026-03-17', 'Starbucks',              'withdrawal',    -8.75, 'cleared',    'Morning coffee',       0, @john_id),
(@acc_chk, '1018', '2026-03-18', 'Amazon',                 'withdrawal',   -67.89, 'cleared',    'Online purchase',      0, @john_id),
(@acc_chk, 'DEP',  '2026-03-20', 'Acme Bank Interest',     'deposit',       14.22, 'cleared',    'Monthly interest',     0, @john_id),
(@acc_chk, '1019', '2026-03-22', 'Whole Foods',            'withdrawal',  -134.56, 'cleared',    'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-03-24', 'Shell Gas Station',      'withdrawal',   -68.00, 'cleared',    'Gas',                  0, @john_id),
(@acc_chk, '1020', '2026-03-25', 'Visa Platinum Payment',  'withdrawal',  -350.00, 'cleared',    'CC payment',           0, @john_id),
(@acc_chk, 'EFT',  '2026-03-30', 'Geico Insurance',        'withdrawal',  -112.00, 'cleared',    'Auto insurance',       0, @john_id),
-- April
(@acc_chk, 'DEP',  '2026-04-01', 'Acme Corp Payroll',      'deposit',    3850.00,  'cleared',    'Apr paycheck',         0, @john_id),
(@acc_chk, '1021', '2026-04-02', 'First National Mortgage','withdrawal', -1450.00, 'cleared',    'Apr mortgage',         0, @john_id),
(@acc_chk, 'EFT',  '2026-04-03', 'City Power & Light',     'withdrawal',  -108.00, 'cleared',    'Apr electricity',      0, @john_id),
(@acc_chk, 'EFT',  '2026-04-05', 'Municipal Water',        'withdrawal',   -45.00, 'cleared',    'Apr water',            0, @john_id),
(@acc_chk, 'EFT',  '2026-04-06', 'Comcast',                'withdrawal',   -89.99, 'cleared',    'Apr internet',         0, @john_id),
(@acc_chk, 'EFT',  '2026-04-07', 'Toyota Financial',       'withdrawal',  -387.50, 'cleared',    'Car payment',          0, @john_id),
(@acc_chk, '1022', '2026-04-09', 'Kroger',                 'withdrawal',  -192.18, 'cleared',    'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-04-14', 'Shell Gas Station',      'withdrawal',   -71.50, 'cleared',    'Gas',                  0, @john_id),
(@acc_chk, 'EFT',  '2026-04-14', 'Netflix',                'withdrawal',   -22.99, 'cleared',    'Streaming',            0, @john_id),
(@acc_chk, 'EFT',  '2026-04-15', 'Acme Bank Savings',      'withdrawal',  -200.00, 'cleared',    'Monthly savings',      0, @john_id),
(@acc_chk, '1023', '2026-04-16', 'Kroger',                 'withdrawal',  -167.44, 'cleared',    'Groceries',            0, @john_id),
(@acc_chk, 'DEP',  '2026-04-20', 'Acme Bank Interest',     'deposit',       13.85, 'cleared',    'Monthly interest',     0, @john_id),
(@acc_chk, '1024', '2026-04-22', 'Home Depot',             'withdrawal',   -89.95, 'cleared',    'Garden supplies',      0, @john_id),
(@acc_chk, '1025', '2026-04-25', 'Visa Platinum Payment',  'withdrawal',  -400.00, 'cleared',    'CC payment',           0, @john_id),
(@acc_chk, '1026', '2026-04-28', 'Whole Foods',            'withdrawal',  -112.30, '',           'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-04-30', 'Geico Insurance',        'withdrawal',  -112.00, '',           'Auto insurance',       0, @john_id),
-- May
(@acc_chk, 'DEP',  '2026-05-01', 'Acme Corp Payroll',      'deposit',    3850.00,  '',           'May paycheck',         0, @john_id),
(@acc_chk, '1027', '2026-05-02', 'First National Mortgage','withdrawal', -1450.00, '',           'May mortgage',         0, @john_id),
(@acc_chk, 'EFT',  '2026-05-04', 'City Power & Light',     'withdrawal',  -112.50, '',           'May electricity',      0, @john_id),
(@acc_chk, 'EFT',  '2026-05-05', 'Municipal Water',        'withdrawal',   -45.00, '',           'May water',            0, @john_id),
(@acc_chk, 'EFT',  '2026-05-06', 'Comcast',                'withdrawal',   -89.99, '',           'May internet',         0, @john_id),
(@acc_chk, 'EFT',  '2026-05-07', 'Toyota Financial',       'withdrawal',  -387.50, '',           'Car payment',          0, @john_id),
(@acc_chk, '1028', '2026-05-08', 'Kroger',                 'withdrawal',  -205.77, '',           'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-05-13', 'Shell Gas Station',      'withdrawal',   -69.00, '',           'Gas',                  0, @john_id),
(@acc_chk, 'EFT',  '2026-05-14', 'Netflix',                'withdrawal',   -22.99, '',           'Streaming',            0, @john_id),
(@acc_chk, 'EFT',  '2026-05-15', 'Acme Bank Savings',      'withdrawal',  -200.00, '',           'Monthly savings',      0, @john_id),
(@acc_chk, '1029', '2026-05-17', 'Kroger',                 'withdrawal',  -188.92, '',           'Groceries',            0, @john_id),
(@acc_chk, 'DEP',  '2026-05-20', 'Acme Bank Interest',     'deposit',       14.68, '',           'Monthly interest',     0, @john_id),
(@acc_chk, '1030', '2026-05-22', 'Target',                 'withdrawal',  -156.33, '',           'Shopping',             0, @john_id),
(@acc_chk, '1031', '2026-05-25', 'Visa Platinum Payment',  'withdrawal',  -450.00, '',           'CC payment',           0, @john_id),
(@acc_chk, 'EFT',  '2026-05-27', 'Shell Gas Station',      'withdrawal',   -72.00, '',           'Gas',                  0, @john_id),
(@acc_chk, 'EFT',  '2026-05-30', 'Geico Insurance',        'withdrawal',  -112.00, '',           'Auto insurance',       0, @john_id),
-- June (through 24th)
(@acc_chk, 'DEP',  '2026-06-01', 'Acme Corp Payroll',      'deposit',    3850.00,  '',           'Jun paycheck',         0, @john_id),
(@acc_chk, '1032', '2026-06-02', 'First National Mortgage','withdrawal', -1450.00, '',           'Jun mortgage',         0, @john_id),
(@acc_chk, 'EFT',  '2026-06-04', 'City Power & Light',     'withdrawal',  -125.00, '',           'Jun electricity',      0, @john_id),
(@acc_chk, 'EFT',  '2026-06-05', 'Municipal Water',        'withdrawal',   -45.00, '',           'Jun water',            0, @john_id),
(@acc_chk, 'EFT',  '2026-06-06', 'Comcast',                'withdrawal',   -89.99, '',           'Jun internet',         0, @john_id),
(@acc_chk, 'EFT',  '2026-06-07', 'Toyota Financial',       'withdrawal',  -387.50, '',           'Car payment',          0, @john_id),
(@acc_chk, '1033', '2026-06-09', 'Kroger',                 'withdrawal',  -198.44, '',           'Groceries',            0, @john_id),
(@acc_chk, 'EFT',  '2026-06-12', 'Shell Gas Station',      'withdrawal',   -74.00, '',           'Gas',                  0, @john_id),
(@acc_chk, 'EFT',  '2026-06-14', 'Netflix',                'withdrawal',   -22.99, '',           'Streaming',            0, @john_id),
(@acc_chk, 'EFT',  '2026-06-15', 'Acme Bank Savings',      'withdrawal',  -200.00, '',           'Monthly savings',      0, @john_id),
(@acc_chk, '1034', '2026-06-18', 'Kroger',                 'withdrawal',  -175.20, '',           'Groceries',            0, @john_id),
(@acc_chk, 'DEP',  '2026-06-20', 'Acme Bank Interest',     'deposit',       15.10, '',           'Monthly interest',     0, @john_id),
(@acc_chk, '1035', '2026-06-22', 'Chase Sapphire Payment', 'withdrawal',  -300.00, '',           'CC payment',           0, @john_id),
(@acc_chk, 'EFT',  '2026-06-23', 'Shell Gas Station',      'withdrawal',   -71.00, '',           'Gas',                  0, @john_id);
SET @t1 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_chk);

-- ── Acme Bank Savings transactions ─────────────────────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_sav, 'EFT', '2026-01-15', 'Transfer from Checking', 'deposit',  200.00, 'reconciled', 'Monthly savings',  0, @john_id),
(@acc_sav, 'DEP', '2026-01-20', 'Acme Bank Interest',     'deposit',   26.04, 'reconciled', 'Jan interest',     0, @john_id),
(@acc_sav, 'EFT', '2026-02-15', 'Transfer from Checking', 'deposit',  200.00, 'reconciled', 'Monthly savings',  0, @john_id),
(@acc_sav, 'DEP', '2026-02-20', 'Acme Bank Interest',     'deposit',   27.35, 'reconciled', 'Feb interest',     0, @john_id),
(@acc_sav, 'EFT', '2026-03-15', 'Transfer from Checking', 'deposit',  200.00, 'reconciled', 'Monthly savings',  0, @john_id),
(@acc_sav, 'DEP', '2026-03-20', 'Acme Bank Interest',     'deposit',   28.12, 'cleared',    'Mar interest',     0, @john_id),
(@acc_sav, 'EFT', '2026-04-15', 'Transfer from Checking', 'deposit',  200.00, 'cleared',    'Monthly savings',  0, @john_id),
(@acc_sav, 'DEP', '2026-04-20', 'Acme Bank Interest',     'deposit',   27.88, 'cleared',    'Apr interest',     0, @john_id),
(@acc_sav, 'EFT', '2026-05-15', 'Transfer from Checking', 'deposit',  200.00, '',           'Monthly savings',  0, @john_id),
(@acc_sav, 'DEP', '2026-05-20', 'Acme Bank Interest',     'deposit',   29.44, '',           'May interest',     0, @john_id),
(@acc_sav, 'EFT', '2026-06-15', 'Transfer from Checking', 'deposit',  200.00, '',           'Monthly savings',  0, @john_id),
(@acc_sav, 'DEP', '2026-06-20', 'Acme Bank Interest',     'deposit',   30.20, '',           'Jun interest',     0, @john_id);
SET @t2 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_sav);

-- ── Ally High-Yield Savings transactions ───────────────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_hys, 'DEP', '2026-01-31', 'Ally Bank Interest',     'deposit',   56.25, 'reconciled', 'Jan interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-02-28', 'Ally Bank Interest',     'deposit',   55.80, 'reconciled', 'Feb interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-03-31', 'Ally Bank Interest',     'deposit',   57.10, 'reconciled', 'Mar interest 4.5% APY', 0, @john_id),
(@acc_hys, 'EFT', '2026-03-15', 'Transfer from Checking', 'deposit', 2000.00, 'reconciled', 'Additional savings',    0, @john_id),
(@acc_hys, 'DEP', '2026-04-30', 'Ally Bank Interest',     'deposit',   64.45, 'cleared',    'Apr interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-05-31', 'Ally Bank Interest',     'deposit',   65.12, '',           'May interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-06-24', 'Ally Bank Interest',     'deposit',   43.80, '',           'Jun interest (partial)', 0, @john_id);
SET @t3 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_hys);

-- ── Visa Platinum Card transactions ────────────────────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
-- January
(@acc_cc1, '', '2026-01-08',  'Amazon',                 'withdrawal', -119.95, 'reconciled', 'Online shopping',     0, @john_id),
(@acc_cc1, '', '2026-01-12',  'Best Buy',               'withdrawal', -299.00, 'reconciled', 'Electronics',         0, @john_id),
(@acc_cc1, '', '2026-01-18',  'Exxon Gas',              'withdrawal',  -52.00, 'reconciled', 'Gas',                 0, @john_id),
(@acc_cc1, '', '2026-01-22',  'CVS Pharmacy',           'withdrawal',  -34.50, 'reconciled', 'Prescriptions',       0, @john_id),
(@acc_cc1, '', '2026-01-26',  'John Smith - Payment',   'deposit',    500.00,  'reconciled', 'CC payment Jan',      0, @john_id),
-- February
(@acc_cc1, '', '2026-02-05',  'Uber',                   'withdrawal',  -34.50, 'reconciled', 'Rideshare',           0, @john_id),
(@acc_cc1, '', '2026-02-10',  'Amazon',                 'withdrawal',  -89.99, 'reconciled', 'Online shopping',     0, @john_id),
(@acc_cc1, '', '2026-02-12',  'REI',                    'withdrawal', -145.75, 'reconciled', 'Outdoor gear',        0, @john_id),
(@acc_cc1, '', '2026-02-20',  'Walgreens',              'withdrawal',  -28.40, 'reconciled', 'Pharmacy',            0, @john_id),
(@acc_cc1, '', '2026-02-25',  'John Smith - Payment',   'deposit',    500.00,  'reconciled', 'CC payment Feb',      0, @john_id),
-- March
(@acc_cc1, '', '2026-03-05',  'Home Depot',             'withdrawal', -178.44, 'reconciled', 'Garden supplies',     0, @john_id),
(@acc_cc1, '', '2026-03-12',  'Amazon',                 'withdrawal',  -67.30, 'reconciled', 'Online shopping',     0, @john_id),
(@acc_cc1, '', '2026-03-15',  'Spotify',                'withdrawal',  -11.99, 'reconciled', 'Music streaming',     0, @john_id),
(@acc_cc1, '', '2026-03-20',  'Restaurant Bella',       'withdrawal',  -78.50, 'cleared',    'Dinner out',          0, @john_id),
(@acc_cc1, '', '2026-03-25',  'John Smith - Payment',   'deposit',    350.00,  'cleared',    'CC payment Mar',      0, @john_id),
-- April
(@acc_cc1, '', '2026-04-03',  'Amazon',                 'withdrawal',  -44.95, 'cleared',    'Online shopping',     0, @john_id),
(@acc_cc1, '', '2026-04-10',  'TJ Maxx',                'withdrawal', -112.60, 'cleared',    'Clothing',            0, @john_id),
(@acc_cc1, '', '2026-04-18',  'Cheesecake Factory',     'withdrawal',  -94.30, 'cleared',    'Anniversary dinner',  0, @john_id),
(@acc_cc1, '', '2026-04-22',  'Spotify',                'withdrawal',  -11.99, '',           'Music streaming',     0, @john_id),
(@acc_cc1, '', '2026-04-25',  'John Smith - Payment',   'deposit',    400.00,  '',           'CC payment Apr',      0, @john_id),
-- May
(@acc_cc1, '', '2026-05-06',  'Amazon',                 'withdrawal', -156.78, '',           'Online shopping',     0, @john_id),
(@acc_cc1, '', '2026-05-11',  'Sporting Goods Outlet',  'withdrawal',  -89.45, '',           'Exercise equipment',  0, @john_id),
(@acc_cc1, '', '2026-05-19',  'Costco Gas',             'withdrawal',  -68.00, '',           'Gas',                 0, @john_id),
(@acc_cc1, '', '2026-05-22',  'Spotify',                'withdrawal',  -11.99, '',           'Music streaming',     0, @john_id),
(@acc_cc1, '', '2026-05-25',  'John Smith - Payment',   'deposit',    450.00,  '',           'CC payment May',      0, @john_id),
-- June
(@acc_cc1, '', '2026-06-02',  'Amazon',                 'withdrawal',  -72.45, '',           'Online shopping',     0, @john_id),
(@acc_cc1, '', '2026-06-10',  'Kohl''s',                'withdrawal',  -88.20, '',           'Clothing',            0, @john_id),
(@acc_cc1, '', '2026-06-15',  'Spotify',                'withdrawal',  -11.99, '',           'Music streaming',     0, @john_id),
(@acc_cc1, '', '2026-06-20',  'Olive Garden',           'withdrawal',  -65.40, '',           'Family dinner',       0, @john_id);
SET @t4 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_cc1);

-- ── Chase Sapphire Reserve transactions ────────────────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
-- January
(@acc_cc2, '', '2026-01-05',  'Delta Airlines',         'withdrawal', -487.00, 'reconciled', 'Spring break flights', 0, @john_id),
(@acc_cc2, '', '2026-01-15',  'Marriott Hotels',        'withdrawal', -324.00, 'reconciled', 'Hotel booking',        0, @john_id),
(@acc_cc2, '', '2026-01-20',  'The Capital Grille',     'withdrawal', -185.40, 'reconciled', 'Client dinner',        0, @john_id),
(@acc_cc2, '', '2026-01-28',  'Chase - Payment',        'deposit',    500.00,  'reconciled', 'CC payment Jan',       0, @john_id),
-- February
(@acc_cc2, '', '2026-02-08',  'United Airlines',        'withdrawal', -612.00, 'reconciled', 'Business trip flights',0, @john_id),
(@acc_cc2, '', '2026-02-09',  'Hilton Hotels',          'withdrawal', -278.00, 'reconciled', 'Business hotel',       0, @john_id),
(@acc_cc2, '', '2026-02-18',  'Ruth''s Chris Steak',    'withdrawal', -148.90, 'reconciled', 'Dinner out',           0, @john_id),
(@acc_cc2, '', '2026-02-25',  'Chase - Payment',        'deposit',    600.00,  'reconciled', 'CC payment Feb',       0, @john_id),
-- March
(@acc_cc2, '', '2026-03-02',  'Lyft',                   'withdrawal',  -42.50, 'reconciled', 'Airport ride',         0, @john_id),
(@acc_cc2, '', '2026-03-10',  'TSA PreCheck',           'withdrawal',  -85.00, 'reconciled', '5-year renewal',       0, @john_id),
(@acc_cc2, '', '2026-03-22',  'Nobu Restaurant',        'withdrawal', -220.75, 'cleared',    'Anniversary dinner',   0, @john_id),
(@acc_cc2, '', '2026-03-28',  'Chase - Payment',        'deposit',    500.00,  'cleared',    'CC payment Mar',       0, @john_id),
-- April
(@acc_cc2, '', '2026-04-12',  'American Airlines',      'withdrawal', -398.00, 'cleared',    'Summer vacation',      0, @john_id),
(@acc_cc2, '', '2026-04-12',  'Airbnb',                 'withdrawal', -742.00, 'cleared',    'Vacation rental',      0, @john_id),
(@acc_cc2, '', '2026-04-25',  'Chase - Payment',        'deposit',    800.00,  '',           'CC payment Apr',       0, @john_id),
-- May
(@acc_cc2, '', '2026-05-03',  'Avis Car Rental',        'withdrawal', -312.50, '',           'Vacation car rental',  0, @john_id),
(@acc_cc2, '', '2026-05-05',  'Universal Studios',      'withdrawal', -485.00, '',           'Vacation activities',  0, @john_id),
(@acc_cc2, '', '2026-05-07',  'La Coquina Restaurant',  'withdrawal', -167.30, '',           'Vacation dining',      0, @john_id),
(@acc_cc2, '', '2026-05-25',  'Chase - Payment',        'deposit',    700.00,  '',           'CC payment May',       0, @john_id),
-- June
(@acc_cc2, '', '2026-06-08',  'Uber Black',             'withdrawal',  -78.40, '',           'Airport transfer',     0, @john_id),
(@acc_cc2, '', '2026-06-18',  'Morton''s Steakhouse',   'withdrawal', -198.50, '',           'Father''s Day dinner',  0, @john_id),
(@acc_cc2, '', '2026-06-22',  'Chase - Payment',        'deposit',    300.00,  '',           'CC payment Jun',       0, @john_id);
SET @t5 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_cc2);

-- ── Investment transactions: Fidelity Brokerage ────────────────────────────
-- Buy 20 VTI @ $275.00 = $5,500.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-01-10', 'Vanguard Total Stock Market ETF', 'investment', 5500.00, 'reconciled', 'Buy 20 shares @ $275.00', @admin_id);
SET @it1 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it1, @vti_id, 'buy', 20.000000, 275.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-01-10', 'Vanguard Total Stock Market ETF', 'transfer', -5500.00, 'reconciled', 'Buy 20 VTI @ $275.00', @it1, @admin_id);
SET @ct1 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct1 WHERE id = @it1;

-- Buy 10 AAPL @ $210.00 = $2,100.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-01-12', 'Apple Inc.', 'investment', 2100.00, 'reconciled', 'Buy 10 shares @ $210.00', @admin_id);
SET @it2 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it2, @aapl_id, 'buy', 10.000000, 210.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-01-12', 'Apple Inc.', 'transfer', -2100.00, 'reconciled', 'Buy 10 AAPL @ $210.00', @it2, @admin_id);
SET @ct2 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct2 WHERE id = @it2;

-- Buy 5 MSFT @ $381.00 = $1,905.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-02-03', 'Microsoft Corporation', 'investment', 1905.00, 'reconciled', 'Buy 5 shares @ $381.00', @admin_id);
SET @it3 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it3, @msft_id, 'buy', 5.000000, 381.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-02-03', 'Microsoft Corporation', 'transfer', -1905.00, 'reconciled', 'Buy 5 MSFT @ $381.00', @it3, @admin_id);
SET @ct3 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct3 WHERE id = @it3;

-- Buy 8 GOOGL @ $176.00 = $1,408.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-02-18', 'Alphabet Inc.', 'investment', 1408.00, 'reconciled', 'Buy 8 shares @ $176.00', @admin_id);
SET @it4 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it4, @googl_id, 'buy', 8.000000, 176.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-02-18', 'Alphabet Inc.', 'transfer', -1408.00, 'reconciled', 'Buy 8 GOOGL @ $176.00', @it4, @admin_id);
SET @ct4 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct4 WHERE id = @it4;

-- Sell 4 AAPL @ $218.50 = $874.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-03-15', 'Apple Inc.', 'investment', -874.00, 'reconciled', 'Sell 4 shares @ $218.50', @admin_id);
SET @it5 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it5, @aapl_id, 'sell', 4.000000, 218.500000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-03-15', 'Apple Inc.', 'transfer', 874.00, 'reconciled', 'Sell 4 AAPL @ $218.50', @it5, @admin_id);
SET @ct5 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct5 WHERE id = @it5;

-- Buy 25 BND @ $74.00 = $1,850.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-04-08', 'Vanguard Total Bond Market ETF', 'investment', 1850.00, 'cleared', 'Buy 25 shares @ $74.00', @admin_id);
SET @it6 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it6, @bnd_id, 'buy', 25.000000, 74.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-04-08', 'Vanguard Total Bond Market ETF', 'transfer', -1850.00, 'cleared', 'Buy 25 BND @ $74.00', @it6, @admin_id);
SET @ct6 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct6 WHERE id = @it6;

-- Buy 10 AAPL @ $225.00 = $2,250.00 (adding to position)
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-05-20', 'Apple Inc.', 'investment', 2250.00, '', 'Buy 10 shares @ $225.00', @admin_id);
SET @it7 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it7, @aapl_id, 'buy', 10.000000, 225.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-05-20', 'Apple Inc.', 'transfer', -2250.00, '', 'Buy 10 AAPL @ $225.00', @it7, @admin_id);
SET @ct7 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct7 WHERE id = @it7;

-- VTI dividend reinvested: 0.6 shares @ $291.00 = $174.60
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-06-15', 'Vanguard Total Stock Market ETF', 'investment', 174.60, '', 'Dividend reinvested 0.6 shares @ $291.00', @admin_id);
SET @it8 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@it8, @vti_id, 'reinvest_div', 0.600000, 291.000000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-06-15', 'Vanguard Total Stock Market ETF', 'transfer', -174.60, '', 'VTI dividend reinvestment', @it8, @admin_id);
SET @ct8 = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ct8 WHERE id = @it8;

-- ── Investment transactions: Fidelity Roth IRA ─────────────────────────────
-- Buy 349 FZROX @ $20.05 = $6,997.45 (2026 IRA contribution, max $7,000)
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_ira, '2026-01-08', 'Fidelity ZERO Total Market Index', 'investment', 6997.45, 'reconciled', '2026 Roth IRA contribution — 349 shares @ $20.05', @admin_id);
SET @ir1 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@ir1, @fzrox_id, 'buy', 349.000000, 20.050000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_ira_cash, '2026-01-08', 'Fidelity ZERO Total Market Index', 'transfer', -6997.45, 'reconciled', '2026 Roth contribution buy FZROX', @ir1, @admin_id);
SET @ir1c = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ir1c WHERE id = @ir1;

-- Buy additional 1 FZROX @ $20.05 = $20.05 (remaining $2.55 stays as cash)
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_ira, '2026-03-20', 'Fidelity ZERO Total Market Index', 'investment', 20.62, 'reconciled', 'Reinvested dividends 1 share @ $20.62', @admin_id);
SET @ir2 = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@ir2, @fzrox_id, 'reinvest_div', 1.000000, 20.620000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_ira_cash, '2026-03-20', 'Fidelity ZERO Total Market Index', 'transfer', -20.62, 'reconciled', 'FZROX dividend reinvestment', @ir2, @admin_id);
SET @ir2c = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @ir2c WHERE id = @ir2;

-- ── Transaction splits: checking account ────────────────────────────────────
-- Row offsets match the 94-row INSERT above (0-based from @t1).
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
-- January (rows 0–17)
(@t1+0,  @income,        @salary,      3850.00),
(@t1+1,  @housing,       @mortgage,   -1450.00),
(@t1+2,  @housing,       @utilities,   -125.50),
(@t1+3,  @housing,       @utilities,    -45.00),
(@t1+4,  @housing,       @utilities,    -89.99),
(@t1+5,  @transport,     @car_pay,     -387.50),
(@t1+6,  @food,          @groceries,   -187.34),
(@t1+7,  @transport,     @gas_fuel,     -65.00),
(@t1+8,  @healthcare,    @doctor,       -40.00),
(@t1+9,  @entertainment, @streaming,    -22.99),
(@t1+10, @financial,     @sav_transfer,-200.00),
(@t1+11, @food,          @groceries,   -154.22),
(@t1+12, @transport,     @gas_fuel,     -58.50),
(@t1+13, @income,        @interest_in,   12.45),
(@t1+14, @shopping,      NULL,         -234.67),
(@t1+15, @financial,     @cc_payment,  -500.00),
(@t1+16, @food,          @groceries,    -98.45),
(@t1+17, @transport,     @auto_ins,    -112.00),
-- February (rows 18–35)
(@t1+18, @income,        @salary,      3850.00),
(@t1+19, @housing,       @mortgage,   -1450.00),
(@t1+20, @housing,       @utilities,   -138.75),
(@t1+21, @housing,       @utilities,    -45.00),
(@t1+22, @housing,       @utilities,    -89.99),
(@t1+23, @transport,     @car_pay,     -387.50),
(@t1+24, @food,          @groceries,   -201.55),
(@t1+25, @transport,     @gas_fuel,     -62.00),
(@t1+26, @entertainment, @streaming,    -22.99),
(@t1+27, @food,          @restaurants,  -89.50),
(@t1+28, @financial,     @sav_transfer,-200.00),
(@t1+29, @food,          @groceries,   -178.33),
(@t1+30, @income,        @interest_in,   13.10),
(@t1+31, @shopping,      NULL,         -189.44),
(@t1+32, @financial,     @cc_payment,  -500.00),
(@t1+33, @housing,       @home_maint,  -145.67),
(@t1+34, @transport,     @auto_ins,    -112.00),
-- March (rows 36–52)
(@t1+35, @income,        @salary,      3850.00),
(@t1+36, @housing,       @mortgage,   -1450.00),
(@t1+37, @housing,       @utilities,   -118.25),
(@t1+38, @housing,       @utilities,    -45.00),
(@t1+39, @housing,       @utilities,    -89.99),
(@t1+40, @transport,     @car_pay,     -387.50),
(@t1+41, @food,          @groceries,   -215.45),
(@t1+42, @transport,     @gas_fuel,     -70.00),
(@t1+43, @entertainment, @streaming,    -22.99),
(@t1+44, @financial,     @sav_transfer,-200.00),
(@t1+45, @food,          @coffee,        -8.75),
(@t1+46, @shopping,      @electronics,  -67.89),
(@t1+47, @income,        @interest_in,   14.22),
(@t1+48, @food,          @groceries,   -134.56),
(@t1+49, @transport,     @gas_fuel,     -68.00),
(@t1+50, @financial,     @cc_payment,  -350.00),
(@t1+51, @transport,     @auto_ins,    -112.00),
-- April (rows 53–68)
(@t1+52, @income,        @salary,      3850.00),
(@t1+53, @housing,       @mortgage,   -1450.00),
(@t1+54, @housing,       @utilities,   -108.00),
(@t1+55, @housing,       @utilities,    -45.00),
(@t1+56, @housing,       @utilities,    -89.99),
(@t1+57, @transport,     @car_pay,     -387.50),
(@t1+58, @food,          @groceries,   -192.18),
(@t1+59, @transport,     @gas_fuel,     -71.50),
(@t1+60, @entertainment, @streaming,    -22.99),
(@t1+61, @financial,     @sav_transfer,-200.00),
(@t1+62, @food,          @groceries,   -167.44),
(@t1+63, @income,        @interest_in,   13.85),
(@t1+64, @housing,       @home_maint,   -89.95),
(@t1+65, @financial,     @cc_payment,  -400.00),
(@t1+66, @food,          @groceries,   -112.30),
(@t1+67, @transport,     @auto_ins,    -112.00),
-- May (rows 69–84)
(@t1+68, @income,        @salary,      3850.00),
(@t1+69, @housing,       @mortgage,   -1450.00),
(@t1+70, @housing,       @utilities,   -112.50),
(@t1+71, @housing,       @utilities,    -45.00),
(@t1+72, @housing,       @utilities,    -89.99),
(@t1+73, @transport,     @car_pay,     -387.50),
(@t1+74, @food,          @groceries,   -205.77),
(@t1+75, @transport,     @gas_fuel,     -69.00),
(@t1+76, @entertainment, @streaming,    -22.99),
(@t1+77, @financial,     @sav_transfer,-200.00),
(@t1+78, @food,          @groceries,   -188.92),
(@t1+79, @income,        @interest_in,   14.68),
(@t1+80, @shopping,      NULL,         -156.33),
(@t1+81, @financial,     @cc_payment,  -450.00),
(@t1+82, @transport,     @gas_fuel,     -72.00),
(@t1+83, @transport,     @auto_ins,    -112.00),
-- June (rows 85–93... through row 93 = @t1+93 for the last Jun entry)
(@t1+84, @income,        @salary,      3850.00),
(@t1+85, @housing,       @mortgage,   -1450.00),
(@t1+86, @housing,       @utilities,   -125.00),
(@t1+87, @housing,       @utilities,    -45.00),
(@t1+88, @housing,       @utilities,    -89.99),
(@t1+89, @transport,     @car_pay,     -387.50),
(@t1+90, @food,          @groceries,   -198.44),
(@t1+91, @transport,     @gas_fuel,     -74.00),
(@t1+92, @entertainment, @streaming,    -22.99),
(@t1+93, @financial,     @sav_transfer,-200.00),
(@t1+94, @food,          @groceries,   -175.20),
(@t1+95, @income,        @interest_in,   15.10),
(@t1+96, @financial,     @cc_payment,  -300.00),
(@t1+97, @transport,     @gas_fuel,     -71.00);

-- ── Transaction splits: Acme Bank Savings (12 rows) ────────────────────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t2+0,  @financial, @sav_transfer, 200.00),
(@t2+1,  @income,    @interest_in,   26.04),
(@t2+2,  @financial, @sav_transfer, 200.00),
(@t2+3,  @income,    @interest_in,   27.35),
(@t2+4,  @financial, @sav_transfer, 200.00),
(@t2+5,  @income,    @interest_in,   28.12),
(@t2+6,  @financial, @sav_transfer, 200.00),
(@t2+7,  @income,    @interest_in,   27.88),
(@t2+8,  @financial, @sav_transfer, 200.00),
(@t2+9,  @income,    @interest_in,   29.44),
(@t2+10, @financial, @sav_transfer, 200.00),
(@t2+11, @income,    @interest_in,   30.20);

-- ── Transaction splits: Ally High-Yield Savings (7 rows) ───────────────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t3+0, @income,    @interest_in,     56.25),
(@t3+1, @income,    @interest_in,     55.80),
(@t3+2, @income,    @interest_in,     57.10),
(@t3+3, @financial, @sav_transfer,  2000.00),
(@t3+4, @income,    @interest_in,     64.45),
(@t3+5, @income,    @interest_in,     65.12),
(@t3+6, @income,    @interest_in,     43.80);

-- ── Transaction splits: Visa Platinum Card (29 rows) ───────────────────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
-- January (rows 0-4)
(@t4+0,  @shopping,      NULL,             -119.95),
(@t4+1,  @shopping,      @electronics,     -299.00),
(@t4+2,  @transport,     @gas_fuel,         -52.00),
(@t4+3,  @healthcare,    @prescriptions,    -34.50),
(@t4+4,  @financial,     @cc_payment,       500.00),
-- February (rows 5-9)
(@t4+5,  @transport,     NULL,              -34.50),
(@t4+6,  @shopping,      NULL,              -89.99),
(@t4+7,  @entertainment, @hobbies,         -145.75),
(@t4+8,  @healthcare,    @prescriptions,    -28.40),
(@t4+9,  @financial,     @cc_payment,       500.00),
-- March (rows 10-14)
(@t4+10, @housing,       @home_maint,      -178.44),
(@t4+11, @shopping,      NULL,              -67.30),
(@t4+12, @entertainment, @streaming,        -11.99),
(@t4+13, @food,          @restaurants,      -78.50),
(@t4+14, @financial,     @cc_payment,       350.00),
-- April (rows 15-19)
(@t4+15, @shopping,      NULL,              -44.95),
(@t4+16, @shopping,      @clothing,        -112.60),
(@t4+17, @food,          @restaurants,      -94.30),
(@t4+18, @entertainment, @streaming,        -11.99),
(@t4+19, @financial,     @cc_payment,       400.00),
-- May (rows 20-24)
(@t4+20, @shopping,      NULL,             -156.78),
(@t4+21, @entertainment, @sports_fitness,   -89.45),
(@t4+22, @transport,     @gas_fuel,         -68.00),
(@t4+23, @entertainment, @streaming,        -11.99),
(@t4+24, @financial,     @cc_payment,       450.00),
-- June (rows 25-28)
(@t4+25, @shopping,      NULL,              -72.45),
(@t4+26, @shopping,      @clothing,         -88.20),
(@t4+27, @entertainment, @streaming,        -11.99),
(@t4+28, @food,          @restaurants,      -65.40);

-- ── Transaction splits: Chase Sapphire Reserve (22 rows) ───────────────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
-- January (rows 0-3)
(@t5+0,  @travel,        @flights,               -487.00),
(@t5+1,  @travel,        @hotels,                -324.00),
(@t5+2,  @business,      @business_meals,        -185.40),
(@t5+3,  @financial,     @cc_payment,             500.00),
-- February (rows 4-7)
(@t5+4,  @travel,        @flights,               -612.00),
(@t5+5,  @travel,        @hotels,                -278.00),
(@t5+6,  @food,          @restaurants,           -148.90),
(@t5+7,  @financial,     @cc_payment,             600.00),
-- March (rows 8-11)
(@t5+8,  @transport,     NULL,                    -42.50),
(@t5+9,  @travel,        NULL,                    -85.00),
(@t5+10, @food,          @restaurants,           -220.75),
(@t5+11, @financial,     @cc_payment,             500.00),
-- April (rows 12-14)
(@t5+12, @travel,        @flights,               -398.00),
(@t5+13, @travel,        @hotels,                -742.00),
(@t5+14, @financial,     @cc_payment,             800.00),
-- May (rows 15-18)
(@t5+15, @travel,        @vacation_activities,   -312.50),
(@t5+16, @travel,        @vacation_activities,   -485.00),
(@t5+17, @food,          @restaurants,           -167.30),
(@t5+18, @financial,     @cc_payment,             700.00),
-- June (rows 19-21)
(@t5+19, @transport,     NULL,                    -78.40),
(@t5+20, @food,          @restaurants,           -198.50),
(@t5+21, @financial,     @cc_payment,             300.00);

-- ── Scheduled bills ────────────────────────────────────────────────────────
INSERT INTO scheduled_bills (name, type, account_id, category_id, subcategory_id, amount, frequency, next_due_date, notes) VALUES
('Mortgage Payment',       'bill',    @acc_chk, @housing,       @mortgage,    -1450.00, 'monthly', '2026-07-02', 'First National Mortgage'),
('Toyota Car Payment',     'bill',    @acc_chk, @transport,     @car_pay,      -387.50, 'monthly', '2026-07-07', 'Toyota Financial Services'),
('Geico Auto Insurance',   'bill',    @acc_chk, @transport,     @auto_ins,     -112.00, 'monthly', '2026-07-30', 'Auto insurance'),
('Comcast Internet',       'bill',    @acc_chk, @housing,       @utilities,     -89.99, 'monthly', '2026-07-06', 'Internet and cable'),
('Netflix',                'bill',    @acc_chk, @entertainment, @streaming,     -22.99, 'monthly', '2026-07-14', 'Streaming service'),
('Acme Payroll',           'deposit', @acc_chk, @income,        @salary,       3850.00, 'monthly', '2026-07-01', 'Monthly salary deposit'),
('Savings Transfer',       'bill',    @acc_chk, @financial,     @sav_transfer, -200.00, 'monthly', '2026-07-15', 'Transfer to Acme savings'),
('Spotify Premium',        'bill',    @acc_cc1, @entertainment, @streaming,     -11.99, 'monthly', '2026-07-22', 'Music streaming'),
('Roth IRA Contribution',  'deposit', @acc_ira_cash, NULL,      NULL,           583.33, 'monthly', '2026-07-01', '~$7,000/yr max spread monthly');

-- ── Savings goals ──────────────────────────────────────────────────────────
INSERT INTO savings_goals (name, target_amount, current_amount, target_date, account_id, notes, created_by) VALUES
('Emergency Fund (6 months)', 25000.00, 12500.00, '2027-06-01', @acc_sav,
 'Build to 6 months of expenses (~$4,167/mo)', @admin_id),
('Vacation Fund',              5000.00,  2000.00, '2026-12-01', @acc_hys,
 'Summer 2027 Europe trip', @admin_id),
('New Roof',                   8500.00,  1500.00, '2027-03-01', @acc_hys,
 'Roof replacement estimated $8,500', @admin_id);

-- Sample data for Steward
-- Passwords: admin=Admin123!  john=John123!  viewer=View123!
-- Covers Jul 2024–Jun 2026 (24 months). Includes banking, credit, investment,
-- and a $600,000 mortgage/loan account.

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

-- ── Loan account: Home Mortgage ($600,000, 30yr fixed @ 6.5%) ──────────
INSERT INTO accounts (name, type, institution, account_number, routing_number, is_favorite, currency, opening_balance, comment, created_by) VALUES
('Home Mortgage', 'Loan', 'First National Mortgage', '****5521', '', 1, 'USD', -600000.00, 'Home purchased 2025-04-12 — 30-year fixed @ 6.5% APR', @admin_id);
SET @acc_loan = LAST_INSERT_ID();
INSERT INTO loan_details (account_id, original_amount, annual_rate, term_months, start_date, payment_amount) VALUES (@acc_loan, 600000.00, 6.5000, 360, '2025-04-12', 3792.41);

-- ── Asset account: the home securing the mortgage above ─────────────────
INSERT INTO accounts (name, type, institution, account_number, routing_number, comment, is_favorite, min_balance, currency, opening_balance, exclude_from_net_worth, created_by) VALUES
('Our Home', 'Asset', '', '', '', 'Home purchased 2025-04-12', 1, 0.00, 'USD', 750000.00, 0, @admin_id);
SET @acc_home = LAST_INSERT_ID();

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
-- ── Additional securities (fictional prices/quantities) ────────────────
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('International Business Machines Corp.', 'IBM', 'Stock', 'US', 'Enterprise IT, hybrid cloud & consulting', @admin_id);
SET @ibm_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Netflix, Inc.', 'NFLX', 'Stock', 'US', 'Streaming video & entertainment', @admin_id);
SET @nflx_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('Oracle Corporation', 'ORCL', 'Stock', 'US', 'Enterprise software & cloud infrastructure', @admin_id);
SET @orcl_id = LAST_INSERT_ID();
INSERT INTO investments (name, symbol, type, country, memo, created_by) VALUES
('GE Aerospace', 'GE', 'Stock', 'US', 'Commercial & military jet engines', @admin_id);
SET @ge_id = LAST_INSERT_ID();


-- ── Investment price history: new securities (Jul 2024–Jun 2026) ──────
INSERT INTO investment_prices (investment_id, price_date, close_price, open_price, high_price, low_price, source) VALUES
-- IBM
(@ibm_id, '2024-07-31', 168.00, 167.35, 168.46, 166.89, 'manual'),
(@ibm_id, '2024-08-31', 172.72, 171.96, 173.18, 171.50, 'manual'),
(@ibm_id, '2024-09-30', 175.94, 175.42, 176.40, 174.96, 'manual'),
(@ibm_id, '2024-10-31', 177.80, 177.77, 178.26, 177.31, 'manual'),
(@ibm_id, '2024-11-30', 179.93, 180.40, 180.86, 179.47, 'manual'),
(@ibm_id, '2024-12-31', 183.65, 184.40, 184.86, 183.19, 'manual'),
(@ibm_id, '2025-01-31', 188.57, 189.25, 189.71, 188.11, 'manual'),
(@ibm_id, '2025-02-28', 192.96, 193.25, 193.71, 192.50, 'manual'),
(@ibm_id, '2025-03-31', 195.68, 195.44, 196.14, 194.98, 'manual'),
(@ibm_id, '2025-04-30', 197.43, 196.78, 197.89, 196.32, 'manual'),
(@ibm_id, '2025-05-31', 199.94, 199.18, 200.40, 198.72, 'manual'),
(@ibm_id, '2025-06-30', 204.13, 203.62, 204.59, 203.16, 'manual'),
(@ibm_id, '2025-07-31', 209.09, 209.07, 209.55, 208.61, 'manual'),
(@ibm_id, '2025-08-31', 213.04, 213.52, 213.98, 212.58, 'manual'),
(@ibm_id, '2025-09-30', 215.34, 216.10, 216.56, 214.88, 'manual'),
(@ibm_id, '2025-10-31', 217.12, 217.79, 218.25, 216.66, 'manual'),
(@ibm_id, '2025-11-30', 220.10, 220.38, 220.84, 219.64, 'manual'),
(@ibm_id, '2025-12-31', 224.68, 224.43, 225.14, 223.97, 'manual'),
(@ibm_id, '2026-01-31', 229.51, 228.85, 229.97, 228.39, 'manual'),
(@ibm_id, '2026-02-28', 232.97, 232.21, 233.43, 231.75, 'manual'),
(@ibm_id, '2026-03-31', 234.94, 234.44, 235.40, 233.98, 'manual'),
(@ibm_id, '2026-04-30', 236.93, 236.92, 237.39, 236.46, 'manual'),
(@ibm_id, '2026-05-31', 240.42, 240.91, 241.37, 239.96, 'manual'),
(@ibm_id, '2026-06-24', 245.26, 246.02, 246.48, 244.80, 'manual'),
-- NFLX
(@nflx_id, '2024-07-31', 620.00, 616.97, 622.16, 614.81, 'manual'),
(@nflx_id, '2024-08-31', 642.07, 638.50, 644.23, 636.34, 'manual'),
(@nflx_id, '2024-09-30', 657.13, 654.70, 659.29, 652.54, 'manual'),
(@nflx_id, '2024-10-31', 665.82, 665.67, 667.98, 663.51, 'manual'),
(@nflx_id, '2024-11-30', 675.76, 677.96, 680.12, 673.60, 'manual'),
(@nflx_id, '2024-12-31', 693.18, 696.70, 698.86, 691.02, 'manual'),
(@nflx_id, '2025-01-31', 716.16, 719.34, 721.50, 714.00, 'manual'),
(@nflx_id, '2025-02-28', 736.68, 738.03, 740.19, 734.52, 'manual'),
(@nflx_id, '2025-03-31', 749.43, 748.31, 751.59, 746.15, 'manual'),
(@nflx_id, '2025-04-30', 757.58, 754.52, 759.74, 752.36, 'manual'),
(@nflx_id, '2025-05-31', 769.32, 765.76, 771.48, 763.60, 'manual'),
(@nflx_id, '2025-06-30', 788.94, 786.55, 791.10, 784.39, 'manual'),
(@nflx_id, '2025-07-31', 812.09, 812.00, 814.25, 809.84, 'manual'),
(@nflx_id, '2025-08-31', 830.58, 832.83, 834.99, 828.42, 'manual'),
(@nflx_id, '2025-09-30', 841.31, 844.84, 847.00, 839.15, 'manual'),
(@nflx_id, '2025-10-31', 849.66, 852.81, 854.97, 847.50, 'manual'),
(@nflx_id, '2025-11-30', 863.60, 864.89, 867.05, 861.44, 'manual'),
(@nflx_id, '2025-12-31', 885.01, 883.83, 887.17, 881.67, 'manual'),
(@nflx_id, '2026-01-31', 907.60, 904.51, 909.76, 902.35, 'manual'),
(@nflx_id, '2026-02-28', 923.78, 920.23, 925.94, 918.07, 'manual'),
(@nflx_id, '2026-03-31', 932.98, 930.64, 935.14, 928.48, 'manual'),
(@nflx_id, '2026-04-30', 942.25, 942.22, 944.41, 940.06, 'manual'),
(@nflx_id, '2026-05-31', 958.56, 960.86, 963.02, 956.40, 'manual'),
(@nflx_id, '2026-06-24', 981.20, 984.74, 986.90, 979.04, 'manual'),
-- ORCL
(@orcl_id, '2024-07-31', 135.00, 134.37, 135.45, 133.92, 'manual'),
(@orcl_id, '2024-08-31', 139.60, 138.86, 140.05, 138.41, 'manual'),
(@orcl_id, '2024-09-30', 142.73, 142.22, 143.18, 141.77, 'manual'),
(@orcl_id, '2024-10-31', 144.55, 144.52, 145.00, 144.07, 'manual'),
(@orcl_id, '2024-11-30', 146.62, 147.08, 147.53, 146.17, 'manual'),
(@orcl_id, '2024-12-31', 150.25, 150.98, 151.43, 149.80, 'manual'),
(@orcl_id, '2025-01-31', 155.03, 155.69, 156.14, 154.58, 'manual'),
(@orcl_id, '2025-02-28', 159.31, 159.59, 160.04, 158.86, 'manual'),
(@orcl_id, '2025-03-31', 161.96, 161.73, 162.41, 161.28, 'manual'),
(@orcl_id, '2025-04-30', 163.66, 163.02, 164.11, 162.57, 'manual'),
(@orcl_id, '2025-05-31', 166.11, 165.37, 166.56, 164.92, 'manual'),
(@orcl_id, '2025-06-30', 170.20, 169.70, 170.65, 169.25, 'manual'),
(@orcl_id, '2025-07-31', 175.02, 175.00, 175.47, 174.55, 'manual'),
(@orcl_id, '2025-08-31', 178.87, 179.34, 179.79, 178.42, 'manual'),
(@orcl_id, '2025-09-30', 181.11, 181.85, 182.30, 180.66, 'manual'),
(@orcl_id, '2025-10-31', 182.85, 183.51, 183.96, 182.40, 'manual'),
(@orcl_id, '2025-11-30', 185.75, 186.02, 186.47, 185.30, 'manual'),
(@orcl_id, '2025-12-31', 190.21, 189.96, 190.66, 189.51, 'manual'),
(@orcl_id, '2026-01-31', 194.92, 194.28, 195.37, 193.83, 'manual'),
(@orcl_id, '2026-02-28', 198.29, 197.55, 198.74, 197.10, 'manual'),
(@orcl_id, '2026-03-31', 200.20, 199.71, 200.65, 199.26, 'manual'),
(@orcl_id, '2026-04-30', 202.14, 202.13, 202.59, 201.68, 'manual'),
(@orcl_id, '2026-05-31', 205.53, 206.01, 206.46, 205.08, 'manual'),
(@orcl_id, '2026-06-24', 210.25, 210.99, 211.44, 209.80, 'manual'),
-- GE
(@ge_id, '2024-07-31', 165.00, 164.45, 165.39, 164.06, 'manual'),
(@ge_id, '2024-08-31', 168.98, 168.34, 169.37, 167.95, 'manual'),
(@ge_id, '2024-09-30', 171.70, 171.26, 172.09, 170.87, 'manual'),
(@ge_id, '2024-10-31', 173.27, 173.24, 173.66, 172.85, 'manual'),
(@ge_id, '2024-11-30', 175.07, 175.47, 175.86, 174.68, 'manual'),
(@ge_id, '2024-12-31', 178.21, 178.85, 179.24, 177.82, 'manual'),
(@ge_id, '2025-01-31', 182.36, 182.93, 183.32, 181.97, 'manual'),
(@ge_id, '2025-02-28', 186.07, 186.31, 186.70, 185.68, 'manual'),
(@ge_id, '2025-03-31', 188.37, 188.17, 188.76, 187.78, 'manual'),
(@ge_id, '2025-04-30', 189.84, 189.29, 190.23, 188.90, 'manual'),
(@ge_id, '2025-05-31', 191.96, 191.32, 192.35, 190.93, 'manual'),
(@ge_id, '2025-06-30', 195.50, 195.07, 195.89, 194.68, 'manual'),
(@ge_id, '2025-07-31', 199.68, 199.66, 200.07, 199.27, 'manual'),
(@ge_id, '2025-08-31', 203.02, 203.43, 203.82, 202.63, 'manual'),
(@ge_id, '2025-09-30', 204.96, 205.60, 205.99, 204.57, 'manual'),
(@ge_id, '2025-10-31', 206.47, 207.04, 207.43, 206.08, 'manual'),
(@ge_id, '2025-11-30', 208.98, 209.21, 209.60, 208.59, 'manual'),
(@ge_id, '2025-12-31', 212.85, 212.64, 213.24, 212.25, 'manual'),
(@ge_id, '2026-01-31', 216.93, 216.37, 217.32, 215.98, 'manual'),
(@ge_id, '2026-02-28', 219.85, 219.21, 220.24, 218.82, 'manual'),
(@ge_id, '2026-03-31', 221.51, 221.09, 221.90, 220.70, 'manual'),
(@ge_id, '2026-04-30', 223.18, 223.17, 223.57, 222.78, 'manual'),
(@ge_id, '2026-05-31', 226.13, 226.54, 226.93, 225.74, 'manual'),
(@ge_id, '2026-06-24', 230.22, 230.86, 231.25, 229.83, 'manual');

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

-- ── Checking account transactions (Jul 2024–Dec 2025) ──────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_chk, 'DEP', '2024-07-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Jul paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Jul rent', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-04', 'City Power & Light', 'withdrawal', -120.00, 'reconciled', 'Jul electricity', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Jul water', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Jul internet', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '910', '2024-07-09', 'Kroger', 'withdrawal', -190.00, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-11', 'Shell Gas Station', 'withdrawal', -70.73, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '911', '2024-07-16', 'Kroger', 'withdrawal', -186.78, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2024-07-20', 'Acme Bank Interest', 'deposit', 6.00, 'reconciled', 'Jul interest', 0, @john_id),
(@acc_chk, '912', '2024-07-22', 'Target', 'withdrawal', -234.56, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '913', '2024-07-25', 'Visa Platinum Payment', 'withdrawal', -443.64, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '914', '2024-07-28', 'Whole Foods', 'withdrawal', -110.00, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-07-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2024-08-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Aug paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Aug rent', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-04', 'City Power & Light', 'withdrawal', -134.45, 'reconciled', 'Aug electricity', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Aug water', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Aug internet', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '915', '2024-08-09', 'Kroger', 'withdrawal', -228.54, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-11', 'Shell Gas Station', 'withdrawal', -73.91, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '916', '2024-08-16', 'Kroger', 'withdrawal', -204.08, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2024-08-20', 'Acme Bank Interest', 'deposit', 7.18, 'reconciled', 'Aug interest', 0, @john_id),
(@acc_chk, '917', '2024-08-22', 'Target', 'withdrawal', -223.77, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '918', '2024-08-25', 'Visa Platinum Payment', 'withdrawal', -499.97, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '919', '2024-08-28', 'Home Depot', 'withdrawal', -158.54, 'reconciled', 'Home maintenance', 0, @john_id),
(@acc_chk, 'EFT', '2024-08-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2024-09-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Sep paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Sep rent', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-04', 'City Power & Light', 'withdrawal', -127.73, 'reconciled', 'Sep electricity', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Sep water', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Sep internet', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '920', '2024-09-09', 'Kroger', 'withdrawal', -210.62, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-11', 'Shell Gas Station', 'withdrawal', -61.11, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '921', '2024-09-16', 'Kroger', 'withdrawal', -171.46, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2024-09-20', 'Acme Bank Interest', 'deposit', 8.35, 'reconciled', 'Sep interest', 0, @john_id),
(@acc_chk, '922', '2024-09-22', 'Target', 'withdrawal', -163.51, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '923', '2024-09-25', 'Visa Platinum Payment', 'withdrawal', -439.14, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '924', '2024-09-17', 'Dr. Johnson', 'withdrawal', -40.00, 'reconciled', 'Office visit copay', 0, @john_id),
(@acc_chk, 'EFT', '2024-09-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2024-10-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Oct paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Oct rent', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-04', 'City Power & Light', 'withdrawal', -109.68, 'reconciled', 'Oct electricity', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Oct water', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Oct internet', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '925', '2024-10-09', 'Kroger', 'withdrawal', -162.49, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-11', 'Shell Gas Station', 'withdrawal', -51.08, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '926', '2024-10-16', 'Kroger', 'withdrawal', -136.69, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2024-10-20', 'Acme Bank Interest', 'deposit', 9.53, 'reconciled', 'Oct interest', 0, @john_id),
(@acc_chk, '927', '2024-10-22', 'Target', 'withdrawal', -142.05, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '928', '2024-10-25', 'Visa Platinum Payment', 'withdrawal', -350.27, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '929', '2024-10-17', 'Starbucks', 'withdrawal', -8.75, 'reconciled', 'Morning coffee', 0, @john_id),
(@acc_chk, 'EFT', '2024-10-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2024-11-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Nov paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Nov rent', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-04', 'City Power & Light', 'withdrawal', -106.75, 'reconciled', 'Nov electricity', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Nov water', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Nov internet', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '930', '2024-11-09', 'Kroger', 'withdrawal', -154.66, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-11', 'Shell Gas Station', 'withdrawal', -58.51, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '931', '2024-11-16', 'Kroger', 'withdrawal', -150.73, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2024-11-20', 'Acme Bank Interest', 'deposit', 10.71, 'reconciled', 'Nov interest', 0, @john_id),
(@acc_chk, '932', '2024-11-22', 'Target', 'withdrawal', -190.84, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '933', '2024-11-25', 'Visa Platinum Payment', 'withdrawal', -363.56, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '934', '2024-11-18', 'Amazon', 'withdrawal', -52.33, 'reconciled', 'Online purchase', 0, @john_id),
(@acc_chk, 'EFT', '2024-11-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2024-12-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Dec paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Dec rent', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-04', 'City Power & Light', 'withdrawal', -123.23, 'reconciled', 'Dec electricity', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Dec water', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Dec internet', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '935', '2024-12-09', 'Kroger', 'withdrawal', -198.60, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-11', 'Shell Gas Station', 'withdrawal', -72.52, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '936', '2024-12-16', 'Kroger', 'withdrawal', -192.99, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2024-12-20', 'Acme Bank Interest', 'deposit', 11.88, 'reconciled', 'Dec interest', 0, @john_id),
(@acc_chk, '937', '2024-12-22', 'Target', 'withdrawal', -238.40, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '938', '2024-12-25', 'Visa Platinum Payment', 'withdrawal', -459.53, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '939', '2024-12-28', 'Whole Foods', 'withdrawal', -115.38, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2024-12-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-01-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Jan paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Jan rent', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-04', 'City Power & Light', 'withdrawal', -134.98, 'reconciled', 'Jan electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Jan water', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Jan internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '940', '2025-01-09', 'Kroger', 'withdrawal', -229.94, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-11', 'Shell Gas Station', 'withdrawal', -72.58, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '941', '2025-01-16', 'Kroger', 'withdrawal', -201.58, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-01-20', 'Acme Bank Interest', 'deposit', 13.06, 'reconciled', 'Jan interest', 0, @john_id),
(@acc_chk, '942', '2025-01-22', 'Target', 'withdrawal', -215.05, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '943', '2025-01-25', 'Visa Platinum Payment', 'withdrawal', -497.59, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '944', '2025-01-28', 'Home Depot', 'withdrawal', -159.94, 'reconciled', 'Home maintenance', 0, @john_id),
(@acc_chk, 'EFT', '2025-01-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-02-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Feb paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Feb rent', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-04', 'City Power & Light', 'withdrawal', -124.79, 'reconciled', 'Feb electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Feb water', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Feb internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '945', '2025-02-09', 'Kroger', 'withdrawal', -202.76, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-11', 'Shell Gas Station', 'withdrawal', -58.60, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '946', '2025-02-16', 'Kroger', 'withdrawal', -163.90, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-02-20', 'Acme Bank Interest', 'deposit', 14.24, 'reconciled', 'Feb interest', 0, @john_id),
(@acc_chk, '947', '2025-02-22', 'Target', 'withdrawal', -155.01, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '948', '2025-02-25', 'Visa Platinum Payment', 'withdrawal', -421.98, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '949', '2025-02-17', 'Dr. Johnson', 'withdrawal', -40.00, 'reconciled', 'Office visit copay', 0, @john_id),
(@acc_chk, 'EFT', '2025-02-28', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-03-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Mar paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Mar rent', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-04', 'City Power & Light', 'withdrawal', -107.58, 'reconciled', 'Mar electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Mar water', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Mar internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '950', '2025-03-09', 'Kroger', 'withdrawal', -156.89, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-11', 'Shell Gas Station', 'withdrawal', -51.07, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '951', '2025-03-16', 'Kroger', 'withdrawal', -135.16, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-03-20', 'Acme Bank Interest', 'deposit', 15.41, 'reconciled', 'Mar interest', 0, @john_id),
(@acc_chk, '952', '2025-03-22', 'Target', 'withdrawal', -146.23, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '953', '2025-03-25', 'Visa Platinum Payment', 'withdrawal', -343.47, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '954', '2025-03-17', 'Starbucks', 'withdrawal', -8.75, 'reconciled', 'Morning coffee', 0, @john_id),
(@acc_chk, 'EFT', '2025-03-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-04-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Apr paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-02', 'Sunrise Apartments', 'withdrawal', -1800.00, 'reconciled', 'Apr rent (final month)', 0, @john_id),
(@acc_chk, '955', '2025-04-12', 'Heritage Title & Escrow', 'withdrawal', -2500.00, 'reconciled', 'Closing costs - new home purchase', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-04', 'City Power & Light', 'withdrawal', -108.57, 'reconciled', 'Apr electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Apr water', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Apr internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '956', '2025-04-09', 'Kroger', 'withdrawal', -159.52, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-11', 'Shell Gas Station', 'withdrawal', -61.01, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '957', '2025-04-16', 'Kroger', 'withdrawal', -157.46, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-04-20', 'Acme Bank Interest', 'deposit', 16.59, 'reconciled', 'Apr interest', 0, @john_id),
(@acc_chk, '958', '2025-04-22', 'Target', 'withdrawal', -201.58, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '959', '2025-04-25', 'Visa Platinum Payment', 'withdrawal', -377.07, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '960', '2025-04-18', 'Amazon', 'withdrawal', -54.76, 'reconciled', 'Online purchase', 0, @john_id),
(@acc_chk, 'EFT', '2025-04-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-05-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'May paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'May mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-04', 'City Power & Light', 'withdrawal', -126.30, 'reconciled', 'May electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'May water', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'May internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '961', '2025-05-09', 'Kroger', 'withdrawal', -206.81, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-11', 'Shell Gas Station', 'withdrawal', -73.87, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '962', '2025-05-16', 'Kroger', 'withdrawal', -198.13, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-05-20', 'Acme Bank Interest', 'deposit', 17.76, 'reconciled', 'May interest', 0, @john_id),
(@acc_chk, '963', '2025-05-22', 'Target', 'withdrawal', -239.97, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '964', '2025-05-25', 'Visa Platinum Payment', 'withdrawal', -473.57, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '965', '2025-05-28', 'Whole Foods', 'withdrawal', -120.50, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-05-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-06-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Jun paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Jun mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-04', 'City Power & Light', 'withdrawal', -134.80, 'reconciled', 'Jun electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Jun water', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-06', 'Comcast', 'withdrawal', -89.99, 'reconciled', 'Jun internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '966', '2025-06-09', 'Kroger', 'withdrawal', -229.47, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-11', 'Shell Gas Station', 'withdrawal', -70.80, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-14', 'Netflix', 'withdrawal', -22.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '967', '2025-06-16', 'Kroger', 'withdrawal', -197.59, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-06-20', 'Acme Bank Interest', 'deposit', 18.94, 'reconciled', 'Jun interest', 0, @john_id),
(@acc_chk, '968', '2025-06-22', 'Target', 'withdrawal', -205.16, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '969', '2025-06-25', 'Visa Platinum Payment', 'withdrawal', -491.58, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '970', '2025-06-28', 'Home Depot', 'withdrawal', -159.47, 'reconciled', 'Home maintenance', 0, @john_id),
(@acc_chk, 'EFT', '2025-06-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-07-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Jul paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Jul mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-04', 'City Power & Light', 'withdrawal', -121.62, 'reconciled', 'Jul electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Jul water', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-06', 'Comcast', 'withdrawal', -94.99, 'reconciled', 'Jul internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '971', '2025-07-09', 'Kroger', 'withdrawal', -194.31, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-11', 'Shell Gas Station', 'withdrawal', -56.30, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-14', 'Netflix', 'withdrawal', -24.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '972', '2025-07-16', 'Kroger', 'withdrawal', -156.63, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-07-20', 'Acme Bank Interest', 'deposit', 20.12, 'reconciled', 'Jul interest', 0, @john_id),
(@acc_chk, '973', '2025-07-22', 'Target', 'withdrawal', -148.14, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '974', '2025-07-25', 'Visa Platinum Payment', 'withdrawal', -404.73, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '975', '2025-07-17', 'Dr. Johnson', 'withdrawal', -40.00, 'reconciled', 'Office visit copay', 0, @john_id),
(@acc_chk, 'EFT', '2025-07-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-08-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Aug paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Aug mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-04', 'City Power & Light', 'withdrawal', -106.06, 'reconciled', 'Aug electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Aug water', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-06', 'Comcast', 'withdrawal', -94.99, 'reconciled', 'Aug internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '976', '2025-08-09', 'Kroger', 'withdrawal', -152.84, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-11', 'Shell Gas Station', 'withdrawal', -51.61, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-14', 'Netflix', 'withdrawal', -24.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '977', '2025-08-16', 'Kroger', 'withdrawal', -135.26, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-08-20', 'Acme Bank Interest', 'deposit', 21.29, 'reconciled', 'Aug interest', 0, @john_id),
(@acc_chk, '978', '2025-08-22', 'Target', 'withdrawal', -152.45, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '979', '2025-08-25', 'Visa Platinum Payment', 'withdrawal', -340.25, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '980', '2025-08-17', 'Starbucks', 'withdrawal', -8.75, 'reconciled', 'Morning coffee', 0, @john_id),
(@acc_chk, 'EFT', '2025-08-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-09-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Sep paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Sep mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-04', 'City Power & Light', 'withdrawal', -110.93, 'reconciled', 'Sep electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Sep water', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-06', 'Comcast', 'withdrawal', -94.99, 'reconciled', 'Sep internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '981', '2025-09-09', 'Kroger', 'withdrawal', -165.81, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-11', 'Shell Gas Station', 'withdrawal', -63.61, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-14', 'Netflix', 'withdrawal', -24.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '982', '2025-09-16', 'Kroger', 'withdrawal', -164.79, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-09-20', 'Acme Bank Interest', 'deposit', 22.47, 'reconciled', 'Sep interest', 0, @john_id),
(@acc_chk, '983', '2025-09-22', 'Target', 'withdrawal', -211.77, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '984', '2025-09-25', 'Visa Platinum Payment', 'withdrawal', -392.60, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '985', '2025-09-18', 'Amazon', 'withdrawal', -57.90, 'reconciled', 'Online purchase', 0, @john_id),
(@acc_chk, 'EFT', '2025-09-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-10-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Oct paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Oct mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-04', 'City Power & Light', 'withdrawal', -129.08, 'reconciled', 'Oct electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Oct water', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-06', 'Comcast', 'withdrawal', -94.99, 'reconciled', 'Oct internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '986', '2025-10-09', 'Kroger', 'withdrawal', -214.22, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-11', 'Shell Gas Station', 'withdrawal', -74.71, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-14', 'Netflix', 'withdrawal', -24.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '987', '2025-10-16', 'Kroger', 'withdrawal', -201.95, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-10-20', 'Acme Bank Interest', 'deposit', 23.65, 'reconciled', 'Oct interest', 0, @john_id),
(@acc_chk, '988', '2025-10-22', 'Target', 'withdrawal', -239.20, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '989', '2025-10-25', 'Visa Platinum Payment', 'withdrawal', -485.09, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '990', '2025-10-28', 'Whole Foods', 'withdrawal', -125.14, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-10-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-11-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Nov paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Nov mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-04', 'City Power & Light', 'withdrawal', -133.93, 'reconciled', 'Nov electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Nov water', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-06', 'Comcast', 'withdrawal', -94.99, 'reconciled', 'Nov internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '991', '2025-11-09', 'Kroger', 'withdrawal', -227.15, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-11', 'Shell Gas Station', 'withdrawal', -68.66, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-14', 'Netflix', 'withdrawal', -24.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '992', '2025-11-16', 'Kroger', 'withdrawal', -192.31, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-11-20', 'Acme Bank Interest', 'deposit', 24.82, 'reconciled', 'Nov interest', 0, @john_id),
(@acc_chk, '993', '2025-11-22', 'Target', 'withdrawal', -194.55, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '994', '2025-11-25', 'Visa Platinum Payment', 'withdrawal', -482.22, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '995', '2025-11-28', 'Home Depot', 'withdrawal', -157.15, 'reconciled', 'Home maintenance', 0, @john_id),
(@acc_chk, 'EFT', '2025-11-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id),
(@acc_chk, 'DEP', '2025-12-01', 'Acme Corp Payroll', 'deposit', 7600.00, 'reconciled', 'Dec paycheck', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-12', 'First National Mortgage', 'withdrawal', -3792.41, 'reconciled', 'Dec mortgage', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-04', 'City Power & Light', 'withdrawal', -118.37, 'reconciled', 'Dec electricity', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-05', 'Municipal Water', 'withdrawal', -45.00, 'reconciled', 'Dec water', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-06', 'Comcast', 'withdrawal', -94.99, 'reconciled', 'Dec internet', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-07', 'Toyota Financial', 'withdrawal', -387.50, 'reconciled', 'Car payment', 0, @john_id),
(@acc_chk, '996', '2025-12-09', 'Kroger', 'withdrawal', -185.65, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-11', 'Shell Gas Station', 'withdrawal', -54.32, 'reconciled', 'Gas', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-14', 'Netflix', 'withdrawal', -24.99, 'reconciled', 'Streaming', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-15', 'Acme Bank Savings', 'withdrawal', -200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_chk, '997', '2025-12-16', 'Kroger', 'withdrawal', -149.98, 'reconciled', 'Groceries', 0, @john_id),
(@acc_chk, 'DEP', '2025-12-20', 'Acme Bank Interest', 'deposit', 26.00, 'reconciled', 'Dec interest', 0, @john_id),
(@acc_chk, '998', '2025-12-22', 'Target', 'withdrawal', -143.24, 'reconciled', 'Shopping', 0, @john_id),
(@acc_chk, '999', '2025-12-25', 'Visa Platinum Payment', 'withdrawal', -388.20, 'reconciled', 'CC payment', 0, @john_id),
(@acc_chk, '1000', '2025-12-17', 'Dr. Johnson', 'withdrawal', -40.00, 'reconciled', 'Office visit copay', 0, @john_id),
(@acc_chk, 'EFT', '2025-12-30', 'Geico Insurance', 'withdrawal', -112.00, 'reconciled', 'Auto insurance', 0, @john_id);
SET @t1_pre = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_chk);

-- ── Transaction splits: checking account (Jul 2024–Dec 2025) ───────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t1_pre+0, @income, @salary, 7600.00),
(@t1_pre+1, @housing, @mortgage, -1800.00),
(@t1_pre+2, @housing, @utilities, -120.00),
(@t1_pre+3, @housing, @utilities, -45.00),
(@t1_pre+4, @housing, @utilities, -89.99),
(@t1_pre+5, @transport, @car_pay, -387.50),
(@t1_pre+6, @food, @groceries, -190.00),
(@t1_pre+7, @transport, @gas_fuel, -70.73),
(@t1_pre+8, @entertainment, @streaming, -22.99),
(@t1_pre+9, @financial, @sav_transfer, -200.00),
(@t1_pre+10, @food, @groceries, -186.78),
(@t1_pre+11, @income, @interest_in, 6.00),
(@t1_pre+12, @shopping, NULL, -234.56),
(@t1_pre+13, @financial, @cc_payment, -443.64),
(@t1_pre+14, @food, @groceries, -110.00),
(@t1_pre+15, @transport, @auto_ins, -112.00),
(@t1_pre+16, @income, @salary, 7600.00),
(@t1_pre+17, @housing, @mortgage, -1800.00),
(@t1_pre+18, @housing, @utilities, -134.45),
(@t1_pre+19, @housing, @utilities, -45.00),
(@t1_pre+20, @housing, @utilities, -89.99),
(@t1_pre+21, @transport, @car_pay, -387.50),
(@t1_pre+22, @food, @groceries, -228.54),
(@t1_pre+23, @transport, @gas_fuel, -73.91),
(@t1_pre+24, @entertainment, @streaming, -22.99),
(@t1_pre+25, @financial, @sav_transfer, -200.00),
(@t1_pre+26, @food, @groceries, -204.08),
(@t1_pre+27, @income, @interest_in, 7.18),
(@t1_pre+28, @shopping, NULL, -223.77),
(@t1_pre+29, @financial, @cc_payment, -499.97),
(@t1_pre+30, @housing, @home_maint, -158.54),
(@t1_pre+31, @transport, @auto_ins, -112.00),
(@t1_pre+32, @income, @salary, 7600.00),
(@t1_pre+33, @housing, @mortgage, -1800.00),
(@t1_pre+34, @housing, @utilities, -127.73),
(@t1_pre+35, @housing, @utilities, -45.00),
(@t1_pre+36, @housing, @utilities, -89.99),
(@t1_pre+37, @transport, @car_pay, -387.50),
(@t1_pre+38, @food, @groceries, -210.62),
(@t1_pre+39, @transport, @gas_fuel, -61.11),
(@t1_pre+40, @entertainment, @streaming, -22.99),
(@t1_pre+41, @financial, @sav_transfer, -200.00),
(@t1_pre+42, @food, @groceries, -171.46),
(@t1_pre+43, @income, @interest_in, 8.35),
(@t1_pre+44, @shopping, NULL, -163.51),
(@t1_pre+45, @financial, @cc_payment, -439.14),
(@t1_pre+46, @healthcare, @doctor, -40.00),
(@t1_pre+47, @transport, @auto_ins, -112.00),
(@t1_pre+48, @income, @salary, 7600.00),
(@t1_pre+49, @housing, @mortgage, -1800.00),
(@t1_pre+50, @housing, @utilities, -109.68),
(@t1_pre+51, @housing, @utilities, -45.00),
(@t1_pre+52, @housing, @utilities, -89.99),
(@t1_pre+53, @transport, @car_pay, -387.50),
(@t1_pre+54, @food, @groceries, -162.49),
(@t1_pre+55, @transport, @gas_fuel, -51.08),
(@t1_pre+56, @entertainment, @streaming, -22.99),
(@t1_pre+57, @financial, @sav_transfer, -200.00),
(@t1_pre+58, @food, @groceries, -136.69),
(@t1_pre+59, @income, @interest_in, 9.53),
(@t1_pre+60, @shopping, NULL, -142.05),
(@t1_pre+61, @financial, @cc_payment, -350.27),
(@t1_pre+62, @food, @coffee, -8.75),
(@t1_pre+63, @transport, @auto_ins, -112.00),
(@t1_pre+64, @income, @salary, 7600.00),
(@t1_pre+65, @housing, @mortgage, -1800.00),
(@t1_pre+66, @housing, @utilities, -106.75),
(@t1_pre+67, @housing, @utilities, -45.00),
(@t1_pre+68, @housing, @utilities, -89.99),
(@t1_pre+69, @transport, @car_pay, -387.50),
(@t1_pre+70, @food, @groceries, -154.66),
(@t1_pre+71, @transport, @gas_fuel, -58.51),
(@t1_pre+72, @entertainment, @streaming, -22.99),
(@t1_pre+73, @financial, @sav_transfer, -200.00),
(@t1_pre+74, @food, @groceries, -150.73),
(@t1_pre+75, @income, @interest_in, 10.71),
(@t1_pre+76, @shopping, NULL, -190.84),
(@t1_pre+77, @financial, @cc_payment, -363.56),
(@t1_pre+78, @shopping, @electronics, -52.33),
(@t1_pre+79, @transport, @auto_ins, -112.00),
(@t1_pre+80, @income, @salary, 7600.00),
(@t1_pre+81, @housing, @mortgage, -1800.00),
(@t1_pre+82, @housing, @utilities, -123.23),
(@t1_pre+83, @housing, @utilities, -45.00),
(@t1_pre+84, @housing, @utilities, -89.99),
(@t1_pre+85, @transport, @car_pay, -387.50),
(@t1_pre+86, @food, @groceries, -198.60),
(@t1_pre+87, @transport, @gas_fuel, -72.52),
(@t1_pre+88, @entertainment, @streaming, -22.99),
(@t1_pre+89, @financial, @sav_transfer, -200.00),
(@t1_pre+90, @food, @groceries, -192.99),
(@t1_pre+91, @income, @interest_in, 11.88),
(@t1_pre+92, @shopping, NULL, -238.40),
(@t1_pre+93, @financial, @cc_payment, -459.53),
(@t1_pre+94, @food, @groceries, -115.38),
(@t1_pre+95, @transport, @auto_ins, -112.00),
(@t1_pre+96, @income, @salary, 7600.00),
(@t1_pre+97, @housing, @mortgage, -1800.00),
(@t1_pre+98, @housing, @utilities, -134.98),
(@t1_pre+99, @housing, @utilities, -45.00),
(@t1_pre+100, @housing, @utilities, -89.99),
(@t1_pre+101, @transport, @car_pay, -387.50),
(@t1_pre+102, @food, @groceries, -229.94),
(@t1_pre+103, @transport, @gas_fuel, -72.58),
(@t1_pre+104, @entertainment, @streaming, -22.99),
(@t1_pre+105, @financial, @sav_transfer, -200.00),
(@t1_pre+106, @food, @groceries, -201.58),
(@t1_pre+107, @income, @interest_in, 13.06),
(@t1_pre+108, @shopping, NULL, -215.05),
(@t1_pre+109, @financial, @cc_payment, -497.59),
(@t1_pre+110, @housing, @home_maint, -159.94),
(@t1_pre+111, @transport, @auto_ins, -112.00),
(@t1_pre+112, @income, @salary, 7600.00),
(@t1_pre+113, @housing, @mortgage, -1800.00),
(@t1_pre+114, @housing, @utilities, -124.79),
(@t1_pre+115, @housing, @utilities, -45.00),
(@t1_pre+116, @housing, @utilities, -89.99),
(@t1_pre+117, @transport, @car_pay, -387.50),
(@t1_pre+118, @food, @groceries, -202.76),
(@t1_pre+119, @transport, @gas_fuel, -58.60),
(@t1_pre+120, @entertainment, @streaming, -22.99),
(@t1_pre+121, @financial, @sav_transfer, -200.00),
(@t1_pre+122, @food, @groceries, -163.90),
(@t1_pre+123, @income, @interest_in, 14.24),
(@t1_pre+124, @shopping, NULL, -155.01),
(@t1_pre+125, @financial, @cc_payment, -421.98),
(@t1_pre+126, @healthcare, @doctor, -40.00),
(@t1_pre+127, @transport, @auto_ins, -112.00),
(@t1_pre+128, @income, @salary, 7600.00),
(@t1_pre+129, @housing, @mortgage, -1800.00),
(@t1_pre+130, @housing, @utilities, -107.58),
(@t1_pre+131, @housing, @utilities, -45.00),
(@t1_pre+132, @housing, @utilities, -89.99),
(@t1_pre+133, @transport, @car_pay, -387.50),
(@t1_pre+134, @food, @groceries, -156.89),
(@t1_pre+135, @transport, @gas_fuel, -51.07),
(@t1_pre+136, @entertainment, @streaming, -22.99),
(@t1_pre+137, @financial, @sav_transfer, -200.00),
(@t1_pre+138, @food, @groceries, -135.16),
(@t1_pre+139, @income, @interest_in, 15.41),
(@t1_pre+140, @shopping, NULL, -146.23),
(@t1_pre+141, @financial, @cc_payment, -343.47),
(@t1_pre+142, @food, @coffee, -8.75),
(@t1_pre+143, @transport, @auto_ins, -112.00),
(@t1_pre+144, @income, @salary, 7600.00),
(@t1_pre+145, @housing, @mortgage, -1800.00),
(@t1_pre+146, @housing, @home_maint, -2500.00),
(@t1_pre+147, @housing, @utilities, -108.57),
(@t1_pre+148, @housing, @utilities, -45.00),
(@t1_pre+149, @housing, @utilities, -89.99),
(@t1_pre+150, @transport, @car_pay, -387.50),
(@t1_pre+151, @food, @groceries, -159.52),
(@t1_pre+152, @transport, @gas_fuel, -61.01),
(@t1_pre+153, @entertainment, @streaming, -22.99),
(@t1_pre+154, @financial, @sav_transfer, -200.00),
(@t1_pre+155, @food, @groceries, -157.46),
(@t1_pre+156, @income, @interest_in, 16.59),
(@t1_pre+157, @shopping, NULL, -201.58),
(@t1_pre+158, @financial, @cc_payment, -377.07),
(@t1_pre+159, @shopping, @electronics, -54.76),
(@t1_pre+160, @transport, @auto_ins, -112.00),
(@t1_pre+161, @income, @salary, 7600.00),
(@t1_pre+162, @housing, @mortgage, -3792.41),
(@t1_pre+163, @housing, @utilities, -126.30),
(@t1_pre+164, @housing, @utilities, -45.00),
(@t1_pre+165, @housing, @utilities, -89.99),
(@t1_pre+166, @transport, @car_pay, -387.50),
(@t1_pre+167, @food, @groceries, -206.81),
(@t1_pre+168, @transport, @gas_fuel, -73.87),
(@t1_pre+169, @entertainment, @streaming, -22.99),
(@t1_pre+170, @financial, @sav_transfer, -200.00),
(@t1_pre+171, @food, @groceries, -198.13),
(@t1_pre+172, @income, @interest_in, 17.76),
(@t1_pre+173, @shopping, NULL, -239.97),
(@t1_pre+174, @financial, @cc_payment, -473.57),
(@t1_pre+175, @food, @groceries, -120.50),
(@t1_pre+176, @transport, @auto_ins, -112.00),
(@t1_pre+177, @income, @salary, 7600.00),
(@t1_pre+178, @housing, @mortgage, -3792.41),
(@t1_pre+179, @housing, @utilities, -134.80),
(@t1_pre+180, @housing, @utilities, -45.00),
(@t1_pre+181, @housing, @utilities, -89.99),
(@t1_pre+182, @transport, @car_pay, -387.50),
(@t1_pre+183, @food, @groceries, -229.47),
(@t1_pre+184, @transport, @gas_fuel, -70.80),
(@t1_pre+185, @entertainment, @streaming, -22.99),
(@t1_pre+186, @financial, @sav_transfer, -200.00),
(@t1_pre+187, @food, @groceries, -197.59),
(@t1_pre+188, @income, @interest_in, 18.94),
(@t1_pre+189, @shopping, NULL, -205.16),
(@t1_pre+190, @financial, @cc_payment, -491.58),
(@t1_pre+191, @housing, @home_maint, -159.47),
(@t1_pre+192, @transport, @auto_ins, -112.00),
(@t1_pre+193, @income, @salary, 7600.00),
(@t1_pre+194, @housing, @mortgage, -3792.41),
(@t1_pre+195, @housing, @utilities, -121.62),
(@t1_pre+196, @housing, @utilities, -45.00),
(@t1_pre+197, @housing, @utilities, -94.99),
(@t1_pre+198, @transport, @car_pay, -387.50),
(@t1_pre+199, @food, @groceries, -194.31),
(@t1_pre+200, @transport, @gas_fuel, -56.30),
(@t1_pre+201, @entertainment, @streaming, -24.99),
(@t1_pre+202, @financial, @sav_transfer, -200.00),
(@t1_pre+203, @food, @groceries, -156.63),
(@t1_pre+204, @income, @interest_in, 20.12),
(@t1_pre+205, @shopping, NULL, -148.14),
(@t1_pre+206, @financial, @cc_payment, -404.73),
(@t1_pre+207, @healthcare, @doctor, -40.00),
(@t1_pre+208, @transport, @auto_ins, -112.00),
(@t1_pre+209, @income, @salary, 7600.00),
(@t1_pre+210, @housing, @mortgage, -3792.41),
(@t1_pre+211, @housing, @utilities, -106.06),
(@t1_pre+212, @housing, @utilities, -45.00),
(@t1_pre+213, @housing, @utilities, -94.99),
(@t1_pre+214, @transport, @car_pay, -387.50),
(@t1_pre+215, @food, @groceries, -152.84),
(@t1_pre+216, @transport, @gas_fuel, -51.61),
(@t1_pre+217, @entertainment, @streaming, -24.99),
(@t1_pre+218, @financial, @sav_transfer, -200.00),
(@t1_pre+219, @food, @groceries, -135.26),
(@t1_pre+220, @income, @interest_in, 21.29),
(@t1_pre+221, @shopping, NULL, -152.45),
(@t1_pre+222, @financial, @cc_payment, -340.25),
(@t1_pre+223, @food, @coffee, -8.75),
(@t1_pre+224, @transport, @auto_ins, -112.00),
(@t1_pre+225, @income, @salary, 7600.00),
(@t1_pre+226, @housing, @mortgage, -3792.41),
(@t1_pre+227, @housing, @utilities, -110.93),
(@t1_pre+228, @housing, @utilities, -45.00),
(@t1_pre+229, @housing, @utilities, -94.99),
(@t1_pre+230, @transport, @car_pay, -387.50),
(@t1_pre+231, @food, @groceries, -165.81),
(@t1_pre+232, @transport, @gas_fuel, -63.61),
(@t1_pre+233, @entertainment, @streaming, -24.99),
(@t1_pre+234, @financial, @sav_transfer, -200.00),
(@t1_pre+235, @food, @groceries, -164.79),
(@t1_pre+236, @income, @interest_in, 22.47),
(@t1_pre+237, @shopping, NULL, -211.77),
(@t1_pre+238, @financial, @cc_payment, -392.60),
(@t1_pre+239, @shopping, @electronics, -57.90),
(@t1_pre+240, @transport, @auto_ins, -112.00),
(@t1_pre+241, @income, @salary, 7600.00),
(@t1_pre+242, @housing, @mortgage, -3792.41),
(@t1_pre+243, @housing, @utilities, -129.08),
(@t1_pre+244, @housing, @utilities, -45.00),
(@t1_pre+245, @housing, @utilities, -94.99),
(@t1_pre+246, @transport, @car_pay, -387.50),
(@t1_pre+247, @food, @groceries, -214.22),
(@t1_pre+248, @transport, @gas_fuel, -74.71),
(@t1_pre+249, @entertainment, @streaming, -24.99),
(@t1_pre+250, @financial, @sav_transfer, -200.00),
(@t1_pre+251, @food, @groceries, -201.95),
(@t1_pre+252, @income, @interest_in, 23.65),
(@t1_pre+253, @shopping, NULL, -239.20),
(@t1_pre+254, @financial, @cc_payment, -485.09),
(@t1_pre+255, @food, @groceries, -125.14),
(@t1_pre+256, @transport, @auto_ins, -112.00),
(@t1_pre+257, @income, @salary, 7600.00),
(@t1_pre+258, @housing, @mortgage, -3792.41),
(@t1_pre+259, @housing, @utilities, -133.93),
(@t1_pre+260, @housing, @utilities, -45.00),
(@t1_pre+261, @housing, @utilities, -94.99),
(@t1_pre+262, @transport, @car_pay, -387.50),
(@t1_pre+263, @food, @groceries, -227.15),
(@t1_pre+264, @transport, @gas_fuel, -68.66),
(@t1_pre+265, @entertainment, @streaming, -24.99),
(@t1_pre+266, @financial, @sav_transfer, -200.00),
(@t1_pre+267, @food, @groceries, -192.31),
(@t1_pre+268, @income, @interest_in, 24.82),
(@t1_pre+269, @shopping, NULL, -194.55),
(@t1_pre+270, @financial, @cc_payment, -482.22),
(@t1_pre+271, @housing, @home_maint, -157.15),
(@t1_pre+272, @transport, @auto_ins, -112.00),
(@t1_pre+273, @income, @salary, 7600.00),
(@t1_pre+274, @housing, @mortgage, -3792.41),
(@t1_pre+275, @housing, @utilities, -118.37),
(@t1_pre+276, @housing, @utilities, -45.00),
(@t1_pre+277, @housing, @utilities, -94.99),
(@t1_pre+278, @transport, @car_pay, -387.50),
(@t1_pre+279, @food, @groceries, -185.65),
(@t1_pre+280, @transport, @gas_fuel, -54.32),
(@t1_pre+281, @entertainment, @streaming, -24.99),
(@t1_pre+282, @financial, @sav_transfer, -200.00),
(@t1_pre+283, @food, @groceries, -149.98),
(@t1_pre+284, @income, @interest_in, 26.00),
(@t1_pre+285, @shopping, NULL, -143.24),
(@t1_pre+286, @financial, @cc_payment, -388.20),
(@t1_pre+287, @healthcare, @doctor, -40.00),
(@t1_pre+288, @transport, @auto_ins, -112.00);

-- ── Checking account transactions (Jan–Jun 2026) ───────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
-- January
(@acc_chk, 'DEP',  '2026-01-01', 'Acme Corp Payroll',      'deposit',    7600.00,  'reconciled', 'Jan paycheck',         0, @john_id),
(@acc_chk, '1001', '2026-01-02', 'First National Mortgage','withdrawal', -3792.41, 'reconciled', 'Jan mortgage',         0, @john_id),
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
(@acc_chk, 'DEP',  '2026-02-01', 'Acme Corp Payroll',      'deposit',    7600.00,  'reconciled', 'Feb paycheck',         0, @john_id),
(@acc_chk, '1008', '2026-02-02', 'First National Mortgage','withdrawal', -3792.41, 'reconciled', 'Feb mortgage',         0, @john_id),
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
(@acc_chk, 'DEP',  '2026-03-01', 'Acme Corp Payroll',      'deposit',    7600.00,  'reconciled', 'Mar paycheck',         0, @john_id),
(@acc_chk, '1015', '2026-03-02', 'First National Mortgage','withdrawal', -3792.41, 'reconciled', 'Mar mortgage',         0, @john_id),
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
(@acc_chk, 'DEP',  '2026-04-01', 'Acme Corp Payroll',      'deposit',    7600.00,  'cleared',    'Apr paycheck',         0, @john_id),
(@acc_chk, '1021', '2026-04-02', 'First National Mortgage','withdrawal', -3792.41, 'cleared',    'Apr mortgage',         0, @john_id),
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
(@acc_chk, 'DEP',  '2026-05-01', 'Acme Corp Payroll',      'deposit',    7600.00,  '',           'May paycheck',         0, @john_id),
(@acc_chk, '1027', '2026-05-02', 'First National Mortgage','withdrawal', -3792.41, '',           'May mortgage',         0, @john_id),
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
(@acc_chk, 'DEP',  '2026-06-01', 'Acme Corp Payroll',      'deposit',    7600.00,  '',           'Jun paycheck',         0, @john_id),
(@acc_chk, '1032', '2026-06-02', 'First National Mortgage','withdrawal', -3792.41, '',           'Jun mortgage',         0, @john_id),
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
SET @t1 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_chk AND transaction_date >= '2026-01-01');

-- ── Acme Bank Savings transactions (Jul 2024–Dec 2025) ──────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_sav, 'EFT', '2024-07-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2024-07-20', 'Acme Bank Interest', 'deposit', 15.00, 'reconciled', 'Jul interest', 0, @john_id),
(@acc_sav, 'EFT', '2024-08-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2024-08-20', 'Acme Bank Interest', 'deposit', 15.65, 'reconciled', 'Aug interest', 0, @john_id),
(@acc_sav, 'EFT', '2024-09-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2024-09-20', 'Acme Bank Interest', 'deposit', 16.29, 'reconciled', 'Sep interest', 0, @john_id),
(@acc_sav, 'EFT', '2024-10-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2024-10-20', 'Acme Bank Interest', 'deposit', 16.94, 'reconciled', 'Oct interest', 0, @john_id),
(@acc_sav, 'EFT', '2024-11-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2024-11-20', 'Acme Bank Interest', 'deposit', 17.59, 'reconciled', 'Nov interest', 0, @john_id),
(@acc_sav, 'EFT', '2024-12-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2024-12-20', 'Acme Bank Interest', 'deposit', 18.24, 'reconciled', 'Dec interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-01-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-01-20', 'Acme Bank Interest', 'deposit', 18.88, 'reconciled', 'Jan interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-02-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-02-20', 'Acme Bank Interest', 'deposit', 19.53, 'reconciled', 'Feb interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-03-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-03-20', 'Acme Bank Interest', 'deposit', 20.18, 'reconciled', 'Mar interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-04-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-04-20', 'Acme Bank Interest', 'deposit', 20.82, 'reconciled', 'Apr interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-05-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-05-20', 'Acme Bank Interest', 'deposit', 21.47, 'reconciled', 'May interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-06-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-06-20', 'Acme Bank Interest', 'deposit', 22.12, 'reconciled', 'Jun interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-07-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-07-20', 'Acme Bank Interest', 'deposit', 22.76, 'reconciled', 'Jul interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-08-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-08-20', 'Acme Bank Interest', 'deposit', 23.41, 'reconciled', 'Aug interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-09-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-09-20', 'Acme Bank Interest', 'deposit', 24.06, 'reconciled', 'Sep interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-10-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-10-20', 'Acme Bank Interest', 'deposit', 24.71, 'reconciled', 'Oct interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-11-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-11-20', 'Acme Bank Interest', 'deposit', 25.35, 'reconciled', 'Nov interest', 0, @john_id),
(@acc_sav, 'EFT', '2025-12-15', 'Transfer from Checking', 'deposit', 200.00, 'reconciled', 'Monthly savings', 0, @john_id),
(@acc_sav, 'DEP', '2025-12-20', 'Acme Bank Interest', 'deposit', 26.00, 'reconciled', 'Dec interest', 0, @john_id);
SET @t2_pre = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_sav);

-- ── Transaction splits: Acme Bank Savings (Jul 2024–Dec 2025) ───────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t2_pre+0, @financial, @sav_transfer, 200.00),
(@t2_pre+1, @income, @interest_in, 15.00),
(@t2_pre+2, @financial, @sav_transfer, 200.00),
(@t2_pre+3, @income, @interest_in, 15.65),
(@t2_pre+4, @financial, @sav_transfer, 200.00),
(@t2_pre+5, @income, @interest_in, 16.29),
(@t2_pre+6, @financial, @sav_transfer, 200.00),
(@t2_pre+7, @income, @interest_in, 16.94),
(@t2_pre+8, @financial, @sav_transfer, 200.00),
(@t2_pre+9, @income, @interest_in, 17.59),
(@t2_pre+10, @financial, @sav_transfer, 200.00),
(@t2_pre+11, @income, @interest_in, 18.24),
(@t2_pre+12, @financial, @sav_transfer, 200.00),
(@t2_pre+13, @income, @interest_in, 18.88),
(@t2_pre+14, @financial, @sav_transfer, 200.00),
(@t2_pre+15, @income, @interest_in, 19.53),
(@t2_pre+16, @financial, @sav_transfer, 200.00),
(@t2_pre+17, @income, @interest_in, 20.18),
(@t2_pre+18, @financial, @sav_transfer, 200.00),
(@t2_pre+19, @income, @interest_in, 20.82),
(@t2_pre+20, @financial, @sav_transfer, 200.00),
(@t2_pre+21, @income, @interest_in, 21.47),
(@t2_pre+22, @financial, @sav_transfer, 200.00),
(@t2_pre+23, @income, @interest_in, 22.12),
(@t2_pre+24, @financial, @sav_transfer, 200.00),
(@t2_pre+25, @income, @interest_in, 22.76),
(@t2_pre+26, @financial, @sav_transfer, 200.00),
(@t2_pre+27, @income, @interest_in, 23.41),
(@t2_pre+28, @financial, @sav_transfer, 200.00),
(@t2_pre+29, @income, @interest_in, 24.06),
(@t2_pre+30, @financial, @sav_transfer, 200.00),
(@t2_pre+31, @income, @interest_in, 24.71),
(@t2_pre+32, @financial, @sav_transfer, 200.00),
(@t2_pre+33, @income, @interest_in, 25.35),
(@t2_pre+34, @financial, @sav_transfer, 200.00),
(@t2_pre+35, @income, @interest_in, 26.00);

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
SET @t2 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_sav AND transaction_date >= '2026-01-01');

-- ── Ally High-Yield Savings transactions (Jul 2024–Dec 2025) ────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_hys, 'DEP', '2024-07-31', 'Ally Bank Interest', 'deposit', 38.00, 'reconciled', 'Jul interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2024-08-31', 'Ally Bank Interest', 'deposit', 39.06, 'reconciled', 'Aug interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2024-09-30', 'Ally Bank Interest', 'deposit', 40.12, 'reconciled', 'Sep interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2024-10-31', 'Ally Bank Interest', 'deposit', 41.18, 'reconciled', 'Oct interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2024-11-30', 'Ally Bank Interest', 'deposit', 42.24, 'reconciled', 'Nov interest 4.5% APY', 0, @john_id),
(@acc_hys, 'EFT', '2024-12-15', 'Transfer from Checking', 'deposit', 3000.00, 'reconciled', 'Additional savings', 0, @john_id),
(@acc_hys, 'DEP', '2024-12-31', 'Ally Bank Interest', 'deposit', 43.29, 'reconciled', 'Dec interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-01-31', 'Ally Bank Interest', 'deposit', 44.35, 'reconciled', 'Jan interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-02-28', 'Ally Bank Interest', 'deposit', 45.41, 'reconciled', 'Feb interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-03-31', 'Ally Bank Interest', 'deposit', 46.47, 'reconciled', 'Mar interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-04-30', 'Ally Bank Interest', 'deposit', 47.53, 'reconciled', 'Apr interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-05-31', 'Ally Bank Interest', 'deposit', 48.59, 'reconciled', 'May interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-06-30', 'Ally Bank Interest', 'deposit', 49.65, 'reconciled', 'Jun interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-07-31', 'Ally Bank Interest', 'deposit', 50.71, 'reconciled', 'Jul interest 4.5% APY', 0, @john_id),
(@acc_hys, 'EFT', '2025-08-15', 'Transfer from Checking', 'deposit', 2500.00, 'reconciled', 'Additional savings', 0, @john_id),
(@acc_hys, 'DEP', '2025-08-31', 'Ally Bank Interest', 'deposit', 51.76, 'reconciled', 'Aug interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-09-30', 'Ally Bank Interest', 'deposit', 52.82, 'reconciled', 'Sep interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-10-31', 'Ally Bank Interest', 'deposit', 53.88, 'reconciled', 'Oct interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-11-30', 'Ally Bank Interest', 'deposit', 54.94, 'reconciled', 'Nov interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2025-12-31', 'Ally Bank Interest', 'deposit', 56.00, 'reconciled', 'Dec interest 4.5% APY', 0, @john_id);
SET @t3_pre = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_hys);

-- ── Transaction splits: Ally High-Yield Savings (Jul 2024–Dec 2025) ─────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t3_pre+0, @income, @interest_in, 38.00),
(@t3_pre+1, @income, @interest_in, 39.06),
(@t3_pre+2, @income, @interest_in, 40.12),
(@t3_pre+3, @income, @interest_in, 41.18),
(@t3_pre+4, @income, @interest_in, 42.24),
(@t3_pre+5, @financial, @sav_transfer, 3000.00),
(@t3_pre+6, @income, @interest_in, 43.29),
(@t3_pre+7, @income, @interest_in, 44.35),
(@t3_pre+8, @income, @interest_in, 45.41),
(@t3_pre+9, @income, @interest_in, 46.47),
(@t3_pre+10, @income, @interest_in, 47.53),
(@t3_pre+11, @income, @interest_in, 48.59),
(@t3_pre+12, @income, @interest_in, 49.65),
(@t3_pre+13, @income, @interest_in, 50.71),
(@t3_pre+14, @financial, @sav_transfer, 2500.00),
(@t3_pre+15, @income, @interest_in, 51.76),
(@t3_pre+16, @income, @interest_in, 52.82),
(@t3_pre+17, @income, @interest_in, 53.88),
(@t3_pre+18, @income, @interest_in, 54.94),
(@t3_pre+19, @income, @interest_in, 56.00);

-- ── Ally High-Yield Savings transactions ───────────────────────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_hys, 'DEP', '2026-01-31', 'Ally Bank Interest',     'deposit',   56.25, 'reconciled', 'Jan interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-02-28', 'Ally Bank Interest',     'deposit',   55.80, 'reconciled', 'Feb interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-03-31', 'Ally Bank Interest',     'deposit',   57.10, 'reconciled', 'Mar interest 4.5% APY', 0, @john_id),
(@acc_hys, 'EFT', '2026-03-15', 'Transfer from Checking', 'deposit', 2000.00, 'reconciled', 'Additional savings',    0, @john_id),
(@acc_hys, 'DEP', '2026-04-30', 'Ally Bank Interest',     'deposit',   64.45, 'cleared',    'Apr interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-05-31', 'Ally Bank Interest',     'deposit',   65.12, '',           'May interest 4.5% APY', 0, @john_id),
(@acc_hys, 'DEP', '2026-06-24', 'Ally Bank Interest',     'deposit',   43.80, '',           'Jun interest (partial)', 0, @john_id);
SET @t3 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_hys AND transaction_date >= '2026-01-01');

-- ── Visa Platinum Card transactions (Jul 2024–Dec 2025) ─────────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_cc1, '', '2024-07-08', 'Amazon', 'withdrawal', -90.00, 'reconciled', 'Amazon', 0, @john_id),
(@acc_cc1, '', '2024-07-18', 'CVS Pharmacy', 'withdrawal', -35.61, 'reconciled', 'CVS Pharmacy', 0, @john_id),
(@acc_cc1, '', '2024-07-26', 'John Smith - Payment', 'deposit', 520.50, 'reconciled', 'CC payment Jul', 0, @john_id),
(@acc_cc1, '', '2024-08-08', 'Best Buy', 'withdrawal', -310.11, 'reconciled', 'Best Buy', 0, @john_id),
(@acc_cc1, '', '2024-08-18', 'REI', 'withdrawal', -154.60, 'reconciled', 'REI', 0, @john_id),
(@acc_cc1, '', '2024-08-26', 'John Smith - Payment', 'deposit', 522.76, 'reconciled', 'CC payment Aug', 0, @john_id),
(@acc_cc1, '', '2024-09-08', 'Exxon Gas', 'withdrawal', -57.36, 'reconciled', 'Exxon Gas', 0, @john_id),
(@acc_cc1, '', '2024-09-18', 'TJ Maxx', 'withdrawal', -93.89, 'reconciled', 'TJ Maxx', 0, @john_id),
(@acc_cc1, '', '2024-09-26', 'John Smith - Payment', 'deposit', 418.43, 'reconciled', 'CC payment Sep', 0, @john_id),
(@acc_cc1, '', '2024-10-08', 'CVS Pharmacy', 'withdrawal', -27.60, 'reconciled', 'CVS Pharmacy', 0, @john_id),
(@acc_cc1, '', '2024-10-18', 'Amazon', 'withdrawal', -72.40, 'reconciled', 'Amazon', 0, @john_id),
(@acc_cc1, '', '2024-10-26', 'John Smith - Payment', 'deposit', 360.35, 'reconciled', 'CC payment Oct', 0, @john_id),
(@acc_cc1, '', '2024-11-08', 'REI', 'withdrawal', -107.03, 'reconciled', 'REI', 0, @john_id),
(@acc_cc1, '', '2024-11-18', 'Best Buy', 'withdrawal', -235.84, 'reconciled', 'Best Buy', 0, @john_id),
(@acc_cc1, '', '2024-11-26', 'John Smith - Payment', 'deposit', 433.61, 'reconciled', 'CC payment Nov', 0, @john_id),
(@acc_cc1, '', '2024-12-08', 'TJ Maxx', 'withdrawal', -99.09, 'reconciled', 'TJ Maxx', 0, @john_id),
(@acc_cc1, '', '2024-12-18', 'Exxon Gas', 'withdrawal', -59.58, 'reconciled', 'Exxon Gas', 0, @john_id),
(@acc_cc1, '', '2024-12-26', 'John Smith - Payment', 'deposit', 530.88, 'reconciled', 'CC payment Dec', 0, @john_id),
(@acc_cc1, '', '2025-01-08', 'Amazon', 'withdrawal', -107.97, 'reconciled', 'Amazon', 0, @john_id),
(@acc_cc1, '', '2025-01-18', 'CVS Pharmacy', 'withdrawal', -37.47, 'reconciled', 'CVS Pharmacy', 0, @john_id),
(@acc_cc1, '', '2025-01-26', 'John Smith - Payment', 'deposit', 509.67, 'reconciled', 'CC payment Jan', 0, @john_id),
(@acc_cc1, '', '2025-02-08', 'Best Buy', 'withdrawal', -276.59, 'reconciled', 'Best Buy', 0, @john_id),
(@acc_cc1, '', '2025-02-18', 'REI', 'withdrawal', -122.93, 'reconciled', 'REI', 0, @john_id),
(@acc_cc1, '', '2025-02-26', 'John Smith - Payment', 'deposit', 401.04, 'reconciled', 'CC payment Feb', 0, @john_id),
(@acc_cc1, '', '2025-03-08', 'Exxon Gas', 'withdrawal', -43.39, 'reconciled', 'Exxon Gas', 0, @john_id),
(@acc_cc1, '', '2025-03-18', 'TJ Maxx', 'withdrawal', -76.00, 'reconciled', 'TJ Maxx', 0, @john_id),
(@acc_cc1, '', '2025-03-26', 'John Smith - Payment', 'deposit', 364.14, 'reconciled', 'CC payment Mar', 0, @john_id),
(@acc_cc1, '', '2025-04-08', 'CVS Pharmacy', 'withdrawal', -27.12, 'reconciled', 'CVS Pharmacy', 0, @john_id),
(@acc_cc1, '', '2025-04-18', 'Amazon', 'withdrawal', -85.26, 'reconciled', 'Amazon', 0, @john_id),
(@acc_cc1, '', '2025-04-26', 'John Smith - Payment', 'deposit', 453.03, 'reconciled', 'CC payment Apr', 0, @john_id),
(@acc_cc1, '', '2025-05-08', 'REI', 'withdrawal', -140.92, 'reconciled', 'REI', 0, @john_id),
(@acc_cc1, '', '2025-05-18', 'Best Buy', 'withdrawal', -304.68, 'reconciled', 'Best Buy', 0, @john_id),
(@acc_cc1, '', '2025-05-26', 'John Smith - Payment', 'deposit', 537.48, 'reconciled', 'CC payment May', 0, @john_id),
(@acc_cc1, '', '2025-06-08', 'TJ Maxx', 'withdrawal', -113.75, 'reconciled', 'TJ Maxx', 0, @john_id),
(@acc_cc1, '', '2025-06-18', 'Exxon Gas', 'withdrawal', -59.52, 'reconciled', 'Exxon Gas', 0, @john_id),
(@acc_cc1, '', '2025-06-26', 'John Smith - Payment', 'deposit', 493.78, 'reconciled', 'CC payment Jun', 0, @john_id),
(@acc_cc1, '', '2025-07-08', 'Amazon', 'withdrawal', -91.94, 'reconciled', 'Amazon', 0, @john_id),
(@acc_cc1, '', '2025-07-18', 'CVS Pharmacy', 'withdrawal', -28.98, 'reconciled', 'CVS Pharmacy', 0, @john_id),
(@acc_cc1, '', '2025-07-26', 'John Smith - Payment', 'deposit', 385.94, 'reconciled', 'CC payment Jul', 0, @john_id),
(@acc_cc1, '', '2025-08-08', 'Best Buy', 'withdrawal', -211.69, 'reconciled', 'Best Buy', 0, @john_id),
(@acc_cc1, '', '2025-08-18', 'REI', 'withdrawal', -104.63, 'reconciled', 'REI', 0, @john_id),
(@acc_cc1, '', '2025-08-26', 'John Smith - Payment', 'deposit', 371.95, 'reconciled', 'CC payment Aug', 0, @john_id),
(@acc_cc1, '', '2025-09-08', 'Exxon Gas', 'withdrawal', -45.71, 'reconciled', 'Exxon Gas', 0, @john_id),
(@acc_cc1, '', '2025-09-18', 'TJ Maxx', 'withdrawal', -94.06, 'reconciled', 'TJ Maxx', 0, @john_id),
(@acc_cc1, '', '2025-09-26', 'John Smith - Payment', 'deposit', 472.31, 'reconciled', 'CC payment Sep', 0, @john_id),
(@acc_cc1, '', '2025-10-08', 'CVS Pharmacy', 'withdrawal', -35.88, 'reconciled', 'CVS Pharmacy', 0, @john_id),
(@acc_cc1, '', '2025-10-18', 'Amazon', 'withdrawal', -107.08, 'reconciled', 'Amazon', 0, @john_id),
(@acc_cc1, '', '2025-10-26', 'John Smith - Payment', 'deposit', 539.98, 'reconciled', 'CC payment Oct', 0, @john_id),
(@acc_cc1, '', '2025-11-08', 'REI', 'withdrawal', -154.15, 'reconciled', 'REI', 0, @john_id),
(@acc_cc1, '', '2025-11-18', 'Best Buy', 'withdrawal', -288.98, 'reconciled', 'Best Buy', 0, @john_id),
(@acc_cc1, '', '2025-11-26', 'John Smith - Payment', 'deposit', 475.83, 'reconciled', 'CC payment Nov', 0, @john_id),
(@acc_cc1, '', '2025-12-08', 'TJ Maxx', 'withdrawal', -92.94, 'reconciled', 'TJ Maxx', 0, @john_id),
(@acc_cc1, '', '2025-12-18', 'Exxon Gas', 'withdrawal', -45.23, 'reconciled', 'Exxon Gas', 0, @john_id),
(@acc_cc1, '', '2025-12-26', 'John Smith - Payment', 'deposit', 373.84, 'reconciled', 'CC payment Dec', 0, @john_id);
SET @t4_pre = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_cc1);

-- ── Transaction splits: Visa Platinum Card (Jul 2024–Dec 2025) ──────────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t4_pre+0, @shopping, NULL, -90.00),
(@t4_pre+1, @healthcare, @prescriptions, -35.61),
(@t4_pre+2, @financial, @cc_payment, 520.50),
(@t4_pre+3, @shopping, @electronics, -310.11),
(@t4_pre+4, @entertainment, @hobbies, -154.60),
(@t4_pre+5, @financial, @cc_payment, 522.76),
(@t4_pre+6, @transport, @gas_fuel, -57.36),
(@t4_pre+7, @shopping, @clothing, -93.89),
(@t4_pre+8, @financial, @cc_payment, 418.43),
(@t4_pre+9, @healthcare, @prescriptions, -27.60),
(@t4_pre+10, @shopping, NULL, -72.40),
(@t4_pre+11, @financial, @cc_payment, 360.35),
(@t4_pre+12, @entertainment, @hobbies, -107.03),
(@t4_pre+13, @shopping, @electronics, -235.84),
(@t4_pre+14, @financial, @cc_payment, 433.61),
(@t4_pre+15, @shopping, @clothing, -99.09),
(@t4_pre+16, @transport, @gas_fuel, -59.58),
(@t4_pre+17, @financial, @cc_payment, 530.88),
(@t4_pre+18, @shopping, NULL, -107.97),
(@t4_pre+19, @healthcare, @prescriptions, -37.47),
(@t4_pre+20, @financial, @cc_payment, 509.67),
(@t4_pre+21, @shopping, @electronics, -276.59),
(@t4_pre+22, @entertainment, @hobbies, -122.93),
(@t4_pre+23, @financial, @cc_payment, 401.04),
(@t4_pre+24, @transport, @gas_fuel, -43.39),
(@t4_pre+25, @shopping, @clothing, -76.00),
(@t4_pre+26, @financial, @cc_payment, 364.14),
(@t4_pre+27, @healthcare, @prescriptions, -27.12),
(@t4_pre+28, @shopping, NULL, -85.26),
(@t4_pre+29, @financial, @cc_payment, 453.03),
(@t4_pre+30, @entertainment, @hobbies, -140.92),
(@t4_pre+31, @shopping, @electronics, -304.68),
(@t4_pre+32, @financial, @cc_payment, 537.48),
(@t4_pre+33, @shopping, @clothing, -113.75),
(@t4_pre+34, @transport, @gas_fuel, -59.52),
(@t4_pre+35, @financial, @cc_payment, 493.78),
(@t4_pre+36, @shopping, NULL, -91.94),
(@t4_pre+37, @healthcare, @prescriptions, -28.98),
(@t4_pre+38, @financial, @cc_payment, 385.94),
(@t4_pre+39, @shopping, @electronics, -211.69),
(@t4_pre+40, @entertainment, @hobbies, -104.63),
(@t4_pre+41, @financial, @cc_payment, 371.95),
(@t4_pre+42, @transport, @gas_fuel, -45.71),
(@t4_pre+43, @shopping, @clothing, -94.06),
(@t4_pre+44, @financial, @cc_payment, 472.31),
(@t4_pre+45, @healthcare, @prescriptions, -35.88),
(@t4_pre+46, @shopping, NULL, -107.08),
(@t4_pre+47, @financial, @cc_payment, 539.98),
(@t4_pre+48, @entertainment, @hobbies, -154.15),
(@t4_pre+49, @shopping, @electronics, -288.98),
(@t4_pre+50, @financial, @cc_payment, 475.83),
(@t4_pre+51, @shopping, @clothing, -92.94),
(@t4_pre+52, @transport, @gas_fuel, -45.23),
(@t4_pre+53, @financial, @cc_payment, 373.84);

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
SET @t4 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_cc1 AND transaction_date >= '2026-01-01');

-- ── Chase Sapphire Reserve transactions (Jul 2024–Dec 2025) ─────────────
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, is_split, created_by) VALUES
(@acc_cc2, '', '2024-07-09', 'Delta Airlines', 'withdrawal', -420.00, 'reconciled', 'Delta Airlines', 0, @john_id),
(@acc_cc2, '', '2024-07-24', 'Chase - Payment', 'deposit', 592.84, 'reconciled', 'CC payment Jul', 0, @john_id),
(@acc_cc2, '', '2024-08-09', 'Marriott Hotels', 'withdrawal', -357.81, 'reconciled', 'Marriott Hotels', 0, @john_id),
(@acc_cc2, '', '2024-08-24', 'Chase - Payment', 'deposit', 659.08, 'reconciled', 'CC payment Aug', 0, @john_id),
(@acc_cc2, '', '2024-09-09', 'The Capital Grille', 'withdrawal', -193.04, 'reconciled', 'The Capital Grille', 0, @john_id),
(@acc_cc2, '', '2024-09-24', 'Chase - Payment', 'deposit', 565.52, 'reconciled', 'CC payment Sep', 0, @john_id),
(@acc_cc2, '', '2024-10-09', 'Lyft', 'withdrawal', -34.50, 'reconciled', 'Lyft', 0, @john_id),
(@acc_cc2, '', '2024-10-24', 'Chase - Payment', 'deposit', 449.22, 'reconciled', 'CC payment Oct', 0, @john_id),
(@acc_cc2, '', '2024-11-09', 'Nobu Restaurant', 'withdrawal', -164.66, 'reconciled', 'Nobu Restaurant', 0, @john_id),
(@acc_cc2, '', '2024-11-24', 'Chase - Payment', 'deposit', 480.56, 'reconciled', 'CC payment Nov', 0, @john_id),
(@acc_cc2, '', '2024-12-09', 'Avis Car Rental', 'withdrawal', -292.05, 'reconciled', 'Avis Car Rental', 0, @john_id),
(@acc_cc2, '', '2024-12-24', 'Chase - Payment', 'deposit', 613.63, 'reconciled', 'CC payment Dec', 0, @john_id),
(@acc_cc2, '', '2025-01-09', 'Delta Airlines', 'withdrawal', -503.88, 'reconciled', 'Delta Airlines', 0, @john_id),
(@acc_cc2, '', '2025-01-24', 'Chase - Payment', 'deposit', 653.48, 'reconciled', 'CC payment Jan', 0, @john_id),
(@acc_cc2, '', '2025-02-09', 'Marriott Hotels', 'withdrawal', -319.15, 'reconciled', 'Marriott Hotels', 0, @john_id),
(@acc_cc2, '', '2025-02-24', 'Chase - Payment', 'deposit', 541.73, 'reconciled', 'CC payment Feb', 0, @john_id),
(@acc_cc2, '', '2025-03-09', 'The Capital Grille', 'withdrawal', -146.03, 'reconciled', 'The Capital Grille', 0, @john_id),
(@acc_cc2, '', '2025-03-24', 'Chase - Payment', 'deposit', 442.10, 'reconciled', 'CC payment Mar', 0, @john_id),
(@acc_cc2, '', '2025-04-09', 'Lyft', 'withdrawal', -33.90, 'reconciled', 'Lyft', 0, @john_id),
(@acc_cc2, '', '2025-04-24', 'Chase - Payment', 'deposit', 500.54, 'reconciled', 'CC payment Apr', 0, @john_id),
(@acc_cc2, '', '2025-05-09', 'Nobu Restaurant', 'withdrawal', -216.81, 'reconciled', 'Nobu Restaurant', 0, @john_id),
(@acc_cc2, '', '2025-05-24', 'Chase - Payment', 'deposit', 631.44, 'reconciled', 'CC payment May', 0, @john_id),
(@acc_cc2, '', '2025-06-09', 'Avis Car Rental', 'withdrawal', -335.26, 'reconciled', 'Avis Car Rental', 0, @john_id),
(@acc_cc2, '', '2025-06-24', 'Chase - Payment', 'deposit', 643.03, 'reconciled', 'CC payment Jun', 0, @john_id),
(@acc_cc2, '', '2025-07-09', 'Delta Airlines', 'withdrawal', -429.05, 'reconciled', 'Delta Airlines', 0, @john_id),
(@acc_cc2, '', '2025-07-24', 'Chase - Payment', 'deposit', 518.33, 'reconciled', 'CC payment Jul', 0, @john_id),
(@acc_cc2, '', '2025-08-09', 'Marriott Hotels', 'withdrawal', -244.25, 'reconciled', 'Marriott Hotels', 0, @john_id),
(@acc_cc2, '', '2025-08-24', 'Chase - Payment', 'deposit', 440.02, 'reconciled', 'CC payment Aug', 0, @john_id),
(@acc_cc2, '', '2025-09-09', 'The Capital Grille', 'withdrawal', -153.83, 'reconciled', 'The Capital Grille', 0, @john_id),
(@acc_cc2, '', '2025-09-24', 'Chase - Payment', 'deposit', 522.83, 'reconciled', 'CC payment Sep', 0, @john_id),
(@acc_cc2, '', '2025-10-09', 'Lyft', 'withdrawal', -44.84, 'reconciled', 'Lyft', 0, @john_id),
(@acc_cc2, '', '2025-10-24', 'Chase - Payment', 'deposit', 645.44, 'reconciled', 'CC payment Oct', 0, @john_id),
(@acc_cc2, '', '2025-11-09', 'Nobu Restaurant', 'withdrawal', -237.15, 'reconciled', 'Nobu Restaurant', 0, @john_id),
(@acc_cc2, '', '2025-11-24', 'Chase - Payment', 'deposit', 628.23, 'reconciled', 'CC payment Nov', 0, @john_id),
(@acc_cc2, '', '2025-12-09', 'Avis Car Rental', 'withdrawal', -273.92, 'reconciled', 'Avis Car Rental', 0, @john_id),
(@acc_cc2, '', '2025-12-24', 'Chase - Payment', 'deposit', 496.41, 'reconciled', 'CC payment Dec', 0, @john_id);
SET @t5_pre = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_cc2);

-- ── Transaction splits: Chase Sapphire Reserve (Jul 2024–Dec 2025) ──────
INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount) VALUES
(@t5_pre+0, @travel, @flights, -420.00),
(@t5_pre+1, @financial, @cc_payment, 592.84),
(@t5_pre+2, @travel, @hotels, -357.81),
(@t5_pre+3, @financial, @cc_payment, 659.08),
(@t5_pre+4, @business, @business_meals, -193.04),
(@t5_pre+5, @financial, @cc_payment, 565.52),
(@t5_pre+6, @transport, NULL, -34.50),
(@t5_pre+7, @financial, @cc_payment, 449.22),
(@t5_pre+8, @food, @restaurants, -164.66),
(@t5_pre+9, @financial, @cc_payment, 480.56),
(@t5_pre+10, @travel, @vacation_activities, -292.05),
(@t5_pre+11, @financial, @cc_payment, 613.63),
(@t5_pre+12, @travel, @flights, -503.88),
(@t5_pre+13, @financial, @cc_payment, 653.48),
(@t5_pre+14, @travel, @hotels, -319.15),
(@t5_pre+15, @financial, @cc_payment, 541.73),
(@t5_pre+16, @business, @business_meals, -146.03),
(@t5_pre+17, @financial, @cc_payment, 442.10),
(@t5_pre+18, @transport, NULL, -33.90),
(@t5_pre+19, @financial, @cc_payment, 500.54),
(@t5_pre+20, @food, @restaurants, -216.81),
(@t5_pre+21, @financial, @cc_payment, 631.44),
(@t5_pre+22, @travel, @vacation_activities, -335.26),
(@t5_pre+23, @financial, @cc_payment, 643.03),
(@t5_pre+24, @travel, @flights, -429.05),
(@t5_pre+25, @financial, @cc_payment, 518.33),
(@t5_pre+26, @travel, @hotels, -244.25),
(@t5_pre+27, @financial, @cc_payment, 440.02),
(@t5_pre+28, @business, @business_meals, -153.83),
(@t5_pre+29, @financial, @cc_payment, 522.83),
(@t5_pre+30, @transport, NULL, -44.84),
(@t5_pre+31, @financial, @cc_payment, 645.44),
(@t5_pre+32, @food, @restaurants, -237.15),
(@t5_pre+33, @financial, @cc_payment, 628.23),
(@t5_pre+34, @travel, @vacation_activities, -273.92),
(@t5_pre+35, @financial, @cc_payment, 496.41);

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
SET @t5 = (SELECT MIN(id) FROM transactions WHERE account_id = @acc_cc2 AND transaction_date >= '2026-01-01');

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

-- ── Brokerage cash contributions (fund the new-security purchases below) ──
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by) VALUES
(@acc_brok_cash, '2024-08-01', 'Cash Contribution', 'transfer', 10000.00, 'reconciled', 'Deposit to fund new positions', @admin_id),
(@acc_brok_cash, '2025-07-01', 'Cash Contribution', 'transfer',  8000.00, 'reconciled', 'Deposit to fund new positions', @admin_id);

-- ── Investment transactions: new securities on Fidelity Brokerage ─────
-- buy 15 IBM @ 172.40 = 2586.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2024-09-16', 'International Business Machines Corp.', 'investment', 2586.00, 'reconciled', 'Buy 15 shares @ 172.40', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @ibm_id, 'buy', 15.000000, 172.400000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2024-09-16', 'International Business Machines Corp.', 'transfer', -2586.00, 'reconciled', 'Buy 15 International Business Machines Corp.', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- buy 10 IBM @ 201.75 = 2017.50
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2025-06-10', 'International Business Machines Corp.', 'investment', 2017.50, 'reconciled', 'Buy 10 shares @ 201.75', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @ibm_id, 'buy', 10.000000, 201.750000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2025-06-10', 'International Business Machines Corp.', 'transfer', -2017.50, 'reconciled', 'Buy 10 International Business Machines Corp.', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- buy 3 NFLX @ 651.20 = 1953.60
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2024-11-08', 'Netflix, Inc.', 'investment', 1953.60, 'reconciled', 'Buy 3 shares @ 651.20', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @nflx_id, 'buy', 3.000000, 651.200000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2024-11-08', 'Netflix, Inc.', 'transfer', -1953.60, 'reconciled', 'Buy 3 Netflix', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- buy 20 ORCL @ 141.60 = 2832.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2025-02-19', 'Oracle Corporation', 'investment', 2832.00, 'reconciled', 'Buy 20 shares @ 141.60', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @orcl_id, 'buy', 20.000000, 141.600000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2025-02-19', 'Oracle Corporation', 'transfer', -2832.00, 'reconciled', 'Buy 20 Oracle Corporation', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- buy 10 ORCL @ 176.90 = 1769.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2025-10-14', 'Oracle Corporation', 'investment', 1769.00, 'reconciled', 'Buy 10 shares @ 176.90', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @orcl_id, 'buy', 10.000000, 176.900000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2025-10-14', 'Oracle Corporation', 'transfer', -1769.00, 'reconciled', 'Buy 10 Oracle Corporation', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- buy 12 GE @ 168.30 = 2019.60
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2025-01-24', 'GE Aerospace', 'investment', 2019.60, 'reconciled', 'Buy 12 shares @ 168.30', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @ge_id, 'buy', 12.000000, 168.300000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2025-01-24', 'GE Aerospace', 'transfer', -2019.60, 'reconciled', 'Buy 12 GE Aerospace', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- sell 4 GE @ 208.50 = 834.00
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2025-12-05', 'GE Aerospace', 'investment', -834.00, 'reconciled', 'Sell 4 shares @ 208.50', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @ge_id, 'sell', 4.000000, 208.500000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2025-12-05', 'GE Aerospace', 'transfer', 834.00, 'reconciled', 'Sell 4 GE Aerospace', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;
-- sell 1 NFLX @ 902.40 = 902.40
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
VALUES (@acc_brok, '2026-02-11', 'Netflix, Inc.', 'investment', -902.40, 'reconciled', 'Sell 1 shares @ 902.40', @admin_id);
SET @newtr = LAST_INSERT_ID();
INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission) VALUES (@newtr, @nflx_id, 'sell', 1.000000, 902.400000, 0.00);
INSERT INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, transfer_pair_id, created_by)
VALUES (@acc_brok_cash, '2026-02-11', 'Netflix, Inc.', 'transfer', 902.40, 'reconciled', 'Sell 1 Netflix', @newtr, @admin_id);
SET @newtrc = LAST_INSERT_ID();
UPDATE transactions SET transfer_pair_id = @newtrc WHERE id = @newtr;

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
(@t1+0,  @income,        @salary,      7600.00),
(@t1+1,  @housing,       @mortgage,   -3792.41),
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
(@t1+18, @income,        @salary,      7600.00),
(@t1+19, @housing,       @mortgage,   -3792.41),
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
(@t1+35, @income,        @salary,      7600.00),
(@t1+36, @housing,       @mortgage,   -3792.41),
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
(@t1+52, @income,        @salary,      7600.00),
(@t1+53, @housing,       @mortgage,   -3792.41),
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
(@t1+68, @income,        @salary,      7600.00),
(@t1+69, @housing,       @mortgage,   -3792.41),
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
(@t1+84, @income,        @salary,      7600.00),
(@t1+85, @housing,       @mortgage,   -3792.41),
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

-- ── Loan account payments (principal portion; interest is the Housing/Mortgage expense on Checking) ──
INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, created_by) VALUES
(@acc_loan, '', '2025-05-12', 'First National Mortgage', 'transfer', 542.41, 'reconciled', 'Payment 1 of 360 — principal 542.41, interest 3250.00, balance 599457.59', @admin_id),
(@acc_loan, '', '2025-06-12', 'First National Mortgage', 'transfer', 545.35, 'reconciled', 'Payment 2 of 360 — principal 545.35, interest 3247.06, balance 598912.24', @admin_id),
(@acc_loan, '', '2025-07-12', 'First National Mortgage', 'transfer', 548.30, 'reconciled', 'Payment 3 of 360 — principal 548.30, interest 3244.11, balance 598363.94', @admin_id),
(@acc_loan, '', '2025-08-12', 'First National Mortgage', 'transfer', 551.27, 'reconciled', 'Payment 4 of 360 — principal 551.27, interest 3241.14, balance 597812.67', @admin_id),
(@acc_loan, '', '2025-09-12', 'First National Mortgage', 'transfer', 554.26, 'reconciled', 'Payment 5 of 360 — principal 554.26, interest 3238.15, balance 597258.41', @admin_id),
(@acc_loan, '', '2025-10-12', 'First National Mortgage', 'transfer', 557.26, 'reconciled', 'Payment 6 of 360 — principal 557.26, interest 3235.15, balance 596701.15', @admin_id),
(@acc_loan, '', '2025-11-12', 'First National Mortgage', 'transfer', 560.28, 'reconciled', 'Payment 7 of 360 — principal 560.28, interest 3232.13, balance 596140.87', @admin_id),
(@acc_loan, '', '2025-12-12', 'First National Mortgage', 'transfer', 563.31, 'reconciled', 'Payment 8 of 360 — principal 563.31, interest 3229.10, balance 595577.56', @admin_id),
(@acc_loan, '', '2026-01-12', 'First National Mortgage', 'transfer', 566.36, 'reconciled', 'Payment 9 of 360 — principal 566.36, interest 3226.05, balance 595011.20', @admin_id),
(@acc_loan, '', '2026-02-12', 'First National Mortgage', 'transfer', 569.43, 'reconciled', 'Payment 10 of 360 — principal 569.43, interest 3222.98, balance 594441.77', @admin_id),
(@acc_loan, '', '2026-03-12', 'First National Mortgage', 'transfer', 572.52, 'reconciled', 'Payment 11 of 360 — principal 572.52, interest 3219.89, balance 593869.25', @admin_id),
(@acc_loan, '', '2026-04-12', 'First National Mortgage', 'transfer', 575.62, 'cleared', 'Payment 12 of 360 — principal 575.62, interest 3216.79, balance 593293.63', @admin_id),
(@acc_loan, '', '2026-05-12', 'First National Mortgage', 'transfer', 578.74, '', 'Payment 13 of 360 — principal 578.74, interest 3213.67, balance 592714.89', @admin_id),
(@acc_loan, '', '2026-06-12', 'First National Mortgage', 'transfer', 581.87, '', 'Payment 14 of 360 — principal 581.87, interest 3210.54, balance 592133.02', @admin_id);

-- ── Scheduled bills ────────────────────────────────────────────────────────
INSERT INTO scheduled_bills (name, type, account_id, category_id, subcategory_id, amount, frequency, next_due_date, notes) VALUES
('Mortgage Payment',       'bill',    @acc_chk, @housing,       @mortgage,    -3792.41, 'monthly', '2026-07-02', 'First National Mortgage'),
('Toyota Car Payment',     'bill',    @acc_chk, @transport,     @car_pay,      -387.50, 'monthly', '2026-07-07', 'Toyota Financial Services'),
('Geico Auto Insurance',   'bill',    @acc_chk, @transport,     @auto_ins,     -112.00, 'monthly', '2026-07-30', 'Auto insurance'),
('Comcast Internet',       'bill',    @acc_chk, @housing,       @utilities,     -89.99, 'monthly', '2026-07-06', 'Internet and cable'),
('Netflix',                'bill',    @acc_chk, @entertainment, @streaming,     -22.99, 'monthly', '2026-07-14', 'Streaming service'),
('Acme Payroll',           'deposit', @acc_chk, @income,        @salary,       7600.00, 'monthly', '2026-07-01', 'Monthly salary deposit'),
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

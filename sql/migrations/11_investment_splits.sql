-- Security-level stock splits. Per-account 'split' transactions adjust share
-- counts; this records the split itself (ex-date + ratio) so price charts and
-- price-return figures can show pre-split prices in post-split terms. Stored
-- prices and historical valuations are not changed.
CREATE TABLE IF NOT EXISTS investment_splits (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    investment_id INT           NOT NULL,
    split_date    DATE          NOT NULL,
    ratio         DECIMAL(15,8) NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inv_split (investment_id, split_date),
    FOREIGN KEY (investment_id) REFERENCES investments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

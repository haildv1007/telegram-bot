-- Search terms & keywords cache cho Google Ads
CREATE TABLE IF NOT EXISTS ads_search_terms_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credential_id INT UNSIGNED NOT NULL,
    campaign_id VARCHAR(50) NOT NULL,
    search_term VARCHAR(500) NOT NULL,
    keyword_text VARCHAR(500) NOT NULL DEFAULT '',
    match_type VARCHAR(20) NOT NULL DEFAULT '',
    spend_date DATE NOT NULL,
    spend DECIMAL(12,2) NOT NULL DEFAULT 0,
    impressions INT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    conversions INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_term (credential_id, campaign_id, search_term(191), spend_date),
    INDEX idx_date (spend_date),
    INDEX idx_cred_date (credential_id, spend_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

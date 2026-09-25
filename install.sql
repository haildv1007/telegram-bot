-- =============================================
-- FRESH INSTALL — chạy 1 lần trên DB mới
-- =============================================
SET FOREIGN_KEY_CHECKS = 0;

-- 1. bots
CREATE TABLE IF NOT EXISTS bots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token VARCHAR(255) NOT NULL,
  webhook_secret VARCHAR(100),
  gemini_api_key VARCHAR(255) DEFAULT NULL,
  role ENUM('reader','reporter','both') NOT NULL DEFAULT 'reader',
  is_default_reporter TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ads_credentials
CREATE TABLE IF NOT EXISTS ads_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platform ENUM('google','facebook') NOT NULL,
  account_id VARCHAR(50) NOT NULL,
  login_customer_id VARCHAR(50) NULL,
  account_label VARCHAR(100),
  developer_token VARCHAR(255) NULL,
  client_id VARCHAR(255) NULL,
  client_secret VARCHAR(255) NULL,
  refresh_token TEXT NULL,
  access_token TEXT NULL,
  token_expires_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_platform_account (platform, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. channel_groups
CREATE TABLE IF NOT EXISTS channel_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  report_bot_id INT NULL,
  report_chat_id VARCHAR(50) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_grp_bot FOREIGN KEY (report_bot_id) REFERENCES bots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. ad_channels
CREATE TABLE IF NOT EXISTS ad_channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NULL,
  name VARCHAR(150) NOT NULL,
  source_bot_id INT NULL,
  source_chat_id VARCHAR(50) NULL,
  report_bot_id INT NULL,
  report_chat_id VARCHAR(50) NULL,
  credential_id INT NULL,
  platform_campaign_id TEXT NULL,
  report_scope ENUM('campaign','account') NOT NULL DEFAULT 'campaign',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_source (source_chat_id),
  CONSTRAINT fk_ch_group FOREIGN KEY (group_id) REFERENCES channel_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_ch_source_bot FOREIGN KEY (source_bot_id) REFERENCES bots(id) ON DELETE SET NULL,
  CONSTRAINT fk_ch_report_bot FOREIGN KEY (report_bot_id) REFERENCES bots(id) ON DELETE SET NULL,
  CONSTRAINT fk_ch_cred FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. channel_credentials (1 channel → nhiều tài khoản ads)
CREATE TABLE IF NOT EXISTS channel_credentials (
  channel_id INT NOT NULL,
  credential_id INT NOT NULL,
  PRIMARY KEY (channel_id, credential_id),
  CONSTRAINT fk_cc_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_cr FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. channel_schedules
CREATE TABLE IF NOT EXISTS channel_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
  report_time TIME NOT NULL,
  report_type ENUM('progress','summary') NOT NULL DEFAULT 'summary',
  report_for ENUM('today','yesterday') NOT NULL DEFAULT 'yesterday',
  active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_time (report_time, active),
  CONSTRAINT fk_sched_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. lead_rules
CREATE TABLE IF NOT EXISTS lead_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NULL,
  priority INT NOT NULL DEFAULT 100,
  match_type ENUM('contains','regex') NOT NULL DEFAULT 'contains',
  match_field ENUM('full_text','source','utm') NOT NULL DEFAULT 'full_text',
  pattern VARCHAR(500) NOT NULL,
  extract_name_regex VARCHAR(500) NULL,
  extract_phone_regex VARCHAR(500) NULL,
  extract_cccd_regex VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_channel_prio (channel_id, priority),
  CONSTRAINT fk_rule_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. leads
CREATE TABLE IF NOT EXISTS leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NULL,
  platform_campaign_id VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  name VARCHAR(255) DEFAULT '',
  phone VARCHAR(50) DEFAULT '',
  cccd VARCHAR(50) DEFAULT '',
  area VARCHAR(255) DEFAULT '',
  vehicle VARCHAR(255) DEFAULT '',
  source VARCHAR(255) DEFAULT '',
  ip VARCHAR(50) DEFAULT '',
  dup_key VARCHAR(255) DEFAULT '',
  raw_text TEXT NULL,
  KEY idx_channel_date (channel_id, created_at),
  KEY idx_camp (platform_campaign_id, created_at),
  KEY idx_dup (dup_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. ad_budget
CREATE TABLE IF NOT EXISTS ad_budget (
  budget_date DATE NOT NULL,
  channel_id INT NOT NULL DEFAULT 0,
  budget DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (budget_date, channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. ads_spend_cache
CREATE TABLE IF NOT EXISTS ads_spend_cache (
  credential_id INT NOT NULL,
  campaign_id VARCHAR(80) NOT NULL DEFAULT '',
  spend_date DATE NOT NULL,
  spend DECIMAL(15,2) NOT NULL DEFAULT 0,
  impressions INT NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  conversions INT NOT NULL DEFAULT 0,
  reach INT NULL,
  frequency DECIMAL(6,2) NULL,
  result_label VARCHAR(60) NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (credential_id, campaign_id, spend_date),
  CONSTRAINT fk_spend_cred FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. campaigns (metadata cache)
CREATE TABLE IF NOT EXISTS campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  credential_id INT NOT NULL,
  platform_campaign_id VARCHAR(80) NOT NULL,
  name VARCHAR(255),
  status VARCHAR(30),
  synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cred_camp (credential_id, platform_campaign_id),
  CONSTRAINT fk_camp_cred FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. ads_search_terms_cache
CREATE TABLE IF NOT EXISTS ads_search_terms_cache (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  credential_id INT NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. unmatched_leads
CREATE TABLE IF NOT EXISTS unmatched_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NULL,
  chat_id VARCHAR(50),
  raw_text TEXT,
  received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. report_history
CREATE TABLE IF NOT EXISTS report_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
  schedule_id INT NULL,
  report_date DATE NOT NULL,
  fire_date DATE NULL,
  report_type ENUM('progress','summary','manual') NOT NULL DEFAULT 'summary',
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  message TEXT,
  success TINYINT(1) NOT NULL DEFAULT 1,
  error TEXT NULL,
  KEY idx_dedupe (channel_id, report_date, schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. digest_history
CREATE TABLE IF NOT EXISTS digest_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  send_date DATE NOT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  message TEXT,
  success TINYINT(1) DEFAULT 1,
  error TEXT NULL,
  UNIQUE KEY uq_group_date (group_id, send_date),
  CONSTRAINT fk_dh_group FOREIGN KEY (group_id) REFERENCES channel_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. app_settings
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('digest_time', '07:00')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 17. admin_users
CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  last_login DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

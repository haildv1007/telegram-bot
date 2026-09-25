-- Migration 001: multi-channel schema
-- Chạy 1 lần trên DB đã import dump gốc.

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. bots — nhiều bot Telegram (reader / reporter / both)
-- ============================================================
CREATE TABLE IF NOT EXISTS bots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token VARCHAR(255) NOT NULL,
  webhook_secret VARCHAR(100),
  role ENUM('reader','reporter','both') NOT NULL DEFAULT 'reader',
  is_default_reporter TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. ads_credentials — token Google / Facebook Ads
-- ============================================================
CREATE TABLE IF NOT EXISTS ads_credentials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  platform ENUM('google','facebook') NOT NULL,
  account_id VARCHAR(50) NOT NULL,
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

-- ============================================================
-- 3. ad_channels — đơn vị báo cáo
-- ============================================================
CREATE TABLE IF NOT EXISTS ad_channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  source_bot_id INT NULL,
  source_chat_id VARCHAR(50) NULL,
  report_bot_id INT NULL,
  report_chat_id VARCHAR(50) NULL,
  credential_id INT NULL,
  platform_campaign_id VARCHAR(80) NULL,
  report_scope ENUM('campaign','account') NOT NULL DEFAULT 'campaign',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_source (source_chat_id),
  CONSTRAINT fk_ch_source_bot FOREIGN KEY (source_bot_id) REFERENCES bots(id) ON DELETE SET NULL,
  CONSTRAINT fk_ch_report_bot FOREIGN KEY (report_bot_id) REFERENCES bots(id) ON DELETE SET NULL,
  CONSTRAINT fk_ch_cred FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. channel_schedules — nhiều mốc báo cáo / channel
-- ============================================================
CREATE TABLE IF NOT EXISTS channel_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
  report_time TIME NOT NULL,
  report_type ENUM('progress','summary') NOT NULL DEFAULT 'summary',
  active TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_time (report_time, active),
  CONSTRAINT fk_sched_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. lead_rules — parse lead → gán channel
-- ============================================================
CREATE TABLE IF NOT EXISTS lead_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
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
  CONSTRAINT fk_rule_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. ads_spend_cache — spend theo (credential, camp, ngày)
-- ============================================================
CREATE TABLE IF NOT EXISTS ads_spend_cache (
  credential_id INT NOT NULL,
  campaign_id VARCHAR(80) NOT NULL DEFAULT '',
  spend_date DATE NOT NULL,
  spend DECIMAL(15,2) NOT NULL DEFAULT 0,
  impressions INT NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  conversions INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (credential_id, campaign_id, spend_date),
  CONSTRAINT fk_spend_cred FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. unmatched_leads — tin không match rule
-- ============================================================
CREATE TABLE IF NOT EXISTS unmatched_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bot_id INT NULL,
  chat_id VARCHAR(50),
  raw_text TEXT,
  received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. report_history — lịch sử báo cáo đã gửi
-- ============================================================
CREATE TABLE IF NOT EXISTS report_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
  schedule_id INT NULL,
  report_date DATE NOT NULL,
  report_type ENUM('progress','summary','manual') NOT NULL DEFAULT 'summary',
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  message TEXT,
  success TINYINT(1) NOT NULL DEFAULT 1,
  error TEXT NULL,
  KEY idx_dedupe (channel_id, report_date, schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. admin_users
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  last_login DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. ALTER bảng cũ
-- ============================================================
ALTER TABLE leads
  ADD COLUMN channel_id INT NULL AFTER id,
  ADD COLUMN raw_text TEXT NULL,
  ADD INDEX idx_channel_date (channel_id, created_at);

ALTER TABLE ad_budget
  ADD COLUMN channel_id INT NOT NULL DEFAULT 0 AFTER budget_date;

ALTER TABLE ad_budget DROP PRIMARY KEY;
ALTER TABLE ad_budget ADD PRIMARY KEY (budget_date, channel_id);

SET FOREIGN_KEY_CHECKS = 1;

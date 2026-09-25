-- 1. Bảng trung gian: 1 rule gắn nhiều channel
CREATE TABLE IF NOT EXISTS rule_channels (
  rule_id INT NOT NULL,
  channel_id INT NOT NULL,
  PRIMARY KEY (rule_id, channel_id),
  CONSTRAINT fk_rc_rule FOREIGN KEY (rule_id) REFERENCES lead_rules(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate dữ liệu cũ: rule nào đang có channel_id → chuyển sang rule_channels
INSERT IGNORE INTO rule_channels (rule_id, channel_id)
SELECT id, channel_id FROM lead_rules WHERE channel_id IS NOT NULL;

-- 2. Thêm currency cho tài khoản ads
ALTER TABLE ads_credentials
  ADD COLUMN currency VARCHAR(5) NOT NULL DEFAULT 'VND' AFTER account_label;

-- 3. Thêm settings cho tỷ giá
INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('exchange_rate_multiplier', '1.095'),
  ('usdt_vnd_rate', '25500'),
  ('usdt_vnd_rate_updated', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

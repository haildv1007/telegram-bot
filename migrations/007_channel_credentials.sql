-- Migration 007: bảng channel_credentials cho phép 1 channel link nhiều tkqc
CREATE TABLE IF NOT EXISTS channel_credentials (
  channel_id INT NOT NULL,
  credential_id INT NOT NULL,
  PRIMARY KEY (channel_id, credential_id),
  CONSTRAINT fk_cc_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE CASCADE,
  CONSTRAINT fk_cc_cr FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate data cũ: mỗi channel có credential_id → 1 dòng trong bảng mới
INSERT IGNORE INTO channel_credentials (channel_id, credential_id)
SELECT id, credential_id FROM ad_channels WHERE credential_id IS NOT NULL;

-- Giữ nguyên cột ad_channels.credential_id để backward-compat, chưa drop

-- Migration 010: tách hẳn "nhóm channel" thành 1 entity riêng, không dùng channel làm cha nữa
CREATE TABLE IF NOT EXISTS channel_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ad_channels DROP FOREIGN KEY fk_ch_parent;
ALTER TABLE ad_channels DROP COLUMN parent_channel_id;
ALTER TABLE ad_channels ADD COLUMN group_id INT NULL AFTER id;
ALTER TABLE ad_channels ADD CONSTRAINT fk_ch_group FOREIGN KEY (group_id) REFERENCES channel_groups(id) ON DELETE SET NULL;

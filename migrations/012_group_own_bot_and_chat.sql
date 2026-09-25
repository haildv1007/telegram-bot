-- Migration 012: nhóm (channel_groups) có bot gửi + chat đích riêng, mỗi nhóm gửi 1 tin độc lập
ALTER TABLE channel_groups
  ADD COLUMN report_bot_id INT NULL AFTER name,
  ADD COLUMN report_chat_id VARCHAR(50) NULL AFTER report_bot_id,
  ADD CONSTRAINT fk_grp_bot FOREIGN KEY (report_bot_id) REFERENCES bots(id) ON DELETE SET NULL;

-- digest_history giờ theo từng nhóm, không còn 1 dòng chung/ngày nữa
DROP TABLE IF EXISTS digest_history;
CREATE TABLE digest_history (
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

-- Bỏ digest_chat_id/digest_bot_id global cũ (giờ cấu hình theo từng nhóm), giữ digest_time (giờ gửi chung)
DELETE FROM app_settings WHERE setting_key IN ('digest_chat_id', 'digest_bot_id');

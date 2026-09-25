-- Migration 011:
-- 1. Thêm report_for (today/yesterday) cho channel_schedules — báo cáo 7h sáng cho ngày hôm qua
-- 2. Tách fire_date (ngày cron thực sự chạy) khỏi report_date (ngày dữ liệu) trong report_history
--    để dedupe đúng khi report_for = yesterday
-- 3. app_settings + digest_history cho tính năng "Tổng hợp lũy kế toàn hệ thống"

ALTER TABLE channel_schedules
  ADD COLUMN report_for ENUM('today','yesterday') NOT NULL DEFAULT 'yesterday' AFTER report_type;
UPDATE channel_schedules SET report_for = 'today' WHERE report_type = 'progress';

ALTER TABLE report_history
  ADD COLUMN fire_date DATE NULL AFTER report_date;
UPDATE report_history SET fire_date = report_date WHERE fire_date IS NULL;

-- Chuyển các schedule summary hiện có (23:55) sang 07:00 sáng hôm sau, báo cáo cho hôm qua
UPDATE channel_schedules SET report_time = '07:00:00', report_for = 'yesterday' WHERE report_type = 'summary';

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('digest_chat_id', '-1003988989087'),
  ('digest_bot_id', '1'),
  ('digest_time', '07:00')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

CREATE TABLE IF NOT EXISTS digest_history (
  send_date DATE PRIMARY KEY,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  message TEXT,
  success TINYINT(1) DEFAULT 1,
  error TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

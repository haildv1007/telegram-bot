-- Migration 002: seed data legacy + tạo 2 channel default cho Shipfood/Taixefood
-- Chạy sau 001.

-- 2 landing hiện có → 2 channel legacy
INSERT INTO ad_channels (id, name, source_chat_id, report_chat_id, report_scope, active)
VALUES
  (1, 'Shipfood (legacy)', '-1003988989087', '-1003988989087', 'account', 1),
  (2, 'Taixefood (legacy)', '-1003988989087', '-1003988989087', 'account', 1);

-- Rule đơn giản dựa vào field `source` đã có trong DB
INSERT INTO lead_rules (channel_id, priority, match_type, match_field, pattern, active)
VALUES
  (1, 100, 'contains', 'full_text', 'shipfood.io.vn', 1),
  (2, 100, 'contains', 'full_text', 'taixefood.io.vn', 1);

-- Schedule mặc định: cuối ngày 23:55 (giữ giống hệ thống cũ)
INSERT INTO channel_schedules (channel_id, report_time, report_type, active) VALUES
  (1, '23:55:00', 'summary', 1),
  (2, '23:55:00', 'summary', 1);

-- Bot hiện có → seed vào bảng bots (role both)
INSERT INTO bots (name, token, webhook_secret, role, is_default_reporter, active)
VALUES ('Legacy SPF Bot', '8901267037:AAHxTpT96uJmpekAqUhThp37GaIcz246S70', 'SPFBOTTELEANALY', 'both', 1, 1);

-- Gán bot legacy làm source + reporter cho 2 channel
UPDATE ad_channels SET source_bot_id = 1, report_bot_id = 1 WHERE id IN (1, 2);

-- Migrate leads cũ theo source
UPDATE leads SET channel_id = 1 WHERE source = 'shipfood.io.vn';
UPDATE leads SET channel_id = 2 WHERE source = 'taixefood.io.vn';

-- Budget cũ đổ về channel 1 (Shipfood - đây là landing chạy đầu tiên)
UPDATE ad_budget SET channel_id = 1 WHERE channel_id = 0;

-- Admin user: username=admin, password=admin123 (đổi ngay sau khi login)
-- Hash sẽ được set qua script tools/seed_admin.php sau khi migrate xong (bcrypt cost 12).
INSERT INTO admin_users (username, password_hash)
VALUES ('admin', 'PLACEHOLDER_RUN_seed_admin.php');

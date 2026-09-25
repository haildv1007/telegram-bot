ALTER TABLE channel_groups
  ADD COLUMN digest_schedule ENUM('daily','weekly','monthly','off') NOT NULL DEFAULT 'off' AFTER report_chat_id,
  ADD COLUMN digest_time TIME DEFAULT '07:00:00' AFTER digest_schedule,
  ADD COLUMN digest_day TINYINT DEFAULT NULL AFTER digest_time;
-- digest_day: cho weekly = 1(Mon)..7(Sun), cho monthly = 1..28

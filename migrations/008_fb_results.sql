-- Migration 008: thêm reach/frequency/result_label cho FB "Kết quả" theo đúng loại
ALTER TABLE ads_spend_cache
  ADD COLUMN reach INT NULL AFTER conversions,
  ADD COLUMN frequency DECIMAL(6,2) NULL AFTER reach,
  ADD COLUMN result_label VARCHAR(60) NULL AFTER frequency;
-- conversions column được tái dùng:
--   Google: conversions thật (Google Ads conversion tracking)
--   Facebook: số "kết quả" (result count) theo loại action ưu tiên cao nhất tìm được
--   result_label: tên loại kết quả FB (VD "Tin nhắn", "Lượt mua", "Lead") — NULL với Google

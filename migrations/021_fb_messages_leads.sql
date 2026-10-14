-- Lưu riêng kết quả Tin nhắn, không gộp với Mua/Đăng ký.
ALTER TABLE ads_spend_cache
  ADD COLUMN messages INT NOT NULL DEFAULT 0 AFTER subscribes;

-- Lưu riêng Purchase và Subscribe của Facebook để có thể hiển thị đồng thời.
ALTER TABLE ads_spend_cache
  ADD COLUMN purchases INT NOT NULL DEFAULT 0 AFTER result_label,
  ADD COLUMN subscribes INT NOT NULL DEFAULT 0 AFTER purchases;

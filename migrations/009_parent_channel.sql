-- Migration 009: parent_channel_id để gộp lũy kế nhiều channel (VD 2 domain cùng 1 sản phẩm)
ALTER TABLE ad_channels
  ADD COLUMN parent_channel_id INT NULL AFTER id,
  ADD CONSTRAINT fk_ch_parent FOREIGN KEY (parent_channel_id) REFERENCES ad_channels(id) ON DELETE SET NULL;

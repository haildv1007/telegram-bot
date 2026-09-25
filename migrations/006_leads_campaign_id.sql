-- Migration 006: track platform campaign id trên lead (từ gad_campaignid trong URL)
ALTER TABLE leads
  ADD COLUMN platform_campaign_id VARCHAR(50) NULL AFTER channel_id,
  ADD INDEX idx_camp (platform_campaign_id, created_at);

-- Migration 004: cho phép nhiều campaign / channel
ALTER TABLE ad_channels MODIFY platform_campaign_id TEXT NULL;
-- Format: CSV các campaign_id, VD "12345,67890,11111". NULL/empty = cả account.

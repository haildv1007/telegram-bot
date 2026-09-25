-- Migration 003: thêm login_customer_id (MCC id) cho Google Ads client
ALTER TABLE ads_credentials
  ADD COLUMN login_customer_id VARCHAR(50) NULL AFTER account_id;

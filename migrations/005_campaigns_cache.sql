-- Migration 005: bảng campaigns cache metadata (id, name, status) — để chip hiện tên
CREATE TABLE IF NOT EXISTS campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  credential_id INT NOT NULL,
  platform_campaign_id VARCHAR(80) NOT NULL,
  name VARCHAR(255),
  status VARCHAR(30),
  synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cred_camp (credential_id, platform_campaign_id),
  CONSTRAINT fk_camp_cred FOREIGN KEY (credential_id) REFERENCES ads_credentials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

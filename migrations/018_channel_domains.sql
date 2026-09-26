-- Map website domains to channels for LadiPage auto-detection
CREATE TABLE IF NOT EXISTS channel_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain),
    KEY idx_channel (channel_id)
);

-- Seed initial mappings based on known LadiPage websites
INSERT IGNORE INTO channel_domains (channel_id, domain) VALUES
(1, 'xanhsmdangkynhanh.online'),
(1, 'xanhsm.io.vn');

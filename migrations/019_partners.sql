CREATE TABLE IF NOT EXISTS partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    passcode VARCHAR(10) NOT NULL,
    group_ids VARCHAR(255) NOT NULL COMMENT 'comma-separated channel_groups.id',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_slug (slug)
);

INSERT IGNORE INTO partners (slug, name, passcode, group_ids) VALUES
('xanhsm', 'Xanh SM', '123456', '1,2');

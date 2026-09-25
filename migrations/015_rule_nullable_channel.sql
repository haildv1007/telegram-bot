ALTER TABLE lead_rules MODIFY channel_id INT NULL;
ALTER TABLE lead_rules DROP FOREIGN KEY fk_rule_ch;
ALTER TABLE lead_rules ADD CONSTRAINT fk_rule_ch FOREIGN KEY (channel_id) REFERENCES ad_channels(id) ON DELETE SET NULL;

ALTER TABLE bots ADD COLUMN gemini_api_key VARCHAR(255) DEFAULT NULL AFTER webhook_secret;

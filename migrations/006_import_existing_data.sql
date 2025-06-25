-- This migration imports data from your existing system
-- Adjust table/column names as needed based on your current database

-- Generate and display API keys
SET @master_key = CONCAT('sk_live_', SUBSTRING(MD5(RAND()), 1, 32));
SET @dashboard_key = CONCAT('sk_dash_', SUBSTRING(MD5(RAND()), 1, 32));

-- Update the placeholder keys with real ones
UPDATE api_keys 
SET api_key = SHA2(@master_key, 256) 
WHERE name = 'Master Key';

UPDATE api_keys 
SET api_key = SHA2(@dashboard_key, 256) 
WHERE name = 'Dashboard';

-- Store keys in a table for display
CREATE TEMPORARY TABLE IF NOT EXISTS temp_api_keys (
    name VARCHAR(100),
    api_key VARCHAR(64)
);

INSERT INTO temp_api_keys VALUES 
('Master Key', @master_key),
('Dashboard', @dashboard_key);

-- Simple select to show the keys
SELECT CONCAT(name, ': ', api_key) as 'YOUR API KEYS - SAVE THESE!' FROM temp_api_keys;

DROP TEMPORARY TABLE temp_api_keys;

-- Update platform API keys from environment or config
-- You should update these with your actual API keys
UPDATE platforms SET api_config = JSON_SET(api_config, '$.api_key', 'YOUR_ACTIVECAMPAIGN_KEY') WHERE platform_code = 'activecampaign';
UPDATE platforms SET api_config = JSON_SET(api_config, '$.api_key', 'YOUR_ZEROBOUNCE_KEY') WHERE platform_code = 'zerobounce';
UPDATE platforms SET api_config = JSON_SET(api_config, '$.api_key', 'YOUR_MAILERCLOUD_KEY') WHERE platform_code = 'mailercloud';
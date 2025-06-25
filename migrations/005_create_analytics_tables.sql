-- Create analytics daily summary table
CREATE TABLE IF NOT EXISTS analytics_daily (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    platform_code VARCHAR(50),
    total_events INT DEFAULT 0,
    successful_events INT DEFAULT 0,
    failed_events INT DEFAULT 0,
    revenue DECIMAL(10,2) DEFAULT 0.00,
    avg_response_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_date_type_platform (date, event_type, platform_code),
    INDEX idx_date (date),
    INDEX idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create rate limit table
CREATE TABLE IF NOT EXISTS rate_limit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    type ENUM('ip', 'email') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier (identifier),
    INDEX idx_created_at (created_at),
    INDEX idx_type_identifier (type, identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create API keys table
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL,
    permissions JSON,
    rate_limit INT DEFAULT 0,
    expires_at DATETIME,
    last_used_at DATETIME,
    request_count INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_api_key (api_key),
    INDEX idx_name (name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create API access log table
CREATE TABLE IF NOT EXISTS api_access_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT,
    api_key_hash VARCHAR(16),
    endpoint VARCHAR(255),
    method VARCHAR(10),
    ip_address VARCHAR(45),
    user_agent TEXT,
    success BOOLEAN DEFAULT TRUE,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL,
    INDEX idx_api_key (api_key_id),
    INDEX idx_created_at (created_at),
    INDEX idx_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create default routing rules
INSERT INTO routing_rules (rule_name, event_type_id, platform_id, priority) 
SELECT 'Lead to ZeroBounce', 
    (SELECT id FROM event_types WHERE type_code = 'lead'),
    (SELECT id FROM platforms WHERE platform_code = 'zerobounce'),
    1;

INSERT INTO routing_rules (rule_name, event_type_id, platform_id, priority, conditions) 
SELECT 'Valid Lead to ActiveCampaign', 
    (SELECT id FROM event_types WHERE type_code = 'lead'),
    (SELECT id FROM platforms WHERE platform_code = 'activecampaign'),
    10,
    '{"email_validation_status": {"not_equals": "invalid"}}';

INSERT INTO routing_rules (rule_name, event_type_id, platform_id, priority) 
SELECT 'Lead to Woopra', 
    (SELECT id FROM event_types WHERE type_code = 'lead'),
    (SELECT id FROM platforms WHERE platform_code = 'woopra'),
    20;

INSERT INTO routing_rules (rule_name, event_type_id, platform_id, priority, conditions) 
SELECT 'Lead with Phone to LimeCellular', 
    (SELECT id FROM event_types WHERE type_code = 'lead'),
    (SELECT id FROM platforms WHERE platform_code = 'limecellular'),
    30,
    '{"has_phone": true}';

INSERT INTO routing_rules (rule_name, event_type_id, platform_id, priority, conditions) 
SELECT 'Valid Lead to Marketbeat', 
    (SELECT id FROM event_types WHERE type_code = 'lead'),
    (SELECT id FROM platforms WHERE platform_code = 'marketbeat'),
    40,
    '{"email_validation_status": "valid"}';

-- Create indexes for performance
CREATE INDEX idx_events_email_created ON events(email, created_at);
CREATE INDEX idx_events_status_created ON events(status, created_at);
CREATE INDEX idx_queue_status_platform ON processing_queue(status, platform_id);
CREATE INDEX idx_log_event_platform ON processing_log(event_id, platform_id);

-- Insert default API keys (remember to change these!)
INSERT INTO api_keys (name, api_key, permissions, rate_limit) VALUES
('Master Key', SHA2(CONCAT('master_', UNIX_TIMESTAMP(), RAND()), 256), '["*"]', 0),
('Dashboard', SHA2(CONCAT('dashboard_', UNIX_TIMESTAMP(), RAND()), 256), '["stats.read", "events.read"]', 1000);
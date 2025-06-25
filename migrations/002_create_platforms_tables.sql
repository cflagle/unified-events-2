-- Create event types table
CREATE TABLE IF NOT EXISTS event_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_code VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    validation_schema JSON,
    requires_email_validation BOOLEAN DEFAULT TRUE,
    requires_phone_validation BOOLEAN DEFAULT FALSE,
    honeypot_fields JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_code (type_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create platforms table
CREATE TABLE IF NOT EXISTS platforms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_code VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    platform_type VARCHAR(50),
    api_config JSON,
    request_method VARCHAR(10) DEFAULT 'POST',
    request_format VARCHAR(20) DEFAULT 'json',
    max_retries INT DEFAULT 3,
    retry_delay_ms INT DEFAULT 1000,
    timeout_seconds INT DEFAULT 30,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_platform_code (platform_code),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create routing rules table
CREATE TABLE IF NOT EXISTS routing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_name VARCHAR(100) NOT NULL,
    event_type_id INT NOT NULL,
    platform_id INT NOT NULL,
    conditions JSON,
    priority INT DEFAULT 100,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_type_id) REFERENCES event_types(id) ON DELETE CASCADE,
    FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE,
    INDEX idx_event_platform (event_type_id, platform_id),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create field mappings table
CREATE TABLE IF NOT EXISTS field_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_id INT NOT NULL,
    event_type_id INT NOT NULL,
    mappings JSON,
    transformations JSON,
    payload_template TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE,
    FOREIGN KEY (event_type_id) REFERENCES event_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_platform_event (platform_id, event_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default event types
INSERT INTO event_types (type_code, display_name, description, honeypot_fields) VALUES
('lead', 'Lead', 'Lead form submission', '["zipcode", "phonenumber"]'),
('purchase', 'Purchase', 'Purchase event', '[]'),
('email_open', 'Email Open', 'Email open tracking', '[]'),
('email_click', 'Email Click', 'Email click tracking', '[]');

-- Insert default platforms
INSERT INTO platforms (platform_code, display_name, platform_type, api_config) VALUES
('activecampaign', 'ActiveCampaign', 'crm', '{"api_url": "https://rif868.api-us1.com", "list_id": 2}'),
('woopra', 'Woopra', 'analytics', '{"domain": "wallstreetwatchdogs.com", "project": "wallstreetwatchdogs.com"}'),
('zerobounce', 'ZeroBounce', 'validation', '{"check_activity": true}'),
('limecellular', 'LimeCellular', 'sms', '{"user": "charles@rif.marketing", "api_id": "S4UWmQBaZ7yeF4z1", "list_id": "135859", "keyword": "Stock (18882312751)"}'),
('marketbeat', 'Marketbeat', 'monetization', '{"source": "tgcoreg", "site_prefix": "wswd", "revenue_per_lead": 2.00}'),
('mailercloud', 'MailerCloud', 'email', '{"contact_lists": ["UIWWUc"], "source_site": "wswd"}');
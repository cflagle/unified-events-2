-- Create bot registry table
CREATE TABLE IF NOT EXISTS bot_registry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier_type ENUM('email', 'phone', 'ip') NOT NULL,
    identifier_value VARCHAR(255) NOT NULL,
    detection_method VARCHAR(100),
    honeypot_fields JSON,
    first_seen_date DATETIME NOT NULL,
    last_seen_date DATETIME NOT NULL,
    attempt_count INT DEFAULT 1,
    associated_emails JSON,
    associated_ips JSON,
    associated_phones JSON,
    notes TEXT,
    severity ENUM('low', 'medium', 'high') DEFAULT 'medium',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_type_value (identifier_type, identifier_value),
    INDEX idx_identifier (identifier_value),
    INDEX idx_last_seen (last_seen_date),
    INDEX idx_severity (severity),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create email validation registry table
CREATE TABLE IF NOT EXISTS email_validation_registry (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    email_md5 VARCHAR(32) NOT NULL,
    validation_status VARCHAR(50) NOT NULL,
    validation_sub_status VARCHAR(100),
    zb_status VARCHAR(50),
    zb_sub_status VARCHAR(100),
    zb_last_active INT,
    zb_free_email BOOLEAN,
    zb_mx_found BOOLEAN,
    zb_smtp_provider VARCHAR(100),
    last_validated_at DATETIME NOT NULL,
    validation_count INT DEFAULT 1,
    validation_source VARCHAR(50),
    first_seen_valid DATETIME,
    first_seen_invalid DATETIME,
    status_change_history JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_md5 (email_md5),
    INDEX idx_status (validation_status),
    INDEX idx_last_validated (last_validated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create validation cache table
CREATE TABLE IF NOT EXISTS validation_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    validation_type VARCHAR(50) NOT NULL,
    validation_value VARCHAR(255) NOT NULL,
    validation_result JSON,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_type_value (validation_type, validation_value),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create suppression lists table
CREATE TABLE IF NOT EXISTS suppression_lists (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    list_type ENUM('email', 'phone', 'domain') NOT NULL,
    list_value VARCHAR(255) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    source VARCHAR(100),
    added_by VARCHAR(255),
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY uk_type_value (list_type, list_value),
    INDEX idx_value (list_value),
    INDEX idx_expires (expires_at),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create preprocessing filters table
CREATE TABLE IF NOT EXISTS preprocessing_filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filter_name VARCHAR(100) NOT NULL,
    filter_type ENUM('bot_check', 'email_validation', 'phone_validation', 'custom') NOT NULL,
    is_blocking BOOLEAN DEFAULT TRUE,
    check_order INT DEFAULT 100,
    applies_to_events JSON,
    filter_rules JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_active (check_order, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default preprocessing filters
INSERT INTO preprocessing_filters (filter_name, filter_type, check_order, applies_to_events) VALUES
('Honeypot Bot Check', 'bot_check', 10, '["lead", "purchase"]'),
('Known Bot Registry Check', 'bot_check', 20, NULL),
('Email Validation Cache Check', 'email_validation', 30, NULL),
('Email Format Validation', 'email_validation', 40, NULL),
('Phone Format Validation', 'phone_validation', 50, '["lead"]');
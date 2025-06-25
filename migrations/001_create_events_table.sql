-- Create events table
CREATE TABLE IF NOT EXISTS events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(36) UNIQUE NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    
    -- Common identification fields
    email VARCHAR(255),
    email_md5 VARCHAR(32),
    phone VARCHAR(20),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    ip_address VARCHAR(45),
    
    -- Lead acquisition data
    acq_source VARCHAR(100),
    acq_campaign VARCHAR(100),
    acq_term VARCHAR(255),
    acq_date DATETIME,
    acq_form_title VARCHAR(255),
    
    -- Current event attribution
    source VARCHAR(100),
    medium VARCHAR(100),
    campaign VARCHAR(100),
    content VARCHAR(255),
    term VARCHAR(255),
    gclid VARCHAR(255),
    ga_client_id VARCHAR(255),
    
    -- Purchase-specific fields
    offer VARCHAR(255),
    publisher VARCHAR(255),
    amount DECIMAL(10,2),
    traffic_source VARCHAR(100),
    purchase_creative VARCHAR(255),
    purchase_campaign VARCHAR(255),
    purchase_content VARCHAR(255),
    purchase_term VARCHAR(255),
    traffic_source_account VARCHAR(255),
    purchase_source VARCHAR(255),
    purchase_lp TEXT,
    sid202 VARCHAR(255),
    source_site VARCHAR(100),
    
    -- Validation status
    email_validation_status VARCHAR(50),
    phone_validation_status VARCHAR(50),
    zb_last_active INT,
    
    -- Event-specific data (JSON)
    event_data JSON,
    
    -- Processing status
    status ENUM('pending', 'processing', 'completed', 'failed', 'blocked') DEFAULT 'pending',
    blocked_reason VARCHAR(255),
    processed_at TIMESTAMP NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_event_type (event_type),
    INDEX idx_email (email),
    INDEX idx_email_md5 (email_md5),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_campaign (campaign),
    INDEX idx_offer (offer),
    INDEX idx_publisher (publisher),
    INDEX idx_source_campaign (source, campaign),
    INDEX idx_acq_date (acq_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
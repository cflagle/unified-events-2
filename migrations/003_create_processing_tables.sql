-- Create processing queue table
CREATE TABLE IF NOT EXISTS processing_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT NOT NULL,
    platform_id INT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'skipped') DEFAULT 'pending',
    skip_reason VARCHAR(255),
    attempts INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    process_after TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    locked_until TIMESTAMP NULL,
    locked_by VARCHAR(100) NULL,
    response_code INT,
    response_body TEXT,
    revenue_amount DECIMAL(10,2) DEFAULT 0.00,
    revenue_status ENUM('pending', 'confirmed', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE,
    INDEX idx_status_process_after (status, process_after),
    INDEX idx_event_platform (event_id, platform_id),
    INDEX idx_locked_until (locked_until),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create processing log table
CREATE TABLE IF NOT EXISTS processing_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT NOT NULL,
    platform_id INT NOT NULL,
    queue_id BIGINT,
    action VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL,
    request_payload TEXT,
    response_code INT,
    response_body TEXT,
    error_message TEXT,
    duration_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE,
    INDEX idx_event_id (event_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create event relationships table
CREATE TABLE IF NOT EXISTS event_relationships (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_event_id BIGINT NOT NULL,
    child_event_id BIGINT NOT NULL,
    relationship_type VARCHAR(50) NOT NULL,
    confidence_score DECIMAL(3,2) DEFAULT 1.00,
    matching_criteria JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (child_event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_parent (parent_event_id),
    INDEX idx_child (child_event_id),
    UNIQUE KEY uk_parent_child (parent_event_id, child_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create revenue tracking table
CREATE TABLE IF NOT EXISTS revenue_tracking (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT NOT NULL,
    platform_id INT NOT NULL,
    gross_revenue DECIMAL(10,2) NOT NULL,
    net_revenue DECIMAL(10,2),
    currency VARCHAR(3) DEFAULT 'USD',
    status ENUM('pending', 'confirmed', 'paid', 'rejected', 'refunded') DEFAULT 'pending',
    payment_date DATE,
    payment_reference VARCHAR(255),
    invoice_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (platform_id) REFERENCES platforms(id) ON DELETE CASCADE,
    INDEX idx_event (event_id),
    INDEX idx_status (status),
    INDEX idx_payment_date (payment_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
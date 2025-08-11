-- Migration: Add record_comments table for per-record comments (separate from PowerDNS native comments)
-- Created: 2025-08-10

CREATE TABLE IF NOT EXISTS record_comments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    record_id BIGINT NOT NULL,
    domain_id INT NOT NULL,
    user_id INT NULL,
    username VARCHAR(100) NULL, -- denormalized for quick display
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_record (record_id), -- enforce single comment per record
    INDEX idx_record_id (record_id),
    INDEX idx_domain_record (domain_id, record_id),
    CONSTRAINT fk_record_comments_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_comments_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_comments_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unique constraint included above; separate unique migration removed as unused.
-- Optional: If existing misused data in comments table should be migrated, write a separate script.

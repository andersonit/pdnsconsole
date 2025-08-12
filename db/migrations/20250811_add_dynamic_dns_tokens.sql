-- Migration: Add Dynamic DNS tokens table (1:1 token-to-record binding)
-- Date: 2025-08-11

CREATE TABLE IF NOT EXISTS dynamic_dns_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    secret_hash VARCHAR(255) NULL,
    record_id BIGINT NOT NULL,
    domain_id INT NOT NULL,
    tenant_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Simple rate limiting: 3 requests per 3 minutes; throttle 10 minutes when exceeded
    window_count INT NOT NULL DEFAULT 0,
    window_reset_at DATETIME NULL,
    throttle_until DATETIME NULL,
    -- Observability
    last_ip VARCHAR(45) NULL,
    last_used DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ddns_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_ddns_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_ddns_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_ddns_token (token),
    INDEX idx_ddns_record (record_id),
    INDEX idx_ddns_domain (domain_id)
) Engine=InnoDB CHARACTER SET 'utf8mb4';

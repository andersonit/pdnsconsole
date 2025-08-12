-- License Admin Private Schema
-- Not distributed with public application

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  organization VARCHAR(255) NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS plans (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  domain_limit INT NOT NULL, -- 0 = unlimited
  price_cents INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  interval_unit ENUM('once','month','year') NOT NULL DEFAULT 'once',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchases (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT NOT NULL,
  plan_id BIGINT NOT NULL,
  stripe_session_id VARCHAR(255) NULL,
  stripe_payment_intent VARCHAR(255) NULL,
  amount_cents INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('pending','paid','refunded','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS licenses (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT NOT NULL,
  purchase_id BIGINT NULL,
  installation_id VARCHAR(64) NULL,
  license_key TEXT NOT NULL,
  domain_limit INT NOT NULL, -- 0 = unlimited
  type ENUM('free','commercial') NOT NULL DEFAULT 'commercial',
  issued DATE NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  revoked_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  INDEX (installation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS license_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  license_id BIGINT NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  detail TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (license_id) REFERENCES licenses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed example plans
INSERT INTO plans (code,name,domain_limit,price_cents,currency,interval_unit) VALUES
 ('FREE','Free Tier',5,0,'USD','once'),
 ('BUSUNL','Business Unlimited',0,19900,'USD','year')
ON DUPLICATE KEY UPDATE name=VALUES(name);

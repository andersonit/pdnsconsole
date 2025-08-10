-- Migration: Remove internal license tracking tables from distributed schema
-- Date: 2025-08-10

START TRANSACTION;

-- Drop tables if they exist (they will no longer be part of the public distribution)
DROP TABLE IF EXISTS license_usage;
DROP TABLE IF EXISTS license_purchases;
DROP TABLE IF EXISTS licenses;

-- Remove trial setting (no trials supported)
DELETE FROM global_settings WHERE setting_key = 'trial_period_days';

COMMIT;

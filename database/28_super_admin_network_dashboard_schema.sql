-- MedPulse Enterprise Hospital Management System
-- Migration 28: Super Admin Network Overview Dashboard, Cross-Branch Analytics, and Audit Tracking
-- Ensures compatibility with requirement columns for audit_logs and hospitals

-- 1. Ensure target_hospital_id, details, id, and timestamp exist on audit_logs
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS target_hospital_id INT NULL AFTER target_entity;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS details TEXT NULL AFTER description;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS id INT GENERATED ALWAYS AS (log_id) VIRTUAL;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS timestamp TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at;

-- 2. Ensure hospitals emergency_status supports diversion labels
ALTER TABLE hospitals MODIFY COLUMN emergency_status VARCHAR(60) NOT NULL DEFAULT 'Operational';

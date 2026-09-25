-- =============================================================================
-- MedPulse Enterprise HMS — Migration 17: Network Expansion
-- Provisions 5-hospital network, branch admins, and full bed architectures.
-- CRITICAL: PRESERVES all existing data (hospital_id=1 beds, allocations, invoices)
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 1: Add `hospital_id` column to users if it doesn't exist
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE users 
    ADD COLUMN IF NOT EXISTS hospital_id INT NULL DEFAULT NULL COMMENT 'Branch hospital binding for Admin/Staff roles',
    ADD COLUMN IF NOT EXISTS `status_extra` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Operational status for hospitals';

-- Add operational_status to hospitals if missing
ALTER TABLE hospitals 
    ADD COLUMN IF NOT EXISTS operational_status ENUM('Active','Suspended','Maintenance') NOT NULL DEFAULT 'Active',
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS contact_number VARCHAR(50) NULL;

-- Allow Emergency Hold status for Super Admin overrides
ALTER TABLE hospital_beds 
    MODIFY COLUMN status ENUM('Available','Occupied','Maintenance','Reserved','Emergency Hold') NOT NULL DEFAULT 'Available';

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 2: Restructure hospitals table — 5 canonical entities
-- Existing IDs 1,2,3 will be reassigned. We RENAME them cleanly.
-- ─────────────────────────────────────────────────────────────────────────────

-- First: null out FK references so we can restructure
UPDATE hospital_beds SET hospital_id = NULL WHERE hospital_id IN (1,2,3);

-- Delete old placeholder hospitals (only 1,2,3 — the ones seeded in migration 15)
DELETE FROM hospitals WHERE hospital_id IN (1,2,3);

-- Reset auto_increment to 1 so we get clean IDs
ALTER TABLE hospitals AUTO_INCREMENT = 1;

-- Insert canonical 5-hospital network
INSERT INTO hospitals (hospital_id, name, code, city, address, contact_number, operational_status, created_at) VALUES
(1, 'MedPulse Hospital & Specialty Care',    'MEDPULSE', 'Dhaka',   'Road 4, Dhanmondi, Dhaka-1212',        '+880-2-9881234',  'Active', NOW()),
(2, 'Square Hospital Ltd',                    'SQUARE',   'Dhaka',   'Panthapath, West Panthapath, Dhaka-1205', '+880-2-8159457',  'Active', NOW()),
(3, 'United Hospital Ltd',                    'UNITED',   'Dhaka',   'Plot 15, Road 71, Gulshan-2, Dhaka-1212', '+880-2-8836000',  'Active', NOW()),
(4, 'United Medical College Hospital',         'UMCH',     'Dhaka',   'Madani Avenue, Vatara, Dhaka-1212',    '+880-2-9825765',  'Active', NOW()),
(5, 'Evercare Hospital Dhaka',                'EVERCARE', 'Dhaka',   'Plot 81, Block E, Bashundhara R/A, Dhaka-1229', '+880-2-55042888', 'Active', NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    code = VALUES(code),
    city = VALUES(city),
    address = VALUES(address),
    contact_number = VALUES(contact_number),
    operational_status = VALUES(operational_status);

-- Re-assign existing 500 beds (previously distributed across old IDs 1,2,3) to Hospital 1
UPDATE hospital_beds SET hospital_id = 1 WHERE hospital_id IS NULL;

-- Bind existing admin@medpulse.org (user_id=6) to hospital_id=1
UPDATE users SET hospital_id = 1 WHERE email = 'admin@medpulse.org' AND role = 'Admin';

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 3: Seed Branch Admin Accounts for Partner Hospitals (2-5)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `gender`, `password_hash`, `role`, `status`, `hospital_id`, `created_at`) VALUES
(60, 'Square Hospital Admin',       'admin@squarehospital.com',   '01722222222', 'Male', '$2y$10$B7d//yukPFBx2jnQ0jFF/.6zm3X2W.wGDlIf8PYN9vOrfhxiH8J9S', 'Admin', 'active', 2, NOW()),
(61, 'United Hospital Admin',       'admin@unitedhospital.com.bd', '01733333333', 'Male', '$2y$10$B7d//yukPFBx2jnQ0jFF/.6zm3X2W.wGDlIf8PYN9vOrfhxiH8J9S', 'Admin', 'active', 3, NOW()),
(62, 'United Medical College Admin', 'admin@umch.edu.bd',          '01744444444', 'Male', '$2y$10$B7d//yukPFBx2jnQ0jFF/.6zm3X2W.wGDlIf8PYN9vOrfhxiH8J9S', 'Admin', 'active', 4, NOW()),
(63, 'Evercare Hospital Admin',     'admin@evercarebd.com',       '01755555555', 'Male', '$2y$10$B7d//yukPFBx2jnQ0jFF/.6zm3X2W.wGDlIf8PYN9vOrfhxiH8J9S', 'Admin', 'active', 5, NOW())
ON DUPLICATE KEY UPDATE
    `full_name`     = VALUES(`full_name`),
    `hospital_id`   = VALUES(`hospital_id`),
    `status`        = 'active',
    `password_hash` = VALUES(`password_hash`);

SET FOREIGN_KEY_CHECKS = 1;


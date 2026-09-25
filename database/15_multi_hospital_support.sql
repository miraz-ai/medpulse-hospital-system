-- ============================================================================
-- MedPulse Enterprise Hospital Management System (HMS)
-- Migration 15: Multi-Hospital Support & Super Admin Provisioning
-- Database: medpulse_hms
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

-- ----------------------------------------------------------------------------
-- 1. Create `hospitals` Table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hospitals` (
  `hospital_id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `city` VARCHAR(100) NOT NULL DEFAULT 'Dhaka',
  `address` VARCHAR(255) DEFAULT NULL,
  `contact_number` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hospital_id`),
  UNIQUE KEY `idx_hospitals_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Insert 3 Hospitals: 'Square Hospital Ltd', 'United Hospital', 'Evercare Hospital Dhaka'
-- ----------------------------------------------------------------------------
INSERT INTO `hospitals` (`hospital_id`, `name`, `code`, `city`, `address`, `contact_number`) 
VALUES
  (1, 'Square Hospital Ltd', 'SQUARE', 'Dhaka', '18/F, Bir Uttam Qazi Nuruzzaman Sarak, West Panthapath, Dhaka 1205', '+88028144400'),
  (2, 'United Hospital', 'UNITED', 'Dhaka', 'Plot 15, Road 71, Gulshan-2, Dhaka 1212', '+88028836444'),
  (3, 'Evercare Hospital Dhaka', 'EVERCARE', 'Dhaka', 'Plot 81, Block E, Bashundhara R/A, Dhaka 1229', '+88028431661')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `code` = VALUES(`code`),
  `city` = VALUES(`city`),
  `address` = VALUES(`address`),
  `contact_number` = VALUES(`contact_number`);

-- ----------------------------------------------------------------------------
-- 3. Alter `users` Table: Update `role` ENUM to include 'super_admin'
-- Preserves existing roles ('Patient', 'Doctor', 'Staff', 'Admin') and default 'Patient'
-- ----------------------------------------------------------------------------
ALTER TABLE `users` 
  MODIFY COLUMN `role` ENUM('Patient', 'Doctor', 'Staff', 'Admin', 'super_admin') NOT NULL DEFAULT 'Patient';

-- ----------------------------------------------------------------------------
-- 4. Alter `hospital_beds` Table: Add `hospital_id` & `price_per_day`
-- ----------------------------------------------------------------------------
ALTER TABLE `hospital_beds`
  ADD COLUMN IF NOT EXISTS `hospital_id` INT(11) NULL DEFAULT NULL AFTER `bed_id`,
  ADD COLUMN IF NOT EXISTS `price_per_day` DECIMAL(10,2) NOT NULL DEFAULT 1500.00;

ALTER TABLE `hospital_beds`
  ADD INDEX IF NOT EXISTS `idx_beds_hospital_id` (`hospital_id`);

-- Add foreign key constraint for hospital_beds.hospital_id -> hospitals(hospital_id) safely
SET @add_fk_beds_hosp = (SELECT IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'hospital_beds' 
          AND CONSTRAINT_NAME = 'fk_hospital_beds_hospital'
    ),
    'ALTER TABLE `hospital_beds` ADD CONSTRAINT `fk_hospital_beds_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`hospital_id`) ON DELETE SET NULL ON UPDATE CASCADE;',
    'SELECT 1;'
));
PREPARE stmt_beds_hosp FROM @add_fk_beds_hosp;
EXECUTE stmt_beds_hosp;
DEALLOCATE PREPARE stmt_beds_hosp;

-- ----------------------------------------------------------------------------
-- 5. Alter `doctor_profiles` Table: Add `hospital_id`
-- ----------------------------------------------------------------------------
ALTER TABLE `doctor_profiles`
  ADD COLUMN IF NOT EXISTS `hospital_id` INT(11) NULL DEFAULT NULL AFTER `user_id`;

ALTER TABLE `doctor_profiles`
  ADD INDEX IF NOT EXISTS `idx_doc_hospital_id` (`hospital_id`);

-- Add foreign key constraint for doctor_profiles.hospital_id -> hospitals(hospital_id) safely
SET @add_fk_doc_hosp = (SELECT IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'doctor_profiles' 
          AND CONSTRAINT_NAME = 'fk_doctor_profiles_hospital'
    ),
    'ALTER TABLE `doctor_profiles` ADD CONSTRAINT `fk_doctor_profiles_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`hospital_id`) ON DELETE SET NULL ON UPDATE CASCADE;',
    'SELECT 1;'
));
PREPARE stmt_doc_hosp FROM @add_fk_doc_hosp;
EXECUTE stmt_doc_hosp;
DEALLOCATE PREPARE stmt_doc_hosp;

-- ----------------------------------------------------------------------------
-- 6. Evenly Distribute Existing Beds and Doctors Across the 3 Hospitals
-- Uses round-robin ranking via ROW_NUMBER() modulo 3 + 1 -> (1, 2, 3)
-- ----------------------------------------------------------------------------
UPDATE `hospital_beds` b
JOIN (
    SELECT 
        `bed_id`,
        ((ROW_NUMBER() OVER (ORDER BY `bed_id`) - 1) % 3) + 1 AS target_hospital_id
    FROM `hospital_beds`
) ranked ON b.`bed_id` = ranked.`bed_id`
SET b.`hospital_id` = ranked.target_hospital_id;

UPDATE `doctor_profiles` d
JOIN (
    SELECT 
        `doctor_id`,
        ((ROW_NUMBER() OVER (ORDER BY `doctor_id`) - 1) % 3) + 1 AS target_hospital_id
    FROM `doctor_profiles`
) ranked ON d.`doctor_id` = ranked.`doctor_id`
SET d.`hospital_id` = ranked.target_hospital_id;

-- ----------------------------------------------------------------------------
-- 7. Create Super Admin User Account
-- Credentials:
--   Email / Identifier: superadmin@medpulse.org
--   Password:          admin123
--   Role:              super_admin
--   Status:            active
-- ----------------------------------------------------------------------------
INSERT INTO `users` (
  `full_name`,
  `email`,
  `phone`,
  `gender`,
  `password_hash`,
  `role`,
  `status`,
  `created_at`
) VALUES (
  'Super Administrator',
  'superadmin@medpulse.org',
  '01700000001',
  'Male',
  '$2y$10$B7d//yukPFBx2jnQ0jFF/.6zm3X2W.wGDlIf8PYN9vOrfhxiH8J9S',
  'super_admin',
  'active',
  NOW()
)
ON DUPLICATE KEY UPDATE
  `full_name` = VALUES(`full_name`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);

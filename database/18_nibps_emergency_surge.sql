-- ============================================================================
-- Migration: 18_nibps_emergency_surge.sql
-- Module: 6-Hospital Network Topology, NIBPS Apex Integration & Emergency Surge Engine
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

-- 1. Modify hospital_beds schema to support rich wards and emergency lifecycle states
ALTER TABLE `hospital_beds`
  MODIFY COLUMN `ward_type` VARCHAR(60) NOT NULL;

ALTER TABLE `hospital_beds`
  MODIFY COLUMN `status` ENUM('Available','Occupied','Maintenance','Reserved','Emergency Hold','Sanitizing') NOT NULL DEFAULT 'Available';

-- Add relocation status and emergency protocol tracking columns if not present
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns 
                   WHERE table_schema = 'medpulse_hms' AND table_name = 'hospital_beds' AND column_name = 'relocation_status');
SET @sql_stmt = IF(@col_exists = 0, 
  'ALTER TABLE `hospital_beds` ADD COLUMN `relocation_status` ENUM(\'NONE\',\'PENDING_RELOCATION\',\'RELOCATED\') NOT NULL DEFAULT \'NONE\' AFTER `status`', 
  'SELECT 1');
PREPARE stmt FROM @sql_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists2 = (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = 'medpulse_hms' AND table_name = 'hospital_beds' AND column_name = 'emergency_protocol_id');
SET @sql_stmt2 = IF(@col_exists2 = 0, 
  'ALTER TABLE `hospital_beds` ADD COLUMN `emergency_protocol_id` INT NULL DEFAULT NULL AFTER `relocation_status`, ADD INDEX `idx_beds_emergency_proto` (`emergency_protocol_id`), ADD INDEX `idx_beds_relocation` (`relocation_status`)', 
  'SELECT 1');
PREPARE stmt2 FROM @sql_stmt2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 2. Create emergency_protocols table
CREATE TABLE IF NOT EXISTS `emergency_protocols` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(50) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `severity_quota` INT NOT NULL,
  `severity_level` VARCHAR(50) NOT NULL,
  `status` ENUM('Active', 'Terminated') DEFAULT 'Active',
  `target_scope` VARCHAR(50) NOT NULL,
  `target_hospital_ids` TEXT NOT NULL,
  `target_wards` TEXT NULL,
  `beds_held_count` INT DEFAULT 0,
  `beds_relocating_count` INT DEFAULT 0,
  `declared_by_user_id` INT NULL,
  `declared_at` DATETIME NOT NULL,
  `terminated_by_user_id` INT NULL,
  `terminated_at` DATETIME NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_proto_status` (`status`),
  INDEX `idx_proto_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create disaster_logs table
CREATE TABLE IF NOT EXISTS `disaster_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `protocol_id` INT NULL,
  `hospital_id` INT NULL,
  `event_type` VARCHAR(60) NOT NULL,
  `details` TEXT NOT NULL,
  `actor_user_id` INT NULL,
  `actor_role` VARCHAR(50) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_disaster_protocol` (`protocol_id`),
  INDEX `idx_disaster_hospital` (`hospital_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Register Hospital 6: National Institute of Burn and Plastic Surgery (NIBPS)
INSERT INTO `hospitals` (`hospital_id`, `name`, `code`, `city`, `address`, `contact_number`, `operational_status`, `created_at`)
VALUES (6, 'National Institute of Burn and Plastic Surgery', 'NIBPS', 'Dhaka', 'Chankharpul, Dhaka', '+880-2-223381234', 'Active', NOW())
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `code` = VALUES(`code`),
  `address` = VALUES(`address`),
  `contact_number` = VALUES(`contact_number`),
  `operational_status` = 'Active';

-- 5. Provision Branch Admin for NIBPS (Hospital 6)
INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `gender`, `password_hash`, `role`, `status`, `hospital_id`, `created_at`)
VALUES (64, 'NIBPS Executive Admin', 'admin@nibps.gov.bd', '01766666666', 'Male', '$2y$10$Bc8YsRxIy.oHaTYBVbys3eTRcG8INPzgx83G87gmz6zi2J4/4YGmO', 'Admin', 'active', 6, NOW())
ON DUPLICATE KEY UPDATE
  `full_name`     = VALUES(`full_name`),
  `hospital_id`   = VALUES(`hospital_id`),
  `status`        = 'active',
  `password_hash` = VALUES(`password_hash`);


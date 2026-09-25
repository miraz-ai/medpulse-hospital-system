-- =============================================================================
-- Migration 20: Bed Discharge Lifecycle & Housekeeping Sanitization Queue
-- Ensures discharged beds immediately transition to 'Sanitizing' status and
-- are tracked through bed_sanitization_queue until certified and released.
-- =============================================================================

USE `medpulse_hms`;

-- 1. Ensure hospital_beds has patient_id, updated_at, and Sanitizing enum status
ALTER TABLE `hospital_beds`
  ADD COLUMN IF NOT EXISTS `patient_id` INT(11) NULL DEFAULT NULL AFTER `hospital_id`,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `hospital_beds`
  ADD INDEX IF NOT EXISTS `idx_beds_patient_id` (`patient_id`),
  ADD INDEX IF NOT EXISTS `idx_beds_status` (`status`);

-- Ensure bed_allocations has discharge_summary column
ALTER TABLE `bed_allocations`
  ADD COLUMN IF NOT EXISTS `discharge_summary` TEXT NULL DEFAULT NULL AFTER `discharged_at`;

-- 2. Create bed_sanitization_queue table
CREATE TABLE IF NOT EXISTS `bed_sanitization_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `hospital_id` INT NOT NULL,
  `bed_id` INT NOT NULL,
  `discharged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cleaning_type` VARCHAR(100) NOT NULL DEFAULT 'UV/Chemical Cycle',
  `status` ENUM('in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'in_progress',
  `assigned_staff` VARCHAR(100) NOT NULL DEFAULT 'UV Decon Team',
  `completed_at` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_bsq_hosp_bed` (`hospital_id`, `bed_id`, `status`),
  KEY `idx_bsq_status` (`status`),
  KEY `idx_bsq_discharged` (`discharged_at`),
  CONSTRAINT `fk_bsq_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`hospital_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bsq_bed` FOREIGN KEY (`bed_id`) REFERENCES `hospital_beds` (`bed_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create or replace interoperability view `beds` pointing to `hospital_beds`
CREATE OR REPLACE VIEW `beds` AS SELECT *, `bed_id` AS `id` FROM `hospital_beds`;

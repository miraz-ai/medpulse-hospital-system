-- ============================================================================
-- Migration 24: Staff Hospital Affiliation & Collision-Proof Patient UID Constraints
-- Database: medpulse_hms
-- ============================================================================

USE `medpulse_hms`;

-- 1. Ensure virtual alias id columns exist on hospitals and users for cross-table agility
SET @exist_hosp_id := (SELECT COUNT(*) FROM information_schema.columns 
                       WHERE table_schema = DATABASE() AND table_name = 'hospitals' AND column_name = 'id');
SET @sql_hosp := IF(@exist_hosp_id = 0, 'ALTER TABLE `hospitals` ADD COLUMN `id` INT(11) GENERATED ALWAYS AS (hospital_id) VIRTUAL', 'SELECT 1');
PREPARE stmt_hosp FROM @sql_hosp;
EXECUTE stmt_hosp;
DEALLOCATE PREPARE stmt_hosp;

SET @exist_user_id := (SELECT COUNT(*) FROM information_schema.columns 
                       WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'id');
SET @sql_user := IF(@exist_user_id = 0, 'ALTER TABLE `users` ADD COLUMN `id` INT(11) GENERATED ALWAYS AS (user_id) VIRTUAL', 'SELECT 1');
PREPARE stmt_user FROM @sql_user;
EXECUTE stmt_user;
DEALLOCATE PREPARE stmt_user;

-- 2. Enforce strict database unique constraint on patients table
ALTER TABLE `patients` MODIFY `patient_uid` VARCHAR(32) NOT NULL;

SET @exist_uq := (SELECT COUNT(*) FROM information_schema.statistics 
                  WHERE table_schema = DATABASE() AND table_name = 'patients' AND index_name = 'uq_patient_uid');
SET @sql_uq := IF(@exist_uq = 0, 'ALTER TABLE `patients` ADD CONSTRAINT `uq_patient_uid` UNIQUE (`patient_uid`)', 'SELECT 1');
PREPARE stmt_uq FROM @sql_uq;
EXECUTE stmt_uq;
DEALLOCATE PREPARE stmt_uq;

-- 3. Staff affiliation and operations table
CREATE TABLE IF NOT EXISTS `staff` (
    `staff_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `hospital_id` INT NOT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `role_title` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('pending', 'active', 'rejected', 'suspended') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_staff_user` (`user_id`),
    KEY `idx_staff_hospital` (`hospital_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Backfill existing staff records from users
INSERT IGNORE INTO `staff` (`user_id`, `hospital_id`, `department`, `role_title`, `status`)
SELECT user_id, COALESCE(hospital_id, 1), department, role, status 
FROM `users` 
WHERE role IN ('Staff', 'Admin');

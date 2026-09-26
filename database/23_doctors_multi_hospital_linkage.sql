-- ============================================================================
-- Migration 23: Doctors Multi-Hospital Direct Linkage Table
-- Database: medpulse_hms
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `doctors` (
    `doctor_id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `hospital_id` INT(11) DEFAULT NULL,
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`doctor_id`),
    KEY `idx_doctors_user` (`user_id`),
    KEY `idx_doctors_hosp` (`hospital_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `doctors` (`user_id`, `hospital_id`, `status`)
SELECT user_id, hospital_id, approval_status FROM `doctor_profiles`;

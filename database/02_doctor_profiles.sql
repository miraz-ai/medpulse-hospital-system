-- ============================================================================
-- Migration: 02_doctor_profiles.sql
-- Module: Doctor Profiles & Clinical Credentialing
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `doctor_profiles` (
  `doctor_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `specialty` varchar(100) NOT NULL,
  `bmdc_license_number` varchar(50) NOT NULL,
  `consultation_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `room_number` varchar(20) NOT NULL,
  `available_days` varchar(100) NOT NULL,
  `shift_timings` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`doctor_id`),
  UNIQUE KEY `idx_doc_user_id` (`user_id`),
  UNIQUE KEY `idx_doc_bmdc_license` (`bmdc_license_number`),
  KEY `idx_doc_specialty` (`specialty`),
  CONSTRAINT `fk_doctor_profiles_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

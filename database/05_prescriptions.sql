-- ============================================================================
-- Migration: 05_prescriptions.sql
-- Module: Digital Prescriptions & Prescription Items (Rx Registry)
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `prescriptions` (
  `prescription_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `diagnosis_notes` text DEFAULT NULL,
  `vitals_summary` varchar(255) DEFAULT NULL,
  `prescribed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`prescription_id`),
  KEY `idx_prescriptions_patient` (`patient_id`),
  KEY `idx_prescriptions_doctor` (`doctor_id`),
  KEY `idx_prescriptions_appointment` (`appointment_id`),
  KEY `idx_prescriptions_date` (`prescribed_at`),
  CONSTRAINT `fk_prescriptions_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prescriptions_doctor` 
    FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prescriptions_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescription_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `prescription_id` int(11) NOT NULL,
  `medicine_name` varchar(150) NOT NULL,
  `dosage` varchar(50) NOT NULL,
  `duration` varchar(50) NOT NULL,
  `special_instructions` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_prescription_items_rx` (`prescription_id`),
  CONSTRAINT `fk_prescription_items_rx` 
    FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`prescription_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Migration: 08_diagnostic_reports.sql
-- Module: Diagnostic, Pathology & Radiology Records
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `diagnostic_reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `requested_by_doctor_id` int(11) DEFAULT NULL,
  `test_name` varchar(150) NOT NULL,
  `test_category` enum('Pathology','Radiology','Biochemistry') NOT NULL,
  `report_file_path` varchar(255) DEFAULT NULL,
  `delivery_status` enum('Pending Analysis','Verified & Ready') NOT NULL DEFAULT 'Pending Analysis',
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  KEY `idx_diagnostic_patient` (`patient_id`),
  KEY `idx_diagnostic_doctor` (`requested_by_doctor_id`),
  KEY `idx_diagnostic_status` (`delivery_status`),
  KEY `idx_diagnostic_category` (`test_category`),
  CONSTRAINT `fk_diagnostic_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_diagnostic_doctor` 
    FOREIGN KEY (`requested_by_doctor_id`) REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

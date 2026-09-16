-- ============================================================================
-- Migration: 04_appointments.sql
-- Module: Consultation Booking & Outpatient Queue
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `appointments` (
  `appointment_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `serial_number` int(11) NOT NULL,
  `reason_for_visit` text DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled','In-Consultation') NOT NULL DEFAULT 'Scheduled',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_id`),
  KEY `idx_appointments_patient` (`patient_id`),
  KEY `idx_appointments_doctor` (`doctor_id`),
  KEY `idx_appointments_status_date` (`status`, `appointment_date`),
  KEY `idx_appointments_doc_date_serial` (`doctor_id`, `appointment_date`, `serial_number`),
  CONSTRAINT `fk_appointments_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_doctor` 
    FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

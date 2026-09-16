-- ============================================================================
-- Migration: 06_bed_allocations.sql
-- Module: Inpatient Admissions & Live Bed Tracking
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `bed_allocations` (
  `allocation_id` int(11) NOT NULL AUTO_INCREMENT,
  `bed_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `attending_doctor_id` int(11) DEFAULT NULL,
  `admitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `discharged_at` datetime DEFAULT NULL,
  `status` enum('Active','Discharged','Transferred') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`allocation_id`),
  KEY `idx_bed_alloc_bed` (`bed_id`),
  KEY `idx_bed_alloc_patient` (`patient_id`),
  KEY `idx_bed_alloc_doctor` (`attending_doctor_id`),
  KEY `idx_bed_alloc_status` (`status`),
  CONSTRAINT `fk_bed_alloc_bed` 
    FOREIGN KEY (`bed_id`) REFERENCES `hospital_beds` (`bed_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bed_alloc_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_bed_alloc_doctor` 
    FOREIGN KEY (`attending_doctor_id`) REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

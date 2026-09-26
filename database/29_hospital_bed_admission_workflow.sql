-- ============================================================================
-- MedPulse Enterprise HMS
-- Migration 29: End-to-End Hospital Bed Admission Workflow & Inpatient Registry
-- ============================================================================

USE medpulse_hms;

-- 1. Ensure bed_reservations has admitted_at and admitted_by_staff_id
SET @has_admitted_at = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bed_reservations' AND COLUMN_NAME = 'admitted_at');
SET @alter_admitted_at = IF(@has_admitted_at = 0, 'ALTER TABLE bed_reservations ADD COLUMN admitted_at DATETIME NULL AFTER hold_expires_at', 'SELECT 1');
PREPARE stmt_adm_at FROM @alter_admitted_at;
EXECUTE stmt_adm_at;
DEALLOCATE PREPARE stmt_adm_at;

SET @has_staff_id = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bed_reservations' AND COLUMN_NAME = 'admitted_by_staff_id');
SET @alter_staff_id = IF(@has_staff_id = 0, 'ALTER TABLE bed_reservations ADD COLUMN admitted_by_staff_id INT NULL AFTER admitted_at', 'SELECT 1');
PREPARE stmt_staff_id FROM @alter_staff_id;
EXECUTE stmt_staff_id;
DEALLOCATE PREPARE stmt_staff_id;

-- 2. Create official admissions ledger table
CREATE TABLE IF NOT EXISTS admissions (
    admission_id INT AUTO_INCREMENT PRIMARY KEY,
    admission_number VARCHAR(32) NOT NULL UNIQUE,
    reservation_id INT NULL,
    hospital_id INT NOT NULL,
    bed_id INT NOT NULL,
    patient_id INT NOT NULL,
    patient_uid VARCHAR(32) NULL,
    guardian_name VARCHAR(150) NULL,
    guardian_relation VARCHAR(50) NULL,
    guardian_phone VARCHAR(25) NULL,
    admitting_staff_id INT NOT NULL,
    attending_doctor_id INT NOT NULL,
    admission_reason TEXT NOT NULL,
    primary_diagnosis VARCHAR(255) NULL,
    triage_acuity ENUM('Routine', 'Critical', 'Post-Op') DEFAULT 'Routine',
    daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('Cash', 'Card', 'MFS') DEFAULT 'Cash',
    payment_reference VARCHAR(100) NULL,
    status ENUM('Admitted', 'Discharged', 'Transferred') DEFAULT 'Admitted',
    admitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    discharged_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_adm_hospital (hospital_id),
    INDEX idx_adm_patient (patient_id),
    INDEX idx_adm_bed (bed_id),
    INDEX idx_adm_doctor (attending_doctor_id),
    INDEX idx_adm_staff (admitting_staff_id),
    INDEX idx_adm_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

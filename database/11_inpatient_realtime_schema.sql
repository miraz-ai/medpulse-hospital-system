-- ============================================================================
-- MedPulse Enterprise - Inpatient & Real-Time Event System Schema Migration
-- Strict Data Integrity, Doctor-Patient Junction Table, Bed Transfer Audit & Notifications
-- ============================================================================

USE medpulse_hms;

-- ----------------------------------------------------------------------------
-- 1. Reconcile Legacy Active Allocations (Data Sanitization Before Constraints)
-- ----------------------------------------------------------------------------
-- Close duplicate active allocations for Patient 18 and Patient 28 if present
UPDATE bed_allocations 
SET status = 'Transferred', discharged_at = NOW() 
WHERE allocation_id IN (4, 9) AND status = 'Active';

-- Re-align hospital bed statuses
UPDATE hospital_beds 
SET status = 'Available' 
WHERE bed_id IN (380, 381) AND bed_id NOT IN (
    SELECT bed_id FROM bed_allocations WHERE status = 'Active'
);

-- ----------------------------------------------------------------------------
-- 2. Hardening Constraints on `bed_allocations` (Mathematical 1-to-1 Invariance)
-- ----------------------------------------------------------------------------
-- Virtual Generated Columns: Evaluates to patient_id / bed_id ONLY when status is 'Active'.
-- Evaluates to NULL otherwise. In MySQL/MariaDB, UNIQUE keys ignore multiple NULLs,
-- which mathematically guarantees at most ONE 'Active' record per patient and bed.

-- Drop existing unique indexes if already present (for idempotent rerun)
SET @drop_uq_pat = (SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bed_allocations' AND INDEX_NAME = 'uq_active_patient'),
    'ALTER TABLE bed_allocations DROP INDEX uq_active_patient;',
    'SELECT 1;'
));
PREPARE stmt FROM @drop_uq_pat; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @drop_uq_bed = (SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bed_allocations' AND INDEX_NAME = 'uq_active_bed'),
    'ALTER TABLE bed_allocations DROP INDEX uq_active_bed;',
    'SELECT 1;'
));
PREPARE stmt FROM @drop_uq_bed; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Check and add generated columns
SET @add_col_pat = (SELECT IF(
    NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bed_allocations' AND COLUMN_NAME = 'active_patient_id'),
    'ALTER TABLE bed_allocations ADD COLUMN active_patient_id INT GENERATED ALWAYS AS (IF(status = "Active", patient_id, NULL)) VIRTUAL;',
    'SELECT 1;'
));
PREPARE stmt FROM @add_col_pat; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_col_bed = (SELECT IF(
    NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bed_allocations' AND COLUMN_NAME = 'active_bed_id'),
    'ALTER TABLE bed_allocations ADD COLUMN active_bed_id INT GENERATED ALWAYS AS (IF(status = "Active", bed_id, NULL)) VIRTUAL;',
    'SELECT 1;'
));
PREPARE stmt FROM @add_col_bed; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Apply Unique Constraints
ALTER TABLE bed_allocations
    ADD UNIQUE KEY uq_active_patient (active_patient_id),
    ADD UNIQUE KEY uq_active_bed (active_bed_id);

-- ----------------------------------------------------------------------------
-- 3. Junction Table: `patient_doctor_assignments` (Many-to-Many Multi-Doctor Care)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS patient_doctor_assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    assigned_by INT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    notes VARCHAR(255) NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    -- Unique constraint preventing duplicate ACTIVE assignments of the same doctor to the same patient
    active_pair VARCHAR(64) GENERATED ALWAYS AS (IF(status = 'Active', CONCAT(patient_id, ':', doctor_id), NULL)) VIRTUAL,
    UNIQUE KEY uq_active_patient_doctor (active_pair),
    INDEX idx_patient_status (patient_id, status),
    INDEX idx_doctor_status (doctor_id, status),
    CONSTRAINT fk_pda_patient FOREIGN KEY (patient_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_pda_doctor FOREIGN KEY (doctor_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_pda_admin FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Audit Table: `bed_transfer_history` (Atomic Relocation Telemetry)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bed_transfer_history (
    transfer_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    from_bed_id INT NOT NULL,
    to_bed_id INT NOT NULL,
    transferred_by INT NOT NULL,
    reason TEXT NULL,
    transferred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient_transfers (patient_id, transferred_at),
    INDEX idx_from_bed (from_bed_id),
    INDEX idx_to_bed (to_bed_id),
    CONSTRAINT fk_bth_patient FOREIGN KEY (patient_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_bth_from_bed FOREIGN KEY (from_bed_id) REFERENCES hospital_beds(bed_id),
    CONSTRAINT fk_bth_to_bed FOREIGN KEY (to_bed_id) REFERENCES hospital_beds(bed_id),
    CONSTRAINT fk_bth_admin FOREIGN KEY (transferred_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Real-Time Event Store: `notifications` (Universal Dispatch Queue)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    recipient_type ENUM('DOCTOR', 'PATIENT', 'ADMIN') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    event_type VARCHAR(64) NOT NULL, -- BED_TRANSFER, PATIENT_BED_MOVED, DISCHARGE, DOCTOR_ASSIGNED, BED_ASSIGNED
    metadata LONGTEXT NOT NULL, -- Strictly typed JSON payload
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_id, recipient_type, is_read),
    INDEX idx_created (created_at),
    INDEX idx_event_type (event_type),
    CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Seed Baseline Active Doctor Assignments from Existing Allocations
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO patient_doctor_assignments (patient_id, doctor_id, is_primary, status, assigned_at)
SELECT ba.patient_id, ba.attending_doctor_id, 1, 'Active', ba.admitted_at
FROM bed_allocations ba
WHERE ba.status = 'Active' 
  AND ba.attending_doctor_id IS NOT NULL
  AND ba.patient_id IS NOT NULL;

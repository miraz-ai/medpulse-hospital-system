-- =========================================================================
-- MedPulse Enterprise Hospital Management System
-- Migration 14: Discharge Automated Invoicing & Doctor Earnings Ledger
-- =========================================================================

USE `medpulse_hms`;

-- 1. Ensure invoices table has discharge and payment status tracking
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `discharge_status` VARCHAR(30) NOT NULL DEFAULT 'discharged' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `payment_status` VARCHAR(30) NOT NULL DEFAULT 'unpaid' AFTER `discharge_status`;

-- Backfill payment_status for existing invoices
UPDATE `invoices` 
SET `payment_status` = IF(`status` = 'Paid', 'paid', IF(`status` = 'Partial', 'partial', 'unpaid'))
WHERE `payment_status` IS NULL OR `payment_status` = '' OR `payment_status` = 'unpaid';

-- 2. Create doctor_earnings ledger table
CREATE TABLE IF NOT EXISTS `doctor_earnings` (
  `earning_id` INT AUTO_INCREMENT PRIMARY KEY,
  `doctor_id` INT NOT NULL,
  `invoice_id` INT NOT NULL,
  `admission_id` INT NULL,
  `patient_name` VARCHAR(150) NOT NULL,
  `consultation_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `disbursement_status` ENUM('pending_hospital_collection', 'available_for_disbursement', 'disbursed') NOT NULL DEFAULT 'pending_hospital_collection',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_de_doctor` (`doctor_id`),
  KEY `idx_de_invoice` (`invoice_id`),
  KEY `idx_de_status` (`disbursement_status`),
  CONSTRAINT `fk_de_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_de_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill doctor_earnings from existing consultation invoice_items
INSERT INTO `doctor_earnings` (doctor_id, invoice_id, admission_id, patient_name, consultation_fee, disbursement_status, created_at)
SELECT 
    ii.doctor_id,
    ii.invoice_id,
    i.admission_id,
    pat.full_name AS patient_name,
    ii.doctor_payout_amount,
    IF(ii.doctor_payout_status = 'DISBURSED', 'disbursed', IF(i.status = 'Paid', 'available_for_disbursement', 'pending_hospital_collection')),
    ii.created_at
FROM invoice_items ii
JOIN invoices i ON ii.invoice_id = i.invoice_id
JOIN users pat ON i.patient_id = pat.user_id
WHERE ii.doctor_id IS NOT NULL 
  AND ii.item_type = 'Consultation'
  AND NOT EXISTS (
      SELECT 1 FROM doctor_earnings de WHERE de.invoice_id = ii.invoice_id AND de.doctor_id = ii.doctor_id
  );

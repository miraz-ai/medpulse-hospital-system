-- ============================================================================
-- MedPulse Enterprise Hospital Management System (HMS)
-- Migration: 12_doctor_payout_and_billing_linkage.sql
-- Module: Doctor Linkage, Payout Tracking & Inpatient Billing Association
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

-- ----------------------------------------------------------------------------
-- 1. Update `invoice_items` Table
-- ----------------------------------------------------------------------------
-- Add doctor_id, doctor_payout_status, and doctor_payout_amount safely
ALTER TABLE `invoice_items`
  ADD COLUMN IF NOT EXISTS `doctor_id` INT(11) NULL DEFAULT NULL AFTER `invoice_id`,
  ADD COLUMN IF NOT EXISTS `doctor_payout_status` ENUM('UNCLAIMED', 'PENDING_CLEARANCE', 'DISBURSED') NOT NULL DEFAULT 'UNCLAIMED' AFTER `total_price`,
  ADD COLUMN IF NOT EXISTS `doctor_payout_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `doctor_payout_status`;

-- Add index on doctor_id and doctor_payout_status for high query performance
ALTER TABLE `invoice_items`
  ADD INDEX IF NOT EXISTS `idx_invoice_items_doctor` (`doctor_id`),
  ADD INDEX IF NOT EXISTS `idx_invoice_items_payout_status` (`doctor_payout_status`);

-- Safe idempotent foreign key constraint creation for invoice_items.doctor_id -> users(user_id)
SET @add_fk_item_doc = (SELECT IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'invoice_items' 
          AND CONSTRAINT_NAME = 'fk_invoice_items_doctor'
    ),
    'ALTER TABLE `invoice_items` ADD CONSTRAINT `fk_invoice_items_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;',
    'SELECT 1;'
));
PREPARE stmt_item_doc FROM @add_fk_item_doc; 
EXECUTE stmt_item_doc; 
DEALLOCATE PREPARE stmt_item_doc;

-- ----------------------------------------------------------------------------
-- 2. Update `invoices` Table
-- ----------------------------------------------------------------------------
-- Add admission_id and due_amount safely
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `admission_id` INT(11) NULL DEFAULT NULL AFTER `patient_id`,
  ADD COLUMN IF NOT EXISTS `due_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `paid_amount`;

-- Add index on admission_id for fast inpatient billing lookups
ALTER TABLE `invoices`
  ADD INDEX IF NOT EXISTS `idx_invoices_admission` (`admission_id`);

-- Safe idempotent foreign key constraint creation for invoices.admission_id -> bed_allocations(allocation_id)
SET @add_fk_inv_adm = (SELECT IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'invoices' 
          AND CONSTRAINT_NAME = 'fk_invoices_admission'
    ),
    'ALTER TABLE `invoices` ADD CONSTRAINT `fk_invoices_admission` FOREIGN KEY (`admission_id`) REFERENCES `bed_allocations` (`allocation_id`) ON DELETE SET NULL ON UPDATE CASCADE;',
    'SELECT 1;'
));
PREPARE stmt_inv_adm FROM @add_fk_inv_adm; 
EXECUTE stmt_inv_adm; 
DEALLOCATE PREPARE stmt_inv_adm;

-- ----------------------------------------------------------------------------
-- 3. Data Integrity & Backfill for Existing Invoices & Items
-- ----------------------------------------------------------------------------
-- Calculate due_amount for any existing invoices where due_amount is default 0.00
-- and invoice has remaining unpaid balance
UPDATE `invoices`
SET `due_amount` = GREATEST(0.00, `net_payable` - `paid_amount`)
WHERE `due_amount` = 0.00 AND `status` != 'Paid';

-- Link existing Consultation invoice items to their respective doctors if matching
-- Item 2: Critical Care Specialist Dr. Satoru Gojo Consultation (user_id = 21)
UPDATE `invoice_items`
SET `doctor_id` = 21,
    `doctor_payout_amount` = 2000.00,
    `doctor_payout_status` = 'DISBURSED'
WHERE `item_id` = 2 AND `doctor_id` IS NULL;

-- Item 6: Surgical Pre-op Evaluation Dr. Mikasa Ackerman (user_id = 20)
UPDATE `invoice_items`
SET `doctor_id` = 20,
    `doctor_payout_amount` = 1500.00,
    `doctor_payout_status` = 'DISBURSED'
WHERE `item_id` = 6 AND `doctor_id` IS NULL;

-- Link historical inpatient invoices to admission allocations
-- Invoice 1 (INV-2026-081) -> Allocation 1 (Patient 1, admitted 2026-09-11)
UPDATE `invoices`
SET `admission_id` = 1
WHERE `invoice_id` = 1 AND `admission_id` IS NULL;

-- Invoice 2 (INV-2026-079) -> Allocation 2 (Patient 5, admitted 2026-09-12)
UPDATE `invoices`
SET `admission_id` = 2
WHERE `invoice_id` = 2 AND `admission_id` IS NULL;

-- Invoice 3 (INV-2026-076) -> Allocation 3 (Patient 10, admitted 2026-09-13)
UPDATE `invoices`
SET `admission_id` = 3
WHERE `invoice_id` = 3 AND `admission_id` IS NULL;

-- Invoice 4 (INV-2026-068) -> Allocation 4 (Patient 18, admitted 2026-09-10)
UPDATE `invoices`
SET `admission_id` = 4
WHERE `invoice_id` = 4 AND `admission_id` IS NULL;

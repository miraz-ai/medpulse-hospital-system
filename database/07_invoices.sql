-- ============================================================================
-- Migration: 07_invoices.sql
-- Module: Billing Summaries & Itemized Ledger (Hospital Accounts)
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `invoices` (
  `invoice_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `admission_id` int(11) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_payable` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('bKash','Nagad','Credit Card','Cash','Insurance') NOT NULL DEFAULT 'Cash',
  `status` enum('Paid','Pending','Partial') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `idx_invoices_number` (`invoice_number`),
  KEY `idx_invoices_patient` (`patient_id`),
  KEY `idx_invoices_admission` (`admission_id`),
  KEY `idx_invoices_generated_by` (`generated_by`),
  KEY `idx_invoices_status` (`status`),
  KEY `idx_invoices_created` (`created_at`),
  CONSTRAINT `fk_invoices_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_invoices_admission` 
    FOREIGN KEY (`admission_id`) REFERENCES `bed_allocations` (`allocation_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_invoices_generated_by` 
    FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `item_type` enum('Consultation','Bed Charge','Diagnostic Test','Pharmacy') NOT NULL,
  `description` varchar(255) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `doctor_payout_status` enum('UNCLAIMED','PENDING_CLEARANCE','DISBURSED') NOT NULL DEFAULT 'UNCLAIMED',
  `doctor_payout_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_invoice_items_inv` (`invoice_id`),
  KEY `idx_invoice_items_doctor` (`doctor_id`),
  KEY `idx_invoice_items_type` (`item_type`),
  KEY `idx_invoice_items_payout_status` (`doctor_payout_status`),
  CONSTRAINT `fk_invoice_items_inv` 
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_invoice_items_doctor` 
    FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

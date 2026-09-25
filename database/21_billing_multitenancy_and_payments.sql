-- ==============================================================================
-- MedPulse Enterprise Hospital Management System
-- Migration 21: Strict Multi-Branch Billing Data Isolation & Payment Collections
-- ==============================================================================

USE medpulse_hms;

-- 1. Ensure hospital_id exists, is indexed, and is NOT NULL in invoices
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'invoices' 
      AND COLUMN_NAME = 'hospital_id'
);

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE invoices ADD COLUMN hospital_id INT NOT NULL DEFAULT 1 AFTER invoice_number, ADD INDEX idx_invoices_hospital (hospital_id);',
    'SELECT "Column hospital_id already exists in invoices";'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Backfill existing invoices to correct originating hospital
-- Step 2a: Map via inpatient admission -> bed -> hospital_beds.hospital_id
UPDATE invoices i
JOIN bed_allocations ba ON i.admission_id = ba.allocation_id
JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
SET i.hospital_id = hb.hospital_id
WHERE hb.hospital_id IS NOT NULL;

-- Step 2b: Fallback to generating user's hospital_id
UPDATE invoices i
JOIN users u ON i.generated_by = u.user_id
SET i.hospital_id = u.hospital_id
WHERE (i.hospital_id IS NULL OR i.hospital_id = 1) AND u.hospital_id IS NOT NULL AND u.hospital_id > 1;

-- 3. Compatibility VIEW: bills mapping to invoices
CREATE OR REPLACE VIEW bills AS
SELECT 
    invoice_id AS bill_id,
    invoices.*
FROM invoices;

-- 4. Create invoice_payments table with strict hospital_id and collected_by_user_id
CREATE TABLE IF NOT EXISTS invoice_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    invoice_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash', 'bKash', 'Nagad', 'Credit Card', 'Bank Card', 'Cheque', 'Insurance', 'Other') NOT NULL DEFAULT 'Cash',
    transaction_reference VARCHAR(100) NULL,
    collected_by_user_id INT NOT NULL,
    payment_notes TEXT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payments_hospital (hospital_id),
    INDEX idx_payments_invoice (invoice_id),
    INDEX idx_payments_collected_by (collected_by_user_id),
    INDEX idx_payments_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Compatibility VIEW: transactions mapping to invoice_payments
CREATE OR REPLACE VIEW transactions AS
SELECT 
    payment_id AS transaction_id,
    invoice_payments.*
FROM invoice_payments;

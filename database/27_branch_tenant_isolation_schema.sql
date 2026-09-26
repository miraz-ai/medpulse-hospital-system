-- ============================================================================
-- MedPulse Enterprise HMS
-- Migration 27: Branch Tenant Isolation Schema & Doctor Approvals
-- ============================================================================

-- 1. Ensure doctors table status supports pending, active, approved, rejected
ALTER TABLE doctors MODIFY COLUMN status ENUM('pending','active','approved','rejected') NOT NULL DEFAULT 'pending';

-- 2. Add bmdc_reg_no and id virtual column to doctors if not present
SET @has_bmdc = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doctors' AND COLUMN_NAME = 'bmdc_reg_no');
SET @alter_bmdc = IF(@has_bmdc = 0, 'ALTER TABLE doctors ADD COLUMN bmdc_reg_no VARCHAR(50) DEFAULT NULL AFTER hospital_id', 'SELECT 1');
PREPARE stmt_bmdc FROM @alter_bmdc;
EXECUTE stmt_bmdc;
DEALLOCATE PREPARE stmt_bmdc;

SET @has_id = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doctors' AND COLUMN_NAME = 'id');
SET @alter_id = IF(@has_id = 0, 'ALTER TABLE doctors ADD COLUMN id INT GENERATED ALWAYS AS (doctor_id) VIRTUAL', 'SELECT 1');
PREPARE stmt_id FROM @alter_id;
EXECUTE stmt_id;
DEALLOCATE PREPARE stmt_id;

-- 3. Populate bmdc_reg_no from doctor_profiles
UPDATE doctors d 
JOIN doctor_profiles dp ON dp.user_id = d.user_id 
SET d.bmdc_reg_no = dp.bmdc_license_number 
WHERE d.bmdc_reg_no IS NULL OR d.bmdc_reg_no = '';

-- 4. Align doctors doctor_id and patients id with user_id for strict parity
UPDATE doctors SET doctor_id = doctor_id + 100000 WHERE doctor_id != user_id;
UPDATE doctors SET doctor_id = user_id WHERE doctor_id != user_id;

UPDATE patients SET id = id + 100000 WHERE id != user_id;
UPDATE patients SET id = user_id WHERE id != user_id;

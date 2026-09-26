-- ============================================================================
-- Migration 25: OPD Sequential Token Generation & Chamber Queue Schema Alignment
-- Database: medpulse_hms
-- ============================================================================

USE `medpulse_hms`;

-- 1. Ensure `id` is primary key on appointments
SET @col_id_exists := (SELECT COUNT(*) FROM information_schema.columns 
                       WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'id');
-- If appointment_id is PK, change it to id
SET @col_app_id_exists := (SELECT COUNT(*) FROM information_schema.columns 
                           WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'appointment_id' AND extra LIKE '%auto_increment%');

-- 2. Add hospital_id if missing
SET @col_hosp := (SELECT COUNT(*) FROM information_schema.columns 
                  WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'hospital_id');
SET @sql_hosp := IF(@col_hosp = 0, 'ALTER TABLE `appointments` ADD COLUMN `hospital_id` INT NOT NULL DEFAULT 1 AFTER `id`', 'SELECT 1');
PREPARE stmt_hosp FROM @sql_hosp;
EXECUTE stmt_hosp;
DEALLOCATE PREPARE stmt_hosp;

-- 3. Add time_slot if missing
SET @col_slot := (SELECT COUNT(*) FROM information_schema.columns 
                  WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'time_slot');
SET @sql_slot := IF(@col_slot = 0, 'ALTER TABLE `appointments` ADD COLUMN `time_slot` VARCHAR(50) NOT NULL DEFAULT \'Morning\' AFTER `appointment_date`', 'SELECT 1');
PREPARE stmt_slot FROM @sql_slot;
EXECUTE stmt_slot;
DEALLOCATE PREPARE stmt_slot;

-- 4. Add token_number if missing
SET @col_tok := (SELECT COUNT(*) FROM information_schema.columns 
                 WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'token_number');
SET @sql_tok := IF(@col_tok = 0, 'ALTER TABLE `appointments` ADD COLUMN `token_number` INT NOT NULL DEFAULT 1 AFTER `time_slot`', 'SELECT 1');
PREPARE stmt_tok FROM @sql_tok;
EXECUTE stmt_tok;
DEALLOCATE PREPARE stmt_tok;

-- 5. Standardize status enum
ALTER TABLE `appointments` MODIFY `status` ENUM('booked', 'checked_in', 'in_consultation', 'completed', 'cancelled') NOT NULL DEFAULT 'booked';

-- 6. Add composite index on (doctor_id, appointment_date, time_slot)
SET @idx_slot := (SELECT COUNT(*) FROM information_schema.statistics 
                  WHERE table_schema = DATABASE() AND table_name = 'appointments' AND index_name = 'idx_doc_date_slot');
SET @sql_idx := IF(@idx_slot = 0, 'ALTER TABLE `appointments` ADD INDEX `idx_doc_date_slot` (`doctor_id`, `appointment_date`, `time_slot`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

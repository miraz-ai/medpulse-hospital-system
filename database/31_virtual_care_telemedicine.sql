-- ============================================================================
-- Migration 31: Virtual Care Suite (24/7 Live Tele-Consultation Room)
-- Dynamic Queue Token Generation, On-Call Duty Doctor Host Links & Sync Telemetry
-- Database: medpulse_hms
-- ============================================================================

USE `medpulse_hms`;

-- 1. Ensure `teleconsult_link` column in doctor_profiles
SET @col_tc_link := (SELECT COUNT(*) FROM information_schema.columns 
                     WHERE table_schema = DATABASE() AND table_name = 'doctor_profiles' AND column_name = 'teleconsult_link');
SET @sql_tc_link := IF(@col_tc_link = 0, 
    'ALTER TABLE `doctor_profiles` ADD COLUMN `teleconsult_link` VARCHAR(500) DEFAULT NULL COMMENT \'Doctor personal or dynamic Zoom meeting room URL\' AFTER `shift_timings`', 
    'SELECT 1');
PREPARE stmt_tc_link FROM @sql_tc_link;
EXECUTE stmt_tc_link;
DEALLOCATE PREPARE stmt_tc_link;

-- 2. Ensure `is_teleconsult_duty` column in doctor_profiles
SET @col_tc_duty := (SELECT COUNT(*) FROM information_schema.columns 
                     WHERE table_schema = DATABASE() AND table_name = 'doctor_profiles' AND column_name = 'is_teleconsult_duty');
SET @sql_tc_duty := IF(@col_tc_duty = 0, 
    'ALTER TABLE `doctor_profiles` ADD COLUMN `is_teleconsult_duty` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1 if available for 24/7 on-call tele-consultation queue\' AFTER `teleconsult_link`', 
    'SELECT 1');
PREPARE stmt_tc_duty FROM @sql_tc_duty;
EXECUTE stmt_tc_duty;
DEALLOCATE PREPARE stmt_tc_duty;

-- 3. Ensure `teleconsult_room_code` column in doctor_profiles
SET @col_tc_code := (SELECT COUNT(*) FROM information_schema.columns 
                     WHERE table_schema = DATABASE() AND table_name = 'doctor_profiles' AND column_name = 'teleconsult_room_code');
SET @sql_tc_code := IF(@col_tc_code = 0, 
    'ALTER TABLE `doctor_profiles` ADD COLUMN `teleconsult_room_code` VARCHAR(50) DEFAULT NULL COMMENT \'Unique clinical tele-consult room ID e.g. MP-VC-401\' AFTER `is_teleconsult_duty`', 
    'SELECT 1');
PREPARE stmt_tc_code FROM @sql_tc_code;
EXECUTE stmt_tc_code;
DEALLOCATE PREPARE stmt_tc_code;

-- 4. Ensure `consultation_type` column in appointments
SET @col_c_type := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'consultation_type');
SET @sql_c_type := IF(@col_c_type = 0, 
    'ALTER TABLE `appointments` ADD COLUMN `consultation_type` ENUM(\'opd\', \'teleconsultation\') NOT NULL DEFAULT \'opd\' AFTER `queue_status`', 
    'SELECT 1');
PREPARE stmt_c_type FROM @sql_c_type;
EXECUTE stmt_c_type;
DEALLOCATE PREPARE stmt_c_type;

-- 5. Ensure `meeting_link` column in appointments
SET @col_m_link := (SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'meeting_link');
SET @sql_m_link := IF(@col_m_link = 0, 
    'ALTER TABLE `appointments` ADD COLUMN `meeting_link` VARCHAR(500) DEFAULT NULL AFTER `consultation_type`', 
    'SELECT 1');
PREPARE stmt_m_link FROM @sql_m_link;
EXECUTE stmt_m_link;
DEALLOCATE PREPARE stmt_m_link;

-- 5b. Ensure `teleconsult_dismissed` column in appointments
SET @col_tc_dismiss := (SELECT COUNT(*) FROM information_schema.columns 
                        WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'teleconsult_dismissed');
SET @sql_tc_dismiss := IF(@col_tc_dismiss = 0, 
    'ALTER TABLE `appointments` ADD COLUMN `teleconsult_dismissed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `meeting_link`', 
    'SELECT 1');
PREPARE stmt_tc_dismiss FROM @sql_tc_dismiss;
EXECUTE stmt_tc_dismiss;
DEALLOCATE PREPARE stmt_tc_dismiss;

-- 6. Populate default authentic Zoom meeting links & room codes for active duty specialists
UPDATE `doctor_profiles`
SET `teleconsult_room_code` = CONCAT('MP-VC-', LPAD(user_id, 3, '0')),
    `is_teleconsult_duty` = 1,
    `teleconsult_link` = COALESCE(`teleconsult_link`, 
        CONCAT('https://zoom.us/j/', 9800000000 + (user_id * 179424673 % 899999999), '?pwd=mp', SUBSTRING(SHA2(CONCAT('medpulse_telecare_', user_id), 256), 1, 6))
    )
WHERE `user_id` IN (SELECT `user_id` FROM `users` WHERE `role` = 'Doctor');

-- 7. Multi-Branch Facility Alignment & Isolation
-- Ensure doctors are assigned to verified hospital branches across the 6-hospital network
UPDATE `doctor_profiles` SET `hospital_id` = 1 WHERE `user_id` = 8;  -- Dr. Rafiqul Islam -> Hospital 1 (MedPulse Dhanmondi)
UPDATE `doctor_profiles` SET `hospital_id` = 1 WHERE `user_id` = 29; -- Dr. Afzal Hossain Miraz -> Hospital 1 (MedPulse Dhanmondi)
UPDATE `doctor_profiles` SET `hospital_id` = 2 WHERE `user_id` = 20; -- Dr. Mikasa Ackerman -> Hospital 2 (Square Hospital)
UPDATE `doctor_profiles` SET `hospital_id` = 3 WHERE `user_id` = 21; -- Dr. Satoru Gojo -> Hospital 3 (United Hospital)
UPDATE `doctor_profiles` SET `hospital_id` = 4 WHERE `user_id` = 13; -- Dr. Miftahul Sheikh -> Hospital 4 (United Medical College)
UPDATE `doctor_profiles` SET `hospital_id` = 5 WHERE `user_id` = 14; -- Dr. Mitsuha -> Hospital 5 (Evercare Hospital)

-- Ensure doctors table maintains multi-hospital approved linkage
INSERT INTO `doctors` (`user_id`, `hospital_id`, `status`)
VALUES 
  (8,  1, 'approved'),
  (29, 1, 'approved'),
  (20, 2, 'approved'),
  (21, 3, 'approved'),
  (13, 4, 'approved'),
  (14, 5, 'approved'),
  (20, 6, 'approved')  -- Dr. Mikasa also provides Emergency Trauma coverage for Hospital 6 (NIBPS)
ON DUPLICATE KEY UPDATE `status` = 'approved';

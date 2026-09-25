-- ============================================================================
-- Migration: Patient Profiles Table with Enterprise UID, DOB & Blood Group
-- Database: medpulse_hms
-- ============================================================================

USE medpulse_hms;

-- 1. Create `patients` table with strict indexing and unique patient_uid
CREATE TABLE IF NOT EXISTS `patients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `patient_uid` VARCHAR(30) NOT NULL UNIQUE,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(120) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `gender` ENUM('Male', 'Female', 'Other') DEFAULT 'Male',
    `dob` DATE NOT NULL,
    `blood_group` ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown') DEFAULT 'Unknown',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_patient_uid` (`patient_uid`),
    INDEX `idx_patient_user_id` (`user_id`),
    INDEX `idx_patient_dob` (`dob`),
    INDEX `idx_patient_blood_group` (`blood_group`),
    CONSTRAINT `fk_patients_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Backfill existing patient records from `users` table
INSERT INTO `patients` (`user_id`, `patient_uid`, `full_name`, `email`, `phone`, `gender`, `dob`, `blood_group`, `created_at`)
SELECT 
    u.user_id,
    CONCAT('MP-', YEAR(NOW()), '-', LPAD(u.user_id, 5, '0')) AS patient_uid,
    u.full_name,
    u.email,
    u.phone,
    COALESCE(u.gender, 'Male') AS gender,
    COALESCE(u.date_of_birth, DATE_SUB(CURDATE(), INTERVAL COALESCE(u.age, 22) YEAR)) AS dob,
    COALESCE(u.blood_group, 'Unknown') AS blood_group,
    COALESCE(u.created_at, NOW()) AS created_at
FROM `users` u
LEFT JOIN `patients` p ON u.user_id = p.user_id
WHERE u.role = 'Patient' AND p.id IS NULL
ORDER BY u.user_id ASC;

-- 3. Verify counts
SELECT COUNT(*) AS total_patients_registered FROM `patients`;

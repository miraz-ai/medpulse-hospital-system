-- ============================================================================
-- MedPulse Enterprise HMS
-- Migration 26: Multi-Hospital Network Live Bed Matrix & 45-Minute Hold Schema
-- ============================================================================

-- 1. Create bed_reservations table
CREATE TABLE IF NOT EXISTS bed_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    bed_id INT NOT NULL,
    patient_id INT NOT NULL,
    hold_expires_at TIMESTAMP NOT NULL,
    status ENUM('held', 'admitted', 'cancelled', 'expired') DEFAULT 'held',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (hospital_id, bed_id),
    INDEX (patient_id),
    CONSTRAINT fk_bed_res_hospital FOREIGN KEY (hospital_id) REFERENCES hospitals(hospital_id) ON DELETE CASCADE,
    CONSTRAINT fk_bed_res_patient FOREIGN KEY (patient_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Ensure hospitals table has location and emergency_status
SET @has_loc = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hospitals' AND COLUMN_NAME = 'location');
SET @alter_loc = IF(@has_loc = 0, 'ALTER TABLE hospitals ADD COLUMN location VARCHAR(255) DEFAULT NULL AFTER city', 'SELECT 1');
PREPARE stmt_loc FROM @alter_loc;
EXECUTE stmt_loc;
DEALLOCATE PREPARE stmt_loc;

SET @has_em = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hospitals' AND COLUMN_NAME = 'emergency_status');
SET @alter_em = IF(@has_em = 0, "ALTER TABLE hospitals ADD COLUMN emergency_status VARCHAR(50) DEFAULT 'Operational' AFTER location", 'SELECT 1');
PREPARE stmt_em FROM @alter_em;
EXECUTE stmt_em;
DEALLOCATE PREPARE stmt_em;

-- 3. Populate default network locations and emergency readiness
UPDATE hospitals SET 
    location = CASE hospital_id
        WHEN 1 THEN 'Dhanmondi, Dhaka'
        WHEN 2 THEN 'Panthapath, Dhaka'
        WHEN 3 THEN 'Gulshan-2, Dhaka'
        WHEN 4 THEN 'Uttara / Vatara, Dhaka'
        WHEN 5 THEN 'Bashundhara R/A, Dhaka'
        WHEN 6 THEN 'Chankharpul, Dhaka'
        ELSE COALESCE(address, city)
    END,
    emergency_status = CASE hospital_id
        WHEN 2 THEN 'Critical Capacity'
        WHEN 6 THEN 'Critical Capacity'
        ELSE 'Operational'
    END
WHERE location IS NULL OR location = '';

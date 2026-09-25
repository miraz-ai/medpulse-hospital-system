-- =============================================================================
-- Migration 19: National Life-Support & Clinical Inventory Telemetry Schema
-- Tracks live Oxygen reserves, ICU Ventilator fleet, and Universal Blood Bank
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `hospital_resources`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS `hospital_resources` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `hospital_id` INT NOT NULL UNIQUE,
  
  -- Oxygen Sub-System:
  `oxygen_reserve_pct` INT NOT NULL DEFAULT 75,
  `current_pressure_psi` INT NOT NULL DEFAULT 2200,
  `depletion_days` DECIMAL(4,1) NOT NULL DEFAULT 7.5,
  `tanker_dispatched` TINYINT(1) NOT NULL DEFAULT 0,
  `tanker_dispatched_at` TIMESTAMP NULL DEFAULT NULL,
  `last_calibrated_at` TIMESTAMP NULL DEFAULT NULL,
  
  -- ICU Ventilator Sub-System:
  `ventilators_total` INT NOT NULL DEFAULT 80,
  `ventilators_active` INT NOT NULL DEFAULT 50,
  `hardware_spec` VARCHAR(150) NOT NULL DEFAULT 'Standard Dual Mode ICU Ventilator',
  
  -- Universal Blood Bank Sub-System:
  `blood_o_neg` INT NOT NULL DEFAULT 25,
  `blood_trauma_packs` INT NOT NULL DEFAULT 100,
  `blood_a_pos` INT NOT NULL DEFAULT 50,
  `blood_b_pos` INT NOT NULL DEFAULT 60,
  `blood_o_pos` INT NOT NULL DEFAULT 80,
  `blood_ab_neg` INT NOT NULL DEFAULT 15,
  `platelet_bags` INT NOT NULL DEFAULT 30,
  `cryo_units` INT NOT NULL DEFAULT 15,
  `courier_dispatched` TINYINT(1) NOT NULL DEFAULT 0,
  `courier_dispatched_at` TIMESTAMP NULL DEFAULT NULL,
  
  -- System Tracking:
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Compatibility Virtual Aliases for Seamless Backward Interoperability:
  `oxygen_psi` INT AS (current_pressure_psi) VIRTUAL,
  `oxygen_days_left` DECIMAL(4,1) AS (depletion_days) VIRTUAL,
  `ventilator_model` VARCHAR(150) AS (hardware_spec) VIRTUAL,
  `blood_o_neg_units` INT AS (blood_o_neg) VIRTUAL,
  `blood_trauma_units` INT AS (blood_trauma_packs) VIRTUAL,
  
  CONSTRAINT `fk_hospital_resources_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`hospital_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Seeding Baseline Dynamic Telemetry for All 6 Network Hospitals
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `hospital_resources` (
  `hospital_id`,
  `oxygen_reserve_pct`,
  `current_pressure_psi`,
  `depletion_days`,
  `tanker_dispatched`,
  `tanker_dispatched_at`,
  `last_calibrated_at`,
  `ventilators_total`,
  `ventilators_active`,
  `hardware_spec`,
  `blood_o_neg`,
  `blood_trauma_packs`,
  `blood_a_pos`,
  `blood_b_pos`,
  `blood_o_pos`,
  `blood_ab_neg`,
  `platelet_bags`,
  `cryo_units`,
  `courier_dispatched`,
  `courier_dispatched_at`
) VALUES
  -- 1. MedPulse Hospital & Specialty Care
  (1, 78, 2180, 7.2, 0, NULL, NOW(), 95, 68, 'Dräger Evita V800 Advanced', 28, 124, 54, 72, 98, 14, 36, 18, 0, NULL),

  -- 2. Square Hospital Ltd
  (2, 92, 2350, 9.5, 0, NULL, NOW(), 110, 84, 'Hamilton-G5 High-End', 34, 156, 65, 88, 112, 16, 42, 22, 0, NULL),

  -- 3. United Hospital Ltd
  (3, 84, 2240, 8.1, 0, NULL, NOW(), 88, 59, 'Maquet Servo-u ICU', 26, 118, 48, 66, 85, 12, 32, 16, 0, NULL),

  -- 4. United Medical College Hospital (UMCH)
  (4, 64, 1780, 4.8, 0, NULL, NOW(), 75, 49, 'GE Healthcare CARESCAPE R860', 28, 107, 35, 46, 62, 10, 32, 12, 0, NULL),

  -- 5. Evercare Hospital Dhaka
  (5, 88, 2290, 8.6, 0, NULL, NOW(), 92, 62, 'Philips Respironics V680', 30, 135, 58, 78, 104, 15, 38, 20, 0, NULL),

  -- 6. National Institute of Burn & Plastic Surgery (NIBPS)
  (6, 61, 1770, 5.4, 0, NULL, NOW(), 85, 74, 'Puritan Bennett 980 Series', 14, 68, 28, 38, 44, 8, 18, 8, 0, NULL)
ON DUPLICATE KEY UPDATE
  `oxygen_reserve_pct`   = VALUES(`oxygen_reserve_pct`),
  `current_pressure_psi` = VALUES(`current_pressure_psi`),
  `depletion_days`       = VALUES(`depletion_days`),
  `last_calibrated_at`   = VALUES(`last_calibrated_at`),
  `ventilators_total`    = VALUES(`ventilators_total`),
  `ventilators_active`   = VALUES(`ventilators_active`),
  `hardware_spec`        = VALUES(`hardware_spec`),
  `blood_o_neg`          = VALUES(`blood_o_neg`),
  `blood_trauma_packs`   = VALUES(`blood_trauma_packs`),
  `blood_a_pos`          = VALUES(`blood_a_pos`),
  `blood_b_pos`          = VALUES(`blood_b_pos`),
  `blood_o_pos`          = VALUES(`blood_o_pos`),
  `blood_ab_neg`         = VALUES(`blood_ab_neg`),
  `platelet_bags`        = VALUES(`platelet_bags`),
  `cryo_units`           = VALUES(`cryo_units`);

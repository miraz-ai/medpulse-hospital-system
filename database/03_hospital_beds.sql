-- ============================================================================
-- Migration: 03_hospital_beds.sql
-- Module: Hospital Beds Inventory & 500-Bed Clinical Ward Registry
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ============================================================================

USE `medpulse_hms`;

CREATE TABLE IF NOT EXISTS `hospital_beds` (
  `bed_id` int(11) NOT NULL AUTO_INCREMENT,
  `bed_number` varchar(20) NOT NULL,
  `ward_type` enum(
    'Emergency',
    'General Ward Male',
    'General Ward Female',
    'Pediatrics',
    'Semi-Cabin',
    'Deluxe Cabin',
    'VIP Suite',
    'Presidential Suite',
    'ICU',
    'CCU',
    'NICU',
    'Recovery'
  ) NOT NULL,
  `floor_number` int(11) NOT NULL,
  `daily_rate` decimal(10,2) NOT NULL,
  `status` enum('Available','Occupied','Maintenance','Reserved') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bed_id`),
  UNIQUE KEY `idx_beds_bed_number` (`bed_number`),
  KEY `idx_beds_ward_status` (`ward_type`, `status`),
  KEY `idx_beds_floor` (`floor_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

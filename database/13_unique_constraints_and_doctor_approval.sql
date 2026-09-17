-- ==========================================================
-- MedPulse HMS: Unique User Constraints & Doctor Approval Schema Hardening
-- Migration: 13_unique_constraints_and_doctor_approval.sql
-- ==========================================================

USE `medpulse_hms`;

-- 1. Ensure unique indexes on users for email and phone
ALTER TABLE `users` 
  ADD UNIQUE KEY IF NOT EXISTS `unique_email` (`email`),
  ADD UNIQUE KEY IF NOT EXISTS `unique_phone` (`phone`);

-- 2. Ensure bmdc_reg_number column, unique index, and approval tracking in doctor_profiles
ALTER TABLE `doctor_profiles`
  ADD COLUMN IF NOT EXISTS `bmdc_reg_number` VARCHAR(50) NULL AFTER `bmdc_license_number`,
  ADD COLUMN IF NOT EXISTS `approval_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER `military_rank`,
  ADD COLUMN IF NOT EXISTS `approved_by` INT NULL AFTER `approval_status`,
  ADD COLUMN IF NOT EXISTS `approved_at` DATETIME NULL AFTER `approved_by`;

-- Synchronize bmdc_reg_number from bmdc_license_number
UPDATE `doctor_profiles` 
SET `bmdc_reg_number` = `bmdc_license_number` 
WHERE `bmdc_reg_number` IS NULL OR `bmdc_reg_number` = '';

-- Add unique constraint for BMDC registration
ALTER TABLE `doctor_profiles` 
  ADD UNIQUE KEY IF NOT EXISTS `unique_bmdc` (`bmdc_reg_number`);

-- Synchronize approval_status with existing user status
UPDATE `doctor_profiles` dp
JOIN `users` u ON dp.user_id = u.user_id
SET dp.approval_status = IF(u.status = 'active', 'approved', IF(u.status = 'rejected', 'rejected', 'pending'));

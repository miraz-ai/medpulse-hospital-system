-- ============================================================================
-- MedPulse Enterprise HMS
-- Migration 30: Provision Multi-Branch Dedicated Reception/Admission Staff Accounts
-- ============================================================================

USE `medpulse_hms`;

-- 1. Insert or update dedicated multi-branch staff users (Password: Staff@123)
-- Hash: $2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW
INSERT INTO `users` (`full_name`, `email`, `phone`, `gender`, `password_hash`, `role`, `status`, `hospital_id`, `department`, `created_at`)
VALUES
  ('Reception Desk - MedPulse Hospital & Specialty Care', 'staff.medpulse@medpulse.com', '01890000001', 'Male', '$2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW', 'Staff', 'active', 1, 'Frontdesk Registrar', NOW()),
  ('Reception Desk - Square Hospital Ltd', 'staff.square@medpulse.com', '01890000002', 'Male', '$2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW', 'Staff', 'active', 2, 'Frontdesk Registrar', NOW()),
  ('Reception Desk - United Hospital Ltd', 'staff.united@medpulse.com', '01890000003', 'Male', '$2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW', 'Staff', 'active', 3, 'Frontdesk Registrar', NOW()),
  ('Reception Desk - United Medical College Hospital', 'staff.umch@medpulse.com', '01890000004', 'Male', '$2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW', 'Staff', 'active', 4, 'Frontdesk Registrar', NOW()),
  ('Reception Desk - Evercare Hospital Dhaka', 'staff.evercare@medpulse.com', '01890000005', 'Male', '$2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW', 'Staff', 'active', 5, 'Frontdesk Registrar', NOW()),
  ('Reception Desk - National Institute of Burn and Plastic Surgery', 'staff.nibps@medpulse.com', '01890000006', 'Male', '$2y$10$C1iVmE527ItFF7oONobGNOfN4DwowhgxiTC0iaR6fPEjOYIa9BvVW', 'Staff', 'active', 6, 'Frontdesk Registrar', NOW())
ON DUPLICATE KEY UPDATE
  `full_name` = VALUES(`full_name`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`),
  `hospital_id` = VALUES(`hospital_id`),
  `department` = VALUES(`department`),
  `password_hash` = VALUES(`password_hash`);

-- 2. Link staff accounts in staff table
INSERT INTO `staff` (`user_id`, `hospital_id`, `department`, `role_title`, `status`, `created_at`)
SELECT u.user_id, u.hospital_id, 'Frontdesk Registrar', 'Admission Desk Officer', 'active', NOW()
FROM `users` u
LEFT JOIN `staff` s ON s.user_id = u.user_id
WHERE u.email IN (
  'staff.medpulse@medpulse.com',
  'staff.square@medpulse.com',
  'staff.united@medpulse.com',
  'staff.umch@medpulse.com',
  'staff.evercare@medpulse.com',
  'staff.nibps@medpulse.com'
) AND s.staff_id IS NULL;

-- 3. Update existing staff links if present
UPDATE `staff` s
JOIN `users` u ON s.user_id = u.user_id
SET s.hospital_id = u.hospital_id,
    s.department = 'Frontdesk Registrar',
    s.role_title = 'Admission Desk Officer',
    s.status = 'active'
WHERE u.email IN (
  'staff.medpulse@medpulse.com',
  'staff.square@medpulse.com',
  'staff.united@medpulse.com',
  'staff.umch@medpulse.com',
  'staff.evercare@medpulse.com',
  'staff.nibps@medpulse.com'
);

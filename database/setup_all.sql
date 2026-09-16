-- ============================================================================
-- MedPulse Enterprise Hospital Management System (HMS)
-- Master Database Migration Runner: setup_all.sql
-- Executes all modular migrations (01 through 10) in strict relational dependency order
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `medpulse_hms`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `medpulse_hms`;

-- 1. Core Users Table & Identity Anchor (Strict ENUM('Male', 'Female'))
SOURCE 01_users.sql;

-- 2. Doctor Profiles & Clinical Credentialing
SOURCE 02_doctor_profiles.sql;

-- 3. Hospital Beds Inventory
SOURCE 03_hospital_beds.sql;

-- 4. Consultation Bookings & Outpatient Appointments
SOURCE 04_appointments.sql;

-- 5. Digital Prescriptions & Prescription Line Items
SOURCE 05_prescriptions.sql;

-- 6. Inpatient Admissions & Live Bed Census Allocations
SOURCE 06_bed_allocations.sql;

-- 7. Billing Ledgers & Invoices
SOURCE 07_invoices.sql;

-- 8. Diagnostic Pathology & Radiology Reports
SOURCE 08_diagnostic_reports.sql;

-- 9. Compliance & Security Audit Logs
SOURCE 09_audit_logs.sql;

-- 10. Verified Mock Seed Data Ingestion
SOURCE 10_seed_data.sql;

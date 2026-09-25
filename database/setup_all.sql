-- ============================================================================
-- MedPulse Enterprise Hospital Management System (HMS)
-- Master Database Migration Runner: setup_all.sql
-- Executes all modular migrations (01 through 10) in strict relational dependency order
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `medpulse_hms`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `medpulse_hms`;

-- Temporarily disable foreign key constraints for clean modular ingestion
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Core Users Table & Identity Anchor (Strict ENUM('Male', 'Female'))
SOURCE 01_users.sql;

-- 2. Doctor Profiles & Clinical Credentialing
SOURCE 02_doctor_profiles.sql;

-- 3. Hospital Beds Inventory (500-Bed Modern Tertiary Capacity)
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

-- 11. Inpatient & Real-Time Event System Schema Migration
SOURCE 11_inpatient_realtime_schema.sql;

-- 12. Doctor Payout & Inpatient Billing Linkage Migration
SOURCE 12_doctor_payout_and_billing_linkage.sql;

-- 13. Unique Constraints & Doctor Approval Schema Hardening
SOURCE 13_unique_constraints_and_doctor_approval.sql;

-- 14. Discharge Automated Invoicing & Doctor Earnings Ledger
SOURCE 14_discharge_billing_and_doctor_earnings.sql;

-- 15. Multi-Hospital Support & Super Admin Provisioning
SOURCE 15_multi_hospital_support.sql;

-- 16. Optimistic Bed Reservation & Concurrent Double-Booking Prevention
SOURCE 16_bed_reservation_locking.sql;

-- 17. Multi-Hospital Network Expansion (5-Hospital Topology & Bed Architectures)
SOURCE 17_network_expansion.sql;

-- 18. 6-Hospital Network Topology, NIBPS Apex Integration & Emergency Surge Engine
SOURCE 18_nibps_emergency_surge.sql;

-- Re-enable foreign key constraints
SET FOREIGN_KEY_CHECKS = 1;



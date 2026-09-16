-- ============================================================================
-- Migration: 10_seed_data.sql
-- Module: Verified Seed Data & Realistic Hospital Records
-- Linked directly to existing active user IDs in `users`
-- ============================================================================

USE `medpulse_hms`;

-- ----------------------------------------------------------------------------
-- 1. Doctor Profiles
-- (user_id 8: Dr. Rafiqul Islam, 13: Dr. Miftahul, 14: Dr. Mitsuha, 
--  20: Dr. Mikasa, 21: Dr. Satoru Gojo, 29: Dr. Afzal Hossain Miraz)
-- ----------------------------------------------------------------------------
INSERT INTO `doctor_profiles` 
  (`user_id`, `specialty`, `bmdc_license_number`, `consultation_fee`, `room_number`, `available_days`, `shift_timings`)
VALUES
  (8,  'Cardiology & Intensive Care',          'BMDC-A-48291', 1200.00, 'Room 304', 'Mon, Wed, Fri',         '09:00 AM - 02:00 PM'),
  (13, 'Orthopedic Surgery & Traumatology',    'BMDC-A-51042', 1000.00, 'Room 210', 'Sun, Tue, Thu',         '10:00 AM - 03:00 PM'),
  (14, 'Dermatology & Skin Aesthetics',        'BMDC-A-62914',  800.00, 'Room 105', 'Mon, Tue, Thu, Sat',     '04:00 PM - 08:00 PM'),
  (20, 'General & Laparoscopic Surgery',       'BMDC-A-73820', 1500.00, 'Room 402', 'Daily (Emergency Call)', '08:00 AM - 04:00 PM'),
  (21, 'Neurology & Critical Neurosurgery',    'BMDC-A-84912', 2000.00, 'Room 501', 'Mon, Wed, Thu, Sat',     '10:30 AM - 05:00 PM'),
  (29, 'Internal Medicine & Diabetology',      'BMDC-A-95018', 1000.00, 'Room 202', 'Sun, Mon, Wed, Thu',     '05:00 PM - 09:30 PM')
ON DUPLICATE KEY UPDATE
  `specialty` = VALUES(`specialty`),
  `consultation_fee` = VALUES(`consultation_fee`),
  `room_number` = VALUES(`room_number`),
  `available_days` = VALUES(`available_days`),
  `shift_timings` = VALUES(`shift_timings`);

-- ----------------------------------------------------------------------------
-- 2. Hospital Beds Inventory
-- ----------------------------------------------------------------------------
INSERT INTO `hospital_beds` (`bed_number`, `ward_type`, `floor_number`, `daily_rate`, `status`)
VALUES
  -- ICU Wards (Floor 3)
  ('ICU-B01', 'ICU', 3, 12000.00, 'Occupied'),
  ('ICU-B02', 'ICU', 3, 12000.00, 'Occupied'),
  ('ICU-B03', 'ICU', 3, 12000.00, 'Available'),
  ('ICU-B04', 'ICU', 3, 12000.00, 'Available'),
  ('ICU-B05', 'ICU', 3, 12000.00, 'Maintenance'),
  -- CCU Wards (Floor 3)
  ('CCU-B01', 'CCU', 3, 10000.00, 'Occupied'),
  ('CCU-B02', 'CCU', 3, 10000.00, 'Available'),
  ('CCU-B03', 'CCU', 3, 10000.00, 'Available'),
  -- General Wards (Floor 2)
  ('GEN-W01', 'General Ward', 2, 2500.00, 'Occupied'),
  ('GEN-W02', 'General Ward', 2, 2500.00, 'Available'),
  ('GEN-W03', 'General Ward', 2, 2500.00, 'Available'),
  ('GEN-W04', 'General Ward', 2, 2500.00, 'Available'),
  -- VIP Cabins (Floor 4)
  ('VIP-C01', 'VIP Cabin', 4, 18000.00, 'Occupied'),
  ('VIP-C02', 'VIP Cabin', 4, 18000.00, 'Available'),
  ('VIP-C03', 'VIP Cabin', 4, 18000.00, 'Reserved')
ON DUPLICATE KEY UPDATE
  `ward_type` = VALUES(`ward_type`),
  `floor_number` = VALUES(`floor_number`),
  `daily_rate` = VALUES(`daily_rate`),
  `status` = VALUES(`status`);

-- ----------------------------------------------------------------------------
-- 3. Bed Allocations
-- (Patient 1: Agatsuma Zenitsu, Patient 5: Nusrat Jahan, 
--  Patient 10: Jahid Hasan, Patient 18: Robert Downey Jr.)
-- ----------------------------------------------------------------------------
INSERT INTO `bed_allocations` 
  (`allocation_id`, `bed_id`, `patient_id`, `attending_doctor_id`, `admitted_at`, `discharged_at`, `status`)
VALUES
  (1, 1, 1,  8,  '2026-09-11 16:00:00', NULL, 'Active'),
  (2, 6, 5,  21, '2026-09-12 11:30:00', NULL, 'Active'),
  (3, 9, 10, 20, '2026-09-13 09:15:00', NULL, 'Active'),
  (4, 13, 18, 29, '2026-09-10 14:00:00', NULL, 'Active')
ON DUPLICATE KEY UPDATE
  `bed_id` = VALUES(`bed_id`),
  `patient_id` = VALUES(`patient_id`),
  `attending_doctor_id` = VALUES(`attending_doctor_id`),
  `status` = VALUES(`status`);

-- ----------------------------------------------------------------------------
-- 4. Consultation Bookings (Appointments)
-- ----------------------------------------------------------------------------
INSERT INTO `appointments` 
  (`appointment_id`, `patient_id`, `doctor_id`, `appointment_date`, `appointment_time`, `serial_number`, `reason_for_visit`, `status`)
VALUES
  (1, 1,  21, '2026-09-18', '10:30:00', 1, 'Routine neurological follow-up and chronic headache evaluation', 'Scheduled'),
  (2, 5,  14, '2026-09-18', '16:00:00', 2, 'Skin allergy assessment and topical therapy review', 'Scheduled'),
  (3, 10, 20, '2026-09-17', '11:00:00', 1, 'Pre-operative gallbladder consultation and surgical clearance', 'In-Consultation'),
  (4, 16, 8,  '2026-09-16', '09:30:00', 3, 'Hypertension management and cardiovascular routine screening', 'Completed'),
  (5, 18, 29, '2026-09-15', '17:30:00', 1, 'Executive annual health and metabolic wellness checkup', 'Completed')
ON DUPLICATE KEY UPDATE
  `patient_id` = VALUES(`patient_id`),
  `doctor_id` = VALUES(`doctor_id`),
  `appointment_date` = VALUES(`appointment_date`),
  `appointment_time` = VALUES(`appointment_time`),
  `serial_number` = VALUES(`serial_number`),
  `reason_for_visit` = VALUES(`reason_for_visit`),
  `status` = VALUES(`status`);

-- ----------------------------------------------------------------------------
-- 5. Prescriptions & Prescription Items
-- ----------------------------------------------------------------------------
INSERT INTO `prescriptions` 
  (`prescription_id`, `patient_id`, `doctor_id`, `appointment_id`, `diagnosis_notes`, `vitals_summary`, `prescribed_at`)
VALUES
  (1, 1, 21, 1, 'Tension-type cephalalgia with mild somatic fatigue and stress insomnia.', 'BP: 120/80 mmHg, Pulse: 74 bpm, Weight: 68 kg', '2026-09-15 11:15:00'),
  (2, 5, 14, 2, 'Acute contact dermatitis with localized erythematous lesions on forearm.', 'BP: 115/75 mmHg, Pulse: 78 bpm, Weight: 54 kg', '2026-09-15 16:45:00'),
  (3, 18, 29, 5, 'Mild dyslipidemia and executive metabolic stress indicators.', 'BP: 125/82 mmHg, Pulse: 72 bpm, Weight: 76 kg', '2026-09-15 18:00:00')
ON DUPLICATE KEY UPDATE
  `diagnosis_notes` = VALUES(`diagnosis_notes`),
  `vitals_summary` = VALUES(`vitals_summary`);

INSERT INTO `prescription_items`
  (`item_id`, `prescription_id`, `medicine_name`, `dosage`, `duration`, `special_instructions`)
VALUES
  (1, 1, 'Napa Extra (500mg)', '1+0+1', '7 Days', 'Take after meals with plenty of water'),
  (2, 1, 'Monas 10mg (Montelukast)', '0+0+1', '15 Days', 'Take once daily at bedtime'),
  (3, 1, 'Neuro-B Complex', '1+0+1', '30 Days', 'Daily vitamin supplement after meals'),
  (4, 2, 'Fexo 120mg (Fexofenadine)', '1+0+1', '10 Days', 'Take after meals for itch relief'),
  (5, 2, 'Dermasol-N Ointment', 'Apply 2x Daily', '7 Days', 'Clean skin and apply topically'),
  (6, 3, 'Lipiget 10mg (Atorvastatin)', '0+0+1', '30 Days', 'Take at night before sleep'),
  (7, 3, 'CoQ10 100mg Dietary Enzyme', '1+0+0', '30 Days', 'Take in morning with breakfast')
ON DUPLICATE KEY UPDATE
  `medicine_name` = VALUES(`medicine_name`),
  `dosage` = VALUES(`dosage`),
  `duration` = VALUES(`duration`),
  `special_instructions` = VALUES(`special_instructions`);

-- ----------------------------------------------------------------------------
-- 6. Billing Invoices & Items
-- ----------------------------------------------------------------------------
INSERT INTO `invoices`
  (`invoice_id`, `invoice_number`, `patient_id`, `generated_by`, `subtotal`, `vat_percentage`, `discount`, `net_payable`, `paid_amount`, `payment_method`, `status`, `created_at`)
VALUES
  (1, 'INV-2026-081', 1,  6,  42000.00, 5.00, 0.00, 44100.00, 44100.00, 'bKash',       'Paid', '2026-09-16 14:20:00'),
  (2, 'INV-2026-079', 5,  15, 27000.00, 5.00, 0.00, 28350.00, 28350.00, 'Insurance',   'Paid', '2026-09-15 10:45:00'),
  (3, 'INV-2026-076', 10, 15,  7800.00, 5.00, 0.00,  8190.00,  8190.00, 'Nagad',       'Paid', '2026-09-14 16:30:00'),
  (4, 'INV-2026-068', 18, 6,  72000.00, 5.00, 0.00, 75600.00, 75600.00, 'Credit Card', 'Paid', '2026-09-12 09:15:00')
ON DUPLICATE KEY UPDATE
  `subtotal` = VALUES(`subtotal`),
  `net_payable` = VALUES(`net_payable`),
  `paid_amount` = VALUES(`paid_amount`),
  `status` = VALUES(`status`);

INSERT INTO `invoice_items`
  (`item_id`, `invoice_id`, `item_type`, `description`, `unit_price`, `quantity`, `total_price`)
VALUES
  (1, 1, 'Bed Charge',       'ICU Telemetry Bed (3 Nights @ ৳12,000)', 12000.00, 3, 36000.00),
  (2, 1, 'Consultation',     'Critical Care Specialist Dr. Satoru Gojo Consultation', 2000.00, 1, 2000.00),
  (3, 1, 'Diagnostic Test',  'Arterial Blood Gas (ABG) Analysis & CBC Panel', 4000.00, 1, 4000.00),
  (4, 2, 'Bed Charge',       'CCU Ward Bed (2 Nights @ ৳10,000)', 10000.00, 2, 20000.00),
  (5, 2, 'Diagnostic Test',  'Color Doppler 2D Echocardiography', 7000.00, 1, 7000.00),
  (6, 3, 'Consultation',     'Surgical Pre-op Evaluation Dr. Mikasa Ackerman', 1500.00, 1, 1500.00),
  (7, 3, 'Diagnostic Test',  'Whole Abdomen Ultrasound with Liver/Gallbladder Study', 4300.00, 1, 4300.00),
  (8, 3, 'Pharmacy',         'Surgical Preparation & Antibiotic Pack', 2000.00, 1, 2000.00),
  (9, 4, 'Bed Charge',       'VIP Deluxe Suite Cabin (4 Nights @ ৳18,000)', 18000.00, 4, 72000.00)
ON DUPLICATE KEY UPDATE
  `unit_price` = VALUES(`unit_price`),
  `quantity` = VALUES(`quantity`),
  `total_price` = VALUES(`total_price`);

-- ----------------------------------------------------------------------------
-- 7. Diagnostic & Pathology Reports
-- ----------------------------------------------------------------------------
INSERT INTO `diagnostic_reports`
  (`report_id`, `patient_id`, `requested_by_doctor_id`, `test_name`, `test_category`, `report_file_path`, `delivery_status`, `uploaded_at`)
VALUES
  (1, 1,  21, 'Complete Blood Count (CBC) with ESR', 'Pathology',    'uploads/reports/cbc_patient_001.pdf',    'Verified & Ready', '2026-09-12 09:30:00'),
  (2, 1,  29, 'Fasting Blood Glucose (HbA1c)',       'Biochemistry', 'uploads/reports/hba1c_patient_001.pdf',  'Verified & Ready', '2026-09-08 14:15:00'),
  (3, 5,  8,  'High-Resolution 12-Lead ECG & Echo',   'Pathology',    'uploads/reports/ecg_patient_005.pdf',    'Verified & Ready', '2026-09-14 11:00:00'),
  (4, 10, 20, 'Abdominal Contrast-Enhanced CT Scan',  'Radiology',    'uploads/reports/ct_patient_010.pdf',     'Pending Analysis', '2026-09-16 15:45:00'),
  (5, 18, 29, 'Comprehensive Lipid Profile Panel',    'Biochemistry', 'uploads/reports/lipid_patient_018.pdf',  'Verified & Ready', '2026-09-11 10:20:00')
ON DUPLICATE KEY UPDATE
  `test_name` = VALUES(`test_name`),
  `test_category` = VALUES(`test_category`),
  `delivery_status` = VALUES(`delivery_status`);

-- ----------------------------------------------------------------------------
-- 8. Compliance & Security Audit Logs
-- ----------------------------------------------------------------------------
INSERT INTO `audit_logs`
  (`log_id`, `actor_id`, `actor_role`, `action_name`, `target_entity`, `ip_address`, `security_level`, `created_at`)
VALUES
  (1, 6,    'Admin',  'Doctor Credential Verified',                       'Dr. Ayesha Siddiqua (BMDC-A-94120)', '192.168.1.10',  'INFO',     '2026-09-17 01:45:00'),
  (2, NULL, 'System', 'Failed Password Lockout Triggered',                'auth.endpoint (01700000000)',        '103.145.74.22', 'CRITICAL', '2026-09-17 01:30:00'),
  (3, 8,    'Doctor', 'ICU Patient Telemetry Downloaded',                 'Patient #0001 (Agatsuma Zenitsu)',   '192.168.1.42',  'INFO',     '2026-09-17 01:05:00'),
  (4, 15,   'Staff',  'Central Ward Bed Allocation Updated',              'Bed ICU-B01 assigned to ADM-1082',   '192.168.1.58',  'INFO',     '2026-09-17 00:35:00'),
  (5, 21,   'Doctor', 'Prescription Synchronized to EMR',                 'EMR File #10492 (Zenitsu)',          '192.168.1.33',  'INFO',     '2026-09-16 23:20:00'),
  (6, NULL, 'System', 'Repeated Identifier Mismatch on Doctor Portal',    'dr.unknown@medpulse.test',           '185.220.101.5', 'WARNING',  '2026-09-16 22:40:00'),
  (7, NULL, 'System', 'Inpatient Invoice Reconciled & Stored',            'Invoice INV-2026-081 (৳ 44,100)',    '127.0.0.1',     'INFO',     '2026-09-16 20:50:00')
ON DUPLICATE KEY UPDATE
  `action_name` = VALUES(`action_name`),
  `target_entity` = VALUES(`target_entity`),
  `security_level` = VALUES(`security_level`);

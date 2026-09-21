-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 21, 2026 at 07:19 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dbms_lab`
--
CREATE DATABASE IF NOT EXISTS `dbms_lab` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `dbms_lab`;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(11) NOT NULL,
  `gender` varchar(10) NOT NULL,
  `reg_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `gender`, `reg_date`) VALUES
(1, 'Eren Yeager', 'afzalhossain.miraz@gmail.com', '$2y$10$0FMTvTbVTem4dqBTYLXGZ.5cWR1OoNcp1fcDZUVs.WmymxumbeXUW', '01783203317', 'Male', '2026-09-05 17:21:06'),
(2, 'Agatsuma Zenitsu', 'saiful4job@gmail.com', '$2y$10$ru5/.ou7XhA8Ch.5/NaMqeSJ.SAFmUpdbhVnzPGzQEzHz299B2j2W', '01783203318', 'Male', '2026-09-06 18:58:43');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
--
-- Database: `japan_trip`
--
CREATE DATABASE IF NOT EXISTS `japan_trip` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `japan_trip`;
--
-- Database: `medpulse_hms`
--
CREATE DATABASE IF NOT EXISTS `medpulse_hms` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `medpulse_hms`;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `serial_number` int(11) NOT NULL,
  `reason_for_visit` text DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled','In-Consultation') NOT NULL DEFAULT 'Scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `patient_id`, `doctor_id`, `appointment_date`, `appointment_time`, `serial_number`, `reason_for_visit`, `status`, `created_at`) VALUES
(1, 1, 21, '2026-09-18', '10:30:00', 1, 'Routine neurological follow-up and chronic headache evaluation', 'Scheduled', '2026-09-16 20:22:01'),
(2, 5, 14, '2026-09-18', '16:00:00', 2, 'Skin allergy assessment and topical therapy review', 'Scheduled', '2026-09-16 20:22:01'),
(3, 10, 20, '2026-09-17', '11:00:00', 1, 'Pre-operative gallbladder consultation and surgical clearance', 'In-Consultation', '2026-09-16 20:22:01'),
(4, 16, 8, '2026-09-16', '09:30:00', 3, 'Hypertension management and cardiovascular routine screening', 'Completed', '2026-09-16 20:22:01'),
(5, 18, 29, '2026-09-15', '17:30:00', 1, 'Executive annual health and metabolic wellness checkup', 'Completed', '2026-09-16 20:22:01');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` enum('Admin','Doctor','Staff','Patient','System') NOT NULL,
  `action` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'SYSTEM',
  `action_name` varchar(100) NOT NULL,
  `target_entity` varchar(150) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '127.0.0.1',
  `security_level` enum('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'INFO',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `actor_id`, `actor_role`, `action`, `description`, `category`, `action_name`, `target_entity`, `ip_address`, `security_level`, `created_at`) VALUES
(1, 6, 6, 'Admin', 'Doctor Credential Verified', 'Dr. Ayesha Siddiqua credentials approved by Miraz', 'VERIFICATION', 'Doctor Credential Verified', 'Dr. Ayesha Siddiqua (BMDC-A-94120)', '192.168.1.10', 'INFO', '2026-09-17 02:12:00'),
(2, 15, 15, 'Staff', 'Patient Bed Admission', 'Patient registered to Emergency Ward Bed #EMG-101', 'ADMISSION', 'Patient Bed Admission', 'Agatsuma Zenitsu (Bed #EMG-101)', '192.168.1.8', 'INFO', '2026-09-17 02:08:00'),
(3, 21, 21, 'Doctor', 'Medication Batch Dispensed', 'Narcotics locker batch #409 released for ICU cardiac care', 'PHARMACY', 'Medication Batch Dispensed', 'Pharmacy Batch #409 (Morphine)', '192.168.1.22', 'INFO', '2026-09-17 01:55:00'),
(4, NULL, NULL, 'System', 'Failed Password Lockout Triggered', 'Repeated unauthorized login attempts detected on staff portal', 'SECURITY', 'Failed Password Lockout Triggered', 'auth.endpoint (01700000000)', '103.145.74.22', 'CRITICAL', '2026-09-17 01:30:00'),
(5, 15, 15, 'Staff', 'Bed Sanitization Completed', 'Bed #14 sanitized and ready for rapid patient allocation', 'ADMISSION', 'Bed Sanitization Completed', 'Bed #14 (Floor 1 Emergency)', '192.168.1.18', 'INFO', '2026-09-17 01:15:00'),
(6, 6, 6, 'Admin', 'Staff Registration Authorized', 'Nurse Farzana Yasmin credential review completed and access granted', 'VERIFICATION', 'Staff Registration Authorized', 'Staff Candidate #25 (Nurse Farzana)', '192.168.1.10', 'INFO', '2026-09-17 00:40:00'),
(7, NULL, NULL, 'System', 'Automated Pulse Wave Telemetry', 'Cardiac monitoring heartbeat steady at 72 BPM • System Normal', 'SYSTEM', 'Automated Pulse Wave Telemetry', 'ECG Pulse Daemon', '127.0.0.1', 'INFO', '2026-09-16 23:50:00'),
(8, 6, 6, 'Admin', 'Administrative Root Session Opened', 'Executive command center login verified via dual authentication', 'SECURITY', 'Administrative Root Session Opened', 'Admin Miraz Console', '192.168.1.5', 'INFO', '2026-09-16 22:30:00'),
(9, 15, 15, 'Staff', 'Presidential Suite Preparation', 'VIP Suite PRES-401 deep sanitization complete and dignitary ready', 'ADMISSION', 'Presidential Suite Preparation', 'Suite PRES-401 (Floor 4)', '192.168.1.12', 'INFO', '2026-09-16 21:10:00'),
(10, NULL, NULL, 'System', 'Hospital Census Reconciled', '500-bed clinical capacity verified across all 5 clinical floors', 'SYSTEM', 'Hospital Census Reconciled', 'Hospital Beds Inventory (500)', '127.0.0.1', 'INFO', '2026-09-16 20:00:00'),
(11, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Agatsuma Zenitsu (#1) allocated to Bed EMG-102 under Dr. Rafiqul Islam. Notes: Test admission smoke test', 'ADMISSION', 'Bed Allocation', 'EMG-102', '::1', 'INFO', '2026-09-17 08:53:27'),
(12, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-102. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-102', '::1', 'INFO', '2026-09-17 08:53:37'),
(13, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed EMG-102. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'EMG-102', '::1', 'INFO', '2026-09-17 08:53:37'),
(14, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-101. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-101', '::1', 'INFO', '2026-09-17 08:55:38'),
(15, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Kibutsuji Muzan (#28) allocated to Bed EMG-102 under Dr. Afzal Hossain Miraz.', 'ADMISSION', 'Bed Allocation', 'EMG-102', '::1', 'INFO', '2026-09-17 08:57:39'),
(16, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-102. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-102', '::1', 'INFO', '2026-09-17 09:28:13'),
(17, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed EMG-101. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'EMG-101', '::1', 'INFO', '2026-09-17 09:28:17'),
(18, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Robert Downey Jr. (#18) allocated to Bed EMG-101 under Dr. Mikasa Ackerman.', 'ADMISSION', 'Bed Allocation', 'EMG-101', '::1', 'INFO', '2026-09-17 09:28:32'),
(19, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed EMG-102. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'EMG-102', '::1', 'INFO', '2026-09-17 09:40:15'),
(20, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Agatsuma Zenitsu (#1) allocated to Bed EMG-102 under Dr. Minhazul Islam Alvi. Notes: Treat him well; he is fragile', 'ADMISSION', 'Bed Allocation', 'EMG-102', '::1', 'INFO', '2026-09-17 09:40:47'),
(21, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Kibutsuji Muzan (#28) allocated to Bed PRES-401 under Dr. Afzal Hossain Miraz.', 'ADMISSION', 'Bed Allocation', 'PRES-401', '::1', 'INFO', '2026-09-17 10:15:16'),
(22, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Kibutsuji Muzan (#28) allocated to Bed EMG-106 under Dr. Miftahul Sheikh.', 'ADMISSION', 'Bed Allocation', 'EMG-106', '::1', 'INFO', '2026-09-17 10:15:52'),
(23, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-102. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-102', '::1', 'INFO', '2026-09-17 13:16:51'),
(24, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-101. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-101', '::1', 'INFO', '2026-09-17 13:17:01'),
(25, NULL, 6, 'Admin', 'PAYMENT_COLLECTED', 'Collected 20000.00 via Cash | Ref: N/A | Invoice: INV-2026-089 | New due: 0.00', 'SYSTEM', 'PAYMENT_COLLECTED', 'INV-2026-089', '::1', 'INFO', '2026-09-17 14:31:38'),
(26, NULL, 6, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'Payout ৳3000.00 cleared to Dr. Dr. Satoru Gojo | Invoice: INV-2026-089', 'SYSTEM', 'DOCTOR_PAYOUT_DISBURSED', 'ITEM-11', '::1', 'INFO', '2026-09-17 14:32:33'),
(27, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed PRES-401. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'PRES-401', '::1', 'INFO', '2026-09-17 15:47:28'),
(28, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Chris Evans (#38) allocated to Bed EMG-121 under Dr. Afzal Hossain Miraz.', 'ADMISSION', 'Bed Allocation', 'EMG-121', '::1', 'INFO', '2026-09-17 16:12:41'),
(29, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-121. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-121', '::1', 'INFO', '2026-09-17 16:12:54'),
(30, NULL, 44, 'Doctor', 'REGISTRATION', 'Doctor account registered: Dr. Sarah Connor (Critical Care Medicine & Trauma, BMDC-A-83845)', 'SECURITY', 'REGISTRATION', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 17:16:40'),
(31, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Mikasa Ackerman (Senior Consultant, Cardiology & Critical Care)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 17:38:53'),
(32, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Mikasa Ackerman (Professor, Neurology & Neurosurgery)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 17:40:31'),
(33, NULL, 43, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Minhazul Islam Alvi (Senior Consultant, Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 17:43:58'),
(34, NULL, 43, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Minhazul Islam Alvi (Assistant Professor, Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 17:44:08'),
(35, NULL, 43, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Minhazul Islam Alvi (Professor, Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 17:44:20'),
(36, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:06:15'),
(37, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:06:34'),
(38, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Col. (Retd.) Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:06:42'),
(39, NULL, NULL, 'Doctor', 'REGISTRATION', 'Doctor account registered: Dr. Afrina Hossain Riana (Gynecology & Obstetrics, BMDC Reg A- 71245)', 'SECURITY', 'REGISTRATION', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 18:18:30'),
(42, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:22:23'),
(43, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:22:23'),
(44, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Col. (Retd.) Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:22:23'),
(45, NULL, 20, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Col. (Retd.) Prof. Dr. Minhazul Islam Alvi, MBBS, FCPS (OBGYN) (Gynecology & Obstetrics)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:22:23'),
(46, NULL, 47, 'Doctor', 'REGISTRATION', 'Doctor account registered: Dr. Afrina Hossain Riana (Gynecology & Obstetrics, A-71245)', 'SECURITY', 'REGISTRATION', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 18:25:42'),
(47, NULL, 6, 'System', 'DOCTOR_ASSIGNMENT', 'Updated care team for Agatsuma Zenitsu (#1): 1 doctors assigned (Added: 1, Removed: 1).', 'CLINICAL_OPS', 'Doctor Assignment', 'Patient #1', '::1', 'INFO', '2026-09-17 18:29:47'),
(48, NULL, 6, 'System', 'DOCTOR_ASSIGNMENT', 'Updated care team for Nusrat Jahan (#5): 1 doctors assigned (Added: 1, Removed: 1).', 'CLINICAL_OPS', 'Doctor Assignment', 'Patient #5', '::1', 'INFO', '2026-09-17 18:29:58'),
(49, NULL, 48, 'Doctor', 'REGISTRATION', 'Doctor account registered: Dr. Maisa Rahman Tinu (Gynecology & Obstetrics, A-24509)', 'SECURITY', 'REGISTRATION', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 18:34:28'),
(50, NULL, 6, 'System', 'PATIENT_DISCHARGE', 'Patient Agatsuma Zenitsu (#1) discharged from Bed CAB-417 (Deluxe Cabin). Bed released to Available status.', 'CLINICAL_OPS', 'Patient Discharge', 'Patient #1 - Bed CAB-417', '::1', 'INFO', '2026-09-17 18:40:39'),
(51, NULL, 6, 'System', 'PATIENT_DISCHARGE', 'Patient Nusrat Jahan (#5) discharged from Bed EMG-124 (Emergency). Bed released to Available status.', 'CLINICAL_OPS', 'Patient Discharge', 'Patient #5 - Bed EMG-124', '::1', 'INFO', '2026-09-17 18:40:43'),
(52, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed EMG-101. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'EMG-101', '::1', 'INFO', '2026-09-17 18:40:56'),
(53, NULL, 6, 'System', 'BED_TRANSFER', 'Transferred Patient #10 from Bed CAB-401 (Deluxe Cabin) to Bed EMG-101 (Emergency). Reason: ', 'CLINICAL_OPS', 'Bed Transfer', 'Patient #10 -> EMG-101', '::1', 'INFO', '2026-09-17 18:41:10'),
(54, NULL, 6, 'System', 'DOCTOR_ASSIGNMENT', 'Updated care team for Jahid Hasan (#10): 1 doctors assigned (Added: 1, Removed: 1).', 'CLINICAL_OPS', 'Doctor Assignment', 'Patient #10', '::1', 'INFO', '2026-09-17 18:41:16'),
(55, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-101. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-101', '::1', 'INFO', '2026-09-17 18:41:29'),
(56, NULL, NULL, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:42:23'),
(57, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed EMG-101. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'EMG-101', '::1', 'INFO', '2026-09-17 18:43:04'),
(58, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Miraz (#19) allocated to Bed EMG-101 under Dr. Afrina Hossain Riana.', 'ADMISSION', 'Bed Allocation', 'EMG-101', '::1', 'INFO', '2026-09-17 18:43:15'),
(59, NULL, 6, 'Admin', 'PATIENT_DISCHARGE', 'Patient discharged from Bed EMG-101. Bed moved to UV-C Sanitization protocol.', 'ADMISSION', 'Patient Discharge', 'EMG-101', '::1', 'INFO', '2026-09-17 18:43:26'),
(60, NULL, NULL, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:45:08'),
(61, NULL, NULL, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:45:27'),
(62, NULL, NULL, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:46:52'),
(63, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_APPROVED', 'Doctor Dr. Edward Elric credentials verified and approved (BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Credential Approved', 'Doctor #52 (BMDC-A-99123)', '127.0.0.1', 'INFO', '2026-09-17 18:46:52'),
(64, NULL, NULL, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:47:03'),
(65, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_APPROVED', 'Doctor Dr. Edward Elric credentials verified and approved (BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Credential Approved', 'Doctor #53 (BMDC-A-99123)', '127.0.0.1', 'INFO', '2026-09-17 18:47:03'),
(66, NULL, 54, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Roy Mustang (Internal Medicine & Critical Care, BMDC: BMDC-A-88442)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:47:04'),
(67, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_REJECTED', 'Doctor Dr. Roy Mustang registration credentials declined (BMDC: BMDC-A-88442)', 'VERIFICATION', 'Doctor Credential Rejected', 'Doctor #54 (BMDC-A-88442)', '127.0.0.1', 'WARNING', '2026-09-17 18:47:04'),
(68, NULL, NULL, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:47:18'),
(69, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_APPROVED', 'Doctor Dr. Edward Elric credentials verified and approved (BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Credential Approved', 'Doctor #55 (BMDC-A-99123)', '127.0.0.1', 'INFO', '2026-09-17 18:47:18'),
(70, NULL, 56, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Riza Hawkeye (Cardiothoracic Surgery & ICU, BMDC: BMDC-A-77331)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:47:18'),
(71, NULL, 57, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Edward Elric (Traumatology & Bio-Alchemy, BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '127.0.0.1', 'INFO', '2026-09-17 18:47:32'),
(72, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_APPROVED', 'Doctor Dr. Edward Elric credentials verified and approved (BMDC: BMDC-A-99123)', 'VERIFICATION', 'Doctor Credential Approved', 'Doctor #57 (BMDC-A-99123)', '127.0.0.1', 'INFO', '2026-09-17 18:47:32'),
(73, NULL, 58, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor account registered awaiting administrative verification: Dr. Tom Holland (Gynecology & Obstetrics, BMDC: A-24508)', 'VERIFICATION', 'Doctor Registration Submitted', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-17 18:50:38'),
(74, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_REJECTED', 'Doctor Dr. Tom Holland registration credentials declined (BMDC: A-24508)', 'VERIFICATION', 'Doctor Credential Rejected', 'Doctor #58 (A-24508)', '::1', 'WARNING', '2026-09-17 18:50:54'),
(75, NULL, 6, 'Admin', 'DOCTOR_CREDENTIAL_APPROVED', 'Doctor Dr. Riza Hawkeye credentials verified and approved (BMDC: BMDC-A-77331)', 'VERIFICATION', 'Doctor Credential Approved', 'Doctor #56 (BMDC-A-77331)', '::1', 'INFO', '2026-09-17 18:52:50'),
(76, NULL, 6, 'System', 'PATIENT_DISCHARGE_INVOICED', 'Patient Agatsuma Zenitsu discharged from Bed EMG-104 (Emergency). Central invoice INV-2026-0006 generated (Total: ৳15,225.00).', 'CENTRAL_TREASURY', 'Patient Discharge & Billing', 'Invoice INV-2026-0006 (Patient #1)', '127.0.0.1', 'INFO', '2026-09-17 19:07:26'),
(77, NULL, 6, 'Admin', 'PAYMENT_COLLECTED', 'Collected 15225.00 via Cash | Ref: CASH-REC-11068 | Invoice: INV-2026-0006 | New due: 0.00', 'SYSTEM', 'PAYMENT_COLLECTED', 'INV-2026-0006', '127.0.0.1', 'INFO', '2026-09-17 19:07:26'),
(78, NULL, 6, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'Payout ৳2500.00 cleared to Dr. Minhazul Islam Alvi | Invoice: INV-2026-0006', 'SYSTEM', 'DOCTOR_PAYOUT_DISBURSED', 'ITEM-16', '127.0.0.1', 'INFO', '2026-09-17 19:07:26'),
(79, NULL, 6, 'System', 'PATIENT_DISCHARGE_INVOICED', 'Patient Robert Downey Jr. discharged from Bed EMG-106 (Emergency). Central invoice INV-2026-0009 generated (Total: ৳15,225.00).', 'CENTRAL_TREASURY', 'Patient Discharge & Billing', 'Invoice INV-2026-0009 (Patient #18)', '127.0.0.1', 'INFO', '2026-09-17 19:07:49'),
(80, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Agatsuma Zenitsu (#1) allocated to Bed EMG-104 under Dr. Afrina Hossain Riana.', 'ADMISSION', 'Bed Allocation', 'EMG-104', '::1', 'INFO', '2026-09-17 19:12:44'),
(81, NULL, 6, 'System', 'PATIENT_DISCHARGE_INVOICED', 'Patient Agatsuma Zenitsu discharged from Bed EMG-104 (Emergency). Central invoice INV-2026-0010 generated (Total: ৳5,460.00).', 'CENTRAL_TREASURY', 'Patient Discharge & Billing', 'Invoice INV-2026-0010 (Patient #1)', '::1', 'INFO', '2026-09-17 19:13:01'),
(82, NULL, 6, 'Admin', 'PAYMENT_COLLECTED', 'Collected 5460.00 via Cash | Ref: N/A | Invoice: INV-2026-0010 | New due: 0.00', 'SYSTEM', 'PAYMENT_COLLECTED', 'INV-2026-0010', '::1', 'INFO', '2026-09-17 19:13:31'),
(83, NULL, 6, 'Admin', 'PAYMENT_COLLECTED', 'Collected 15225.00 via Cash | Ref: N/A | Invoice: INV-2026-0009 | New due: 0.00', 'SYSTEM', 'PAYMENT_COLLECTED', 'INV-2026-0009', '::1', 'INFO', '2026-09-17 19:16:16'),
(84, NULL, 6, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'Payout ৳1200.00 cleared to Dr. Dr. Afrina Hossain Riana | Invoice: INV-2026-0010', 'SYSTEM', 'DOCTOR_PAYOUT_DISBURSED', 'ITEM-20', '::1', 'INFO', '2026-09-17 19:18:00'),
(85, NULL, 6, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'Payout ৳2500.00 cleared to Dr. Minhazul Islam Alvi | Invoice: INV-2026-0009', 'SYSTEM', 'DOCTOR_PAYOUT_DISBURSED', 'ITEM-18', '::1', 'INFO', '2026-09-17 19:18:01'),
(86, NULL, 43, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Minhazul Islam Alvi, MBBS (Orthopedics & Trauma Surgery)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-18 10:00:18'),
(87, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed PRES-401. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'PRES-401', '::1', 'INFO', '2026-09-18 12:59:56'),
(88, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Kibutsuji Muzan (#28) allocated to Bed PRES-401 under Minhazul Islam Alvi.', 'ADMISSION', 'Bed Allocation', 'PRES-401', '::1', 'INFO', '2026-09-18 13:04:37'),
(89, NULL, 6, 'System', 'PATIENT_DISCHARGE_INVOICED', 'Patient Kibutsuji Muzan discharged from Bed PRES-401 (Presidential Suite). Central invoice INV-2026-0011 generated (Total: ৳53,760.00).', 'CENTRAL_TREASURY', 'Patient Discharge & Billing', 'Invoice INV-2026-0011 (Patient #28)', '::1', 'INFO', '2026-09-18 13:05:22'),
(90, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Agatsuma Zenitsu (#1) allocated to Bed PRES-401 under Minhazul Islam Alvi.', 'ADMISSION', 'Bed Allocation', 'PRES-401', '::1', 'INFO', '2026-09-18 13:05:39'),
(91, NULL, 6, 'System', 'PATIENT_DISCHARGE_INVOICED', 'Patient Agatsuma Zenitsu discharged from Bed PRES-401 (Presidential Suite). Central invoice INV-2026-0012 generated (Total: ৳53,760.00).', 'CENTRAL_TREASURY', 'Patient Discharge & Billing', 'Invoice INV-2026-0012 (Patient #1)', '::1', 'INFO', '2026-09-18 13:06:18'),
(92, NULL, 6, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'Payout ৳1200.00 cleared to Dr. Minhazul Islam Alvi | Invoice: INV-2026-0012', 'SYSTEM', 'DOCTOR_PAYOUT_DISBURSED', 'ITEM-24', '::1', 'INFO', '2026-09-18 13:06:38'),
(93, NULL, 6, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'Payout ৳1200.00 cleared to Dr. Minhazul Islam Alvi | Invoice: INV-2026-0011', 'SYSTEM', 'DOCTOR_PAYOUT_DISBURSED', 'ITEM-22', '::1', 'INFO', '2026-09-18 13:06:40'),
(94, NULL, 47, 'Doctor', 'PROFILE_UPDATE', 'Updated clinical profile: Dr. Afrina Hossain Riana, MBBS (Neurology & Neurosurgery)', 'SECURITY', 'PROFILE_UPDATE', 'DOCTOR_PORTAL', '::1', 'INFO', '2026-09-18 14:08:09'),
(95, NULL, 6, 'Admin', 'PAYMENT_COLLECTED', 'Collected 53760.00 via Cash | Ref: N/A | Invoice: INV-2026-0012 | New due: 0.00', 'SYSTEM', 'PAYMENT_COLLECTED', 'INV-2026-0012', '::1', 'INFO', '2026-09-19 05:42:35'),
(96, NULL, 6, 'Admin', 'BED_SANITIZED', 'Sanitization protocol cleared for Bed EMG-101. Ready for intake.', 'SYSTEM', 'Bed Sanitized', 'EMG-101', '::1', 'INFO', '2026-09-20 17:12:40'),
(97, NULL, 6, 'Admin', 'BED_ALLOCATION', 'Patient Agatsuma Zenitsu (#1) allocated to Bed EMG-101 under Afrina Hossain Riana.', 'ADMISSION', 'Bed Allocation', 'EMG-101', '::1', 'INFO', '2026-09-20 17:12:52');

-- --------------------------------------------------------

--
-- Table structure for table `bed_allocations`
--

CREATE TABLE `bed_allocations` (
  `allocation_id` int(11) NOT NULL,
  `bed_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `attending_doctor_id` int(11) DEFAULT NULL,
  `admitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `discharged_at` datetime DEFAULT NULL,
  `status` enum('Active','Discharged','Transferred') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active_patient_id` int(11) GENERATED ALWAYS AS (if(`status` = 'Active',`patient_id`,NULL)) VIRTUAL,
  `active_bed_id` int(11) GENERATED ALWAYS AS (if(`status` = 'Active',`bed_id`,NULL)) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bed_allocations`
--

INSERT INTO `bed_allocations` (`allocation_id`, `bed_id`, `patient_id`, `attending_doctor_id`, `admitted_at`, `discharged_at`, `status`, `created_at`) VALUES
(1, 1, 1, 8, '2026-09-11 16:00:00', '2026-09-17 14:55:38', 'Discharged', '2026-09-16 20:22:01'),
(2, 41, 5, 21, '2026-09-12 11:30:00', '2026-09-17 17:03:41', 'Transferred', '2026-09-16 20:22:01'),
(3, 301, 10, 20, '2026-09-13 09:15:00', '2026-09-18 00:41:10', 'Transferred', '2026-09-16 20:22:01'),
(4, 381, 18, 29, '2026-09-10 14:00:00', '2026-09-17 16:50:20', 'Transferred', '2026-09-16 20:22:01'),
(5, 2, 1, 8, '2026-09-17 14:53:27', '2026-09-17 14:53:37', 'Discharged', '2026-09-17 08:53:27'),
(6, 2, 28, 29, '2026-09-17 14:57:39', '2026-09-17 15:28:13', 'Discharged', '2026-09-17 08:57:39'),
(7, 1, 18, 20, '2026-09-17 15:28:32', '2026-09-17 16:55:51', 'Transferred', '2026-09-17 09:28:32'),
(8, 2, 1, 43, '2026-09-17 15:40:47', '2026-09-17 17:36:07', 'Transferred', '2026-09-17 09:40:47'),
(9, 380, 28, 29, '2026-09-17 16:15:16', '2026-09-17 16:50:20', 'Transferred', '2026-09-17 10:15:16'),
(10, 6, 28, 13, '2026-09-17 16:15:52', '2026-09-17 17:32:10', 'Transferred', '2026-09-17 10:15:52'),
(19, 4, 18, 20, '2026-09-17 16:55:51', '2026-09-17 16:56:04', 'Transferred', '2026-09-17 10:55:51'),
(22, 1, 18, 20, '2026-09-17 16:56:04', '2026-09-17 16:56:04', 'Discharged', '2026-09-17 10:56:04'),
(23, 1, 18, 20, '2026-09-17 16:56:08', '2026-09-17 16:59:29', 'Transferred', '2026-09-17 10:56:08'),
(24, 380, 18, 43, '2026-09-17 16:56:08', '2026-09-17 18:25:34', 'Transferred', '2026-09-17 10:59:29'),
(28, 24, 5, 47, '2026-09-12 11:30:00', '2026-09-18 00:40:43', 'Discharged', '2026-09-17 11:03:41'),
(29, 1, 16, NULL, '2026-09-17 17:14:47', '2026-09-17 17:26:07', 'Transferred', '2026-09-17 11:14:47'),
(30, 275, 16, NULL, '2026-09-17 17:14:47', '2026-09-17 17:34:22', 'Transferred', '2026-09-17 11:26:07'),
(31, 30, 28, 13, '2026-09-17 16:15:52', '2026-09-17 17:49:41', 'Transferred', '2026-09-17 11:32:10'),
(32, 31, 16, 8, '2026-09-17 17:14:47', '2026-09-17 17:49:49', 'Transferred', '2026-09-17 11:34:22'),
(33, 277, 1, 43, '2026-09-17 15:40:47', '2026-09-17 17:59:03', 'Transferred', '2026-09-17 11:36:07'),
(34, 281, 28, 13, '2026-09-17 16:15:52', '2026-09-17 18:25:47', 'Transferred', '2026-09-17 11:49:41'),
(35, 63, 16, 8, '2026-09-17 17:14:47', '2026-09-17 17:58:17', 'Transferred', '2026-09-17 11:49:49'),
(36, 23, 16, 8, '2026-09-17 17:14:47', '2026-09-17 17:58:29', 'Transferred', '2026-09-17 11:58:17'),
(37, 65, 16, 20, '2026-09-17 17:14:47', '2026-09-17 18:27:42', 'Transferred', '2026-09-17 11:58:29'),
(38, 317, 1, 47, '2026-09-17 15:40:47', '2026-09-18 00:40:39', 'Discharged', '2026-09-17 11:59:03'),
(39, 68, 18, 14, '2026-09-17 16:56:08', '2026-09-17 18:49:13', 'Transferred', '2026-09-17 12:25:34'),
(40, 380, 28, 21, '2026-09-17 16:15:52', '2026-09-17 21:47:28', 'Discharged', '2026-09-17 12:25:47'),
(41, 1, 16, 29, '2026-09-17 17:14:47', '2026-09-17 19:17:01', 'Discharged', '2026-09-17 12:27:42'),
(42, 2, 18, 14, '2026-09-17 16:56:08', '2026-09-17 19:16:51', 'Discharged', '2026-09-17 12:49:13'),
(43, 21, 38, 29, '2026-09-17 22:12:41', '2026-09-17 22:12:54', 'Discharged', '2026-09-17 16:12:41'),
(44, 1, 10, 47, '2026-09-13 09:15:00', '2026-09-18 00:41:29', 'Discharged', '2026-09-17 18:41:10'),
(45, 1, 19, 47, '2026-09-18 00:43:15', '2026-09-18 00:43:26', 'Discharged', '2026-09-17 18:43:15'),
(46, 4, 1, 20, '2026-09-15 20:06:04', NULL, 'Discharged', '2026-09-17 19:06:04'),
(47, 4, 1, 20, '2026-09-15 20:06:26', NULL, 'Discharged', '2026-09-17 19:06:26'),
(48, 4, 1, 20, '2026-09-15 20:06:40', NULL, 'Discharged', '2026-09-17 19:06:40'),
(49, 4, 1, 20, '2026-09-15 20:06:59', NULL, 'Discharged', '2026-09-17 19:06:59'),
(50, 4, 1, 20, '2026-09-15 20:07:13', NULL, 'Discharged', '2026-09-17 19:07:13'),
(51, 4, 1, 20, '2026-09-15 20:07:26', '2026-09-18 01:07:26', 'Discharged', '2026-09-17 19:07:26'),
(52, 6, 18, 20, '2026-09-15 15:07:49', '2026-09-18 01:07:49', 'Discharged', '2026-09-17 19:07:49'),
(53, 4, 1, 47, '2026-09-18 01:12:44', '2026-09-18 01:13:01', 'Discharged', '2026-09-17 19:12:44'),
(54, 380, 28, 43, '2026-09-18 19:04:37', '2026-09-18 19:05:22', 'Discharged', '2026-09-18 13:04:37'),
(55, 380, 1, 43, '2026-09-18 19:05:39', '2026-09-18 19:06:18', 'Discharged', '2026-09-18 13:05:39'),
(56, 1, 1, 47, '2026-09-20 23:12:51', NULL, 'Active', '2026-09-20 17:12:51');

-- --------------------------------------------------------

--
-- Table structure for table `bed_transfer_history`
--

CREATE TABLE `bed_transfer_history` (
  `transfer_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `from_bed_id` int(11) NOT NULL,
  `to_bed_id` int(11) NOT NULL,
  `transferred_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `transferred_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bed_transfer_history`
--

INSERT INTO `bed_transfer_history` (`transfer_id`, `patient_id`, `from_bed_id`, `to_bed_id`, `transferred_by`, `reason`, `transferred_at`) VALUES
(2, 18, 1, 4, 1, 'Automated Test Relocation', '2026-09-17 16:55:51'),
(3, 18, 4, 1, 1, 'Automated Test Relocation', '2026-09-17 16:56:04'),
(4, 18, 1, 380, 6, '', '2026-09-17 16:59:29'),
(5, 5, 41, 24, 6, '', '2026-09-17 17:03:41'),
(6, 16, 1, 275, 6, '', '2026-09-17 17:26:07'),
(7, 28, 6, 30, 6, '', '2026-09-17 17:32:10'),
(8, 16, 275, 273, 6, '', '2026-09-17 17:34:22'),
(9, 1, 2, 277, 6, '', '2026-09-17 17:36:07'),
(10, 16, 273, 68, 6, '', '2026-09-17 17:45:57'),
(11, 16, 68, 31, 6, '', '2026-09-17 17:46:05'),
(12, 28, 30, 281, 6, '', '2026-09-17 17:49:41'),
(13, 16, 31, 63, 6, '', '2026-09-17 17:49:49'),
(14, 16, 63, 23, 6, '', '2026-09-17 17:58:17'),
(15, 16, 23, 65, 6, '', '2026-09-17 17:58:29'),
(16, 1, 277, 317, 6, '', '2026-09-17 17:59:03'),
(17, 18, 380, 68, 6, '', '2026-09-17 18:25:34'),
(18, 28, 281, 380, 6, '', '2026-09-17 18:25:47'),
(19, 16, 65, 1, 6, '', '2026-09-17 18:27:42'),
(20, 18, 68, 2, 6, '', '2026-09-17 18:49:13'),
(21, 10, 301, 1, 6, '', '2026-09-18 00:41:10');

-- --------------------------------------------------------

--
-- Table structure for table `diagnostic_reports`
--

CREATE TABLE `diagnostic_reports` (
  `report_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `requested_by_doctor_id` int(11) DEFAULT NULL,
  `test_name` varchar(150) NOT NULL,
  `test_category` enum('Pathology','Radiology','Biochemistry') NOT NULL,
  `report_file_path` varchar(255) DEFAULT NULL,
  `delivery_status` enum('Pending Analysis','Verified & Ready') NOT NULL DEFAULT 'Pending Analysis',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `diagnostic_reports`
--

INSERT INTO `diagnostic_reports` (`report_id`, `patient_id`, `requested_by_doctor_id`, `test_name`, `test_category`, `report_file_path`, `delivery_status`, `uploaded_at`) VALUES
(1, 1, 21, 'Complete Blood Count (CBC) with ESR', 'Pathology', 'uploads/reports/cbc_patient_001.pdf', 'Verified & Ready', '2026-09-12 03:30:00'),
(2, 1, 29, 'Fasting Blood Glucose (HbA1c)', 'Biochemistry', 'uploads/reports/hba1c_patient_001.pdf', 'Verified & Ready', '2026-09-08 08:15:00'),
(3, 5, 8, 'High-Resolution 12-Lead ECG & Echo', 'Pathology', 'uploads/reports/ecg_patient_005.pdf', 'Verified & Ready', '2026-09-14 05:00:00'),
(4, 10, 20, 'Abdominal Contrast-Enhanced CT Scan', 'Radiology', 'uploads/reports/ct_patient_010.pdf', 'Pending Analysis', '2026-09-16 09:45:00'),
(5, 18, 29, 'Comprehensive Lipid Profile Panel', 'Biochemistry', 'uploads/reports/lipid_patient_018.pdf', 'Verified & Ready', '2026-09-11 04:20:00');

-- --------------------------------------------------------

--
-- Table structure for table `doctor_earnings`
--

CREATE TABLE `doctor_earnings` (
  `earning_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `admission_id` int(11) DEFAULT NULL,
  `patient_name` varchar(150) NOT NULL,
  `consultation_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `disbursement_status` enum('pending_hospital_collection','available_for_disbursement','disbursed') NOT NULL DEFAULT 'pending_hospital_collection',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doctor_earnings`
--

INSERT INTO `doctor_earnings` (`earning_id`, `doctor_id`, `invoice_id`, `admission_id`, `patient_name`, `consultation_fee`, `disbursement_status`, `created_at`, `updated_at`) VALUES
(1, 21, 1, 1, 'Agatsuma Zenitsu', 2000.00, 'disbursed', '2026-09-16 20:22:01', '2026-09-17 18:58:45'),
(2, 20, 3, 3, 'Jahid Hasan', 1500.00, 'disbursed', '2026-09-16 20:22:01', '2026-09-17 18:58:45'),
(3, 21, 5, 40, 'Kibutsuji Muzan', 3000.00, 'disbursed', '2026-09-17 13:36:01', '2026-09-17 18:58:45'),
(4, 20, 8, 51, 'Agatsuma Zenitsu', 2500.00, 'disbursed', '2026-09-17 19:07:26', '2026-09-17 19:07:26'),
(5, 20, 9, 52, 'Robert Downey Jr.', 2500.00, 'disbursed', '2026-09-17 19:07:49', '2026-09-17 19:18:01'),
(6, 47, 10, 53, 'Agatsuma Zenitsu', 1200.00, 'disbursed', '2026-09-17 19:13:01', '2026-09-17 19:18:00'),
(7, 43, 11, 54, 'Kibutsuji Muzan', 1200.00, 'disbursed', '2026-09-18 13:05:22', '2026-09-18 13:06:40'),
(8, 43, 12, 55, 'Agatsuma Zenitsu', 1200.00, 'disbursed', '2026-09-18 13:06:18', '2026-09-18 13:06:38');

-- --------------------------------------------------------

--
-- Table structure for table `doctor_profiles`
--

CREATE TABLE `doctor_profiles` (
  `doctor_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `specialty` varchar(100) NOT NULL,
  `designation` varchar(100) NOT NULL DEFAULT 'Consultant',
  `qualifications` varchar(255) DEFAULT 'MBBS',
  `military_rank` varchar(64) DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `bmdc_license_number` varchar(50) NOT NULL,
  `bmdc_reg_number` varchar(50) DEFAULT NULL,
  `consultation_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `room_number` varchar(20) NOT NULL,
  `shift_schedule` varchar(100) DEFAULT NULL,
  `available_days` varchar(100) NOT NULL,
  `shift_timings` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doctor_profiles`
--

INSERT INTO `doctor_profiles` (`doctor_id`, `user_id`, `specialty`, `designation`, `qualifications`, `military_rank`, `approval_status`, `approved_by`, `approved_at`, `bmdc_license_number`, `bmdc_reg_number`, `consultation_fee`, `room_number`, `shift_schedule`, `available_days`, `shift_timings`, `created_at`, `updated_at`) VALUES
(1, 8, 'Cardiology & Intensive Care', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-48291', 'BMDC-A-48291', 1200.00, 'Room 304', '09:00 AM - 02:00 PM', 'Mon, Wed, Fri', '09:00 AM - 02:00 PM', '2026-09-16 20:22:01', '2026-09-17 18:36:29'),
(2, 13, 'Orthopedic Surgery & Traumatology', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-51042', 'BMDC-A-51042', 1000.00, 'Room 210', '10:00 AM - 03:00 PM', 'Sun, Tue, Thu', '10:00 AM - 03:00 PM', '2026-09-16 20:22:01', '2026-09-17 18:36:29'),
(3, 14, 'Dermatology & Skin Aesthetics', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-62914', 'BMDC-A-62914', 800.00, 'Room 105', '04:00 PM - 08:00 PM', 'Mon, Tue, Thu, Sat', '04:00 PM - 08:00 PM', '2026-09-16 20:22:01', '2026-09-17 18:36:29'),
(4, 20, 'Gynecology & Obstetrics', 'Professor', 'MBBS, FCPS (OBGYN)', 'Col. (Retd.)', 'approved', NULL, NULL, 'BMDC-A-73820', 'BMDC-A-73820', 2500.00, 'Room-402', 'Sat - Thu: 04:00 PM - 09:00 PM', 'Daily (Emergency Call)', 'Sat - Thu: 04:00 PM - 09:00 PM', '2026-09-16 20:22:01', '2026-09-17 18:36:29'),
(5, 21, 'Neurology & Critical Neurosurgery', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-84912', 'BMDC-A-84912', 2000.00, 'Room 501', '10:30 AM - 05:00 PM', 'Mon, Wed, Thu, Sat', '10:30 AM - 05:00 PM', '2026-09-16 20:22:01', '2026-09-17 18:36:29'),
(6, 29, 'Internal Medicine & Diabetology', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-95018', 'BMDC-A-95018', 1000.00, 'Room 202', '05:00 PM - 09:30 PM', 'Sun, Mon, Wed, Thu', '05:00 PM - 09:30 PM', '2026-09-16 20:22:01', '2026-09-17 18:36:29'),
(20, 11, 'General Surgery & Critical Care', 'Consultant', 'MBBS', NULL, 'rejected', NULL, NULL, 'BMDC-A-69675', 'BMDC-A-69675', 1200.00, 'Room-214', '09:00 AM - 05:00 PM', 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 17:12:33', '2026-09-17 18:36:29'),
(21, 33, 'General Surgery & Critical Care', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-28395', 'BMDC-A-28395', 1200.00, 'Room-335', '09:00 AM - 05:00 PM', 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 17:12:33', '2026-09-17 18:36:29'),
(22, 35, 'General Surgery & Critical Care', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-82432', 'BMDC-A-82432', 1200.00, 'Room-232', '09:00 AM - 05:00 PM', 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 17:12:33', '2026-09-17 18:36:29'),
(23, 43, 'Orthopedics & Trauma Surgery', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-80668', 'BMDC-A-80668', 1200.00, 'Room-202', '09:00 AM - 05:00 PM', 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 17:12:33', '2026-09-18 10:00:18'),
(27, 44, 'Critical Care Medicine & Trauma', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'BMDC-A-83845', 'BMDC-A-83845', 1500.00, 'Room-475', '09:00 AM - 05:00 PM', 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 17:16:40', '2026-09-17 18:36:29'),
(29, 47, 'Neurology & Neurosurgery', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'A-71245', 'A-71245', 1200.00, 'Room-348', '09:00 AM - 05:00 PM', 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 18:25:42', '2026-09-18 14:08:09'),
(30, 48, 'Gynecology & Obstetrics', 'Consultant', 'MBBS', NULL, 'approved', NULL, NULL, 'A-24509', 'A-24509', 1200.00, 'Room-241', NULL, 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 18:34:28', '2026-09-17 18:36:29'),
(36, 54, 'Internal Medicine & Critical Care', 'Consultant', 'MBBS', NULL, 'rejected', 6, '2026-09-18 00:47:04', 'BMDC-A-88442', 'BMDC-A-88442', 1200.00, 'Room-508', NULL, 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 18:47:04', '2026-09-17 18:47:04'),
(38, 56, 'Cardiothoracic Surgery & ICU', 'Consultant', 'MBBS', NULL, 'approved', 6, '2026-09-18 00:52:50', 'BMDC-A-77331', 'BMDC-A-77331', 1200.00, 'Room-414', NULL, 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 18:47:18', '2026-09-17 18:52:50'),
(39, 57, 'Traumatology & Bio-Alchemy', 'Consultant', 'MBBS', NULL, 'approved', 6, '2026-09-18 00:47:32', 'BMDC-A-99123', 'BMDC-A-99123', 1200.00, 'Room-401', NULL, 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 18:47:32', '2026-09-17 18:47:32'),
(40, 58, 'Gynecology & Obstetrics', 'Consultant', 'MBBS', NULL, 'rejected', 6, '2026-09-18 00:50:54', 'A-24508', 'A-24508', 1200.00, 'Room-436', NULL, 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM', '2026-09-17 18:50:38', '2026-09-17 18:50:54');

-- --------------------------------------------------------

--
-- Table structure for table `hospital_beds`
--

CREATE TABLE `hospital_beds` (
  `bed_id` int(11) NOT NULL,
  `bed_number` varchar(30) NOT NULL,
  `ward_type` enum('Emergency','General Ward Male','General Ward Female','Pediatrics','Semi-Cabin','Deluxe Cabin','VIP Suite','Presidential Suite','ICU','CCU','NICU','Recovery') NOT NULL,
  `floor_number` int(11) NOT NULL DEFAULT 1,
  `daily_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Available','Occupied','Maintenance','Reserved') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `hospital_beds`
--

INSERT INTO `hospital_beds` (`bed_id`, `bed_number`, `ward_type`, `floor_number`, `daily_rate`, `status`, `created_at`) VALUES
(1, 'EMG-101', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(2, 'EMG-102', 'Emergency', 1, 4000.00, 'Maintenance', '2026-09-17 08:01:44'),
(3, 'EMG-103', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(4, 'EMG-104', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(5, 'EMG-105', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(6, 'EMG-106', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(7, 'EMG-107', 'Emergency', 1, 4000.00, 'Maintenance', '2026-09-17 08:01:44'),
(8, 'EMG-108', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(9, 'EMG-109', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(10, 'EMG-110', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(11, 'EMG-111', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(12, 'EMG-112', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(13, 'EMG-113', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(14, 'EMG-114', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(15, 'EMG-115', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(16, 'EMG-116', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(17, 'EMG-117', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(18, 'EMG-118', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(19, 'EMG-119', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(20, 'EMG-120', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(21, 'EMG-121', 'Emergency', 1, 4000.00, 'Maintenance', '2026-09-17 08:01:44'),
(22, 'EMG-122', 'Emergency', 1, 4000.00, 'Maintenance', '2026-09-17 08:01:44'),
(23, 'EMG-123', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(24, 'EMG-124', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(25, 'EMG-125', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(26, 'EMG-126', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(27, 'EMG-127', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(28, 'EMG-128', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(29, 'EMG-129', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(30, 'EMG-130', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(31, 'EMG-131', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(32, 'EMG-132', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(33, 'EMG-133', 'Emergency', 1, 4000.00, 'Occupied', '2026-09-17 08:01:44'),
(34, 'EMG-134', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(35, 'EMG-135', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(36, 'EMG-136', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(37, 'EMG-137', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(38, 'EMG-138', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(39, 'EMG-139', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(40, 'EMG-140', 'Emergency', 1, 4000.00, 'Available', '2026-09-17 08:01:44'),
(41, 'GWM-201', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(42, 'GWM-202', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(43, 'GWM-203', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(44, 'GWM-204', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(45, 'GWM-205', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(46, 'GWM-206', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(47, 'GWM-207', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(48, 'GWM-208', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(49, 'GWM-209', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(50, 'GWM-210', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(51, 'GWM-211', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(52, 'GWM-212', 'General Ward Male', 2, 2500.00, 'Maintenance', '2026-09-17 08:01:44'),
(53, 'GWM-213', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(54, 'GWM-214', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(55, 'GWM-215', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(56, 'GWM-216', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(57, 'GWM-217', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(58, 'GWM-218', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(59, 'GWM-219', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(60, 'GWM-220', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(61, 'GWM-221', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(62, 'GWM-222', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(63, 'GWM-223', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(64, 'GWM-224', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(65, 'GWM-225', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(66, 'GWM-226', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(67, 'GWM-227', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(68, 'GWM-228', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(69, 'GWM-229', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(70, 'GWM-230', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(71, 'GWM-231', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(72, 'GWM-232', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(73, 'GWM-233', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(74, 'GWM-234', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(75, 'GWM-235', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(76, 'GWM-236', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(77, 'GWM-237', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(78, 'GWM-238', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(79, 'GWM-239', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(80, 'GWM-240', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(81, 'GWM-241', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(82, 'GWM-242', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(83, 'GWM-243', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(84, 'GWM-244', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(85, 'GWM-245', 'General Ward Male', 2, 2500.00, 'Maintenance', '2026-09-17 08:01:44'),
(86, 'GWM-246', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(87, 'GWM-247', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(88, 'GWM-248', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(89, 'GWM-249', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(90, 'GWM-250', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(91, 'GWM-251', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(92, 'GWM-252', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(93, 'GWM-253', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(94, 'GWM-254', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(95, 'GWM-255', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(96, 'GWM-256', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(97, 'GWM-257', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(98, 'GWM-258', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(99, 'GWM-259', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(100, 'GWM-260', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(101, 'GWM-261', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(102, 'GWM-262', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(103, 'GWM-263', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(104, 'GWM-264', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(105, 'GWM-265', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(106, 'GWM-266', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(107, 'GWM-267', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(108, 'GWM-268', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(109, 'GWM-269', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(110, 'GWM-270', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(111, 'GWM-271', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(112, 'GWM-272', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(113, 'GWM-273', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(114, 'GWM-274', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(115, 'GWM-275', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(116, 'GWM-276', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(117, 'GWM-277', 'General Ward Male', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(118, 'GWM-278', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(119, 'GWM-279', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(120, 'GWM-280', 'General Ward Male', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(121, 'GWF-201', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(122, 'GWF-202', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(123, 'GWF-203', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(124, 'GWF-204', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(125, 'GWF-205', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(126, 'GWF-206', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(127, 'GWF-207', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(128, 'GWF-208', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(129, 'GWF-209', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(130, 'GWF-210', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(131, 'GWF-211', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(132, 'GWF-212', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(133, 'GWF-213', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(134, 'GWF-214', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(135, 'GWF-215', 'General Ward Female', 2, 2500.00, 'Maintenance', '2026-09-17 08:01:44'),
(136, 'GWF-216', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(137, 'GWF-217', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(138, 'GWF-218', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(139, 'GWF-219', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(140, 'GWF-220', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(141, 'GWF-221', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(142, 'GWF-222', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(143, 'GWF-223', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(144, 'GWF-224', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(145, 'GWF-225', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(146, 'GWF-226', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(147, 'GWF-227', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(148, 'GWF-228', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(149, 'GWF-229', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(150, 'GWF-230', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(151, 'GWF-231', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(152, 'GWF-232', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(153, 'GWF-233', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(154, 'GWF-234', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(155, 'GWF-235', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(156, 'GWF-236', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(157, 'GWF-237', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(158, 'GWF-238', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(159, 'GWF-239', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(160, 'GWF-240', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(161, 'GWF-241', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(162, 'GWF-242', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(163, 'GWF-243', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(164, 'GWF-244', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(165, 'GWF-245', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(166, 'GWF-246', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(167, 'GWF-247', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(168, 'GWF-248', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(169, 'GWF-249', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(170, 'GWF-250', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(171, 'GWF-251', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(172, 'GWF-252', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(173, 'GWF-253', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(174, 'GWF-254', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(175, 'GWF-255', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(176, 'GWF-256', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(177, 'GWF-257', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(178, 'GWF-258', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(179, 'GWF-259', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(180, 'GWF-260', 'General Ward Female', 2, 2500.00, 'Maintenance', '2026-09-17 08:01:44'),
(181, 'GWF-261', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(182, 'GWF-262', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(183, 'GWF-263', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(184, 'GWF-264', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(185, 'GWF-265', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(186, 'GWF-266', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(187, 'GWF-267', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(188, 'GWF-268', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(189, 'GWF-269', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(190, 'GWF-270', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(191, 'GWF-271', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(192, 'GWF-272', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(193, 'GWF-273', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(194, 'GWF-274', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(195, 'GWF-275', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(196, 'GWF-276', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(197, 'GWF-277', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(198, 'GWF-278', 'General Ward Female', 2, 2500.00, 'Occupied', '2026-09-17 08:01:44'),
(199, 'GWF-279', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(200, 'GWF-280', 'General Ward Female', 2, 2500.00, 'Available', '2026-09-17 08:01:44'),
(201, 'PED-301', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(202, 'PED-302', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(203, 'PED-303', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(204, 'PED-304', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(205, 'PED-305', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(206, 'PED-306', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(207, 'PED-307', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(208, 'PED-308', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(209, 'PED-309', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(210, 'PED-310', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(211, 'PED-311', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(212, 'PED-312', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(213, 'PED-313', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(214, 'PED-314', 'Pediatrics', 3, 3500.00, 'Maintenance', '2026-09-17 08:01:44'),
(215, 'PED-315', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(216, 'PED-316', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(217, 'PED-317', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(218, 'PED-318', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(219, 'PED-319', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(220, 'PED-320', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(221, 'PED-321', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(222, 'PED-322', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(223, 'PED-323', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(224, 'PED-324', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(225, 'PED-325', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(226, 'PED-326', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(227, 'PED-327', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(228, 'PED-328', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(229, 'PED-329', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(230, 'PED-330', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(231, 'PED-331', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(232, 'PED-332', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(233, 'PED-333', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(234, 'PED-334', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(235, 'PED-335', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(236, 'PED-336', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(237, 'PED-337', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(238, 'PED-338', 'Pediatrics', 3, 3500.00, 'Maintenance', '2026-09-17 08:01:44'),
(239, 'PED-339', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(240, 'PED-340', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(241, 'PED-341', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(242, 'PED-342', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(243, 'PED-343', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(244, 'PED-344', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(245, 'PED-345', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(246, 'PED-346', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(247, 'PED-347', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(248, 'PED-348', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(249, 'PED-349', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(250, 'PED-350', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(251, 'PED-351', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(252, 'PED-352', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(253, 'PED-353', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(254, 'PED-354', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(255, 'PED-355', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(256, 'PED-356', 'Pediatrics', 3, 3500.00, 'Occupied', '2026-09-17 08:01:44'),
(257, 'PED-357', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(258, 'PED-358', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(259, 'PED-359', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(260, 'PED-360', 'Pediatrics', 3, 3500.00, 'Available', '2026-09-17 08:01:44'),
(261, 'SC-301', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(262, 'SC-302', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(263, 'SC-303', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(264, 'SC-304', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(265, 'SC-305', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(266, 'SC-306', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(267, 'SC-307', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(268, 'SC-308', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(269, 'SC-309', 'Semi-Cabin', 3, 6000.00, 'Maintenance', '2026-09-17 08:01:44'),
(270, 'SC-310', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(271, 'SC-311', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(272, 'SC-312', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(273, 'SC-313', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(274, 'SC-314', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(275, 'SC-315', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(276, 'SC-316', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(277, 'SC-317', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(278, 'SC-318', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(279, 'SC-319', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(280, 'SC-320', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(281, 'SC-321', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(282, 'SC-322', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(283, 'SC-323', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(284, 'SC-324', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(285, 'SC-325', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(286, 'SC-326', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(287, 'SC-327', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(288, 'SC-328', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(289, 'SC-329', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(290, 'SC-330', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(291, 'SC-331', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(292, 'SC-332', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(293, 'SC-333', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(294, 'SC-334', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(295, 'SC-335', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(296, 'SC-336', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(297, 'SC-337', 'Semi-Cabin', 3, 6000.00, 'Occupied', '2026-09-17 08:01:44'),
(298, 'SC-338', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(299, 'SC-339', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(300, 'SC-340', 'Semi-Cabin', 3, 6000.00, 'Available', '2026-09-17 08:01:44'),
(301, 'CAB-401', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(302, 'CAB-402', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(303, 'CAB-403', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(304, 'CAB-404', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(305, 'CAB-405', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(306, 'CAB-406', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(307, 'CAB-407', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(308, 'CAB-408', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(309, 'CAB-409', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(310, 'CAB-410', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(311, 'CAB-411', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(312, 'CAB-412', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(313, 'CAB-413', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(314, 'CAB-414', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(315, 'CAB-415', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(316, 'CAB-416', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(317, 'CAB-417', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(318, 'CAB-418', 'Deluxe Cabin', 4, 10000.00, 'Maintenance', '2026-09-17 08:01:44'),
(319, 'CAB-419', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(320, 'CAB-420', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(321, 'CAB-421', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(322, 'CAB-422', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(323, 'CAB-423', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(324, 'CAB-424', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(325, 'CAB-425', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(326, 'CAB-426', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(327, 'CAB-427', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(328, 'CAB-428', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(329, 'CAB-429', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(330, 'CAB-430', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(331, 'CAB-431', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(332, 'CAB-432', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(333, 'CAB-433', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(334, 'CAB-434', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(335, 'CAB-435', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(336, 'CAB-436', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(337, 'CAB-437', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(338, 'CAB-438', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(339, 'CAB-439', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(340, 'CAB-440', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(341, 'CAB-441', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(342, 'CAB-442', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(343, 'CAB-443', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(344, 'CAB-444', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(345, 'CAB-445', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(346, 'CAB-446', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(347, 'CAB-447', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(348, 'CAB-448', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(349, 'CAB-449', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(350, 'CAB-450', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(351, 'CAB-451', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(352, 'CAB-452', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(353, 'CAB-453', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(354, 'CAB-454', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(355, 'CAB-455', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(356, 'CAB-456', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(357, 'CAB-457', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(358, 'CAB-458', 'Deluxe Cabin', 4, 10000.00, 'Occupied', '2026-09-17 08:01:44'),
(359, 'CAB-459', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(360, 'CAB-460', 'Deluxe Cabin', 4, 10000.00, 'Available', '2026-09-17 08:01:44'),
(361, 'VIP-401', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(362, 'VIP-402', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(363, 'VIP-403', 'VIP Suite', 4, 20000.00, 'Occupied', '2026-09-17 08:01:44'),
(364, 'VIP-404', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(365, 'VIP-405', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(366, 'VIP-406', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(367, 'VIP-407', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(368, 'VIP-408', 'VIP Suite', 4, 20000.00, 'Occupied', '2026-09-17 08:01:44'),
(369, 'VIP-409', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(370, 'VIP-410', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(371, 'VIP-411', 'VIP Suite', 4, 20000.00, 'Maintenance', '2026-09-17 08:01:44'),
(372, 'VIP-412', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(373, 'VIP-413', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(374, 'VIP-414', 'VIP Suite', 4, 20000.00, 'Occupied', '2026-09-17 08:01:44'),
(375, 'VIP-415', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(376, 'VIP-416', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(377, 'VIP-417', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(378, 'VIP-418', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(379, 'VIP-419', 'VIP Suite', 4, 20000.00, 'Available', '2026-09-17 08:01:44'),
(380, 'PRES-401', 'Presidential Suite', 4, 50000.00, 'Available', '2026-09-17 08:01:44'),
(381, 'ICU-501', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(382, 'ICU-502', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(383, 'ICU-503', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(384, 'ICU-504', 'ICU', 5, 18000.00, 'Maintenance', '2026-09-17 08:01:44'),
(385, 'ICU-505', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(386, 'ICU-506', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(387, 'ICU-507', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(388, 'ICU-508', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(389, 'ICU-509', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(390, 'ICU-510', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(391, 'ICU-511', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(392, 'ICU-512', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(393, 'ICU-513', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(394, 'ICU-514', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(395, 'ICU-515', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(396, 'ICU-516', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(397, 'ICU-517', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(398, 'ICU-518', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(399, 'ICU-519', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(400, 'ICU-520', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(401, 'ICU-521', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(402, 'ICU-522', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(403, 'ICU-523', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(404, 'ICU-524', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(405, 'ICU-525', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(406, 'ICU-526', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(407, 'ICU-527', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(408, 'ICU-528', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(409, 'ICU-529', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(410, 'ICU-530', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(411, 'ICU-531', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(412, 'ICU-532', 'ICU', 5, 18000.00, 'Occupied', '2026-09-17 08:01:44'),
(413, 'ICU-533', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(414, 'ICU-534', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(415, 'ICU-535', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(416, 'ICU-536', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(417, 'ICU-537', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(418, 'ICU-538', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(419, 'ICU-539', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(420, 'ICU-540', 'ICU', 5, 18000.00, 'Available', '2026-09-17 08:01:44'),
(421, 'CCU-501', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(422, 'CCU-502', 'CCU', 5, 15000.00, 'Occupied', '2026-09-17 08:01:44'),
(423, 'CCU-503', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(424, 'CCU-504', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(425, 'CCU-505', 'CCU', 5, 15000.00, 'Occupied', '2026-09-17 08:01:44'),
(426, 'CCU-506', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(427, 'CCU-507', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(428, 'CCU-508', 'CCU', 5, 15000.00, 'Maintenance', '2026-09-17 08:01:44'),
(429, 'CCU-509', 'CCU', 5, 15000.00, 'Occupied', '2026-09-17 08:01:44'),
(430, 'CCU-510', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(431, 'CCU-511', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(432, 'CCU-512', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(433, 'CCU-513', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(434, 'CCU-514', 'CCU', 5, 15000.00, 'Occupied', '2026-09-17 08:01:44'),
(435, 'CCU-515', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(436, 'CCU-516', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(437, 'CCU-517', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(438, 'CCU-518', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(439, 'CCU-519', 'CCU', 5, 15000.00, 'Occupied', '2026-09-17 08:01:44'),
(440, 'CCU-520', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(441, 'CCU-521', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(442, 'CCU-522', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(443, 'CCU-523', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(444, 'CCU-524', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(445, 'CCU-525', 'CCU', 5, 15000.00, 'Occupied', '2026-09-17 08:01:44'),
(446, 'CCU-526', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(447, 'CCU-527', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(448, 'CCU-528', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(449, 'CCU-529', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(450, 'CCU-530', 'CCU', 5, 15000.00, 'Available', '2026-09-17 08:01:44'),
(451, 'NICU-501', 'NICU', 5, 12000.00, 'Occupied', '2026-09-17 08:01:44'),
(452, 'NICU-502', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(453, 'NICU-503', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(454, 'NICU-504', 'NICU', 5, 12000.00, 'Occupied', '2026-09-17 08:01:44'),
(455, 'NICU-505', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(456, 'NICU-506', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(457, 'NICU-507', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(458, 'NICU-508', 'NICU', 5, 12000.00, 'Occupied', '2026-09-17 08:01:44'),
(459, 'NICU-509', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(460, 'NICU-510', 'NICU', 5, 12000.00, 'Maintenance', '2026-09-17 08:01:44'),
(461, 'NICU-511', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(462, 'NICU-512', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(463, 'NICU-513', 'NICU', 5, 12000.00, 'Occupied', '2026-09-17 08:01:44'),
(464, 'NICU-514', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(465, 'NICU-515', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(466, 'NICU-516', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(467, 'NICU-517', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(468, 'NICU-518', 'NICU', 5, 12000.00, 'Occupied', '2026-09-17 08:01:44'),
(469, 'NICU-519', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(470, 'NICU-520', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(471, 'NICU-521', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(472, 'NICU-522', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(473, 'NICU-523', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(474, 'NICU-524', 'NICU', 5, 12000.00, 'Occupied', '2026-09-17 08:01:44'),
(475, 'NICU-525', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(476, 'NICU-526', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(477, 'NICU-527', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(478, 'NICU-528', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(479, 'NICU-529', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(480, 'NICU-530', 'NICU', 5, 12000.00, 'Available', '2026-09-17 08:01:44'),
(481, 'REC-501', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(482, 'REC-502', 'Recovery', 5, 8000.00, 'Occupied', '2026-09-17 08:01:44'),
(483, 'REC-503', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(484, 'REC-504', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(485, 'REC-505', 'Recovery', 5, 8000.00, 'Maintenance', '2026-09-17 08:01:44'),
(486, 'REC-506', 'Recovery', 5, 8000.00, 'Occupied', '2026-09-17 08:01:44'),
(487, 'REC-507', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(488, 'REC-508', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(489, 'REC-509', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(490, 'REC-510', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(491, 'REC-511', 'Recovery', 5, 8000.00, 'Occupied', '2026-09-17 08:01:44'),
(492, 'REC-512', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(493, 'REC-513', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(494, 'REC-514', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(495, 'REC-515', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(496, 'REC-516', 'Recovery', 5, 8000.00, 'Occupied', '2026-09-17 08:01:44'),
(497, 'REC-517', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(498, 'REC-518', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(499, 'REC-519', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44'),
(500, 'REC-520', 'Recovery', 5, 8000.00, 'Available', '2026-09-17 08:01:44');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `admission_id` int(11) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_payable` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `due_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('bKash','Nagad','Credit Card','Cash','Insurance') NOT NULL DEFAULT 'Cash',
  `status` enum('Paid','Pending','Partial') NOT NULL DEFAULT 'Pending',
  `discharge_status` varchar(30) NOT NULL DEFAULT 'discharged',
  `payment_status` varchar(30) NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoice_id`, `invoice_number`, `patient_id`, `admission_id`, `generated_by`, `subtotal`, `vat_percentage`, `discount`, `net_payable`, `paid_amount`, `due_amount`, `payment_method`, `status`, `discharge_status`, `payment_status`, `created_at`) VALUES
(1, 'INV-2026-081', 1, 1, 6, 42000.00, 5.00, 0.00, 44100.00, 44100.00, 0.00, 'bKash', 'Paid', 'discharged', 'paid', '2026-09-16 08:20:00'),
(2, 'INV-2026-079', 5, 2, 15, 27000.00, 5.00, 0.00, 28350.00, 28350.00, 0.00, 'Insurance', 'Paid', 'discharged', 'paid', '2026-09-15 04:45:00'),
(3, 'INV-2026-076', 10, 3, 15, 7800.00, 5.00, 0.00, 8190.00, 8190.00, 0.00, 'Nagad', 'Paid', 'discharged', 'paid', '2026-09-14 10:30:00'),
(4, 'INV-2026-068', 18, 4, 6, 72000.00, 5.00, 0.00, 75600.00, 75600.00, 0.00, 'Credit Card', 'Paid', 'discharged', 'paid', '2026-09-12 03:15:00'),
(5, 'INV-2026-089', 28, 40, 6, 32000.00, 5.00, 1600.00, 32000.00, 32000.00, 0.00, 'Credit Card', 'Paid', 'discharged', 'paid', '2026-09-17 13:36:01'),
(8, 'INV-2026-0006', 1, 51, 6, 14500.00, 5.00, 0.00, 15225.00, 15225.00, 0.00, 'Cash', 'Paid', 'discharged', 'paid', '2026-09-17 19:07:26'),
(9, 'INV-2026-0009', 18, 52, 6, 14500.00, 5.00, 0.00, 15225.00, 15225.00, 0.00, 'Cash', 'Paid', 'discharged', 'paid', '2026-09-17 19:07:49'),
(10, 'INV-2026-0010', 1, 53, 6, 5200.00, 5.00, 0.00, 5460.00, 5460.00, 0.00, 'Cash', 'Paid', 'discharged', 'paid', '2026-09-17 19:13:01'),
(11, 'INV-2026-0011', 28, 54, 6, 51200.00, 5.00, 0.00, 53760.00, 0.00, 53760.00, 'Cash', 'Pending', 'discharged', 'unpaid', '2026-09-18 13:05:22'),
(12, 'INV-2026-0012', 1, 55, 6, 51200.00, 5.00, 0.00, 53760.00, 53760.00, 0.00, 'Cash', 'Paid', 'discharged', 'paid', '2026-09-18 13:06:18');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `item_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `item_type` enum('Consultation','Bed Charge','Diagnostic Test','Pharmacy') NOT NULL,
  `description` varchar(255) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `total_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `doctor_payout_status` enum('UNCLAIMED','PENDING_CLEARANCE','DISBURSED') NOT NULL DEFAULT 'UNCLAIMED',
  `doctor_payout_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`item_id`, `invoice_id`, `doctor_id`, `item_type`, `description`, `unit_price`, `quantity`, `total_price`, `doctor_payout_status`, `doctor_payout_amount`, `created_at`) VALUES
(1, 1, NULL, 'Bed Charge', 'ICU Telemetry Bed (3 Nights @ ৳12,000)', 12000.00, 3, 36000.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(2, 1, 21, 'Consultation', 'Critical Care Specialist Dr. Satoru Gojo Consultation', 2000.00, 1, 2000.00, 'DISBURSED', 2000.00, '2026-09-16 20:22:01'),
(3, 1, NULL, 'Diagnostic Test', 'Arterial Blood Gas (ABG) Analysis & CBC Panel', 4000.00, 1, 4000.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(4, 2, NULL, 'Bed Charge', 'CCU Ward Bed (2 Nights @ ৳10,000)', 10000.00, 2, 20000.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(5, 2, NULL, 'Diagnostic Test', 'Color Doppler 2D Echocardiography', 7000.00, 1, 7000.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(6, 3, 20, 'Consultation', 'Surgical Pre-op Evaluation Dr. Mikasa Ackerman', 1500.00, 1, 1500.00, 'DISBURSED', 1500.00, '2026-09-16 20:22:01'),
(7, 3, NULL, 'Diagnostic Test', 'Whole Abdomen Ultrasound with Liver/Gallbladder Study', 4300.00, 1, 4300.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(8, 3, NULL, 'Pharmacy', 'Surgical Preparation & Antibiotic Pack', 2000.00, 1, 2000.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(9, 4, NULL, 'Bed Charge', 'VIP Deluxe Suite Cabin (4 Nights @ ৳18,000)', 18000.00, 4, 72000.00, 'UNCLAIMED', 0.00, '2026-09-16 20:22:01'),
(10, 5, NULL, 'Bed Charge', 'Presidential Suite Care (2 Nights @ ৳12,000)', 12000.00, 2, 24000.00, 'UNCLAIMED', 0.00, '2026-09-17 13:36:01'),
(11, 5, 21, 'Consultation', 'Senior Neurologist Clinical Round Dr. Satoru Gojo', 3000.00, 1, 3000.00, 'DISBURSED', 3000.00, '2026-09-17 13:36:01'),
(12, 5, NULL, 'Diagnostic Test', 'High-Resolution Brain MRI with Contrast', 5000.00, 1, 5000.00, 'UNCLAIMED', 0.00, '2026-09-17 13:36:01'),
(15, 8, NULL, 'Bed Charge', 'EMG-104 (Emergency) [3 Night(s) @ ৳4,000.00] Facility Stay', 4000.00, 3, 12000.00, 'UNCLAIMED', 0.00, '2026-09-17 19:07:26'),
(16, 8, 20, 'Consultation', 'Attending Doctor Inpatient Round & Care: Col. (Retd.) Prof. Dr. Minhazul Islam Alvi', 2500.00, 1, 2500.00, 'DISBURSED', 2500.00, '2026-09-17 19:07:26'),
(17, 9, NULL, 'Bed Charge', 'EMG-106 (Emergency) [3 Night(s) @ ৳4,000.00] Facility Stay', 4000.00, 3, 12000.00, 'UNCLAIMED', 0.00, '2026-09-17 19:07:49'),
(18, 9, 20, 'Consultation', 'Attending Doctor Inpatient Round & Care: Col. (Retd.) Prof. Dr. Minhazul Islam Alvi', 2500.00, 1, 2500.00, 'DISBURSED', 2500.00, '2026-09-17 19:07:49'),
(19, 10, NULL, 'Bed Charge', 'EMG-104 (Emergency) [1 Night(s) @ ৳4,000.00] Facility Stay', 4000.00, 1, 4000.00, 'UNCLAIMED', 0.00, '2026-09-17 19:13:01'),
(20, 10, 47, 'Consultation', 'Attending Doctor Inpatient Round & Care: Dr. Afrina Hossain Riana', 1200.00, 1, 1200.00, 'DISBURSED', 1200.00, '2026-09-17 19:13:01'),
(21, 11, NULL, 'Bed Charge', 'PRES-401 (Presidential Suite) [1 Night(s) @ ৳50,000.00] Facility Stay', 50000.00, 1, 50000.00, 'UNCLAIMED', 0.00, '2026-09-18 13:05:22'),
(22, 11, 43, 'Consultation', 'Attending Doctor Inpatient Round & Care: Dr. Minhazul Islam Alvi', 1200.00, 1, 1200.00, 'DISBURSED', 1200.00, '2026-09-18 13:05:22'),
(23, 12, NULL, 'Bed Charge', 'PRES-401 (Presidential Suite) [1 Night(s) @ ৳50,000.00] Facility Stay', 50000.00, 1, 50000.00, 'UNCLAIMED', 0.00, '2026-09-18 13:06:18'),
(24, 12, 43, 'Consultation', 'Attending Doctor Inpatient Round & Care: Dr. Minhazul Islam Alvi', 1200.00, 1, 1200.00, 'DISBURSED', 1200.00, '2026-09-18 13:06:18');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `recipient_type` enum('DOCTOR','PATIENT','ADMIN') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `metadata` longtext NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient_id`, `recipient_type`, `title`, `message`, `event_type`, `metadata`, `is_read`, `created_at`) VALUES
(1, 18, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-101 (Emergency) to Bed EMG-104 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-101 (Emergency) to Bed EMG-104 (Emergency).\",\"timestamp\":\"2026-09-17T12:55:51+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-104)\",\"reason\":\"Automated Test Relocation\",\"transferred_by\":\"Agatsuma Zenitsu\",\"timestamp\":\"2026-09-17 12:55:51\"}}', 0, '2026-09-17 10:55:51'),
(2, 20, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed EMG-101 (Emergency) to Bed EMG-104 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed EMG-101 (Emergency) to Bed EMG-104 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T12:55:51+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-104)\",\"reason\":\"Automated Test Relocation\",\"transferred_by\":\"Agatsuma Zenitsu\",\"timestamp\":\"2026-09-17 12:55:51\"}}', 0, '2026-09-17 10:55:51'),
(3, 1, 'ADMIN', 'Relocation Recorded: Robert Downey Jr.', 'Patient Robert Downey Jr. moved from Bed EMG-101 to EMG-104.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:1\",\"recipient_id\":1,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. moved from Bed EMG-101 to EMG-104.\",\"timestamp\":\"2026-09-17T12:55:51+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-104)\",\"reason\":\"Automated Test Relocation\",\"transferred_by\":\"Agatsuma Zenitsu\",\"timestamp\":\"2026-09-17 12:55:51\"}}', 0, '2026-09-17 10:55:51'),
(4, 18, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-104 (Emergency) to Bed EMG-101 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-104 (Emergency) to Bed EMG-101 (Emergency).\",\"timestamp\":\"2026-09-17T12:56:04+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Automated Test Relocation\",\"transferred_by\":\"Agatsuma Zenitsu\",\"timestamp\":\"2026-09-17 12:56:04\"}}', 0, '2026-09-17 10:56:04'),
(5, 20, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed EMG-104 (Emergency) to Bed EMG-101 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed EMG-104 (Emergency) to Bed EMG-101 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T12:56:04+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Automated Test Relocation\",\"transferred_by\":\"Agatsuma Zenitsu\",\"timestamp\":\"2026-09-17 12:56:04\"}}', 0, '2026-09-17 10:56:04'),
(6, 1, 'ADMIN', 'Relocation Recorded: Robert Downey Jr.', 'Patient Robert Downey Jr. moved from Bed EMG-104 to EMG-101.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:1\",\"recipient_id\":1,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. moved from Bed EMG-104 to EMG-101.\",\"timestamp\":\"2026-09-17T12:56:04+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Automated Test Relocation\",\"transferred_by\":\"Agatsuma Zenitsu\",\"timestamp\":\"2026-09-17 12:56:04\"}}', 0, '2026-09-17 10:56:04'),
(7, 18, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed EMG-104 (Emergency). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed EMG-104 (Emergency). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-17T12:56:04+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"released_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Patient successfully treated and discharged.\",\"discharged_at\":\"2026-09-17 12:56:04\",\"discharged_by\":\"Agatsuma Zenitsu\"}}', 0, '2026-09-17 10:56:04'),
(8, 20, 'DOCTOR', 'Patient Discharged: Robert Downey Jr.', 'Patient Robert Downey Jr. has been discharged from Bed EMG-104. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. has been discharged from Bed EMG-104. Inpatient care completed.\",\"timestamp\":\"2026-09-17T12:56:04+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"released_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Patient successfully treated and discharged.\",\"discharged_at\":\"2026-09-17 12:56:04\",\"discharged_by\":\"Agatsuma Zenitsu\"}}', 0, '2026-09-17 10:56:04'),
(9, 18, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-101 (Emergency) to Bed PRES-401 (Presidential Suite).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-101 (Emergency) to Bed PRES-401 (Presidential Suite).\",\"timestamp\":\"2026-09-17T12:59:29+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"room_number\":\"Floor 4 - Presidential Suite (Bed PRES-401)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 12:59:29\"}}', 0, '2026-09-17 10:59:29'),
(10, 20, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed EMG-101 (Emergency) to Bed PRES-401 (Presidential Suite). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed EMG-101 (Emergency) to Bed PRES-401 (Presidential Suite). Active rounds list updated.\",\"timestamp\":\"2026-09-17T12:59:29+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"room_number\":\"Floor 4 - Presidential Suite (Bed PRES-401)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 12:59:29\"}}', 0, '2026-09-17 10:59:29'),
(11, 6, 'ADMIN', 'Relocation Recorded: Robert Downey Jr.', 'Patient Robert Downey Jr. moved from Bed EMG-101 to PRES-401.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. moved from Bed EMG-101 to PRES-401.\",\"timestamp\":\"2026-09-17T12:59:29+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"room_number\":\"Floor 4 - Presidential Suite (Bed PRES-401)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 12:59:29\"}}', 0, '2026-09-17 10:59:29'),
(12, 5, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed GWM-201 (General Ward Male) to Bed EMG-124 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:5\",\"recipient_id\":5,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed GWM-201 (General Ward Male) to Bed EMG-124 (Emergency).\",\"timestamp\":\"2026-09-17T13:03:41+02:00\",\"data\":{\"patient_id\":5,\"patient_name\":\"Nusrat Jahan\",\"old_bed\":{\"bed_id\":41,\"bed_number\":\"GWM-201\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":24,\"bed_number\":\"EMG-124\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-124)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:03:41\"}}', 0, '2026-09-17 11:03:41'),
(13, 21, 'DOCTOR', 'Patient Bed Moved: Nusrat Jahan', 'Your patient Nusrat Jahan has been relocated from Bed GWM-201 (General Ward Male) to Bed EMG-124 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:21\",\"recipient_id\":21,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Nusrat Jahan\",\"message\":\"Your patient Nusrat Jahan has been relocated from Bed GWM-201 (General Ward Male) to Bed EMG-124 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:03:41+02:00\",\"data\":{\"patient_id\":5,\"patient_name\":\"Nusrat Jahan\",\"old_bed\":{\"bed_id\":41,\"bed_number\":\"GWM-201\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":24,\"bed_number\":\"EMG-124\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-124)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:03:41\"}}', 0, '2026-09-17 11:03:41'),
(14, 6, 'ADMIN', 'Relocation Recorded: Nusrat Jahan', 'Patient Nusrat Jahan moved from Bed GWM-201 to EMG-124.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Nusrat Jahan\",\"message\":\"Patient Nusrat Jahan moved from Bed GWM-201 to EMG-124.\",\"timestamp\":\"2026-09-17T13:03:41+02:00\",\"data\":{\"patient_id\":5,\"patient_name\":\"Nusrat Jahan\",\"old_bed\":{\"bed_id\":41,\"bed_number\":\"GWM-201\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":24,\"bed_number\":\"EMG-124\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-124)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:03:41\"}}', 0, '2026-09-17 11:03:41'),
(15, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-101 (Emergency) to Bed SC-315 (Semi-Cabin).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-101 (Emergency) to Bed SC-315 (Semi-Cabin).\",\"timestamp\":\"2026-09-17T13:26:07+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":275,\"bed_number\":\"SC-315\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-315)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:26:07\"}}', 0, '2026-09-17 11:26:07'),
(16, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed EMG-101 to SC-315.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed EMG-101 to SC-315.\",\"timestamp\":\"2026-09-17T13:26:07+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":275,\"bed_number\":\"SC-315\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-315)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:26:07\"}}', 0, '2026-09-17 11:26:07'),
(17, 16, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:26:26+02:00\",\"data\":{\"patient_id\":16,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:26:26\"}}', 0, '2026-09-17 11:26:26'),
(18, 28, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-106 (Emergency) to Bed EMG-130 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-106 (Emergency) to Bed EMG-130 (Emergency).\",\"timestamp\":\"2026-09-17T13:32:10+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":6,\"bed_number\":\"EMG-106\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":30,\"bed_number\":\"EMG-130\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-130)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:32:10\"}}', 0, '2026-09-17 11:32:10'),
(19, 13, 'DOCTOR', 'Patient Bed Moved: Kibutsuji Muzan', 'Your patient Kibutsuji Muzan has been relocated from Bed EMG-106 (Emergency) to Bed EMG-130 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:13\",\"recipient_id\":13,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Kibutsuji Muzan\",\"message\":\"Your patient Kibutsuji Muzan has been relocated from Bed EMG-106 (Emergency) to Bed EMG-130 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:32:10+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":6,\"bed_number\":\"EMG-106\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":30,\"bed_number\":\"EMG-130\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-130)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:32:10\"}}', 0, '2026-09-17 11:32:10'),
(20, 6, 'ADMIN', 'Relocation Recorded: Kibutsuji Muzan', 'Patient Kibutsuji Muzan moved from Bed EMG-106 to EMG-130.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Kibutsuji Muzan\",\"message\":\"Patient Kibutsuji Muzan moved from Bed EMG-106 to EMG-130.\",\"timestamp\":\"2026-09-17T13:32:10+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":6,\"bed_number\":\"EMG-106\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":30,\"bed_number\":\"EMG-130\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-130)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:32:10\"}}', 0, '2026-09-17 11:32:10'),
(21, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed SC-315 (Semi-Cabin) to Bed SC-313 (Semi-Cabin).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed SC-315 (Semi-Cabin) to Bed SC-313 (Semi-Cabin).\",\"timestamp\":\"2026-09-17T13:34:22+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":275,\"bed_number\":\"SC-315\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":273,\"bed_number\":\"SC-313\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-313)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:34:22\"}}', 0, '2026-09-17 11:34:22'),
(22, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed SC-315 to SC-313.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed SC-315 to SC-313.\",\"timestamp\":\"2026-09-17T13:34:22+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":275,\"bed_number\":\"SC-315\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":273,\"bed_number\":\"SC-313\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-313)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:34:22\"}}', 0, '2026-09-17 11:34:22'),
(23, 1, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-102 (Emergency) to Bed SC-317 (Semi-Cabin).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-102 (Emergency) to Bed SC-317 (Semi-Cabin).\",\"timestamp\":\"2026-09-17T13:36:07+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"old_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":277,\"bed_number\":\"SC-317\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-317)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:36:07\"}}', 0, '2026-09-17 11:36:07'),
(24, 43, 'DOCTOR', 'Patient Bed Moved: Agatsuma Zenitsu', 'Your patient Agatsuma Zenitsu has been relocated from Bed EMG-102 (Emergency) to Bed SC-317 (Semi-Cabin). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Agatsuma Zenitsu\",\"message\":\"Your patient Agatsuma Zenitsu has been relocated from Bed EMG-102 (Emergency) to Bed SC-317 (Semi-Cabin). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:36:07+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"old_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":277,\"bed_number\":\"SC-317\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-317)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:36:07\"}}', 0, '2026-09-17 11:36:07'),
(25, 6, 'ADMIN', 'Relocation Recorded: Agatsuma Zenitsu', 'Patient Agatsuma Zenitsu moved from Bed EMG-102 to SC-317.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Agatsuma Zenitsu\",\"message\":\"Patient Agatsuma Zenitsu moved from Bed EMG-102 to SC-317.\",\"timestamp\":\"2026-09-17T13:36:07+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"old_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":277,\"bed_number\":\"SC-317\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-317)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:36:07\"}}', 0, '2026-09-17 11:36:07'),
(26, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed SC-313 (Semi-Cabin) to Bed GWM-228 (General Ward Male).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed SC-313 (Semi-Cabin) to Bed GWM-228 (General Ward Male).\",\"timestamp\":\"2026-09-17T13:45:57+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":273,\"bed_number\":\"SC-313\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-228)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:45:57\"}}', 0, '2026-09-17 11:45:57'),
(27, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed SC-313 to GWM-228.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed SC-313 to GWM-228.\",\"timestamp\":\"2026-09-17T13:45:57+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":273,\"bed_number\":\"SC-313\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-228)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:45:57\"}}', 0, '2026-09-17 11:45:57'),
(28, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed GWM-228 (General Ward Male) to Bed EMG-131 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed GWM-228 (General Ward Male) to Bed EMG-131 (Emergency).\",\"timestamp\":\"2026-09-17T13:46:05+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":31,\"bed_number\":\"EMG-131\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-131)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:46:05\"}}', 0, '2026-09-17 11:46:05'),
(29, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed GWM-228 to EMG-131.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed GWM-228 to EMG-131.\",\"timestamp\":\"2026-09-17T13:46:05+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":31,\"bed_number\":\"EMG-131\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-131)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:46:05\"}}', 0, '2026-09-17 11:46:05'),
(30, 16, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:46:20+02:00\",\"data\":{\"patient_id\":16,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:46:20\"}}', 0, '2026-09-17 11:46:20'),
(31, 16, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:46:32+02:00\",\"data\":{\"patient_id\":16,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:46:32\"}}', 0, '2026-09-17 11:46:32'),
(32, 28, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:46:57+02:00\",\"data\":{\"patient_id\":28,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:46:57\"}}', 0, '2026-09-17 11:46:57'),
(33, 28, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:47:16+02:00\",\"data\":{\"patient_id\":28,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:47:16\"}}', 0, '2026-09-17 11:47:16'),
(34, 10, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:10\",\"recipient_id\":10,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:47:29+02:00\",\"data\":{\"patient_id\":10,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:47:29\"}}', 0, '2026-09-17 11:47:29'),
(35, 1, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:47:43+02:00\",\"data\":{\"patient_id\":1,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:47:43\"}}', 0, '2026-09-17 11:47:43'),
(36, 18, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:47:49+02:00\",\"data\":{\"patient_id\":18,\"added_doctors\":[],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:47:49\"}}', 0, '2026-09-17 11:47:49'),
(37, 8, 'DOCTOR', 'New Inpatient Assigned: Sabbir Ahmed', 'You have been assigned to inpatient care for Sabbir Ahmed.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:8\",\"recipient_id\":8,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Sabbir Ahmed\",\"message\":\"You have been assigned to inpatient care for Sabbir Ahmed.\",\"timestamp\":\"2026-09-17T13:49:20+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 13:49:20\"}}', 0, '2026-09-17 11:49:20'),
(38, 16, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:49:20+02:00\",\"data\":{\"patient_id\":16,\"added_doctors\":[8],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:49:20\"}}', 0, '2026-09-17 11:49:20'),
(39, 29, 'DOCTOR', 'New Inpatient Assigned: Robert Downey Jr.', 'You have been assigned to inpatient care for Robert Downey Jr..', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:29\",\"recipient_id\":29,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Robert Downey Jr.\",\"message\":\"You have been assigned to inpatient care for Robert Downey Jr..\",\"timestamp\":\"2026-09-17T13:49:26+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 13:49:26\"}}', 0, '2026-09-17 11:49:26'),
(40, 18, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T13:49:26+02:00\",\"data\":{\"patient_id\":18,\"added_doctors\":[29],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 13:49:26\"}}', 0, '2026-09-17 11:49:26'),
(41, 28, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-130 (Emergency) to Bed SC-321 (Semi-Cabin).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-130 (Emergency) to Bed SC-321 (Semi-Cabin).\",\"timestamp\":\"2026-09-17T13:49:41+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":30,\"bed_number\":\"EMG-130\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":281,\"bed_number\":\"SC-321\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-321)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:49:41\"}}', 0, '2026-09-17 11:49:41'),
(42, 13, 'DOCTOR', 'Patient Bed Moved: Kibutsuji Muzan', 'Your patient Kibutsuji Muzan has been relocated from Bed EMG-130 (Emergency) to Bed SC-321 (Semi-Cabin). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:13\",\"recipient_id\":13,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Kibutsuji Muzan\",\"message\":\"Your patient Kibutsuji Muzan has been relocated from Bed EMG-130 (Emergency) to Bed SC-321 (Semi-Cabin). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:49:41+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":30,\"bed_number\":\"EMG-130\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":281,\"bed_number\":\"SC-321\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-321)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:49:41\"}}', 0, '2026-09-17 11:49:41'),
(43, 6, 'ADMIN', 'Relocation Recorded: Kibutsuji Muzan', 'Patient Kibutsuji Muzan moved from Bed EMG-130 to SC-321.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Kibutsuji Muzan\",\"message\":\"Patient Kibutsuji Muzan moved from Bed EMG-130 to SC-321.\",\"timestamp\":\"2026-09-17T13:49:41+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":30,\"bed_number\":\"EMG-130\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":281,\"bed_number\":\"SC-321\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"room_number\":\"Floor 3 - Semi-Cabin (Bed SC-321)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:49:41\"}}', 0, '2026-09-17 11:49:41'),
(44, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-131 (Emergency) to Bed GWM-223 (General Ward Male).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-131 (Emergency) to Bed GWM-223 (General Ward Male).\",\"timestamp\":\"2026-09-17T13:49:49+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":31,\"bed_number\":\"EMG-131\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":63,\"bed_number\":\"GWM-223\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-223)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:49:49\"}}', 0, '2026-09-17 11:49:49'),
(45, 8, 'DOCTOR', 'Patient Bed Moved: Sabbir Ahmed', 'Your patient Sabbir Ahmed has been relocated from Bed EMG-131 (Emergency) to Bed GWM-223 (General Ward Male). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:8\",\"recipient_id\":8,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Sabbir Ahmed\",\"message\":\"Your patient Sabbir Ahmed has been relocated from Bed EMG-131 (Emergency) to Bed GWM-223 (General Ward Male). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:49:49+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":31,\"bed_number\":\"EMG-131\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":63,\"bed_number\":\"GWM-223\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-223)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:49:49\"}}', 0, '2026-09-17 11:49:49'),
(46, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed EMG-131 to GWM-223.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed EMG-131 to GWM-223.\",\"timestamp\":\"2026-09-17T13:49:49+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":31,\"bed_number\":\"EMG-131\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":63,\"bed_number\":\"GWM-223\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-223)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:49:49\"}}', 0, '2026-09-17 11:49:49'),
(47, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed GWM-223 (General Ward Male) to Bed EMG-123 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed GWM-223 (General Ward Male) to Bed EMG-123 (Emergency).\",\"timestamp\":\"2026-09-17T13:58:17+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":63,\"bed_number\":\"GWM-223\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":23,\"bed_number\":\"EMG-123\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-123)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:58:17\"}}', 0, '2026-09-17 11:58:17'),
(48, 8, 'DOCTOR', 'Patient Bed Moved: Sabbir Ahmed', 'Your patient Sabbir Ahmed has been relocated from Bed GWM-223 (General Ward Male) to Bed EMG-123 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:8\",\"recipient_id\":8,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Sabbir Ahmed\",\"message\":\"Your patient Sabbir Ahmed has been relocated from Bed GWM-223 (General Ward Male) to Bed EMG-123 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:58:17+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":63,\"bed_number\":\"GWM-223\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":23,\"bed_number\":\"EMG-123\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-123)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:58:17\"}}', 0, '2026-09-17 11:58:17'),
(49, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed GWM-223 to EMG-123.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed GWM-223 to EMG-123.\",\"timestamp\":\"2026-09-17T13:58:17+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":63,\"bed_number\":\"GWM-223\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":23,\"bed_number\":\"EMG-123\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-123)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:58:17\"}}', 0, '2026-09-17 11:58:17'),
(50, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed EMG-123 (Emergency) to Bed GWM-225 (General Ward Male).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed EMG-123 (Emergency) to Bed GWM-225 (General Ward Male).\",\"timestamp\":\"2026-09-17T13:58:29+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":23,\"bed_number\":\"EMG-123\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-225)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:58:29\"}}', 0, '2026-09-17 11:58:29'),
(51, 8, 'DOCTOR', 'Patient Bed Moved: Sabbir Ahmed', 'Your patient Sabbir Ahmed has been relocated from Bed EMG-123 (Emergency) to Bed GWM-225 (General Ward Male). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:8\",\"recipient_id\":8,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Sabbir Ahmed\",\"message\":\"Your patient Sabbir Ahmed has been relocated from Bed EMG-123 (Emergency) to Bed GWM-225 (General Ward Male). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:58:29+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":23,\"bed_number\":\"EMG-123\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-225)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:58:29\"}}', 0, '2026-09-17 11:58:29'),
(52, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed EMG-123 to GWM-225.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed EMG-123 to GWM-225.\",\"timestamp\":\"2026-09-17T13:58:29+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":23,\"bed_number\":\"EMG-123\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"new_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-225)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:58:29\"}}', 0, '2026-09-17 11:58:29'),
(53, 1, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed SC-317 (Semi-Cabin) to Bed CAB-417 (Deluxe Cabin).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed SC-317 (Semi-Cabin) to Bed CAB-417 (Deluxe Cabin).\",\"timestamp\":\"2026-09-17T13:59:03+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"old_bed\":{\"bed_id\":277,\"bed_number\":\"SC-317\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":317,\"bed_number\":\"CAB-417\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"room_number\":\"Floor 4 - Deluxe Cabin (Bed CAB-417)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:59:03\"}}', 0, '2026-09-17 11:59:03'),
(54, 43, 'DOCTOR', 'Patient Bed Moved: Agatsuma Zenitsu', 'Your patient Agatsuma Zenitsu has been relocated from Bed SC-317 (Semi-Cabin) to Bed CAB-417 (Deluxe Cabin). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Agatsuma Zenitsu\",\"message\":\"Your patient Agatsuma Zenitsu has been relocated from Bed SC-317 (Semi-Cabin) to Bed CAB-417 (Deluxe Cabin). Active rounds list updated.\",\"timestamp\":\"2026-09-17T13:59:03+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"old_bed\":{\"bed_id\":277,\"bed_number\":\"SC-317\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":317,\"bed_number\":\"CAB-417\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"room_number\":\"Floor 4 - Deluxe Cabin (Bed CAB-417)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:59:03\"}}', 0, '2026-09-17 11:59:03'),
(55, 6, 'ADMIN', 'Relocation Recorded: Agatsuma Zenitsu', 'Patient Agatsuma Zenitsu moved from Bed SC-317 to CAB-417.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Agatsuma Zenitsu\",\"message\":\"Patient Agatsuma Zenitsu moved from Bed SC-317 to CAB-417.\",\"timestamp\":\"2026-09-17T13:59:03+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"old_bed\":{\"bed_id\":277,\"bed_number\":\"SC-317\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":317,\"bed_number\":\"CAB-417\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"room_number\":\"Floor 4 - Deluxe Cabin (Bed CAB-417)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 13:59:03\"}}', 0, '2026-09-17 11:59:03'),
(56, 33, 'DOCTOR', 'New Inpatient Assigned: Robert Downey Jr.', 'You have been assigned to inpatient care for Robert Downey Jr..', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:33\",\"recipient_id\":33,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Robert Downey Jr.\",\"message\":\"You have been assigned to inpatient care for Robert Downey Jr..\",\"timestamp\":\"2026-09-17T14:24:01+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:24:01\"}}', 0, '2026-09-17 12:24:01'),
(57, 18, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:24:01+02:00\",\"data\":{\"patient_id\":18,\"added_doctors\":[33],\"removed_doctors\":[20],\"timestamp\":\"2026-09-17 14:24:01\"}}', 0, '2026-09-17 12:24:01');
INSERT INTO `notifications` (`id`, `recipient_id`, `recipient_type`, `title`, `message`, `event_type`, `metadata`, `is_read`, `created_at`) VALUES
(58, 20, 'DOCTOR', 'New Inpatient Assigned: Sabbir Ahmed', 'You have been assigned to inpatient care for Sabbir Ahmed.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Sabbir Ahmed\",\"message\":\"You have been assigned to inpatient care for Sabbir Ahmed.\",\"timestamp\":\"2026-09-17T14:25:00+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:25:00\"}}', 0, '2026-09-17 12:25:00'),
(59, 16, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:25:00+02:00\",\"data\":{\"patient_id\":16,\"added_doctors\":[20],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 14:25:00\"}}', 0, '2026-09-17 12:25:00'),
(60, 43, 'DOCTOR', 'New Inpatient Assigned: Robert Downey Jr.', 'You have been assigned to inpatient care for Robert Downey Jr..', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Robert Downey Jr.\",\"message\":\"You have been assigned to inpatient care for Robert Downey Jr..\",\"timestamp\":\"2026-09-17T14:25:11+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:25:11\"}}', 0, '2026-09-17 12:25:11'),
(61, 18, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:25:11+02:00\",\"data\":{\"patient_id\":18,\"added_doctors\":[43],\"removed_doctors\":[29],\"timestamp\":\"2026-09-17 14:25:11\"}}', 0, '2026-09-17 12:25:11'),
(62, 18, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed PRES-401 (Presidential Suite) to Bed GWM-228 (General Ward Male).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed PRES-401 (Presidential Suite) to Bed GWM-228 (General Ward Male).\",\"timestamp\":\"2026-09-17T14:25:34+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"new_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-228)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:34\"}}', 0, '2026-09-17 12:25:34'),
(63, 33, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed PRES-401 (Presidential Suite) to Bed GWM-228 (General Ward Male). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:33\",\"recipient_id\":33,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed PRES-401 (Presidential Suite) to Bed GWM-228 (General Ward Male). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:25:34+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"new_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-228)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:34\"}}', 0, '2026-09-17 12:25:34'),
(64, 43, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed PRES-401 (Presidential Suite) to Bed GWM-228 (General Ward Male). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed PRES-401 (Presidential Suite) to Bed GWM-228 (General Ward Male). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:25:34+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"new_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-228)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:34\"}}', 0, '2026-09-17 12:25:34'),
(65, 6, 'ADMIN', 'Relocation Recorded: Robert Downey Jr.', 'Patient Robert Downey Jr. moved from Bed PRES-401 to GWM-228.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. moved from Bed PRES-401 to GWM-228.\",\"timestamp\":\"2026-09-17T14:25:34+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"new_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"room_number\":\"Floor 2 - General Ward Male (Bed GWM-228)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:34\"}}', 0, '2026-09-17 12:25:34'),
(66, 28, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed SC-321 (Semi-Cabin) to Bed PRES-401 (Presidential Suite).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed SC-321 (Semi-Cabin) to Bed PRES-401 (Presidential Suite).\",\"timestamp\":\"2026-09-17T14:25:47+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":281,\"bed_number\":\"SC-321\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"room_number\":\"Floor 4 - Presidential Suite (Bed PRES-401)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:47\"}}', 0, '2026-09-17 12:25:47'),
(67, 13, 'DOCTOR', 'Patient Bed Moved: Kibutsuji Muzan', 'Your patient Kibutsuji Muzan has been relocated from Bed SC-321 (Semi-Cabin) to Bed PRES-401 (Presidential Suite). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:13\",\"recipient_id\":13,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Kibutsuji Muzan\",\"message\":\"Your patient Kibutsuji Muzan has been relocated from Bed SC-321 (Semi-Cabin) to Bed PRES-401 (Presidential Suite). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:25:47+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":281,\"bed_number\":\"SC-321\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"room_number\":\"Floor 4 - Presidential Suite (Bed PRES-401)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:47\"}}', 0, '2026-09-17 12:25:47'),
(68, 6, 'ADMIN', 'Relocation Recorded: Kibutsuji Muzan', 'Patient Kibutsuji Muzan moved from Bed SC-321 to PRES-401.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Kibutsuji Muzan\",\"message\":\"Patient Kibutsuji Muzan moved from Bed SC-321 to PRES-401.\",\"timestamp\":\"2026-09-17T14:25:47+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"old_bed\":{\"bed_id\":281,\"bed_number\":\"SC-321\",\"ward_type\":\"Semi-Cabin\",\"floor_number\":3},\"new_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"room_number\":\"Floor 4 - Presidential Suite (Bed PRES-401)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:25:47\"}}', 0, '2026-09-17 12:25:47'),
(69, 14, 'DOCTOR', 'New Inpatient Assigned: Kibutsuji Muzan', 'You have been assigned to inpatient care for Kibutsuji Muzan.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:14\",\"recipient_id\":14,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Kibutsuji Muzan\",\"message\":\"You have been assigned to inpatient care for Kibutsuji Muzan.\",\"timestamp\":\"2026-09-17T14:26:18+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:26:18\"}}', 0, '2026-09-17 12:26:18'),
(70, 28, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:26:18+02:00\",\"data\":{\"patient_id\":28,\"added_doctors\":[14],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 14:26:18\"}}', 0, '2026-09-17 12:26:18'),
(71, 21, 'DOCTOR', 'New Inpatient Assigned: Kibutsuji Muzan', 'You have been assigned to inpatient care for Kibutsuji Muzan.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:21\",\"recipient_id\":21,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Kibutsuji Muzan\",\"message\":\"You have been assigned to inpatient care for Kibutsuji Muzan.\",\"timestamp\":\"2026-09-17T14:26:26+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:26:26\"}}', 0, '2026-09-17 12:26:26'),
(72, 28, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:26:26+02:00\",\"data\":{\"patient_id\":28,\"added_doctors\":[21],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 14:26:26\"}}', 0, '2026-09-17 12:26:26'),
(73, 16, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed GWM-225 (General Ward Male) to Bed EMG-101 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed GWM-225 (General Ward Male) to Bed EMG-101 (Emergency).\",\"timestamp\":\"2026-09-17T14:27:42+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:27:42\"}}', 0, '2026-09-17 12:27:42'),
(74, 8, 'DOCTOR', 'Patient Bed Moved: Sabbir Ahmed', 'Your patient Sabbir Ahmed has been relocated from Bed GWM-225 (General Ward Male) to Bed EMG-101 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:8\",\"recipient_id\":8,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Sabbir Ahmed\",\"message\":\"Your patient Sabbir Ahmed has been relocated from Bed GWM-225 (General Ward Male) to Bed EMG-101 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:27:42+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:27:42\"}}', 0, '2026-09-17 12:27:42'),
(75, 20, 'DOCTOR', 'Patient Bed Moved: Sabbir Ahmed', 'Your patient Sabbir Ahmed has been relocated from Bed GWM-225 (General Ward Male) to Bed EMG-101 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Sabbir Ahmed\",\"message\":\"Your patient Sabbir Ahmed has been relocated from Bed GWM-225 (General Ward Male) to Bed EMG-101 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:27:42+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:27:42\"}}', 0, '2026-09-17 12:27:42'),
(76, 6, 'ADMIN', 'Relocation Recorded: Sabbir Ahmed', 'Patient Sabbir Ahmed moved from Bed GWM-225 to EMG-101.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Sabbir Ahmed\",\"message\":\"Patient Sabbir Ahmed moved from Bed GWM-225 to EMG-101.\",\"timestamp\":\"2026-09-17T14:27:42+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"old_bed\":{\"bed_id\":65,\"bed_number\":\"GWM-225\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:27:42\"}}', 0, '2026-09-17 12:27:42'),
(77, 29, 'DOCTOR', 'New Inpatient Assigned: Sabbir Ahmed', 'You have been assigned to inpatient care for Sabbir Ahmed.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:29\",\"recipient_id\":29,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Sabbir Ahmed\",\"message\":\"You have been assigned to inpatient care for Sabbir Ahmed.\",\"timestamp\":\"2026-09-17T14:41:52+02:00\",\"data\":{\"patient_id\":16,\"patient_name\":\"Sabbir Ahmed\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:41:52\"}}', 0, '2026-09-17 12:41:52'),
(78, 16, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:16\",\"recipient_id\":16,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:41:52+02:00\",\"data\":{\"patient_id\":16,\"added_doctors\":[29],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 14:41:52\"}}', 0, '2026-09-17 12:41:52'),
(79, 14, 'DOCTOR', 'New Inpatient Assigned: Robert Downey Jr.', 'You have been assigned to inpatient care for Robert Downey Jr..', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:14\",\"recipient_id\":14,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Robert Downey Jr.\",\"message\":\"You have been assigned to inpatient care for Robert Downey Jr..\",\"timestamp\":\"2026-09-17T14:49:00+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 14:49:00\"}}', 0, '2026-09-17 12:49:00'),
(80, 18, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T14:49:00+02:00\",\"data\":{\"patient_id\":18,\"added_doctors\":[14],\"removed_doctors\":[],\"timestamp\":\"2026-09-17 14:49:00\"}}', 0, '2026-09-17 12:49:00'),
(81, 18, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency).\",\"timestamp\":\"2026-09-17T14:49:13+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-102)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:49:13\"}}', 0, '2026-09-17 12:49:13'),
(82, 33, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:33\",\"recipient_id\":33,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:49:13+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-102)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:49:13\"}}', 0, '2026-09-17 12:49:13'),
(83, 43, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:49:13+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-102)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:49:13\"}}', 0, '2026-09-17 12:49:13'),
(84, 14, 'DOCTOR', 'Patient Bed Moved: Robert Downey Jr.', 'Your patient Robert Downey Jr. has been relocated from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:14\",\"recipient_id\":14,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Robert Downey Jr.\",\"message\":\"Your patient Robert Downey Jr. has been relocated from Bed GWM-228 (General Ward Male) to Bed EMG-102 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T14:49:13+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-102)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:49:13\"}}', 0, '2026-09-17 12:49:13'),
(85, 6, 'ADMIN', 'Relocation Recorded: Robert Downey Jr.', 'Patient Robert Downey Jr. moved from Bed GWM-228 to EMG-102.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. moved from Bed GWM-228 to EMG-102.\",\"timestamp\":\"2026-09-17T14:49:13+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"old_bed\":{\"bed_id\":68,\"bed_number\":\"GWM-228\",\"ward_type\":\"General Ward Male\",\"floor_number\":2},\"new_bed\":{\"bed_id\":2,\"bed_number\":\"EMG-102\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-102)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 14:49:13\"}}', 0, '2026-09-17 12:49:13'),
(86, 47, 'DOCTOR', 'New Inpatient Assigned: Agatsuma Zenitsu', 'You have been assigned to inpatient care for Agatsuma Zenitsu.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:47\",\"recipient_id\":47,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Agatsuma Zenitsu\",\"message\":\"You have been assigned to inpatient care for Agatsuma Zenitsu.\",\"timestamp\":\"2026-09-17T20:29:47+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 20:29:47\"}}', 0, '2026-09-17 18:29:47'),
(87, 1, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T20:29:47+02:00\",\"data\":{\"patient_id\":1,\"added_doctors\":[47],\"removed_doctors\":[43],\"timestamp\":\"2026-09-17 20:29:47\"}}', 0, '2026-09-17 18:29:47'),
(88, 47, 'DOCTOR', 'New Inpatient Assigned: Nusrat Jahan', 'You have been assigned to inpatient care for Nusrat Jahan.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:47\",\"recipient_id\":47,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Nusrat Jahan\",\"message\":\"You have been assigned to inpatient care for Nusrat Jahan.\",\"timestamp\":\"2026-09-17T20:29:58+02:00\",\"data\":{\"patient_id\":5,\"patient_name\":\"Nusrat Jahan\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 20:29:58\"}}', 0, '2026-09-17 18:29:58'),
(89, 5, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:5\",\"recipient_id\":5,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T20:29:58+02:00\",\"data\":{\"patient_id\":5,\"added_doctors\":[47],\"removed_doctors\":[21],\"timestamp\":\"2026-09-17 20:29:58\"}}', 0, '2026-09-17 18:29:58'),
(90, 1, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed CAB-417 (Deluxe Cabin). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed CAB-417 (Deluxe Cabin). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-17T20:40:39+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":317,\"bed_number\":\"CAB-417\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"discharge_summary\":\"Clinical recovery goals achieved. Patient discharged in stable condition.\",\"discharged_at\":\"2026-09-17 20:40:39\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 18:40:39'),
(91, 47, 'DOCTOR', 'Patient Discharged: Agatsuma Zenitsu', 'Patient Agatsuma Zenitsu has been discharged from Bed CAB-417. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:47\",\"recipient_id\":47,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Agatsuma Zenitsu\",\"message\":\"Patient Agatsuma Zenitsu has been discharged from Bed CAB-417. Inpatient care completed.\",\"timestamp\":\"2026-09-17T20:40:39+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":317,\"bed_number\":\"CAB-417\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"discharge_summary\":\"Clinical recovery goals achieved. Patient discharged in stable condition.\",\"discharged_at\":\"2026-09-17 20:40:39\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 18:40:39'),
(92, 5, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed EMG-124 (Emergency). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:5\",\"recipient_id\":5,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed EMG-124 (Emergency). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-17T20:40:43+02:00\",\"data\":{\"patient_id\":5,\"patient_name\":\"Nusrat Jahan\",\"released_bed\":{\"bed_id\":24,\"bed_number\":\"EMG-124\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Clinical recovery goals achieved. Patient discharged in stable condition.\",\"discharged_at\":\"2026-09-17 20:40:43\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 18:40:43'),
(93, 47, 'DOCTOR', 'Patient Discharged: Nusrat Jahan', 'Patient Nusrat Jahan has been discharged from Bed EMG-124. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:47\",\"recipient_id\":47,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Nusrat Jahan\",\"message\":\"Patient Nusrat Jahan has been discharged from Bed EMG-124. Inpatient care completed.\",\"timestamp\":\"2026-09-17T20:40:43+02:00\",\"data\":{\"patient_id\":5,\"patient_name\":\"Nusrat Jahan\",\"released_bed\":{\"bed_id\":24,\"bed_number\":\"EMG-124\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Clinical recovery goals achieved. Patient discharged in stable condition.\",\"discharged_at\":\"2026-09-17 20:40:43\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 18:40:43'),
(94, 10, 'PATIENT', 'Bed Relocation Confirmed', 'You have been transferred from Bed CAB-401 (Deluxe Cabin) to Bed EMG-101 (Emergency).', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"patient:10\",\"recipient_id\":10,\"recipient_type\":\"PATIENT\",\"title\":\"Bed Relocation Confirmed\",\"message\":\"You have been transferred from Bed CAB-401 (Deluxe Cabin) to Bed EMG-101 (Emergency).\",\"timestamp\":\"2026-09-17T20:41:10+02:00\",\"data\":{\"patient_id\":10,\"patient_name\":\"Jahid Hasan\",\"old_bed\":{\"bed_id\":301,\"bed_number\":\"CAB-401\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 20:41:10\"}}', 0, '2026-09-17 18:41:10'),
(95, 20, 'DOCTOR', 'Patient Bed Moved: Jahid Hasan', 'Your patient Jahid Hasan has been relocated from Bed CAB-401 (Deluxe Cabin) to Bed EMG-101 (Emergency). Active rounds list updated.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Bed Moved: Jahid Hasan\",\"message\":\"Your patient Jahid Hasan has been relocated from Bed CAB-401 (Deluxe Cabin) to Bed EMG-101 (Emergency). Active rounds list updated.\",\"timestamp\":\"2026-09-17T20:41:10+02:00\",\"data\":{\"patient_id\":10,\"patient_name\":\"Jahid Hasan\",\"old_bed\":{\"bed_id\":301,\"bed_number\":\"CAB-401\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 20:41:10\"}}', 0, '2026-09-17 18:41:10'),
(96, 6, 'ADMIN', 'Relocation Recorded: Jahid Hasan', 'Patient Jahid Hasan moved from Bed CAB-401 to EMG-101.', 'PATIENT_BED_MOVED', '{\"event_id\":null,\"event\":\"PATIENT_BED_MOVED\",\"channel\":\"admin:6\",\"recipient_id\":6,\"recipient_type\":\"ADMIN\",\"title\":\"Relocation Recorded: Jahid Hasan\",\"message\":\"Patient Jahid Hasan moved from Bed CAB-401 to EMG-101.\",\"timestamp\":\"2026-09-17T20:41:10+02:00\",\"data\":{\"patient_id\":10,\"patient_name\":\"Jahid Hasan\",\"old_bed\":{\"bed_id\":301,\"bed_number\":\"CAB-401\",\"ward_type\":\"Deluxe Cabin\",\"floor_number\":4},\"new_bed\":{\"bed_id\":1,\"bed_number\":\"EMG-101\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"room_number\":\"Floor 1 - Emergency (Bed EMG-101)\",\"reason\":\"Routine clinical room relocation\",\"transferred_by\":\"Miraz\",\"timestamp\":\"2026-09-17 20:41:10\"}}', 0, '2026-09-17 18:41:10'),
(97, 47, 'DOCTOR', 'New Inpatient Assigned: Jahid Hasan', 'You have been assigned to inpatient care for Jahid Hasan.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"doctor:47\",\"recipient_id\":47,\"recipient_type\":\"DOCTOR\",\"title\":\"New Inpatient Assigned: Jahid Hasan\",\"message\":\"You have been assigned to inpatient care for Jahid Hasan.\",\"timestamp\":\"2026-09-17T20:41:16+02:00\",\"data\":{\"patient_id\":10,\"patient_name\":\"Jahid Hasan\",\"action\":\"ASSIGNED\",\"timestamp\":\"2026-09-17 20:41:16\"}}', 0, '2026-09-17 18:41:16'),
(98, 10, 'PATIENT', 'Care Team Updated', 'Your attending medical team has been updated by the clinical administration.', 'DOCTOR_ASSIGNED', '{\"event_id\":null,\"event\":\"DOCTOR_ASSIGNED\",\"channel\":\"patient:10\",\"recipient_id\":10,\"recipient_type\":\"PATIENT\",\"title\":\"Care Team Updated\",\"message\":\"Your attending medical team has been updated by the clinical administration.\",\"timestamp\":\"2026-09-17T20:41:16+02:00\",\"data\":{\"patient_id\":10,\"added_doctors\":[47],\"removed_doctors\":[20],\"timestamp\":\"2026-09-17 20:41:16\"}}', 0, '2026-09-17 18:41:16'),
(99, 1, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed EMG-104 (Emergency). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed EMG-104 (Emergency). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-17T21:07:26+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Clinical course completed. Stable for home recovery.\",\"discharged_at\":\"2026-09-17 21:07:26\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 19:07:26'),
(100, 20, 'DOCTOR', 'Patient Discharged: Agatsuma Zenitsu', 'Patient Agatsuma Zenitsu has been discharged from Bed EMG-104. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Agatsuma Zenitsu\",\"message\":\"Patient Agatsuma Zenitsu has been discharged from Bed EMG-104. Inpatient care completed.\",\"timestamp\":\"2026-09-17T21:07:26+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Clinical course completed. Stable for home recovery.\",\"discharged_at\":\"2026-09-17 21:07:26\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 19:07:26'),
(101, 18, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed EMG-106 (Emergency). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:18\",\"recipient_id\":18,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed EMG-106 (Emergency). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-17T21:07:49+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"released_bed\":{\"bed_id\":6,\"bed_number\":\"EMG-106\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Completed inpatient post-op care. Stable vital signs, discharged in ambulatory condition.\",\"discharged_at\":\"2026-09-17 21:07:49\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 19:07:49'),
(102, 20, 'DOCTOR', 'Patient Discharged: Robert Downey Jr.', 'Patient Robert Downey Jr. has been discharged from Bed EMG-106. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:20\",\"recipient_id\":20,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Robert Downey Jr.\",\"message\":\"Patient Robert Downey Jr. has been discharged from Bed EMG-106. Inpatient care completed.\",\"timestamp\":\"2026-09-17T21:07:49+02:00\",\"data\":{\"patient_id\":18,\"patient_name\":\"Robert Downey Jr.\",\"released_bed\":{\"bed_id\":6,\"bed_number\":\"EMG-106\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Completed inpatient post-op care. Stable vital signs, discharged in ambulatory condition.\",\"discharged_at\":\"2026-09-17 21:07:49\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 19:07:49'),
(103, 1, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed EMG-104 (Emergency). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed EMG-104 (Emergency). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-17T21:13:01+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Clinical recovery goals achieved. Patient discharged in stable condition.\",\"discharged_at\":\"2026-09-17 21:13:01\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 19:13:01'),
(104, 47, 'DOCTOR', 'Patient Discharged: Agatsuma Zenitsu', 'Patient Agatsuma Zenitsu has been discharged from Bed EMG-104. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:47\",\"recipient_id\":47,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Agatsuma Zenitsu\",\"message\":\"Patient Agatsuma Zenitsu has been discharged from Bed EMG-104. Inpatient care completed.\",\"timestamp\":\"2026-09-17T21:13:01+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":4,\"bed_number\":\"EMG-104\",\"ward_type\":\"Emergency\",\"floor_number\":1},\"discharge_summary\":\"Clinical recovery goals achieved. Patient discharged in stable condition.\",\"discharged_at\":\"2026-09-17 21:13:01\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-17 19:13:01'),
(105, 28, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed PRES-401 (Presidential Suite). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:28\",\"recipient_id\":28,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed PRES-401 (Presidential Suite). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-18T15:05:22+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"released_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"discharge_summary\":\"Clinical discharge approved. Inpatient treatment cycle completed.\",\"discharged_at\":\"2026-09-18 15:05:22\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-18 13:05:22'),
(106, 43, 'DOCTOR', 'Patient Discharged: Kibutsuji Muzan', 'Patient Kibutsuji Muzan has been discharged from Bed PRES-401. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Kibutsuji Muzan\",\"message\":\"Patient Kibutsuji Muzan has been discharged from Bed PRES-401. Inpatient care completed.\",\"timestamp\":\"2026-09-18T15:05:22+02:00\",\"data\":{\"patient_id\":28,\"patient_name\":\"Kibutsuji Muzan\",\"released_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"discharge_summary\":\"Clinical discharge approved. Inpatient treatment cycle completed.\",\"discharged_at\":\"2026-09-18 15:05:22\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-18 13:05:22'),
(107, 1, 'PATIENT', 'Discharge Completed', 'You have been formally discharged from Bed PRES-401 (Presidential Suite). Wishing you a swift recovery!', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"patient:1\",\"recipient_id\":1,\"recipient_type\":\"PATIENT\",\"title\":\"Discharge Completed\",\"message\":\"You have been formally discharged from Bed PRES-401 (Presidential Suite). Wishing you a swift recovery!\",\"timestamp\":\"2026-09-18T15:06:18+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"discharge_summary\":\"Clinical discharge approved. Inpatient treatment cycle completed.\",\"discharged_at\":\"2026-09-18 15:06:18\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-18 13:06:18'),
(108, 43, 'DOCTOR', 'Patient Discharged: Agatsuma Zenitsu', 'Patient Agatsuma Zenitsu has been discharged from Bed PRES-401. Inpatient care completed.', 'PATIENT_DISCHARGED', '{\"event_id\":null,\"event\":\"PATIENT_DISCHARGED\",\"channel\":\"doctor:43\",\"recipient_id\":43,\"recipient_type\":\"DOCTOR\",\"title\":\"Patient Discharged: Agatsuma Zenitsu\",\"message\":\"Patient Agatsuma Zenitsu has been discharged from Bed PRES-401. Inpatient care completed.\",\"timestamp\":\"2026-09-18T15:06:18+02:00\",\"data\":{\"patient_id\":1,\"patient_name\":\"Agatsuma Zenitsu\",\"released_bed\":{\"bed_id\":380,\"bed_number\":\"PRES-401\",\"ward_type\":\"Presidential Suite\",\"floor_number\":4},\"discharge_summary\":\"Clinical discharge approved. Inpatient treatment cycle completed.\",\"discharged_at\":\"2026-09-18 15:06:18\",\"discharged_by\":\"Miraz\"}}', 0, '2026-09-18 13:06:18');

-- --------------------------------------------------------

--
-- Table structure for table `patient_doctor_assignments`
--

CREATE TABLE `patient_doctor_assignments` (
  `assignment_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `notes` varchar(255) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `active_pair` varchar(64) GENERATED ALWAYS AS (if(`status` = 'Active',concat(`patient_id`,':',`doctor_id`),NULL)) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `patient_doctor_assignments`
--

INSERT INTO `patient_doctor_assignments` (`assignment_id`, `patient_id`, `doctor_id`, `assigned_by`, `is_primary`, `status`, `notes`, `assigned_at`, `ended_at`) VALUES
(1, 5, 21, NULL, 1, 'Inactive', NULL, '2026-09-12 11:30:00', '2026-09-18 00:29:58'),
(2, 10, 20, NULL, 1, 'Inactive', NULL, '2026-09-13 09:15:00', '2026-09-18 00:41:16'),
(4, 1, 43, NULL, 1, 'Inactive', NULL, '2026-09-17 15:40:47', '2026-09-18 00:29:47'),
(5, 28, 13, NULL, 0, 'Inactive', NULL, '2026-09-17 16:15:52', '2026-09-18 19:05:22'),
(12, 18, 20, NULL, 1, 'Inactive', NULL, '2026-09-17 16:56:04', '2026-09-17 16:56:04'),
(14, 18, 20, NULL, 1, 'Inactive', NULL, '2026-09-17 16:56:08', '2026-09-17 18:24:01'),
(15, 16, 8, 6, 0, 'Active', NULL, '2026-09-17 17:49:20', NULL),
(16, 18, 29, 6, 0, 'Inactive', NULL, '2026-09-17 17:49:26', '2026-09-17 18:25:11'),
(17, 18, 33, 6, 0, 'Inactive', NULL, '2026-09-17 18:24:01', NULL),
(18, 16, 20, 6, 0, 'Active', NULL, '2026-09-17 18:25:00', NULL),
(19, 18, 43, 6, 0, 'Inactive', NULL, '2026-09-17 18:25:11', NULL),
(20, 28, 14, 6, 0, 'Inactive', NULL, '2026-09-17 18:26:18', '2026-09-18 19:05:22'),
(21, 28, 21, 6, 1, 'Inactive', NULL, '2026-09-17 18:26:26', '2026-09-18 19:05:22'),
(22, 16, 29, 6, 1, 'Active', NULL, '2026-09-17 18:41:52', NULL),
(23, 18, 14, 6, 1, 'Inactive', NULL, '2026-09-17 18:49:00', NULL),
(24, 38, 29, 6, 1, 'Active', NULL, '2026-09-17 22:12:41', NULL),
(25, 1, 47, 6, 1, 'Inactive', NULL, '2026-09-18 00:29:47', '2026-09-18 00:40:39'),
(26, 5, 47, 6, 1, 'Inactive', NULL, '2026-09-18 00:29:58', '2026-09-18 00:40:43'),
(27, 10, 47, 6, 1, 'Active', NULL, '2026-09-18 00:41:16', NULL),
(28, 19, 47, 6, 1, 'Active', NULL, '2026-09-18 00:43:15', NULL),
(29, 1, 20, 6, 1, 'Inactive', 'Lead emergency specialist assigned.', '2026-09-18 01:06:04', NULL),
(30, 1, 20, 6, 1, 'Inactive', 'Lead emergency specialist assigned.', '2026-09-18 01:06:26', NULL),
(31, 1, 20, 6, 1, 'Inactive', 'Lead emergency specialist assigned.', '2026-09-18 01:06:40', NULL),
(32, 1, 20, 6, 1, 'Inactive', 'Lead emergency specialist assigned.', '2026-09-18 01:06:59', NULL),
(33, 1, 20, 6, 1, 'Inactive', 'Lead emergency specialist assigned.', '2026-09-18 01:07:13', NULL),
(34, 1, 20, 6, 1, 'Inactive', 'Lead emergency specialist assigned.', '2026-09-18 01:07:26', '2026-09-18 01:07:26'),
(35, 18, 20, 6, 1, 'Inactive', 'Lead specialist assigned.', '2026-09-18 01:07:49', '2026-09-18 01:07:49'),
(36, 1, 47, 6, 1, 'Inactive', NULL, '2026-09-18 01:12:44', '2026-09-18 01:13:01'),
(37, 28, 43, 6, 1, 'Inactive', NULL, '2026-09-18 19:04:37', '2026-09-18 19:05:22'),
(38, 1, 43, 6, 1, 'Inactive', NULL, '2026-09-18 19:05:39', '2026-09-18 19:06:18'),
(39, 1, 47, 6, 1, 'Active', NULL, '2026-09-20 23:12:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `prescription_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `diagnosis_notes` text DEFAULT NULL,
  `vitals_summary` varchar(255) DEFAULT NULL,
  `prescribed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `prescriptions`
--

INSERT INTO `prescriptions` (`prescription_id`, `patient_id`, `doctor_id`, `appointment_id`, `diagnosis_notes`, `vitals_summary`, `prescribed_at`, `created_at`) VALUES
(1, 1, 21, 1, 'Tension-type cephalalgia with mild somatic fatigue and stress insomnia.', 'BP: 120/80 mmHg, Pulse: 74 bpm, Weight: 68 kg', '2026-09-15 05:15:00', '2026-09-16 20:22:01'),
(2, 5, 14, 2, 'Acute contact dermatitis with localized erythematous lesions on forearm.', 'BP: 115/75 mmHg, Pulse: 78 bpm, Weight: 54 kg', '2026-09-15 10:45:00', '2026-09-16 20:22:01'),
(3, 18, 29, 5, 'Mild dyslipidemia and executive metabolic stress indicators.', 'BP: 125/82 mmHg, Pulse: 72 bpm, Weight: 76 kg', '2026-09-15 12:00:00', '2026-09-16 20:22:01');

-- --------------------------------------------------------

--
-- Table structure for table `prescription_items`
--

CREATE TABLE `prescription_items` (
  `item_id` int(11) NOT NULL,
  `prescription_id` int(11) NOT NULL,
  `medicine_name` varchar(150) NOT NULL,
  `dosage` varchar(50) NOT NULL,
  `duration` varchar(50) NOT NULL,
  `special_instructions` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `prescription_items`
--

INSERT INTO `prescription_items` (`item_id`, `prescription_id`, `medicine_name`, `dosage`, `duration`, `special_instructions`, `created_at`) VALUES
(1, 1, 'Napa Extra (500mg)', '1+0+1', '7 Days', 'Take after meals with plenty of water', '2026-09-16 20:22:01'),
(2, 1, 'Monas 10mg (Montelukast)', '0+0+1', '15 Days', 'Take once daily at bedtime', '2026-09-16 20:22:01'),
(3, 1, 'Neuro-B Complex', '1+0+1', '30 Days', 'Daily vitamin supplement after meals', '2026-09-16 20:22:01'),
(4, 2, 'Fexo 120mg (Fexofenadine)', '1+0+1', '10 Days', 'Take after meals for itch relief', '2026-09-16 20:22:01'),
(5, 2, 'Dermasol-N Ointment', 'Apply 2x Daily', '7 Days', 'Clean skin and apply topically', '2026-09-16 20:22:01'),
(6, 3, 'Lipiget 10mg (Atorvastatin)', '0+0+1', '30 Days', 'Take at night before sleep', '2026-09-16 20:22:01'),
(7, 3, 'CoQ10 100mg Dietary Enzyme', '1+0+0', '30 Days', 'Take in morning with breakfast', '2026-09-16 20:22:01');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `gender` enum('Male','Female') NOT NULL DEFAULT 'Male',
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Patient','Doctor','Staff','Admin') NOT NULL DEFAULT 'Patient',
  `status` enum('pending','active','rejected','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `blood_group` varchar(10) DEFAULT 'B+',
  `age` int(11) DEFAULT 22,
  `date_of_birth` date DEFAULT NULL,
  `prescriptions` text DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `license_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `gender`, `password_hash`, `role`, `status`, `created_at`, `blood_group`, `age`, `date_of_birth`, `prescriptions`, `department`, `license_id`) VALUES
(1, 'Agatsuma Zenitsu', 'afzalhossain.miraz@gmail.com', '01783203318', 'Male', '$2y$12$pF3GaBNjv0JQNiqV97PdJOw44P7GqwQ3hA5XEr5mEEUppzgsceYIy', 'Patient', 'active', '2026-09-11 15:45:28', 'B+', 22, NULL, NULL, NULL, NULL),
(5, 'Nusrat Jahan', 'nusrat.jahan@medpulse.test', '01812345678', 'Female', '$2y$12$fgDxuBJH30Zgfjm8KeU8BOR710dlfLV9Ax5Bx18kguhyiF2q4a1Gq', 'Patient', 'active', '2026-09-11 17:08:12', 'B+', 22, NULL, NULL, NULL, NULL),
(6, 'Miraz', 'admin@medpulse.org', '01700000000', 'Male', '$2y$10$a4fqeh4HQJxShcyhih4jI.4kh3MzMvZCmZWHe2kfsNwZGBEe5PEjm', 'Admin', 'active', '2026-09-11 17:39:22', 'B+', 22, NULL, NULL, NULL, NULL),
(8, 'Dr. Rafiqul Islam', 'dr.rafiq@medpulse.test', '01711122233', 'Male', '$2y$12$cCjKp2iPuBE46X6KPt0a7ekjWKdZgDnoXxHde7yvW/244UlZVg8ze', 'Doctor', 'active', '2026-09-11 17:50:13', 'B+', 22, NULL, NULL, 'Cardiology & Cardiac Care', 'BMDC-A-48921'),
(9, 'Farhana Akter', 'farhana.staff@medpulse.test', '01822334455', 'Female', '$2y$12$cWghiR/rub4nOvrgOInsUup6EOZGfclnbGVvl818tPkCVNzkgMRdK', 'Staff', 'rejected', '2026-09-11 17:50:30', 'B+', 22, NULL, NULL, 'Inpatient Nursing & Triage', 'STF-MED-208'),
(10, 'Jahid Hasan', 'jahid.patient@medpulse.test', '01933445566', 'Male', '$2y$12$B5uzHCytdNMvls1DX.b6Nu7/0w7F34X.tAWOvhMl9R6X7hYlhhoEm', 'Patient', 'active', '2026-09-11 17:50:39', 'B+', 22, NULL, NULL, NULL, NULL),
(11, 'Dr. Tahsin Mahmud', 'tahsin.mahmud@medpulse.test', '01755667788', 'Male', '$2y$12$pBDaMD4hn8DC.mb/Kxfe1OEY7hvz6thWr1qFNCeraDilOy.QS4R.G', 'Doctor', 'rejected', '2026-09-11 17:52:39', 'B+', 22, NULL, NULL, NULL, NULL),
(12, 'Sumaiya Noor', 'sumaiya.noor@medpulse.test', '01644332211', 'Female', '$2y$12$CNpQuvFRMIvFUPBr/OgZk.cfiLYrj/b1jTAB/vGi25V4XVSWTwLzu', 'Staff', 'rejected', '2026-09-11 17:52:39', 'B+', 22, NULL, NULL, 'Pathology & Diagnostic Laboratory', 'STF-MED-312'),
(13, 'Dr. Miftahul Sheikh', 'miftahul@medpulse.org', '01855555555', 'Male', '$2y$12$iX8oTqMdtOzTyUSJM39YvO/0zFWwk/H8bJv28f0TwZN.XlgzaZ8zu', 'Doctor', 'active', '2026-09-11 17:54:27', 'B+', 22, NULL, NULL, 'Internal Medicine & Critical Care', 'BMDC-A-51042'),
(14, 'Dr. Mitsuha', 'mitsuha@medpulse.org', '01783203388', 'Female', '$2y$12$c2tNi3CGwynM9y7RMoswDueE7aT2Z8EZmyvhYQjiLMtXk.UEDEOK6', 'Doctor', 'active', '2026-09-11 18:20:29', 'B+', 22, NULL, NULL, 'Dermatology & Skin Aesthetics', 'BMDC-A-62914'),
(15, 'Ms. Shinobu', 'shinobu@medpulse.org', '01756789159', 'Male', '$2y$12$f9jsGZ5zWccOL6LmcWyavukaN1Mhui2dKGR2sqttqfY8P13pdjiVu', 'Staff', 'active', '2026-09-11 18:23:25', 'B+', 22, NULL, NULL, 'Pharmacy & Clinical Dispensary', 'STF-MED-104'),
(16, 'Sabbir Ahmed', 'sabbir.ahmed@medpulse.test', '01766554433', 'Male', '$2y$12$32Vvawh0e952yWOUpqIj6OfICq5RjjcNw.5fC8ywlnE.Hbc97zqq6', 'Patient', 'active', '2026-09-11 18:55:50', 'B+', 22, NULL, NULL, NULL, NULL),
(18, 'Robert Downey Jr.', 'robertdowney@medpulse.com', '01578530163', 'Male', '$2y$12$9/xcBRRb7hlokADbPDGCIu5.L7fa/yXL8MP3bIPTNM.gklo3YdJwG', 'Patient', 'active', '2026-09-12 05:56:53', 'B+', 22, NULL, NULL, NULL, NULL),
(19, 'Miraz', 'miraz@gmail.com', '01712345678', 'Male', '$2y$12$GWaKvgLtaNtx4zc27yP13e86crQZg89rTWAKe2XQ4PJ7SsgWP5UsS', 'Patient', 'active', '2026-09-12 06:36:51', 'B+', 22, NULL, NULL, NULL, NULL),
(20, 'Minhazul Islam Alvi', 'drmikasa@medpulse.com', '01799887766', 'Female', '$2y$12$ydv/njDRdSKz6l9k/8aWsu6j5vqqbZilokXhdxjoo9ZLSWgfB7LBS', 'Doctor', 'active', '2026-09-12 06:50:08', 'B+', 22, NULL, NULL, 'Gynecology & Obstetrics', 'BMDC-A-73891'),
(21, 'Dr. Satoru Gojo', 'soturogojo@gmail.com', '01352890132', 'Male', '$2y$12$wxjkc7DAgfbAG/K8MQGTeO8rzGyOogn5aM3TG5NGzOEPwZxHnGEMm', 'Doctor', 'active', '2026-09-12 07:22:03', 'B+', 22, NULL, NULL, 'Neurology & Advanced Diagnostics', 'BMDC-A-10029'),
(28, 'Kibutsuji Muzan', 'muzan@gmail.com', '01783203317', 'Male', '$2y$12$ZxbZAkG1EeKoaotYgfe4megUktcFQzte7PJ3gq.2913oEWwOn1KZi', 'Patient', 'active', '2026-09-12 07:37:49', 'B+', 22, NULL, NULL, NULL, NULL),
(29, 'Dr. Afzal Hossain Miraz', 'drmiraz@gmail.com', '01715158160', 'Male', '$2y$12$j7siCv9HT5XFih7P.t.5S.KesyuiY0b4J6V6PiavHL/NBb2jvMLgi', 'Doctor', 'active', '2026-09-12 07:58:49', 'B+', 22, NULL, NULL, 'Emergency Medicine & Anesthesia', 'BMDC-A-39904'),
(30, 'Joro', 'joro@gmail.com', '01383620372', 'Male', '$2y$12$FzRWSptpN14wOxu6BcVdyOd1f3CiV00wqLC/2Ng3ANMaT/AQoVhVy', 'Patient', 'active', '2026-09-16 15:32:51', 'B+', 22, NULL, NULL, NULL, NULL),
(33, 'Dr. Ayesha Siddiqua', 'ayesha.siddiqua@medpulse.org', '01711223344', 'Female', '$2y$12$haltJF9SOUVypHaNjP8Yce4vd7q8STPQHRzawmfWq9CXQxP4imzLS', 'Doctor', 'active', '2026-09-16 18:52:42', 'B+', 22, NULL, NULL, 'Cardiology & Intensive Care', 'BMDC-A-94120'),
(34, 'Tanvir Chowdhury', 'tanvir.chowdhury@medpulse.org', '01811223355', 'Male', '$2y$12$haltJF9SOUVypHaNjP8Yce4vd7q8STPQHRzawmfWq9CXQxP4imzLS', 'Staff', 'rejected', '2026-09-16 18:52:42', 'B+', 22, NULL, NULL, 'Pathology & Diagnostic Laboratory', 'STF-8841'),
(35, 'Dr. Test Physician', 'dr.test.1789586057@medpulse.test', '01730224158', 'Male', '$2y$12$CHN/7V8ufW7x.RVzyTmYoO70/pitCzNnb9XSkKLNREtW/XcugdNQ.', 'Doctor', 'active', '2026-09-16 19:14:17', 'B+', 22, NULL, NULL, NULL, NULL),
(38, 'Chris Evans', 'chrisevans@gmail.com', '01653789215', 'Male', '$2y$12$QvA2RkhAV9LD2eOtLOSGmeAe2XxSsyteiWVzhlTylaIBL/9g7Jup6', 'Patient', 'active', '2026-09-16 20:47:49', 'B+', 22, NULL, NULL, NULL, NULL),
(43, 'Minhazul Islam Alvi', 'minhazul@medpulse.org', '01683560758', 'Male', '$2y$12$K/2JOOmOYNWVH/IHbvI8Wue2pBsM2rjtwm14nqmFtXIp39ZRj2SYm', 'Doctor', 'active', '2026-09-17 09:33:00', 'B+', 22, NULL, NULL, 'Orthopedics & Trauma Surgery', NULL),
(44, 'Dr. Sarah Connor', 'sarah.connor.1789665400@medpulse.test', '01736779151', 'Female', '$2y$12$2DdfU8fPYdsynvAlyl1X1..LdWyo3HX7uc3goFHpsgWwsqml/Ktkm', 'Doctor', 'active', '2026-09-17 17:16:40', 'B+', 22, NULL, NULL, 'Critical Care Medicine & Trauma', 'BMDC-A-83845'),
(47, 'Afrina Hossain Riana', 'dr.riana@medpulse.org', '01783203389', 'Female', '$2y$12$XBNTw98ziEtPorkIyEbplu0LiBn1R5MaQhgpX562SGFdovqs47duq', 'Doctor', 'active', '2026-09-17 18:25:42', 'B+', 22, NULL, NULL, 'Neurology & Neurosurgery', 'A-71245'),
(48, 'Dr. Maisa Rahman Tinu', 'dr.maisa@medpulse.org', '01683560765', 'Female', '$2y$12$PnruVQ8ZkkQgTinGKr/nvu2R3DjrfElwtqHs0xf9BONz0w3zKjrcC', 'Doctor', 'active', '2026-09-17 18:34:28', 'B+', 22, NULL, NULL, 'Gynecology & Obstetrics', 'A-24509'),
(54, 'Dr. Roy Mustang', 'dr.roy.mustang@alchemymed.org', '01718882233', 'Male', '$2y$12$DO8exx4l1K9R9p6NYnW6PeZxOSRwrgxkdVIIif2NQWjerlcyjCsOm', 'Doctor', 'rejected', '2026-09-17 18:47:04', 'B+', 22, NULL, NULL, 'Internal Medicine & Critical Care', 'BMDC-A-88442'),
(56, 'Dr. Riza Hawkeye', 'dr.riza.hawkeye@alchemymed.org', '01712334455', 'Female', '$2y$12$DF4isfSWOgkQg2GfoFbGkuCiUeqUVVZTvz6uBtnmvqeHxtCCZiHLO', 'Doctor', 'active', '2026-09-17 18:47:18', 'B+', 22, NULL, NULL, 'Cardiothoracic Surgery & ICU', 'BMDC-A-77331'),
(57, 'Dr. Edward Elric', 'dr.edward.elric@alchemymed.org', '01719998811', 'Male', '$2y$12$lcT4njQ4SYgruL8P3GDxKuJvg1ElZQKkFUQpbvWP6lmFYdwxrvl96', 'Doctor', 'active', '2026-09-17 18:47:32', 'B+', 22, NULL, NULL, 'Traumatology & Bio-Alchemy', 'BMDC-A-99123'),
(58, 'Dr. Tom Holland', 'dr.tom@medpulse.org', '01783203309', 'Male', '$2y$12$ZH2nXFXXJa1h009h82c9qes5NCt8g764AlgnhfBZpjDiLD4r05PBq', 'Doctor', 'rejected', '2026-09-17 18:50:38', 'B+', 22, NULL, NULL, 'Gynecology & Obstetrics', 'A-24508');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `idx_appointments_patient` (`patient_id`),
  ADD KEY `idx_appointments_doctor` (`doctor_id`),
  ADD KEY `idx_appointments_status_date` (`status`,`appointment_date`),
  ADD KEY `idx_appointments_doc_date_serial` (`doctor_id`,`appointment_date`,`serial_number`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_audit_actor` (`actor_id`),
  ADD KEY `idx_audit_level` (`security_level`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `bed_allocations`
--
ALTER TABLE `bed_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD UNIQUE KEY `uq_active_patient` (`active_patient_id`),
  ADD UNIQUE KEY `uq_active_bed` (`active_bed_id`),
  ADD KEY `idx_bed_alloc_bed` (`bed_id`),
  ADD KEY `idx_bed_alloc_patient` (`patient_id`),
  ADD KEY `idx_bed_alloc_doctor` (`attending_doctor_id`),
  ADD KEY `idx_bed_alloc_status` (`status`);

--
-- Indexes for table `bed_transfer_history`
--
ALTER TABLE `bed_transfer_history`
  ADD PRIMARY KEY (`transfer_id`),
  ADD KEY `idx_patient_transfers` (`patient_id`,`transferred_at`),
  ADD KEY `idx_from_bed` (`from_bed_id`),
  ADD KEY `idx_to_bed` (`to_bed_id`),
  ADD KEY `fk_bth_admin` (`transferred_by`);

--
-- Indexes for table `diagnostic_reports`
--
ALTER TABLE `diagnostic_reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `idx_diagnostic_patient` (`patient_id`),
  ADD KEY `idx_diagnostic_doctor` (`requested_by_doctor_id`),
  ADD KEY `idx_diagnostic_status` (`delivery_status`),
  ADD KEY `idx_diagnostic_category` (`test_category`);

--
-- Indexes for table `doctor_earnings`
--
ALTER TABLE `doctor_earnings`
  ADD PRIMARY KEY (`earning_id`),
  ADD KEY `idx_de_doctor` (`doctor_id`),
  ADD KEY `idx_de_invoice` (`invoice_id`),
  ADD KEY `idx_de_status` (`disbursement_status`);

--
-- Indexes for table `doctor_profiles`
--
ALTER TABLE `doctor_profiles`
  ADD PRIMARY KEY (`doctor_id`),
  ADD UNIQUE KEY `idx_doc_user_id` (`user_id`),
  ADD UNIQUE KEY `idx_doc_bmdc_license` (`bmdc_license_number`),
  ADD UNIQUE KEY `unique_bmdc` (`bmdc_reg_number`),
  ADD KEY `idx_doc_specialty` (`specialty`);

--
-- Indexes for table `hospital_beds`
--
ALTER TABLE `hospital_beds`
  ADD PRIMARY KEY (`bed_id`),
  ADD UNIQUE KEY `idx_beds_bed_number` (`bed_number`),
  ADD KEY `idx_beds_ward_status` (`ward_type`,`status`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `idx_invoices_number` (`invoice_number`),
  ADD KEY `idx_invoices_patient` (`patient_id`),
  ADD KEY `idx_invoices_generated_by` (`generated_by`),
  ADD KEY `idx_invoices_status` (`status`),
  ADD KEY `idx_invoices_created` (`created_at`),
  ADD KEY `idx_invoices_admission` (`admission_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_invoice_items_inv` (`invoice_id`),
  ADD KEY `idx_invoice_items_type` (`item_type`),
  ADD KEY `idx_invoice_items_doctor` (`doctor_id`),
  ADD KEY `idx_invoice_items_payout_status` (`doctor_payout_status`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient` (`recipient_id`,`recipient_type`,`is_read`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_event_type` (`event_type`);

--
-- Indexes for table `patient_doctor_assignments`
--
ALTER TABLE `patient_doctor_assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD UNIQUE KEY `uq_active_patient_doctor` (`active_pair`),
  ADD KEY `idx_patient_status` (`patient_id`,`status`),
  ADD KEY `idx_doctor_status` (`doctor_id`,`status`),
  ADD KEY `fk_pda_admin` (`assigned_by`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`prescription_id`),
  ADD KEY `idx_prescriptions_patient` (`patient_id`),
  ADD KEY `idx_prescriptions_doctor` (`doctor_id`),
  ADD KEY `idx_prescriptions_appointment` (`appointment_id`),
  ADD KEY `idx_prescriptions_date` (`prescribed_at`);

--
-- Indexes for table `prescription_items`
--
ALTER TABLE `prescription_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_prescription_items_rx` (`prescription_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `unique_email` (`email`),
  ADD UNIQUE KEY `unique_phone` (`phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `bed_allocations`
--
ALTER TABLE `bed_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `bed_transfer_history`
--
ALTER TABLE `bed_transfer_history`
  MODIFY `transfer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `diagnostic_reports`
--
ALTER TABLE `diagnostic_reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `doctor_earnings`
--
ALTER TABLE `doctor_earnings`
  MODIFY `earning_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `doctor_profiles`
--
ALTER TABLE `doctor_profiles`
  MODIFY `doctor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `hospital_beds`
--
ALTER TABLE `hospital_beds`
  MODIFY `bed_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=501;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `patient_doctor_assignments`
--
ALTER TABLE `patient_doctor_assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `prescription_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `prescription_items`
--
ALTER TABLE `prescription_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `bed_allocations`
--
ALTER TABLE `bed_allocations`
  ADD CONSTRAINT `fk_bed_alloc_bed` FOREIGN KEY (`bed_id`) REFERENCES `hospital_beds` (`bed_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bed_alloc_doctor` FOREIGN KEY (`attending_doctor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bed_alloc_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `bed_transfer_history`
--
ALTER TABLE `bed_transfer_history`
  ADD CONSTRAINT `fk_bth_admin` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `fk_bth_from_bed` FOREIGN KEY (`from_bed_id`) REFERENCES `hospital_beds` (`bed_id`),
  ADD CONSTRAINT `fk_bth_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bth_to_bed` FOREIGN KEY (`to_bed_id`) REFERENCES `hospital_beds` (`bed_id`);

--
-- Constraints for table `diagnostic_reports`
--
ALTER TABLE `diagnostic_reports`
  ADD CONSTRAINT `fk_diagnostic_doctor` FOREIGN KEY (`requested_by_doctor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_diagnostic_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `doctor_earnings`
--
ALTER TABLE `doctor_earnings`
  ADD CONSTRAINT `fk_de_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_de_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `doctor_profiles`
--
ALTER TABLE `doctor_profiles`
  ADD CONSTRAINT `fk_doctor_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_admission` FOREIGN KEY (`admission_id`) REFERENCES `bed_allocations` (`allocation_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoice_items_inv` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `patient_doctor_assignments`
--
ALTER TABLE `patient_doctor_assignments`
  ADD CONSTRAINT `fk_pda_admin` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pda_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pda_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prescriptions_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_prescriptions_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `prescription_items`
--
ALTER TABLE `prescription_items`
  ADD CONSTRAINT `fk_prescription_items_rx` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`prescription_id`) ON DELETE CASCADE ON UPDATE CASCADE;
--
-- Database: `phpmyadmin`
--
CREATE DATABASE IF NOT EXISTS `phpmyadmin` DEFAULT CHARACTER SET utf8 COLLATE utf8_bin;
USE `phpmyadmin`;

-- --------------------------------------------------------

--
-- Table structure for table `pma__bookmark`
--

CREATE TABLE `pma__bookmark` (
  `id` int(11) NOT NULL,
  `dbase` varchar(255) NOT NULL DEFAULT '',
  `user` varchar(255) NOT NULL DEFAULT '',
  `label` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `query` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Bookmarks';

-- --------------------------------------------------------

--
-- Table structure for table `pma__central_columns`
--

CREATE TABLE `pma__central_columns` (
  `db_name` varchar(64) NOT NULL,
  `col_name` varchar(64) NOT NULL,
  `col_type` varchar(64) NOT NULL,
  `col_length` text DEFAULT NULL,
  `col_collation` varchar(64) NOT NULL,
  `col_isNull` tinyint(1) NOT NULL,
  `col_extra` varchar(255) DEFAULT '',
  `col_default` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Central list of columns';

-- --------------------------------------------------------

--
-- Table structure for table `pma__column_info`
--

CREATE TABLE `pma__column_info` (
  `id` int(5) UNSIGNED NOT NULL,
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `column_name` varchar(64) NOT NULL DEFAULT '',
  `comment` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `mimetype` varchar(255) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '',
  `transformation` varchar(255) NOT NULL DEFAULT '',
  `transformation_options` varchar(255) NOT NULL DEFAULT '',
  `input_transformation` varchar(255) NOT NULL DEFAULT '',
  `input_transformation_options` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Column information for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__designer_settings`
--

CREATE TABLE `pma__designer_settings` (
  `username` varchar(64) NOT NULL,
  `settings_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Settings related to Designer';

-- --------------------------------------------------------

--
-- Table structure for table `pma__export_templates`
--

CREATE TABLE `pma__export_templates` (
  `id` int(5) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL,
  `export_type` varchar(10) NOT NULL,
  `template_name` varchar(64) NOT NULL,
  `template_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Saved export templates';

-- --------------------------------------------------------

--
-- Table structure for table `pma__favorite`
--

CREATE TABLE `pma__favorite` (
  `username` varchar(64) NOT NULL,
  `tables` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Favorite tables';

-- --------------------------------------------------------

--
-- Table structure for table `pma__history`
--

CREATE TABLE `pma__history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL DEFAULT '',
  `db` varchar(64) NOT NULL DEFAULT '',
  `table` varchar(64) NOT NULL DEFAULT '',
  `timevalue` timestamp NOT NULL DEFAULT current_timestamp(),
  `sqlquery` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='SQL history for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__navigationhiding`
--

CREATE TABLE `pma__navigationhiding` (
  `username` varchar(64) NOT NULL,
  `item_name` varchar(64) NOT NULL,
  `item_type` varchar(64) NOT NULL,
  `db_name` varchar(64) NOT NULL,
  `table_name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Hidden items of navigation tree';

-- --------------------------------------------------------

--
-- Table structure for table `pma__pdf_pages`
--

CREATE TABLE `pma__pdf_pages` (
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `page_nr` int(10) UNSIGNED NOT NULL,
  `page_descr` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='PDF relation pages for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__recent`
--

CREATE TABLE `pma__recent` (
  `username` varchar(64) NOT NULL,
  `tables` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Recently accessed tables';

--
-- Dumping data for table `pma__recent`
--

INSERT INTO `pma__recent` (`username`, `tables`) VALUES
('root', '[{\"db\":\"medpulse_hms\",\"table\":\"users\"},{\"db\":\"medpulse_hms\",\"table\":\"doctor_profiles\"},{\"db\":\"medpulse_hms\",\"table\":\"hospital_beds\"},{\"db\":\"medpulse_hms\",\"table\":\"invoice_items\"},{\"db\":\"medpulse_hms\",\"table\":\"invoices\"},{\"db\":\"medpulse_hms\",\"table\":\"audit_logs\"},{\"db\":\"medpulse_hms\",\"table\":\"bed_allocations\"},{\"db\":\"medpulse_hms\",\"table\":\"appointments\"},{\"db\":\"medpulse_hms\",\"table\":\"diagnostic_reports\"},{\"db\":\"medpulse_hms\",\"table\":\"prescription_items\"}]');

-- --------------------------------------------------------

--
-- Table structure for table `pma__relation`
--

CREATE TABLE `pma__relation` (
  `master_db` varchar(64) NOT NULL DEFAULT '',
  `master_table` varchar(64) NOT NULL DEFAULT '',
  `master_field` varchar(64) NOT NULL DEFAULT '',
  `foreign_db` varchar(64) NOT NULL DEFAULT '',
  `foreign_table` varchar(64) NOT NULL DEFAULT '',
  `foreign_field` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Relation table';

-- --------------------------------------------------------

--
-- Table structure for table `pma__savedsearches`
--

CREATE TABLE `pma__savedsearches` (
  `id` int(5) UNSIGNED NOT NULL,
  `username` varchar(64) NOT NULL DEFAULT '',
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `search_name` varchar(64) NOT NULL DEFAULT '',
  `search_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Saved searches';

-- --------------------------------------------------------

--
-- Table structure for table `pma__table_coords`
--

CREATE TABLE `pma__table_coords` (
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `pdf_page_number` int(11) NOT NULL DEFAULT 0,
  `x` float UNSIGNED NOT NULL DEFAULT 0,
  `y` float UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Table coordinates for phpMyAdmin PDF output';

-- --------------------------------------------------------

--
-- Table structure for table `pma__table_info`
--

CREATE TABLE `pma__table_info` (
  `db_name` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL DEFAULT '',
  `display_field` varchar(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Table information for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__table_uiprefs`
--

CREATE TABLE `pma__table_uiprefs` (
  `username` varchar(64) NOT NULL,
  `db_name` varchar(64) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `prefs` text NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Tables'' UI preferences';

--
-- Dumping data for table `pma__table_uiprefs`
--

INSERT INTO `pma__table_uiprefs` (`username`, `db_name`, `table_name`, `prefs`, `last_update`) VALUES
('root', 'medpulse_hms', 'users', '{\"sorted_col\":\"`gender` DESC\"}', '2026-09-11 19:06:38');

-- --------------------------------------------------------

--
-- Table structure for table `pma__tracking`
--

CREATE TABLE `pma__tracking` (
  `db_name` varchar(64) NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `version` int(10) UNSIGNED NOT NULL,
  `date_created` datetime NOT NULL,
  `date_updated` datetime NOT NULL,
  `schema_snapshot` text NOT NULL,
  `schema_sql` text DEFAULT NULL,
  `data_sql` longtext DEFAULT NULL,
  `tracking` set('UPDATE','REPLACE','INSERT','DELETE','TRUNCATE','CREATE DATABASE','ALTER DATABASE','DROP DATABASE','CREATE TABLE','ALTER TABLE','RENAME TABLE','DROP TABLE','CREATE INDEX','DROP INDEX','CREATE VIEW','ALTER VIEW','DROP VIEW') DEFAULT NULL,
  `tracking_active` int(1) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Database changes tracking for phpMyAdmin';

-- --------------------------------------------------------

--
-- Table structure for table `pma__userconfig`
--

CREATE TABLE `pma__userconfig` (
  `username` varchar(64) NOT NULL,
  `timevalue` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `config_data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='User preferences storage for phpMyAdmin';

--
-- Dumping data for table `pma__userconfig`
--

INSERT INTO `pma__userconfig` (`username`, `timevalue`, `config_data`) VALUES
('root', '2026-09-21 17:18:39', '{\"Console\\/Mode\":\"collapse\"}');

-- --------------------------------------------------------

--
-- Table structure for table `pma__usergroups`
--

CREATE TABLE `pma__usergroups` (
  `usergroup` varchar(64) NOT NULL,
  `tab` varchar(64) NOT NULL,
  `allowed` enum('Y','N') NOT NULL DEFAULT 'N'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='User groups with configured menu items';

-- --------------------------------------------------------

--
-- Table structure for table `pma__users`
--

CREATE TABLE `pma__users` (
  `username` varchar(64) NOT NULL,
  `usergroup` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Users and their assignments to user groups';

--
-- Indexes for dumped tables
--

--
-- Indexes for table `pma__bookmark`
--
ALTER TABLE `pma__bookmark`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pma__central_columns`
--
ALTER TABLE `pma__central_columns`
  ADD PRIMARY KEY (`db_name`,`col_name`);

--
-- Indexes for table `pma__column_info`
--
ALTER TABLE `pma__column_info`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `db_name` (`db_name`,`table_name`,`column_name`);

--
-- Indexes for table `pma__designer_settings`
--
ALTER TABLE `pma__designer_settings`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__export_templates`
--
ALTER TABLE `pma__export_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_user_type_template` (`username`,`export_type`,`template_name`);

--
-- Indexes for table `pma__favorite`
--
ALTER TABLE `pma__favorite`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__history`
--
ALTER TABLE `pma__history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`,`db`,`table`,`timevalue`);

--
-- Indexes for table `pma__navigationhiding`
--
ALTER TABLE `pma__navigationhiding`
  ADD PRIMARY KEY (`username`,`item_name`,`item_type`,`db_name`,`table_name`);

--
-- Indexes for table `pma__pdf_pages`
--
ALTER TABLE `pma__pdf_pages`
  ADD PRIMARY KEY (`page_nr`),
  ADD KEY `db_name` (`db_name`);

--
-- Indexes for table `pma__recent`
--
ALTER TABLE `pma__recent`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__relation`
--
ALTER TABLE `pma__relation`
  ADD PRIMARY KEY (`master_db`,`master_table`,`master_field`),
  ADD KEY `foreign_field` (`foreign_db`,`foreign_table`);

--
-- Indexes for table `pma__savedsearches`
--
ALTER TABLE `pma__savedsearches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_savedsearches_username_dbname` (`username`,`db_name`,`search_name`);

--
-- Indexes for table `pma__table_coords`
--
ALTER TABLE `pma__table_coords`
  ADD PRIMARY KEY (`db_name`,`table_name`,`pdf_page_number`);

--
-- Indexes for table `pma__table_info`
--
ALTER TABLE `pma__table_info`
  ADD PRIMARY KEY (`db_name`,`table_name`);

--
-- Indexes for table `pma__table_uiprefs`
--
ALTER TABLE `pma__table_uiprefs`
  ADD PRIMARY KEY (`username`,`db_name`,`table_name`);

--
-- Indexes for table `pma__tracking`
--
ALTER TABLE `pma__tracking`
  ADD PRIMARY KEY (`db_name`,`table_name`,`version`);

--
-- Indexes for table `pma__userconfig`
--
ALTER TABLE `pma__userconfig`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `pma__usergroups`
--
ALTER TABLE `pma__usergroups`
  ADD PRIMARY KEY (`usergroup`,`tab`,`allowed`);

--
-- Indexes for table `pma__users`
--
ALTER TABLE `pma__users`
  ADD PRIMARY KEY (`username`,`usergroup`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `pma__bookmark`
--
ALTER TABLE `pma__bookmark`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__column_info`
--
ALTER TABLE `pma__column_info`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__export_templates`
--
ALTER TABLE `pma__export_templates`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__history`
--
ALTER TABLE `pma__history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__pdf_pages`
--
ALTER TABLE `pma__pdf_pages`
  MODIFY `page_nr` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pma__savedsearches`
--
ALTER TABLE `pma__savedsearches`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;
--
-- Database: `sundarbon`
--
CREATE DATABASE IF NOT EXISTS `sundarbon` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `sundarbon`;

-- --------------------------------------------------------

--
-- Table structure for table `sales_data`
--

CREATE TABLE `sales_data` (
  `SaleID` int(11) NOT NULL,
  `ProductName` varchar(255) NOT NULL,
  `CategoryID` int(11) NOT NULL,
  `CategoryName` varchar(20) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `Revenue` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_data`
--

INSERT INTO `sales_data` (`SaleID`, `ProductName`, `CategoryID`, `CategoryName`, `Quantity`, `Revenue`) VALUES
(1, 'Laptop', 301, 'Electronics', 5, 423500),
(2, 'Mouse', 301, 'Electronics', 15, 45000),
(3, 'Chair', 302, 'Furniture', 8, 64000),
(4, 'Desk', 302, 'Furniture', 6, 87120),
(5, 'Bottle', 303, 'low performing', 20, 30000),
(6, 'Pen', 303, 'low performing', 25, 20000);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `sales_data`
--
ALTER TABLE `sales_data`
  ADD PRIMARY KEY (`SaleID`);
--
-- Database: `test`
--
CREATE DATABASE IF NOT EXISTS `test` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `test`;
--
-- Database: `trip`
--
CREATE DATABASE IF NOT EXISTS `trip` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `trip`;

-- --------------------------------------------------------

--
-- Table structure for table `trip`
--

CREATE TABLE `trip` (
  `serial no` int(3) NOT NULL,
  `name` text NOT NULL,
  `age` int(3) NOT NULL,
  `gender` varchar(8) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(11) NOT NULL,
  `other` text NOT NULL,
  `dt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip`
--

INSERT INTO `trip` (`serial no`, `name`, `age`, `gender`, `email`, `phone`, `other`, `dt`) VALUES
(1, 'miraz', 23, 'male', 'abc@gmail.com', '01111111111', 'sssssssssssssssssss', '2026-06-24 19:53:06'),
(2, 'Eren Yeager', 23, 'male', 'error@gmail.com', '01783203317', 'cc', '2026-06-24 20:04:22'),
(3, 'tom holland', 23, 'male', 'rman@gmail.com', '98765437', 'i am coming on 31st July.', '2026-06-24 20:10:12');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `trip`
--
ALTER TABLE `trip`
  ADD PRIMARY KEY (`serial no`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `trip`
--
ALTER TABLE `trip`
  MODIFY `serial no` int(3) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 12, 2026 at 10:21 AM
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
-- Database: `medpulse_hms`
--

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
  `status` enum('pending','active','rejected') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `phone`, `gender`, `password_hash`, `role`, `status`, `created_at`) VALUES
(1, 'Agatsuma Zenitsu', 'afzalhossain.miraz@gmail.com', '01783203318', 'Male', '$2y$12$pF3GaBNjv0JQNiqV97PdJOw44P7GqwQ3hA5XEr5mEEUppzgsceYIy', 'Patient', 'active', '2026-09-11 15:45:28'),
(5, 'Nusrat Jahan', 'nusrat.jahan@medpulse.test', '01812345678', 'Female', '$2y$12$fgDxuBJH30Zgfjm8KeU8BOR710dlfLV9Ax5Bx18kguhyiF2q4a1Gq', 'Patient', 'active', '2026-09-11 17:08:12'),
(6, 'Miraz', 'admin@medpulse.org', '01700000000', 'Male', '$2y$12$3ucl5ZjD1zVL9jIpKhiJCuHmoQkZiQki4IVgwSvKqbsBUcy2VhSbe', 'Admin', 'active', '2026-09-11 17:39:22'),
(8, 'Dr. Rafiqul Islam', 'dr.rafiq@medpulse.test', '01711122233', 'Male', '$2y$12$cCjKp2iPuBE46X6KPt0a7ekjWKdZgDnoXxHde7yvW/244UlZVg8ze', 'Doctor', 'active', '2026-09-11 17:50:13'),
(9, 'Farhana Akter', 'farhana.staff@medpulse.test', '01822334455', 'Female', '$2y$12$cWghiR/rub4nOvrgOInsUup6EOZGfclnbGVvl818tPkCVNzkgMRdK', 'Staff', 'rejected', '2026-09-11 17:50:30'),
(10, 'Jahid Hasan', 'jahid.patient@medpulse.test', '01933445566', 'Male', '$2y$12$B5uzHCytdNMvls1DX.b6Nu7/0w7F34X.tAWOvhMl9R6X7hYlhhoEm', 'Patient', 'active', '2026-09-11 17:50:39'),
(11, 'Dr. Tahsin Mahmud', 'tahsin.mahmud@medpulse.test', '01755667788', 'Male', '$2y$12$pBDaMD4hn8DC.mb/Kxfe1OEY7hvz6thWr1qFNCeraDilOy.QS4R.G', 'Doctor', 'rejected', '2026-09-11 17:52:39'),
(12, 'Sumaiya Noor', 'sumaiya.noor@medpulse.test', '01644332211', 'Female', '$2y$12$CNpQuvFRMIvFUPBr/OgZk.cfiLYrj/b1jTAB/vGi25V4XVSWTwLzu', 'Staff', 'rejected', '2026-09-11 17:52:39'),
(13, 'Dr. Miftahul Sheikh', 'miftahul@medpulse.org', '01855555555', 'Male', '$2y$12$iX8oTqMdtOzTyUSJM39YvO/0zFWwk/H8bJv28f0TwZN.XlgzaZ8zu', 'Doctor', 'active', '2026-09-11 17:54:27'),
(14, 'Dr. Mitsuha', 'mitsuha@medpulse.org', '01783203388', 'Female', '$2y$12$c2tNi3CGwynM9y7RMoswDueE7aT2Z8EZmyvhYQjiLMtXk.UEDEOK6', 'Doctor', 'active', '2026-09-11 18:20:29'),
(15, 'Ms. Shinobu', 'shinobu@medpulse.org', '01756789159', 'Male', '$2y$12$f9jsGZ5zWccOL6LmcWyavukaN1Mhui2dKGR2sqttqfY8P13pdjiVu', 'Staff', 'active', '2026-09-11 18:23:25'),
(16, 'Sabbir Ahmed', 'sabbir.ahmed@medpulse.test', '01766554433', 'Male', '$2y$12$32Vvawh0e952yWOUpqIj6OfICq5RjjcNw.5fC8ywlnE.Hbc97zqq6', 'Patient', 'active', '2026-09-11 18:55:50'),
(18, 'Robert Downey Jr.', 'robertdowney@medpulse.com', '01578530163', 'Male', '$2y$12$9/xcBRRb7hlokADbPDGCIu5.L7fa/yXL8MP3bIPTNM.gklo3YdJwG', 'Patient', 'active', '2026-09-12 05:56:53'),
(19, 'Miraz', 'miraz@gmail.com', '01712345678', 'Male', '$2y$12$GWaKvgLtaNtx4zc27yP13e86crQZg89rTWAKe2XQ4PJ7SsgWP5UsS', 'Patient', 'active', '2026-09-12 06:36:51'),
(20, 'Dr. Mikasa Ackerman', 'drmikasa@medpulse.com', '01982517352', 'Female', '$2y$12$ydv/njDRdSKz6l9k/8aWsu6j5vqqbZilokXhdxjoo9ZLSWgfB7LBS', 'Doctor', 'active', '2026-09-12 06:50:08'),
(21, 'Dr. Satoru Gojo', 'soturogojo@gmail.com', '01352890132', 'Male', '$2y$12$wxjkc7DAgfbAG/K8MQGTeO8rzGyOogn5aM3TG5NGzOEPwZxHnGEMm', 'Doctor', 'active', '2026-09-12 07:22:03'),
(28, 'Kibutsuji Muzan', 'muzan@gmail.com', '01783203317', 'Male', '$2y$12$ZxbZAkG1EeKoaotYgfe4megUktcFQzte7PJ3gq.2913oEWwOn1KZi', 'Patient', 'active', '2026-09-12 07:37:49'),
(29, 'Dr. Afzal Hossain Miraz', 'drmiraz@gmail.com', '01715158160', 'Male', '$2y$12$j7siCv9HT5XFih7P.t.5S.KesyuiY0b4J6V6PiavHL/NBb2jvMLgi', 'Doctor', 'active', '2026-09-12 07:58:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

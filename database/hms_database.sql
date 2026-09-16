CREATE DATABASE IF NOT EXISTS medpulse_hms;
USE medpulse_hms;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL UNIQUE,
    gender ENUM('Male', 'Female', 'Other') NOT NULL DEFAULT 'Male',
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Patient', 'Doctor', 'Staff', 'Admin') NOT NULL DEFAULT 'Patient',
    status ENUM('pending', 'active', 'rejected') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Sanitize and collect form inputs
$name      = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$raw_email = trim($_POST['email'] ?? '');
$raw_phone = trim($_POST['phone'] ?? '');
$gender    = trim($_POST['gender'] ?? 'Male');
$password  = $_POST['password'] ?? '';
$role      = trim($_POST['register_role'] ?? 'Patient');

// 1. Role Assignment & Restriction (No public Admin registration)
$allowed_roles = ['Patient', 'Doctor', 'Staff'];
if (!in_array($role, $allowed_roles, true)) {
    $role = 'Patient';
}

// 2. Status Assignment Logic
// Patients are active immediately; clinical personnel (Doctor, Staff) require administrative approval
$status = ($role === 'Patient') ? 'active' : 'pending';

// 3. Mandatory Fields Check
if ($name === '' || $raw_email === '' || $raw_phone === '' || $gender === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'All mandatory fields are required.']);
    exit;
}

// 4. Email Validity Check
if (!filter_var($raw_email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $raw_email)) {
    echo json_encode(['status' => 'error', 'message' => 'Please provide a valid email address (e.g. user@example.com).']);
    exit;
}
$email = strtolower($raw_email);

// 5. Bangladeshi Phone Number Sanitization & Strict Validation
// Remove all spaces, hyphens, parentheses, and plus signs
$phone = preg_replace('/[\s\-\(\)\+]/', '', $raw_phone);
if (str_starts_with($phone, '8801')) {
    $phone = substr($phone, 2); // converts 8801XXXXXXXXX -> 01XXXXXXXXX
}

if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid mobile number. Please provide a valid 11-digit Bangladeshi number (e.g., 017XXXXXXXX).'
    ]);
    exit;
}

// 6. Gender Validity Check
$allowed_genders = ['Male', 'Female', 'Other'];
if (!in_array($gender, $allowed_genders, true)) {
    $gender = 'Male';
}

// 7. Password Strength Validation (Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol)
$passwordPattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]{8,32}$/';
if (!preg_match($passwordPattern, $password)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Password must include uppercase, lowercase, number, and symbol (@$!%*?&#).'
    ]);
    exit;
}

try {
    // 8. Pre-Validation Checks (Explicit Unique Email & Phone Lookup)
    $checkStmt = $pdo->prepare("
        SELECT email, phone 
        FROM users 
        WHERE email = :email OR phone = :phone 
        LIMIT 1
    ");
    $checkStmt->execute([
        'email' => $email,
        'phone' => $phone
    ]);
    $existing_user = $checkStmt->fetch();

    if ($existing_user) {
        if (strtolower($existing_user['email']) === $email) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This email address is already registered. Please log in or use a different email.'
            ]);
            exit;
        }

        if ($existing_user['phone'] === $phone) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This mobile number is already linked to an existing account.'
            ]);
            exit;
        }
    }

    // 9. Password Hashing with BCrypt (cost => 12)
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // 10. Database Persistence with Role & Status
    $insert = $pdo->prepare("
        INSERT INTO users (full_name, email, phone, gender, role, status, password_hash)
        VALUES (:name, :email, :phone, :gender, :role, :status, :hash)
    ");
    $insert->execute([
        'name'   => $name,
        'email'  => $email,
        'phone'  => $phone,
        'gender' => $gender,
        'role'   => $role,
        'status' => $status,
        'hash'   => $hashedPassword
    ]);

    // 11. Role-based Response & Redirection Pipeline
    if ($status === 'pending') {
        echo json_encode([
            'status'   => 'success',
            'pending'  => true,
            'role'     => $role,
            'message'  => "Registration received! Your {$role} account is awaiting administrative approval before activation.",
            'redirect' => 'login.php?msg=pending_verification'
        ]);
    } else {
        echo json_encode([
            'status'   => 'success',
            'pending'  => false,
            'role'     => $role,
            'message'  => 'Registration successful! Switch to login to access your portal.',
            'redirect' => 'login.php?msg=registered'
        ]);
    }
    exit;

} catch (PDOException $e) {
    // 12. Database Exception Fallback (Handle MySQL 1062 Duplicate Entry Violation)
    $errorCode = $e->errorInfo[1] ?? 0;
    if ($errorCode === 1062) {
        $errorMessage = $e->getMessage();
        if (stripos($errorMessage, 'email') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This email address is already registered. Please log in or use a different email.'
            ]);
            exit;
        } elseif (stripos($errorMessage, 'phone') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This mobile number is already linked to an existing account.'
            ]);
            exit;
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'An account with these details already exists. Please verify your information.'
            ]);
            exit;
        }
    }

    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}

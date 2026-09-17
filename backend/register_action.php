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
// Patients and Doctors are active immediately; Staff requires administrative approval
$status = in_array($role, ['Patient', 'Doctor'], true) ? 'active' : 'pending';

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

    // Clean Doctor Name formatting if signing up as doctor
    if ($role === 'Doctor') {
        $cleanName = preg_replace('/^(?:(?:dr\.?|doctor)\s+)+/i', '', $name);
        $name = 'Dr. ' . $cleanName;
    }

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
    $newUserId = (int)$pdo->lastInsertId();

    // 11. Linked Doctor Profile Provisioning & Immediate Session Auth
    if ($role === 'Doctor') {
        $specialty = trim($_POST['specialty'] ?? '') ?: 'General Surgery & Critical Care';
        $consultationFee = isset($_POST['consultation_fee']) && is_numeric($_POST['consultation_fee'])
            ? (float)$_POST['consultation_fee']
            : 1200.00;
        $roomNumber = trim($_POST['room_number'] ?? '') ?: ('Room-' . mt_rand(201, 508));
        $availableDays = 'Mon,Tue,Wed,Thu,Fri';
        $shiftTimings = '09:00 AM - 05:00 PM';

        // BMDC License handling
        $bmdcLicense = trim($_POST['bmdc_license_number'] ?? $_POST['bmdc_reg'] ?? '');
        if (empty($bmdcLicense)) {
            // Generate guaranteed unique BMDC license
            do {
                $candidate = 'BMDC-A-' . mt_rand(20000, 99999);
                $bCheck = $pdo->prepare("SELECT 1 FROM doctor_profiles WHERE bmdc_license_number = ? LIMIT 1");
                $bCheck->execute([$candidate]);
            } while ($bCheck->fetchColumn());
            $bmdcLicense = $candidate;
        }

        // Insert into doctor_profiles
        $docProfileStmt = $pdo->prepare("
            INSERT INTO doctor_profiles 
                (user_id, specialty, bmdc_license_number, consultation_fee, room_number, available_days, shift_timings)
            VALUES 
                (:uid, :specialty, :bmdc, :fee, :room, :days, :shift)
        ");
        $docProfileStmt->execute([
            ':uid'       => $newUserId,
            ':specialty' => $specialty,
            ':bmdc'      => $bmdcLicense,
            ':fee'       => $consultationFee,
            ':room'      => $roomNumber,
            ':days'      => $availableDays,
            ':shift'     => $shiftTimings,
        ]);
        $doctorProfileId = (int)$pdo->lastInsertId();

        // Also populate users.department and users.license_id
        $updUser = $pdo->prepare("UPDATE users SET department = :dept, license_id = :lic WHERE user_id = :uid");
        $updUser->execute([
            ':dept' => $specialty,
            ':lic'  => $bmdcLicense,
            ':uid'  => $newUserId,
        ]);

        // Establish Authenticated Session
        session_regenerate_id(true);
        $_SESSION['user_id']       = $newUserId;
        $_SESSION['full_name']     = $name;
        $_SESSION['email']         = $email;
        $_SESSION['phone']         = $phone;
        $_SESSION['gender']        = $gender;
        $_SESSION['role']          = 'Doctor';
        $_SESSION['status']        = 'active';
        $_SESSION['doctor_id']     = $doctorProfileId;
        $_SESSION['last_activity'] = time();

        // Audit Log Entry
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs 
                    (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address)
                VALUES 
                    (:actor, 'Doctor', 'REGISTRATION', 'REGISTRATION', :desc, 'SECURITY', 'DOCTOR_PORTAL', :ip)
            ");
            $auditStmt->execute([
                ':actor' => $newUserId,
                ':desc'  => "Doctor account registered: {$name} ({$specialty}, {$bmdcLicense})",
                ':ip'    => $ip,
            ]);
        } catch (Throwable $e) {
            // Non-blocking audit log
        }

        echo json_encode([
            'status'   => 'success',
            'success'  => true,
            'pending'  => false,
            'role'     => 'Doctor',
            'message'  => "Doctor registration complete! Welcome {$name}. Redirecting to your Clinical Workspace...",
            'redirect' => 'doctor/dashboard.php'
        ]);
        exit;
    }

    // 12. Role-based Response & Redirection Pipeline for other roles
    if ($status === 'pending') {
        echo json_encode([
            'status'   => 'success',
            'success'  => true,
            'pending'  => true,
            'role'     => $role,
            'message'  => "Registration received! Your {$role} account is awaiting administrative approval before activation.",
            'redirect' => 'login.php?msg=pending_verification'
        ]);
    } else {
        echo json_encode([
            'status'   => 'success',
            'success'  => true,
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

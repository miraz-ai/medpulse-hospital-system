<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$name        = trim($_POST['name'] ?? (filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? ''));
$raw_email   = trim($_POST['email'] ?? '');
$raw_phone   = trim($_POST['phone'] ?? '');
$gender      = trim($_POST['gender'] ?? 'Male');
$password    = $_POST['password'] ?? '';
$role        = trim($_POST['register_role'] ?? 'Patient');
$raw_dob     = trim($_POST['dob'] ?? '');
$raw_blood   = trim($_POST['blood_group'] ?? 'Unknown');
if (preg_match('/^(A|B|AB|O)\s*$/i', $raw_blood, $m)) {
    $raw_blood = strtoupper($m[1]) . '+';
} elseif (preg_match('/^(A|B|AB|O)[\+\-]$/i', $raw_blood)) {
    $raw_blood = strtoupper($raw_blood);
} elseif (stripos($raw_blood, 'Unknown') !== false) {
    $raw_blood = 'Unknown';
}

// 1. Role Assignment & Restriction (No public Admin registration)
$allowed_roles = ['Patient', 'Doctor', 'Staff'];
if (!in_array($role, $allowed_roles, true)) {
    $role = 'Patient';
}

// 2. Status Assignment Logic
// Patients are active immediately; Doctors and Staff require administrative approval
$status = ($role === 'Patient') ? 'active' : 'pending';

// 3. Mandatory Fields Check
if ($name === '' || $raw_email === '' || $raw_phone === '' || $gender === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'All mandatory fields are required.']);
    exit;
}

// Patient Specific Mandatory Fields (DOB & Blood Group)
if ($role === 'Patient') {
    if ($raw_dob === '') {
        echo json_encode(['status' => 'error', 'message' => 'Date of birth is required for patient registration.']);
        exit;
    }
    $dobTimestamp = strtotime($raw_dob);
    if (!$dobTimestamp || $dobTimestamp > time()) {
        echo json_encode(['status' => 'error', 'message' => 'Please provide a valid date of birth (cannot be in the future).']);
        exit;
    }
}

$allowed_blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];
$blood_group = in_array($raw_blood, $allowed_blood_groups, true) ? $raw_blood : 'Unknown';
$dob = !empty($raw_dob) ? date('Y-m-d', strtotime($raw_dob)) : null;

// Dynamic Age Calculation
$age = null;
if (!empty($dob)) {
    try {
        $dobObj = new DateTime($dob);
        $age = (new DateTime())->diff($dobObj)->y;
    } catch (Exception $e) {
        $age = null;
    }
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
    // 8. Pre-Flight Duplicate Checks
    // Check 8a: Unique Email in users
    $checkEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
    $checkEmail->execute([':email' => $email]);
    if ($checkEmail->fetch()) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This email address is already registered. Please sign in or use a different email.'
        ]);
        exit;
    }

    // Check 8b: Unique Phone in users
    $checkPhone = $pdo->prepare("SELECT user_id FROM users WHERE phone = :phone LIMIT 1");
    $checkPhone->execute([':phone' => $phone]);
    if ($checkPhone->fetch()) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'This phone number is already associated with an existing account.'
        ]);
        exit;
    }

    // Check 8c: Unique BMDC Registration Number in doctor_profiles (if role is Doctor)
    $bmdcLicense = '';
    if ($role === 'Doctor') {
        $rawBmdc = trim($_POST['bmdc_reg_number'] ?? $_POST['bmdc_reg'] ?? $_POST['bmdc_license_number'] ?? '');
        if (!empty($rawBmdc)) {
            $checkBmdc = $pdo->prepare("
                SELECT doctor_id 
                FROM doctor_profiles 
                WHERE bmdc_reg_number = :bmdc1 OR bmdc_license_number = :bmdc2 
                LIMIT 1
            ");
            $checkBmdc->execute([
                ':bmdc1' => $rawBmdc,
                ':bmdc2' => $rawBmdc,
            ]);
            if ($checkBmdc->fetch()) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'This BMDC registration number is already registered under an existing doctor profile.'
                ]);
                exit;
            }
            $bmdcLicense = $rawBmdc;
        } else {
            // Auto-generate guaranteed unique BMDC license
            do {
                $candidate = 'BMDC-A-' . mt_rand(20000, 99999);
                $bCheck = $pdo->prepare("
                    SELECT 1 
                    FROM doctor_profiles 
                    WHERE bmdc_reg_number = ? OR bmdc_license_number = ? 
                    LIMIT 1
                ");
                $bCheck->execute([$candidate, $candidate]);
            } while ($bCheck->fetchColumn());
            $bmdcLicense = $candidate;
        }
    }

    // 9. Password Hashing with BCrypt (cost => 12)
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Clean Doctor Name formatting if signing up as doctor
    if ($role === 'Doctor') {
        $cleanName = preg_replace('/^(?:(?:dr\.?|doctor)\s+)+/i', '', $name);
        $name = 'Dr. ' . $cleanName;
    }

    // 10. Database Persistence with Role, Demographics & Status
    $insert = $pdo->prepare("
        INSERT INTO users (full_name, email, phone, gender, role, status, password_hash, blood_group, date_of_birth, age)
        VALUES (:name, :email, :phone, :gender, :role, :status, :hash, :bg, :dob, :age)
    ");
    $insert->execute([
        'name'   => $name,
        'email'  => $email,
        'phone'  => $phone,
        'gender' => $gender,
        'role'   => $role,
        'status' => $status,
        'hash'   => $hashedPassword,
        'bg'     => $blood_group,
        'dob'    => $dob,
        'age'    => $age
    ]);
    $newUserId = (int)$pdo->lastInsertId();

    // 11. Linked Patient Profile Provisioning with Auto-Generated Enterprise Sequence UID
    if ($role === 'Patient') {
        // Sequence format: MP-YYYY-XXXXX (e.g. MP-2026-00042)
        $currentYear = date('Y');
        $seqStmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_seq FROM patients");
        $nextSeq = (int)$seqStmt->fetchColumn();

        do {
            $patientUid = sprintf("MP-%s-%05d", $currentYear, $nextSeq);
            $chkUid = $pdo->prepare("SELECT 1 FROM patients WHERE patient_uid = ? LIMIT 1");
            $chkUid->execute([$patientUid]);
            $exists = (bool)$chkUid->fetchColumn();
            if ($exists) {
                $nextSeq++;
            }
        } while ($exists);

        $insPatient = $pdo->prepare("
            INSERT INTO patients 
                (user_id, patient_uid, full_name, email, phone, gender, dob, blood_group, created_at)
            VALUES 
                (:uid, :p_uid, :name, :email, :phone, :gender, :dob, :bg, NOW())
        ");
        $insPatient->execute([
            ':uid'     => $newUserId,
            ':p_uid'   => $patientUid,
            ':name'    => $name,
            ':email'   => $email,
            ':phone'   => $phone,
            ':gender'  => $gender,
            ':dob'     => $dob ?: date('Y-m-d', strtotime('-22 years')),
            ':bg'      => $blood_group
        ]);

        // Audit Log Entry
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs 
                    (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                VALUES 
                    (:actor, 'Patient', 'PATIENT_REGISTRATION', 'Patient Registration', :desc, 'REGISTRATION', :target, :ip, 'INFO')
            ");
            $auditStmt->execute([
                ':actor'  => $newUserId,
                ':desc'   => "Patient registered with UID {$patientUid}, Blood Group {$blood_group}, DOB {$dob}",
                ':target' => $patientUid,
                ':ip'     => $ip
            ]);
        } catch (Throwable $e) {}

        // Automatic login session population for patient
        $_SESSION['user_id']       = $newUserId;
        $_SESSION['patient_uid']   = $patientUid;
        $_SESSION['full_name']     = $name;
        $_SESSION['email']         = $email;
        $_SESSION['phone']         = $phone;
        $_SESSION['gender']        = $gender;
        $_SESSION['role']          = 'Patient';
        $_SESSION['status']        = 'active';
        $_SESSION['logged_in']     = true;
        $_SESSION['last_activity'] = time();

        echo json_encode([
            'status'      => 'success',
            'success'     => true,
            'pending'     => false,
            'role'        => 'Patient',
            'patient_uid' => $patientUid,
            'message'     => "Welcome to MedPulse! Registration successful. Your Patient ID is {$patientUid}. Entering patient portal...",
            'redirect'    => 'patient/portal.php'
        ]);
        exit;
    }

    // 11. Linked Doctor Profile Provisioning (Strictly Pending Admin Approval Gatekeeper)
    if ($role === 'Doctor') {
        $specialty = trim($_POST['specialty'] ?? '') ?: 'General Surgery & Critical Care';
        $consultationFee = isset($_POST['consultation_fee']) && is_numeric($_POST['consultation_fee'])
            ? (float)$_POST['consultation_fee']
            : 1200.00;
        $roomNumber = trim($_POST['room_number'] ?? '') ?: ('Room-' . mt_rand(201, 508));
        $availableDays = 'Mon,Tue,Wed,Thu,Fri';
        $shiftTimings = '09:00 AM - 05:00 PM';

        // Insert into doctor_profiles with approval_status = 'pending'
        $docProfileStmt = $pdo->prepare("
            INSERT INTO doctor_profiles 
                (user_id, specialty, designation, qualifications, bmdc_license_number, bmdc_reg_number, approval_status, consultation_fee, room_number, available_days, shift_timings)
            VALUES 
                (:uid, :specialty, 'Consultant', 'MBBS', :bmdc_lic, :bmdc_reg, 'pending', :fee, :room, :days, :shift)
        ");
        $docProfileStmt->execute([
            ':uid'       => $newUserId,
            ':specialty' => $specialty,
            ':bmdc_lic'  => $bmdcLicense,
            ':bmdc_reg'  => $bmdcLicense,
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

        // Audit Log Entry
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs 
                    (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                VALUES 
                    (:actor, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor Registration Submitted', :desc, 'VERIFICATION', 'DOCTOR_PORTAL', :ip, 'INFO')
            ");
            $auditStmt->execute([
                ':actor' => $newUserId,
                ':desc'  => "Doctor account registered awaiting administrative verification: {$name} ({$specialty}, BMDC: {$bmdcLicense})",
                ':ip'    => $ip,
            ]);
        } catch (Throwable $e) {
            // Non-blocking audit log
        }

        // NOTE: DO NOT establish an active doctor login session automatically upon registration.
        // Redirect the doctor with informative credentialing notice.
        echo json_encode([
            'status'   => 'success',
            'success'  => true,
            'pending'  => true,
            'role'     => 'Doctor',
            'message'  => 'Registration successful. Your medical credentials (BMDC) have been submitted to the Admin Treasury & Credentialing Board for verification. You will gain portal access once approved.',
            'redirect' => 'login.php?msg=pending_verification'
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
    // 13. Database Exception Fallback (Handle MySQL 1062 Duplicate Entry Violation)
    $errorCode = $e->errorInfo[1] ?? 0;
    if ($errorCode === 1062 || $e->getCode() == 23000) {
        $errorMessage = $e->getMessage();
        if (stripos($errorMessage, 'unique_email') !== false || stripos($errorMessage, 'email') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This email address is already registered. Please sign in or use a different email.'
            ]);
            exit;
        } elseif (stripos($errorMessage, 'unique_phone') !== false || stripos($errorMessage, 'phone') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This phone number is already associated with an existing account.'
            ]);
            exit;
        } elseif (stripos($errorMessage, 'unique_bmdc') !== false || stripos($errorMessage, 'bmdc') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'This BMDC registration number is already registered under an existing doctor profile.'
            ]);
            exit;
        } elseif (stripos($errorMessage, 'patient_uid') !== false) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'A patient record with this UID sequence already exists. Please re-submit to assign the next available number.'
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

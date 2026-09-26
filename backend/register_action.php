<?php
/**
 * MedPulse Enterprise Registration Handler
 * Handles Patient, Doctor, and Staff Account Creation with Strict Relational Isolation
 *
 * Requirements:
 * - Patient: Full Name, Email, Phone, Gender, Password, Date of Birth (dob), Blood Group
 * - Collision-Proof Unique Patient UID Engine: MP-YYYY-XXXXXX (6-char secure uppercase alphanumeric)
 * - Doctor: Requires hospital_id queried from hospitals table with status = 'pending'
 * - Staff: Requires hospital_id queried from hospitals table with status = 'pending' and optional department
 * - Prepared PDO statements with comprehensive duplicate checks and retry loops
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
       || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';

function sendRegisterResponse(string $status, string $message, array $extra = []) {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'status'  => $status,
            'success' => ($status === 'success'),
            'message' => $message
        ], $extra));
        exit;
    }
    
    if ($status === 'success') {
        $redir = $extra['redirect'] ?? 'login.php?msg=registered';
        header("Location: ../" . ltrim($redir, '/'));
    } else {
        header("Location: ../login.php?error=reg_error&msg=" . urlencode($message) . "&mode=register");
    }
    exit;
}

/**
 * Generate enterprise collision-proof patient UID: MP-YYYY-XXXXXX
 * 6-character cryptographically secure uppercase alphanumeric string using random_bytes
 */
function generatePatientUid(?string $year = null): string {
    $year = $year ?: date('Y');
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charsLen = strlen($alphabet);
    $bytes = random_bytes(6);
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[ord($bytes[$i]) % $charsLen];
    }
    return "MP-{$year}-{$code}";
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendRegisterResponse('error', 'Invalid request method.');
}

// 0. CSRF Protection Gatekeeper
$csrf_token = trim($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!empty($_SESSION['csrf_token']) && !empty($csrf_token)) {
    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        sendRegisterResponse('error', 'Security validation failed (CSRF token mismatch). Please refresh the page and try again.');
    }
}

// 1. Collect & Sanitize Generic Inputs
$name      = trim($_POST['name'] ?? $_POST['fullName'] ?? '');
$raw_email = trim($_POST['email'] ?? '');
$raw_phone = trim($_POST['phone'] ?? '');
$gender    = trim($_POST['gender'] ?? 'Male');
$password  = $_POST['password'] ?? '';
$raw_role  = trim($_POST['register_role'] ?? $_POST['role'] ?? 'Patient');

// Normalize role: Patient, Doctor, or Staff
if (strcasecmp($raw_role, 'Doctor') === 0) {
    $role = 'Doctor';
} elseif (strcasecmp($raw_role, 'Staff') === 0 || strcasecmp($raw_role, 'Admin') === 0) {
    $role = 'Staff';
} else {
    $role = 'Patient';
}

// 2. Mandatory Core Fields Check
if ($name === '' || $raw_email === '' || $raw_phone === '' || $password === '') {
    sendRegisterResponse('error', 'Please fill in all mandatory fields.');
}

// 3. Email Validation
if (!filter_var($raw_email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $raw_email)) {
    sendRegisterResponse('error', 'Please provide a valid email address (e.g. user@example.com).');
}
$email = strtolower($raw_email);

// 4. Bangladeshi Phone Number Sanitization & Strict Validation
$phone = preg_replace('/[\s\-\(\)\+]/', '', $raw_phone);
if (str_starts_with($phone, '8801')) {
    $phone = substr($phone, 2);
}
if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    sendRegisterResponse('error', 'Invalid mobile number. Please provide a valid 11-digit Bangladeshi number (e.g., 017XXXXXXXX).');
}

// 5. Gender Validation
$allowed_genders = ['Male', 'Female', 'Other'];
if (!in_array($gender, $allowed_genders, true)) {
    $gender = 'Male';
}

// 6. Password Strength Validation (Min 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol)
$passwordPattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]{8,32}$/';
if (!preg_match($passwordPattern, $password)) {
    sendRegisterResponse('error', 'Password must be at least 8 characters and include uppercase, lowercase, number, and special symbol (@$!%*?&#).');
}

// 7. Role-Specific Field Validations
$dob         = null;
$blood_group = 'Unknown';
$age         = null;
$hospital_id = null;
$hospRow     = null;
$bmdcLicense = '';
$department  = null;

if ($role === 'Patient') {
    $raw_dob   = trim($_POST['dob'] ?? '');
    $raw_blood = trim($_POST['blood_group'] ?? $_POST['bloodGroup'] ?? '');

    if ($raw_dob === '') {
        sendRegisterResponse('error', 'Date of birth is required for patient registration.');
    }
    $dobTimestamp = strtotime($raw_dob);
    if (!$dobTimestamp || $dobTimestamp > time()) {
        sendRegisterResponse('error', 'Please provide a valid date of birth (cannot be in the future).');
    }
    $dob = date('Y-m-d', $dobTimestamp);

    // Calculate dynamic age
    try {
        $dobObj = new DateTime($dob);
        $age = (new DateTime())->diff($dobObj)->y;
    } catch (Exception $e) {
        $age = null;
    }

    $allowed_blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];
    if (empty($raw_blood) || !in_array($raw_blood, $allowed_blood_groups, true)) {
        sendRegisterResponse('error', 'Please select a valid blood group from the list.');
    }
    $blood_group = $raw_blood;

} elseif ($role === 'Doctor') {
    // Required hospital_id dropdown dynamically queried from hospitals
    $raw_hosp_id = (int)($_POST['hospital_id'] ?? $_POST['facility'] ?? 0);
    if ($raw_hosp_id <= 0) {
        sendRegisterResponse('error', 'Please select your affiliated hospital facility from the dropdown.');
    }

    $hCheck = $pdo->prepare("SELECT hospital_id, name, city FROM hospitals WHERE hospital_id = ? LIMIT 1");
    $hCheck->execute([$raw_hosp_id]);
    $hospRow = $hCheck->fetch(PDO::FETCH_ASSOC);

    if (!$hospRow) {
        sendRegisterResponse('error', 'The selected hospital facility is not recognized. Please choose from the available facilities.');
    }
    $hospital_id = (int)$hospRow['hospital_id'];

    // BMDC License Handling
    $rawBmdc = trim($_POST['bmdc_reg_number'] ?? $_POST['licenseNumber'] ?? $_POST['bmdc_reg'] ?? '');
    if (!empty($rawBmdc)) {
        $checkBmdc = $pdo->prepare("
            SELECT doctor_id 
            FROM doctor_profiles 
            WHERE bmdc_reg_number = :bmdc1 OR bmdc_license_number = :bmdc2 
            LIMIT 1
        ");
        $checkBmdc->execute([':bmdc1' => $rawBmdc, ':bmdc2' => $rawBmdc]);
        if ($checkBmdc->fetch()) {
            sendRegisterResponse('error', 'This BMDC registration number is already registered under an existing doctor profile.');
        }
        $bmdcLicense = $rawBmdc;
    } else {
        // Auto-generate guaranteed unique BMDC license
        do {
            $candidate = 'BMDC-A-' . mt_rand(20000, 99999);
            $bCheck = $pdo->prepare("SELECT 1 FROM doctor_profiles WHERE bmdc_reg_number = ? OR bmdc_license_number = ? LIMIT 1");
            $bCheck->execute([$candidate, $candidate]);
        } while ($bCheck->fetchColumn());
        $bmdcLicense = $candidate;
    }

    // Prefix Dr. to doctor legal name if omitted
    $cleanDocName = preg_replace('/^(?:(?:dr\.?|doctor)\s+)+/i', '', $name);
    $name = 'Dr. ' . $cleanDocName;

} elseif ($role === 'Staff') {
    // Required hospital_id dropdown dynamically queried from hospitals
    $raw_hosp_id = (int)($_POST['hospital_id'] ?? $_POST['facility'] ?? 0);
    if ($raw_hosp_id <= 0) {
        sendRegisterResponse('error', 'Please select your affiliated hospital facility from the dropdown.');
    }

    $hCheck = $pdo->prepare("SELECT hospital_id, name, city FROM hospitals WHERE hospital_id = ? LIMIT 1");
    $hCheck->execute([$raw_hosp_id]);
    $hospRow = $hCheck->fetch(PDO::FETCH_ASSOC);

    if (!$hospRow) {
        sendRegisterResponse('error', 'The selected hospital facility is not recognized. Please choose from the available facilities.');
    }
    $hospital_id = (int)$hospRow['hospital_id'];

    $raw_dept = trim($_POST['department'] ?? $_POST['role_title'] ?? '');
    $department = $raw_dept !== '' ? $raw_dept : 'General Staff';
}

try {
    // 8. Pre-Flight Duplicate Checks
    $checkEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = :email LIMIT 1");
    $checkEmail->execute([':email' => $email]);
    if ($checkEmail->fetch()) {
        sendRegisterResponse('error', 'This email address is already registered. Please sign in or use a different email.');
    }

    $checkPhone = $pdo->prepare("SELECT user_id FROM users WHERE phone = :phone LIMIT 1");
    $checkPhone->execute([':phone' => $phone]);
    if ($checkPhone->fetch()) {
        sendRegisterResponse('error', 'This phone number is already associated with an existing account.');
    }

    // 9. Password Hashing (BCrypt cost 12)
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // 10. Database Persistence with Role, Demographics & Status
    // Patient is immediately active; Doctor & Staff require administrative verification (status = 'pending')
    $status = ($role === 'Patient') ? 'active' : 'pending';

    // Retry loop wrapping transaction to seamlessly recover from rare patient_uid collisions
    $maxRetries = 5;
    $registeredSuccess = false;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare("
                INSERT INTO users (full_name, email, phone, gender, role, status, password_hash, blood_group, date_of_birth, age, hospital_id, department, license_id)
                VALUES (:name, :email, :phone, :gender, :role, :status, :hash, :bg, :dob, :age, :hosp_id, :dept, :lic)
            ");
            $insert->execute([
                'name'    => $name,
                'email'   => $email,
                'phone'   => $phone,
                'gender'  => $gender,
                'role'    => $role,
                'status'  => $status,
                'hash'    => $hashedPassword,
                'bg'      => $blood_group,
                'dob'     => $dob,
                'age'     => $age,
                'hosp_id' => $hospital_id,
                'dept'    => $department ?: ($hospRow ? $hospRow['name'] : null),
                'lic'     => $bmdcLicense ?: null
            ]);
            $newUserId = (int)$pdo->lastInsertId();

            // 11. Patient Specific: Linked Record & Unique patient_uid (MP-YYYY-XXXXXX)
            if ($role === 'Patient') {
                $currentYear = date('Y');
                $chkUid = $pdo->prepare("SELECT 1 FROM patients WHERE patient_uid = ? LIMIT 1");
                do {
                    $patientUid = generatePatientUid($currentYear);
                    $chkUid->execute([$patientUid]);
                    $exists = (bool)$chkUid->fetchColumn();
                } while ($exists);

                $insPatient = $pdo->prepare("
                    INSERT INTO patients (user_id, patient_uid, full_name, email, phone, gender, dob, blood_group, created_at)
                    VALUES (:uid, :p_uid, :name, :email, :phone, :gender, :dob, :bg, NOW())
                ");
                $insPatient->execute([
                    ':uid'   => $newUserId,
                    ':p_uid' => $patientUid,
                    ':name'  => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':gender'=> $gender,
                    ':dob'   => $dob,
                    ':bg'    => $blood_group
                ]);

                // Audit Log Entry
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                        VALUES (:actor, 'Patient', 'PATIENT_REGISTRATION', 'Patient Registration', :desc, 'REGISTRATION', :target, :ip, 'INFO')
                    ");
                    $auditStmt->execute([
                        ':actor'  => $newUserId,
                        ':desc'   => "Patient registered with UID {$patientUid}, Blood Group {$blood_group}, DOB {$dob}",
                        ':target' => $patientUid,
                        ':ip'     => $ip
                    ]);
                } catch (Throwable $e) {}

                $pdo->commit();
                $registeredSuccess = true;

                // Populate session for automatic login
                $_SESSION['user_id']       = $newUserId;
                $_SESSION['patient_uid']   = $patientUid;
                $_SESSION['full_name']     = $name;
                $_SESSION['email']         = $email;
                $_SESSION['phone']         = $phone;
                $_SESSION['gender']        = $gender;
                $_SESSION['dob']           = $dob;
                $_SESSION['blood_group']   = $blood_group;
                $_SESSION['role']          = 'patient';
                $_SESSION['status']        = 'active';
                $_SESSION['logged_in']     = true;
                $_SESSION['last_activity'] = time();

                sendRegisterResponse('success', "Welcome to MedPulse! Registration successful. Your Patient ID is {$patientUid}. Entering patient dashboard...", [
                    'pending'     => false,
                    'role'        => 'patient',
                    'patient_uid' => $patientUid,
                    'redirect'    => 'patient/dashboard.php'
                ]);

            } elseif ($role === 'Doctor') {
                // 12. Doctor Specific: Provision doctors AND doctor_profiles with chosen hospital_id & status = 'pending'
                $insDoctor = $pdo->prepare("
                    INSERT INTO doctors (user_id, hospital_id, status, created_at)
                    VALUES (:uid, :hosp_id, 'pending', NOW())
                ");
                $insDoctor->execute([
                    ':uid'     => $newUserId,
                    ':hosp_id' => $hospital_id
                ]);

                $docProfileStmt = $pdo->prepare("
                    INSERT INTO doctor_profiles 
                        (user_id, hospital_id, specialty, designation, qualifications, bmdc_license_number, bmdc_reg_number, approval_status, consultation_fee, room_number, available_days, shift_timings)
                    VALUES 
                        (:uid, :hosp_id, 'Clinical Practice & Specialty Care', 'Consultant', 'MBBS', :bmdc_lic, :bmdc_reg, 'pending', 1200.00, :room, 'Mon,Tue,Wed,Thu,Fri', '09:00 AM - 05:00 PM')
                ");
                $docProfileStmt->execute([
                    ':uid'      => $newUserId,
                    ':hosp_id'  => $hospital_id,
                    ':bmdc_lic' => $bmdcLicense,
                    ':bmdc_reg' => $bmdcLicense,
                    ':room'     => 'Room-' . mt_rand(201, 508),
                ]);

                // Audit Log Entry
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                        VALUES (:actor, 'Doctor', 'DOCTOR_REGISTRATION_SUBMITTED', 'Doctor Registration Submitted', :desc, 'VERIFICATION', 'DOCTOR_PORTAL', :ip, 'INFO')
                    ");
                    $auditStmt->execute([
                        ':actor' => $newUserId,
                        ':desc'  => "Doctor registered awaiting verification under hospital ID {$hospital_id} ({$hospRow['name']}): {$name} (BMDC: {$bmdcLicense})",
                        ':ip'    => $ip,
                    ]);
                } catch (Throwable $e) {}

                $pdo->commit();
                $registeredSuccess = true;

                sendRegisterResponse('success', "Registration successful! Your doctor credentials under {$hospRow['name']} have been submitted to hospital administration for verification. You will gain portal access once approved.", [
                    'pending'  => true,
                    'role'     => 'doctor',
                    'redirect' => 'login.php?msg=pending_verification'
                ]);

            } elseif ($role === 'Staff') {
                // 13. Staff Specific: Provision staff table bound strictly to selected hospital_id & status = 'pending'
                $insStaff = $pdo->prepare("
                    INSERT INTO staff (user_id, hospital_id, department, role_title, status, created_at)
                    VALUES (:uid, :hosp_id, :dept, :role_title, 'pending', NOW())
                ");
                $insStaff->execute([
                    ':uid'        => $newUserId,
                    ':hosp_id'    => $hospital_id,
                    ':dept'       => $department,
                    ':role_title' => $department ?: 'Staff Member'
                ]);

                // Audit Log Entry
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                        VALUES (:actor, 'Staff', 'STAFF_REGISTRATION_SUBMITTED', 'Staff Registration Submitted', :desc, 'VERIFICATION', 'STAFF_PORTAL', :ip, 'INFO')
                    ");
                    $auditStmt->execute([
                        ':actor' => $newUserId,
                        ':desc'  => "Staff registered awaiting verification under hospital ID {$hospital_id} ({$hospRow['name']}): {$name} ({$department})",
                        ':ip'    => $ip,
                    ]);
                } catch (Throwable $e) {}

                $pdo->commit();
                $registeredSuccess = true;

                sendRegisterResponse('success', "Registration successful! Your staff credentials under {$hospRow['name']} have been submitted to hospital administration for verification. You will gain portal access once approved.", [
                    'pending'  => true,
                    'role'     => 'staff',
                    'redirect' => 'login.php?msg=pending_verification'
                ]);
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errorCode = $e->errorInfo[1] ?? 0;
            $msg = $e->getMessage();
            $isDuplicate = ($errorCode === 1062 || $e->getCode() == 23000);

            // Catch SQLSTATE 23000 / 1062 on patient_uid and retry generation seamlessly
            if ($role === 'Patient' && $isDuplicate && (stripos($msg, 'patient_uid') !== false || stripos($msg, 'uq_patient_uid') !== false)) {
                continue;
            }

            if ($isDuplicate) {
                if (stripos($msg, 'email') !== false) {
                    sendRegisterResponse('error', 'This email address is already registered. Please sign in or use a different email.');
                } elseif (stripos($msg, 'phone') !== false) {
                    sendRegisterResponse('error', 'This phone number is already associated with an existing account.');
                } elseif (stripos($msg, 'bmdc') !== false) {
                    sendRegisterResponse('error', 'This BMDC registration number is already registered under an existing doctor profile.');
                } else {
                    sendRegisterResponse('error', 'An account with these details already exists. Please verify your information.');
                }
            }

            error_log("Registration PDO Exception: " . $e->getMessage());
            sendRegisterResponse('error', 'Database service error: ' . $e->getMessage());
        }
    }

    if (!$registeredSuccess) {
        sendRegisterResponse('error', 'Failed to generate a unique patient identifier. Please try submitting again.');
    }

} catch (Throwable $e) {
    error_log("Registration General Error: " . $e->getMessage());
    sendRegisterResponse('error', 'An unexpected error occurred during registration. Please try again.');
}

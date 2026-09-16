<?php
/**
 * MedPulse Enterprise Authentication Controller
 * Handles session validation, credential authentication, and role-based redirection.
 */

// 1. Session hardening configuration before session initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// 2. If user is already authenticated, route them to their respective dashboard
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Patient') {
        header("Location: patient_dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'Admin') {
        header("Location: admin_dashboard.php");
        exit;
    } elseif (in_array($_SESSION['role'], ['Doctor', 'Staff'], true)) {
        header("Location: dashboard.php");
        exit;
    }
}

// 3. If GET request, render the landing page with auth modal (preserving any error or message query params)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/index.php';
    exit;
}

// 4. Handle Direct POST Authentication Request
require_once __DIR__ . '/config/db.php';

$raw_identifier = trim($_POST['identifier'] ?? $_POST['email'] ?? '');
$password       = $_POST['password'] ?? '';
$selected_tab   = trim($_POST['selected_tab'] ?? $_POST['login_role'] ?? 'Patient');

if ($selected_tab === 'Staff') {
    $selected_tab = 'Doctor/Staff';
}

if ($raw_identifier === '' || $password === '') {
    header("Location: login.php?error=empty_credentials");
    exit;
}

// Normalize phone/email identifier
$clean_phone = preg_replace('/[\s\-\(\)\+]/', '', $raw_identifier);
if (str_starts_with($clean_phone, '8801')) {
    $clean_phone = substr($clean_phone, 2);
}
$identifier = preg_match('/^01[3-9]\d{8}$/', $clean_phone) ? $clean_phone : strtolower($raw_identifier);

try {
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, gender, password_hash, role, status FROM users WHERE email = :ident_email OR phone = :ident_phone LIMIT 1");
    $stmt->execute([
        ':ident_email' => $identifier,
        ':ident_phone' => $identifier
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify account existence and password
    if (!$user || !password_verify($password, $user['password_hash'])) {
        header("Location: login.php?error=invalid_credentials");
        exit;
    }

    // Immediate status gatekeeper (Checked BEFORE role verification or session setting)
    if (strcasecmp($user['status'], 'Pending') === 0) {
        header("Location: login.php?error=pending_approval");
        exit;
    }
    if (strcasecmp($user['status'], 'Suspended') === 0) {
        header("Location: login.php?error=account_suspended");
        exit;
    }
    if (strcasecmp($user['status'], 'Rejected') === 0) {
        header("Location: login.php?error=account_declined");
        exit;
    }

    // Role verification against active tab
    if ($selected_tab === 'Patient' && $user['role'] !== 'Patient') {
        header("Location: login.php?error=role_mismatch");
        exit;
    }
    if ($selected_tab === 'Doctor/Staff' && !in_array($user['role'], ['Doctor', 'Staff'], true)) {
        header("Location: login.php?error=role_mismatch");
        exit;
    }
    if ($selected_tab === 'Admin' && $user['role'] !== 'Admin') {
        header("Location: login.php?error=role_mismatch");
        exit;
    }

    // Fallback status check
    if ($user['status'] !== 'active') {
        header("Location: login.php?error=account_inactive");
        exit;
    }

    // 5. Session fixation prevention & non-sensitive session storage
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['user_id'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();

    // 6. Role-Based Redirection Flow
    if ($user['role'] === 'Patient') {
        header("Location: patient_dashboard.php");
        exit;
    } elseif ($user['role'] === 'Admin') {
        header("Location: admin_dashboard.php");
        exit;
    } elseif (in_array($user['role'], ['Doctor', 'Staff'], true)) {
        header("Location: dashboard.php");
        exit;
    } else {
        header("Location: patient_dashboard.php");
        exit;
    }

} catch (PDOException $e) {
    error_log("Database error in login.php: " . $e->getMessage());
    header("Location: login.php?error=server_error");
    exit;
}

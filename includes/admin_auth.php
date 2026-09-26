<?php
/**
 * MedPulse Enterprise — Admin Area Session Guard
 * Allows role: 'Admin' (case-insensitive match)
 */

require_once __DIR__ . '/session_guard.php';

// Helper: destroy session and redirect
function _adminAuthRedirect(string $target): never {
    medpulseDestroySession($target);
}

// 2. RBAC Guard — must be logged in AND role = admin
$_roleCheck = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || $_roleCheck !== 'admin') {
    _adminAuthRedirect('../login.php');
}

// 3. Inactivity Timeout (30 minutes)
$inactiveTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    _adminAuthRedirect('../login.php?error=session_expired');
}
$_SESSION['last_activity'] = time();

// 4. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// 5. DB Connection & DB-level admin record verification
require_once __DIR__ . '/../config/db.php';

try {
    $authStmt = $pdo->prepare("SELECT user_id, full_name, email, role, status, hospital_id FROM users WHERE user_id = :id AND role = 'Admin' LIMIT 1");
    $authStmt->execute([':id' => (int)$_SESSION['user_id']]);
    $currentAdmin = $authStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentAdmin || strtolower($currentAdmin['status']) !== 'active') {
        _adminAuthRedirect('../login.php');
    }

    $adminName  = $currentAdmin['full_name'];
    $adminEmail = $currentAdmin['email'];

    if (!isset($_SESSION['hospital_id']) || empty($_SESSION['hospital_id'])) {
        $_SESSION['hospital_id'] = (int)($currentAdmin['hospital_id'] ?? 1);
    }

    require_once __DIR__ . '/../config/tenant_scope.php';
    TenantScope::detectTampering($pdo);
    $sessionHospitalId = (int)$_SESSION['hospital_id'];

} catch (PDOException $e) {
    error_log('Admin Auth DB error: ' . $e->getMessage());
    die('A secure database communication failure occurred. Please contact system engineering.');
}

// 6. Dynamic Time-based greeting (Asia/Dhaka)
date_default_timezone_set('Asia/Dhaka');
$hour = (int)date('H');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}


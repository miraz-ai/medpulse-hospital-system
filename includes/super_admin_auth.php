<?php
/**
 * MedPulse Enterprise — Super Admin Session Guard
 * Allows role: 'super_admin' only (strictly enforced)
 */

require_once __DIR__ . '/session_guard.php';

// Helper: destroy session and redirect
function _saAuthRedirect(string $target): never {
    medpulseDestroySession($target);
}

// 2. Strict RBAC Guard — must be logged in AND role = super_admin
$_roleCheck = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || $_roleCheck !== 'super_admin') {
    _saAuthRedirect('../login.php?error=unauthorized');
}

// 3. Inactivity Timeout (30 minutes)
$inactiveTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    _saAuthRedirect('../login.php?error=session_expired');
}
$_SESSION['last_activity'] = time();

// 4. CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// 5. DB Connection & DB-level super_admin record verification
require_once __DIR__ . '/../config/db.php';

try {
    $authStmt = $pdo->prepare("SELECT user_id, full_name, email, role, status FROM users WHERE user_id = :id AND role = 'super_admin' LIMIT 1");
    $authStmt->execute([':id' => (int)$_SESSION['user_id']]);
    $currentSuperAdmin = $authStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentSuperAdmin || strtolower($currentSuperAdmin['status']) !== 'active') {
        _saAuthRedirect('../login.php?error=unauthorized');
    }

    $adminName  = $currentSuperAdmin['full_name'];
    $adminEmail = $currentSuperAdmin['email'];

} catch (PDOException $e) {
    error_log('Super Admin Auth DB error: ' . $e->getMessage());
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


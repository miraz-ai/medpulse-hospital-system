<?php
/**
 * MedPulse Enterprise — Super Admin Session Guard
 * Allows role: 'super_admin' only (strictly enforced)
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

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

// 1. Mandatory anti-caching headers (prevents Back-button session leakage)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Helper: destroy session and redirect
function _saAuthRedirect(string $target): never {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . $target);
    exit();
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

// 6. Time-based greeting
$currentHour = (int)date('H');
$greeting = match(true) {
    $currentHour < 12 => 'Good Morning',
    $currentHour < 17 => 'Good Afternoon',
    default           => 'Good Evening',
};

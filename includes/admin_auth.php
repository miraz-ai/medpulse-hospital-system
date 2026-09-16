<?php
/**
 * MedPulse Enterprise Admin Authentication & Session Gatekeeper
 * Enforces hardened session cookies, strict Admin RBAC, timeout, and anti-caching headers.
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

// 1. Strict Authentication & Role-Based Access Control (RBAC) Guard
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// 2. Inactivity Timeout (30 minutes)
$inactiveTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$_SESSION['last_activity'] = time();

// 3. Anti-Caching Headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 4. CSRF Token Initialization
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// 5. Database Connection & Active Admin Record Verification
require_once __DIR__ . '/../config/db.php';

try {
    $authStmt = $pdo->prepare("SELECT user_id, full_name, email, phone, gender, role, status FROM users WHERE user_id = :id AND role = 'Admin' LIMIT 1");
    $authStmt->execute([':id' => $_SESSION['user_id']]);
    $currentAdmin = $authStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentAdmin || $currentAdmin['status'] !== 'active') {
        session_destroy();
        header("Location: ../login.php");
        exit();
    }

    $adminName  = $currentAdmin['full_name'];
    $adminEmail = $currentAdmin['email'];

} catch (PDOException $e) {
    error_log("Admin Auth DB error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact system engineering.");
}

// 6. Dynamic Time-based Greeting
$currentHour = (int)date('H');
if ($currentHour < 12) {
    $greeting = "Good Morning";
} elseif ($currentHour < 17) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}

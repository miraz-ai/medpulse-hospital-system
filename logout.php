<?php
/**
 * MedPulse Enterprise Secure Logout Handler
 * Fully destroys the authenticated session, invalidates cookies, and safely redirects.
 */

// Initialize session with secure parameters if not already active
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

// 1. Wipe all session data from server memory
$_SESSION = [];

// 2. Invalidate the session cookie on client browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destroy the session storage on server
session_destroy();

// 4. Safe redirect to login with logout confirmation flag
header("Location: login.php?msg=logged_out");
exit;

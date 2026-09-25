<?php
/**
 * MedPulse Enterprise Secure Logout Handler
 * Fully destroys the authenticated session, invalidates cookies, and safely redirects.
 */

require_once __DIR__ . '/includes/session_guard.php';

// 1. Wipe all session variables
$_SESSION = [];

// 2. Explicit past timestamp destruction of session cookie
if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// 3. Destroy session
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// 4. Safe redirect with clean state
header("Location: login.php?logged_out=1");
exit();

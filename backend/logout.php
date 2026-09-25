<?php
/**
 * MedPulse Backend Logout Handler
 */

require_once __DIR__ . '/../includes/session_guard.php';

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', time() - 42000, '/');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header("Location: ../login.php?logged_out=1");
exit();
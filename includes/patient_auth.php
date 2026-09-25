<?php
/**
 * MedPulse Enterprise — Patient Portal Session Guard
 * Allows role: 'patient' (strictly enforced)
 */

require_once __DIR__ . '/session_guard.php';

$_roleCheck = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || $_roleCheck !== 'patient') {
    medpulseDestroySession('../login.php');
}

// Inactivity Timeout (30 minutes)
$inactiveTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    medpulseDestroySession('../login.php?error=session_expired');
}
$_SESSION['last_activity'] = time();

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../config/db.php';

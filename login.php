<?php
/**
 * MedPulse Enterprise Authentication Controller
 * GET  → renders index.php (login/register UI)
 * POST → proxied to backend/login_action.php
 *
 * This file only handles already-authenticated redirect logic for GET requests.
 * All POST logic lives in backend/login_action.php.
 */

// 1. Session hardening
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

// 2. Universal already-authenticated redirect (role auto-detected from session)
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $roleNorm = strtolower($_SESSION['role']);
    $map = [
        'super_admin' => 'super_admin/dashboard.php',
        'admin'       => 'admin/dashboard.php',
        'doctor'      => 'doctor/dashboard.php',
        'patient'     => 'patient/dashboard.php',
        'staff'       => 'staff/dashboard.php',
    ];
    header('Location: ' . ($map[$roleNorm] ?? 'patient/dashboard.php'));
    exit();
}

// 3. POST → hand off to backend/login_action.php (form action already points there)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/backend/login_action.php';
    exit();
}

// 4. GET → render the login/register UI
require_once __DIR__ . '/index.php';
exit();

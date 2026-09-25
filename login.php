<?php
/**
 * MedPulse Enterprise Authentication Controller
 * GET  → renders index.php (login/register UI)
 * POST → proxied to backend/login_action.php
 *
 * Implements strict anti-caching, role-aware authenticated redirect, and fresh session isolation.
 */

require_once __DIR__ . '/includes/session_guard.php';

// ── 1. If incoming request is POST, wipe any existing stale session before authenticating ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Completely isolate new login attempt from any prior session state
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        $_SESSION = [];
        session_destroy();
    }
    // Start brand-new clean session for this attempt
    require __DIR__ . '/includes/session_guard.php';
    require_once __DIR__ . '/backend/login_action.php';
    exit();
}

// ── 2. GET Request: Role-Aware Already-Authenticated Redirect Guard ────────────
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $destination = medpulseGetRoleDashboard($_SESSION['role']);
    header('Location: ' . $destination);
    exit();
}

// ── 3. Render Authentication View ─────────────────────────────────────────────
require_once __DIR__ . '/index.php';
exit();

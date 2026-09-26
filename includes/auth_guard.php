<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Global Multi-Tenant Authentication & Facility Scoping Guard
 */

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/tenant_scope.php';

// Automatically detect parameter tampering on any guarded route
TenantScope::detectTampering($pdo);

// Ensure user is authenticated
if (empty($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Active session required.']);
        exit();
    }
    medpulseDestroySession('../login.php');
}

// Enforce tenant scoping and ensure hospital_id is resolved and cached in session
$sessionHospitalId = TenantScope::enforce($pdo);

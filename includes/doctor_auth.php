<?php
/**
 * MedPulse Enterprise — Doctor Portal Session Guard
 * Allows role: 'doctor' (strictly enforced)
 */

require_once __DIR__ . '/session_guard.php';

$_roleCheck = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || $_roleCheck !== 'doctor') {
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
require_once __DIR__ . '/../config/tenant_scope.php';

try {
    $docUserStmt = $pdo->prepare("SELECT user_id, full_name, email, role, status, hospital_id FROM users WHERE user_id = :id AND role = 'Doctor' LIMIT 1");
    $docUserStmt->execute([':id' => (int)$_SESSION['user_id']]);
    $currentDocUser = $docUserStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentDocUser || strtolower($currentDocUser['status']) !== 'active') {
        medpulseDestroySession('../login.php?error=unauthorized');
    }

    if (!isset($_SESSION['hospital_id']) || empty($_SESSION['hospital_id'])) {
        $docAffilStmt = $pdo->prepare("SELECT hospital_id FROM doctors WHERE user_id = :uid LIMIT 1");
        $docAffilStmt->execute([':uid' => (int)$_SESSION['user_id']]);
        $docHosp = $docAffilStmt->fetchColumn();
        $_SESSION['hospital_id'] = (int)($docHosp ?: ($currentDocUser['hospital_id'] ?? 1));
    }

    TenantScope::detectTampering($pdo);
    $sessionHospitalId = (int)$_SESSION['hospital_id'];

} catch (PDOException $e) {
    error_log('Doctor Auth DB error: ' . $e->getMessage());
    die('A secure database communication failure occurred. Please contact system engineering.');
}

<?php
/**
 * MedPulse Patient Portal — Dismiss Completed Consultation Endpoint
 */
declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../../includes/session_guard.php';

global $pdo;
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/db.php';
}
if (!isset($pdo) && isset($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
}

require_once __DIR__ . '/../../controllers/TelemedicineController.php';

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'patient') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Patient authentication required.'
    ]);
    exit;
}

$patientId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed.']);
    exit;
}

$submittedCsrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!empty($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$appointmentId = (int)($_POST['appointment_id'] ?? 0);
if ($appointmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
    exit;
}

try {
    $res = TelemedicineController::dismissPatientSession($pdo, $patientId, $appointmentId);
    echo json_encode($res);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

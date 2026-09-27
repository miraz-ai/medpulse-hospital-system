<?php
/**
 * MedPulse Patient Portal — Request Live Tele-Consultation Session Endpoint
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

// Authentication Check: User must be logged in as Patient
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
    echo json_encode([
        'success' => false,
        'message' => 'Method Not Allowed. POST required.'
    ]);
    exit;
}

// CSRF check
$submittedCsrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!empty($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Security token invalid or expired. Please refresh the page.'
    ]);
    exit;
}

$doctorId = (int)($_POST['doctor_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$symptoms = trim($_POST['symptoms'] ?? '');

if ($doctorId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please select an available on-call duty specialist.'
    ]);
    exit;
}

try {
    $res = TelemedicineController::requestLiveSession($pdo, $patientId, $doctorId, $reason, $symptoms);
    
    if ($res['success']) {
        // Also fetch the full immediate session telemetry
        $activeSession = TelemedicineController::getPatientLiveSession($pdo, $patientId);
        $res['session'] = $activeSession;
    }
    
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error creating consultation session: ' . $e->getMessage()
    ]);
}

<?php
/**
 * MedPulse Patient Portal — Zero-Reload Live Telemedicine Sync Endpoint
 * 
 * High-speed JSON telemetry polling endpoint (every 3-4s):
 * - Real-time queue progression
 * - Dynamic token tracking
 * - Privacy lock enforcement: zoom_link is ONLY revealed if patient token is actively called!
 */
declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
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

try {
    // 1. Fetch current patient's active live tele-consultation session
    $activeSession = TelemedicineController::getPatientLiveSession($pdo, $patientId);

    // 2. Fetch live duty doctors status
    $dutyDoctors = TelemedicineController::getOnCallDutyDoctors($pdo);

    // Filter sensitive fields from doctors list
    $cleanDoctors = array_map(function($d) {
        return [
            'user_id'               => (int)$d['user_id'],
            'full_name'             => $d['full_name'],
            'specialty'             => $d['specialty'],
            'designation'           => $d['designation'],
            'hospital_name'         => $d['hospital_name'],
            'room_code'             => $d['teleconsult_room_code'],
            'session_status'        => $d['session_status'],
            'current_serving_token' => (int)$d['current_serving_token'],
            'waiting_count'         => (int)$d['waiting_count'],
            'next_waiting_token'    => (int)$d['next_waiting_token']
        ];
    }, $dutyDoctors);

    echo json_encode([
        'success'            => true,
        'timestamp'          => time(),
        'has_active_session' => ($activeSession !== null),
        'session'            => $activeSession,
        'on_call_doctors'    => $cleanDoctors
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sync error: ' . $e->getMessage()
    ]);
}

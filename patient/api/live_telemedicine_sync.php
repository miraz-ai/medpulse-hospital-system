<?php
/**
 * MedPulse Patient Portal — Zero-Reload Live Telemedicine Sync Endpoint
 * 
 * High-speed JSON telemetry polling endpoint (every 3-4s):
 * - Real-time queue progression
 * - Dynamic token tracking
 * - Strictly isolates doctors to selected hospital branch
 * - Privacy lock enforcement: zoom_link is ONLY revealed if patient token is actively called!
 * - Zero fake countdown timers: Driven solely by sequential tokens
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
$requestedHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 1;
if ($requestedHospitalId <= 0) $requestedHospitalId = 1;

try {
    // 1. Fetch current patient's active live tele-consultation session (if any)
    $activeSession = TelemedicineController::getPatientLiveSession($pdo, $patientId);

    // If patient is in an active session, use that session's hospital
    $effectiveHospitalId = ($activeSession && !empty($activeSession['hospital_id']))
        ? (int)$activeSession['hospital_id']
        : $requestedHospitalId;

    // 2. Fetch all active network hospital branches
    $hospitals = TelemedicineController::getNetworkHospitals($pdo);

    // 3. Fetch verified 24/7 on-call duty doctors strictly for this hospital branch
    $dutyDoctors = TelemedicineController::getOnCallDutyDoctors($pdo, $effectiveHospitalId);

    // Format clean doctor objects
    $cleanDoctors = array_map(function($d) {
        return [
            'user_id'               => (int)$d['user_id'],
            'full_name'             => $d['full_name'],
            'specialty'             => $d['specialty'],
            'designation'           => $d['designation'],
            'hospital_id'           => (int)$d['hospital_id'],
            'hospital_name'         => $d['hospital_name'],
            'hospital_city'         => $d['hospital_city'],
            'room_code'             => $d['teleconsult_room_code'],
            'session_status'        => $d['session_status'],
            'current_serving_token' => (int)$d['current_serving_token'],
            'waiting_count'         => (int)$d['waiting_count'],
            'queue_load_label'      => $d['queue_load_label'],
            'active_patient_name'   => $d['active_patient_name']
        ];
    }, $dutyDoctors);

    echo json_encode([
        'success'              => true,
        'timestamp'            => time(),
        'has_active_session'   => ($activeSession !== null),
        'session'              => $activeSession,
        'selected_hospital_id' => $effectiveHospitalId,
        'hospitals'            => $hospitals,
        'on_call_doctors'      => $cleanDoctors
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sync error: ' . $e->getMessage()
    ]);
}

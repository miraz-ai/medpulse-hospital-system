<?php
/**
 * MedPulse Doctor Portal — Virtual Care Chamber Host Actions Endpoint
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

// Authentication Check: User must be logged in as Doctor
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'doctor') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Doctor credentials required.'
    ]);
    exit;
}

$doctorId = (int)$_SESSION['user_id'];
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    switch ($action) {
        case 'call_next':
            $res = TelemedicineController::callNextPatient($pdo, $doctorId);
            echo json_encode($res, JSON_UNESCAPED_SLASHES);
            break;

        case 'complete_session':
            $res = TelemedicineController::completeSession($pdo, $doctorId);
            echo json_encode($res, JSON_UNESCAPED_SLASHES);
            break;

        case 'update_link':
            $link = trim($_POST['meeting_link'] ?? '');
            $res = TelemedicineController::updateDoctorMeetingLink($pdo, $doctorId, $link);
            echo json_encode($res, JSON_UNESCAPED_SLASHES);
            break;

        case 'get_queue':
        default:
            // Fetch current doctor's queue and live status
            $docStmt = $pdo->prepare("
                SELECT u.full_name, dp.specialty, dp.room_number, dp.teleconsult_link, dp.teleconsult_room_code,
                       COALESCE(dp.session_status, 'idle') AS session_status,
                       COALESCE(dp.current_serving_token, 0) AS current_serving_token
                FROM users u
                LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
                WHERE u.user_id = :doc_id
                LIMIT 1
            ");
            $docStmt->execute([':doc_id' => $doctorId]);
            $doc = $docStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $defaultMeeting = TelemedicineController::buildDefaultMeetingUrl($doctorId);
            $meetingLink = $doc['teleconsult_link'] ?: $defaultMeeting['url'];

            // Currently serving patient
            $activeServingStmt = $pdo->prepare("
                SELECT a.id, a.token_number, a.patient_id, a.actual_start_time, a.reason_for_visit, a.symptoms,
                       u.full_name AS patient_name, u.phone, u.gender
                FROM appointments a
                JOIN users u ON a.patient_id = u.user_id
                WHERE a.doctor_id = :doc_id
                  AND a.appointment_date = CURRENT_DATE
                  AND a.status = 'in_consultation'
                LIMIT 1
            ");
            $activeServingStmt->execute([':doc_id' => $doctorId]);
            $activePatient = $activeServingStmt->fetch(PDO::FETCH_ASSOC);

            // Waiting patients
            $waitingStmt = $pdo->prepare("
                SELECT a.id, a.token_number, a.patient_id, a.created_at, a.reason_for_visit, a.symptoms,
                       u.full_name AS patient_name, u.phone, u.gender
                FROM appointments a
                JOIN users u ON a.patient_id = u.user_id
                WHERE a.doctor_id = :doc_id
                  AND a.appointment_date = CURRENT_DATE
                  AND a.status IN ('booked', 'checked_in')
                  AND (a.queue_status IS NULL OR a.queue_status != 'completed')
                ORDER BY a.token_number ASC
            ");
            $waitingStmt->execute([':doc_id' => $doctorId]);
            $waitingPatients = $waitingStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'               => true,
                'timestamp'             => time(),
                'session_status'        => $doc['session_status'] ?? 'idle',
                'current_serving_token' => (int)($doc['current_serving_token'] ?? 0),
                'meeting_link'          => $meetingLink,
                'room_code'             => $doc['teleconsult_room_code'] ?: $defaultMeeting['room_code'],
                'active_patient'        => $activePatient ?: null,
                'waiting_patients'      => $waitingPatients,
                'waiting_count'         => count($waitingPatients)
            ], JSON_UNESCAPED_SLASHES);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Action failed: ' . $e->getMessage()
    ]);
}

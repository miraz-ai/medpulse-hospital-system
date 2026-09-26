<?php
/**
 * MedPulse OPD Queue Real-time API Endpoint
 * Provides JSON payloads for:
 * - Patient live queue tracker updates (current serving, wait time, people ahead)
 * - Attending doctor chamber queue advancement (Call Next Patient)
 * - Shift capacity checks (cap 25)
 */

require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../controllers/AppointmentController.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized session. Please sign in.']);
    exit();
}

$userId = (int)$_SESSION['user_id'];
$userRole = strtolower($_SESSION['role'] ?? '');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'patient_live_status':
        $targetPatientId = $userId;
        if ($userRole !== 'patient') {
            if (isset($_GET['patient_id']) && in_array($userRole, ['admin', 'super_admin', 'doctor', 'staff'])) {
                $targetPatientId = (int)$_GET['patient_id'];
            } else {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Access denied: Patient role required.']);
                exit();
            }
        }
        $data = AppointmentController::getPatientLiveQueue($pdo, $targetPatientId);
        echo json_encode([
            'status' => 'success',
            'data'   => $data
        ]);
        exit();

    case 'doctor_queue':
        if ($userRole !== 'doctor') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Doctor role required.']);
            exit();
        }
        $data = AppointmentController::getDoctorTodayQueue($pdo, $userId);
        echo json_encode([
            'status' => 'success',
            'data'   => $data
        ]);
        exit();

    case 'call_next':
        if ($userRole !== 'doctor' && !in_array($userRole, ['admin', 'super_admin'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Doctor or Admin role required.']);
            exit();
        }
        $docId = ($userRole === 'doctor') ? $userId : (int)($_POST['doctor_id'] ?? $_GET['doctor_id'] ?? $userId);
        $actualDuration = isset($_REQUEST['actual_duration']) ? (int)$_REQUEST['actual_duration'] : null;
        $result = AppointmentController::callNextPatient($pdo, $docId, $actualDuration);
        echo json_encode([
            'status' => $result['success'] ? 'success' : 'error',
            'data'   => $result
        ]);
        exit();

    case 'start_session':
        if ($userRole !== 'doctor' && !in_array($userRole, ['admin', 'super_admin'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Doctor or Admin role required.']);
            exit();
        }
        $docId = ($userRole === 'doctor') ? $userId : (int)($_POST['doctor_id'] ?? $_GET['doctor_id'] ?? $userId);
        $result = AppointmentController::startChamberSession($pdo, $docId);
        echo json_encode([
            'status' => $result['success'] ? 'success' : 'error',
            'data'   => $result
        ]);
        exit();

    case 'hold_token':
        if ($userRole !== 'doctor' && !in_array($userRole, ['admin', 'super_admin'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Doctor or Admin role required.']);
            exit();
        }
        $docId = ($userRole === 'doctor') ? $userId : (int)($_POST['doctor_id'] ?? $_GET['doctor_id'] ?? $userId);
        $appId = isset($_REQUEST['appointment_id']) ? (int)$_REQUEST['appointment_id'] : null;
        $result = AppointmentController::holdCurrentToken($pdo, $docId, $appId);
        echo json_encode([
            'status' => $result['success'] ? 'success' : 'error',
            'data'   => $result
        ]);
        exit();

    case 'end_session':
        if ($userRole !== 'doctor' && !in_array($userRole, ['admin', 'super_admin'])) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Doctor or Admin role required.']);
            exit();
        }
        $docId = ($userRole === 'doctor') ? $userId : (int)($_POST['doctor_id'] ?? $_GET['doctor_id'] ?? $userId);
        $result = AppointmentController::endChamberSession($pdo, $docId);
        echo json_encode([
            'status' => $result['success'] ? 'success' : 'error',
            'data'   => $result
        ]);
        exit();

    case 'update_delta':
        $doctorId = (int)($_POST['doctor_id'] ?? $_GET['doctor_id'] ?? $_REQUEST['doctor_id'] ?? ($userRole === 'doctor' ? $userId : 0));
        $delta = isset($_POST['delta_minutes']) ? (int)$_POST['delta_minutes'] : (int)($_GET['delta_minutes'] ?? $_REQUEST['delta_minutes'] ?? 0);
        $isAbsolute = !empty($_REQUEST['absolute']);

        if ($doctorId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Valid doctor_id is required.']);
            exit();
        }

        if ($isAbsolute) {
            $stmt = $pdo->prepare("UPDATE doctor_profiles SET accumulated_delta_minutes = :delta WHERE user_id = :doc_id");
        } else {
            $stmt = $pdo->prepare("UPDATE doctor_profiles SET accumulated_delta_minutes = accumulated_delta_minutes + :delta WHERE user_id = :doc_id");
        }
        $stmt->execute([':delta' => $delta, ':doc_id' => $doctorId]);

        $docStmt = $pdo->prepare("SELECT accumulated_delta_minutes, avg_consultation_time, session_status FROM doctor_profiles WHERE user_id = ?");
        $docStmt->execute([$doctorId]);
        $docInfo = $docStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'                    => 'success',
            'doctor_id'                 => $doctorId,
            'accumulated_delta_minutes' => (int)($docInfo['accumulated_delta_minutes'] ?? 0),
            'avg_consultation_time'     => (int)($docInfo['avg_consultation_time'] ?? 10),
            'session_status'            => $docInfo['session_status'] ?? 'idle'
        ]);
        exit();

    case 'set_session_status':
        $doctorId = (int)($_POST['doctor_id'] ?? $_GET['doctor_id'] ?? $_REQUEST['doctor_id'] ?? ($userRole === 'doctor' ? $userId : 0));
        $sessStatus = strtolower(trim($_REQUEST['session_status'] ?? 'live'));
        if (!in_array($sessStatus, ['idle', 'live', 'paused', 'completed'])) {
            $sessStatus = 'live';
        }
        if ($doctorId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Valid doctor_id is required.']);
            exit();
        }
        $stmt = $pdo->prepare("UPDATE doctor_profiles SET session_status = :st WHERE user_id = :doc_id");
        $stmt->execute([':st' => $sessStatus, ':doc_id' => $doctorId]);
        echo json_encode([
            'status'         => 'success',
            'doctor_id'      => $doctorId,
            'session_status' => $sessStatus
        ]);
        exit();

    case 'check_capacity':
        $doctorId = (int)($_GET['doctor_id'] ?? 0);
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        $timeSlot = trim($_GET['time_slot'] ?? 'Morning');

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS booked_count, COALESCE(MAX(token_number), 0) AS last_token
            FROM appointments
            WHERE doctor_id = :doc_id 
              AND appointment_date = :app_date 
              AND time_slot = :slot 
              AND status != 'cancelled'
        ");
        $stmt->execute([
            ':doc_id'   => $doctorId,
            ':app_date' => $date,
            ':slot'     => $timeSlot
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['booked_count'] ?? 0);
        $lastToken = (int)($row['last_token'] ?? 0);

        echo json_encode([
            'status'          => 'success',
            'booked_count'    => $count,
            'max_capacity'    => 25,
            'spots_remaining' => max(0, 25 - $count),
            'is_full'         => ($count >= 25),
            'next_token'      => ($count >= 25) ? null : ($lastToken + 1)
        ]);
        exit();

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action parameter specified.']);
        exit();
}

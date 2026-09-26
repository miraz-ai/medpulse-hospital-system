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
        if ($userRole !== 'patient') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Patient role required.']);
            exit();
        }
        $data = AppointmentController::getPatientLiveQueue($pdo, $userId);
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
        if ($userRole !== 'doctor') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Access denied: Doctor role required.']);
            exit();
        }
        $result = AppointmentController::callNextPatient($pdo, $userId);
        echo json_encode([
            'status' => $result['success'] ? 'success' : 'error',
            'data'   => $result
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

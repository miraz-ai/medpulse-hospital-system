<?php
/**
 * MedPulse Enterprise HMS — Bed Reservation & Network Matrix API
 * Endpoints for live telemetry, 45-minute bed pre-reservations, and cancellation.
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../controllers/BedReservationController.php';

// Authentication guard
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'patient') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Patient session required.']);
    exit();
}

$patientId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? 'matrix');

try {
    switch ($action) {
        case 'matrix':
            $matrix = BedReservationController::getNetworkBedMatrix($pdo);
            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'hospitals'  => $matrix,
                    'network_ts' => date('Y-m-d H:i:s')
                ]
            ]);
            break;

        case 'active_hold':
            $activeHold = BedReservationController::getPatientActiveReservation($pdo, $patientId);
            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'has_active_hold' => ($activeHold !== null),
                    'reservation'     => $activeHold
                ]
            ]);
            break;

        case 'hospital_beds':
            $hospitalId = (int)($_GET['hospital_id'] ?? 0);
            $wardType = $_GET['ward_type'] ?? null;
            if ($hospitalId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Valid hospital_id is required.']);
                exit();
            }
            $beds = BedReservationController::getHospitalAvailableBeds($pdo, $hospitalId, $wardType);
            $wards = BedReservationController::getHospitalWardSummary($pdo, $hospitalId);
            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'beds'  => $beds,
                    'wards' => $wards
                ]
            ]);
            break;

        case 'hold_bed':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                exit();
            }
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $bedId = (int)($input['bed_id'] ?? 0);
            $hospitalId = (int)($input['hospital_id'] ?? 0);

            if ($bedId <= 0 || $hospitalId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'bed_id and hospital_id are required.']);
                exit();
            }

            $res = BedReservationController::reserveBed($pdo, $patientId, $bedId, $hospitalId);
            if ($res['success']) {
                echo json_encode(['status' => 'success', 'data' => $res]);
            } else {
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => $res['message']]);
            }
            break;

        case 'cancel_hold':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
                exit();
            }
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $reservationId = (int)($input['reservation_id'] ?? 0);

            if ($reservationId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'reservation_id is required.']);
                exit();
            }

            $res = BedReservationController::cancelReservation($pdo, $patientId, $reservationId);
            if ($res['success']) {
                echo json_encode(['status' => 'success', 'data' => $res]);
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => $res['message']]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Unknown action '{$action}'."]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
}

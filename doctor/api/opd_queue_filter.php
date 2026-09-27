<?php
/**
 * MedPulse — OPD Queue Filter API
 * Returns patient queue scoped to a specific appointment_date + shift (time_slot).
 * Called via AJAX from the 4-day date switcher on the doctor's OPD Chamber Console.
 *
 * GET  ?date=YYYY-MM-DD&shift=Morning|Evening
 * GET  ?action=capacity&dates[]=...  -> returns booked counts for tab indicators
 */

require_once __DIR__ . '/../../includes/doctor_auth.php';
require_once __DIR__ . '/../../controllers/AppointmentController.php';

header('Content-Type: application/json; charset=utf-8');

$doctorUserId = (int)($_SESSION['user_id'] ?? 0);
if (!$doctorUserId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = trim($_GET['action'] ?? 'queue');

// Action: capacity indicators for the 4-day tabs
if ($action === 'capacity') {
    $rawDates = $_GET['dates'] ?? [];
    if (!is_array($rawDates)) $rawDates = [];

    $validDates = [];
    foreach (array_slice($rawDates, 0, 7) as $rd) {
        $d = DateTime::createFromFormat('Y-m-d', trim($rd));
        if ($d && $d->format('Y-m-d') === trim($rd)) {
            $validDates[] = $d->format('Y-m-d');
        }
    }

    $capacity = AppointmentController::getDoctorDayCapacity($pdo, $doctorUserId, $validDates);
    echo json_encode(['status' => 'success', 'data' => $capacity]);
    exit();
}

// Action: filtered patient queue
$date  = trim($_GET['date']  ?? date('Y-m-d'));
$shift = trim($_GET['shift'] ?? 'Morning');

$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}
$shift = in_array(strtolower($shift), ['morning', 'evening'])
    ? ucfirst(strtolower($shift))
    : 'Morning';

$result = AppointmentController::getDoctorQueueByDateShift($pdo, $doctorUserId, $date, $shift);

// Fetch currently serving token for the selected date/shift
try {
    $srvStmt = $pdo->prepare("
        SELECT COALESCE(MAX(token_number), 0) AS serving_token
        FROM appointments
        WHERE doctor_id        = :doctor_id
          AND appointment_date = :appt_date
          AND time_slot        = :shift
          AND status           = 'in_consultation'
        LIMIT 1
    ");
    $srvStmt->execute([':doctor_id' => $doctorUserId, ':appt_date' => $date, ':shift' => $shift]);
    $servingToken = (int)($srvStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $servingToken = 0;
}
$result['serving_token'] = $servingToken;

foreach ($result['patients'] as &$pt) {
    if (!empty($pt['appointment_time'])) {
        $pt['appointment_time_fmt'] = date('h:i A', strtotime($pt['appointment_time']));
    } else {
        $pt['appointment_time_fmt'] = 'Scheduled';
    }
    if (!empty($pt['appointment_date'])) {
        $pt['appointment_date_fmt'] = date('D, M j', strtotime($pt['appointment_date']));
    }
    unset($pt['baseline_vitals'], $pt['allergies'], $pt['dob']);
}
unset($pt);

echo json_encode([
    'status' => 'success',
    'date'   => $date,
    'shift'  => $shift,
    'data'   => $result,
]);
exit();

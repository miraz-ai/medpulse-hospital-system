<?php
/**
 * MedPulse — Patient OPD Live Queue Sync API
 * Returns real-time queue status for the logged-in patient's active today appointments.
 *
 * GET ?appointment_id=NNN   → single appointment live status
 * GET (no params)           → all active today appointments for this patient
 *
 * Response: {
 *   status: 'success',
 *   appointments: [
 *     {
 *       appointment_id, doctor_id, doctor_name, shift, date,
 *       my_token, serving_token, queue_position,
 *       patients_ahead, estimated_wait_min, my_status
 *     }, ...
 *   ]
 * }
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/patient_auth.php';

header('Content-Type: application/json; charset=utf-8');

$patientId = (int)($_SESSION['user_id'] ?? 0);
if (!$patientId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$singleId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

try {
    // Fetch patient's active appointments for today
    if ($singleId > 0) {
        $baseWhere = "a.id = :single_id AND a.patient_id = :patient_id AND a.consultation_type = 'opd'";
        $params = [':single_id' => $singleId, ':patient_id' => $patientId];
    } else {
        $baseWhere = "a.patient_id = :patient_id AND a.appointment_date = CURRENT_DATE AND a.consultation_type = 'opd' AND a.status NOT IN ('cancelled', 'completed')";
        $params = [':patient_id' => $patientId];
    }

    $stmt = $pdo->prepare("
        SELECT
            a.id AS appointment_id,
            a.doctor_id,
            a.token_number   AS my_token,
            a.appointment_date,
            a.time_slot      AS shift,
            a.status         AS my_status,
            a.queue_status,
            u.full_name      AS doctor_name,
            COALESCE(dp.room_number, 'OPD') AS room_number,
            COALESCE(dp.avg_consultation_time, 10) AS avg_mins
        FROM appointments a
        JOIN users u ON a.doctor_id = u.user_id
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE {$baseWhere}
        ORDER BY a.appointment_date ASC, a.token_number ASC
    ");
    $stmt->execute($params);
    $myAppts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    foreach ($myAppts as $appt) {
        $docId   = (int)$appt['doctor_id'];
        $shift   = $appt['shift'];
        $date    = $appt['appointment_date'];
        $myToken = (int)$appt['my_token'];
        $avgMins = max(5, (int)$appt['avg_mins']);

        // Get currently serving token for this doctor/date/shift
        $srvStmt = $pdo->prepare("
            SELECT COALESCE(MAX(token_number), 0) AS serving_token
            FROM appointments
            WHERE doctor_id        = :doctor_id
              AND appointment_date = :appt_date
              AND time_slot        = :shift
              AND status           = 'in_consultation'
            LIMIT 1
        ");
        $srvStmt->execute([
            ':doctor_id'  => $docId,
            ':appt_date'  => $date,
            ':shift'      => $shift,
        ]);
        $servingToken = (int)($srvStmt->fetchColumn() ?: 0);

        // Count patients waiting BEFORE this patient (tokens < my_token, not cancelled/completed)
        $aheadStmt = $pdo->prepare("
            SELECT COUNT(*) AS ahead_count
            FROM appointments
            WHERE doctor_id        = :doctor_id
              AND appointment_date = :appt_date
              AND time_slot        = :shift
              AND token_number     < :my_token
              AND status           NOT IN ('cancelled', 'completed')
        ");
        $aheadStmt->execute([
            ':doctor_id'  => $docId,
            ':appt_date'  => $date,
            ':shift'      => $shift,
            ':my_token'   => $myToken,
        ]);
        $patientsAhead = (int)$aheadStmt->fetchColumn();

        // Total in queue for this shift/date (excluding cancelled/completed)
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) AS total_count
            FROM appointments
            WHERE doctor_id        = :doctor_id
              AND appointment_date = :appt_date
              AND time_slot        = :shift
              AND status           NOT IN ('cancelled', 'completed')
        ");
        $totalStmt->execute([
            ':doctor_id'  => $docId,
            ':appt_date'  => $date,
            ':shift'      => $shift,
        ]);
        $totalActive = (int)$totalStmt->fetchColumn();

        // Queue position = patientsAhead + 1 (unless serving or done)
        $queuePos = $patientsAhead + 1;
        $myStatus = strtolower($appt['my_status'] ?? '');
        $myQueueStatus = strtolower($appt['queue_status'] ?? '');

        if ($myStatus === 'in_consultation' || $myQueueStatus === 'serving') {
            $queuePos     = 0;
            $patientsAhead = 0;
            $estWaitMin   = 0;
        } elseif ($myStatus === 'completed' || $myQueueStatus === 'completed') {
            $queuePos     = -1;
            $patientsAhead = 0;
            $estWaitMin   = 0;
        } else {
            // Estimated wait = patients ahead × avg mins per consultation
            $estWaitMin = $patientsAhead * $avgMins;
        }

        $result[] = [
            'appointment_id'   => (int)$appt['appointment_id'],
            'doctor_id'        => $docId,
            'doctor_name'      => $appt['doctor_name'],
            'room'             => $appt['room_number'],
            'shift'            => $shift,
            'date'             => $date,
            'my_token'         => $myToken,
            'serving_token'    => $servingToken,
            'queue_position'   => $queuePos,
            'patients_ahead'   => $patientsAhead,
            'total_active'     => $totalActive,
            'estimated_wait_min' => $estWaitMin,
            'my_status'        => $myStatus ?: $myQueueStatus,
        ];
    }

    echo json_encode(['status' => 'success', 'appointments' => $result]);

} catch (Throwable $e) {
    error_log('opd_live_sync error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
exit();

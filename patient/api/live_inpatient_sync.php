<?php
/**
 * MedPulse Enterprise HMS — Patient Inpatient & Bed Hold Live Sync Endpoint
 *
 * Scoped to the authenticated patient.
 * Returns:
 * 1. state: 'admitted' | 'held' | 'none'
 * 2. admission: Official Admitted Room details
 * 3. hold: Active 45-minute hold details
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
require_once __DIR__ . '/../../controllers/BedReservationController.php';

// Authentication Check: User must be logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'state'   => 'none',
        'message' => 'Unauthorized: Patient session required.'
    ]);
    exit;
}

$patientUserId = (int)$_SESSION['user_id'];

try {
    // 1. Sweep expired holds
    BedReservationController::releaseExpiredHolds($pdo);

    // 2. Check for an Active Inpatient Admission
    $admStmt = $pdo->prepare("
        SELECT a.admission_id, a.admission_number, a.reservation_id, a.hospital_id, a.bed_id,
               a.patient_id, a.patient_uid, a.guardian_name, a.guardian_phone,
               a.admitting_staff_id, a.attending_doctor_id, a.admission_reason, a.primary_diagnosis,
               a.triage_acuity, a.daily_rate, a.deposit_amount, a.payment_method, a.status,
               a.admitted_at,
               b.bed_number, b.ward_type, b.floor_number,
               h.name AS hospital_name, h.location AS hospital_location, h.code AS hospital_code,
               doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty,
               stf_u.full_name AS staff_name, COALESCE(stf.role_title, 'Admission Desk Officer') AS staff_role
        FROM admissions a
        JOIN hospital_beds b ON a.bed_id = b.bed_id
        JOIN hospitals h ON a.hospital_id = h.hospital_id
        LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
        LEFT JOIN staff stf ON a.admitting_staff_id = stf.staff_id
        LEFT JOIN users stf_u ON stf.user_id = stf_u.user_id
        WHERE a.patient_id = :pid
          AND a.status = 'Admitted'
        ORDER BY a.admitted_at DESC
        LIMIT 1
    ");
    $admStmt->execute([':pid' => $patientUserId]);
    $rawAdm = $admStmt->fetch(PDO::FETCH_ASSOC);

    $admissionData = null;
    if ($rawAdm) {
        $rawDocName = trim((string)($rawAdm['doctor_name'] ?? ''));
        if (!empty($rawDocName)) {
            $docTitle = (stripos($rawDocName, 'Dr.') === 0 || stripos($rawDocName, 'Dr ') === 0) ? $rawDocName : ('Dr. ' . $rawDocName);
        } else {
            $docTitle = 'Assigned Ward Specialist';
        }
        if (!empty($rawAdm['doctor_specialty'])) {
            $docTitle .= ' (' . $rawAdm['doctor_specialty'] . ')';
        }

        $formattedTime = date('M j, Y', strtotime($rawAdm['admitted_at'])) . ' at ' . date('g:i A', strtotime($rawAdm['admitted_at']));

        $admissionData = [
            'admission_id'         => (int)$rawAdm['admission_id'],
            'admission_number'     => $rawAdm['admission_number'],
            'bed_id'               => (int)$rawAdm['bed_id'],
            'bed_number'           => $rawAdm['bed_number'],
            'ward_type'            => $rawAdm['ward_type'],
            'floor_number'         => (int)$rawAdm['floor_number'],
            'room_and_bed'         => "{$rawAdm['ward_type']} - Bed #{$rawAdm['bed_number']}",
            'facility_name'        => $rawAdm['hospital_name'],
            'facility_location'    => $rawAdm['hospital_location'],
            'facility_code'        => $rawAdm['hospital_code'],
            'attending_consultant' => $docTitle,
            'doctor_name'          => $rawAdm['doctor_name'],
            'doctor_specialty'     => $rawAdm['doctor_specialty'],
            'admitting_staff'      => $rawAdm['staff_name'] ?? 'Admission Desk Officer',
            'staff_role'           => $rawAdm['staff_role'] ?? 'Admission Desk Officer',
            'admitted_at'          => $rawAdm['admitted_at'],
            'formatted_time'       => $formattedTime,
            'primary_diagnosis'    => $rawAdm['primary_diagnosis'] ?: ($rawAdm['admission_reason'] ?: 'Inpatient Clinical Care'),
            'triage_acuity'        => $rawAdm['triage_acuity'] ?: 'Routine',
            'daily_rate'           => (float)$rawAdm['daily_rate'],
            'care_status_text'     => 'Admitted / Under Care',
            'status'               => 'Admitted'
        ];
    }

    // 3. Check for Active Bed Hold
    $rawHold = BedReservationController::getPatientActiveReservation($pdo, $patientUserId);
    $holdData = null;
    if ($rawHold) {
        $holdData = [
            'reservation_id'      => (int)$rawHold['reservation_id'],
            'bed_id'              => (int)$rawHold['bed_id'],
            'bed_number'          => $rawHold['bed_number'],
            'ward_type'           => $rawHold['ward_type'],
            'floor_number'        => (int)$rawHold['floor_number'],
            'daily_rate'          => (float)$rawHold['daily_rate'],
            'hospital_name'       => $rawHold['hospital_name'],
            'hospital_location'   => $rawHold['hospital_location'],
            'seconds_remaining'   => max(0, (int)$rawHold['seconds_remaining']),
            'countdown_formatted' => $rawHold['countdown_formatted'] ?? '45:00'
        ];
    }

    // 4. Check if Patient was Discharged (if no current active admission)
    $isDischarged = false;
    if (!$admissionData) {
        $lastAdmStmt = $pdo->prepare("
            SELECT admission_id, admission_number, status, discharged_at
            FROM admissions
            WHERE patient_id = :pid
            ORDER BY admission_id DESC
            LIMIT 1
        ");
        $lastAdmStmt->execute([':pid' => $patientUserId]);
        $lastAdm = $lastAdmStmt->fetch(PDO::FETCH_ASSOC);
        if ($lastAdm && strcasecmp((string)$lastAdm['status'], 'Discharged') === 0) {
            $isDischarged = true;
        }
    }

    // 5. Resolve Current State
    $state = 'none';
    if ($admissionData) {
        $state = 'admitted';
    } elseif ($holdData) {
        $state = 'held';
    } elseif ($isDischarged) {
        $state = 'discharged';
    }

    echo json_encode([
        'success'       => true,
        'state'         => $state,
        'status'        => $admissionData ? 'admitted' : ($isDischarged ? 'discharged' : 'none'),
        'admission'     => $admissionData,
        'hold'          => $holdData,
        'is_discharged' => $isDischarged,
        'timestamp'     => time()
    ]);
    exit;

} catch (Throwable $e) {
    error_log("Patient Inpatient Sync Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'state'   => 'none',
        'message' => 'Internal server error while syncing inpatient status.'
    ]);
    exit;
}

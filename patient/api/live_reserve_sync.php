<?php
/**
 * MedPulse Patient Portal — Live Bed Matrix & Telemetry Sync Endpoint
 * 
 * Provides real-time polling updates for:
 * 1. Multi-Hospital Network Matrix (Total, Occupied, Available, Occupancy Rate %)
 * 2. Ward Vacancy & Category Filter Counters for Selected Hospital
 * 3. Individual Bed Status (Available, Reserved/Held, Occupied, Sanitizing)
 * 4. Active Patient Pre-Reservation Hold or Official Inpatient Admission state
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
require_once __DIR__ . '/../../includes/doctor_helpers.php';

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
$selectedHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 1;
if ($selectedHospitalId <= 0) $selectedHospitalId = 1;

// Helper: Ward Type Category Classifier
if (!function_exists('categorizeWardType')) {
    function categorizeWardType(string $wardType): string {
        $wt = strtolower($wardType);
        if (str_contains($wt, 'icu') || str_contains($wt, 'ccu') || str_contains($wt, 'hdu') || str_contains($wt, 'nicu') || str_contains($wt, 'intensive') || str_contains($wt, 'critical')) {
            return 'ccu_icu';
        }
        if (str_contains($wt, 'cabin') || str_contains($wt, 'deluxe') || str_contains($wt, 'vip') || str_contains($wt, 'suite')) {
            return 'cabin_deluxe';
        }
        return 'general';
    }
}

try {
    // 1. Maintenance sweep for expired holds
    BedReservationController::releaseExpiredHolds($pdo);

    // 2. Fetch Network Matrix for all hospitals
    $networkMatrix = BedReservationController::getNetworkBedMatrix($pdo);

    // 3. Check for Active Hold for authenticated patient
    $activeHold = BedReservationController::getPatientActiveReservation($pdo, $patientUserId);

    // 4. Check for Active Inpatient Admission for authenticated patient
    $admStmt = $pdo->prepare("
        SELECT a.admission_id, a.admission_number, a.bed_id, a.hospital_id,
               a.admitted_at, a.primary_diagnosis, a.admission_reason, a.triage_acuity, a.daily_rate,
               b.bed_number, b.ward_type, b.floor_number,
               h.name AS hospital_name, h.location AS hospital_location,
               doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty
        FROM admissions a
        JOIN hospital_beds b ON a.bed_id = b.bed_id
        JOIN hospitals h ON a.hospital_id = h.hospital_id
        LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
        WHERE a.patient_id = :pid AND a.status = 'Admitted'
        ORDER BY a.admitted_at DESC
        LIMIT 1
    ");
    $admStmt->execute([':pid' => $patientUserId]);
    $rawAdm = $admStmt->fetch(PDO::FETCH_ASSOC);

    $activeAdmission = null;
    if ($rawAdm) {
        $cleanDoc = cleanDoctorBaseName($rawAdm['doctor_name'] ?? '');
        $docTitle = ($cleanDoc !== '' && $cleanDoc !== 'Physician') ? ('Dr. ' . $cleanDoc) : 'Assigned Ward Specialist';
        if (!empty($rawAdm['doctor_specialty'])) {
            $docTitle .= ' (' . $rawAdm['doctor_specialty'] . ')';
        }

        $formattedTime = date('M j, Y \a\t g:i A', strtotime($rawAdm['admitted_at']));

        $activeAdmission = [
            'admission_id'         => (int)$rawAdm['admission_id'],
            'admission_number'     => $rawAdm['admission_number'],
            'bed_id'               => (int)$rawAdm['bed_id'],
            'bed_number'           => $rawAdm['bed_number'],
            'ward_type'            => $rawAdm['ward_type'],
            'floor_number'         => (int)$rawAdm['floor_number'],
            'facility_name'        => $rawAdm['hospital_name'],
            'facility_location'    => $rawAdm['hospital_location'],
            'attending_consultant' => $docTitle,
            'primary_diagnosis'    => $rawAdm['primary_diagnosis'] ?: ($rawAdm['admission_reason'] ?: 'Inpatient Clinical Care'),
            'triage_acuity'        => $rawAdm['triage_acuity'] ?: 'Routine',
            'daily_rate'           => (float)$rawAdm['daily_rate'],
            'admitted_at'          => $rawAdm['admitted_at'],
            'formatted_time'       => $formattedTime,
            'status'               => 'Admitted'
        ];
    }

    // Resolve patient state
    $state = 'none';
    if ($activeAdmission) {
        $state = 'admitted';
    } elseif ($activeHold) {
        $state = 'held';
    }

    // 5. Fetch beds for selected hospital with live statuses
    $bedsStmt = $pdo->prepare("
        SELECT b.id, b.bed_number, b.ward_type, b.floor_number,
               LOWER(COALESCE(hb.status, b.status)) AS raw_status,
               COALESCE(b.daily_rate, b.price_per_day, 0.00) AS daily_rate,
               h.name AS hospital_name, h.location AS hospital_location,
               r.id AS active_reservation_id, r.patient_id AS hold_patient_id
        FROM beds b
        LEFT JOIN hospital_beds hb ON b.id = hb.bed_id
        JOIN hospitals h ON b.hospital_id = h.hospital_id
        LEFT JOIN bed_reservations r ON (r.bed_id = b.id OR r.bed_id = hb.bed_id) AND r.status IN ('held', 'active_hold') AND r.hold_expires_at > NOW()
        WHERE b.hospital_id = :hid
        ORDER BY b.ward_type ASC, b.bed_number ASC
    ");
    $bedsStmt->execute([':hid' => $selectedHospitalId]);
    $rawBeds = $bedsStmt->fetchAll(PDO::FETCH_ASSOC);

    $catCounts = [
        'all'          => 0,
        'ccu_icu'      => 0,
        'general'      => 0,
        'cabin_deluxe' => 0,
    ];

    $bedsData = [];
    foreach ($rawBeds as $row) {
        $isPatientHold = false;
        if (!empty($row['active_reservation_id'])) {
            $effStatus = 'reserved';
            if ((int)$row['hold_patient_id'] === $patientUserId) {
                $isPatientHold = true;
            }
        } elseif ($row['raw_status'] === 'reserved') {
            $effStatus = 'reserved';
        } elseif ($row['raw_status'] === 'occupied') {
            $effStatus = 'occupied';
        } elseif (in_array($row['raw_status'], ['maintenance', 'sanitizing'], true)) {
            $effStatus = $row['raw_status'];
        } else {
            $effStatus = 'available';
        }

        $cat = categorizeWardType($row['ward_type'] ?? '');
        if ($effStatus === 'available') {
            $catCounts['all']++;
            if (isset($catCounts[$cat])) {
                $catCounts[$cat]++;
            }
        }

        $bedsData[] = [
            'id'                => (int)$row['id'],
            'bed_number'        => $row['bed_number'],
            'ward_type'         => $row['ward_type'],
            'floor_number'      => (int)$row['floor_number'],
            'daily_rate'        => (float)$row['daily_rate'],
            'status'            => $effStatus,
            'category'          => $cat,
            'is_patient_hold'   => $isPatientHold,
            'hospital_name'     => $row['hospital_name'],
            'hospital_location' => $row['hospital_location']
        ];
    }

    echo json_encode([
        'success'              => true,
        'timestamp'            => time(),
        'state'                => $state,
        'hold'                 => $activeHold,
        'admission'            => $activeAdmission,
        'network_matrix'       => $networkMatrix,
        'selected_hospital_id' => $selectedHospitalId,
        'vacant_count'         => $catCounts['all'],
        'cat_counts'           => $catCounts,
        'beds'                 => $bedsData
    ]);
    exit;

} catch (Throwable $e) {
    error_log("Patient Live Reserve Sync Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'state'   => 'none',
        'message' => 'Internal server error syncing bed reserve telemetry.'
    ]);
    exit;
}

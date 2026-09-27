<?php
/**
 * MedPulse Enterprise HMS — Staff Live Sync API Endpoint
 *
 * Responsibilities:
 * 1. Auto-expiry handler:
 *    - Auto-marks expired holds: UPDATE bed_reservations SET status = 'expired' WHERE status = 'active_hold' AND expires_at < NOW()
 *    - Reopens expired beds: UPDATE beds SET status = 'available' WHERE id IN (SELECT bed_id FROM bed_reservations WHERE status = 'expired')
 * 2. Return incoming_holds and inpatients payload (scoped to current facility)
 * 3. Handle atomic admission submission transaction
 * 4. Handle bed hold release action
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/session_guard.php';
require_once __DIR__ . '/../../includes/doctor_helpers.php';
if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/db.php';
}

// Authentication Check: Staff, Nurse, Receptionist, or Admin required
$role = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['staff', 'admin', 'super_admin', 'nurse', 'receptionist'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Active Staff session required.']);
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$sessionHospitalId = (int)($_SESSION['branch_id'] ?? $_SESSION['hospital_id'] ?? 1);

// Resolve Staff Profile
try {
    $staffStmt = $pdo->prepare("
        SELECT s.staff_id, s.hospital_id, s.department, s.role_title, u.full_name, u.email,
               h.name AS hospital_name, h.location AS hospital_location, h.code AS hospital_code
        FROM users u
        LEFT JOIN staff s ON s.user_id = u.user_id
        LEFT JOIN hospitals h ON h.hospital_id = COALESCE(s.hospital_id, u.hospital_id, :sess_hosp, 1)
        WHERE u.user_id = :uid
        LIMIT 1
    ");
    $staffStmt->execute([':uid' => $currentUserId, ':sess_hosp' => $sessionHospitalId]);
    $staffProfile = $staffStmt->fetch(PDO::FETCH_ASSOC);

    $staffId           = (int)($_SESSION['staff_id'] ?? $staffProfile['staff_id'] ?? 0);
    if ($staffId <= 0 && !empty($staffProfile['staff_id'])) {
        $staffId = (int)$staffProfile['staff_id'];
    }
    $staffHospitalId   = (int)($staffProfile['hospital_id'] ?? $sessionHospitalId);
    $staffName         = $staffProfile['full_name'] ?? $_SESSION['full_name'] ?? 'Staff Officer';
    $staffDesignation  = $staffProfile['role_title'] ?? 'Senior Triage Officer / Admission Clerk';
    if ($staffDesignation === 'Staff' || empty($staffDesignation)) {
        $staffDesignation = 'Senior Triage Officer / Admission Clerk';
    }
    $staffHospitalName = $staffProfile['hospital_name'] ?? 'MedPulse Central Hospital';

    $_SESSION['staff_id']    = $staffId;
    $_SESSION['branch_id']   = $staffHospitalId;
    $_SESSION['hospital_id'] = $staffHospitalId;

} catch (Throwable $e) {
    error_log("Staff Profile Error: " . $e->getMessage());
    $staffId           = (int)($_SESSION['staff_id'] ?? 1);
    $staffHospitalId   = $sessionHospitalId;
    $staffName         = $_SESSION['full_name'] ?? 'Staff Officer';
    $staffDesignation  = 'Senior Triage Officer / Admission Clerk';
    $staffHospitalName = 'MedPulse Central Hospital';

    $_SESSION['staff_id']    = $staffId;
    $_SESSION['branch_id']   = $staffHospitalId;
    $_SESSION['hospital_id'] = $staffHospitalId;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? 'sync');

// ── 1. ACTION: Process Inpatient Admission (Atomic Transaction) ───────────────
if ($action === 'admit' || $action === 'process_admission') {
    // CSRF Check
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF security token mismatch. Please refresh.']);
        exit;
    }

    $reservationId     = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $bedId             = (int)($_POST['bed_id'] ?? 0);
    $patientId         = (int)($_POST['patient_id'] ?? 0);
    $attendingDoctorId = (int)($_POST['attending_doctor_id'] ?? 0);
    $guardianName      = trim($_POST['guardian_name'] ?? '');
    $guardianRelation  = trim($_POST['guardian_relation'] ?? 'Next of Kin');
    $guardianPhone     = trim($_POST['guardian_phone'] ?? '');
    $admissionReason   = trim($_POST['admission_reason'] ?? 'Inpatient Clinical Care');
    $primaryDiagnosis  = trim($_POST['primary_diagnosis'] ?? 'Acute Care Intake');
    $triageAcuity      = in_array($_POST['triage_acuity'] ?? '', ['Routine', 'Critical', 'Post-Op'], true) ? $_POST['triage_acuity'] : 'Routine';
    $dailyRate         = max(0.0, (float)($_POST['daily_rate'] ?? 0.0));
    $depositAmount     = max(0.0, (float)($_POST['deposit_amount'] ?? 0.0));
    $paymentMethod     = in_array($_POST['payment_method'] ?? '', ['Cash', 'Card', 'MFS'], true) ? $_POST['payment_method'] : 'Cash';
    $paymentRef        = trim($_POST['payment_reference'] ?? '');

    if ($bedId <= 0 || $patientId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid Bed ID or Patient ID for admission.']);
        exit;
    }

    if ($attendingDoctorId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please select an Attending Consultant from the registry.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock & Verify Bed
        $bedStmt = $pdo->prepare("
            SELECT bed_id, hospital_id, bed_number, ward_type, floor_number, daily_rate, price_per_day, status 
            FROM hospital_beds 
            WHERE bed_id = :bid 
            FOR UPDATE
        ");
        $bedStmt->execute([':bid' => $bedId]);
        $bed = $bedStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bed) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'The selected bed does not exist in the hospital registry.']);
            exit;
        }

        if ($dailyRate <= 0.0) {
            $dailyRate = (float)(!empty($bed['daily_rate']) ? $bed['daily_rate'] : (!empty($bed['price_per_day']) ? $bed['price_per_day'] : 1500.0));
        }

        // Lock & Verify Patient
        $patStmt = $pdo->prepare("
            SELECT u.user_id, u.full_name, u.phone, u.gender, p.patient_uid 
            FROM users u
            LEFT JOIN patients p ON (p.user_id = u.user_id OR p.id = u.user_id)
            WHERE u.user_id = :uid
            FOR UPDATE
        ");
        $patStmt->execute([':uid' => $patientId]);
        $patient = $patStmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Patient record could not be found.']);
            exit;
        }

        $patientUid = !empty($patient['patient_uid']) ? $patient['patient_uid'] : ('MP-P' . str_pad((string)$patientId, 5, '0', STR_PAD_LEFT));

        // 1. Update bed_reservations: status = 'admitted', admitted_at = NOW(), admitted_by = staff_id
        if ($reservationId && $reservationId > 0) {
            $updRes = $pdo->prepare("
                UPDATE bed_reservations 
                SET status = 'admitted', 
                    admitted_at = NOW(), 
                    admitted_by = :staff_id,
                    admitted_by_staff_id = :staff_id2 
                WHERE id = :res_id
            ");
            $updRes->execute([
                ':staff_id'  => $staffId,
                ':staff_id2' => $staffId,
                ':res_id'    => $reservationId
            ]);
        } else {
            $updRes = $pdo->prepare("
                UPDATE bed_reservations 
                SET status = 'admitted', 
                    admitted_at = NOW(), 
                    admitted_by = :staff_id,
                    admitted_by_staff_id = :staff_id2 
                WHERE bed_id = :bid AND patient_id = :pid AND status IN ('held', 'active_hold')
            ");
            $updRes->execute([
                ':staff_id'  => $staffId,
                ':staff_id2' => $staffId,
                ':bid'       => $bedId,
                ':pid'       => $patientId
            ]);
        }

        // 2. Update beds: status = 'occupied' (and hospital_beds table)
        $updHb = $pdo->prepare("
            UPDATE hospital_beds 
            SET status = 'Occupied',
                patient_id = :pid,
                reserved_until = NULL,
                reservation_user_id = NULL,
                reservation_token = NULL,
                updated_at = NOW()
            WHERE bed_id = :bid
        ");
        $updHb->execute([':pid' => $patientId, ':bid' => $bedId]);

        // Also sync `beds` view/table if present
        try {
            $updBeds = $pdo->prepare("UPDATE beds SET status = 'Occupied', patient_id = :pid WHERE id = :bid OR bed_id = :bid2");
            $updBeds->execute([':pid' => $patientId, ':bid' => $bedId, ':bid2' => $bedId]);
        } catch (Throwable $e) {
            // View update handled by underlying hospital_beds
        }

        // 3. Generate unique admission dossier number
        $admNumber = 'ADM-' . date('Ymd') . '-' . str_pad((string)mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);

        // 4. Insert record into `admissions` table
        $insAdm = $pdo->prepare("
            INSERT INTO admissions (
                admission_number, reservation_id, hospital_id, bed_id, patient_id, patient_uid,
                guardian_name, guardian_relation, guardian_phone,
                admitting_staff_id, attending_doctor_id, admission_reason, primary_diagnosis,
                triage_acuity, daily_rate, deposit_amount, payment_method, payment_reference,
                status, admitted_at, created_at
            ) VALUES (
                :adm_num, :res_id, :hosp_id, :bed_id, :pat_id, :pat_uid,
                :g_name, :g_rel, :g_phone,
                :staff_id, :doc_id, :reason, :diag,
                :acuity, :rate, :deposit, :pay_method, :pay_ref,
                'Admitted', NOW(), NOW()
            )
        ");
        $insAdm->execute([
            ':adm_num'    => $admNumber,
            ':res_id'     => $reservationId,
            ':hosp_id'    => $staffHospitalId,
            ':bed_id'     => $bedId,
            ':pat_id'     => $patientId,
            ':pat_uid'    => $patientUid,
            ':g_name'     => $guardianName,
            ':g_rel'      => $guardianRelation,
            ':g_phone'    => $guardianPhone,
            ':staff_id'   => $staffId,
            ':doc_id'     => $attendingDoctorId,
            ':reason'     => $admissionReason,
            ':diag'       => $primaryDiagnosis,
            ':acuity'     => $triageAcuity,
            ':rate'       => $dailyRate,
            ':deposit'    => $depositAmount,
            ':pay_method' => $paymentMethod,
            ':pay_ref'    => $paymentRef
        ]);

        // 5. Sync clinical bed_allocations table
        try {
            $allocCheck = $pdo->prepare("SELECT allocation_id FROM bed_allocations WHERE bed_id = :bid AND patient_id = :pid AND status = 'Active' LIMIT 1");
            $allocCheck->execute([':bid' => $bedId, ':pid' => $patientId]);
            $existingAlloc = $allocCheck->fetchColumn();

            if (!$existingAlloc) {
                $closeOld = $pdo->prepare("UPDATE bed_allocations SET status = 'Transferred', discharged_at = NOW() WHERE patient_id = :pid AND status = 'Active'");
                $closeOld->execute([':pid' => $patientId]);

                $insAlloc = $pdo->prepare("
                    INSERT INTO bed_allocations (bed_id, patient_id, attending_doctor_id, admitted_at, status, created_at)
                    VALUES (:bid, :pid, :doc_id, NOW(), 'Active', NOW())
                ");
                $insAlloc->execute([
                    ':bid'    => $bedId,
                    ':pid'    => $patientId,
                    ':doc_id' => $attendingDoctorId
                ]);
            }
        } catch (Throwable $e) {
            error_log("bed_allocations sync warning: " . $e->getMessage());
        }

        $pdo->commit();

        echo json_encode([
            'success'          => true,
            'message'          => "Inpatient admission confirmed successfully for {$patient['full_name']} (Bed {$bed['bed_number']}).",
            'admission_number' => $admNumber,
            'patient_name'     => $patient['full_name'],
            'bed_number'       => $bed['bed_number'],
            'ward_type'        => $bed['ward_type']
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Admission error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal database error during admission: ' . $e->getMessage()]);
        exit;
    }
}

// ── 2. ACTION: Release / Cancel Hold ─────────────────────────────────────────
if ($action === 'release_hold' || $action === 'cancel_hold') {
    $resId = (int)($_POST['reservation_id'] ?? 0);
    $bedId = (int)($_POST['bed_id'] ?? 0);

    if ($resId <= 0 && $bedId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid reservation ID or Bed ID is required.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($resId > 0) {
            $updRes = $pdo->prepare("UPDATE bed_reservations SET status = 'cancelled' WHERE id = :rid");
            $updRes->execute([':rid' => $resId]);
        }

        if ($bedId > 0) {
            $updBed = $pdo->prepare("
                UPDATE hospital_beds 
                SET status = 'Available', 
                    patient_id = NULL, 
                    reserved_until = NULL, 
                    reservation_user_id = NULL, 
                    reservation_token = NULL 
                WHERE bed_id = :bid
            ");
            $updBed->execute([':bid' => $bedId]);

            try {
                $pdo->prepare("UPDATE beds SET status = 'available', patient_id = NULL WHERE id = :bid OR bed_id = :bid2")
                    ->execute([':bid' => $bedId, ':bid2' => $bedId]);
            } catch (Throwable $e) {}
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Bed reservation hold released back to vacancy.']);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to release hold: ' . $e->getMessage()]);
        exit;
    }
}

// ── 3. DEFAULT: Live Polling Sync Endpoint ───────────────────────────────────

// Step 3a. Auto-Expiry Handler
try {
    // 1. Auto-mark expired holds
    $pdo->exec("
        UPDATE bed_reservations 
        SET status = 'expired' 
        WHERE (status = 'active_hold' OR status = 'held') 
          AND COALESCE(expires_at, hold_expires_at) < NOW()
    ");

    // 2. Reopen expired beds
    $pdo->exec("
        UPDATE hospital_beds 
        SET status = 'Available', 
            patient_id = NULL, 
            reserved_until = NULL, 
            reservation_user_id = NULL, 
            reservation_token = NULL 
        WHERE bed_id IN (
            SELECT bed_id FROM bed_reservations WHERE status = 'expired'
        ) AND status = 'Reserved'
    ");

    try {
        $pdo->exec("
            UPDATE beds 
            SET status = 'available' 
            WHERE id IN (SELECT bed_id FROM bed_reservations WHERE status = 'expired')
              AND LOWER(status) = 'reserved'
        ");
    } catch (Throwable $e) {}

} catch (Throwable $e) {
    error_log("Auto-expiry daemon error: " . $e->getMessage());
}

// Step 3b. Fetch Incoming Bed Holds
$incomingHolds = [];
try {
    $holdsStmt = $pdo->prepare("
        SELECT r.id AS reservation_id, r.hospital_id, r.bed_id, r.patient_id, 
               COALESCE(r.expires_at, r.hold_expires_at) AS expires_at,
               r.status, r.created_at,
               b.bed_number, b.ward_type, b.floor_number, 
               COALESCE(b.daily_rate, b.price_per_day, 1500.00) AS daily_rate,
               u.full_name AS patient_name, u.phone AS patient_phone, u.gender,
               COALESCE(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), u.age, 0) AS age,
               COALESCE(p.patient_uid, CONCAT('MP-P', LPAD(u.user_id, 5, '0'))) AS patient_uid,
               COALESCE(p.blood_group, u.blood_group, 'Unknown') AS blood_group,
               TIMESTAMPDIFF(SECOND, NOW(), COALESCE(r.expires_at, r.hold_expires_at)) AS seconds_remaining
        FROM bed_reservations r
        JOIN hospital_beds b ON r.bed_id = b.bed_id
        JOIN users u ON u.user_id = r.patient_id
        LEFT JOIN patients p ON (p.user_id = r.patient_id OR p.id = r.patient_id)
        WHERE r.hospital_id = :hosp_id
          AND (r.status = 'active_hold' OR r.status = 'held')
          AND COALESCE(r.expires_at, r.hold_expires_at) > NOW()
        ORDER BY r.created_at DESC
    ");
    $holdsStmt->execute([':hosp_id' => $staffHospitalId]);
    $rawHolds = $holdsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawHolds as $row) {
        $secs = max(0, (int)$row['seconds_remaining']);
        $incomingHolds[] = [
            'reservation_id'    => (int)$row['reservation_id'],
            'bed_id'            => (int)$row['bed_id'],
            'patient_id'        => (int)$row['patient_id'],
            'patient_name'      => $row['patient_name'] ?? 'Unknown Patient',
            'uhid'              => $row['patient_uid'],
            'phone'             => $row['patient_phone'] ?? 'N/A',
            'gender'            => $row['gender'] ?? 'N/A',
            'age'               => (int)$row['age'],
            'blood_group'       => $row['blood_group'],
            'bed_number'        => $row['bed_number'],
            'ward_type'         => $row['ward_type'],
            'floor_number'      => (int)$row['floor_number'],
            'daily_rate'        => (float)$row['daily_rate'],
            'expires_at'        => $row['expires_at'],
            'seconds_remaining' => $secs
        ];
    }
} catch (Throwable $e) {
    error_log("Fetch holds error: " . $e->getMessage());
    $incomingHolds = [];
}

// Step 3c. Fetch Inpatient Registry
$inpatients = [];
try {
    $admissionsStmt = $pdo->prepare("
        SELECT a.admission_id, a.admission_number, a.reservation_id, a.hospital_id, a.bed_id,
               a.patient_id, a.patient_uid, a.guardian_name, a.guardian_phone,
               a.admitting_staff_id, a.attending_doctor_id, a.admission_reason, a.primary_diagnosis,
               a.triage_acuity, a.daily_rate, a.deposit_amount, a.payment_method, a.status,
               a.admitted_at,
               b.bed_number, b.ward_type, b.floor_number,
               u.full_name AS patient_name, u.phone AS patient_phone, u.gender,
               COALESCE(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), u.age, 0) AS age,
               COALESCE(p.blood_group, u.blood_group, 'Unknown') AS blood_group,
               doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty,
               stf_u.full_name AS staff_name, COALESCE(stf.role_title, 'Senior Triage Officer') AS staff_role
        FROM admissions a
        JOIN hospital_beds b ON a.bed_id = b.bed_id
        JOIN users u ON a.patient_id = u.user_id
        LEFT JOIN patients p ON (p.user_id = u.user_id OR p.id = u.user_id)
        LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
        LEFT JOIN staff stf ON a.admitting_staff_id = stf.staff_id
        LEFT JOIN users stf_u ON stf.user_id = stf_u.user_id
        WHERE a.hospital_id = :hosp_id
          AND a.status = 'Admitted'
        ORDER BY a.admitted_at DESC
        LIMIT 50
    ");
    $admissionsStmt->execute([':hosp_id' => $staffHospitalId]);
    $rawAdmissions = $admissionsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawAdmissions as $row) {
        $docTitle = !empty($row['doctor_name']) ? ('Dr. ' . $row['doctor_name']) : 'Assigned Duty Physician';
        if (!empty($row['doctor_specialty'])) {
            $docTitle .= ' (' . $row['doctor_specialty'] . ')';
        }

        $inpatients[] = [
            'admission_id'             => (int)$row['admission_id'],
            'admission_number'         => $row['admission_number'],
            'bed_id'                   => (int)$row['bed_id'],
            'bed_number'               => $row['bed_number'],
            'ward_type'                => $row['ward_type'],
            'floor_number'             => (int)$row['floor_number'],
            'patient_id'               => (int)$row['patient_id'],
            'patient_name'             => $row['patient_name'] ?? 'Inpatient',
            'uhid'                     => $row['patient_uid'] ?: ('MP-P' . str_pad((string)$row['patient_id'], 5, '0', STR_PAD_LEFT)),
            'patient_phone'            => $row['patient_phone'] ?? 'N/A',
            'patient_gender'           => $row['gender'] ?? 'N/A',
            'patient_age'              => (int)$row['age'],
            'attending_consultant'     => $docTitle,
            'doctor_name'              => $row['doctor_name'] ?? '',
            'doctor_specialty'         => $row['doctor_specialty'] ?? '',
            'staff_name'               => $row['staff_name'] ?? $staffName,
            'staff_designation'        => $row['staff_role'] ?? $staffDesignation,
            'primary_diagnosis'        => $row['primary_diagnosis'] ?: ($row['admission_reason'] ?: 'Clinical Inpatient Care'),
            'triage_acuity'            => $row['triage_acuity'] ?: 'Routine',
            'daily_rate'               => (float)$row['daily_rate'],
            'deposit_amount'           => (float)$row['deposit_amount'],
            'payment_method'           => $row['payment_method'],
            'admitted_at'              => $row['admitted_at'],
            'formatted_admission_time' => date('M j, Y', strtotime($row['admitted_at'])) . ' &bull; ' . date('g:i A', strtotime($row['admitted_at']))
        ];
    }
} catch (Throwable $e) {
    error_log("Fetch admissions error: " . $e->getMessage());
    $inpatients = [];
}

// Step 3d. Active Doctors for Modal Dropdown
$doctors = [];
try {
    $docStmt = $pdo->query("
        SELECT u.user_id AS id, u.full_name AS name, COALESCE(dp.specialty, u.department, 'General Medicine') AS specialty
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.role = 'Doctor' AND u.status = 'active'
        ORDER BY u.full_name ASC
    ");
    $doctors = $docStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $doctors = [];
}

// Return unified real-time payload
echo json_encode([
    'success'        => true,
    'timestamp'      => time(),
    'incoming_holds' => $incomingHolds,
    'inpatients'     => $inpatients,
    'doctors'        => $doctors,
    'staff'          => [
        'staff_id'    => $staffId,
        'name'        => $staffName,
        'designation' => $staffDesignation,
        'branch'      => $staffHospitalName,
        'hospital_id' => $staffHospitalId
    ]
]);
exit;

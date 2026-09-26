<?php
/**
 * MedPulse Enterprise HMS — Bed Reservation Controller
 * Multi-Hospital Network Live Bed Matrix & 45-Minute Hold Engine
 */

class BedReservationController {

    /**
     * Self-Healing Expiration Daemon / Auto-Release Hook:
     * Sweeps expired holds and releases beds back to 'available' status.
     */
    public static function releaseExpiredHolds(PDO $pdo): int {
        try {
            // Sweep expired holds and release beds
            $stmt = $pdo->prepare("
                UPDATE beds b
                JOIN bed_reservations r ON r.bed_id = b.id
                SET b.status = 'available', r.status = 'expired'
                WHERE r.status = 'held' AND r.hold_expires_at < NOW()
            ");
            $stmt->execute();
            $releasedCount = $stmt->rowCount();

            // Safety sync for underlying hospital_beds table
            $syncStmt = $pdo->prepare("
                UPDATE hospital_beds hb
                JOIN bed_reservations r ON r.bed_id = hb.bed_id
                SET hb.status = 'Available', r.status = 'expired'
                WHERE r.status = 'held' AND r.hold_expires_at < NOW()
            ");
            $syncStmt->execute();

            return $releasedCount;
        } catch (Throwable $e) {
            error_log("Error in BedReservationController::releaseExpiredHolds: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Multi-Hospital Live Matrix Aggregator:
     * Queries all partner facilities joined with aggregate bed statistics.
     */
    public static function getNetworkBedMatrix(PDO $pdo): array {
        try {
            // First run maintenance sweep
            self::releaseExpiredHolds($pdo);

            $stmt = $pdo->query("
                SELECT 
                    h.hospital_id,
                    h.hospital_id AS id,
                    h.name AS hospital_name,
                    h.code AS hospital_code,
                    h.city,
                    h.location,
                    h.emergency_status,
                    COUNT(hb.bed_id) AS total_beds,
                    SUM(CASE WHEN LOWER(hb.status) = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds,
                    SUM(CASE WHEN LOWER(hb.status) = 'available' THEN 1 ELSE 0 END) AS available_beds,
                    SUM(CASE WHEN LOWER(hb.status) = 'reserved' THEN 1 ELSE 0 END) AS reserved_beds,
                    SUM(CASE WHEN LOWER(hb.status) IN ('maintenance', 'sanitizing', 'emergency hold') THEN 1 ELSE 0 END) AS other_beds
                FROM hospitals h
                LEFT JOIN hospital_beds hb ON hb.hospital_id = h.hospital_id
                GROUP BY h.hospital_id, h.name, h.code, h.city, h.location, h.emergency_status
                ORDER BY h.hospital_id ASC;
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Error in getNetworkBedMatrix: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch active hold reservation for a specific patient.
     */
    public static function getPatientActiveReservation(PDO $pdo, int $patientId): ?array {
        try {
            self::releaseExpiredHolds($pdo);

            $stmt = $pdo->prepare("
                SELECT 
                    r.id AS reservation_id,
                    r.hospital_id,
                    r.bed_id,
                    r.patient_id,
                    r.hold_expires_at,
                    r.status,
                    r.created_at,
                    TIMESTAMPDIFF(SECOND, NOW(), r.hold_expires_at) AS seconds_remaining,
                    h.name AS hospital_name,
                    h.location AS hospital_location,
                    h.contact_number,
                    b.bed_number,
                    b.ward_type,
                    b.floor_number,
                    COALESCE(b.daily_rate, b.price_per_day, 0.00) AS daily_rate
                FROM bed_reservations r
                JOIN hospitals h ON r.hospital_id = h.hospital_id
                JOIN beds b ON r.bed_id = b.id
                WHERE r.patient_id = :patient_id 
                  AND r.status = 'held' 
                  AND r.hold_expires_at > NOW()
                ORDER BY r.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([':patient_id' => $patientId]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($res) {
                $secs = max(0, (int)$res['seconds_remaining']);
                $mins = floor($secs / 60);
                $remSecs = $secs % 60;
                $res['countdown_formatted'] = sprintf('%02d:%02d', $mins, $remSecs);
                return $res;
            }

            return null;
        } catch (Throwable $e) {
            error_log("Error in getPatientActiveReservation: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 45-Minute Bed Hold Booking Engine:
     * Atomically locks the bed, enforces 1 active hold per patient, and reserves for 45 minutes.
     */
    public static function reserveBed(PDO $pdo, int $patientId, int $bedId, int $hospitalId): array {
        try {
            $pdo->beginTransaction();

            // 1. Run maintenance sweep
            $sweep = $pdo->prepare("
                UPDATE beds b
                JOIN bed_reservations r ON r.bed_id = b.id
                SET b.status = 'available', r.status = 'expired'
                WHERE r.status = 'held' AND r.hold_expires_at < NOW()
            ");
            $sweep->execute();

            // 2. Check if the patient already has an active hold across the network
            $activeStmt = $pdo->prepare("
                SELECT r.id, r.bed_id, r.hospital_id, r.hold_expires_at, h.name AS hospital_name
                FROM bed_reservations r
                JOIN hospitals h ON r.hospital_id = h.hospital_id
                WHERE r.patient_id = :patient_id 
                  AND r.status = 'held' 
                  AND r.hold_expires_at > NOW()
                LIMIT 1 
                FOR UPDATE
            ");
            $activeStmt->execute([':patient_id' => $patientId]);
            $existingHold = $activeStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingHold) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Active hold exists: You already have a bed on hold at ' . $existingHold['hospital_name'] . '. Only 1 active hold allowed per patient across the network.'
                ];
            }

            // 3. Verify the bed is still 'available'
            $bedStmt = $pdo->prepare("
                SELECT id, bed_number, ward_type, floor_number, daily_rate 
                FROM beds 
                WHERE id = :bed_id AND hospital_id = :hospital_id AND status = 'available' 
                FOR UPDATE
            ");
            $bedStmt->execute([
                ':bed_id'      => $bedId,
                ':hospital_id' => $hospitalId
            ]);
            $bed = $bedStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bed) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'The requested bed is no longer available. It may have just been reserved or occupied.'
                ];
            }

            // 4. Transition the bed status: UPDATE beds SET status = 'reserved' WHERE id = :bed_id
            $updBed = $pdo->prepare("UPDATE beds SET status = 'reserved' WHERE id = :bed_id");
            $updBed->execute([':bed_id' => $bedId]);

            // Sync hospital_beds directly
            $updHb = $pdo->prepare("UPDATE hospital_beds SET status = 'Reserved' WHERE bed_id = :bed_id");
            $updHb->execute([':bed_id' => $bedId]);

            // 5. Insert hold reservation with 45-minute expiration
            $insStmt = $pdo->prepare("
                INSERT INTO bed_reservations (hospital_id, bed_id, patient_id, hold_expires_at, status)
                VALUES (:hospital_id, :bed_id, :patient_id, NOW() + INTERVAL 45 MINUTE, 'held')
            ");
            $insStmt->execute([
                ':hospital_id' => $hospitalId,
                ':bed_id'      => $bedId,
                ':patient_id'  => $patientId
            ]);
            $reservationId = (int)$pdo->lastInsertId();

            $pdo->commit();

            return [
                'success'         => true,
                'reservation_id'  => $reservationId,
                'bed_number'      => $bed['bed_number'],
                'ward_type'       => $bed['ward_type'],
                'hold_duration'   => '45 minutes',
                'message'         => "Bed {$bed['bed_number']} successfully reserved on temporary 45-minute hold."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in reserveBed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'System error while reserving bed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Release/Cancel an active hold before expiration.
     */
    public static function cancelReservation(PDO $pdo, int $patientId, int $reservationId): array {
        try {
            $pdo->beginTransaction();

            $findStmt = $pdo->prepare("
                SELECT id, bed_id, hospital_id 
                FROM bed_reservations 
                WHERE id = :id AND patient_id = :patient_id AND status = 'held' 
                FOR UPDATE
            ");
            $findStmt->execute([
                ':id'         => $reservationId,
                ':patient_id' => $patientId
            ]);
            $res = $findStmt->fetch(PDO::FETCH_ASSOC);

            if (!$res) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Reservation not found or already cancelled/expired.'
                ];
            }

            // Mark reservation cancelled
            $cancelStmt = $pdo->prepare("UPDATE bed_reservations SET status = 'cancelled' WHERE id = :id");
            $cancelStmt->execute([':id' => $reservationId]);

            // Release bed
            $relBed = $pdo->prepare("UPDATE beds SET status = 'available' WHERE id = :bed_id");
            $relBed->execute([':bed_id' => $res['bed_id']]);

            $relHb = $pdo->prepare("UPDATE hospital_beds SET status = 'Available' WHERE bed_id = :bed_id");
            $relHb->execute([':bed_id' => $res['bed_id']]);

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Bed reservation cancelled successfully and returned to network vacancy.'
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in cancelReservation: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to cancel reservation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get available beds for a given hospital with optional ward filtering.
     */
    public static function getHospitalAvailableBeds(PDO $pdo, int $hospitalId, ?string $wardType = null, int $limit = 60): array {
        try {
            self::releaseExpiredHolds($pdo);

            $sql = "
                SELECT 
                    b.id,
                    b.bed_number,
                    b.ward_type,
                    b.floor_number,
                    COALESCE(b.daily_rate, b.price_per_day, 0.00) AS daily_rate,
                    b.status,
                    h.name AS hospital_name,
                    h.location AS hospital_location
                FROM beds b
                JOIN hospitals h ON b.hospital_id = h.hospital_id
                WHERE b.hospital_id = :hospital_id 
                  AND b.status = 'available'
            ";
            $params = [':hospital_id' => $hospitalId];

            if (!empty($wardType) && $wardType !== 'all') {
                $sql .= " AND b.ward_type = :ward_type";
                $params[':ward_type'] = $wardType;
            }

            $sql .= " ORDER BY b.ward_type ASC, b.bed_number ASC LIMIT " . (int)$limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Error in getHospitalAvailableBeds: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get ward types breakdown for a specific hospital.
     */
    public static function getHospitalWardSummary(PDO $pdo, int $hospitalId): array {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    ward_type,
                    COUNT(*) AS total_beds,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_beds,
                    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds,
                    SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved_beds,
                    MIN(COALESCE(daily_rate, price_per_day, 0.00)) AS min_daily_rate
                FROM beds
                WHERE hospital_id = :hospital_id
                GROUP BY ward_type
                ORDER BY ward_type ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Process Full Inpatient Admission Dossier from 45-Minute Hold or Direct Intake.
     * Atomic transaction:
     * 1. bed_reservations: status = 'admitted', admitted_at = NOW(), admitted_by_staff_id = :staff_id
     * 2. admissions: complete medical intake dossier, guardian info, clinical acuity & billing ref
     * 3. hospital_beds & beds: status = 'Occupied', patient_id = :patient_id
     * 4. bed_allocations: status = 'Active', attending_doctor_id = :doctor_id
     * 5. patient_doctor_assignments: link doctor to patient
     * 6. audit_logs: secure compliance trail
     */
    public static function processAdmission(PDO $pdo, array $params): array {
        try {
            $reservationId     = !empty($params['reservation_id']) ? (int)$params['reservation_id'] : null;
            $bedId             = (int)($params['bed_id'] ?? 0);
            $patientId         = (int)($params['patient_id'] ?? 0);
            $hospitalId        = (int)($params['hospital_id'] ?? 0);
            $admittingStaffId  = (int)($params['admitting_staff_id'] ?? 0);
            $staffUserId       = (int)($params['staff_user_id'] ?? 0);
            $attendingDoctorId = (int)($params['attending_doctor_id'] ?? 0);
            $guardianName      = trim($params['guardian_name'] ?? '');
            $guardianRelation  = trim($params['guardian_relation'] ?? 'Next of Kin');
            $guardianPhone     = trim($params['guardian_phone'] ?? '');
            $admissionReason   = trim($params['admission_reason'] ?? 'Inpatient Clinical Care');
            $primaryDiagnosis  = trim($params['primary_diagnosis'] ?? 'Acute Care Intake');
            $triageAcuity      = in_array($params['triage_acuity'] ?? '', ['Routine', 'Critical', 'Post-Op'], true) ? $params['triage_acuity'] : 'Routine';
            $dailyRate         = max(0.0, (float)($params['daily_rate'] ?? 0.0));
            $depositAmount     = max(0.0, (float)($params['deposit_amount'] ?? 0.0));
            $paymentMethod     = in_array($params['payment_method'] ?? '', ['Cash', 'Card', 'MFS'], true) ? $params['payment_method'] : 'Cash';
            $paymentRef        = trim($params['payment_reference'] ?? '');

            if ($bedId <= 0 || $patientId <= 0) {
                return ['success' => false, 'message' => 'Invalid Bed ID or Patient ID for admission.'];
            }

            if ($attendingDoctorId <= 0) {
                return ['success' => false, 'message' => 'Please select an Attending Physician/Consultant from the database.'];
            }

            $pdo->beginTransaction();

            // 1. Resolve & Lock Bed
            $bedStmt = $pdo->prepare("SELECT bed_id, hospital_id, bed_number, ward_type, floor_number, daily_rate, price_per_day, status FROM hospital_beds WHERE bed_id = :bid FOR UPDATE");
            $bedStmt->execute([':bid' => $bedId]);
            $bed = $bedStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bed) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'The selected bed does not exist in the hospital registry.'];
            }

            if ($hospitalId <= 0) {
                $hospitalId = (int)$bed['hospital_id'];
            }

            if ($dailyRate <= 0.0) {
                $dailyRate = (float)(!empty($bed['daily_rate']) ? $bed['daily_rate'] : (!empty($bed['price_per_day']) ? $bed['price_per_day'] : 1500.0));
            }

            // 2. Resolve & Lock Patient
            $patStmt = $pdo->prepare("
                SELECT u.user_id, u.full_name, u.phone, u.gender, p.patient_uid, p.blood_group 
                FROM users u
                LEFT JOIN patients p ON (p.user_id = u.user_id OR p.id = u.user_id)
                WHERE u.user_id = :uid
                FOR UPDATE
            ");
            $patStmt->execute([':uid' => $patientId]);
            $patient = $patStmt->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Patient record could not be found.'];
            }

            $patientUid = !empty($patient['patient_uid']) ? $patient['patient_uid'] : ('MP-P' . str_pad((string)$patientId, 5, '0', STR_PAD_LEFT));

            // 3. Update Bed Reservation if hold was used
            if ($reservationId && $reservationId > 0) {
                $updRes = $pdo->prepare("
                    UPDATE bed_reservations 
                    SET status = 'admitted', 
                        admitted_at = NOW(), 
                        admitted_by_staff_id = :staff_id 
                    WHERE id = :res_id
                ");
                $updRes->execute([
                    ':staff_id' => $admittingStaffId,
                    ':res_id'   => $reservationId
                ]);
            } else {
                // Also close any pending holds for this bed/patient
                $updRes = $pdo->prepare("
                    UPDATE bed_reservations 
                    SET status = 'admitted', 
                        admitted_at = NOW(), 
                        admitted_by_staff_id = :staff_id 
                    WHERE bed_id = :bid AND patient_id = :pid AND status = 'held'
                ");
                $updRes->execute([
                    ':staff_id' => $admittingStaffId,
                    ':bid'      => $bedId,
                    ':pid'      => $patientId
                ]);
            }

            // 4. Generate Unique Admission Number: ADM-YYYYMMDD-XXXX
            $admNumber = 'ADM-' . date('Ymd') . '-' . str_pad((string)mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);

            // 5. Insert Into `admissions` Table
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
                ':hosp_id'    => $hospitalId,
                ':bed_id'     => $bedId,
                ':pat_id'     => $patientId,
                ':pat_uid'    => $patientUid,
                ':g_name'     => $guardianName,
                ':g_rel'      => $guardianRelation,
                ':g_phone'    => $guardianPhone,
                ':staff_id'   => $admittingStaffId,
                ':doc_id'     => $attendingDoctorId,
                ':reason'     => $admissionReason,
                ':diag'       => $primaryDiagnosis,
                ':acuity'     => $triageAcuity,
                ':rate'       => $dailyRate,
                ':deposit'    => $depositAmount,
                ':pay_method' => $paymentMethod,
                ':pay_ref'    => $paymentRef
            ]);
            $admissionId = (int)$pdo->lastInsertId();

            // 6. Update `hospital_beds` -> status = 'Occupied', patient_id = :patient_id
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

            // 7. Sync `bed_allocations` table (clinical rounding invariant)
            $allocCheck = $pdo->prepare("SELECT allocation_id FROM bed_allocations WHERE bed_id = :bid AND patient_id = :pid AND status = 'Active' LIMIT 1");
            $allocCheck->execute([':bid' => $bedId, ':pid' => $patientId]);
            $existingAlloc = $allocCheck->fetchColumn();

            if (!$existingAlloc) {
                // Close any existing active allocation for this patient if any
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
            } else {
                $updAlloc = $pdo->prepare("UPDATE bed_allocations SET attending_doctor_id = :doc_id WHERE allocation_id = :aid");
                $updAlloc->execute([':doc_id' => $attendingDoctorId, ':aid' => $existingAlloc]);
            }

            // 8. Sync `patient_doctor_assignments`
            try {
                $pdaStmt = $pdo->prepare("
                    INSERT INTO patient_doctor_assignments (patient_id, doctor_id, assigned_by, is_primary, status, notes, assigned_at)
                    VALUES (:pid, :doc_id, :by_uid, 1, 'Active', 'Assigned at Inpatient Bed Admission', NOW())
                    ON DUPLICATE KEY UPDATE is_primary = 1, status = 'Active'
                ");
                $pdaStmt->execute([
                    ':pid'    => $patientId,
                    ':doc_id' => $attendingDoctorId,
                    ':by_uid' => $staffUserId ?: null
                ]);
            } catch (Throwable $e) {}

            // 9. Compliance Audit Trail
            try {
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                    VALUES (?, 'staff', 'BED_ADMISSION', ?, 'INPATIENT', 'Process Hospital Admission', ?, ?, 'MEDIUM')
                ");
                $audit->execute([
                    $staffUserId ?: $admittingStaffId,
                    "Inpatient admitted to Bed {$bed['bed_number']} ({$bed['ward_type']}). Admission #{$admNumber}, Acuity: {$triageAcuity}.",
                    "bed_id:{$bedId}:patient_id:{$patientId}",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } catch (Throwable $e) {}

            $pdo->commit();

            return [
                'success'          => true,
                'admission_id'     => $admissionId,
                'admission_number' => $admNumber,
                'bed_number'       => $bed['bed_number'],
                'ward_type'        => $bed['ward_type'],
                'patient_name'     => $patient['full_name'],
                'patient_uid'      => $patientUid,
                'triage_acuity'    => $triageAcuity,
                'message'          => "Patient {$patient['full_name']} ({$patientUid}) successfully admitted to Bed {$bed['bed_number']} ({$bed['ward_type']}). Admission Dossier #{$admNumber} generated."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in processAdmission: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Admission transaction failed: ' . $e->getMessage()
            ];
        }
    }
}

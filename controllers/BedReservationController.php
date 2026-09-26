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
                    h.id AS hospital_id,
                    h.name AS hospital_name,
                    h.location,
                    h.emergency_status,
                    COUNT(b.id) AS total_beds,
                    SUM(CASE WHEN b.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds,
                    SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) AS available_beds,
                    SUM(CASE WHEN b.status = 'reserved' THEN 1 ELSE 0 END) AS reserved_beds
                FROM hospitals h
                LEFT JOIN beds b ON b.hospital_id = h.id
                GROUP BY h.id, h.name, h.location, h.emergency_status
                ORDER BY h.id ASC;
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
            $stmt->execute([':hospital_id' => $hospitalId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

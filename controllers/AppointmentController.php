<?php
/**
 * MedPulse OPD Appointment Controller
 * 
 * Handles:
 * - Sequential token generation capped at 25 per doctor/date/shift
 * - Chamber queue progression (Call Next Patient)
 * - Real-time queue status calculations (current serving, people ahead, estimated wait)
 */

if (date_default_timezone_get() !== 'Asia/Dhaka') {
    date_default_timezone_set('Asia/Dhaka');
}

class AppointmentController {

    /**
     * Book an OPD appointment with sequential token generation (capped at 25 per shift).
     *
     * @param string $symptoms  Patient-reported chief complaint (optional, stored verbatim after sanitisation)
     */
    public static function bookAppointment(PDO $pdo, int $patientId, int $doctorId, string $appointmentDate, string $timeSlot, string $reason = '', string $symptoms = ''): array {
        // Validate date
        $dateTs = strtotime($appointmentDate);
        if (!$dateTs || $appointmentDate < date('Y-m-d')) {
            return [
                'success' => false,
                'message' => 'Invalid appointment date. Appointments cannot be booked for past dates.'
            ];
        }

        // Validate time slot
        $timeSlot = ucfirst(strtolower(trim($timeSlot)));
        if (!in_array($timeSlot, ['Morning', 'Evening'], true)) {
            $timeSlot = 'Morning';
        }

        // Prevent duplicate bookings by the same patient for the same doctor on the same date
        $dupStmt = $pdo->prepare("
            SELECT id, token_number 
            FROM appointments 
            WHERE patient_id = :patient_id 
              AND doctor_id = :doctor_id 
              AND appointment_date = :appointment_date 
              AND status != 'cancelled'
            LIMIT 1
        ");
        $dupStmt->execute([
            ':patient_id'        => $patientId,
            ':doctor_id'         => $doctorId,
            ':appointment_date'  => $appointmentDate
        ]);
        $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return [
                'success' => false,
                'message' => "Duplicate booking: You already have an active appointment (Serial #{$existing['token_number']}) with this specialist on {$appointmentDate}."
            ];
        }

        // Resolve affiliated hospital_id AND doctor schedule for ETA calculation
        $hospStmt = $pdo->prepare("
            SELECT
                COALESCE(d.hospital_id, dp.hospital_id, u.hospital_id, 1) AS hospital_id,
                COALESCE(dp.avg_consultation_time, 10)                    AS avg_consultation_time,
                CASE
                    WHEN :slot = 'Evening' THEN COALESCE(dp.shift_start_time, '16:00:00')
                    ELSE                       COALESCE(dp.shift_start_time, '09:00:00')
                END AS shift_start
            FROM users u
            LEFT JOIN doctors d        ON u.user_id = d.user_id
            LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
            WHERE u.user_id = :doctor_id
            LIMIT 1
        ");
        $hospStmt->execute([':doctor_id' => $doctorId, ':slot' => $timeSlot]);
        $docInfo     = $hospStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hospitalId  = (int)($docInfo['hospital_id']          ?: 1);
        $avgMins     = (int)($docInfo['avg_consultation_time'] ?: 10);
        $shiftStart  = $docInfo['shift_start']                 ?: (($timeSlot === 'Evening') ? '16:00:00' : '09:00:00');

        // Standard default appointment_time column (legacy; kept for compatibility)
        $appointmentTime = ($timeSlot === 'Evening') ? '16:00:00' : '10:00:00';

        // Sanitise optional symptoms string (strip tags, trim, cap at 1000 chars)
        $symptoms = mb_substr(trim(strip_tags($symptoms)), 0, 1000);

        try {
            // Execute within a database transaction with row isolation
            $pdo->beginTransaction();

            // Count existing active bookings with row lock FOR UPDATE
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) AS booked_count, COALESCE(MAX(token_number), 0) AS last_token
                FROM appointments
                WHERE doctor_id = :doctor_id 
                  AND appointment_date = :appointment_date 
                  AND time_slot = :time_slot 
                  AND status != 'cancelled'
                FOR UPDATE;
            ");
            $countStmt->execute([
                ':doctor_id'        => $doctorId,
                ':appointment_date' => $appointmentDate,
                ':time_slot'        => $timeSlot
            ]);
            $row = $countStmt->fetch(PDO::FETCH_ASSOC);
            $bookedCount = (int)($row['booked_count'] ?? 0);
            $lastToken   = (int)($row['last_token'] ?? 0);

            // Capacity Validation: Strictly cap at 25 patients per shift/session
            if ($bookedCount >= 25) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'Shift full: Maximum capacity of 25 patients reached for this session.'
                ];
            }

            // Next Sequential Assignment
            $tokenNumber = $lastToken + 1;

            // ── Compute estimated consultation window ──────────────────────
            // Formula: shift_start + (token_index * avg_mins)
            //   token_index = token_number - 1  (first patient starts at shift_start itself)
            $shiftBaseTs       = strtotime(date('Y-m-d') . ' ' . $shiftStart);
            $estStartTs        = $shiftBaseTs + (($tokenNumber - 1) * $avgMins * 60);
            $estEndTs          = $estStartTs  + ($avgMins * 60);
            $estimatedStartTime = date('H:i:s', $estStartTs);
            $estimatedEndTime   = date('H:i:s', $estEndTs);

            // Insert the appointment record with the assigned token_number
            $insStmt = $pdo->prepare("
                INSERT INTO appointments
                    (hospital_id, doctor_id, patient_id, appointment_date, time_slot,
                     token_number, serial_number, appointment_time,
                     reason_for_visit, symptoms,
                     estimated_start_time, estimated_end_time,
                     queue_status, status, created_at)
                VALUES
                    (:hospital_id, :doctor_id, :patient_id, :appointment_date, :time_slot,
                     :token_number, :serial_number, :appointment_time,
                     :reason_for_visit, :symptoms,
                     :estimated_start_time, :estimated_end_time,
                     'scheduled', 'booked', NOW())
            ");
            $insStmt->execute([
                ':hospital_id'          => $hospitalId,
                ':doctor_id'            => $doctorId,
                ':patient_id'           => $patientId,
                ':appointment_date'     => $appointmentDate,
                ':time_slot'            => $timeSlot,
                ':token_number'         => $tokenNumber,
                ':serial_number'        => $tokenNumber,
                ':appointment_time'     => $appointmentTime,
                ':reason_for_visit'     => $reason ?: 'OPD Consultation',
                ':symptoms'             => $symptoms ?: null,
                ':estimated_start_time' => $estimatedStartTime,
                ':estimated_end_time'   => $estimatedEndTime,
            ]);

            $appointmentId = (int)$pdo->lastInsertId();
            $pdo->commit();

            return [
                'success'              => true,
                'appointment_id'       => $appointmentId,
                'token_number'         => $tokenNumber,
                'doctor_id'            => $doctorId,
                'date'                 => $appointmentDate,
                'time_slot'            => $timeSlot,
                'estimated_start_time' => $estimatedStartTime,
                'estimated_end_time'   => $estimatedEndTime,
                'message'              => "Appointment booked successfully! Your Serial Number is #{$tokenNumber} ({$timeSlot} Shift). Estimated consultation window: {$estimatedStartTime} – {$estimatedEndTime}."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in bookAppointment: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error while booking appointment: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Chamber Queue Progression: Attending doctor calls the next sequential patient
     * Calculates rolling delta for finished consultation and updates doctor's accumulated_delta_minutes
     */
    public static function callNextPatient(PDO $pdo, int $doctorId, ?int $actualDuration = null): array {
        try {
            $pdo->beginTransaction();

            // 1. Fetch current in_consultation appointment and doctor's avg & accumulated delta
            $prevStmt = $pdo->prepare("
                SELECT a.id, a.token_number, a.actual_start_time, a.created_at,
                       dp.avg_consultation_time, dp.accumulated_delta_minutes
                FROM appointments a
                LEFT JOIN doctor_profiles dp ON dp.user_id = a.doctor_id
                WHERE a.doctor_id = :doctor_id 
                  AND a.appointment_date = CURRENT_DATE 
                  AND a.status = 'in_consultation'
                LIMIT 1
                FOR UPDATE
            ");
            $prevStmt->execute([':doctor_id' => $doctorId]);
            $prevApp = $prevStmt->fetch(PDO::FETCH_ASSOC);

            $deltaMinutes = 0;
            $prevCompletedCount = 0;
            $newAccumulatedDelta = 0;

            if ($prevApp) {
                $avgMins = (int)(($prevApp['avg_consultation_time'] ?? 0) ?: 10);

                // Calculate actual consultation time spent
                if ($actualDuration !== null && $actualDuration >= 0) {
                    $actualMins = $actualDuration;
                } elseif (!empty($prevApp['actual_start_time'])) {
                    $startTs = strtotime($prevApp['actual_start_time']);
                    $elapsed = max(1, (int)round((time() - $startTs) / 60));
                    $actualMins = $elapsed;
                } else {
                    $actualMins = $avgMins;
                }

                // Rolling Delta Formula: actual_consultation_time - avg_consultation_time
                // E.g. spent 7 mins against avg 10 mins: 7 - 10 = -3 mins
                $deltaMinutes = $actualMins - $avgMins;

                // Update doctor's accumulated_delta_minutes
                $curDelta = (int)($prevApp['accumulated_delta_minutes'] ?? 0);
                $newAccumulatedDelta = $curDelta + $deltaMinutes;

                $updDoc = $pdo->prepare("
                    UPDATE doctor_profiles 
                    SET accumulated_delta_minutes = :new_delta
                    WHERE user_id = :doctor_id
                ");
                $updDoc->execute([
                    ':new_delta'  => $newAccumulatedDelta,
                    ':doctor_id' => $doctorId
                ]);

                // Update previous appointment to 'completed'
                $updPrev = $pdo->prepare("
                    UPDATE appointments 
                    SET status = 'completed',
                        queue_status = 'completed',
                        actual_end_time = NOW()
                    WHERE id = :id
                ");
                $updPrev->execute([':id' => $prevApp['id']]);
                $prevCompletedCount = 1;
            }

            // 2. Select next queued patient for today
            $nextStmt = $pdo->prepare("
                SELECT a.id, a.token_number, a.patient_id, u.full_name AS patient_name
                FROM appointments a
                JOIN users u ON a.patient_id = u.user_id
                WHERE a.doctor_id = :doctor_id 
                  AND a.appointment_date = CURRENT_DATE 
                  AND a.status IN ('booked', 'checked_in')
                ORDER BY a.token_number ASC 
                LIMIT 1 
                FOR UPDATE
            ");
            $nextStmt->execute([':doctor_id' => $doctorId]);
            $nextApp = $nextStmt->fetch(PDO::FETCH_ASSOC);

            if ($nextApp) {
                // Update next token to 'in_consultation'
                $updNext = $pdo->prepare("
                    UPDATE appointments 
                    SET status = 'in_consultation',
                        queue_status = 'serving',
                        actual_start_time = NOW()
                    WHERE id = :id
                ");
                $updNext->execute([':id' => $nextApp['id']]);

                // Update doctor's session to live
                $updDocSession = $pdo->prepare("
                    UPDATE doctor_profiles
                    SET session_status = 'live',
                        current_serving_token = :token
                    WHERE user_id = :doctor_id
                ");
                $updDocSession->execute([
                    ':token'     => $nextApp['token_number'],
                    ':doctor_id' => $doctorId
                ]);

                $pdo->commit();

                return [
                    'success'                   => true,
                    'has_next'                  => true,
                    'appointment_id'            => (int)$nextApp['id'],
                    'called_token'              => (int)$nextApp['token_number'],
                    'patient_id'                => (int)$nextApp['patient_id'],
                    'patient_name'              => $nextApp['patient_name'],
                    'previous_completed'        => ($prevCompletedCount > 0),
                    'delta_minutes'             => $deltaMinutes,
                    'accumulated_delta_minutes' => $newAccumulatedDelta,
                    'message'                   => "Now serving Serial #{$nextApp['token_number']} - {$nextApp['patient_name']}"
                ];
            } else {
                // Queue cleared: complete session
                $updDocSession = $pdo->prepare("
                    UPDATE doctor_profiles
                    SET session_status = 'completed',
                        current_serving_token = 0
                    WHERE user_id = :doctor_id
                ");
                $updDocSession->execute([':doctor_id' => $doctorId]);

                $pdo->commit();
                return [
                    'success'                   => true,
                    'has_next'                  => false,
                    'called_token'              => 0,
                    'previous_completed'        => ($prevCompletedCount > 0),
                    'delta_minutes'             => $deltaMinutes,
                    'accumulated_delta_minutes' => $newAccumulatedDelta,
                    'message'                   => ($prevCompletedCount > 0)
                        ? "Previous consultation completed. No more patients waiting in today's OPD queue."
                        : "No patients currently waiting in today's OPD queue."
                ];
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in callNextPatient: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error advancing queue: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Start chamber session (set session_status to 'live')
     */
    public static function startChamberSession(PDO $pdo, int $doctorId): array {
        try {
            $stmt = $pdo->prepare("UPDATE doctor_profiles SET session_status = 'live' WHERE user_id = :doctor_id");
            $stmt->execute([':doctor_id' => $doctorId]);
            return [
                'success'        => true,
                'session_status' => 'live',
                'message'        => 'Chamber session is now LIVE. Ready to call patients.'
            ];
        } catch (Throwable $e) {
            error_log("Error in startChamberSession: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error starting chamber session: ' . $e->getMessage()];
        }
    }

    /**
     * Temporarily hold or skip current token in consultation
     */
    public static function holdCurrentToken(PDO $pdo, int $doctorId, ?int $appointmentId = null): array {
        try {
            $pdo->beginTransaction();
            if ($appointmentId) {
                $stmt = $pdo->prepare("
                    SELECT id, token_number 
                    FROM appointments 
                    WHERE id = :id AND doctor_id = :doctor_id 
                    LIMIT 1 
                    FOR UPDATE
                ");
                $stmt->execute([':id' => $appointmentId, ':doctor_id' => $doctorId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, token_number 
                    FROM appointments 
                    WHERE doctor_id = :doctor_id 
                      AND appointment_date = CURRENT_DATE 
                      AND status = 'in_consultation' 
                    LIMIT 1 
                    FOR UPDATE
                ");
                $stmt->execute([':doctor_id' => $doctorId]);
            }
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'No active patient in consultation to put on hold.'];
            }

            // Move appointment back to checked_in / scheduled state
            $upd = $pdo->prepare("
                UPDATE appointments 
                SET status = 'checked_in',
                    queue_status = 'scheduled',
                    actual_start_time = NULL
                WHERE id = :id
            ");
            $upd->execute([':id' => $app['id']]);

            // Set current_serving_token to 0 so chamber is ready for next
            $updDoc = $pdo->prepare("
                UPDATE doctor_profiles 
                SET current_serving_token = 0 
                WHERE user_id = :doctor_id
            ");
            $updDoc->execute([':doctor_id' => $doctorId]);

            $pdo->commit();
            return [
                'success'      => true,
                'token_number' => (int)$app['token_number'],
                'message'      => "Token #{$app['token_number']} has been placed on temporary hold and returned to the waiting queue."
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in holdCurrentToken: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error holding token: ' . $e->getMessage()];
        }
    }

    /**
     * End chamber session (mark session_status as 'completed')
     */
    public static function endChamberSession(PDO $pdo, int $doctorId): array {
        try {
            $pdo->beginTransaction();

            // Check if there is an active patient currently inside chamber
            $stmt = $pdo->prepare("
                SELECT a.id, a.token_number, a.actual_start_time, dp.avg_consultation_time, dp.accumulated_delta_minutes
                FROM appointments a
                JOIN doctor_profiles dp ON a.doctor_id = dp.user_id
                WHERE a.doctor_id = :doctor_id 
                  AND a.appointment_date = CURRENT_DATE 
                  AND a.status = 'in_consultation'
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':doctor_id' => $doctorId]);
            $active = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($active) {
                $avgMins = (int)(($active['avg_consultation_time'] ?? 0) ?: 10);
                $startTs = !empty($active['actual_start_time']) ? strtotime($active['actual_start_time']) : time();
                $elapsedMins = max(1, (int)round((time() - $startTs) / 60));
                $delta = $elapsedMins - $avgMins;
                $newDelta = (int)($active['accumulated_delta_minutes'] ?? 0) + $delta;

                $updApp = $pdo->prepare("
                    UPDATE appointments 
                    SET status = 'completed', 
                        queue_status = 'completed', 
                        actual_end_time = NOW() 
                    WHERE id = :id
                ");
                $updApp->execute([':id' => $active['id']]);

                $updDoc = $pdo->prepare("
                    UPDATE doctor_profiles 
                    SET session_status = 'completed', 
                        current_serving_token = 0, 
                        accumulated_delta_minutes = :new_delta 
                    WHERE user_id = :doctor_id
                ");
                $updDoc->execute([':new_delta' => $newDelta, ':doctor_id' => $doctorId]);
            } else {
                $updDoc = $pdo->prepare("
                    UPDATE doctor_profiles 
                    SET session_status = 'completed', 
                        current_serving_token = 0 
                    WHERE user_id = :doctor_id
                ");
                $updDoc->execute([':doctor_id' => $doctorId]);
            }

            $pdo->commit();
            return [
                'success'        => true,
                'session_status' => 'completed',
                'message'        => 'Chamber consultation session ended successfully.'
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error in endChamberSession: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error ending chamber session: ' . $e->getMessage()];
        }
    }

    /**
     * Get real-time queue status for a patient's active appointment
     * Computes live expected start time with doctor accumulated rolling buffer delta
     */
    public static function getPatientLiveQueue(PDO $pdo, int $patientId): ?array {
        try {
            $stmt = $pdo->prepare("
                SELECT a.id, a.hospital_id, a.doctor_id, a.patient_id, a.appointment_date, 
                       a.time_slot, a.token_number, a.status, a.queue_status,
                       a.reason_for_visit, a.symptoms,
                       a.estimated_start_time, a.estimated_end_time,
                       a.actual_start_time, a.actual_end_time,
                       (a.appointment_date = CURRENT_DATE) AS is_today_sql,
                       u.full_name AS doctor_name,
                       dp.specialty, dp.room_number, dp.chamber_room_no,
                       dp.avg_consultation_time, dp.accumulated_delta_minutes,
                       dp.session_status, dp.current_serving_token, dp.shift_start_time,
                       h.name AS hospital_name, h.city AS hospital_city
                FROM appointments a
                JOIN users u ON a.doctor_id = u.user_id
                INNER JOIN doctors d ON (a.doctor_id = d.id OR a.doctor_id = d.user_id)
                LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
                LEFT JOIN hospitals h ON a.hospital_id = h.id
                WHERE a.patient_id = :patient_id 
                  AND a.status IN ('booked', 'checked_in', 'in_consultation')
                  AND u.status = 'active'
                  AND u.role = 'Doctor'
                  AND d.status IN ('active', 'approved')
                  AND a.appointment_date >= CURRENT_DATE
                ORDER BY 
                    CASE WHEN a.status = 'in_consultation' THEN 0 
                         WHEN a.appointment_date = CURRENT_DATE THEN 1 
                         ELSE 2 END,
                    a.appointment_date ASC, 
                    a.token_number ASC
                LIMIT 1
            ");
            $stmt->execute([':patient_id' => $patientId]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                return null;
            }

            $isToday = (!empty($app['is_today_sql']) || $app['appointment_date'] === date('Y-m-d'));
            $myToken = (int)$app['token_number'];
            $accumulatedDelta = (int)($app['accumulated_delta_minutes'] ?? 0);
            $avgMinsPerPatient = (int)(($app['avg_consultation_time'] ?? 0) ?: 10);
            $rawSession = strtolower(trim($app['session_status'] ?? 'idle'));

            $currentServing = 0;
            if ($isToday) {
                // Query doctor's current in_consultation token for today
                $serveStmt = $pdo->prepare("
                    SELECT COALESCE(token_number, 0) AS current_serving
                    FROM appointments
                    WHERE doctor_id = :doctor_id 
                      AND appointment_date = CURRENT_DATE 
                      AND status = 'in_consultation'
                    LIMIT 1;
                ");
                $serveStmt->execute([':doctor_id' => $app['doctor_id']]);
                $currentServing = (int)($serveStmt->fetchColumn() ?: 0);
                if ($currentServing === 0 && (int)($app['current_serving_token'] ?? 0) > 0) {
                    $currentServing = (int)$app['current_serving_token'];
                }
            }

            $isChamberLive = ($isToday && ($currentServing > 0 || $rawSession === 'live'));
            $chamberSessionStatus = $isChamberLive ? 'live' : ($rawSession === 'completed' ? 'completed' : 'idle');

            // Calculate people ahead: MAX(0, my_token - current_serving)
            if ($isToday) {
                $peopleAhead = max(0, $myToken - $currentServing);
                $isMyTurn = ($app['status'] === 'in_consultation' || ($currentServing === $myToken && $currentServing > 0));
                if ($isMyTurn) {
                    $peopleAhead = 0;
                }
            } else {
                $peopleAhead = max(0, $myToken - 1);
                $isMyTurn = false;
            }

            // Normalize chamber room number to format: Room-[Room #]
            $rawRoom = !empty($app['room_number']) ? $app['room_number'] : (!empty($app['chamber_room_no']) ? $app['chamber_room_no'] : '101');
            if (preg_match('/(?:Room|Chamber)?[- ]*([A-Za-z0-9-]+)/i', $rawRoom, $m)) {
                $cleanRoom = $m[1];
            } else {
                $cleanRoom = $rawRoom;
            }
            $displayRoom = 'Room-' . $cleanRoom;

            // Compute initial estimated start time
            $initialEstStart = $app['estimated_start_time'] ?? '';
            if (empty($initialEstStart)) {
                $shiftStart = !empty($app['shift_start_time']) ? $app['shift_start_time'] : '09:00:00';
                $baseTs = strtotime($app['appointment_date'] . ' ' . $shiftStart);
                $estTs = $baseTs + (($myToken - 1) * $avgMinsPerPatient * 60);
                $initialEstStart = date('H:i:s', $estTs);
            }
            $initialEstStartTs = strtotime($app['appointment_date'] . ' ' . $initialEstStart);

            // ── Dynamic Rolling Buffer Recalculation ─────────────────────────
            // live_expected_start = initial_estimated_start + accumulated_delta_minutes
            $liveExpectedStartTs = $initialEstStartTs + ($accumulatedDelta * 60);
            $liveExpectedStartTime = date('H:i:s', $liveExpectedStartTs);
            $liveExpectedStartFormatted = date('g:i A', $liveExpectedStartTs);
            $liveExpectedEndTs = $liveExpectedStartTs + ($avgMinsPerPatient * 60);
            $liveExpectedEndFormatted = date('g:i A', $liveExpectedEndTs);

            // ── Dynamic Status Badge ────────────────────────────────────────
            // If delta < 0: "Chamber running X mins ahead of schedule"
            // If delta > 0: "Delayed by ~X mins"
            // If delta == 0: "On schedule"
            if ($accumulatedDelta < 0) {
                $statusBadge = "Chamber running " . abs($accumulatedDelta) . " mins ahead of schedule";
                $statusBadgeType = "ahead";
            } elseif ($accumulatedDelta > 0) {
                $statusBadge = "Delayed by ~" . $accumulatedDelta . " mins";
                $statusBadgeType = "delayed";
            } else {
                $statusBadge = "On schedule";
                $statusBadgeType = "on_schedule";
            }

            // ── Wait Time Remaining for Countdown ───────────────────────────
            $nowTs = time();
            $secDiff = $liveExpectedStartTs - $nowTs;
            if ($isMyTurn) {
                $waitMinutesRemaining = 0;
            } elseif ($isToday && $isChamberLive && $peopleAhead > 0 && $secDiff <= 0) {
                // If scheduled window has elapsed today but queue is running live:
                $waitMinutesRemaining = max(1, ($peopleAhead * $avgMinsPerPatient) + $accumulatedDelta);
                $liveExpectedStartTs = $nowTs + ($waitMinutesRemaining * 60);
                $liveExpectedStartFormatted = date('g:i A', $liveExpectedStartTs);
                $liveExpectedEndFormatted = date('g:i A', $liveExpectedStartTs + ($avgMinsPerPatient * 60));
            } else {
                $waitMinutesRemaining = max(0, (int)ceil($secDiff / 60));
            }

            // ── Smart Proximity & Turn Alert ("Proceed to Door") ────────────
            // Trigger amber alert banner ONLY when:
            // 1. The chamber session is 'live', AND
            // 2. Either (a) patient is currently being called (serving), OR (b) strictly next in line (ahead_count == 0 or ahead_count == 1)
            // When chamber is 'idle' or ahead_count > 1, NEVER display the proceed-to-door alert
            $showProximityAlert = false;
            $proximityAlertMessage = '';

            if ($isToday && $isChamberLive) {
                if ($isMyTurn || $peopleAhead <= 1) {
                    $showProximityAlert = true;
                    $proximityAlertMessage = "⚠️ You are next in line! Please proceed outside {$displayRoom} immediately.";
                }
            }

            $formattedDate = date('M j, Y', strtotime($app['appointment_date']));
            if ($isToday) {
                if ($isMyTurn) {
                    $statusLabel = "It's your turn! Please proceed inside chamber";
                } else {
                    $statusLabel = "{$peopleAhead} Patients Ahead (~{$waitMinutesRemaining} mins wait)";
                }
            } else {
                $statusLabel = "Scheduled for {$formattedDate} | Serial: #{$myToken} (Queue goes live on appointment day)";
            }

            return [
                'has_appointment'                   => true,
                'appointment_id'                    => (int)$app['id'],
                'doctor_id'                         => (int)$app['doctor_id'],
                'doctor_name'                       => $app['doctor_name'],
                'specialty'                         => $app['specialty'] ?? 'Clinical Specialist',
                'room_number'                       => $displayRoom,
                'raw_room_number'                   => $rawRoom,
                'hospital_name'                     => $app['hospital_name'] ?? 'MedPulse Central Hospital',
                'hospital_city'                     => $app['hospital_city'] ?? 'Dhaka',
                'appointment_date'                  => $app['appointment_date'],
                'formatted_date'                    => $formattedDate,
                'time_slot'                         => $app['time_slot'],
                'token_number'                      => $myToken,
                'status'                            => $app['status'],
                'queue_status'                      => $app['queue_status'] ?? 'scheduled',
                'symptoms'                          => $app['symptoms'] ?? '',
                'estimated_start_time'              => $initialEstStart,
                'estimated_end_time'                => $app['estimated_end_time'] ?? '',
                'initial_estimated_start'           => $initialEstStart,
                'initial_estimated_start_formatted' => date('g:i A', $initialEstStartTs),
                'accumulated_delta_minutes'         => $accumulatedDelta,
                'live_expected_start'               => $liveExpectedStartTime,
                'live_expected_start_formatted'     => $liveExpectedStartFormatted,
                'live_expected_start_ts'            => $liveExpectedStartTs,
                'live_expected_end_formatted'       => $liveExpectedEndFormatted,
                'status_badge'                      => $statusBadge,
                'status_badge_type'                 => $statusBadgeType,
                'avg_mins'                          => $avgMinsPerPatient,
                'is_today'                          => $isToday,
                'current_serving'                   => $currentServing,
                'people_ahead'                      => $peopleAhead,
                'estimated_wait_mins'               => $waitMinutesRemaining,
                'session_status'                    => $chamberSessionStatus,
                'is_chamber_live'                   => $isChamberLive,
                'is_my_turn'                        => $isMyTurn,
                'show_proximity_alert'              => $showProximityAlert,
                'proximity_alert_message'           => $proximityAlertMessage,
                'wait_mins_remaining'               => $waitMinutesRemaining,
                'status_label'                      => $statusLabel
            ];

        } catch (Throwable $e) {
            error_log("Error in getPatientLiveQueue: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get active patient list for today's session for attending doctor sorted by token_number ASC
     */
    public static function getDoctorTodayQueue(PDO $pdo, int $doctorId): array {
        try {
            $stmt = $pdo->prepare("
                SELECT a.id, a.token_number, a.serial_number, a.time_slot, a.status, a.queue_status,
                       a.reason_for_visit, a.symptoms, a.appointment_time, a.appointment_date,
                       a.actual_start_time, a.actual_end_time,
                       a.estimated_start_time, a.estimated_end_time,
                       u.user_id AS patient_id, u.full_name AS patient_name, u.phone, u.gender,
                       pat.patient_uid, pat.blood_group, pat.dob, pat.allergies, pat.baseline_vitals, pat.email
                FROM appointments a
                JOIN users u ON a.patient_id = u.user_id
                LEFT JOIN patients pat ON u.user_id = pat.user_id
                WHERE a.doctor_id = :doctor_id 
                  AND a.appointment_date = CURRENT_DATE
                ORDER BY a.token_number ASC
            ");
            $stmt->execute([':doctor_id' => $doctorId]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute age for each patient record
            foreach ($list as &$item) {
                if (!empty($item['dob'])) {
                    try {
                        $item['age'] = (new DateTime($item['dob']))->diff(new DateTime())->y;
                    } catch (Throwable $e) {
                        $item['age'] = null;
                    }
                } else {
                    $item['age'] = null;
                }
            }
            unset($item);

            // Get current serving token
            $serveStmt = $pdo->prepare("
                SELECT COALESCE(token_number, 0) AS current_serving
                FROM appointments
                WHERE doctor_id = :doctor_id 
                  AND appointment_date = CURRENT_DATE 
                  AND status = 'in_consultation'
                LIMIT 1;
            ");
            $serveStmt->execute([':doctor_id' => $doctorId]);
            $currentServing = (int)($serveStmt->fetchColumn() ?: 0);

            return [
                'current_serving' => $currentServing,
                'patients'        => $list,
                'queue'           => $list
            ];
        } catch (Throwable $e) {
            error_log("Error in getDoctorTodayQueue: " . $e->getMessage());
            return [
                'current_serving' => 0,
                'patients'        => []
            ];
        }
    }
}

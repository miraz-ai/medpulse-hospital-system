<?php
/**
 * MedPulse OPD Appointment Controller
 * 
 * Handles:
 * - Sequential token generation capped at 25 per doctor/date/shift
 * - Chamber queue progression (Call Next Patient)
 * - Real-time queue status calculations (current serving, people ahead, estimated wait)
 */

class AppointmentController {

    /**
     * Book an OPD appointment with sequential token generation (capped at 25 per shift)
     */
    public static function bookAppointment(PDO $pdo, int $patientId, int $doctorId, string $appointmentDate, string $timeSlot, string $reason = ''): array {
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

        // Resolve affiliated hospital_id for the doctor
        $hospStmt = $pdo->prepare("
            SELECT COALESCE(d.hospital_id, dp.hospital_id, u.hospital_id, 1) AS hospital_id
            FROM users u
            LEFT JOIN doctors d ON u.user_id = d.user_id
            LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
            WHERE u.user_id = :doctor_id
            LIMIT 1
        ");
        $hospStmt->execute([':doctor_id' => $doctorId]);
        $hospitalId = (int)($hospStmt->fetchColumn() ?: 1);

        // Standard default appointment time based on slot
        $appointmentTime = ($timeSlot === 'Evening') ? '16:00:00' : '10:00:00';

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

            // Insert the appointment record with the assigned token_number
            $insStmt = $pdo->prepare("
                INSERT INTO appointments 
                    (hospital_id, doctor_id, patient_id, appointment_date, time_slot, token_number, serial_number, appointment_time, reason_for_visit, status, created_at)
                VALUES 
                    (:hospital_id, :doctor_id, :patient_id, :appointment_date, :time_slot, :token_number, :serial_number, :appointment_time, :reason_for_visit, 'booked', NOW())
            ");
            $insStmt->execute([
                ':hospital_id'       => $hospitalId,
                ':doctor_id'         => $doctorId,
                ':patient_id'        => $patientId,
                ':appointment_date'  => $appointmentDate,
                ':time_slot'         => $timeSlot,
                ':token_number'      => $tokenNumber,
                ':serial_number'     => $tokenNumber,
                ':appointment_time'  => $appointmentTime,
                ':reason_for_visit'  => $reason ?: 'OPD Consultation'
            ]);

            $appointmentId = (int)$pdo->lastInsertId();
            $pdo->commit();

            return [
                'success'        => true,
                'appointment_id' => $appointmentId,
                'token_number'   => $tokenNumber,
                'doctor_id'      => $doctorId,
                'date'           => $appointmentDate,
                'time_slot'      => $timeSlot,
                'message'        => "Appointment booked successfully! Your Serial Number is #{$tokenNumber} ({$timeSlot} Shift)."
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
     */
    public static function callNextPatient(PDO $pdo, int $doctorId): array {
        try {
            $pdo->beginTransaction();

            // 1. Update previous in_consultation appointment to 'completed'
            $updPrev = $pdo->prepare("
                UPDATE appointments 
                SET status = 'completed' 
                WHERE doctor_id = :doctor_id 
                  AND appointment_date = CURRENT_DATE 
                  AND status = 'in_consultation'
            ");
            $updPrev->execute([':doctor_id' => $doctorId]);
            $prevCompletedCount = $updPrev->rowCount();

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
                    SET status = 'in_consultation' 
                    WHERE id = :id
                ");
                $updNext->execute([':id' => $nextApp['id']]);

                $pdo->commit();

                return [
                    'success'            => true,
                    'has_next'           => true,
                    'appointment_id'     => (int)$nextApp['id'],
                    'called_token'       => (int)$nextApp['token_number'],
                    'patient_id'         => (int)$nextApp['patient_id'],
                    'patient_name'       => $nextApp['patient_name'],
                    'previous_completed' => ($prevCompletedCount > 0),
                    'message'            => "Now serving Serial #{$nextApp['token_number']} - {$nextApp['patient_name']}"
                ];
            } else {
                $pdo->commit();
                return [
                    'success'            => true,
                    'has_next'           => false,
                    'called_token'       => 0,
                    'previous_completed' => ($prevCompletedCount > 0),
                    'message'            => ($prevCompletedCount > 0)
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
     * Get real-time queue status for a patient's active appointment
     */
    public static function getPatientLiveQueue(PDO $pdo, int $patientId): ?array {
        try {
            $stmt = $pdo->prepare("
                SELECT a.id, a.hospital_id, a.doctor_id, a.patient_id, a.appointment_date, 
                       a.time_slot, a.token_number, a.status, a.reason_for_visit,
                       u.full_name AS doctor_name,
                       dp.specialty, dp.room_number,
                       h.name AS hospital_name, h.city AS hospital_city
                FROM appointments a
                JOIN users u ON a.doctor_id = u.user_id
                LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
                LEFT JOIN hospitals h ON a.hospital_id = h.id
                WHERE a.patient_id = :patient_id 
                  AND a.status IN ('booked', 'checked_in', 'in_consultation')
                  AND a.appointment_date >= CURRENT_DATE
                ORDER BY a.appointment_date ASC, a.token_number ASC
                LIMIT 1
            ");
            $stmt->execute([':patient_id' => $patientId]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$app) {
                return null;
            }

            $isToday = ($app['appointment_date'] === date('Y-m-d'));
            $myToken = (int)$app['token_number'];
            $currentServing = 0;
            $peopleAhead = 0;
            $estimatedWaitMins = 0;

            if ($isToday) {
                // Query doctor's current status for today
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

                // Calculate people ahead: MAX(0, my_token - current_serving)
                $peopleAhead = max(0, $myToken - $currentServing);

                // If currently serving this patient's token
                if ($app['status'] === 'in_consultation' || ($currentServing === $myToken && $currentServing > 0)) {
                    $peopleAhead = 0;
                    $estimatedWaitMins = 0;
                } else {
                    $estimatedWaitMins = $peopleAhead * 8;
                }
            }

            $formattedDate = date('M j, Y', strtotime($app['appointment_date']));
            $isMyTurn = ($isToday && ($app['status'] === 'in_consultation' || ($currentServing === $myToken && $currentServing > 0)));

            if ($isToday) {
                if ($isMyTurn) {
                    $statusLabel = "It's your turn! Please proceed inside chamber";
                } else {
                    $statusLabel = "{$peopleAhead} Patients Ahead (~{$estimatedWaitMins} mins wait)";
                }
            } else {
                $statusLabel = "Scheduled for {$formattedDate} | Serial: #{$myToken} (Queue goes live on appointment day)";
            }

            return [
                'has_appointment'     => true,
                'appointment_id'      => (int)$app['id'],
                'doctor_id'           => (int)$app['doctor_id'],
                'doctor_name'         => $app['doctor_name'],
                'specialty'           => $app['specialty'] ?? 'Clinical Specialist',
                'room_number'         => $app['room_number'] ?? 'Chamber 1',
                'hospital_name'       => $app['hospital_name'] ?? 'MedPulse Central Hospital',
                'hospital_city'       => $app['hospital_city'] ?? 'Dhaka',
                'appointment_date'    => $app['appointment_date'],
                'formatted_date'      => $formattedDate,
                'time_slot'           => $app['time_slot'],
                'token_number'        => $myToken,
                'status'              => $app['status'],
                'is_today'            => $isToday,
                'current_serving'     => $currentServing,
                'people_ahead'        => $peopleAhead,
                'estimated_wait_mins' => $estimatedWaitMins,
                'is_my_turn'          => $isMyTurn,
                'status_label'        => $statusLabel
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
                SELECT a.id, a.token_number, a.serial_number, a.time_slot, a.status, 
                       a.reason_for_visit, a.appointment_time, a.appointment_date,
                       u.user_id AS patient_id, u.full_name AS patient_name, u.phone, u.gender,
                       pat.patient_uid, pat.blood_group
                FROM appointments a
                JOIN users u ON a.patient_id = u.user_id
                LEFT JOIN patients pat ON u.user_id = pat.user_id
                WHERE a.doctor_id = :doctor_id 
                  AND a.appointment_date = CURRENT_DATE
                ORDER BY a.token_number ASC
            ");
            $stmt->execute([':doctor_id' => $doctorId]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                'patients'        => $list
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

<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Centralized Real-Time Event Dispatcher & Notification Bus
 */

declare(strict_types=1);

namespace MedPulse\Services;

use PDO;
use Throwable;

class EventDispatcher
{
    private const EVENT_LOG_FILE = __DIR__ . '/../../storage/events/events_stream.jsonl';

    /**
     * Dispatch an event to targeted recipients, persisting to DB and broadcasting to real-time stream.
     *
     * @param PDO $pdo Active PDO connection (can participate in active transaction)
     * @param string $eventType Standardized event name (e.g. PATIENT_BED_MOVED, BED_ASSIGNED, PATIENT_DISCHARGED, DOCTOR_ASSIGNED)
     * @param string $title Human-readable notification title
     * @param string $message Detailed notification message
     * @param array<int, array{id: int, type: string}> $recipients Array of recipient descriptors ['id' => int, 'type' => 'DOCTOR'|'PATIENT'|'ADMIN']
     * @param array<string, mixed> $data Strictly typed payload
     * @return array<int, int> Inserted notification IDs
     */
    public static function dispatch(
        PDO $pdo,
        string $eventType,
        string $title,
        string $message,
        array $recipients,
        array $data
    ): array {
        $insertedIds = [];
        $timestamp = date('c');

        // Prepare notifications statement
        $stmt = $pdo->prepare("
            INSERT INTO notifications (recipient_id, recipient_type, title, message, event_type, metadata, is_read, created_at)
            VALUES (:rid, :rtype, :title, :message, :event_type, :metadata, 0, NOW())
        ");

        foreach ($recipients as $recipient) {
            $recipientId = (int)$recipient['id'];
            $recipientType = strtoupper(trim($recipient['type']));

            // Channel identifier: e.g. "patient:18", "doctor:21", "admin:all"
            $channel = strtolower($recipientType) . ':' . $recipientId;

            $eventPayload = [
                'event_id'       => null, // Will populate after DB insert
                'event'          => $eventType,
                'channel'        => $channel,
                'recipient_id'   => $recipientId,
                'recipient_type' => $recipientType,
                'title'          => $title,
                'message'        => $message,
                'timestamp'      => $timestamp,
                'data'           => $data
            ];

            $stmt->execute([
                ':rid'        => $recipientId,
                ':rtype'      => $recipientType,
                ':title'      => $title,
                ':message'    => $message,
                ':event_type' => $eventType,
                ':metadata'   => json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);

            $notifId = (int)$pdo->lastInsertId();
            $insertedIds[] = $notifId;
            $eventPayload['event_id'] = $notifId;

            // Broadcast to local real-time event pipeline for SSE/WebSocket consumers
            self::appendToStream($eventPayload);
        }

        return $insertedIds;
    }

    /**
     * Dispatch Bed Transfer Event
     * Emits:
     *  a) To patient:{patientId}: Alert with new bed/room info
     *  b) To each doctor:{doctorId}: Alert that patient has moved with old & new bed/room numbers
     *  c) To admin:1: Administrative broadcast
     */
    public static function notifyBedTransfer(
        PDO $pdo,
        int $patientId,
        array $oldBed,
        array $newBed,
        array $doctorIds,
        int $actorId,
        ?string $reason = null
    ): void {
        // Fetch patient details
        $pStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :pid");
        $pStmt->execute([':pid' => $patientId]);
        $patientName = (string)($pStmt->fetchColumn() ?: "Patient #{$patientId}");

        // Fetch actor details
        $aStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :aid");
        $aStmt->execute([':aid' => $actorId]);
        $actorName = (string)($aStmt->fetchColumn() ?: "Admin #{$actorId}");

        $roomNumber = "Floor {$newBed['floor_number']} - {$newBed['ward_type']} (Bed {$newBed['bed_number']})";

        $payloadData = [
            'patient_id'     => $patientId,
            'patient_name'   => $patientName,
            'old_bed'        => [
                'bed_id'       => (int)$oldBed['bed_id'],
                'bed_number'   => (string)$oldBed['bed_number'],
                'ward_type'    => (string)$oldBed['ward_type'],
                'floor_number' => (int)$oldBed['floor_number'],
            ],
            'new_bed'        => [
                'bed_id'       => (int)$newBed['bed_id'],
                'bed_number'   => (string)$newBed['bed_number'],
                'ward_type'    => (string)$newBed['ward_type'],
                'floor_number' => (int)$newBed['floor_number'],
            ],
            'room_number'    => $roomNumber,
            'reason'         => $reason ?: 'Routine clinical room relocation',
            'transferred_by' => $actorName,
            'timestamp'      => date('Y-m-d H:i:s')
        ];

        // 1. Recipient: Patient
        $patientTitle = "Bed Relocation Confirmed";
        $patientMsg = "You have been transferred from Bed {$oldBed['bed_number']} ({$oldBed['ward_type']}) to Bed {$newBed['bed_number']} ({$newBed['ward_type']}).";
        self::dispatch(
            $pdo,
            'PATIENT_BED_MOVED',
            $patientTitle,
            $patientMsg,
            [['id' => $patientId, 'type' => 'PATIENT']],
            $payloadData
        );

        // 2. Recipients: All Assigned Doctors
        if (!empty($doctorIds)) {
            $docRecipients = array_map(fn($did) => ['id' => (int)$did, 'type' => 'DOCTOR'], $doctorIds);
            $docTitle = "Patient Bed Moved: {$patientName}";
            $docMsg = "Your patient {$patientName} has been relocated from Bed {$oldBed['bed_number']} ({$oldBed['ward_type']}) to Bed {$newBed['bed_number']} ({$newBed['ward_type']}). Active rounds list updated.";

            self::dispatch(
                $pdo,
                'PATIENT_BED_MOVED',
                $docTitle,
                $docMsg,
                $docRecipients,
                $payloadData
            );
        }

        // 3. Recipient: Admin Actor (for central audit telemetry)
        if ($actorId > 0) {
            self::dispatch(
                $pdo,
                'PATIENT_BED_MOVED',
                "Relocation Recorded: {$patientName}",
                "Patient {$patientName} moved from Bed {$oldBed['bed_number']} to {$newBed['bed_number']}.",
                [['id' => $actorId, 'type' => 'ADMIN']],
                $payloadData
            );
        }
    }

    /**
     * Dispatch Patient Discharge Event
     */
    public static function notifyDischarge(
        PDO $pdo,
        int $patientId,
        array $releasedBed,
        array $doctorIds,
        int $actorId,
        ?string $summary = null
    ): void {
        $pStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :pid");
        $pStmt->execute([':pid' => $patientId]);
        $patientName = (string)($pStmt->fetchColumn() ?: "Patient #{$patientId}");

        $aStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :aid");
        $aStmt->execute([':aid' => $actorId]);
        $actorName = (string)($aStmt->fetchColumn() ?: "Admin #{$actorId}");

        $payloadData = [
            'patient_id'        => $patientId,
            'patient_name'      => $patientName,
            'released_bed'      => [
                'bed_id'       => (int)$releasedBed['bed_id'],
                'bed_number'   => (string)$releasedBed['bed_number'],
                'ward_type'    => (string)$releasedBed['ward_type'],
                'floor_number' => (int)$releasedBed['floor_number'],
            ],
            'discharge_summary' => $summary ?: 'Clinical discharge approved.',
            'discharged_at'     => date('Y-m-d H:i:s'),
            'discharged_by'     => $actorName
        ];

        // 1. Patient Notification
        self::dispatch(
            $pdo,
            'PATIENT_DISCHARGED',
            "Discharge Completed",
            "You have been formally discharged from Bed {$releasedBed['bed_number']} ({$releasedBed['ward_type']}). Wishing you a swift recovery!",
            [['id' => $patientId, 'type' => 'PATIENT']],
            $payloadData
        );

        // 2. Assigned Doctors
        if (!empty($doctorIds)) {
            $docRecipients = array_map(fn($did) => ['id' => (int)$did, 'type' => 'DOCTOR'], $doctorIds);
            self::dispatch(
                $pdo,
                'PATIENT_DISCHARGED',
                "Patient Discharged: {$patientName}",
                "Patient {$patientName} has been discharged from Bed {$releasedBed['bed_number']}. Inpatient care completed.",
                $docRecipients,
                $payloadData
            );
        }
    }

    /**
     * Dispatch Doctor Assignment Event
     */
    public static function notifyDoctorAssignment(
        PDO $pdo,
        int $patientId,
        array $addedDoctorIds,
        array $removedDoctorIds,
        int $actorId
    ): void {
        $pStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :pid");
        $pStmt->execute([':pid' => $patientId]);
        $patientName = (string)($pStmt->fetchColumn() ?: "Patient #{$patientId}");

        // Notify added doctors
        if (!empty($addedDoctorIds)) {
            $recipients = array_map(fn($did) => ['id' => (int)$did, 'type' => 'DOCTOR'], $addedDoctorIds);
            self::dispatch(
                $pdo,
                'DOCTOR_ASSIGNED',
                "New Inpatient Assigned: {$patientName}",
                "You have been assigned to inpatient care for {$patientName}.",
                $recipients,
                [
                    'patient_id'   => $patientId,
                    'patient_name' => $patientName,
                    'action'       => 'ASSIGNED',
                    'timestamp'    => date('Y-m-d H:i:s')
                ]
            );
        }

        // Notify patient of care team change
        self::dispatch(
            $pdo,
            'DOCTOR_ASSIGNED',
            "Care Team Updated",
            "Your attending medical team has been updated by the clinical administration.",
            [['id' => $patientId, 'type' => 'PATIENT']],
            [
                'patient_id'        => $patientId,
                'added_doctors'    => $addedDoctorIds,
                'removed_doctors'  => $removedDoctorIds,
                'timestamp'        => date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Record an audit log entry in the existing audit_logs table.
     */
    public static function pushAuditLog(
        PDO $pdo,
        int $actorId,
        string $actionKey,
        string $description,
        string $category,
        string $actionName,
        string $targetEntity,
        string $ip
    ): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, description, category, target_entity, ip_address, created_at)
                VALUES (:uid, :act, :desc, :cat, :tgt, :ip, NOW())
            ");
            $stmt->execute([
                ':uid'  => $actorId > 0 ? $actorId : null,
                ':act'  => mb_substr($actionKey, 0, 50),
                ':desc' => mb_substr($description, 0, 255),
                ':cat'  => mb_substr($category, 0, 50),
                ':tgt'  => mb_substr($targetEntity, 0, 100),
                ':ip'   => mb_substr($ip, 0, 45)
            ]);
        } catch (Throwable $e) {
            error_log("Audit log failed in EventDispatcher: " . $e->getMessage());
        }
    }

    /**
     * Append to local events file stream for SSE / WebSocket consumption.
     */
    private static function appendToStream(array $payload): void
    {
        try {
            $dir = dirname(self::EVENT_LOG_FILE);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents(self::EVENT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            error_log("Failed to append to events_stream.jsonl: " . $e->getMessage());
        }
    }
}

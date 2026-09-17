<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Atomic Bed Transfer API Controller
 * 
 * Atomically transfers a patient from Bed A to Bed B with strict row locking,
 * release of Bed A, acquisition of Bed B, transfer history logging,
 * and multi-channel real-time event dispatching.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../Services/EventDispatcher.php';

use MedPulse\Services\EventDispatcher;

// 1. Method Guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

// 2. Admin RBAC Guard
if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Administrator authentication required.']);
    exit;
}

// 3. CSRF Protection Gatekeeper
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token expired or invalid. Please reload the console.']);
    exit;
}

$actorId = (int)$_SESSION['user_id'];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// 4. Input Validation
$patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
$fromBedId = filter_var($_POST['from_bed_id'] ?? null, FILTER_VALIDATE_INT);
$toBedId   = filter_var($_POST['to_bed_id'] ?? null, FILTER_VALIDATE_INT);
$reason    = trim((string)($_POST['reason'] ?? 'Routine clinical ward relocation'));

if (!$patientId || !$fromBedId || !$toBedId) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: patient_id, from_bed_id, and to_bed_id must be valid integers.'
    ]);
    exit;
}

if ($fromBedId === $toBedId) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Destination bed must be different from the source bed.'
    ]);
    exit;
}

try {
    // Begin single atomic transaction
    $pdo->beginTransaction();

    // 5. Deadlock-safe row locking on both beds in deterministic ascending ID order
    $firstBedId = min($fromBedId, $toBedId);
    $secondBedId = max($fromBedId, $toBedId);

    $bedLockStmt = $pdo->prepare("
        SELECT bed_id, bed_number, ward_type, floor_number, daily_rate, status
        FROM hospital_beds
        WHERE bed_id IN (:b1, :b2)
        ORDER BY bed_id ASC
        FOR UPDATE
    ");
    $bedLockStmt->execute([':b1' => $firstBedId, ':b2' => $secondBedId]);
    $lockedBeds = $bedLockStmt->fetchAll(PDO::FETCH_ASSOC);

    $bedsById = [];
    foreach ($lockedBeds as $b) {
        $bedsById[(int)$b['bed_id']] = $b;
    }

    if (!isset($bedsById[$fromBedId]) || !isset($bedsById[$toBedId])) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'One or both specified beds do not exist in the hospital registry.']);
        exit;
    }

    $fromBed = $bedsById[$fromBedId];
    $toBed   = $bedsById[$toBedId];

    // Verify source bed is occupied
    if ($fromBed['status'] !== 'Occupied') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => "Source Bed {$fromBed['bed_number']} is not currently marked Occupied."]);
        exit;
    }

    // Verify target bed is available
    if ($toBed['status'] !== 'Available') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => "Target Bed {$toBed['bed_number']} is no longer Available ({$toBed['status']})."]);
        exit;
    }

    // 6. Verify patient's active allocation matches source bed
    $allocStmt = $pdo->prepare("
        SELECT allocation_id, patient_id, attending_doctor_id, admitted_at
        FROM bed_allocations
        WHERE patient_id = :pid AND bed_id = :bid AND status = 'Active'
        FOR UPDATE
    ");
    $allocStmt->execute([':pid' => $patientId, ':bid' => $fromBedId]);
    $activeAllocation = $allocStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeAllocation) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Patient #{$patientId} does not have an active admission record in Bed {$fromBed['bed_number']}."
        ]);
        exit;
    }

    // 7. Atomic State Transitions
    // Step A: Release Source Bed A -> Available
    $freeBedStmt = $pdo->prepare("UPDATE hospital_beds SET status = 'Available' WHERE bed_id = :bid");
    $freeBedStmt->execute([':bid' => $fromBedId]);

    // Step B: Mark Target Bed B -> Occupied
    $occupyBedStmt = $pdo->prepare("UPDATE hospital_beds SET status = 'Occupied' WHERE bed_id = :bid");
    $occupyBedStmt->execute([':bid' => $toBedId]);

    // Step C: Close previous allocation as 'Transferred'
    $closeAllocStmt = $pdo->prepare("
        UPDATE bed_allocations
        SET status = 'Transferred', discharged_at = NOW()
        WHERE allocation_id = :aid
    ");
    $closeAllocStmt->execute([':aid' => $activeAllocation['allocation_id']]);

    // Step D: Open new active allocation on Bed B (Unique constraints enforce 1-to-1)
    $attendingDocId = $activeAllocation['attending_doctor_id'] ? (int)$activeAllocation['attending_doctor_id'] : null;
    $newAllocStmt = $pdo->prepare("
        INSERT INTO bed_allocations (bed_id, patient_id, attending_doctor_id, admitted_at, status)
        VALUES (:bid, :pid, :did, :admitted_at, 'Active')
    ");
    $newAllocStmt->execute([
        ':bid'         => $toBedId,
        ':pid'         => $patientId,
        ':did'         => $attendingDocId,
        ':admitted_at' => $activeAllocation['admitted_at']
    ]);
    $newAllocationId = (int)$pdo->lastInsertId();

    // Step E: Record in bed_transfer_history audit table
    $historyStmt = $pdo->prepare("
        INSERT INTO bed_transfer_history (patient_id, from_bed_id, to_bed_id, transferred_by, reason, transferred_at)
        VALUES (:pid, :from_bid, :to_bid, :actor_id, :reason, NOW())
    ");
    $historyStmt->execute([
        ':pid'      => $patientId,
        ':from_bid' => $fromBedId,
        ':to_bid'   => $toBedId,
        ':actor_id' => $actorId,
        ':reason'   => $reason
    ]);
    $transferId = (int)$pdo->lastInsertId();

    // 8. Fetch all active doctors assigned to this patient from junction table
    $docStmt = $pdo->prepare("
        SELECT doctor_id FROM patient_doctor_assignments
        WHERE patient_id = :pid AND status = 'Active'
    ");
    $docStmt->execute([':pid' => $patientId]);
    $assignedDoctorIds = $docStmt->fetchAll(PDO::FETCH_COLUMN);
    $assignedDoctorIds = array_map('intval', $assignedDoctorIds);

    // If junction table has no records yet but allocation had an attending doctor, include them
    if (empty($assignedDoctorIds) && $attendingDocId) {
        $assignedDoctorIds[] = $attendingDocId;
    }

    // 9. Dispatch Real-Time Notifications (Emits to patient, doctors, admin)
    EventDispatcher::notifyBedTransfer(
        $pdo,
        $patientId,
        $fromBed,
        $toBed,
        $assignedDoctorIds,
        $actorId,
        $reason
    );

    // 10. Central Audit Log
    EventDispatcher::pushAuditLog(
        $pdo,
        $actorId,
        'BED_TRANSFER',
        "Transferred Patient #{$patientId} from Bed {$fromBed['bed_number']} ({$fromBed['ward_type']}) to Bed {$toBed['bed_number']} ({$toBed['ward_type']}). Reason: {$reason}",
        'CLINICAL_OPS',
        'Bed Transfer',
        "Patient #{$patientId} -> {$toBed['bed_number']}",
        $clientIp
    );

    // Commit single atomic transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "✓ Successfully transferred patient to Bed {$toBed['bed_number']} ({$toBed['ward_type']}).",
        'data'    => [
            'transfer_id'       => $transferId,
            'new_allocation_id' => $newAllocationId,
            'patient_id'        => $patientId,
            'from_bed'          => $fromBed['bed_number'],
            'to_bed'            => $toBed['bed_number'],
            'ward_type'         => $toBed['ward_type'],
            'floor_number'      => $toBed['floor_number'],
            'transferred_at'    => date('Y-m-d H:i:s')
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Bed Transfer Transaction Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Bed transfer transaction aborted: ' . $e->getMessage()
    ]);
}

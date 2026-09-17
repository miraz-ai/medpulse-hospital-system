<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Atomic Patient Discharge API Controller
 * 
 * Atomically marks patient discharged, releases the occupied bed back to Available,
 * records discharge timestamp, deactivates active care team doctor assignments,
 * and dispatches multi-channel real-time notifications.
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
    echo json_encode(['success' => false, 'message' => 'Access denied. Administrator privileges required.']);
    exit;
}

// 3. CSRF Protection Gatekeeper
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page.']);
    exit;
}

$actorId = (int)$_SESSION['user_id'];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// 4. Input Parsing
$patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
$dischargeSummary = trim((string)($_POST['summary'] ?? 'Clinical discharge approved. Inpatient treatment cycle completed.'));

if (!$patientId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Valid patient_id is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 5. Fetch Patient Info & Active Bed Allocation with Row Lock
    $allocStmt = $pdo->prepare("
        SELECT ba.allocation_id, ba.bed_id, ba.admitted_at,
               b.bed_number, b.ward_type, b.floor_number, b.status AS bed_status,
               u.full_name AS patient_name
        FROM bed_allocations ba
        JOIN hospital_beds b ON ba.bed_id = b.bed_id
        JOIN users u ON ba.patient_id = u.user_id
        WHERE ba.patient_id = :pid AND ba.status = 'Active'
        FOR UPDATE
    ");
    $allocStmt->execute([':pid' => $patientId]);
    $activeAlloc = $allocStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeAlloc) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Patient #{$patientId} has no active bed admission to discharge."]);
        exit;
    }

    $bedId = (int)$activeAlloc['bed_id'];

    // 6. Release Bed immediately -> 'Available' (or sanitized)
    $relBedStmt = $pdo->prepare("UPDATE hospital_beds SET status = 'Available' WHERE bed_id = :bid");
    $relBedStmt->execute([':bid' => $bedId]);

    // 7. Update Bed Allocation -> 'Discharged' with discharge timestamp
    $closeAllocStmt = $pdo->prepare("
        UPDATE bed_allocations
        SET status = 'Discharged', discharged_at = NOW()
        WHERE allocation_id = :aid
    ");
    $closeAllocStmt->execute([':aid' => $activeAlloc['allocation_id']]);

    // 8. End all active doctor assignments for this inpatient cycle
    $docStmt = $pdo->prepare("
        SELECT doctor_id FROM patient_doctor_assignments
        WHERE patient_id = :pid AND status = 'Active'
        FOR UPDATE
    ");
    $docStmt->execute([':pid' => $patientId]);
    $assignedDoctorIds = $docStmt->fetchAll(PDO::FETCH_COLUMN);
    $assignedDoctorIds = array_map('intval', $assignedDoctorIds);

    $endDocStmt = $pdo->prepare("
        UPDATE patient_doctor_assignments
        SET status = 'Inactive', ended_at = NOW()
        WHERE patient_id = :pid AND status = 'Active'
    ");
    $endDocStmt->execute([':pid' => $patientId]);

    // 9. Dispatch Real-Time Notifications
    EventDispatcher::notifyDischarge(
        $pdo,
        $patientId,
        [
            'bed_id'       => $bedId,
            'bed_number'   => $activeAlloc['bed_number'],
            'ward_type'    => $activeAlloc['ward_type'],
            'floor_number' => $activeAlloc['floor_number']
        ],
        $assignedDoctorIds,
        $actorId,
        $dischargeSummary
    );

    // 10. Central Audit Log
    EventDispatcher::pushAuditLog(
        $pdo,
        $actorId,
        'PATIENT_DISCHARGE',
        "Patient {$activeAlloc['patient_name']} (#{$patientId}) discharged from Bed {$activeAlloc['bed_number']} ({$activeAlloc['ward_type']}). Bed released to Available status.",
        'CLINICAL_OPS',
        'Patient Discharge',
        "Patient #{$patientId} - Bed {$activeAlloc['bed_number']}",
        $clientIp
    );

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "✓ Patient {$activeAlloc['patient_name']} has been successfully discharged and Bed {$activeAlloc['bed_number']} is now Available.",
        'data'    => [
            'patient_id'     => $patientId,
            'released_bed'   => $activeAlloc['bed_number'],
            'discharged_at'  => date('Y-m-d H:i:s'),
            'summary'        => $dischargeSummary
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Discharge Transaction Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Discharge transaction failed: ' . $e->getMessage()
    ]);
}

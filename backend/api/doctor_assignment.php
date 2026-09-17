<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Multi-Doctor Care Team Assignment API Controller
 * 
 * Manages Many-to-Many assignments between patients and attending doctors,
 * guaranteeing data consistency via transactional updates and dispatching real-time notifications.
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
    echo json_encode(['success' => false, 'message' => 'Access denied. Administrative privileges required.']);
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

// 4. Input Parsing & Validation
$patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
$primaryDoctorId = filter_var($_POST['primary_doctor_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$notes = trim((string)($_POST['notes'] ?? ''));

// Doctors can come in as an array or comma-separated string
$rawDoctorIds = $_POST['doctor_ids'] ?? [];
if (is_string($rawDoctorIds)) {
    $rawDoctorIds = explode(',', $rawDoctorIds);
}

$newDoctorIds = [];
if (is_array($rawDoctorIds)) {
    foreach ($rawDoctorIds as $did) {
        $val = filter_var($did, FILTER_VALIDATE_INT);
        if ($val && $val > 0) {
            $newDoctorIds[] = (int)$val;
        }
    }
}
$newDoctorIds = array_values(array_unique($newDoctorIds));

if (!$patientId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Valid patient_id is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Verify patient exists
    $pCheck = $pdo->prepare("SELECT user_id, full_name FROM users WHERE user_id = :pid AND role = 'Patient' FOR UPDATE");
    $pCheck->execute([':pid' => $patientId]);
    $patient = $pCheck->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Patient #{$patientId} not found in directory."]);
        exit;
    }

    // Verify all requested doctors exist and are active
    if (!empty($newDoctorIds)) {
        $placeholders = implode(',', array_fill(0, count($newDoctorIds), '?'));
        $docCheck = $pdo->prepare("SELECT user_id FROM users WHERE user_id IN ($placeholders) AND role = 'Doctor' AND status = 'active'");
        $docCheck->execute($newDoctorIds);
        $validFoundDoctorIds = $docCheck->fetchAll(PDO::FETCH_COLUMN);
        $validFoundDoctorIds = array_map('intval', $validFoundDoctorIds);

        if (count($validFoundDoctorIds) !== count($newDoctorIds)) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'One or more designated doctors are invalid or inactive.']);
            exit;
        }
    }

    // Fetch currently active assignments
    $curStmt = $pdo->prepare("
        SELECT doctor_id 
        FROM patient_doctor_assignments 
        WHERE patient_id = :pid AND status = 'Active'
        FOR UPDATE
    ");
    $curStmt->execute([':pid' => $patientId]);
    $currentDoctorIds = $curStmt->fetchAll(PDO::FETCH_COLUMN);
    $currentDoctorIds = array_map('intval', $currentDoctorIds);

    $toRemove = array_diff($currentDoctorIds, $newDoctorIds);
    $toAdd    = array_diff($newDoctorIds, $currentDoctorIds);

    // 1. Deactivate removed doctors
    if (!empty($toRemove)) {
        $removePlaceholders = implode(',', array_fill(0, count($toRemove), '?'));
        $removeStmt = $pdo->prepare("
            UPDATE patient_doctor_assignments
            SET status = 'Inactive', ended_at = NOW()
            WHERE patient_id = ? AND doctor_id IN ($removePlaceholders) AND status = 'Active'
        ");
        $removeStmt->execute(array_merge([$patientId], array_values($toRemove)));
    }

    // 2. Insert or reactivate newly added doctors
    $insStmt = $pdo->prepare("
        INSERT INTO patient_doctor_assignments (patient_id, doctor_id, assigned_by, is_primary, status, notes, assigned_at)
        VALUES (:pid, :did, :aid, :is_prim, 'Active', :notes, NOW())
    ");

    foreach ($toAdd as $did) {
        $isPrim = ($primaryDoctorId === $did) ? 1 : 0;
        $insStmt->execute([
            ':pid'     => $patientId,
            ':did'     => $did,
            ':aid'     => $actorId,
            ':is_prim' => $isPrim,
            ':notes'   => $notes ?: null
        ]);
    }

    // 3. Update primary doctor status across active assignments
    if ($primaryDoctorId && in_array($primaryDoctorId, $newDoctorIds, true)) {
        $updPrim = $pdo->prepare("
            UPDATE patient_doctor_assignments
            SET is_primary = IF(doctor_id = :primid, 1, 0)
            WHERE patient_id = :pid AND status = 'Active'
        ");
        $updPrim->execute([':primid' => $primaryDoctorId, ':pid' => $patientId]);

        // Also update bed_allocations attending_doctor_id for legacy alignment
        $updBedAlloc = $pdo->prepare("
            UPDATE bed_allocations 
            SET attending_doctor_id = :primid 
            WHERE patient_id = :pid AND status = 'Active'
        ");
        $updBedAlloc->execute([':primid' => $primaryDoctorId, ':pid' => $patientId]);
    }

    // 4. Dispatch Real-Time Notifications
    EventDispatcher::notifyDoctorAssignment(
        $pdo,
        $patientId,
        array_values($toAdd),
        array_values($toRemove),
        $actorId
    );

    // 5. Central Audit Log
    $docSummary = count($newDoctorIds) . " doctors assigned (Added: " . count($toAdd) . ", Removed: " . count($toRemove) . ")";
    EventDispatcher::pushAuditLog(
        $pdo,
        $actorId,
        'DOCTOR_ASSIGNMENT',
        "Updated care team for {$patient['full_name']} (#{$patientId}): {$docSummary}.",
        'CLINICAL_OPS',
        'Doctor Assignment',
        "Patient #{$patientId}",
        $clientIp
    );

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "✓ Care team successfully updated for {$patient['full_name']}.",
        'data'    => [
            'patient_id'        => $patientId,
            'active_doctor_ids' => $newDoctorIds,
            'primary_doctor_id' => $primaryDoctorId,
            'added'             => array_values($toAdd),
            'removed'           => array_values($toRemove)
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Doctor Assignment Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update doctor assignments: ' . $e->getMessage()
    ]);
}

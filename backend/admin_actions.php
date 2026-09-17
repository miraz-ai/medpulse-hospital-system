<?php
/**
 * MedPulse Administrative User Management Actions
 * Handles status updates (Approve, Reject, Suspend, Activate, Delete) with CSRF and RBAC enforcement.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

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
require_once __DIR__ . '/../config/db.php';

// 1. Method Guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// 2. Strict Admin RBAC Authorization Guard
if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Access denied. Administrative privileges required.'
    ]);
    exit;
}

// 3. CSRF Protection Gatekeeper
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Security token mismatch. Request rejected.'
    ]);
    exit;
}

// 4. Input Validation
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$action = trim($_POST['action'] ?? '');
$allowedActions = ['approve', 'reject', 'suspend', 'activate', 'delete', 'allocate_patient', 'allocate_bed'];

// Support allocate_patient where patient_id may be passed instead of user_id
if (($action === 'allocate_patient' || $action === 'allocate_bed') && !$userId) {
    $userId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
}

if (!$userId || !in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'success' => false,
        'message' => 'Invalid parameters. Valid user_id/patient_id and supported action are required.'
    ]);
    exit;
}

// Dedicated Handler: allocate_patient / allocate_bed
if ($action === 'allocate_patient' || $action === 'allocate_bed') {
    $patientId = $userId;
    $bedId     = filter_var($_POST['bed_id'] ?? null, FILTER_VALIDATE_INT);
    $docId     = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $notes     = trim((string)($_POST['notes'] ?? ''));
    $actorId   = (int)($_SESSION['user_id'] ?? 0);
    $clientIp  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!$bedId || !$patientId) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'Bed ID and Patient ID are required.'
        ]);
        exit;
    }

    try {
        // 1. Business-Rule Validation: Check if patient is already admitted elsewhere
        $checkPat = $pdo->prepare("
            SELECT ba.bed_id, b.bed_number 
            FROM bed_allocations ba 
            JOIN hospital_beds b ON ba.bed_id = b.bed_id 
            WHERE ba.patient_id = :pid AND ba.status = 'Active' 
            LIMIT 1
        ");
        $checkPat->execute([':pid' => $patientId]);
        $currentBed = $checkPat->fetch(PDO::FETCH_ASSOC);

        if ($currentBed) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => "Patient is currently admitted in Bed {$currentBed['bed_number']}. Please use Transfer Bed."
            ]);
            exit;
        }

        // 2. Business-Rule Validation: Check if bed is already occupied
        $bedRow = $pdo->prepare("SELECT bed_number, status FROM hospital_beds WHERE bed_id = :id LIMIT 1");
        $bedRow->execute([':id' => $bedId]);
        $bed = $bedRow->fetch(PDO::FETCH_ASSOC);

        if (!$bed || $bed['status'] !== 'Available') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => 'Bed is already occupied by another patient.'
            ]);
            exit;
        }

        $patRow = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :id AND role = 'Patient' LIMIT 1");
        $patRow->execute([':id' => $patientId]);
        $patient = $patRow->fetch(PDO::FETCH_ASSOC);
        $patName = $patient['full_name'] ?? "Patient #$patientId";

        $pdo->beginTransaction();

        // 3. Mark bed as Occupied
        $upd = $pdo->prepare("UPDATE hospital_beds SET status = 'Occupied' WHERE bed_id = :bid AND status = 'Available'");
        $upd->execute([':bid' => $bedId]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'message' => 'Bed is already occupied by another patient.'
            ]);
            exit;
        }

        // 4. Insert allocation record
        $ins = $pdo->prepare("
            INSERT INTO bed_allocations (bed_id, patient_id, attending_doctor_id, admitted_at, status)
            VALUES (:bid, :pid, :did, NOW(), 'Active')
        ");
        $ins->execute([':bid' => $bedId, ':pid' => $patientId, ':did' => $docId]);

        // Sync attending doctor to patient_doctor_assignments junction table
        if ($docId) {
            $pdaStmt = $pdo->prepare("
                INSERT INTO patient_doctor_assignments (patient_id, doctor_id, assigned_by, is_primary, status, assigned_at)
                VALUES (:pid, :did, :aid, 1, 'Active', NOW())
                ON DUPLICATE KEY UPDATE status = 'Active', is_primary = 1
            ");
            $pdaStmt->execute([':pid' => $patientId, ':did' => $docId, ':aid' => $actorId]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'status'  => 'success',
            'message' => "✓ {$patName} successfully allocated to Bed {$bed['bed_number']}."
        ]);
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Admin action allocate PDOException: " . $e->getMessage());

        if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
            if (str_contains($e->getMessage(), 'uq_active_bed')) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'status'  => 'error',
                    'message' => 'Bed is already occupied by another patient.'
                ]);
                exit;
            }
            if (str_contains($e->getMessage(), 'uq_active_patient')) {
                $lookup = $pdo->prepare("
                    SELECT b.bed_number 
                    FROM bed_allocations ba 
                    JOIN hospital_beds b ON ba.bed_id = b.bed_id 
                    WHERE ba.patient_id = :pid AND ba.status = 'Active' 
                    LIMIT 1
                ");
                $lookup->execute([':pid' => $patientId]);
                $activeBedNum = $lookup->fetchColumn() ?: 'Unknown';
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'status'  => 'error',
                    'message' => "Patient is currently admitted in Bed {$activeBedNum}. Please use Transfer Bed."
                ]);
                exit;
            }
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'Database operation error: ' . $e->getMessage()
        ]);
        exit;
    }
}

try {
    // 5. Target User Lookup
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, role, status FROM users WHERE user_id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Target personnel account not found.']);
        exit;
    }

    // Protect Admin accounts from any modification
    if ($targetUser['role'] === 'Admin') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Cannot modify root administrative accounts.']);
        exit;
    }

    // 6. Action Execution Pipeline
    if ($action === 'delete') {
        $delStmt = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
        $delStmt->execute([':id' => $userId]);

        echo json_encode([
            'status'    => 'success',
            'message'   => "Account for {$targetUser['full_name']} ({$targetUser['role']}) has been permanently deleted.",
            'user_id'   => $userId,
            'action'    => 'delete',
            'user_name' => $targetUser['full_name'],
            'user_role' => $targetUser['role']
        ]);
        exit;
    }

    $statusMap = [
        'approve'  => 'active',
        'activate' => 'active',
        'reject'   => 'rejected',
        'suspend'  => 'suspended'
    ];
    $newStatus = $statusMap[$action];

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
    $updateStmt->execute([
        ':status' => $newStatus,
        ':id'     => $userId
    ]);

    // Handle Doctor Credential Approval State & Audit Trail
    if ($targetUser['role'] === 'Doctor') {
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $bmdcLookup = $pdo->prepare("SELECT COALESCE(bmdc_reg_number, bmdc_license_number, '') FROM doctor_profiles WHERE user_id = :uid LIMIT 1");
        $bmdcLookup->execute([':uid' => $userId]);
        $bmdcNum = $bmdcLookup->fetchColumn() ?: 'N/A';

        if ($action === 'approve' || $action === 'activate') {
            $updDoc = $pdo->prepare("
                UPDATE doctor_profiles 
                SET approval_status = 'approved', 
                    approved_by = :aid, 
                    approved_at = NOW() 
                WHERE user_id = :uid
            ");
            $updDoc->execute([':aid' => $adminId, ':uid' => $userId]);

            // Log official audit entry
            try {
                $auditStmt = $pdo->prepare("
                    INSERT INTO audit_logs 
                        (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                    VALUES 
                        (:actor_id, 'Admin', 'DOCTOR_CREDENTIAL_APPROVED', 'Doctor Credential Approved', :desc, 'VERIFICATION', :target, :ip, 'INFO')
                ");
                $auditStmt->execute([
                    ':actor_id' => $adminId,
                    ':desc'     => "Doctor {$targetUser['full_name']} credentials verified and approved (BMDC: {$bmdcNum})",
                    ':target'   => "Doctor #{$userId} ({$bmdcNum})",
                    ':ip'       => $clientIp
                ]);
            } catch (Throwable $e) {
                // Non-blocking audit log
            }
        } elseif ($action === 'reject') {
            $updDoc = $pdo->prepare("
                UPDATE doctor_profiles 
                SET approval_status = 'rejected', 
                    approved_by = :aid, 
                    approved_at = NOW() 
                WHERE user_id = :uid
            ");
            $updDoc->execute([':aid' => $adminId, ':uid' => $userId]);

            // Log rejection audit entry
            try {
                $auditStmt = $pdo->prepare("
                    INSERT INTO audit_logs 
                        (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                    VALUES 
                        (:actor_id, 'Admin', 'DOCTOR_CREDENTIAL_REJECTED', 'Doctor Credential Rejected', :desc, 'VERIFICATION', :target, :ip, 'WARNING')
                ");
                $auditStmt->execute([
                    ':actor_id' => $adminId,
                    ':desc'     => "Doctor {$targetUser['full_name']} registration credentials declined (BMDC: {$bmdcNum})",
                    ':target'   => "Doctor #{$userId} ({$bmdcNum})",
                    ':ip'       => $clientIp
                ]);
            } catch (Throwable $e) {
                // Non-blocking audit log
            }
        }
    }

    $pdo->commit();

    $actionVerbs = [
        'approve'  => 'approved & authorized for portal access',
        'activate' => 'reactivated',
        'reject'   => 'declined',
        'suspend'  => 'suspended'
    ];
    $verb = $actionVerbs[$action];

    echo json_encode([
        'status'     => 'success',
        'message'    => "{$targetUser['full_name']} ({$targetUser['role']}) has been successfully {$verb}.",
        'user_id'    => $userId,
        'action'     => $action,
        'new_status' => $newStatus,
        'user_name'  => $targetUser['full_name'],
        'user_role'  => $targetUser['role']
    ]);
    exit;

} catch (PDOException $e) {
    error_log("Admin action PDOException: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database operation error. Please try again.']);
    exit;
}

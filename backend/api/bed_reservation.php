<?php
/**
 * MedPulse Enterprise Hospital Management System
 * ==========================================================================
 * Atomic Bed Reservation & Anti-Double-Booking Controller
 * File: backend/api/bed_reservation.php
 * ==========================================================================
 *
 * Actions (POST):
 *   reserve   – Place a 10-minute soft lock on a bed for a patient.
 *               Returns: reservation_token (used for confirm/release).
 *   confirm   – Convert a pending reservation into a real bed_allocations row
 *               (marks hospital_beds.status = 'Occupied').
 *   release   – Patient or system voluntarily releases the temporary lock.
 *   status    – Poll a single bed's current live state (GET).
 *   cleanup   – Release all expired reservations (called by cron / GET).
 *
 * Safety Guarantees:
 *   ① SELECT … FOR UPDATE row-level exclusive lock prevents two concurrent
 *     PHP processes from double-reserving the same bed.
 *   ② Conditional UPDATE with WHERE status = 'Available' + expiry guard
 *     provides atomic compare-and-swap semantics.
 *   ③ One active reservation per patient across ALL hospitals:
 *     a patient who already holds a 'Reserved' bed or 'Active' allocation
 *     cannot request a second one.
 *   ④ 10-minute TTL: if not confirmed within the window, the lock is
 *     automatically released by the PHP cleanup sweep.
 *
 * ==========================================================================
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure'   => $isHttps, 'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../Services/EventDispatcher.php';

use MedPulse\Services\EventDispatcher;

// ── Constants ────────────────────────────────────────────────────────────────
const RESERVATION_TTL_MINUTES = 10;
const RESERVATION_TTL_SECONDS = 600; // 10 × 60

// ── Routing ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = strtolower(trim(
    $method === 'GET'
        ? ($_GET['action']  ?? '')
        : ($_POST['action'] ?? (json_decode((string)file_get_contents('php://input'), true)['action'] ?? ''))
));

// Parse JSON body for POST requests
$jsonBody = [];
if ($method === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $jsonBody = (array)json_decode((string)file_get_contents('php://input'), true);
}
$postData = array_merge($_POST, $jsonBody);

// ── Auth: cleanup is public (cron), everything else requires patient session ──
if ($action !== 'cleanup' && $action !== 'status') {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['role']) || strtolower($_SESSION['role'] ?? '') !== 'patient') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authentication required. Patients only.']);
        exit;
    }
}

$patientId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$clientIp  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// ── Dispatch ─────────────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ====================================================================
        // ACTION: reserve
        // ====================================================================
        case 'reserve':
            handleReserve($pdo, $patientId, $postData, $clientIp);
            break;

        // ====================================================================
        // ACTION: confirm
        // ====================================================================
        case 'confirm':
            handleConfirm($pdo, $patientId, $postData, $clientIp);
            break;

        // ====================================================================
        // ACTION: release
        // ====================================================================
        case 'release':
            handleRelease($pdo, $patientId, $postData, $clientIp);
            break;

        // ====================================================================
        // ACTION: status  (GET – no session required)
        // ====================================================================
        case 'status':
            handleStatus($pdo, (int)($_GET['bed_id'] ?? 0), $patientId);
            break;

        // ====================================================================
        // ACTION: cleanup  (GET – cron endpoint, no auth)
        // ====================================================================
        case 'cleanup':
            handleCleanup($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Unknown action '{$action}'. Valid: reserve, confirm, release, status, cleanup.",
            ]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("BedReservation Error [{$action}]: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error. Please try again.',
        'debug'   => (ini_get('display_errors') === '1') ? $e->getMessage() : null,
    ]);
}

// ============================================================================
// FUNCTION: handleReserve
// ============================================================================
/**
 * Attempts to atomically place a 10-minute soft lock on the requested bed.
 *
 * Algorithm (all within a single InnoDB transaction):
 *   1. CLEANUP: sweep expired locks first (opportunistic, so no separate cron needed).
 *   2. GUARD:   check if this patient already holds any reservation or active bed.
 *   3. LOCK:    SELECT bed FOR UPDATE (exclusive row lock; blocks concurrent PHP forks).
 *   4. CHECK:   bed must be 'Available' with no live reservation.
 *   5. WRITE:   UPDATE hospital_beds status='Reserved', set TTL columns.
 *   6. WRITE:   INSERT bed_allocations with request_status='pending'.
 *   7. COMMIT.
 */
function handleReserve(PDO $pdo, int $patientId, array $data, string $ip): void
{
    $bedId = filter_var($data['bed_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$bedId || $bedId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'bed_id is required and must be a positive integer.']);
        return;
    }

    $pdo->beginTransaction();

    // ── 1. Sweep expired locks (opportunistic cleanup in same transaction) ──
    sweepExpiredLocks($pdo);

    // ── 2. One-booking-per-patient guard ────────────────────────────────────
    $existingGuard = $pdo->prepare("
        SELECT 'active_bed' AS reason, b.bed_id, b.bed_number, h.name AS hospital_name
        FROM hospital_beds b
        LEFT JOIN hospitals h ON b.hospital_id = h.hospital_id
        WHERE b.reservation_user_id = :pid
          AND b.status = 'Reserved'
          AND b.reserved_until > NOW()
        LIMIT 1
    ");
    $existingGuard->execute([':pid' => $patientId]);
    $existingReservation = $existingGuard->fetch(PDO::FETCH_ASSOC);

    if ($existingReservation) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'      => false,
            'error_code'   => 'ALREADY_RESERVED',
            'message'      => "You already hold a reservation for Bed {$existingReservation['bed_number']} at {$existingReservation['hospital_name']}. Cancel it first or wait for it to expire (10 min).",
            'existing_bed' => [
                'bed_id'        => (int)$existingReservation['bed_id'],
                'bed_number'    => $existingReservation['bed_number'],
                'hospital_name' => $existingReservation['hospital_name'],
            ],
        ]);
        return;
    }

    // Check for confirmed active allocation (patient already admitted)
    $activeAlloc = $pdo->prepare("
        SELECT ba.allocation_id, b.bed_number, h.name AS hospital_name
        FROM bed_allocations ba
        JOIN hospital_beds b ON ba.bed_id = b.bed_id
        LEFT JOIN hospitals h ON b.hospital_id = h.hospital_id
        WHERE ba.patient_id = :pid AND ba.status = 'Active'
        LIMIT 1
    ");
    $activeAlloc->execute([':pid' => $patientId]);
    $currentAdmission = $activeAlloc->fetch(PDO::FETCH_ASSOC);

    if ($currentAdmission) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'ALREADY_ADMITTED',
            'message'    => "You are currently admitted to Bed {$currentAdmission['bed_number']} at {$currentAdmission['hospital_name']}. Discharge is required before requesting a new bed.",
        ]);
        return;
    }

    // ── 3. Exclusive row lock on this specific bed ───────────────────────────
    $lockStmt = $pdo->prepare("
        SELECT
            bed_id, bed_number, ward_type, floor_number, price_per_day,
            status, reserved_until, reservation_user_id, hospital_id
        FROM hospital_beds
        WHERE bed_id = :bed_id
        FOR UPDATE
    ");
    $lockStmt->execute([':bed_id' => $bedId]);
    $bed = $lockStmt->fetch(PDO::FETCH_ASSOC);

    if (!$bed) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error_code' => 'BED_NOT_FOUND', 'message' => "Bed #{$bedId} does not exist."]);
        return;
    }

    // ── 4. Verify bed is truly free ──────────────────────────────────────────
    $isLocked = ($bed['status'] === 'Reserved'
                 && $bed['reserved_until'] !== null
                 && strtotime($bed['reserved_until']) > time());

    if ($bed['status'] === 'Occupied') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'BED_OCCUPIED',
            'message'    => "Bed {$bed['bed_number']} is currently occupied by another patient.",
            'bed_status' => 'Occupied',
        ]);
        return;
    }

    if ($bed['status'] === 'Maintenance') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'BED_MAINTENANCE',
            'message'    => "Bed {$bed['bed_number']} is under maintenance and unavailable.",
            'bed_status' => 'Maintenance',
        ]);
        return;
    }

    if ($isLocked && (int)$bed['reservation_user_id'] !== $patientId) {
        // Compute remaining time for user-friendly message
        $remainingSecs = strtotime($bed['reserved_until']) - time();
        $remainingMins = max(1, (int)ceil($remainingSecs / 60));

        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'        => false,
            'error_code'     => 'BED_UNDER_PROCESS',
            'message'        => "Bed {$bed['bed_number']} is temporarily held by another patient. It will be released in approximately {$remainingMins} minute(s) if not confirmed.",
            'bed_status'     => 'Holding',
            'expires_in_min' => $remainingMins,
        ]);
        return;
    }

    // ── 5. Place the soft lock ───────────────────────────────────────────────
    $token     = bin2hex(random_bytes(24)); // 48-char hex token
    $expiresAt = date('Y-m-d H:i:s', time() + RESERVATION_TTL_SECONDS);

    $updateBed = $pdo->prepare("
        UPDATE hospital_beds
        SET
            status              = 'Reserved',
            reserved_until      = :expires,
            reservation_user_id = :pid,
            reservation_token   = :token
        WHERE
            bed_id = :bid
            AND (
                status = 'Available'
                OR (status = 'Reserved' AND (reserved_until IS NULL OR reserved_until < NOW()))
            )
    ");
    $updateBed->execute([
        ':expires' => $expiresAt,
        ':pid'     => $patientId,
        ':token'   => $token,
        ':bid'     => $bedId,
    ]);

    if ($updateBed->rowCount() === 0) {
        // Someone else grabbed it between our SELECT FOR UPDATE and UPDATE — impossible
        // with FOR UPDATE, but guard it defensively
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'LOCK_RACE',
            'message'    => "Bed {$bed['bed_number']} was just taken by another request. Please choose a different bed.",
        ]);
        return;
    }

    // ── 6. Insert pending allocation record ──────────────────────────────────
    $insertAlloc = $pdo->prepare("
        INSERT INTO bed_allocations
            (bed_id, patient_id, admitted_at, status, request_status, reservation_token, expires_at)
        VALUES
            (:bid, :pid, NOW(), 'Active', 'pending', :token, :expires)
    ");
    $insertAlloc->execute([
        ':bid'     => $bedId,
        ':pid'     => $patientId,
        ':token'   => $token,
        ':expires' => $expiresAt,
    ]);
    $allocationId = (int)$pdo->lastInsertId();

    // ── Fetch hospital info for response ────────────────────────────────────
    $hospStmt = $pdo->prepare("SELECT name, city, contact_number FROM hospitals WHERE hospital_id = :hid LIMIT 1");
    $hospStmt->execute([':hid' => (int)$bed['hospital_id']]);
    $hospital = $hospStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Unknown', 'city' => '', 'contact_number' => ''];

    // ── Audit log ────────────────────────────────────────────────────────────
    EventDispatcher::pushAuditLog(
        $pdo, $patientId, 'BED_RESERVED',
        "Patient #{$patientId} reserved Bed {$bed['bed_number']} ({$bed['ward_type']}) at {$hospital['name']}. TTL: 10 min. Token: {$token}",
        'PATIENT_ADMISSION', 'Bed Reserved', "Bed #{$bedId}", $ip
    );

    $pdo->commit();

    echo json_encode([
        'success'           => true,
        'action'            => 'reserved',
        'reservation_token' => $token,
        'allocation_id'     => $allocationId,
        'expires_at'        => $expiresAt,
        'expires_in_sec'    => RESERVATION_TTL_SECONDS,
        'bed'               => [
            'bed_id'       => (int)$bed['bed_id'],
            'bed_number'   => $bed['bed_number'],
            'ward_type'    => $bed['ward_type'],
            'floor_number' => (int)$bed['floor_number'],
            'price_per_day'=> (float)$bed['price_per_day'],
            'status'       => 'Reserved',
        ],
        'hospital' => [
            'hospital_id'    => (int)$bed['hospital_id'],
            'hospital_name'  => $hospital['name'],
            'city'           => $hospital['city'],
            'contact_number' => $hospital['contact_number'],
        ],
        'message' => "Bed {$bed['bed_number']} is now held for you for " . RESERVATION_TTL_MINUTES . " minutes. Confirm your admission request to secure it.",
    ]);
}

// ============================================================================
// FUNCTION: handleConfirm
// ============================================================================
/**
 * Converts a pending soft-lock into a real confirmed admission.
 *   1. Validate token + expiry (still within window).
 *   2. FOR UPDATE lock bed + pending allocation row.
 *   3. Promote: hospital_beds.status = 'Occupied', clear lock columns.
 *   4. Promote: bed_allocations.request_status = 'confirmed'.
 *   5. Dispatch BED_RESERVATION_CONFIRMED notification.
 */
function handleConfirm(PDO $pdo, int $patientId, array $data, string $ip): void
{
    $token        = trim((string)($data['reservation_token'] ?? ''));
    $allocationId = filter_var($data['allocation_id'] ?? null, FILTER_VALIDATE_INT);

    if (empty($token) || !$allocationId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'reservation_token and allocation_id are required.']);
        return;
    }

    $pdo->beginTransaction();

    // Lock and fetch the pending allocation
    $allocStmt = $pdo->prepare("
        SELECT ba.allocation_id, ba.bed_id, ba.patient_id, ba.expires_at,
               ba.request_status, ba.reservation_token
        FROM bed_allocations ba
        WHERE ba.allocation_id = :aid
          AND ba.patient_id    = :pid
          AND ba.reservation_token = :token
        FOR UPDATE
    ");
    $allocStmt->execute([':aid' => $allocationId, ':pid' => $patientId, ':token' => $token]);
    $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC);

    if (!$alloc) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'success'    => false,
            'error_code' => 'RESERVATION_NOT_FOUND',
            'message'    => 'Reservation not found or does not belong to your account.',
        ]);
        return;
    }

    if ($alloc['request_status'] === 'confirmed') {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'action' => 'already_confirmed', 'message' => 'This reservation is already confirmed.']);
        return;
    }

    if ($alloc['request_status'] === 'expired' || ($alloc['expires_at'] && strtotime($alloc['expires_at']) < time())) {
        $pdo->rollBack();
        http_response_code(410);
        echo json_encode([
            'success'    => false,
            'error_code' => 'RESERVATION_EXPIRED',
            'message'    => 'Your 10-minute reservation window has expired. Please select the bed again to restart.',
        ]);
        return;
    }

    if ($alloc['request_status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'RESERVATION_INVALID_STATE',
            'message'    => "Reservation is in '{$alloc['request_status']}' state and cannot be confirmed.",
        ]);
        return;
    }

    $bedId = (int)$alloc['bed_id'];

    // Lock the bed row
    $bedStmt = $pdo->prepare("SELECT bed_id, bed_number, ward_type, floor_number, price_per_day, status, reservation_user_id, hospital_id FROM hospital_beds WHERE bed_id = :bid FOR UPDATE");
    $bedStmt->execute([':bid' => $bedId]);
    $bed = $bedStmt->fetch(PDO::FETCH_ASSOC);

    if (!$bed) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error_code' => 'BED_NOT_FOUND', 'message' => "Bed #{$bedId} not found."]);
        return;
    }

    // Verify the lock is still owned by this patient
    if ((int)$bed['reservation_user_id'] !== $patientId) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'TOKEN_MISMATCH',
            'message'    => 'Bed reservation ownership mismatch. Please restart the booking process.',
        ]);
        return;
    }

    // Promote bed: Reserved → Occupied, clear lock columns
    $pdo->prepare("
        UPDATE hospital_beds
        SET status              = 'Occupied',
            reserved_until      = NULL,
            reservation_user_id = NULL,
            reservation_token   = NULL
        WHERE bed_id = :bid
          AND reservation_user_id = :pid
    ")->execute([':bid' => $bedId, ':pid' => $patientId]);

    // Promote allocation: pending → confirmed
    $pdo->prepare("
        UPDATE bed_allocations
        SET request_status    = 'confirmed',
            reservation_token = NULL,
            expires_at        = NULL,
            admitted_at       = NOW()
        WHERE allocation_id = :aid
    ")->execute([':aid' => $allocationId]);

    // Notify patient
    $hospStmt = $pdo->prepare("SELECT name FROM hospitals WHERE hospital_id = :hid LIMIT 1");
    $hospStmt->execute([':hid' => (int)$bed['hospital_id']]);
    $hospName = $hospStmt->fetchColumn() ?: 'Hospital';

    EventDispatcher::dispatch(
        $pdo,
        'BED_RESERVATION_CONFIRMED',
        'Admission Confirmed — Bed ' . $bed['bed_number'],
        "Your admission to {$hospName} has been confirmed. Bed: {$bed['bed_number']} ({$bed['ward_type']}), Floor {$bed['floor_number']}. Please proceed to reception.",
        [['id' => $patientId, 'type' => 'PATIENT']],
        [
            'allocation_id'  => $allocationId,
            'bed_id'         => $bedId,
            'bed_number'     => $bed['bed_number'],
            'ward_type'      => $bed['ward_type'],
            'floor_number'   => (int)$bed['floor_number'],
            'hospital_name'  => $hospName,
            'price_per_day'  => (float)$bed['price_per_day'],
            'confirmed_at'   => date('Y-m-d H:i:s'),
        ]
    );

    EventDispatcher::pushAuditLog(
        $pdo, $patientId, 'BED_CONFIRMED',
        "Patient #{$patientId} confirmed Bed {$bed['bed_number']} ({$bed['ward_type']}) at {$hospName}.",
        'PATIENT_ADMISSION', 'Bed Confirmed', "Bed #{$bedId}", $ip
    );

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'action'        => 'confirmed',
        'allocation_id' => $allocationId,
        'bed' => [
            'bed_id'       => $bedId,
            'bed_number'   => $bed['bed_number'],
            'ward_type'    => $bed['ward_type'],
            'floor_number' => (int)$bed['floor_number'],
            'price_per_day'=> (float)$bed['price_per_day'],
            'status'       => 'Occupied',
        ],
        'hospital_name' => $hospName,
        'message'       => "✓ Admission confirmed for Bed {$bed['bed_number']} at {$hospName}. Please report to reception.",
    ]);
}

// ============================================================================
// FUNCTION: handleRelease
// ============================================================================
/**
 * Voluntarily releases a pending reservation before expiry.
 * Requires the reservation_token to prevent spoofed releases.
 */
function handleRelease(PDO $pdo, int $patientId, array $data, string $ip): void
{
    $token        = trim((string)($data['reservation_token'] ?? ''));
    $allocationId = filter_var($data['allocation_id'] ?? null, FILTER_VALIDATE_INT);

    if (empty($token) || !$allocationId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'reservation_token and allocation_id are required.']);
        return;
    }

    $pdo->beginTransaction();

    // Lock the allocation row
    $allocStmt = $pdo->prepare("
        SELECT allocation_id, bed_id, patient_id, request_status
        FROM bed_allocations
        WHERE allocation_id      = :aid
          AND patient_id         = :pid
          AND reservation_token  = :token
        FOR UPDATE
    ");
    $allocStmt->execute([':aid' => $allocationId, ':pid' => $patientId, ':token' => $token]);
    $alloc = $allocStmt->fetch(PDO::FETCH_ASSOC);

    if (!$alloc) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Reservation not found or already released.']);
        return;
    }

    if ($alloc['request_status'] === 'confirmed') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success'    => false,
            'error_code' => 'ALREADY_CONFIRMED',
            'message'    => 'This reservation is already confirmed. Contact the hospital to cancel your admission.',
        ]);
        return;
    }

    $bedId = (int)$alloc['bed_id'];

    // Lock bed row
    $bedStmt = $pdo->prepare("SELECT bed_id, bed_number, reservation_user_id FROM hospital_beds WHERE bed_id = :bid FOR UPDATE");
    $bedStmt->execute([':bid' => $bedId]);
    $bed = $bedStmt->fetch(PDO::FETCH_ASSOC);

    // Release bed lock only if still owned by this patient
    if ($bed && (int)$bed['reservation_user_id'] === $patientId) {
        $pdo->prepare("
            UPDATE hospital_beds
            SET status = 'Available', reserved_until = NULL, reservation_user_id = NULL, reservation_token = NULL
            WHERE bed_id = :bid AND reservation_user_id = :pid
        ")->execute([':bid' => $bedId, ':pid' => $patientId]);
    }

    // Cancel the allocation row
    $pdo->prepare("
        UPDATE bed_allocations
        SET request_status = 'cancelled', reservation_token = NULL, expires_at = NULL, discharged_at = NOW()
        WHERE allocation_id = :aid
    ")->execute([':aid' => $allocationId]);

    EventDispatcher::pushAuditLog(
        $pdo, $patientId, 'BED_RELEASED',
        "Patient #{$patientId} voluntarily released reservation for Bed {$bed['bed_number']} (allocation #{$allocationId}).",
        'PATIENT_ADMISSION', 'Reservation Released', "Bed #{$bedId}", $ip
    );

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'action'  => 'released',
        'bed_id'  => $bedId,
        'message' => 'Your reservation has been cancelled. The bed is now available to other patients.',
    ]);
}

// ============================================================================
// FUNCTION: handleStatus
// ============================================================================
/**
 * Returns live status of a single bed — no auth required (read-only).
 */
function handleStatus(PDO $pdo, int $bedId, int $viewerId): void
{
    if ($bedId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'bed_id query parameter is required.']);
        return;
    }

    // Sweep expired locks first (so status is always fresh)
    sweepExpiredLocks($pdo);

    $stmt = $pdo->prepare("
        SELECT
            b.bed_id, b.bed_number, b.ward_type, b.floor_number,
            b.price_per_day, b.status, b.reserved_until, b.reservation_user_id,
            h.name AS hospital_name, h.hospital_id
        FROM hospital_beds b
        LEFT JOIN hospitals h ON b.hospital_id = h.hospital_id
        WHERE b.bed_id = :bid
        LIMIT 1
    ");
    $stmt->execute([':bid' => $bedId]);
    $bed = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bed) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Bed #{$bedId} not found."]);
        return;
    }

    // Determine effective display status
    $isActiveReservation = (
        $bed['status'] === 'Reserved'
        && $bed['reserved_until'] !== null
        && strtotime($bed['reserved_until']) > time()
    );

    $displayStatus = $bed['status'];
    $heldByMe      = false;
    $expiresInSec  = null;

    if ($isActiveReservation) {
        $displayStatus = 'Holding'; // UI-friendly label
        $expiresInSec  = strtotime($bed['reserved_until']) - time();
        $heldByMe      = ($viewerId > 0 && (int)$bed['reservation_user_id'] === $viewerId);
    }

    echo json_encode([
        'success' => true,
        'bed'     => [
            'bed_id'        => (int)$bed['bed_id'],
            'bed_number'    => $bed['bed_number'],
            'ward_type'     => $bed['ward_type'],
            'floor_number'  => (int)$bed['floor_number'],
            'price_per_day' => (float)$bed['price_per_day'],
            'status'        => $displayStatus,
            'held_by_me'    => $heldByMe,
            'expires_in_sec'=> $expiresInSec,
            'reserved_until'=> $bed['reserved_until'],
            'hospital_name' => $bed['hospital_name'],
            'hospital_id'   => (int)$bed['hospital_id'],
        ],
    ]);
}

// ============================================================================
// FUNCTION: handleCleanup  (cron / event-scheduler fallback)
// ============================================================================
function handleCleanup(PDO $pdo): void
{
    $released = sweepExpiredLocks($pdo);
    echo json_encode([
        'success'        => true,
        'action'         => 'cleanup',
        'beds_released'  => $released['beds'],
        'allocs_expired' => $released['allocs'],
        'swept_at'       => date('Y-m-d H:i:s'),
    ]);
}

// ============================================================================
// HELPER: sweepExpiredLocks
// ============================================================================
/**
 * Atomically releases all expired bed reservations.
 * Called opportunistically on every reserve/status action so that even
 * without a cron job, stale locks are cleared during normal traffic.
 *
 * @return array{beds:int,allocs:int}
 */
function sweepExpiredLocks(PDO $pdo): array
{
    // Expire pending allocations
    $allocSweep = $pdo->prepare("
        UPDATE bed_allocations
        SET request_status = 'expired'
        WHERE request_status = 'pending'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
    ");
    $allocSweep->execute();
    $allocsExpired = $allocSweep->rowCount();

    // Release bed locks
    $bedSweep = $pdo->prepare("
        UPDATE hospital_beds
        SET status              = 'Available',
            reserved_until      = NULL,
            reservation_user_id = NULL,
            reservation_token   = NULL
        WHERE status         = 'Reserved'
          AND reserved_until IS NOT NULL
          AND reserved_until < NOW()
    ");
    $bedSweep->execute();
    $bedsReleased = $bedSweep->rowCount();

    return ['beds' => $bedsReleased, 'allocs' => $allocsExpired];
}

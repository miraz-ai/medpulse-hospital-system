<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Emergency Protocol & Surge Triage API Controller
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../Services/EmergencyProtocolService.php';

use MedPulse\Services\EmergencyProtocolService;

$service = new EmergencyProtocolService($pdo);

$action = $_POST['_action'] ?? $_GET['_action'] ?? '';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// ── GET: Get active emergency protocol ────────────────────────────────────────
if ($action === 'get_active_emergency') {
    try {
        $protocols = $service->getActiveProtocols();
        $proto = !empty($protocols) ? $protocols[0] : null;
        echo json_encode([
            'success'   => true,
            'protocol'  => $proto,
            'protocols' => $protocols,
            'count'     => count($protocols)
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── GET / POST: Calculate live preview counter ────────────────────────────────
if ($action === 'preview_surge') {
    $protoCode = trim($_POST['protocol_code'] ?? $_GET['protocol_code'] ?? 'DENGUE_EPIDEMIC');
    $quotaPct  = max(10, min(50, (int)($_POST['quota_pct'] ?? $_GET['quota_pct'] ?? 10)));
    $rawHosp   = $_POST['target_hospitals'] ?? $_GET['target_hospitals'] ?? [];
    
    $targetHospitalIds = [];
    if (is_array($rawHosp)) {
        $targetHospitalIds = array_map('intval', $rawHosp);
    } elseif (is_string($rawHosp) && !empty($rawHosp)) {
        $targetHospitalIds = array_map('intval', explode(',', $rawHosp));
    }

    try {
        $preview = $service->calculatePreview($protoCode, $quotaPct, $targetHospitalIds);
        echo json_encode(['success' => true, 'preview' => $preview]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── All mutating actions require CSRF validation ──────────────────────────────
$submittedToken = trim($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh page.']);
    exit;
}

$userRole = strtolower($_SESSION['role'] ?? '');
$userId   = (int)($_SESSION['user_id'] ?? 0);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

// ── Super Admin: Declare National Emergency ──────────────────────────────────
if ($action === 'declare_emergency') {
    if ($userRole !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only Super Admin can declare National Emergency.']);
        exit;
    }

    $protoCode = trim($_POST['protocol_code'] ?? '');
    $quotaPct  = max(10, min(50, (int)($_POST['quota_pct'] ?? 20)));
    $notes     = trim($_POST['notes'] ?? '');
    $scope     = trim($_POST['target_scope'] ?? 'TARGETED');
    $rawHosp   = $_POST['target_hospitals'] ?? [];

    $targetHospitalIds = [];
    if (is_array($rawHosp)) {
        $targetHospitalIds = array_map('intval', $rawHosp);
    } elseif (is_string($rawHosp) && !empty($rawHosp)) {
        $targetHospitalIds = array_map('intval', explode(',', $rawHosp));
    }

    // Determine severity label from quota
    $severityLevel = match(true) {
        $quotaPct >= 50 => 'National Crisis',
        $quotaPct >= 35 => 'Severe',
        $quotaPct >= 20 => 'Moderate',
        default         => 'Alert',
    };

    try {
        $result = $service->declareEmergency(
            $protoCode,
            $quotaPct,
            $severityLevel,
            $scope,
            $targetHospitalIds,
            $userId,
            $clientIp,
            $notes
        );
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to declare emergency: ' . $e->getMessage()]);
    }
    exit;
}

// ── Super Admin: Stand-Down & Restore Normal Operations ───────────────────────
if ($action === 'terminate_emergency') {
    if ($userRole !== 'super_admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only Super Admin can terminate National Emergency.']);
        exit;
    }

    $protocolId = isset($_POST['protocol_id']) && is_numeric($_POST['protocol_id']) && (int)$_POST['protocol_id'] > 0
        ? (int)$_POST['protocol_id'] 
        : null;

    try {
        $result = $service->standDownEmergency($protocolId, $userId, $clientIp);
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Stand-down failed: ' . $e->getMessage()]);
    }
    exit;
}

// ── Branch Admin / Super Admin: 1-Click Evacuate & Transfer Patient ───────────
if ($action === 'evacuate_patient') {
    if (!in_array($userRole, ['super_admin', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied.']);
        exit;
    }

    $bedId = (int)($_POST['bed_id'] ?? 0);
    if ($bedId < 1) {
        echo json_encode(['success' => false, 'message' => 'Valid bed ID required.']);
        exit;
    }

    // Branch Admin can only evacuate beds in their assigned hospital
    if ($userRole === 'admin') {
        $adminHid = (int)($_SESSION['hospital_id'] ?? 1);
        $check = $pdo->prepare("SELECT hospital_id FROM hospital_beds WHERE bed_id = ?");
        $check->execute([$bedId]);
        $bedHid = (int)$check->fetchColumn();
        if ($bedHid !== $adminHid) {
            echo json_encode(['success' => false, 'message' => 'Cannot evacuate bed from another branch facility.']);
            exit;
        }
    }

    try {
        $result = $service->evacuateAndTransferPatient($bedId, $userId, $clientIp);
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Evacuation failed: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);

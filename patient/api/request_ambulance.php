<?php
/**
 * MedPulse Enterprise HMS — Emergency Branch Ambulance Dispatch API
 * Dispatches an emergency ambulance request to the selected hospital branch fleet.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../includes/patient_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Validate CSRF token
$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid or expired. Please refresh.']);
    exit;
}

$patientUserId = (int)($_SESSION['user_id'] ?? 0);
if ($patientUserId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized patient session.']);
    exit;
}

try {
    // 1. Fetch Patient Record
    $patStmt = $pdo->prepare("SELECT id, user_id, patient_uid, full_name, phone, blood_group FROM patients WHERE user_id = ? LIMIT 1");
    $patStmt->execute([$patientUserId]);
    $patient = $patStmt->fetch(PDO::FETCH_ASSOC);

    $patientName = $patient['full_name'] ?? ($_SESSION['user_name'] ?? 'Registered Patient');
    $patientUid  = $patient['patient_uid'] ?? ('MP-P' . str_pad((string)$patientUserId, 5, '0', STR_PAD_LEFT));
    $contactPhone = trim($_POST['contact_phone'] ?? ($patient['phone'] ?? ''));

    if (empty($contactPhone)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid contact phone number is required for dispatch response.']);
        exit;
    }

    // 2. Fetch Selected Hospital Branch
    $hospitalId = (int)($_POST['hospital_id'] ?? 1);
    $hospStmt = $pdo->prepare("SELECT id, hospital_id, name, city, contact_number, address FROM hospitals WHERE hospital_id = ? OR id = ? LIMIT 1");
    $hospStmt->execute([$hospitalId, $hospitalId]);
    $hospital = $hospStmt->fetch(PDO::FETCH_ASSOC);

    if (!$hospital) {
        $hospital = [
            'id' => 1,
            'hospital_id' => 1,
            'name' => 'MedPulse Hospital & Specialty Care',
            'city' => 'Dhaka',
            'contact_number' => '+880-2-9881234',
            'address' => 'Road 4, Dhanmondi, Dhaka-1212'
        ];
    }

    $pickupAddress = trim($_POST['pickup_address'] ?? 'Patient registered residential address');
    if ($pickupAddress === '') {
        $pickupAddress = 'Registered Residence / Emergency Location Dhaka';
    }
    $urgencyLevel = trim($_POST['urgency_level'] ?? 'Critical / Immediate Dispatch');
    $notes = trim($_POST['notes'] ?? '');

    $dispatchId = 'AMB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    // 3. Insert notification for Branch Clinical / Emergency Staff
    $notifTitle = "🚨 EMERGENCY AMBULANCE DISPATCH: " . htmlspecialchars($patientName);
    $notifMsg   = "URGENT DISPATCH [{$dispatchId}]: Patient {$patientName} ({$patientUid}, Tel: {$contactPhone}) requested branch fleet ambulance at: {$pickupAddress}. Acuity: {$urgencyLevel}. Notes: " . ($notes ?: 'Immediate deployment requested.');

    $metadata = json_encode([
        'dispatch_id'    => $dispatchId,
        'patient_user_id' => $patientUserId,
        'patient_name'   => $patientName,
        'patient_uid'    => $patientUid,
        'contact_phone'  => $contactPhone,
        'hospital_id'    => (int)($hospital['hospital_id'] ?? $hospital['id']),
        'hospital_name'  => $hospital['name'],
        'pickup_address' => $pickupAddress,
        'urgency_level'  => $urgencyLevel,
        'notes'          => $notes,
        'dispatched_at'  => date('Y-m-d H:i:s')
    ]);

    // Notify ADMIN and PATIENT
    $insNotif = $pdo->prepare("
        INSERT INTO notifications (recipient_id, recipient_type, title, message, event_type, metadata, is_read, created_at)
        VALUES (:rid, :rtype, :title, :message, 'EMERGENCY_AMBULANCE_DISPATCH', :meta, 0, NOW())
    ");
    // Notify ADMIN / Hospital Fleet Control
    $insNotif->execute([
        ':rid'     => (int)($hospital['hospital_id'] ?? $hospital['id']),
        ':rtype'   => 'ADMIN',
        ':title'   => $notifTitle,
        ':message' => $notifMsg,
        ':meta'    => $metadata
    ]);
    // Notify PATIENT record
    $insNotif->execute([
        ':rid'     => $patientUserId,
        ':rtype'   => 'PATIENT',
        ':title'   => "Ambulance Dispatched: {$dispatchId}",
        ':message' => "Your emergency ambulance dispatch order [{$dispatchId}] for {$hospital['name']} has been queued. Priority: {$urgencyLevel}.",
        ':meta'    => $metadata
    ]);

    // 4. Record in Audit Logs
    try {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs 
                (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level, target_hospital_id, created_at)
            VALUES 
                (:aid, 'patient', 'EMERGENCY_AMBULANCE_DISPATCH', 'EMERGENCY_AMBULANCE_DISPATCH', :desc, 'EMERGENCY', 'ambulance_fleet', :ip, 'CRITICAL', :hid, NOW())
        ");
        $auditStmt->execute([
            ':aid'  => $patientUserId,
            ':desc' => "Emergency branch ambulance dispatch {$dispatchId} requested by {$patientName} to {$hospital['name']}",
            ':ip'   => $clientIp,
            ':hid'  => (int)($hospital['hospital_id'] ?? $hospital['id'])
        ]);
    } catch (Throwable $e) {
        error_log("Failed to insert emergency dispatch audit log: " . $e->getMessage());
    }

    echo json_encode([
        'success'        => true,
        'message'        => 'Emergency ambulance dispatch request transmitted to active hospital fleet coordinator.',
        'dispatch_id'    => $dispatchId,
        'patient_name'   => $patientName,
        'contact_phone'  => $contactPhone,
        'hospital_name'  => $hospital['name'],
        'hospital_phone' => $hospital['contact_number'],
        'pickup_address' => $pickupAddress,
        'urgency_level'  => $urgencyLevel,
        'dispatched_at'  => date('d M Y, h:i A')
    ]);

} catch (Throwable $e) {
    error_log("Ambulance dispatch error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Emergency service dispatch encountered an error. Please dial 999 or call the hospital directly.'
    ]);
}

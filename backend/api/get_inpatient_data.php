<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Inpatient & Care Teams Directory API
 * 
 * Returns all currently admitted patients with active bed and doctor details.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';

// RBAC Guard
if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

try {
    // 1. Fetch admitted patients with active bed
    $sql = "
        SELECT 
            ba.allocation_id,
            ba.admitted_at,
            u.user_id AS patient_id,
            u.full_name AS patient_name,
            u.email AS patient_email,
            u.phone AS patient_phone,
            u.gender,
            u.age,
            u.blood_group,
            b.bed_id,
            b.bed_number,
            b.ward_type,
            b.floor_number,
            b.daily_rate,
            b.status AS bed_status
        FROM bed_allocations ba
        JOIN users u ON ba.patient_id = u.user_id
        JOIN hospital_beds b ON ba.bed_id = b.bed_id
        WHERE ba.status = 'Active'
        ORDER BY ba.admitted_at DESC
    ";
    $patients = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch assigned doctors for all active patients
    $docSql = "
        SELECT 
            pda.assignment_id,
            pda.patient_id,
            pda.doctor_id,
            pda.is_primary,
            pda.assigned_at,
            doc.full_name AS doctor_name,
            doc.email AS doctor_email,
            dp.specialty,
            dp.bmdc_license_number
        FROM patient_doctor_assignments pda
        JOIN users doc ON pda.doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
        WHERE pda.status = 'Active'
        ORDER BY pda.is_primary DESC, doc.full_name ASC
    ";
    $assignments = $pdo->query($docSql)->fetchAll(PDO::FETCH_ASSOC);

    $doctorsByPatient = [];
    foreach ($assignments as $a) {
        $pid = (int)$a['patient_id'];
        if (!isset($doctorsByPatient[$pid])) {
            $doctorsByPatient[$pid] = [];
        }
        $doctorsByPatient[$pid][] = [
            'assignment_id'       => (int)$a['assignment_id'],
            'doctor_id'           => (int)$a['doctor_id'],
            'doctor_name'         => $a['doctor_name'],
            'doctor_email'        => $a['doctor_email'],
            'specialty'           => $a['specialty'] ?? 'General Medicine',
            'is_primary'          => (bool)$a['is_primary'],
            'bmdc_license_number' => $a['bmdc_license_number'] ?? 'N/A'
        ];
    }

    // Combine
    foreach ($patients as &$p) {
        $pid = (int)$p['patient_id'];
        $p['assigned_doctors'] = $doctorsByPatient[$pid] ?? [];
    }
    unset($p);

    echo json_encode([
        'success'  => true,
        'count'    => count($patients),
        'patients' => $patients
    ]);

} catch (Throwable $e) {
    error_log("Get Inpatient Data Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load inpatient data.']);
}

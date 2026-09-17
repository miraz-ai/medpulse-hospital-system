<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Available Beds Lookup API
 * 
 * Returns only currently Available beds for transfer or new allocation,
 * optionally filtered by ward type.
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

$wardFilter = trim((string)($_GET['ward'] ?? ''));

try {
    if ($wardFilter !== '' && $wardFilter !== 'all') {
        $stmt = $pdo->prepare("
            SELECT bed_id, bed_number, ward_type, floor_number, daily_rate, status
            FROM hospital_beds
            WHERE status = 'Available' AND ward_type = :ward
            ORDER BY floor_number ASC, bed_number ASC
        ");
        $stmt->execute([':ward' => $wardFilter]);
    } else {
        $stmt = $pdo->query("
            SELECT bed_id, bed_number, ward_type, floor_number, daily_rate, status
            FROM hospital_beds
            WHERE status = 'Available'
            ORDER BY ward_type ASC, floor_number ASC, bed_number ASC
        ");
    }

    $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'count'      => count($beds),
        'beds'       => $beds
    ]);

} catch (Throwable $e) {
    error_log("Get Available Beds Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve available beds.']);
}

<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Branch Invoices Module
 *
 * Enforces strict multi-branch data isolation for invoice viewing and management.
 */

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/db.php';

$sessionHospitalId = (int)($_SESSION['hospital_id'] ?? 1);

// Direct URL access check: ?invoice_id=XYZ
if (!empty($_GET['invoice_id'])) {
    $invId = filter_var($_GET['invoice_id'], FILTER_VALIDATE_INT);
    if ($invId) {
        $stmt = $pdo->prepare("SELECT invoice_id, hospital_id FROM invoices WHERE invoice_id = ? LIMIT 1");
        $stmt->execute([$invId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($inv && (int)$inv['hospital_id'] !== $sessionHospitalId) {
            http_response_code(403);
            exit('Access Denied: Record belongs to another facility.');
        }
    }
}

require_once __DIR__ . '/billing_management.php';

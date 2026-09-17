<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Automated Central Treasury Billing Pipeline on Inpatient Discharge
 * Location: admin/discharge_patient_action.php
 * 
 * Atomically:
 * 1. Computes inpatient stay duration (nights) & facility bed charges
 * 2. Resolves primary attending physician credentials & consultation fee
 * 3. Auto-generates centralized official invoice record (INV-2026-XXXX) with 5% VAT
 * 4. Inserts itemized statements for Bed Facility Stay and Attending Consultation
 * 5. Credits doctor_earnings ledger with pending_hospital_collection status
 * 6. Releases bed back to 'Available' and closes admission cycle
 * 7. Dispatches multi-channel notifications and logs immutable audit records
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

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';
require_once __DIR__ . '/../backend/Services/EventDispatcher.php';

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

// 3. CSRF Gatekeeper
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token invalid. Please refresh the page.']);
    exit;
}

$actorId  = (int)$_SESSION['user_id'];
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// 4. Input Parsing (Supports patient_id, bed_id, or allocation_id)
$patientId    = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);
$bedId        = filter_var($_POST['bed_id'] ?? null, FILTER_VALIDATE_INT);
$allocationId = filter_var($_POST['allocation_id'] ?? null, FILTER_VALIDATE_INT);
$dischargeSummary = trim((string)($_POST['summary'] ?? 'Clinical discharge approved. Inpatient treatment cycle completed.'));

if (!$patientId && !$bedId && !$allocationId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Valid patient_id, bed_id, or allocation_id is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 5. Fetch Active Bed Allocation with Row-Lock FOR UPDATE
    $query = "
        SELECT ba.allocation_id, ba.bed_id, ba.patient_id, ba.attending_doctor_id, ba.admitted_at,
               b.bed_number, b.ward_type, b.floor_number, b.daily_rate, b.status AS bed_status,
               u.full_name AS patient_name, u.email AS patient_email, u.phone AS patient_phone
        FROM bed_allocations ba
        JOIN hospital_beds b ON ba.bed_id = b.bed_id
        JOIN users u ON ba.patient_id = u.user_id
        WHERE ba.status = 'Active'
    ";
    $params = [];

    if ($allocationId) {
        $query .= " AND ba.allocation_id = :aid";
        $params[':aid'] = $allocationId;
    } elseif ($patientId) {
        $query .= " AND ba.patient_id = :pid";
        $params[':pid'] = $patientId;
    } elseif ($bedId) {
        $query .= " AND ba.bed_id = :bid";
        $params[':bid'] = $bedId;
    }
    $query .= " LIMIT 1 FOR UPDATE";

    $allocStmt = $pdo->prepare($query);
    $allocStmt->execute($params);
    $activeAlloc = $allocStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeAlloc) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No active inpatient admission found matching the specified parameters.']);
        exit;
    }

    $targetPatientId = (int)$activeAlloc['patient_id'];
    $targetBedId     = (int)$activeAlloc['bed_id'];
    $targetAllocId   = (int)$activeAlloc['allocation_id'];
    $patientName     = $activeAlloc['patient_name'];
    $bedNumber       = $activeAlloc['bed_number'];
    $wardType        = $activeAlloc['ward_type'];

    // 6. Calculate Stay Duration & Facility Bed Charges
    $admittedAt = $activeAlloc['admitted_at'];
    $dischargedAt = date('Y-m-d H:i:s');
    $admitDt = new DateTime($admittedAt);
    $dischDt = new DateTime($dischargedAt);
    $diffSeconds = max(0, $dischDt->getTimestamp() - $admitDt->getTimestamp());
    
    // In hospital billing, inpatient care is calculated as minimum 1 night/day
    $nights = max(1, (int)ceil($diffSeconds / 86400));
    
    $bedDailyRate = (float)$activeAlloc['daily_rate'];
    if ($bedDailyRate <= 0) {
        $wardRates = [
            'Emergency'           => 1500.00,
            'General Ward Male'   => 800.00,
            'General Ward Female' => 800.00,
            'Pediatrics'          => 1000.00,
            'Semi-Cabin'          => 2500.00,
            'Deluxe Cabin'        => 4500.00,
            'VIP Suite'           => 8500.00,
            'Presidential Suite'  => 15000.00,
            'ICU'                 => 12000.00,
            'CCU'                 => 10000.00,
            'NICU'                => 9000.00,
            'Recovery'            => 3000.00,
        ];
        $bedDailyRate = $wardRates[$wardType] ?? 1200.00;
    }
    $totalBedCharge = round($nights * $bedDailyRate, 2);

    // 7. Resolve Primary Attending Physician & Consultation Fee
    $docUserId = (int)($activeAlloc['attending_doctor_id'] ?? 0);
    if ($docUserId <= 0) {
        $pdaStmt = $pdo->prepare("
            SELECT doctor_id 
            FROM patient_doctor_assignments 
            WHERE patient_id = :pid AND status = 'Active' 
            ORDER BY is_primary DESC, assignment_id DESC 
            LIMIT 1
        ");
        $pdaStmt->execute([':pid' => $targetPatientId]);
        $docUserId = (int)$pdaStmt->fetchColumn();
    }

    $consultationFee = 1200.00; // Default consultation baseline
    $docDisplayTitle = 'Attending Specialist Consultant';
    $hasDoctor = false;

    if ($docUserId > 0) {
        $docQuery = $pdo->prepare("
            SELECT u.user_id, u.full_name, dp.doctor_id, dp.specialty, dp.designation, dp.military_rank, dp.qualifications, dp.consultation_fee
            FROM users u
            LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
            WHERE u.user_id = :uid
            LIMIT 1
        ");
        $docQuery->execute([':uid' => $docUserId]);
        $doctorProfile = $docQuery->fetch(PDO::FETCH_ASSOC);

        if ($doctorProfile) {
            $hasDoctor = true;
            if (isset($doctorProfile['consultation_fee']) && (float)$doctorProfile['consultation_fee'] > 0) {
                $consultationFee = (float)$doctorProfile['consultation_fee'];
            }
            $docDisplayTitle = formatDoctorTitle(
                $doctorProfile['full_name'],
                $doctorProfile['designation'] ?? 'Consultant',
                $doctorProfile['military_rank'] ?? null
            );
        }
    }

    // 8. Financial Calculations & Invoice Record Generation
    $subtotal = $totalBedCharge + ($hasDoctor ? $consultationFee : 0.00);
    $vatPercentage = 5.00; // 5% standard tertiary healthcare VAT
    $vatAmount = round($subtotal * ($vatPercentage / 100), 2);
    $discount = 0.00;
    $netPayable = round($subtotal + $vatAmount - $discount, 2);
    $dueAmount = $netPayable;
    $paidAmount = 0.00;

    // Generate unique sequential invoice number format INV-2026-XXXX
    $year = date('Y');
    $maxStmt = $pdo->query("SELECT MAX(invoice_id) FROM invoices");
    $nextId = ((int)$maxStmt->fetchColumn()) + 1;
    do {
        $invoiceNumber = sprintf('INV-%s-%04d', $year, $nextId);
        $chkInv = $pdo->prepare("SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1");
        $chkInv->execute([$invoiceNumber]);
        if ($chkInv->fetchColumn()) {
            $nextId++;
        } else {
            break;
        }
    } while (true);

    // Insert Centralized Master Invoice
    $insInvoice = $pdo->prepare("
        INSERT INTO invoices 
            (invoice_number, patient_id, admission_id, generated_by, subtotal, vat_percentage, discount, net_payable, paid_amount, due_amount, payment_method, status, discharge_status, payment_status, created_at)
        VALUES 
            (:inv_num, :pid, :aid, :actor, :subtotal, :vat_pct, :discount, :net, 0.00, :due, 'Cash', 'Pending', 'discharged', 'unpaid', NOW())
    ");
    $insInvoice->execute([
        ':inv_num'   => $invoiceNumber,
        ':pid'       => $targetPatientId,
        ':aid'       => $targetAllocId,
        ':actor'     => $actorId,
        ':subtotal'  => $subtotal,
        ':vat_pct'   => $vatPercentage,
        ':discount'  => $discount,
        ':net'       => $netPayable,
        ':due'       => $dueAmount
    ]);
    $newInvoiceId = (int)$pdo->lastInsertId();

    // 9. Populate Itemized Statements (invoice_items)
    // Item 1: Facility Bed Stay Charge
    $bedDescription = "{$bedNumber} ({$wardType}) [{$nights} Night(s) @ ৳" . number_format($bedDailyRate, 2) . "] Facility Stay";
    $insItemBed = $pdo->prepare("
        INSERT INTO invoice_items 
            (invoice_id, doctor_id, item_type, description, unit_price, quantity, total_price, doctor_payout_status, doctor_payout_amount, created_at)
        VALUES 
            (:inv_id, NULL, 'Bed Charge', :bed_desc, :unit_rate, :nights, :total_bed, 'UNCLAIMED', 0.00, NOW())
    ");
    $insItemBed->execute([
        ':inv_id'    => $newInvoiceId,
        ':bed_desc'  => $bedDescription,
        ':unit_rate' => $bedDailyRate,
        ':nights'    => $nights,
        ':total_bed' => $totalBedCharge
    ]);

    // Item 2: Attending Doctor Clinical Round
    if ($hasDoctor && $docUserId > 0) {
        $doctorDescription = "Attending Doctor Inpatient Round & Care: {$docDisplayTitle}";
        $insItemDoc = $pdo->prepare("
            INSERT INTO invoice_items 
                (invoice_id, doctor_id, item_type, description, unit_price, quantity, total_price, doctor_payout_status, doctor_payout_amount, created_at)
            VALUES 
                (:inv_id, :doc_id, 'Consultation', :doc_desc, :unit_price, 1, :total_price, 'PENDING_CLEARANCE', :payout_amt, NOW())
        ");
        $insItemDoc->execute([
            ':inv_id'      => $newInvoiceId,
            ':doc_id'      => $docUserId,
            ':doc_desc'    => $doctorDescription,
            ':unit_price'  => $consultationFee,
            ':total_price' => $consultationFee,
            ':payout_amt'  => $consultationFee
        ]);

        // 10. Record Doctor Earnings in Ledger
        $insDocEarn = $pdo->prepare("
            INSERT INTO doctor_earnings 
                (doctor_id, invoice_id, admission_id, patient_name, consultation_fee, disbursement_status, created_at)
            VALUES 
                (:doc_id, :inv_id, :aid, :pat_name, :fee, 'pending_hospital_collection', NOW())
        ");
        $insDocEarn->execute([
            ':doc_id'   => $docUserId,
            ':inv_id'   => $newInvoiceId,
            ':aid'      => $targetAllocId,
            ':pat_name' => $patientName,
            ':fee'      => $consultationFee
        ]);
    }

    // 11. Release Bed Allocation & End Care Team Assignments
    $updBed = $pdo->prepare("UPDATE hospital_beds SET status = 'Available' WHERE bed_id = :bid");
    $updBed->execute([':bid' => $targetBedId]);

    $closeAlloc = $pdo->prepare("
        UPDATE bed_allocations 
        SET status = 'Discharged', discharged_at = NOW() 
        WHERE allocation_id = :aid
    ");
    $closeAlloc->execute([':aid' => $targetAllocId]);

    $endAssignments = $pdo->prepare("
        UPDATE patient_doctor_assignments 
        SET status = 'Inactive', ended_at = NOW() 
        WHERE patient_id = :pid AND status = 'Active'
    ");
    $endAssignments->execute([':pid' => $targetPatientId]);

    // 12. Multi-Channel Notifications & Central Audit Logs
    try {
        $assignedDocIds = $hasDoctor ? [$docUserId] : [];
        EventDispatcher::notifyDischarge(
            $pdo,
            $targetPatientId,
            [
                'bed_id'       => $targetBedId,
                'bed_number'   => $bedNumber,
                'ward_type'    => $wardType,
                'floor_number' => $activeAlloc['floor_number'] ?? 1
            ],
            $assignedDocIds,
            $actorId,
            $dischargeSummary
        );

        EventDispatcher::pushAuditLog(
            $pdo,
            $actorId,
            'PATIENT_DISCHARGE_INVOICED',
            "Patient {$patientName} discharged from Bed {$bedNumber} ({$wardType}). Central invoice {$invoiceNumber} generated (Total: ৳" . number_format($netPayable, 2) . ").",
            'CENTRAL_TREASURY',
            'Patient Discharge & Billing',
            "Invoice {$invoiceNumber} (Patient #{$targetPatientId})",
            $clientIp
        );
    } catch (Throwable $notifErr) {
        // Non-blocking notification failure
        error_log("Notification dispatch error on discharge: " . $notifErr->getMessage());
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "✓ Patient {$patientName} discharged successfully. Central Invoice {$invoiceNumber} generated (৳" . number_format($netPayable, 2) . ").",
        'data'    => [
            'invoice_id'     => $newInvoiceId,
            'invoice_number' => $invoiceNumber,
            'patient_id'     => $targetPatientId,
            'patient_name'   => $patientName,
            'bed_number'     => $bedNumber,
            'ward_type'      => $wardType,
            'nights'         => $nights,
            'bed_rate'       => $bedDailyRate,
            'bed_charge'     => $totalBedCharge,
            'doctor_fee'     => $consultationFee,
            'doctor_name'    => $docDisplayTitle,
            'subtotal'       => $subtotal,
            'vat_amount'     => $vatAmount,
            'net_payable'    => $netPayable,
            'discharged_at'  => $dischargedAt,
            'summary'        => $dischargeSummary
        ]
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Discharge Billing Pipeline Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Discharge and billing transaction failed: ' . $e->getMessage()
    ]);
    exit;
}

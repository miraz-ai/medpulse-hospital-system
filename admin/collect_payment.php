<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Dedicated Multi-Branch Payment Collection Controller
 *
 * Enforces strict tenant isolation, CSRF validation, cash collection guards,
 * and security audit logging for cross-branch intrusion attempts.
 */

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/db.php';

$sessionUserId     = (int)($_SESSION['user_id'] ?? 0);
$sessionHospitalId = (int)($_SESSION['hospital_id'] ?? 1);
$clientIp          = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Direct GET access guard
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $invId = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($invId) {
        $chk = $pdo->prepare("SELECT invoice_id, hospital_id FROM invoices WHERE invoice_id = ? LIMIT 1");
        $chk->execute([$invId]);
        $inv = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            http_response_code(404);
            exit('Invoice not found.');
        }

        if ((int)$inv['hospital_id'] !== $sessionHospitalId) {
            http_response_code(403);
            exit('Access Denied: Record belongs to another facility.');
        }

        header("Location: billing_management.php?invoice_id={$invId}");
        exit;
    }

    header("Location: billing_management.php");
    exit;
}

// Payment Collection Processing (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    // 1. CSRF Token Validation
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
        } else {
            echo 'Security token mismatch. Please refresh and try again.';
        }
        exit;
    }

    // 2. Validate Inputs
    $invId    = filter_var($_POST['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
    $received = filter_var($_POST['amount_received'] ?? ($_POST['amount'] ?? 0), FILTER_VALIDATE_FLOAT);
    $method   = trim($_POST['payment_method'] ?? 'Cash');
    $txnRef   = trim($_POST['transaction_reference'] ?? ($_POST['transaction_id'] ?? ''));
    $notes    = trim($_POST['payment_notes'] ?? ($_POST['notes'] ?? ''));

    if (!$invId || $received <= 0) {
        http_response_code(400);
        $msg = 'Invalid invoice identifier or collection amount.';
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $msg]);
        } else {
            echo $msg;
        }
        exit;
    }

    $allowedMethods = ['Cash', 'bKash', 'Nagad', 'Credit Card', 'Bank Card', 'Cheque', 'Insurance', 'Other'];
    if (!in_array($method, $allowedMethods, true)) {
        $method = 'Cash';
    }

    try {
        $pdo->beginTransaction();

        // 3. Fetch Invoice with Row Lock
        $stmt = $pdo->prepare("
            SELECT invoice_id, invoice_number, hospital_id, due_amount, paid_amount, status
            FROM invoices
            WHERE invoice_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$invId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            $pdo->rollBack();
            http_response_code(404);
            $msg = 'Invoice not found.';
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
            } else {
                echo $msg;
            }
            exit;
        }

        // 4. Strict Branch Write & Cash Collection Guard
        if ((int)$invoice['hospital_id'] !== $sessionHospitalId) {
            $pdo->rollBack();

            // Record security incident in audit_logs
            $secAudit = $pdo->prepare("
                INSERT INTO audit_logs
                    (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
                VALUES
                    (:actor, 'Admin', 'UNAUTHORIZED_PAYMENT_ATTEMPT', 'UNAUTHORIZED_PAYMENT_ATTEMPT', :desc, 'SECURITY', :target, :ip, 'CRITICAL')
            ");
            $secAudit->execute([
                ':actor'  => $sessionUserId ?: null,
                ':desc'   => sprintf(
                    'Security Alert: Cross-branch payment collection rejected! Admin #%d (Hospital #%d) attempted payment on Invoice %s (Hospital #%d).',
                    $sessionUserId, $sessionHospitalId, $invoice['invoice_number'], (int)$invoice['hospital_id']
                ),
                ':target' => $invoice['invoice_number'],
                ':ip'     => $clientIp
            ]);

            http_response_code(403);
            $msg = 'Access Denied: You cannot collect payments on invoices belonging to another facility.';
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
            } else {
                echo $msg;
            }
            exit;
        }

        // 5. Calculate and Bound Collection
        $currentDue = (float)$invoice['due_amount'];
        if ($currentDue <= 0 && $invoice['status'] === 'Paid') {
            $pdo->rollBack();
            http_response_code(400);
            $msg = 'Invoice is already fully settled.';
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $msg]);
            } else {
                echo $msg;
            }
            exit;
        }

        $received      = min((float)$received, $currentDue);
        $newPaid       = (float)$invoice['paid_amount'] + $received;
        $newDue        = max(0.00, $currentDue - $received);
        $newStatus     = ($newDue <= 0.00) ? 'Paid' : 'Partial';
        $paymentStatus = ($newDue <= 0.00) ? 'paid' : 'unpaid';

        // 6. Update Invoice with strict hospital scoping
        $updateStmt = $pdo->prepare("
            UPDATE invoices
               SET paid_amount    = :paid,
                   due_amount     = :due,
                   status         = :status,
                   payment_status = :pay_status,
                   payment_method = :method
             WHERE invoice_id = :id AND hospital_id = :hid
        ");
        $updateStmt->execute([
            ':paid'       => $newPaid,
            ':due'        => $newDue,
            ':status'     => $newStatus,
            ':pay_status' => $paymentStatus,
            ':method'     => $method,
            ':id'         => $invId,
            ':hid'        => $sessionHospitalId
        ]);

        // 7. Insert into invoice_payments ledger
        $payStmt = $pdo->prepare("
            INSERT INTO invoice_payments
                (hospital_id, invoice_id, amount, payment_method, transaction_reference, collected_by_user_id, payment_notes, payment_date)
            VALUES
                (:hid, :inv_id, :amt, :method, :ref, :user_id, :notes, NOW())
        ");
        $notesText = $notes ?: "Payment collected by Admin #{$sessionUserId} at Branch #{$sessionHospitalId}";
        $payStmt->execute([
            ':hid'     => $sessionHospitalId,
            ':inv_id'  => $invId,
            ':amt'     => $received,
            ':method'  => $method,
            ':ref'     => $txnRef ?: null,
            ':user_id' => $sessionUserId,
            ':notes'   => $notesText
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // 8. Release Doctor Earnings if invoice is now Paid
        if ($newStatus === 'Paid') {
            $earnStmt = $pdo->prepare("
                UPDATE doctor_earnings
                   SET disbursement_status = 'available_for_disbursement',
                       updated_at = NOW()
                 WHERE invoice_id = :id
                   AND disbursement_status = 'pending_hospital_collection'
            ");
            $earnStmt->execute([':id' => $invId]);
        }

        // 9. Audit Log
        $logStmt = $pdo->prepare("
            INSERT INTO audit_logs
                (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address, security_level)
            VALUES
                (:actor, 'Admin', 'PAYMENT_COLLECTED', 'PAYMENT_COLLECTED', :desc, 'TRANSACTION', :target, :ip, 'INFO')
        ");
        $logStmt->execute([
            ':actor'  => $sessionUserId ?: null,
            ':desc'   => sprintf(
                'Collected ৳%s for Invoice %s via %s (Payment #%d) at Branch #%d. Remaining Due: ৳%s',
                number_format($received, 2),
                $invoice['invoice_number'],
                $method,
                $paymentId,
                $sessionHospitalId,
                number_format($newDue, 2)
            ),
            ':target' => $invoice['invoice_number'],
            ':ip'     => $clientIp
        ]);

        $pdo->commit();

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'        => true,
                'message'        => 'Payment successfully recorded.',
                'payment_id'     => $paymentId,
                'invoice_number' => $invoice['invoice_number'],
                'amount'         => $received,
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'status'         => $newStatus
            ]);
        } else {
            header("Location: billing_management.php?invoice_id={$invId}&payment_success=1");
        }
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        $msg = 'Transaction error: ' . $e->getMessage();
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $msg]);
        } else {
            echo $msg;
        }
        exit;
    }
}

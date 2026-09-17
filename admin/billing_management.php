<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Central Billing & Invoicing Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';

// ----------------------------------------------------------------------------
// AJAX Endpoint: Fetch Itemized Invoice Breakdown & Doctor Payout Details
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_breakdown') {
    header('Content-Type: application/json; charset=utf-8');
    $invId = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$invId) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice identifier.']);
        exit;
    }

    try {
        // Fetch invoice master details
        $stmt = $pdo->prepare("
            SELECT 
                i.invoice_id,
                i.invoice_number,
                i.patient_id,
                i.admission_id,
                i.subtotal,
                i.vat_percentage,
                i.discount,
                i.net_payable,
                i.paid_amount,
                i.due_amount,
                i.payment_method,
                i.status,
                i.created_at,
                u.full_name AS patient_name,
                u.phone AS patient_phone,
                u.email AS patient_email,
                u.gender AS patient_gender,
                u.age AS patient_age,
                u.blood_group AS patient_blood_group,
                gen.full_name AS generated_by_name,
                hb.bed_number,
                hb.ward_type,
                ba.admitted_at,
                ba.discharged_at
            FROM invoices i
            JOIN users u ON i.patient_id = u.user_id
            LEFT JOIN users gen ON i.generated_by = gen.user_id
            LEFT JOIN bed_allocations ba ON i.admission_id = ba.allocation_id
            LEFT JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
            WHERE i.invoice_id = ?
        ");
        $stmt->execute([$invId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            echo json_encode(['success' => false, 'message' => 'Invoice record not found.']);
            exit;
        }

        // Fetch line items with assigned physician details & payout status
        $itemStmt = $pdo->prepare("
            SELECT 
                ii.item_id,
                ii.invoice_id,
                ii.doctor_id,
                ii.item_type,
                ii.description,
                ii.unit_price,
                ii.quantity,
                ii.total_price,
                ii.doctor_payout_status,
                ii.doctor_payout_amount,
                doc.full_name AS doctor_name,
                doc.phone AS doctor_phone,
                dp.specialty AS doctor_specialty,
                dp.designation AS doctor_designation,
                dp.military_rank AS doctor_military_rank,
                dp.qualifications AS doctor_qualifications
            FROM invoice_items ii
            LEFT JOIN users doc ON ii.doctor_id = doc.user_id
            LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
            WHERE ii.invoice_id = ?
            ORDER BY ii.item_id ASC
        ");
        $itemStmt->execute([$invId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$it) {
            if (!empty($it['doctor_name'])) {
                $it['doctor_name'] = formatDoctorTitle(
                    $it['doctor_name'],
                    $it['doctor_designation'] ?? null,
                    $it['doctor_military_rank'] ?? null
                );
            }
        }
        unset($it);

        echo json_encode([
            'success' => true,
            'invoice' => $invoice,
            'items'   => $items
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ----------------------------------------------------------------------------
// AJAX Endpoint: Treasury Summary — Revenue Aggregates + Doctor Fee Liabilities
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_treasury_summary') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        // Hospital-wide financial aggregates
        $summaryStmt = $pdo->query("
            SELECT
                COALESCE(SUM(net_payable), 0)  AS total_invoiced,
                COALESCE(SUM(paid_amount), 0)  AS total_collected,
                COALESCE(SUM(due_amount),  0)  AS total_outstanding,
                COUNT(*)                        AS invoice_count,
                SUM(IF(status = 'Paid', 1, 0))    AS paid_count,
                SUM(IF(status != 'Paid', 1, 0))   AS pending_count
            FROM invoices
        ");
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        // Doctor fee liabilities (not yet disbursed)
        $liabilityStmt = $pdo->query("
            SELECT COALESCE(SUM(doctor_payout_amount), 0) AS doctor_fee_liability
            FROM invoice_items
            WHERE doctor_payout_status != 'DISBURSED' AND doctor_id IS NOT NULL
        ");
        $summary['doctor_fee_liability'] = (float)$liabilityStmt->fetchColumn();

        // Pending payout items for clearance table
        $payoutStmt = $pdo->query("
            SELECT
                ii.item_id, ii.invoice_id, ii.doctor_id,
                ii.description, ii.doctor_payout_amount, ii.doctor_payout_status,
                doc.full_name  AS doctor_name,
                dp.specialty   AS doctor_specialty,
                dp.designation AS doctor_designation,
                dp.military_rank AS doctor_military_rank,
                dp.qualifications AS doctor_qualifications,
                i.invoice_number,
                pat.full_name  AS patient_name
            FROM invoice_items ii
            JOIN invoices           i   ON ii.invoice_id = i.invoice_id
            JOIN users              doc ON ii.doctor_id  = doc.user_id
            JOIN users              pat ON i.patient_id  = pat.user_id
            LEFT JOIN doctor_profiles dp ON doc.user_id  = dp.user_id
            WHERE ii.doctor_id IS NOT NULL
              AND ii.doctor_payout_status != 'DISBURSED'
            ORDER BY ii.item_id DESC
            LIMIT 100
        ");
        $payouts = $payoutStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payouts as &$p) {
            if (!empty($p['doctor_name'])) {
                $p['doctor_name'] = formatDoctorTitle(
                    $p['doctor_name'],
                    $p['doctor_designation'] ?? null,
                    $p['doctor_military_rank'] ?? null
                );
            }
        }
        unset($p);

        echo json_encode(['success' => true, 'summary' => $summary, 'payouts' => $payouts]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// AJAX Endpoint: Settle Payment — Collect Patient Due
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'settle_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check
    $csrfPost = $_POST['csrf_token'] ?? '';
    if (empty($csrfPost) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfPost)) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh.']);
        exit;
    }

    $invId    = filter_var($_POST['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
    $received = filter_var($_POST['amount_received'] ?? 0, FILTER_VALIDATE_FLOAT);
    $method   = trim($_POST['payment_method'] ?? 'Cash');
    $txnId    = trim($_POST['transaction_id'] ?? '');
    $adminId  = (int)($_SESSION['user_id'] ?? 0);
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!$invId || $received <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice or amount.']);
        exit;
    }

    // Validate allowed payment methods
    $allowedMethods = ['Cash', 'bKash', 'Nagad', 'Bank Card', 'Cheque', 'Insurance'];
    if (!in_array($method, $allowedMethods, true)) {
        $method = 'Cash';
    }

    try {
        $pdo->beginTransaction();

        // Fetch current state
        $fetchStmt = $pdo->prepare("SELECT due_amount, paid_amount, status FROM invoices WHERE invoice_id = ? LIMIT 1");
        $fetchStmt->execute([$invId]);
        $inv = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
            exit;
        }

        $received = min((float)$received, (float)$inv['due_amount']); // cap at due
        $newPaid  = (float)$inv['paid_amount'] + $received;
        $newDue   = max(0, (float)$inv['due_amount'] - $received);
        $newStatus = $newDue <= 0 ? 'Paid' : 'Partial';

        // Update invoice
        $paymentStatus = $newDue <= 0 ? 'paid' : 'unpaid';
        $updateStmt = $pdo->prepare("
            UPDATE invoices
               SET paid_amount = :paid, due_amount = :due, status = :status,
                   payment_status = :pay_status, payment_method = :method
             WHERE invoice_id = :id
        ");
        $updateStmt->execute([
            ':paid'       => $newPaid,
            ':due'        => $newDue,
            ':status'     => $newStatus,
            ':pay_status' => $paymentStatus,
            ':method'     => $method,
            ':id'         => $invId,
        ]);

        // When payment is settled (Paid), release doctor earnings to available_for_disbursement
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

        // Fetch invoice number for audit
        $invNumStmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE invoice_id = ?");
        $invNumStmt->execute([$invId]);
        $invNumber = $invNumStmt->fetchColumn() ?: "INV-{$invId}";

        // Audit log
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs
                (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address)
            VALUES
                (:actor, 'Admin', 'PAYMENT_COLLECTED', 'PAYMENT_COLLECTED', :desc, 'SYSTEM', :target, :ip)
        ");
        $auditStmt->execute([
            ':actor'  => $adminId ?: null,
            ':desc'   => sprintf('Collected %.2f via %s | Ref: %s | Invoice: %s | New due: %.2f',
                                  $received, $method, $txnId ?: 'N/A', $invNumber, $newDue),
            ':target' => $invNumber,
            ':ip'     => $ip,
        ]);

        $pdo->commit();
        echo json_encode([
            'success'    => true,
            'message'    => 'Payment recorded successfully.',
            'new_paid'   => $newPaid,
            'new_due'    => $newDue,
            'new_status' => $newStatus,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// AJAX Endpoint: Approve Doctor Payout — Mark Item as DISBURSED
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'approve_doctor_payout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check
    $csrfPost = $_POST['csrf_token'] ?? '';
    if (empty($csrfPost) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfPost)) {
        echo json_encode(['success' => false, 'message' => 'Security token mismatch.']);
        exit;
    }

    $itemId  = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT);
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (!$itemId) {
        echo json_encode(['success' => false, 'message' => 'Invalid item identifier.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch item details for audit
        $itemFetchStmt = $pdo->prepare("
            SELECT ii.invoice_id, ii.doctor_id, ii.doctor_payout_amount, ii.doctor_payout_status,
                   doc.full_name AS doctor_name, i.invoice_number
            FROM invoice_items ii
            JOIN invoices i ON ii.invoice_id = i.invoice_id
            JOIN users doc  ON ii.doctor_id  = doc.user_id
            WHERE ii.item_id = ?
            LIMIT 1
        ");
        $itemFetchStmt->execute([$itemId]);
        $item = $itemFetchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$item || $item['doctor_payout_status'] === 'DISBURSED') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $item ? 'Payout already disbursed.' : 'Item not found.']);
            exit;
        }

        // Mark disbursed
        $updateStmt = $pdo->prepare("
            UPDATE invoice_items
               SET doctor_payout_status = 'DISBURSED'
             WHERE item_id = ?
        ");
        $updateStmt->execute([$itemId]);

        // Sync doctor_earnings ledger
        $updEarn = $pdo->prepare("
            UPDATE doctor_earnings
               SET disbursement_status = 'disbursed',
                   updated_at = NOW()
             WHERE invoice_id = :inv_id
               AND doctor_id = :doc_id
        ");
        $updEarn->execute([
            ':inv_id' => $item['invoice_id'],
            ':doc_id' => $item['doctor_id']
        ]);

        // Audit log
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs
                (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address)
            VALUES
                (:actor, 'Admin', 'DOCTOR_PAYOUT_DISBURSED', 'DOCTOR_PAYOUT_DISBURSED', :desc, 'SYSTEM', :target, :ip)
        ");
        $auditStmt->execute([
            ':actor'  => $adminId ?: null,
            ':desc'   => sprintf('Payout ৳%.2f cleared to Dr. %s | Invoice: %s',
                                  (float)$item['doctor_payout_amount'],
                                  $item['doctor_name'],
                                  $item['invoice_number']),
            ':target' => 'ITEM-' . $itemId,
            ':ip'     => $ip,
        ]);

        $pdo->commit();
        echo json_encode([
            'success'     => true,
            'message'     => 'Payout approved and marked as disbursed.',
            'doctor_name' => $item['doctor_name'],
            'amount'      => $item['doctor_payout_amount'],
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// AJAX Endpoint: Audit Feed — Recent Billing-Related Events
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_audit_feed') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $feedStmt = $pdo->prepare("
            SELECT
                a.log_id, a.action, a.description, a.target_entity,
                a.ip_address, a.created_at,
                u.full_name AS admin_name
            FROM audit_logs a
            LEFT JOIN users u ON a.actor_id = u.user_id
            WHERE a.action LIKE '%INVOICE%'
               OR a.action LIKE '%PAYMENT%'
               OR a.action LIKE '%PAYOUT%'
            ORDER BY a.created_at DESC
            LIMIT 40
        ");
        $feedStmt->execute();
        $events = $feedStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'events' => $events]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.', 'events' => []]);
    }
    exit;
}

// ----------------------------------------------------------------------------
// Page Load: Fetch All Central Invoices with Doctor Breakdown Aggregates
// ----------------------------------------------------------------------------
$invoices = [];
try {
    $invoicesStmt = $pdo->query("
        SELECT 
            i.invoice_id,
            i.invoice_number,
            i.patient_id,
            i.admission_id,
            i.subtotal,
            i.vat_percentage,
            i.discount,
            i.net_payable,
            i.paid_amount,
            i.due_amount,
            i.payment_method,
            i.status,
            i.created_at,
            u.full_name AS patient_name,
            u.email AS patient_email,
            u.phone AS patient_phone,
            u.gender AS patient_gender,
            hb.bed_number,
            hb.ward_type,
            COUNT(DISTINCT ii.doctor_id) AS doctor_count,
            GROUP_CONCAT(DISTINCT doc.full_name ORDER BY doc.full_name SEPARATOR ', ') AS doctor_names,
            GROUP_CONCAT(DISTINCT ii.item_type ORDER BY ii.item_type SEPARATOR ', ') AS service_types
        FROM invoices i
        JOIN users u ON i.patient_id = u.user_id
        LEFT JOIN bed_allocations ba ON i.admission_id = ba.allocation_id
        LEFT JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
        LEFT JOIN invoice_items ii ON i.invoice_id = ii.invoice_id
        LEFT JOIN users doc ON ii.doctor_id = doc.user_id AND doc.role = 'Doctor'
        GROUP BY i.invoice_id
        ORDER BY i.created_at DESC
    ");
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as &$inv) {
        if (!empty($inv['doctor_names'])) {
            $names = explode(', ', $inv['doctor_names']);
            $formatted = array_map(function($n) {
                return formatDoctorTitle($n);
            }, $names);
            $inv['doctor_names'] = implode(', ', $formatted);
        }
    }
    unset($inv);
} catch (PDOException $e) {
    $invoices = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Billing & Central Invoicing</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/billing-management.css">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial (Dynamic Active Route Highlighting) -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container (Starts cleanly past sidebar) -->
  <main class="viewport-full">

    <!-- Page Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          Central Billing & Invoices
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
        </h1>
        <p>Hospital accounts ledger, inpatient settlement statements, diagnostic charges, and insurance reconciliations</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" id="btnOpenTreasuryModal" onclick="openTreasuryModal()">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Treasury Audit
        </button>
        <button class="btn-action-gradient" onclick="showToast('New clinical invoice initiated.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          + Generate Invoice
        </button>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Monthly Revenue</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </div>
        <div class="stat-card-number">৳ 2,480,500</div>
        <div class="stat-card-badge badge-green">+14.2% Collected</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Pending Invoices</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number">12</div>
        <div class="stat-card-badge badge-amber">Under Settlement</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Insurance Claims</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <div class="stat-card-number">08</div>
        <div class="stat-card-badge badge-blue">Verified Coverage</div>
      </div>
    </div>

    <!-- Invoices Stream Table Card -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Recent Hospital Clinical Invoices
          </h3>
          <p>Real-time billing transactions, physician consultations, inpatient stays, and insurance settlements</p>
        </div>
        <span class="role-pill role-pill-doctor" style="font-size: 0.76rem;">
          CENTRAL TREASURY
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Invoice #</th>
              <th>Patient Details</th>
              <th>Doctor Involvement</th>
              <th>Department Service</th>
              <th>Financials (Net / Due)</th>
              <th>Payment Method</th>
              <th>Billing Date</th>
              <th>Settlement</th>
              <th style="text-align: right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($invoices)): ?>
              <tr>
                <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                  No clinical invoices recorded yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($invoices as $inv): ?>
                <?php
                  $initials = strtoupper(substr(trim($inv['patient_name']), 0, 2));
                  $net = (float)$inv['net_payable'];
                  $paid = (float)$inv['paid_amount'];
                  $due = (float)$inv['due_amount'];
                  $paymentClean = strtolower(str_replace(' ', '', $inv['payment_method']));
                ?>
                <tr>
                  <!-- 1. Invoice Number -->
                  <td>
                    <span class="license-chip"><?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($inv['bed_number'])): ?>
                      <div class="invoice-admission-tag" title="Inpatient Bed Allocation">
                        <svg class="ui-ico" style="width: 11px; height: 11px; stroke: #0284c7;" viewBox="0 0 24 24"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"></path></svg>
                        Bed <?= htmlspecialchars($inv['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <!-- 2. Patient Details -->
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                      <div>
                        <strong style="color: var(--text-heading); display: block; font-size: 0.86rem;">
                          <?= htmlspecialchars($inv['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <span style="color: var(--text-muted); font-size: 0.74rem;">
                          <?= htmlspecialchars($inv['patient_phone'] ?: $inv['patient_email'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      </div>
                    </div>
                  </td>

                  <!-- 3. Doctor Involvement -->
                  <td>
                    <?php if ((int)$inv['doctor_count'] > 0 && !empty($inv['doctor_names'])): ?>
                      <div class="doctor-badge-wrap">
                        <span class="doctor-chip" title="<?= htmlspecialchars($inv['doctor_names'], ENT_QUOTES, 'UTF-8') ?>">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                          <?= htmlspecialchars($inv['doctor_names'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      </div>
                    <?php else: ?>
                      <span class="doctor-direct-chip" title="Direct Hospital Facility Charges">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="width: 13px; height: 13px; stroke: #94a3b8;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="9" x2="15" y2="15"></line><line x1="15" y1="9" x2="9" y2="15"></line></svg>
                        Hospital Direct
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- 4. Department Service -->
                  <td>
                    <?php
                      $services = !empty($inv['service_types']) ? $inv['service_types'] : 'General Clinical Service';
                    ?>
                    <span style="color: #334155; font-size: 0.82rem; font-weight: 500;">
                      <?= htmlspecialchars($services, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>

                  <!-- 5. Financials (Net / Paid / Due) -->
                  <td>
                    <div class="financial-summary-cell">
                      <strong class="text-net-payable">৳ <?= number_format($net, 2) ?></strong>
                      <div class="financial-subtext">
                        <span class="text-paid">Paid: ৳ <?= number_format($paid, 2) ?></span>
                        <?php if ($due > 0.00): ?>
                          <span class="text-due-pill">Due: ৳ <?= number_format($due, 2) ?></span>
                        <?php else: ?>
                          <span class="text-zero-due">&bull; No Due</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>

                  <!-- 6. Payment Method -->
                  <td>
                    <span class="payment-chip payment-<?= htmlspecialchars($paymentClean, ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($inv['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>

                  <!-- 7. Billing Date -->
                  <td>
                    <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: 500;">
                      <?= date('d M Y', strtotime($inv['created_at'])) ?>
                    </span>
                  </td>

                  <!-- 8. Settlement Status -->
                  <td>
                    <?php if ($inv['status'] === 'Paid'): ?>
                      <span class="status-badge-active">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Paid & Settled
                      </span>
                    <?php elseif ($inv['status'] === 'Partial'): ?>
                      <span class="status-badge-partial">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                        Partial Due
                      </span>
                    <?php else: ?>
                      <span class="status-badge-pending">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        Pending
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- 9. Actions Column -->
                  <td style="text-align: right;">
                    <div class="billing-actions-row">
                      <!-- View / Print Breakdown Trigger -->
                      <button type="button" 
                              class="btn-billing-action btn-view-breakdown" 
                              data-action="view-invoice" 
                              data-invoice-id="<?= (int)$inv['invoice_id'] ?>" 
                              title="View & Print Itemized Bill Breakdown">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        <span>Breakdown</span>
                      </button>

                      <!-- Audit Trail / Quick Status Icon -->
                      <?php if ($due <= 0.00 && $inv['status'] === 'Paid'): ?>
                        <button type="button" 
                                class="btn-billing-action btn-audit-settled" 
                                data-action="audit-trail" 
                                data-invoice-id="<?= (int)$inv['invoice_id'] ?>" 
                                data-invoice-number="<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>" 
                                data-status="settled" 
                                data-net="<?= number_format($net, 2) ?>" 
                                data-paid="<?= number_format($paid, 2) ?>" 
                                data-due="0.00" 
                                title="Audit Status: Fully Settled & Reconciled">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
                          <span>Settled</span>
                        </button>
                      <?php else: ?>
                        <!-- Collect Payment button for unsettled invoices -->
                        <button type="button" 
                                class="btn-billing-action btn-collect-payment" 
                                data-action="settle-payment" 
                                data-invoice-id="<?= (int)$inv['invoice_id'] ?>" 
                                data-invoice-number="<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>" 
                                data-due="<?= number_format($due, 2, '.', '') ?>" 
                                title="Collect Payment — Outstanding: ৳<?= number_format($due, 2) ?>">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                          <span>Collect</span>
                        </button>
                        <button type="button" 
                                class="btn-billing-action btn-audit-due" 
                                data-action="audit-trail" 
                                data-invoice-id="<?= (int)$inv['invoice_id'] ?>" 
                                data-invoice-number="<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>" 
                                data-status="due" 
                                data-net="<?= number_format($net, 2) ?>" 
                                data-paid="<?= number_format($paid, 2) ?>" 
                                data-due="<?= number_format($due, 2) ?>" 
                                title="Audit Status: Pending Balance ৳<?= number_format($due, 2) ?>">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                          <span>Due ৳<?= number_format($due) ?></span>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <!-- ========================================================================
       MODAL 1: Itemized Clinical Invoice & Doctor Breakdown
       ======================================================================== -->
  <div id="invoiceBreakdownModal" class="billing-modal-backdrop" aria-hidden="true">
    <div class="billing-modal-container">
      <div class="billing-modal-card">
        <!-- Modal Header -->
        <div class="billing-modal-header">
          <div class="modal-title-wrap">
            <div class="modal-hospital-badge">
              <svg class="ui-ico" viewBox="0 0 24 24" style="stroke: var(--brand-primary); width: 26px; height: 26px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
              <div>
                <h3>MedPulse Hospital Central Billing</h3>
                <p>Official Itemized Patient Ledger & Clinical Statement</p>
              </div>
            </div>
          </div>
          <div class="modal-header-right">
            <span id="modalInvoiceStatusBadge" class="status-badge-active">Paid & Settled</span>
            <button type="button" class="billing-modal-close" id="btnCloseBreakdownModal" aria-label="Close modal">&times;</button>
          </div>
        </div>

        <!-- Modal Body (Printable Area) -->
        <div class="billing-modal-body" id="printableInvoiceContent">
          <!-- Metadata Grid -->
          <div class="invoice-meta-grid">
            <div class="meta-item">
              <span class="meta-label">Invoice Number</span>
              <strong class="meta-value" id="modalInvoiceNumber">-</strong>
            </div>
            <div class="meta-item">
              <span class="meta-label">Billing Date</span>
              <strong class="meta-value" id="modalInvoiceDate">-</strong>
            </div>
            <div class="meta-item">
              <span class="meta-label">Patient Name</span>
              <strong class="meta-value" id="modalPatientName">-</strong>
            </div>
            <div class="meta-item">
              <span class="meta-label">Patient Contact</span>
              <strong class="meta-value" id="modalPatientContact">-</strong>
            </div>
            <div class="meta-item">
              <span class="meta-label">Inpatient Stay</span>
              <strong class="meta-value" id="modalInpatientBed">Outpatient / Ambulatory</strong>
            </div>
            <div class="meta-item">
              <span class="meta-label">Payment Method</span>
              <strong class="meta-value" id="modalPaymentMethod">-</strong>
            </div>
          </div>

          <!-- Itemized Breakdown Table -->
          <div class="invoice-items-table-wrap">
            <table class="invoice-items-table">
              <thead>
                <tr>
                  <th style="width: 16%;">Category</th>
                  <th style="width: 38%;">Service Description</th>
                  <th style="width: 26%;">Attending / Consulting Doctor</th>
                  <th style="width: 10%; text-align: right;">Rate (৳)</th>
                  <th style="width: 10%; text-align: right;">Total (৳)</th>
                </tr>
              </thead>
              <tbody id="modalInvoiceItemsBody">
                <!-- Injected dynamically via billing_management.js -->
              </tbody>
            </table>
          </div>

          <!-- Financial Reconciliation Summary -->
          <div class="invoice-reconciliation-panel">
            <div class="reconciliation-col-left">
              <div class="ledger-security-stamp">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: var(--status-green);"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
                <span>Verified by MedPulse Central Treasury & Compliance Ledger</span>
              </div>
              <p class="ledger-disclaimer">
                Computer-generated clinical statement. All medical consultation fees, bed telemetry allocations, and diagnostic charges are verified according to Bangladesh DGHS clinical guidelines.
              </p>
            </div>
            <div class="reconciliation-col-right">
              <div class="recon-row">
                <span>Subtotal:</span>
                <strong id="modalSubtotal">৳ 0.00</strong>
              </div>
              <div class="recon-row">
                <span id="modalVatLabel">VAT (5.0%):</span>
                <strong id="modalVatAmount">৳ 0.00</strong>
              </div>
              <div class="recon-row" id="modalDiscountRow">
                <span>Institutional Discount:</span>
                <strong id="modalDiscountAmount" style="color: var(--status-green);">- ৳ 0.00</strong>
              </div>
              <div class="recon-row recon-net">
                <span>Net Total Payable:</span>
                <strong id="modalNetPayable">৳ 0.00</strong>
              </div>
              <div class="recon-row">
                <span>Paid Amount:</span>
                <strong id="modalPaidAmount" style="color: var(--brand-primary);">৳ 0.00</strong>
              </div>
              <div class="recon-row recon-due" id="modalDueRow">
                <span>Outstanding Due:</span>
                <strong id="modalDueAmount">৳ 0.00</strong>
              </div>
            </div>
          </div>
        </div>

        <!-- Modal Footer Actions -->
        <div class="billing-modal-footer">
          <button type="button" class="btn-modal-secondary" id="btnCancelBreakdownModal">Close</button>
          <button type="button" class="btn-modal-primary" id="btnPrintInvoice">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print Official Invoice
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================================
       MODAL 2: Quick Audit Trail & Settlement Status
       ======================================================================== -->
  <div id="invoiceAuditModal" class="billing-modal-backdrop" aria-hidden="true">
    <div class="billing-modal-container billing-modal-container-sm">
      <div class="billing-modal-card">
        <div class="billing-modal-header">
          <div class="modal-title-wrap">
            <div class="modal-hospital-badge">
              <svg class="ui-ico" viewBox="0 0 24 24" style="stroke: var(--brand-primary); width: 22px; height: 22px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
              <div>
                <h3>Invoice Audit Trail</h3>
                <p id="auditInvoiceSubtitle">Treasury & Compliance Status</p>
              </div>
            </div>
          </div>
          <button type="button" class="billing-modal-close" id="btnCloseAuditModal" aria-label="Close modal">&times;</button>
        </div>
        <div class="billing-modal-body" style="padding: 1.5rem; gap: 1rem;">
          <div id="auditStatusBanner" class="audit-status-banner settled">
            <!-- Populated dynamically via JS -->
          </div>
          <div class="audit-details-list">
            <div class="audit-detail-item">
              <span class="audit-lbl">Treasury Ledger Status:</span>
              <strong id="auditLedgerStatus" class="audit-val">VERIFIED_BALANCED</strong>
            </div>
            <div class="audit-detail-item">
              <span class="audit-lbl">Net Invoice Total:</span>
              <strong id="auditNetVal" class="audit-val">৳ 0.00</strong>
            </div>
            <div class="audit-detail-item">
              <span class="audit-lbl">Total Disbursed / Paid:</span>
              <strong id="auditPaidVal" class="audit-val">৳ 0.00</strong>
            </div>
            <div class="audit-detail-item">
              <span class="audit-lbl">Remaining Balance Due:</span>
              <strong id="auditDueVal" class="audit-val">৳ 0.00</strong>
            </div>
            <div class="audit-detail-item">
              <span class="audit-lbl">Ledger Integrity Hash:</span>
              <code id="auditSecurityHash" style="font-size: 0.74rem; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #0284c7;">SHA256: 4f8b91...a02c</code>
            </div>
          </div>
          <div style="margin-top: 1rem; display: flex; justify-content: flex-end; gap: 0.5rem;">
            <a href="audit_logs.php?category=FINANCIAL" class="btn-modal-secondary" style="font-size: 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
              View System Audit Logs
            </a>
            <button type="button" class="btn-modal-primary" id="btnDismissAuditModal" style="font-size: 0.8rem;">Dismiss</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================================
       MODAL 3: Treasury Audit — Revenue Overview + Doctor Payout Clearance
       ======================================================================== -->
  <div id="treasuryAuditModal" class="billing-modal-backdrop" aria-hidden="true" role="dialog" aria-labelledby="treasuryModalTitle">
    <div class="billing-modal-container treasury-modal-container">
      <div class="billing-modal-card">

        <!-- Header -->
        <div class="billing-modal-header">
          <div class="modal-title-wrap">
            <div class="modal-hospital-badge">
              <svg class="ui-ico" viewBox="0 0 24 24" style="stroke: var(--brand-teal); width: 24px; height: 24px;"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
              <div>
                <h3 id="treasuryModalTitle">Treasury Audit Console</h3>
                <p>Hospital financial reconciliation, physician fee clearance &amp; audit trail</p>
              </div>
            </div>
          </div>
          <button type="button" class="billing-modal-close" id="btnCloseTreasuryModal" aria-label="Close Treasury Audit">&times;</button>
        </div>

        <!-- Tab Bar -->
        <div class="treasury-tab-bar">
          <button class="treasury-tab active" data-tab="overview" onclick="switchTreasuryTab('overview', this)">Revenue Overview</button>
          <button class="treasury-tab" data-tab="payouts" onclick="switchTreasuryTab('payouts', this)">Doctor Payout Clearance <span id="pendingPayoutBadge" class="treasury-badge"></span></button>
          <button class="treasury-tab" data-tab="auditfeed" onclick="switchTreasuryTab('auditfeed', this)">Audit Feed</button>
        </div>

        <!-- Modal Body -->
        <div class="billing-modal-body" style="padding: 1.5rem; gap: 1rem;">

          <!-- Tab: Revenue Overview -->
          <div id="tabOverview" class="treasury-tab-panel active">
            <div id="treasuryMetricCards" class="treasury-metric-cards">
              <div class="treasury-metric-card tmc-blue">
                <div class="tmc-label">Total Invoiced</div>
                <div class="tmc-value" id="tmcTotalInvoiced">—</div>
                <div class="tmc-note" id="tmcInvoiceCount">loading…</div>
              </div>
              <div class="treasury-metric-card tmc-green">
                <div class="tmc-label">Total Collected</div>
                <div class="tmc-value" id="tmcTotalCollected">—</div>
                <div class="tmc-note" id="tmcPaidCount">loading…</div>
              </div>
              <div class="treasury-metric-card tmc-red">
                <div class="tmc-label">Outstanding Receivables</div>
                <div class="tmc-value" id="tmcOutstanding">—</div>
                <div class="tmc-note" id="tmcPendingCount">loading…</div>
              </div>
              <div class="treasury-metric-card tmc-amber">
                <div class="tmc-label">Doctor Fee Liability</div>
                <div class="tmc-value" id="tmcDoctorLiability">—</div>
                <div class="tmc-note">Pending clearance to physicians</div>
              </div>
            </div>
          </div>

          <!-- Tab: Doctor Payout Clearance -->
          <div id="tabPayouts" class="treasury-tab-panel" style="display:none;">
            <div id="payoutTableWrap">
              <div class="treasury-loading">
                <div class="billing-spinner"></div>
                <span>Loading payout clearance data…</span>
              </div>
            </div>
          </div>

          <!-- Tab: Audit Feed -->
          <div id="tabAuditfeed" class="treasury-tab-panel" style="display:none;">
            <div id="auditFeedWrap">
              <div class="treasury-loading">
                <div class="billing-spinner"></div>
                <span>Loading audit trail…</span>
              </div>
            </div>
          </div>

        </div>

        <!-- Footer -->
        <div class="billing-modal-footer">
          <a href="audit_logs.php" class="btn-modal-secondary" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-size:0.8rem;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
            Full Audit Logs
          </a>
          <button type="button" class="btn-modal-primary" id="btnDismissTreasuryModal">Close Console</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ========================================================================
       MODAL 4: Settle Due — Collect Patient Payment
       ======================================================================== -->
  <div id="settlePaymentModal" class="billing-modal-backdrop" aria-hidden="true" role="dialog" aria-labelledby="settleModalTitle">
    <div class="billing-modal-container billing-modal-container-sm">
      <div class="billing-modal-card">

        <!-- Header -->
        <div class="billing-modal-header">
          <div class="modal-title-wrap">
            <div class="modal-hospital-badge">
              <svg class="ui-ico" viewBox="0 0 24 24" style="stroke: var(--status-green); width: 22px; height: 22px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
              <div>
                <h3 id="settleModalTitle">Collect Payment</h3>
                <p id="settleModalSubtitle">Record inbound payment for invoice</p>
              </div>
            </div>
          </div>
          <button type="button" class="billing-modal-close" id="btnCloseSettleModal" aria-label="Close">&times;</button>
        </div>

        <!-- Body -->
        <div class="billing-modal-body" style="padding: 1.5rem; gap: 1rem;">

          <!-- Due amount display -->
          <div class="settle-due-display" id="settleDueDisplay">
            <div class="settle-due-label">Outstanding Balance Due</div>
            <div class="settle-due-amount" id="settleDueAmount">৳ 0.00</div>
          </div>

          <form id="settlePaymentForm" onsubmit="handleSettleSubmit(event)" novalidate>
            <input type="hidden" name="invoice_id" id="settleInvoiceId" value="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="settle-form-field">
              <label for="settlePaymentMethod">Payment Method <span style="color:#ef4444;">*</span></label>
              <select id="settlePaymentMethod" name="payment_method" required>
                <option value="Cash">Cash</option>
                <option value="bKash">bKash</option>
                <option value="Nagad">Nagad</option>
                <option value="Bank Card">Bank Card</option>
                <option value="Cheque">Cheque</option>
                <option value="Insurance">Insurance</option>
              </select>
            </div>

            <div class="settle-form-field">
              <label for="settleTransactionId">Transaction / Reference ID <span style="color:#94a3b8;font-size:.76rem;">(optional for cash)</span></label>
              <input type="text" id="settleTransactionId" name="transaction_id" placeholder="e.g. TXN-2026-XXXXX" maxlength="100">
            </div>

            <div class="settle-form-field">
              <label for="settleAmountReceived">Amount Received (৳) <span style="color:#ef4444;">*</span></label>
              <input type="number" id="settleAmountReceived" name="amount_received" step="0.01" min="0.01" placeholder="Enter amount collected" required>
              <small id="settleAmountHint" style="color:#94a3b8;font-size:.74rem;">Max: outstanding due amount</small>
            </div>

            <div id="settleFormError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;color:#dc2626;font-size:.8rem;margin-top:8px;"></div>

            <div class="billing-modal-footer" style="margin-top:1.25rem;padding:0;">
              <button type="button" class="btn-modal-secondary" id="btnCancelSettleModal">Cancel</button>
              <button type="submit" class="btn-modal-primary" id="btnSubmitSettle">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke:white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Record Payment
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>


  <!-- ========================================================================
       PRINTABLE INVOICE VOUCHER — Hidden from screen, visible only at @print
       All content is populated dynamically by billing_management.js
       ======================================================================== -->
  <div id="printable-invoice-voucher" aria-hidden="true">

    <!-- Faint Background Watermark -->
    <div class="piv-watermark">
      <img src="../assets/images/logo.png" alt="" class="piv-watermark-logo">
    </div>

    <!-- Letterhead -->
    <div class="piv-letterhead">
      <div class="piv-lh-left">
        <img src="../assets/images/logo.png" alt="MedPulse Hospital &amp; Specialty Care" class="piv-header-logo">
      </div>
      <div class="piv-lh-right">
        <div>Plot 14, Road 7, Medical Zone, Dhaka-1212</div>
        <div>PABX: (02) 9876543 &nbsp;|&nbsp; Emergency: +880 1700-000000</div>
        <div>Govt. DGHS Reg: <strong>HSM-2026-DH-0941</strong></div>
      </div>
    </div>
    <div class="piv-rule"></div>

    <!-- Document Title Band -->
    <div class="piv-doc-title">
      <div class="piv-doc-label">OFFICIAL PATIENT BILLING STATEMENT &amp; PAYMENT RECEIPT</div>
      <div id="pivInvoiceStatusChip" class="piv-status-chip piv-status-pending">PENDING SETTLEMENT</div>
    </div>

    <!-- Invoice Details Bar -->
    <div class="piv-invoice-bar">
      <div class="piv-ib-item">
        <span class="pml">Invoice Number</span>
        <strong class="pmv" id="pivInvoiceNumber">&#x2014;</strong>
      </div>
      <div class="piv-ib-item">
        <span class="pml">Billing Date &amp; Time</span>
        <strong class="pmv" id="pivInvoiceDate">&#x2014;</strong>
      </div>
      <div class="piv-ib-item">
        <span class="pml">Payment Method</span>
        <strong class="pmv" id="pivPaymentMethod">&#x2014;</strong>
      </div>
    </div>

    <!-- Patient Meta Two-Column Grid -->
    <div class="piv-meta-grid piv-patient-grid">
      <div class="piv-meta-block">
        <table class="piv-meta-table">
          <tr><td class="pml">Patient Name</td><td class="pmv" id="pivPatientName">&#x2014;</td></tr>
          <tr><td class="pml">Age / Gender</td><td class="pmv" id="pivPatientAge">&#x2014;</td></tr>
          <tr><td class="pml">Patient UHID</td><td class="pmv" id="pivPatientUhid">&#x2014;</td></tr>
        </table>
      </div>
      <div class="piv-meta-block">
        <table class="piv-meta-table">
          <tr><td class="pml">Ward / Bed</td><td class="pmv" id="pivBedInfo">Outpatient</td></tr>
          <tr><td class="pml">Admitted Date</td><td class="pmv" id="pivAdmittedDate">&#x2014;</td></tr>
          <tr><td class="pml">Discharged Status</td><td class="pmv" id="pivDischargedDate">&#x2014;</td></tr>
        </table>
      </div>
    </div>

    <!-- Itemized Ledger Table -->
    <div class="piv-section-label">ITEMIZED CLINICAL STATEMENT</div>
    <table class="piv-ledger-table">
      <thead>
        <tr>
          <th style="width:5%;text-align:center;">SL#</th>
          <th style="width:16%;">Category</th>
          <th style="width:33%;">Description / Procedure</th>
          <th style="width:20%;">Consultant / Unit</th>
          <th style="width:11%;text-align:right;">Unit Price (&#2547;)</th>
          <th style="width:5%;text-align:center;">Qty</th>
          <th style="width:10%;text-align:right;">Total (&#2547;)</th>
        </tr>
      </thead>
      <tbody id="pivLedgerBody">
      </tbody>
    </table>

    <!-- Financial Summary + PAID Stamp Wrapper -->
    <div class="piv-summary-wrap">

      <!-- PAID Rubber Stamp -->
      <div id="pivPaidStamp" class="piv-paid-stamp" style="display:none;" aria-hidden="true">
        <div class="piv-stamp-inner">
          <div class="piv-stamp-top">MEDPULSE TREASURY</div>
          <div class="piv-stamp-main">PAID</div>
          <div class="piv-stamp-bottom">DATE: <span id="pivStampDate">&#x2014;</span> &bull; CASHIER VERIFIED</div>
        </div>
      </div>

      <!-- Left: Legal disclaimer -->
      <div class="piv-summary-left">
        <div class="piv-verified-line">
          <svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          Verified by MedPulse Central Treasury &amp; Compliance Ledger
        </div>
        <p class="piv-disclaimer">Computer-generated clinical statement. Valid without signature unless otherwise stated. All charges verified per Bangladesh DGHS clinical guidelines (Circular No. DGHS/2026/Billing/041).</p>
        <div class="piv-hash-line">Ledger Hash: <code id="pivLedgerHash">SHA256: loading...</code></div>
      </div>

      <!-- Right: Financial totals -->
      <div class="piv-summary-right">
        <div class="piv-fin-row"><span>Subtotal</span><span id="pivSubtotal">&#2547; 0.00</span></div>
        <div class="piv-fin-row" id="pivVatRow"><span id="pivVatLabel">VAT (5.0%)</span><span id="pivVatAmount">&#2547; 0.00</span></div>
        <div class="piv-fin-row" id="pivDiscountRow" style="display:none;"><span>Institutional Discount</span><span id="pivDiscount" style="color:#15803d;">&#x2212; &#2547; 0.00</span></div>
        <div class="piv-fin-row piv-fin-net"><span>Net Total Payable</span><span id="pivNetPayable">&#2547; 0.00</span></div>
        <div class="piv-fin-row"><span>Amount Paid</span><span id="pivPaidAmount">&#2547; 0.00</span></div>
        <div class="piv-fin-row piv-fin-due" id="pivDueRow"><span>Outstanding Balance</span><span id="pivDueAmount">&#2547; 0.00</span></div>
      </div>
    </div>

    <div class="piv-rule piv-rule-sm"></div>

    <!-- Signature Strip -->
    <div class="piv-sig-strip">
      <div class="piv-sig-col">
        <div class="piv-sig-line"></div>
        <div class="piv-sig-role">Prepared By</div>
        <div class="piv-sig-dept">Billing Desk &amp; Cashier</div>
      </div>
      <div class="piv-sig-col">
        <div class="piv-sig-line"></div>
        <div class="piv-sig-role">Audited By</div>
        <div class="piv-sig-dept">Treasury &amp; Accounts</div>
      </div>
      <div class="piv-sig-col">
        <div class="piv-sig-line"></div>
        <div class="piv-sig-role">Authorized Signatory</div>
        <div class="piv-sig-dept">Medical Superintendent</div>
      </div>
    </div>

    <!-- Official Footer Note & Disclaimer -->
    <div class="piv-footer-note">
      <div>This is a computer-generated clinical billing statement verified by MedPulse Hospital &amp; Research Institute.</div>
      <div>For billing disputes or insurance claims, please contact Accounts &amp; Treasury within 7 working days of issue.</div>
      <div class="piv-footer-sub">Plot 14, Road 7, Medical Zone, Dhaka-1212 &bull; DGHS Reg: HSM-2026-DH-0941 &bull; info@medpulse.hospital</div>
    </div>

  </div><!-- /#printable-invoice-voucher -->

  <!-- Dedicated Admin Billing Management Script -->
  <script src="../assets/js/admin/billing_management.js"></script>
</body>
</html>

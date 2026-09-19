<?php
/**
 * MedPulse — Patient Billing Transparency Portal
 * Patient-scoped, session-locked financial ledger.
 */

// ── Session Hardening ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

// ── RBAC Guard ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'], $_SESSION['role']) || empty($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// ── Inactivity Timeout ─────────────────────────────────────────────────────
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ../login.php');
    exit();
}
$_SESSION['last_activity'] = time();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';

$patientUserId = (int) $_SESSION['user_id'];

// ── AJAX: Itemised Receipt ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_receipt') {
    header('Content-Type: application/json; charset=utf-8');
    $invId = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$invId) { echo json_encode(['success' => false, 'message' => 'Invalid invoice.']); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT i.invoice_id, i.invoice_number, i.admission_id,
                   i.subtotal, i.vat_percentage, i.discount,
                   i.net_payable, i.paid_amount, i.due_amount,
                   i.payment_method, i.status, i.created_at,
                   u.full_name AS patient_name, u.phone AS patient_phone, u.email AS patient_email,
                   hb.bed_number, hb.ward_type,
                   ba.admitted_at, ba.discharged_at
            FROM invoices i
            JOIN users u ON i.patient_id = u.user_id
            LEFT JOIN bed_allocations ba ON i.admission_id = ba.allocation_id
            LEFT JOIN hospital_beds   hb ON ba.bed_id = hb.bed_id
            WHERE i.invoice_id = ? AND i.patient_id = ?
            LIMIT 1
        ");
        $stmt->execute([$invId, $patientUserId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { echo json_encode(['success' => false, 'message' => 'Record not found or access denied.']); exit; }

        $itemStmt = $pdo->prepare("
            SELECT ii.item_type, ii.description, ii.quantity, ii.unit_price, ii.total_price,
                   doc.full_name AS doctor_name, dp.specialty AS doctor_specialty,
                   dp.designation AS doctor_designation, dp.military_rank AS doctor_military_rank
            FROM invoice_items ii
            LEFT JOIN users           doc ON ii.doctor_id = doc.user_id
            LEFT JOIN doctor_profiles dp  ON doc.user_id  = dp.user_id
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
        echo json_encode(['success' => true, 'invoice' => $invoice, 'items' => $items]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}

// ── Patient Identity ───────────────────────────────────────────────────────
try {
    $pStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE user_id = ? AND role = 'Patient' LIMIT 1");
    $pStmt->execute([$patientUserId]);
    $patient = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { header('Location: ../login.php'); exit(); }
} catch (PDOException $e) { die('A database failure occurred.'); }

// ── Summary Aggregates ─────────────────────────────────────────────────────
try {
    $sumStmt = $pdo->prepare("
        SELECT COALESCE(SUM(net_payable),0) AS total_billed,
               COALESCE(SUM(paid_amount),0) AS total_paid,
               COALESCE(SUM(due_amount),0)  AS total_due,
               COUNT(*)                      AS invoice_count
        FROM invoices WHERE patient_id = ?
    ");
    $sumStmt->execute([$patientUserId]);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $summary = ['total_billed'=>0,'total_paid'=>0,'total_due'=>0,'invoice_count'=>0];
}

// ── Invoice List ───────────────────────────────────────────────────────────
try {
    $listStmt = $pdo->prepare("
        SELECT i.invoice_id, i.invoice_number, i.net_payable, i.paid_amount, i.due_amount,
               i.payment_method, i.status, i.created_at,
               hb.bed_number, hb.ward_type, ba.admitted_at, ba.discharged_at,
               GROUP_CONCAT(DISTINCT doc.full_name SEPARATOR ', ') AS attending_doctors
        FROM invoices i
        LEFT JOIN bed_allocations ba  ON i.admission_id = ba.allocation_id
        LEFT JOIN hospital_beds   hb  ON ba.bed_id = hb.bed_id
        LEFT JOIN invoice_items   ii  ON i.invoice_id = ii.invoice_id AND ii.doctor_id IS NOT NULL
        LEFT JOIN users           doc ON ii.doctor_id = doc.user_id
        WHERE i.patient_id = ?
        GROUP BY i.invoice_id, i.invoice_number, i.net_payable, i.paid_amount,
                 i.due_amount, i.payment_method, i.status, i.created_at,
                 hb.bed_number, hb.ward_type, ba.admitted_at, ba.discharged_at
        ORDER BY i.created_at DESC
    ");
    $listStmt->execute([$patientUserId]);
    $invoices = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $invoices = []; }

$patientName = htmlspecialchars($patient['full_name'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | My Bills &amp; Financial Records</title>
  <meta name="description" content="View your complete, itemised hospital billing records, doctor consultation fees, and payment history.">
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%' y1='0%' x2='0%' y2='100%'><stop offset='0%' stop-color='%230284c7'/><stop offset='100%' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    .billing-root{display:flex;min-height:100vh;width:100%;}
    .bills-main{flex:1;padding:28px 32px;overflow-y:auto;max-width:1240px;margin:0 auto;width:100%;}
    @media(max-width:900px){.bills-main{padding:20px 16px;} .invoice-table-wrap{overflow-x:auto;}}

    /* No-cash banner */
    .nocash-banner{display:flex;align-items:flex-start;gap:14px;background:linear-gradient(135deg,#fff7ed,#fef3c7);border:1px solid #f59e0b;border-left:4px solid #f59e0b;border-radius:14px;padding:18px 22px;margin-bottom:26px;}
    .nocash-banner .nb-icon{width:40px;height:40px;background:#f59e0b;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .nocash-banner h3{font-size:.92rem;font-weight:700;color:#92400e;margin-bottom:4px;}
    .nocash-banner p{font-size:.8rem;color:#a16207;line-height:1.5;}

    /* Summary grid */
    .summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:30px;}
    @media(max-width:720px){.summary-grid{grid-template-columns:1fr;}}
    .summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:22px 24px;position:relative;overflow:hidden;transition:box-shadow .22s,transform .22s;}
    .summary-card:hover{box-shadow:0 8px 28px rgba(0,0,0,.08);transform:translateY(-2px);}
    .summary-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
    .sc-billed::before{background:linear-gradient(90deg,#0284c7,#0d9488);}
    .sc-paid::before{background:linear-gradient(90deg,#059669,#10b981);}
    .sc-due::before{background:linear-gradient(90deg,#dc2626,#ef4444);}
    .sc-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:10px;}
    .sc-amount{font-size:2rem;font-weight:800;letter-spacing:-.04em;line-height:1;margin-bottom:6px;}
    .sc-billed .sc-amount{color:#0284c7;} .sc-paid .sc-amount{color:#059669;} .sc-due .sc-amount{color:#dc2626;}
    .sc-note{font-size:.76rem;color:#94a3b8;}

    /* Page header */
    .page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px;}
    .page-header h1{font-size:1.4rem;font-weight:800;color:#0f172a;margin-bottom:4px;}
    .page-header p{font-size:.83rem;color:#64748b;}
    .back-link{display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:8px 14px;font-size:.8rem;font-weight:600;color:#475569;text-decoration:none;transition:background .15s;}
    .back-link:hover{background:#e2e8f0;}
    .back-link svg{stroke:#475569;width:15px;height:15px;}

    /* Section title */
    .section-title{font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:16px;display:flex;align-items:center;gap:10px;}
    .section-title svg{stroke:#0284c7;}

    /* Invoice table */
    .invoice-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow-x:auto;margin-bottom:30px;}
    .invoice-table{width:100%;border-collapse:collapse;font-size:.83rem;}
    .invoice-table thead tr{background:linear-gradient(135deg,#f8fafc,#f1f5f9);}
    .invoice-table th{padding:13px 16px;text-align:left;font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e2e8f0;white-space:nowrap;}
    .invoice-table th:last-child, .invoice-table td:last-child{min-width:210px;}
    .invoice-table tbody tr{border-bottom:1px solid #f1f5f9;transition:background .15s;}
    .invoice-table tbody tr:last-child{border-bottom:none;}
    .invoice-table tbody tr:hover{background:#f8fafc;}
    .invoice-table td{padding:14px 16px;color:#334155;vertical-align:middle;}
    .inv-number{font-family:'Courier New',monospace;font-weight:700;font-size:.78rem;color:#0284c7;}
    .inv-amount{font-weight:700;color:#0f172a;} .inv-due{font-weight:700;color:#dc2626;} .inv-paid{font-weight:700;color:#059669;} .inv-zero{color:#94a3b8;}
    .badge-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
    .badge-paid{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;}
    .badge-partial{background:#fef3c7;color:#d97706;border:1px solid #fcd34d;}
    .badge-pending{background:#ffe4e6;color:#dc2626;border:1px solid #fca5a5;}
    .badge-draft{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;}
    .doctor-pill{display:inline-block;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:999px;padding:2px 9px;font-size:.7rem;font-weight:600;margin:1px 2px;white-space:nowrap;}
    .btn-view-receipt{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#0284c7,#0d9488);color:#fff;border:none;cursor:pointer;border-radius:8px;padding:7px 14px;font-size:.74rem;font-weight:700;transition:opacity .18s,transform .18s;font-family:inherit;}
    .btn-view-receipt:hover{opacity:.88;transform:scale(1.02);}
    .btn-view-receipt svg{stroke:#fff;width:14px;height:14px;}
    .btn-pay-gateway{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;cursor:pointer;border-radius:8px;padding:7px 12px;font-size:.74rem;font-weight:700;transition:opacity .18s,transform .18s;font-family:inherit;white-space:nowrap;}
    .btn-pay-gateway:hover{opacity:.88;transform:scale(1.02);}
    .btn-pay-gateway svg{stroke:#fff;width:13px;height:13px;}
    /* Download Official Receipt (PDF) — routes to centralized generate_invoice_pdf.php */
    .btn-download-pdf{display:inline-flex;align-items:center;gap:6px;background:#0f172a;color:#fff;border-radius:8px;padding:7px 14px;font-size:.74rem;font-weight:700;text-decoration:none;transition:opacity .18s,transform .18s;justify-content:center;width:100%;white-space:nowrap;}
    .btn-download-pdf:hover{opacity:.85;transform:scale(1.02);color:#fff;}
    .btn-download-pdf svg{stroke:#fff;width:14px;height:14px;flex-shrink:0;}
    .empty-bills{text-align:center;padding:64px 24px;}
    .empty-bills svg{stroke:#cbd5e1;margin-bottom:16px;}
    .empty-bills h3{font-size:1rem;font-weight:700;color:#64748b;margin-bottom:6px;}
    .empty-bills p{font-size:.82rem;color:#94a3b8;}

    /* Modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(5px);z-index:900;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s;}
    .modal-backdrop.open{opacity:1;pointer-events:all;}
    .receipt-modal{background:#fff;border-radius:20px;box-shadow:0 32px 80px rgba(0,0,0,.22);width:100%;max-width:680px;max-height:90vh;overflow-y:auto;transform:translateY(20px) scale(.97);transition:transform .28s cubic-bezier(.34,1.56,.64,1);scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent;}
    .modal-backdrop.open .receipt-modal{transform:translateY(0) scale(1);}
    .modal-header{padding:24px 28px 0;display:flex;align-items:flex-start;justify-content:space-between;}
    .modal-title{font-size:1.05rem;font-weight:800;color:#0f172a;margin-bottom:2px;}
    .modal-subtitle{font-size:.78rem;color:#94a3b8;}
    .modal-close{background:#f1f5f9;border:none;border-radius:10px;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s;}
    .modal-close:hover{background:#e2e8f0;}
    .modal-close svg{stroke:#64748b;width:18px;height:18px;}
    .modal-body{padding:20px 28px 28px;}
    .receipt-header-band{background:linear-gradient(135deg,#0284c7,#0d9488);border-radius:14px;padding:20px 22px;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:22px;flex-wrap:wrap;}
    .rhb-label{font-size:.7rem;font-weight:700;opacity:.75;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
    .rhb-value{font-size:.95rem;font-weight:700;} .rhb-inv{font-family:'Courier New',monospace;font-size:1.05rem;letter-spacing:.04em;}
    .admission-strip{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;font-size:.79rem;}
    .admission-strip span{color:#64748b;} .admission-strip strong{color:#0f172a;font-weight:700;}
    .receipt-items-table{width:100%;border-collapse:collapse;margin-bottom:22px;font-size:.82rem;}
    .receipt-items-table th{background:#f8fafc;padding:9px 12px;text-align:left;font-size:.67rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e2e8f0;}
    .receipt-items-table th:last-child,.receipt-items-table td:last-child{text-align:right;}
    .receipt-items-table td{padding:11px 12px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:top;}
    .receipt-items-table tbody tr:last-child td{border-bottom:none;}
    .item-type-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
    .chip-bed{background:#e0f2fe;color:#0369a1;} .chip-doctor{background:#f3e8ff;color:#7c3aed;} .chip-diagnostic{background:#fef9c3;color:#a16207;} .chip-other{background:#f1f5f9;color:#475569;}
    .doctor-attr{font-size:.72rem;color:#7c3aed;font-weight:600;margin-top:3px;}
    .receipt-totals{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:20px;}
    .totals-row{display:flex;justify-content:space-between;padding:6px 0;font-size:.82rem;color:#475569;border-bottom:1px solid #f1f5f9;}
    .totals-row:last-child{border-bottom:none;}
    .totals-row.net{font-size:1rem;font-weight:800;color:#0f172a;padding-top:12px;margin-top:4px;}
    .totals-row.paid-row{color:#059669;font-weight:700;} .totals-row.due-row{color:#dc2626;font-weight:700;}
    .btn-print-receipt{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#0284c7,#0d9488);color:#fff;border:none;cursor:pointer;border-radius:10px;padding:11px 22px;font-size:.84rem;font-weight:700;transition:opacity .18s,transform .18s;font-family:inherit;width:100%;justify-content:center;}
    .btn-print-receipt:hover{opacity:.88;transform:translateY(-1px);}
    .modal-loading{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;padding:48px 24px;color:#94a3b8;}
    .modal-loading .spinner{width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#0284c7;border-radius:50%;animation:spin .8s linear infinite;}
    @keyframes spin{to{transform:rotate(360deg);}}

    /* Print */
    @media print {
      body * { visibility: hidden; }
      #printRegion, #printRegion * { visibility: visible; }
      #printRegion { position: fixed; inset: 0; background: #fff; padding: 32px; font-size: 12pt; }
      .btn-print-receipt, .modal-close { display: none !important; }
    }
  </style>
</head>
<body>
<div class="billing-root">

  <!-- Receipt Modal -->
  <div class="modal-backdrop" id="receiptBackdrop" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
    <div class="receipt-modal" id="receiptModal">
      <div id="modalContent">
        <div class="modal-loading"><div class="spinner"></div><span>Loading…</span></div>
      </div>
    </div>
  </div>

  <!-- Print Region -->
  <div id="printRegion" style="display:none;"></div>

  <!-- Main Content -->
  <main class="bills-main" id="main-billing">

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <h1>
          <svg style="display:inline;vertical-align:-4px;stroke:#0284c7;width:26px;height:26px;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          My Bills &amp; Financial Records
        </h1>
        <p>Secure financial ledger for <strong><?= $patientName ?></strong> — all charges itemised and transparent.</p>
      </div>
      <a href="dashboard.php" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Dashboard
      </a>
    </div>

    <!-- No-Cash Banner -->
    <div class="nocash-banner" role="alert">
      <div class="nb-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </div>
      <div>
        <h3>⚠️ Do Not Pay Cash to Any Individual</h3>
        <p>
          MedPulse Hospital uses a fully digital payment system. All payments must be made through the
          <strong>official hospital cashier counter</strong> or the online payment portal.
          Never hand cash directly to any doctor, nurse, or staff member.
          If asked, please <strong>report it immediately</strong> to Hospital Administration (Ext. 220).
          All receipts generated here are official digital records.
        </p>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
      <div class="summary-card sc-billed">
        <div class="sc-label">Total Billed</div>
        <div class="sc-amount">&#2547;<?= number_format((float)$summary['total_billed'], 2) ?></div>
        <div class="sc-note"><?= (int)$summary['invoice_count'] ?> invoice<?= $summary['invoice_count'] != 1 ? 's' : '' ?> issued</div>
      </div>
      <div class="summary-card sc-paid">
        <div class="sc-label">Paid So Far</div>
        <div class="sc-amount">&#2547;<?= number_format((float)$summary['total_paid'], 2) ?></div>
        <div class="sc-note">Verified &amp; settled payments</div>
      </div>
      <div class="summary-card sc-due">
        <div class="sc-label">Outstanding Balance</div>
        <div class="sc-amount">&#2547;<?= number_format((float)$summary['total_due'], 2) ?></div>
        <div class="sc-note"><?= $summary['total_due'] > 0 ? 'Please settle at the cashier counter' : 'All clear — no pending dues' ?></div>
      </div>
    </div>

    <!-- Invoice History -->
    <div class="section-title">
      <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Invoice History
    </div>

    <div class="invoice-table-wrap">
      <?php if (empty($invoices)): ?>
        <div class="empty-bills">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="64" height="64"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <h3>No Invoices Found</h3>
          <p>Your billing records will appear here once an invoice has been generated by the Accounts team.</p>
        </div>
      <?php else: ?>
        <table class="invoice-table" id="invoiceTable">
          <thead>
            <tr>
              <th>Invoice #</th>
              <th>Date</th>
              <th>Ward / Bed</th>
              <th>Attending Physicians</th>
              <th>Total</th>
              <th>Paid</th>
              <th>Due</th>
              <th>Status</th>
              <th>Actions / Statement</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invoices as $inv):
              $statusBadge = match(strtolower($inv['status'])) {
                'paid'    => '<span class="badge-status badge-paid">&#9679; Paid</span>',
                'partial' => '<span class="badge-status badge-partial">&#9680; Partial</span>',
                'pending' => '<span class="badge-status badge-pending">&#9675; UNPAID / ACTION REQUIRED</span>',
                default   => '<span class="badge-status badge-draft">' . htmlspecialchars($inv['status'], ENT_QUOTES, 'UTF-8') . '</span>',
              };
              $dueClass = (float)$inv['due_amount'] > 0 ? 'inv-due' : 'inv-zero';
              $doctors  = $inv['attending_doctors'] ?? '';
            ?>
            <tr>
              <td><span class="inv-number"><?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
              <td>
                <?php if ($inv['bed_number']): ?>
                  <strong><?= htmlspecialchars($inv['bed_number'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                  <small style="color:#94a3b8;"><?= htmlspecialchars($inv['ward_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                <?php else: ?>
                  <span style="color:#94a3b8;">Outpatient</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($doctors): ?>
                  <?php foreach (explode(', ', $doctors) as $doc): ?>
                    <span class="doctor-pill"><?= htmlspecialchars(trim($doc), ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span style="color:#94a3b8;font-size:.75rem;">&#8212;</span>
                <?php endif; ?>
              </td>
              <td class="inv-amount">&#2547;<?= number_format((float)$inv['net_payable'], 2) ?></td>
              <td class="inv-paid">&#2547;<?= number_format((float)$inv['paid_amount'], 2) ?></td>
              <td class="<?= $dueClass ?>">&#2547;<?= number_format((float)$inv['due_amount'], 2) ?></td>
              <td><?= $statusBadge ?></td>
              <td>
                <div style="display:flex;flex-direction:column;gap:5px;align-items:stretch;min-width:160px;max-width:215px;">
                  <button
                    class="btn-view-receipt"
                    id="btn-receipt-<?= (int)$inv['invoice_id'] ?>"
                    data-invoice-id="<?= (int)$inv['invoice_id'] ?>"
                    onclick="openReceipt(<?= (int)$inv['invoice_id'] ?>, '<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>')"
                    aria-label="View breakdown for invoice <?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>"
                    title="View Itemized Breakdown"
                    style="justify-content:center;width:100%;"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    View Breakdown
                  </button>
                  <!-- Download Official Receipt via centralized PDF engine (session-gated, ownership-checked) -->
                  <a
                    href="../includes/generate_invoice_pdf.php?invoice_id=<?= (int)$inv['invoice_id'] ?>&download=1"
                    class="btn-download-pdf"
                    target="_blank"
                    rel="noopener noreferrer"
                    id="btn-pdf-<?= (int)$inv['invoice_id'] ?>"
                    aria-label="Download Official Receipt PDF for invoice <?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>"
                    title="Download Official Receipt (PDF)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download Official Receipt
                  </a>
                  <?php if ((float)$inv['due_amount'] > 0 || strtolower($inv['status']) !== 'paid'): ?>
                    <button
                      class="btn-pay-gateway"
                      onclick="openPaymentGuidance('<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>', <?= (float)$inv['due_amount'] ?>)"
                      title="Pay via Central Cashier or Digital Gateway"
                      style="justify-content:center;width:100%;"
                    >
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                      Pay via Central Cashier / Gateway
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <p style="font-size:.74rem;color:#94a3b8;text-align:center;margin-top:8px;line-height:1.6;">
      This is an official electronic financial record of MedPulse Hospital &amp; Specialty Care.
      For disputes or billing queries, contact the Accounts Department at Ext.&nbsp;220.
    </p>

  </main>
</div>

<script>
const backdrop     = document.getElementById('receiptBackdrop');
const modalContent = document.getElementById('modalContent');

function openReceipt(invoiceId, invoiceNumber) {
  modalContent.innerHTML = `<div class="modal-loading"><div class="spinner"></div><span>Loading receipt for ${escHtml(invoiceNumber)}&hellip;</span></div>`;
  backdrop.classList.add('open');
  document.body.style.overflow = 'hidden';

  fetch(`my_bills.php?action=get_receipt&invoice_id=${encodeURIComponent(invoiceId)}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        modalContent.innerHTML = `<div class="modal-loading" style="color:#ef4444;padding:48px 24px;"><span>${escHtml(data.message || 'Failed to load receipt.')}</span></div>`;
        return;
      }
      renderReceipt(data.invoice, data.items);
    })
    .catch(() => {
      modalContent.innerHTML = `<div class="modal-loading" style="color:#ef4444;"><span>Network error. Please try again.</span></div>`;
    });
}

function closeReceipt() {
  backdrop.classList.remove('open');
  document.body.style.overflow = '';
}

function renderReceipt(inv, items) {
  const fmt  = v => '\u09F3' + parseFloat(v || 0).toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2});
  const fmtD = s => s ? new Date(s).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '\u2014';

  const statusClass = {
    'Paid':'badge-paid','paid':'badge-paid',
    'Partial':'badge-partial','partial':'badge-partial',
    'Pending':'badge-pending','pending':'badge-pending'
  }[inv.status] || 'badge-draft';

  let rowsHtml = '';
  if (items && items.length) {
    items.forEach(it => {
      const t = (it.item_type || '').toLowerCase();
      const chipClass = t.includes('bed') ? 'chip-bed'
                      : (t.includes('doctor') || t.includes('consult') || t.includes('visit')) ? 'chip-doctor'
                      : (t.includes('diag') || t.includes('lab') || t.includes('test')) ? 'chip-diagnostic'
                      : 'chip-other';
      const docHtml = it.doctor_name
        ? `<div class="doctor-attr">\u{1F3E5} ${escHtml(it.doctor_name)}${it.doctor_specialty ? ' &middot; ' + escHtml(it.doctor_specialty) : ''}</div>` : '';
      rowsHtml += `<tr>
        <td><span class="item-type-chip ${chipClass}">${escHtml(it.item_type||'Service')}</span><br>
            <span style="color:#0f172a;font-weight:600;">${escHtml(it.description)}</span>${docHtml}</td>
        <td style="text-align:center;">${parseInt(it.quantity)||1}</td>
        <td style="text-align:right;">${fmt(it.unit_price)}</td>
        <td style="text-align:right;font-weight:700;color:#0f172a;">${fmt(it.total_price)}</td>
      </tr>`;
    });
  } else {
    rowsHtml = `<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:24px;">No line items on record.</td></tr>`;
  }

  const admHtml = (inv.bed_number || inv.admitted_at) ? `
    <div class="admission-strip">
      ${inv.bed_number ? `<div><span>Ward / Bed &nbsp;</span><strong>${escHtml(inv.bed_number)} &middot; ${escHtml(inv.ward_type||'')}</strong></div>` : ''}
      ${inv.admitted_at ? `<div><span>Admitted &nbsp;</span><strong>${fmtD(inv.admitted_at)}</strong></div>` : ''}
      ${inv.discharged_at ? `<div><span>Discharged &nbsp;</span><strong>${fmtD(inv.discharged_at)}</strong></div>` : ''}
    </div>` : '';

  const vatLine  = parseFloat(inv.vat_percentage||0) > 0
    ? `<div class="totals-row"><span>VAT (${parseFloat(inv.vat_percentage).toFixed(1)}%)</span><span>${fmt(parseFloat(inv.subtotal)*parseFloat(inv.vat_percentage)/100)}</span></div>` : '';
  const discLine = parseFloat(inv.discount||0) > 0
    ? `<div class="totals-row"><span>Discount</span><span style="color:#059669;">&minus; ${fmt(inv.discount)}</span></div>` : '';

  modalContent.innerHTML = `
    <div class="modal-header">
      <div>
        <div class="modal-title" id="receiptModalTitle">Official Payment Receipt</div>
        <div class="modal-subtitle">MedPulse Hospital &amp; Specialty Care</div>
      </div>
      <button class="modal-close" onclick="closeReceipt()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="modal-body">
      <div class="receipt-header-band">
        <div><div class="rhb-label">Invoice #</div><div class="rhb-value rhb-inv">${escHtml(inv.invoice_number)}</div></div>
        <div><div class="rhb-label">Patient</div><div class="rhb-value">${escHtml(inv.patient_name)}</div></div>
        <div><div class="rhb-label">Issue Date</div><div class="rhb-value">${fmtD(inv.created_at)}</div></div>
        <div><div class="rhb-label">Status</div><div><span class="badge-status ${statusClass}">${escHtml(inv.status)}</span></div></div>
      </div>
      ${admHtml}
      <table class="receipt-items-table">
        <thead><tr><th>Description</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Total</th></tr></thead>
        <tbody>${rowsHtml}</tbody>
      </table>
      <div class="receipt-totals">
        <div class="totals-row"><span>Subtotal</span><span>${fmt(inv.subtotal)}</span></div>
        ${vatLine}${discLine}
        <div class="totals-row net"><span>Net Payable</span><span>${fmt(inv.net_payable)}</span></div>
        <div class="totals-row paid-row"><span>Amount Paid</span><span>${fmt(inv.paid_amount)}</span></div>
        <div class="totals-row due-row"><span>Outstanding Due</span><span>${fmt(inv.due_amount)}</span></div>
      </div>
      ${inv.payment_method ? `<p style="font-size:.78rem;color:#64748b;margin-bottom:16px;"><strong>Payment Method:</strong> ${escHtml(inv.payment_method)}</p>` : ''}
      <div style="background:#fef9c3;border:1px solid #fbbf24;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:.75rem;color:#92400e;line-height:1.6;">
        &#9888;&#65039; <strong>Official Record:</strong> This receipt is electronically generated and valid without a physical signature.
        Do not pay cash to any individual. All payments must go through the official cashier or online portal.
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <!-- Official PDF receipt via centralized generator (opens new tab, auto-triggers print dialog) -->
        <a
          href="../includes/generate_invoice_pdf.php?invoice_id=${encodeURIComponent(inv.invoice_id)}&download=1"
          class="btn-print-receipt"
          style="flex:1;text-decoration:none;justify-content:center;"
          target="_blank"
          rel="noopener noreferrer"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="17" height="17"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download Official Receipt (PDF)
        </a>
        ${parseFloat(inv.due_amount||0) > 0 ? `
        <button class="btn-pay-gateway" style="flex:1;justify-content:center;padding:11px 22px;font-size:.84rem;" onclick="openPaymentGuidance('${escHtml(inv.invoice_number)}', ${parseFloat(inv.due_amount)})">
          <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Pay via Cashier / Gateway
        </button>` : ''}
      </div>
    </div>`;
}

function openPaymentGuidance(invNumber, dueAmount) {
  const fmt = v => '\u09F3' + parseFloat(v || 0).toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2});
  modalContent.innerHTML = `
    <div class="modal-header">
      <div>
        <div class="modal-title" id="receiptModalTitle">Payment Guidance & Cashier Desk</div>
        <div class="modal-subtitle">Invoice ${escHtml(invNumber)} &middot; Outstanding Due: ${fmt(dueAmount)}</div>
      </div>
      <button class="modal-close" onclick="closeReceipt()" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="modal-body">
      <div style="background:linear-gradient(135deg,#059669,#047857);border-radius:14px;padding:20px 22px;color:#fff;margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
          <div>
            <div style="font-size:.7rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.05em;">Treasury Reference</div>
            <div style="font-family:'Courier New',monospace;font-size:1.15rem;font-weight:800;letter-spacing:.04em;">${escHtml(invNumber)}</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:.7rem;font-weight:700;opacity:.8;text-transform:uppercase;letter-spacing:.05em;">Payable Due</div>
            <div style="font-size:1.25rem;font-weight:800;">${fmt(dueAmount)}</div>
          </div>
        </div>
      </div>

      <div style="display:grid;gap:12px;margin-bottom:22px;">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;gap:14px;align-items:flex-start;">
          <div style="width:34px;height:34px;border-radius:8px;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0;">1</div>
          <div>
            <div style="font-size:.84rem;font-weight:700;color:#0f172a;margin-bottom:3px;">MedPulse Central Treasury Cashier</div>
            <div style="font-size:.76rem;color:#64748b;line-height:1.5;">Present Invoice Number <strong>${escHtml(invNumber)}</strong> at Cashier Counters 1–4 (Ground Floor Main Lobby). Accepted modes: Cash, VISA, MasterCard, or Bank Draft. A computer-stamped voucher will be issued immediately.</div>
          </div>
        </div>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;gap:14px;align-items:flex-start;">
          <div style="width:34px;height:34px;border-radius:8px;background:#dcfce7;color:#15803d;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0;">2</div>
          <div>
            <div style="font-size:.84rem;font-weight:700;color:#0f172a;margin-bottom:3px;">Digital Payment Gateway (bKash / Nagad / Rocket)</div>
            <div style="font-size:.76rem;color:#64748b;line-height:1.5;">Select <em>Merchant Payment</em> &rarr; Merchant Wallet: <strong>01700-MEDPULSE</strong> &rarr; Amount: <strong>${parseFloat(dueAmount).toFixed(2)}</strong> &rarr; Reference: <strong>${escHtml(invNumber)}</strong>. Keep your Transaction ID for cashier reconciliation.</div>
          </div>
        </div>
      </div>

      <div style="background:#fef9c3;border:1px solid #fbbf24;border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:.75rem;color:#92400e;line-height:1.6;">
        &#9888;&#65039; <strong>Notice:</strong> Following payment at Central Cashier, your patient ledger and discharge gate pass will unlock automatically in real time.
      </div>

      <button class="btn-print-receipt" style="background:#0f172a;" onclick="closeReceipt()">
        Close Window
      </button>
    </div>
  `;
  backdrop.classList.add('open');
  document.body.style.overflow = 'hidden';
}

// printReceipt() removed — PDF generation is handled by includes/generate_invoice_pdf.php (centralized engine)

document.addEventListener('keydown', e => { if (e.key === 'Escape' && backdrop.classList.contains('open')) closeReceipt(); });
backdrop.addEventListener('click', e => { if (e.target === backdrop) closeReceipt(); });

function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>

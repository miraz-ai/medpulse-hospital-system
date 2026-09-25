<?php
/**
 * MedPulse Hospital & Specialty Care
 * Centralized Invoice PDF Generator — Shared by Admin & Patient Portals
 *
 * Endpoint:  includes/generate_invoice_pdf.php
 * Params:
 *   invoice_id  (int, required)   — the invoice to render
 *   download    (int, optional)   — 1 = Content-Disposition: attachment, 0/absent = inline
 *
 * Security:
 *   - Session must be active and authenticated.
 *   - If role === "Patient", strict ownership check: invoice patient_id must match session user_id.
 *   - Unauthenticated access redirects to ../login.php.
 */

// ── Session Hardening ────────────────────────────────────────────────────────
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

// ── Auth Guard ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'], $_SESSION['role']) || empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// ── Inactivity Timeout (30 min) ──────────────────────────────────────────────
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit();
}
$_SESSION['last_activity'] = time();

// ── No-Cache Headers ─────────────────────────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ── Input Validation ─────────────────────────────────────────────────────────
$invoiceId  = filter_var($_GET['invoice_id'] ?? 0, FILTER_VALIDATE_INT);
$isDownload = !empty($_GET['download']) && $_GET['download'] === '1';

if (!$invoiceId || $invoiceId <= 0) {
    http_response_code(400);
    die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px;">Error: Invalid or missing invoice_id parameter.</h2>');
}

// ── DB & Helpers ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/doctor_helpers.php';

$sessionUserId = (int) $_SESSION['user_id'];
$sessionRole   = $_SESSION['role'];

// ── Fetch Invoice Master Record ───────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            i.invoice_id,
            i.invoice_number,
            i.hospital_id,
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
            u.full_name    AS patient_name,
            u.phone        AS patient_phone,
            u.email        AS patient_email,
            u.gender       AS patient_gender,
            u.age          AS patient_age,
            u.blood_group  AS patient_blood_group,
            hb.bed_number,
            hb.ward_type,
            ba.admitted_at,
            ba.discharged_at
        FROM invoices i
        JOIN users u ON i.patient_id = u.user_id
        LEFT JOIN bed_allocations ba ON i.admission_id = ba.allocation_id
        LEFT JOIN hospital_beds   hb ON ba.bed_id = hb.bed_id
        WHERE i.invoice_id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px;">Database error. Please try again later.</h2>');
}

if (!$invoice) {
    http_response_code(404);
    die('<h2 style="font-family:sans-serif;color:#64748b;padding:40px;">Invoice not found.</h2>');
}

// ── Multi-Branch Isolation & Ownership Gatekeeper ────────────────────────────
if ($sessionRole === 'Admin') {
    $sessionHospitalId = (int)($_SESSION['hospital_id'] ?? 1);
    if ((int)$invoice['hospital_id'] !== $sessionHospitalId) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px;">Access Denied: Record belongs to another facility.</h2>');
    }
} elseif ($sessionRole === 'Patient') {
    if ((int) $invoice['patient_id'] !== $sessionUserId) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px;">Access Denied: You are not authorized to view or download this billing statement.</h2>');
    }
}

// ── Fetch Invoice Line Items ──────────────────────────────────────────────────
try {
    $itemStmt = $pdo->prepare("
        SELECT
            ii.item_type,
            ii.description,
            ii.quantity,
            ii.unit_price,
            ii.total_price,
            doc.full_name          AS doctor_name,
            dp.specialty           AS doctor_specialty,
            dp.designation         AS doctor_designation,
            dp.military_rank       AS doctor_military_rank,
            dp.qualifications      AS doctor_qualifications
        FROM invoice_items ii
        LEFT JOIN users           doc ON ii.doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp  ON doc.user_id  = dp.user_id
        WHERE ii.invoice_id = ?
        ORDER BY ii.item_id ASC
    ");
    $itemStmt->execute([$invoiceId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items = [];
}

// ── Format Doctor Titles ──────────────────────────────────────────────────────
foreach ($items as &$it) {
    if (!empty($it['doctor_name'])) {
        $it['doctor_display'] = formatDoctorFullIdentity(
            $it['doctor_name'],
            $it['doctor_designation'] ?? null,
            $it['doctor_military_rank'] ?? null,
            $it['doctor_qualifications'] ?? null
        );
    } else {
        $it['doctor_display'] = '';
    }
}
unset($it);

// ── SHA-256 Ledger Hash (server-side, deterministic) ─────────────────────────
$hashSource = ($invoice['invoice_number'] ?? '') . '|'
            . ($invoice['net_payable']    ?? '') . '|'
            . ($invoice['patient_id']     ?? '') . '|'
            . ($invoice['created_at']     ?? '');
$fullHash   = hash('sha256', $hashSource);
$ledgerHash = 'SHA256: ' . strtoupper(substr($fullHash, 0, 16)) . '...' . strtoupper(substr($fullHash, -8));

// ── Formatting Helpers ────────────────────────────────────────────────────────
function fmtBDT(float $v): string {
    return 'BDT ' . number_format($v, 2);
}
function fmtDate(?string $d): string {
    if (empty($d) || $d === '0000-00-00 00:00:00') return '&mdash;';
    try { return (new DateTime($d))->format('d M Y'); }
    catch (Exception $e) { return htmlspecialchars((string)$d, ENT_QUOTES, 'UTF-8'); }
}
function fmtDateTime(?string $d): string {
    if (empty($d) || $d === '0000-00-00 00:00:00') return '&mdash;';
    try { return (new DateTime($d))->format('d M Y, h:i A'); }
    catch (Exception $e) { return htmlspecialchars((string)$d, ENT_QUOTES, 'UTF-8'); }
}
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── Derived Display Values ────────────────────────────────────────────────────
$isPaid      = strtolower($invoice['status']) === 'paid';
$isPartial   = strtolower($invoice['status']) === 'partial';
$statusLabel = $isPaid    ? 'FULLY SETTLED'
             : ($isPartial ? 'PARTIALLY PAID' : 'PENDING SETTLEMENT');
$statusColor = $isPaid    ? '#15803d'
             : ($isPartial ? '#d97706' : '#dc2626');
$statusBg    = $isPaid    ? '#dcfce7'
             : ($isPartial ? '#fef9c3' : '#fee2e2');

$patientUhid = 'UHID-' . str_pad((string)($invoice['patient_id'] ?? 0), 6, '0', STR_PAD_LEFT);
$agePart     = !empty($invoice['patient_age'])    ? $invoice['patient_age'] . ' yrs' : '';
$genderPart  = !empty($invoice['patient_gender']) ? $invoice['patient_gender']        : '';
$ageGender   = trim($agePart . ($agePart && $genderPart ? ', ' : '') . $genderPart);

$vatPct      = (float)($invoice['vat_percentage'] ?? 0);
$vatAmt      = round((float)($invoice['subtotal'] ?? 0) * $vatPct / 100, 2);
$discount    = (float)($invoice['discount'] ?? 0);

// ── Logo: Base64 embed ────────────────────────────────────────────────────────
$logoPath = __DIR__ . '/../assets/images/logo.png';
$logoB64  = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';
$logoSrc  = $logoB64 ? 'data:image/png;base64,' . $logoB64 : '';

// ── Content-Disposition Header ────────────────────────────────────────────────
$safeInvNum = preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice['invoice_number'] ?? 'invoice');
if ($isDownload) {
    header('Content-Disposition: attachment; filename="MedPulse_Invoice_' . $safeInvNum . '.html"');
} else {
    header('Content-Disposition: inline');
}
header('Content-Type: text/html; charset=UTF-8');

// ── Build Line Items HTML ─────────────────────────────────────────────────────
$rowsHtml = '';
$sl = 1;
foreach ($items as $it) {
    $typeLabel  = esc(strtoupper($it['item_type'] ?? 'SERVICE'));
    $desc       = esc($it['description'] ?? '');
    $docDisplay = esc($it['doctor_display'] ?? '');
    $docSpec    = esc($it['doctor_specialty'] ?? '');
    $docCell    = $docDisplay
        ? $docDisplay . (!empty($docSpec) ? '<br><span style="font-size:8.5pt;color:#475569;">' . $docSpec . '</span>' : '')
        : '<span style="color:#94a3b8;">&mdash;</span>';
    $unitPrice  = fmtBDT((float)($it['unit_price']  ?? 0));
    $qty        = (int)($it['quantity'] ?? 1);
    $total      = fmtBDT((float)($it['total_price'] ?? 0));

    $rowsHtml .= "<tr>
        <td style=\"text-align:center;\">{$sl}</td>
        <td><span style=\"font-size:8pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#475569;\">{$typeLabel}</span></td>
        <td><strong style=\"color:#0f172a;\">{$desc}</strong></td>
        <td style=\"font-size:9pt;\">{$docCell}</td>
        <td style=\"text-align:right;\">{$unitPrice}</td>
        <td style=\"text-align:center;\">{$qty}</td>
        <td style=\"text-align:right;font-weight:700;\">{$total}</td>
    </tr>\n";
    $sl++;
}
if (!$rowsHtml) {
    $rowsHtml = '<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">No itemized charges recorded.</td></tr>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse Invoice &mdash; <?= esc($invoice['invoice_number']) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    html,body{background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b;font-size:10.5pt;line-height:1.5;}

    /* Page card */
    .piv-page{background:#fff;max-width:870px;margin:28px auto;padding:36px 40px 48px;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 4px 32px rgba(0,0,0,.08);position:relative;overflow:hidden;}

    /* Faint watermark */
    .piv-watermark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0;}
    .piv-watermark img{width:340px;opacity:.05;filter:grayscale(100%);}

    /* Content layer */
    .piv-content{position:relative;z-index:1;}

    /* Letterhead — 2-column: logo+address left | title+status right */
    .piv-letterhead{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin-bottom:14px;}
    /* Left: logo image (48px height, crisp) + sub-address block below */
    .piv-lh-left{display:flex;flex-direction:column;align-items:flex-start;gap:0;}
    .piv-lh-left img{height:48px;width:auto;image-rendering:-webkit-optimize-contrast;object-fit:contain;display:block;}
    .piv-lh-addr{margin-top:7px;font-size:8pt;color:#475569;line-height:1.7;}
    .piv-lh-addr strong{color:#0f172a;}
    /* Right: title + status chip stacked vertically, right-aligned */
    .piv-lh-right{text-align:right;display:flex;flex-direction:column;align-items:flex-end;justify-content:flex-end;gap:10px;padding-top:4px;}
    .piv-lh-title{font-size:9pt;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:#0f172a;line-height:1.3;max-width:300px;text-align:right;}
    .piv-lh-right strong{color:#0f172a;}

    /* Rules */
    .piv-rule{border:none;border-top:2.5px solid #0f172a;margin:12px 0;}
    .piv-rule-sm{border:none;border-top:1px solid #e2e8f0;margin:16px 0;}

    .piv-status-chip{font-size:7.5pt;font-weight:800;letter-spacing:.09em;text-transform:uppercase;padding:4px 14px;border-radius:999px;border:1.5px solid currentColor;white-space:nowrap;}

    /* Invoice bar */
    .piv-invoice-bar{display:flex;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:18px;}
    .piv-ib-item{flex:1;padding:10px 16px;border-right:1px solid #e2e8f0;}
    .piv-ib-item:last-child{border-right:none;}
    .pml{display:block;font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:3px;}
    .pmv{display:block;font-size:9pt;font-weight:700;color:#0f172a;font-family:'Courier New',monospace;}

    /* Patient meta grid */
    .piv-meta-grid{display:flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:22px;}
    .piv-meta-block{flex:1;padding:14px 18px;border-right:1px solid #e2e8f0;}
    .piv-meta-block:last-child{border-right:none;}
    .piv-meta-table{width:100%;border-collapse:collapse;font-size:9pt;}
    .piv-meta-table tr+tr td{padding-top:6px;}
    .piv-meta-table .pml{font-size:7.5pt;color:#94a3b8;font-family:'Segoe UI',Arial,sans-serif;font-weight:700;letter-spacing:.05em;padding-right:12px;}
    .piv-meta-table .pmv{font-size:9pt;font-family:'Segoe UI',Arial,sans-serif;font-weight:700;color:#0f172a;}

    /* Section label */
    .piv-section-label{font-size:7.5pt;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#0284c7;border-left:3px solid #0284c7;padding-left:8px;margin-bottom:10px;}

    /* Ledger table */
    .piv-ledger-table{width:100%;border-collapse:collapse;margin-bottom:22px;font-size:9pt;}
    .piv-ledger-table thead{background:#0f172a;color:#fff;}
    .piv-ledger-table th{padding:9px 11px;text-align:left;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;}
    .piv-ledger-table td{padding:10px 11px;border:1px solid #e2e8f0;vertical-align:top;color:#334155;}
    .piv-ledger-table tbody tr:nth-child(even) td{background:#f8fafc;}
    .piv-ledger-table tbody tr:last-child td{border-bottom:2px solid #0f172a;}

    /* Summary wrap */
    .piv-summary-wrap{display:flex;gap:24px;align-items:flex-start;margin-bottom:18px;}

    /* PAID stamp */
    .piv-paid-stamp{flex-shrink:0;width:132px;display:flex;align-items:center;justify-content:center;padding-top:4px;}
    .piv-stamp-inner{border:3px solid #15803d;border-radius:8px;padding:8px 10px;transform:rotate(-12deg);color:#15803d;display:inline-block;text-align:center;}
    .piv-stamp-top{font-size:6.5pt;font-weight:800;letter-spacing:.12em;text-transform:uppercase;border-bottom:1.5px solid #15803d;padding-bottom:3px;margin-bottom:4px;}
    .piv-stamp-main{font-size:24pt;font-weight:900;letter-spacing:.1em;line-height:1;color:#15803d;}
    .piv-stamp-bottom{font-size:6pt;font-weight:700;letter-spacing:.05em;text-transform:uppercase;border-top:1.5px solid #15803d;padding-top:3px;margin-top:4px;}

    /* Summary left */
    .piv-summary-left{flex:1;}
    .piv-verified-line{display:flex;align-items:center;gap:6px;font-size:8pt;font-weight:700;color:#15803d;margin-bottom:6px;}
    .piv-disclaimer{font-size:8pt;color:#64748b;line-height:1.55;margin-bottom:8px;}
    .piv-hash-line{font-size:7.5pt;color:#94a3b8;}
    .piv-hash-line code{font-family:'Courier New',monospace;font-size:7pt;color:#475569;}

    /* Summary right */
    .piv-summary-right{min-width:260px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;}
    .piv-fin-row{display:flex;justify-content:space-between;padding:7px 14px;font-size:9pt;color:#475569;border-bottom:1px solid #f1f5f9;}
    .piv-fin-row:last-child{border-bottom:none;}
    .piv-fin-row span:last-child{font-weight:700;font-family:'Courier New',monospace;color:#0f172a;}
    .piv-fin-net{background:#0f172a!important;color:#fff!important;}
    .piv-fin-net span{color:#fff!important;font-size:10pt;}
    .piv-fin-due span:last-child{color:#dc2626!important;}
    .piv-fin-due-zero span:last-child{color:#15803d!important;}
    .piv-fin-paid-amt span:last-child{color:#0284c7!important;}

    /* Signature strip */
    .piv-sig-strip{display:flex;}
    .piv-sig-col{flex:1;text-align:center;padding:0 10px;border-right:1px solid #e2e8f0;}
    .piv-sig-col:last-child{border-right:none;}
    .piv-sig-line{border-top:1.5px solid #0f172a;margin:30px auto 6px;width:80%;}
    .piv-sig-role{font-size:8pt;font-weight:700;color:#0f172a;}
    .piv-sig-dept{font-size:7.5pt;color:#64748b;}

    /* Footer */
    .piv-footer-note{margin-top:20px;text-align:center;font-size:7.5pt;color:#94a3b8;line-height:1.7;border-top:1px solid #f1f5f9;padding-top:12px;}

    /* Screen action bar */
    .print-bar{max-width:870px;margin:0 auto 16px;display:flex;gap:10px;justify-content:flex-end;padding:0 4px;}
    .btn-print,.btn-close{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-family:'Segoe UI',Arial,sans-serif;font-size:9.5pt;font-weight:700;cursor:pointer;border:none;transition:opacity .18s,transform .15s;text-decoration:none;}
    .btn-print{background:#0f172a;color:#fff;}
    .btn-print:hover{opacity:.85;transform:translateY(-1px);}
    .btn-close{background:#e2e8f0;color:#475569;}
    .btn-close:hover{background:#cbd5e1;}

    /* @media print */
    @media print {
      html,body{background:#fff;margin:0;padding:0;font-size:10pt;}
      .print-bar{display:none!important;}
      .piv-page{max-width:100%;margin:0;padding:18mm 16mm 20mm;border:none;border-radius:0;box-shadow:none;}
      .piv-ledger-table thead{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#0f172a!important;color:#fff!important;}
      .piv-fin-net{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
      .piv-stamp-inner{border-color:#15803d!important;color:#15803d!important;}
      a{color:inherit;text-decoration:none;}
      @page{margin:0;size:A4 portrait;}
    }
  </style>
</head>
<body>

<!-- Screen-only action bar -->
<div class="print-bar">
  <button class="btn-close" onclick="window.close()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    Close
  </button>
  <button class="btn-print" onclick="window.print()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Save / Print PDF
  </button>
</div>

<!-- Invoice page -->
<div class="piv-page">

  <!-- Faint background watermark -->
  <div class="piv-watermark">
    <?php if ($logoSrc): ?><img src="<?= $logoSrc ?>" alt=""><?php endif; ?>
  </div>

  <div class="piv-content">

    <!-- Letterhead: left = logo + address | right = title + status chip -->
    <div class="piv-letterhead">

      <!-- LEFT: Official logo (base64, no broken icon) + contact block -->
      <div class="piv-lh-left">
        <?php if ($logoSrc): ?>
          <img src="<?= $logoSrc ?>" alt="MedPulse Hospital &amp; Specialty Care" height="48" style="height:48px;width:auto;object-fit:contain;display:block;">
        <?php else: ?>
          <!-- Fallback text brand if logo file missing -->
          <div style="font-size:16pt;font-weight:900;color:#0f172a;letter-spacing:-.02em;line-height:1;">Med<span style="color:#0284c7;">Pulse</span></div>
        <?php endif; ?>
        <div class="piv-lh-addr">
          <div>Plot 14, Road 7, Medical Zone, Dhaka-1212</div>
          <div>PABX: (02) 9876543 &nbsp;|&nbsp; Emergency: +880 1700-000000</div>
          <div>Govt. DGHS Reg: <strong>HSM-2026-DH-0941</strong></div>
        </div>
      </div>

      <!-- RIGHT: Receipt title + payment status badge -->
      <div class="piv-lh-right">
        <div class="piv-lh-title">Official Patient Billing Statement &amp; Payment Receipt</div>
        <div class="piv-status-chip" style="color:<?= esc($statusColor) ?>;background:<?= esc($statusBg) ?>;border-color:<?= esc($statusColor) ?>;">
          <?= esc($statusLabel) ?>
        </div>
        <?php if ($isPaid && !empty($invoice['payment_method'])): ?>
          <div style="font-size:7.5pt;color:#475569;font-weight:600;">via <?= esc($invoice['payment_method']) ?></div>
        <?php endif; ?>
      </div>

    </div>
    <hr class="piv-rule">

    <!-- Invoice details bar -->
    <div class="piv-invoice-bar">
      <div class="piv-ib-item">
        <span class="pml">Invoice Number</span>
        <strong class="pmv"><?= esc($invoice['invoice_number']) ?></strong>
      </div>
      <div class="piv-ib-item">
        <span class="pml">Billing Date &amp; Time</span>
        <strong class="pmv"><?= fmtDateTime($invoice['created_at']) ?></strong>
      </div>
      <div class="piv-ib-item">
        <span class="pml">Payment Channel</span>
        <strong class="pmv"><?= esc($invoice['payment_method'] ?? '&mdash;') ?></strong>
      </div>
      <div class="piv-ib-item">
        <span class="pml">Patient UHID</span>
        <strong class="pmv"><?= esc($patientUhid) ?></strong>
      </div>
    </div>

    <!-- Patient & admission meta grid -->
    <div class="piv-meta-grid">
      <div class="piv-meta-block">
        <table class="piv-meta-table">
          <tr>
            <td class="pml">Patient&nbsp;Name</td>
            <td class="pmv"><?= esc($invoice['patient_name'] ?? '&mdash;') ?></td>
          </tr>
          <tr>
            <td class="pml">UHID&nbsp;Reference</td>
            <td class="pmv"><?= esc($patientUhid) ?></td>
          </tr>
          <tr>
            <td class="pml">Age&nbsp;/&nbsp;Gender</td>
            <td class="pmv"><?= esc($ageGender ?: '&mdash;') ?></td>
          </tr>
          <?php if (!empty($invoice['patient_blood_group'])): ?>
          <tr>
            <td class="pml">Blood&nbsp;Group</td>
            <td class="pmv"><?= esc($invoice['patient_blood_group']) ?></td>
          </tr>
          <?php endif; ?>
        </table>
      </div>
      <div class="piv-meta-block">
        <table class="piv-meta-table">
          <tr>
            <td class="pml">Ward&nbsp;/&nbsp;Bed</td>
            <td class="pmv"><?= !empty($invoice['bed_number'])
              ? esc($invoice['bed_number']) . (!empty($invoice['ward_type']) ? ' &middot; ' . esc($invoice['ward_type']) : '')
              : 'Outpatient / Ambulatory' ?></td>
          </tr>
          <tr>
            <td class="pml">Admitted&nbsp;Date</td>
            <td class="pmv"><?= fmtDate($invoice['admitted_at'] ?? null) ?></td>
          </tr>
          <tr>
            <td class="pml">Discharged&nbsp;Date</td>
            <td class="pmv"><?= fmtDate($invoice['discharged_at'] ?? null) ?></td>
          </tr>
          <tr>
            <td class="pml">Contact</td>
            <td class="pmv"><?= esc($invoice['patient_phone'] ?? '&mdash;') ?></td>
          </tr>
        </table>
      </div>
    </div>

    <!-- Itemized clinical statement -->
    <div class="piv-section-label">Itemized Clinical Statement</div>
    <table class="piv-ledger-table">
      <thead>
        <tr>
          <th style="width:5%;text-align:center;">SL#</th>
          <th style="width:14%;">Category</th>
          <th style="width:30%;">Description / Procedure</th>
          <th style="width:22%;">Consultant / Unit</th>
          <th style="width:13%;text-align:right;">Unit Price (BDT)</th>
          <th style="width:5%;text-align:center;">Qty</th>
          <th style="width:11%;text-align:right;">Total (BDT)</th>
        </tr>
      </thead>
      <tbody>
        <?= $rowsHtml ?>
      </tbody>
    </table>

    <!-- Financial summary + PAID stamp -->
    <div class="piv-summary-wrap">

      <?php if ($isPaid): ?>
      <!-- PAID rubber stamp (conditionally shown for fully settled invoices) -->
      <div class="piv-paid-stamp">
        <div class="piv-stamp-inner">
          <div class="piv-stamp-top">MedPulse Treasury</div>
          <div class="piv-stamp-main">PAID</div>
          <div class="piv-stamp-bottom">DATE: <?= fmtDate($invoice['created_at']) ?> &bull; CASHIER VERIFIED</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Legal disclaimer (left) -->
      <div class="piv-summary-left">
        <div class="piv-verified-line">
          <svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          Verified by MedPulse Central Treasury &amp; Compliance Ledger
        </div>
        <p class="piv-disclaimer">
          Computer-generated clinical statement. Valid without physical signature unless otherwise stated.
          All charges verified per Bangladesh DGHS clinical guidelines (Circular No. DGHS/2026/Billing/041).
        </p>
        <div class="piv-hash-line">Ledger Hash: <code><?= esc($ledgerHash) ?></code></div>
      </div>

      <!-- Financial totals (right) -->
      <div class="piv-summary-right">
        <div class="piv-fin-row">
          <span>Subtotal</span>
          <span><?= fmtBDT((float)($invoice['subtotal'] ?? 0)) ?></span>
        </div>
        <?php if ($vatPct > 0): ?>
        <div class="piv-fin-row">
          <span>VAT (<?= number_format($vatPct, 1) ?>%)</span>
          <span><?= fmtBDT($vatAmt) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($discount > 0): ?>
        <div class="piv-fin-row" style="color:#15803d;">
          <span>Institutional Discount</span>
          <span>&#x2212; <?= fmtBDT($discount) ?></span>
        </div>
        <?php endif; ?>
        <div class="piv-fin-row piv-fin-net">
          <span>Net Total Payable</span>
          <span><?= fmtBDT((float)($invoice['net_payable'] ?? 0)) ?></span>
        </div>
        <div class="piv-fin-row piv-fin-paid-amt">
          <span>Amount Paid</span>
          <span><?= fmtBDT((float)($invoice['paid_amount'] ?? 0)) ?></span>
        </div>
        <div class="piv-fin-row <?= (float)($invoice['due_amount'] ?? 0) <= 0 ? 'piv-fin-due-zero' : 'piv-fin-due' ?>">
          <span>Outstanding Balance</span>
          <span><?= fmtBDT((float)($invoice['due_amount'] ?? 0)) ?></span>
        </div>
      </div>
    </div>

    <hr class="piv-rule-sm">

    <!-- Signature strip -->
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

    <!-- Official footer -->
    <div class="piv-footer-note">
      <div>This is a computer-generated clinical billing statement verified by MedPulse Hospital &amp; Research Institute.</div>
      <div>For billing disputes or insurance claims, please contact Accounts &amp; Treasury within 7 working days of issue.</div>
      <div>Plot 14, Road 7, Medical Zone, Dhaka-1212 &bull; DGHS Reg: HSM-2026-DH-0941 &bull; info@medpulse.hospital</div>
    </div>

  </div><!-- /.piv-content -->
</div><!-- /.piv-page -->

<script>
  // Auto-trigger print dialog once fully loaded (600ms lets base64 images render)
  window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 600);
  });
</script>
</body>
</html>

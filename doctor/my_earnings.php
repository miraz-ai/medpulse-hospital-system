<?php
/**
 * MedPulse — Doctor Clinical Fees & Hospital Payout Ledger
 * Strictly session-scoped to the authenticated physician.
 * Provides transparent consultation fee tracking and hospital disbursement status.
 */

// ── Session Hardening ──────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

// ── RBAC Guard — Doctor only ───────────────────────────────────────────────
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'doctor') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// Anti-caching headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// ── Inactivity Timeout (30 min) ────────────────────────────────────────────
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

// ── Cache Headers ──────────────────────────────────────────────────────────
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';

$doctorUserId = (int) $_SESSION['user_id'];

// ── Doctor Identity & Profile ──────────────────────────────────────────────
try {
    $docStmt = $pdo->prepare("
        SELECT u.full_name, u.email, u.phone, u.gender,
               dp.specialty, dp.designation, dp.military_rank, dp.qualifications,
               dp.bmdc_license_number, dp.consultation_fee, dp.room_number
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.user_id = ? AND u.role = 'Doctor'
        LIMIT 1
    ");
    $docStmt->execute([$doctorUserId]);
    $doctor = $docStmt->fetch(PDO::FETCH_ASSOC);
    if (!$doctor) {
        header('Location: ../login.php');
        exit();
    }
} catch (PDOException $e) {
    die('A database failure occurred. Please contact hospital support.');
}

// ── Earnings Summary Aggregates ────────────────────────────────────────────
// Security: ALL queries bind strictly to $doctorUserId — no other physician's
// data can leak regardless of URL manipulation.
try {
    $sumStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(ii.doctor_payout_amount), 0)                                   AS total_earned,
            COALESCE(SUM(CASE WHEN ii.doctor_payout_status = 'DISBURSED'
                              THEN ii.doctor_payout_amount ELSE 0 END), 0)               AS total_disbursed,
            COALESCE(SUM(CASE WHEN ii.doctor_payout_status != 'DISBURSED'
                              THEN ii.doctor_payout_amount ELSE 0 END), 0)               AS total_pending,
            COUNT(ii.item_id)                                                            AS consult_count,
            COALESCE(SUM(CASE WHEN ii.doctor_payout_status = 'DISBURSED' THEN 1 ELSE 0 END), 0) AS disbursed_count,
            COALESCE(SUM(CASE WHEN ii.doctor_payout_status != 'DISBURSED' THEN 1 ELSE 0 END), 0) AS pending_count
        FROM invoice_items ii
        WHERE ii.doctor_id = ?
    ");
    $sumStmt->execute([$doctorUserId]);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $summary = [
        'total_earned' => 0, 'total_disbursed' => 0, 'total_pending' => 0,
        'consult_count' => 0, 'disbursed_count' => 0, 'pending_count' => 0,
    ];
}

// ── Consultation Ledger ────────────────────────────────────────────────────
try {
    $ledgerStmt = $pdo->prepare("
        SELECT
            ii.item_id,
            ii.item_type,
            ii.description,
            ii.doctor_payout_amount   AS fee,
            ii.doctor_payout_status   AS payout_status,
            i.invoice_number,
            i.status                  AS patient_payment_status,
            i.created_at              AS invoice_date,
            pat.full_name             AS patient_name,
            pat.user_id               AS patient_id,
            hb.bed_number,
            hb.ward_type
        FROM invoice_items ii
        JOIN invoices      i   ON ii.invoice_id = i.invoice_id
        JOIN users         pat ON i.patient_id  = pat.user_id
        LEFT JOIN bed_allocations ba ON i.admission_id = ba.allocation_id
        LEFT JOIN hospital_beds   hb ON ba.bed_id       = hb.bed_id
        WHERE ii.doctor_id = ?
        ORDER BY i.created_at DESC
    ");
    $ledgerStmt->execute([$doctorUserId]);
    $ledger = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ledger = [];
}

// ── Greeting ───────────────────────────────────────────────────────────────
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

$rawDocName  = $doctor['full_name'] ?? 'Doctor';
$cleanDocName = cleanDoctorBaseName($rawDocName);
$displayName = formatDoctorTitle($cleanDocName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$doctorName  = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
$specialty   = htmlspecialchars($doctor['specialty'] ?? 'General Practice', ENT_QUOTES, 'UTF-8');
$licenseNum  = htmlspecialchars($doctor['bmdc_license_number'] ?? '—', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | My Clinical Earnings</title>
  <meta name="description" content="Secure doctor earnings ledger — clinical fees, consultation records, and hospital disbursement status.">
  <meta name="robots" content="noindex, nofollow">

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%' y1='0%' x2='0%' y2='100%'><stop offset='0%' stop-color='%230284c7'/><stop offset='100%' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* ── Design System ────────────────────────────────────────────────── */
    :root {
      --brand-primary:  #0284c7;
      --brand-teal:     #0d9488;
      --brand-gradient: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      --bg-page:        #f0f9ff;
      --surface:        #ffffff;
      --border:         #e2e8f0;
      --border-subtle:  #f1f5f9;
      --text-heading:   #0f172a;
      --text-body:      #475569;
      --text-muted:     #94a3b8;
      --green:  #059669; --green-bg:  #ecfdf5; --green-border:  #a7f3d0;
      --amber:  #d97706; --amber-bg:  #fffbeb; --amber-border:  #fcd34d;
      --sky:    #0284c7; --sky-bg:    #f0f9ff; --sky-border:    #bae6fd;
      --red:    #dc2626; --red-bg:    #fef2f2; --red-border:    #fca5a5;
      --purple: #7c3aed; --purple-bg: #f5f3ff; --purple-border: #c4b5fd;
      --radius-xl: 20px; --radius-lg: 16px; --radius-md: 10px;
      --spring: all 0.28s cubic-bezier(0.34,1.56,0.64,1);
    }

    *, *::before, *::after {
      margin: 0; padding: 0; box-sizing: border-box;
      font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
    }

    body {
      background: var(--bg-page);
      color: var(--text-body);
      min-height: 100vh;
      background-image:
        radial-gradient(at 0% 0%,   rgba(2,132,199,.05) 0px, transparent 50%),
        radial-gradient(at 100% 100%, rgba(13,148,136,.06) 0px, transparent 50%);
      -webkit-font-smoothing: antialiased;
    }

    .ui-ico {
      width: 20px; height: 20px;
      stroke: currentColor; stroke-width: 2;
      stroke-linecap: round; stroke-linejoin: round;
      fill: none; flex-shrink: 0;
    }

    /* ── Page Shell ───────────────────────────────────────────────────── */
    .page-wrap {
      max-width: 1180px;
      margin: 0 auto;
      padding: 28px 32px 48px;
    }
    @media (max-width: 768px) { .page-wrap { padding: 16px; } }

    /* ── Page Header ─────────────────────────────────────────────────── */
    .page-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 16px; flex-wrap: wrap; margin-bottom: 28px;
    }
    .page-header h1 {
      font-size: 1.5rem; font-weight: 800; color: var(--text-heading);
      display: flex; align-items: center; gap: 10px; margin-bottom: 4px;
    }
    .page-header h1 svg { stroke: var(--brand-primary); }
    .page-header p { font-size: .84rem; color: var(--text-muted); }
    .back-link {
      display: inline-flex; align-items: center; gap: 7px;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 10px; padding: 9px 16px;
      font-size: .8rem; font-weight: 600; color: #475569;
      text-decoration: none; transition: background .15s, box-shadow .15s;
      white-space: nowrap;
    }
    .back-link:hover { background: #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .back-link svg { stroke: #475569; width: 15px; height: 15px; }

    /* ── Doctor Identity Card ─────────────────────────────────────────── */
    .doctor-id-card {
      background: var(--brand-gradient);
      border-radius: var(--radius-xl);
      padding: 24px 28px;
      color: #fff;
      display: flex; align-items: center; gap: 20px;
      margin-bottom: 24px;
      flex-wrap: wrap;
      position: relative; overflow: hidden;
    }
    .doctor-id-card::before {
      content: '';
      position: absolute; top: -40px; right: -40px;
      width: 180px; height: 180px;
      border-radius: 50%;
      background: rgba(255,255,255,.06);
    }
    .doctor-id-card::after {
      content: '';
      position: absolute; bottom: -30px; left: 60px;
      width: 120px; height: 120px;
      border-radius: 50%;
      background: rgba(255,255,255,.04);
    }
    .doctor-avatar {
      width: 64px; height: 64px; border-radius: 50%;
      background: rgba(255,255,255,.2);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem; font-weight: 800; color: #fff;
      flex-shrink: 0; border: 2px solid rgba(255,255,255,.35);
      z-index: 1;
    }
    .doctor-id-info { flex: 1; z-index: 1; }
    .doctor-id-info .greeting {
      font-size: .72rem; font-weight: 700; opacity: .75;
      text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px;
    }
    .doctor-id-info h2 { font-size: 1.25rem; font-weight: 800; margin-bottom: 6px; }
    .doctor-id-meta { display: flex; gap: 18px; flex-wrap: wrap; }
    .doctor-id-meta span {
      font-size: .75rem; font-weight: 600; opacity: .85;
      display: flex; align-items: center; gap: 5px;
    }
    .doctor-id-meta svg { stroke: rgba(255,255,255,.85); width: 13px; height: 13px; }

    /* ── Summary Cards ────────────────────────────────────────────────── */
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px; margin-bottom: 28px;
    }
    @media (max-width: 720px) { .summary-grid { grid-template-columns: 1fr; } }

    .summary-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 22px 22px 18px;
      position: relative; overflow: hidden;
      transition: box-shadow .22s, transform .22s;
    }
    .summary-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,.09); transform: translateY(-2px); }
    .summary-card::before {
      content: ''; position: absolute;
      top: 0; left: 0; right: 0; height: 3px;
    }
    .sc-earned::before  { background: var(--brand-gradient); }
    .sc-disbursed::before { background: linear-gradient(90deg,#059669,#10b981); }
    .sc-pending::before { background: linear-gradient(90deg,#d97706,#f59e0b); }

    .sc-icon {
      width: 42px; height: 42px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: 14px;
    }
    .sc-earned    .sc-icon { background: #e0f2fe; }
    .sc-disbursed .sc-icon { background: var(--green-bg); }
    .sc-pending   .sc-icon { background: var(--amber-bg); }
    .sc-earned    .sc-icon svg { stroke: var(--brand-primary); }
    .sc-disbursed .sc-icon svg { stroke: var(--green); }
    .sc-pending   .sc-icon svg { stroke: var(--amber); }

    .sc-label {
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px;
    }
    .sc-amount {
      font-size: 1.85rem; font-weight: 800; letter-spacing: -.04em;
      line-height: 1; margin-bottom: 6px;
    }
    .sc-earned    .sc-amount { color: var(--brand-primary); }
    .sc-disbursed .sc-amount { color: var(--green); }
    .sc-pending   .sc-amount { color: var(--amber); }
    .sc-note { font-size: .74rem; color: var(--text-muted); }

    /* ── Section Title ────────────────────────────────────────────────── */
    .section-head {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
    }
    .section-title {
      font-size: 1rem; font-weight: 700; color: var(--text-heading);
      display: flex; align-items: center; gap: 9px;
    }
    .section-title svg { stroke: var(--brand-primary); }
    .section-chip {
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .06em; padding: 4px 12px; border-radius: 999px;
      background: #f0f9ff; color: var(--brand-primary);
      border: 1px solid #bae6fd;
    }

    /* ── Ledger Table ─────────────────────────────────────────────────── */
    .ledger-wrap {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      overflow: hidden; margin-bottom: 24px;
    }
    .ledger-table { width: 100%; border-collapse: collapse; font-size: .83rem; }
    .ledger-table thead tr {
      background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    }
    .ledger-table th {
      padding: 13px 16px; text-align: left;
      font-size: .67rem; font-weight: 700;
      color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em;
      border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    .ledger-table th:last-child { text-align: right; }
    .ledger-table tbody tr {
      border-bottom: 1px solid var(--border-subtle);
      transition: background .15s;
    }
    .ledger-table tbody tr:last-child { border-bottom: none; }
    .ledger-table tbody tr:hover { background: #f8fafc; }
    .ledger-table td { padding: 14px 16px; vertical-align: middle; color: #334155; }
    .ledger-table td:last-child { text-align: right; }

    /* Date cell */
    .date-cell { white-space: nowrap; }
    .date-main { font-size: .83rem; font-weight: 600; color: var(--text-heading); }
    .date-time { font-size: .72rem; color: var(--text-muted); margin-top: 2px; }

    /* Patient cell */
    .patient-cell { display: flex; align-items: center; gap: 10px; }
    .patient-avatar {
      width: 36px; height: 36px; border-radius: 50%;
      background: linear-gradient(135deg, #e0f2fe, #f0f9ff);
      display: flex; align-items: center; justify-content: center;
      font-size: .74rem; font-weight: 800; color: var(--brand-primary);
      flex-shrink: 0; border: 1px solid #bae6fd;
    }
    .patient-name { font-weight: 700; color: var(--text-heading); font-size: .84rem; }
    .patient-id   { font-size: .71rem; color: var(--text-muted); }

    /* Service cell */
    .service-type {
      display: inline-block; margin-bottom: 4px;
      font-size: .63rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .04em; padding: 2px 8px; border-radius: 999px;
      background: var(--purple-bg); color: var(--purple);
      border: 1px solid var(--purple-border);
    }
    .service-desc { font-size: .82rem; color: #334155; font-weight: 600; line-height: 1.35; }
    .service-bed  { font-size: .72rem; color: var(--text-muted); margin-top: 2px; }

    /* Fee */
    .fee-amount { font-weight: 800; color: var(--text-heading); font-size: .92rem; }

    /* Status badges */
    .badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 11px; border-radius: 999px;
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .04em; border: 1px solid transparent;
      white-space: nowrap;
    }
    .badge-paid     { background: var(--green-bg);  color: var(--green);  border-color: var(--green-border); }
    .badge-pending  { background: var(--red-bg);    color: var(--red);    border-color: var(--red-border); }
    .badge-partial  { background: var(--amber-bg);  color: var(--amber);  border-color: var(--amber-border); }
    .badge-disbursed { background: var(--sky-bg);   color: var(--sky);    border-color: var(--sky-border); }
    .badge-clearance { background: var(--amber-bg); color: var(--amber);  border-color: var(--amber-border); }
    .badge-unclaimed { background: #f8fafc;          color: #64748b;       border-color: #cbd5e1; }
    .badge-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

    /* ── Empty State ─────────────────────────────────────────────────── */
    .empty-state {
      text-align: center; padding: 64px 24px;
    }
    .empty-state svg { stroke: #cbd5e1; margin-bottom: 16px; }
    .empty-state h3 { font-size: 1rem; font-weight: 700; color: #64748b; margin-bottom: 6px; }
    .empty-state p  { font-size: .82rem; color: var(--text-muted); }

    /* ── Info Notice ─────────────────────────────────────────────────── */
    .info-notice {
      background: linear-gradient(135deg, #eff6ff, #f0f9ff);
      border: 1px solid #bae6fd; border-left: 4px solid var(--brand-primary);
      border-radius: 12px; padding: 16px 20px;
      display: flex; gap: 14px; align-items: flex-start;
      margin-bottom: 24px;
    }
    .info-notice .ni-icon {
      width: 38px; height: 38px; border-radius: 50%;
      background: var(--brand-primary);
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .info-notice h4  { font-size: .86rem; font-weight: 700; color: #1e3a5f; margin-bottom: 3px; }
    .info-notice p   { font-size: .78rem; color: #2563eb; line-height: 1.55; }

    /* ── Responsive overflow ─────────────────────────────────────────── */
    @media (max-width: 900px) { .ledger-wrap { overflow-x: auto; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

<main class="viewport-full">

  <!-- ── Page Header ────────────────────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <h1>
        <svg class="ui-ico" viewBox="0 0 24 24" style="width:26px;height:26px;">
          <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
        </svg>
        Clinical Fees &amp; Payout Ledger
      </h1>
      <p>Your personal earnings record — consultation fees, patient billing status, and hospital disbursements.</p>
    </div>
    <a href="dashboard.php" class="back-link">
      <svg viewBox="0 0 24 24" class="ui-ico"><polyline points="15 18 9 12 15 6"/></svg>
      Back to Dashboard
    </a>
  </div>

  <!-- ── Doctor Identity Card ───────────────────────────────────────────── -->
  <div class="doctor-id-card">
    <div class="doctor-avatar">
      <?= strtoupper(substr(trim($cleanDocName), 0, 2)) ?>
    </div>
    <div class="doctor-id-info">
      <div class="greeting"><?= $greeting ?>, Doctor</div>
      <h2><?= $doctorName ?></h2>
      <div class="doctor-id-meta">
        <span>
          <svg viewBox="0 0 24 24" class="ui-ico"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          <?= $specialty ?>
        </span>
        <span>
          <svg viewBox="0 0 24 24" class="ui-ico"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          BMDC: <?= $licenseNum ?>
        </span>
        <?php if ($doctor['room_number']): ?>
        <span>
          <svg viewBox="0 0 24 24" class="ui-ico"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Room <?= htmlspecialchars($doctor['room_number'], ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Summary Cards ──────────────────────────────────────────────────── -->
  <div class="summary-grid">
    <!-- Total Earned -->
    <div class="summary-card sc-earned">
      <div class="sc-icon">
        <svg class="ui-ico" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      </div>
      <div class="sc-label">Total Earned Fees</div>
      <div class="sc-amount">&#2547;<?= number_format((float)$summary['total_earned'], 2) ?></div>
      <div class="sc-note"><?= (int)$summary['consult_count'] ?> consultation<?= $summary['consult_count'] != 1 ? 's' : '' ?> recorded</div>
    </div>

    <!-- Hospital Disbursed -->
    <div class="summary-card sc-disbursed">
      <div class="sc-icon">
        <svg class="ui-ico" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="sc-label">Hospital Disbursed</div>
      <div class="sc-amount">&#2547;<?= number_format((float)$summary['total_disbursed'], 2) ?></div>
      <div class="sc-note"><?= (int)$summary['disbursed_count'] ?> payment<?= $summary['disbursed_count'] != 1 ? 's' : '' ?> cleared to you</div>
    </div>

    <!-- Pending Disbursement -->
    <div class="summary-card sc-pending">
      <div class="sc-icon">
        <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/></svg>
      </div>
      <div class="sc-label">Pending Disbursement</div>
      <div class="sc-amount">&#2547;<?= number_format((float)$summary['total_pending'], 2) ?></div>
      <div class="sc-note">
        <?= $summary['total_pending'] > 0
            ? (int)$summary['pending_count'] . ' item' . ($summary['pending_count'] != 1 ? 's' : '') . ' awaiting hospital clearance'
            : 'All fees have been disbursed' ?>
      </div>
    </div>
  </div>

  <!-- ── Informational Notice ───────────────────────────────────────────── -->
  <div class="info-notice">
    <div class="ni-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
      </svg>
    </div>
    <div>
      <h4>How Your Fees Work</h4>
      <p>
        Your consultation fees are collected by the hospital from patients and held in the central treasury.
        The administration team reviews and disburses your fees to your registered bank account.
        Contact the Accounts Department at Ext.&nbsp;220 for disbursement queries.
      </p>
    </div>
  </div>

  <!-- ── Consultation Ledger ─────────────────────────────────────────────── -->
  <div class="section-head">
    <div class="section-title">
      <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Consultation Ledger
    </div>
    <span class="section-chip">
      <?= (int)$summary['consult_count'] ?> RECORD<?= $summary['consult_count'] != 1 ? 'S' : '' ?>
    </span>
  </div>

  <div class="ledger-wrap">
    <?php if (empty($ledger)): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="64" height="64">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
        </svg>
        <h3>No Consultation Records Found</h3>
        <p>Your clinical billing records will appear here once a patient invoice includes your consultation fees.</p>
      </div>
    <?php else: ?>
      <table class="ledger-table" id="earningsTable">
        <thead>
          <tr>
            <th>Date &amp; Time</th>
            <th>Patient</th>
            <th>Service Rendered</th>
            <th>Invoice #</th>
            <th>Patient Payment</th>
            <th>Hospital Clearance</th>
            <th>Fee (&#2547;)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $row):
            // ── Patient payment badge ──────────────────────────────────
            $patBadge = match(strtolower($row['patient_payment_status'])) {
              'paid'    => '<span class="badge badge-paid"><span class="badge-dot"></span>Paid</span>',
              'partial' => '<span class="badge badge-partial"><span class="badge-dot"></span>Partial</span>',
              default   => '<span class="badge badge-pending"><span class="badge-dot"></span>Due</span>',
            };

            // ── Payout clearance badge ─────────────────────────────────
            $payoutBadge = match($row['payout_status']) {
              'DISBURSED'         => '<span class="badge badge-disbursed"><span class="badge-dot"></span>Disbursed</span>',
              'PENDING_CLEARANCE' => '<span class="badge badge-clearance"><span class="badge-dot"></span>Pending Clearance</span>',
              default             => '<span class="badge badge-unclaimed"><span class="badge-dot"></span>Unclaimed</span>',
            };

            // ── Initials ───────────────────────────────────────────────
            $initials = strtoupper(substr(trim($row['patient_name']), 0, 2));

            // ── Date formatting ────────────────────────────────────────
            $dateObj  = new DateTime($row['invoice_date']);
            $dateMain = $dateObj->format('d M Y');
            $dateTime = $dateObj->format('h:i A');
          ?>
          <tr>
            <!-- Date -->
            <td class="date-cell">
              <div class="date-main"><?= $dateMain ?></div>
              <div class="date-time"><?= $dateTime ?></div>
            </td>

            <!-- Patient -->
            <td>
              <div class="patient-cell">
                <div class="patient-avatar"><?= $initials ?></div>
                <div>
                  <div class="patient-name"><?= htmlspecialchars($row['patient_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="patient-id">ID: <?= str_pad($row['patient_id'], 4, '0', STR_PAD_LEFT) ?></div>
                </div>
              </div>
            </td>

            <!-- Service -->
            <td>
              <span class="service-type"><?= htmlspecialchars($row['item_type'] ?? 'Consultation', ENT_QUOTES, 'UTF-8') ?></span><br>
              <span class="service-desc"><?= htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php if ($row['bed_number']): ?>
                <div class="service-bed">
                  <svg style="display:inline;vertical-align:-2px;stroke:#94a3b8;width:11px;height:11px;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
                  Bed <?= htmlspecialchars($row['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                  &middot; <?= htmlspecialchars($row['ward_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- Invoice # -->
            <td>
              <span style="font-family:'Courier New',monospace;font-weight:700;font-size:.76rem;color:var(--brand-primary);">
                <?= htmlspecialchars($row['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>

            <!-- Patient Payment Status -->
            <td><?= $patBadge ?></td>

            <!-- Hospital Clearance -->
            <td><?= $payoutBadge ?></td>

            <!-- Fee -->
            <td>
              <span class="fee-amount">&#2547;<?= number_format((float)$row['fee'], 2) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <p style="font-size:.74rem;color:var(--text-muted);text-align:center;line-height:1.6;">
    This ledger is your official earnings record at MedPulse Hospital &amp; Specialty Care.
    For disbursement queries or discrepancies, contact the Accounts Department at Ext.&nbsp;220.
  </p>

</main>
</body>
</html>

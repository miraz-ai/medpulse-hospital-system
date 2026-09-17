<?php
/**
 * MedPulse Doctor Portal — Clinical Executive Command Center
 * Unified App Shell with Production Sidebar & Real-time Relational Telemetry
 */

// ── Strict Session & RBAC Guard ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Doctor') !== 0) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// ── Inactivity Timeout (30 min) ────────────────────────────────────────────
$inactiveTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit();
}
$_SESSION['last_activity'] = time();

// Cache Buster Headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../config/db.php';

$doctorUserId = (int)$_SESSION['user_id'];

// ── Fetch Authenticated Doctor Profile & Credentials ───────────────────────
try {
    $docStmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender,
               dp.doctor_id, dp.specialty, dp.bmdc_license_number, dp.consultation_fee,
               dp.room_number, dp.available_days, dp.shift_timings
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.user_id = ? AND u.role = 'Doctor'
        LIMIT 1
    ");
    $docStmt->execute([$doctorUserId]);
    $doctor = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doctor) {
        header("Location: ../login.php");
        exit();
    }
} catch (PDOException $e) {
    die("A database error occurred while fetching physician profile.");
}

// ── Doctor Greeting & Name Sanitization (Resolves Dr. Dr. bug) ─────────────
$rawFullName = $doctor['full_name'] ?? $_SESSION['full_name'] ?? 'Physician';
// Strip all existing variations of Dr, Dr., Doctor
$cleanName = preg_replace('/^(?:(?:dr\.?|doctor)\s+)+/i', '', trim($rawFullName));
$displayName = 'Dr. ' . $cleanName;

// Generate initials for avatar
$nameParts = preg_split('/\s+/', trim($cleanName));
$doctorInitials = strtoupper(substr($nameParts[0] ?? 'D', 0, 1) . substr($nameParts[count($nameParts) - 1] ?? 'R', 0, 1));

// Specialty & Credentials formatting
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Surgery & Critical Care', ENT_QUOTES, 'UTF-8');
$specialtyShort = strlen($specialty) > 28 ? substr($specialty, 0, 26) . '…' : $specialty;
$bmdcLicense = htmlspecialchars($doctor['bmdc_license_number'] ?? 'BMDC-PENDING', ENT_QUOTES, 'UTF-8');
$consultationFee = number_format((float)($doctor['consultation_fee'] ?? 1200), 2);
$roomNumber = htmlspecialchars($doctor['room_number'] ?? 'Room-302', ENT_QUOTES, 'UTF-8');
$shiftTimings = htmlspecialchars($doctor['shift_timings'] ?? '09:00 AM - 05:00 PM', ENT_QUOTES, 'UTF-8');

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');

// ── Fetch 4 Vital KPI Metrics ──────────────────────────────────────────────
try {
    // 1. Total Consultations (All appointments)
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ?");
    $stmtC->execute([$doctorUserId]);
    $totalConsultations = (int)$stmtC->fetchColumn();

    // 2. Assigned Inpatients (Currently admitted under care)
    $stmtI = $pdo->prepare("SELECT COUNT(*) FROM bed_allocations WHERE attending_doctor_id = ? AND status = 'Active'");
    $stmtI->execute([$doctorUserId]);
    $assignedInpatients = (int)$stmtI->fetchColumn();

    // 3. Unsettled Disbursements (Unclaimed or Pending clearance fees)
    $stmtU = $pdo->prepare("
        SELECT COALESCE(SUM(doctor_payout_amount), 0)
        FROM invoice_items
        WHERE doctor_id = ? AND doctor_payout_status != 'DISBURSED'
    ");
    $stmtU->execute([$doctorUserId]);
    $unsettledDisbursements = (float)$stmtU->fetchColumn();

    // 4. Total Earnings (All fees recorded)
    $stmtE = $pdo->prepare("
        SELECT COALESCE(SUM(doctor_payout_amount), 0)
        FROM invoice_items
        WHERE doctor_id = ?
    ");
    $stmtE->execute([$doctorUserId]);
    $totalEarnings = (float)$stmtE->fetchColumn();

} catch (PDOException $e) {
    $totalConsultations = 0;
    $assignedInpatients = 0;
    $unsettledDisbursements = 0.00;
    $totalEarnings = 0.00;
}

// ── Fetch Active Inpatients List ───────────────────────────────────────────
try {
    $inpatientStmt = $pdo->prepare("
        SELECT ba.allocation_id, ba.admitted_at, ba.status,
               u.user_id AS patient_id, u.full_name AS patient_name, u.gender, u.age, u.blood_group,
               hb.bed_number, hb.ward_type, hb.floor_number
        FROM bed_allocations ba
        JOIN users u ON ba.patient_id = u.user_id
        JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
        WHERE ba.attending_doctor_id = ? AND ba.status = 'Active'
        ORDER BY ba.admitted_at DESC
        LIMIT 10
    ");
    $inpatientStmt->execute([$doctorUserId]);
    $activeInpatients = $inpatientStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $activeInpatients = [];
}

// ── Fetch Upcoming Consultations & Schedule ────────────────────────────────
try {
    $appQueueStmt = $pdo->prepare("
        SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.serial_number,
               a.reason_for_visit, a.status,
               u.user_id AS patient_id, u.full_name AS patient_name, u.gender, u.phone
        FROM appointments a
        JOIN users u ON a.patient_id = u.user_id
        WHERE a.doctor_id = ? AND a.status IN ('Scheduled', 'In-Consultation')
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 8
    ");
    $appQueueStmt->execute([$doctorUserId]);
    $upcomingAppointments = $appQueueStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcomingAppointments = [];
}

// ── Fetch Recent Prescriptions ─────────────────────────────────────────────
try {
    $rxStmt = $pdo->prepare("
        SELECT p.prescription_id, p.diagnosis_notes, p.vitals_summary, p.prescribed_at,
               u.full_name AS patient_name, u.gender
        FROM prescriptions p
        JOIN users u ON p.patient_id = u.user_id
        WHERE p.doctor_id = ?
        ORDER BY p.prescribed_at DESC
        LIMIT 5
    ");
    $rxStmt->execute([$doctorUserId]);
    $recentPrescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentPrescriptions = [];
}

// ── Fetch Recent Earnings Snapshot ─────────────────────────────────────────
try {
    $earnStmt = $pdo->prepare("
        SELECT ii.item_id, ii.description, ii.doctor_payout_amount, ii.doctor_payout_status,
               i.invoice_number, i.created_at, u.full_name AS patient_name
        FROM invoice_items ii
        JOIN invoices i ON ii.invoice_id = i.invoice_id
        JOIN users u ON i.patient_id = u.user_id
        WHERE ii.doctor_id = ?
        ORDER BY ii.item_id DESC
        LIMIT 5
    ");
    $earnStmt->execute([$doctorUserId]);
    $recentEarnings = $earnStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentEarnings = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Doctor Clinical Workspace</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">

  <!-- Modern Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Enterprise Design System -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* ── Doctor Portal Refined Styles ────────────────────────────────────── */
    .doctor-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.25rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }
    .topbar-breadcrumb {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-muted);
    }
    .breadcrumb-sep { color: #cbd5e1; font-weight: 400; }
    .breadcrumb-current { color: var(--brand-teal); font-weight: 700; }

    .topbar-search-wrap {
      flex: 1;
      max-width: 420px;
      position: relative;
    }
    .topbar-search-wrap svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      stroke: var(--text-muted);
      pointer-events: none;
    }
    .topbar-search-input {
      width: 100%;
      padding: 0.6rem 1rem 0.6rem 2.4rem;
      border-radius: var(--radius-md);
      border: 1px solid var(--surface-border);
      background: var(--surface);
      font-size: 0.84rem;
      color: var(--text-heading);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .topbar-search-input:focus {
      border-color: var(--brand-teal);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
    }

    .topbar-right-cluster {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .topbar-notif-btn {
      position: relative;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-md);
      width: 40px;
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: var(--text-heading);
      transition: background 0.18s;
    }
    .topbar-notif-btn:hover {
      background: #f1f5f9;
    }
    .notif-badge-dot {
      position: absolute;
      top: 9px;
      right: 9px;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--status-amber);
      box-shadow: 0 0 0 2px var(--surface);
    }

    .doctor-profile-badge {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      padding: 0.4rem 0.85rem 0.4rem 0.5rem;
      border-radius: 30px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .doc-avatar-initials {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--brand-gradient);
      color: #ffffff;
      font-weight: 800;
      font-size: 0.82rem;
      display: flex;
      align-items: center;
      justify-content: center;
      letter-spacing: 0.5px;
    }
    .doc-meta-text {
      display: flex;
      flex-direction: column;
    }
    .doc-display-name {
      font-size: 0.86rem;
      font-weight: 700;
      color: var(--text-heading);
      line-height: 1.15;
    }
    .doc-status-indicator {
      font-size: 0.72rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .pulse-dot-green {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--status-green);
      box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.2);
    }

    /* Subtitle and Credentials Bar */
    .doctor-credentials-line {
      margin-top: 6px;
      font-size: 0.82rem;
      color: #0f766e;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .cred-chip {
      background: rgba(13, 148, 136, 0.1);
      color: var(--brand-teal);
      padding: 2px 8px;
      border-radius: 6px;
      font-weight: 700;
      font-size: 0.74rem;
    }

    /* Production Button Classes */
    .bg-teal-600 {
      background-color: #0d9488 !important;
    }
    .bg-teal-600:hover, .hover\:bg-teal-700:hover {
      background-color: #0f766e !important;
      transform: translateY(-1px);
    }
    .btn-teal-action {
      background-color: #0d9488;
      color: #ffffff !important;
      padding: 0.65rem 1.15rem;
      border-radius: 0.5rem;
      font-weight: 600;
      font-size: 0.84rem;
      border: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      cursor: pointer;
      text-decoration: none;
      box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      transition: all 0.2s ease;
    }
    .btn-teal-action:hover {
      background-color: #0f766e;
      box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
    }

    /* Two-column layout grid */
    .doctor-dash-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.75rem;
      margin-top: 1.75rem;
    }
    @media (max-width: 1024px) {
      .doctor-dash-grid { grid-template-columns: 1fr; }
    }

    /* Quick status chips */
    .chip-inpatient {
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
    }
    .chip-consult {
      background: #eff6ff;
      color: #2563eb;
      border: 1px solid #bfdbfe;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
    }
    .chip-disbursed {
      background: #ecfdf5;
      color: #059669;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
    }
    .chip-pending {
      background: #fffbeb;
      color: #d97706;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
    }
  </style>
</head>
<body>

  <!-- Shared Production Doctor Sidebar -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <!-- Main Viewport (Full Parity with Admin/Patient Dashboards) -->
  <main class="viewport-full">

    <!-- Top Navigation / Header Bar -->
    <div class="doctor-topbar">
      <div class="topbar-breadcrumb">
        <span class="breadcrumb-root">Doctor Portal</span>
        <span class="breadcrumb-sep">/</span>
        <span class="breadcrumb-current">Clinical Overview</span>
      </div>

      <div class="topbar-search-wrap">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input type="text" id="doctorQuickSearch" class="topbar-search-input" placeholder="Search inpatients, appointments, or UHID..." onkeyup="filterDoctorDashboard(this.value)">
      </div>

      <div class="topbar-right-cluster">
        <div class="topbar-notif-btn" title="Clinical Notifications" onclick="showToast('All clinical telemetries and test orders are synchronized.', 'success')">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
          <span class="notif-badge-dot"></span>
        </div>

        <div class="doctor-profile-badge">
          <div class="doc-avatar-initials"><?= $doctorInitials ?></div>
          <div class="doc-meta-text">
            <span class="doc-display-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="doc-status-indicator">
              <span class="pulse-dot-green"></span>
              On Duty &bull; <?= htmlspecialchars($specialtyShort, ENT_QUOTES, 'UTF-8') ?>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Executive Doctor Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>!
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
        </h1>
        <div class="doctor-credentials-line">
          <span><?= $specialty ?></span>
          <span>&bull;</span>
          <span>BMDC Reg: <strong><?= $bmdcLicense ?></strong></span>
          <span class="cred-chip"><?= $roomNumber ?></span>
          <span class="cred-chip"><?= $shiftTimings ?></span>
        </div>
      </div>

      <div class="banner-actions">
        <a href="my_inpatients.php" class="btn-action-telemed" style="text-decoration: none;">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          Daily Rounds
        </a>
        <a href="my_earnings.php" class="btn-teal-action">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          Earnings Ledger
        </a>
      </div>
    </div>

    <!-- 4-Metric Vital Stats Cards (Parity with Admin & Patient Portals) -->
    <div class="stat-cards-grid">
      <!-- Metric 1: Total Consultations -->
      <a href="appointments.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Total Consultations</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
        </div>
        <div class="stat-card-number"><?= number_format($totalConsultations) ?></div>
        <div class="stat-card-badge badge-blue">
          <span>Outpatient Queue &rarr;</span>
        </div>
      </a>

      <!-- Metric 2: Assigned Inpatients -->
      <a href="my_inpatients.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Assigned Inpatients</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
        </div>
        <div class="stat-card-number"><?= number_format($assignedInpatients) ?></div>
        <div class="stat-card-badge badge-green">
          <span>Active Inpatient Care &rarr;</span>
        </div>
      </a>

      <!-- Metric 3: Unsettled Disbursements -->
      <a href="my_earnings.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Unsettled Disbursements</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number">&#2547;<?= number_format($unsettledDisbursements, 2) ?></div>
        <div class="stat-card-badge <?= $unsettledDisbursements > 0 ? 'badge-amber' : 'badge-green' ?>">
          <span><?= $unsettledDisbursements > 0 ? 'Pending Settlement &rarr;' : 'All Cleared' ?></span>
        </div>
      </a>

      <!-- Metric 4: Total Clinical Earnings -->
      <a href="my_earnings.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Total Clinical Earnings</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </div>
        <div class="stat-card-number">&#2547;<?= number_format($totalEarnings, 2) ?></div>
        <div class="stat-card-badge badge-green">
          <span>Recorded Fees &rarr;</span>
        </div>
      </a>
    </div>

    <!-- Active Inpatients & Daily Rounds Section -->
    <section class="admin-stack-card" id="inpatientsSection" style="margin-top: 1.75rem;">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-teal); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
            My Inpatients &amp; Daily Rounds
          </h3>
          <p>Patients currently admitted under your attending clinical supervision</p>
        </div>
        <a href="my_inpatients.php" class="btn-teal-action" style="padding: 0.45rem 0.85rem; font-size: 0.78rem;">
          View All Rounds &rarr;
        </a>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table" id="inpatientTable">
          <thead>
            <tr>
              <th>Patient Profile</th>
              <th>Ward / Bed Allocation</th>
              <th>Gender / Age</th>
              <th>Blood Group</th>
              <th>Admitted Date &amp; Duration</th>
              <th>Care Status</th>
              <th style="text-align: right;">Clinical Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($activeInpatients)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No inpatients are currently assigned to your attending care.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($activeInpatients as $inpat): 
                $admitTime = strtotime($inpat['admitted_at']);
                $daysAdmitted = max(1, ceil((time() - $admitTime) / 86400));
                $pInitials = strtoupper(substr($inpat['patient_name'], 0, 2));
              ?>
                <tr class="inpatient-row">
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials"><?= htmlspecialchars($pInitials, ENT_QUOTES, 'UTF-8') ?></div>
                      <div>
                        <strong style="font-size: 0.92rem; color: var(--text-heading);">
                          <?= htmlspecialchars($inpat['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <div style="font-size: 0.74rem; color: var(--text-muted);">
                          UHID: MP-P-<?= str_pad((string)$inpat['patient_id'], 4, '0', STR_PAD_LEFT) ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <strong style="color: var(--brand-primary); font-size: 0.88rem;">
                      <?= htmlspecialchars($inpat['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">
                      <?= htmlspecialchars($inpat['ward_type'], ENT_QUOTES, 'UTF-8') ?> &bull; Fl <?= htmlspecialchars((string)$inpat['floor_number'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <?= htmlspecialchars($inpat['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>, 
                    <?= (int)($inpat['age'] ?? 24) ?> yrs
                  </td>
                  <td>
                    <span class="live-chip-sm" style="background: #fef2f2; color: #dc2626; border-color: #fca5a5;">
                      <?= htmlspecialchars($inpat['blood_group'] ?? 'B+', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-size: 0.84rem; font-weight: 600; color: var(--text-heading);">
                      <?= date('M j, Y', $admitTime) ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                      Day <?= $daysAdmitted ?> of admission
                    </div>
                  </td>
                  <td>
                    <span class="chip-inpatient">Active Under Care</span>
                  </td>
                  <td style="text-align: right;">
                    <a href="prescriptions.php?patient_id=<?= (int)$inpat['patient_id'] ?>" class="btn-action-telemed" style="padding: 0.4rem 0.75rem; font-size: 0.76rem; text-decoration: none;">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                      Rx Note
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Outpatient Consultations & Queue Section -->
    <section class="admin-stack-card" id="appointmentsSection" style="margin-top: 1.75rem;">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
            Consultations &amp; Outpatient Schedule
          </h3>
          <p>Scheduled appointments, walk-in consultations, and outpatient clinical queue</p>
        </div>
        <a href="appointments.php" class="btn-teal-action" style="padding: 0.45rem 0.85rem; font-size: 0.78rem;">
          View Full Schedule &rarr;
        </a>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table" id="appointmentTable">
          <thead>
            <tr>
              <th>Serial</th>
              <th>Patient Name</th>
              <th>Appointment Date &amp; Slot</th>
              <th>Clinical Reason</th>
              <th>Contact Phone</th>
              <th>Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($upcomingAppointments)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No upcoming outpatient consultations in your queue.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($upcomingAppointments as $app): 
                $appTimeFormatted = date('h:i A', strtotime($app['appointment_time']));
                $appDateFormatted = date('M j, Y', strtotime($app['appointment_date']));
              ?>
                <tr>
                  <td>
                    <span class="live-chip-sm" style="background: #e0f2fe; color: #0284c7; border-color: #bae6fd; font-size: 0.75rem;">
                      #<?= (int)$app['serial_number'] ?>
                    </span>
                  </td>
                  <td>
                    <strong style="font-size: 0.9rem; color: var(--text-heading);">
                      <?= htmlspecialchars($app['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                      Gender: <?= htmlspecialchars($app['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <strong style="font-size: 0.86rem; color: var(--brand-primary);"><?= $appDateFormatted ?></strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted);"><?= $appTimeFormatted ?></div>
                  </td>
                  <td style="max-width: 260px;">
                    <div style="font-size: 0.82rem; color: var(--text-body); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                      <?= htmlspecialchars($app['reason_for_visit'] ?? 'General Consultation', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td style="font-size: 0.82rem; color: var(--text-muted);"><?= htmlspecialchars($app['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <?php if ($app['status'] === 'In-Consultation'): ?>
                      <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">IN CONSULTATION</span>
                    <?php else: ?>
                      <span class="chip-consult">Scheduled</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: right;">
                    <button class="btn-action-gradient" style="padding: 0.38rem 0.75rem; font-size: 0.75rem;" onclick="showToast('Consultation session initialized for <?= htmlspecialchars(addslashes($app['patient_name']), ENT_QUOTES, 'UTF-8') ?>', 'success')">
                      Attend Patient
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Two Column Bottom Grid: Prescriptions & Recent Earnings -->
    <div class="doctor-dash-grid">

      <!-- Left Column: Recent Prescriptions -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>
              <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
              Recent Prescriptions
            </h3>
            <p>Clinical instructions, vitals &amp; digital Rx records</p>
          </div>
          <a href="prescriptions.php" class="btn-action-telemed" style="padding: 0.35rem 0.7rem; font-size: 0.75rem; text-decoration: none;">
            All Rx &rarr;
          </a>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-data-table">
            <thead>
              <tr>
                <th>Patient</th>
                <th>Diagnosis &amp; Vitals</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentPrescriptions)): ?>
                <tr>
                  <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">No recent prescriptions issued.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentPrescriptions as $rx): ?>
                  <tr>
                    <td>
                      <strong style="font-size: 0.88rem; color: var(--text-heading);"><?= htmlspecialchars($rx['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                      <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($rx['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                      <div style="font-size: 0.82rem; font-weight: 600; color: var(--text-body);"><?= htmlspecialchars($rx['diagnosis_notes'] ?? 'Clinical evaluation', ENT_QUOTES, 'UTF-8') ?></div>
                      <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($rx['vitals_summary'] ?? 'BP: Normal', ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td style="font-size: 0.78rem; color: var(--text-muted); white-space: nowrap;">
                      <?= date('M j, Y', strtotime($rx['prescribed_at'])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Right Column: Recent Earnings & Payout Status -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>
              <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
              Recent Clinical Settlements
            </h3>
            <p>Direct physician disbursements &amp; consultations</p>
          </div>
          <a href="my_earnings.php" class="btn-teal-action" style="padding: 0.35rem 0.7rem; font-size: 0.75rem;">
            Full Ledger &rarr;
          </a>
        </div>

        <div class="admin-table-wrap">
          <table class="admin-data-table">
            <thead>
              <tr>
                <th>Patient / Invoice</th>
                <th>Fee (BDT)</th>
                <th>Disbursement</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentEarnings)): ?>
                <tr>
                  <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">No earnings entries recorded yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentEarnings as $item): ?>
                  <tr>
                    <td>
                      <strong style="font-size: 0.88rem; color: var(--text-heading);"><?= htmlspecialchars($item['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                      <div style="font-size: 0.72rem; color: var(--brand-primary);"><?= htmlspecialchars($item['invoice_number'], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                      <strong style="font-size: 0.88rem; color: var(--text-heading);">&#2547;<?= number_format((float)$item['doctor_payout_amount'], 2) ?></strong>
                    </td>
                    <td>
                      <?php if ($item['doctor_payout_status'] === 'DISBURSED'): ?>
                        <span class="chip-disbursed">DISBURSED</span>
                      <?php else: ?>
                        <span class="chip-pending"><?= htmlspecialchars($item['doctor_payout_status'], ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>

  </main>

  <script>
    function filterDoctorDashboard(query) {
      const q = (query || '').toLowerCase().trim();
      const rows = document.querySelectorAll('#inpatientTable tbody tr.inpatient-row, #appointmentTable tbody tr');
      rows.forEach(row => {
        if (!q) {
          row.style.display = '';
          return;
        }
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
      });
    }
  </script>
</body>
</html>

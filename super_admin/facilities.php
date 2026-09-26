<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — Network Facilities & Emergency Routing Console
 *
 * Implements:
 * 1. Cross-Branch High-Level Metric Tiles (Network Beds, Occupancy %, Today's OPD, Active Chamber, Pending Staff)
 * 2. Facility Capacity Matrix & Emergency Ambulance Diversion Toggle Controls
 * 3. Global Cross-Branch Staff & Doctor Credential Directory & Overrides
 * 4. System Audit Log Tracking with bound parameters
 * 5. Strict super_admin RBAC access protection
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../controllers/NetworkManagementController.php';

use MedPulse\Controllers\NetworkManagementController;

// ── REQUIREMENT 5: STRICT SUPER ADMIN RBAC ACCESS PROTECTION ─────────────────
if (empty($_SESSION['role']) || strtolower($_SESSION['role']) !== 'super_admin') {
    http_response_code(403);
    medpulseDestroySession('../login.php?error=unauthorized');
    exit;
}

$feedback = null;
$feedbackType = 'success';

// ── ACTION HANDLERS (POST) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $postedCsrf = $_POST['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        http_response_code(403);
        die('403 Forbidden: Invalid CSRF token.');
    }

    $isAjax = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
           || (!empty($_POST['ajax']));

    // ── ACTION 1: Emergency Ambulance Diversion Toggle ────────────────────────
    if ($action === 'toggle_diversion') {
        $hospitalId = filter_var($_POST['hospital_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus  = trim($_POST['new_status'] ?? 'Operational');
        $actorId    = (int)($_SESSION['user_id'] ?? 0);

        if ($hospitalId) {
            $result = NetworkManagementController::toggleEmergencyDiversion($pdo, $hospitalId, $newStatus, $actorId);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
            }
            $feedback = $result['message'];
            $feedbackType = $result['success'] ? 'success' : 'error';
        }
    }

    // ── ACTION 2: Override Staff / Doctor Credential Status ───────────────────
    if ($action === 'override_staff') {
        $targetUserId = filter_var($_POST['target_user_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus    = trim($_POST['new_status'] ?? '');
        $actorId      = (int)($_SESSION['user_id'] ?? 0);

        if ($targetUserId && $newStatus) {
            $result = NetworkManagementController::overrideStaffStatus($pdo, $targetUserId, $newStatus, $actorId);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
            }
            $feedback = $result['message'];
            $feedbackType = $result['success'] ? 'success' : 'error';
        }
    }
}

// ── REQUIREMENT 1: NETWORK-WIDE SUMMARY KPIS ────────────────────────────────
$kpis = NetworkManagementController::getNetworkKPIs($pdo);

// ── REQUIREMENT 2: FACILITY CAPACITY MATRIX & DIVERSION ─────────────────────
$facilities = NetworkManagementController::getFacilityCapacityMatrix($pdo);

// ── REQUIREMENT 3: GLOBAL FACILITY STAFF & DOCTOR DIRECTORY ─────────────────
$filterHosp   = filter_var($_GET['hospital_id'] ?? null, FILTER_VALIDATE_INT);
$filterRole   = trim($_GET['role'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all');
$searchTerm   = trim($_GET['search'] ?? '');

$staffList = NetworkManagementController::getGlobalStaffDirectory(
    $pdo,
    $filterHosp,
    $filterRole,
    $filterStatus,
    $searchTerm
);

// ── REQUIREMENT 4: SYSTEM AUDIT LOG TRACKING (RECENT SECURITY LOGS) ─────────
$recentSecurityLogs = $pdo->query("
    SELECT 
        COALESCE(id, log_id) AS id,
        user_id,
        actor_id,
        action,
        target_hospital_id,
        COALESCE(details, description) AS details,
        COALESCE(timestamp, created_at) AS timestamp,
        security_level,
        ip_address
    FROM audit_logs
    ORDER BY created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Hospital list for dropdown filter
$allHospitalsList = $pdo->query("SELECT hospital_id, name FROM hospitals ORDER BY hospital_id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse Super Admin — Network Facilities &amp; Emergency Routing</title>

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%237c3aed'/><stop offset='100%25' stop-color='%230284c7'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 12v40M12 32h40' stroke='%23ffffff' stroke-width='6' stroke-linecap='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    :root {
      --sa-accent: #7c3aed;
      --sa-accent-soft: rgba(124, 58, 237, 0.12);
      --sa-gradient: linear-gradient(135deg, #7c3aed, #4f46e5);
    }
    .sa-header-banner {
      background: linear-gradient(135deg, rgba(30, 27, 75, 0.95), rgba(15, 23, 42, 0.98));
      border: 1px solid rgba(124, 58, 237, 0.25);
      border-radius: var(--radius-lg);
      padding: 1.75rem 2rem;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
    }
    .sa-header-banner::after {
      content: '';
      position: absolute;
      top: -40px;
      right: -40px;
      width: 180px;
      height: 180px;
      background: radial-gradient(circle, rgba(124, 58, 237, 0.3), transparent 70%);
      pointer-events: none;
    }
    .sa-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(124, 58, 237, 0.2);
      border: 1px solid rgba(124, 58, 237, 0.4);
      color: #c084fc;
      padding: 4px 12px;
      border-radius: 9999px;
      font-size: 0.82rem;
      font-weight: 700;
      margin-bottom: 8px;
    }
    .kpi-metric-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      padding: 1.25rem 1.5rem;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .kpi-metric-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
    }
    .kpi-progress-bar {
      height: 6px;
      background: var(--bg-secondary);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 8px;
    }
    .kpi-progress-fill {
      height: 100%;
      border-radius: 999px;
      transition: width 0.4s ease;
    }
    .btn-toggle-divert {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: #ffffff;
      border: none;
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s;
    }
    .btn-toggle-divert:hover {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }
    .btn-toggle-restore {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #ffffff;
      border: none;
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s;
    }
    .btn-toggle-restore:hover {
      background: linear-gradient(135deg, #059669, #047857);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      background: var(--bg-card);
      padding: 1rem 1.25rem;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-color);
      margin-bottom: 1.5rem;
    }
    .filter-ctrl {
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      color: var(--text-heading);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.88rem;
      outline: none;
    }
    .badge-divert {
      background: rgba(239, 68, 68, 0.15);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.35);
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .badge-operational {
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.35);
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .btn-sm-action {
      padding: 4px 10px;
      font-size: 0.75rem;
      font-weight: 600;
      border-radius: 6px;
      cursor: pointer;
      border: none;
    }
  </style>
</head>
<body>

  <!-- Centralized Super Admin Sidebar -->
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <!-- Main Viewport Past Sidebar -->
  <main class="viewport-full">

    <?php if ($feedback): ?>
      <div style="background: <?= $feedbackType === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#10b981' : '#ef4444' ?>; color: <?= $feedbackType === 'success' ? '#34d399' : '#f87171' ?>; padding: 12px 18px; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
        <?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Executive Header Banner -->
    <div class="sa-header-banner">
      <div class="sa-badge">
        <svg class="ui-ico" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
        SUPER ADMIN COMMAND &bull; <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <h1 style="font-size: 1.75rem; font-weight: 800; color: #ffffff; margin: 0 0 8px;">
        Network Overview &amp; Emergency Ambulance Diversion Controls
      </h1>
      <p style="font-size: 0.92rem; color: #94a3b8; margin: 0; max-width: 820px; line-height: 1.5;">
        Enterprise-level command center across all 6 partner hospital facilities (MedPulse Central, Square, United, Evercare, UMCH, NIBPS). Monitor network capacity, manage live hospital emergency statuses, and enforce clinical credential governance.
      </p>
    </div>

    <!-- ── REQUIREMENT 1: CROSS-BRANCH HIGH-LEVEL METRIC TILES ──────────────── -->
    <div class="stat-cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-bottom: 2rem;">
      
      <!-- Metric 1: Bed Capacity & Occupancy Rate with Progress Bar -->
      <div class="kpi-metric-card" style="border-left: 4px solid var(--sa-accent);">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">
              Total Network Bed Capacity
            </span>
            <span style="font-size: 0.75rem; font-weight: 800; color: #c084fc;">
              <?= $kpis['total_facilities'] ?> Facilities
            </span>
          </div>
          <div style="font-size: 1.65rem; font-weight: 800; color: var(--text-heading); margin: 6px 0 2px;">
            <?= number_format($kpis['network_beds']) ?>
            <span style="font-size: 0.9rem; font-weight: 500; color: var(--text-muted);">Beds</span>
          </div>
        </div>

        <div>
          <div style="display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 700; margin-top: 6px;">
            <span style="color: var(--text-muted);"><?= number_format($kpis['occupied_beds']) ?> Occupied</span>
            <span style="color: <?= $kpis['occupancy_rate'] > 85 ? '#ef4444' : ($kpis['occupancy_rate'] > 65 ? '#f59e0b' : '#10b981') ?>;">
              <?= $kpis['occupancy_rate'] ?>% Occupied
            </span>
          </div>
          <div class="kpi-progress-bar">
            <div class="kpi-progress-fill" style="width: <?= min(100, $kpis['occupancy_rate']) ?>%; background: <?= $kpis['occupancy_rate'] > 85 ? '#ef4444' : ($kpis['occupancy_rate'] > 65 ? '#f59e0b' : '#10b981') ?>;"></div>
          </div>
        </div>
      </div>

      <!-- Metric 2: Today's Total Network OPD Appointments -->
      <div class="kpi-metric-card" style="border-left: 4px solid #0284c7;">
        <div>
          <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">
            Today's OPD Consultations
          </div>
          <div style="font-size: 1.65rem; font-weight: 800; color: #0284c7; margin: 6px 0 2px;">
            <?= number_format($kpis['today_appointments']) ?>
          </div>
        </div>
        <div style="font-size: 0.78rem; color: var(--text-muted);">
          Scheduled across all 6 hospital chambers today
        </div>
      </div>

      <!-- Metric 3: Active In-Consultation Queue Count -->
      <div class="kpi-metric-card" style="border-left: 4px solid #10b981;">
        <div>
          <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">
            Active In-Consultation Queue
          </div>
          <div style="font-size: 1.65rem; font-weight: 800; color: #10b981; margin: 6px 0 2px;">
            <?= number_format($kpis['active_in_consultation']) ?>
          </div>
        </div>
        <div style="font-size: 0.78rem; color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 4px;">
          <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981;"></span>
          Currently inside physician chambers
        </div>
      </div>

      <!-- Metric 4: Pending Doctor & Staff Registrations -->
      <div class="kpi-metric-card" style="border-left: 4px solid #f59e0b;">
        <div>
          <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">
            Pending Registrations Queue
          </div>
          <div style="font-size: 1.65rem; font-weight: 800; color: #f59e0b; margin: 6px 0 2px;">
            <?= number_format($kpis['pending_registrations']) ?>
          </div>
        </div>
        <div style="font-size: 0.78rem; color: var(--text-muted);">
          Doctors &amp; staff awaiting facility verification
        </div>
      </div>

    </div>

    <!-- ── REQUIREMENT 2: FACILITY CAPACITY MATRIX & EMERGENCY DIVERSION CONTROL ── -->
    <section class="admin-table-wrap" style="margin-bottom: 2.5rem;">
      <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
          <h2 style="font-size: 1.2rem; font-weight: 800; color: var(--text-heading); margin: 0 0 4px; display: flex; align-items: center; gap: 8px;">
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
            Facility Capacity Matrix &amp; Emergency Ambulance Diversion Controls
          </h2>
          <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
            Live telemetry across all 6 partner hospitals. Toggle ambulance diversion to protect overwhelmed emergency departments and warn prospective patients.
          </p>
        </div>
        <span class="live-chip-sm" style="background: rgba(124, 58, 237, 0.15); color: #a855f7; border: 1px solid rgba(124, 58, 237, 0.3);">
          ENTERPRISE ROUTING ACTIVE
        </span>
      </div>

      <table class="admin-data-table">
        <thead>
          <tr>
            <th>Facility Name</th>
            <th>Location &amp; Admin Desk</th>
            <th>Total Capacity</th>
            <th>Available Vacancy</th>
            <th>ICU / Critical</th>
            <th>Emergency Status</th>
            <th style="text-align: right;">Emergency Diversion Toggle</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($facilities as $fac): ?>
            <?php 
              $hId = (int)$fac['hospital_id'];
              $emStatus = trim($fac['emergency_status']);
              $isDiverted = str_contains(strtolower($emStatus), 'divert') || strtolower($emStatus) === 'critical capacity';
              $totBeds = (int)$fac['total_beds'];
              $availBeds = (int)$fac['available_beds'];
              $icuVacant = (int)$fac['icu_beds_available'];
              $icuTot = (int)$fac['icu_beds_total'];
            ?>
            <tr id="facility-row-<?= $hId ?>">
              <td>
                <div style="font-weight: 800; color: var(--text-heading); font-size: 0.95rem;">
                  <?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size: 0.74rem; color: var(--text-muted); font-family: monospace;">
                  ID: #<?= $hId ?> &bull; Operational Status: <?= htmlspecialchars($fac['operational_status'] ?? 'Active') ?>
                </div>
              </td>
              <td>
                <div style="font-size: 0.85rem; color: var(--text-heading);">
                  <?= htmlspecialchars($fac['location'] ?? $fac['city'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size: 0.74rem; color: var(--text-muted);">
                  Admin: <?= htmlspecialchars($fac['branch_admin_contact'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              </td>
              <td>
                <strong style="font-size: 0.95rem; color: var(--text-heading);"><?= number_format($totBeds) ?></strong>
              </td>
              <td>
                <span style="font-weight: 700; color: #10b981; font-size: 0.95rem;"><?= number_format($availBeds) ?></span>
                <span style="font-size: 0.75rem; color: var(--text-muted);">/ <?= number_format($totBeds) ?></span>
              </td>
              <td>
                <span style="font-weight: 700; color: var(--brand-primary); font-size: 0.9rem;"><?= $icuVacant ?></span>
                <span style="font-size: 0.75rem; color: var(--text-muted);">/ <?= $icuTot ?> Vacant</span>
              </td>
              <td>
                <?php if ($isDiverted): ?>
                  <span class="badge-divert">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444;"></span>
                    <?= htmlspecialchars($emStatus) ?>
                  </span>
                <?php else: ?>
                  <span class="badge-operational">
                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981;"></span>
                    Operational
                  </span>
                <?php endif; ?>
              </td>
              <td style="text-align: right;">
                <form method="POST" style="display: inline;" onsubmit="return confirmDiversionToggle(event, <?= $hId ?>, '<?= htmlspecialchars(addslashes($fac['name'])) ?>', '<?= $isDiverted ? 'Operational' : 'Ambulance Divert' ?>')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="toggle_diversion">
                  <input type="hidden" name="hospital_id" value="<?= $hId ?>">
                  <?php if ($isDiverted): ?>
                    <input type="hidden" name="new_status" value="Operational">
                    <button type="submit" class="btn-toggle-restore" title="Restore ER status to normal operational capacity">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      Restore to Operational
                    </button>
                  <?php else: ?>
                    <input type="hidden" name="new_status" value="Ambulance Divert">
                    <button type="submit" class="btn-toggle-divert" title="Trigger Emergency Ambulance Diversion due to trauma surge">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                      Divert Ambulance ER
                    </button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <!-- ── REQUIREMENT 3: GLOBAL FACILITY STAFF & DOCTOR DIRECTORY ───────────── -->
    <section class="admin-table-wrap" style="margin-bottom: 2.5rem;">
      <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color);">
        <h2 style="font-size: 1.2rem; font-weight: 800; color: var(--text-heading); margin: 0 0 4px; display: flex; align-items: center; gap: 8px;">
          <svg class="ui-ico" style="stroke: #a855f7; width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          Global Facility Staff &amp; Doctor Credential Directory
        </h2>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
          Centralized cross-branch registry. Super Administrators retain absolute authority to override branch approvals or revoke clinical credentials directly.
        </p>
      </div>

      <!-- Filters -->
      <form method="GET" class="filter-bar" style="margin: 1rem 1.5rem; border-radius: 8px;">
        <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted);">Facility:</label>
        <select name="hospital_id" class="filter-ctrl" onchange="this.form.submit()">
          <option value="">All 6 Hospitals</option>
          <?php foreach ($allHospitalsList as $hl): ?>
            <option value="<?= (int)$hl['hospital_id'] ?>" <?= $filterHosp === (int)$hl['hospital_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($hl['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); margin-left: 8px;">Role:</label>
        <select name="role" class="filter-ctrl" onchange="this.form.submit()">
          <option value="all">All Roles</option>
          <option value="doctor" <?= $filterRole === 'doctor' ? 'selected' : '' ?>>Doctors Only</option>
          <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>Clinical &amp; Support Staff</option>
          <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Branch Admins</option>
        </select>

        <label style="font-size: 0.82rem; font-weight: 700; color: var(--text-muted); margin-left: 8px;">Status:</label>
        <select name="status" class="filter-ctrl" onchange="this.form.submit()">
          <option value="all">All Statuses</option>
          <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending Review</option>
          <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>

        <input type="text" name="search" class="filter-ctrl" style="min-width: 200px;" placeholder="Search name, phone, BMDC..." value="<?= htmlspecialchars($searchTerm) ?>">

        <button type="submit" class="btn-action-telemed">Filter</button>
        <?php if ($filterHosp || $filterRole !== 'all' || $filterStatus !== 'all' || $searchTerm !== ''): ?>
          <a href="facilities.php" style="font-size: 0.82rem; color: #38bdf8; text-decoration: none; margin-left: 8px;">Reset</a>
        <?php endif; ?>
      </form>

      <table class="admin-data-table">
        <thead>
          <tr>
            <th>Personnel Name</th>
            <th>Role &amp; Specialty</th>
            <th>Assigned Hospital Branch</th>
            <th>License / Staff ID</th>
            <th>Status</th>
            <th style="text-align: right;">Super Admin Override</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($staffList)): ?>
            <tr>
              <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                No personnel records match the specified cross-branch filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($staffList as $stf): ?>
              <?php 
                $uId = (int)$stf['user_id'];
                $stfStatus = strtolower($stf['status'] ?? 'pending');
              ?>
              <tr>
                <td>
                  <strong style="color: var(--text-heading); font-size: 0.92rem;">
                    <?= htmlspecialchars($stf['full_name'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                  <div style="font-size: 0.74rem; color: var(--text-muted);">
                    <?= htmlspecialchars($stf['email'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($stf['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </td>
                <td>
                  <span class="role-pill <?= strtolower($stf['role']) === 'doctor' ? 'role-pill-doctor' : 'role-pill-staff' ?>">
                    <?= htmlspecialchars($stf['role']) ?>
                  </span>
                  <?php if (!empty($stf['specialty'])): ?>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">
                      <?= htmlspecialchars($stf['specialty']) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <span style="font-weight: 600; color: var(--brand-primary); font-size: 0.88rem;">
                    <?= htmlspecialchars($stf['hospital_name'] ?? 'MedPulse Central') ?>
                  </span>
                </td>
                <td>
                  <span class="license-chip">
                    <?= htmlspecialchars($stf['credentials_id'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td>
                  <?php if ($stfStatus === 'active'): ?>
                    <span class="status-badge-active">Active</span>
                  <?php elseif ($stfStatus === 'pending'): ?>
                    <span class="status-badge-pending">Pending Review</span>
                  <?php else: ?>
                    <span class="status-badge-suspended"><?= ucfirst($stfStatus) ?></span>
                  <?php endif; ?>
                </td>
                <td style="text-align: right;">
                  <div class="table-actions-flex" style="justify-content: flex-end; gap: 6px;">
                    <?php if ($stfStatus !== 'active'): ?>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="override_staff">
                        <input type="hidden" name="target_user_id" value="<?= $uId ?>">
                        <input type="hidden" name="new_status" value="active">
                        <button type="submit" class="btn-sm-action" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);" title="Directly override and approve credentials">
                          Approve Override
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($stfStatus !== 'suspended'): ?>
                      <form method="POST" style="display: inline;" onsubmit="return confirm('Revoke and suspend this user credential across network?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="override_staff">
                        <input type="hidden" name="target_user_id" value="<?= $uId ?>">
                        <input type="hidden" name="new_status" value="suspended">
                        <button type="submit" class="btn-sm-action" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);" title="Revoke and suspend access">
                          Revoke
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <!-- ── REQUIREMENT 4: SYSTEM AUDIT LOG TRACKING (RECENT SECURITY EVENTS) ── -->
    <section class="admin-table-wrap" style="margin-bottom: 3rem;">
      <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <div>
          <h2 style="font-size: 1.15rem; font-weight: 800; color: var(--text-heading); margin: 0 0 4px; display: flex; align-items: center; gap: 8px;">
            <svg class="ui-ico" style="stroke: #ef4444; width: 20px; height: 20px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            System Audit Log Tracking (Enterprise Governance)
          </h2>
          <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
            Immutable telemetry records of administrative actions (Emergency Diversions, Credential Overrides, Bed Adjustments).
          </p>
        </div>
        <a href="audit_logs.php" style="font-size: 0.82rem; font-weight: 700; color: #a855f7; text-decoration: none;">View Full Audit Log &rarr;</a>
      </div>

      <table class="admin-data-table">
        <thead>
          <tr>
            <th style="width: 80px;">Log ID</th>
            <th>Action</th>
            <th>Facility Target</th>
            <th>Details &amp; Audit Trail</th>
            <th>Timestamp</th>
            <th>Level</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentSecurityLogs as $log): ?>
            <tr>
              <td>
                <span style="font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: var(--text-muted);">
                  #<?= (int)$log['id'] ?>
                </span>
              </td>
              <td>
                <strong style="color: var(--text-heading); font-size: 0.85rem; font-family: 'JetBrains Mono', monospace;">
                  <?= htmlspecialchars($log['action']) ?>
                </strong>
              </td>
              <td>
                <span style="font-weight: 600; color: var(--brand-primary); font-size: 0.82rem;">
                  <?= $log['target_hospital_id'] ? "Hospital #{$log['target_hospital_id']}" : "Network Global" ?>
                </span>
              </td>
              <td style="font-size: 0.82rem; color: var(--text-body);">
                <?= htmlspecialchars($log['details']) ?>
              </td>
              <td style="font-size: 0.78rem; color: var(--text-muted); white-space: nowrap;">
                <?= htmlspecialchars(date('d M Y, h:i A', strtotime($log['timestamp']))) ?>
              </td>
              <td>
                <?php 
                  $lvl = strtoupper($log['security_level'] ?? 'INFO');
                  $lvlColor = match($lvl) {
                      'CRITICAL' => '#ef4444',
                      'WARNING'  => '#f59e0b',
                      default    => '#10b981'
                  };
                ?>
                <span style="font-size: 0.72rem; font-weight: 800; color: <?= $lvlColor ?>; background: <?= $lvlColor ?>1a; padding: 2px 8px; border-radius: 4px; border: 1px solid <?= $lvlColor ?>33;">
                  <?= $lvl ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

  </main>

  <script>
    function confirmDiversionToggle(e, hospitalId, hospitalName, newStatus) {
      const msg = (newStatus === 'Ambulance Divert')
        ? `⚠️ EMERGENCY DIVERSION ALERT ⚠️\n\nAre you sure you want to put ${hospitalName} on AMBULANCE DIVERT?\n\nThis will trigger public alerts on patient portals warning of high trauma surge and diversion.`
        : `Restore ${hospitalName} to OPERATIONAL status?\n\nThis will clear the ambulance diversion warning.`;
      
      if (!confirm(msg)) {
        e.preventDefault();
        return false;
      }
      return true;
    }
  </script>
</body>
</html>

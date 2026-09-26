<?php
/**
 * MedPulse Enterprise HMS — Patient Consultations & Appointment History
 * 
 * Features:
 * - Comprehensive view of patient's scheduled, serving, completed, and cancelled OPD visits
 * - Live token serial display (#1, #2, etc.)
 * - Patient intake symptoms and chief complaints
 * - 1-Click secure appointment cancellation with CSRF guard
 * - Quick re-booking and live queue tracker links
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/patient_auth.php';

$patientId   = (int)$_SESSION['user_id'];
$patientName = $_SESSION['user_name'] ?? 'Patient';

$flashSuccess = null;
$flashError   = null;

// ── Handle Appointment Cancellation ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_appointment') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flashError = 'Security validation failed. Please refresh the page.';
    } else {
        $cancelId = (int)($_POST['appointment_id'] ?? 0);

        // Fetch appointment to ensure it belongs to this patient and can be cancelled
        $chkStmt = $pdo->prepare("
            SELECT id, doctor_id, token_number, appointment_date, time_slot, status, queue_status 
            FROM appointments 
            WHERE id = ? AND patient_id = ?
        ");
        $chkStmt->execute([$cancelId, $patientId]);
        $targetApp = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetApp) {
            $flashError = 'Appointment record not found or access unauthorized.';
        } elseif (in_array($targetApp['status'], ['completed', 'cancelled'], true) || in_array($targetApp['queue_status'], ['completed', 'cancelled'], true)) {
            $flashError = 'This consultation is already marked as ' . htmlspecialchars($targetApp['status']) . '.';
        } elseif ($targetApp['status'] === 'in_consultation' || $targetApp['queue_status'] === 'serving') {
            $flashError = 'Cannot cancel an appointment that is currently serving inside the chamber.';
        } else {
            $upd = $pdo->prepare("
                UPDATE appointments 
                SET status = 'cancelled', queue_status = 'cancelled' 
                WHERE id = ? AND patient_id = ?
            ");
            $upd->execute([$cancelId, $patientId]);
            $flashSuccess = "Appointment #{$cancelId} (Serial Token #{$targetApp['token_number']}) scheduled for {$targetApp['appointment_date']} was successfully cancelled.";
        }
    }
}

// ── Query All Appointments For Current Patient ──────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT 
            a.id, a.hospital_id, a.doctor_id, a.patient_id, a.appointment_date, 
            a.time_slot, a.token_number, a.serial_number, a.status, a.queue_status,
            a.reason_for_visit, a.symptoms,
            a.estimated_start_time, a.estimated_end_time,
            a.actual_start_time, a.actual_end_time,
            a.created_at,
            (a.appointment_date = CURRENT_DATE) AS is_today,
            (a.appointment_date >= CURRENT_DATE) AS is_upcoming,
            u.full_name AS doctor_name,
            COALESCE(dp.specialty, d.specialty, 'Specialist') AS specialty,
            COALESCE(dp.room_number, d.chamber_room_no, 'Room 101') AS room_number,
            COALESCE(h.name, d.hospital_name, 'MedPulse Hospital & Specialty Care') AS hospital_name,
            COALESCE(h.city, 'Dhaka') AS hospital_city
        FROM appointments a
        JOIN users u ON a.doctor_id = u.user_id
        LEFT JOIN doctors d ON (a.doctor_id = d.id OR a.doctor_id = d.user_id)
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        LEFT JOIN hospitals h ON COALESCE(a.hospital_id, d.hospital_id, dp.hospital_id) = h.hospital_id OR a.hospital_id = h.id
        WHERE a.patient_id = :patient_id
        ORDER BY a.appointment_date DESC, a.token_number ASC, a.id DESC
    ");
    $stmt->execute([':patient_id' => $patientId]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to query appointments: " . $e->getMessage());
    $appointments = [];
}

// ── Calculate Metrics ────────────────────────────────────────────────────────
$totalCount     = count($appointments);
$activeCount    = 0;
$completedCount = 0;
$cancelledCount = 0;

foreach ($appointments as $a) {
    $st = strtolower($a['status'] ?? '');
    $qs = strtolower($a['queue_status'] ?? '');

    if ($st === 'cancelled' || $qs === 'cancelled') {
        $cancelledCount++;
    } elseif ($st === 'completed' || $qs === 'completed') {
        $completedCount++;
    } else {
        $activeCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Consultations &amp; Appointments &middot; MedPulse Hospital System</title>
  
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  
  <style>
    /* Scoped Styles for Consultations Page */
    .consultations-view {
      max-width: 1280px;
      margin: 0 auto;
    }

    /* KPI Summary Stats */
    .kpi-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2rem;
    }
    .kpi-card {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 16px;
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    }
    .kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .kpi-val {
      font-size: 1.45rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.1;
    }
    .kpi-label {
      font-size: 0.78rem;
      color: var(--text-muted);
      font-weight: 600;
      margin-top: 3px;
    }

    /* Tab Filter Pills */
    .tab-nav-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .tab-pills {
      display: flex;
      background: #e2e8f0;
      padding: 4px;
      border-radius: 12px;
      gap: 4px;
    }
    .tab-btn {
      border: none;
      background: transparent;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 700;
      color: var(--text-body);
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .tab-btn.active {
      background: #ffffff;
      color: var(--brand-primary);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    .tab-badge {
      font-size: 0.72rem;
      padding: 2px 6px;
      border-radius: 999px;
      background: #f1f5f9;
      color: var(--text-muted);
    }
    .tab-btn.active .tab-badge {
      background: rgba(2, 132, 199, 0.12);
      color: var(--brand-primary);
    }

    /* Consultations List / Table Container */
    .consultations-container {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 18px;
      box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
      overflow: hidden;
    }
    .consultations-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }
    .consultations-table th {
      background: #f8fafc;
      color: var(--text-muted);
      font-size: 0.76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid var(--surface-border);
    }
    .consultations-table td {
      padding: 1.15rem 1.25rem;
      border-bottom: 1px solid var(--surface-border-subtle);
      vertical-align: middle;
      font-size: 0.88rem;
    }
    .consultations-table tr:last-child td {
      border-bottom: none;
    }
    .consultations-table tr:hover td {
      background-color: #fafbfc;
    }

    /* Date & Shift Cell */
    .cell-date {
      font-weight: 700;
      color: var(--text-heading);
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .shift-tag {
      font-size: 0.74rem;
      font-weight: 600;
      color: var(--text-muted);
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .today-tag {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.68rem;
      font-weight: 800;
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.25);
      border-radius: 4px;
      padding: 1px 5px;
      width: fit-content;
    }

    /* Doctor Info Cell */
    .cell-doc-name {
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 2px;
    }
    .cell-doc-spec {
      font-size: 0.78rem;
      color: var(--brand-teal);
      font-weight: 600;
      margin-bottom: 2px;
    }
    .cell-doc-room {
      font-size: 0.76rem;
      color: var(--text-muted);
    }

    /* Token Badge */
    .token-serial-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 44px;
      height: 32px;
      padding: 0 10px;
      border-radius: 8px;
      background: linear-gradient(135deg, rgba(2, 132, 199, 0.12), rgba(13, 148, 136, 0.12));
      border: 1.5px solid rgba(2, 132, 199, 0.25);
      color: var(--brand-primary);
      font-size: 0.95rem;
      font-weight: 800;
      letter-spacing: -0.2px;
    }

    /* Symptoms Cell */
    .symptoms-text {
      max-width: 240px;
      font-size: 0.82rem;
      color: var(--text-body);
      line-height: 1.35;
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }
    .symptoms-empty {
      font-size: 0.78rem;
      color: var(--text-muted);
      font-style: italic;
    }

    /* Status Badges */
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 0.35rem 0.75rem;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .badge-scheduled {
      background: rgba(2, 132, 199, 0.1);
      color: var(--brand-primary);
      border: 1px solid rgba(2, 132, 199, 0.25);
    }
    .badge-serving {
      background: rgba(13, 148, 136, 0.12);
      color: var(--brand-teal);
      border: 1px solid rgba(13, 148, 136, 0.3);
      animation: pulse-ring 2s infinite ease-in-out;
    }
    .badge-completed {
      background: rgba(16, 185, 129, 0.1);
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.25);
    }
    .badge-cancelled {
      background: rgba(239, 68, 68, 0.1);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.25);
    }
    @keyframes pulse-ring {
      0%, 100% { box-shadow: 0 0 0 0 rgba(13, 148, 136, 0.2); }
      50% { box-shadow: 0 0 0 6px rgba(13, 148, 136, 0); }
    }

    /* Action Buttons */
    .actions-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .btn-track-queue {
      padding: 0.45rem 0.85rem;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all 0.2s ease;
      box-shadow: 0 2px 6px rgba(2, 132, 199, 0.2);
    }
    .btn-track-queue:hover {
      opacity: 0.95;
      transform: translateY(-1px);
    }
    .btn-cancel-app {
      padding: 0.45rem 0.85rem;
      background: #fff;
      color: #ef4444;
      border: 1.5px solid #fecaca;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s ease;
    }
    .btn-cancel-app:hover {
      background: #fef2f2;
      border-color: #ef4444;
    }
    .btn-rebook {
      padding: 0.45rem 0.85rem;
      background: #f1f5f9;
      color: var(--text-body);
      border: 1px solid var(--surface-border);
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s ease;
    }
    .btn-rebook:hover {
      background: #e2e8f0;
      color: var(--text-heading);
    }

    /* Empty State */
    .empty-consultations {
      padding: 4rem 1.5rem;
      text-align: center;
    }
    .empty-consultations svg {
      width: 56px;
      height: 56px;
      stroke: var(--text-muted);
      margin-bottom: 1.25rem;
    }
    .empty-consultations h3 {
      font-size: 1.2rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.35rem;
    }
    .empty-consultations p {
      color: var(--text-muted);
      font-size: 0.9rem;
      max-width: 440px;
      margin: 0 auto 1.5rem;
    }

    /* Alerts */
    .alert-box {
      border-radius: 12px;
      padding: 0.9rem 1.25rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.88rem;
    }
    .alert-success {
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      color: #065f46;
    }
    .alert-danger {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    @media (max-width: 860px) {
      .consultations-table, .consultations-table thead, .consultations-table tbody, .consultations-table th, .consultations-table td, .consultations-table tr {
        display: block;
      }
      .consultations-table thead {
        display: none;
      }
      .consultations-table tr {
        border-bottom: 1.5px solid var(--surface-border);
        padding: 1rem 0;
      }
      .consultations-table td {
        padding: 0.5rem 1.25rem;
        border: none;
      }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <main class="viewport">
    <div class="consultations-view">

      <!-- Header Section -->
      <div class="welcome-banner" style="margin-bottom: 1.75rem;">
        <div class="welcome-text">
          <h1>Clinical Consultations &amp; Visits</h1>
          <p>Review your scheduled OPD appointments, real-time sequential chamber tokens, intake chief complaints, and consultation visit records.</p>
        </div>
        <div class="banner-actions">
          <a href="specialists.php" class="btn-action-gradient" style="text-decoration:none;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: #ffffff;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            + Book Specialist Consultation
          </a>
        </div>
      </div>

      <!-- Flash Messages -->
      <?php if (!empty($flashSuccess)): ?>
        <div class="alert-box alert-success">
          <svg class="ui-ico" style="stroke: #059669;" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          <div><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($flashError)): ?>
        <div class="alert-box alert-danger">
          <svg class="ui-ico" style="stroke: #ef4444;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <div><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <!-- Summary KPI Row -->
      <div class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-icon" style="background: rgba(2, 132, 199, 0.12); color: var(--brand-primary);">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          </div>
          <div>
            <div class="kpi-val"><?= $totalCount ?></div>
            <div class="kpi-label">Total Consultations</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background: rgba(13, 148, 136, 0.12); color: var(--brand-teal);">
            <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          </div>
          <div>
            <div class="kpi-val"><?= $activeCount ?></div>
            <div class="kpi-label">Active / Scheduled Visits</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          </div>
          <div>
            <div class="kpi-val"><?= $completedCount ?></div>
            <div class="kpi-label">Completed Consultations</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
            <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
          </div>
          <div>
            <div class="kpi-val"><?= $cancelledCount ?></div>
            <div class="kpi-label">Cancelled Visits</div>
          </div>
        </div>
      </div>

      <!-- Tab Filter Navigation -->
      <div class="tab-nav-row">
        <div class="tab-pills">
          <button type="button" class="tab-btn active" data-filter="all">
            All Records <span class="tab-badge"><?= $totalCount ?></span>
          </button>
          <button type="button" class="tab-btn" data-filter="active">
            Active &amp; Scheduled <span class="tab-badge"><?= $activeCount ?></span>
          </button>
          <button type="button" class="tab-btn" data-filter="completed">
            Completed <span class="tab-badge"><?= $completedCount ?></span>
          </button>
          <button type="button" class="tab-btn" data-filter="cancelled">
            Cancelled <span class="tab-badge"><?= $cancelledCount ?></span>
          </button>
        </div>

        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">
          Strict 25-Patient Quality Policy Enforced
        </div>
      </div>

      <!-- Consultations Table / Cards -->
      <div class="consultations-container">
        <?php if (empty($appointments)): ?>
          <div class="empty-consultations">
            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <h3>No Consultation Records Found</h3>
            <p>You have not booked any OPD consultations yet. Browse our verified medical specialists to schedule your first consultation.</p>
            <a href="specialists.php" class="btn-action-gradient" style="text-decoration:none; display:inline-flex;">
              + Browse Medical Specialists
            </a>
          </div>
        <?php else: ?>
          <table class="consultations-table">
            <thead>
              <tr>
                <th>Date &amp; Shift</th>
                <th>Doctor &amp; Chamber</th>
                <th style="text-align: center;">Assigned Token #</th>
                <th>Intake Symptoms</th>
                <th>Status</th>
                <th style="text-align: right;">Actions</th>
              </tr>
            </thead>
            <tbody id="consultationsTableBody">
              <?php foreach ($appointments as $app): ?>
                <?php
                  $st = strtolower($app['status'] ?? '');
                  $qs = strtolower($app['queue_status'] ?? '');

                  $isCancelled = ($st === 'cancelled' || $qs === 'cancelled');
                  $isCompleted = ($st === 'completed' || $qs === 'completed');
                  $isServing   = ($st === 'in_consultation' || $qs === 'serving');
                  $isActive    = (!$isCancelled && !$isCompleted);

                  $rowCategory = 'active';
                  if ($isCancelled) $rowCategory = 'cancelled';
                  elseif ($isCompleted) $rowCategory = 'completed';

                  $dateObj     = new DateTime($app['appointment_date']);
                  $dateFormatted = $dateObj->format('D, M j, Y');
                  $isToday     = (!empty($app['is_today']) || $app['appointment_date'] === date('Y-m-d'));
                  $symptomsText = trim((string)($app['symptoms'] ?? ''));
                  if (empty($symptomsText)) {
                      $symptomsText = trim((string)($app['reason_for_visit'] ?? ''));
                  }
                ?>
                <tr class="app-row" data-category="<?= $rowCategory ?>">
                  
                  <!-- 1. Date & Shift -->
                  <td>
                    <div class="cell-date">
                      <span><?= htmlspecialchars($dateFormatted) ?></span>
                      <div class="shift-tag">
                        <svg class="ui-ico ui-ico-sm" style="width: 13px; height: 13px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?= htmlspecialchars($app['time_slot'] ?? 'Morning') ?> Shift
                      </div>
                      <?php if ($isToday): ?>
                        <span class="today-tag">
                          <span style="width: 6px; height: 6px; border-radius: 50%; background: #059669;"></span>
                          TODAY
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>

                  <!-- 2. Doctor & Chamber -->
                  <td>
                    <div class="cell-doc-name"><?= htmlspecialchars($app['doctor_name']) ?></div>
                    <div class="cell-doc-spec"><?= htmlspecialchars($app['specialty']) ?></div>
                    <div class="cell-doc-room">
                      <strong><?= htmlspecialchars($app['room_number']) ?></strong> &middot; <?= htmlspecialchars($app['hospital_name']) ?>
                    </div>
                  </td>

                  <!-- 3. Assigned Token # -->
                  <td style="text-align: center;">
                    <div class="token-serial-pill" title="Assigned Queue Token Serial">
                      #<?= (int)$app['token_number'] ?>
                    </div>
                  </td>

                  <!-- 4. Intake Symptoms -->
                  <td>
                    <?php if (!empty($symptomsText)): ?>
                      <div class="symptoms-text" title="<?= htmlspecialchars($symptomsText) ?>">
                        <?= htmlspecialchars($symptomsText) ?>
                      </div>
                    <?php else: ?>
                      <span class="symptoms-empty">None recorded (Standard consultation)</span>
                    <?php endif; ?>
                  </td>

                  <!-- 5. Status Badge -->
                  <td>
                    <?php if ($isCancelled): ?>
                      <span class="badge-status badge-cancelled">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                        Cancelled
                      </span>
                    <?php elseif ($isCompleted): ?>
                      <span class="badge-status badge-completed">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        Completed
                      </span>
                    <?php elseif ($isServing): ?>
                      <span class="badge-status badge-serving">
                        <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--brand-teal);"></span>
                        Serving in Chamber
                      </span>
                    <?php else: ?>
                      <span class="badge-status badge-scheduled">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        Scheduled
                      </span>
                    <?php endif; ?>
                  </td>

                  <!-- 6. Actions -->
                  <td>
                    <div class="actions-cell" style="justify-content: flex-end;">
                      <?php if ($isActive): ?>
                        
                        <?php if ($isToday): ?>
                          <a href="dashboard.php" class="btn-track-queue">
                            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: #ffffff;"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                            Track Live Queue
                          </a>
                        <?php endif; ?>

                        <!-- Active Cancel Button -->
                        <form action="appointments.php" method="POST" onsubmit="return confirm('Are you sure you want to cancel this appointment (Token #<?= (int)$app['token_number'] ?>)?');" style="margin: 0;">
                          <input type="hidden" name="action" value="cancel_appointment">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                          <input type="hidden" name="appointment_id" value="<?= (int)$app['id'] ?>">
                          <button type="submit" class="btn-cancel-app" title="Cancel this consultation booking">
                            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            Cancel Appointment
                          </button>
                        </form>

                      <?php elseif ($isCompleted): ?>
                        <a href="specialists.php?book_doctor_id=<?= (int)$app['doctor_id'] ?>" class="btn-rebook">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                          Book Follow-Up
                        </a>
                      <?php elseif ($isCancelled): ?>
                        <a href="specialists.php?book_doctor_id=<?= (int)$app['doctor_id'] ?>" class="btn-rebook">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                          Re-Book Doctor
                        </a>
                      <?php endif; ?>
                    </div>
                  </td>

                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div id="noFilteredRows" style="display: none; padding: 3rem 1.5rem; text-align: center; color: var(--text-muted);">
            <svg class="ui-ico" style="width: 40px; height: 40px; margin-bottom: 0.5rem;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>
            <div style="font-weight: 700; color: var(--text-heading);">No records match this filter</div>
            <div style="font-size: 0.85rem; margin-top: 3px;">Try switching to another tab or book a new consultation.</div>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </main>

  <!-- ── Tab Filter Javascript ─────────────────────────────────────────── -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const tabButtons    = document.querySelectorAll('.tab-btn');
      const tableRows     = document.querySelectorAll('.app-row');
      const noFilteredMsg = document.getElementById('noFilteredRows');

      tabButtons.forEach(btn => {
        btn.addEventListener('click', function() {
          tabButtons.forEach(b => b.classList.remove('active'));
          this.classList.add('active');

          const filter = this.getAttribute('data-filter') || 'all';
          let visibleRows = 0;

          tableRows.forEach(row => {
            const category = row.getAttribute('data-category') || '';
            if (filter === 'all' || category === filter) {
              row.style.display = '';
              visibleRows++;
            } else {
              row.style.display = 'none';
            }
          });

          if (noFilteredMsg) {
            noFilteredMsg.style.display = (visibleRows === 0) ? 'block' : 'none';
          }
        });
      });
    });
  </script>
</body>
</html>

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
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_POST['format']) && $_POST['format'] === 'json')
              || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $flashError = 'Security validation failed. Please refresh the page.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $flashError]);
            exit;
        }
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
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $flashError]);
                exit;
            }
        } elseif (in_array($targetApp['status'], ['completed', 'cancelled'], true) || in_array($targetApp['queue_status'], ['completed', 'cancelled'], true)) {
            $flashError = 'This consultation is already marked as ' . htmlspecialchars($targetApp['status']) . '.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $flashError]);
                exit;
            }
        } elseif ($targetApp['status'] === 'in_consultation' || $targetApp['queue_status'] === 'serving') {
            $flashError = 'Cannot cancel an appointment that is currently serving inside the chamber.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $flashError]);
                exit;
            }
        } else {
            $upd = $pdo->prepare("
                UPDATE appointments 
                SET status = 'cancelled', queue_status = 'cancelled' 
                WHERE id = ? AND patient_id = ?
            ");
            $upd->execute([$cancelId, $patientId]);
            $flashSuccess = "Appointment #{$cancelId} (Token #{$targetApp['token_number']}) scheduled for {$targetApp['appointment_date']} was successfully cancelled. This slot has been released back to the queue.";
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $flashSuccess,
                    'appointment_id' => $cancelId,
                    'token_number' => (int)$targetApp['token_number'],
                    'doctor_id' => (int)$targetApp['doctor_id'],
                    'appointment_date' => $targetApp['appointment_date']
                ]);
                exit;
            }
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
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">
  
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

    /* ── Live Queue Sync Panel ──────────────────────────────────────────────── */
    .live-queue-panel {
      background: linear-gradient(135deg, #0f172a 0%, #0d1f35 60%, #0f2d40 100%);
      border: 1px solid rgba(56, 189, 248, 0.2);
      border-radius: 18px;
      padding: 1.5rem 1.75rem;
      margin-bottom: 1.75rem;
      position: relative;
      overflow: hidden;
    }
    .live-queue-panel::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(ellipse at top right, rgba(56, 189, 248, 0.07) 0%, transparent 70%);
      pointer-events: none;
    }
    .lq-header {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      margin-bottom: 1.15rem;
    }
    .lq-live-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 10px #10b981;
      animation: lqPulse 1.6s infinite ease-in-out;
      flex-shrink: 0;
    }
    @keyframes lqPulse {
      0%, 100% { transform: scale(0.9); opacity: 0.75; }
      50%       { transform: scale(1.4); opacity: 1;    }
    }
    .lq-title {
      font-size: 0.78rem;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      color: #38bdf8;
    }
    .lq-sub {
      font-size: 0.72rem;
      color: rgba(148, 163, 184, 0.8);
      margin-left: auto;
    }
    .lq-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 1rem;
    }
    .lq-card {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 14px;
      padding: 1.1rem 1.25rem;
      transition: border-color 0.3s ease;
    }
    .lq-card.active-now {
      border-color: rgba(16, 185, 129, 0.45);
      background: rgba(16, 185, 129, 0.07);
    }
    .lq-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.85rem;
    }
    .lq-doctor-name {
      font-size: 0.84rem;
      font-weight: 800;
      color: #e2e8f0;
    }
    .lq-shift-badge {
      font-size: 0.68rem;
      font-weight: 700;
      padding: 0.2rem 0.55rem;
      border-radius: 6px;
      background: rgba(56, 189, 248, 0.12);
      color: #38bdf8;
      border: 1px solid rgba(56, 189, 248, 0.25);
    }
    .lq-metrics-row {
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
      align-items: center;
    }
    .lq-metric {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.15rem;
      min-width: 70px;
    }
    .lq-metric-val {
      font-size: 1.75rem;
      font-weight: 800;
      line-height: 1;
    }
    .lq-metric-label {
      font-size: 0.64rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      color: rgba(148, 163, 184, 0.7);
    }
    .lq-metric.serving  .lq-metric-val { color: #34d399; }
    .lq-metric.my-token .lq-metric-val { color: #38bdf8; }
    .lq-metric.position .lq-metric-val { color: #fbbf24; }
    .lq-metric.wait     .lq-metric-val { color: #f87171; }
    .lq-divider {
      width: 1px;
      height: 40px;
      background: rgba(255,255,255,0.1);
      align-self: center;
    }
    .lq-status-msg {
      margin-top: 0.75rem;
      font-size: 0.78rem;
      color: rgba(148,163,184,0.8);
      line-height: 1.4;
    }
    .lq-status-msg.calling {
      color: #34d399;
      font-weight: 700;
    }
    .lq-status-msg.done {
      color: #94a3b8;
    }
    .lq-empty {
      text-align: center;
      padding: 1.5rem;
      color: rgba(148, 163, 184, 0.6);
      font-size: 0.85rem;
    }
    .lq-last-updated {
      font-size: 0.68rem;
      color: rgba(148,163,184,0.45);
      text-align: right;
      margin-top: 0.65rem;
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
            <div class="kpi-val" id="kpiActiveCount"><?= $activeCount ?></div>
            <div class="kpi-label">Active / Scheduled Visits</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          </div>
          <div>
            <div class="kpi-val" id="kpiCompletedCount"><?= $completedCount ?></div>
            <div class="kpi-label">Completed Consultations</div>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
            <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
          </div>
          <div>
            <div class="kpi-val" id="kpiCancelledCount"><?= $cancelledCount ?></div>
            <div class="kpi-label">Cancelled Visits</div>
          </div>
        </div>
      </div>

      <!-- Tab Filter Navigation -->
      <div class="tab-nav-row">
        <div class="tab-pills">
          <button type="button" class="tab-btn active" data-filter="all">
            All Records <span class="tab-badge" id="tabAllBadge"><?= $totalCount ?></span>
          </button>
          <button type="button" class="tab-btn" data-filter="active">
            Active &amp; Scheduled <span class="tab-badge" id="tabActiveBadge"><?= $activeCount ?></span>
          </button>
          <button type="button" class="tab-btn" data-filter="completed">
            Completed <span class="tab-badge" id="tabCompletedBadge"><?= $completedCount ?></span>
          </button>
          <button type="button" class="tab-btn" data-filter="cancelled">
            Cancelled <span class="tab-badge" id="tabCancelledBadge"><?= $cancelledCount ?></span>
          </button>
        </div>

        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">
          Strict 25-Patient Quality Policy Enforced
        </div>
      </div>

      <input type="hidden" id="pageCsrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

      <!-- Live Queue Status Panel (for today's active appointments) -->
      <?php
        $todayActiveAppts = array_filter($appointments, function($a) {
          $s = strtolower($a['status'] ?? '');
          $q = strtolower($a['queue_status'] ?? '');
          $isToday = ($a['appointment_date'] === date('Y-m-d'));
          $notDone = !in_array($s, ['cancelled', 'completed']) && !in_array($q, ['cancelled', 'completed']);
          return $isToday && $notDone;
        });
      ?>
      <?php if (!empty($todayActiveAppts)): ?>
      <div class="live-queue-panel" id="liveQueuePanel">
        <div class="lq-header">
          <span class="lq-live-dot"></span>
          <span class="lq-title">Today's Live Queue Status</span>
          <span class="lq-sub" id="lqLastUpdated">Syncing…</span>
        </div>
        <div class="lq-cards-grid" id="lqCardsGrid">
          <?php foreach ($todayActiveAppts as $ta): ?>
          <div class="lq-card" id="lq-card-<?= (int)$ta['id'] ?>" data-appointment-id="<?= (int)$ta['id'] ?>">
            <div class="lq-card-top">
              <span class="lq-doctor-name"><?= htmlspecialchars($ta['doctor_name'] ?? 'Doctor') ?></span>
              <span class="lq-shift-badge"><?= htmlspecialchars($ta['time_slot'] ?? 'Morning') ?> Shift</span>
            </div>
            <div class="lq-metrics-row">
              <div class="lq-metric serving">
                <span class="lq-metric-val" id="lq-serving-<?= (int)$ta['id'] ?>">—</span>
                <span class="lq-metric-label">Now Serving</span>
              </div>
              <div class="lq-divider"></div>
              <div class="lq-metric my-token">
                <span class="lq-metric-val">#<?= (int)$ta['token_number'] ?></span>
                <span class="lq-metric-label">My Token</span>
              </div>
              <div class="lq-divider"></div>
              <div class="lq-metric position">
                <span class="lq-metric-val" id="lq-pos-<?= (int)$ta['id'] ?>">—</span>
                <span class="lq-metric-label">Queue Pos.</span>
              </div>
              <div class="lq-divider"></div>
              <div class="lq-metric wait">
                <span class="lq-metric-val" id="lq-wait-<?= (int)$ta['id'] ?>">—</span>
                <span class="lq-metric-label">Est. Wait</span>
              </div>
            </div>
            <div class="lq-status-msg" id="lq-msg-<?= (int)$ta['id'] ?>">Loading queue status…</div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="lq-last-updated" id="lqTimestamp">Last synced: just now</div>
      </div>
      <?php endif; ?>

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
                <tr class="app-row" data-category="<?= $rowCategory ?>" data-appointment-id="<?= (int)$app['id'] ?>" data-is-today="<?= $isToday ? '1' : '0' ?>">
                  
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
                  <td data-role="status-badge">
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

                        <!-- Active Cancel Button (Modern UI Modal Trigger) -->
                        <button type="button" 
                                class="btn-cancel-app js-cancel-appointment-btn" 
                                data-appointment-id="<?= (int)$app['id'] ?>"
                                data-token-number="<?= (int)$app['token_number'] ?>"
                                data-doctor-id="<?= (int)$app['doctor_id'] ?>"
                                data-doctor-name="<?= htmlspecialchars($app['doctor_name'], ENT_QUOTES, 'UTF-8') ?>"
                                title="Cancel this consultation booking">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                          Cancel Appointment
                        </button>

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

  <!-- ── Tab Filter + Live Queue Sync Javascript ─────────────────────── -->
  <script>
    // ── Tab Filter ────────────────────────────────────────────────────────────
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

    // ── Live Queue Polling (3.5-second tick) ──────────────────────────────────
    (function initLiveQueueSync() {
      const panel = document.getElementById('liveQueuePanel');
      if (!panel) return; // No active today appointments — nothing to sync

      let _lqTimer = null;
      let _prevSnapshot = {}; // appointment_id -> { serving_token, queue_position }

      async function lqTick() {
        try {
          const res  = await fetch('api/opd_live_sync.php', { credentials: 'same-origin', cache: 'no-store' });
          if (!res.ok) return;
          const json = await res.json();
          if (json.status !== 'success') return;

          const appts = json.appointments || [];
          appts.forEach(appt => {
            const id   = appt.appointment_id;
            const prev = _prevSnapshot[id] || {};

            // Detect if anything changed for this appointment
            const changed = (
              prev.serving_token  !== appt.serving_token  ||
              prev.queue_position !== appt.queue_position ||
              prev.my_status      !== appt.my_status
            );

            _prevSnapshot[id] = {
              serving_token:  appt.serving_token,
              queue_position: appt.queue_position,
              my_status:      appt.my_status,
            };

            if (!changed) return; // Skip DOM update if nothing changed

            // ── Update LQ Panel Card ────────────────────────────────────────
            const card      = document.getElementById(`lq-card-${id}`);
            const srvEl     = document.getElementById(`lq-serving-${id}`);
            const posEl     = document.getElementById(`lq-pos-${id}`);
            const waitEl    = document.getElementById(`lq-wait-${id}`);
            const msgEl     = document.getElementById(`lq-msg-${id}`);

            if (srvEl) srvEl.textContent = appt.serving_token > 0 ? `#${appt.serving_token}` : '—';

            const s = (appt.my_status || '').toLowerCase();
            if (s === 'in_consultation') {
              if (posEl)  posEl.textContent  = '🔔';
              if (waitEl) waitEl.textContent  = '0m';
              if (msgEl)  { msgEl.textContent = '✔ Your turn! Please proceed to the chamber.'; msgEl.className = 'lq-status-msg calling'; }
              if (card)   card.classList.add('active-now');
            } else if (s === 'completed') {
              if (posEl)  posEl.textContent  = '✓';
              if (waitEl) waitEl.textContent  = 'Done';
              if (msgEl)  { msgEl.textContent = 'Consultation completed. Thank you!'; msgEl.className = 'lq-status-msg done'; }
              if (card)   card.classList.remove('active-now');
            } else {
              const pos  = appt.queue_position || '—';
              const wait = appt.estimated_wait_min > 0 ? `~${appt.estimated_wait_min}m` : '< 5m';
              if (posEl)  posEl.textContent  = typeof pos === 'number' ? `#${pos}` : pos;
              if (waitEl) waitEl.textContent  = wait;
              if (card)   card.classList.remove('active-now');

              let msg = '';
              const ahead = appt.patients_ahead ?? 0;
              if (ahead === 0) {
                msg = 'You are next in line. Please be ready near the chamber.';
              } else {
                msg = `${ahead} patient${ahead !== 1 ? 's' : ''} ahead of you. Estimated wait: ${wait}.`;
              }
              if (msgEl) { msgEl.textContent = msg; msgEl.className = 'lq-status-msg'; }
            }

            // ── Also update the status badge in the main table row ──────────
            const tableRow = document.querySelector(`.app-row[data-appointment-id="${id}"]`);
            if (tableRow) {
              const statusCell = tableRow.querySelector('[data-role="status-badge"]');
              if (statusCell) {
                const ns = (appt.my_status || '').toLowerCase();
                if (ns === 'in_consultation') {
                  statusCell.innerHTML = `<span class="badge-status badge-serving"><span style="width:7px;height:7px;border-radius:50%;background:var(--brand-teal);"></span>Serving in Chamber</span>`;
                } else if (ns === 'completed') {
                  statusCell.innerHTML = `<span class="badge-status badge-completed"><svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>Completed</span>`;
                }
              }
            }
          });

          // Update timestamp
          const tsEl = document.getElementById('lqTimestamp');
          if (tsEl) {
            const now = new Date();
            tsEl.textContent = `Last synced: ${now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;
          }
          const subEl = document.getElementById('lqLastUpdated');
          if (subEl) subEl.textContent = 'Live ● syncing every 3.5s';

        } catch (e) {
          // Silent fail
        }
      }

      // Run immediately then start interval
      lqTick();
      _lqTimer = setInterval(lqTick, 3500);

      // Memory leak prevention
      window.addEventListener('pagehide',      () => { if (_lqTimer) clearInterval(_lqTimer); });
      window.addEventListener('beforeunload',  () => { if (_lqTimer) clearInterval(_lqTimer); });
    })();
  </script>

  <!-- MedPulse Dialog & Async Cancellation Engine -->
  <script src="../assets/js/medpulse_dialog.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.addEventListener('click', async function(e) {
        const btn = e.target.closest('.js-cancel-appointment-btn');
        if (!btn) return;

        const apptId = btn.getAttribute('data-appointment-id');
        const tokenNo = btn.getAttribute('data-token-number');
        const doctorId = btn.getAttribute('data-doctor-id');
        const doctorName = btn.getAttribute('data-doctor-name') || 'Specialist';
        const csrfToken = document.getElementById('pageCsrfToken')?.value || '';

        // Trigger custom UI modal matching MedPulse design system
        const confirmed = await MedPulseDialog.confirm({
          title: 'Cancel Scheduled Consultation',
          message: `Are you sure you want to cancel your appointment (Token #${tokenNo})? This slot will be released back to the queue.`,
          subtitle: `Dr. ${doctorName} • Queue Token #${tokenNo}`,
          type: 'danger',
          cancelText: 'Keep Appointment',
          confirmText: 'Confirm Cancellation'
        });

        if (!confirmed) return;

        // Loading state
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.style.opacity = '0.7';
        btn.innerHTML = `<span class="mp-spinner" style="width:12px;height:12px;border-width:1.8px;"></span> <span>Cancelling…</span>`;

        try {
          const formData = new FormData();
          formData.append('action', 'cancel_appointment');
          formData.append('appointment_id', apptId);
          formData.append('csrf_token', csrfToken);
          formData.append('format', 'json');

          const resp = await fetch('appointments.php', {
            method: 'POST',
            body: formData,
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json'
            }
          });

          const data = await resp.json();

          if (data && data.success) {
            // 1. In-app toast notification matching MedPulse design system
            MedPulseDialog.toast({
              title: 'Consultation Cancelled',
              message: data.message || `Appointment (Token #${tokenNo}) cancelled successfully.`,
              type: 'warning',
              duration: 4500
            });

            // 2. Dynamically update main table row without page reload
            const row = document.querySelector(`.app-row[data-appointment-id="${apptId}"]`);
            if (row) {
              row.setAttribute('data-category', 'cancelled');

              // Update status badge
              const statusCell = row.querySelector('[data-role="status-badge"]');
              if (statusCell) {
                statusCell.innerHTML = `
                  <span class="badge-status badge-cancelled">
                    <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                    Cancelled
                  </span>
                `;
              }

              // Update action cell to "Re-Book Doctor" button
              const actionsCell = row.querySelector('.actions-cell');
              if (actionsCell) {
                actionsCell.innerHTML = `
                  <a href="specialists.php?book_doctor_id=${encodeURIComponent(doctorId)}" class="btn-rebook">
                    <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Re-Book Doctor
                  </a>
                `;
              }

              // If tab filter is currently active, hide the row
              const activeTab = document.querySelector('.tab-btn.active');
              if (activeTab && activeTab.getAttribute('data-filter') === 'active') {
                row.style.display = 'none';
              }
            }

            // 3. Remove / fade out today's live queue card if present
            const lqCard = document.getElementById(`lq-card-${apptId}`);
            if (lqCard) {
              lqCard.style.transition = 'all 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
              lqCard.style.opacity = '0';
              lqCard.style.transform = 'scale(0.95)';
              setTimeout(() => {
                lqCard.remove();
                const remainingLqCards = document.querySelectorAll('.lq-card');
                if (remainingLqCards.length === 0) {
                  const lqPanel = document.getElementById('liveQueuePanel');
                  if (lqPanel) lqPanel.style.display = 'none';
                }
              }, 300);
            }

            // 4. Update KPI stat count cards dynamically
            const kpiActive = document.getElementById('kpiActiveCount');
            if (kpiActive) {
              kpiActive.textContent = Math.max(0, parseInt(kpiActive.textContent || '0', 10) - 1);
            }
            const kpiCancelled = document.getElementById('kpiCancelledCount');
            if (kpiCancelled) {
              kpiCancelled.textContent = parseInt(kpiCancelled.textContent || '0', 10) + 1;
            }

            // 5. Update Tab Badge counters dynamically
            const tabActive = document.getElementById('tabActiveBadge');
            if (tabActive) {
              tabActive.textContent = Math.max(0, parseInt(tabActive.textContent || '0', 10) - 1);
            }
            const tabCancelled = document.getElementById('tabCancelledBadge');
            if (tabCancelled) {
              tabCancelled.textContent = parseInt(tabCancelled.textContent || '0', 10) + 1;
            }

          } else {
            MedPulseDialog.toast({
              title: 'Cancellation Notice',
              message: (data && data.message) ? data.message : 'Unable to cancel appointment.',
              type: 'error',
              duration: 4500
            });
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerHTML = origHtml;
          }
        } catch (err) {
          console.error('Cancellation error:', err);
          MedPulseDialog.toast({
            title: 'Connection Error',
            message: 'Network error connecting to appointment service.',
            type: 'error',
            duration: 4500
          });
          btn.disabled = false;
          btn.style.opacity = '1';
          btn.innerHTML = origHtml;
        }
      });
    });
  </script>
</body>
</html>

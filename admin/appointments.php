<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Branch OPD Appointments & Real-Time Patient Queue Console
 *
 * Scoped strictly to authenticated facility (:session_hospital_id).
 * Rejects cross-tenant updates with HTTP 403.
 */

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/tenant_scope.php';

// Session hospital resolution & strict tenant binding
$sessionHospitalId = TenantScope::enforce($pdo, ['admin', 'hospital_admin', 'super_admin']);

// Fetch branch hospital information
$branchStmt = $pdo->prepare("SELECT hospital_id, name, code, address, city, phone FROM hospitals WHERE hospital_id = ?");
$branchStmt->execute([$sessionHospitalId]);
$currentHospital = $branchStmt->fetch(PDO::FETCH_ASSOC);
$branchName = $currentHospital['name'] ?? "Branch Hospital #{$sessionHospitalId}";

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

    $appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = trim($_POST['new_status'] ?? '');
    $allowedStatuses = ['booked', 'checked_in', 'in_consultation', 'completed', 'cancelled'];

    if ($appointmentId && in_array($newStatus, $allowedStatuses, true)) {
        // Strict Tenant Isolation Guard: Verify appointment belongs strictly to this hospital
        $appCheck = $pdo->prepare("SELECT hospital_id, token_number FROM appointments WHERE id = ? OR appointment_id = ? LIMIT 1");
        $appCheck->execute([$appointmentId, $appointmentId]);
        $targetApp = $appCheck->fetch(PDO::FETCH_ASSOC);

        if (!$targetApp || (!TenantScope::isSuperAdmin() && (int)$targetApp['hospital_id'] !== $sessionHospitalId)) {
            http_response_code(403);
            die('403 Forbidden: Tenant violation. Appointment belongs to another hospital facility.');
        }

        $updStmt = $pdo->prepare("UPDATE appointments SET status = :st WHERE id = :id OR appointment_id = :id2");
        $updStmt->execute([':st' => $newStatus, ':id' => $appointmentId, ':id2' => $appointmentId]);

        $feedback = "Appointment Token #{$targetApp['token_number']} status transitioned to '" . strtoupper($newStatus) . "'.";
        $feedbackType = 'success';
    }
}

// ── FILTER PARAMETERS ───────────────────────────────────────────────────────
$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
$selectedDocId = filter_var($_GET['doctor_id'] ?? null, FILTER_VALIDATE_INT);
$selectedStatus = trim($_GET['status'] ?? '');

// ── REQUIREMENT 2: OPD QUEUE & APPOINTMENTS QUERY ──────────────────────────
// Constrain appointment queries strictly by the authenticated facility:
$sqlQueue = "
    SELECT a.*, p.patient_uid, u.full_name AS patient_name, u.phone, d_user.full_name AS doctor_name
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    JOIN users u ON u.id = p.user_id
    JOIN doctors d ON d.id = a.doctor_id
    JOIN users d_user ON d_user.id = d.user_id
    WHERE a.hospital_id = :session_hospital_id
";

$params = [':session_hospital_id' => $sessionHospitalId];

if ($selectedDate !== '') {
    $sqlQueue .= " AND a.appointment_date = :app_date";
    $params[':app_date'] = $selectedDate;
}

if ($selectedDocId) {
    $sqlQueue .= " AND (a.doctor_id = :doc_id OR d.user_id = :doc_id2)";
    $params[':doc_id'] = $selectedDocId;
    $params[':doc_id2'] = $selectedDocId;
}

if ($selectedStatus !== '') {
    $sqlQueue .= " AND a.status = :st";
    $params[':st'] = $selectedStatus;
}

$sqlQueue .= " ORDER BY a.token_number ASC";

$queueStmt = $pdo->prepare($sqlQueue);
$queueStmt->execute($params);
$appointments = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch doctors of this branch for filter dropdown
$docListStmt = $pdo->prepare("
    SELECT d.id, d.doctor_id, u.full_name, COALESCE(d.bmdc_reg_no, 'BMDC') AS bmdc
    FROM doctors d
    JOIN users u ON u.id = d.user_id
    WHERE d.hospital_id = :session_hospital_id
    ORDER BY u.full_name ASC
");
$docListStmt->execute([':session_hospital_id' => $sessionHospitalId]);
$branchDoctors = $docListStmt->fetchAll(PDO::FETCH_ASSOC);

// Today's summary telemetry
$todayCountStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_today,
        SUM(status = 'checked_in') AS checked_in_count,
        SUM(status = 'in_consultation') AS in_consult_count,
        SUM(status = 'completed') AS completed_count
    FROM appointments
    WHERE hospital_id = :session_hospital_id AND appointment_date = CURRENT_DATE
");
$todayCountStmt->execute([':session_hospital_id' => $sessionHospitalId]);
$telemetry = $todayCountStmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Branch OPD Appointments &amp; Queue Tracker</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    .branch-identity-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(2, 132, 199, 0.12);
      border: 1px solid rgba(2, 132, 199, 0.3);
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 0.85rem;
      font-weight: 700;
      color: #38bdf8;
      margin-bottom: 12px;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 8px #10b981;
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
    .filter-control {
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      color: var(--text-heading);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.9rem;
      outline: none;
    }
    .token-badge {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 800;
      font-size: 1.15rem;
      color: var(--brand-primary);
      background: rgba(2, 132, 199, 0.12);
      border: 1px solid rgba(2, 132, 199, 0.25);
      border-radius: 8px;
      padding: 4px 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 44px;
    }
    .status-pill {
      font-size: 0.76rem;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 9999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .status-pill.booked {
      background: rgba(2, 132, 199, 0.15);
      color: #38bdf8;
      border: 1px solid rgba(2, 132, 199, 0.3);
    }
    .status-pill.checked_in {
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }
    .status-pill.in_consultation {
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.3);
      animation: pulse-border 2s infinite;
    }
    .status-pill.completed {
      background: rgba(148, 163, 184, 0.15);
      color: #94a3b8;
      border: 1px solid rgba(148, 163, 184, 0.3);
    }
    .status-pill.cancelled {
      background: rgba(239, 68, 68, 0.15);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }
    @keyframes pulse-border {
      0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
      70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
      100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .btn-action-sm {
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      color: var(--text-heading);
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 0.78rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: all 0.2s;
    }
    .btn-action-sm:hover {
      background: var(--brand-primary);
      color: #ffffff;
      border-color: var(--brand-primary);
    }
  </style>
</head>
<body>

  <!-- Centralized Admin Sidebar -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Main Viewport Past Sidebar -->
  <main class="viewport-full">

    <?php if ($feedback): ?>
      <div style="background: <?= $feedbackType === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#10b981' : '#ef4444' ?>; color: <?= $feedbackType === 'success' ? '#34d399' : '#f87171' ?>; padding: 12px 18px; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
        <?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Facility Header -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="branch-identity-badge">
          <span class="badge-dot"></span>
          Facility Scope: <?= htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8') ?> (ID: #<?= (int)$sessionHospitalId ?>)
        </div>
        <h1>
          Branch OPD Appointments &amp; Queue Tracker
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        </h1>
        <p>Outpatient department consultations, sequential token queues (capped at 25/shift), and live chamber reception strictly scoped to this facility.</p>
      </div>
      <div class="banner-actions">
        <a href="beds.php" class="btn-action-telemed">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path></svg>
          Bed &amp; Admission Controls
        </a>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(2, 132, 199, 0.15); color: #0284c7;">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Today's Total OPD</div>
          <div class="stat-value"><?= (int)($telemetry['total_today'] ?? 0) ?></div>
          <div class="stat-subtext" style="color: var(--text-muted);"><?= date('l, d F Y') ?></div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
          <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Checked-In Waiting</div>
          <div class="stat-value" style="color: #f59e0b;"><?= (int)($telemetry['checked_in_count'] ?? 0) ?></div>
          <div class="stat-subtext" style="color: #f59e0b;">Present in waiting lounge</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">In Active Consultation</div>
          <div class="stat-value" style="color: #10b981;"><?= (int)($telemetry['in_consult_count'] ?? 0) ?></div>
          <div class="stat-subtext" style="color: #10b981;">Inside physician chambers</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(148, 163, 184, 0.15); color: #94a3b8;">
          <svg class="ui-ico" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Completed Consults</div>
          <div class="stat-value"><?= (int)($telemetry['completed_count'] ?? 0) ?></div>
          <div class="stat-subtext" style="color: var(--text-muted);">Prescriptions dispensed</div>
        </div>
      </div>
    </div>

    <!-- Filters Bar -->
    <form method="GET" class="filter-bar">
      <label style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Queue Date:</label>
      <input type="date" name="date" class="filter-control" value="<?= htmlspecialchars($selectedDate) ?>" onchange="this.form.submit()">

      <label style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-left: 12px;">Physician:</label>
      <select name="doctor_id" class="filter-control" onchange="this.form.submit()">
        <option value="">All Branch Doctors</option>
        <?php foreach ($branchDoctors as $doc): ?>
          <option value="<?= (int)$doc['id'] ?>" <?= $selectedDocId === (int)$doc['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($doc['full_name']) ?> (BMDC: <?= htmlspecialchars($doc['bmdc']) ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <label style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-left: 12px;">Status:</label>
      <select name="status" class="filter-control" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="booked" <?= $selectedStatus === 'booked' ? 'selected' : '' ?>>Booked</option>
        <option value="checked_in" <?= $selectedStatus === 'checked_in' ? 'selected' : '' ?>>Checked In</option>
        <option value="in_consultation" <?= $selectedStatus === 'in_consultation' ? 'selected' : '' ?>>In Consultation</option>
        <option value="completed" <?= $selectedStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="cancelled" <?= $selectedStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      </select>

      <?php if ($selectedDate !== date('Y-m-d') || $selectedDocId || $selectedStatus !== ''): ?>
        <a href="appointments.php" style="font-size: 0.82rem; color: #38bdf8; text-decoration: none; margin-left: auto;">Reset to Today</a>
      <?php endif; ?>
    </form>

    <!-- Queue & Appointments Table -->
    <section class="admin-table-wrap">
      <table class="admin-data-table">
        <thead>
          <tr>
            <th style="width: 80px;">Token</th>
            <th>Patient Details</th>
            <th>Attending Doctor</th>
            <th>Slot / Schedule</th>
            <th>Status</th>
            <th style="text-align: right;">Reception Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($appointments)): ?>
            <tr>
              <td colspan="6" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                <svg class="ui-ico" style="width: 48px; height: 48px; margin-bottom: 12px; stroke: #64748b;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <div style="font-weight: 600; color: var(--text-heading); font-size: 1rem;">No Appointments Found</div>
                <div style="font-size: 0.85rem;">No consultations scheduled matching the specified facility parameters.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($appointments as $app): ?>
              <?php 
                $appId = (int)($app['id'] ?? $app['appointment_id']);
                $tokenNum = (int)($app['token_number'] ?? $app['serial_number'] ?? 1);
                $st = strtolower($app['status'] ?? 'booked');
              ?>
              <tr>
                <td>
                  <span class="token-badge">#<?= $tokenNum ?></span>
                </td>
                <td>
                  <div>
                    <strong style="color: var(--text-heading); font-size: 0.92rem;">
                      <?= htmlspecialchars($app['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.76rem; color: var(--text-muted); font-family: monospace;">
                      UID: <?= htmlspecialchars($app['patient_uid'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($app['phone'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </div>
                </td>
                <td>
                  <strong style="color: var(--brand-primary); font-size: 0.9rem;">
                    <?= htmlspecialchars($app['doctor_name'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                  <div style="font-size: 0.76rem; color: var(--text-muted);">
                    <?= htmlspecialchars($app['reason_for_visit'] ?? 'Clinical Consultation', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </td>
                <td>
                  <div style="font-weight: 600; color: var(--text-heading); font-size: 0.88rem;">
                    <?= htmlspecialchars($app['time_slot'] ?? 'Regular Shift', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size: 0.76rem; color: var(--text-muted);">
                    <?= htmlspecialchars(date('d M Y', strtotime($app['appointment_date'])), ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </td>
                <td>
                  <span class="status-pill <?= $st ?>">
                    <?= str_replace('_', ' ', $st) ?>
                  </span>
                </td>
                <td>
                  <div class="table-actions-flex" style="justify-content: flex-end; gap: 6px;">
                    <?php if ($st === 'booked'): ?>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="appointment_id" value="<?= $appId ?>">
                        <input type="hidden" name="new_status" value="checked_in">
                        <button type="submit" class="btn-action-sm" title="Check-in patient to waiting room">
                          Check In
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($st === 'checked_in'): ?>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="appointment_id" value="<?= $appId ?>">
                        <input type="hidden" name="new_status" value="in_consultation">
                        <button type="submit" class="btn-action-sm" style="color: #10b981; border-color: rgba(16, 185, 129, 0.4);" title="Call into physician chamber">
                          Call Chamber
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($st === 'in_consultation'): ?>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="appointment_id" value="<?= $appId ?>">
                        <input type="hidden" name="new_status" value="completed">
                        <button type="submit" class="btn-action-sm" style="color: #38bdf8; border-color: rgba(56, 189, 248, 0.4);" title="Complete consultation">
                          Complete
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if (in_array($st, ['booked', 'checked_in'], true)): ?>
                      <form method="POST" style="display: inline;" onsubmit="return confirm('Cancel this appointment?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="appointment_id" value="<?= $appId ?>">
                        <input type="hidden" name="new_status" value="cancelled">
                        <button type="submit" class="btn-action-sm" style="color: #ef4444; border-color: rgba(239, 68, 68, 0.3);" title="Cancel appointment">
                          Cancel
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

  </main>
</body>
</html>

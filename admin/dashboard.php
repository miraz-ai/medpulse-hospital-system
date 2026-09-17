<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Executive Command Center & Role Control
 */

require_once __DIR__ . '/../includes/admin_auth.php';

try {
    // 1. Pending Approvals Queue (Doctors & Staff awaiting administrative review)
    $pendingStmt = $pdo->prepare("
        SELECT user_id, full_name, email, phone, gender, role, status, license_id, department, created_at 
        FROM users 
        WHERE status = 'pending' AND role IN ('Doctor', 'Staff') 
        ORDER BY created_at DESC
    ");
    $pendingStmt->execute();
    $pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = count($pendingUsers);

    // 2. High-Level Vital Metrics
    $patientCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Patient'")->fetchColumn();
    $activeDoctorsCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Doctor' AND status = 'active'")->fetchColumn();
    $activeStaffCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Staff' AND status = 'active'")->fetchColumn();

} catch (PDOException $e) {
    error_log("Admin Dashboard DB error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact system engineering.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Executive Command Center</title>
  
  <!-- Hospital Favicon (100% Parity with patient_dashboard.php) -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/live-ticker.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/live-pulse.css?v=<?= time() ?>">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial (Dynamic Active Route Highlighting) -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container (Starts cleanly past sidebar) -->
  <main class="viewport-full">

    <!-- Executive Welcome Banner (Fluid flex-wrap, zero text clipping) -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>!
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p>Enterprise Operations Console: Real-time clinical telemetry, personnel security, and role-based registries are synchronized.</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Security audit telemetry is operating in strict mode.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          Audit Telemetry
        </button>
        <button class="btn-action-gradient" onclick="window.location.href='index.php'">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Register Staff
        </button>
      </div>
    </div>

    <!-- Live Event Telemetry Ticker -->
    <div class="telemetry-ticker-bar" id="telemetryTicker" aria-label="Live event telemetry ticker">
      <div class="ticker-indicator">
        <div class="ticker-pulse-wrapper">
          <span class="ticker-pulse-ring"></span>
          <span class="ticker-pulse-dot"></span>
        </div>
        <span class="indicator-label">Live Telemetry</span>
      </div>

      <div class="ticker-viewport">
        <div class="ticker-track" id="tickerTrack">
          <div class="ticker-item">
            <span class="ticker-cat-badge cat-admission">Admission</span>
            <span class="ticker-text">Patient registered to Emergency Ward 3B</span>
            <span class="ticker-separator">&bull;</span>
            <span class="ticker-time">Just now</span>
          </div>
          <div class="ticker-item">
            <span class="ticker-cat-badge cat-verification">Verification</span>
            <span class="ticker-text">Dr. Ayesha Siddiqua credentials approved</span>
            <span class="ticker-separator">&bull;</span>
            <span class="ticker-time">2m ago</span>
          </div>
          <div class="ticker-item">
            <span class="ticker-cat-badge cat-pharmacy">Pharmacy</span>
            <span class="ticker-text">Medication batch #409 released</span>
            <span class="ticker-separator">&bull;</span>
            <span class="ticker-time">5m ago</span>
          </div>
          <div class="ticker-item">
            <span class="ticker-cat-badge cat-census">Census</span>
            <span class="ticker-text">Bed #14 sanitized and ready for allocation</span>
            <span class="ticker-separator">&bull;</span>
            <span class="ticker-time">8m ago</span>
          </div>
        </div>
      </div>

      <a href="audit_logs.php" class="ticker-audit-link" title="View dedicated audit logs">
        <span>View All Logs</span>
        <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
      </a>
    </div>

    <!-- Executive 4-Metric Vital Stats Cards -->
    <div class="stat-cards-grid">
      <!-- Metric 1: Total Registered Patients -->
      <a href="manage_patients.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Patient Registry</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= number_format($patientCount) ?></div>
        <div class="stat-card-badge badge-blue">
          <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          View Full Registry &rarr;
        </div>
      </a>

      <!-- Metric 2: Verified Medical Doctors -->
      <a href="manage_doctors.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Medical Doctors</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
        </div>
        <div class="stat-card-number" id="kpiActiveDoctorsCount"><?= number_format($activeDoctorsCount) ?></div>
        <div class="stat-card-badge badge-green">
          <span>BMDC Licensed &rarr;</span>
        </div>
      </a>

      <!-- Metric 3: Active Clinical Staff -->
      <a href="manage_staff.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Clinical Staff</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
        </div>
        <div class="stat-card-number" id="kpiActiveStaffCount"><?= number_format($activeStaffCount) ?></div>
        <div class="stat-card-badge badge-green">
          <span>Operations Active &rarr;</span>
        </div>
      </a>

      <!-- Metric 4: Pending Verification Queue -->
      <a href="#pendingApprovalSection" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Pending Verification</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number" id="kpiPendingCount"><?= str_pad((string)$pendingCount, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="stat-card-badge <?= $pendingCount > 0 ? 'badge-amber' : 'badge-green' ?>" id="kpiPendingBadge">
          <span><?= $pendingCount > 0 ? 'Review Required' : 'All Clear' ?></span>
        </div>
      </a>
    </div>

    <!-- PRIORITY SECTION: Pending Credential Approvals Queue (Top Priority Action Area) -->
    <section class="admin-stack-card" id="pendingApprovalSection">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--status-amber); width: 22px; height: 22px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Pending Credential Approvals Queue
          </h3>
          <p>Candidate doctor & staff accounts awaiting mandatory administrative authorization before granting portal access</p>
        </div>
        <div>
          <span class="status-badge-pending" id="pendingQueueBadge">
            <?= $pendingCount ?> PENDING APPLICANTS
          </span>
        </div>
      </div>

      <div class="admin-table-wrap" id="pendingTableWrap" style="<?= $pendingCount === 0 ? 'display: none;' : '' ?>">
        <table class="admin-data-table" id="pendingTable">
          <thead>
            <tr>
              <th>Personnel Candidate</th>
              <th>Role Requested</th>
              <th>Official Email</th>
              <th>Phone Number</th>
              <th>License / Staff ID</th>
              <th>Registered Date</th>
              <th style="text-align: right;">Review Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingUsers as $user): ?>
              <tr id="row-user-<?= (int)$user['user_id'] ?>">
                <td>
                  <div class="user-cell-flex">
                    <div class="user-avatar-initials">
                      <?= htmlspecialchars(strtoupper(substr($user['full_name'], 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div>
                      <strong style="color: var(--text-heading); font-size: 0.92rem;">
                        <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>
                      </strong>
                      <div style="font-size: 0.72rem; color: var(--text-muted);">
                        Gender: <?= htmlspecialchars($user['gender'], ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if ($user['role'] === 'Doctor'): ?>
                    <span class="role-pill role-pill-doctor">
                      <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                      Doctor
                    </span>
                  <?php else: ?>
                    <span class="role-pill role-pill-staff">
                      <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
                      Staff
                    </span>
                  <?php endif; ?>
                </td>
                <td style="font-weight: 600; color: var(--text-heading);">
                  <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <span class="license-chip">
                    <?= htmlspecialchars($user['license_id'] ?? 'VERIFY-PENDING', ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="font-size: 0.78rem; color: var(--text-muted);">
                  <?= htmlspecialchars(date('d M Y, h:i A', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td>
                  <div class="table-actions-flex" style="justify-content: flex-end;">
                    <button class="btn-table-action btn-table-approve" 
                            onclick="executeAdminAction(<?= (int)$user['user_id'] ?>, 'approve', this)"
                            title="Approve candidate and grant portal access">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      Approve
                    </button>
                    <button class="btn-table-action btn-table-reject" 
                            onclick="executeAdminAction(<?= (int)$user['user_id'] ?>, 'reject', this)"
                            title="Decline candidate application">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                      Decline
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Empty State when Queue is Cleared -->
      <div class="empty-state-card" id="pendingEmptyCard" style="<?= $pendingCount > 0 ? 'display: none;' : '' ?>">
        <svg class="ui-ico" style="width: 48px; height: 48px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
        <h4>All Personnel Credentials Verified</h4>
        <p>The verification queue is clean. New doctor and staff registration requests will appear here for administrative sign-off.</p>
      </div>
    </section>

    <!-- LIVE HOSPITAL PULSE & FAST ACCESS STREAM -->
    <div class="bed-availability-panel">
      <div class="panel-header-flex">
        <div>
          <h3 class="panel-heading">Live Hospital Pulse & Departmental Hubs</h3>
          <p class="panel-subtext">Instant overview of key clinical departments and census telemetry</p>
        </div>
        <div class="panel-status-group">
          <!-- Live ECG / Pulse Wave Monitor -->
          <div class="ecg-pulse-monitor" title="Real-time cardiac telemetry monitor">
            <svg class="ecg-wave-svg" viewBox="0 0 60 18" width="60" height="18" aria-hidden="true">
              <path class="ecg-wave-bg" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
              <path class="ecg-wave-active" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
            </svg>
            <span class="ecg-label"><span class="ecg-bpm-dot"></span>72 BPM &bull; TELEMETRY ACTIVE</span>
          </div>

          <div class="live-status-pill">
            <div class="radar-pulse-dot"></div>
            SYSTEM NORMAL
          </div>
        </div>
      </div>

      <div class="bed-types-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
        <!-- Hub 1: Doctors -->
        <a href="manage_doctors.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Doctors Roster</span>
            <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          </div>
          <div class="bed-qty"><?= number_format($activeDoctorsCount) ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Active On Duty</small></div>
          <div style="font-size: 0.78rem; color: var(--brand-primary); font-weight: 600; margin-top: 6px;">Manage Physicians &rarr;</div>
        </a>

        <!-- Hub 2: Staff -->
        <a href="manage_staff.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Clinical Staff</span>
            <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          </div>
          <div class="bed-qty"><?= number_format($activeStaffCount) ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Assigned Personnel</small></div>
          <div style="font-size: 0.78rem; color: var(--brand-teal); font-weight: 600; margin-top: 6px;">Manage Staff &rarr;</div>
        </a>

        <!-- Hub 3: Patients -->
        <a href="manage_patients.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Patients Master</span>
            <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
          </div>
          <div class="bed-qty"><?= number_format($patientCount) ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Enrolled Profiles</small></div>
          <div style="font-size: 0.78rem; color: var(--status-green); font-weight: 600; margin-top: 6px;">Browse Patient Records &rarr;</div>
        </a>

        <!-- Hub 4: Bed Census -->
        <a href="live_census.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Bed Census</span>
            <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          </div>
          <div class="bed-qty">45 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 67 Available</small></div>
          <div style="font-size: 0.78rem; color: var(--status-amber); font-weight: 600; margin-top: 6px;">Live Bed Telemetry &rarr;</div>
        </a>
      </div>
    </div>

  </main>

  <!-- Real-time Status Action Script with CSRF Validation -->
  <script>
    async function executeAdminAction(userId, action, buttonEl) {
      const row = document.getElementById(`row-user-${userId}`);
      const originalHtml = buttonEl.innerHTML;
      buttonEl.disabled = true;
      buttonEl.innerHTML = '<span style="font-size: 0.72rem;">Updating...</span>';

      const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

      try {
        const formData = new FormData();
        formData.append('user_id', userId);
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);

        const response = await fetch('../backend/admin_actions.php', {
          method: 'POST',
          body: formData
        });
        const result = await response.json();

        if (result.status === 'success') {
          showToast(result.message, 'success');

          if (action === 'approve' || action === 'reject') {
            if (row) {
              row.style.transition = 'all 0.35s ease';
              row.style.opacity = '0';
              row.style.transform = 'translateX(20px)';
              setTimeout(() => {
                row.remove();
                syncPendingCounters();
              }, 350);
            }
          }
        } else {
          showToast(result.message || 'Operation failed. Please retry.', 'error');
          buttonEl.disabled = false;
          buttonEl.innerHTML = originalHtml;
        }
      } catch (err) {
        showToast('Server communication failure. Check connection.', 'error');
        buttonEl.disabled = false;
        buttonEl.innerHTML = originalHtml;
      }
    }

    function syncPendingCounters() {
      const tbody = document.querySelector('#pendingTable tbody');
      const rows = tbody ? tbody.querySelectorAll('tr') : [];
      const remaining = rows.length;

      const headerBadge = document.getElementById('pendingQueueBadge');
      if (headerBadge) {
        headerBadge.innerText = `${remaining} PENDING APPLICANTS`;
      }

      const sidebarBadge = document.getElementById('sidebarPendingBadge');
      if (sidebarBadge) {
        if (remaining > 0) {
          sidebarBadge.innerText = `${remaining} NEW`;
        } else {
          sidebarBadge.style.display = 'none';
        }
      }

      const kpiNumber = document.getElementById('kpiPendingCount');
      if (kpiNumber) {
        kpiNumber.innerText = String(remaining).padStart(2, '0');
      }

      const kpiBadge = document.getElementById('kpiPendingBadge');
      if (kpiBadge) {
        if (remaining === 0) {
          kpiBadge.className = 'stat-card-badge badge-green';
          kpiBadge.innerHTML = '<span>All Clear</span>';
        } else {
          kpiBadge.className = 'stat-card-badge badge-amber';
          kpiBadge.innerHTML = '<span>Review Required</span>';
        }
      }

      if (remaining === 0) {
        const tableWrap = document.getElementById('pendingTableWrap');
        const emptyCard = document.getElementById('pendingEmptyCard');
        if (tableWrap) tableWrap.style.display = 'none';
        if (emptyCard) emptyCard.style.display = 'block';
      }
    }
  </script>

  <script src="../assets/js/live-ticker.js?v=<?= time() ?>"></script>
</body>
</html>

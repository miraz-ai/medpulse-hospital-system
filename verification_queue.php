<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Candidate Verification & Credential Approval Queue
 */

require_once __DIR__ . '/includes/admin_auth.php';

try {
    // Query pending applicants (Doctors & Staff)
    $pendingStmt = $pdo->prepare("
        SELECT user_id, full_name, email, phone, gender, role, status, license_id, department, created_at 
        FROM users 
        WHERE status = 'pending' AND role IN ('Doctor', 'Staff') 
        ORDER BY created_at DESC
    ");
    $pendingStmt->execute();
    $pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = count($pendingUsers);

    $pendingDoctors = count(array_filter($pendingUsers, fn($u) => $u['role'] === 'Doctor'));
    $pendingStaff   = count(array_filter($pendingUsers, fn($u) => $u['role'] === 'Staff'));

} catch (PDOException $e) {
    error_log("Verification Queue DB error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact system engineering.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Credential Verification Queue</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="assets/css/patient_dashboard.css">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial (Dynamic Active Route Highlighting) -->
  <?php require_once __DIR__ . '/includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container (Starts cleanly past sidebar) -->
  <main class="viewport-full">

    <!-- Page Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          Candidate Verification Queue
          <svg class="ui-ico" style="stroke: var(--status-amber); width: 24px; height: 24px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </h1>
        <p>Administrative review checkpoint: Validate medical credentials, BMDC licensing validity, and grant authorized clinical access</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Credential verification policy is enforcing strict mode.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          Security Policy
        </button>
        <button class="btn-action-gradient" onclick="window.location.reload()">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Refresh Queue
        </button>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Pending Applicants</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number" id="kpiPendingCount"><?= str_pad((string)$pendingCount, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="stat-card-badge <?= $pendingCount > 0 ? 'badge-amber' : 'badge-green' ?>" id="kpiPendingBadge">
          <?= $pendingCount > 0 ? 'Review Required' : 'All Clear' ?>
        </div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Candidate Doctors</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        </div>
        <div class="stat-card-number"><?= number_format($pendingDoctors) ?></div>
        <div class="stat-card-badge badge-blue">BMDC Verification</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Candidate Staff</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
        </div>
        <div class="stat-card-number"><?= number_format($pendingStaff) ?></div>
        <div class="stat-card-badge badge-green">Operations Verification</div>
      </div>
    </div>

    <!-- Candidate Verification Table Card -->
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

        const response = await fetch('backend/admin_actions.php', {
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

</body>
</html>

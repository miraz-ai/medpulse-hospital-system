<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Clinical & Administrative Staff Console
 */

require_once __DIR__ . '/includes/admin_auth.php';

try {
    // Fetch active and suspended staff
    $staffStmt = $pdo->prepare("
        SELECT user_id, full_name, email, phone, gender, role, status, license_id, department, created_at 
        FROM users 
        WHERE role = 'Staff' AND status IN ('active', 'suspended') 
        ORDER BY status ASC, full_name ASC
    ");
    $staffStmt->execute();
    $staffRoster = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStaff     = count($staffRoster);
    $activeStaff    = count(array_filter($staffRoster, fn($s) => $s['status'] === 'active'));
    $suspendedStaff = count(array_filter($staffRoster, fn($s) => $s['status'] === 'suspended'));

} catch (PDOException $e) {
    error_log("Manage Staff DB error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact system engineering.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Clinical & Administrative Staff</title>
  
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
          Clinical & Administrative Staff
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
        </h1>
        <p>Manage hospital nurses, pharmacists, lab technicians, central ward coordinators, and operational personnel</p>
      </div>
      <div class="banner-actions">
        <a href="admin_dashboard.php#pendingApprovalSection" class="btn-action-telemed" style="text-decoration: none;">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Verification Queue
        </a>
        <button class="btn-action-gradient" onclick="window.location.href='index.php'">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          + Add Staff
        </button>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Total Staff Members</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
        </div>
        <div class="stat-card-number"><?= number_format($totalStaff) ?></div>
        <div class="stat-card-badge badge-blue">Clinical & Admin</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Active Operations</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= number_format($activeStaff) ?></div>
        <div class="stat-card-badge badge-green">Operational Duty</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Suspended Personnel</span>
          <svg class="ui-ico" style="stroke: var(--status-red);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
        </div>
        <div class="stat-card-number"><?= number_format($suspendedStaff) ?></div>
        <div class="stat-card-badge <?= $suspendedStaff > 0 ? 'badge-amber' : 'badge-green' ?>">
          <?= $suspendedStaff > 0 ? 'Under Review' : 'Zero Suspensions' ?>
        </div>
      </div>
    </div>

    <!-- Full Staff Roster Table Card -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-teal); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Clinical & Administrative Staff Directory
          </h3>
          <p>Oversee department assignments, employee credentials, and portal access permissions</p>
        </div>
        <span class="role-pill role-pill-staff" style="font-size: 0.76rem;">
          <?= $totalStaff ?> STAFF MEMBERS
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Staff Member</th>
              <th>Department / Function</th>
              <th>Staff ID</th>
              <th>Official Email</th>
              <th>Phone Number</th>
              <th>Status</th>
              <th style="text-align: right;">Access Control</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($staffRoster)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">No staff members registered.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($staffRoster as $staff): ?>
                <tr id="row-user-<?= (int)$staff['user_id'] ?>">
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials" style="background: linear-gradient(135deg, #f3e8ff, #fce7f3); color: #7e22ce;">
                        <?= htmlspecialchars(strtoupper(substr($staff['full_name'], 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                      </div>
                      <div>
                        <strong style="font-size: 0.92rem; color: var(--text-heading);">
                          <?= htmlspecialchars($staff['full_name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <div style="font-size: 0.72rem; color: var(--text-muted);">
                          Gender: <?= htmlspecialchars($staff['gender'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <strong style="color: #7e22ce; font-size: 0.85rem;">
                      <?= htmlspecialchars($staff['department'] ?? 'General Operations', ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                  </td>
                  <td>
                    <span class="license-chip">
                      <?= htmlspecialchars($staff['license_id'] ?? 'STF-PENDING', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($staff['email'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($staff['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td id="status-cell-<?= (int)$staff['user_id'] ?>">
                    <?php if ($staff['status'] === 'active'): ?>
                      <span class="status-badge-active">
                        <svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>
                        Active
                      </span>
                    <?php else: ?>
                      <span class="status-badge-suspended">
                        <svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>
                        Suspended
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="table-actions-flex" style="justify-content: flex-end;" id="action-cell-<?= (int)$staff['user_id'] ?>">
                      <?php if ($staff['status'] === 'active'): ?>
                        <button class="btn-table-action btn-table-suspend" 
                                onclick="executeAdminAction(<?= (int)$staff['user_id'] ?>, 'suspend', this)"
                                title="Suspend staff account">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                          Suspend
                        </button>
                      <?php else: ?>
                        <button class="btn-table-action btn-table-activate" 
                                onclick="executeAdminAction(<?= (int)$staff['user_id'] ?>, 'activate', this)"
                                title="Reactivate staff account">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                          Reactivate
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <!-- Real-time Status Action Script with CSRF Validation -->
  <script>
    async function executeAdminAction(userId, action, buttonEl) {
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

          const statusCell = document.getElementById(`status-cell-${userId}`);
          const actionCell = document.getElementById(`action-cell-${userId}`);

          if (action === 'suspend') {
            if (statusCell) {
              statusCell.innerHTML = '<span class="status-badge-suspended"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>Suspended</span>';
            }
            if (actionCell) {
              actionCell.innerHTML = `
                <button class="btn-table-action btn-table-activate" onclick="executeAdminAction(${userId}, 'activate', this)" title="Reactivate staff account">
                  <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  Reactivate
                </button>
              `;
            }
          } else if (action === 'activate') {
            if (statusCell) {
              statusCell.innerHTML = '<span class="status-badge-active"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>Active</span>';
            }
            if (actionCell) {
              actionCell.innerHTML = `
                <button class="btn-table-action btn-table-suspend" onclick="executeAdminAction(${userId}, 'suspend', this)" title="Suspend staff account">
                  <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                  Suspend
                </button>
              `;
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
  </script>

</body>
</html>

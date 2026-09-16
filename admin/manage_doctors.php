<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Doctors Roster Management Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';

try {
    // Fetch active and suspended doctors
    $doctorsStmt = $pdo->prepare("
        SELECT user_id, full_name, email, phone, gender, role, status, license_id, department, created_at 
        FROM users 
        WHERE role = 'Doctor' AND status IN ('active', 'suspended') 
        ORDER BY status ASC, full_name ASC
    ");
    $doctorsStmt->execute();
    $doctorsRoster = $doctorsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDoctors     = count($doctorsRoster);
    $activeDoctors    = count(array_filter($doctorsRoster, fn($d) => $d['status'] === 'active'));
    $suspendedDoctors = count(array_filter($doctorsRoster, fn($d) => $d['status'] === 'suspended'));

} catch (PDOException $e) {
    error_log("Manage Doctors DB error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact system engineering.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Medical Doctors Roster</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial (Dynamic Active Route Highlighting) -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container (Starts cleanly past sidebar) -->
  <main class="viewport-full">

    <!-- Page Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          Medical Doctors Roster
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
        </h1>
        <p>Comprehensive roster of clinical specialists, BMDC licensing records, and active consultation credentials</p>
      </div>
      <div class="banner-actions">
        <a href="dashboard.php#pendingApprovalSection" class="btn-action-telemed" style="text-decoration: none;">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Verification Queue
        </a>
        <button class="btn-action-gradient" onclick="window.location.href='../index.php'">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          + Add Physician
        </button>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Total Physicians</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        </div>
        <div class="stat-card-number"><?= number_format($totalDoctors) ?></div>
        <div class="stat-card-badge badge-blue">Registered Staff</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Active On Duty</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= number_format($activeDoctors) ?></div>
        <div class="stat-card-badge badge-green">Portal Access Active</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Suspended Accounts</span>
          <svg class="ui-ico" style="stroke: var(--status-red);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
        </div>
        <div class="stat-card-number"><?= number_format($suspendedDoctors) ?></div>
        <div class="stat-card-badge <?= $suspendedDoctors > 0 ? 'badge-amber' : 'badge-green' ?>">
          <?= $suspendedDoctors > 0 ? 'Action Required' : 'Zero Suspensions' ?>
        </div>
      </div>
    </div>

    <!-- Full Medical Doctors Roster Table Card -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
            Practicing Medical Doctors
          </h3>
          <p>Supervise clinical privileges, BMDC license registration, and active login state</p>
        </div>
        <span class="role-pill role-pill-doctor" style="font-size: 0.76rem;">
          <?= $totalDoctors ?> REGISTERED PHYSICIANS
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Doctor Profile</th>
              <th>Clinical Department / Specialty</th>
              <th>BMDC License ID</th>
              <th>Contact Phone</th>
              <th>Official Email</th>
              <th>Portal Status</th>
              <th style="text-align: right;">Access Control</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($doctorsRoster)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">No registered physicians found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($doctorsRoster as $doctor): ?>
                <tr id="row-user-<?= (int)$doctor['user_id'] ?>">
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials">
                        <?= htmlspecialchars(strtoupper(substr($doctor['full_name'], 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                      </div>
                      <div>
                        <strong style="font-size: 0.92rem; color: var(--text-heading);">
                          <?= htmlspecialchars($doctor['full_name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <div style="font-size: 0.72rem; color: var(--text-muted);">
                          Gender: <?= htmlspecialchars($doctor['gender'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <strong style="color: var(--brand-primary); font-size: 0.85rem;">
                      <?= htmlspecialchars($doctor['department'] ?? 'General Medicine', ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                  </td>
                  <td>
                    <span class="license-chip">
                      <?= htmlspecialchars($doctor['license_id'] ?? 'BMDC-PENDING', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($doctor['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td style="color: var(--text-muted);"><?= htmlspecialchars($doctor['email'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td id="status-cell-<?= (int)$doctor['user_id'] ?>">
                    <?php if ($doctor['status'] === 'active'): ?>
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
                    <div class="table-actions-flex" style="justify-content: flex-end;" id="action-cell-<?= (int)$doctor['user_id'] ?>">
                      <?php if ($doctor['status'] === 'active'): ?>
                        <button class="btn-table-action btn-table-suspend" 
                                onclick="executeAdminAction(<?= (int)$doctor['user_id'] ?>, 'suspend', this)"
                                title="Suspend portal access">
                          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                          Suspend
                        </button>
                      <?php else: ?>
                        <button class="btn-table-action btn-table-activate" 
                                onclick="executeAdminAction(<?= (int)$doctor['user_id'] ?>, 'activate', this)"
                                title="Reactivate portal access">
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

        const response = await fetch('../backend/admin_actions.php', {
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
                <button class="btn-table-action btn-table-activate" onclick="executeAdminAction(${userId}, 'activate', this)" title="Reactivate portal access">
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
                <button class="btn-table-action btn-table-suspend" onclick="executeAdminAction(${userId}, 'suspend', this)" title="Suspend portal access">
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

<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Audit Security Logs & Compliance Monitoring Console
 */

require_once __DIR__ . '/includes/admin_auth.php';

// Clinical Telemetry & Security Audit Trail Events
$auditLogs = [
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-4 minutes')),
        'actor_name'  => $adminName ?? 'Miraz',
        'actor_role'  => 'Admin',
        'action'      => 'Doctor Credential Verified',
        'target'      => 'Dr. Ayesha Siddiqua (BMDC-A-94120)',
        'ip_address'  => '192.168.1.10',
        'device'      => 'Firefox 128 (Linux x86_64)',
        'level'       => 'INFO',
        'badge_class' => 'status-badge-active'
    ],
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-18 minutes')),
        'actor_name'  => 'Gateway Security Bot',
        'actor_role'  => 'System',
        'action'      => 'Failed Password Lockout Triggered',
        'target'      => 'auth.endpoint (01700000000)',
        'ip_address'  => '103.145.74.22',
        'device'      => 'Python-Requests / Automated Probe',
        'level'       => 'CRITICAL',
        'badge_class' => 'status-badge-suspended'
    ],
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-45 minutes')),
        'actor_name'  => 'Dr. Rafiqul Islam',
        'actor_role'  => 'Doctor',
        'action'      => 'ICU Patient Telemetry Downloaded',
        'target'      => 'Patient #0001 (Agatsuma Zenitsu)',
        'ip_address'  => '192.168.1.42',
        'device'      => 'Chrome 128 (Windows NT 10.0)',
        'level'       => 'INFO',
        'badge_class' => 'status-badge-active'
    ],
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-1 hour 15 minutes')),
        'actor_name'  => 'Ms. Shinobu',
        'actor_role'  => 'Staff',
        'action'      => 'Central Ward Bed Allocation Updated',
        'target'      => 'Bed ICU-03 assigned to ADM-1082',
        'ip_address'  => '192.168.1.58',
        'device'      => 'Safari 17 (iPadOS Tablet)',
        'level'       => 'INFO',
        'badge_class' => 'status-badge-active'
    ],
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-2 hours 30 minutes')),
        'actor_name'  => 'Dr. Satoru Gojo',
        'actor_role'  => 'Doctor',
        'action'      => 'Prescription Synchronized to EMR',
        'target'      => 'EMR File #10492',
        'ip_address'  => '192.168.1.33',
        'device'      => 'Chrome 128 (macOS Sonoma)',
        'level'       => 'INFO',
        'badge_class' => 'status-badge-active'
    ],
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-3 hours 10 minutes')),
        'actor_name'  => 'Unknown Client',
        'actor_role'  => 'System',
        'action'      => 'Repeated Identifier Mismatch on Doctor Portal',
        'target'      => 'dr.unknown@medpulse.test',
        'ip_address'  => '185.220.101.5',
        'device'      => 'Tor Exit Node / Edge Gateway',
        'level'       => 'WARNING',
        'badge_class' => 'status-badge-pending'
    ],
    [
        'timestamp'   => date('d M Y, h:i A', strtotime('-5 hours 00 minutes')),
        'actor_name'  => 'Central Treasury Engine',
        'actor_role'  => 'System',
        'action'      => 'Inpatient Invoice Reconciled & Stored',
        'target'      => 'Invoice INV-2026-081 (৳ 45,000)',
        'ip_address'  => '127.0.0.1',
        'device'      => 'Automated Cron Dispatcher',
        'level'       => 'INFO',
        'badge_class' => 'status-badge-active'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Audit Security Logs & Compliance</title>
  
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

    <!-- Top Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          Audit Security Logs & Compliance Monitoring
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p>Immutable clinical trail tracking, authentication attempts, role modifications, and patient data access logs.</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Audit trail package encrypted and prepared for download.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Export Audit Trail (CSV/PDF)
        </button>
        <button class="btn-action-gradient" onclick="showToast('Immutable audit stream synchronized.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Live Stream Sync
        </button>
      </div>
    </div>

    <!-- 3-Metric Summary Cards -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <!-- Card 1: Total Events Logged Today -->
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Total Events Today</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number">142</div>
        <div class="stat-card-badge badge-blue">
          <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          100% Immutably Logged
        </div>
      </div>

      <!-- Card 2: Suspicious Attempts -->
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Suspicious Attempts</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </div>
        <div class="stat-card-number" style="color: var(--status-amber);">03</div>
        <div class="stat-card-badge badge-amber">
          <span>Rate Limiting Engaged</span>
        </div>
      </div>

      <!-- Card 3: Database Backup Status -->
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Database Backup Status</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-card-number" style="color: var(--status-green); font-size: 1.45rem; display: flex; align-items: center; gap: 8px;">
          <svg class="ui-ico" style="stroke: var(--status-green); width: 28px; height: 28px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Encrypted
        </div>
        <div class="stat-card-badge badge-green">
          <span>Synced at 00:00 UTC</span>
        </div>
      </div>
    </div>

    <!-- Audit Telemetry Stream Table -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            Live Immutable Telemetry Stream
          </h3>
          <p>Real-time audit records recording every clinical record mutation, login transaction, and security event</p>
        </div>
        <span class="role-pill role-pill-doctor" style="font-size: 0.76rem;">
          SYSTEM AUDIT TRAIL
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Actor / User</th>
              <th>Action Performed</th>
              <th>Target / Resource</th>
              <th>IP Address & Device</th>
              <th style="text-align: right;">Security Level</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditLogs as $log): ?>
              <tr>
                <td style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                  <?= htmlspecialchars($log['timestamp'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td>
                  <div class="user-cell-flex">
                    <div class="user-avatar-initials" style="<?= $log['actor_role'] === 'System' ? 'background: #f1f5f9; color: #475569;' : '' ?>">
                      <?= htmlspecialchars(strtoupper(substr($log['actor_name'], 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div>
                      <strong style="font-size: 0.9rem; color: var(--text-heading);">
                        <?= htmlspecialchars($log['actor_name'], ENT_QUOTES, 'UTF-8') ?>
                      </strong>
                      <div style="margin-top: 2px;">
                        <?php if ($log['actor_role'] === 'Admin'): ?>
                          <span class="role-pill role-pill-staff" style="padding: 1px 6px; font-size: 0.65rem;">Admin</span>
                        <?php elseif ($log['actor_role'] === 'Doctor'): ?>
                          <span class="role-pill role-pill-doctor" style="padding: 1px 6px; font-size: 0.65rem;">Doctor</span>
                        <?php elseif ($log['actor_role'] === 'Staff'): ?>
                          <span class="role-pill role-pill-staff" style="padding: 1px 6px; font-size: 0.65rem;">Staff</span>
                        <?php else: ?>
                          <span style="font-size: 0.65rem; background: #e2e8f0; color: #475569; padding: 1px 6px; border-radius: 999px; font-weight: 700;">System</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <strong style="color: var(--text-heading); font-size: 0.88rem;">
                    <?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                </td>
                <td>
                  <span class="license-chip">
                    <?= htmlspecialchars($log['target'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td>
                  <div style="font-weight: 600; font-size: 0.82rem; color: var(--text-heading);">
                    <?= htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size: 0.72rem; color: var(--text-muted); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars($log['device'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </td>
                <td style="text-align: right;">
                  <?php if ($log['level'] === 'INFO'): ?>
                    <span class="status-badge-active" style="background: var(--status-blue-bg); color: var(--status-blue); border-color: #bfdbfe;">
                      <svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>
                      INFO
                    </span>
                  <?php elseif ($log['level'] === 'WARNING'): ?>
                    <span class="status-badge-pending">
                      <svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>
                      WARNING
                    </span>
                  <?php else: ?>
                    <span class="status-badge-suspended">
                      <svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>
                      CRITICAL
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

</body>
</html>

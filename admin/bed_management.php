<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Live Bed Census & Admissions Telemetry Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Live Bed & Census Control</title>
  
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
          Live Bed & Census Telemetry
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
        </h1>
        <p>Real-time inpatient occupancy, emergency admission allocations, and intensive care bed availability</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Admissions dispatcher is actively routing.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Admissions Dispatch
        </button>
        <button class="btn-action-gradient" onclick="showToast('Ward census refreshed.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Sync Telemetry
        </button>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Total Hospital Capacity</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
        </div>
        <div class="stat-card-number">67</div>
        <div class="stat-card-badge badge-blue">Licensed Beds</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Available Vacancies</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-card-number">45</div>
        <div class="stat-card-badge badge-green">Ready for Admission</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Current Inpatients</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number">22</div>
        <div class="stat-card-badge badge-amber">32.8% Occupancy</div>
      </div>
    </div>

    <!-- Live Bed Availability & Ward Units Grid -->
    <div class="bed-availability-panel" style="margin-bottom: 2rem;">
      <div class="panel-header-flex">
        <div>
          <h3 class="panel-heading">Ward Telemetry & Intensive Care Unit Breakdown</h3>
          <p class="panel-subtext">Live census stream synchronized with Central Nursing Dispatch</p>
        </div>
        <div class="live-status-pill">
          <div class="radar-pulse-dot"></div>
          LIVE RADAR
        </div>
      </div>

      <div class="bed-types-grid">
        <!-- ICU Beds -->
        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>ICU Beds</span>
            <svg class="ui-ico" style="stroke: var(--status-red);" viewBox="0 0 24 24"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
          </div>
          <div class="bed-qty">04 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 10 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-icu"></div></div>
          <div style="font-size: 0.76rem; color: var(--status-red); margin-top: 8px; font-weight: 600;">6 Occupied &bull; Critical Care Priority</div>
        </div>

        <!-- CCU Beds -->
        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>CCU Beds</span>
            <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
          </div>
          <div class="bed-qty">07 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 12 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-ccu"></div></div>
          <div style="font-size: 0.76rem; color: var(--status-amber); margin-top: 8px; font-weight: 600;">5 Occupied &bull; Cardiac Telemetry Active</div>
        </div>

        <!-- General Ward -->
        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>General Ward</span>
            <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          </div>
          <div class="bed-qty">28 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 35 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-general"></div></div>
          <div style="font-size: 0.76rem; color: var(--status-green); margin-top: 8px; font-weight: 600;">7 Occupied &bull; Routine Post-Op Care</div>
        </div>

        <!-- VIP Cabins -->
        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>VIP Cabins</span>
            <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M3 21h18"></path><path d="M19 21v-4"></path><path d="M19 17a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v4"></path></svg>
          </div>
          <div class="bed-qty">06 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 10 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-cabin"></div></div>
          <div style="font-size: 0.76rem; color: var(--brand-primary); margin-top: 8px; font-weight: 600;">4 Occupied &bull; Private Clinical Suite</div>
        </div>
      </div>
    </div>

    <!-- Live Admissions Stream Table Card -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
            Active Inpatient Admissions Registry
          </h3>
          <p>Real-time inpatient logs, assigned attending physicians, and clinical ward allocations</p>
        </div>
        <span class="role-pill role-pill-doctor" style="font-size: 0.76rem;">
          4 ACTIVE WARD UNITS
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Admission Code</th>
              <th>Patient Name</th>
              <th>Assigned Ward</th>
              <th>Bed Number</th>
              <th>Attending Physician</th>
              <th>Admission Date</th>
              <th style="text-align: right;">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><span class="license-chip">ADM-1082</span></td>
              <td><strong style="color: var(--text-heading);">Agatsuma Zenitsu</strong></td>
              <td><strong style="color: var(--status-red);">ICU Ward</strong></td>
              <td>Bed ICU-03</td>
              <td>Dr. Rafiqul Islam</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">16 Sep 2026, 09:30 AM</td>
              <td style="text-align: right;"><span class="status-badge-active">Admitted</span></td>
            </tr>
            <tr>
              <td><span class="license-chip">ADM-1079</span></td>
              <td><strong style="color: var(--text-heading);">Nusrat Jahan</strong></td>
              <td><strong style="color: var(--status-amber);">CCU Ward</strong></td>
              <td>Bed CCU-05</td>
              <td>Dr. Miftahul Sheikh</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">15 Sep 2026, 02:15 PM</td>
              <td style="text-align: right;"><span class="status-badge-active">Admitted</span></td>
            </tr>
            <tr>
              <td><span class="license-chip">ADM-1065</span></td>
              <td><strong style="color: var(--text-heading);">Jahid Hasan</strong></td>
              <td><strong style="color: var(--status-green);">General Ward</strong></td>
              <td>Bed GW-12</td>
              <td>Dr. Mitsuha</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">14 Sep 2026, 11:45 AM</td>
              <td style="text-align: right;"><span class="status-badge-active">Admitted</span></td>
            </tr>
            <tr>
              <td><span class="license-chip">ADM-1048</span></td>
              <td><strong style="color: var(--text-heading);">Robert Downey Jr.</strong></td>
              <td><strong style="color: var(--brand-primary);">VIP Cabin</strong></td>
              <td>Cabin VIP-02</td>
              <td>Dr. Satoru Gojo</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">12 Sep 2026, 04:00 PM</td>
              <td style="text-align: right;"><span class="status-badge-active">Admitted</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

  </main>

</body>
</html>

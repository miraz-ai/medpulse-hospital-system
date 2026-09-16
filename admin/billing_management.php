<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Central Billing & Invoicing Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Billing & Central Invoicing</title>
  
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
          Central Billing & Invoices
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
        </h1>
        <p>Hospital accounts ledger, inpatient settlement statements, diagnostic charges, and insurance reconciliations</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Treasury audit synchronized with central bank ledger.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Treasury Audit
        </button>
        <button class="btn-action-gradient" onclick="showToast('New clinical invoice initiated.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          + Generate Invoice
        </button>
      </div>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Monthly Revenue</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </div>
        <div class="stat-card-number">৳ 2,480,500</div>
        <div class="stat-card-badge badge-green">+14.2% Collected</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Pending Invoices</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number">12</div>
        <div class="stat-card-badge badge-amber">Under Settlement</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Insurance Claims</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <div class="stat-card-number">08</div>
        <div class="stat-card-badge badge-blue">Verified Coverage</div>
      </div>
    </div>

    <!-- Invoices Stream Table Card -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Recent Hospital Clinical Invoices
          </h3>
          <p>Real-time billing transactions, discharge billings, and diagnostic laboratory statements</p>
        </div>
        <span class="role-pill role-pill-doctor" style="font-size: 0.76rem;">
          CENTRAL TREASURY
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Invoice #</th>
              <th>Patient Name</th>
              <th>Department Service</th>
              <th>Total Amount</th>
              <th>Payment Method</th>
              <th>Billing Date</th>
              <th style="text-align: right;">Settlement</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><span class="license-chip">INV-2026-081</span></td>
              <td><strong style="color: var(--text-heading);">Agatsuma Zenitsu</strong></td>
              <td>ICU Critical Care & Telemetry</td>
              <td><strong style="color: var(--brand-primary);">৳ 45,000</strong></td>
              <td>bKash / Credit Card</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">16 Sep 2026</td>
              <td style="text-align: right;"><span class="status-badge-active">Paid & Settled</span></td>
            </tr>
            <tr>
              <td><span class="license-chip">INV-2026-079</span></td>
              <td><strong style="color: var(--text-heading);">Nusrat Jahan</strong></td>
              <td>Cardiac CCU Diagnostics</td>
              <td><strong style="color: var(--brand-primary);">৳ 28,500</strong></td>
              <td>Insurance Direct Claim</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">15 Sep 2026</td>
              <td style="text-align: right;"><span class="status-badge-active">Approved</span></td>
            </tr>
            <tr>
              <td><span class="license-chip">INV-2026-076</span></td>
              <td><strong style="color: var(--text-heading);">Jahid Hasan</strong></td>
              <td>General Surgery Consultation</td>
              <td><strong style="color: var(--brand-primary);">৳ 8,200</strong></td>
              <td>Nagad Mobile Pay</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">14 Sep 2026</td>
              <td style="text-align: right;"><span class="status-badge-active">Paid</span></td>
            </tr>
            <tr>
              <td><span class="license-chip">INV-2026-068</span></td>
              <td><strong style="color: var(--text-heading);">Robert Downey Jr.</strong></td>
              <td>VIP Suite Admission Care</td>
              <td><strong style="color: var(--brand-primary);">৳ 75,000</strong></td>
              <td>Direct Bank Wire</td>
              <td style="color: var(--text-muted); font-size: 0.8rem;">12 Sep 2026</td>
              <td style="text-align: right;"><span class="status-badge-active">Settled</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

  </main>

</body>
</html>

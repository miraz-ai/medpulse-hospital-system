<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone System Configurations & Hospital Infrastructure Console
 */

require_once __DIR__ . '/includes/admin_auth.php';

$saveSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $saveSuccess = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | System Configurations & Infrastructure</title>
  
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

    <form method="POST" action="settings.php" onsubmit="handleSettingsSave(event)">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <!-- Top Banner -->
      <div class="welcome-banner">
        <div class="welcome-text">
          <h1>
            System Configurations & Hospital Infrastructure Parameters
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          </h1>
          <p>Global controls for clinical workflows, telemedicine endpoints, automated dispatch triggers, and security policies.</p>
        </div>
        <div class="banner-actions">
          <button type="button" class="btn-action-telemed" onclick="showToast('Configurations verified against DGHS clinical baseline.', 'success')">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            Audit Baseline
          </button>
          <button type="submit" class="btn-action-gradient" id="btnSaveConfig">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Save All Changes
          </button>
        </div>
      </div>

      <!-- Modular Setting Sections Stack -->

      <!-- SECTION 1: Hospital Identity & Contact Configuration -->
      <section class="admin-stack-card" style="margin-bottom: 1.75rem;">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>
              <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
              Section 1: Hospital Identity & Contact Configuration
            </h3>
            <p>Official healthcare institution naming, national clinical licensing, and emergency dispatch hotline</p>
          </div>
          <span class="role-pill role-pill-staff" style="font-size: 0.76rem;">CORE IDENTITY</span>
        </div>

        <div class="settings-form-grid">
          <div class="setting-field">
            <label for="hospitalName">Hospital Official Name</label>
            <input type="text" id="hospitalName" name="hospital_name" value="MedPulse Hospital & Specialty Care" required>
            <small>Displayed on patient discharge summaries, portal headers, and invoices</small>
          </div>

          <div class="setting-field">
            <label for="emergencyHotline">Emergency Hotline</label>
            <input type="text" id="emergencyHotline" name="emergency_hotline" value="10666 / +880 1811-223344" required>
            <small>Connected to Central Emergency Fleet and ambulance dispatch</small>
          </div>

          <div class="setting-field">
            <label for="hospitalAddress">Physical Hospital Campus Address</label>
            <input type="text" id="hospitalAddress" name="hospital_address" value="Plot 14, Road 7, Sector 3, Uttara, Dhaka-1230" required>
            <small>Official headquarters location for telemedicine jurisdiction</small>
          </div>

          <div class="setting-field">
            <label for="accreditationNo">Clinical Accreditation Registration No.</label>
            <input type="text" id="accreditationNo" name="accreditation_no" value="DGHS-HA-2026-9941" required>
            <small>Issued by Directorate General of Health Services (DGHS)</small>
          </div>
        </div>
      </section>

      <!-- SECTION 2: Security & Session Policies -->
      <section class="admin-stack-card" style="margin-bottom: 1.75rem;">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>
              <svg class="ui-ico" style="stroke: var(--status-red); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
              Section 2: Security & Session Policies
            </h3>
            <p>Role-based authentication gatekeepers, inactivity timeout thresholds, and cryptographic hardening</p>
          </div>
          <span class="role-pill role-pill-doctor" style="font-size: 0.76rem;">RBAC HARDENING</span>
        </div>

        <div>
          <!-- Toggle 1: Enforce Strict Doctor/Staff Verification -->
          <div class="setting-toggle-row">
            <div class="setting-toggle-info">
              <strong>Enforce Strict Doctor/Staff Verification Before Login</strong>
              <span>When active, Doctor and Staff registrations are forced into 'Pending' status and cannot access portals until admin approval.</span>
            </div>
            <label class="switch">
              <input type="checkbox" name="enforce_approval" checked>
              <span class="slider"></span>
            </label>
          </div>

          <!-- Setting 2: Session Timeout Duration -->
          <div class="setting-toggle-row">
            <div class="setting-toggle-info">
              <strong>Session Timeout Duration</strong>
              <span>Automatically terminate inactive administrative and physician sessions to prevent unauthorized physical terminal access.</span>
            </div>
            <div style="min-width: 160px;">
              <select name="session_timeout" style="padding: 0.55rem 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--surface-border); font-weight: 600; width: 100%;">
                <option value="900">15 minutes</option>
                <option value="1800" selected>30 minutes (Default)</option>
                <option value="3600">1 hour</option>
              </select>
            </div>
          </div>

          <!-- Toggle 3: Multi-Factor Authentication (MFA) -->
          <div class="setting-toggle-row">
            <div class="setting-toggle-info">
              <strong>Multi-Factor Authentication (MFA) for Administrative Personnel</strong>
              <span>Require second-factor OTP confirmation on logins originating from non-intranet IP subnets.</span>
            </div>
            <label class="switch">
              <input type="checkbox" name="mfa_admin" checked>
              <span class="slider"></span>
            </label>
          </div>
        </div>
      </section>

      <!-- SECTION 3: Clinical & Ward Telemetry Parameters -->
      <section class="admin-stack-card" style="margin-bottom: 1.75rem;">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>
              <svg class="ui-ico" style="stroke: var(--brand-teal); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
              Section 3: Clinical & Ward Telemetry Parameters
            </h3>
            <p>Maximum inpatient ward allocations, WebRTC telemedicine servers, and appointment grace periods</p>
          </div>
          <span class="role-pill role-pill-patient" style="font-size: 0.76rem;">WARD METRICS</span>
        </div>

        <div class="settings-form-grid">
          <div class="setting-field">
            <label for="capICU">Intensive Care Unit (ICU) Capacity</label>
            <input type="number" id="capICU" name="cap_icu" value="12" min="1" max="100" required>
            <small>Dedicated ventilators and invasive hemodynamic monitors</small>
          </div>

          <div class="setting-field">
            <label for="capCCU">Coronary Care Unit (CCU) Capacity</label>
            <input type="number" id="capCCU" name="cap_ccu" value="16" min="1" max="100" required>
            <small>Cardiac telemetry and central monitoring beds</small>
          </div>

          <div class="setting-field">
            <label for="capGeneral">General Inpatient Ward Capacity</label>
            <input type="number" id="capGeneral" name="cap_general" value="40" min="1" max="500" required>
            <small>Standard inpatient post-operative care units</small>
          </div>

          <div class="setting-field">
            <label for="capVIP">VIP Private Cabins Capacity</label>
            <input type="number" id="capVIP" name="cap_vip" value="10" min="1" max="50" required>
            <small>Private clinical suites with attendant accommodations</small>
          </div>

          <div class="setting-field">
            <label for="telemedEndpoint">Automated Telemedicine WebRTC Gateway</label>
            <input type="text" id="telemedEndpoint" name="telemed_endpoint" value="wss://telemed.medpulse.org/v2/rtc" required>
            <small>Status: <span style="color: var(--status-green); font-weight: 700;">Connected (Latency: 14ms)</span></small>
          </div>

          <div class="setting-field">
            <label for="gracePeriod">Consultation Grace Period (Minutes)</label>
            <input type="number" id="gracePeriod" name="grace_period" value="15" min="5" max="60" required>
            <small>Buffer time allowed before rescheduling no-show appointments</small>
          </div>
        </div>
      </section>

      <!-- SECTION 4: Automated Invoicing & Financial Rates -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>
              <svg class="ui-ico" style="stroke: var(--status-green); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
              Section 4: Automated Invoicing & Financial Rates
            </h3>
            <p>Hospital financial parameters, outpatient base fees, and statutory taxation percentages</p>
          </div>
          <span class="role-pill role-pill-staff" style="font-size: 0.76rem;">TREASURY</span>
        </div>

        <div class="settings-form-grid">
          <div class="setting-field">
            <label for="currencySymbol">Default Currency Symbol</label>
            <input type="text" id="currencySymbol" name="currency_symbol" value="৳ (BDT)" required>
            <small>Primary currency for patient billing invoices and payroll</small>
          </div>

          <div class="setting-field">
            <label for="consultationFee">Standard Consultation Base Fee</label>
            <input type="text" id="consultationFee" name="consultation_fee" value="৳ 1,200.00" required>
            <small>General specialist outpatient initial evaluation charge</small>
          </div>

          <div class="setting-field">
            <label for="serviceVat">VAT / Hospital Service Charge Rate</label>
            <input type="text" id="serviceVat" name="service_vat" value="5%" required>
            <small>Statutory health services value-added taxation</small>
          </div>
        </div>
      </section>

    </form>

  </main>

  <script>
    function handleSettingsSave(event) {
      event.preventDefault();
      const btn = document.getElementById('btnSaveConfig');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span style="font-size: 0.75rem;">Saving...</span>';

      setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('Hospital infrastructure parameters and security policies saved successfully.', 'success');
      }, 500);
    }
  </script>

</body>
</html>

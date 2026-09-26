<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Centralized Reusable Admin Sidebar Partial with Programmatic Active Route Highlighting
 */

// Detect current active script name
$currentRoute = basename($_SERVER['PHP_SELF'] ?? '');

// Ensure $pendingCount is available for the verification badge
if (!isset($pendingCount) && isset($pdo)) {
    try {
        $pCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role IN ('Doctor', 'Staff')");
        $pendingCount = (int)$pCountStmt->fetchColumn();
    } catch (Throwable $e) {
        $pendingCount = 0;
    }
} else {
    $pendingCount = $pendingCount ?? 0;
}
?>

<!-- Floating Toast Notification Banner -->
<div class="admin-toast-box" id="adminToast"></div>

<!-- Mobile Topbar with Native Hamburger -->
<header class="mobile-topbar">
  <button class="mobile-hamburger" id="menuToggle" aria-label="Toggle Navigation">
    <svg class="ui-ico" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
  </button>
  <a href="dashboard.php" class="mobile-brand">
    <img src="../assets/images/logo.png" alt="MedPulse">
  </a>
  <div style="width: 38px;"></div>
</header>

<!-- Dark Backdrop Overlay for Mobile Slideout -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Left Sidebar (Fixed 260px Width, Native Layout Parity) -->
<aside class="left-bar" id="appSidebar">
  <a href="dashboard.php" class="brand-header-link">
    <img src="../assets/images/logo.png" alt="MedPulse Hospital & Specialty Care">
  </a>

  <!-- 1. Hospital Ops -->
  <div class="nav-label">Hospital Ops</div>
  <ul class="nav-menu">
    <li class="nav-item <?= in_array($currentRoute, ['dashboard.php', 'executive_overview.php']) ? 'active' : '' ?>">
      <a href="executive_overview.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          Executive Overview
        </div>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'verification_queue.php') ? 'active' : '' ?>">
      <a href="verification_queue.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Verification Queue
        </div>
        <?php if ($pendingCount > 0): ?>
          <span class="live-chip-sm" id="sidebarPendingBadge" style="background: var(--status-amber);"><?= (int)$pendingCount ?> NEW</span>
        <?php endif; ?>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'manage_doctors.php') ? 'active' : '' ?>">
      <a href="manage_doctors.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          Doctors Roster
        </div>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'manage_staff.php') ? 'active' : '' ?>">
      <a href="manage_staff.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          Clinical & Admin Staff
        </div>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'manage_patients.php') ? 'active' : '' ?>">
      <a href="manage_patients.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
          Patient Registry
        </div>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'inpatient_care.php') ? 'active' : '' ?>">
      <a href="inpatient_care.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          Inpatient Care & Teams
        </div>
        <span class="live-chip-sm" style="background-color: #0f766e; color: #ffffff !important; font-weight: 700; font-size: 10px; padding: 2px 6px; border-radius: 4px;">CARE</span>
      </a>
    </li>
    <li class="nav-item <?= in_array($currentRoute, ['live_census.php', 'bed_management.php'], true) ? 'active' : '' ?>">
      <a href="live_census.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          Live Bed & Census
        </div>
        <span class="live-chip-sm">LIVE</span>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'beds.php') ? 'active' : '' ?>">
      <a href="beds.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><polyline points="10 12 14 12 14 16"></polyline></svg>
          Branch Bed &amp; Admissions
        </div>
        <span class="live-chip-sm" style="background-color: #059669; color: #ffffff !important; font-weight: 700; font-size: 10px; padding: 2px 6px; border-radius: 4px;">ADMIT</span>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'appointments.php') ? 'active' : '' ?>">
      <a href="appointments.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          OPD Appointments Queue
        </div>
        <span class="live-chip-sm" style="background-color: #0284c7; color: #ffffff !important; font-weight: 700; font-size: 10px; padding: 2px 6px; border-radius: 4px;">OPD</span>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'resource_inventory.php') ? 'active' : '' ?>">
      <a href="resource_inventory.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
          Resource Inventory
        </div>
        <span class="live-chip-sm" style="background-color: #0284c7; color: #ffffff !important; font-weight: 700; font-size: 10px; padding: 2px 6px; border-radius: 4px;">IOT</span>
      </a>
    </li>
  </ul>

  <!-- 2. Finance & Diagnostics -->
  <div class="nav-label">Finance & Diagnostics</div>
  <ul class="nav-menu">
    <li class="nav-item <?= in_array($currentRoute, ['billing_management.php', 'billing.php', 'invoices.php', 'collect_payment.php']) ? 'active' : '' ?>">
      <a href="billing_management.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          Billing & Invoices
        </div>
      </a>
    </li>
    <li class="nav-item">
      <a href="javascript:void(0)" onclick="showToast('Diagnostic Laboratory Telemetry active & synchronized.', 'success')">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"></path><path d="M8.5 2h7"></path><path d="M14.5 16h-5"></path></svg>
          Diagnostic Approvals
        </div>
      </a>
    </li>
    <li class="nav-item">
      <a href="tel:999">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
          Emergency Fleet
        </div>
      </a>
    </li>
  </ul>

  <!-- 3. Administration -->
  <div class="nav-label">Administration</div>
  <ul class="nav-menu">
    <li class="nav-item <?= ($currentRoute === 'audit_logs.php') ? 'active' : '' ?>">
      <a href="audit_logs.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          Audit Logs & Security
        </div>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'settings.php') ? 'active' : '' ?>">
      <a href="settings.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          System Configurations
        </div>
      </a>
    </li>
  </ul>

  <!-- 4. Sidebar Footer -->
  <div class="sidebar-footer">
    <a href="../logout.php" class="btn-signout">
      <svg class="ui-ico" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      Sign Out
    </a>
  </div>
</aside>

<!-- Core Mobile Drawer Navigation & Shared Toast Engine -->
<script>
  (function() {
    const menuToggle = document.getElementById('menuToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function toggleMenu() {
      if (appSidebar) appSidebar.classList.toggle('open');
      if (sidebarBackdrop) sidebarBackdrop.classList.toggle('active');
    }

    if (menuToggle) {
      menuToggle.addEventListener('click', toggleMenu);
    }
    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener('click', toggleMenu);
    }
  })();

  // Global Interactive Toast Notification Function
  let toastTimer = null;
  function showToast(message, type = 'success') {
    const toast = document.getElementById('adminToast');
    if (!toast) return;
    if (toastTimer) clearTimeout(toastTimer);

    toast.className = 'admin-toast-box ' + (type === 'success' ? 'toast-success' : 'toast-error');
    toast.innerHTML = `
      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;">
        ${type === 'success' 
          ? '<polyline points="20 6 9 17 4 12"></polyline>' 
          : '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>'}
      </svg>
      <span>${message}</span>
    `;
    toast.style.display = 'flex';

    toastTimer = setTimeout(() => {
      toast.style.display = 'none';
    }, 4000);
  }

  // Client-Side History Guard: Kill BFCache and re-verify session on back-navigation
  window.addEventListener('pageshow', function(event) {
    if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
      window.location.reload();
    }
  });
</script>

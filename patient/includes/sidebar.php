<?php
/**
 * MedPulse Enterprise HMS — Unified Patient Portal Canonical Sidebar
 * Consistent Navigation Across All Patient Views with Dynamic Route Highlighting
 */

$currentRoute = basename($_SERVER['PHP_SELF'] ?? '');
?>
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

<!-- Left Sidebar (Clean Compact 8pt Golden Spacing) -->
<aside class="left-bar" id="appSidebar">
  <a href="dashboard.php" class="brand-header-link">
    <img src="../assets/images/logo.png" alt="MedPulse Hospital &amp; Specialty Care">
  </a>

  <div class="nav-label">Clinical Care</div>
  <ul class="nav-menu">
    <!-- Overview -->
    <li class="nav-item <?= in_array($currentRoute, ['dashboard.php', 'portal.php', '']) ? 'active' : '' ?>">
      <a href="dashboard.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          Overview
        </div>
      </a>
    </li>

    <!-- Medical Specialists -->
    <li class="nav-item <?= in_array($currentRoute, ['specialists.php', 'doctors.php']) ? 'active' : '' ?>">
      <a href="specialists.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          Medical Specialists
        </div>
      </a>
    </li>

    <!-- Consultations -->
    <li class="nav-item <?= in_array($currentRoute, ['appointments.php', 'book_appointment.php']) ? 'active' : '' ?>">
      <a href="appointments.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
          Consultations
        </div>
      </a>
    </li>

    <!-- Virtual Care Suite -->
    <li class="nav-item <?= ($currentRoute === 'telemedicine.php') ? 'active' : '' ?>">
      <a href="telemedicine.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
          Virtual Care Suite
        </div>
        <span class="vc-chip-sm" style="font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 999px; background: rgba(59, 130, 246, 0.15); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.3);">HD CALL</span>
      </a>
    </li>

    <!-- Live Bed Census -->
    <li class="nav-item <?= ($currentRoute === 'reserve_bed.php') ? 'active' : '' ?>">
      <a href="reserve_bed.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          Live Bed Census
        </div>
        <span class="live-chip-sm" style="font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 999px; background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3);">45M HOLD</span>
      </a>
    </li>

    <!-- Diagnostic Reports -->
    <li class="nav-item <?= ($currentRoute === 'reports.php') ? 'active' : '' ?>">
      <a href="reports.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"></path><path d="M8.5 2h7"></path><path d="M14.5 16h-5"></path></svg>
          Diagnostic Reports
        </div>
      </a>
    </li>

    <!-- Digital Rx -->
    <li class="nav-item <?= ($currentRoute === 'prescriptions.php') ? 'active' : '' ?>">
      <a href="prescriptions.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
          Digital Rx
        </div>
      </a>
    </li>

    <!-- Automated Billing -->
    <li class="nav-item <?= in_array($currentRoute, ['billing.php', 'my_bills.php']) ? 'active' : '' ?>">
      <a href="billing.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          Automated Billing
        </div>
      </a>
    </li>
  </ul>

  <div class="nav-label">Emergency &amp; Support</div>
  <ul class="nav-menu">
    <li class="nav-item">
      <a href="tel:10666" style="color: #ef4444;">
        <div class="nav-item-inner">
          <svg class="ui-ico" style="stroke: #ef4444;" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
          Emergency Ambulance (10666)
        </div>
      </a>
    </li>
  </ul>

  <div class="sidebar-footer">
    <a href="../logout.php" class="btn-signout">
      <svg class="ui-ico" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      Sign Out
    </a>
  </div>
</aside>

<script>
  (function() {
    const menuToggle = document.getElementById('menuToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function toggleMenu() {
      if (appSidebar) appSidebar.classList.toggle('open');
      if (sidebarBackdrop) sidebarBackdrop.classList.toggle('active');
    }

    if (menuToggle) menuToggle.addEventListener('click', toggleMenu);
    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleMenu);
  })();
</script>

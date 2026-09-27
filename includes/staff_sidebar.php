<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Centralized Reusable Staff Sidebar Partial with Dynamic Active Route Highlighting
 */

// Detect current active script name
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

<!-- Left Sidebar (Fixed 260px Width, Native MedPulse Parity) -->
<aside class="left-bar" id="appSidebar">
  <a href="dashboard.php" class="brand-header-link">
    <img src="../assets/images/logo.png" alt="MedPulse Hospital &amp; Specialty Care">
  </a>

  <!-- 1. Clinical Operations -->
  <div class="nav-label">Staff Operations</div>
  <ul class="nav-menu">
    <!-- Live Bed Census / Admissions (Active) -->
    <li class="nav-item <?= in_array($currentRoute, ['dashboard.php', 'admissions.php', 'beds.php', '']) ? 'active' : '' ?>">
      <a href="dashboard.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          Live Bed Census / Admissions
        </div>
        <span class="live-chip-sm">ACTIVE</span>
      </a>
    </li>

    <!-- Patient Triage Desk -->
    <li class="nav-item <?= ($currentRoute === 'triage.php') ? 'active' : '' ?>">
      <a href="javascript:void(0)">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
          Patient Triage Desk
        </div>
      </a>
    </li>

    <!-- Doctor Consultations Queue -->
    <li class="nav-item <?= in_array($currentRoute, ['queue.php', 'consultations.php']) ? 'active' : '' ?>">
      <a href="javascript:void(0)">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
          Doctor Consultations Queue
        </div>
      </a>
    </li>
  </ul>

  <!-- 2. Account & Session -->
  <div class="nav-label">Account &amp; Session</div>
  <div class="sidebar-footer">
    <a href="../logout.php" class="btn-signout">
      <svg class="ui-ico" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      Sign Out
    </a>
  </div>
</aside>

<!-- Mobile Drawer Navigation Engine -->
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

  // Client-Side History Guard: Kill BFCache and re-verify session on back-navigation
  window.addEventListener('pageshow', function(event) {
    if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
      window.location.reload();
    }
  });
</script>

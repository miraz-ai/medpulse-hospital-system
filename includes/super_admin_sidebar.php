<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin Sidebar — Network-wide multi-hospital navigation
 */

$currentRoute = basename($_SERVER['PHP_SELF'] ?? '');
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

<!-- Left Sidebar -->
<aside class="left-bar" id="appSidebar">
  <a href="dashboard.php" class="brand-header-link">
    <img src="../assets/images/logo.png" alt="MedPulse Super Admin">
  </a>

  <!-- Role badge -->
  <div style="margin: 0 14px 16px; padding: 8px 12px; background: linear-gradient(135deg,rgba(2,132,199,.12),rgba(13,148,136,.12)); border: 1px solid rgba(2,132,199,.22); border-radius: 10px; display:flex; align-items:center; gap:8px;">
    <svg class="ui-ico" style="stroke:var(--brand-primary); width:16px; height:16px;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
    <div>
      <div style="font-size:0.65rem; color:var(--text-muted); font-weight:700; letter-spacing:.06em; text-transform:uppercase;">Role</div>
      <div style="font-size:0.78rem; font-weight:700; color:var(--brand-primary);">Super Administrator</div>
    </div>
  </div>

  <!-- 1. Network Command -->
  <div class="nav-label">Network Command</div>
  <ul class="nav-menu">
    <li class="nav-item <?= ($currentRoute === 'dashboard.php') ? 'active' : '' ?>">
      <a href="dashboard.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          Network Overview
        </div>
      </a>
    </li>
    <li class="nav-item <?= in_array($currentRoute, ['hospitals.php', 'hospital_directory.php']) ? 'active' : '' ?>">
      <a href="hospital_directory.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><line x1="9" y1="22" x2="9" y2="12"></line><line x1="15" y1="22" x2="15" y2="12"></line><line x1="9" y1="7" x2="15" y2="7"></line></svg>
          Hospital Directory
        </div>
        <span class="net-badge-chip">NET</span>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'bed_monitor.php') ? 'active' : '' ?>">
      <a href="bed_monitor.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          Central Bed Monitor
        </div>
        <span class="live-chip-sm">LIVE</span>
      </a>
    </li>
  </ul>

  <!-- 2. Governance -->
  <div class="nav-label">Governance</div>
  <ul class="nav-menu">
    <li class="nav-item <?= ($currentRoute === 'audit_logs.php') ? 'active' : '' ?>">
      <a href="audit_logs.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          Network Audit Logs
        </div>
      </a>
    </li>
  </ul>

  <!-- 3. Sidebar Footer -->
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

    if (menuToggle) menuToggle.addEventListener('click', toggleMenu);
    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleMenu);
  })();

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
    toastTimer = setTimeout(() => { toast.style.display = 'none'; }, 4000);
  }
</script>

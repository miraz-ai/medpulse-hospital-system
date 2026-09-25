<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Centralized Reusable Doctor Sidebar Partial with Dynamic Active Route Highlighting
 */

// Detect current active script name
$currentRoute = basename($_SERVER['PHP_SELF'] ?? '');

// Inpatient count and today's consultation count for live badges
$sidebarInpatientCount = 0;
$sidebarConsultCount = 0;
$sidebarUnsettledCount = 0;

if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $docUid = (int)$_SESSION['user_id'];
        
        // Active assigned inpatients
        $inpatStmt = $pdo->prepare("SELECT COUNT(*) FROM bed_allocations WHERE attending_doctor_id = ? AND status = 'Active'");
        $inpatStmt->execute([$docUid]);
        $sidebarInpatientCount = (int)$inpatStmt->fetchColumn();

        // Today's scheduled or in-consultation appointments
        $appStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE() AND status IN ('Scheduled', 'In-Consultation')");
        $appStmt->execute([$docUid]);
        $sidebarConsultCount = (int)$appStmt->fetchColumn();

        // Unsettled disbursement items
        $unsettledStmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_items WHERE doctor_id = ? AND doctor_payout_status != 'DISBURSED'");
        $unsettledStmt->execute([$docUid]);
        $sidebarUnsettledCount = (int)$unsettledStmt->fetchColumn();
    } catch (Throwable $e) {
        // Non-blocking
    }
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

  <!-- 1. Clinical Practice -->
  <div class="nav-label">Clinical Care</div>
  <ul class="nav-menu">
    <li class="nav-item <?= ($currentRoute === 'dashboard.php') ? 'active' : '' ?>">
      <a href="dashboard.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          Overview / Dashboard
        </div>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'my_inpatients.php') ? 'active' : '' ?>">
      <a href="my_inpatients.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          My Inpatients &amp; Rounds
        </div>
        <?php if ($sidebarInpatientCount > 0): ?>
          <span class="live-chip-sm"><?= (int)$sidebarInpatientCount ?> ROUNDS</span>
        <?php endif; ?>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'appointments.php') ? 'active' : '' ?>">
      <a href="appointments.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
          Consultations &amp; Schedule
        </div>
        <?php if ($sidebarConsultCount > 0): ?>
          <span class="live-chip-sm" style="background: var(--status-amber);"><?= (int)$sidebarConsultCount ?> TODAY</span>
        <?php endif; ?>
      </a>
    </li>
    <li class="nav-item <?= ($currentRoute === 'prescriptions.php') ? 'active' : '' ?>">
      <a href="prescriptions.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
          Clinical Prescriptions
        </div>
      </a>
    </li>
  </ul>

  <!-- 2. Accounts & Financials -->
  <div class="nav-label">Accounts &amp; Disbursements</div>
  <ul class="nav-menu">
    <li class="nav-item <?= ($currentRoute === 'my_earnings.php') ? 'active' : '' ?>">
      <a href="my_earnings.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          Earnings &amp; Ledger
        </div>
        <?php if ($sidebarUnsettledCount > 0): ?>
          <span class="live-chip-sm" style="background: #0d9488; color: #ffffff !important; font-weight: 700; border-color: #0f766e;"><?= (int)$sidebarUnsettledCount ?> PENDING</span>
        <?php endif; ?>
      </a>
    </li>
  </ul>

  <!-- 3. Professional Profile -->
  <div class="nav-label">Credentialing &amp; Settings</div>
  <ul class="nav-menu">
    <li class="nav-item <?= ($currentRoute === 'profile.php') ? 'active' : '' ?>">
      <a href="profile.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          Profile &amp; Credentials
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

<!-- Mobile Drawer Navigation & Shared Toast Engine -->
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

<?php
// Enforce strict session cookie security before session initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// 1. Strict Authentication & Role-Based Access Control (RBAC) Guard
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Patient') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// 2. Inactivity Timeout (Auto-logout after 30 minutes of idle time)
$inactiveTimeout = 1800; // 30 minutes in seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$_SESSION['last_activity'] = time();

// Prevent browser caching of sensitive clinical records
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 3. Dynamic Patient Data Retrieval via Parameterized PDO Query
require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, phone, gender, role, status,
               blood_group, age, date_of_birth, assigned_doctor, prescriptions
        FROM users 
        WHERE user_id = :id AND role = 'Patient' 
        LIMIT 1
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify account active status and existence
    if (!$patient || $patient['status'] !== 'active') {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        header("Location: ../login.php");
        exit();
    }

    $patientName    = $patient['full_name'];
    $patientEmail   = $patient['email'];
    $bloodGroup     = !empty($patient['blood_group']) ? $patient['blood_group'] : 'B+';
    $gender         = !empty($patient['gender']) ? $patient['gender'] : 'Male';
    $assignedDoctor = !empty($patient['assigned_doctor']) ? $patient['assigned_doctor'] : 'Dr. Satoru Gojo';
    $prescriptions  = !empty($patient['prescriptions']) ? $patient['prescriptions'] : null;

    // Calculate dynamic age if date_of_birth exists, else fallback to age column or default
    if (!empty($patient['date_of_birth'])) {
        try {
            $dobObj = new DateTime($patient['date_of_birth']);
            $age = (new DateTime())->diff($dobObj)->y;
        } catch (Exception $e) {
            $age = !empty($patient['age']) ? (int)$patient['age'] : 22;
        }
    } else {
        $age = !empty($patient['age']) ? (int)$patient['age'] : 22;
    }

} catch (PDOException $e) {
    error_log("Database error in patient_dashboard.php: " . $e->getMessage());
    die("A database communication failure occurred. Please contact hospital support.");
}

$hour = (int)date('H');
if ($hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour < 17) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Patient Portal</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- External Custom CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
</head>
<body>

  <!-- Mobile Topbar with Native Hamburger -->
  <header class="mobile-topbar">
    <button class="mobile-hamburger" id="menuToggle" aria-label="Toggle Navigation">
      <svg class="ui-ico" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
    <a href="#" class="mobile-brand">
      <img src="../assets/images/logo.png" alt="MedPulse">
    </a>
    <div style="width: 38px;"></div>
  </header>

  <!-- Dark Backdrop Overlay for Mobile Slideout -->
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- Left Sidebar (Clean Compact 8pt Golden Spacing) -->
  <aside class="left-bar" id="appSidebar">
    <a href="#" class="brand-header-link">
      <img src="../assets/images/logo.png" alt="MedPulse Hospital & Specialty Care">
    </a>

    <div class="nav-label">Clinical Care</div>
    <ul class="nav-menu">
      <li class="nav-item active">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            Overview
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
            Medical Specialists
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
            Consultations
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            Virtual Care Suite
          </div>
          <span class="vc-chip-sm">HD CALL</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
            Live Bed Census
          </div>
          <span class="live-chip-sm">LIVE</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"></path><path d="M8.5 2h7"></path><path d="M14.5 16h-5"></path></svg>
            Diagnostic Reports
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
            Digital Rx
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Automated Billing
          </div>
        </a>
      </li>
    </ul>

    <div class="nav-label">Emergency & Support</div>
    <ul class="nav-menu">
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            Ambulance Dispatch
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            Account Settings
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

  <!-- Central Workspace -->
  <main class="viewport">
    
    <!-- Dynamic Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>! 
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
        </h1>
        <p>Your electronic medical record, consultations, and diagnostic tests are fully synced.</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
          Virtual Room
        </button>
        <button class="btn-action-gradient">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          New Booking
        </button>
      </div>
    </div>

    <!-- Live Bed Management -->
    <div class="bed-availability-panel">
      <div class="panel-header-flex">
        <div>
          <h3 class="panel-heading">Live Bed Census & Admission</h3>
          <p class="panel-subtext">Real-time occupancy synced with hospital central admissions</p>
        </div>
        <div class="live-status-pill">
          <div class="radar-pulse-dot"></div>
          LIVE STREAM
        </div>
      </div>

      <div class="bed-types-grid">
        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>ICU Beds</span>
            <svg class="ui-ico" style="stroke: var(--status-red);" viewBox="0 0 24 24"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
          </div>
          <div class="bed-qty">04 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 10 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-icu"></div></div>
        </div>

        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>CCU Beds</span>
            <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
          </div>
          <div class="bed-qty">07 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 12 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-ccu"></div></div>
        </div>

        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>General Ward</span>
            <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          </div>
          <div class="bed-qty">28 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 35 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-general"></div></div>
        </div>

        <div class="bed-unit-card">
          <div class="bed-unit-head">
            <span>VIP Cabins</span>
            <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M3 21h18"></path><path d="M19 21v-4"></path><path d="M19 17a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v4"></path></svg>
          </div>
          <div class="bed-qty">06 <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ 10 Free</small></div>
          <div class="bed-progress"><div class="bed-fill fill-cabin"></div></div>
        </div>
      </div>
    </div>

    <!-- Recommended Specialists -->
    <div class="panel-header-flex" style="margin-bottom: 1.15rem;">
      <h3 class="panel-heading">Recommended Medical Specialists</h3>
      <a href="#" class="link-action-sub">
        View Directory 
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
      </a>
    </div>

    <div class="doctor-grid">
      <!-- Specialist 1 -->
      <div class="doctor-card-floating">
        <div class="doctor-avatar-wrap">
          <img src="https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=300&auto=format&fit=crop&q=80" alt="Dr. Satoru Gojo" class="doctor-avatar-img">
          <div class="doc-live-badge-dot"></div>
        </div>
        <div class="doc-info-block">
          <div class="doc-header-row">
            <h4 class="doc-name">Dr. Satoru Gojo</h4>
            <span class="doc-rating-badge">★ 4.9</span>
          </div>
          <div class="doc-specialty">Cardiology & Cardiac Surgery</div>
          <div class="doc-meta-footer">
            <div class="doc-tags-wrap">
              <span class="doc-available-chip"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px; fill: currentColor;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg> Available</span>
              <span class="doc-slot-chip">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                10:30 AM
              </span>
            </div>
            <div class="doc-fee-box">৳ 1,000</div>
          </div>
        </div>
      </div>

      <!-- Specialist 2 -->
      <div class="doctor-card-floating">
        <div class="doctor-avatar-wrap">
          <img src="https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=300&auto=format&fit=crop&q=80" alt="Dr. Mitsuha Miyamizu" class="doctor-avatar-img">
          <div class="doc-live-badge-dot"></div>
        </div>
        <div class="doc-info-block">
          <div class="doc-header-row">
            <h4 class="doc-name">Dr. Mitsuha Miyamizu</h4>
            <span class="doc-rating-badge">★ 4.8</span>
          </div>
          <div class="doc-specialty">Dermatology & Skin Aesthetics</div>
          <div class="doc-meta-footer">
            <div class="doc-tags-wrap">
              <span class="doc-available-chip"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px; fill: currentColor;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg> Available</span>
              <span class="doc-slot-chip">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                04:00 PM
              </span>
            </div>
            <div class="doc-fee-box">৳ 800</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Digital Diagnostics Hub -->
    <div class="diagnostics-panel">
      <div class="panel-header-flex">
        <div>
          <h3 class="panel-heading">Diagnostic & Pathology Hub</h3>
          <p class="panel-subtext">Electronic test results with visual reference ranges</p>
        </div>
        <a href="#" class="link-action-sub">
          All Lab Tests 
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
      </div>

      <div class="lab-records-grid">
        <!-- Test 1 -->
        <div class="lab-test-card">
          <div class="lab-header">
            <div>
              <div class="lab-title">Complete Blood Count (CBC)</div>
              <div class="lab-date">Sample Date: 12 Sep 2026</div>
            </div>
            <span class="lab-status-badge status-verified">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Verified
            </span>
          </div>
          <div class="lab-result-metric">
            <span class="metric-value">14.8</span>
            <span class="metric-unit">g/dL (Hemoglobin)</span>
          </div>
          <div class="range-scale-bar"><div class="bed-fill scale-fill-normal"></div></div>
          <div class="lab-action-row">
            <span style="font-size: 0.72rem; color: #15803d; font-weight: 700;">Within Normal Range (13.5 - 17.5)</span>
            <button class="btn-report-dl">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              Download
            </button>
          </div>
        </div>

        <!-- Test 2 -->
        <div class="lab-test-card">
          <div class="lab-header">
            <div>
              <div class="lab-title">Fasting Blood Glucose (HbA1c)</div>
              <div class="lab-date">Sample Date: 08 Sep 2026</div>
            </div>
            <span class="lab-status-badge status-verified">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Verified
            </span>
          </div>
          <div class="lab-result-metric">
            <span class="metric-value">95.4</span>
            <span class="metric-unit">mg/dL (Glucose)</span>
          </div>
          <div class="range-scale-bar"><div class="bed-fill scale-fill-normal"></div></div>
          <div class="lab-action-row">
            <span style="font-size: 0.72rem; color: #15803d; font-weight: 700;">Optimal Sugar Level (70 - 99)</span>
            <button class="btn-report-dl">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              Download
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Prescriptions & Automated Invoicing -->
    <div class="columns-dual">
      <div class="panel-block">
        <h3 class="panel-heading" style="margin-bottom: 1rem;">
          Active Digital Prescriptions
        </h3>

        <div class="script-item">
          <div>
            <strong style="font-size: 0.92rem; color: var(--text-heading);">Napa Extra (500mg)</strong>
            <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Paracetamol + Caffeine</p>
            <span class="tag-time tag-morning">1 Tab after meal (3x Daily)</span>
          </div>
          <button class="btn-download-rx">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Rx PDF
          </button>
        </div>

        <div class="script-item">
          <div>
            <strong style="font-size: 0.92rem; color: var(--text-heading);">Monas 10mg</strong>
            <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Montelukast Sodium</p>
            <span class="tag-time tag-night">1 Tab at night (15 Days)</span>
          </div>
          <button class="btn-download-rx">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Rx PDF
          </button>
        </div>
      </div>

      <div class="panel-block">
        <h3 class="panel-heading" style="margin-bottom: 1rem;">
          Automated Invoicing
        </h3>
        <div class="payment-box">
          <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Outstanding Balance</span>
          <div class="pay-amount">৳ 1,200.00</div>
          <button class="btn-action-gradient" style="width: 100%; justify-content: center; padding: 0.72rem;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Pay Auto Invoice
          </button>
        </div>
      </div>
    </div>

  </main>

  <!-- Right Profile Panel -->
  <aside class="profile-bar">
    <div class="patient-hero">
      <div class="patient-avatar-wrap">
        <div class="patient-avatar-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </div>
      </div>
      <h3 style="font-size: 1.05rem; font-weight: 800; color: var(--text-heading);"><?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?></h3>
      <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($patientEmail, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <!-- Health Vitals -->
    <div class="vitals-cards-2x2">
      <div class="vital-cell">
        <label>Blood Type</label>
        <strong style="color: var(--status-red);"><?= htmlspecialchars($bloodGroup, ENT_QUOTES, 'UTF-8') ?></strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-optimal"></div></div>
      </div>
      <div class="vital-cell">
        <label>Age / Sex</label>
        <strong><?= htmlspecialchars((string)$age, ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($gender, ENT_QUOTES, 'UTF-8') ?></strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-optimal"></div></div>
      </div>
      <div class="vital-cell">
        <label>Blood Pressure</label>
        <strong>120/80</strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-normal"></div></div>
      </div>
      <div class="vital-cell">
        <label>Heart Rate</label>
        <strong>74 bpm</strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-normal"></div></div>
      </div>
    </div>

    <!-- Emergency Dispatch Button -->
    <a href="tel:999" class="ambulance-card-btn">
      <svg class="ui-ico" style="stroke: white; width: 26px; height: 26px; margin: 0 auto 4px;" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
      <span style="display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700; opacity: 0.9;">Emergency 24/7</span>
      <strong>Request Ambulance</strong>
    </a>
  </aside>

  <!-- Mobile Slide Navigation Script -->
  <script>
    const menuToggle = document.getElementById('menuToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function toggleMenu() {
      appSidebar.classList.toggle('open');
      sidebarBackdrop.classList.toggle('active');
    }

    if (menuToggle) {
      menuToggle.addEventListener('click', toggleMenu);
      sidebarBackdrop.addEventListener('click', toggleMenu);
    }
  </script>

</body>
</html>
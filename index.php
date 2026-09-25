<?php
require_once __DIR__ . '/includes/session_guard.php';

// If already authenticated, redirect immediately to role-specific dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $destination = medpulseGetRoleDashboard($_SESSION['role']);
    header('Location: ' . $destination);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Pulse of Better Healthcare</title>
  <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg?v=1">
<link rel="alternate icon" href="assets/images/favicon.svg?v=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>

  <div class="glow-1"></div>
  <div class="glow-2"></div>
  <?php
    $err = $_GET['error'] ?? $_GET['auth_error'] ?? '';
    $msg = $_GET['msg'] ?? '';
    $isLoggedOut = isset($_GET['logged_out']) || ($msg === 'logged_out');
    $serverMsg = '';
    $serverClass = '';
    if ($isLoggedOut) {
        $serverMsg = 'You have been successfully signed out of the MedPulse Network.';
        $serverClass = 'alert-success';
    } elseif ($err === 'pending_approval') {
        $serverMsg = 'Your account is currently under administrative verification. Please wait for official approval before accessing the clinical portal.';
        $serverClass = 'alert-error';
    } elseif ($err === 'account_suspended') {
        $serverMsg = 'Your account has been suspended by hospital administration. Please contact hospital HR.';
        $serverClass = 'alert-error';
    } elseif ($err === 'account_declined') {
        $serverMsg = 'Your registration credentials were rejected by the hospital administration. Contact hospital admin.';
        $serverClass = 'alert-error';
    } elseif ($err === 'invalid_credentials') {
        $serverMsg = 'Invalid credentials. Please verify your email/phone and password.';
        $serverClass = 'alert-error';
    } elseif ($msg === 'pending_verification') {
        $serverMsg = 'Registration successful. Your medical credentials (BMDC) have been submitted to the Admin Treasury & Credentialing Board for verification. You will gain portal access once approved.';
        $serverClass = 'alert-success';
    } elseif ($msg === 'registered') {
        $serverMsg = 'Registration successful! Please sign in with your credentials.';
        $serverClass = 'alert-success';
    }
  ?>
  <div id="alertBanner" <?= $serverMsg ? 'style="display: block;" class="' . $serverClass . '"' : '' ?>><?= htmlspecialchars($serverMsg, ENT_QUOTES, 'UTF-8') ?></div>

  <div class="auth-card" id="authCard">

    <div class="mobile-nav-pills">
      <button id="mTabLogin" class="active" onclick="mobileToggle('login')">Sign In</button>
      <button id="mTabRegister" onclick="mobileToggle('register')">Register</button>
    </div>

    <!-- Sign In Panel -->
    <div class="form-pane signin-pane m-show" id="signInSection">
      <form id="loginForm" action="backend/login_action.php" method="POST">
        <div class="brand">
          <div class="brand-logo"><i class="fa-solid fa-heart-pulse"></i></div>
          <span class="brand-name">MedPulse</span>
        </div>
        <h2 class="title">Welcome Back</h2>
        <p class="desc">Sign in to your synchronized hospital records</p>

        <div class="field-box">
          <input type="text" name="identifier" id="loginIdentifier" placeholder="Email Address or Mobile Number" required autocomplete="username" />
          <i class="fa-regular fa-envelope f-icon"></i>
        </div>

        <div class="field-box password-input-group password-field-wrapper" style="position: relative; width: 100%;">
          <input type="password" name="password" id="password" class="form-control password-input" style="width: 100%; padding-right: 42px; padding-left: 42px;" placeholder="Password" required autocomplete="current-password" />
          <i class="fa-solid fa-lock f-icon" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 4;"></i>
          <button type="button" class="toggle-password-btn" onclick="togglePassword(this)" aria-label="Toggle password visibility" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center; padding: 0; width: 28px; height: 28px; z-index: 5;">
            <i class="fas fa-eye eye-icon" style="pointer-events: none;"></i>
          </button>
        </div>

        <button type="submit" class="submit-btn">Authenticate &amp; Enter</button>
      </form>
    </div>

    <!-- Sign Up Panel -->
    <div class="form-pane signup-pane m-hidden" id="signUpSection">
      <form id="registerForm" onsubmit="handleAuthSubmit(event, 'backend/register_action.php')">
        <div class="brand">
          <div class="brand-logo"><i class="fa-solid fa-heart-pulse"></i></div>
          <span class="brand-name">MedPulse</span>
        </div>
        <h2 class="title">Join Network</h2>
        <p class="desc">Create patient or clinical personnel credentials</p>

        <div class="role-selector">
          <input type="radio" id="r-pat" name="register_role" value="Patient" checked onchange="toggleDoctorFields(this.value)">
          <label for="r-pat"><i class="fa-solid fa-user-injured"></i> Patient</label>

          <input type="radio" id="r-doc" name="register_role" value="Doctor" onchange="toggleDoctorFields(this.value)">
          <label for="r-doc"><i class="fa-solid fa-user-doctor"></i> Doctor</label>

          <input type="radio" id="r-stf" name="register_role" value="Staff" onchange="toggleDoctorFields(this.value)">
          <label for="r-stf"><i class="fa-solid fa-hospital-user"></i> Staff</label>
        </div>

        <div id="doctorExtraFields" style="display: none;">
          <div class="field-box">
            <input type="text" name="specialty" placeholder="Specialty (e.g. General Surgery & Critical Care)" />
            <i class="fa-solid fa-stethoscope f-icon"></i>
          </div>
          <div class="field-box">
            <input type="text" name="bmdc_reg" placeholder="BMDC Reg No. (e.g. A-72819)" />
            <i class="fa-solid fa-id-card f-icon"></i>
          </div>
        </div>

        <div class="field-box">
          <input type="text" name="name" placeholder="Full Legal Name" required />
          <i class="fa-regular fa-user f-icon"></i>
        </div>

        <div class="field-box">
          <input type="email" name="email" placeholder="Official Email Address" required />
          <i class="fa-regular fa-envelope f-icon"></i>
        </div>

        <div class="field-box">
          <input type="tel" id="regPhone" name="phone" placeholder="01712345678" maxlength="11" pattern="01[3-9][0-9]{8}" required />
          <i class="fa-solid fa-phone f-icon"></i>
        </div>

        <div class="field-grid-patient">
          <div class="field-box" style="margin-bottom: 0;">
            <label for="regDob" style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.04em;">Date of Birth</label>
            <div style="position: relative;">
              <input type="date" id="regDob" name="dob" max="<?= date('Y-m-d') ?>" required style="padding-left: 38px; color-scheme: dark; width: 100%;" />
              <i class="fa-regular fa-calendar f-icon" style="left: 12px;"></i>
            </div>
          </div>

          <div class="field-box" style="margin-bottom: 0;">
            <label for="regBloodGroup" style="display: block; font-size: 0.72rem; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.04em;">Blood Group</label>
            <div style="position: relative;">
              <select id="regBloodGroup" name="blood_group" required style="padding-left: 38px; padding-right: 28px; appearance: none; width: 100%;">
                <option value="" disabled selected>Select Blood Group</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
                <option value="Unknown">Unknown / Not Tested</option>
              </select>
              <i class="fa-solid fa-droplet f-icon" style="left: 12px; color: #ef4444;"></i>
              <i class="fa-solid fa-chevron-down" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--text-muted); font-size: 0.75rem;"></i>
            </div>
          </div>
        </div>

        <div class="gender-section">
          <div class="gender-selector">
            <input type="radio" id="g-male" name="gender" value="Male" checked>
            <label for="g-male"><i class="fa-solid fa-mars"></i> Male</label>

            <input type="radio" id="g-female" name="gender" value="Female">
            <label for="g-female"><i class="fa-solid fa-venus"></i> Female</label>

          </div>
        </div>

        <div class="field-box password-input-group password-field-wrapper" style="position: relative; width: 100%;">
          <input type="password" id="regPassInput" name="password" class="form-control password-input" style="width: 100%; padding-right: 42px; padding-left: 42px;" placeholder="Create Strong Password" oninput="checkStrength(this.value)" required />
          <i class="fa-solid fa-shield-halved f-icon" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 4;"></i>
          <button type="button" class="toggle-password-btn" onclick="togglePassword(this)" aria-label="Toggle password visibility" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center; padding: 0; width: 28px; height: 28px; z-index: 5;">
            <i class="fas fa-eye eye-icon" style="pointer-events: none;"></i>
          </button>
        </div>

        <div class="password-strength-box">
          <div class="strength-bar-track">
            <div class="strength-bar-progress" id="strengthBar"></div>
          </div>
          <div class="criteria-list">
            <div class="criteria-item" id="crit-len"><i class="fa-solid fa-circle"></i> Min 8 chars</div>
            <div class="criteria-item" id="crit-upper"><i class="fa-solid fa-circle"></i> 1 Uppercase</div>
            <div class="criteria-item" id="crit-num"><i class="fa-solid fa-circle"></i> 1 Number</div>
            <div class="criteria-item" id="crit-sym"><i class="fa-solid fa-circle"></i> 1 Symbol (@$!%*?&#)</div>
          </div>
        </div>

        <button type="submit" class="submit-btn">Confirm Registration</button>
      </form>
    </div>

    <!-- Desktop Sliding Hero Panel -->
    <div class="slider-overlay">
      <div class="slider-gradient">
        <div class="slider-panel slider-left">
          <h2>Registered Already?</h2>
          <p>Sign in to quickly check live beds, clinical reports, appointments, and one-click bills.</p>
          <button class="ghost-switch" onclick="toggleDesktopSlider(false)">Back to Login</button>
        </div>
        <div class="slider-panel slider-right">
          <h2>New to MedPulse?</h2>
          <p>Register as a patient or hospital staff to sync operations into our centralized relational model.</p>
          <button class="ghost-switch" onclick="toggleDesktopSlider(true)">Sign Up Now</button>
        </div>
      </div>
    </div>

  </div>

  <script src="assets/js/auth.js?v=<?= time() ?>"></script>
</body>
</html>
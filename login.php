<?php
/**
 * MedPulse Enterprise Authentication Controller & View
 * 
 * Features:
 * - Enterprise Multi-Role Authentication & Registration
 * - Automated Role-Based Sign-In: single-query role resolution without manual role toggling
 * - Registration Tabs: Patient, Doctor, and Staff with dynamic network hospital dropdowns
 * - Direct database authentication with PDO prepared statements
 * - Dynamic hospital facility selection directly from hospitals table (SELECT id, name FROM hospitals ORDER BY id ASC)
 * - Collision-Proof Unique Patient UID Engine (MP-YYYY-XXXXXX) with DOB and Blood Group
 * - CSRF token verification and session hardening
 * - Seamless AJAX and standard form post support with alert containers
 */

require_once __DIR__ . '/includes/session_guard.php';
require_once __DIR__ . '/config/db.php';

// ── 1. POST Request Router: Delegate to backend controllers ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register_role']) || (isset($_POST['action']) && $_POST['action'] === 'register') || isset($_POST['dob']) || isset($_POST['hospital_id'])) {
        require_once __DIR__ . '/backend/register_action.php';
        exit();
    }
    require_once __DIR__ . '/backend/login_action.php';
    exit();
}

// ── 2. Already-Authenticated Role-Aware Redirection Guard ──────────────────────
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $roleNorm = strtolower($_SESSION['role']);
    $destination = match ($roleNorm) {
        'doctor'                  => 'doctor/dashboard.php',
        'patient'                 => 'patient/dashboard.php',
        'admin', 'hospital_admin' => 'admin/dashboard.php',
        'super_admin'             => 'super_admin/dashboard.php',
        'staff'                   => 'staff/dashboard.php',
        default                   => 'patient/dashboard.php'
    };
    header('Location: ' . $destination);
    exit();
}

// ── 3. CSRF Token Initialization ──────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── 4. Query Hospital Facilities for Doctor & Staff Registration Dropdowns ────
try {
    $hospStmt = $pdo->query("SELECT id, name FROM hospitals ORDER BY id ASC");
    $hospitals = $hospStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Failed to query hospitals: " . $e->getMessage());
    $hospitals = [
        ['id' => 1, 'name' => 'MedPulse Hospital & Specialty Care'],
        ['id' => 2, 'name' => 'Square Hospital Ltd'],
        ['id' => 3, 'name' => 'United Hospital Ltd'],
        ['id' => 4, 'name' => 'United Medical College Hospital'],
        ['id' => 5, 'name' => 'Evercare Hospital Dhaka'],
        ['id' => 6, 'name' => 'National Institute of Burn and Plastic Surgery'],
    ];
}

// ── 5. Parse Status & Error Messages for Alert UI ─────────────────────────────
$err = $_GET['error'] ?? $_GET['auth_error'] ?? '';
$msg = $_GET['msg'] ?? '';
$isLoggedOut = isset($_GET['logged_out']) || ($msg === 'logged_out');
$activeMode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'signin';

$serverMsg = '';
$alertType = 'error';

if ($isLoggedOut) {
    $serverMsg = 'You have been successfully signed out of the MedPulse Network.';
    $alertType = 'success';
} elseif ($err === 'invalid_credentials') {
    $serverMsg = 'Invalid credentials. Please verify your email / Patient UID and password.';
    $alertType = 'error';
} elseif ($err === 'pending_approval') {
    $serverMsg = 'Your medical account is currently under administrative verification. Please wait for credentials approval.';
    $alertType = 'warning';
} elseif ($err === 'account_suspended') {
    $serverMsg = 'Your account has been suspended by hospital administration. Please contact administration support.';
    $alertType = 'error';
} elseif ($err === 'account_declined') {
    $serverMsg = 'Your registration credentials were declined by hospital administration.';
    $alertType = 'error';
} elseif ($err === 'account_inactive') {
    $serverMsg = 'Your account is currently inactive. Please contact hospital administrator.';
    $alertType = 'error';
} elseif ($err === 'invalid_csrf') {
    $serverMsg = 'Security validation failed (CSRF token expired). Please try submitting again.';
    $alertType = 'error';
} elseif ($err === 'reg_error') {
    $serverMsg = !empty($_GET['msg']) ? urldecode($_GET['msg']) : 'Registration could not be completed. Please review your information.';
    $alertType = 'error';
} elseif ($msg === 'pending_verification') {
    $serverMsg = 'Registration successful! Your credentials have been submitted for hospital verification. You will gain portal access once approved.';
    $alertType = 'success';
} elseif ($msg === 'registered') {
    $serverMsg = 'Registration successful! Please sign in with your credentials.';
    $alertType = 'success';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sign In &middot; MedPulse Hospital &amp; Specialty Care</title>
<link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<!-- Tailwind CSS via CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- MedPulse Auth Stylesheet -->
<link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
<style>
  .optional-label {
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 400;
    margin-left: 4px;
  }
</style>
</head>
<body>

<div class="auth-page">

  <!-- ============ LEFT: BRAND PANEL ============ -->
  <aside class="brand-panel">

    <img class="brand-bg-photo" src="assets/images/medpulse.png" alt="Doctors consulting inside a MedPulse facility">
    <div class="brand-overlay" aria-hidden="true"></div>

    <div class="brand-panel__inner">

      <div class="brand-logo-chip">
        <img class="brand-logo" src="assets/images/logo.png" alt="MedPulse — Hospital &amp; Specialty Care">
      </div>

      <div class="brand-copy">
        <p class="text-white/85 text-sm sm:text-base leading-relaxed">
          Integrated clinical workflows, encrypted patient records, and real-time bed admissions across verified hospital facilities.
        </p>
      </div>

      <ul class="trust-list">
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3l7 3v6c0 5-3.4 8.6-7 10-3.6-1.4-7-5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>
          Encrypted, HIPAA-ready patient data
        </li>
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/></svg>
          Care teams available around the clock
        </li>
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 21v-6a4 4 0 014-4h8a4 4 0 014 4v6"/><circle cx="12" cy="7" r="4"/></svg>
          For patients, doctors and hospital staff
        </li>
      </ul>

    </div>
  </aside>

  <!-- ============ RIGHT: AUTH FORMS ============ -->
  <main class="form-panel">
    <div class="form-card">

      <img class="form-card__mobile-logo" src="assets/images/logo.png" alt="MedPulse — Hospital &amp; Specialty Care">

      <!-- Alert Notification Banner Container -->
      <div id="authAlert" class="auth-alert <?= $serverMsg ? 'auth-alert--' . $alertType : '' ?>" style="<?= $serverMsg ? '' : 'display: none;' ?>" role="alert">
        <svg class="auth-alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <?php if ($alertType === 'success'): ?>
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          <?php elseif ($alertType === 'warning'): ?>
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          <?php else: ?>
            <circle cx="12" cy="12" r="9"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
          <?php endif; ?>
        </svg>
        <div id="authAlertText" class="auth-alert__content">
          <?= htmlspecialchars($serverMsg, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>

      <!-- Auth Switch (Tabs) -->
      <div class="auth-switch" id="authSwitch" role="tablist">
        <button type="button" class="auth-switch__btn <?= $activeMode === 'signin' ? 'is-active' : '' ?>" id="tabSignIn" role="tab" aria-selected="<?= $activeMode === 'signin' ? 'true' : 'false' ?>" aria-controls="panelSignIn">Sign In</button>
        <button type="button" class="auth-switch__btn <?= $activeMode === 'register' ? 'is-active' : '' ?>" id="tabRegister" role="tab" aria-selected="<?= $activeMode === 'register' ? 'true' : 'false' ?>" aria-controls="panelRegister">Create Account</button>
        <span class="auth-switch__thumb <?= $activeMode === 'register' ? 'is-right' : '' ?>" id="authThumb" aria-hidden="true"></span>
      </div>

      <!-- ---------------- SIGN IN PANEL ---------------- -->
      <section class="auth-panel <?= $activeMode === 'signin' ? 'is-active' : '' ?>" id="panelSignIn" role="tabpanel" aria-labelledby="tabSignIn" <?= $activeMode === 'signin' ? '' : 'hidden' ?>>

        <p class="eyebrow">Welcome Back</p>
        <h2 class="auth-heading">Sign in to MedPulse</h2>
        <p class="auth-subtext">Access your clinical portal, staff console, or patient records</p>

        <form class="auth-form" id="signInForm" action="backend/login_action.php" method="POST" autocomplete="on" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

          <!-- Automated Identifier Input: Registered Email or Patient UID (No manual role toggles required) -->
          <div class="form-field">
            <label for="signInId">Email or Patient UID</label>
            <input type="text" id="signInId" name="identifier" placeholder="e.g. user@hospital.com or MP-2026-XXXXXX" autocomplete="username" required>
          </div>

          <div class="form-field">
            <label for="signInPw">Password</label>
            <div class="password-field">
              <input type="password" id="signInPw" name="password" placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="password-toggle" data-target="signInPw">Show</button>
            </div>
          </div>

          <div class="form-row">
            <label class="checkbox">
              <input type="checkbox" name="rememberMe" value="1">
              <span>Remember me</span>
            </label>
            <a href="#" class="text-link" onclick="displayAlert('Please reach out to your hospital administration to securely reset credentials.', 'warning'); return false;">Forgot Password?</a>
          </div>

          <button type="submit" class="btn-primary" id="btnSignIn">Sign In</button>

          <div class="divider"><span>OR</span></div>

          <p class="helper-text">Need help? <a href="#" class="text-link" onclick="displayAlert('MedPulse Central Support Desk: +880-2-9881234 (Available 24/7)', 'info'); return false;">Contact Hospital Support</a></p>
          <p class="helper-text">New here? <a href="#" class="text-link" id="goToRegister">Create an account</a></p>
        </form>
      </section>

      <!-- ---------------- CREATE ACCOUNT PANEL ---------------- -->
      <section class="auth-panel <?= $activeMode === 'register' ? 'is-active' : '' ?>" id="panelRegister" role="tabpanel" aria-labelledby="tabRegister" <?= $activeMode === 'register' ? '' : 'hidden' ?>>

        <p class="eyebrow">Join MedPulse Network</p>
        <h2 class="auth-heading">Create your account</h2>
        <p class="auth-subtext">Register as a patient, affiliated medical doctor, or hospital staff</p>

        <form class="auth-form" id="registerForm" action="backend/register_action.php" method="POST" autocomplete="on" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

          <!-- Dynamic 3-Way Role Switch: Patient, Doctor, and Staff -->
          <div class="role-tabs" role="radiogroup" aria-label="Account type">
            <input type="radio" name="register_role" id="regRolePatient" value="Patient" checked>
            <label for="regRolePatient">Patient</label>

            <input type="radio" name="register_role" id="regRoleDoctor" value="Doctor">
            <label for="regRoleDoctor">Doctor</label>

            <input type="radio" name="register_role" id="regRoleStaff" value="Staff">
            <label for="regRoleStaff">Staff</label>
          </div>

          <!-- Doctor Registration Specific Fields -->
          <div class="doctor-fields" id="doctorFields" hidden>
            <div class="form-field">
              <label for="regHospitalId">Hospital / Facility <span class="required-star">*</span></label>
              <select id="regHospitalId" name="hospital_id" disabled>
                <option value="" disabled selected>Select affiliated hospital facility</option>
                <?php foreach ($hospitals as $h): ?>
                  <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-field">
              <label for="regLicense">Medical Council Reg. No. (BMDC)</label>
              <input type="text" id="regLicense" name="bmdc_reg_number" placeholder="e.g. A-72819" disabled>
            </div>
          </div>

          <!-- Staff Registration Specific Fields -->
          <div class="staff-fields" id="staffFields" hidden>
            <div class="form-field">
              <label for="regStaffHospitalId">Hospital / Facility <span class="required-star">*</span></label>
              <select id="regStaffHospitalId" name="hospital_id" disabled>
                <option value="" disabled selected>Select affiliated hospital facility</option>
                <?php foreach ($hospitals as $h): ?>
                  <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-field">
              <label for="regDepartment">Staff Role / Department <span class="optional-label">(Optional)</span></label>
              <input type="text" id="regDepartment" name="department" placeholder="e.g. Nurse, Receptionist, Housekeeping, Billing Clerk" list="staffRolesList" disabled>
              <datalist id="staffRolesList">
                <option value="Nurse">
                <option value="Receptionist">
                <option value="Billing Clerk">
                <option value="Housekeeping">
                <option value="Laboratory Technician">
                <option value="Pharmacist">
                <option value="Administrative Staff">
              </datalist>
            </div>
          </div>

          <div class="form-field">
            <label for="regName">Full Legal Name <span class="required-star">*</span></label>
            <input type="text" id="regName" name="name" placeholder="e.g. Jane Doe" autocomplete="name" required>
          </div>

          <div class="form-field">
            <label for="regEmail">Email Address <span class="required-star">*</span></label>
            <input type="email" id="regEmail" name="email" placeholder="you@hospital.com" autocomplete="email" required>
          </div>

          <div class="form-field">
            <label for="regPhone">Mobile Phone Number <span class="required-star">*</span></label>
            <input type="tel" id="regPhone" name="phone" placeholder="01712345678" maxlength="11" pattern="01[3-9][0-9]{8}" autocomplete="tel" required>
          </div>

          <div class="form-field">
            <label for="regGender">Gender <span class="required-star">*</span></label>
            <select id="regGender" name="gender" required>
              <option value="Male" selected>Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <!-- Patient Specific Demographics: DOB and Blood Group Dropdown -->
          <div class="form-grid" id="patientFields">
            <div class="form-field">
              <label for="regDob">Date of Birth <span class="required-star">*</span></label>
              <input type="date" id="regDob" name="dob" max="<?= date('Y-m-d') ?>" autocomplete="bday" required>
            </div>
            <div class="form-field">
              <label for="regBloodGroup">Blood Group <span class="required-star">*</span></label>
              <select id="regBloodGroup" name="blood_group" required>
                <option value="" disabled selected>Select</option>
                <option value="A+">A+</option>
                <option value="A-">A-</option>
                <option value="B+">B+</option>
                <option value="B-">B-</option>
                <option value="AB+">AB+</option>
                <option value="AB-">AB-</option>
                <option value="O+">O+</option>
                <option value="O-">O-</option>
                <option value="Unknown">Unknown</option>
              </select>
            </div>
          </div>

          <div class="form-field">
            <label for="regPw">Create Password <span class="required-star">*</span></label>
            <div class="password-field">
              <input type="password" id="regPw" name="password" placeholder="Min. 8 characters (Uppercase, number, symbol)" autocomplete="new-password" minlength="8" required>
              <button type="button" class="password-toggle" data-target="regPw">Show</button>
            </div>
          </div>

          <button type="submit" class="btn-primary" id="btnRegister">Create Account</button>

          <p class="helper-text">Already registered? <a href="#" class="text-link" id="goToSignIn">Back to sign in</a></p>
        </form>
      </section>

      <p class="form-footer">&copy; <?= date('Y') ?> MedPulse Hospital &amp; Specialty Care. All rights reserved.</p>
    </div>
  </main>

</div>

<script>
  // UI Interactive Tab Switching
  const tabSignIn     = document.getElementById('tabSignIn');
  const tabRegister   = document.getElementById('tabRegister');
  const panelSignIn   = document.getElementById('panelSignIn');
  const panelRegister = document.getElementById('panelRegister');
  const thumb         = document.getElementById('authThumb');
  const authAlert     = document.getElementById('authAlert');
  const authAlertText = document.getElementById('authAlertText');

  function displayAlert(message, type = 'error') {
    authAlert.className = 'auth-alert auth-alert--' + type;
    authAlertText.textContent = message;
    authAlert.style.display = 'flex';
    authAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function hideAlert() {
    authAlert.style.display = 'none';
  }

  function showPanel(which) {
    const toRegister = which === 'register';
    tabSignIn.classList.toggle('is-active', !toRegister);
    tabRegister.classList.toggle('is-active', toRegister);
    tabSignIn.setAttribute('aria-selected', String(!toRegister));
    tabRegister.setAttribute('aria-selected', String(toRegister));
    panelSignIn.hidden = toRegister;
    panelRegister.hidden = !toRegister;
    panelSignIn.classList.toggle('is-active', !toRegister);
    panelRegister.classList.toggle('is-active', toRegister);
    thumb.classList.toggle('is-right', toRegister);
  }

  tabSignIn.addEventListener('click', () => showPanel('signin'));
  tabRegister.addEventListener('click', () => showPanel('register'));
  document.getElementById('goToRegister').addEventListener('click', (e) => { e.preventDefault(); showPanel('register'); });
  document.getElementById('goToSignIn').addEventListener('click', (e) => { e.preventDefault(); showPanel('signin'); });

  // Patient, Doctor, and Staff Registration Role Toggle
  const doctorFields       = document.getElementById('doctorFields');
  const staffFields        = document.getElementById('staffFields');
  const patientFields      = document.getElementById('patientFields');
  const regHospitalId      = document.getElementById('regHospitalId');
  const regLicense         = document.getElementById('regLicense');
  const regStaffHospitalId = document.getElementById('regStaffHospitalId');
  const regDepartment      = document.getElementById('regDepartment');
  const regDob             = document.getElementById('regDob');
  const regBloodGroup      = document.getElementById('regBloodGroup');

  function updateRegistrationTabs() {
    const selectedRole = document.querySelector('input[name="register_role"]:checked')?.value || 'Patient';
    const isDoc = selectedRole === 'Doctor';
    const isStaff = selectedRole === 'Staff';
    const isPatient = selectedRole === 'Patient';

    // Doctor section
    doctorFields.hidden = !isDoc;
    regHospitalId.disabled = !isDoc;
    regLicense.disabled = !isDoc;
    if (isDoc) {
      regHospitalId.setAttribute('required', 'required');
    } else {
      regHospitalId.removeAttribute('required');
    }

    // Staff section
    staffFields.hidden = !isStaff;
    regStaffHospitalId.disabled = !isStaff;
    regDepartment.disabled = !isStaff;
    if (isStaff) {
      regStaffHospitalId.setAttribute('required', 'required');
    } else {
      regStaffHospitalId.removeAttribute('required');
    }

    // Patient section
    patientFields.hidden = !isPatient;
    if (isPatient) {
      regDob.setAttribute('required', 'required');
      regBloodGroup.setAttribute('required', 'required');
    } else {
      regDob.removeAttribute('required');
      regBloodGroup.removeAttribute('required');
    }
  }

  document.querySelectorAll('input[name="register_role"]').forEach(radio => {
    radio.addEventListener('change', updateRegistrationTabs);
  });
  updateRegistrationTabs(); // Initialize on DOM load

  // Password Visibility Toggle
  document.querySelectorAll('.password-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.textContent = isHidden ? 'Hide' : 'Show';
    });
  });

  // Asynchronous Login Form Submission with Automated Role Resolution
  const signInForm = document.getElementById('signInForm');
  const btnSignIn = document.getElementById('btnSignIn');

  signInForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    hideAlert();

    const identifier = document.getElementById('signInId').value.trim();
    const password = document.getElementById('signInPw').value;

    if (!identifier || !password) {
      displayAlert('Please enter both your identifier (email or patient UID) and password.', 'error');
      return;
    }

    btnSignIn.classList.add('is-loading');
    btnSignIn.textContent = 'Authenticating...';

    const formData = new FormData(signInForm);
    formData.append('ajax', '1');

    try {
      const response = await fetch('backend/login_action.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });

      const contentType = response.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        const result = await response.json();
        if (result.status === 'success') {
          displayAlert(result.message || 'Authenticated successfully. Redirecting...', 'success');
          setTimeout(() => {
            window.location.href = result.redirect;
          }, 450);
          return;
        } else {
          displayAlert(result.message || 'Authentication failed. Please verify credentials.', 'error');
          btnSignIn.classList.remove('is-loading');
          btnSignIn.textContent = 'Sign In';
        }
      } else {
        // Non-JSON response: fallback to standard form submit
        signInForm.submit();
      }
    } catch (err) {
      console.warn('Asynchronous login attempt failed, falling back to standard POST:', err);
      signInForm.submit();
    }
  });

  // Asynchronous Registration Form Submission with Graceful Fallback
  const registerForm = document.getElementById('registerForm');
  const btnRegister = document.getElementById('btnRegister');

  registerForm.addEventListener('submit', async function(e) {
    e.preventDefault();
    hideAlert();

    const role = document.querySelector('input[name="register_role"]:checked')?.value || 'Patient';
    const name = document.getElementById('regName').value.trim();
    const email = document.getElementById('regEmail').value.trim();
    const phone = document.getElementById('regPhone').value.trim();
    const password = document.getElementById('regPw').value;

    if (!name || !email || !phone || !password) {
      displayAlert('Please fill in all mandatory fields.', 'error');
      return;
    }

    if (role === 'Patient') {
      const dob = regDob.value;
      const bloodGroup = regBloodGroup.value;
      if (!dob) {
        displayAlert('Date of birth is required for patient registration.', 'error');
        return;
      }
      if (!bloodGroup) {
        displayAlert('Please select a valid blood group.', 'error');
        return;
      }
    } else if (role === 'Doctor') {
      const hospitalId = regHospitalId.value;
      if (!hospitalId) {
        displayAlert('Please select your affiliated hospital facility.', 'error');
        return;
      }
    } else if (role === 'Staff') {
      const hospitalId = regStaffHospitalId.value;
      if (!hospitalId) {
        displayAlert('Please select your affiliated hospital facility.', 'error');
        return;
      }
    }

    btnRegister.classList.add('is-loading');
    btnRegister.textContent = 'Creating Account...';

    const formData = new FormData(registerForm);
    formData.append('ajax', '1');

    try {
      const response = await fetch('backend/register_action.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });

      const contentType = response.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        const result = await response.json();
        if (result.status === 'success') {
          displayAlert(result.message, 'success');
          setTimeout(() => {
            window.location.href = result.redirect;
          }, 800);
          return;
        } else {
          displayAlert(result.message || 'Registration failed. Please check your inputs.', 'error');
          btnRegister.classList.remove('is-loading');
          btnRegister.textContent = 'Create Account';
        }
      } else {
        registerForm.submit();
      }
    } catch (err) {
      console.warn('Asynchronous registration attempt failed, falling back to standard POST:', err);
      registerForm.submit();
    }
  });
</script>

</body>
</html>

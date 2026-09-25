const authCard = document.getElementById('authCard');
const alertBanner = document.getElementById('alertBanner');

window.addEventListener('DOMContentLoaded', () => {
  clearAllForms();

  const regPhoneInput = document.getElementById('regPhone');
  if (regPhoneInput) {
    regPhoneInput.addEventListener('input', (e) => {
      e.target.value = e.target.value.replace(/\D/g, '').slice(0, 11);
    });
  }

  const params = new URLSearchParams(window.location.search);
  const authError = params.get('auth_error') || params.get('error');
  const msg = params.get('msg');

  if (authError === 'unauthorized') {
    showAlert('Session expired or unauthorized access. Please sign in.', 'error');
  } else if (authError === 'pending_approval') {
    showAlert('Your account is currently under administrative verification. Please wait for official approval before accessing the clinical portal.', 'error', 7000);
  } else if (authError === 'account_suspended') {
    showAlert('Your account has been suspended by hospital administration. Please contact hospital HR.', 'error', 7000);
  } else if (authError === 'account_declined') {
    showAlert('Your registration credentials were rejected by the hospital administration. Contact hospital admin.', 'error', 7000);
  } else if (authError === 'account_inactive') {
    showAlert('Your account is currently inactive. Please contact administration.', 'error');
  } else if (authError === 'invalid_credentials') {
    showAlert('Invalid credentials. Please verify your email/phone and password.', 'error');
  } else if (authError === 'role_mismatch') {
    showAlert('Role restriction: Please switch to the appropriate tab to log in.', 'error');
  }

  if (msg === 'logged_out') {
    showAlert('You have been securely logged out.', 'success');
  } else if (msg === 'pending_verification') {
    showAlert('Registration successful. Your medical credentials (BMDC) have been submitted to the Admin Treasury & Credentialing Board for verification. You will gain portal access once approved.', 'success', 8000);
  } else if (msg === 'registered') {
    showAlert('Registration successful! Please sign in with your credentials.', 'success');
  }

  // Password visibility toggle handler
  const toggleButtons = document.querySelectorAll('.toggle-password-btn');
  toggleButtons.forEach(button => {
    if (!button.hasAttribute('onclick')) {
      button.addEventListener('click', (e) => {
        e.preventDefault();
        togglePassword(button);
      });
    }
  });
});

function togglePassword(btn) {
  if (!btn) return;
  const now = Date.now();
  if (btn._lastToggleTime && (now - btn._lastToggleTime < 250)) {
    return;
  }
  btn._lastToggleTime = now;

  const wrapper = btn.closest('.password-input-group, .password-field-wrapper, .field-box');
  if (!wrapper) return;
  const input = wrapper.querySelector('.password-input') || wrapper.querySelector('input');
  const icon = btn.querySelector('.eye-icon') || btn.querySelector('i') || btn.querySelector('svg');
  if (!input) return;

  const isPassword = (input.getAttribute('type') === 'password') || (input.type === 'password');
  const newType = isPassword ? 'text' : 'password';
  input.setAttribute('type', newType);
  input.type = newType;

  if (icon) {
    if (isPassword) {
      icon.classList.remove('fa-eye');
      icon.classList.add('fa-eye-slash');
      if (icon.hasAttribute('data-icon')) icon.setAttribute('data-icon', 'eye-slash');
      btn.setAttribute('aria-label', 'Hide password');
    } else {
      icon.classList.remove('fa-eye-slash');
      icon.classList.add('fa-eye');
      if (icon.hasAttribute('data-icon')) icon.setAttribute('data-icon', 'eye');
      btn.setAttribute('aria-label', 'Show password');
    }
  }
}

function clearAllForms() {
  const forms = document.querySelectorAll('form');
  forms.forEach(form => form.reset());
  checkStrength('');
  document.querySelectorAll('.password-input-group, .password-field-wrapper').forEach(wrapper => {
    const input = wrapper.querySelector('.password-input') || wrapper.querySelector('input');
    const icon = wrapper.querySelector('.toggle-password-btn .eye-icon, .toggle-password-btn i');
    const btn = wrapper.querySelector('.toggle-password-btn');
    if (input) {
      input.setAttribute('type', 'password');
      input.type = 'password';
    }
    if (icon) {
      icon.classList.add('fa-eye');
      icon.classList.remove('fa-eye-slash');
      if (icon.hasAttribute('data-icon')) icon.setAttribute('data-icon', 'eye');
    }
    if (btn) {
      btn.setAttribute('aria-label', 'Show password');
    }
  });
}

function toggleDesktopSlider(openSignup) {
  clearAllForms();
  if (openSignup) {
    authCard.classList.add('active-signup');
  } else {
    authCard.classList.remove('active-signup');
  }
}

function mobileToggle(view) {
  clearAllForms();
  authCard.classList.remove("active-signup");
  const signin = document.getElementById('signInSection');
  const signup = document.getElementById('signUpSection');
  const tabIn = document.getElementById('mTabLogin');
  const tabUp = document.getElementById('mTabRegister');

  if (view === 'register') {
    signin.className = 'form-pane signin-pane m-hidden';
    signup.className = 'form-pane signup-pane m-show';
    tabUp.classList.add('active');
    tabIn.classList.remove('active');
  } else {
    signup.className = 'form-pane signup-pane m-hidden';
    signin.className = 'form-pane signin-pane m-show';
    tabIn.classList.add('active');
    tabUp.classList.remove('active');
  }
}

function checkStrength(pass) {
  const bar = document.getElementById('strengthBar');
  if (!bar) return;

  let score = 0;
  const hasLen = pass.length >= 8 && pass.length <= 32;
  const hasUpper = /[A-Z]/.test(pass);
  const hasNum = /\d/.test(pass);
  const hasSym = /[@$!%*?&#]/.test(pass);

  updateItem('crit-len', hasLen);
  updateItem('crit-upper', hasUpper);
  updateItem('crit-num', hasNum);
  updateItem('crit-sym', hasSym);

  if (hasLen) score += 25;
  if (hasUpper) score += 25;
  if (hasNum) score += 25;
  if (hasSym) score += 25;

  bar.style.width = score + '%';
  if (score <= 25) bar.style.backgroundColor = '#ef4444';
  else if (score <= 50) bar.style.backgroundColor = '#f97316';
  else if (score <= 75) bar.style.backgroundColor = '#eab308';
  else bar.style.backgroundColor = '#10b981';
}

function updateItem(id, condition) {
  const el = document.getElementById(id);
  if (!el) return;
  if (condition) {
    el.classList.add('met');
    el.querySelector('i').className = 'fa-solid fa-circle-check';
  } else {
    el.classList.remove('met');
    el.querySelector('i').className = 'fa-solid fa-circle';
  }
}

async function handleAuthSubmit(e, endpoint) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);

  if (endpoint.includes('login_action.php')) {
    const ident = (formData.get('identifier') || formData.get('email') || '').trim();
    formData.set('identifier', ident);
    formData.set('email', ident);

    const selectedTab = formData.get('selected_tab') || formData.get('login_role') || 'Patient';
    formData.set('selected_tab', selectedTab);
    formData.set('login_role', selectedTab);
  }

  if (endpoint.includes('register_action.php') || endpoint.includes('register.php')) {
    const role = formData.get('register_role') || 'Patient';
    if (role === 'Patient') {
      const dob = (formData.get('dob') || '').trim();
      if (!dob) {
        showAlert('Please select your date of birth.', 'error');
        return;
      }
      const bg = (formData.get('blood_group') || '').trim();
      if (!bg) {
        showAlert('Please select your blood group.', 'error');
        return;
      }
    }

    const email = (formData.get('email') || '').trim();
    const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
    if (!emailPattern.test(email)) {
      showAlert('Please enter a valid email address (e.g. user@example.com).', 'error');
      return;
    }

    let phone = (formData.get('phone') || '').trim().replace(/[\s\-\(\)\+]/g, '');
    if (phone.startsWith('8801')) {
      phone = phone.substring(2);
    }
    const bdPhonePattern = /^01[3-9]\d{8}$/;
    if (!bdPhonePattern.test(phone)) {
      showAlert('Invalid mobile number. Please provide a valid 11-digit Bangladeshi number (e.g., 017XXXXXXXX).', 'error');
      return;
    }
    formData.set('phone', phone);

    const gender = formData.get('gender');
    if (!gender || !['Male', 'Female', 'Other'].includes(gender)) {
      showAlert('Please select a valid gender option.', 'error');
      return;
    }

    const pass = formData.get('password') || '';
    const pattern = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]{8,32}$/;
    if (!pattern.test(pass)) {
      showAlert('Password must fulfill all four security requirements.', 'error');
      return;
    }
  }

  try {
    const res = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await res.json();

    if (data.status === 'success') {
      showAlert(data.message, 'success');
      if (data.redirect) {
        setTimeout(() => {
          let target = data.redirect;
          if (target.startsWith('../')) {
            target = target.substring(3);
          }
          window.location.href = target;
        }, 1200);
      } else {
        clearAllForms();
        setTimeout(() => {
          toggleDesktopSlider(false);
          mobileToggle('login');
        }, 1500);
      }
    } else {
      showAlert(data.message, 'error');
    }
  } catch (err) {
    showAlert('Server communication failed. Check Apache & MySQL.', 'error');
  }
}

let alertTimer = null;
function showAlert(msg, type, duration = 4500) {
  if (!alertBanner) return;
  if (alertTimer) clearTimeout(alertTimer);
  alertBanner.innerText = msg;
  alertBanner.className = type === 'success' ? 'alert-success' : 'alert-error';
  alertBanner.style.display = 'block';
  alertTimer = setTimeout(() => {
    alertBanner.style.display = 'none';
  }, duration);
}

function toggleDoctorFields(role) {
  const docFields = document.getElementById('doctorExtraFields');
  if (docFields) {
    docFields.style.display = (role === 'Doctor') ? 'block' : 'none';
  }

  // DOB and Blood Group are Patient-only registration fields
  const patientDobBgRow = document.querySelector('.field-grid-patient');
  if (patientDobBgRow) {
    patientDobBgRow.style.display = (role === 'Patient') ? 'grid' : 'none';
    // Toggle required attribute to avoid HTML5 validation errors on hidden fields
    const dobInput = document.getElementById('regDob');
    const bgSelect = document.getElementById('regBloodGroup');
    if (dobInput) dobInput.required = (role === 'Patient');
    if (bgSelect) bgSelect.required = (role === 'Patient');
  }

  // Always scroll the signup pane back to top when switching tabs,
  // so the MedPulse logo header is fully visible regardless of tab content height.
  const signupPane = document.getElementById('signUpSection');
  if (signupPane) {
    signupPane.scrollTop = 0;
  }
}

// Auto-open register tab if requested via query parameter
document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('tab') === 'register' || urlParams.get('action') === 'register') {
    if (window.innerWidth <= 840) {
      mobileToggle('register');
    } else {
      toggleDesktopSlider(true);
    }
  }
});
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
    showAlert('Your account is awaiting administrative approval. Our hospital authority will review your medical credentials before granting portal access.', 'error', 6000);
  } else if (authError === 'account_suspended') {
    showAlert('Your account has been suspended by hospital administration. Please contact hospital HR.', 'error', 6000);
  } else if (authError === 'account_declined') {
    showAlert('Your account request has been declined. Please contact administration.', 'error', 6000);
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
    showAlert('Registration received! Your account is awaiting administrative approval before activation.', 'success', 6000);
  } else if (msg === 'registered') {
    showAlert('Registration successful! Please sign in with your credentials.', 'success');
  }

  // Password toggles are handled via delegated event listener below
});

let lastToggleTime = 0;
function togglePassword(btn) {
  if (!btn) return;

  // Prevent double-invocation flip-flops within 100ms
  const now = Date.now();
  if (now - lastToggleTime < 100) return;
  lastToggleTime = now;

  const wrapper = btn.closest('.password-input-group, .password-field-wrapper, .field-box');
  if (!wrapper) return;

  const input = wrapper.querySelector('.password-input') || wrapper.querySelector('input[type="password"], input[type="text"]');
  const icon = btn.querySelector('.eye-icon, i, svg');
  if (!input) return;

  const isPassword = input.getAttribute('type') === 'password' || input.type === 'password';
  const newType = isPassword ? 'text' : 'password';

  input.setAttribute('type', newType);
  input.type = newType;

  if (icon) {
    if (isPassword) {
      icon.classList.remove('fa-eye');
      icon.classList.add('fa-eye-slash');
    } else {
      icon.classList.remove('fa-eye-slash');
      icon.classList.add('fa-eye');
    }
  }

  btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
}

window.togglePassword = togglePassword;

// Event delegation for all password visibility toggle buttons
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.toggle-password-btn');
  if (!btn) return;

  e.preventDefault();
  e.stopPropagation();
  togglePassword(btn);
});

function clearAllForms() {
  const forms = document.querySelectorAll('form');
  forms.forEach(form => form.reset());
  checkStrength('');
  document.querySelectorAll('.password-input-group, .password-field-wrapper, .field-box').forEach(wrapper => {
    const input = wrapper.querySelector('.password-input') || wrapper.querySelector('input[type="password"], input[type="text"]');
    const icon = wrapper.querySelector('.eye-icon, i, svg');
    const btn = wrapper.querySelector('.toggle-password-btn');
    if (input) {
      input.setAttribute('type', 'password');
      input.type = 'password';
    }
    if (icon) {
      icon.classList.add('fa-eye');
      icon.classList.remove('fa-eye-slash');
    }
    if (btn) {
      btn.setAttribute('aria-label', 'Toggle password visibility');
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

  if (endpoint.includes('register_action.php')) {
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
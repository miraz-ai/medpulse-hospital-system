/**
 * MedPulse Admin – Live Bed & Census View Script
 * Handles: bed lifecycle actions (allocate, discharge, mark-ready), AJAX
 * POST to the PHP action handler, metric chip refresh, modal/dialog UI,
 * census toast notifications, and keyboard/backdrop dismiss.
 *
 * No PHP data embedded — all dynamic values read from data-* attributes
 * or DOM state. CSRF token read from <meta name="csrf-token">.
 */
(function () {
  'use strict';

  const PAGE_URL = window.location.pathname;
  const CSRF    = document.querySelector('meta[name="csrf-token"]')?.content || '';

  // ------------------------------------------------------------------
  // Census Toast Notification System
  // ------------------------------------------------------------------
  window.censusToast = function (msg, type = 'success') {
    const container = document.getElementById('censusToastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `census-toast census-toast-${type}`;

    const icons = {
      success : '<svg viewBox="0 0 24 24" width="16" height="16"><polyline points="20 6 9 17 4 12" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></polyline></svg>',
      error   : '<svg viewBox="0 0 24 24" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"></line><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" fill="none" stroke-width="2.5" stroke-linecap="round"></line></svg>',
      warning : '<svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10" stroke="currentColor" fill="none" stroke-width="2"></circle><line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line><line x1="12" y1="16" x2="12.01" y2="16" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"></line></svg>',
      info    : '<svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10" stroke="currentColor" fill="none" stroke-width="2"></circle><line x1="12" y1="16" x2="12" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"></line><line x1="12" y1="8" x2="12.01" y2="8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"></line></svg>',
    };

    toast.innerHTML = `<span class="census-toast-icon">${icons[type] || icons.info}</span><span class="census-toast-msg">${msg}</span>`;
    container.appendChild(toast);

    // Auto-dismiss
    setTimeout(() => toast.classList.add('census-toast-out'), 3800);
    setTimeout(() => toast.remove(), 4400);
  };

  // ------------------------------------------------------------------
  // Metric Chip Refresh — pulls live stats and updates DOM values
  // ------------------------------------------------------------------
  async function refreshMetricChips() {
    try {
      const res = await fetch(`${PAGE_URL}?_action=get_stats`, { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) return;
      const s = data.stats;
      // Update the four .census-card-value elements in order: total, occupied, available, icu%
      const vals = document.querySelectorAll('.census-card-value');
      if (vals[0]) vals[0].textContent = Number(s.total_beds).toLocaleString();
      if (vals[1]) vals[1].textContent = Number(s.occupied_beds).toLocaleString();
      if (vals[2]) vals[2].textContent = Number(s.available_beds).toLocaleString();
      // Update sanitizing counter if present
      const sanitEl = document.getElementById('sanitizingCounter');
      if (sanitEl && s.sanitizing_beds !== undefined) {
        sanitEl.textContent = Number(s.sanitizing_beds).toLocaleString();
      }
      const queueBadge = document.getElementById('queueCountBadge');
      if (queueBadge && s.sanitizing_beds !== undefined) {
        queueBadge.textContent = `${Number(s.sanitizing_beds).toLocaleString()} Pending Decontamination`;
      }
      // vals[3] = ICU% — server doesn't return it in get_stats (complex), skip live update

      // Update dynamic clinical telemetry pill
      if (data.telemetry) {
        const t = data.telemetry;
        const pill = document.querySelector('.telemetry-pill');
        if (pill) {
          pill.className = `ecg-pulse-monitor telemetry-pill ${t.class}`;
          pill.title = `Real-time clinical telemetry: ${t.label} (${t.bpm})`;
          const lbl = pill.querySelector('.ecg-label');
          if (lbl) {
            lbl.innerHTML = `<span class="ecg-bpm-dot"></span>${escHtml(t.bpm)} &bull; ${escHtml(t.label)} &bull; ${escHtml(String(t.active_beds))} BEDS ACTIVE`;
          }
        }
      }
    } catch (_) { /* silent — chip values remain from last render */ }
  }

  // ------------------------------------------------------------------
  // Dropdown cache (single fetch per page load)
  // ------------------------------------------------------------------
  let _dropdownCache = null;
  async function getDropdowns() {
    if (_dropdownCache) return _dropdownCache;
    const res  = await fetch(`${PAGE_URL}?_action=get_dropdowns`, { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Failed to load dropdowns.');
    _dropdownCache = data;
    return data;
  }

  // ------------------------------------------------------------------
  // ALLOCATE MODAL
  // ------------------------------------------------------------------
  window.openAllocateModal = async function (bedId, bedNumber) {
    const overlay = document.getElementById('allocateModalOverlay');
    document.getElementById('allocBedId').value      = bedId;
    document.getElementById('allocBedDisplay').value = bedNumber;
    document.getElementById('allocNotes').value      = '';
    overlay.style.display = 'flex';
    overlay.offsetHeight; // reflow for animation
    overlay.classList.add('census-modal-visible');
    document.body.style.overflow = 'hidden';

    // Load dropdowns
    const patSel = document.getElementById('allocPatientId');
    const docSel = document.getElementById('allocDoctorId');
    patSel.innerHTML = '<option value="">— Loading patients… —</option>';
    docSel.innerHTML = '<option value="">— Loading physicians… —</option>';

    try {
      const { patients, doctors } = await getDropdowns();

      patSel.innerHTML = '<option value="">— Select patient —</option>' +
        patients.map(p => `<option value="${p.user_id}">${escHtml(p.full_name)}</option>`).join('');

      docSel.innerHTML = '<option value="">— Select physician (optional) —</option>' +
        doctors.map(d => `<option value="${d.user_id}">${escHtml(d.full_name)}</option>`).join('');
    } catch (err) {
      censusToast('Could not load patient/doctor list: ' + err.message, 'error');
      patSel.innerHTML = '<option value="">— Error loading data —</option>';
      docSel.innerHTML = '<option value="">— Error loading data —</option>';
    }
  };

  window.closeAllocateModal = function () {
    const overlay = document.getElementById('allocateModalOverlay');
    overlay.classList.remove('census-modal-visible');
    setTimeout(() => { overlay.style.display = 'none'; }, 250);
    document.body.style.overflow = '';
  };

  window.submitAllocation = async function (e) {
    e.preventDefault();
    const btn  = document.getElementById('allocSubmitBtn');
    const form = document.getElementById('allocateForm');
    const data = new FormData(form);

    if (!data.get('patient_id')) {
      censusToast('Please select a patient before confirming.', 'warning');
      return;
    }

    btn.disabled    = true;
    btn.textContent = 'Allocating…';

    try {
      const res  = await fetch(PAGE_URL, { method: 'POST', body: data, credentials: 'same-origin' });
      const resp = await res.json();
      closeAllocateModal();
      if (resp.success) {
        censusToast(resp.message, 'success');
        refreshMetricChips();
        setTimeout(() => window.location.reload(), 2800);
      } else {
        censusToast(resp.message || 'Allocation failed.', 'error');
      }
    } catch (err) {
      censusToast('Network error during allocation. Please retry.', 'error');
    } finally {
      btn.disabled    = false;
      btn.textContent = 'Confirm Allocation';
    }
  };

  // ------------------------------------------------------------------
  // DISCHARGE CONFIRM DIALOG
  // ------------------------------------------------------------------
  window.openDischargeConfirm = function (bedId, bedNumber, patientName) {
    document.getElementById('dischargeBedId').value = bedId;
    document.getElementById('dischargePatientLabel').textContent =
      `Bed ${bedNumber} — ${patientName || 'Current Inpatient'}`;
    const overlay = document.getElementById('dischargeModalOverlay');
    overlay.style.display = 'flex';
    overlay.offsetHeight;
    overlay.classList.add('census-modal-visible');
    document.body.style.overflow = 'hidden';
  };

  window.closeDischargeModal = function () {
    const overlay = document.getElementById('dischargeModalOverlay');
    overlay.classList.remove('census-modal-visible');
    setTimeout(() => { overlay.style.display = 'none'; }, 250);
    document.body.style.overflow = '';
  };

  window.submitDischarge = async function () {
    const bedId = document.getElementById('dischargeBedId').value;
    const btn   = document.getElementById('dischargeConfirmBtn');
    btn.disabled    = true;
    btn.textContent = 'Processing…';

    const fd = new FormData();
    fd.append('_action',    'discharge_patient');
    fd.append('bed_id',     bedId);
    fd.append('csrf_token', CSRF);

    try {
      const res  = await fetch(PAGE_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const resp = await res.json();
      closeDischargeModal();
      if (resp.success) {
        censusToast(resp.message, 'success');
        refreshMetricChips();
        setTimeout(() => window.location.reload(), 2800);
      } else {
        censusToast(resp.message || 'Discharge failed.', 'error');
      }
    } catch (err) {
      censusToast('Network error during discharge. Please retry.', 'error');
    } finally {
      btn.disabled    = false;
      btn.textContent = 'Confirm Discharge';
    }
  };

  // ------------------------------------------------------------------
  // MARK BED READY (Sanitized & Available)
  // ------------------------------------------------------------------
  window.markBedReady = async function (btn, bedId, bedNumber) {
    btn.disabled    = true;
    const oldText   = btn.textContent;
    btn.textContent = 'Clearing…';

    const fd = new FormData();
    fd.append('_action',    'mark_bed_ready');
    fd.append('bed_id',     bedId);
    fd.append('csrf_token', CSRF);

    try {
      const res  = await fetch(PAGE_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const resp = await res.json();
      if (resp.success) {
        censusToast(resp.message, 'success');
        refreshMetricChips();
        setTimeout(() => window.location.reload(), 900);
      } else {
        censusToast(resp.message || 'Mark Ready failed.', 'error');
        btn.disabled    = false;
        btn.textContent = oldText;
      }
    } catch (err) {
      censusToast('Network error. Please retry.', 'error');
      btn.disabled    = false;
      btn.textContent = oldText;
    }
  };

  // ------------------------------------------------------------------
  // BATCH SANITIZE BEDS (Housekeeping Batch Action)
  // ------------------------------------------------------------------
  window.batchSanitizeBeds = async function () {
    const btn = document.getElementById('btnBatchSanitize');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Clearing Sanitization Queue…';
    }

    const fd = new FormData();
    fd.append('_action',    'batch_sanitize');
    fd.append('csrf_token', CSRF);

    try {
      const res  = await fetch(PAGE_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const resp = await res.json();
      if (resp.success) {
        censusToast(resp.message, 'success');
        refreshMetricChips();
        setTimeout(() => window.location.reload(), 1000);
      } else {
        censusToast(resp.message || 'Batch sanitization failed.', 'error');
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Retry Batch Sanitization';
        }
      }
    } catch (err) {
      censusToast('Network error during batch sanitization. Please retry.', 'error');
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Retry Batch Sanitization';
      }
    }
  };

  // ------------------------------------------------------------------
  // Escape HTML helper
  // ------------------------------------------------------------------
  function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ------------------------------------------------------------------
  // Interactive Care Team Popover Mini-Cards
  // ------------------------------------------------------------------
  window.closeAllCareTeamPopovers = function() {
    document.querySelectorAll('.care-team-popover-card').forEach(card => {
      card.style.display = 'none';
      card.classList.remove('is-open');
    });
    document.querySelectorAll('.btn-care-team-popover').forEach(btn => {
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('is-active');
    });
    document.querySelectorAll('.bed-slot-card.has-active-popover').forEach(card => {
      card.classList.remove('has-active-popover');
    });
  };

  window.toggleCareTeamPopover = function(e, btn) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    if (!btn) return;
    const wrapper = btn.closest('.care-team-popover-wrapper');
    if (!wrapper) return;
    const popover = wrapper.querySelector('.care-team-popover-card');
    if (!popover) return;
    const bedCard = wrapper.closest('.bed-slot-card');

    const isCurrentlyOpen = (popover.classList.contains('is-open') || popover.style.display === 'block');

    // Close all other popovers first
    window.closeAllCareTeamPopovers();

    if (!isCurrentlyOpen) {
      popover.style.display = 'block';
      popover.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('is-active');
      if (bedCard) {
        bedCard.classList.add('has-active-popover');
      }
    }
  };

  function initCareTeamPopovers() {
    // Delegated click on document
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.btn-care-team-popover');
      if (btn) {
        window.toggleCareTeamPopover(e, btn);
        return;
      }
      if (e.target.closest('.care-team-popover-card')) {
        return; // Clicked inside popover card
      }
      window.closeAllCareTeamPopovers();
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        window.closeAllCareTeamPopovers();
      }
    });
  }

  // Run popover init immediately if DOM already loaded or on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCareTeamPopovers);
  } else {
    initCareTeamPopovers();
  }

  // ------------------------------------------------------------------
  // Backdrop click to close modals
  // ------------------------------------------------------------------
  function initModalsBackdrop() {
    document.getElementById('allocateModalOverlay')?.addEventListener('click', function (e) {
      if (e.target === this) window.closeAllocateModal();
    });
    document.getElementById('dischargeModalOverlay')?.addEventListener('click', function (e) {
      if (e.target === this) window.closeDischargeModal();
    });

    // Keyboard ESC to close
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        window.closeAllocateModal();
        window.closeDischargeModal();
        window.closeAllCareTeamPopovers();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initModalsBackdrop);
  } else {
    initModalsBackdrop();
  }

}());

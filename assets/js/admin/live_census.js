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
      // vals[3] = ICU% — server doesn't return it in get_stats (complex), skip live update
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
  // MARK BED READY
  // ------------------------------------------------------------------
  window.markBedReady = async function (btn, bedId, bedNumber) {
    btn.disabled    = true;
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
        setTimeout(() => window.location.reload(), 2800);
      } else {
        censusToast(resp.message || 'Mark Ready failed.', 'error');
        btn.disabled    = false;
        btn.textContent = 'Mark Ready';
      }
    } catch (err) {
      censusToast('Network error. Please retry.', 'error');
      btn.disabled    = false;
      btn.textContent = 'Mark Ready';
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
  function closeAllCareTeamPopovers() {
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
  }

  function initCareTeamPopovers() {
    document.querySelectorAll('.care-team-popover-wrapper').forEach(wrapper => {
      const btn = wrapper.querySelector('.btn-care-team-popover');
      const popover = wrapper.querySelector('.care-team-popover-card');
      const bedCard = wrapper.closest('.bed-slot-card');

      if (!btn || !popover) return;

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const isCurrentlyOpen = (popover.style.display === 'block' || popover.classList.contains('is-open'));

        // Close all popovers first
        closeAllCareTeamPopovers();

        if (!isCurrentlyOpen) {
          popover.style.display = 'block';
          popover.classList.add('is-open');
          btn.setAttribute('aria-expanded', 'true');
          btn.classList.add('is-active');
          if (bedCard) bedCard.classList.add('has-active-popover');
        }
      });

      // Prevent clicks inside the popover from bubbling up to document and closing it
      popover.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    });

    // Close on click outside
    document.addEventListener('click', function () {
      closeAllCareTeamPopovers();
    });
  }

  // ------------------------------------------------------------------
  // Backdrop click to close modals
  // ------------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', () => {
    initCareTeamPopovers();

    document.getElementById('allocateModalOverlay')?.addEventListener('click', function (e) {
      if (e.target === this) window.closeAllocateModal();
    });
    document.getElementById('dischargeModalOverlay')?.addEventListener('click', function (e) {
      if (e.target === this) window.closeDischargeModal();
    });

    // Keyboard ESC to close
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeAllocateModal();
        closeDischargeModal();
        closeAllCareTeamPopovers();
      }
    });
  });

}());

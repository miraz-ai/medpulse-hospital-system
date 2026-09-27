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
            const sub = t.sublabel ? ` &bull; ${escHtml(t.sublabel)}` : (t.active_beds ? ` &bull; ${escHtml(String(t.active_beds))} BEDS ACTIVE` : '');
            lbl.innerHTML = `<span class="ecg-bpm-dot"></span>${escHtml(t.bpm)} &bull; ${escHtml(t.label)}${sub}`;
          }
        }
      }

      // Update Beds in Surge Hold card dynamically
      const surgeCard = document.getElementById('surgeHoldMetricCard');
      const surgeCounter = document.getElementById('surgeHoldCounter');
      const surgeBadge = document.getElementById('surgeHoldCardBadge');
      if (surgeCard && s.emergency_hold_beds !== undefined) {
        const isSurgeOn = !!(data.disaster_mode || (data.telemetry && data.telemetry.disaster_active));
        if (surgeCounter) {
          surgeCounter.textContent = isSurgeOn ? Number(s.emergency_hold_beds).toLocaleString() : '0';
        }
        if (isSurgeOn) {
          surgeCard.style.border = '1.5px solid rgba(244, 63, 94, 0.5)';
          surgeCard.style.background = 'linear-gradient(135deg, rgba(255, 241, 242, 0.6) 0%, #fff 100%)';
          surgeCard.style.cursor = 'pointer';
          surgeCard.onclick = window.toggleSurgeHoldFilter;
          const cardVal = document.getElementById('surgeHoldCardValue');
          if (cardVal) cardVal.style.color = '#e11d48';
          if (surgeBadge) {
            surgeBadge.style.background = '#ffe4e6';
            surgeBadge.style.color = '#9f1239';
            surgeBadge.innerHTML = '<span>⚡ Click to isolate surge beds</span>';
          }
        } else {
          surgeCard.style.border = '1.5px solid #e2e8f0';
          surgeCard.style.background = '#ffffff';
          surgeCard.style.cursor = 'default';
          surgeCard.onclick = null;
          const cardVal = document.getElementById('surgeHoldCardValue');
          if (cardVal) cardVal.style.color = '#334155';
          if (surgeBadge) {
            surgeBadge.style.background = '#f1f5f9';
            surgeBadge.style.color = '#64748b';
            surgeBadge.innerHTML = '<span>Normal Operations &bull; All Units Stable</span>';
          }
        }
      }

      // Update Carousel Visibility if present
      const carousel = document.getElementById('branchDisasterCarousel');
      if (carousel) {
        const isSurgeOn = !!(data.disaster_mode || (data.telemetry && data.telemetry.disaster_active));
        carousel.style.display = isSurgeOn ? '' : 'none';
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
  // DISCHARGE CONFIRM DIALOG & DYNAMIC DOM STATE MACHINE
  // ------------------------------------------------------------------
  window.openDischargeConfirm = async function (bedId, bedNumber, patientName) {
    if (window.MedPulseDialog && typeof window.MedPulseDialog.confirm === 'function') {
      const confirmed = await window.MedPulseDialog.confirm({
        title: 'Confirm Inpatient Discharge',
        message: `Are you sure you want to discharge ${patientName ? patientName : 'the patient'} from Bed ${bedNumber}? This will finalize admission records and immediately release the bed back to AVAILABLE.`,
        confirmText: 'Confirm Discharge',
        cancelText: 'Keep Admitted',
        type: 'warning'
      });
      if (confirmed) {
        await executeDischarge(bedId, bedNumber);
      }
      return;
    }

    // Fallback custom UI modal overlay
    const bedIdInput = document.getElementById('dischargeBedId');
    if (bedIdInput) bedIdInput.value = bedId;
    const patientLbl = document.getElementById('dischargePatientLabel');
    if (patientLbl) {
      patientLbl.textContent = `Bed ${bedNumber} — ${patientName || 'Current Inpatient'}`;
    }
    const overlay = document.getElementById('dischargeModalOverlay');
    if (overlay) {
      overlay.setAttribute('data-bed-number', bedNumber || '');
      overlay.style.display = 'flex';
      overlay.offsetHeight;
      overlay.classList.add('census-modal-visible');
      document.body.style.overflow = 'hidden';
    }
  };

  window.closeDischargeModal = function () {
    const overlay = document.getElementById('dischargeModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('census-modal-visible');
    setTimeout(() => { overlay.style.display = 'none'; }, 250);
    document.body.style.overflow = '';
  };

  window.submitDischarge = async function () {
    const bedId = document.getElementById('dischargeBedId') ? document.getElementById('dischargeBedId').value : null;
    const overlay = document.getElementById('dischargeModalOverlay');
    const bedNumber = overlay ? overlay.getAttribute('data-bed-number') : '';
    const btn = document.getElementById('dischargeConfirmBtn');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Processing…';
    }

    try {
      await executeDischarge(bedId, bedNumber);
      closeDischargeModal();
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Confirm Discharge';
      }
    }
  };

  async function executeDischarge(bedId, bedNumber) {
    const fd = new FormData();
    fd.append('_action',    'discharge_patient');
    fd.append('bed_id',     bedId);
    fd.append('csrf_token', CSRF);

    try {
      const res  = await fetch(PAGE_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
      const resp = await res.json();
      if (resp.success) {
        censusToast(resp.message, 'success');
        updateBedCardToAvailable(bedId, bedNumber, resp);
        refreshMetricChips();
      } else {
        censusToast(resp.message || 'Discharge failed.', 'error');
      }
    } catch (err) {
      censusToast('Network error during discharge. Please retry.', 'error');
    }
  }

  function updateBedCardToAvailable(bedId, bedNumber, resp) {
    const card = document.getElementById('bed-card-' + bedId) 
              || document.querySelector(`.bed-slot-card[data-bed-id="${bedId}"]`);
    if (!card) return;

    // 1. Flip card status styling classes
    card.classList.remove('slot-occupied', 'slot-maintenance', 'slot-sanitizing', 'slot-emergency-hold');
    card.classList.add('slot-available');
    card.setAttribute('data-status', 'available');

    const isPresidential = card.getAttribute('data-is-presidential') === '1' || card.classList.contains('slot-presidential');
    const bedNum = bedNumber || card.getAttribute('data-bed-number') || (resp && resp.bed_number) || ('Bed #' + bedId);
    const dailyRate = (resp && resp.daily_rate) || card.getAttribute('data-daily-rate') || '1200';
    const floor = (resp && resp.floor_number) || card.getAttribute('data-floor') || '1';

    // 2. Flip status badge from 'OCCUPIED' to 'AVAILABLE'
    const statusPill = card.querySelector('.bed-status-pill');
    if (statusPill) {
      statusPill.className = `bed-status-pill status-available-pill ${isPresidential ? 'badge-presidential' : ''}`;
      statusPill.textContent = isPresidential ? 'VIP Suite Ready' : 'Available';
      statusPill.style.cssText = '';
    }

    // 3. Clear assigned patient identifier / physician details and present available bed state
    const cardBody = card.querySelector('.bed-card-body');
    if (cardBody) {
      cardBody.innerHTML = `
        <div class="bed-vacant-msg">
          <svg class="ui-ico ui-ico-sm" style="stroke: #16a34a; width: 16px; height: 16px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          <span>Ready for immediate placement</span>
        </div>
        <div style="font-size: 0.74rem; color: #166534; opacity: 0.85; display: flex; justify-content: space-between;">
          <span>Daily Rate: ৳ ${Number(dailyRate).toLocaleString()}</span>
          <span>Floor ${escHtml(String(floor))}</span>
        </div>
      `;
    }

    // 4. Update card action button to "Allocate Patient"
    const actionsBar = card.querySelector('.bed-actions-bar');
    if (actionsBar) {
      actionsBar.innerHTML = `
        <button 
          type="button" 
          class="btn-bed-action btn-bed-primary"
          onclick="openAllocateModal(${Number(bedId)}, '${escHtml(bedNum)}')"
        >
          <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Allocate Patient
        </button>
      `;
    }

    // 5. Subtle glow highlight transition
    card.style.transition = 'box-shadow 0.4s ease, border-color 0.4s ease';
    card.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.4)';
    card.style.borderColor = '#10b981';
    setTimeout(() => {
      card.style.boxShadow = '';
      card.style.borderColor = '';
    }, 1800);
  }

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

  // ------------------------------------------------------------------
  // Live Housekeeping Elapsed Cleaning Timers
  // ------------------------------------------------------------------
  function updateElapsedCleaningTimers() {
    const timerEls = document.querySelectorAll('.elapsed-cleaning-timer');
    if (!timerEls.length) return;
    const now = Math.floor(Date.now() / 1000);

    timerEls.forEach(el => {
      const ts = parseInt(el.getAttribute('data-timestamp'), 10);
      if (!ts) return;
      const diff = Math.max(0, now - ts);
      const m = Math.floor(diff / 60);
      const s = diff % 60;
      const h = Math.floor(m / 60);
      const remM = m % 60;

      const textEl = el.querySelector('.timer-display') || el;
      if (h > 0) {
        textEl.textContent = `${h}h ${remM}m ${s < 10 ? '0' : ''}${s}s`;
      } else {
        textEl.textContent = `${m}m ${s < 10 ? '0' : ''}${s}s`;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      initModalsBackdrop();
      updateElapsedCleaningTimers();
    });
  } else {
    initModalsBackdrop();
    updateElapsedCleaningTimers();
  }

  setInterval(updateElapsedCleaningTimers, 1000);

}());

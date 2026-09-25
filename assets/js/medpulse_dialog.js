/**
 * MedPulse Enterprise Hospital Management System
 * MedPulse Modern Dialog & Toast Engine JS
 * Replaces native window.alert() and window.confirm() with enterprise-grade UI modals & toasts.
 */

window.MedPulseDialog = (function() {
  'use strict';

  // Ensure toast container exists
  function getToastContainer() {
    let container = document.getElementById('mpToastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'mpToastContainer';
      container.className = 'mp-toast-container';
      document.body.appendChild(container);
    }
    return container;
  }

  // Helper icons
  const ICONS = {
    danger: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    shieldAlert: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    warning: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    success: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`,
    info: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`,
    refresh: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`,
    ambulance: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/><line x1="8" y1="7" x2="8" y2="11"/><line x1="6" y1="9" x2="10" y2="9"/></svg>`,
    close: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`
  };

  /**
   * Floating Toast Notification
   * @param {string|object} opts - Message string or options object
   * @param {string} [type='success'] - 'success', 'error', 'warning', 'info'
   * @param {number} [duration=4000] - Duration in ms
   */
  function toast(opts, type = 'success', duration = 4000) {
    let title = '';
    let msg = '';

    if (typeof opts === 'string') {
      msg = opts;
      title = type === 'success' ? 'Success' : type === 'error' ? 'Action Failed' : type === 'warning' ? 'Notice' : 'Information';
    } else if (typeof opts === 'object') {
      msg = opts.message || opts.text || '';
      title = opts.title || (opts.type === 'error' ? 'Error' : 'Notification');
      type = opts.type || type;
      duration = opts.duration !== undefined ? opts.duration : duration;
    }

    const container = getToastContainer();
    const toastEl = document.createElement('div');
    toastEl.className = `mp-toast ${type}`;

    const iconSvg = type === 'success' ? ICONS.success : type === 'error' ? ICONS.danger : type === 'warning' ? ICONS.warning : ICONS.info;

    toastEl.innerHTML = `
      <div class="mp-toast-icon">${iconSvg}</div>
      <div class="mp-toast-content">
        <h4 class="mp-toast-title">${escapeHtml(title)}</h4>
        <p class="mp-toast-msg">${escapeHtml(msg)}</p>
      </div>
      <button type="button" class="mp-toast-close" aria-label="Close">${ICONS.close}</button>
      <div class="mp-toast-progress"><div class="mp-toast-bar"></div></div>
    `;

    container.appendChild(toastEl);

    // Trigger entrance animation
    requestAnimationFrame(() => {
      toastEl.classList.add('show');
    });

    const bar = toastEl.querySelector('.mp-toast-bar');
    if (bar && duration > 0) {
      bar.style.transition = `width ${duration}ms linear`;
      requestAnimationFrame(() => {
        bar.style.width = '0%';
      });
    }

    let dismissTimer = null;
    const dismiss = () => {
      if (dismissTimer) clearTimeout(dismissTimer);
      toastEl.classList.remove('show');
      setTimeout(() => {
        if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
      }, 300);
    };

    if (duration > 0) {
      dismissTimer = setTimeout(dismiss, duration);
    }

    toastEl.querySelector('.mp-toast-close').addEventListener('click', dismiss);
  }

  /**
   * Modern Modal Alert Replacement
   */
  function alert(opts, title = 'Notice') {
    return new Promise(resolve => {
      let message = typeof opts === 'string' ? opts : opts.message || '';
      let dialogTitle = typeof opts === 'object' && opts.title ? opts.title : title;
      let type = typeof opts === 'object' && opts.type ? opts.type : 'info';

      const overlay = document.createElement('div');
      overlay.className = 'mp-dialog-overlay';

      const iconSvg = type === 'danger' ? ICONS.danger : type === 'warning' ? ICONS.warning : ICONS.info;
      const btnClass = type === 'danger' ? 'mp-btn-confirm-danger' : type === 'warning' ? 'mp-btn-confirm-warning' : 'mp-btn-confirm-primary';

      overlay.innerHTML = `
        <div class="mp-dialog-card" role="dialog" aria-modal="true">
          <div class="mp-dialog-header ${type}">
            <div class="mp-dialog-icon-wrapper ${type}">${iconSvg}</div>
            <div class="mp-dialog-title-group">
              <h3 class="mp-dialog-title">${escapeHtml(dialogTitle)}</h3>
              <p class="mp-dialog-subtitle">MedPulse Enterprise Notification</p>
            </div>
            <button type="button" class="mp-dialog-close-btn" aria-label="Close">${ICONS.close}</button>
          </div>
          <div class="mp-dialog-body" style="padding-top:10px;">
            <p style="font-size:0.88rem;color:#334155;line-height:1.5;margin:0 0 16px;">${escapeHtml(message)}</p>
          </div>
          <div class="mp-dialog-actions">
            <button type="button" class="mp-btn-dialog ${btnClass}" id="mpAlertOkBtn">OK, Understood</button>
          </div>
        </div>
      `;

      document.body.appendChild(overlay);
      requestAnimationFrame(() => overlay.classList.add('mp-open'));

      const cleanup = () => {
        overlay.classList.remove('mp-open');
        setTimeout(() => {
          if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 250);
        resolve(true);
      };

      overlay.querySelector('#mpAlertOkBtn').addEventListener('click', cleanup);
      overlay.querySelector('.mp-dialog-close-btn').addEventListener('click', cleanup);
      overlay.addEventListener('click', e => { if (e.target === overlay) cleanup(); });
    });
  }

  /**
   * Generic Modern Confirm Dialog
   */
  function confirm(opts) {
    return new Promise(resolve => {
      const title = opts.title || 'Confirm Action';
      const message = opts.message || opts.text || 'Are you sure you want to proceed?';
      const type = opts.type || 'warning'; // 'danger', 'warning', 'primary', 'info'
      const confirmText = opts.confirmText || 'Confirm Action';
      const cancelText = opts.cancelText || 'Cancel';
      const html = opts.html || null;

      const overlay = document.createElement('div');
      overlay.className = 'mp-dialog-overlay';

      const iconSvg = type === 'danger' ? ICONS.danger : type === 'warning' ? ICONS.warning : ICONS.info;
      const confirmBtnClass = type === 'danger' ? 'mp-btn-confirm-danger' : type === 'warning' ? 'mp-btn-confirm-warning' : 'mp-btn-confirm-primary';

      overlay.innerHTML = `
        <div class="mp-dialog-card" role="dialog" aria-modal="true">
          <div class="mp-dialog-header ${type}">
            <div class="mp-dialog-icon-wrapper ${type}">${iconSvg}</div>
            <div class="mp-dialog-title-group">
              <h3 class="mp-dialog-title">${escapeHtml(title)}</h3>
              <p class="mp-dialog-subtitle">${opts.subtitle ? escapeHtml(opts.subtitle) : 'Action requires confirmation'}</p>
            </div>
            <button type="button" class="mp-dialog-close-btn" id="mpConfirmCloseX" aria-label="Close">${ICONS.close}</button>
          </div>
          <div class="mp-dialog-body">
            ${html ? html : `<p style="font-size:0.86rem;color:#334155;line-height:1.5;margin:0 0 16px;">${escapeHtml(message)}</p>`}
          </div>
          <div class="mp-dialog-actions">
            <button type="button" class="mp-btn-dialog mp-btn-cancel" id="mpCancelBtn">${escapeHtml(cancelText)}</button>
            <button type="button" class="mp-btn-dialog ${confirmBtnClass}" id="mpConfirmBtn">
              <span class="mp-btn-text">${escapeHtml(confirmText)}</span>
            </button>
          </div>
        </div>
      `;

      document.body.appendChild(overlay);
      requestAnimationFrame(() => overlay.classList.add('mp-open'));

      const closeDialog = (result) => {
        overlay.classList.remove('mp-open');
        setTimeout(() => {
          if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 250);
        resolve(result);
      };

      const confirmBtn = overlay.querySelector('#mpConfirmBtn');
      const cancelBtn = overlay.querySelector('#mpCancelBtn');
      const closeX = overlay.querySelector('#mpConfirmCloseX');

      confirmBtn.addEventListener('click', () => {
        // Set loading state
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;
        confirmBtn.innerHTML = `<span class="mp-spinner"></span> <span>Processing…</span>`;
        setTimeout(() => closeDialog(true), 150);
      });

      cancelBtn.addEventListener('click', () => closeDialog(false));
      closeX.addEventListener('click', () => closeDialog(false));
      overlay.addEventListener('click', e => { if (e.target === overlay) closeDialog(false); });

      // Keyboard support
      const onKeyDown = (e) => {
        if (e.key === 'Escape') {
          window.removeEventListener('keydown', onKeyDown);
          closeDialog(false);
        }
      };
      window.addEventListener('keydown', onKeyDown);
    });
  }

  /**
   * Specialized National Emergency Surge Confirmation Modal
   */
  function surgeConfirm(opts) {
    return new Promise(resolve => {
      const protoCode = opts.protocolCode || 'DISASTER_PROTOCOL';
      const protoTitle = opts.protocolTitle || 'National Disaster Protocol';
      const quotaPct = opts.quotaPct || 20;
      const severityLabel = opts.severityLabel || 'Moderate';
      const quotaBeds = opts.quotaBeds || 0;
      const holdBeds = opts.holdBeds || 0;
      const relocBeds = opts.relocBeds || 0;
      const facilities = opts.facilities || [];

      const overlay = document.createElement('div');
      overlay.className = 'mp-dialog-overlay';

      const facilityPillsHtml = facilities.map(f => `
        <span class="mp-facility-pill">
          <svg style="width:12px;height:12px;stroke:#fda4af;fill:none;stroke-width:2;" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          ${escapeHtml(f)}
        </span>
      `).join('');

      overlay.innerHTML = `
        <div class="mp-dialog-card" role="dialog" aria-modal="true" style="max-width:560px;">
          <div class="mp-dialog-header danger">
            <div class="mp-dialog-icon-wrapper danger">${ICONS.shieldAlert}</div>
            <div class="mp-dialog-title-group">
              <h3 class="mp-dialog-title" style="color:#b91c1c;">Authorize Emergency Surge</h3>
              <p class="mp-dialog-subtitle">Clinical Capacity Lock &amp; Evacuation Directive</p>
            </div>
            <button type="button" class="mp-dialog-close-btn" id="mpSurgeCloseX" aria-label="Close">${ICONS.close}</button>
          </div>
          <div class="mp-dialog-body">
            <!-- Surge Protocol Card -->
            <div class="mp-surge-summary-box">
              <div class="mp-surge-badge-row">
                <span class="mp-proto-tag">🚨 ${escapeHtml(protoCode)}</span>
                <span class="mp-quota-tag">⚡ ${quotaPct}% ${escapeHtml(severityLabel)}</span>
              </div>
              <div style="font-size:0.92rem;font-weight:800;color:#fff;margin-bottom:12px;">
                ${escapeHtml(protoTitle)}
              </div>
              <div class="mp-surge-metrics-grid">
                <div class="mp-sm-item">
                  <div class="mp-sm-val" style="color:#ffffff;">${quotaBeds.toLocaleString()}</div>
                  <div class="mp-sm-lbl">Target Quota</div>
                </div>
                <div class="mp-sm-item">
                  <div class="mp-sm-val" style="color:#4ade80;">${holdBeds.toLocaleString()}</div>
                  <div class="mp-sm-lbl">P1 Holds</div>
                </div>
                <div class="mp-sm-item">
                  <div class="mp-sm-val" style="color:#f87171;">${relocBeds.toLocaleString()}</div>
                  <div class="mp-sm-lbl">P2 Relocations</div>
                </div>
              </div>
              <div style="font-size:0.68rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:10px;">
                Designated Facilities (${facilities.length}):
              </div>
              <div class="mp-facility-pills">
                ${facilityPillsHtml || '<span style="font-size:0.75rem;color:#cbd5e1;">All Network Facilities</span>'}
              </div>
            </div>

            <!-- Danger Warning Notice -->
            <div class="mp-notice-box">
              ${ICONS.danger}
              <div>
                <strong>Operational Capacity Impact:</strong> Authorizing this protocol will immediately lock target beds into <em>'Emergency Hold'</em> and tag inpatients for clinical relocation across designated facility censuses.
              </div>
            </div>
          </div>
          <div class="mp-dialog-actions">
            <button type="button" class="mp-btn-dialog mp-btn-cancel" id="mpSurgeCancelBtn">Cancel / Abort</button>
            <button type="button" class="mp-btn-dialog mp-btn-confirm-danger" id="mpSurgeConfirmBtn">
              <span class="mp-btn-text">🚨 Confirm &amp; Lock Capacity</span>
            </button>
          </div>
        </div>
      `;

      document.body.appendChild(overlay);
      requestAnimationFrame(() => overlay.classList.add('mp-open'));

      const closeDialog = (result) => {
        overlay.classList.remove('mp-open');
        setTimeout(() => {
          if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 250);
        resolve(result);
      };

      const confirmBtn = overlay.querySelector('#mpSurgeConfirmBtn');
      const cancelBtn = overlay.querySelector('#mpSurgeCancelBtn');
      const closeX = overlay.querySelector('#mpSurgeCloseX');

      confirmBtn.addEventListener('click', () => {
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;
        confirmBtn.innerHTML = `<span class="mp-spinner"></span> <span>Enforcing Locks…</span>`;
        closeDialog(true);
      });

      cancelBtn.addEventListener('click', () => closeDialog(false));
      closeX.addEventListener('click', () => closeDialog(false));
      overlay.addEventListener('click', e => { if (e.target === overlay) closeDialog(false); });

      const onKeyDown = (e) => {
        if (e.key === 'Escape') {
          window.removeEventListener('keydown', onKeyDown);
          closeDialog(false);
        }
      };
      window.addEventListener('keydown', onKeyDown);
    });
  }

  // Utility to escape HTML
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  return {
    toast,
    alert,
    confirm,
    surgeConfirm
  };
})();

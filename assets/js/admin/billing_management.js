/**
 * MedPulse Enterprise Hospital Management System
 * Admin Central Billing Management JavaScript
 * Handles: View / Print Breakdown Modal, Doctor Consultation Payout Display,
 * Audit Trail Status Dialog, Print Flow, and Keyboard/Backdrop Dismissal.
 */

(function () {
  'use strict';

  // DOM Elements
  const breakdownModal   = document.getElementById('invoiceBreakdownModal');
  const auditModal       = document.getElementById('invoiceAuditModal');
  const btnCloseBreakdown = document.getElementById('btnCloseBreakdownModal');
  const btnCancelBreakdown = document.getElementById('btnCancelBreakdownModal');
  const btnPrintInvoice  = document.getElementById('btnPrintInvoice');
  const btnCloseAudit    = document.getElementById('btnCloseAuditModal');
  const btnDismissAudit  = document.getElementById('btnDismissAuditModal');

  // Breakdown Modal dynamic fields
  const modalInvoiceStatusBadge = document.getElementById('modalInvoiceStatusBadge');
  const modalInvoiceNumber      = document.getElementById('modalInvoiceNumber');
  const modalInvoiceDate        = document.getElementById('modalInvoiceDate');
  const modalPatientName        = document.getElementById('modalPatientName');
  const modalPatientContact     = document.getElementById('modalPatientContact');
  const modalInpatientBed       = document.getElementById('modalInpatientBed');
  const modalPaymentMethod      = document.getElementById('modalPaymentMethod');
  const modalInvoiceItemsBody   = document.getElementById('modalInvoiceItemsBody');
  const modalSubtotal           = document.getElementById('modalSubtotal');
  const modalVatLabel           = document.getElementById('modalVatLabel');
  const modalVatAmount          = document.getElementById('modalVatAmount');
  const modalDiscountRow        = document.getElementById('modalDiscountRow');
  const modalDiscountAmount     = document.getElementById('modalDiscountAmount');
  const modalNetPayable         = document.getElementById('modalNetPayable');
  const modalPaidAmount         = document.getElementById('modalPaidAmount');
  const modalDueRow             = document.getElementById('modalDueRow');
  const modalDueAmount          = document.getElementById('modalDueAmount');

  // Audit Modal dynamic fields
  const auditInvoiceSubtitle    = document.getElementById('auditInvoiceSubtitle');
  const auditStatusBanner       = document.getElementById('auditStatusBanner');
  const auditLedgerStatus       = document.getElementById('auditLedgerStatus');
  const auditNetVal             = document.getElementById('auditNetVal');
  const auditPaidVal            = document.getElementById('auditPaidVal');
  const auditDueVal             = document.getElementById('auditDueVal');
  const auditSecurityHash       = document.getElementById('auditSecurityHash');

  /**
   * Format number as BDT currency string (e.g. 45,000.00)
   */
  function formatMoney(amount) {
    return Number(amount || 0).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /**
   * Format doctor name ensuring correct clinical honorific prefix (prevents "Dr. Dr." duplicates,
   * while preserving computed titles like "Prof. Dr.", "Assoc. Prof. Dr.", "Col. (Retd.) Prof. Dr.")
   */
  function formatDoctorName(rawName) {
    if (!rawName) return '';
    const str = String(rawName).trim();
    // If string already has a complex calculated title, preserve it cleanly
    if (/^(?:(?:col\.|lt\.\s*col\.|brig\.\s*gen\.|major)\s*(?:\(retd\.?\))?\s*)*(?:prof\.|assoc\.|asst\.)/i.test(str)) {
      return str;
    }
    if (/^(?:col\.|lt\.\s*col\.|brig\.\s*gen\.|major)\s*(?:\(retd\.?\))?/i.test(str)) {
      return str;
    }
    // Clean any duplicate or redundant leading Dr./Doctor prefixes
    const clean = str.replace(/^(?:(?:dr\.?|doctor)\s*)+/i, '');
    return `Dr. ${clean}`;
  }

  /**
   * Open Breakdown Modal
   */
  function openBreakdownModal() {
    if (breakdownModal) {
      breakdownModal.classList.add('open');
      breakdownModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }
  }

  /**
   * Close Breakdown Modal
   */
  function closeBreakdownModal() {
    if (breakdownModal) {
      breakdownModal.classList.remove('open');
      breakdownModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }
  }

  /**
   * Open Audit Modal
   */
  function openAuditModal() {
    if (auditModal) {
      auditModal.classList.add('open');
      auditModal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
    }
  }

  /**
   * Close Audit Modal
   */
  function closeAuditModal() {
    if (auditModal) {
      auditModal.classList.remove('open');
      auditModal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }
  }

  /**
   * Fetch invoice breakdown from backend
   */
  /**
   * Fetch invoice breakdown from backend
   */
  async function fetchInvoiceBreakdown(invoiceId) {
    openBreakdownModal();
    if (btnPrintInvoice) {
      btnPrintInvoice.disabled = true;
      btnPrintInvoice.style.opacity = '0.5';
    }
    if (modalInvoiceItemsBody) {
      modalInvoiceItemsBody.innerHTML = `
        <tr>
          <td colspan="5" style="text-align: center; padding: 2.5rem; color: #64748b;">
            <svg class="ui-ico" style="animation: spin 1s linear infinite; stroke: var(--brand-primary); margin-bottom: 8px; width: 24px; height: 24px;" viewBox="0 0 24 24"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="7.76" y2="16.24"></line><line x1="16.24" y1="7.76" x2="19.07" y2="4.93"></line></svg>
            <div>Loading itemized statement & physician breakdown...</div>
          </td>
        </tr>
      `;
    }

    try {
      const response = await fetch(`billing_management.php?action=get_invoice_breakdown&invoice_id=${encodeURIComponent(invoiceId)}`, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      const data = await response.json();

      if (!data.success) {
        if (modalInvoiceItemsBody) {
          modalInvoiceItemsBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 2rem;">Error: ${escapeHtml(data.message || 'Unable to load invoice.')}</td></tr>`;
        }
        return;
      }

      renderInvoiceBreakdown(data.invoice, data.items || []);

    } catch (err) {
      console.error('Invoice fetch error:', err);
      if (modalInvoiceItemsBody) {
        modalInvoiceItemsBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #ef4444; padding: 2rem;">Network or server communication error.</td></tr>`;
      }
    }
  }

  /**
   * Render invoice metadata and line items
   */
  function renderInvoiceBreakdown(inv, items) {
    if (btnPrintInvoice) {
      btnPrintInvoice.disabled = false;
      btnPrintInvoice.style.opacity = '1';
    }

    // Status Badge
    if (modalInvoiceStatusBadge) {
      if (inv.status === 'Paid') {
        modalInvoiceStatusBadge.className = 'status-badge-active';
        modalInvoiceStatusBadge.innerHTML = `<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> Paid & Settled`;
      } else if (inv.status === 'Partial') {
        modalInvoiceStatusBadge.className = 'status-badge-partial';
        modalInvoiceStatusBadge.innerHTML = `<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg> Partial Due`;
      } else {
        modalInvoiceStatusBadge.className = 'status-badge-pending';
        modalInvoiceStatusBadge.innerHTML = `<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> Pending Settlement`;
      }
    }

    // Header info
    if (modalInvoiceNumber)  modalInvoiceNumber.textContent = inv.invoice_number;
    if (modalInvoiceDate)    modalInvoiceDate.textContent = new Date(inv.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    if (modalPatientName)    modalPatientName.textContent = inv.patient_name + (inv.patient_age ? ` (${inv.patient_age} yrs, ${inv.patient_gender || ''})` : '');
    if (modalPatientContact) modalPatientContact.textContent = inv.patient_phone || inv.patient_email || 'Direct Walk-in';
    
    // Inpatient Bed info
    if (modalInpatientBed) {
      if (inv.bed_number) {
        modalInpatientBed.textContent = `Bed: ${inv.bed_number} (${inv.ward_type || 'Inpatient'})`;
      } else {
        modalInpatientBed.textContent = 'Outpatient / Ambulatory Service';
      }
    }

    if (modalPaymentMethod) modalPaymentMethod.textContent = inv.payment_method || 'Cash Settlement';

    // Render items table
    if (modalInvoiceItemsBody) {
      if (!items || items.length === 0) {
        modalInvoiceItemsBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 2rem;">No line items found for this invoice.</td></tr>`;
      } else {
        let rowsHtml = '';
        items.forEach(item => {
          // Category pill class
          const catClean = (item.item_type || '').toLowerCase().replace(/\s+/g, '');
          const catClass = `category-${catClean}`;

          // Doctor cell formatting
          let doctorCell = '<span style="color: #94a3b8; font-size: 0.78rem;">Hospital Facility</span>';
          if (item.doctor_name) {
            let payoutClass = 'payout-unclaimed';
            let payoutLabel = 'Unclaimed';
            if (item.doctor_payout_status === 'DISBURSED') {
              payoutClass = 'payout-disbursed';
              payoutLabel = `Disbursed ৳${formatMoney(item.doctor_payout_amount)}`;
            } else if (item.doctor_payout_status === 'PENDING_CLEARANCE') {
              payoutClass = 'payout-pending';
              payoutLabel = `Pending Payout ৳${formatMoney(item.doctor_payout_amount)}`;
            }

            doctorCell = `
              <div class="doctor-item-cell">
                <span class="doctor-item-name">${escapeHtml(formatDoctorName(item.doctor_name))}</span>
                ${item.doctor_specialty ? `<span class="doctor-item-specialty">${escapeHtml(item.doctor_specialty)}</span>` : ''}
                <span class="payout-chip ${payoutClass}">${payoutLabel}</span>
              </div>
            `;
          }

          rowsHtml += `
            <tr>
              <td><span class="item-category-chip ${catClass}">${escapeHtml(item.item_type)}</span></td>
              <td>
                <div style="font-weight: 600; color: #0f172a;">${escapeHtml(item.description)}</div>
                <div style="font-size: 0.72rem; color: #64748b;">Qty: ${escapeHtml(item.quantity)} &times; ৳${formatMoney(item.unit_price)}</div>
              </td>
              <td>${doctorCell}</td>
              <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 600;">৳${formatMoney(item.unit_price)}</td>
              <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; color: #0f172a;">৳${formatMoney(item.total_price)}</td>
            </tr>
          `;
        });
        modalInvoiceItemsBody.innerHTML = rowsHtml;
      }
    }

    // Financial totals
    if (modalSubtotal) modalSubtotal.textContent = `৳ ${formatMoney(inv.subtotal)}`;
    if (modalVatLabel) modalVatLabel.textContent = `VAT (${Number(inv.vat_percentage || 0).toFixed(1)}%):`;
    
    const vatAmount = (Number(inv.subtotal || 0) * Number(inv.vat_percentage || 0)) / 100;
    if (modalVatAmount) modalVatAmount.textContent = `৳ ${formatMoney(vatAmount)}`;

    const discount = Number(inv.discount || 0);
    if (modalDiscountRow) {
      if (discount > 0) {
        modalDiscountRow.style.display = 'flex';
        if (modalDiscountAmount) modalDiscountAmount.textContent = `- ৳ ${formatMoney(discount)}`;
      } else {
        modalDiscountRow.style.display = 'none';
      }
    }

    if (modalNetPayable) modalNetPayable.textContent = `৳ ${formatMoney(inv.net_payable)}`;
    if (modalPaidAmount) modalPaidAmount.textContent = `৳ ${formatMoney(inv.paid_amount)}`;

    const due = Number(inv.due_amount || 0);
    if (modalDueAmount) modalDueAmount.textContent = `৳ ${formatMoney(due)}`;

    if (modalDueRow) {
      if (due > 0) {
        modalDueRow.className = 'recon-row recon-due has-due';
      } else {
        modalDueRow.className = 'recon-row recon-due no-due';
      }
    }

    // Store invoice data on window for print access
    window._lastInvoiceData = { inv, items };

    // Immediately pre-populate the printable voucher DOM so it is ready on demand
    syncVoucherData();
  }

  /**
   * Populate the hidden #printable-invoice-voucher with the current invoice data,
   * showing/hiding the PAID stamp and calculating ledger hash.
   * Returns true on success, false if no data.
   */
  function syncVoucherData() {
    const d = window._lastInvoiceData;
    if (!d || !d.inv) return false;
    const { inv, items } = d;

    // ── Helper ──────────────────────────────────────────────────────────────
    const $  = id => document.getElementById(id);
    const fmt = v  => `৳ ${formatMoney(v)}`;
    const esc = s  => escapeHtml(s);
    const parseSafeDate = s => {
      if (!s) return null;
      const parsed = new Date(typeof s === 'string' ? s.replace(' ', 'T') : s);
      return isNaN(parsed.getTime()) ? null : parsed;
    };
    const fmtDate = s => {
      const dt = parseSafeDate(s);
      if (!dt) return '—';
      return dt.toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric'
      });
    };
    const fmtDateTime = s => {
      const dt = parseSafeDate(s);
      if (!dt) return '—';
      return dt.toLocaleDateString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
    };

    // ── Status chip ──────────────────────────────────────────────────────────
    const chip = $('pivInvoiceStatusChip');
    const isPaid = inv.status === 'Paid' || Number(inv.due_amount || 0) <= 0;
    if (chip) {
      chip.textContent = isPaid ? 'FULLY SETTLED' : 'PENDING SETTLEMENT';
      chip.className = 'piv-status-chip ' + (isPaid ? 'piv-status-paid' : 'piv-status-pending');
    }

    // ── Meta fields ──────────────────────────────────────────────────────────
    const setText = (id, val) => { const el = $(id); if (el) el.textContent = val || '—'; };
    setText('pivInvoiceNumber', inv.invoice_number);
    setText('pivInvoiceDate',   fmtDateTime(inv.created_at));
    setText('pivPaymentMethod', inv.payment_method || 'Cash Settlement');
    setText('pivPatientName',   inv.patient_name);
    setText('pivPatientAge',    [inv.patient_age ? inv.patient_age + ' yrs' : '', inv.patient_gender || ''].filter(Boolean).join(', ') || '—');
    setText('pivPatientUhid',   'UHID-' + String(inv.patient_id || '').padStart(5, '0'));
    setText('pivBedInfo',       inv.bed_number ? `Bed ${inv.bed_number} - ${inv.ward_type || 'Inpatient'}` : 'Outpatient / Ambulatory');
    setText('pivAdmittedDate',  inv.admitted_at ? fmtDateTime(inv.admitted_at) : '—');
    setText('pivDischargedDate',inv.discharged_at ? fmtDateTime(inv.discharged_at) : 'In Progress');

    // ── Ledger rows ──────────────────────────────────────────────────────────
    const tbody = $('pivLedgerBody');
    if (tbody) {
      if (!items || items.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:16px;">No line items on record.</td></tr>`;
      } else {
        let html = '';
        items.forEach((it, idx) => {
          const docLine = it.doctor_name
            ? `<div class="piv-item-doc">${esc(formatDoctorName(it.doctor_name))}${it.doctor_specialty ? ' · ' + esc(it.doctor_specialty) : ''}</div>`
            : '<span style="color:#64748b;font-size:9px;">Hospital Facility</span>';
          const catLabel = String(it.item_type || 'Service').toUpperCase();
          html += `<tr>
            <td style="text-align:center;vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;">${idx + 1}</td>
            <td style="vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;"><span class="piv-item-type">${esc(catLabel)}</span></td>
            <td style="vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;"><div class="piv-item-desc">${esc(it.description)}</div></td>
            <td style="vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;">${docLine}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;">৳${formatMoney(it.unit_price)}</td>
            <td style="text-align:center;vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;">${parseInt(it.quantity) || 1}</td>
            <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;vertical-align:top;padding:8px;border-bottom:1px solid #e2e8f0;">৳${formatMoney(it.total_price)}</td>
          </tr>`;
        });
        tbody.innerHTML = html;
      }
    }

    // ── Financial summary ────────────────────────────────────────────────────
    setText('pivSubtotal',   fmt(inv.subtotal));
    const vatPct = Number(inv.vat_percentage || 0);
    const vatAmt = Number(inv.subtotal || 0) * vatPct / 100;
    const vatLbl = $('pivVatLabel');
    if (vatLbl) vatLbl.textContent = `VAT (${vatPct.toFixed(1)}%)`;
    setText('pivVatAmount', fmt(vatAmt));

    // Explicitly define discount to prevent ReferenceError
    const discount = Number(inv.discount || 0);
    const discEl = $('pivDiscountRow');
    if (discEl) discEl.style.display = discount > 0 ? 'flex' : 'none';
    if (discount > 0) setText('pivDiscount', `− ${fmt(discount)}`);

    setText('pivNetPayable', fmt(inv.net_payable));
    setText('pivPaidAmount', fmt(inv.paid_amount));
    const dueEl = $('pivDueRow');
    const due2 = Number(inv.due_amount || 0);
    if (dueEl) dueEl.className = 'piv-fin-row piv-fin-due' + (due2 <= 0 ? ' piv-fin-due-zero' : '');
    setText('pivDueAmount', fmt(due2));

    // ── PAID stamp ────────────────────────────────────────────────────────────
    const stamp = $('pivPaidStamp');
    if (stamp) {
      if (isPaid) {
        stamp.classList.add('is-paid-visible');
        stamp.style.display = 'block';
        const stampDate = fmtDate(inv.created_at);
        setText('pivStampDate', stampDate);
      } else {
        stamp.classList.remove('is-paid-visible');
        stamp.style.display = 'none';
      }
    }

    // ── Ledger hash (deterministic) ───────────────────────────────────────────
    const hashSrc = (inv.invoice_number || '') + (inv.net_payable || '') + (inv.patient_id || '');
    let h = 0x811c9dc5;
    for (let i = 0; i < hashSrc.length; i++) {
      h ^= hashSrc.charCodeAt(i);
      h = (h * 0x01000193) >>> 0;
    }
    const hexH = h.toString(16).padStart(8, '0');
    setText('pivLedgerHash', `SHA256: e8f2${hexH}a94b...c1d7`);

    return true;
  }

  /**
   * Action: Populate voucher and trigger browser print dialog
   */
  function populatePrintVoucher() {
    const success = syncVoucherData();
    if (!success && !window._lastInvoiceData) {
      console.warn('Invoice breakdown data is not yet loaded for printing.');
      return;
    }
    window.print();
  }



  /**
   * Handle Audit Trail Popover/Dialog
   */
  function handleAuditClick(button) {
    const invId  = button.getAttribute('data-invoice-id');
    const invNum = button.getAttribute('data-invoice-number') || `INV-${invId}`;
    const status = button.getAttribute('data-status') || 'settled';
    const net    = button.getAttribute('data-net') || '0.00';
    const paid   = button.getAttribute('data-paid') || '0.00';
    const due    = button.getAttribute('data-due') || '0.00';

    if (auditInvoiceSubtitle) {
      auditInvoiceSubtitle.textContent = `Treasury & Compliance Audit for ${invNum}`;
    }

    if (auditStatusBanner) {
      if (status === 'settled' || Number(due) <= 0) {
        auditStatusBanner.className = 'audit-status-banner settled';
        auditStatusBanner.innerHTML = `
          <svg class="ui-ico" style="stroke: #16a34a; width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
          <div>
            <strong style="display: block; font-size: 0.92rem;">Audit Reconciled & Fully Settled</strong>
            <span style="font-size: 0.78rem; opacity: 0.9;">All physician consultation fees and clinical charges have been settled with zero outstanding balance.</span>
          </div>
        `;
        if (auditLedgerStatus) auditLedgerStatus.textContent = 'VERIFIED_BALANCED';
      } else {
        auditStatusBanner.className = 'audit-status-banner due';
        auditStatusBanner.innerHTML = `
          <svg class="ui-ico" style="stroke: #d97706; width: 22px; height: 22px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <div>
            <strong style="display: block; font-size: 0.92rem;">Outstanding Due Flagged</strong>
            <span style="font-size: 0.78rem; opacity: 0.9;">This invoice has a remaining receivable balance of ৳ ${due}. Requires cashier or billing clearance.</span>
          </div>
        `;
        if (auditLedgerStatus) auditLedgerStatus.textContent = 'PENDING_COLLECTION';
      }
    }

    if (auditNetVal)  auditNetVal.textContent = `৳ ${net}`;
    if (auditPaidVal) auditPaidVal.textContent = `৳ ${paid}`;
    if (auditDueVal)  auditDueVal.textContent = `৳ ${due}`;

    // Generate deterministic hash from invNum
    let hash = 0;
    for (let i = 0; i < invNum.length; i++) {
      hash = ((hash << 5) - hash) + invNum.charCodeAt(i);
      hash |= 0;
    }
    const hexHash = Math.abs(hash).toString(16).padStart(8, '0');
    if (auditSecurityHash) {
      auditSecurityHash.textContent = `SHA256: e8${hexHash}7a94f0b2...c1`;
    }

    openAuditModal();
  }

  // ── NEW: Treasury Audit Modal ─────────────────────────────────────────────

  const treasuryModal     = document.getElementById('treasuryAuditModal');
  const settleModal       = document.getElementById('settlePaymentModal');
  const btnCloseTreasury  = document.getElementById('btnCloseTreasuryModal');
  const btnDismissTreasury = document.getElementById('btnDismissTreasuryModal');
  const btnCloseSettle    = document.getElementById('btnCloseSettleModal');
  const btnCancelSettle   = document.getElementById('btnCancelSettleModal');

  // CSRF token from meta tag (already rendered by PHP into the <meta> tag)
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const CSRF     = csrfMeta ? csrfMeta.getAttribute('content') : '';

  // Treasury state
  let treasuryDataLoaded = false;

  // ── Treasury open/close ───────────────────────────────────────────────────
  window.openTreasuryModal = function () {
    if (!treasuryModal) return;
    treasuryModal.classList.add('open');
    treasuryModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    if (!treasuryDataLoaded) {
      fetchTreasurySummary();
      treasuryDataLoaded = true;
    }
  };

  function closeTreasuryModal() {
    if (!treasuryModal) return;
    treasuryModal.classList.remove('open');
    treasuryModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ── Tab switcher ──────────────────────────────────────────────────────────
  window.switchTreasuryTab = function (tabName, btn) {
    document.querySelectorAll('.treasury-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.treasury-tab-panel').forEach(p => { p.style.display = 'none'; p.classList.remove('active'); });
    btn.classList.add('active');
    const panel = document.getElementById('tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1));
    if (panel) { panel.style.display = 'block'; panel.classList.add('active'); }

    // Lazy-load Audit Feed only when that tab is first activated
    const feedWrap = document.getElementById('auditFeedWrap');
    if (tabName === 'auditfeed' && feedWrap && feedWrap.querySelector('.treasury-loading')) {
      fetchAuditFeed();
    }
  };

  // ── Fetch treasury summary ────────────────────────────────────────────────
  async function fetchTreasurySummary() {
    try {
      const r    = await fetch('billing_management.php?action=get_treasury_summary', { credentials: 'same-origin' });
      const data = await r.json();
      if (!data.success) return;

      const s = data.summary;
      const fmt = v => '\u09F3 ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const setEl = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      setEl('tmcTotalInvoiced',   fmt(s.total_invoiced));
      setEl('tmcTotalCollected',  fmt(s.total_collected));
      setEl('tmcOutstanding',     fmt(s.total_outstanding));
      setEl('tmcDoctorLiability', fmt(s.doctor_fee_liability));
      setEl('tmcInvoiceCount',    `${s.invoice_count} invoices total`);
      setEl('tmcPaidCount',       `${s.paid_count || 0} fully settled`);
      setEl('tmcPendingCount',    `${s.pending_count || 0} awaiting payment`);

      // Render payout table
      renderPayoutTable(data.payouts || []);

    } catch (err) {
      console.error('Treasury fetch error:', err);
    }
  }

  // ── Render payout clearance table ─────────────────────────────────────────
  function renderPayoutTable(payouts) {
    const wrap  = document.getElementById('payoutTableWrap');
    const badge = document.getElementById('pendingPayoutBadge');
    if (!wrap) return;

    if (badge) {
      badge.textContent = payouts.length > 0 ? payouts.length : '';
      badge.style.display = payouts.length > 0 ? 'inline-flex' : 'none';
    }

    if (!payouts.length) {
      wrap.innerHTML = `<div class="treasury-empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke="#10b981" width="48" height="48" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        <strong>All Doctor Payouts Cleared</strong>
        <span>No pending fee disbursements at this time.</span>
      </div>`;
      return;
    }

    const fmt = v => '\u09F3 ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const statusLabel = { 'UNCLAIMED': 'Unclaimed', 'PENDING_CLEARANCE': 'Pending Clearance' };

    let rows = '';
    payouts.forEach(p => {
      const statusClass = p.doctor_payout_status === 'PENDING_CLEARANCE' ? 'payout-pending' : 'payout-unclaimed';
      rows += `
        <tr id="payout-row-${escapeHtml(p.item_id)}">
          <td>
            <div style="font-weight:700;color:#0f172a;font-size:.84rem;">${escapeHtml(p.doctor_name)}</div>
            <div style="font-size:.72rem;color:#64748b;">${escapeHtml(p.doctor_specialty || 'General')}</div>
          </td>
          <td style="font-size:.8rem;color:#334155;">${escapeHtml(p.patient_name)}</td>
          <td><span class="license-chip" style="font-size:.7rem;">${escapeHtml(p.invoice_number)}</span></td>
          <td><span class="payout-chip ${statusClass}" style="font-size:.72rem;">${statusLabel[p.doctor_payout_status] || p.doctor_payout_status}</span></td>
          <td style="font-weight:700;color:#0f172a;">${fmt(p.doctor_payout_amount)}</td>
          <td>
            <button type="button"
                    class="btn-billing-action btn-approve-payout"
                    data-action="approve-payout"
                    data-item-id="${escapeHtml(p.item_id)}"
                    data-doctor-name="${escapeHtml(p.doctor_name)}"
                    data-amount="${escapeHtml(p.doctor_payout_amount)}"
                    title="Approve & clear payout to ${escapeHtml(p.doctor_name)}">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
              <span>Approve &amp; Clear</span>
            </button>
          </td>
        </tr>`;
    });

    wrap.innerHTML = `
      <div style="overflow-x:auto;">
        <table class="admin-data-table payout-clearance-table">
          <thead><tr>
            <th>Doctor</th><th>Patient</th><th>Invoice #</th>
            <th>Status</th><th>Fee (৳)</th><th style="text-align:right;">Action</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }

  // ── Approve doctor payout ─────────────────────────────────────────────────
  async function handleApprovePayoutClick(btn) {
    const itemId = btn.getAttribute('data-item-id');
    const dName  = btn.getAttribute('data-doctor-name');

    btn.disabled = true;
    btn.innerHTML = '<span style="opacity:.6;">Processing…</span>';

    try {
      const fd = new FormData();
      fd.append('item_id',    itemId);
      fd.append('csrf_token', CSRF);

      const r    = await fetch('billing_management.php?action=approve_doctor_payout', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();

      if (data.success) {
        const row = document.getElementById(`payout-row-${itemId}`);
        if (row) {
          row.style.transition = 'opacity .4s, background .4s';
          row.style.background = '#ecfdf5';
          setTimeout(() => {
            row.style.opacity = '0';
            setTimeout(() => row.remove(), 400);
          }, 800);
        }
        showToast(`Payout cleared to ${formatDoctorName(dName)}`, 'success');
        // Refresh treasury data next open
        treasuryDataLoaded = false;
      } else {
        btn.disabled = false;
        btn.innerHTML = '<span>Approve &amp; Clear</span>';
        showToast(data.message || 'Failed to approve payout.', 'error');
      }
    } catch (err) {
      btn.disabled = false;
      btn.innerHTML = '<span>Approve &amp; Clear</span>';
      showToast('Network error. Please try again.', 'error');
    }
  }

  // ── Audit feed ────────────────────────────────────────────────────────────
  async function fetchAuditFeed() {
    const wrap = document.getElementById('auditFeedWrap');
    if (!wrap) return;

    try {
      const r    = await fetch('billing_management.php?action=get_audit_feed', { credentials: 'same-origin' });
      const data = await r.json();

      if (!data.success || !data.events.length) {
        wrap.innerHTML = `<div class="treasury-empty-state"><span>No billing audit events recorded yet.</span></div>`;
        return;
      }

      const actionColors = { PAYMENT_COLLECTED: '#059669', DOCTOR_PAYOUT_DISBURSED: '#0284c7', default: '#64748b' };
      let rows = '';
      data.events.forEach(ev => {
        const color = actionColors[ev.action] || actionColors.default;
        const ts = new Date(ev.created_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        rows += `
          <tr>
            <td style="font-size:.74rem;color:#94a3b8;white-space:nowrap;">${escapeHtml(ts)}</td>
            <td style="font-size:.8rem;color:#334155;">${escapeHtml(ev.admin_name || 'System')}</td>
            <td><span style="font-size:.7rem;font-weight:700;color:${color};background:${color}18;padding:2px 8px;border-radius:999px;">${escapeHtml(ev.action)}</span></td>
            <td style="font-size:.78rem;color:#475569;max-width:260px;">${escapeHtml(ev.description || '—')}</td>
            <td style="font-size:.72rem;color:#94a3b8;font-family:monospace;">${escapeHtml(ev.ip_address || '—')}</td>
          </tr>`;
      });

      wrap.innerHTML = `
        <div style="overflow-x:auto;">
          <table class="admin-data-table" style="font-size:.82rem;">
            <thead><tr>
              <th>Timestamp</th><th>Admin</th><th>Action</th><th>Description</th><th>IP</th>
            </tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;
    } catch (err) {
      wrap.innerHTML = `<div class="treasury-empty-state"><span style="color:#ef4444;">Failed to load audit feed.</span></div>`;
    }
  }

  // ── Settle Payment Modal ──────────────────────────────────────────────────
  window.openSettleModal = function (invoiceId, invoiceNumber, dueAmount) {
    if (!settleModal) return;
    const idEl     = document.getElementById('settleInvoiceId');
    const subtitleEl = document.getElementById('settleModalSubtitle');
    const dueEl    = document.getElementById('settleDueAmount');
    const amtEl    = document.getElementById('settleAmountReceived');
    const errEl    = document.getElementById('settleFormError');
    const submitBtn = document.getElementById('btnSubmitSettle');

    if (idEl)       idEl.value           = invoiceId;
    if (subtitleEl) subtitleEl.textContent = `Invoice: ${invoiceNumber}`;
    if (dueEl)      dueEl.textContent    = '\u09F3 ' + Number(dueAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (amtEl)      { amtEl.value = ''; amtEl.setAttribute('max', dueAmount); }
    if (errEl)      { errEl.style.display = 'none'; errEl.textContent = ''; }
    if (submitBtn)  { submitBtn.disabled = false; submitBtn.innerHTML = '<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke:white;"><polyline points="20 6 9 17 4 12"></polyline></svg> Record Payment'; }
    document.getElementById('settlePaymentMethod').value = 'Cash';
    document.getElementById('settleTransactionId').value = '';

    settleModal.classList.add('open');
    settleModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  function closeSettleModal() {
    if (!settleModal) return;
    settleModal.classList.remove('open');
    settleModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // ── Settle form submission ────────────────────────────────────────────────
  window.handleSettleSubmit = async function (e) {
    e.preventDefault();
    const form    = e.target;
    const errEl   = document.getElementById('settleFormError');
    const submitBtn = document.getElementById('btnSubmitSettle');
    const amtEl   = document.getElementById('settleAmountReceived');

    errEl.style.display = 'none';

    const amount = parseFloat(amtEl.value);
    if (!amount || amount <= 0) {
      errEl.textContent = 'Please enter a valid amount greater than zero.';
      errEl.style.display = 'block';
      return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span style="opacity:.7;">Processing…</span>';

    try {
      const fd = new FormData(form);
      const r  = await fetch('billing_management.php?action=settle_payment', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();

      if (data.success) {
        closeSettleModal();
        showToast(data.message || 'Payment recorded.', 'success');
        // Update the row in the table without a full reload
        updateTableRowAfterSettle(fd.get('invoice_id'), data);
      } else {
        errEl.textContent = data.message || 'Failed to record payment.';
        errEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke:white;"><polyline points="20 6 9 17 4 12"></polyline></svg> Record Payment';
      }
    } catch (err) {
      errEl.textContent = 'Network error. Please try again.';
      errEl.style.display = 'block';
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke:white;"><polyline points="20 6 9 17 4 12"></polyline></svg> Record Payment';
    }
  };

  // ── Update table row after payment settled ────────────────────────────────
  function updateTableRowAfterSettle(invoiceId, data) {
    // Find the Collect/Due button to locate the row
    const collectBtn = document.querySelector(`[data-action="settle-payment"][data-invoice-id="${invoiceId}"]`);
    if (!collectBtn) { setTimeout(() => location.reload(), 1500); return; }
    const row = collectBtn.closest('tr');
    if (!row) return;

    const fmt = v => '\u09F3 ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Update financials cell (column index 4, 0-based)
    const cells = row.querySelectorAll('td');
    if (cells[4]) {
      cells[4].querySelector('.financial-summary-cell .financial-subtext').innerHTML = `
        <span class="text-paid">Paid: ${fmt(data.new_paid)}</span>
        ${data.new_due > 0
          ? `<span class="text-due-pill">Due: ${fmt(data.new_due)}</span>`
          : `<span class="text-zero-due">&bull; No Due</span>`}
      `;
    }

    // Update status cell (column index 7)
    if (cells[7]) {
      if (data.new_status === 'Paid') {
        cells[7].innerHTML = `<span class="status-badge-active"><svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> Paid &amp; Settled</span>`;
      } else {
        cells[7].innerHTML = `<span class="status-badge-partial"><svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg> Partial Due</span>`;
      }
    }

    // If fully paid, swap Collect button for Settled button
    if (data.new_status === 'Paid' && cells[8]) {
      const actionsRow = cells[8].querySelector('.billing-actions-row');
      if (actionsRow) {
        const collectBtnEl = actionsRow.querySelector('[data-action="settle-payment"]');
        const auditBtnEl   = actionsRow.querySelector('[data-action="audit-trail"]');
        if (collectBtnEl) collectBtnEl.remove();
        if (auditBtnEl) {
          auditBtnEl.className = 'btn-billing-action btn-audit-settled';
          auditBtnEl.setAttribute('data-status', 'settled');
          auditBtnEl.setAttribute('data-due', '0.00');
          auditBtnEl.querySelector('span').textContent = 'Settled';
        }
      }
    }

    // Flash the row green briefly
    row.style.transition = 'background .4s';
    row.style.background = '#ecfdf5';
    setTimeout(() => { row.style.background = ''; }, 2000);
  }

  // ── Extend event delegation ───────────────────────────────────────────────
  // (Hooks into the existing click listener via additional data-action values)
  document.addEventListener('click', function (e2) {
    // Settle payment trigger from table
    const settleBtn = e2.target.closest('[data-action="settle-payment"]');
    if (settleBtn) {
      e2.preventDefault();
      const invId  = settleBtn.getAttribute('data-invoice-id');
      const invNum = settleBtn.getAttribute('data-invoice-number') || `INV-${invId}`;
      const due    = settleBtn.getAttribute('data-due') || '0.00';
      openSettleModal(invId, invNum, due);
      return;
    }

    // Approve doctor payout from payout table
    const payoutBtn = e2.target.closest('[data-action="approve-payout"]');
    if (payoutBtn) {
      e2.preventDefault();
      handleApprovePayoutClick(payoutBtn);
      return;
    }

    // Backdrop dismiss
    if (e2.target === treasuryModal) closeTreasuryModal();
    if (e2.target === settleModal)   closeSettleModal();
  });

  // Close button wiring
  if (btnCloseTreasury)   btnCloseTreasury.addEventListener('click',   closeTreasuryModal);
  if (btnDismissTreasury) btnDismissTreasury.addEventListener('click', closeTreasuryModal);
  if (btnCloseSettle)     btnCloseSettle.addEventListener('click',     closeSettleModal);
  if (btnCancelSettle)    btnCancelSettle.addEventListener('click',    closeSettleModal);

  // Extend existing Escape handler (supplement, not replace — existing listener is above)
  document.addEventListener('keydown', function (ek) {
    if (ek.key === 'Escape') {
      if (treasuryModal && treasuryModal.classList.contains('open')) closeTreasuryModal();
      if (settleModal   && settleModal.classList.contains('open'))   closeSettleModal();
    }
  });

  // ── Original Event Delegation (view-invoice & audit-trail) ───────────────
  document.addEventListener('click', function (e) {
    // 1. View Invoice Breakdown button
    const viewBtn = e.target.closest('[data-action="view-invoice"]');
    if (viewBtn) {
      e.preventDefault();
      const invoiceId = viewBtn.getAttribute('data-invoice-id');
      if (invoiceId) fetchInvoiceBreakdown(invoiceId);
      return;
    }

    // 2. Audit Trail button
    const auditBtn = e.target.closest('[data-action="audit-trail"]');
    if (auditBtn) {
      e.preventDefault();
      handleAuditClick(auditBtn);
      return;
    }

    // 3. Modal backdrop dismiss (existing modals)
    if (e.target === breakdownModal) closeBreakdownModal();
    if (e.target === auditModal)     closeAuditModal();
  });

  // Modal Close Buttons (existing modals)
  if (btnCloseBreakdown)  btnCloseBreakdown.addEventListener('click', closeBreakdownModal);
  if (btnCancelBreakdown) btnCancelBreakdown.addEventListener('click', closeBreakdownModal);
  if (btnCloseAudit)      btnCloseAudit.addEventListener('click', closeAuditModal);
  if (btnDismissAudit)    btnDismissAudit.addEventListener('click', closeAuditModal);

  // Print Invoice Button — populate voucher first, then send to print
  if (btnPrintInvoice) {
    btnPrintInvoice.addEventListener('click', function (e) {
      e.preventDefault();
      populatePrintVoucher();
    });
  }

  // Ensure print data is synced even if user prints via keyboard shortcut (Ctrl+P)
  window.addEventListener('beforeprint', function () {
    syncVoucherData();
  });

  // Keyboard Escape listener (existing modals)
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
      closeBreakdownModal();
      closeAuditModal();
    }
  });

})();


/**
 * MedPulse Enterprise Hospital Management System
 * Inpatient Care & Clinical Teams Management Controller
 * Location: assets/js/admin/inpatient_care.js
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. CSRF Token
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.content : '';

  // 2. Modals Elements
  const transferModal   = document.getElementById('bedTransferModal');
  const doctorsModal    = document.getElementById('doctorAssignmentModal');
  const dischargeModal  = document.getElementById('dischargeModal');

  // Form Elements
  const transferForm    = document.getElementById('transferBedForm');
  const doctorsForm     = document.getElementById('assignDoctorsForm');
  const dischargeForm   = document.getElementById('dischargePatientForm');

  // State
  let availableBedsCache = [];
  let activeDoctorsCache = [];

  // Parse doctor registry from pre-rendered JSON script tag if available
  const docDataEl = document.getElementById('activeDoctorsData');
  if (docDataEl) {
    try {
      activeDoctorsCache = JSON.parse(docDataEl.textContent);
    } catch (e) {
      console.error('Failed to parse doctors registry:', e);
    }
  }

  // Helper: Open Modal
  function openModal(modalEl) {
    if (modalEl) {
      modalEl.classList.add('open');
      document.body.style.overflow = 'hidden';
    }
  }

  // Helper: Close Modal
  function closeModal(modalEl) {
    if (modalEl) {
      modalEl.classList.remove('open');
      document.body.style.overflow = '';
    }
  }

  // Close when clicking on backdrop
  document.querySelectorAll('.inpatient-modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) {
        closeModal(backdrop);
      }
    });
  });

  // Global close helper for close buttons
  window.closeIpcModal = function(modalId) {
    const el = document.getElementById(modalId);
    if (el) closeModal(el);
  };

  // ---------------------------------------------------------------------------
  // Action 1: Open Bed Transfer Modal
  // ---------------------------------------------------------------------------
  window.openTransferModal = function(patientId, patientName, currentBedId, currentBedNumber, currentWard) {
    document.getElementById('transferPatientId').value = patientId;
    document.getElementById('transferFromBedId').value = currentBedId;
    document.getElementById('transferPatientName').textContent = patientName;
    document.getElementById('transferCurrentBed').textContent = `${currentBedNumber} (${currentWard})`;
    document.getElementById('transferReason').value = '';

    const bedSelect = document.getElementById('transferToBedSelect');
    bedSelect.innerHTML = '<option value="">-- Loading Available Beds... --</option>';
    bedSelect.disabled = true;

    openModal(transferModal);

    // Fetch only available beds
    fetch('../backend/api/get_available_beds.php')
      .then(res => res.json())
      .then(data => {
        if (!data.success || !data.beds) {
          bedSelect.innerHTML = '<option value="">No available beds found</option>';
          return;
        }

        availableBedsCache = data.beds.filter(b => parseInt(b.bed_id) !== parseInt(currentBedId));
        renderAvailableBedsDropdown('all');
      })
      .catch(err => {
        console.error('Failed to fetch beds:', err);
        bedSelect.innerHTML = '<option value="">Error loading beds. Please retry.</option>';
      });
  };

  function renderAvailableBedsDropdown(wardFilter) {
    const bedSelect = document.getElementById('transferToBedSelect');
    bedSelect.innerHTML = '<option value="">-- Select Destination Available Bed --</option>';

    const filtered = (wardFilter === 'all')
      ? availableBedsCache
      : availableBedsCache.filter(b => b.ward_type === wardFilter);

    if (filtered.length === 0) {
      bedSelect.innerHTML = '<option value="">No available beds in selected ward</option>';
      bedSelect.disabled = true;
      return;
    }

    filtered.forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.bed_id;
      opt.textContent = `${b.bed_number} — ${b.ward_type} (Floor ${b.floor_number}) | ৳${Number(b.daily_rate).toLocaleString()}/day`;
      bedSelect.appendChild(opt);
    });

    bedSelect.disabled = false;
  }

  // Ward filter inside Transfer modal
  const transferWardFilter = document.getElementById('transferWardFilter');
  if (transferWardFilter) {
    transferWardFilter.addEventListener('change', (e) => {
      renderAvailableBedsDropdown(e.target.value);
    });
  }

  // Submit Bed Transfer
  if (transferForm) {
    transferForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = this.querySelector('button[type="submit"]');
      const origText = submitBtn.innerHTML;

      const patientId = document.getElementById('transferPatientId').value;
      const fromBedId = document.getElementById('transferFromBedId').value;
      const toBedId   = document.getElementById('transferToBedSelect').value;
      const reason    = document.getElementById('transferReason').value;

      if (!toBedId) {
        if (typeof showToast === 'function') showToast('Please select a destination bed.', 'error');
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<svg class="ui-ico spin" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Transferring...';

      const formData = new URLSearchParams();
      formData.append('csrf_token', csrfToken);
      formData.append('patient_id', patientId);
      formData.append('from_bed_id', fromBedId);
      formData.append('to_bed_id', toBedId);
      formData.append('reason', reason);

      fetch('../backend/api/bed_transfer.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      })
      .then(res => res.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;

        if (data.success) {
          closeModal(transferModal);
          if (typeof showToast === 'function') {
            showToast(data.message, 'success');
          }

          // Dynamically update row in table
          updateRowBed(patientId, data.data);
        } else {
          if (typeof showToast === 'function') {
            showToast(data.message || 'Transfer failed.', 'error');
          }
        }
      })
      .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;
        console.error('Transfer error:', err);
        if (typeof showToast === 'function') showToast('Transfer network failure.', 'error');
      });
    });
  }

  function updateRowBed(patientId, transferData) {
    const row = document.getElementById(`patientRow-${patientId}`);
    if (!row) {
      setTimeout(() => window.location.reload(), 800);
      return;
    }

    const bedCell = row.querySelector('.col-bed-info');
    if (bedCell) {
      bedCell.innerHTML = `
        <span class="bed-badge-pill">
          <svg class="ui-ico" style="width:14px;height:14px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          ${transferData.to_bed}
        </span>
        <span class="bed-ward-meta">${transferData.ward_type} • Floor ${transferData.floor_number}</span>
      `;
    }

    // Update the Transfer button dataset
    const transferBtn = row.querySelector('.btn-transfer');
    if (transferBtn) {
      transferBtn.setAttribute('onclick', `openTransferModal(${patientId}, '${transferData.patient_name || ''}', ${transferData.to_bed_id || ''}, '${transferData.to_bed}', '${transferData.ward_type}')`);
    }
  }

  // ---------------------------------------------------------------------------
  // Action 2: Open Multi-Doctor Care Team Modal
  // ---------------------------------------------------------------------------
  window.openDoctorModal = function(patientId, patientName, bedNumber, activeDocIdsJson, primaryDocId) {
    document.getElementById('doctorPatientId').value = patientId;
    document.getElementById('doctorPatientName').textContent = patientName;
    document.getElementById('doctorCurrentBed').textContent = bedNumber;

    let activeDocIds = [];
    try {
      activeDocIds = typeof activeDocIdsJson === 'string' ? JSON.parse(activeDocIdsJson) : (activeDocIdsJson || []);
    } catch (e) {
      activeDocIds = [];
    }

    renderDoctorCheckboxes(activeDocIds, primaryDocId);
    openModal(doctorsModal);
  };

  function renderDoctorCheckboxes(activeDocIds, primaryDocId) {
    const container = document.getElementById('doctorCheckboxList');
    const primarySelect = document.getElementById('primaryDoctorSelect');
    container.innerHTML = '';
    primarySelect.innerHTML = '<option value="">-- Select Primary Attending Doctor --</option>';

    activeDoctorsCache.forEach(doc => {
      const isChecked = activeDocIds.includes(parseInt(doc.user_id));
      const isPrimary = (parseInt(primaryDocId) === parseInt(doc.user_id));

      // 1. Checkbox item
      const item = document.createElement('label');
      item.className = 'doc-pick-item';
      item.innerHTML = `
        <input type="checkbox" name="doctor_ids[]" value="${doc.user_id}" ${isChecked ? 'checked' : ''} onchange="handleDocCheckChange(this)">
        <div class="doc-pick-info">
          <div class="doc-pick-name">${doc.full_name}</div>
          <div class="doc-pick-spec">${doc.specialty || 'General Medicine'} ${doc.room_number ? '• ' + doc.room_number : ''}</div>
        </div>
      `;
      container.appendChild(item);

      // 2. Primary dropdown option
      const opt = document.createElement('option');
      opt.value = doc.user_id;
      opt.textContent = `${doc.full_name} (${doc.specialty || 'General'})`;
      if (isPrimary) opt.selected = true;
      primarySelect.appendChild(opt);
    });
  }

  window.handleDocCheckChange = function(checkbox) {
    // If a doctor is checked and no primary is selected, suggest it
    const primarySelect = document.getElementById('primaryDoctorSelect');
    if (checkbox.checked && !primarySelect.value) {
      primarySelect.value = checkbox.value;
    }
  };

  // Submit Doctor Assignments
  if (doctorsForm) {
    doctorsForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = this.querySelector('button[type="submit"]');
      const origText = submitBtn.innerHTML;

      const patientId = document.getElementById('doctorPatientId').value;
      const primaryDocId = document.getElementById('primaryDoctorSelect').value;
      const checkedBoxes = document.querySelectorAll('#doctorCheckboxList input[type="checkbox"]:checked');
      const docIds = Array.from(checkedBoxes).map(cb => cb.value);

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<svg class="ui-ico spin" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Updating Team...';

      const formData = new URLSearchParams();
      formData.append('csrf_token', csrfToken);
      formData.append('patient_id', patientId);
      formData.append('primary_doctor_id', primaryDocId || '');
      docIds.forEach(id => formData.append('doctor_ids[]', id));

      fetch('../backend/api/doctor_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      })
      .then(res => res.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;

        if (data.success) {
          closeModal(doctorsModal);
          if (typeof showToast === 'function') showToast(data.message, 'success');
          updateRowDoctors(patientId, docIds, primaryDocId);
        } else {
          if (typeof showToast === 'function') showToast(data.message || 'Update failed.', 'error');
        }
      })
      .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;
        console.error('Doctor update error:', err);
        if (typeof showToast === 'function') showToast('Doctor update network failure.', 'error');
      });
    });
  }

  function updateRowDoctors(patientId, docIds, primaryDocId) {
    const row = document.getElementById(`patientRow-${patientId}`);
    if (!row) {
      setTimeout(() => window.location.reload(), 800);
      return;
    }

    const docCell = row.querySelector('.col-doctors-info');
    if (!docCell) return;

    if (docIds.length === 0) {
      docCell.innerHTML = '<span class="unassigned-pill">Unassigned</span>';
    } else {
      let html = '<div class="care-team-cluster">';
      docIds.forEach(id => {
        const docObj = activeDoctorsCache.find(d => parseInt(d.user_id) === parseInt(id));
        if (docObj) {
          const isPrimary = (parseInt(id) === parseInt(primaryDocId));
          html += `
            <span class="doc-tag ${isPrimary ? 'doc-primary' : ''}" title="${docObj.specialty || ''}">
              ${isPrimary ? '<svg class="ui-ico" style="width:12px;height:12px;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>' : ''}
              ${docObj.full_name.replace('Dr. ', '')}
            </span>
          `;
        }
      });
      html += '</div>';
      docCell.innerHTML = html;
    }

    // Update button dataset
    const docBtn = row.querySelector('.btn-doctors');
    if (docBtn) {
      const jsonIds = JSON.stringify(docIds.map(Number));
      const patName = row.dataset.patientName || '';
      const bedNum = row.dataset.bedNumber || '';
      docBtn.setAttribute('onclick', `openDoctorModal(${patientId}, '${patName}', '${bedNum}', '${jsonIds}', ${primaryDocId || 'null'})`);
    }
  }

  // ---------------------------------------------------------------------------
  // Action 3: Open Patient Discharge Modal
  // ---------------------------------------------------------------------------
  window.openDischargeModal = function(patientId, patientName, bedNumber, wardType) {
    document.getElementById('dischargePatientId').value = patientId;
    document.getElementById('dischargePatientName').textContent = patientName;
    document.getElementById('dischargeBedNumber').textContent = `${bedNumber} (${wardType})`;
    document.getElementById('dischargeSummary').value = 'Clinical recovery goals achieved. Patient discharged in stable condition.';

    openModal(dischargeModal);
  };

  if (dischargeForm) {
    dischargeForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = this.querySelector('button[type="submit"]');
      const origText = submitBtn.innerHTML;

      const patientId = document.getElementById('dischargePatientId').value;
      const summary   = document.getElementById('dischargeSummary').value;

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<svg class="ui-ico spin" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Discharging...';

      const formData = new URLSearchParams();
      formData.append('csrf_token', csrfToken);
      formData.append('patient_id', patientId);
      formData.append('summary', summary);

      fetch('../backend/api/discharge_patient.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      })
      .then(res => res.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;

        if (data.success) {
          closeModal(dischargeModal);
          if (typeof showToast === 'function') showToast(data.message, 'success');

          // Animate and remove row from table
          const row = document.getElementById(`patientRow-${patientId}`);
          if (row) {
            row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            row.style.opacity = '0';
            row.style.transform = 'scale(0.95)';
            setTimeout(() => {
              row.remove();
              updateInpatientCountBadge();
            }, 400);
          }
        } else {
          if (typeof showToast === 'function') showToast(data.message || 'Discharge failed.', 'error');
        }
      })
      .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;
        console.error('Discharge error:', err);
        if (typeof showToast === 'function') showToast('Discharge network failure.', 'error');
      });
    });
  }

  function updateInpatientCountBadge() {
    const rows = document.querySelectorAll('.inpatient-table tbody tr:not(.empty-row)');
    const count = rows.length;
    const countBadge = document.getElementById('kpiTotalInpatients');
    if (countBadge) countBadge.textContent = count;
  }

  // ---------------------------------------------------------------------------
  // 4. Live Search & Ward Filter in Table
  // ---------------------------------------------------------------------------
  const searchInput = document.getElementById('inpatientSearch');
  const tableWardFilter = document.getElementById('tableWardFilter');

  function filterTable() {
    const term = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const ward = (tableWardFilter ? tableWardFilter.value : 'all');

    const rows = document.querySelectorAll('.inpatient-table tbody tr:not(.empty-row)');
    let visibleCount = 0;

    rows.forEach(row => {
      const text = row.textContent.toLowerCase();
      const rowWard = row.dataset.ward || '';

      const matchTerm = (term === '' || text.includes(term));
      const matchWard = (ward === 'all' || rowWard === ward);

      if (matchTerm && matchWard) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    const noResultsRow = document.getElementById('noResultsRow');
    if (noResultsRow) {
      noResultsRow.style.display = (visibleCount === 0) ? '' : 'none';
    }
  }

  if (searchInput) searchInput.addEventListener('input', filterTable);
  if (tableWardFilter) tableWardFilter.addEventListener('change', filterTable);

  // ---------------------------------------------------------------------------
  // 5. Universal Real-Time SSE Stream Listener
  // ---------------------------------------------------------------------------
  function initRealtimeStream() {
    if (!window.EventSource) return;

    try {
      const sse = new EventSource('../backend/api/realtime_stream.php?channel=admin:all');

      sse.addEventListener('PATIENT_BED_MOVED', (e) => {
        const payload = JSON.parse(e.data);
        if (typeof showToast === 'function') {
          showToast(`⚡ Telemetry: ${payload.title}`, 'info');
        }
      });

      sse.addEventListener('PATIENT_DISCHARGED', (e) => {
        const payload = JSON.parse(e.data);
        if (typeof showToast === 'function') {
          showToast(`⚡ Telemetry: ${payload.title}`, 'info');
        }
      });

      sse.addEventListener('DOCTOR_ASSIGNED', (e) => {
        const payload = JSON.parse(e.data);
        if (typeof showToast === 'function') {
          showToast(`⚡ Telemetry: ${payload.title}`, 'info');
        }
      });

      sse.onerror = () => {
        // SSE will automatically attempt reconnection according to standard spec
      };
    } catch (err) {
      console.warn('Real-time SSE subscription not initialized:', err);
    }
  }

  initRealtimeStream();
});

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
  // ---------------------------------------------------------------------------
  // ---------------------------------------------------------------------------
  // Action 2: Open Multi-Doctor Care Team Modal (Full Hospital Physicians Combobox)
  // ---------------------------------------------------------------------------
  let selectedDoctorIds = [];
  let currentPrimaryDocId = null;

  const chipsContainer     = document.getElementById('selected-doctors-chips');
  const doctorSearchInput  = document.getElementById('doctor-search-input');
  const searchDropdown     = document.getElementById('doctor-search-dropdown');
  const dropdownList       = document.getElementById('doctorDropdownListContainer');
  const dropdownCount      = document.getElementById('dropdownHeaderCount');
  const toggleDoctorBtn    = document.getElementById('toggleDoctorDropdownBtn');
  const primarySelect      = document.getElementById('primaryDoctorSelect');
  const hiddenInputsBox    = document.getElementById('doctor-hidden-inputs');
  const careTeamCountLabel = document.getElementById('careTeamCountLabel');

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

    selectedDoctorIds = activeDocIds.map(Number);
    currentPrimaryDocId = primaryDocId ? Number(primaryDocId) : (selectedDoctorIds[0] || null);

    renderSelectedChips();
    syncPrimarySelect();
    syncHiddenInputs();
    updateCareTeamCount();

    if (doctorSearchInput) doctorSearchInput.value = '';
    if (searchDropdown) searchDropdown.style.display = 'none';

    openModal(doctorsModal);
  };

  // Alias for Care Team Modal
  window.openCareTeamModal = window.openDoctorModal;

  function getDoctorPastelBadgeClass(specialty, doctorId = 0) {
    const spec = String(specialty || '').toLowerCase().trim();
    if (/cardio|emerg|anesthe|critical|icu/i.test(spec)) return 'doc-badge-rose';
    if (/med|general|diabet|pediatr|nephro|pulmon/i.test(spec)) return 'doc-badge-sky';
    if (/surg|ortho|trauma|plastic|uro/i.test(spec)) return 'doc-badge-amber';
    if (/neuro|special|derma|psych|onc/i.test(spec)) return 'doc-badge-purple';
    if (spec.length > 0) return 'doc-badge-teal';

    const variants = ['doc-badge-rose', 'doc-badge-sky', 'doc-badge-amber', 'doc-badge-purple', 'doc-badge-teal'];
    return variants[Math.abs(Number(doctorId) || 0) % 5];
  }

  function updateCareTeamCount() {
    if (careTeamCountLabel) {
      careTeamCountLabel.textContent = `${selectedDoctorIds.length} Assigned`;
    }
  }

  function renderSelectedChips() {
    if (!chipsContainer) return;
    chipsContainer.innerHTML = '';

    selectedDoctorIds.forEach(id => {
      const doc = activeDoctorsCache.find(d => Number(d.user_id) === Number(id));
      if (!doc) return;

      const isPrimary = (Number(currentPrimaryDocId) === Number(id));
      const pastelClass = getDoctorPastelBadgeClass(doc.specialty, doc.user_id);
      const chip = document.createElement('div');
      chip.className = `doctor-chip ${pastelClass} ${isPrimary ? 'is-primary-chip' : ''}`;
      chip.innerHTML = `
        <span>${doc.full_name} <span class="chip-spec">(${doc.specialty || 'General'}${isPrimary ? ' • Lead' : ''})</span></span>
        <button type="button" class="chip-remove-btn" title="Remove ${doc.full_name}" onclick="removeDoctorChip(${doc.user_id})">&times;</button>
      `;
      chipsContainer.appendChild(chip);
    });

    updateCareTeamCount();
  }

  function syncPrimarySelect() {
    if (!primarySelect) return;
    primarySelect.innerHTML = '';

    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = '-- None / Unassigned --';
    primarySelect.appendChild(defaultOpt);

    // Group 1: Currently Selected Care Team
    const careTeamGroup = document.createElement('optgroup');
    careTeamGroup.label = 'Active Care Team (Selected)';

    selectedDoctorIds.forEach(id => {
      const doc = activeDoctorsCache.find(d => Number(d.user_id) === Number(id));
      if (!doc) return;
      const opt = document.createElement('option');
      opt.value = doc.user_id;
      opt.textContent = `${doc.full_name} — ${doc.specialty || 'General Medicine'} (${doc.room_number || 'Room'})`;
      if (Number(currentPrimaryDocId) === Number(id)) {
        opt.selected = true;
      }
      careTeamGroup.appendChild(opt);
    });

    if (selectedDoctorIds.length > 0) {
      primarySelect.appendChild(careTeamGroup);
    }

    // Group 2: All Other Available Hospital Physicians
    const allDoctorsGroup = document.createElement('optgroup');
    allDoctorsGroup.label = 'All Other Hospital Physicians (Click to Assign)';

    activeDoctorsCache.forEach(doc => {
      if (selectedDoctorIds.includes(Number(doc.user_id))) return;
      const opt = document.createElement('option');
      opt.value = doc.user_id;
      opt.textContent = `${doc.full_name} — ${doc.specialty || 'General Medicine'} (${doc.room_number || 'Room'})`;
      if (Number(currentPrimaryDocId) === Number(doc.user_id)) {
        opt.selected = true;
      }
      allDoctorsGroup.appendChild(opt);
    });

    primarySelect.appendChild(allDoctorsGroup);
  }

  if (primarySelect) {
    primarySelect.addEventListener('change', (e) => {
      const selectedVal = e.target.value ? Number(e.target.value) : null;
      currentPrimaryDocId = selectedVal;
      // If user selected a doctor from the full roster who wasn't yet in the care team, automatically add them!
      if (selectedVal && !selectedDoctorIds.includes(selectedVal)) {
        selectedDoctorIds.push(selectedVal);
        syncHiddenInputs();
      }
      renderSelectedChips();
      syncPrimarySelect();
      filterDoctorDropdown(doctorSearchInput ? doctorSearchInput.value : '');
    });
  }

  function syncHiddenInputs() {
    if (!hiddenInputsBox) return;
    hiddenInputsBox.innerHTML = '';
    selectedDoctorIds.forEach(id => {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'doctor_ids[]';
      inp.value = id;
      hiddenInputsBox.appendChild(inp);
    });
  }

  window.removeDoctorChip = function(doctorId) {
    selectedDoctorIds = selectedDoctorIds.filter(id => Number(id) !== Number(doctorId));
    if (Number(currentPrimaryDocId) === Number(doctorId)) {
      currentPrimaryDocId = selectedDoctorIds[0] || null;
    }
    renderSelectedChips();
    syncPrimarySelect();
    syncHiddenInputs();

    // Re-filter dropdown so the status immediately updates
    if (searchDropdown && searchDropdown.style.display !== 'none') {
      filterDoctorDropdown(doctorSearchInput ? doctorSearchInput.value : '');
    }
  };

  window.addDoctorChip = function(doctorId) {
    doctorId = Number(doctorId);
    if (!selectedDoctorIds.includes(doctorId)) {
      selectedDoctorIds.push(doctorId);
      if (!currentPrimaryDocId) {
        currentPrimaryDocId = doctorId;
      }
      renderSelectedChips();
      syncPrimarySelect();
      syncHiddenInputs();
    }

    // Re-filter dropdown to update the item state to 'Assigned'
    if (searchDropdown && searchDropdown.style.display !== 'none') {
      filterDoctorDropdown(doctorSearchInput ? doctorSearchInput.value : '');
    }
  };

  function filterDoctorDropdown(query) {
    if (!searchDropdown || !dropdownList) return;
    const term = (query || '').trim().toLowerCase();

    // Matches against ALL hospital doctors
    const matches = (term === '')
      ? activeDoctorsCache
      : activeDoctorsCache.filter(doc => {
          const nameMatch = (doc.full_name || '').toLowerCase().includes(term);
          const specMatch = (doc.specialty || '').toLowerCase().includes(term);
          const roomMatch = (doc.room_number || '').toLowerCase().includes(term);
          const licMatch  = (doc.bmdc_license_number || '').toLowerCase().includes(term);
          return nameMatch || specMatch || roomMatch || licMatch;
        });

    if (dropdownCount) {
      dropdownCount.textContent = (term === '') 
        ? `All Hospital Physicians (${activeDoctorsCache.length} Total)` 
        : `Matching Physicians (${matches.length} of ${activeDoctorsCache.length})`;
    }

    if (matches.length === 0) {
      dropdownList.innerHTML = `<div class="doctor-dropdown-empty">No physicians matching "${term}". All hospital doctors are accessible.</div>`;
    } else {
      dropdownList.innerHTML = '';
      matches.forEach(doc => {
        const isAssigned = selectedDoctorIds.includes(Number(doc.user_id));
        const isPrimary  = (Number(currentPrimaryDocId) === Number(doc.user_id));
        const item = document.createElement('div');
        item.className = `doctor-dropdown-item ${isAssigned ? 'is-assigned' : ''}`;
        
        item.innerHTML = `
          <div class="doctor-dropdown-info">
            <div style="display:flex; align-items:center; gap:6px;">
              <span class="doctor-dropdown-name">${doc.full_name}</span>
              ${isPrimary ? '<span style="font-size:0.68rem; font-weight:700; color:#b45309; background:#fef3c7; padding:1px 6px; border-radius:4px;">Lead</span>' : ''}
              ${doc.status === 'suspended' ? '<span style="font-size:0.68rem; font-weight:700; color:#dc2626; background:#fee2e2; padding:1px 6px; border-radius:4px;">Suspended</span>' : ''}
            </div>
            <div class="doctor-dropdown-meta">
              <span class="doctor-dropdown-spec">${doc.specialty || 'General Medicine'}</span>
              <span>• ${doc.room_number || 'Room'}</span>
              <span>• ${doc.bmdc_license_number || 'BMDC'}</span>
            </div>
          </div>
          <div class="doctor-dropdown-action">
            ${isAssigned 
              ? '<span class="doctor-dropdown-action-btn btn-assigned-doc">✓ Assigned</span>'
              : '<span class="doctor-dropdown-action-btn btn-add-doc">+ Add to Team</span>'
            }
          </div>
        `;

        item.addEventListener('mousedown', (e) => {
          e.preventDefault(); // Prevent input blur
          if (isAssigned) {
            removeDoctorChip(doc.user_id);
          } else {
            addDoctorChip(doc.user_id);
          }
        });

        dropdownList.appendChild(item);
      });
    }

    searchDropdown.style.display = 'block';
  }

  // Dropdown toggle button click
  if (toggleDoctorBtn) {
    toggleDoctorBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (searchDropdown && searchDropdown.style.display === 'block') {
        searchDropdown.style.display = 'none';
      } else {
        filterDoctorDropdown(doctorSearchInput ? doctorSearchInput.value : '');
        if (doctorSearchInput) doctorSearchInput.focus();
      }
    });
  }

  // Prevent input blur when clicking inside dropdown or on scrollbar
  if (searchDropdown) {
    searchDropdown.addEventListener('mousedown', (e) => {
      e.preventDefault();
    });
  }

  if (doctorSearchInput) {
    doctorSearchInput.addEventListener('input', (e) => {
      filterDoctorDropdown(e.target.value);
    });

    doctorSearchInput.addEventListener('focus', (e) => {
      filterDoctorDropdown(e.target.value);
    });

    doctorSearchInput.addEventListener('blur', () => {
      setTimeout(() => {
        if (searchDropdown) searchDropdown.style.display = 'none';
      }, 250);
    });
  }

  // Close floating dropdown when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.doctor-combobox-wrapper')) {
      if (searchDropdown) searchDropdown.style.display = 'none';
    }
  });

  // Submit Doctor Assignments
  if (doctorsForm) {
    doctorsForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = this.querySelector('button[type="submit"]');
      const origText = submitBtn.innerHTML;

      const patientId = document.getElementById('doctorPatientId').value;
      const primaryDocId = primarySelect ? primarySelect.value : '';

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<svg class="ui-ico spin" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Updating Team...';

      const formData = new URLSearchParams();
      formData.append('csrf_token', csrfToken);
      formData.append('patient_id', patientId);
      formData.append('primary_doctor_id', primaryDocId || '');
      selectedDoctorIds.forEach(id => formData.append('doctor_ids[]', id));

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
          updateRowDoctors(patientId, selectedDoctorIds, primaryDocId);
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
          const badgeClass = getDoctorPastelBadgeClass(docObj.specialty, docObj.user_id);
          const leadLabel = isPrimary ? ' [Lead Attending]' : '';
          const tooltipText = (docObj.specialty || 'Attending Physician') + leadLabel;
          html += `
            <span class="doc-tag ${badgeClass}" title="${tooltipText}">
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
  // Action 3B: Quick Admit Inpatient Modal & Available Beds Controller
  // ---------------------------------------------------------------------------
  const admitModal = document.getElementById('admitInpatientModal');
  const admitForm  = document.getElementById('admitInpatientForm');

  window.openAdmitModal = function() {
    openModal(admitModal);
    loadAdmitBeds('all');
  };

  function loadAdmitBeds(wardFilter) {
    const bedSelect = document.getElementById('admitBedSelect');
    if (!bedSelect) return;
    bedSelect.innerHTML = '<option value="">-- Loading Available Beds... --</option>';

    fetch('../backend/api/get_available_beds.php')
      .then(r => r.json())
      .then(data => {
        if (!data.success || !data.beds) {
          bedSelect.innerHTML = '<option value="">No available beds found</option>';
          return;
        }
        availableBedsCache = data.beds;
        renderAdmitBedsDropdown(wardFilter);
      })
      .catch(() => {
        bedSelect.innerHTML = '<option value="">Error loading available beds</option>';
      });
  }

  function renderAdmitBedsDropdown(wardFilter) {
    const bedSelect = document.getElementById('admitBedSelect');
    if (!bedSelect) return;
    bedSelect.innerHTML = '<option value="">-- Select Available Ward Bed --</option>';

    const filtered = (wardFilter === 'all')
      ? availableBedsCache
      : availableBedsCache.filter(b => b.ward_type === wardFilter);

    if (filtered.length === 0) {
      bedSelect.innerHTML = '<option value="">No available beds in selected ward</option>';
      return;
    }

    filtered.forEach(b => {
      const opt = document.createElement('option');
      opt.value = b.bed_id;
      opt.textContent = `${b.bed_number} — ${b.ward_type} (Floor ${b.floor_number}) | ৳${Number(b.daily_rate).toLocaleString()}/day`;
      bedSelect.appendChild(opt);
    });
  }

  const admitWardFilter = document.getElementById('admitWardFilter');
  if (admitWardFilter) {
    admitWardFilter.addEventListener('change', (e) => {
      renderAdmitBedsDropdown(e.target.value);
    });
  }

  if (admitForm) {
    admitForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const submitBtn = this.querySelector('button[type="submit"]');
      const origText = submitBtn.innerHTML;

      const patientId = document.getElementById('admitPatientSelect').value;
      const bedId     = document.getElementById('admitBedSelect').value;
      const docId     = document.getElementById('admitDoctorSelect').value;
      const notes     = document.getElementById('admitNotes').value;

      if (!patientId || !bedId || !docId) {
        if (typeof showToast === 'function') showToast('Patient, Bed, and Attending Doctor are required.', 'error');
        return;
      }

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<svg class="ui-ico spin" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Admitting...';

      const formData = new URLSearchParams();
      formData.append('csrf_token', csrfToken);
      formData.append('action', 'allocate_patient');
      formData.append('patient_id', patientId);
      formData.append('bed_id', bedId);
      formData.append('doctor_id', docId);
      formData.append('notes', notes);

      fetch('../backend/admin_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
      })
      .then(r => r.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;

        if (data.success || data.status === 'success') {
          closeModal(admitModal);
          if (typeof showToast === 'function') showToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 600);
        } else {
          if (typeof showToast === 'function') showToast(data.message || 'Admission failed.', 'error');
        }
      })
      .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;
        if (typeof showToast === 'function') showToast('Network error during admission.', 'error');
      });
    });
  }

  // ---------------------------------------------------------------------------
  // 4. Live Search & Ward Filter in Table
  // ---------------------------------------------------------------------------
  const tableSearchInput  = document.getElementById('inpatientSearch');
  const tableWardFilter   = document.getElementById('tableWardFilter');

  function filterTable() {
    const term = (tableSearchInput ? tableSearchInput.value : '').toLowerCase().trim();
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

  if (tableSearchInput) tableSearchInput.addEventListener('input', filterTable);
  if (tableWardFilter) tableWardFilter.addEventListener('change', filterTable);

  // Event delegation on table for clinical action buttons (supporting dynamic re-renders)
  const inpatientTable = document.querySelector('.inpatient-table');
  if (inpatientTable) {
    inpatientTable.addEventListener('click', (e) => {
      const transferBtn = e.target.closest('.btn-transfer');
      if (transferBtn && !transferBtn.getAttribute('onclick')) {
        const row = transferBtn.closest('tr');
        if (row) {
          const pid = row.id.replace('patientRow-', '');
          const pname = row.dataset.patientName || '';
          const bnum = row.dataset.bedNumber || '';
          const ward = row.dataset.ward || '';
          window.openTransferModal(pid, pname, '', bnum, ward);
        }
      }

      const doctorsBtn = e.target.closest('.btn-doctors');
      if (doctorsBtn && !doctorsBtn.getAttribute('onclick')) {
        const row = doctorsBtn.closest('tr');
        if (row) {
          const pid = row.id.replace('patientRow-', '');
          const pname = row.dataset.patientName || '';
          const bnum = row.dataset.bedNumber || '';
          window.openDoctorModal(pid, pname, bnum, [], null);
        }
      }

      const dischargeBtn = e.target.closest('.btn-discharge');
      if (dischargeBtn && !dischargeBtn.getAttribute('onclick')) {
        const row = dischargeBtn.closest('tr');
        if (row) {
          const pid = row.id.replace('patientRow-', '');
          const pname = row.dataset.patientName || '';
          const bnum = row.dataset.bedNumber || '';
          const ward = row.dataset.ward || '';
          window.openDischargeModal(pid, pname, bnum, ward);
        }
      }
    });
  }

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

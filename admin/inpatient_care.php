<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Inpatient Care & Clinical Teams Management Console
 * Real-time bed occupancy, atomic transfers, multi-physician rounding assignments, and patient discharge telemetry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';

/**
 * Map doctor specialty or ID hash to clean, soft pastel badge classes
 */
function getDoctorPastelBadgeClass(?string $specialty, int $doctorId = 0): string {
    $spec = strtolower(trim((string)$specialty));
    if (preg_match('/cardio|emerg|anesthe|critical|icu/i', $spec)) {
        return 'doc-badge-rose';
    } elseif (preg_match('/med|general|diabet|pediatr|nephro|pulmon/i', $spec)) {
        return 'doc-badge-sky';
    } elseif (preg_match('/surg|ortho|trauma|plastic|uro/i', $spec)) {
        return 'doc-badge-amber';
    } elseif (preg_match('/neuro|special|derma|psych|onc/i', $spec)) {
        return 'doc-badge-purple';
    } elseif ($spec !== '') {
        return 'doc-badge-teal';
    }
    $variants = ['doc-badge-rose', 'doc-badge-sky', 'doc-badge-amber', 'doc-badge-purple', 'doc-badge-teal'];
    return $variants[abs($doctorId) % 5];
}

try {
    // 1. Fetch Inpatient Vital Metrics
    $totalInpatients = (int)$pdo->query("SELECT COUNT(*) FROM bed_allocations WHERE status = 'Active'")->fetchColumn();
    $availableBeds   = (int)$pdo->query("SELECT COUNT(*) FROM hospital_beds WHERE status = 'Available'")->fetchColumn();
    $occupiedBeds    = (int)$pdo->query("SELECT COUNT(*) FROM hospital_beds WHERE status = 'Occupied'")->fetchColumn();
    $activePhysicians = (int)$pdo->query("SELECT COUNT(DISTINCT doctor_id) FROM patient_doctor_assignments WHERE status = 'Active'")->fetchColumn();

    // 2. Fetch Admitted Patients
    $patientsStmt = $pdo->query("
        SELECT 
            ba.allocation_id,
            ba.admitted_at,
            ba.attending_doctor_id,
            u.user_id AS patient_id,
            u.full_name AS patient_name,
            u.email AS patient_email,
            u.phone AS patient_phone,
            u.gender,
            COALESCE(TIMESTAMPDIFF(YEAR, pat.dob, CURDATE()), u.age, 0) AS age,
            COALESCE(pat.blood_group, u.blood_group, 'Unknown') AS blood_group,
            pat.patient_uid,
            pat.dob,
            b.bed_id,
            b.bed_number,
            b.ward_type,
            b.floor_number,
            b.daily_rate
        FROM bed_allocations ba
        JOIN users u ON ba.patient_id = u.user_id
        LEFT JOIN patients pat ON u.user_id = pat.user_id
        JOIN hospital_beds b ON ba.bed_id = b.bed_id
        WHERE ba.status = 'Active'
        ORDER BY ba.admitted_at DESC
    ");
    $inpatients = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Doctor Assignments for Inpatients
    $docAssignStmt = $pdo->query("
        SELECT 
            pda.assignment_id,
            pda.patient_id,
            pda.doctor_id,
            pda.is_primary,
            doc.full_name AS doctor_name,
            COALESCE(dp.specialty, doc.department, 'General Medicine') AS specialty
        FROM patient_doctor_assignments pda
        JOIN users doc ON pda.doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
        WHERE pda.status = 'Active'
        ORDER BY pda.is_primary DESC, doc.full_name ASC
    ");
    $assignments = $docAssignStmt->fetchAll(PDO::FETCH_ASSOC);

    $patientDoctors = [];
    foreach ($assignments as $a) {
        $pid = (int)$a['patient_id'];
        if (!isset($patientDoctors[$pid])) {
            $patientDoctors[$pid] = [];
        }
        $patientDoctors[$pid][] = $a;
    }

    // 4. Fetch All Active & Available Doctors for Care Teams
    $allDoctorsStmt = $pdo->query("
        SELECT 
            u.user_id,
            u.full_name,
            COALESCE(dp.specialty, u.department, 'General Medicine') AS specialty,
            COALESCE(dp.room_number, 'Consultation Wing') AS room_number,
            COALESCE(dp.bmdc_license_number, u.license_id, 'N/A') AS bmdc_license_number,
            u.email,
            u.phone,
            u.status
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.role = 'Doctor' AND u.status IN ('active', 'suspended')
        ORDER BY (u.status = 'active') DESC, u.full_name ASC
    ");
    $activeDoctors = $allDoctorsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Unique Ward Types for Filter
    $wardTypesStmt = $pdo->query("SELECT DISTINCT ward_type FROM hospital_beds ORDER BY ward_type ASC");
    $wardTypes = $wardTypesStmt->fetchAll(PDO::FETCH_COLUMN);

    // 6. Fetch Registered Non-Admitted Patients for Quick Admission Modal
    $unadmittedPatientsStmt = $pdo->query("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender,
               COALESCE(TIMESTAMPDIFF(YEAR, pat.dob, CURDATE()), u.age, 0) AS age,
               COALESCE(pat.blood_group, u.blood_group, 'Unknown') AS blood_group,
               pat.patient_uid, pat.dob
        FROM users u
        LEFT JOIN patients pat ON u.user_id = pat.user_id
        WHERE u.role = 'Patient' 
          AND u.user_id NOT IN (
              SELECT patient_id FROM bed_allocations WHERE status = 'Active'
          )
        ORDER BY u.full_name ASC
    ");
    $eligiblePatients = $unadmittedPatientsStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Inpatient Care Controller Error: " . $e->getMessage());
    die("A secure clinical database connection failure occurred. Please contact hospital IT.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Inpatient Care & Clinical Teams</title>

  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">

  <!-- Typography -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Layout & Component Styles -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/inpatient-care.css">
</head>
<body>

  <!-- Shared Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Main Viewport -->
  <main class="viewport-full">

    <!-- Header Banner -->
    <div class="inpatient-header-banner">
      <div class="inpatient-header-title">
        <h1>
          <svg class="ui-ico" style="stroke: #0d9488; width: 26px; height: 26px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          Inpatient Care & Clinical Teams
        </h1>
        <p>Live ward admissions census, atomic bed relocations, multi-physician rounding assignments, and patient discharge telemetry</p>
      </div>
      <div class="inpatient-header-chips">
        <div class="header-telemetry-chip">
          <span class="pulse-dot"></span>
          Real-Time Telemetry Active
        </div>
        <button class="btn-ipc-action" style="background: #0d9488; color: #ffffff; padding: 7px 14px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(13,148,136,0.2);" onclick="openAdmitModal();">
          <svg class="ui-ico ui-ico-sm" style="stroke: #ffffff; width: 15px; height: 15px;" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          + Admit New Inpatient
        </button>
        <button class="btn-refresh-telemetry" onclick="window.location.reload();">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
          Refresh Data
        </button>
      </div>
    </div>

    <!-- Vital Metrics Cards -->
    <div class="inpatient-metrics-grid">
      <div class="inpatient-metric-card">
        <div class="metric-card-top">
          <span class="metric-card-label">Admitted Inpatients</span>
          <div class="metric-card-icon" style="background:#f0fdfa; color:#0d9488; border:1px solid #ccfbf1;">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
          </div>
        </div>
        <div class="metric-value" id="kpiTotalInpatients"><?= $totalInpatients ?></div>
        <div class="metric-subtext">Active inpatient care cases</div>
      </div>

      <div class="inpatient-metric-card">
        <div class="metric-card-top">
          <span class="metric-card-label">Occupied Beds</span>
          <div class="metric-card-icon" style="background:#fff1f2; color:#e11d48; border:1px solid #fecdd3;">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          </div>
        </div>
        <div class="metric-value"><?= $occupiedBeds ?></div>
        <div class="metric-subtext">Strict 1-to-1 occupancy locked</div>
      </div>

      <div class="inpatient-metric-card">
        <div class="metric-card-top">
          <span class="metric-card-label">Available Capacity</span>
          <div class="metric-card-icon" style="background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0;">
            <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
          </div>
        </div>
        <div class="metric-value"><?= $availableBeds ?></div>
        <div class="metric-subtext">Open for immediate transfer</div>
      </div>

      <div class="inpatient-metric-card">
        <div class="metric-card-top">
          <span class="metric-card-label">Assigned Physicians</span>
          <div class="metric-card-icon" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe;">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          </div>
        </div>
        <div class="metric-value"><?= $activePhysicians ?></div>
        <div class="metric-subtext">Multi-doctor rounding active</div>
      </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="inpatient-controls-bar">
      <div class="controls-search-group">
        <input type="text" id="inpatientSearch" class="controls-search-input" placeholder="Search by patient, bed #, ward, or attending doctor...">
      </div>
      <div class="controls-filter-group">
        <select id="tableWardFilter" class="ward-filter-select">
          <option value="all">All Hospital Wards</option>
          <?php foreach ($wardTypes as $wt): ?>
            <option value="<?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Inpatient Roster Card -->
    <section class="inpatient-stack-card">
      <div class="inpatient-card-header">
        <h2>
          <svg class="ui-ico" style="width:18px;height:18px;stroke:var(--ipc-teal-600);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
          Admitted Inpatient Roster & Clinical Care Teams
        </h2>
        <span class="patient-id-chip"><?= count($inpatients) ?> Active Cases</span>
      </div>

      <div class="inpatient-table-wrap">
        <table class="inpatient-table">
          <thead>
            <tr>
              <th>Patient File</th>
              <th>Assigned Bed & Ward</th>
              <th>Admission Time</th>
              <th>Attending Care Team</th>
              <th style="text-align: right;">Clinical Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($inpatients)): ?>
              <tr class="empty-row">
                <td colspan="5" style="text-align: center; color: var(--ipc-slate-400); padding: 3rem;">
                  No active inpatients currently admitted. All ward beds are vacant or sanitizing.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($inpatients as $p): ?>
                <?php
                  $pid = (int)$p['patient_id'];
                  $docs = $patientDoctors[$pid] ?? [];
                  $docIds = array_map(fn($d) => (int)$d['doctor_id'], $docs);
                  $primaryDoc = array_values(array_filter($docs, fn($d) => (bool)$d['is_primary']));
                  $primaryDocId = !empty($primaryDoc) ? (int)$primaryDoc[0]['doctor_id'] : ($p['attending_doctor_id'] ? (int)$p['attending_doctor_id'] : null);
                  $admittedDuration = (new DateTime($p['admitted_at']))->diff(new DateTime());
                  $daysStay = $admittedDuration->days;
                ?>
                <tr id="patientRow-<?= $pid ?>" 
                    data-patient-name="<?= htmlspecialchars($p['patient_name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-bed-number="<?= htmlspecialchars($p['bed_number'], ENT_QUOTES, 'UTF-8') ?>"
                    data-ward="<?= htmlspecialchars($p['ward_type'], ENT_QUOTES, 'UTF-8') ?>">
                  
                  <!-- Patient Info -->
                  <td>
                    <div>
                      <strong style="color: var(--ipc-slate-900); font-size: 0.94rem;">
                        <?= htmlspecialchars($p['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                      </strong>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center; margin-top: 4px;">
                      <span class="patient-id-chip"><?= htmlspecialchars(!empty($p['patient_uid']) ? $p['patient_uid'] : ('MP-' . date('Y') . '-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT)), ENT_QUOTES, 'UTF-8') ?></span>
                      <span style="font-size: 0.74rem; color: var(--ipc-slate-400);">
                        <?= htmlspecialchars($p['gender'] ?? 'Male', ENT_QUOTES, 'UTF-8') ?><?= !empty($p['age']) ? ', ' . (int)$p['age'] . 'y' : '' ?>
                      </span>
                      <span style="font-size: 0.72rem; font-weight: 700; color: #dc2626; background: #fee2e2; padding: 1px 5px; border-radius: 4px;">
                        <?= htmlspecialchars($p['blood_group'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </div>
                  </td>

                  <!-- Bed Info -->
                  <td class="col-bed-info">
                    <span class="bed-badge-pill">
                      <svg class="ui-ico" style="width:14px;height:14px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
                      <?= htmlspecialchars($p['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="bed-ward-meta">
                      <?= htmlspecialchars($p['ward_type'], ENT_QUOTES, 'UTF-8') ?> • Floor <?= (int)$p['floor_number'] ?>
                    </span>
                  </td>

                  <!-- Admission Date & Stay -->
                  <td>
                    <div style="font-size: 0.84rem; font-weight: 600; color: var(--ipc-slate-800);">
                      <?= htmlspecialchars(date('d M Y, h:i A', strtotime($p['admitted_at'])), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <span style="font-size: 0.74rem; color: var(--ipc-slate-400);">
                      <?= $daysStay === 0 ? 'Admitted Today' : "{$daysStay} day(s) admitted" ?>
                    </span>
                  </td>

                  <!-- Care Team Doctors -->
                  <td class="col-doctors-info">
                    <?php if (empty($docs)): ?>
                      <span class="unassigned-pill">Unassigned</span>
                    <?php else: ?>
                      <div class="care-team-cluster">
                        <?php foreach ($docs as $d): ?>
                          <?php 
                            $badgeClass = getDoctorPastelBadgeClass($d['specialty'] ?? '', (int)$d['doctor_id']);
                            $leadLabel = !empty($d['is_primary']) ? ' [Lead Attending]' : '';
                            $tooltipText = (!empty($d['specialty']) ? $d['specialty'] : 'Attending Physician') . $leadLabel;
                          ?>
                          <span class="doc-tag <?= $badgeClass ?>" title="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(str_replace('Dr. ', '', $d['doctor_name']), ENT_QUOTES, 'UTF-8') ?>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <!-- Clinical Actions -->
                  <td style="text-align: right;">
                    <div class="action-buttons-group">
                      <!-- 1. Bed Transfer Action -->
                      <button class="btn-ipc-action btn-transfer"
                              onclick="openTransferModal(<?= $pid ?>, '<?= htmlspecialchars($p['patient_name'], ENT_QUOTES, 'UTF-8') ?>', <?= (int)$p['bed_id'] ?>, '<?= htmlspecialchars($p['bed_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($p['ward_type'], ENT_QUOTES, 'UTF-8') ?>')"
                              title="Transfer Bed">
                        <svg class="ui-ico" style="width:13px;height:13px;" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                        Transfer
                      </button>

                      <!-- 2. Multi-Doctor Assignment Action -->
                      <button class="btn-ipc-action btn-doctors"
                              onclick="openDoctorModal(<?= $pid ?>, '<?= htmlspecialchars($p['patient_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($p['bed_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(json_encode($docIds), ENT_QUOTES, 'UTF-8') ?>', <?= $primaryDocId ?: 'null' ?>)"
                              title="Manage Care Team Doctors">
                        <svg class="ui-ico" style="width:13px;height:13px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
                        Care Team
                      </button>

                      <!-- 3. Discharge Action -->
                      <button class="btn-ipc-action btn-discharge"
                              onclick="openDischargeModal(<?= $pid ?>, '<?= htmlspecialchars($p['patient_name'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($p['bed_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($p['ward_type'], ENT_QUOTES, 'UTF-8') ?>')"
                              title="Discharge Inpatient">
                        <svg class="ui-ico" style="width:13px;height:13px;" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                        Discharge
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>

            <tr id="noResultsRow" style="display: none;">
              <td colspan="5" style="text-align: center; color: var(--ipc-slate-400); padding: 2.5rem;">
                No matching inpatients found for the current search filter.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <!-- =========================================================================
       MODAL 1: ATOMIC BED TRANSFER
       ========================================================================= -->
  <div class="inpatient-modal-backdrop" id="bedTransferModal">
    <div class="inpatient-modal">
      <div class="inpatient-modal-header">
        <h3>
          <svg class="ui-ico" style="stroke: #0d9488;" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
          Transfer Patient to Available Bed
        </h3>
        <button class="modal-close-btn" onclick="closeIpcModal('bedTransferModal')">&times;</button>
      </div>
      <form id="transferBedForm">
        <div class="inpatient-modal-body">
          <input type="hidden" id="transferPatientId" name="patient_id">
          <input type="hidden" id="transferFromBedId" name="from_bed_id">

          <div class="modal-patient-context">
            <div>
              <div style="font-size: 0.76rem; color: var(--ipc-teal-700); font-weight: 700; text-transform: uppercase;">Inpatient</div>
              <strong id="transferPatientName" style="color: var(--ipc-slate-900); font-size: 1rem;">-</strong>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.76rem; color: var(--ipc-slate-400); font-weight: 600;">Current Bed</div>
              <span id="transferCurrentBed" class="bed-badge-pill">-</span>
            </div>
          </div>

          <div class="form-group-ipc">
            <label for="transferWardFilter">Filter Destination by Ward</label>
            <select id="transferWardFilter" class="ipc-select">
              <option value="all">All Hospital Wards</option>
              <?php foreach ($wardTypes as $wt): ?>
                <option value="<?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group-ipc">
            <label for="transferToBedSelect">Select New Available Bed *</label>
            <select id="transferToBedSelect" name="to_bed_id" class="ipc-select" required>
              <option value="">-- Loading Available Beds... --</option>
            </select>
            <span style="font-size: 0.72rem; color: var(--ipc-slate-400); margin-top: 4px; display: block;">
              Only beds with status 'Available' are eligible. Reallocating Bed A to Bed B is strictly atomic.
            </span>
          </div>

          <div class="form-group-ipc">
            <label for="transferReason">Clinical Reason / Relocation Note</label>
            <input type="text" id="transferReason" name="reason" class="ipc-input" placeholder="e.g. Upgraded to VIP Suite, Transferred to ICU monitoring">
          </div>
        </div>
        <div class="inpatient-modal-footer">
          <button type="button" class="btn-ipc-cancel" onclick="closeIpcModal('bedTransferModal')">Cancel</button>
          <button type="submit" class="btn-ipc-submit">
            <svg class="ui-ico" style="width:14px;height:14px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Execute Atomic Transfer
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       MODAL 2: MULTI-DOCTOR CARE TEAM ASSIGNMENT
       ========================================================================= -->
  <div class="inpatient-modal-backdrop" id="doctorAssignmentModal">
    <div class="inpatient-modal">
      <div class="inpatient-modal-header">
        <h3>
          <svg class="ui-ico" style="stroke: #2563eb;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          Manage Attending Care Team
        </h3>
        <button class="modal-close-btn" onclick="closeIpcModal('doctorAssignmentModal')">&times;</button>
      </div>
      <form id="assignDoctorsForm">
        <div class="inpatient-modal-body">
          <input type="hidden" id="doctorPatientId" name="patient_id">

          <div class="modal-patient-context" style="background: #eff6ff; border-color: #bfdbfe;">
            <div>
              <div style="font-size: 0.76rem; color: #1d4ed8; font-weight: 700; text-transform: uppercase;">Inpatient</div>
              <strong id="doctorPatientName" style="color: var(--ipc-slate-900); font-size: 1rem;">-</strong>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.76rem; color: var(--ipc-slate-400); font-weight: 600;">Location</div>
              <span id="doctorCurrentBed" class="bed-badge-pill" style="background:#dbeafe; color:#1e40af; border-color:#bfdbfe;">-</span>
            </div>
          </div>

          <div class="form-group-ipc">
            <label for="primaryDoctorSelect">Designated Primary Attending Physician</label>
            <select id="primaryDoctorSelect" name="primary_doctor_id" class="ipc-select">
              <option value="">-- Select Primary Attending Doctor --</option>
            </select>
            <span style="font-size: 0.72rem; color: var(--ipc-slate-400); margin-top: 4px; display: block;">
              Select any hospital physician to lead rounds. Choosing a doctor will automatically add them to the care team.
            </span>
          </div>

          <div class="form-group-ipc">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <label style="margin-bottom: 0;">Assigned Care Team Physicians (Consultants)</label>
              <span id="careTeamCountLabel" style="font-size: 0.74rem; font-weight: 700; color: #0d9488; background: #ccfbf1; padding: 1px 7px; border-radius: 10px;">0 Assigned</span>
            </div>

            <!-- Selected Doctors Clinical Chips Container -->
            <div id="selected-doctors-chips" class="selected-doctors-chips flex flex-wrap gap-2"></div>

            <!-- Searchable Combobox with Toggle Button -->
            <label style="font-size: 0.76rem; color: var(--ipc-slate-600); margin-top: 10px; margin-bottom: 4px; font-weight: 600;">
              Add / Search All Hospital Physicians:
            </label>
            <div class="doctor-combobox-wrapper">
              <div class="doctor-input-container">
                <svg class="doctor-search-ico" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="doctor-search-input" class="ipc-input doctor-search-field" placeholder="Click or type doctor name, specialty, or room..." autocomplete="off">
                <button type="button" id="toggleDoctorDropdownBtn" class="doctor-dropdown-toggle-btn" title="Show all hospital doctors">
                  <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
              </div>

              <!-- Floating All-Doctors Dropdown Panel -->
              <div id="doctor-search-dropdown" class="doctor-search-dropdown" style="display: none;">
                <div class="doctor-dropdown-header">
                  <span id="dropdownHeaderCount">All Hospital Physicians (<?= count($activeDoctors) ?> Total)</span>
                  <span style="font-size: 0.70rem; color: var(--ipc-slate-400);">Click to add/remove</span>
                </div>
                <div id="doctorDropdownListContainer" class="doctor-dropdown-list"></div>
              </div>
            </div>

            <!-- Dynamically Synced Hidden Inputs -->
            <div id="doctor-hidden-inputs"></div>
          </div>
        </div>
        <div class="inpatient-modal-footer">
          <button type="button" class="btn-ipc-cancel" onclick="closeIpcModal('doctorAssignmentModal')">Cancel</button>
          <button type="submit" class="btn-ipc-submit" style="background: #2563eb;">
            <svg class="ui-ico" style="width:14px;height:14px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Save Care Team
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       MODAL 2B: QUICK ADMIT INPATIENT & ALLOCATE BED
       ========================================================================= -->
  <div class="inpatient-modal-backdrop" id="admitInpatientModal">
    <div class="inpatient-modal">
      <div class="inpatient-modal-header">
        <h3>
          <svg class="ui-ico" style="stroke: #0d9488;" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Admit New Inpatient & Assign Bed
        </h3>
        <button class="modal-close-btn" onclick="closeIpcModal('admitInpatientModal')">&times;</button>
      </div>
      <form id="admitInpatientForm">
        <div class="inpatient-modal-body">
          <div class="form-group-ipc">
            <label for="admitPatientSelect">Select Registered Patient *</label>
            <select id="admitPatientSelect" name="patient_id" class="ipc-select" required>
              <option value="">-- Choose Patient for Admission --</option>
              <?php foreach ($eligiblePatients as $ep): ?>
                <?php
                  $epUid = !empty($ep['patient_uid']) ? $ep['patient_uid'] : ('MP-' . date('Y') . '-' . str_pad((string)$ep['user_id'], 5, '0', STR_PAD_LEFT));
                  $epAgeStr = !empty($ep['age']) ? ((int)$ep['age'] . 'y') : 'Age N/A';
                ?>
                <option value="<?= (int)$ep['user_id'] ?>">
                  <?= htmlspecialchars($ep['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($epUid, ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars($ep['gender'] ?? 'Male', ENT_QUOTES, 'UTF-8') ?>, <?= $epAgeStr ?>, <?= htmlspecialchars($ep['blood_group'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <span style="font-size: 0.72rem; color: var(--ipc-slate-400); margin-top: 4px; display: block;">
              Only patients without active admissions are listed. Inpatients can hold at most one bed at a time.
            </span>
          </div>

          <div class="form-group-ipc">
            <label for="admitWardFilter">Filter Available Beds by Ward</label>
            <select id="admitWardFilter" class="ipc-select">
              <option value="all">All Hospital Wards</option>
              <?php foreach ($wardTypes as $wt): ?>
                <option value="<?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group-ipc">
            <label for="admitBedSelect">Select Available Bed *</label>
            <select id="admitBedSelect" name="bed_id" class="ipc-select" required>
              <option value="">-- Loading Available Beds... --</option>
            </select>
          </div>

          <div class="form-group-ipc">
            <label for="admitDoctorSelect">Attending Physician (Lead Care Doctor) *</label>
            <select id="admitDoctorSelect" name="doctor_id" class="ipc-select" required>
              <option value="">-- Choose Attending Doctor from Full Roster --</option>
              <?php foreach ($activeDoctors as $doc): ?>
                <option value="<?= (int)$doc['user_id'] ?>">
                  <?= htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($doc['specialty'] ?? 'General Medicine', ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($doc['room_number'] ?? 'Consultation', ENT_QUOTES, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group-ipc">
            <label for="admitNotes">Admission Diagnosis / Clinical Reason</label>
            <input type="text" id="admitNotes" name="notes" class="ipc-input" placeholder="e.g. Acute chest pain, Post-operative surgical recovery, ICU monitoring">
          </div>
        </div>
        <div class="inpatient-modal-footer">
          <button type="button" class="btn-ipc-cancel" onclick="closeIpcModal('admitInpatientModal')">Cancel</button>
          <button type="submit" class="btn-ipc-submit" style="background: #0d9488;">
            <svg class="ui-ico" style="width:14px;height:14px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Confirm Admission & Allocate Bed
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- =========================================================================
       MODAL 3: PATIENT DISCHARGE CONFIRMATION
       ========================================================================= -->
  <div class="inpatient-modal-backdrop" id="dischargeModal">
    <div class="inpatient-modal">
      <div class="inpatient-modal-header">
        <h3>
          <svg class="ui-ico" style="stroke: #e11d48;" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
          Confirm Inpatient Discharge
        </h3>
        <button class="modal-close-btn" onclick="closeIpcModal('dischargeModal')">&times;</button>
      </div>
      <form id="dischargePatientForm">
        <div class="inpatient-modal-body">
          <input type="hidden" id="dischargePatientId" name="patient_id">

          <div class="modal-patient-context" style="background: #fff1f2; border-color: #fecdd3;">
            <div>
              <div style="font-size: 0.76rem; color: #be123c; font-weight: 700; text-transform: uppercase;">Discharging Patient</div>
              <strong id="dischargePatientName" style="color: var(--ipc-slate-900); font-size: 1rem;">-</strong>
            </div>
            <div style="text-align: right;">
              <div style="font-size: 0.76rem; color: var(--ipc-slate-400); font-weight: 600;">Releasing Bed</div>
              <span id="dischargeBedNumber" class="bed-badge-pill" style="background:#ffe4e6; color:#9f1239; border-color:#fecdd3;">-</span>
            </div>
          </div>

          <div style="background: #eff6ff; border: 1px solid #dbeafe; border-left: 4px solid #0284c7; border-radius: 6px; padding: 12px; margin-bottom: 16px; font-size: 0.82rem; color: #0369a1;">
            <strong>Clinical Sanitization Protocol:</strong> Discharging this inpatient will transition the bed immediately into <strong>'Sanitizing'</strong> status in the branch UV/Chemical Housekeeping queue. The bed will not be available for new admissions until clinical decontamination is certified.
          </div>

          <div class="form-group-ipc">
            <label for="dischargeSummary">Discharge Summary & Discharge Instructions</label>
            <textarea id="dischargeSummary" name="summary" class="ipc-textarea" rows="3" placeholder="Enter clinical recovery summary or follow-up instructions..."></textarea>
          </div>
        </div>
        <div class="inpatient-modal-footer">
          <button type="button" class="btn-ipc-cancel" onclick="closeIpcModal('dischargeModal')">Cancel</button>
          <button type="submit" class="btn-ipc-submit" style="background: #e11d48;">
            <svg class="ui-ico" style="width:14px;height:14px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Confirm & Discharge
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Active Doctors JSON Store for JS -->
  <script id="activeDoctorsData" type="application/json">
    <?= json_encode($activeDoctors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
  </script>

  <!-- External Clean JavaScript Controller -->
  <script src="../assets/js/admin/inpatient_care.js"></script>

</body>
</html>

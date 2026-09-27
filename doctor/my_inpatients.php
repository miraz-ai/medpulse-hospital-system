<?php
/**
 * MedPulse Doctor Portal — My Inpatients & Daily Clinical Rounds
 * Real-time bed occupancy, clinical rounding logs, and attending patient supervision.
 */

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';

$doctorUserId = (int)$_SESSION['user_id'];

// Doctor Info
try {
    $docStmt = $pdo->prepare("
        SELECT u.full_name, dp.specialty, dp.designation, dp.military_rank, dp.qualifications, dp.bmdc_license_number
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $docStmt->execute([$doctorUserId]);
    $doctor = $docStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $doctor = null;
}

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? 'Doctor');
$displayName = formatDoctorTitle($cleanName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Surgery & Critical Care', ENT_QUOTES, 'UTF-8');

// Fetch Active Inpatients scoped to session hospital (Admissions + Bed Allocations)
try {
    $sessionHospitalId = (int)($_SESSION['hospital_id'] ?? 1);
    $dpDocId = (int)($doctor['doctor_id'] ?? 0);
    $inpatStmt = $pdo->prepare("
        SELECT 
            COALESCE(a.admission_id, ba.allocation_id) AS admission_id,
            a.admission_number,
            COALESCE(a.admitted_at, ba.admitted_at) AS admitted_at,
            COALESCE(a.status, ba.status, 'Admitted') AS status,
            COALESCE(a.primary_diagnosis, a.admission_reason, 'Clinical Inpatient Care') AS primary_diagnosis,
            COALESCE(a.triage_acuity, 'Routine') AS triage_acuity,
            COALESCE(a.daily_rate, hb.daily_rate, hb.price_per_day, 0.00) AS daily_rate,
            u.user_id AS patient_id,
            u.full_name AS patient_name,
            u.email,
            u.phone,
            u.gender,
            COALESCE(TIMESTAMPDIFF(YEAR, pat.dob, CURDATE()), u.age, 0) AS age,
            COALESCE(pat.blood_group, u.blood_group, 'Unknown') AS blood_group,
            COALESCE(a.patient_uid, pat.patient_uid, CONCAT('MP-P', LPAD(u.user_id, 5, '0'))) AS patient_uid,
            pat.dob,
            hb.bed_id,
            hb.bed_number,
            hb.ward_type,
            hb.floor_number,
            h.name AS hospital_name,
            COALESCE(stf_u.full_name, 'Admission Desk Officer') AS admitting_staff_name,
            COALESCE(stf.role_title, 'Frontdesk Registrar') AS admitting_staff_role,
            CASE 
                WHEN COALESCE(a.admitted_at, ba.admitted_at) >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1
                ELSE 0 
            END AS is_new_intake
        FROM admissions a
        LEFT JOIN bed_allocations ba ON (ba.bed_id = a.bed_id AND ba.patient_id = a.patient_id AND ba.status = 'Active')
        JOIN users u ON a.patient_id = u.user_id
        LEFT JOIN patients pat ON (pat.user_id = u.user_id OR pat.id = u.user_id)
        JOIN hospital_beds hb ON a.bed_id = hb.bed_id
        JOIN hospitals h ON a.hospital_id = h.hospital_id
        LEFT JOIN staff stf ON a.admitting_staff_id = stf.staff_id
        LEFT JOIN users stf_u ON stf.user_id = stf_u.user_id
        WHERE (a.attending_doctor_id = :doc_id OR a.attending_doctor_id = :dp_id)
          AND a.status = 'Admitted'
        ORDER BY a.admitted_at DESC
    ");
    $inpatStmt->execute([':doc_id' => $doctorUserId, ':dp_id' => $dpDocId]);
    $activeInpatients = $inpatStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if admissions table has no records for legacy allocations
    if (empty($activeInpatients)) {
        $legacyStmt = $pdo->prepare("
            SELECT ba.allocation_id, ba.admitted_at, ba.status,
                   'Clinical Inpatient Care' AS primary_diagnosis,
                   'Routine' AS triage_acuity,
                   u.user_id AS patient_id, u.full_name AS patient_name, u.email, u.phone, u.gender,
                   COALESCE(TIMESTAMPDIFF(YEAR, pat.dob, CURDATE()), u.age, 0) AS age,
                   COALESCE(pat.blood_group, u.blood_group, 'Unknown') AS blood_group,
                   COALESCE(pat.patient_uid, CONCAT('MP-P', LPAD(u.user_id, 5, '0'))) AS patient_uid,
                   pat.dob,
                   hb.bed_id, hb.bed_number, hb.ward_type, hb.floor_number, hb.daily_rate,
                   'Admission Desk Officer' AS admitting_staff_name,
                   'Frontdesk Registrar' AS admitting_staff_role,
                   0 AS is_new_intake
            FROM bed_allocations ba
            JOIN users u ON ba.patient_id = u.user_id
            LEFT JOIN patients pat ON u.user_id = pat.user_id
            JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
            WHERE (ba.attending_doctor_id = :doc_id OR ba.attending_doctor_id = :dp_id)
              AND ba.status = 'Active'
            ORDER BY ba.admitted_at DESC
        ");
        $legacyStmt->execute([':doc_id' => $doctorUserId, ':dp_id' => $dpDocId]);
        $activeInpatients = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("my_inpatients fetch error: " . $e->getMessage());
    $activeInpatients = [];
}

// Fetch Discharged Inpatients History
try {
    $historyStmt = $pdo->prepare("
        SELECT ba.allocation_id, ba.admitted_at, ba.discharged_at, ba.status,
               u.full_name AS patient_name, hb.bed_number, hb.ward_type
        FROM bed_allocations ba
        JOIN users u ON ba.patient_id = u.user_id
        JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
        WHERE ba.attending_doctor_id = :doc_id 
          AND ba.status != 'Active'
          AND hb.hospital_id = :hid
        ORDER BY ba.allocation_id DESC
        LIMIT 10
    ");
    $historyStmt->execute([':doc_id' => $doctorUserId, ':hid' => $sessionHospitalId]);
    $pastInpatients = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pastInpatients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Inpatients &amp; Daily Rounds</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    /* Soft pulse badge: New Ward Intake */
    .badge-new-ward-intake {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #f0fdfa;
      color: #0f766e;
      border: 1px solid #99f6e4;
      padding: 3px 9px;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      box-shadow: 0 2px 6px rgba(13, 148, 136, 0.15);
    }
    .dot-intake-pulse {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #0d9488;
      box-shadow: 0 0 0 0 rgba(13, 148, 136, 0.7);
      animation: intakePulse 1.8s infinite;
    }
    @keyframes intakePulse {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(13, 148, 136, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(13, 148, 136, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(13, 148, 136, 0); }
    }
    .badge-admitted-live {
      background: rgba(16, 185, 129, 0.1);
      color: #047857;
      border: 1px solid rgba(16, 185, 129, 0.25);
      border-radius: 9999px;
      padding: 3px 9px;
      font-size: 0.74rem;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .dot-live-pulse {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 6px #10b981;
      animation: liveDotPulse 1.6s infinite;
    }
    @keyframes liveDotPulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(0.85); }
    }
    .badge-acuity-pill {
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      padding: 2px 6px;
      border-radius: 4px;
      background: #e0f2fe;
      color: #0369a1;
      border: 1px solid #bae6fd;
    }
    .dossier-id-chip {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 700;
      font-size: 0.74rem;
      color: #0284c7;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      padding: 2px 6px;
      border-radius: 4px;
      display: inline-block;
    }
  </style>
</head>
<body>

  <!-- Shared Production Doctor Sidebar -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <!-- Main Viewport -->
  <main class="viewport-full">

    <!-- Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          My Inpatients &amp; Clinical Rounds
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
        </h1>
        <p>Real-time inpatient bed allocations, attending clinical rounds, and bedside care supervision for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Initiating ward round checklist telemetry...', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Start Round Checklist
        </button>
        <a href="dashboard.php" class="btn-action-gradient" style="text-decoration: none;">
          &larr; Doctor Dashboard
        </a>
      </div>
    </div>

    <!-- Active Admitted Inpatients -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-teal); width: 22px; height: 22px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            Active Inpatient Census (<?= count($activeInpatients) ?> Admitted)
          </h3>
          <p>Supervise admitted patients, track length of stay, and log daily clinical notes</p>
        </div>
        <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-size: 0.76rem;">
          ATTENDING SUPERVISION ACTIVE
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Patient &amp; UHID</th>
              <th>Assigned Bed / Ward</th>
              <th>Primary Diagnosis &amp; Acuity</th>
              <th>Admitting Staff</th>
              <th>Admission Date</th>
              <th>Care Status</th>
              <th style="text-align: right;">Clinical Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($activeInpatients)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No inpatients are currently assigned to your attending supervision.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($activeInpatients as $inpat): 
                $admitTime = strtotime($inpat['admitted_at']);
                $days = max(1, ceil((time() - $admitTime) / 86400));
                $pInitials = strtoupper(substr($inpat['patient_name'], 0, 2));
                $acuity = $inpat['triage_acuity'] ?? 'Routine';
              ?>
                <tr>
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials"><?= htmlspecialchars($pInitials, ENT_QUOTES, 'UTF-8') ?></div>
                      <div>
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                          <strong style="font-size: 0.92rem; color: var(--text-heading);">
                            <?= htmlspecialchars($inpat['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                          </strong>
                          <?php if (!empty($inpat['is_new_intake'])): ?>
                            <span class="badge-new-ward-intake">
                              <span class="dot-intake-pulse"></span>
                              New Ward Intake
                            </span>
                          <?php endif; ?>
                        </div>
                        <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">
                          UHID: <span class="dossier-id-chip"><?= htmlspecialchars($inpat['patient_uid'], ENT_QUOTES, 'UTF-8') ?></span> &bull; <?= htmlspecialchars($inpat['gender'] ?? 'N/A') ?><?= !empty($inpat['age']) ? ', ' . (int)$inpat['age'] . ' yrs' : '' ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <strong style="color: var(--brand-primary); font-size: 0.92rem;">
                      Bed #<?= htmlspecialchars($inpat['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">
                      <?= htmlspecialchars($inpat['ward_type'], ENT_QUOTES, 'UTF-8') ?> (Floor <?= htmlspecialchars((string)$inpat['floor_number'], ENT_QUOTES, 'UTF-8') ?>)
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.86rem;">
                      <?= htmlspecialchars($inpat['primary_diagnosis'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if (!empty($acuity)): ?>
                      <span class="badge-acuity-pill" style="margin-top: 3px; display: inline-block;">
                        <?= htmlspecialchars($acuity, ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: #334155; font-size: 0.84rem;">
                      <?= htmlspecialchars($inpat['admitting_staff_name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div style="font-size: 0.72rem; color: #0284c7; font-weight: 600;">
                      <?= htmlspecialchars($inpat['admitting_staff_role'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-size: 0.84rem; font-weight: 700; color: var(--text-heading);">
                      <?= date('M j, Y', $admitTime) ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                      <?= date('g:i A', $admitTime) ?> &bull; Day <?= $days ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge-admitted-live">
                      <span class="dot-live-pulse"></span>
                      &bull; Admitted / Under Care
                    </span>
                  </td>
                  <td style="text-align: right;">
                    <a href="prescriptions.php?patient_id=<?= (int)$inpat['patient_id'] ?>" class="btn-action-telemed" style="padding: 0.4rem 0.75rem; font-size: 0.76rem; text-decoration: none;">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><line x1="12" y1="11" x2="12" y2="17"></line><line x1="9" y1="14" x2="15" y2="14"></line></svg>
                      Rx Note
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Inpatient Discharge & Transfer History -->
    <?php if (!empty($pastInpatients)): ?>
    <section class="admin-stack-card" style="margin-top: 1.75rem;">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>Recent Discharges &amp; Transfers</h3>
          <p>Historical record of previously attended inpatients</p>
        </div>
      </div>
      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Patient Name</th>
              <th>Ward / Bed</th>
              <th>Admitted At</th>
              <th>Discharged / Transferred At</th>
              <th>Resolution Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pastInpatients as $past): ?>
              <tr>
                <td><strong><?= htmlspecialchars($past['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($past['bed_number'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($past['ward_type'], ENT_QUOTES, 'UTF-8') ?>)</td>
                <td><?= date('M j, Y', strtotime($past['admitted_at'])) ?></td>
                <td><?= $past['discharged_at'] ? date('M j, Y h:i A', strtotime($past['discharged_at'])) : '—' ?></td>
                <td>
                  <span class="live-chip-sm" style="background: #f1f5f9; color: #475569; border-color: #cbd5e1;">
                    <?= htmlspecialchars($past['status'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

  </main>
</body>
</html>

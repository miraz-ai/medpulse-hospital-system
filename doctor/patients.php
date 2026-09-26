<?php
/**
 * MedPulse Doctor Portal — Branch Clinical Patient Registry
 *
 * Scoped strictly to authenticated physician (:session_doctor_id) and branch (:session_hospital_id).
 * Rejects cross-tenant queries.
 */

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';
require_once __DIR__ . '/../config/tenant_scope.php';

$doctorUserId = (int)$_SESSION['user_id'];
$sessionHospitalId = TenantScope::enforce($pdo, ['doctor']);

// Fetch Authenticated Doctor Profile
try {
    $docStmt = $pdo->prepare("
        SELECT u.full_name, dp.specialty, dp.designation, dp.military_rank, dp.qualifications, dp.bmdc_license_number,
               h.name AS hospital_name
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        LEFT JOIN hospitals h ON h.hospital_id = :hid
        WHERE u.user_id = :uid
        LIMIT 1
    ");
    $docStmt->execute([':uid' => $doctorUserId, ':hid' => $sessionHospitalId]);
    $doctor = $docStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $doctor = null;
}

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? 'Doctor');
$displayName = formatDoctorTitle($cleanName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$branchName = $doctor['hospital_name'] ?? "Branch #{$sessionHospitalId}";

$searchTerm = trim($_GET['search'] ?? '');
$filterType = trim($_GET['type'] ?? 'all'); // 'all', 'inpatient', 'outpatient'

// ── QUERY: Combined Patients Under Doctor's Care at this Facility ───────────
$sql = "
    SELECT 
        u.user_id AS patient_id,
        u.full_name AS patient_name,
        u.email,
        u.phone,
        u.gender,
        COALESCE(TIMESTAMPDIFF(YEAR, pat.dob, CURDATE()), u.age, 0) AS age,
        COALESCE(pat.blood_group, u.blood_group, 'Unknown') AS blood_group,
        COALESCE(pat.patient_uid, CONCAT('MP-', u.user_id)) AS patient_uid,
        pat.dob,
        -- Check if currently an active inpatient in this hospital
        MAX(CASE WHEN ba.status = 'Active' THEN 1 ELSE 0 END) AS is_active_inpatient,
        MAX(CASE WHEN ba.status = 'Active' THEN hb.bed_number ELSE NULL END) AS active_bed_number,
        MAX(CASE WHEN ba.status = 'Active' THEN hb.ward_type ELSE NULL END) AS active_ward_type,
        -- Total consultations with this doctor at this hospital
        COUNT(DISTINCT a.id) AS total_consultations,
        MAX(a.appointment_date) AS last_consultation_date,
        -- Total prescriptions
        COUNT(DISTINCT pr.prescription_id) AS total_prescriptions
    FROM users u
    LEFT JOIN patients pat ON u.user_id = pat.user_id
    LEFT JOIN appointments a ON a.patient_id = u.user_id 
        AND a.doctor_id = :doc_id 
        AND a.hospital_id = :hosp_id
    LEFT JOIN bed_allocations ba ON ba.patient_id = u.user_id 
        AND ba.attending_doctor_id = :doc_id2
    LEFT JOIN hospital_beds hb ON hb.bed_id = ba.bed_id 
        AND hb.hospital_id = :hosp_id2
    LEFT JOIN prescriptions pr ON pr.patient_id = u.user_id 
        AND pr.doctor_id = :doc_id3
    WHERE (a.id IS NOT NULL OR (ba.allocation_id IS NOT NULL AND hb.bed_id IS NOT NULL))
";

$params = [
    ':doc_id'   => $doctorUserId,
    ':hosp_id'  => $sessionHospitalId,
    ':doc_id2'  => $doctorUserId,
    ':hosp_id2' => $sessionHospitalId,
    ':doc_id3'  => $doctorUserId,
];

if ($searchTerm !== '') {
    $sql .= " AND (u.full_name LIKE :sterm OR u.phone LIKE :sterm2 OR pat.patient_uid LIKE :sterm3)";
    $termParam = "%{$searchTerm}%";
    $params[':sterm'] = $termParam;
    $params[':sterm2'] = $termParam;
    $params[':sterm3'] = $termParam;
}

$sql .= " GROUP BY u.user_id, pat.id";

if ($filterType === 'inpatient') {
    $sql .= " HAVING is_active_inpatient = 1";
} elseif ($filterType === 'outpatient') {
    $sql .= " HAVING is_active_inpatient = 0";
}

$sql .= " ORDER BY is_active_inpatient DESC, last_consultation_date DESC";

$patStmt = $pdo->prepare($sql);
$patStmt->execute($params);
$patients = $patStmt->fetchAll(PDO::FETCH_ASSOC);

// Counters
$totalCount = count($patients);
$inpatientCount = 0;
$outpatientCount = 0;
foreach ($patients as $p) {
    if ($p['is_active_inpatient']) {
        $inpatientCount++;
    } else {
        $outpatientCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | My Patients &amp; Clinical Directory</title>

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    .facility-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(2, 132, 199, 0.12);
      border: 1px solid rgba(2, 132, 199, 0.3);
      padding: 4px 12px;
      border-radius: 9999px;
      font-size: 0.82rem;
      font-weight: 700;
      color: #38bdf8;
      margin-bottom: 12px;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
    }
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      background: var(--bg-card);
      padding: 1rem 1.25rem;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-color);
      margin-bottom: 1.5rem;
    }
    .search-input {
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      color: var(--text-heading);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.9rem;
      outline: none;
      min-width: 240px;
    }
    .btn-filter {
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 600;
      text-decoration: none;
      color: var(--text-muted);
      border: 1px solid var(--border-color);
      transition: all 0.2s;
    }
    .btn-filter.active {
      background: var(--brand-primary);
      color: #ffffff;
      border-color: var(--brand-primary);
    }
    .btn-rx {
      background: linear-gradient(135deg, #0284c7, #0369a1);
      color: #ffffff;
      padding: 6px 12px;
      border-radius: 6px;
      text-decoration: none;
      font-size: 0.78rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .btn-rx:hover {
      background: linear-gradient(135deg, #0369a1, #075985);
    }
  </style>
</head>
<body>

  <!-- Reusable Doctor Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <!-- Main Viewport Past Sidebar -->
  <main class="viewport-full">

    <!-- Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="facility-tag">
          <span class="badge-dot"></span>
          Facility Scope: <?= htmlspecialchars($branchName) ?> (ID: #<?= (int)$sessionHospitalId ?>)
        </div>
        <h1>
          My Clinical Patients &amp; Consultations
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
        </h1>
        <p>Comprehensive patient registry of all individuals treated by <?= htmlspecialchars($displayName) ?> at this facility.</p>
      </div>
      <div class="banner-actions">
        <a href="chamber.php" class="btn-action-telemed">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
          Go to Chamber Queue
        </a>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(2, 132, 199, 0.15); color: #0284c7;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Total Treated Patients</div>
          <div class="stat-value"><?= $totalCount ?></div>
          <div class="stat-subtext" style="color: var(--text-muted);">Inpatient &amp; Outpatient</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Active Inpatients</div>
          <div class="stat-value" style="color: #ef4444;"><?= $inpatientCount ?></div>
          <div class="stat-subtext" style="color: #ef4444;">Currently Admitted in Wards</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><polyline points="9 16 12 19 16 12"></polyline></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Outpatient History</div>
          <div class="stat-value" style="color: #10b981;"><?= $outpatientCount ?></div>
          <div class="stat-subtext" style="color: var(--text-muted);">Consulted at <?= htmlspecialchars($branchName) ?></div>
        </div>
      </div>
    </div>

    <!-- Filter & Search Bar -->
    <form method="GET" class="filter-bar">
      <input type="text" name="search" class="search-input" placeholder="Search by patient name, phone, or UID..." value="<?= htmlspecialchars($searchTerm) ?>">
      
      <div style="display: flex; gap: 8px; margin-left: 8px;">
        <a href="patients.php?type=all<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="btn-filter <?= $filterType === 'all' ? 'active' : '' ?>">All Patients</a>
        <a href="patients.php?type=inpatient<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="btn-filter <?= $filterType === 'inpatient' ? 'active' : '' ?>">Active Inpatients</a>
        <a href="patients.php?type=outpatient<?= $searchTerm ? '&search=' . urlencode($searchTerm) : '' ?>" class="btn-filter <?= $filterType === 'outpatient' ? 'active' : '' ?>">Outpatients</a>
      </div>

      <button type="submit" class="btn-action-telemed" style="margin-left: auto;">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        Search
      </button>
    </form>

    <!-- Patients Roster Table -->
    <section class="admin-table-wrap">
      <table class="admin-data-table">
        <thead>
          <tr>
            <th>Patient Identity</th>
            <th>Demographics</th>
            <th>Care Status</th>
            <th>Consultation History</th>
            <th style="text-align: right;">Clinical Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($patients)): ?>
            <tr>
              <td colspan="5" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                <svg class="ui-ico" style="width: 48px; height: 48px; margin-bottom: 12px; stroke: #64748b;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <div style="font-weight: 600; color: var(--text-heading); font-size: 1rem;">No Patients Found</div>
                <div style="font-size: 0.85rem;">No clinical records matching the specified criteria at this facility.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($patients as $p): ?>
              <tr>
                <td>
                  <div>
                    <strong style="color: var(--text-heading); font-size: 0.95rem;">
                      <?= htmlspecialchars($p['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.78rem; color: var(--text-muted); font-family: monospace;">
                      UID: <?= htmlspecialchars($p['patient_uid'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </div>
                </td>
                <td>
                  <div style="font-size: 0.85rem; color: var(--text-heading);">
                    <?= (int)$p['age'] ?> yrs &bull; <?= htmlspecialchars($p['gender'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size: 0.76rem; color: var(--text-muted);">
                    Blood: <strong style="color: #ef4444;"><?= htmlspecialchars($p['blood_group'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></strong> &bull; <?= htmlspecialchars($p['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </td>
                <td>
                  <?php if ($p['is_active_inpatient']): ?>
                    <span style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                      <span style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444;"></span>
                      Inpatient: Bed <?= htmlspecialchars($p['active_bed_number'] ?? 'Ward') ?>
                    </span>
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">
                      <?= htmlspecialchars($p['active_ward_type'] ?? 'General') ?>
                    </div>
                  <?php else: ?>
                    <span style="background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25); padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 700;">
                      OPD Outpatient
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-size: 0.85rem; color: var(--text-heading);">
                    <strong><?= (int)$p['total_consultations'] ?></strong> Visits &bull; <strong><?= (int)$p['total_prescriptions'] ?></strong> Prescriptions
                  </div>
                  <div style="font-size: 0.76rem; color: var(--text-muted);">
                    Last Seen: <?= $p['last_consultation_date'] ? date('d M Y', strtotime($p['last_consultation_date'])) : 'Inpatient Care' ?>
                  </div>
                </td>
                <td style="text-align: right;">
                  <div class="table-actions-flex" style="justify-content: flex-end; gap: 8px;">
                    <?php if ($p['is_active_inpatient']): ?>
                      <a href="my_inpatients.php" class="btn-rx" style="background: linear-gradient(135deg, #0d9488, #0f766e);" title="View ward rounding details">
                        Round Vitals
                      </a>
                    <?php endif; ?>
                    <a href="prescriptions.php?patient_id=<?= (int)$p['patient_id'] ?>" class="btn-rx" title="Prescribe medications">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                      Prescription
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

  </main>
</body>
</html>

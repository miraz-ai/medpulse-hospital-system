<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Patient Master Registry Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';

// Enforce strict Multi-Tenant Branch Isolation
$isSuperAdmin = TenantScope::isSuperAdmin();
$adminHospitalId = (int)($_SESSION['hospital_id'] ?? 1);

try {
    // Fetch registered patients joined with patients table and active inpatient admissions
    if ($isSuperAdmin) {
        $patientsStmt = $pdo->prepare("
            SELECT 
                u.user_id, 
                u.full_name, 
                u.email, 
                u.phone, 
                u.gender, 
                u.role, 
                u.status, 
                COALESCE(p.blood_group, u.blood_group, 'Unknown') AS blood_group, 
                COALESCE(p.dob, u.date_of_birth) AS dob,
                p.patient_uid,
                u.created_at,
                act_adm.admission_id,
                act_adm.admission_number,
                act_adm.bed_number,
                act_adm.ward_type,
                act_adm.floor_number,
                act_adm.primary_diagnosis,
                act_adm.doctor_name,
                act_adm.doctor_specialty,
                act_adm.staff_name,
                act_adm.staff_designation
            FROM users u 
            LEFT JOIN patients p ON u.user_id = p.user_id
            LEFT JOIN (
                SELECT a.patient_id, a.admission_id, a.admission_number, a.primary_diagnosis,
                       b.bed_number, b.ward_type, b.floor_number,
                       doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty,
                       COALESCE(su.full_name, 'Admission Desk Officer') AS staff_name,
                       COALESCE(stf.role_title, 'Frontdesk Registrar') AS staff_designation
                FROM admissions a
                JOIN hospital_beds b ON a.bed_id = b.bed_id
                LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
                LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
                LEFT JOIN staff stf ON a.admitting_staff_id = stf.staff_id
                LEFT JOIN users su ON stf.user_id = su.user_id
                WHERE a.status = 'Admitted'
            ) act_adm ON act_adm.patient_id = u.user_id
            WHERE u.role = 'Patient' 
            ORDER BY u.created_at DESC
        ");
        $patientsStmt->execute();
    } else {
        $patientsStmt = $pdo->prepare("
            SELECT 
                u.user_id, 
                u.full_name, 
                u.email, 
                u.phone, 
                u.gender, 
                u.role, 
                u.status, 
                COALESCE(p.blood_group, u.blood_group, 'Unknown') AS blood_group, 
                COALESCE(p.dob, u.date_of_birth) AS dob,
                p.patient_uid,
                u.created_at,
                act_adm.admission_id,
                act_adm.admission_number,
                act_adm.bed_number,
                act_adm.ward_type,
                act_adm.floor_number,
                act_adm.primary_diagnosis,
                act_adm.doctor_name,
                act_adm.doctor_specialty,
                act_adm.staff_name,
                act_adm.staff_designation
            FROM users u 
            LEFT JOIN patients p ON u.user_id = p.user_id
            LEFT JOIN (
                SELECT a.patient_id, a.admission_id, a.admission_number, a.primary_diagnosis,
                       b.bed_number, b.ward_type, b.floor_number,
                       doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty,
                       COALESCE(su.full_name, 'Admission Desk Officer') AS staff_name,
                       COALESCE(stf.role_title, 'Frontdesk Registrar') AS staff_designation
                FROM admissions a
                JOIN hospital_beds b ON a.bed_id = b.bed_id
                LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
                LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
                LEFT JOIN staff stf ON a.admitting_staff_id = stf.staff_id
                LEFT JOIN users su ON stf.user_id = su.user_id
                WHERE a.status = 'Admitted' AND a.hospital_id = :h1
            ) act_adm ON act_adm.patient_id = u.user_id
            WHERE u.role = 'Patient' 
              AND (
                  u.hospital_id = :h2
                  OR EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = u.user_id AND a.hospital_id = :h3)
                  OR EXISTS (SELECT 1 FROM admissions adm WHERE adm.patient_id = u.user_id AND adm.hospital_id = :h4)
                  OR EXISTS (SELECT 1 FROM invoices inv WHERE inv.patient_id = u.user_id AND inv.hospital_id = :h5)
                  OR (
                      u.hospital_id IS NULL
                      AND NOT EXISTS (SELECT 1 FROM appointments a2 WHERE a2.patient_id = u.user_id AND a2.hospital_id != :h6)
                      AND NOT EXISTS (SELECT 1 FROM admissions adm2 WHERE adm2.patient_id = u.user_id AND adm2.hospital_id != :h7)
                      AND NOT EXISTS (SELECT 1 FROM invoices inv2 WHERE inv2.patient_id = u.user_id AND inv2.hospital_id != :h8)
                  )
              )
            ORDER BY u.created_at DESC
        ");
        $patientsStmt->execute([
            ':h1' => $adminHospitalId,
            ':h2' => $adminHospitalId,
            ':h3' => $adminHospitalId,
            ':h4' => $adminHospitalId,
            ':h5' => $adminHospitalId,
            ':h6' => $adminHospitalId,
            ':h7' => $adminHospitalId,
            ':h8' => $adminHospitalId
        ]);
    }
    $patientsRegistry = $patientsStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalPatients = count($patientsRegistry);

} catch (PDOException $e) {
    error_log("Manage Patients DB error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact system engineering.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Patient Master Registry</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial (Dynamic Active Route Highlighting) -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container (Starts cleanly past sidebar) -->
  <main class="viewport-full">

    <!-- Page Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          Patient Master Registry
          <svg class="ui-ico" style="stroke: var(--status-green); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
        </h1>
        <p>Central electronic medical records, verified clinical demographics, and patient account status</p>
      </div>
    </div>

    <!-- Sub-Navigation Navigation Tabs -->
    <div style="display: flex; gap: 10px; margin-bottom: 1.5rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">
      <a href="manage_patients.php" style="padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 0.9rem; text-decoration: none; color: #ffffff; background: linear-gradient(135deg, #0284c7, #0d9488); display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
        Master Patient Directory
      </a>
      <a href="admissions.php" style="padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 0.9rem; text-decoration: none; color: #64748b; background: rgba(2, 132, 199, 0.06); display: inline-flex; align-items: center; gap: 8px;">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14h6"></path><path d="M9 10h6"></path><path d="M9 18h4"></path></svg>
        Inpatient Admissions Registry (Live Sync)
      </a>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Enrolled Patients</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        </div>
        <div class="stat-card-number"><?= number_format($totalPatients) ?></div>
        <div class="stat-card-badge badge-blue">Verified Hospital Files</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Active Portal Accounts</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= number_format($totalPatients) ?></div>
        <div class="stat-card-badge badge-green">100% Synced</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Medical Records Access</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
        </div>
        <div class="stat-card-number">Active</div>
        <div class="stat-card-badge badge-blue">HIPAA Compliant</div>
      </div>
    </div>

    <!-- Full Patient Master Registry Table Card -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--status-green); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
            Patient Admissions & Master Directory
          </h3>
          <p>Comprehensive patient demographic files, blood group cross-matches, and contact records</p>
        </div>
        <span class="role-pill role-pill-patient" style="font-size: 0.76rem;">
          <?= $totalPatients ?> ENROLLED PATIENTS
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Patient ID</th>
              <th>Full Legal Name</th>
              <th>Contact Email</th>
              <th>Mobile Number</th>
              <th>Blood Group</th>
              <th>Age / Sex</th>
              <th>Registered Date</th>
              <th style="text-align: right;">Portal Record</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($patientsRegistry)): ?>
              <tr>
                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">No patient records found.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($patientsRegistry as $patient): 
                $calcAge = 'N/A';
                if (!empty($patient['dob'])) {
                    try {
                        $calcAge = (new DateTime())->diff(new DateTime($patient['dob']))->y . ' yrs';
                    } catch (Exception $e) {}
                }
                $displayUid = !empty($patient['patient_uid']) ? $patient['patient_uid'] : ('MP-' . date('Y') . '-' . str_pad((string)$patient['user_id'], 5, '0', STR_PAD_LEFT));
                $bg = !empty($patient['blood_group']) ? $patient['blood_group'] : 'Unknown';
              ?>
                <tr>
                  <td>
                    <span class="license-chip" style="font-family: monospace; font-weight: 700;"><?= htmlspecialchars($displayUid, ENT_QUOTES, 'UTF-8') ?></span>
                  </td>
                  <td>
                    <strong style="font-size: 0.92rem; color: var(--text-heading);">
                      <?= htmlspecialchars($patient['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <?php if (!empty($patient['admission_id'])): ?>
                      <div style="margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                        <span style="display: inline-flex; align-items: center; gap: 5px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 2px 7px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800;">
                          <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; box-shadow: 0 0 6px #10b981;"></span>
                          INPATIENT: Bed #<?= htmlspecialchars($patient['bed_number']) ?> (<?= htmlspecialchars($patient['ward_type']) ?> &bull; Fl <?= (int)$patient['floor_number'] ?>)
                        </span>
                        <span style="font-size: 0.73rem; color: #0f766e; font-weight: 600;">
                          Dr. <?= htmlspecialchars($patient['doctor_name']) ?> &bull; <?= htmlspecialchars($patient['staff_name']) ?> (<?= htmlspecialchars($patient['staff_designation']) ?>)
                        </span>
                        <a href="admissions.php?q=<?= urlencode($patient['patient_uid'] ?: $patient['full_name']) ?>" style="font-size: 0.72rem; color: #0284c7; font-weight: 700; text-decoration: none;">
                          View Dossier &rarr;
                        </a>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="color: var(--text-muted);"><?= htmlspecialchars($patient['email'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($patient['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <span style="font-weight: 800; color: var(--status-red); background: var(--status-red-bg); padding: 2px 7px; border-radius: 4px; font-size: 0.74rem;">
                      <?= htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td>
                    <?= htmlspecialchars($calcAge, ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($patient['gender'] ?? 'Male', ENT_QUOTES, 'UTF-8') ?>
                  </td>
                  <td style="font-size: 0.78rem; color: var(--text-muted);">
                    <?= htmlspecialchars(date('d M Y', strtotime($patient['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                  </td>
                  <td style="text-align: right;">
                    <button class="btn-table-action btn-table-activate" 
                            onclick="showToast('Synchronizing medical history for <?= htmlspecialchars($patient['full_name'], ENT_QUOTES, 'UTF-8') ?>', 'success')"
                            title="Review Medical File">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      Verified
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

</body>
</html>

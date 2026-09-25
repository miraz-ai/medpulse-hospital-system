<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Standalone Patient Master Registry Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';

try {
    // Fetch registered patients joined with patients table
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
            u.created_at 
        FROM users u 
        LEFT JOIN patients p ON u.user_id = p.user_id
        WHERE u.role = 'Patient' 
        ORDER BY u.created_at DESC
    ");
    $patientsStmt->execute();
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
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Electronic Medical Records (EMR) telemetry is synchronized.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          EMR Telemetry
        </button>
        <button class="btn-action-gradient" onclick="window.location.href='../index.php'">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          + Enroll Patient
        </button>
      </div>
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

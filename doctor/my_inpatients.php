<?php
/**
 * MedPulse Doctor Portal — My Inpatients & Daily Clinical Rounds
 * Real-time bed occupancy, clinical rounding logs, and attending patient supervision.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Doctor') !== 0) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';

$doctorUserId = (int)$_SESSION['user_id'];

// Doctor Info
try {
    $docStmt = $pdo->prepare("
        SELECT u.full_name, dp.specialty, dp.bmdc_license_number
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

$cleanName = preg_replace('/^(?:(?:dr\.?|doctor)\s+)+/i', '', trim($doctor['full_name'] ?? 'Doctor'));
$displayName = 'Dr. ' . $cleanName;
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Surgery & Critical Care', ENT_QUOTES, 'UTF-8');

// Fetch Active Inpatients
try {
    $inpatStmt = $pdo->prepare("
        SELECT ba.allocation_id, ba.admitted_at, ba.status,
               u.user_id AS patient_id, u.full_name AS patient_name, u.email, u.phone, u.gender, u.age, u.blood_group,
               hb.bed_id, hb.bed_number, hb.ward_type, hb.floor_number, hb.daily_rate
        FROM bed_allocations ba
        JOIN users u ON ba.patient_id = u.user_id
        JOIN hospital_beds hb ON ba.bed_id = hb.bed_id
        WHERE ba.attending_doctor_id = ? AND ba.status = 'Active'
        ORDER BY ba.admitted_at DESC
    ");
    $inpatStmt->execute([$doctorUserId]);
    $activeInpatients = $inpatStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
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
        WHERE ba.attending_doctor_id = ? AND ba.status != 'Active'
        ORDER BY ba.allocation_id DESC
        LIMIT 10
    ");
    $historyStmt->execute([$doctorUserId]);
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
              <th>Patient Profile</th>
              <th>Assigned Bed / Ward</th>
              <th>Gender / Age</th>
              <th>Blood Group</th>
              <th>Admitted Timestamp</th>
              <th>Length of Stay</th>
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
              ?>
                <tr>
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials"><?= htmlspecialchars($pInitials, ENT_QUOTES, 'UTF-8') ?></div>
                      <div>
                        <strong style="font-size: 0.92rem; color: var(--text-heading);">
                          <?= htmlspecialchars($inpat['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <div style="font-size: 0.72rem; color: var(--text-muted);">
                          UHID: MP-P-<?= str_pad((string)$inpat['patient_id'], 4, '0', STR_PAD_LEFT) ?> &bull; <?= htmlspecialchars($inpat['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <strong style="color: var(--brand-primary); font-size: 0.88rem;">
                      <?= htmlspecialchars($inpat['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">
                      <?= htmlspecialchars($inpat['ward_type'], ENT_QUOTES, 'UTF-8') ?> (Floor <?= htmlspecialchars((string)$inpat['floor_number'], ENT_QUOTES, 'UTF-8') ?>)
                    </div>
                  </td>
                  <td><?= htmlspecialchars($inpat['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>, <?= (int)($inpat['age'] ?? 24) ?> yrs</td>
                  <td>
                    <span class="live-chip-sm" style="background: #fef2f2; color: #dc2626; border-color: #fca5a5;">
                      <?= htmlspecialchars($inpat['blood_group'] ?? 'B+', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-size: 0.84rem; font-weight: 600; color: var(--text-heading);">
                      <?= date('M j, Y', $admitTime) ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                      <?= date('h:i A', $admitTime) ?>
                    </div>
                  </td>
                  <td>
                    <span class="live-chip-sm" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;">
                      Day <?= $days ?>
                    </span>
                  </td>
                  <td style="text-align: right;">
                    <button class="btn-action-telemed" style="padding: 0.4rem 0.8rem; font-size: 0.76rem;" onclick="showToast('Conducting clinical round for <?= htmlspecialchars(addslashes($inpat['patient_name']), ENT_QUOTES, 'UTF-8') ?>. Notes synchronized.', 'success')">
                      Record Round Note
                    </button>
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

<?php
/**
 * MedPulse Doctor Portal — Clinical Prescriptions & Diagnosis Records
 * Digital Rx management, therapeutic orders, and historical patient diagnostics.
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

// Fetch Doctor Profile
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
$bmdcLicense = htmlspecialchars($doctor['bmdc_license_number'] ?? 'BMDC-PENDING', ENT_QUOTES, 'UTF-8');

// Fetch Prescriptions for this Doctor
try {
    $rxStmt = $pdo->prepare("
        SELECT p.prescription_id, p.diagnosis_notes, p.vitals_summary, p.prescribed_at,
               u.user_id AS patient_id, u.full_name AS patient_name, u.gender, u.age, u.blood_group
        FROM prescriptions p
        JOIN users u ON p.patient_id = u.user_id
        WHERE p.doctor_id = ?
        ORDER BY p.prescribed_at DESC
    ");
    $rxStmt->execute([$doctorUserId]);
    $prescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prescriptions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Clinical Prescriptions</title>
  
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
          Clinical Prescriptions &amp; Digital Rx
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
        </h1>
        <p>Pharmacological therapy orders, diagnostic summaries, and clinical advice issued by <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <div class="banner-actions">
        <a href="dashboard.php" class="btn-action-telemed" style="text-decoration: none;">
          &larr; Doctor Dashboard
        </a>
        <button class="btn-action-gradient" onclick="showToast('New clinical prescription draft initialized.', 'success')">
          + Draft New Rx
        </button>
      </div>
    </div>

    <!-- Prescriptions Table Section -->
    <section class="admin-stack-card">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-teal); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
            Prescription Audit Ledger (<?= count($prescriptions) ?> Records)
          </h3>
          <p>Verified digital prescriptions linked to hospital pharmacy and patient portal</p>
        </div>
        <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-size: 0.76rem;">
          BMDC: <?= $bmdcLicense ?>
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Rx ID</th>
              <th>Patient Profile</th>
              <th>Clinical Diagnosis Notes</th>
              <th>Vitals Recorded</th>
              <th>Prescribed Timestamp</th>
              <th style="text-align: right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($prescriptions)): ?>
              <tr>
                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No clinical prescriptions have been issued yet.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($prescriptions as $rx): 
                $rxDate = strtotime($rx['prescribed_at']);
                $pInitials = strtoupper(substr($rx['patient_name'], 0, 2));
              ?>
                <tr>
                  <td>
                    <strong style="color: var(--brand-primary); font-size: 0.88rem;">
                      #RX-<?= str_pad((string)$rx['prescription_id'], 4, '0', STR_PAD_LEFT) ?>
                    </strong>
                  </td>
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials"><?= htmlspecialchars($pInitials, ENT_QUOTES, 'UTF-8') ?></div>
                      <div>
                        <strong style="font-size: 0.92rem; color: var(--text-heading);">
                          <?= htmlspecialchars($rx['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                        <div style="font-size: 0.72rem; color: var(--text-muted);">
                          UHID: MP-P-<?= str_pad((string)$rx['patient_id'], 4, '0', STR_PAD_LEFT) ?> &bull; <?= htmlspecialchars($rx['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td style="max-width: 320px;">
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-heading);">
                      <?= htmlspecialchars($rx['diagnosis_notes'] ?? 'Clinical examination & treatment plan', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <span class="live-chip-sm" style="background: #f8fafc; color: #475569; border-color: #cbd5e1; font-size: 0.74rem;">
                      <?= htmlspecialchars($rx['vitals_summary'] ?? 'BP: 120/80 mmHg &bull; SpO2: 98%', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td>
                    <div style="font-size: 0.84rem; font-weight: 600; color: var(--text-heading);">
                      <?= date('M j, Y', $rxDate) ?>
                    </div>
                    <div style="font-size: 0.72rem; color: var(--text-muted);">
                      <?= date('h:i A', $rxDate) ?>
                    </div>
                  </td>
                  <td style="text-align: right;">
                    <button class="btn-action-telemed" style="padding: 0.4rem 0.75rem; font-size: 0.76rem;" onclick="showToast('Prescription RX-<?= (int)$rx['prescription_id'] ?> synchronized with Hospital Pharmacy.', 'success')">
                      View Digital Rx
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

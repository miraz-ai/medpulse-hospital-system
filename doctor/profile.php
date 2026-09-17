<?php
/**
 * MedPulse Doctor Portal — Physician Credentials & Practice Profile
 * BMDC licensing records, consultation schedule, and clinical practice credentials.
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
$updateSuccess = false;
$updateMessage = '';

// Handle Profile Update Request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $newPhone = trim($_POST['phone'] ?? '');
    $newFee = (float)($_POST['consultation_fee'] ?? 1200);
    $newRoom = trim($_POST['room_number'] ?? '');
    $newShift = trim($_POST['shift_timings'] ?? '');

    try {
        if (!empty($newPhone)) {
            $updUser = $pdo->prepare("UPDATE users SET phone = ? WHERE user_id = ?");
            $updUser->execute([$newPhone, $doctorUserId]);
        }

        $updProfile = $pdo->prepare("
            UPDATE doctor_profiles 
            SET consultation_fee = ?, room_number = ?, shift_timings = ? 
            WHERE user_id = ?
        ");
        $updProfile->execute([$newFee, $newRoom, $newShift, $doctorUserId]);

        $updateSuccess = true;
        $updateMessage = 'Clinical practice credentials successfully updated.';
    } catch (PDOException $e) {
        $updateSuccess = false;
        $updateMessage = 'Failed to update credentials: ' . $e->getMessage();
    }
}

// Fetch Full Physician Details
try {
    $docStmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender, u.role, u.status, u.created_at,
               dp.doctor_id, dp.specialty, dp.bmdc_license_number, dp.consultation_fee,
               dp.room_number, dp.available_days, dp.shift_timings
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
$consultationFee = number_format((float)($doctor['consultation_fee'] ?? 1200), 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Doctor Profile &amp; Credentials</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    .profile-card-grid {
      display: grid;
      grid-template-columns: 1fr 1.6fr;
      gap: 1.75rem;
      margin-top: 1.75rem;
    }
    @media (max-width: 960px) { .profile-card-grid { grid-template-columns: 1fr; } }

    .form-group-field {
      margin-bottom: 1.25rem;
    }
    .form-group-field label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 0.4rem;
    }
    .form-control-input {
      width: 100%;
      padding: 0.65rem 0.95rem;
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-md);
      font-size: 0.86rem;
      color: var(--text-heading);
      background: var(--surface);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .form-control-input:focus {
      border-color: var(--brand-teal);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
    }
    .form-control-input[readonly] {
      background: #f8fafc;
      color: var(--text-muted);
      cursor: not-allowed;
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
          <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p>Verified Medical Practitioner &bull; <?= $specialty ?> &bull; BMDC Registration: <?= $bmdcLicense ?></p>
      </div>
      <div class="banner-actions">
        <a href="dashboard.php" class="btn-action-telemed" style="text-decoration: none;">
          &larr; Overview
        </a>
        <a href="my_earnings.php" class="btn-action-gradient" style="text-decoration: none;">
          Earnings &amp; Ledger &rarr;
        </a>
      </div>
    </div>

    <?php if (!empty($updateMessage)): ?>
      <div class="alert <?= $updateSuccess ? 'alert-success' : 'alert-danger' ?>" style="padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600; font-size: 0.86rem; background: <?= $updateSuccess ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $updateSuccess ? '#059669' : '#dc2626' ?>; border: 1px solid <?= $updateSuccess ? '#a7f3d0' : '#fca5a5' ?>;">
        <?= htmlspecialchars($updateMessage, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="profile-card-grid">

      <!-- Left Column: Official BMDC Credential Card -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>Verified Credentials</h3>
            <p>Bangladesh Medical &amp; Dental Council</p>
          </div>
          <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">VERIFIED</span>
        </div>

        <div style="padding: 1.5rem 1.75rem;">
          <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--brand-gradient); color: white; font-size: 1.75rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem;">
              <?= strtoupper(substr(trim($cleanName), 0, 2)) ?>
            </div>
            <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--text-heading);"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h3>
            <div style="font-size: 0.82rem; color: var(--brand-teal); font-weight: 700; margin-top: 2px;"><?= $specialty ?></div>
          </div>

          <div style="border-top: 1px solid var(--surface-border-subtle); padding-top: 1.25rem; display: flex; flex-direction: column; gap: 0.85rem;">
            <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">BMDC Registration:</span>
              <strong style="color: var(--text-heading);"><?= $bmdcLicense ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Consultation Room:</span>
              <strong style="color: var(--brand-primary);"><?= htmlspecialchars($doctor['room_number'] ?? 'Room-302', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Standard Fee:</span>
              <strong style="color: var(--status-green);">&#2547;<?= $consultationFee ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Shift Schedule:</span>
              <strong style="color: var(--text-heading);"><?= htmlspecialchars($doctor['shift_timings'] ?? '09:00 AM - 05:00 PM', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Practicing Days:</span>
              <strong style="color: var(--text-heading);"><?= htmlspecialchars($doctor['available_days'] ?? 'Mon,Tue,Wed,Thu,Fri', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Portal Status:</span>
              <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">ACTIVE &amp; ON DUTY</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Right Column: Editable Practice Details -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>Practice &amp; Contact Configurations</h3>
            <p>Maintain your consultation fee, room assignment, and contact telephone</p>
          </div>
        </div>

        <form method="POST" action="profile.php" style="padding: 1.5rem 1.75rem;">
          <input type="hidden" name="action" value="update_profile">

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group-field">
              <label>Official Full Name</label>
              <input type="text" class="form-control-input" value="<?= htmlspecialchars($doctor['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
            </div>
            <div class="form-group-field">
              <label>Registered Email</label>
              <input type="email" class="form-control-input" value="<?= htmlspecialchars($doctor['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" readonly>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group-field">
              <label>Specialty Department</label>
              <input type="text" class="form-control-input" value="<?= $specialty ?>" readonly>
            </div>
            <div class="form-group-field">
              <label>BMDC License Registration</label>
              <input type="text" class="form-control-input" value="<?= $bmdcLicense ?>" readonly>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group-field">
              <label>Official Phone Number</label>
              <input type="text" name="phone" class="form-control-input" value="<?= htmlspecialchars($doctor['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group-field">
              <label>Consultation Fee (BDT &#2547;)</label>
              <input type="number" step="50" name="consultation_fee" class="form-control-input" value="<?= (float)($doctor['consultation_fee'] ?? 1200) ?>" required>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group-field">
              <label>Consultation Room</label>
              <input type="text" name="room_number" class="form-control-input" value="<?= htmlspecialchars($doctor['room_number'] ?? 'Room-302', ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="form-group-field">
              <label>Shift Timings</label>
              <input type="text" name="shift_timings" class="form-control-input" value="<?= htmlspecialchars($doctor['shift_timings'] ?? '09:00 AM - 05:00 PM', ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
          </div>

          <div style="text-align: right; margin-top: 1rem;">
            <button type="submit" class="btn-action-gradient" style="padding: 0.65rem 1.5rem;">
              Save Credential Updates
            </button>
          </div>
        </form>
      </section>

    </div>

  </main>
</body>
</html>

<?php
/**
 * MedPulse Doctor Portal — Physician Credentials & Practice Profile
 * BMDC licensing records, medical designation, clinical specialty, and practice settings.
 */

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';

$doctorUserId = (int)$_SESSION['user_id'];
$updateSuccess = false;
$updateMessage = '';

// Standard Medical Specialty List
$medicalSpecialties = [
    'General & Laparoscopic Surgery',
    'Neurology & Neurosurgery',
    'Cardiology & Critical Care',
    'Orthopedics & Trauma Surgery',
    'Pediatrics & Child Health',
    'Gynecology & Obstetrics',
    'Internal Medicine',
    'Nephrology & Urology',
    'Gastroenterology & Hepatology',
    'Dermatology & Venereology',
    'ENT & Head-Neck Surgery',
    'Ophthalmology',
    'Pulmonology / Chest Medicine',
    'Anesthesiology & Critical Care',
    'Psychiatry & Behavioral Health',
    'Emergency & Critical Care Medicine'
];

// Standard Clinical Designation List
$clinicalDesignations = [
    'Professor',
    'Associate Professor',
    'Assistant Professor',
    'Senior Consultant',
    'Consultant',
    'Junior Consultant',
    'Resident Physician (RP)',
    'Registrar / Senior Resident',
    'Medical Officer'
];

// Optional Military / Commission Ranks
$militaryRanks = [
    'None',
    'Col. (Retd.)',
    'Lt. Col. (Retd.)',
    'Brig. Gen. (Retd.)',
    'Major (Retd.)'
];

// ── Handle Real-Time Profile Update Request ─────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $rawName = trim($_POST['full_name'] ?? '');
    // Clean base name: strip leading Dr., Prof., military ranks to save pure name
    $cleanName = cleanDoctorBaseName($rawName);

    $militaryRank = trim($_POST['military_rank'] ?? '');
    if (empty($militaryRank) || strcasecmp($militaryRank, 'None') === 0) {
        $militaryRank = null;
    }

    $designation = trim($_POST['designation'] ?? 'Consultant');
    $qualifications = trim($_POST['qualifications'] ?? 'MBBS');
    if (empty($qualifications)) {
        $qualifications = 'MBBS';
    }

    $phone = trim($_POST['phone'] ?? '');
    $specialty = trim($_POST['specialty'] ?? 'General & Laparoscopic Surgery');
    $fee = isset($_POST['consultation_fee']) && is_numeric($_POST['consultation_fee']) ? (float)$_POST['consultation_fee'] : 1200.00;
    $room = trim($_POST['room_number'] ?? 'Room-302');
    $shift = trim($_POST['shift_schedule'] ?? $_POST['shift_timings'] ?? '09:00 AM - 05:00 PM');

    $computedTitle = formatDoctorTitle($cleanName, $designation, $militaryRank);
    $computedFullIdentity = formatDoctorFullIdentity($cleanName, $designation, $militaryRank, $qualifications);
    $avatarInitials = formatDoctorAvatarInitials($cleanName);

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    try {
        $pdo->beginTransaction();

        // 1. Update users table with pure base name
        $updUser = $pdo->prepare("
            UPDATE users 
            SET full_name = :full_name, 
                phone = :phone,
                department = :specialty
            WHERE user_id = :user_id
        ");
        $updUser->execute([
            ':full_name' => $cleanName,
            ':phone'     => $phone,
            ':specialty' => $specialty,
            ':user_id'   => $doctorUserId,
        ]);

        // 2. Update doctor_profiles table with structured clinical credentials
        $updProfile = $pdo->prepare("
            UPDATE doctor_profiles 
            SET specialty = :specialty,
                designation = :designation,
                military_rank = :military_rank,
                qualifications = :qualifications,
                consultation_fee = :consultation_fee,
                room_number = :room_number,
                shift_schedule = :shift_schedule,
                shift_timings = :shift_timings,
                updated_at = NOW() 
            WHERE user_id = :user_id
        ");
        $updProfile->execute([
            ':specialty'        => $specialty,
            ':designation'      => $designation,
            ':military_rank'    => $militaryRank,
            ':qualifications'   => $qualifications,
            ':consultation_fee' => $fee,
            ':room_number'      => $room,
            ':shift_schedule'   => $shift,
            ':shift_timings'    => $shift,
            ':user_id'          => $doctorUserId,
        ]);

        $pdo->commit();

        $_SESSION['full_name'] = $cleanName;
        $updateSuccess = true;
        $updateMessage = 'Profile credentials updated successfully.';

        // Audit Log Entry
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs 
                    (actor_id, actor_role, action, action_name, description, category, target_entity, ip_address)
                VALUES 
                    (:actor, 'Doctor', 'PROFILE_UPDATE', 'PROFILE_UPDATE', :desc, 'SECURITY', 'DOCTOR_PORTAL', :ip)
            ");
            $auditStmt->execute([
                ':actor' => $doctorUserId,
                ':desc'  => "Updated clinical profile: {$computedFullIdentity} ({$specialty})",
                ':ip'    => $ip,
            ]);
        } catch (Throwable $e) {
            // Non-blocking
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Profile credentials updated successfully',
                'data'    => [
                    'full_title'       => $computedTitle,
                    'pure_name'        => $cleanName,
                    'full_identity'    => $computedFullIdentity,
                    'title_prefix'     => computeDoctorHonorificPrefix($designation, $militaryRank),
                    'avatar_initials'  => $avatarInitials,
                    'military_rank'    => $militaryRank ?? '',
                    'qualifications'   => $qualifications,
                    'specialty'        => $specialty,
                    'designation'      => $designation,
                    'consultation_fee' => number_format($fee, 2),
                    'room_number'      => $room,
                    'shift_schedule'   => $shift
                ]
            ]);
            exit();
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $updateSuccess = false;
        if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'phone') !== false) {
            $updateMessage = 'The phone number provided is already associated with another account.';
        } else {
            $updateMessage = 'Failed to update credentials: ' . $e->getMessage();
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $updateMessage]);
            exit();
        }
    }
}

// ── Fetch Fresh Physician Details ──────────────────────────────────────────
try {
    $docStmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender, u.role, u.status, u.created_at,
               dp.doctor_id, dp.specialty, dp.designation, dp.military_rank, dp.qualifications,
               dp.bmdc_license_number, dp.consultation_fee, dp.room_number, dp.available_days,
               dp.shift_schedule, dp.shift_timings
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

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? 'Physician');
$designation = trim($doctor['designation'] ?? 'Consultant');
$militaryRank = trim($doctor['military_rank'] ?? '');
if (strcasecmp($militaryRank, 'None') === 0) {
    $militaryRank = '';
}
$qualifications = trim($doctor['qualifications'] ?? 'MBBS');
if (empty($qualifications)) {
    $qualifications = 'MBBS';
}
$specialty = trim($doctor['specialty'] ?? 'General & Laparoscopic Surgery');

$displayTitle = formatDoctorTitle($cleanName, $designation, $militaryRank);
$displayFullIdentity = formatDoctorFullIdentity($cleanName, $designation, $militaryRank, $qualifications);
$doctorInitials = formatDoctorAvatarInitials($cleanName);

$bmdcLicense = trim($doctor['bmdc_license_number'] ?? 'BMDC-PENDING');
$consultationFee = number_format((float)($doctor['consultation_fee'] ?? 1200), 2);
$roomNumber = trim($doctor['room_number'] ?? 'Room-302');
$shiftSchedule = trim($doctor['shift_schedule'] ?? $doctor['shift_timings'] ?? '09:00 AM - 05:00 PM');
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
      grid-template-columns: 1fr 1.65fr;
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
      margin-bottom: 0.45rem;
    }
    .form-control-input, .form-control-select {
      width: 100%;
      padding: 0.68rem 0.95rem;
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-md);
      font-size: 0.86rem;
      color: var(--text-heading);
      background: var(--surface);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: inherit;
    }
    .form-control-input:focus, .form-control-select:focus {
      border-color: var(--brand-teal);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
    }
    .form-control-input[readonly] {
      background: #f1f5f9 !important;
      color: var(--text-muted) !important;
      cursor: not-allowed !important;
      opacity: 0.85;
    }
    .form-control-select {
      cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23475569'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 0.75rem center;
      background-size: 1.15rem;
      padding-right: 2.25rem;
      appearance: none;
      -webkit-appearance: none;
    }

    .readonly-box-group {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .field-hint-text {
      font-size: 0.74rem;
      color: var(--text-muted);
      display: block;
      margin-top: 0.35rem;
    }
  </style>
</head>
<body>

  <!-- Shared Production Doctor Sidebar -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <!-- Main Viewport (Starts cleanly below layout wrapper with standard padding p-8) -->
  <main class="viewport-full">

    <!-- Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1 id="headerBannerTitle">
          <?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?>
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p id="headerBannerSubtitle">
          Verified Clinical Practitioner &bull; <span id="hdrSubText"><?= htmlspecialchars($designation, ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($qualifications, ENT_QUOTES, 'UTF-8') ?></span> &bull; BMDC Reg: <?= htmlspecialchars($bmdcLicense, ENT_QUOTES, 'UTF-8') ?>
        </p>
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

    <!-- Live Status Alert (Server side fallback) -->
    <?php if (!empty($updateMessage)): ?>
      <div id="statusAlertBox" class="alert <?= $updateSuccess ? 'alert-success' : 'alert-danger' ?>" style="padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600; font-size: 0.86rem; background: <?= $updateSuccess ? '#ecfdf5' : '#fef2f2' ?>; color: <?= $updateSuccess ? '#059669' : '#dc2626' ?>; border: 1px solid <?= $updateSuccess ? '#a7f3d0' : '#fca5a5' ?>;">
        <?= htmlspecialchars($updateMessage, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="profile-card-grid">

      <!-- Left Column: Official BMDC Credential Card & Dynamic Real-Time Preview -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>Verified Credentials</h3>
            <p>Bangladesh Medical &amp; Dental Council</p>
          </div>
          <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-weight: 800;">
            VERIFIED
          </span>
        </div>

        <div style="padding: 1.5rem 1.75rem;">
          <div style="text-align: center; margin-bottom: 1.5rem;">
            <div id="previewAvatar" style="width: 72px; height: 72px; border-radius: 50%; background: var(--brand-gradient); color: white; font-size: 1.75rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; letter-spacing: 0.5px; box-shadow: 0 4px 12px var(--brand-glow);">
              <?= htmlspecialchars($doctorInitials, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <h3 id="previewDisplayName" style="font-size: 1.15rem; font-weight: 800; color: var(--text-heading);"><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></h3>
            <div id="previewQualifications" style="font-size: 0.82rem; color: #475569; font-weight: 600; margin-top: 2px;">
              <?= htmlspecialchars($qualifications, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div id="previewDesignationSpecialty" style="font-size: 0.82rem; color: var(--brand-teal); font-weight: 700; margin-top: 3px;">
              <?= htmlspecialchars($designation, ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8') ?>
            </div>
          </div>

          <div style="border-top: 1px solid var(--surface-border-subtle); padding-top: 1.25rem; display: flex; flex-direction: column; gap: 0.85rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">BMDC Registration:</span>
              <strong style="color: var(--text-heading);"><?= htmlspecialchars($bmdcLicense, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Clinical Designation:</span>
              <strong id="previewDesignation" style="color: var(--brand-primary); font-weight: 700;"><?= htmlspecialchars($designation, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Medical Qualifications:</span>
              <strong id="previewQualList" style="color: var(--text-heading);"><?= htmlspecialchars($qualifications, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Consultation Room:</span>
              <strong id="previewRoom" style="color: var(--brand-primary);"><?= htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Standard Fee:</span>
              <strong id="previewFee" style="color: var(--status-green);">&#2547;<?= $consultationFee ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Shift Schedule:</span>
              <strong id="previewShift" style="color: var(--text-heading);"><?= htmlspecialchars($shiftSchedule, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Practicing Days:</span>
              <strong style="color: var(--text-heading);"><?= htmlspecialchars($doctor['available_days'] ?? 'Daily (Emergency Call)', ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem;">
              <span style="color: var(--text-muted);">Portal Status:</span>
              <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-weight: 700;">ACTIVE &amp; ON DUTY</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Right Column: Editable Practice Details Form -->
      <section class="admin-stack-card">
        <div class="admin-stack-header">
          <div class="admin-stack-title-group">
            <h3>Practice &amp; Credential Configurations</h3>
            <p>Update pure name, designation, medical qualifications, room, and fees</p>
          </div>
        </div>

        <form id="doctorProfileForm" method="POST" action="profile.php" style="padding: 1.5rem 1.75rem;">
          <input type="hidden" name="action" value="update_profile">

          <!-- Row 1: Base Legal Name & Military Rank -->
          <div style="display: grid; grid-template-columns: 1.35fr 1fr; gap: 1rem;">
            <div class="form-group-field">
              <label for="fieldFullName">Official Full Name (Legal Base Name)</label>
              <input type="text" name="full_name" id="fieldFullName" class="form-control-input" value="<?= htmlspecialchars($cleanName, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Minhazul Islam Alvi" required>
              <span class="field-hint-text">Enter base name without Dr. or Prof. prefixes (auto-computed by designation).</span>
            </div>
            <div class="form-group-field">
              <label for="fieldMilitaryRank">Honorary / Military Rank (Optional)</label>
              <select name="military_rank" id="fieldMilitaryRank" class="form-control-select">
                <?php foreach ($militaryRanks as $mOption): ?>
                  <option value="<?= ($mOption === 'None') ? '' : htmlspecialchars($mOption, ENT_QUOTES, 'UTF-8') ?>" <?= (strcasecmp($militaryRank, $mOption) === 0 || ($mOption === 'None' && empty($militaryRank))) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($mOption, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="field-hint-text">Prepend commission rank (e.g. Col. (Retd.)).</span>
            </div>
          </div>

          <!-- Row 2: Designation & Medical Qualifications -->
          <div style="display: grid; grid-template-columns: 1.15fr 1.35fr; gap: 1rem;">
            <div class="form-group-field">
              <label for="fieldDesignation">Clinical Designation</label>
              <select name="designation" id="fieldDesignation" class="form-control-select" required>
                <?php foreach ($clinicalDesignations as $desigOption): ?>
                  <option value="<?= htmlspecialchars($desigOption, ENT_QUOTES, 'UTF-8') ?>" <?= (strcasecmp($designation, $desigOption) === 0) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($desigOption, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group-field">
              <label for="fieldQualifications">Medical Qualifications (Degrees)</label>
              <input type="text" name="qualifications" id="fieldQualifications" class="form-control-input" value="<?= htmlspecialchars($qualifications, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. MBBS, FCPS (Surgery), FRCS (Glasgow)" required>
              <span class="field-hint-text">Displayed on prescriptions, invoices, and profile cards.</span>
            </div>
          </div>

          <!-- Row 3: Specialty Department & BMDC License (Strictly Readonly) -->
          <div style="display: grid; grid-template-columns: 1.3fr 1.2fr; gap: 1rem;">
            <div class="form-group-field">
              <label for="fieldSpecialty">Specialty Department</label>
              <select name="specialty" id="fieldSpecialty" class="form-control-select" required>
                <?php foreach ($medicalSpecialties as $specOption): ?>
                  <option value="<?= htmlspecialchars($specOption, ENT_QUOTES, 'UTF-8') ?>" <?= (strcasecmp($specialty, $specOption) === 0) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($specOption, ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group-field">
              <label>BMDC License Registration (Immutable)</label>
              <div class="readonly-box-group">
                <input type="text" class="form-control-input" value="<?= htmlspecialchars($bmdcLicense, ENT_QUOTES, 'UTF-8') ?>" readonly title="BMDC License is government-verified and immutable">
                <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; padding: 7px 10px; font-weight: 800;">VERIFIED</span>
              </div>
            </div>
          </div>

          <!-- Row 4: Phone, Fee, Room, and Shift Timings -->
          <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr 1.3fr; gap: 1rem;">
            <div class="form-group-field">
              <label for="fieldPhone">Official Phone</label>
              <input type="text" name="phone" id="fieldPhone" class="form-control-input" value="<?= htmlspecialchars($doctor['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="01XXXXXXXXX" required>
            </div>

            <div class="form-group-field">
              <label for="fieldFee">Fee (BDT &#2547;)</label>
              <input type="number" step="50" min="0" name="consultation_fee" id="fieldFee" class="form-control-input" value="<?= (float)($doctor['consultation_fee'] ?? 1200) ?>" required>
            </div>

            <div class="form-group-field">
              <label for="fieldRoom">Room</label>
              <input type="text" name="room_number" id="fieldRoom" class="form-control-input" value="<?= htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Room-241" required>
            </div>

            <div class="form-group-field">
              <label for="fieldShift">Shift Timings</label>
              <input type="text" name="shift_schedule" id="fieldShift" class="form-control-input" value="<?= htmlspecialchars($shiftSchedule, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 09:00 AM - 05:00 PM" required>
            </div>
          </div>

          <div style="text-align: right; margin-top: 1rem;">
            <button type="submit" id="btnSaveProfile" class="btn-action-gradient" style="padding: 0.65rem 1.65rem; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 8px;">
              <svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
              Save Credential Updates
            </button>
          </div>
        </form>
      </section>

    </div>

  </main>

  <script>
    // ── Helper: Escape HTML string ───────────────────────────────────────────
    function escapeHtml(text) {
      if (!text) return '';
      return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    // ── Dynamic Real-Time Client-Side Honorific & Title Auto-Computation ─────
    function updateComputedPreview() {
      const nameInput = document.getElementById('fieldFullName');
      const desigInput = document.getElementById('fieldDesignation');
      const milInput = document.getElementById('fieldMilitaryRank');
      const qualInput = document.getElementById('fieldQualifications');
      const specInput = document.getElementById('fieldSpecialty');
      const feeInput = document.getElementById('fieldFee');
      const roomInput = document.getElementById('fieldRoom');
      const shiftInput = document.getElementById('fieldShift');

      const rawName = nameInput ? nameInput.value.trim() : '';
      const designation = desigInput ? desigInput.value.trim() : 'Consultant';
      const milRank = milInput ? milInput.value.trim() : '';
      const qualifications = qualInput ? qualInput.value.trim() : 'MBBS';
      const specialty = specInput ? specInput.value.trim() : '';
      const fee = feeInput ? feeInput.value : '1200';
      const room = roomInput ? roomInput.value.trim() : 'Room-302';
      const shift = shiftInput ? shiftInput.value.trim() : '';

      // Strip leading prefixes (Dr., Prof., military ranks) to compute clean base name
      const prefixRegex = /^(?:(?:Col\.|Lt\.\s*Col\.|Brig\.\s*Gen\.|Major)\s*(?:\(Retd\.?\))?\s*)*(?:(?:Assoc\.|Associate)\s+(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Asst\.|Assistant)\s+(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Prof\.|Professor)\s*(?:Dr\.?)?|(?:Dr\.?|Doctor)\s*)+/i;
      let cleanName = rawName.replace(prefixRegex, '').replace(/\s+/g, ' ').trim();
      if (!cleanName) cleanName = 'Physician';

      // Determine academic title prefix
      let prefix = 'Dr.';
      if (/assoc/i.test(designation)) {
        prefix = 'Assoc. Prof. Dr.';
      } else if (/asst|assist/i.test(designation)) {
        prefix = 'Asst. Prof. Dr.';
      } else if (/prof/i.test(designation)) {
        prefix = 'Prof. Dr.';
      } else {
        prefix = 'Dr.';
      }

      // Prepend military rank if specified
      if (milRank && milRank.toLowerCase() !== 'none') {
        prefix = milRank + ' ' + prefix;
      }

      const computedTitle = (prefix + ' ' + cleanName).trim();

      // Compute avatar initials strictly from clean base name
      const nameParts = cleanName.split(' ').filter(Boolean);
      let initials = 'DR';
      if (nameParts.length >= 2) {
        initials = (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase();
      } else if (nameParts.length === 1 && nameParts[0].length >= 2) {
        initials = nameParts[0].substring(0, 2).toUpperCase();
      }

      // Synchronize preview elements in real time
      const prevDisplayName = document.getElementById('previewDisplayName');
      if (prevDisplayName) prevDisplayName.textContent = computedTitle;

      const headerTitle = document.getElementById('headerBannerTitle');
      if (headerTitle) {
        headerTitle.innerHTML = `
          ${escapeHtml(computedTitle)}
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        `;
      }

      const hdrSub = document.getElementById('hdrSubText');
      if (hdrSub) {
        hdrSub.textContent = `${designation} • ${specialty} • ${qualifications}`;
      }

      const prevQual = document.getElementById('previewQualifications');
      if (prevQual) prevQual.textContent = qualifications;

      const prevQualList = document.getElementById('previewQualList');
      if (prevQualList) prevQualList.textContent = qualifications;

      const prevDesigSpec = document.getElementById('previewDesignationSpecialty');
      if (prevDesigSpec) prevDesigSpec.textContent = `${designation} • ${specialty}`;

      const prevDesig = document.getElementById('previewDesignation');
      if (prevDesig) prevDesig.textContent = designation;

      const prevRoom = document.getElementById('previewRoom');
      if (prevRoom) prevRoom.textContent = room;

      const prevFee = document.getElementById('previewFee');
      if (prevFee) {
        const numFee = parseFloat(fee || 0);
        prevFee.innerHTML = `&#2547;${numFee.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      }

      const prevShift = document.getElementById('previewShift');
      if (prevShift) prevShift.textContent = shift;

      const prevAvatar = document.getElementById('previewAvatar');
      if (prevAvatar) prevAvatar.textContent = initials;
    }

    // Attach real-time input & change listeners
    ['fieldFullName', 'fieldQualifications', 'fieldFee', 'fieldRoom', 'fieldShift', 'fieldPhone'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', updateComputedPreview);
    });

    ['fieldMilitaryRank', 'fieldDesignation', 'fieldSpecialty'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', updateComputedPreview);
    });

    // ── Real-Time Asynchronous Submission & Instant Server Synchronization ────
    document.getElementById('doctorProfileForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      const form = this;
      const submitBtn = document.getElementById('btnSaveProfile');
      const origText = submitBtn.innerHTML;

      submitBtn.disabled = true;
      submitBtn.innerHTML = `
        <svg class="ui-ico ui-ico-sm" style="stroke: white; animation: spin 1s linear infinite;" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10"></circle><path d="M12 2a10 10 0 0 1 10 10"></path>
        </svg> Saving Updates...
      `;

      try {
        const formData = new FormData(form);
        const res = await fetch('profile.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });

        const data = await res.json();
        if (data.success) {
          showToast(data.message || 'Profile credentials updated successfully!', 'success');

          // Keep pure base name in input and update preview card
          if (data.data) {
            const d = data.data;
            if (document.getElementById('fieldFullName')) {
              document.getElementById('fieldFullName').value = d.pure_name;
            }
            updateComputedPreview();
          }
        } else {
          showToast(data.message || 'Error updating credentials.', 'error');
        }
      } catch (err) {
        // Fallback to normal submission if network error
        form.submit();
        return;
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = origText;
      }
    });
  </script>

  <style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  </style>
</body>
</html>

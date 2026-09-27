<?php
/**
 * MedPulse Enterprise HMS — Patient Profile Management
 * 
 * Features:
 * - Editable demographic and contact details (Name, Phone, Blood Group, DOB/Age, Address)
 * - Strict Security Constraint: Patient UID is permanently READ-ONLY and locked against tampering
 * - Server-side validation with CSRF security guard
 * - Automatic age calculation from Date of Birth
 * - Instant session synchronization across patient portal
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/patient_auth.php';

$userId      = (int)$_SESSION['user_id'];
$patientName = $_SESSION['user_name'] ?? 'Patient';

$successMsg = null;
$errorMsg   = null;

// ── 1. Fetch Existing Patient Profile & Medical Credentials ──────────────────
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.user_id, u.full_name, u.email, u.phone, u.gender, u.blood_group,
            u.date_of_birth, u.age, u.address,
            p.patient_uid, p.allergies, p.baseline_vitals, p.created_at
        FROM users u
        LEFT JOIN patients p ON u.user_id = p.user_id
        WHERE u.user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':uid' => $userId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        throw new RuntimeException("Patient record not found.");
    }

    // Ensure a permanent Patient UID exists
    $patientUid = $patient['patient_uid'] ?? '';
    if (empty($patientUid)) {
        $patientUid = 'MP-' . date('Y') . '-' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
        // Insert or update patient UID record
        $upsert = $pdo->prepare("
            INSERT INTO patients (user_id, patient_uid, full_name, email, phone, gender, dob, blood_group, allergies, baseline_vitals)
            VALUES (:uid, :puid, :name, :email, :phone, :gender, :dob, :bg, 'NKDA (No Known Drug Allergies)', 'BP: 120/80 mmHg | Pulse: 74 bpm')
            ON DUPLICATE KEY UPDATE patient_uid = VALUES(patient_uid)
        ");
        $upsert->execute([
            ':uid'    => $userId,
            ':puid'   => $patientUid,
            ':name'   => $patient['full_name'],
            ':email'  => $patient['email'],
            ':phone'  => $patient['phone'] ?? '01700000000',
            ':gender' => $patient['gender'] ?? 'Male',
            ':dob'    => $patient['date_of_birth'] ?? '1995-01-01',
            ':bg'     => $patient['blood_group'] ?? 'Unknown'
        ]);
        $patient['patient_uid'] = $patientUid;
    }
} catch (Throwable $e) {
    error_log("Patient Profile Fetch Error: " . $e->getMessage());
    die("A secure database communication failure occurred. Please contact hospital support.");
}

// ── 2. Handle Profile Update POST Submission ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $errorMsg = "Security validation failed. Please refresh the page and try again.";
    } else {
        // Collect and sanitize editable fields
        $fullName   = trim((string)($_POST['full_name'] ?? ''));
        $phone      = trim((string)($_POST['phone'] ?? ''));
        $gender     = trim((string)($_POST['gender'] ?? 'Male'));
        $bloodGroup = trim((string)($_POST['blood_group'] ?? 'Unknown'));
        $dob        = trim((string)($_POST['date_of_birth'] ?? ''));
        $address    = trim((string)($_POST['address'] ?? ''));

        // Validation Rules
        $allowedGenders = ['Male', 'Female', 'Other'];
        $allowedBloods  = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
            $errorMsg = "Please enter a valid legal full name (2–100 characters).";
        } elseif (empty($phone) || !preg_match('/^(\+?880|0)1[3-9]\d{8}$/', str_replace([' ', '-'], '', $phone))) {
            $errorMsg = "Please enter a valid 11-digit Bangladeshi contact phone number (e.g. 017XXXXXXXX).";
        } elseif (!in_array($gender, $allowedGenders, true)) {
            $errorMsg = "Please select a recognized biological gender.";
        } elseif (!in_array($bloodGroup, $allowedBloods, true)) {
            $errorMsg = "Please select a valid ABO / Rh blood type.";
        } elseif (!empty($dob) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $errorMsg = "Please provide a valid date of birth (YYYY-MM-DD).";
        } else {
            // Calculate age if DOB provided
            $calculatedAge = null;
            if (!empty($dob)) {
                try {
                    $dobDate = new DateTime($dob);
                    $today   = new DateTime('today');
                    if ($dobDate > $today) {
                        $errorMsg = "Date of birth cannot be in the future.";
                    } else {
                        $calculatedAge = $dobDate->diff($today)->y;
                    }
                } catch (Exception $e) {
                    $errorMsg = "Invalid date format.";
                }
            }

            // Check if phone number is already taken by another account
            if ($errorMsg === null) {
                $phoneCheck = $pdo->prepare("SELECT user_id FROM users WHERE phone = ? AND user_id != ? LIMIT 1");
                $phoneCheck->execute([$phone, $userId]);
                if ($phoneCheck->fetch()) {
                    $errorMsg = "The contact phone number is already registered to another user account.";
                }
            }

            if ($errorMsg === null) {
                try {
                    $pdo->beginTransaction();

                    // Update `users` table (Note: patient_uid is NEVER updated)
                    $updUser = $pdo->prepare("
                        UPDATE users 
                        SET full_name     = :full_name,
                            phone         = :phone,
                            gender        = :gender,
                            blood_group   = :blood_group,
                            date_of_birth = :dob,
                            age           = :age,
                            address       = :address
                        WHERE user_id = :uid
                    ");
                    $updUser->execute([
                        ':full_name'   => $fullName,
                        ':phone'       => $phone,
                        ':gender'      => $gender,
                        ':blood_group' => $bloodGroup,
                        ':dob'         => !empty($dob) ? $dob : null,
                        ':age'         => $calculatedAge,
                        ':address'     => $address,
                        ':uid'         => $userId
                    ]);

                    // Update `patients` table
                    $updPat = $pdo->prepare("
                        UPDATE patients 
                        SET full_name   = :full_name,
                            phone       = :phone,
                            gender      = :gender,
                            blood_group = :blood_group,
                            dob         = :dob,
                            address     = :address,
                            updated_at  = NOW()
                        WHERE user_id = :uid
                    ");
                    $updPat->execute([
                        ':full_name'   => $fullName,
                        ':phone'       => $phone,
                        ':gender'      => $gender,
                        ':blood_group' => $bloodGroup,
                        ':dob'         => !empty($dob) ? $dob : '1995-01-01',
                        ':address'     => $address,
                        ':uid'         => $userId
                    ]);

                    $pdo->commit();

                    // Sync session state
                    $_SESSION['user_name'] = $fullName;
                    $patientName = $fullName;

                    // Refresh local values
                    $patient['full_name']     = $fullName;
                    $patient['phone']         = $phone;
                    $patient['gender']        = $gender;
                    $patient['blood_group']   = $bloodGroup;
                    $patient['date_of_birth'] = $dob;
                    $patient['age']           = $calculatedAge;
                    $patient['address']       = $address;

                    $successMsg = "Profile updated successfully! Your medical profile and electronic health record (EHR) have been synchronized.";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Profile Update Error: " . $e->getMessage());
                    $errorMsg = "Unable to save profile changes due to a server error. Please try again.";
                }
            }
        }
    }
}

// Format display values
$dispName    = $patient['full_name'] ?? 'Registered Patient';
$dispUid     = $patient['patient_uid'] ?? 'MP-2026-00000';
$dispEmail   = $patient['email'] ?? '';
$dispPhone   = $patient['phone'] ?? '';
$dispGender  = $patient['gender'] ?? 'Male';
$dispBlood   = $patient['blood_group'] ?? 'Unknown';
$dispDob     = $patient['date_of_birth'] ?? '';
$dispAge     = $patient['age'] ?? null;
$dispAddress = $patient['address'] ?? '';
$dispCreated = !empty($patient['created_at']) ? date('M d, Y', strtotime($patient['created_at'])) : date('M d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Patient Profile &amp; Medical Identity</title>

  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Patient Dashboard CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* Profile Specific Layout Enhancements */
    .profile-card-grid {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: 1.5rem;
      align-items: start;
    }
    @media (max-width: 992px) {
      .profile-card-grid {
        grid-template-columns: 1fr;
      }
    }

    .patient-identity-card {
      background: #ffffff;
      border: 1px solid var(--border-subtle);
      border-radius: 16px;
      padding: 1.5rem;
      box-shadow: 0 4px 16px rgba(0,0,0,0.03);
      text-align: center;
    }
    .patient-big-avatar {
      width: 80px;
      height: 80px;
      margin: 0 auto 1rem;
      border-radius: 50%;
      background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
      color: #0284c7;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.85rem;
      font-weight: 800;
      border: 3px solid #ffffff;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.15);
    }
    .uid-badge-locked {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: monospace;
      font-size: 0.88rem;
      font-weight: 800;
      color: #0369a1;
      background: #f0f9ff;
      border: 1.5px solid #bae6fd;
      padding: 4px 12px;
      border-radius: 999px;
      letter-spacing: 0.04em;
      margin: 6px 0 12px;
    }

    .form-group-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;
    }
    @media (max-width: 640px) {
      .form-group-grid {
        grid-template-columns: 1fr;
      }
    }

    .field-wrap {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 1rem;
    }
    .field-label {
      font-size: 0.8rem;
      font-weight: 700;
      color: #334155;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .field-input, .field-select, .field-textarea {
      width: 100%;
      padding: 0.72rem 0.95rem;
      font-size: 0.88rem;
      font-weight: 500;
      color: #0f172a;
      background: #ffffff;
      border: 1.5px solid #cbd5e1;
      border-radius: 10px;
      outline: none;
      transition: all 0.2s ease;
      font-family: inherit;
    }
    .field-input:focus, .field-select:focus, .field-textarea:focus {
      border-color: #0284c7;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
    }
    .field-input[readonly], .field-input[disabled] {
      background: #f8fafc;
      color: #64748b;
      cursor: not-allowed;
      border-color: #e2e8f0;
    }

    .alert-banner {
      padding: 1rem 1.25rem;
      border-radius: 12px;
      font-size: 0.88rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .alert-success {
      background: #ecfdf5;
      border: 1px solid #a7f3d0;
      color: #065f46;
    }
    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }
    .alert-info-locked {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
      padding: 0.75rem 1rem;
      border-radius: 10px;
      font-size: 0.78rem;
      line-height: 1.45;
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin-top: 1rem;
      text-align: left;
    }

    .btn-save-profile {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      border: none;
      padding: 0.75rem 1.5rem;
      border-radius: 10px;
      font-size: 0.9rem;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
      transition: all 0.2s ease;
    }
    .btn-save-profile:hover {
      opacity: 0.95;
      transform: translateY(-1px);
    }
  </style>
</head>
<body>

  <!-- Centralized Patient Sidebar Partial -->
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <!-- Central Primary Workspace Container -->
  <main class="viewport-full">

    <!-- Header Banner -->
    <div class="welcome-banner" style="margin-bottom: 1.5rem;">
      <div class="welcome-text">
        <h1>
          My Medical Profile
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </h1>
        <p>Manage your verified personal demographics, emergency contact info, and medical credentials</p>
      </div>
      <div class="banner-actions">
        <a href="dashboard.php" class="btn-action-telemed" style="text-decoration: none;">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          Overview Dashboard
        </a>
      </div>
    </div>

    <!-- Status Messages -->
    <?php if ($successMsg): ?>
      <div class="alert-banner alert-success">
        <svg style="width: 20px; height: 20px; stroke: currentColor; flex-shrink: 0;" fill="none" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        <div><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
      <div class="alert-banner alert-error">
        <svg style="width: 20px; height: 20px; stroke: currentColor; flex-shrink: 0;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <div><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    <?php endif; ?>

    <div class="profile-card-grid">

      <!-- Left Column: Patient Identity & Vitals Summary Card -->
      <aside class="patient-identity-card">
        <div class="patient-big-avatar">
          <?= htmlspecialchars(strtoupper(substr($dispName, 0, 2)), ENT_QUOTES, 'UTF-8') ?>
        </div>

        <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: 2px;">
          <?= htmlspecialchars($dispName, ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 6px;">
          <?= htmlspecialchars($dispEmail, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <!-- Permanent Read-Only Locked UID -->
        <div>
          <span class="uid-badge-locked" title="Permanent Non-Transferable Patient Identifier">
            <svg style="width: 14px; height: 14px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            <?= htmlspecialchars($dispUid, ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>

        <div style="display: flex; justify-content: center; gap: 8px; margin: 10px 0;">
          <span style="font-size: 0.72rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 999px;">
            Verified Patient
          </span>
          <span style="font-size: 0.72rem; font-weight: 700; background: #fef2f2; color: #dc2626; padding: 3px 8px; border-radius: 999px;">
            Blood Type: <?= htmlspecialchars($dispBlood, ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>

        <div style="border-top: 1px dashed #e2e8f0; margin: 1.25rem 0; padding-top: 1rem; text-align: left; font-size: 0.8rem; color: #475569;">
          <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span style="color: #64748b;">Member Since:</span>
            <strong><?= htmlspecialchars($dispCreated, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span style="color: #64748b;">Current Age:</span>
            <strong><?= $dispAge !== null ? (int)$dispAge . ' Years' : 'Calculated via DOB' ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span style="color: #64748b;">Portal Access:</span>
            <strong style="color: #059669;">Active &bull; 24/7</strong>
          </div>
        </div>

        <div class="alert-info-locked">
          <svg style="width: 16px; height: 16px; stroke: currentColor; flex-shrink: 0; margin-top: 2px;" fill="none" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
          <div>
            <strong>Strict Identity Protection:</strong> Your Patient UID (<code><?= htmlspecialchars($dispUid, ENT_QUOTES, 'UTF-8') ?></code>) is permanently bound to your clinical health records and cannot be altered.
          </div>
        </div>
      </aside>

      <!-- Right Column: Interactive Profile Edit Form -->
      <section class="admin-stack-card" style="margin-bottom: 0;">
        <div class="admin-stack-header" style="border-bottom: 1px solid #f1f5f9; padding-bottom: 1rem; margin-bottom: 1.25rem;">
          <div class="admin-stack-title-group">
            <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a;">Personal &amp; Contact Details</h3>
            <p style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Ensure your contact information and vitals are accurate for prescription delivery and emergency triage</p>
          </div>
          <span style="font-size: 0.74rem; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 4px 10px; border-radius: 8px;">
            EHR Synchronized
          </span>
        </div>

        <form method="POST" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

          <!-- Row 1: Patient UID (Permanently Locked) & Email (Read-Only) -->
          <div class="form-group-grid">
            <div class="field-wrap">
              <label class="field-label" for="field_patient_uid">
                <span>Patient Unique ID (UID)</span>
                <span style="color: #0369a1; font-size: 0.72rem; font-weight: 800;">🔒 READ-ONLY / LOCKED</span>
              </label>
              <input type="text" id="field_patient_uid" class="field-input" value="<?= htmlspecialchars($dispUid, ENT_QUOTES, 'UTF-8') ?>" readonly disabled>
              <small style="font-size: 0.72rem; color: #64748b;">Permanent electronic health record identifier.</small>
            </div>

            <div class="field-wrap">
              <label class="field-label" for="field_email">
                <span>Official Email Address</span>
                <span style="color: #64748b; font-size: 0.72rem;">Login Credential</span>
              </label>
              <input type="email" id="field_email" class="field-input" value="<?= htmlspecialchars($dispEmail, ENT_QUOTES, 'UTF-8') ?>" readonly disabled>
              <small style="font-size: 0.72rem; color: #64748b;">Contact hospital administration to change your login email.</small>
            </div>
          </div>

          <!-- Row 2: Full Name & Contact Phone -->
          <div class="form-group-grid">
            <div class="field-wrap">
              <label class="field-label" for="field_full_name">
                <span>Full Legal Name <span style="color: #dc2626;">*</span></span>
              </label>
              <input type="text" id="field_full_name" name="full_name" class="field-input" required 
                     value="<?= htmlspecialchars($dispName, ENT_QUOTES, 'UTF-8') ?>" 
                     placeholder="e.g. Mohammad Ali">
            </div>

            <div class="field-wrap">
              <label class="field-label" for="field_phone">
                <span>Contact Phone Number <span style="color: #dc2626;">*</span></span>
              </label>
              <input type="tel" id="field_phone" name="phone" class="field-input" required 
                     value="<?= htmlspecialchars($dispPhone, ENT_QUOTES, 'UTF-8') ?>" 
                     placeholder="e.g. 017XXXXXXXX">
            </div>
          </div>

          <!-- Row 3: Blood Group & Gender -->
          <div class="form-group-grid">
            <div class="field-wrap">
              <label class="field-label" for="field_blood_group">
                <span>Blood Group / ABO Type <span style="color: #dc2626;">*</span></span>
              </label>
              <select id="field_blood_group" name="blood_group" class="field-select" required>
                <?php 
                  $bloodOptions = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];
                  foreach ($bloodOptions as $opt): 
                ?>
                  <option value="<?= $opt ?>" <?= ($dispBlood === $opt) ? 'selected' : '' ?>>
                    <?= $opt === 'Unknown' ? 'Unknown / Pending Test' : $opt ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field-wrap">
              <label class="field-label" for="field_gender">
                <span>Biological Gender <span style="color: #dc2626;">*</span></span>
              </label>
              <select id="field_gender" name="gender" class="field-select" required>
                <option value="Male" <?= ($dispGender === 'Male') ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= ($dispGender === 'Female') ? 'selected' : '' ?>>Female</option>
                <option value="Other" <?= ($dispGender === 'Other') ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
          </div>

          <!-- Row 4: Date of Birth & Calculated Age Indicator -->
          <div class="form-group-grid">
            <div class="field-wrap">
              <label class="field-label" for="field_dob">
                <span>Date of Birth</span>
                <span style="font-size: 0.72rem; color: #64748b;">(Calculates Age)</span>
              </label>
              <input type="date" id="field_dob" name="date_of_birth" class="field-input" 
                     value="<?= htmlspecialchars($dispDob, ENT_QUOTES, 'UTF-8') ?>" 
                     max="<?= date('Y-m-d') ?>">
            </div>

            <div class="field-wrap">
              <label class="field-label" for="field_age_display">
                <span>Current Calculated Age</span>
              </label>
              <input type="text" id="field_age_display" class="field-input" readonly disabled
                     value="<?= $dispAge !== null ? (int)$dispAge . ' Years Old' : 'Enter DOB to calculate' ?>">
              <small style="font-size: 0.72rem; color: #64748b;">Automatically derived from your Date of Birth.</small>
            </div>
          </div>

          <!-- Row 5: Residential Address -->
          <div class="field-wrap">
            <label class="field-label" for="field_address">
              <span>Residential Address &amp; Location</span>
            </label>
            <input type="text" id="field_address" name="address" class="field-input" 
                   value="<?= htmlspecialchars($dispAddress, ENT_QUOTES, 'UTF-8') ?>" 
                   placeholder="e.g. House #14, Road #4, Sector 7, Uttara, Dhaka">
            <small style="font-size: 0.72rem; color: #64748b;">Used for emergency ambulance dispatch and medicine deliveries.</small>
          </div>

          <!-- Action Buttons -->
          <div style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f1f5f9;">
            <a href="dashboard.php" style="font-size: 0.85rem; font-weight: 600; color: #64748b; text-decoration: none; padding: 0.75rem 1rem;">
              Cancel
            </a>
            <button type="submit" class="btn-save-profile">
              <svg style="width: 18px; height: 18px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
              Save Profile Changes
            </button>
          </div>

        </form>
      </section>

    </div>

  </main>

  <!-- Auto-calculate Age on DOB change -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const dobInput = document.getElementById('field_dob');
      const ageDisplay = document.getElementById('field_age_display');

      if (dobInput && ageDisplay) {
        dobInput.addEventListener('change', () => {
          const val = dobInput.value;
          if (!val) {
            ageDisplay.value = 'Enter DOB to calculate';
            return;
          }
          const dob = new Date(val);
          const today = new Date();
          let age = today.getFullYear() - dob.getFullYear();
          const m = today.getMonth() - dob.getMonth();
          if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
            age--;
          }
          if (age >= 0 && age <= 125) {
            ageDisplay.value = `${age} Years Old`;
          } else {
            ageDisplay.value = 'Invalid Date';
          }
        });
      }
    });
  </script>

</body>
</html>

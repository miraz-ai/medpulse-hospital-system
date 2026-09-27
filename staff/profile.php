<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Staff Portal — Profile & Account Settings
 */

require_once __DIR__ . '/../includes/session_guard.php';
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

// Authentication Guard: Staff role required (or elevated admin/super_admin)
$role = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['staff', 'admin', 'super_admin', 'nurse', 'receptionist'], true)) {
    medpulseDestroySession('../login.php');
}

$currentUserId     = (int)$_SESSION['user_id'];
$sessionHospitalId = (int)($_SESSION['branch_id'] ?? $_SESSION['hospital_id'] ?? 1);

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$flashMessage = null;
$flashType    = 'success';

// Handle Profile / Password Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $flashMessage = 'Security token invalid. Please refresh the page.';
        $flashType    = 'error';
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $phone    = trim($_POST['phone'] ?? '');

            if (empty($fullName)) {
                $flashMessage = 'Full name cannot be blank.';
                $flashType    = 'error';
            } else {
                try {
                    $upd = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
                    $upd->execute([$fullName, $phone, $currentUserId]);
                    $_SESSION['full_name'] = $fullName;
                    $flashMessage = 'Profile information updated successfully.';
                } catch (PDOException $e) {
                    $flashMessage = 'Database error updating profile.';
                    $flashType    = 'error';
                }
            }
        } elseif ($action === 'update_password') {
            $currentPwd = $_POST['current_password'] ?? '';
            $newPwd     = $_POST['new_password'] ?? '';
            $confirmPwd = $_POST['confirm_password'] ?? '';

            if (empty($newPwd) || strlen($newPwd) < 6) {
                $flashMessage = 'New password must be at least 6 characters.';
                $flashType    = 'error';
            } elseif ($newPwd !== $confirmPwd) {
                $flashMessage = 'New password and confirmation do not match.';
                $flashType    = 'error';
            } else {
                try {
                    $pwdStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
                    $pwdStmt->execute([$currentUserId]);
                    $hash = $pwdStmt->fetchColumn();

                    if (!password_verify($currentPwd, $hash)) {
                        $flashMessage = 'Current password is incorrect.';
                        $flashType    = 'error';
                    } else {
                        $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$newHash, $currentUserId]);
                        $flashMessage = 'Password changed successfully.';
                    }
                } catch (PDOException $e) {
                    $flashMessage = 'Database error updating password.';
                    $flashType    = 'error';
                }
            }
        }
    }
}

// Fetch Staff Profile
try {
    $staffStmt = $pdo->prepare("
        SELECT s.staff_id, s.hospital_id, s.department, s.role_title, u.full_name, u.email, u.phone, u.status, u.created_at,
               h.name AS hospital_name, h.location AS hospital_location, h.code AS hospital_code
        FROM users u
        LEFT JOIN staff s ON s.user_id = u.user_id
        LEFT JOIN hospitals h ON h.hospital_id = COALESCE(s.hospital_id, u.hospital_id, :sess_hosp, 1)
        WHERE u.user_id = :uid
        LIMIT 1
    ");
    $staffStmt->execute([':uid' => $currentUserId, ':sess_hosp' => $sessionHospitalId]);
    $staffProfile = $staffStmt->fetch(PDO::FETCH_ASSOC);

    $staffName         = $staffProfile['full_name'] ?? $_SESSION['full_name'] ?? 'Hospital Staff';
    $staffEmail        = $staffProfile['email'] ?? '';
    $staffPhone        = $staffProfile['phone'] ?? '';
    $staffStatus       = ucfirst($staffProfile['status'] ?? 'Active');
    $staffDesignation  = $staffProfile['role_title'] ?? $staffProfile['department'] ?? 'Clinical Operations Officer';
    $staffDepartment   = $staffProfile['department'] ?? 'Emergency & Admissions';
    $staffHospitalName = $staffProfile['hospital_name'] ?? 'Central Medical Center';
    $staffHospitalCode = $staffProfile['hospital_code'] ?? 'CMC';
    $staffId           = $staffProfile['staff_id'] ?? 0;
    $registeredAt      = !empty($staffProfile['created_at']) ? date('M j, Y', strtotime($staffProfile['created_at'])) : 'Active Member';
} catch (PDOException $e) {
    die('Database connection failure: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Staff Profile &amp; Settings</title>

  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">

  <!-- Modern Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">

  <!-- Core Layout Stylesheet -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* Clean Topbar */
    .staff-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.25rem;
      padding: 0.75rem 1.25rem;
      background: var(--surface, #ffffff);
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.8));
      border-radius: 1rem;
      margin-bottom: 1.75rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
      min-height: 56px;
      box-sizing: border-box;
    }

    .topbar-context {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .topbar-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(13, 148, 136, 0.08);
      color: var(--brand-teal, #0d9488);
      border: 1px solid rgba(13, 148, 136, 0.22);
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.02em;
      line-height: 1;
    }

    .branch-pill-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 6px #10b981;
      flex-shrink: 0;
    }

    .staff-profile-card {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #f8fafc;
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.8));
      border-radius: 9999px;
      padding: 4px 14px 4px 5px;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
      transition: all 0.2s ease;
    }
    .staff-profile-card:hover {
      border-color: rgba(13, 148, 136, 0.35);
      background: #ffffff;
    }

    .staff-avatar-badge {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand-primary, #0284c7), var(--brand-teal, #0d9488));
      color: #ffffff;
      font-weight: 800;
      font-size: 0.82rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(13, 148, 136, 0.25);
    }

    .staff-profile-meta {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }

    .staff-pill-name {
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--text-heading, #0f172a);
    }

    .staff-meta-sep {
      color: var(--text-muted, #94a3b8);
      font-size: 0.75rem;
    }

    .staff-pill-designation {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--brand-teal, #0d9488);
    }

    .staff-pill-branch {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: #ffffff;
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.8));
      padding: 3px 10px;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 600;
      color: var(--text-body, #334155);
      margin-left: 2px;
      white-space: nowrap;
    }

    /* Grid for profile settings */
    .profile-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.5rem;
    }
    @media (min-width: 1024px) {
      .profile-grid {
        grid-template-columns: 1fr 1fr;
      }
    }

    .profile-card {
      background: var(--surface, #ffffff);
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.8));
      border-radius: 1rem;
      padding: 1.5rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }

    .profile-card-title {
      font-size: 1rem;
      font-weight: 800;
      color: var(--text-heading, #0f172a);
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0 0 4px 0;
    }
    .profile-card-sub {
      font-size: 0.78rem;
      color: var(--text-muted, #64748b);
      margin: 0 0 1.25rem 0;
    }

    .form-group {
      margin-bottom: 1rem;
    }
    .form-label {
      display: block;
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--text-heading, #0f172a);
      margin-bottom: 0.35rem;
    }
    .form-input {
      width: 100%;
      padding: 0.55rem 0.85rem;
      border-radius: 0.5rem;
      border: 1.5px solid var(--surface-border, #e2e8f0);
      background: var(--surface, #ffffff);
      font-size: 0.84rem;
      font-family: inherit;
      color: var(--text-heading, #0f172a);
      box-sizing: border-box;
      transition: border-color 0.15s ease;
    }
    .form-input:focus {
      outline: none;
      border-color: var(--brand-teal, #0d9488);
    }
    .form-input:disabled {
      background: #f8fafc;
      color: #64748b;
      cursor: not-allowed;
    }

    .btn-save {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 0.55rem 1.25rem;
      border-radius: 0.5rem;
      background: linear-gradient(135deg, #0d9488, #0f766e);
      color: #ffffff;
      font-weight: 700;
      font-size: 0.82rem;
      border: none;
      cursor: pointer;
      box-shadow: 0 2px 6px rgba(13, 148, 136, 0.25);
      transition: all 0.15s ease;
    }
    .btn-save:hover {
      filter: brightness(1.08);
      transform: translateY(-1px);
    }

    .flash-alert {
      padding: 10px 16px;
      border-radius: 0.5rem;
      margin-bottom: 1.5rem;
      font-size: 0.82rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .flash-alert.success {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .flash-alert.error {
      background: #fff1f2;
      color: #9f1239;
      border: 1px solid rgba(244, 63, 94, 0.3);
    }
  </style>
</head>
<body>

  <!-- Centralized Staff Sidebar Component -->
  <?php require_once __DIR__ . '/../includes/staff_sidebar.php'; ?>

  <!-- Main Viewport Canvas -->
  <main class="viewport-full">

    <!-- Topbar with clean single-line vertical centering -->
    <header class="staff-topbar">
      <div class="topbar-context">
        <div class="topbar-badge">
          <span class="branch-pill-dot"></span>
          <span>Staff Clinical Console</span>
        </div>
      </div>
      <div class="staff-profile-card">
        <div class="staff-avatar-badge"><?= htmlspecialchars(strtoupper(substr($staffName, 0, 1) ?: 'S'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="staff-profile-meta">
          <span class="staff-pill-name" id="topbarStaffName"><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="staff-meta-sep">&bull;</span>
          <span class="staff-pill-designation" id="topbarStaffDesignation"><?= htmlspecialchars($staffDesignation, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="staff-pill-branch" title="<?= htmlspecialchars($staffHospitalName, ENT_QUOTES, 'UTF-8') ?>">
          <svg class="ui-ico ui-ico-sm" style="stroke: #0d9488; width: 13px; height: 13px;" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M13 7l6 3v11M9 9v.01M9 13v.01M9 17v.01M17 13v.01M17 17v.01"/></svg>
          <span id="topbarStaffBranch"><?= htmlspecialchars($staffHospitalName, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </header>

    <!-- Welcome Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="branch-identity-badge" style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;background:rgba(13,148,136,0.1);color:#0d9488;border-radius:20px;font-size:0.72rem;font-weight:700;margin-bottom:6px;">
          <span class="badge-dot" style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>
          Account Security &amp; Credentials
        </div>
        <h1 style="margin:4px 0 6px;font-size:1.4rem;font-weight:800;color:var(--text-heading);">
          Staff Profile &amp; Settings
        </h1>
        <p style="margin:0;font-size:0.84rem;color:var(--text-muted);">
          Manage your official contact credentials, hospital affiliation, and account security details.
        </p>
      </div>
    </div>

    <!-- Flash message -->
    <?php if ($flashMessage): ?>
      <div class="flash-alert <?= $flashType ?>">
        <span><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endif; ?>

    <div class="profile-grid">
      <!-- Card 1: Official Personnel Details -->
      <div class="profile-card">
        <h3 class="profile-card-title">
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 18px; height: 18px;" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          Personal &amp; Contact Details
        </h3>
        <p class="profile-card-sub">Your registered clinical profile details within this facility</p>

        <form method="POST" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="_action" value="update_profile">

          <div class="form-group">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-input" value="<?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Official Email (Username)</label>
            <input type="email" class="form-input" value="<?= htmlspecialchars($staffEmail, ENT_QUOTES, 'UTF-8') ?>" disabled>
          </div>

          <div class="form-group">
            <label class="form-label">Contact Phone</label>
            <input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($staffPhone, ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Designation / Role Title</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($staffDesignation, ENT_QUOTES, 'UTF-8') ?>" disabled>
          </div>

          <div class="form-group">
            <label class="form-label">Assigned Facility</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($staffHospitalName, ENT_QUOTES, 'UTF-8') ?>" disabled>
          </div>

          <button type="submit" class="btn-save">
            Update Profile Information
          </button>
        </form>
      </div>

      <!-- Card 2: Security & Credentials -->
      <div class="profile-card">
        <h3 class="profile-card-title">
          <svg class="ui-ico" style="stroke: #0284c7; width: 18px; height: 18px;" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
          Security &amp; Password
        </h3>
        <p class="profile-card-sub">Change your account password to maintain administrative compliance</p>

        <form method="POST" action="profile.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="_action" value="update_password">

          <div class="form-group">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-input" required>
          </div>

          <div class="form-group">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-input" placeholder="Minimum 6 characters" required>
          </div>

          <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-input" required>
          </div>

          <button type="submit" class="btn-save" style="background: linear-gradient(135deg, #0284c7, #0369a1);">
            Change Password
          </button>
        </form>
      </div>
    </div>
  </main>

</body>
</html>

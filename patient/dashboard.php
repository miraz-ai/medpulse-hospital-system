<?php
require_once __DIR__ . '/../includes/patient_auth.php';

try {
    $stmt = $pdo->prepare("
        SELECT u.*, p.patient_uid, p.dob AS patient_dob, p.blood_group AS patient_blood_group
        FROM users u 
        LEFT JOIN patients p ON u.user_id = p.user_id
        WHERE u.user_id = :id AND u.role = 'Patient' 
        LIMIT 1
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify account active status and existence
    if (!$patient || $patient['status'] !== 'active') {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        header("Location: ../login.php");
        exit();
    }

    $patientName    = $patient['full_name'];
    $patientEmail   = $patient['email'];
    $patientUid     = !empty($patient['patient_uid']) ? $patient['patient_uid'] : ($_SESSION['patient_uid'] ?? ('MP-' . date('Y') . '-' . str_pad((string)$patient['user_id'], 5, '0', STR_PAD_LEFT)));
    $bloodGroup     = !empty($patient['patient_blood_group']) ? $patient['patient_blood_group'] : (!empty($patient['blood_group']) ? $patient['blood_group'] : 'Unknown');
    $gender         = !empty($patient['gender']) ? $patient['gender'] : 'Male';
    $prescriptions  = !empty($patient['prescriptions']) ? $patient['prescriptions'] : null;
    $rawDob         = !empty($patient['patient_dob']) ? $patient['patient_dob'] : ($patient['date_of_birth'] ?? null);

    // Calculate dynamic age strictly from actual dob
    $age = null;
    $dobFormatted = null;
    if (!empty($rawDob)) {
        try {
            $dobObj = new DateTime($rawDob);
            $age = (new DateTime())->diff($dobObj)->y;
            $dobFormatted = $dobObj->format('d M Y');
        } catch (Exception $e) {
            $age = !empty($patient['age']) ? (int)$patient['age'] : null;
        }
    } else {
        $age = !empty($patient['age']) ? (int)$patient['age'] : null;
    }

    // Relational Assigned Doctor Lookup:
    // 1. Prioritize active inpatient attending physician from bed allocations
    // 2. Next, check upcoming or active outpatient consultation appointment
    // 3. Fallback placeholder 'Unassigned' (with safe null-coalescing guard)
    $assignedDoctor = $patient['assigned_doctor'] ?? null;
    if (empty($assignedDoctor)) {
        try {
            $docStmt = $pdo->prepare("
                SELECT u.full_name 
                FROM bed_allocations ba 
                JOIN users u ON ba.attending_doctor_id = u.user_id 
                WHERE ba.patient_id = :id AND ba.status = 'Active' 
                ORDER BY ba.admitted_at DESC 
                LIMIT 1
            ");
            $docStmt->execute([':id' => $patient['user_id']]);
            $assignedDoctor = $docStmt->fetchColumn();
        } catch (PDOException $e) {
            $assignedDoctor = null;
        }
    }

    if (empty($assignedDoctor)) {
        try {
            $docStmt = $pdo->prepare("
                SELECT u.full_name 
                FROM appointments a 
                JOIN users u ON a.doctor_id = u.user_id 
                WHERE a.patient_id = :id AND a.status IN ('booked', 'checked_in', 'in_consultation') 
                ORDER BY a.appointment_date ASC, a.token_number ASC 
                LIMIT 1
            ");
            $docStmt->execute([':id' => $patient['user_id']]);
            $assignedDoctor = $docStmt->fetchColumn();
        } catch (PDOException $e) {
            $assignedDoctor = null;
        }
    }

    $assignedDoctor = !empty($assignedDoctor) ? $assignedDoctor : 'Unassigned';

    // Live OPD Queue Status Lookup
    require_once __DIR__ . '/../controllers/AppointmentController.php';
    $activeOpdQueue = AppointmentController::getPatientLiveQueue($pdo, (int)$patient['user_id']);

    // Multi-Hospital Network Live Bed Matrix & Patient Bed Pre-Reservation (45-Minute Hold) Engine
    require_once __DIR__ . '/../controllers/BedReservationController.php';

    // Handle Quick Bed Hold Cancellation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_bed_hold') {
        $resId = (int)($_POST['reservation_id'] ?? 0);
        BedReservationController::cancelReservation($pdo, (int)$patient['user_id'], $resId);
        header("Location: dashboard.php?bed_cancelled=1#networkBedMatrixSection");
        exit();
    }

    BedReservationController::releaseExpiredHolds($pdo);
    $activeBedHold = BedReservationController::getPatientActiveReservation($pdo, (int)$patient['user_id']);
    $networkBedMatrix = BedReservationController::getNetworkBedMatrix($pdo);

} catch (PDOException $e) {
    error_log("Database error in patient_dashboard.php: " . $e->getMessage());
    die("A database communication failure occurred. Please contact hospital support.");
}

// Dynamic Time-Based Greeting (Asia/Dhaka)
date_default_timezone_set('Asia/Dhaka');
$hour = (int)date('H');
if ($hour >= 5 && $hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Patient Portal</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- External Custom CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    .opd-queue-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 1.5rem 1.75rem;
      margin-bottom: 1.75rem;
      box-shadow: 0 4px 20px rgba(0,0,0,0.04);
    }
    .opd-queue-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.25rem;
      flex-wrap: wrap;
      gap: 12px;
    }
    .opd-badge-chip {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: rgba(2, 132, 199, 0.08);
      color: var(--brand-primary);
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.05em;
      padding: 3px 9px;
      border-radius: 20px;
      margin-bottom: 4px;
    }
    .opd-title {
      font-size: 1.2rem;
      font-weight: 800;
      color: var(--text-heading);
      margin: 0;
    }
    .opd-subtitle {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin: 2px 0 0;
    }
    .btn-new-opd-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--brand-primary);
      text-decoration: none;
      padding: 0.4rem 0.85rem;
      border: 1.5px solid #bae6fd;
      border-radius: 8px;
      background: #f0f9ff;
      transition: all 0.2s;
    }
    .btn-new-opd-link:hover {
      background: #e0f2fe;
    }
    .opd-doctor-info {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0.9rem 1.1rem;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      margin-bottom: 1.25rem;
    }
    .opd-doctor-avatar {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .opd-doctor-name {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-heading);
      margin: 0 0 3px;
    }
    .opd-doctor-meta {
      font-size: 0.82rem;
      color: var(--text-muted);
    }
    .opd-metrics-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1rem;
    }
    @media (max-width: 768px) {
      .opd-metrics-grid { grid-template-columns: 1fr; }
    }
    .opd-metric-box {
      padding: 1.15rem;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      background: #ffffff;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    .metric-my-token {
      background: linear-gradient(135deg, rgba(2, 132, 199, 0.05), rgba(13, 148, 136, 0.05));
      border-color: #bae6fd;
    }
    .metric-serving {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.06), rgba(217, 119, 6, 0.04));
      border-color: #fde68a;
    }
    .metric-ahead {
      background: #f8fafc;
    }
    .opd-metric-label {
      font-size: 0.76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
      margin-bottom: 6px;
    }
    .opd-metric-val {
      font-size: 1.75rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.1;
      margin-bottom: 4px;
    }
    .metric-my-token .opd-metric-val {
      color: var(--brand-primary);
    }
    .metric-serving .opd-metric-val {
      color: #b45309;
    }
    .opd-metric-sub {
      font-size: 0.74rem;
      color: var(--text-muted);
    }
    .opd-live-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 12px;
      border-radius: 20px;
      font-weight: 700;
      font-size: 0.82rem;
      margin-bottom: 6px;
      width: fit-content;
    }
    .pill-waiting {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .pill-turn-now {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
      animation: turnPulse 1.8s infinite;
    }
    .pulse-dot-blue {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.3);
    }
    .pulse-dot-green {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.4);
    }
    @keyframes turnPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
      50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    }
    .opd-wait-badge {
      color: #475569;
      font-weight: 600;
    }
    .opd-future-card {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 1.15rem 1.4rem;
      background: #f0fdf4;
      border: 1.5px solid #bbf7d0;
      border-radius: 12px;
    }
    .opd-future-icon {
      width: 44px;
      height: 44px;
      background: #22c55e;
      color: #fff;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .opd-future-title {
      font-size: 0.96rem;
      font-weight: 800;
      color: #15803d;
      margin-bottom: 3px;
    }
    .opd-future-sub {
      font-size: 0.82rem;
      color: #334155;
      margin: 0;
    }
    .opd-empty-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      padding: 1.15rem 1.35rem;
      background: #f8fafc;
      border: 1px dashed #cbd5e1;
      border-radius: 12px;
    }
    .opd-empty-text h4 {
      margin: 0 0 4px;
      font-size: 0.95rem;
      font-weight: 800;
      color: var(--text-heading);
    }
    .opd-empty-text p {
      margin: 0;
      font-size: 0.82rem;
      color: var(--text-muted);
    }

    /* Premium Hospital Matrix Card & Hover Interaction */
    .hosp-matrix-card-link {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 1.35rem 1.4rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      cursor: pointer;
      text-decoration: none;
      color: inherit;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .hosp-matrix-card-link:hover {
      transform: translateY(-6px);
      box-shadow: 0 16px 32px rgba(0, 0, 0, 0.08), 0 4px 8px rgba(0, 0, 0, 0.04);
      border-color: #0d9488 !important;
    }
    .hosp-matrix-card-link.selected {
      border: 2px solid #0d9488 !important;
      background: rgba(240, 253, 250, 0.4) !important;
      box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.2), 0 8px 24px rgba(13, 148, 136, 0.16);
    }
    .hosp-matrix-card-link .btn-card-action {
      text-align: center;
      padding: 0.55rem 0.85rem;
      font-size: 0.78rem;
      font-weight: 700;
      border-radius: 8px;
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #cbd5e1;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      transition: all 0.25s ease;
      margin-top: 0.65rem;
    }
    .hosp-matrix-card-link:hover .btn-card-action {
      background: #0d9488;
      color: #ffffff !important;
      border-color: #0d9488;
    }
    .hosp-matrix-card-link:hover .action-arrow {
      transform: translateX(4px);
    }
    .hosp-matrix-card-link.selected .btn-card-action {
      background: #0d9488;
      color: #ffffff;
      border-color: #0d9488;
    }
    .action-arrow {
      display: inline-block;
      transition: transform 0.2s ease;
    }
  </style>
</head>
<body>

  <!-- Shared Canonical Patient Sidebar Partial -->
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <!-- Central Workspace -->
  <main class="viewport">
    
    <!-- Dynamic Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?>! 
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
        </h1>
        <p>Your electronic medical record, consultations, and diagnostic tests are fully synced.</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
          Virtual Room
        </button>
        <a href="book_appointment.php" class="btn-action-gradient" style="text-decoration: none;">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          New OPD Booking
        </a>
      </div>
    </div>

    <!-- Active Bed Pre-Reservation Hold Banner -->
    <?php if (!empty($activeBedHold)): ?>
      <div class="active-bed-hold-banner" id="activeBedHoldBanner" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #ffffff; border-radius: 16px; padding: 1.25rem 1.75rem; margin-bottom: 1.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; border: 2px solid #7dd3fc; box-shadow: 0 10px 25px rgba(2, 132, 199, 0.3);">
        <div style="display: flex; align-items: center; gap: 1rem;">
          <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
          </div>
          <div>
            <div style="display: flex; align-items: center; gap: 6px;">
              <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#fef08a; animation: pulse 1.5s infinite;"></span>
              <span style="font-size: 0.72rem; text-transform: uppercase; font-weight: 800; letter-spacing: 0.8px; color: #bae6fd;">TEMPORARY BED PRE-RESERVATION</span>
            </div>
            <h3 style="margin: 0.2rem 0; font-size: 1.2rem; font-weight: 800; color: #ffffff;">
              Hold Active: Bed <?= htmlspecialchars($activeBedHold['bed_number']) ?> at <?= htmlspecialchars($activeBedHold['hospital_name']) ?>
            </h3>
            <div style="font-size: 0.82rem; color: #e0f2fe;">
              Ward: <strong><?= htmlspecialchars($activeBedHold['ward_type']) ?></strong> (Floor <?= (int)$activeBedHold['floor_number'] ?>) &bull; 
              Daily Rate: <strong>&#2547;<?= number_format((float)$activeBedHold['daily_rate'], 2) ?></strong> &bull; 
              Location: <?= htmlspecialchars($activeBedHold['hospital_location']) ?>
            </div>
          </div>
        </div>

        <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
          <div style="background: rgba(15, 23, 42, 0.35); border: 1px solid rgba(255, 255, 255, 0.25); border-radius: 10px; padding: 0.5rem 1.2rem; text-align: center;">
            <div style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: #bae6fd;">Expires In</div>
            <div id="patientDashboardHoldTimer" data-seconds="<?= (int)$activeBedHold['seconds_remaining'] ?>" style="font-size: 1.5rem; font-weight: 800; color: #fef08a; font-variant-numeric: tabular-nums;">
              <?= $activeBedHold['countdown_formatted'] ?>
            </div>
          </div>

          <form method="POST" onsubmit="return confirm('Cancel this bed hold and release it back to the hospital vacancy?');" style="margin: 0;">
            <input type="hidden" name="action" value="cancel_bed_hold">
            <input type="hidden" name="reservation_id" value="<?= (int)$activeBedHold['reservation_id'] ?>">
            <button type="submit" class="btn-teal-action" style="background: rgba(239, 68, 68, 0.25); color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.3); padding: 0.55rem 1rem; font-size: 0.8rem; font-weight: 700; border-radius: 8px; cursor: pointer;">
              Cancel Hold
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- Live OPD Queue Tracker Widget -->
    <div class="opd-queue-card" id="opdQueueWidget">
      <div class="opd-queue-header">
        <div class="opd-header-left">
          <div class="opd-badge-chip">
            <div class="radar-pulse-dot"></div>
            <span>OPD CHAMBER QUEUE TRACKER</span>
          </div>
          <h2 class="opd-title">Live Outpatient Queue Progression</h2>
          <p class="opd-subtitle">Real-time chamber synchronization &bull; Dynamic serial countdown</p>
        </div>
        <div class="opd-header-right">
          <a href="book_appointment.php" class="btn-new-opd-link">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Book Another Consultation
          </a>
        </div>
      </div>

      <?php if (!empty($activeOpdQueue)): ?>
        <?php if ($activeOpdQueue['is_today']): ?>
          <!-- Active Today's Queue Tracker -->
          <div class="opd-tracker-body">
            <div class="opd-doctor-info">
              <div class="opd-doctor-avatar">
                <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
              </div>
              <div>
                <h3 class="opd-doctor-name"><?= htmlspecialchars($activeOpdQueue['doctor_name']) ?></h3>
                <div class="opd-doctor-meta">
                  <span><?= htmlspecialchars($activeOpdQueue['specialty']) ?></span> &bull; 
                  <strong style="color: var(--brand-primary);"><?= htmlspecialchars($activeOpdQueue['hospital_name']) ?></strong> &bull;
                  <span>Chamber: <?= htmlspecialchars($activeOpdQueue['room_number']) ?></span>
                </div>
              </div>
            </div>

            <div class="opd-metrics-grid">
              <!-- Your Token -->
              <div class="opd-metric-box metric-my-token">
                <span class="opd-metric-label">Your Serial Number</span>
                <div class="opd-metric-val" id="myTokenDisplay">#<?= $activeOpdQueue['token_number'] ?></div>
                <span class="opd-metric-sub"><?= htmlspecialchars($activeOpdQueue['time_slot']) ?> Shift (Today)</span>
              </div>

              <!-- Currently Serving -->
              <div class="opd-metric-box metric-serving">
                <span class="opd-metric-label">Currently Serving</span>
                <div class="opd-metric-val" id="currentServingDisplay">
                  <?= $activeOpdQueue['current_serving'] > 0 ? ('#' . $activeOpdQueue['current_serving']) : 'Chamber Idle' ?>
                </div>
                <span class="opd-metric-sub">Attending Physician Chamber</span>
              </div>

              <!-- Live Indicator Pill & Estimated Wait -->
              <div class="opd-metric-box metric-ahead">
                <span class="opd-metric-label">Queue Position</span>
                <div id="queuePillContainer">
                  <?php if ($activeOpdQueue['is_my_turn']): ?>
                    <div class="opd-live-pill pill-turn-now">
                      <div class="pulse-dot-green"></div>
                      <span>YOUR TURN &ndash; ENTER CHAMBER</span>
                    </div>
                  <?php else: ?>
                    <div class="opd-live-pill pill-waiting">
                      <div class="pulse-dot-blue"></div>
                      <span id="peopleAheadText"><?= $activeOpdQueue['people_ahead'] ?> Patients Ahead</span>
                      <span class="opd-wait-badge" id="waitBadgeText">(~<?= $activeOpdQueue['estimated_wait_mins'] ?> mins wait)</span>
                    </div>
                  <?php endif; ?>
                </div>
                <span class="opd-metric-sub" id="trackerFooterNotice">Estimated at ~8 mins per patient</span>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- Future Scheduled Appointment -->
          <div class="opd-future-card">
            <div class="opd-future-icon">
              <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            </div>
            <div class="opd-future-content">
              <div class="opd-future-title">
                Scheduled for <?= htmlspecialchars($activeOpdQueue['formatted_date']) ?> | Serial: #<?= $activeOpdQueue['token_number'] ?> (Queue goes live on appointment day)
              </div>
              <p class="opd-future-sub">
                Attending Specialist: <strong><?= htmlspecialchars($activeOpdQueue['doctor_name']) ?></strong> &bull; <?= htmlspecialchars($activeOpdQueue['hospital_name']) ?> (<?= htmlspecialchars($activeOpdQueue['time_slot']) ?> Shift)
              </p>
            </div>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <!-- No Active Appointment Prompt -->
        <div class="opd-empty-card">
          <div class="opd-empty-text">
            <h4>No Active OPD Appointment</h4>
            <p>Consult with leading specialists across MedPulse network hospitals with guaranteed sequential tokens and automated chamber wait tracking.</p>
          </div>
          <a href="book_appointment.php" class="btn-action-gradient" style="text-decoration: none;">
            <svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Schedule Specialist Visit
          </a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Multi-Hospital Network Live Bed Matrix & Pre-Reservation Section -->
    <div class="bed-availability-panel" id="networkBedMatrixSection" style="margin-bottom: 2.25rem;">
      <div class="panel-header-flex" style="flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between;">
        <div>
          <div style="display: flex; align-items: center; gap: 0.65rem;">
            <h3 class="panel-heading" style="margin: 0;">Multi-Hospital Network Live Bed Matrix</h3>
            <span class="live-chip-sm" style="background: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); font-weight: 800;">
              6 FACILITIES SYNCED
            </span>
          </div>
          <p class="panel-subtext" style="margin-top: 0.25rem;">
            Real-time census, vacancy tracking, emergency department diversion status, and 45-minute self-service bed hold
          </p>
        </div>

        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <a href="reserve_bed.php" class="btn-action-gradient" style="padding: 0.52rem 1.1rem; font-size: 0.82rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 8px;">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
            Open Ward Bed Explorer &rarr;
          </a>
        </div>
      </div>

      <!-- 6-Hospital Grid -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 1.25rem; margin-top: 1.25rem;">
        <?php 
        $selectedHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
        foreach ($networkBedMatrix as $hosp): 
          $hId = (int)$hosp['hospital_id'];
          $isSelected = ($selectedHospitalId > 0 && $hId === $selectedHospitalId);
          $emStatus = strtolower(trim($hosp['emergency_status'] ?? ''));
          $isDiverted = str_contains($emStatus, 'divert') || $emStatus === 'critical capacity' || str_contains($emStatus, 'overwhelmed');
          $avail = (int)$hosp['available_beds'];
          $occ = (int)$hosp['occupied_beds'];
          $tot = (int)$hosp['total_beds'];
          $occPercent = $tot > 0 ? round(($occ / $tot) * 100) : 0;
        ?>
          <a href="reserve_bed.php?hospital_id=<?= $hId ?>#wardExplorerSection" class="group block bg-white rounded-2xl border <?= $isSelected ? 'border-2 border-teal-600 bg-teal-50/20 ring-2 ring-teal-500/20' : 'border-slate-200/80' ?> p-5 cursor-pointer transition-all duration-300 ease-out hover:-translate-y-1.5 hover:shadow-xl hover:border-teal-500 hosp-matrix-card-link <?= $isSelected ? 'selected' : '' ?>">
            <div>
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.75rem;">
                <div>
                  <h4 style="margin: 0; font-size: 1.02rem; font-weight: 800; color: var(--text-heading);">
                    <?= htmlspecialchars($hosp['hospital_name']) ?>
                  </h4>
                  <div style="font-size: 0.76rem; color: var(--text-muted); margin-top: 2px; display: flex; align-items: center; gap: 4px;">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= htmlspecialchars($hosp['location']) ?>
                  </div>
                </div>

                <span style="font-size: 0.68rem; font-weight: 800; padding: 2px 8px; border-radius: 999px; display: inline-flex; align-items: center; gap: 4px; <?= $isDiverted ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;' ?>">
                  <span style="width: 6px; height: 6px; border-radius: 50%; background: <?= $isDiverted ? '#ef4444' : '#10b981' ?>;"></span>
                  <?= htmlspecialchars($hosp['emergency_status']) ?>
                </span>
              </div>

              <?php if ($isDiverted): ?>
                <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.35); color: #dc2626; border-radius: 8px; padding: 6px 10px; font-size: 0.72rem; font-weight: 700; display: flex; align-items: center; gap: 6px; margin-bottom: 0.75rem;">
                  <svg style="width: 14px; height: 14px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                  <span>Warning: High Trauma Surge - Walk-in &amp; Critical Diversion in Effect</span>
                </div>
              <?php endif; ?>

              <!-- Census Stats -->
              <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem; background: #f8fafc; padding: 0.65rem; border-radius: 8px; margin: 0.75rem 0; text-align: center;">
                <div>
                  <div style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Total Beds</div>
                  <div style="font-size: 1.1rem; font-weight: 800; color: var(--text-heading);"><?= $tot ?></div>
                </div>
                <div>
                  <div style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Occupied</div>
                  <div style="font-size: 1.1rem; font-weight: 800; color: #dc2626;"><?= $occ ?></div>
                </div>
                <div>
                  <div style="font-size: 0.65rem; text-transform: uppercase; font-weight: 700; color: #0284c7;">Available</div>
                  <div style="font-size: 1.1rem; font-weight: 800; color: #059669;"><?= $avail ?></div>
                </div>
              </div>

              <!-- Occupancy Bar -->
              <div style="margin-bottom: 0.5rem;">
                <div style="display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 3px;">
                  <span>Census Occupancy</span>
                  <span><strong><?= $occPercent ?>%</strong></span>
                </div>
                <div style="height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                  <div style="height: 100%; width: <?= $occPercent ?>%; background: <?= $occPercent > 85 ? '#dc2626' : ($occPercent > 65 ? '#d97706' : '#059669') ?>; border-radius: 999px;"></div>
                </div>
              </div>
            </div>

            <!-- Synchronized Hover Bottom Action -->
            <div class="btn-card-action group-hover:text-teal-600">
              <span>View Ward Beds &amp; Hold</span>
              <span class="action-arrow group-hover:translate-x-1 transition-transform">&rarr;</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Recommended Specialists -->
    <div class="panel-header-flex" style="margin-bottom: 1.15rem;">
      <h3 class="panel-heading">Recommended Medical Specialists</h3>
      <a href="#" class="link-action-sub">
        View Directory 
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
      </a>
    </div>

    <div class="doctor-grid">
      <!-- Specialist 1 -->
      <div class="doctor-card-floating">
        <div class="doctor-avatar-wrap">
          <img src="https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=300&auto=format&fit=crop&q=80" alt="Dr. Satoru Gojo" class="doctor-avatar-img">
          <div class="doc-live-badge-dot"></div>
        </div>
        <div class="doc-info-block">
          <div class="doc-header-row">
            <h4 class="doc-name">Dr. Satoru Gojo</h4>
            <span class="doc-rating-badge">★ 4.9</span>
          </div>
          <div class="doc-specialty">Cardiology & Cardiac Surgery</div>
          <div class="doc-meta-footer">
            <div class="doc-tags-wrap">
              <span class="doc-available-chip"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px; fill: currentColor;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg> Available</span>
              <span class="doc-slot-chip">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                10:30 AM
              </span>
            </div>
            <div class="doc-fee-box">৳ 1,000</div>
          </div>
        </div>
      </div>

      <!-- Specialist 2 -->
      <div class="doctor-card-floating">
        <div class="doctor-avatar-wrap">
          <img src="https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=300&auto=format&fit=crop&q=80" alt="Dr. Mitsuha Miyamizu" class="doctor-avatar-img">
          <div class="doc-live-badge-dot"></div>
        </div>
        <div class="doc-info-block">
          <div class="doc-header-row">
            <h4 class="doc-name">Dr. Mitsuha Miyamizu</h4>
            <span class="doc-rating-badge">★ 4.8</span>
          </div>
          <div class="doc-specialty">Dermatology & Skin Aesthetics</div>
          <div class="doc-meta-footer">
            <div class="doc-tags-wrap">
              <span class="doc-available-chip"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px; fill: currentColor;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg> Available</span>
              <span class="doc-slot-chip">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                04:00 PM
              </span>
            </div>
            <div class="doc-fee-box">৳ 800</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Digital Diagnostics Hub -->
    <div class="diagnostics-panel">
      <div class="panel-header-flex">
        <div>
          <h3 class="panel-heading">Diagnostic & Pathology Hub</h3>
          <p class="panel-subtext">Electronic test results with visual reference ranges</p>
        </div>
        <a href="#" class="link-action-sub">
          All Lab Tests 
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
      </div>

      <div class="lab-records-grid">
        <!-- Test 1 -->
        <div class="lab-test-card">
          <div class="lab-header">
            <div>
              <div class="lab-title">Complete Blood Count (CBC)</div>
              <div class="lab-date">Sample Date: 12 Sep 2026</div>
            </div>
            <span class="lab-status-badge status-verified">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Verified
            </span>
          </div>
          <div class="lab-result-metric">
            <span class="metric-value">14.8</span>
            <span class="metric-unit">g/dL (Hemoglobin)</span>
          </div>
          <div class="range-scale-bar"><div class="bed-fill scale-fill-normal"></div></div>
          <div class="lab-action-row">
            <span style="font-size: 0.72rem; color: #15803d; font-weight: 700;">Within Normal Range (13.5 - 17.5)</span>
            <button class="btn-report-dl">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              Download
            </button>
          </div>
        </div>

        <!-- Test 2 -->
        <div class="lab-test-card">
          <div class="lab-header">
            <div>
              <div class="lab-title">Fasting Blood Glucose (HbA1c)</div>
              <div class="lab-date">Sample Date: 08 Sep 2026</div>
            </div>
            <span class="lab-status-badge status-verified">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
              Verified
            </span>
          </div>
          <div class="lab-result-metric">
            <span class="metric-value">95.4</span>
            <span class="metric-unit">mg/dL (Glucose)</span>
          </div>
          <div class="range-scale-bar"><div class="bed-fill scale-fill-normal"></div></div>
          <div class="lab-action-row">
            <span style="font-size: 0.72rem; color: #15803d; font-weight: 700;">Optimal Sugar Level (70 - 99)</span>
            <button class="btn-report-dl">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              Download
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Prescriptions & Automated Invoicing -->
    <div class="columns-dual">
      <div class="panel-block">
        <h3 class="panel-heading" style="margin-bottom: 1rem;">
          Active Digital Prescriptions
        </h3>

        <div class="script-item">
          <div>
            <strong style="font-size: 0.92rem; color: var(--text-heading);">Napa Extra (500mg)</strong>
            <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Paracetamol + Caffeine</p>
            <span class="tag-time tag-morning">1 Tab after meal (3x Daily)</span>
          </div>
          <button class="btn-download-rx">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Rx PDF
          </button>
        </div>

        <div class="script-item">
          <div>
            <strong style="font-size: 0.92rem; color: var(--text-heading);">Monas 10mg</strong>
            <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px;">Montelukast Sodium</p>
            <span class="tag-time tag-night">1 Tab at night (15 Days)</span>
          </div>
          <button class="btn-download-rx">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Rx PDF
          </button>
        </div>
      </div>

      <div class="panel-block">
        <h3 class="panel-heading" style="margin-bottom: 1rem;">
          Automated Invoicing
        </h3>
        <div class="payment-box">
          <span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Outstanding Balance</span>
          <div class="pay-amount">৳ 1,200.00</div>
          <button class="btn-action-gradient" style="width: 100%; justify-content: center; padding: 0.72rem;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Pay Auto Invoice
          </button>
        </div>
      </div>
    </div>

  </main>

  <!-- Right Profile Panel -->
  <aside class="profile-bar">
    <div class="patient-hero">
      <div class="patient-avatar-wrap">
        <div class="patient-avatar-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </div>
      </div>
      <h3 style="font-size: 1.05rem; font-weight: 800; color: var(--text-heading); margin-bottom: 3px;"><?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?></h3>
      <div style="margin-bottom: 4px;">
        <span style="font-family: monospace; font-size: 0.76rem; font-weight: 800; color: var(--brand-primary); background: rgba(14,165,233,0.12); padding: 2px 8px; border-radius: 6px; letter-spacing: 0.04em;">
          <?= htmlspecialchars($patientUid, ENT_QUOTES, 'UTF-8') ?>
        </span>
      </div>
      <p style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($patientEmail, ENT_QUOTES, 'UTF-8') ?></p>
      <?php if (!empty($dobFormatted)): ?>
        <p style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">DOB: <strong style="color: var(--text-heading);"><?= htmlspecialchars($dobFormatted, ENT_QUOTES, 'UTF-8') ?></strong></p>
      <?php endif; ?>
    </div>

    <!-- Health Vitals -->
    <div class="vitals-cards-2x2">
      <div class="vital-cell">
        <label>Blood Type</label>
        <strong style="color: var(--status-red);"><?= htmlspecialchars($bloodGroup, ENT_QUOTES, 'UTF-8') ?></strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-optimal"></div></div>
      </div>
      <div class="vital-cell">
        <label>Age / Sex</label>
        <strong><?= $age !== null ? htmlspecialchars((string)$age, ENT_QUOTES, 'UTF-8') . ' yrs' : 'N/A' ?> / <?= htmlspecialchars($gender, ENT_QUOTES, 'UTF-8') ?></strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-optimal"></div></div>
      </div>
      <div class="vital-cell">
        <label>Blood Pressure</label>
        <strong>120/80</strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-normal"></div></div>
      </div>
      <div class="vital-cell">
        <label>Heart Rate</label>
        <strong>74 bpm</strong>
        <div class="vital-progress"><div class="vital-progress-bar bar-normal"></div></div>
      </div>
    </div>

    <!-- Attending / Assigned Specialist -->
    <div style="margin: 1.15rem 0 1rem; padding: 0.85rem 1rem; background: var(--surface-card); border: 1px solid var(--border-subtle); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
      <span style="display: block; font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 5px;">Primary Attending Doctor</span>
      <div style="display: flex; align-items: center; gap: 8px;">
        <svg class="ui-ico ui-ico-sm" style="stroke: var(--brand-primary); width: 18px; height: 18px; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
        <strong style="font-size: 0.88rem; color: var(--text-heading);"><?= htmlspecialchars($assignedDoctor, ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </div>

    <!-- Emergency Dispatch Button -->
    <a href="tel:999" class="ambulance-card-btn">
      <svg class="ui-ico" style="stroke: white; width: 26px; height: 26px; margin: 0 auto 4px;" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
      <span style="display: block; font-size: 0.72rem; text-transform: uppercase; font-weight: 700; opacity: 0.9;">Emergency 24/7</span>
      <strong>Request Ambulance</strong>
    </a>
  </aside>

  <!-- Mobile Slide Navigation Script -->
  <script>
    const menuToggle = document.getElementById('menuToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function toggleMenu() {
      appSidebar.classList.toggle('open');
      sidebarBackdrop.classList.toggle('active');
    }

    if (menuToggle) {
      menuToggle.addEventListener('click', toggleMenu);
      sidebarBackdrop.addEventListener('click', toggleMenu);
    }

    // Client-Side History Guard: Kill BFCache and re-verify session on back-navigation
    window.addEventListener('pageshow', function(event) {
      if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
        window.location.reload();
      }
    });

    // Real-Time Patient OPD Queue Tracker Polling Engine
    (function initLiveQueueTracker() {
      const widget = document.getElementById('opdQueueWidget');
      if (!widget) return;

      const currentServingEl = document.getElementById('currentServingDisplay');
      const queuePillContainer = document.getElementById('queuePillContainer');

      async function pollQueueStatus() {
        try {
          const res = await fetch('../backend/api/opd_queue.php?action=patient_live_status', {
            headers: { 'Accept': 'application/json' }
          });
          if (!res.ok) return;
          const json = await res.json();
          if (json.status === 'success' && json.data && json.data.is_today) {
            const d = json.data;
            if (currentServingEl) {
              currentServingEl.textContent = (d.current_serving > 0) ? ('#' + d.current_serving) : 'Chamber Idle';
            }

            if (queuePillContainer) {
              if (d.is_my_turn) {
                queuePillContainer.innerHTML = `
                  <div class="opd-live-pill pill-turn-now">
                    <div class="pulse-dot-green"></div>
                    <span>YOUR TURN &ndash; ENTER CHAMBER</span>
                  </div>
                `;
              } else {
                queuePillContainer.innerHTML = `
                  <div class="opd-live-pill pill-waiting">
                    <div class="pulse-dot-blue"></div>
                    <span id="peopleAheadText">${d.people_ahead} Patients Ahead</span>
                    <span class="opd-wait-badge" id="waitBadgeText">(~${d.estimated_wait_mins} mins wait)</span>
                  </div>
                `;
              }
            }
          }
        } catch (e) {
          // Graceful fallback: retry on next cycle
        }
      }

      // Poll every 5 seconds for live queue synchronization
      setInterval(pollQueueStatus, 5000);
    })();

    // Interactive Bed Pre-Reservation (45-Minute Hold) Countdown Timer
    (function initHoldCountdown() {
      const holdEl = document.getElementById('patientDashboardHoldTimer');
      if (!holdEl) return;

      let remainingSecs = parseInt(holdEl.getAttribute('data-seconds'), 10) || 0;
      const timer = setInterval(() => {
        if (remainingSecs <= 0) {
          clearInterval(timer);
          holdEl.textContent = '00:00 (Expired)';
          holdEl.style.color = '#ef4444';
          setTimeout(() => window.location.reload(), 1800);
          return;
        }

        remainingSecs--;
        const mins = Math.floor(remainingSecs / 60);
        const secs = remainingSecs % 60;
        holdEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;

        if (remainingSecs < 300) {
          holdEl.style.color = '#ef4444';
        }
      }, 1000);
    })();
  </script>

</body>
</html>
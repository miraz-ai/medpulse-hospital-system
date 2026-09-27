<?php
/**
 * MedPulse Patient Portal — Multi-Hospital Bed Explorer & Pre-Reservation Engine
 * 45-Minute Hold Window, Real-Time Census & Emergency ER Status
 */

require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/BedReservationController.php';

$patientId = (int)$_SESSION['user_id'];

// ── Handle Bed Reservation / Cancellation POST Requests ──────────────────────
$actionMessage = null;
$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'hold_bed') {
        $bedId = (int)($_POST['bed_id'] ?? 0);
        $hospitalId = (int)($_POST['hospital_id'] ?? 0);
        
        $res = BedReservationController::reserveBed($pdo, $patientId, $bedId, $hospitalId);
        if ($res['success']) {
            header("Location: reserve_bed.php?hospital_id={$hospitalId}&reserved=1");
            exit();
        } else {
            $actionError = $res['message'];
        }
    } elseif ($_POST['action'] === 'cancel_hold') {
        $reservationId = (int)($_POST['reservation_id'] ?? 0);
        $hospitalId = (int)($_POST['hospital_id'] ?? 1);

        $res = BedReservationController::cancelReservation($pdo, $patientId, $reservationId);
        if ($res['success']) {
            header("Location: reserve_bed.php?hospital_id={$hospitalId}&cancelled=1");
            exit();
        } else {
            $actionError = $res['message'];
        }
    }
}

// ── Fetch Patient Profile ────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender,
               p.patient_uid, p.blood_group, p.dob
        FROM users u
        LEFT JOIN patients p ON u.user_id = p.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error loading patient profile.");
}

$patientName = $patient['full_name'] ?? 'Valued Patient';
$patientUid = $patient['patient_uid'] ?? ('MP-' . date('Y') . '-' . str_pad((string)$patientId, 5, '0', STR_PAD_LEFT));

require_once __DIR__ . '/../includes/doctor_helpers.php';

// ── Check Active Hold for This Patient ──────────────────────────────────────
$activeHold = BedReservationController::getPatientActiveReservation($pdo, $patientId);

// ── Check Active Inpatient Admission for This Patient ───────────────────────
$activeAdmission = null;
try {
    $admStmt = $pdo->prepare("
        SELECT a.admission_id, a.admission_number, a.bed_id, a.hospital_id,
               a.admitted_at, a.primary_diagnosis, a.admission_reason, a.triage_acuity, a.daily_rate,
               b.bed_number, b.ward_type, b.floor_number,
               h.name AS hospital_name, h.location AS hospital_location,
               doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty
        FROM admissions a
        JOIN hospital_beds b ON a.bed_id = b.bed_id
        JOIN hospitals h ON a.hospital_id = h.hospital_id
        LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
        LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
        WHERE a.patient_id = :pid AND a.status = 'Admitted'
        ORDER BY a.admitted_at DESC
        LIMIT 1
    ");
    $admStmt->execute([':pid' => $patientId]);
    $rawAdm = $admStmt->fetch(PDO::FETCH_ASSOC);
    if ($rawAdm) {
        $cleanDoc = cleanDoctorBaseName($rawAdm['doctor_name'] ?? '');
        $docTitle = ($cleanDoc !== '' && $cleanDoc !== 'Physician') ? ('Dr. ' . $cleanDoc) : 'Assigned Ward Specialist';
        if (!empty($rawAdm['doctor_specialty'])) {
            $docTitle .= ' (' . $rawAdm['doctor_specialty'] . ')';
        }
        $formattedTime = date('M j, Y \a\t g:i A', strtotime($rawAdm['admitted_at']));
        $activeAdmission = [
            'admission_id'         => (int)$rawAdm['admission_id'],
            'admission_number'     => $rawAdm['admission_number'],
            'bed_id'               => (int)$rawAdm['bed_id'],
            'bed_number'           => $rawAdm['bed_number'],
            'ward_type'            => $rawAdm['ward_type'],
            'floor_number'         => (int)$rawAdm['floor_number'],
            'facility_name'        => $rawAdm['hospital_name'],
            'facility_location'    => $rawAdm['hospital_location'],
            'attending_consultant' => $docTitle,
            'primary_diagnosis'    => $rawAdm['primary_diagnosis'] ?: ($rawAdm['admission_reason'] ?: 'Inpatient Clinical Care'),
            'triage_acuity'        => $rawAdm['triage_acuity'] ?: 'Routine',
            'daily_rate'           => (float)$rawAdm['daily_rate'],
            'admitted_at'          => $rawAdm['admitted_at'],
            'formatted_time'       => $formattedTime,
            'status'               => 'Admitted'
        ];
    }
} catch (Throwable $e) {}

// ── Fetch Network Matrix for All 6 Hospitals ─────────────────────────────────
$networkMatrix = BedReservationController::getNetworkBedMatrix($pdo);

// Selected Hospital for Ward Explorer (defaults to URL param or first hospital)
$selectedHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : ($activeHold['hospital_id'] ?? ($activeAdmission['hospital_id'] ?? 1));
if ($selectedHospitalId <= 0) $selectedHospitalId = 1;

// Selected Ward Type filter
$selectedWard = $_GET['ward_type'] ?? 'all';

// Find metadata for selected hospital
$selectedHospital = null;
foreach ($networkMatrix as $h) {
    if ((int)$h['hospital_id'] === $selectedHospitalId) {
        $selectedHospital = $h;
        break;
    }
}
if (!$selectedHospital && !empty($networkMatrix)) {
    $selectedHospital = $networkMatrix[0];
    $selectedHospitalId = (int)$selectedHospital['hospital_id'];
}

// Helper: Ward Type Category Classifier
if (!function_exists('categorizeWardType')) {
    function categorizeWardType(string $wardType): string {
        $wt = strtolower($wardType);
        if (str_contains($wt, 'icu') || str_contains($wt, 'ccu') || str_contains($wt, 'hdu') || str_contains($wt, 'nicu') || str_contains($wt, 'intensive') || str_contains($wt, 'critical')) {
            return 'ccu_icu';
        }
        if (str_contains($wt, 'cabin') || str_contains($wt, 'deluxe') || str_contains($wt, 'vip') || str_contains($wt, 'suite')) {
            return 'cabin_deluxe';
        }
        return 'general';
    }
}

// Fetch ward types and all available beds for selected hospital
$wardSummary = BedReservationController::getHospitalWardSummary($pdo, $selectedHospitalId);
$availableBeds = BedReservationController::getHospitalAvailableBeds($pdo, $selectedHospitalId, 'all', 400);

// Compute vacancy counts per category
$catCounts = [
    'all'          => count($availableBeds),
    'ccu_icu'      => 0,
    'general'      => 0,
    'cabin_deluxe' => 0,
];

foreach ($availableBeds as $bed) {
    $cat = categorizeWardType($bed['ward_type'] ?? '');
    if (isset($catCounts[$cat])) {
        $catCounts[$cat]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Multi-Hospital Bed Matrix &amp; 45-Min Pre-Reservation</title>

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* ── Multi-Hospital Bed Matrix Styles ────────────────────────────────── */
    .matrix-hero {
      background: linear-gradient(135deg, #091e3a 0%, #0d3b5e 50%, #0d9488 100%);
      color: #ffffff;
      padding: 2rem 2.25rem;
      border-radius: 18px;
      margin-bottom: 2rem;
      box-shadow: 0 12px 30px -8px rgba(9, 30, 58, 0.35);
      position: relative;
      overflow: hidden;
    }
    .matrix-hero::after {
      content: '';
      position: absolute;
      right: -40px;
      bottom: -40px;
      width: 240px;
      height: 240px;
      background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
      border-radius: 50%;
      pointer-events: none;
    }
    .matrix-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 1.25rem;
      margin-bottom: 2.25rem;
    }
    .hosp-matrix-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 1.35rem 1.4rem;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      cursor: pointer;
      text-decoration: none;
      color: inherit;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
    }
    .hosp-matrix-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 16px 32px rgba(0, 0, 0, 0.08), 0 4px 8px rgba(0, 0, 0, 0.04);
      border-color: #0d9488 !important;
    }
    .hosp-matrix-card.selected {
      border: 2px solid #0d9488 !important;
      box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.2), 0 8px 24px rgba(13, 148, 136, 0.16);
      background: rgba(240, 253, 250, 0.4) !important;
    }
    .hosp-matrix-card .card-action-btn {
      text-align: center;
      padding: 0.55rem 1rem;
      font-size: 0.82rem;
      font-weight: 700;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #cbd5e1;
      transition: all 0.25s ease;
      margin-top: 0.5rem;
    }
    .hosp-matrix-card:hover .card-action-btn {
      background: #0d9488;
      color: #ffffff;
      border-color: #0d9488;
    }
    .hosp-matrix-card:hover .card-action-btn .action-arrow {
      transform: translateX(4px);
    }
    .hosp-matrix-card.selected .card-action-btn {
      background: #0d9488;
      color: #ffffff;
      border-color: #0d9488;
    }
    .action-arrow {
      display: inline-block;
      transition: transform 0.2s ease;
    }
    #wardExplorerSection {
      scroll-margin-top: 5rem;
    }
    .status-badge-er {
      font-size: 0.72rem;
      font-weight: 800;
      padding: 3px 10px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      letter-spacing: 0.5px;
    }
    .status-badge-er.operational {
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
    }
    .status-badge-er.critical {
      background: #fef2f2;
      color: #dc2626;
      border: 1px solid #fecaca;
    }
    .pulse-dot-sm {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      animation: pulse 1.8s infinite;
    }
    .operational .pulse-dot-sm { background: #10b981; }
    .critical .pulse-dot-sm { background: #ef4444; }

    /* Active Hold Countdown Banner */
    .active-hold-banner {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      border-radius: 16px;
      padding: 1.5rem 2rem;
      margin-bottom: 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1.25rem;
      box-shadow: 0 10px 25px rgba(2, 132, 199, 0.35);
      border: 2px solid #7dd3fc;
    }
    .hold-countdown-box {
      background: rgba(15, 23, 42, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 12px;
      padding: 0.65rem 1.4rem;
      text-align: center;
    }
    .hold-time-val {
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: 1px;
      font-variant-numeric: tabular-nums;
      color: #fef08a;
    }

    /* Bed Card in Ward List */
    .bed-item-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.2s ease;
    }
    .bed-item-card:hover {
      border-color: #0284c7;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
    }

    /* ── Dynamic Category Filter Pills ───────────────────────────────────── */
    .ward-filter-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.45rem 1rem;
      font-size: 0.82rem;
      font-weight: 600;
      border-radius: 9999px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #334155;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
      user-select: none;
      outline: none;
    }
    .ward-filter-pill:hover:not(.active) {
      background: #e2e8f0;
      color: #0f172a;
      border-color: #94a3b8;
    }
    .ward-filter-pill.active {
      background: #0d9488 !important; /* MedPulse teal bg-teal-600 */
      color: #ffffff !important;
      border-color: #0d9488 !important;
      box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
    }
    .ward-filter-pill .pill-counter {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 0.1rem 0.5rem;
      border-radius: 9999px;
      background: rgba(148, 163, 184, 0.22);
      color: #475569;
      transition: all 0.2s ease;
    }
    .ward-filter-pill.active .pill-counter {
      background: rgba(255, 255, 255, 0.25);
      color: #ffffff;
    }
  </style>
</head>
<body>

  <!-- Shared Canonical Patient Sidebar Partial -->
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <main class="viewport-full">

    <!-- Top Real-Time Alert Container: Active Admission or Bed Hold -->
    <div id="patientTopAlertContainer">
      <?php if ($activeAdmission): ?>
        <div class="active-hold-banner" id="activeInpatientBanner" style="background: linear-gradient(135deg, #064e3b 0%, #0d9488 60%, #0284c7 100%); margin-bottom: 2rem;">
          <div style="display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;">
            <div style="width: 52px; height: 52px; border-radius: 12px; background: rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
              <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ffffff" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span class="pulse-dot-sm" style="background: #34d399;"></span>
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; color: #a7f3d0;">
                  VERIFIED INPATIENT ADMISSION &bull; DOSSIER #<?= htmlspecialchars((string)($activeAdmission['admission_number'] ?: $activeAdmission['admission_id'])) ?>
                </span>
              </div>
              <h2 style="margin: 0.2rem 0; font-size: 1.35rem; font-weight: 800; color: #ffffff;">
                Admitted: Bed #<?= htmlspecialchars($activeAdmission['bed_number']) ?> &bull; <?= htmlspecialchars($activeAdmission['ward_type']) ?> (Floor <?= (int)$activeAdmission['floor_number'] ?>)
              </h2>
              <div style="font-size: 0.85rem; color: #e0f2fe;">
                Facility: <strong><?= htmlspecialchars($activeAdmission['facility_name']) ?></strong> &bull; 
                Consultant: <strong><?= htmlspecialchars($activeAdmission['attending_consultant']) ?></strong> &bull; 
                Intake: <?= htmlspecialchars($activeAdmission['primary_diagnosis']) ?>
              </div>
            </div>
          </div>

          <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); border-radius: 10px; padding: 0.6rem 1rem; text-align: right;">
              <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #a7f3d0;">Status</div>
              <div style="font-size: 0.95rem; font-weight: 800; color: #ffffff;">Under Active Care</div>
            </div>
            <a href="dashboard.php" class="btn-teal-action" style="background: #ffffff; color: #0f766e; font-weight: 800; padding: 0.65rem 1.15rem; border-radius: 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
              <span>Inpatient Dossier</span> &rarr;
            </a>
          </div>
        </div>
      <?php elseif ($activeHold): ?>
        <div class="active-hold-banner" id="activeHoldDeck">
          <div style="display: flex; align-items: center; gap: 1.25rem;">
            <div style="width: 52px; height: 52px; border-radius: 12px; background: rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center;">
              <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ffffff" stroke-width="2"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
            </div>
            <div>
              <div style="display: flex; align-items: center; gap: 8px;">
                <span class="pulse-dot-sm" style="background: #fef08a;"></span>
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; color: #bae6fd;">
                  TEMPORARY PRE-RESERVATION ACTIVE
                </span>
              </div>
              <h2 style="margin: 0.2rem 0; font-size: 1.35rem; font-weight: 800; color: #ffffff;">
                Hold Active: Bed <?= htmlspecialchars($activeHold['bed_number']) ?> at <?= htmlspecialchars($activeHold['hospital_name']) ?>
              </h2>
              <div style="font-size: 0.85rem; color: #e0f2fe;">
                Ward: <strong><?= htmlspecialchars($activeHold['ward_type']) ?></strong> (Floor <?= (int)$activeHold['floor_number'] ?>) &bull; 
                Daily Rate: <strong>&#2547;<?= number_format((float)$activeHold['daily_rate'], 2) ?></strong> &bull; 
                Location: <?= htmlspecialchars($activeHold['hospital_location']) ?>
              </div>
            </div>
          </div>

          <div style="display: flex; align-items: center; gap: 1.25rem;">
            <div class="hold-countdown-box">
              <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #bae6fd; letter-spacing: 0.5px;">Hold Expires In</div>
              <div class="hold-time-val" id="holdCountdown" data-seconds="<?= (int)$activeHold['seconds_remaining'] ?>">
                <?= $activeHold['countdown_formatted'] ?>
              </div>
            </div>

            <form method="POST" onsubmit="event.preventDefault(); const form = this; if (window.MedPulseDialog && window.MedPulseDialog.confirm) { MedPulseDialog.confirm({ title: 'Cancel Bed Reservation', message: 'Cancel and release this bed back to network vacancy?', type: 'danger', confirmText: 'Release Bed', cancelText: 'Keep Bed Hold' }).then(c => { if(c) { form.setAttribute('data-mp-confirmed', 'true'); form.submit(); } }); } else { form.submit(); }" style="margin: 0;">
              <input type="hidden" name="action" value="cancel_hold">
              <input type="hidden" name="reservation_id" value="<?= (int)$activeHold['reservation_id'] ?>">
              <input type="hidden" name="hospital_id" value="<?= (int)$activeHold['hospital_id'] ?>">
              <button type="submit" class="btn-teal-action" style="background: rgba(239, 68, 68, 0.2); color: #ffffff; border: 1px solid rgba(239, 68, 68, 0.4); padding: 0.65rem 1.15rem; font-weight: 700; border-radius: 10px;">
                Cancel Hold
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Feedback Alerts -->
    <?php if (isset($_GET['reserved'])): ?>
      <div class="alert alert-success" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.65rem;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span><strong>Bed Pre-Reserved!</strong> Your bed is placed on temporary 45-minute hold. Please report to the hospital reception before expiration.</span>
      </div>
    <?php elseif (isset($_GET['cancelled'])): ?>
      <div class="alert alert-info" style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.65rem;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#2563eb" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>Bed hold released. You may browse and hold another bed across the network.</span>
      </div>
    <?php elseif ($actionError): ?>
      <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.65rem;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span><?= htmlspecialchars($actionError) ?></span>
      </div>
    <?php endif; ?>

    <!-- Header Hero -->
    <div class="matrix-hero">
      <div style="max-width: 650px;">
        <div style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: rgba(255,255,255,0.15); font-size: 0.75rem; font-weight: 800; letter-spacing: 0.5px; margin-bottom: 0.65rem;">
          <span class="pulse-dot-sm" style="background: #38bdf8;"></span> NETWORK LIVE CENSUS
        </div>
        <h1 style="font-size: 2rem; font-weight: 800; margin: 0 0 0.4rem 0;">
          Multi-Hospital Live Bed Matrix
        </h1>
        <p style="font-size: 0.95rem; color: #e2e8f0; margin: 0; line-height: 1.5;">
          Real-time bed availability across all 6 MedPulse network partner hospitals in Dhaka. Self-service temporary 45-minute bed pre-reservation with automated hold release.
        </p>
      </div>
    </div>

    <!-- 6-Hospital Live Matrix Cards Grid -->
    <div class="panel-header-flex" style="margin-bottom: 1rem;">
      <h3 class="panel-heading">Partner Facilities &amp; Emergency Readiness</h3>
      <span style="font-size: 0.8rem; color: var(--text-muted);">Synced Live with Central Hospital Dispatch</span>
    </div>

    <div class="matrix-grid">
      <?php foreach ($networkMatrix as $hosp): 
        $hId = (int)$hosp['hospital_id'];
        $isSelected = ($hId === $selectedHospitalId);
        $rawEmerg = strtolower(trim($hosp['emergency_status'] ?? ''));
        $isCritical = str_contains($rawEmerg, 'critical') || str_contains($rawEmerg, 'divert') || str_contains($rawEmerg, 'overwhelm');
        $avail = (int)$hosp['available_beds'];
        $occ = (int)$hosp['occupied_beds'];
        $tot = (int)$hosp['total_beds'];
        $occPercent = $tot > 0 ? round(($occ / $tot) * 100) : 0;
      ?>
        <a href="reserve_bed.php?hospital_id=<?= $hId ?>#wardExplorerSection" class="group block bg-white rounded-2xl border <?= $isSelected ? 'border-2 border-teal-600 bg-teal-50/20 ring-2 ring-teal-500/20' : 'border-slate-200/80' ?> p-5 cursor-pointer transition-all duration-300 ease-out hover:-translate-y-1.5 hover:shadow-xl hover:border-teal-500 hosp-matrix-card <?= $isSelected ? 'selected' : '' ?>" style="text-decoration: none; color: inherit;" data-hosp-card="<?= $hId ?>">
          <div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; gap: 0.5rem;">
              <div>
                <h4 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text-heading);">
                  <?= htmlspecialchars($hosp['hospital_name']) ?>
                </h4>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.2rem; display: flex; align-items: center; gap: 4px;">
                  <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  <?= htmlspecialchars($hosp['location']) ?>
                </div>
              </div>

              <span class="status-badge-er <?= $isCritical ? 'critical' : 'operational' ?>" data-hosp-emerg="<?= $hId ?>">
                <span class="pulse-dot-sm"></span>
                <span class="emerg-text-val"><?= htmlspecialchars($hosp['emergency_status']) ?></span>
              </span>
            </div>

            <div class="hosp-critical-alert" data-hosp-alert="<?= $hId ?>" style="<?= $isCritical ? '' : 'display: none;' ?> background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.35); color: #dc2626; border-radius: 8px; padding: 6px 10px; font-size: 0.72rem; font-weight: 700; display: <?= $isCritical ? 'flex' : 'none' ?>; align-items: center; gap: 6px; margin-bottom: 0.75rem;">
              <svg style="width: 14px; height: 14px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
              <span>Warning: High Trauma Surge - Walk-in &amp; Critical Diversion in Effect</span>
            </div>

            <!-- Census Counters -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem; background: #f8fafc; padding: 0.75rem; border-radius: 10px; margin: 0.85rem 0; text-align: center;">
              <div>
                <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Total</div>
                <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-heading);" data-hosp-total="<?= $hId ?>"><?= $tot ?></div>
              </div>
              <div>
                <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Occupied</div>
                <div style="font-size: 1.15rem; font-weight: 800; color: #dc2626;" data-hosp-occ="<?= $hId ?>"><?= $occ ?></div>
              </div>
              <div>
                <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #0284c7;">Available</div>
                <div style="font-size: 1.15rem; font-weight: 800; color: #059669;" data-hosp-avail="<?= $hId ?>"><?= $avail ?></div>
              </div>
            </div>

            <!-- Progress Bar -->
            <div style="margin-bottom: 0.5rem;">
              <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--text-muted); margin-bottom: 3px;">
                <span>Occupancy Rate</span>
                <span><strong data-hosp-rate="<?= $hId ?>"><?= $occPercent ?>%</strong></span>
              </div>
              <div style="height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                <div data-hosp-bar="<?= $hId ?>" style="height: 100%; width: <?= $occPercent ?>%; background: <?= $occPercent > 85 ? '#dc2626' : ($occPercent > 65 ? '#d97706' : '#059669') ?>; border-radius: 999px; transition: width 0.4s ease, background 0.4s ease;"></div>
              </div>
            </div>
          </div>

          <div class="card-action-btn group-hover:text-teal-600">
            <span><?= $isSelected ? '✓ Viewing Wards &amp; Beds' : 'View Ward Beds &amp; Hold' ?></span>
            <span class="action-arrow group-hover:translate-x-1 transition-transform">&rarr;</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Ward Bed Explorer for Selected Hospital -->
    <section class="admin-stack-card" id="wardExplorerSection" style="scroll-margin-top: 5rem;">
      <div class="admin-stack-header" style="flex-wrap: wrap; gap: 1rem; justify-content: space-between; align-items: center;">
        <div>
          <div style="display: flex; align-items: center; gap: 0.65rem;">
            <h3>
              <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
              <?= htmlspecialchars($selectedHospital['hospital_name']) ?> &bull; Available Ward Beds
            </h3>
            <span class="live-chip-sm" id="selectedHospitalVacantChip" style="background: #e0f2fe; color: #0284c7; border-color: #bae6fd;">
              <?= count($availableBeds) ?> BEDS VACANT
            </span>
          </div>
          <p>Select any available bed to secure a 45-minute reservation hold. Only 1 active hold allowed per patient.</p>
        </div>

        <!-- Dynamic Category Filter Pills -->
        <div class="ward-filter-tabs" id="wardFilterTabs" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
          <button type="button" class="ward-filter-pill active" data-filter="all">
            <span>All Beds</span>
            <span class="pill-counter" data-pill-filter="all"><?= (int)$catCounts['all'] ?></span>
          </button>
          <button type="button" class="ward-filter-pill" data-filter="ccu_icu">
            <span>CCU / ICU</span>
            <span class="pill-counter" data-pill-filter="ccu_icu"><?= (int)$catCounts['ccu_icu'] ?></span>
          </button>
          <button type="button" class="ward-filter-pill" data-filter="general">
            <span>General Ward</span>
            <span class="pill-counter" data-pill-filter="general"><?= (int)$catCounts['general'] ?></span>
          </button>
          <button type="button" class="ward-filter-pill" data-filter="cabin_deluxe">
            <span>Cabin / Deluxe</span>
            <span class="pill-counter" data-pill-filter="cabin_deluxe"><?= (int)$catCounts['cabin_deluxe'] ?></span>
          </button>
        </div>
      </div>

      <!-- Beds Grid -->
      <div id="wardBedsGridContainer" style="padding: 1.5rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
        <?php if (empty($availableBeds)): ?>
          <div id="noBedsAvailableInitialMsg" style="grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-heading);">No Vacant Beds In This Category</div>
            <p style="font-size: 0.85rem; margin-top: 0.35rem;">Please select another ward type or explore other partner facilities above.</p>
          </div>
        <?php else: ?>
          <?php foreach ($availableBeds as $bed): ?>
            <?php $bedCat = categorizeWardType($bed['ward_type'] ?? ''); ?>
            <div class="bed-item-card" data-bed-id="<?= (int)$bed['id'] ?>" data-ward-type="<?= htmlspecialchars($bed['ward_type']) ?>" data-category="<?= $bedCat ?>">
              <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                  <span style="font-size: 1.15rem; font-weight: 800; color: var(--brand-primary);" class="bed-num-label">
                    <?= htmlspecialchars($bed['bed_number']) ?>
                  </span>
                  <span class="live-chip-sm bed-status-chip" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-size: 0.72rem;">
                    VACANT
                  </span>
                </div>

                <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-heading);" class="bed-ward-label">
                  <?= htmlspecialchars($bed['ward_type']) ?>
                </div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;" class="bed-floor-label">
                  Floor <?= (int)$bed['floor_number'] ?> &bull; <?= htmlspecialchars($bed['hospital_location']) ?>
                </div>

                <div style="margin: 0.85rem 0; font-size: 0.95rem; font-weight: 800; color: var(--text-heading);" class="bed-rate-label">
                  &#2547;<?= number_format((float)$bed['daily_rate'], 2) ?> <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">/ day</span>
                </div>
              </div>

              <div class="bed-action-area" data-bed-action-id="<?= (int)$bed['id'] ?>">
                <?php if ($activeAdmission): ?>
                  <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.5; cursor: not-allowed; padding: 0.55rem; font-size: 0.8rem;" title="You are currently an admitted inpatient.">
                    Currently Admitted
                  </button>
                <?php elseif ($activeHold): ?>
                  <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.5; cursor: not-allowed; padding: 0.55rem; font-size: 0.8rem;" title="You already have an active hold across the network.">
                    Hold Active Elsewhere
                  </button>
                <?php else: ?>
                  <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="hold_bed">
                    <input type="hidden" name="bed_id" value="<?= (int)$bed['id'] ?>">
                    <input type="hidden" name="hospital_id" value="<?= $selectedHospitalId ?>">
                    <button type="submit" class="btn-action-gradient" style="width: 100%; border: none; cursor: pointer; padding: 0.55rem; font-size: 0.82rem; font-weight: 700; border-radius: 8px;">
                      Hold Bed (45-Min Hold) &rarr;
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div id="noBedsFilteredMsg" style="display: none; grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin: 0 auto 0.5rem;"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-heading);">No Vacant Beds In This Category</div>
            <p style="font-size: 0.85rem; margin-top: 0.35rem;">There are currently no vacant beds matching this filter in <?= htmlspecialchars($selectedHospital['hospital_name']) ?>.</p>
          </div>
        <?php endif; ?>
      </div>
    </section>

  </main>

  <!-- Interactive Real-Time Sync, Category Filter, and Hold Countdown Script -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const selectedHospitalId = <?= (int)$selectedHospitalId ?>;
      let currentActiveFilter = 'all';

      // ── Smooth Auto-Scroll to Ward Explorer Section ────────────────────────
      const section = document.getElementById('wardExplorerSection');
      const params = new URLSearchParams(window.location.search);
      if (section && (params.has('hospital_id') || window.location.hash === '#wardExplorerSection')) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      // ── HTML Escaping Utility ──────────────────────────────────────────────
      function escapeHtml(str) {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      // ── Ward Category Classifier ───────────────────────────────────────────
      function categorizeWardType(wardType) {
        const wt = (wardType || '').toLowerCase();
        if (wt.includes('icu') || wt.includes('ccu') || wt.includes('hdu') || wt.includes('nicu') || wt.includes('intensive') || wt.includes('critical')) {
          return 'ccu_icu';
        }
        if (wt.includes('cabin') || wt.includes('deluxe') || wt.includes('vip') || wt.includes('suite')) {
          return 'cabin_deluxe';
        }
        return 'general';
      }

      // ── Client-side Instant Ward Bed Category Filter Tabs ──────────────────
      const filterPills = document.querySelectorAll('.ward-filter-pill');

      function filterBedsByCategory(category) {
        currentActiveFilter = category;
        const bedCards = document.querySelectorAll('.bed-item-card');
        const noFilterMsg = document.getElementById('noBedsFilteredMsg');
        const initialEmptyMsg = document.getElementById('noBedsAvailableInitialMsg');
        let visibleCount = 0;

        bedCards.forEach(card => {
          const cardCat = card.getAttribute('data-category');
          const wardType = (card.getAttribute('data-ward-type') || '').toLowerCase();

          let match = false;
          if (category === 'all') {
            match = true;
          } else if (category === 'ccu_icu') {
            match = (cardCat === 'ccu_icu') ||
                    wardType.includes('icu') || wardType.includes('ccu') ||
                    wardType.includes('hdu') || wardType.includes('nicu') ||
                    wardType.includes('intensive') || wardType.includes('critical');
          } else if (category === 'general') {
            match = (cardCat === 'general');
          } else if (category === 'cabin_deluxe') {
            match = (cardCat === 'cabin_deluxe') ||
                    wardType.includes('cabin') || wardType.includes('deluxe') ||
                    wardType.includes('vip') || wardType.includes('suite');
          }

          if (match) {
            card.style.display = '';
            visibleCount++;
          } else {
            card.style.display = 'none';
          }
        });

        if (initialEmptyMsg) {
          initialEmptyMsg.style.display = bedCards.length === 0 ? 'block' : 'none';
        }
        if (noFilterMsg) {
          noFilterMsg.style.display = (bedCards.length > 0 && visibleCount === 0) ? 'block' : 'none';
        }
      }

      filterPills.forEach(pill => {
        pill.addEventListener('click', (e) => {
          e.preventDefault();
          filterPills.forEach(p => p.classList.remove('active'));
          pill.classList.add('active');
          const filter = pill.getAttribute('data-filter') || 'all';
          filterBedsByCategory(filter);
        });
      });

      // ── Active Hold Countdown Timer Engine ────────────────────────────────
      let holdTimerInterval = null;

      function startHoldCountdown(totalSeconds) {
        if (holdTimerInterval) clearInterval(holdTimerInterval);
        const countdownEl = document.getElementById('holdCountdown');
        if (!countdownEl) return;

        let remainingSecs = parseInt(totalSeconds, 10);
        if (isNaN(remainingSecs) || remainingSecs < 0) remainingSecs = 0;

        function renderTimerTick() {
          if (remainingSecs <= 0) {
            if (holdTimerInterval) clearInterval(holdTimerInterval);
            countdownEl.textContent = '00:00 (Expired)';
            countdownEl.style.color = '#ef4444';
            pollReserveSync(); // Immediate sync on expiration
            return;
          }

          const mins = Math.floor(remainingSecs / 60);
          const secs = remainingSecs % 60;
          countdownEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;

          if (remainingSecs < 300) {
            countdownEl.style.color = '#ef4444';
          } else {
            countdownEl.style.color = '#ffffff';
          }

          remainingSecs--;
        }

        renderTimerTick();
        holdTimerInterval = setInterval(renderTimerTick, 1000);
      }

      function stopHoldCountdown() {
        if (holdTimerInterval) {
          clearInterval(holdTimerInterval);
          holdTimerInterval = null;
        }
      }

      // Initial timer start if hold exists on page load
      const initCountdownEl = document.getElementById('holdCountdown');
      if (initCountdownEl) {
        const initSecs = parseInt(initCountdownEl.getAttribute('data-seconds'), 10) || 0;
        startHoldCountdown(initSecs);
      }

      // ── Real-Time Top Alert Banner Synchronizer ───────────────────────────
      let currentPatientState = '<?= $activeAdmission ? 'admitted' : ($activeHold ? 'held' : 'none') ?>';

      function syncTopAlertBanner(state, hold, adm) {
        const container = document.getElementById('patientTopAlertContainer');
        if (!container) return;

        if (state === 'admitted' && adm) {
          stopHoldCountdown();
          if (currentPatientState !== 'admitted') {
            currentPatientState = 'admitted';
            container.innerHTML = `
              <div class="active-hold-banner" id="activeInpatientBanner" style="background: linear-gradient(135deg, #064e3b 0%, #0d9488 60%, #0284c7 100%); margin-bottom: 2rem;">
                <div style="display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap;">
                  <div style="width: 52px; height: 52px; border-radius: 12px; background: rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ffffff" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                  </div>
                  <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <span class="pulse-dot-sm" style="background: #34d399;"></span>
                      <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; color: #a7f3d0;">
                        VERIFIED INPATIENT ADMISSION &bull; DOSSIER #${escapeHtml(String(adm.admission_number || adm.admission_id))}
                      </span>
                    </div>
                    <h2 style="margin: 0.2rem 0; font-size: 1.35rem; font-weight: 800; color: #ffffff;">
                      Admitted: Bed #${escapeHtml(adm.bed_number)} &bull; ${escapeHtml(adm.ward_type)} (Floor ${adm.floor_number})
                    </h2>
                    <div style="font-size: 0.85rem; color: #e0f2fe;">
                      Facility: <strong>${escapeHtml(adm.facility_name)}</strong> &bull; 
                      Consultant: <strong>${escapeHtml(adm.attending_consultant)}</strong> &bull; 
                      Intake: ${escapeHtml(adm.primary_diagnosis)}
                    </div>
                  </div>
                </div>

                <div style="display: flex; align-items: center; gap: 1rem;">
                  <div style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); border-radius: 10px; padding: 0.6rem 1rem; text-align: right;">
                    <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #a7f3d0;">Status</div>
                    <div style="font-size: 0.95rem; font-weight: 800; color: #ffffff;">Under Active Care</div>
                  </div>
                  <a href="dashboard.php" class="btn-teal-action" style="background: #ffffff; color: #0f766e; font-weight: 800; padding: 0.65rem 1.15rem; border-radius: 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <span>Inpatient Dossier</span> &rarr;
                  </a>
                </div>
              </div>
            `;
          }
        } else if (state === 'held' && hold) {
          if (currentPatientState !== 'held' || !document.getElementById('activeHoldDeck')) {
            currentPatientState = 'held';
            container.innerHTML = `
              <div class="active-hold-banner" id="activeHoldDeck">
                <div style="display: flex; align-items: center; gap: 1.25rem;">
                  <div style="width: 52px; height: 52px; border-radius: 12px; background: rgba(255,255,255,0.18); display: flex; align-items: center; justify-content: center;">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ffffff" stroke-width="2"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
                  </div>
                  <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <span class="pulse-dot-sm" style="background: #fef08a;"></span>
                      <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; color: #bae6fd;">
                        TEMPORARY PRE-RESERVATION ACTIVE
                      </span>
                    </div>
                    <h2 style="margin: 0.2rem 0; font-size: 1.35rem; font-weight: 800; color: #ffffff;">
                      Hold Active: Bed ${escapeHtml(hold.bed_number)} at ${escapeHtml(hold.hospital_name)}
                    </h2>
                    <div style="font-size: 0.85rem; color: #e0f2fe;">
                      Ward: <strong>${escapeHtml(hold.ward_type)}</strong> (Floor ${hold.floor_number}) &bull; 
                      Daily Rate: <strong>&#2547;${Number(hold.daily_rate).toFixed(2)}</strong> &bull; 
                      Location: ${escapeHtml(hold.hospital_location)}
                    </div>
                  </div>
                </div>

                <div style="display: flex; align-items: center; gap: 1.25rem;">
                  <div class="hold-countdown-box">
                    <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #bae6fd; letter-spacing: 0.5px;">Hold Expires In</div>
                    <div class="hold-time-val" id="holdCountdown" data-seconds="${hold.seconds_remaining}">
                      ${escapeHtml(hold.countdown_formatted || '45:00')}
                    </div>
                  </div>

                  <form method="POST" onsubmit="event.preventDefault(); const form = this; if (window.MedPulseDialog && window.MedPulseDialog.confirm) { MedPulseDialog.confirm({ title: 'Cancel Bed Reservation', message: 'Cancel and release this bed back to network vacancy?', type: 'danger', confirmText: 'Release Bed', cancelText: 'Keep Bed Hold' }).then(c => { if(c) { form.setAttribute('data-mp-confirmed', 'true'); form.submit(); } }); } else { form.submit(); }" style="margin: 0;">
                    <input type="hidden" name="action" value="cancel_hold">
                    <input type="hidden" name="reservation_id" value="${hold.reservation_id}">
                    <input type="hidden" name="hospital_id" value="${hold.hospital_id}">
                    <button type="submit" class="btn-teal-action" style="background: rgba(239, 68, 68, 0.2); color: #ffffff; border: 1px solid rgba(239, 68, 68, 0.4); padding: 0.65rem 1.15rem; font-weight: 700; border-radius: 10px;">
                      Cancel Hold
                    </button>
                  </form>
                </div>
              </div>
            `;
            startHoldCountdown(hold.seconds_remaining);
          }
        } else {
          // No active hold and no admission
          if (currentPatientState !== 'none') {
            currentPatientState = 'none';
            stopHoldCountdown();
            container.innerHTML = '';
          }
        }
      }

      // ── Real-Time Multi-Hospital Matrix & Ward Bed Telemetry Polling Engine ─
      let isPollingActive = false;

      async function pollReserveSync() {
        if (isPollingActive) return;
        isPollingActive = true;

        try {
          const res = await fetch(`api/live_reserve_sync.php?hospital_id=${selectedHospitalId}`);
          if (!res.ok) return;

          const data = await res.json();
          if (!data || !data.success) return;

          // 1. Sync Network Matrix Branch Cards Telemetry
          if (Array.isArray(data.network_matrix)) {
            data.network_matrix.forEach(h => {
              const hid = h.hospital_id;
              const totEl   = document.querySelector(`[data-hosp-total="${hid}"]`);
              const occEl   = document.querySelector(`[data-hosp-occ="${hid}"]`);
              const availEl = document.querySelector(`[data-hosp-avail="${hid}"]`);
              const rateEl  = document.querySelector(`[data-hosp-rate="${hid}"]`);
              const barEl   = document.querySelector(`[data-hosp-bar="${hid}"]`);
              const emergEl = document.querySelector(`[data-hosp-emerg="${hid}"]`);
              const alertEl = document.querySelector(`[data-hosp-alert="${hid}"]`);

              const total     = parseInt(h.total_beds, 10) || 0;
              const occupied  = parseInt(h.occupied_beds, 10) || 0;
              const available = parseInt(h.available_beds, 10) || 0;
              const rate      = total > 0 ? Math.round((occupied / total) * 100) : 0;

              if (totEl) totEl.textContent = total;
              if (occEl) occEl.textContent = occupied;
              if (availEl) availEl.textContent = available;
              if (rateEl) rateEl.textContent = `${rate}%`;
              if (barEl) {
                barEl.style.width = `${rate}%`;
                barEl.style.background = rate > 85 ? '#dc2626' : (rate > 65 ? '#d97706' : '#059669');
              }

              // Emergency status badge
              if (emergEl) {
                const rawEmerg = (h.emergency_status || '').toLowerCase();
                const isCrit = rawEmerg.includes('critical') || rawEmerg.includes('divert') || rawEmerg.includes('overwhelm');
                emergEl.className = `status-badge-er ${isCrit ? 'critical' : 'operational'}`;
                const textVal = emergEl.querySelector('.emerg-text-val');
                if (textVal) textVal.textContent = h.emergency_status || '';
                if (alertEl) {
                  alertEl.style.display = isCrit ? 'flex' : 'none';
                }
              }
            });
          }

          // 2. Sync Top Vacant Count & Filter Pill Counters
          const vacantChip = document.getElementById('selectedHospitalVacantChip');
          if (vacantChip && typeof data.vacant_count !== 'undefined') {
            vacantChip.textContent = `${data.vacant_count} BEDS VACANT`;
          }

          if (data.cat_counts) {
            ['all', 'ccu_icu', 'general', 'cabin_deluxe'].forEach(cat => {
              const pillCounter = document.querySelector(`[data-pill-filter="${cat}"]`);
              if (pillCounter && typeof data.cat_counts[cat] !== 'undefined') {
                pillCounter.textContent = data.cat_counts[cat];
              }
            });
          }

          // 3. Sync Top Alert Banner in Real-Time
          syncTopAlertBanner(data.state, data.hold, data.admission);

          // 4. Sync Individual Bed Item Cards (Status Badge & Action Button)
          const hasAdmission = (data.state === 'admitted');
          const hasHold = (data.state === 'held');
          const gridContainer = document.getElementById('wardBedsGridContainer');

          if (Array.isArray(data.beds) && gridContainer) {
            data.beds.forEach(bed => {
              let card = document.querySelector(`.bed-item-card[data-bed-id="${bed.id}"]`);

              // If bed is vacant and not in DOM, create and append it dynamically
              if (!card && bed.status === 'available') {
                card = document.createElement('div');
                card.className = 'bed-item-card';
                card.setAttribute('data-bed-id', bed.id);
                card.setAttribute('data-ward-type', bed.ward_type);
                card.setAttribute('data-category', bed.category);

                card.innerHTML = `
                  <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                      <span style="font-size: 1.15rem; font-weight: 800; color: var(--brand-primary);" class="bed-num-label">
                        ${escapeHtml(bed.bed_number)}
                      </span>
                      <span class="live-chip-sm bed-status-chip" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-size: 0.72rem;">
                        VACANT
                      </span>
                    </div>

                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-heading);" class="bed-ward-label">
                      ${escapeHtml(bed.ward_type)}
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;" class="bed-floor-label">
                      Floor ${bed.floor_number} &bull; ${escapeHtml(bed.hospital_location)}
                    </div>

                    <div style="margin: 0.85rem 0; font-size: 0.95rem; font-weight: 800; color: var(--text-heading);" class="bed-rate-label">
                      &#2547;${Number(bed.daily_rate).toFixed(2)} <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">/ day</span>
                    </div>
                  </div>

                  <div class="bed-action-area" data-bed-action-id="${bed.id}"></div>
                `;

                // Insert before the noBedsFilteredMsg
                const noMsg = document.getElementById('noBedsFilteredMsg');
                if (noMsg) {
                  gridContainer.insertBefore(card, noMsg);
                } else {
                  gridContainer.appendChild(card);
                }
              }

              if (card) {
                const statusChip = card.querySelector('.bed-status-chip');
                const actionArea = card.querySelector('.bed-action-area');

                if (bed.status === 'available') {
                  if (statusChip) {
                    statusChip.style.background = '#ecfdf5';
                    statusChip.style.color = '#059669';
                    statusChip.style.borderColor = '#a7f3d0';
                    statusChip.textContent = 'VACANT';
                  }
                  if (actionArea) {
                    if (hasAdmission) {
                      actionArea.innerHTML = `
                        <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.5; cursor: not-allowed; padding: 0.55rem; font-size: 0.8rem;" title="You are currently an admitted inpatient.">
                          Currently Admitted
                        </button>
                      `;
                    } else if (hasHold) {
                      actionArea.innerHTML = `
                        <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.5; cursor: not-allowed; padding: 0.55rem; font-size: 0.8rem;" title="You already have an active hold across the network.">
                          Hold Active Elsewhere
                        </button>
                      `;
                    } else {
                      actionArea.innerHTML = `
                        <form method="POST" style="margin: 0;">
                          <input type="hidden" name="action" value="hold_bed">
                          <input type="hidden" name="bed_id" value="${bed.id}">
                          <input type="hidden" name="hospital_id" value="${selectedHospitalId}">
                          <button type="submit" class="btn-action-gradient" style="width: 100%; border: none; cursor: pointer; padding: 0.55rem; font-size: 0.82rem; font-weight: 700; border-radius: 8px;">
                            Hold Bed (45-Min Hold) &rarr;
                          </button>
                        </form>
                      `;
                    }
                  }
                } else if (bed.status === 'reserved') {
                  if (statusChip) {
                    statusChip.style.background = '#fffbeb';
                    statusChip.style.color = '#d97706';
                    statusChip.style.borderColor = '#fde68a';
                    statusChip.textContent = bed.is_patient_hold ? 'YOUR HOLD' : 'ON HOLD';
                  }
                  if (actionArea) {
                    actionArea.innerHTML = `
                      <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.45; cursor: not-allowed; background: #94a3b8; padding: 0.55rem; font-size: 0.8rem;">
                        ${bed.is_patient_hold ? 'Your Active Hold' : 'On Hold (45-Min)'}
                      </button>
                    `;
                  }
                } else if (bed.status === 'occupied') {
                  if (statusChip) {
                    statusChip.style.background = '#fef2f2';
                    statusChip.style.color = '#dc2626';
                    statusChip.style.borderColor = '#fecaca';
                    statusChip.textContent = 'OCCUPIED';
                  }
                  if (actionArea) {
                    actionArea.innerHTML = `
                      <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.45; cursor: not-allowed; background: #64748b; padding: 0.55rem; font-size: 0.8rem;">
                        Occupied
                      </button>
                    `;
                  }
                } else {
                  // Maintenance or Sanitizing
                  if (statusChip) {
                    statusChip.style.background = '#f1f5f9';
                    statusChip.style.color = '#64748b';
                    statusChip.style.borderColor = '#cbd5e1';
                    statusChip.textContent = (bed.status || 'MAINTENANCE').toUpperCase();
                  }
                  if (actionArea) {
                    actionArea.innerHTML = `
                      <button disabled class="btn-action-gradient" style="width: 100%; opacity: 0.45; cursor: not-allowed; background: #94a3b8; padding: 0.55rem; font-size: 0.8rem;">
                        Unavailable
                      </button>
                    `;
                  }
                }
              }
            });

            // Re-apply active category filter
            filterBedsByCategory(currentActiveFilter);
          }

        } catch (err) {
          console.warn('Patient bed reserve sync poll warning:', err);
        } finally {
          isPollingActive = false;
        }
      }

      // Start Real-Time Background Polling Sync Every 4.5 Seconds
      setInterval(pollReserveSync, 4500);
    });
  </script>
</body>
</html>

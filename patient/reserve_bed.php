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

// ── Check Active Hold for This Patient ──────────────────────────────────────
$activeHold = BedReservationController::getPatientActiveReservation($pdo, $patientId);

// ── Fetch Network Matrix for All 6 Hospitals ─────────────────────────────────
$networkMatrix = BedReservationController::getNetworkBedMatrix($pdo);

// Selected Hospital for Ward Explorer (defaults to URL param or first hospital)
$selectedHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : ($activeHold['hospital_id'] ?? 1);
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

// Fetch ward types and available beds for selected hospital
$wardSummary = BedReservationController::getHospitalWardSummary($pdo, $selectedHospitalId);
$availableBeds = BedReservationController::getHospitalAvailableBeds($pdo, $selectedHospitalId, $selectedWard);
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
  </style>
</head>
<body>

  <!-- Shared Canonical Patient Sidebar Partial -->
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <main class="viewport-full">

    <!-- Active Hold Real-Time Countdown Deck -->
    <?php if ($activeHold): ?>
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

          <form method="POST" onsubmit="return confirm('Cancel and release this bed back to network vacancy?');" style="margin: 0;">
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
        <a href="reserve_bed.php?hospital_id=<?= $hId ?>#wardExplorerSection" class="group block bg-white rounded-2xl border <?= $isSelected ? 'border-2 border-teal-600 bg-teal-50/20 ring-2 ring-teal-500/20' : 'border-slate-200/80' ?> p-5 cursor-pointer transition-all duration-300 ease-out hover:-translate-y-1.5 hover:shadow-xl hover:border-teal-500 hosp-matrix-card <?= $isSelected ? 'selected' : '' ?>" style="text-decoration: none; color: inherit;">
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

              <span class="status-badge-er <?= $isCritical ? 'critical' : 'operational' ?>">
                <span class="pulse-dot-sm"></span>
                <?= htmlspecialchars($hosp['emergency_status']) ?>
              </span>
            </div>

            <?php if ($isCritical): ?>
              <div style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.35); color: #dc2626; border-radius: 8px; padding: 6px 10px; font-size: 0.72rem; font-weight: 700; display: flex; align-items: center; gap: 6px; margin-bottom: 0.75rem;">
                <svg style="width: 14px; height: 14px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <span>Warning: High Trauma Surge - Walk-in &amp; Critical Diversion in Effect</span>
              </div>
            <?php endif; ?>

            <!-- Census Counters -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem; background: #f8fafc; padding: 0.75rem; border-radius: 10px; margin: 0.85rem 0; text-align: center;">
              <div>
                <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Total</div>
                <div style="font-size: 1.15rem; font-weight: 800; color: var(--text-heading);"><?= $tot ?></div>
              </div>
              <div>
                <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Occupied</div>
                <div style="font-size: 1.15rem; font-weight: 800; color: #dc2626;"><?= $occ ?></div>
              </div>
              <div>
                <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #0284c7;">Available</div>
                <div style="font-size: 1.15rem; font-weight: 800; color: #059669;"><?= $avail ?></div>
              </div>
            </div>

            <!-- Progress Bar -->
            <div style="margin-bottom: 0.5rem;">
              <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: var(--text-muted); margin-bottom: 3px;">
                <span>Occupancy Rate</span>
                <span><strong><?= $occPercent ?>%</strong></span>
              </div>
              <div style="height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                <div style="height: 100%; width: <?= $occPercent ?>%; background: <?= $occPercent > 85 ? '#dc2626' : ($occPercent > 65 ? '#d97706' : '#059669') ?>; border-radius: 999px;"></div>
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
            <span class="live-chip-sm" style="background: #e0f2fe; color: #0284c7; border-color: #bae6fd;">
              <?= count($availableBeds) ?> BEDS VACANT
            </span>
          </div>
          <p>Select any available bed to secure a 45-minute reservation hold. Only 1 active hold allowed per patient.</p>
        </div>

        <!-- Ward Filters -->
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
          <a href="reserve_bed.php?hospital_id=<?= $selectedHospitalId ?>&ward_type=all#wardExplorerSection" class="btn-teal-action" style="<?= $selectedWard === 'all' ? 'background: var(--brand-primary); color: #fff;' : 'background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;' ?> padding: 0.4rem 0.85rem; font-size: 0.78rem; text-decoration: none; border-radius: 6px;">
            All Wards
          </a>
          <?php foreach ($wardSummary as $w): ?>
            <a href="reserve_bed.php?hospital_id=<?= $selectedHospitalId ?>&ward_type=<?= urlencode($w['ward_type']) ?>#wardExplorerSection" class="btn-teal-action" style="<?= $selectedWard === $w['ward_type'] ? 'background: var(--brand-primary); color: #fff;' : 'background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;' ?> padding: 0.4rem 0.85rem; font-size: 0.78rem; text-decoration: none; border-radius: 6px;">
              <?= htmlspecialchars($w['ward_type']) ?> (<?= (int)$w['available_beds'] ?>)
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Beds Grid -->
      <div style="padding: 1.5rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
        <?php if (empty($availableBeds)): ?>
          <div style="grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-heading);">No Vacant Beds In This Category</div>
            <p style="font-size: 0.85rem; margin-top: 0.35rem;">Please select another ward type or explore other partner facilities above.</p>
          </div>
        <?php else: ?>
          <?php foreach ($availableBeds as $bed): ?>
            <div class="bed-item-card">
              <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
                  <span style="font-size: 1.15rem; font-weight: 800; color: var(--brand-primary);">
                    <?= htmlspecialchars($bed['bed_number']) ?>
                  </span>
                  <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-size: 0.72rem;">
                    VACANT
                  </span>
                </div>

                <div style="font-size: 0.85rem; font-weight: 700; color: var(--text-heading);">
                  <?= htmlspecialchars($bed['ward_type']) ?>
                </div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                  Floor <?= (int)$bed['floor_number'] ?> &bull; <?= htmlspecialchars($bed['hospital_location']) ?>
                </div>

                <div style="margin: 0.85rem 0; font-size: 0.95rem; font-weight: 800; color: var(--text-heading);">
                  &#2547;<?= number_format((float)$bed['daily_rate'], 2) ?> <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted);">/ day</span>
                </div>
              </div>

              <div>
                <?php if ($activeHold): ?>
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
        <?php endif; ?>
      </div>
    </section>

  </main>

  <!-- Interactive Countdown Timer & Smooth Scroll Script -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Smooth Auto-Scroll to Ward Explorer Section
      const section = document.getElementById('wardExplorerSection');
      const params = new URLSearchParams(window.location.search);
      if (section && (params.has('hospital_id') || window.location.hash === '#wardExplorerSection')) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      // Reservation Countdown Timer
      const countdownEl = document.getElementById('holdCountdown');
      if (!countdownEl) return;

      let remainingSecs = parseInt(countdownEl.getAttribute('data-seconds'), 10) || 0;

      const timer = setInterval(() => {
        if (remainingSecs <= 0) {
          clearInterval(timer);
          countdownEl.textContent = '00:00 (Expired)';
          countdownEl.style.color = '#ef4444';
          setTimeout(() => window.location.reload(), 2000);
          return;
        }

        remainingSecs--;
        const mins = Math.floor(remainingSecs / 60);
        const secs = remainingSecs % 60;
        countdownEl.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;

        if (remainingSecs < 300) {
          countdownEl.style.color = '#ef4444'; // Red alert when under 5 minutes
        }
      }, 1000);
    });
  </script>
</body>
</html>

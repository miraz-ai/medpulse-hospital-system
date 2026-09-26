<?php
/**
 * MedPulse Doctor Portal — OPD Clinical Chamber Console
 * Real-Time Sequential Queue Progression, Token Calling & Shift Management (Cap 25)
 */

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';
require_once __DIR__ . '/../controllers/AppointmentController.php';

$doctorUserId = (int)$_SESSION['user_id'];

// ── Handle Action Progression ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'call_next') {
        $result = AppointmentController::callNextPatient($pdo, $doctorUserId);
        if (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => $result['success'] ? 'success' : 'error', 'data' => $result]);
            exit();
        }
        header("Location: chamber.php?called=1");
        exit();
    }
}

// ── Fetch Doctor Profile & Details ─────────────────────────────────────────
try {
    $docStmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender,
               dp.specialty, dp.designation, dp.military_rank, dp.qualifications,
               dp.bmdc_license_number, dp.consultation_fee,
               dp.room_number, dp.shift_timings
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

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? $_SESSION['full_name'] ?? 'Doctor');
$displayName = formatDoctorTitle($cleanName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Medicine & OPD', ENT_QUOTES, 'UTF-8');
$roomNumber = htmlspecialchars($doctor['room_number'] ?? 'Chamber 101', ENT_QUOTES, 'UTF-8');
$shiftTimings = htmlspecialchars($doctor['shift_timings'] ?? '09:00 AM - 02:00 PM', ENT_QUOTES, 'UTF-8');
$bmdcLicense = htmlspecialchars($doctor['bmdc_license_number'] ?? 'BMDC-VERIFIED', ENT_QUOTES, 'UTF-8');

// ── Fetch Queue Data ────────────────────────────────────────────────────────
$todayQueue = AppointmentController::getDoctorTodayQueue($pdo, $doctorUserId);
$currentInChamber = null;
$nextInQueue = null;
$waitingCount = 0;
$completedCount = 0;

foreach ($todayQueue as $item) {
    $st = strtolower($item['status']);
    if ($st === 'in_consultation' && !$currentInChamber) {
        $currentInChamber = $item;
    } elseif (in_array($st, ['booked', 'checked_in']) && !$nextInQueue) {
        $nextInQueue = $item;
        $waitingCount++;
    } elseif (in_array($st, ['booked', 'checked_in'])) {
        $waitingCount++;
    } elseif ($st === 'completed') {
        $completedCount++;
    }
}

$totalBooked = count($todayQueue);
$remainingCapacity = max(0, 25 - $totalBooked);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | OPD Chamber Live Queue Console</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    .chamber-header-card {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #ffffff;
      padding: 1.75rem 2rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1.5rem;
    }
    .chamber-focus-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
      margin-bottom: 2rem;
    }
    @media (max-width: 900px) {
      .chamber-focus-grid {
        grid-template-columns: 1fr;
      }
    }
    .focus-card {
      background: #ffffff;
      border-radius: 14px;
      padding: 1.75rem;
      border: 1px solid #e2e8f0;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .focus-card.active-chamber {
      border: 2px solid #10b981;
      background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
    }
    .focus-card.next-in-line {
      border: 2px solid #0284c7;
      background: linear-gradient(180deg, #ffffff 0%, #f0f9ff 100%);
    }
    .giant-token-badge {
      font-size: 2.8rem;
      font-weight: 800;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 76px;
      height: 76px;
      border-radius: 16px;
    }
    .btn-call-giant {
      background: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      color: #ffffff;
      border: none;
      border-radius: 12px;
      padding: 1.1rem 2rem;
      font-size: 1.15rem;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      cursor: pointer;
      box-shadow: 0 6px 20px rgba(2, 132, 199, 0.35);
      transition: all 0.2s ease;
      width: 100%;
    }
    .btn-call-giant:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(2, 132, 199, 0.45);
    }
    .btn-call-giant:active {
      transform: translateY(0);
    }
    .btn-call-giant:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }
    .pulse-dot {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 8px #10b981;
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
      0% { transform: scale(0.95); opacity: 0.8; }
      50% { transform: scale(1.3); opacity: 1; }
      100% { transform: scale(0.95); opacity: 0.8; }
    }
  </style>
</head>
<body>

  <!-- Shared Doctor Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <main class="viewport-full">
    
    <!-- Chamber Command Header -->
    <div class="chamber-header-card">
      <div>
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.35rem;">
          <span class="pulse-dot"></span>
          <span style="font-size: 0.8rem; font-weight: 800; letter-spacing: 1px; color: #38bdf8; text-transform: uppercase;">
            Outpatient Department • Live Chamber Console
          </span>
        </div>
        <h1 style="font-size: 1.85rem; font-weight: 800; color: #ffffff; margin: 0;">
          <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.86rem; color: #94a3b8; margin-top: 0.45rem; flex-wrap: wrap;">
          <span><?= $specialty ?></span>
          <span>&bull;</span>
          <span style="color: #38bdf8; font-weight: 700;"><?= $roomNumber ?></span>
          <span>&bull;</span>
          <span>Shift: <?= $shiftTimings ?></span>
          <span>&bull;</span>
          <span>BMDC: <?= $bmdcLicense ?></span>
        </div>
      </div>

      <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <div style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 10px; padding: 0.75rem 1.25rem; text-align: center;">
          <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #94a3b8;">Shift Load (Cap 25)</div>
          <div style="font-size: 1.4rem; font-weight: 800; color: #38bdf8;"><?= $totalBooked ?> / 25</div>
        </div>
        <div style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 10px; padding: 0.75rem 1.25rem; text-align: center;">
          <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #94a3b8;">Waiting in Queue</div>
          <div style="font-size: 1.4rem; font-weight: 800; color: #f59e0b;"><?= $waitingCount ?></div>
        </div>
        <div style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 10px; padding: 0.75rem 1.25rem; text-align: center;">
          <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #94a3b8;">Completed Today</div>
          <div style="font-size: 1.4rem; font-weight: 800; color: #10b981;"><?= $completedCount ?></div>
        </div>
      </div>
    </div>

    <!-- Active Chamber Progression Deck -->
    <div class="chamber-focus-grid">
      
      <!-- Left Card: Currently Inside Chamber -->
      <div class="focus-card <?= $currentInChamber ? 'active-chamber' : '' ?>">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: #059669; letter-spacing: 0.5px; display: flex; align-items: center; gap: 0.4rem;">
              <span class="pulse-dot"></span> INSIDE CHAMBER CONSULTATION
            </span>
            <?php if ($currentInChamber): ?>
              <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-weight: 800;">
                ACTIVE SESSION
              </span>
            <?php else: ?>
              <span class="chip-pending" style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1;">CHAMBER READY</span>
            <?php endif; ?>
          </div>

          <?php if ($currentInChamber): ?>
            <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.25rem;">
              <div class="giant-token-badge" style="background: #10b981; color: #ffffff; box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);">
                #<?= (int)$currentInChamber['token_number'] ?>
              </div>
              <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--text-heading);">
                  <?= htmlspecialchars($currentInChamber['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <div style="font-size: 0.84rem; color: #64748b; margin-top: 0.25rem;">
                  <?= htmlspecialchars($currentInChamber['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> &bull; Phone: <?= htmlspecialchars($currentInChamber['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size: 0.82rem; color: #0369a1; font-weight: 600; margin-top: 0.25rem;">
                  Reason: <?= htmlspecialchars($currentInChamber['reason_for_visit'] ?? 'General Consultation', ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div style="padding: 1.5rem 0; text-align: center; color: #64748b;">
              <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
              <div style="font-weight: 700; font-size: 1.05rem; color: var(--text-heading);">Chamber is Currently Vacant</div>
              <p style="font-size: 0.85rem; margin-top: 0.25rem;">Click "Call Next Patient" to admit the next queued patient.</p>
            </div>
          <?php endif; ?>
        </div>

        <div style="display: flex; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap;">
          <?php if ($currentInChamber): ?>
            <a href="prescriptions.php?patient_id=<?= (int)$currentInChamber['patient_id'] ?>&appointment_id=<?= (int)$currentInChamber['id'] ?>" class="btn-action-gradient" style="flex: 1; text-align: center; text-decoration: none; padding: 0.75rem; font-size: 0.88rem; font-weight: 700; border-radius: 8px;">
              Write Prescription &amp; Clinical Notes &rarr;
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right Card: Next Patient & Big Call Next Action -->
      <div class="focus-card <?= $nextInQueue ? 'next-in-line' : '' ?>">
        <div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: #0284c7; letter-spacing: 0.5px;">
              NEXT PATIENT IN QUEUE
            </span>
            <span class="live-chip-sm" style="background: #e0f2fe; color: #0284c7; border-color: #bae6fd;">
              <?= $waitingCount ?> WAITING
            </span>
          </div>

          <?php if ($nextInQueue): ?>
            <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.25rem;">
              <div class="giant-token-badge" style="background: #0284c7; color: #ffffff; box-shadow: 0 6px 16px rgba(2, 132, 199, 0.3);">
                #<?= (int)$nextInQueue['token_number'] ?>
              </div>
              <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--text-heading);">
                  <?= htmlspecialchars($nextInQueue['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <div style="font-size: 0.84rem; color: #64748b; margin-top: 0.25rem;">
                  <?= htmlspecialchars($nextInQueue['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> &bull; Phone: <?= htmlspecialchars($nextInQueue['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size: 0.82rem; color: #0369a1; font-weight: 600; margin-top: 0.25rem;">
                  Slot: <?= htmlspecialchars($nextInQueue['time_slot'] ?? 'Morning', ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($nextInQueue['reason_for_visit'] ?? 'General Consultation', ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div style="padding: 1.5rem 0; text-align: center; color: #64748b;">
              <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="m9 11 3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
              <div style="font-weight: 700; font-size: 1.05rem; color: var(--text-heading);">Queue is Cleared</div>
              <p style="font-size: 0.85rem; margin-top: 0.25rem;">No more patients waiting in line for this session.</p>
            </div>
          <?php endif; ?>
        </div>

        <div>
          <form method="POST" id="chamberCallNextForm" style="margin: 0;">
            <input type="hidden" name="action" value="call_next">
            <button type="submit" id="btnChamberCallNext" class="btn-call-giant" <?= empty($nextInQueue) ? 'disabled' : '' ?>>
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
              CALL NEXT PATIENT
            </button>
          </form>
        </div>
      </div>

    </div>

    <!-- OPD Sequential Session Queue Table -->
    <section class="admin-stack-card">
      <div class="admin-stack-header" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
            Today's OPD Session Serial List (1 - 25 Sequential)
          </h3>
          <p>Sorted strictly by assigned token number. Cap 25 per date and shift.</p>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
          <button onclick="window.location.reload()" class="btn-teal-action" style="padding: 0.48rem 0.85rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
            Refresh Queue
          </button>
        </div>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table" id="opdQueueTable">
          <thead>
            <tr>
              <th style="width: 80px;">Token</th>
              <th>Patient Name</th>
              <th>Contact Phone</th>
              <th>Shift Slot</th>
              <th>Clinical Reason</th>
              <th>Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($todayQueue)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                  No patient appointments registered for today's session.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($todayQueue as $pt): 
                $tNum = (int)$pt['token_number'];
                $sVal = strtolower($pt['status']);
              ?>
                <tr id="queue-row-<?= $tNum ?>" style="<?= $sVal === 'in_consultation' ? 'background: rgba(16, 185, 129, 0.08); font-weight: 600;' : '' ?>">
                  <td>
                    <span class="live-chip-sm" style="font-size: 0.95rem; font-weight: 800; padding: 4px 10px; background: <?= $sVal === 'in_consultation' ? '#ecfdf5; color: #059669; border: 1px solid #a7f3d0;' : ($sVal === 'completed' ? '#f1f5f9; color: #64748b; border: 1px solid #cbd5e1;' : '#e0f2fe; color: #0284c7; border: 1px solid #bae6fd;') ?>">
                      #<?= $tNum ?>
                    </span>
                  </td>
                  <td>
                    <strong style="font-size: 0.92rem; color: var(--text-heading);">
                      <?= htmlspecialchars($pt['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">
                      <?= htmlspecialchars($pt['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td style="font-size: 0.84rem; color: var(--text-muted);"><?= htmlspecialchars($pt['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <strong style="font-size: 0.84rem; color: var(--brand-primary);"><?= htmlspecialchars($pt['time_slot'] ?? 'Morning', ENT_QUOTES, 'UTF-8') ?></strong>
                    <div style="font-size: 0.72rem; color: var(--text-muted);"><?= !empty($pt['appointment_time']) ? date('h:i A', strtotime($pt['appointment_time'])) : 'Scheduled' ?></div>
                  </td>
                  <td style="max-width: 240px;">
                    <div style="font-size: 0.84rem; color: var(--text-body); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                      <?= htmlspecialchars($pt['reason_for_visit'] ?? 'General Consultation', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($sVal === 'in_consultation'): ?>
                      <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-weight: 800;">
                        <span class="pulse-dot" style="width: 6px; height: 6px; margin-right: 4px;"></span>
                        INSIDE CHAMBER
                      </span>
                    <?php elseif (in_array($sVal, ['booked', 'checked_in'])): ?>
                      <span class="chip-consult" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">Waiting</span>
                    <?php elseif ($sVal === 'completed'): ?>
                      <span class="chip-disbursed" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">Completed</span>
                    <?php else: ?>
                      <span class="chip-pending"><?= ucfirst($sVal) ?></span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: right;">
                    <?php if ($sVal === 'in_consultation'): ?>
                      <a href="prescriptions.php?patient_id=<?= (int)$pt['patient_id'] ?>&appointment_id=<?= (int)$pt['id'] ?>" class="btn-action-gradient" style="padding: 0.4rem 0.85rem; font-size: 0.76rem; text-decoration: none;">
                        Prescribe Rx &rarr;
                      </a>
                    <?php elseif (in_array($sVal, ['booked', 'checked_in'])): ?>
                      <button type="button" onclick="callPatientDirectly(<?= $tNum ?>)" class="btn-teal-action" style="padding: 0.4rem 0.75rem; font-size: 0.76rem;">
                        Call #<?= $tNum ?>
                      </button>
                    <?php else: ?>
                      <span style="font-size: 0.76rem; color: #94a3b8;">Completed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <script>
    // Audio Chime Synthesizer for Calling Next Patient
    function playCallChime() {
      try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15); // A5
        gain.gain.setValueAtTime(0.2, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.45);
      } catch (e) {}
    }

    document.addEventListener('DOMContentLoaded', function() {
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('called') === '1') {
        playCallChime();
        if (typeof showToast === 'function') {
          showToast('Chamber queue updated: Next patient called into consultation.', 'success');
        }
      }

      const form = document.getElementById('chamberCallNextForm');
      if (form) {
        form.addEventListener('submit', async function(e) {
          e.preventDefault();
          const btn = document.getElementById('btnChamberCallNext');
          const original = btn.innerHTML;
          btn.disabled = true;
          btn.innerHTML = `<span style="display:inline-block; animation: spin 1s infinite linear;">↻</span> ADVANCING QUEUE...`;

          try {
            const res = await fetch('../backend/api/opd_queue.php?action=call_next', { credentials: 'same-origin' });
            const data = await res.json();
            if (data.status === 'success') {
              playCallChime();
              if (typeof showToast === 'function') {
                showToast(data.data.message || 'Next patient called into chamber!', 'success');
              }
              setTimeout(() => window.location.reload(), 500);
            } else {
              if (typeof showToast === 'function') {
                showToast(data.message || 'No more patients waiting in queue.', 'error');
              } else {
                alert(data.message || 'No more patients waiting in queue.');
              }
              btn.disabled = false;
              btn.innerHTML = original;
            }
          } catch (err) {
            form.submit();
          }
        });
      }
    });

    function callPatientDirectly(tokenNum) {
      const form = document.getElementById('chamberCallNextForm');
      if (form) form.requestSubmit();
    }

    // Auto-refresh queue status every 15 seconds
    setInterval(() => {
      fetch('../backend/api/opd_queue.php?action=doctor_queue')
        .then(r => r.json())
        .then(res => {
          if (res.status === 'success') {
            // Check if queue count changed, if so trigger silent reload
            const serverCount = res.data.queue.length;
            const currentRows = document.querySelectorAll('#opdQueueTable tbody tr[id^="queue-row-"]').length;
            if (serverCount !== currentRows) {
              window.location.reload();
            }
          }
        })
        .catch(() => {});
    }, 15000);
  </script>
</body>
</html>

<?php
/**
 * MedPulse Enterprise HMS — Virtual Care Suite (24/7 Live Tele-Consultation Room)
 * Real-Time Zero-Reload Synchronized Chamber Queue & Locked Video Consultation Bridge
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../controllers/TelemedicineController.php';

$patientId   = (int)$_SESSION['user_id'];
$patientName = $_SESSION['user_name'] ?? 'Patient';

// Ensure required tele-consultation schema is ready
TelemedicineController::ensureSchema($pdo);

// 1. Fetch Current Patient's Active Tele-Consultation Session
$currentSession = TelemedicineController::getPatientLiveSession($pdo, $patientId);

// 2. Fetch Available 24/7 On-Call Duty Specialists
$onCallDoctors = TelemedicineController::getOnCallDutyDoctors($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Virtual Care Suite &middot; 24/7 Live Tele-Consultation &middot; MedPulse</title>
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    /* Scoped Virtual Care Suite Aesthetics (Zero Global CSS Alteration) */
    .vc-hero-banner {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0369a1 100%);
      border-radius: 20px;
      padding: 2.2rem 2.5rem;
      color: #ffffff;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 30px -10px rgba(2, 132, 199, 0.25);
    }
    .vc-hero-banner::after {
      content: '';
      position: absolute;
      top: -40px;
      right: -40px;
      width: 240px;
      height: 240px;
      background: radial-gradient(circle, rgba(14, 165, 233, 0.22) 0%, transparent 70%);
      border-radius: 50%;
      pointer-events: none;
    }
    .vc-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      background: rgba(14, 165, 233, 0.15);
      border: 1px solid rgba(56, 189, 248, 0.3);
      color: #38bdf8;
      margin-bottom: 0.75rem;
    }
    .tele-pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      display: inline-block;
      animation: telePulse 1.4s infinite alternate;
    }
    .tele-pulse-amber {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #f59e0b;
      display: inline-block;
      animation: telePulse 1.4s infinite alternate;
    }
    @keyframes telePulse {
      0% { opacity: 0.4; transform: scale(0.9); }
      100% { opacity: 1; transform: scale(1.2); }
    }

    /* Live Queue Radar Strip */
    .vc-sync-strip {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.75rem 1.25rem;
      margin-bottom: 1.5rem;
      font-size: 0.82rem;
    }
    .vc-sync-indicator {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      color: #0f172a;
    }
    .vc-sync-ping {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #0284c7;
      box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7);
      animation: vcPing 2s infinite;
    }
    @keyframes vcPing {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(2, 132, 199, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0); }
    }

    /* Virtual Waiting Room 2-Column Grid */
    .vc-waiting-grid {
      display: grid;
      grid-template-columns: 1.1fr 1fr;
      gap: 1.5rem;
      margin-bottom: 2rem;
    }
    @media (max-width: 900px) {
      .vc-waiting-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Card Styling */
    .vc-card {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 20px;
      padding: 1.75rem;
      box-shadow: 0 4px 20px -4px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
    }

    /* Big Token Display */
    .token-showcase {
      background: linear-gradient(135deg, #f0f7ff 0%, #e0f2fe 100%);
      border: 1.5px solid #bae6fd;
      border-radius: 16px;
      padding: 1.5rem;
      text-align: center;
      margin: 1.25rem 0;
      position: relative;
      overflow: hidden;
    }
    .token-number-hero {
      font-size: 3.5rem;
      font-weight: 800;
      color: #0284c7;
      line-height: 1;
      letter-spacing: -0.03em;
      margin: 0.35rem 0;
      font-feature-settings: "tnum";
    }

    /* Real-Time Queue Telemetry Grid */
    .queue-telemetry-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.85rem;
      margin-top: 1rem;
    }
    .queue-tile {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.85rem;
      display: flex;
      flex-direction: column;
    }
    .queue-tile-label {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #64748b;
      margin-bottom: 4px;
    }
    .queue-tile-val {
      font-size: 1.05rem;
      font-weight: 800;
      color: #0f172a;
    }

    /* Locked Video Bridge Card */
    .vc-bridge-locked {
      background: #ffffff;
      border: 2px solid #e2e8f0;
      border-radius: 20px;
      padding: 2rem 1.75rem;
      text-align: center;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .lock-shield {
      width: 76px;
      height: 76px;
      border-radius: 50%;
      background: #f1f5f9;
      color: #64748b;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1.25rem;
      position: relative;
    }
    .lock-shield.is-pulsing {
      background: #fef3c7;
      color: #d97706;
      box-shadow: 0 0 0 8px rgba(245, 158, 11, 0.12);
    }

    /* Turn Active / Video Call Unlocked State */
    .vc-bridge-active {
      background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
      border: 2px solid #10b981;
      border-radius: 20px;
      padding: 2rem 1.75rem;
      text-align: center;
      box-shadow: 0 20px 40px -10px rgba(16, 185, 129, 0.25);
      animation: turnCallGlow 2s infinite alternate;
    }
    @keyframes turnCallGlow {
      0% { box-shadow: 0 10px 30px -5px rgba(16, 185, 129, 0.25); border-color: #10b981; }
      100% { box-shadow: 0 16px 45px -5px rgba(16, 185, 129, 0.45); border-color: #059669; }
    }

    .btn-join-telehealth-active {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #ffffff !important;
      text-decoration: none;
      padding: 1.15rem 2rem;
      font-size: 1.1rem;
      font-weight: 800;
      border-radius: 14px;
      box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
      transition: all 0.25s ease;
      width: 100%;
      cursor: pointer;
      border: none;
      animation: callBounce 1.5s infinite;
    }
    @keyframes callBounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-3px); }
    }
    .btn-join-telehealth-active:hover {
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      box-shadow: 0 12px 30px rgba(16, 185, 129, 0.55);
      transform: translateY(-2px);
    }

    .btn-join-telehealth-locked {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      background: #f1f5f9;
      color: #94a3b8 !important;
      text-decoration: none;
      padding: 1rem 1.5rem;
      font-size: 0.95rem;
      font-weight: 700;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      width: 100%;
      cursor: not-allowed;
      pointer-events: none;
    }

    /* On-Call Duty Doctors Grid (Selection State) */
    .vc-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }
    .doc-card {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 18px;
      padding: 1.6rem;
      transition: all 0.25s ease;
      box-shadow: 0 4px 15px -3px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .doc-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 28px -6px rgba(2, 132, 199, 0.12);
      border-color: #93c5fd;
    }
    .doc-avatar-pill {
      width: 54px;
      height: 54px;
      border-radius: 14px;
      background: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      font-weight: 800;
      flex-shrink: 0;
      box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);
    }
    .btn-request-session {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      color: #ffffff;
      border: none;
      padding: 0.85rem 1.4rem;
      font-size: 0.9rem;
      font-weight: 800;
      border-radius: 12px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
      transition: all 0.2s ease;
    }
    .btn-request-session:hover {
      background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
    }
    .btn-cancel-request {
      background: #fff;
      color: #ef4444;
      border: 1px solid #fecaca;
      padding: 0.65rem 1.15rem;
      font-size: 0.82rem;
      font-weight: 700;
      border-radius: 10px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s ease;
    }
    .btn-cancel-request:hover {
      background: #fef2f2;
      border-color: #f87171;
    }
  </style>
</head>
<body>

  <!-- Shared Canonical Patient Sidebar -->
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <!-- Main Viewport -->
  <main class="viewport">
    
    <!-- Hero Banner -->
    <div class="vc-hero-banner">
      <div style="position: relative; z-index: 1;">
        <div class="vc-status-pill">
          <span class="tele-pulse-dot"></span>
          24/7 VIRTUAL CARE SUITE &bull; ZERO-RELOAD SYNC
        </div>
        <h1 style="font-size: 1.85rem; font-weight: 800; margin: 0 0 0.5rem; letter-spacing: -0.02em;">
          Virtual Care Suite
        </h1>
        <p style="font-size: 0.95rem; margin: 0; opacity: 0.9; max-width: 660px; line-height: 1.5;">
          Direct clinical video bridge with 24/7 on-call hospital specialists. Dynamic token queue with real-time zero-reload sync and encrypted privacy-locked Zoom HD launch.
        </p>

        <!-- Technical Telemetry Strip -->
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.5rem;">
          <div style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; padding: 0.6rem 1rem; display: flex; align-items: center; gap: 8px;">
            <svg style="width: 18px; height: 18px; stroke: #38bdf8;" fill="none" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            <div>
              <span style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8; display: block;">Video Protocol</span>
              <strong style="font-size: 0.82rem;">1080p HD &bull; Zoom Pro</strong>
            </div>
          </div>

          <div style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; padding: 0.6rem 1rem; display: flex; align-items: center; gap: 8px;">
            <svg style="width: 18px; height: 18px; stroke: #34d399;" fill="none" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <div>
              <span style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8; display: block;">Privacy Lock</span>
              <strong style="font-size: 0.82rem;">Turn-Gated Video Gate</strong>
            </div>
          </div>

          <div style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; padding: 0.6rem 1rem; display: flex; align-items: center; gap: 8px;">
            <svg style="width: 18px; height: 18px; stroke: #fcd34d;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <div>
              <span style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.8; display: block;">Sync Engine</span>
              <strong style="font-size: 0.82rem;" id="heroSyncText">Live (Polling 3.5s)</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Active Real-Time Synchronizer Strip -->
    <div class="vc-sync-strip">
      <div class="vc-sync-indicator">
        <span class="vc-sync-ping"></span>
        <span id="syncStatusLabel">Real-Time Zero-Reload Synchronizer: Connected to Room Host</span>
      </div>
      <div style="color: #64748b; font-size: 0.76rem;" id="syncClock">
        Last Synced: Just now
      </div>
    </div>

    <!-- Dynamic Container: Flips between Waiting Room & Doctor Selection without page reload -->
    <div id="vcMainContainer">
      
      <!-- ===================================================================== -->
      <!-- VIEW A: VIRTUAL WAITING ROOM (Shown when patient has active token)   -->
      <!-- ===================================================================== -->
      <div id="viewWaitingRoom" style="display: <?= $currentSession ? 'block' : 'none' ?>;">
        
        <!-- Live Alert Banner (Updated dynamically) -->
        <div id="turnAlertBanner" style="display: <?= (!empty($currentSession['is_called'])) ? 'flex' : 'none' ?>; background: #ecfdf5; border: 1.5px solid #6ee7b7; border-radius: 14px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; align-items: center; justify-content: space-between; gap: 1rem;">
          <div style="display: flex; align-items: center; gap: 12px;">
            <span style="width: 36px; height: 36px; border-radius: 50%; background: #10b981; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
              🔔
            </span>
            <div>
              <strong style="color: #065f46; font-size: 0.95rem; display: block;">Doctor is calling your token now!</strong>
              <span style="color: #047857; font-size: 0.82rem;">Your video bridge is active. Click "Join Video Call Now" to begin your consultation.</span>
            </div>
          </div>
          <span style="font-size: 0.72rem; font-weight: 800; background: #059669; color: #fff; padding: 4px 10px; border-radius: 999px;">
            TURN ACTIVE
          </span>
        </div>

        <div class="vc-waiting-grid">
          
          <!-- Column 1: Attending Specialist & Token Telemetry Dossier -->
          <div class="vc-card">
            <div>
              <!-- Hospital Badge -->
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #0284c7; background: rgba(2, 132, 199, 0.08); border: 1px solid rgba(2, 132, 199, 0.2); padding: 3px 8px; border-radius: 6px;">
                  <span id="cardHospitalName"><?= htmlspecialchars($currentSession['hospital_name'] ?? 'MedPulse Hospital') ?></span>
                </span>
                <span id="chamberLiveBadge" style="font-size: 0.7rem; font-weight: 800; padding: 3px 8px; border-radius: 999px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; display: inline-flex; align-items: center; gap: 5px;">
                  <span class="tele-pulse-dot"></span> CHAMBER HOST ACTIVE
                </span>
              </div>

              <!-- Doctor Identity -->
              <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 1.25rem;">
                <div class="doc-avatar-pill" id="cardDocInitials">
                  <?php 
                    $docName = $currentSession['doctor_name'] ?? 'Doctor';
                    echo htmlspecialchars(substr(trim(str_replace('Dr.', '', $docName)), 0, 2));
                  ?>
                </div>
                <div>
                  <h3 style="font-size: 1.2rem; font-weight: 800; color: #0f172a; margin: 0 0 2px;" id="cardDoctorName">
                    <?= htmlspecialchars($currentSession['doctor_name'] ?? 'Attending Specialist') ?>
                  </h3>
                  <p style="font-size: 0.82rem; font-weight: 700; color: #0284c7; margin: 0;" id="cardDoctorSpecialty">
                    <?= htmlspecialchars($currentSession['specialty'] ?? 'Specialist Consultant') ?>
                  </p>
                  <span style="font-size: 0.72rem; color: #64748b; display: block; margin-top: 2px;" id="cardDoctorMeta">
                    BMDC: <?= htmlspecialchars($currentSession['bmdc_license_number'] ?? 'VERIFIED') ?> &bull; Room: <?= htmlspecialchars($currentSession['room_code'] ?? 'MP-VC-401') ?>
                  </span>
                </div>
              </div>

              <!-- Assigned Token Box -->
              <div class="token-showcase">
                <span style="font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #0369a1; display: block;">
                  Your Assigned Live Token
                </span>
                <div class="token-number-hero" id="cardMyToken">
                  #<?= str_pad((string)($currentSession['my_token'] ?? 1), 2, '0', STR_PAD_LEFT) ?>
                </div>
                <div style="font-size: 0.78rem; font-weight: 700; color: #0284c7;" id="cardTokenStatusLine">
                  <?= !empty($currentSession['is_called']) ? '🎉 Doctor is calling your token now!' : 'You are in queue. Please stay on this page.' ?>
                </div>
              </div>

              <!-- Live Queue Metrics -->
              <div class="queue-telemetry-row">
                <div class="queue-tile">
                  <span class="queue-tile-label">Doctor Currently Seeing</span>
                  <div class="queue-tile-val" style="color: #2563eb;" id="cardCurrentServing">
                    <?php 
                      $cs = (int)($currentSession['current_serving_token'] ?? 0);
                      echo ($cs > 0) ? "Token #" . str_pad((string)$cs, 2, '0', STR_PAD_LEFT) : 'Starting Next';
                    ?>
                  </div>
                </div>

                <div class="queue-tile">
                  <span class="queue-tile-label">Your Queue Position</span>
                  <div class="queue-tile-val" id="cardQueuePosition">
                    <?php 
                      if (!empty($currentSession['is_called'])) {
                        echo '<span style="color:#059669;">Your Turn Now</span>';
                      } else {
                        $ahead = (int)($currentSession['people_ahead'] ?? 0);
                        echo ($ahead === 0) ? '<span style="color:#d97706;">Next in Line</span>' : "{$ahead} Ahead";
                      }
                    ?>
                  </div>
                </div>

                <div class="queue-tile">
                  <span class="queue-tile-label">Est. Wait Time</span>
                  <div class="queue-tile-val" id="cardEstWait">
                    <?= !empty($currentSession['is_called']) ? '0 Mins (Ready)' : '~' . ($currentSession['estimated_wait_mins'] ?? 8) . ' Mins' ?>
                  </div>
                </div>

                <div class="queue-tile">
                  <span class="queue-tile-label">Session Protocol</span>
                  <div class="queue-tile-val" style="font-size: 0.9rem; color: #059669;">
                    24/7 Live Care
                  </div>
                </div>
              </div>
            </div>

            <!-- Cancel / Exit Queue Option -->
            <div style="margin-top: 1.5rem; pt: 1rem; border-top: 1px dashed #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
              <span style="font-size: 0.74rem; color: #64748b;">Need to leave?</span>
              <button type="button" class="btn-cancel-request" onclick="cancelActiveQueue(<?= (int)($currentSession['appointment_id'] ?? 0) ?>);">
                <svg style="width: 14px; height: 14px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                <span>Cancel Queue Request</span>
              </button>
            </div>
          </div>

          <!-- Column 2: Video Consultation Bridge (Dynamic Zero-Reload Swap) -->
          <div id="videoBridgeContainer">
            
            <?php if (!empty($currentSession['is_called'])): ?>
              <!-- STATE B: TURN ACTIVE / UNLOCKED STATE -->
              <div class="vc-bridge-active" id="bridgeActiveView">
                <div style="width: 72px; height: 72px; border-radius: 50%; background: #10b981; color: #ffffff; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);">
                  <svg style="width: 38px; height: 38px; stroke: #fff; fill: none;" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                </div>

                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; background: #dcfce7; color: #15803d; padding: 4px 12px; border-radius: 999px; margin-bottom: 0.75rem;">
                  <span class="tele-pulse-dot"></span> CALL UNLOCKED &bull; YOUR TURN ACTIVE
                </span>

                <h2 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">
                  Doctor is Ready For You!
                </h2>
                <p style="font-size: 0.88rem; color: #475569; margin: 0 0 1.5rem; line-height: 1.5;">
                  The encrypted video chamber has opened. Click the button below to launch Zoom HD in a new tab.
                </p>

                <!-- Meeting Room Credentials -->
                <div style="background: #ffffff; border: 1.5px solid #a7f3d0; border-radius: 12px; padding: 0.85rem 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.8rem;">
                  <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="color: #64748b;">Meeting Room:</span>
                    <strong style="color: #0f172a; font-family: monospace;" id="meetingRoomIdDisplay"><?= htmlspecialchars($currentSession['meeting_id'] ?? '980-000-0000') ?></strong>
                  </div>
                  <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="color: #64748b;">Passcode:</span>
                    <strong style="color: #0f172a; font-family: monospace;" id="meetingPasscodeDisplay"><?= htmlspecialchars($currentSession['passcode'] ?? 'mp2026') ?></strong>
                  </div>
                  <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">Security:</span>
                    <strong style="color: #059669;">AES-256 HD Tunneled</strong>
                  </div>
                </div>

                <!-- Primary Action: Join Video Call Now -->
                <a href="<?= htmlspecialchars($currentSession['zoom_link'] ?? '#') ?>" 
                   target="_blank" 
                   rel="noopener noreferrer" 
                   class="btn-join-telehealth-active"
                   id="btnJoinVideoCall">
                  <svg style="width: 22px; height: 22px; stroke: #fff;" fill="none" viewBox="0 0 24 24">
                    <polygon points="23 7 16 12 23 17 23 7"></polygon>
                    <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                  </svg>
                  <span>Join Video Call Now</span>
                  <svg style="width: 16px; height: 16px; stroke: #fff;" fill="none" viewBox="0 0 24 24">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                  </svg>
                </a>

                <div style="font-size: 0.72rem; color: #64748b; margin-top: 1rem;">
                  Works on Zoom Web App and Zoom Desktop Client.
                </div>
              </div>

            <?php else: ?>
              <!-- STATE A: WAITING / PRIVACY LOCKED STATE -->
              <div class="vc-bridge-locked" id="bridgeLockedView">
                <div class="lock-shield is-pulsing">
                  <svg style="width: 36px; height: 36px; stroke: currentColor;" fill="none" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                </div>

                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; background: #fef3c7; color: #b45309; padding: 4px 12px; border-radius: 999px; margin-bottom: 0.75rem;">
                  <span class="tele-pulse-amber"></span> VIDEO BRIDGE LOCKED &bull; WAITING FOR TURN
                </span>

                <h2 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">
                  Consultation Bridge Locked
                </h2>
                <p style="font-size: 0.88rem; color: #64748b; margin: 0 0 1.5rem; line-height: 1.5;">
                  To ensure clinical confidentiality and prevent overlapping patients, your Zoom link is locked while the doctor completes consultations with preceding tokens.
                </p>

                <!-- Informational Lock Callout -->
                <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.8rem; color: #475569;">
                  <div style="display: flex; gap: 8px; align-items: flex-start;">
                    <span style="font-size: 1.1rem; line-height: 1;">⚡</span>
                    <div>
                      <strong style="color: #0f172a; display: block; margin-bottom: 2px;">Instant Zero-Reload Unlock</strong>
                      The moment your doctor calls Token #<span id="lockedWaitTokenDisplay"><?= (int)($currentSession['my_token'] ?? 1) ?></span>, this lock will automatically dissolve into the active <strong>"Join Video Call Now"</strong> button.
                    </div>
                  </div>
                </div>

                <!-- Disabled / Locked Button -->
                <div class="btn-join-telehealth-locked">
                  <svg style="width: 18px; height: 18px; stroke: currentColor;" fill="none" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                  </svg>
                  <span>Video Call Locked (Waiting for Doctor)</span>
                </div>

                <div style="margin-top: 1.25rem; font-size: 0.74rem; color: #64748b; display: flex; align-items: center; justify-content: center; gap: 6px;">
                  <span>🔊 Audio Chime Armed &bull; Keep tab open</span>
                </div>
              </div>
            <?php endif; ?>

          </div>

        </div>

      </div>

      <!-- ===================================================================== -->
      <!-- VIEW B: ON-CALL DOCTOR SELECTION (Shown when NO active token)        -->
      <!-- ===================================================================== -->
      <div id="viewDoctorSelection" style="display: <?= $currentSession ? 'none' : 'block' ?>;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
          <div>
            <h2 style="font-size: 1.35rem; font-weight: 800; color: var(--text-heading); margin: 0;">
              24/7 On-Call Duty Specialists
            </h2>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 3px 0 0;">
              Actively hosting virtual chamber rooms. Select a specialist to request an immediate live consultation session.
            </p>
          </div>
          <span style="font-size: 0.75rem; font-weight: 800; background: #ecfdf5; color: #059669; padding: 4px 10px; border-radius: 999px;">
            <?= count($onCallDoctors) ?> Specialists On-Call
          </span>
        </div>

        <div class="vc-grid" id="onCallDoctorsGrid">
          <?php foreach ($onCallDoctors as $doc): 
            $docId = (int)$doc['user_id'];
            $names = explode(' ', trim($doc['full_name']));
            $initials = '';
            foreach ($names as $n) {
              if (!empty($n) && strtolower($n) !== 'dr.') {
                $initials .= strtoupper($n[0]);
              }
            }
            if (empty($initials)) $initials = 'DR';
            $initials = substr($initials, 0, 2);

            $servingToken = (int)$doc['current_serving_token'];
            $waitingCount = (int)$doc['waiting_count'];
          ?>
            <div class="doc-card" id="docCard_<?= $docId ?>">
              <div>
                <!-- Top Status Badge Strip -->
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1rem;">
                  <span style="font-size: 0.7rem; font-weight: 700; color: #0284c7; background: rgba(2, 132, 199, 0.08); border: 1px solid rgba(2, 132, 199, 0.2); padding: 3px 8px; border-radius: 6px;">
                    <?= htmlspecialchars($doc['hospital_name']) ?>
                  </span>

                  <span style="font-size: 0.68rem; font-weight: 800; padding: 3px 8px; border-radius: 999px; background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; display: inline-flex; align-items: center; gap: 4px;">
                    <span class="tele-pulse-dot"></span> 24/7 ON-CALL
                  </span>
                </div>

                <!-- Doctor Identity -->
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 1.15rem;">
                  <div class="doc-avatar-pill">
                    <?= htmlspecialchars($initials) ?>
                  </div>
                  <div>
                    <h3 style="font-size: 1.1rem; font-weight: 800; color: var(--text-heading); margin: 0 0 2px;">
                      <?= htmlspecialchars($doc['full_name']) ?>
                    </h3>
                    <p style="font-size: 0.8rem; font-weight: 600; color: #0284c7; margin: 0;">
                      <?= htmlspecialchars($doc['specialty']) ?>
                    </p>
                    <span style="font-size: 0.72rem; color: var(--text-muted); display: block; margin-top: 2px;">
                      Room: <?= htmlspecialchars($doc['teleconsult_room_code']) ?> &bull; <?= htmlspecialchars($doc['bmdc_license_number'] ?? 'BMDC-VERIFIED') ?>
                    </span>
                  </div>
                </div>

                <!-- Real-Time Chamber Telemetry Box -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.85rem; margin-bottom: 1.15rem; font-size: 0.82rem;">
                  <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: #64748b; font-weight: 600;">Currently Consulting:</span>
                    <strong style="color: #2563eb;" id="docServing_<?= $docId ?>">
                      <?= ($servingToken > 0) ? "Token #" . str_pad((string)$servingToken, 2, '0', STR_PAD_LEFT) : 'Chamber Ready' ?>
                    </strong>
                  </div>
                  <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: #64748b; font-weight: 600;">Waiting Queue:</span>
                    <strong style="color: #0f172a;" id="docWaiting_<?= $docId ?>">
                      <?= ($waitingCount > 0) ? "{$waitingCount} Patients in Line" : 'No Wait &bull; Immediate' ?>
                    </strong>
                  </div>
                  <div style="display: flex; justify-content: space-between;">
                    <span style="color: #64748b; font-weight: 600;">Estimated Wait:</span>
                    <strong style="color: #059669;" id="docWaitTime_<?= $docId ?>">
                      <?= ($waitingCount > 0) ? "~" . ($waitingCount * 8) . " mins" : '~0 mins' ?>
                    </strong>
                  </div>
                </div>

                <!-- Quick Complaint / Symptoms Field -->
                <div style="margin-bottom: 1rem;">
                  <label style="display: block; font-size: 0.72rem; font-weight: 700; color: #475569; margin-bottom: 4px;">
                    Chief Complaint / Symptoms (Optional)
                  </label>
                  <input type="text" 
                         id="inputReason_<?= $docId ?>" 
                         placeholder="E.g. Fever, headache, follow-up inquiry" 
                         style="width: 100%; padding: 0.55rem 0.75rem; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-family: inherit;">
                </div>
              </div>

              <!-- Action: Request Live Consultation Session -->
              <div>
                <button type="button" 
                        class="btn-request-session" 
                        id="btnReqDoc_<?= $docId ?>"
                        onclick="requestLiveSession(<?= $docId ?>);">
                  <svg style="width: 16px; height: 16px; stroke: #fff; fill: currentColor;" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                  <span>Request Live Consultation Session</span>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

      </div>

    </div>

    <!-- Technical Guide & Patient Guidance Strip -->
    <div style="background: #ffffff; border: 1px solid var(--surface-border); border-radius: 16px; padding: 1.5rem; margin-top: 1.5rem;">
      <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--text-heading); margin: 0 0 0.5rem; display: flex; align-items: center; gap: 6px;">
        <svg style="width: 16px; height: 16px; stroke: #0284c7;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
        Virtual Waiting Room &bull; Real-Time Consultation Protocol
      </h4>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; font-size: 0.82rem; color: #475569; margin-top: 0.85rem;">
        <div style="background: #f8fafc; padding: 0.85rem; border-radius: 10px; border: 1px solid #f1f5f9;">
          <strong style="color: #0f172a; display: block; margin-bottom: 3px;">1. Dynamic Sequential Token</strong>
          Upon requesting a session, you receive a dynamic serial. Attending specialists consult patients strictly in token order to avoid chamber congestion.
        </div>
        <div style="background: #f8fafc; padding: 0.85rem; border-radius: 10px; border: 1px solid #f1f5f9;">
          <strong style="color: #0f172a; display: block; margin-bottom: 3px;">2. Privacy-Locked Video Gate</strong>
          The Zoom meeting bridge is locked while waiting. The moment your doctor advances the queue and calls your token, your "Join Video Call Now" button automatically unlocks.
        </div>
        <div style="background: #f8fafc; padding: 0.85rem; border-radius: 10px; border: 1px solid #f1f5f9;">
          <strong style="color: #0f172a; display: block; margin-bottom: 3px;">3. Zero-Reload Automatic Sync</strong>
          No manual page refresh is needed! Our real-time synchronizer polls every 3.5 seconds and triggers an audio chime when your turn arrives.
        </div>
      </div>
    </div>

  </main>

  <!-- Hidden CSRF Token Anchor -->
  <input type="hidden" id="pageCsrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

  <!-- ========================================================================= -->
  <!-- Real-Time Zero-Reload Synchronization Client Engine                      -->
  <!-- ========================================================================= -->
  <script>
    (function() {
      let pollInterval = 3500; // 3.5 seconds lightweight polling
      let pollTimer = null;
      let currentState = <?= json_encode($currentSession['state'] ?? 'none') ?>;
      let activeAppointmentId = <?= (int)($currentSession['appointment_id'] ?? 0) ?>;
      let myTokenNumber = <?= (int)($currentSession['my_token'] ?? 0) ?>;
      let hasChimedForCurrentTurn = false;

      // Web Audio API Pleasant 2-Tone Medical Chime Synthesizer
      function playTurnChime() {
        try {
          const AudioContext = window.AudioContext || window.webkitAudioContext;
          if (!AudioContext) return;
          const ctx = new AudioContext();
          const now = ctx.currentTime;

          // First Note (D5 - 587.33 Hz)
          const osc1 = ctx.createOscillator();
          const gain1 = ctx.createGain();
          osc1.type = 'sine';
          osc1.frequency.setValueAtTime(587.33, now);
          gain1.gain.setValueAtTime(0.28, now);
          gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.45);
          osc1.connect(gain1);
          gain1.connect(ctx.destination);
          osc1.start(now);
          osc1.stop(now + 0.45);

          // Second Note (A5 - 880 Hz)
          const osc2 = ctx.createOscillator();
          const gain2 = ctx.createGain();
          osc2.type = 'sine';
          osc2.frequency.setValueAtTime(880, now + 0.22);
          gain2.gain.setValueAtTime(0.32, now + 0.22);
          gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.85);
          osc2.connect(gain2);
          gain2.connect(ctx.destination);
          osc2.start(now + 0.22);
          osc2.stop(now + 0.85);
        } catch (err) {
          console.warn('Audio chime notice:', err);
        }
      }

      // Title Flash Reminder for Minimized Tabs
      let titleTimer = null;
      function startTitleFlash(text) {
        if (titleTimer) clearInterval(titleTimer);
        const origTitle = "Virtual Care Suite · MedPulse";
        let isFlash = false;
        titleTimer = setInterval(() => {
          document.title = isFlash ? text : origTitle;
          isFlash = !isFlash;
        }, 1000);
      }

      function stopTitleFlash() {
        if (titleTimer) {
          clearInterval(titleTimer);
          titleTimer = null;
          document.title = "Virtual Care Suite · MedPulse";
        }
      }

      // Core Polling Handler
      async function executeZeroReloadSync() {
        try {
          const resp = await fetch('api/live_telemedicine_sync.php', {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
          });
          if (!resp.ok) return;

          const data = await resp.json();
          if (!data || !data.success) return;

          // Update Sync Clock
          const nowStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
          const syncClock = document.getElementById('syncClock');
          if (syncClock) syncClock.textContent = 'Last Synced: ' + nowStr;

          // Branch A: Patient has an active session
          if (data.has_active_session && data.session) {
            handleActiveSessionTelemetry(data.session);
          } else {
            // Branch B: No active session
            handleNoActiveSession(data.on_call_doctors || []);
          }

        } catch (err) {
          console.error('Zero-reload sync tick error:', err);
        }
      }

      // Seamless DOM state update for Active Session
      function handleActiveSessionTelemetry(session) {
        const waitingView = document.getElementById('viewWaitingRoom');
        const selectionView = document.getElementById('viewDoctorSelection');
        
        if (waitingView && waitingView.style.display === 'none') {
          waitingView.style.display = 'block';
          if (selectionView) selectionView.style.display = 'none';
        }

        activeAppointmentId = session.appointment_id;
        myTokenNumber = session.my_token;

        // 1. Update Token Dossier
        const tokenHero = document.getElementById('cardMyToken');
        if (tokenHero) tokenHero.textContent = '#' + String(session.my_token).padStart(2, '0');

        const curServing = document.getElementById('cardCurrentServing');
        if (curServing) {
          curServing.textContent = (session.current_serving_token > 0)
            ? 'Token #' + String(session.current_serving_token).padStart(2, '0')
            : 'Starting Next';
        }

        const queuePos = document.getElementById('cardQueuePosition');
        if (queuePos) {
          if (session.is_called) {
            queuePos.innerHTML = '<span style="color:#059669;">Your Turn Now</span>';
          } else if (session.people_ahead === 0) {
            queuePos.innerHTML = '<span style="color:#d97706;">Next in Line</span>';
          } else {
            queuePos.textContent = session.people_ahead + ' Ahead';
          }
        }

        const estWait = document.getElementById('cardEstWait');
        if (estWait) {
          estWait.textContent = session.is_called ? '0 Mins (Ready)' : '~' + session.estimated_wait_mins + ' Mins';
        }

        const docNameEl = document.getElementById('cardDoctorName');
        if (docNameEl) docNameEl.textContent = session.doctor_name;

        const docSpecEl = document.getElementById('cardDoctorSpecialty');
        if (docSpecEl) docSpecEl.textContent = session.specialty;

        const hospNameEl = document.getElementById('cardHospitalName');
        if (hospNameEl) hospNameEl.textContent = session.hospital_name;

        // 2. VIDEO BRIDGE TRANSITION: WAITING -> CALLED
        const isCalledNow = (session.state === 'called' || session.is_called);

        if (isCalledNow) {
          // Play pleasant chime and trigger title flash once when turn transitions
          if (!hasChimedForCurrentTurn) {
            playTurnChime();
            startTitleFlash('🔔 (1) YOUR TURN IS ACTIVE! Join Video Call');
            hasChimedForCurrentTurn = true;
          }

          renderActiveCallBridge(session);
        } else {
          hasChimedForCurrentTurn = false;
          stopTitleFlash();
          renderLockedBridge(session);
        }

        currentState = session.state;
      }

      // Render Active Video Call Bridge (Zero-Reload Instant Swap)
      function renderActiveCallBridge(session) {
        const bridgeContainer = document.getElementById('videoBridgeContainer');
        const alertBanner = document.getElementById('turnAlertBanner');
        const tokenLine = document.getElementById('cardTokenStatusLine');

        if (alertBanner) alertBanner.style.display = 'flex';
        if (tokenLine) tokenLine.textContent = '🎉 Doctor is calling your token now!';

        if (!bridgeContainer) return;

        // If active view is already mounted, just update links
        const existingActive = document.getElementById('bridgeActiveView');
        if (existingActive) {
          const btn = document.getElementById('btnJoinVideoCall');
          if (btn && session.zoom_link) btn.href = session.zoom_link;
          const roomId = document.getElementById('meetingRoomIdDisplay');
          if (roomId && session.meeting_id) roomId.textContent = session.meeting_id;
          const pass = document.getElementById('meetingPasscodeDisplay');
          if (pass && session.passcode) pass.textContent = session.passcode;
          return;
        }

        // Swapping DOM to Active Call View
        bridgeContainer.innerHTML = `
          <div class="vc-bridge-active" id="bridgeActiveView">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: #10b981; color: #ffffff; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);">
              <svg style="width: 38px; height: 38px; stroke: #fff; fill: none;" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            </div>

            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; background: #dcfce7; color: #15803d; padding: 4px 12px; border-radius: 999px; margin-bottom: 0.75rem;">
              <span class="tele-pulse-dot"></span> CALL UNLOCKED &bull; YOUR TURN ACTIVE
            </span>

            <h2 style="font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">
              Doctor is Ready For You!
            </h2>
            <p style="font-size: 0.88rem; color: #475569; margin: 0 0 1.5rem; line-height: 1.5;">
              The encrypted video chamber has opened. Click the button below to launch Zoom HD in a new tab.
            </p>

            <div style="background: #ffffff; border: 1.5px solid #a7f3d0; border-radius: 12px; padding: 0.85rem 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.8rem;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #64748b;">Meeting Room:</span>
                <strong style="color: #0f172a; font-family: monospace;" id="meetingRoomIdDisplay">${escapeHtml(session.meeting_id || '980-000-0000')}</strong>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #64748b;">Passcode:</span>
                <strong style="color: #0f172a; font-family: monospace;" id="meetingPasscodeDisplay">${escapeHtml(session.passcode || 'mp2026')}</strong>
              </div>
              <div style="display: flex; justify-content: space-between;">
                <span style="color: #64748b;">Security:</span>
                <strong style="color: #059669;">AES-256 HD Tunneled</strong>
              </div>
            </div>

            <a href="${escapeHtml(session.zoom_link || '#')}" 
               target="_blank" 
               rel="noopener noreferrer" 
               class="btn-join-telehealth-active"
               id="btnJoinVideoCall">
              <svg style="width: 22px; height: 22px; stroke: #fff;" fill="none" viewBox="0 0 24 24">
                <polygon points="23 7 16 12 23 17 23 7"></polygon>
                <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
              </svg>
              <span>Join Video Call Now</span>
              <svg style="width: 16px; height: 16px; stroke: #fff;" fill="none" viewBox="0 0 24 24">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                <polyline points="15 3 21 3 21 9"></polyline>
                <line x1="10" y1="14" x2="21" y2="3"></line>
              </svg>
            </a>

            <div style="font-size: 0.72rem; color: #64748b; margin-top: 1rem;">
              Works on Zoom Web App and Zoom Desktop Client.
            </div>
          </div>
        `;
      }

      // Render Locked Bridge (When Still in Queue)
      function renderLockedBridge(session) {
        const bridgeContainer = document.getElementById('videoBridgeContainer');
        const alertBanner = document.getElementById('turnAlertBanner');
        const tokenLine = document.getElementById('cardTokenStatusLine');

        if (alertBanner) alertBanner.style.display = 'none';
        if (tokenLine) tokenLine.textContent = 'You are in queue. Please stay on this page.';

        if (!bridgeContainer) return;

        // If locked view is already mounted, return
        if (document.getElementById('bridgeLockedView')) return;

        bridgeContainer.innerHTML = `
          <div class="vc-bridge-locked" id="bridgeLockedView">
            <div class="lock-shield is-pulsing">
              <svg style="width: 36px; height: 36px; stroke: currentColor;" fill="none" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
            </div>

            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; background: #fef3c7; color: #b45309; padding: 4px 12px; border-radius: 999px; margin-bottom: 0.75rem;">
              <span class="tele-pulse-amber"></span> VIDEO BRIDGE LOCKED &bull; WAITING FOR TURN
            </span>

            <h2 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">
              Consultation Bridge Locked
            </h2>
            <p style="font-size: 0.88rem; color: #64748b; margin: 0 0 1.5rem; line-height: 1.5;">
              To ensure clinical confidentiality and prevent overlapping patients, your Zoom link is locked while the doctor completes consultations with preceding tokens.
            </p>

            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.8rem; color: #475569;">
              <div style="display: flex; gap: 8px; align-items: flex-start;">
                <span style="font-size: 1.1rem; line-height: 1;">⚡</span>
                <div>
                  <strong style="color: #0f172a; display: block; margin-bottom: 2px;">Instant Zero-Reload Unlock</strong>
                  The moment your doctor calls Token #<span id="lockedWaitTokenDisplay">${session.my_token || 1}</span>, this lock will automatically dissolve into the active <strong>"Join Video Call Now"</strong> button.
                </div>
              </div>
            </div>

            <div class="btn-join-telehealth-locked">
              <svg style="width: 18px; height: 18px; stroke: currentColor;" fill="none" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
              <span>Video Call Locked (Waiting for Doctor)</span>
            </div>

            <div style="margin-top: 1.25rem; font-size: 0.74rem; color: #64748b; display: flex; align-items: center; justify-content: center; gap: 6px;">
              <span>🔊 Audio Chime Armed &bull; Keep tab open</span>
            </div>
          </div>
        `;
      }

      // Handle when patient has no active queue token
      function handleNoActiveSession(doctors) {
        stopTitleFlash();
        hasChimedForCurrentTurn = false;

        const waitingView = document.getElementById('viewWaitingRoom');
        const selectionView = document.getElementById('viewDoctorSelection');

        if (waitingView && waitingView.style.display !== 'none') {
          waitingView.style.display = 'none';
          if (selectionView) selectionView.style.display = 'block';
        }

        // Live update on-call doctors telemetry in the cards
        doctors.forEach(doc => {
          const sEl = document.getElementById(`docServing_${doc.user_id}`);
          if (sEl) {
            sEl.textContent = (doc.current_serving_token > 0)
              ? 'Token #' + String(doc.current_serving_token).padStart(2, '0')
              : 'Chamber Ready';
          }
          const wEl = document.getElementById(`docWaiting_${doc.user_id}`);
          if (wEl) {
            wEl.textContent = (doc.waiting_count > 0)
              ? doc.waiting_count + ' Patients in Line'
              : 'No Wait • Immediate';
          }
          const tEl = document.getElementById(`docWaitTime_${doc.user_id}`);
          if (tEl) {
            tEl.textContent = (doc.waiting_count > 0)
              ? '~' + (doc.waiting_count * 8) + ' mins'
              : '~0 mins';
          }
        });
      }

      // Global Action: Request Live Session with an On-Call Doctor
      window.requestLiveSession = async function(doctorId) {
        const btn = document.getElementById(`btnReqDoc_${doctorId}`);
        const inputReason = document.getElementById(`inputReason_${doctorId}`);
        const reason = inputReason ? inputReason.value.trim() : '';
        const csrfToken = document.getElementById('pageCsrfToken').value;

        if (btn) {
          btn.disabled = true;
          btn.style.opacity = '0.7';
          btn.innerHTML = '<span>Assigning Dynamic Token...</span>';
        }

        try {
          const formData = new FormData();
          formData.append('doctor_id', doctorId);
          formData.append('reason', reason);
          formData.append('csrf_token', csrfToken);

          const resp = await fetch('api/request_teleconsult.php', {
            method: 'POST',
            body: formData
          });

          const data = await resp.json();

          if (data && data.success) {
            // Immediate zero-reload state swap to Virtual Waiting Room!
            if (data.session) {
              handleActiveSessionTelemetry(data.session);
            } else {
              // Trigger sync immediately
              await executeZeroReloadSync();
            }
          } else {
            alert(data.message || 'Unable to request consultation session.');
          }
        } catch (err) {
          console.error('Request session error:', err);
          alert('Network error connecting to Virtual Care Suite.');
        } finally {
          if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerHTML = `
              <svg style="width: 16px; height: 16px; stroke: #fff; fill: currentColor;" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
              <span>Request Live Consultation Session</span>
            `;
          }
        }
      };

      // Global Action: Cancel Queue Request
      window.cancelActiveQueue = async function(appId) {
        if (!confirm('Are you sure you want to cancel and exit your position in the consultation queue?')) {
          return;
        }

        const csrfToken = document.getElementById('pageCsrfToken').value;

        try {
          const formData = new FormData();
          formData.append('appointment_id', appId);
          formData.append('csrf_token', csrfToken);

          const resp = await fetch('api/cancel_teleconsult.php', {
            method: 'POST',
            body: formData
          });

          const data = await resp.json();
          if (data && data.success) {
            stopTitleFlash();
            hasChimedForCurrentTurn = false;
            document.getElementById('viewWaitingRoom').style.display = 'none';
            document.getElementById('viewDoctorSelection').style.display = 'block';
            await executeZeroReloadSync();
          } else {
            alert(data.message || 'Failed to cancel queue request.');
          }
        } catch (e) {
          console.error(e);
          alert('Error connecting to cancellation service.');
        }
      };

      function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
          return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
        });
      }

      // Initialize Zero-Reload Synchronization Poller
      pollTimer = setInterval(executeZeroReloadSync, pollInterval);

      // Perform an immediate initial sync check
      setTimeout(executeZeroReloadSync, 1000);

    })();
  </script>

</body>
</html>

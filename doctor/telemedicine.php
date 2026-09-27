<?php
/**
 * MedPulse Doctor Portal — Virtual Care Suite Chamber Console
 * Host 24/7 Live Tele-Consultations, Manage Queue Progression & Call Next Patient
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';
require_once __DIR__ . '/../controllers/TelemedicineController.php';

$doctorUserId = (int)$_SESSION['user_id'];

// Ensure columns exist safely
TelemedicineController::ensureSchema($pdo);

// Fetch Doctor Profile with Affiliated Hospital Branch
try {
    $docStmt = $pdo->prepare("
        SELECT u.full_name, u.email, u.phone,
               dp.specialty, dp.designation, dp.military_rank, dp.qualifications,
               dp.bmdc_license_number, dp.room_number, dp.teleconsult_link, dp.teleconsult_room_code,
               COALESCE(dp.session_status, 'idle') AS session_status,
               COALESCE(dp.current_serving_token, 0) AS current_serving_token,
               COALESCE(h.name, 'MedPulse Hospital & Specialty Care') AS hospital_name,
               COALESCE(h.city, 'Dhaka') AS hospital_city
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        LEFT JOIN doctors d ON u.user_id = d.user_id
        LEFT JOIN hospitals h ON (dp.hospital_id = h.hospital_id OR d.hospital_id = h.hospital_id OR u.hospital_id = h.hospital_id)
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $docStmt->execute([$doctorUserId]);
    $doctor = $docStmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $doctor = null;
}

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? 'Doctor');
$displayName = formatDoctorTitle($cleanName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Medicine & Critical Care', ENT_QUOTES, 'UTF-8');
$rawHospitalName = html_entity_decode((string)($doctor['hospital_name'] ?? 'MedPulse Hospital & Specialty Care'), ENT_QUOTES, 'UTF-8');
$hospitalName = htmlspecialchars($rawHospitalName, ENT_QUOTES, 'UTF-8');
$hospitalCity = htmlspecialchars(html_entity_decode((string)($doctor['hospital_city'] ?? 'Dhaka'), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');

$defaultMeeting = TelemedicineController::buildDefaultMeetingUrl($doctorUserId);
$meetingLink = $doctor['teleconsult_link'] ?: $defaultMeeting['url'];
$roomCode = $doctor['teleconsult_room_code'] ?: $defaultMeeting['room_code'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Virtual Care Suite &middot; Chamber Host Console &middot; MedPulse</title>
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    .vc-doc-banner {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0369a1 100%);
      border-radius: 20px;
      padding: 2.2rem 2.5rem;
      color: #ffffff;
      margin-bottom: 2rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 30px -10px rgba(2, 132, 199, 0.25);
    }
    .vc-badge-live {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      background: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(52, 211, 153, 0.3);
      color: #34d399;
      margin-bottom: 0.75rem;
    }
    .vc-pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      display: inline-block;
      animation: vcDotPulse 1.4s infinite alternate;
    }
    @keyframes vcDotPulse {
      0% { opacity: 0.4; transform: scale(0.9); }
      100% { opacity: 1; transform: scale(1.2); }
    }

    /* Modern 4-Column Telemetry Stat Cards Grid */
    .vc-telemetry-grid {
      display: grid;
      grid-template-columns: repeat(1, minmax(0, 1fr));
      gap: 1rem;
      margin-top: 1.5rem;
    }
    @media (min-width: 640px) {
      .vc-telemetry-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (min-width: 1024px) {
      .vc-telemetry-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
    }
    .vc-stat-card {
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 14px;
      padding: 0.85rem 1rem;
      display: flex;
      align-items: center;
      gap: 0.85rem;
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition: all 0.2s ease;
      min-width: 0;
    }
    .vc-stat-card:hover {
      background: rgba(255, 255, 255, 0.09);
      border-color: rgba(255, 255, 255, 0.22);
      transform: translateY(-1px);
    }
    .vc-stat-icon-wrap {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .vc-stat-icon-wrap.facility {
      background: rgba(56, 189, 248, 0.15);
      border: 1px solid rgba(56, 189, 248, 0.3);
      color: #38bdf8;
    }
    .vc-stat-icon-wrap.room {
      background: rgba(129, 140, 248, 0.15);
      border: 1px solid rgba(129, 140, 248, 0.3);
      color: #818cf8;
    }
    .vc-stat-icon-wrap.status {
      background: rgba(52, 211, 153, 0.15);
      border: 1px solid rgba(52, 211, 153, 0.3);
      color: #34d399;
    }
    .vc-stat-icon-wrap.queue {
      background: rgba(251, 191, 36, 0.15);
      border: 1px solid rgba(251, 191, 36, 0.3);
      color: #fbbf24;
    }
    .vc-stat-content {
      min-width: 0;
      flex: 1;
    }
    .vc-stat-label {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: rgba(255, 255, 255, 0.65);
      margin-bottom: 2px;
      display: block;
    }
    .vc-stat-value {
      font-size: 0.88rem;
      font-weight: 700;
      color: #ffffff;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
    }
    .vc-stat-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.82rem;
      font-weight: 800;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.2);
      padding: 2px 8px;
      border-radius: 6px;
      color: #f8fafc;
      letter-spacing: 0.04em;
    }
    .vc-status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.78rem;
      font-weight: 700;
      color: #34d399;
    }
    .vc-queue-highlight {
      font-size: 1.05rem;
      font-weight: 800;
      color: #fbbf24;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .vc-host-card {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 18px;
      padding: 1.75rem;
      box-shadow: 0 4px 15px -3px rgba(0, 0, 0, 0.04);
      margin-bottom: 1.5rem;
    }
    .vc-serving-box {
      border: 2px solid #2563eb;
      background: linear-gradient(180deg, #f0f7ff 0%, #ffffff 100%);
      border-radius: 16px;
      padding: 1.75rem;
      margin-bottom: 1.5rem;
      position: relative;
    }
    .btn-call-next {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #ffffff;
      border: none;
      padding: 0.95rem 1.6rem;
      font-size: 1rem;
      font-weight: 800;
      border-radius: 12px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 4px 16px rgba(16, 185, 129, 0.35);
      transition: all 0.2s ease;
    }
    .btn-call-next:hover {
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
    }
    .btn-launch-host {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      color: #ffffff;
      border: none;
      padding: 0.95rem 1.6rem;
      font-size: 1rem;
      font-weight: 800;
      border-radius: 12px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 4px 16px rgba(37, 99, 235, 0.35);
      transition: all 0.2s ease;
    }
    .btn-launch-host:hover {
      background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
      transform: translateY(-2px);
    }
    .btn-complete-consult {
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #cbd5e1;
      padding: 0.85rem 1.25rem;
      font-size: 0.85rem;
      font-weight: 700;
      border-radius: 10px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
    }
    .btn-complete-consult:hover {
      background: #e2e8f0;
      color: #0f172a;
    }
    .vc-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.85rem;
    }
    .vc-table th {
      padding: 0.85rem 1rem;
      background: #f8fafc;
      color: #64748b;
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 1px solid #e2e8f0;
    }
    .vc-table td {
      padding: 1rem;
      border-bottom: 1px solid #f1f5f9;
      vertical-align: middle;
    }
  </style>
</head>
<body>

  <!-- Shared Doctor Sidebar -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <!-- Main Viewport -->
  <main class="viewport-full">
    <!-- Top Hero Banner -->
    <div class="vc-doc-banner">
      <div style="position: relative; z-index: 1;">
        <div class="vc-badge-live">
          <span class="vc-pulse-dot"></span>
          24/7 VIRTUAL CARE SUITE &bull; CHAMBER HOST CONSOLE
        </div>
        <h1 style="font-size: 1.85rem; font-weight: 800; margin: 0 0 0.5rem; letter-spacing: -0.02em;">
          Live Tele-Consultation Chamber
        </h1>
        <p style="font-size: 0.95rem; margin: 0; opacity: 0.9; max-width: 680px; line-height: 1.5;">
          Host encrypted HD video consultations for <?= htmlspecialchars($displayName) ?>. Call queued patients sequentially and advance tokens with zero-reload synchronization.
        </p>

        <!-- 4-Column Stat Cards Grid -->
        <div class="vc-telemetry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

          <!-- Card 1: Branch Facility -->
          <div class="vc-stat-card">
            <div class="vc-stat-icon-wrap facility">
              <svg style="width: 20px; height: 20px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><path d="M3 21h18M9 8h1M9 12h1M9 16h1M14 8h1M14 12h1M14 16h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
            </div>
            <div class="vc-stat-content">
              <span class="vc-stat-label">Branch Facility</span>
              <strong class="vc-stat-value" title="<?= $hospitalName ?>"><?= $hospitalName ?></strong>
            </div>
          </div>

          <!-- Card 2: Room Code -->
          <div class="vc-stat-card">
            <div class="vc-stat-icon-wrap room">
              <svg style="width: 20px; height: 20px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            </div>
            <div class="vc-stat-content">
              <span class="vc-stat-label">Room Code</span>
              <div>
                <span class="vc-stat-pill" id="bannerRoomCode"><?= htmlspecialchars($roomCode) ?></span>
              </div>
            </div>
          </div>

          <!-- Card 3: Room Status -->
          <div class="vc-stat-card">
            <div class="vc-stat-icon-wrap status">
              <svg style="width: 20px; height: 20px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div class="vc-stat-content">
              <span class="vc-stat-label">Room Status</span>
              <div class="vc-status-badge" id="bannerRoomStatus">
                <span class="vc-pulse-dot"></span>
                <span>Active &bull; Accepting</span>
              </div>
            </div>
          </div>

          <!-- Card 4: Queue Count -->
          <div class="vc-stat-card">
            <div class="vc-stat-icon-wrap queue">
              <svg style="width: 20px; height: 20px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div class="vc-stat-content">
              <span class="vc-stat-label">Queue Count</span>
              <strong class="vc-queue-highlight" id="bannerWaitingCount">0 Waiting</strong>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Active In-Chamber Consultation Box -->
    <div class="vc-serving-box" id="activeServingBox">
      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
        <div>
          <span style="font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #2563eb; display: flex; align-items: center; gap: 6px;">
            <span class="vc-pulse-dot"></span> CURRENTLY IN LIVE CONSULTATION
          </span>
          <h2 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 4px 0 0;" id="activePatientName">
            No Patient in Consultation
          </h2>
          <p style="font-size: 0.85rem; color: #64748b; margin: 4px 0 0;" id="activePatientMeta">
            Chamber is idle. Click "Call Next Patient" below to admit the next token.
          </p>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
          <a href="<?= htmlspecialchars($meetingLink) ?>" target="_blank" rel="noopener noreferrer" class="btn-launch-host" id="btnLaunchHost">
            <svg style="width: 20px; height: 20px; stroke: #fff;" fill="none" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            <span>Launch Zoom as Host</span>
            <svg style="width: 14px; height: 14px; stroke: #fff;" fill="none" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
          </a>

          <button type="button" class="btn-call-next" id="btnCallNext" onclick="triggerCallNext();">
            <svg style="width: 20px; height: 20px; stroke: #fff;" fill="none" viewBox="0 0 24 24"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
            <span>Call Next Patient</span>
          </button>

          <button type="button" class="btn-complete-consult" id="btnCompleteSession" onclick="triggerCompleteSession();" style="display: none;">
            <svg style="width: 16px; height: 16px; stroke: currentColor;" fill="none" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Mark Complete</span>
          </button>
        </div>
      </div>

      <!-- Chief Complaint / Triage Strip -->
      <div id="activeComplaintStrip" style="display: none; background: #ffffff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 0.85rem 1rem; font-size: 0.82rem; color: #1e293b;">
        <strong>Chief Complaint:</strong> <span id="activeComplaintText">-</span>
      </div>
    </div>

    <!-- Live Tele-Consultation Queue & Meeting Config Split -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: start;">
      
      <!-- Left: Live Patient Waiting Queue -->
      <div class="vc-host-card">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
          <div>
            <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
              Live Patient Waiting Queue
            </h3>
            <p style="font-size: 0.82rem; color: #64748b; margin: 2px 0 0;">
              Patients currently in the Virtual Waiting Room awaiting consultation.
            </p>
          </div>
          <span style="font-size: 0.75rem; font-weight: 800; background: #eff6ff; color: #2563eb; padding: 4px 10px; border-radius: 999px;" id="queueCounterPill">
            0 Patients
          </span>
        </div>

        <div style="overflow-x: auto;">
          <table class="vc-table">
            <thead>
              <tr>
                <th>Token</th>
                <th>Patient Details</th>
                <th>Chief Complaint</th>
                <th>Queue Position</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="waitingQueueTbody">
              <tr>
                <td colspan="5" style="text-align: center; color: #94a3b8; padding: 2rem;">
                  Loading active queue...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Right: Doctor Meeting Link & Room Settings -->
      <div class="vc-host-card">
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem;">
          Chamber Meeting Settings
        </h3>
        <p style="font-size: 0.82rem; color: #64748b; margin: 0 0 1.25rem;">
          Customize your personal Zoom, Google Meet, or Webex room bridge link.
        </p>

        <form id="meetingConfigForm" onsubmit="saveMeetingConfig(event);">
          <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.76rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
              Active Video Bridge URL
            </label>
            <input type="url" id="inputMeetingLink" name="meeting_link" value="<?= htmlspecialchars($meetingLink) ?>" required placeholder="https://zoom.us/j/..." style="width: 100%; padding: 0.65rem 0.75rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 8px; font-family: monospace;">
            <span style="font-size: 0.7rem; color: #64748b; display: block; margin-top: 4px;">
              Patients are automatically directed to this link when their token is called.
            </span>
          </div>

          <div style="margin-bottom: 1.25rem;">
            <label style="display: block; font-size: 0.76rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
              Clinical Room ID
            </label>
            <input type="text" value="<?= htmlspecialchars($roomCode) ?>" readonly style="width: 100%; padding: 0.65rem 0.75rem; font-size: 0.85rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-family: monospace; color: #475569;">
          </div>

          <button type="submit" id="btnSaveConfig" style="width: 100%; background: #0f172a; color: #ffffff; border: none; padding: 0.75rem; font-size: 0.88rem; font-weight: 700; border-radius: 8px; cursor: pointer; transition: background 0.2s;">
            Save Meeting Link
          </button>
        </form>

        <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px dashed #e2e8f0; font-size: 0.78rem; color: #475569;">
          <strong style="color: #0f172a; display: block; margin-bottom: 4px;">Privacy Lock Mechanism:</strong>
          Patients waiting in the queue cannot join this link until you click <strong>"Call Next Patient"</strong>. Once called, their button unlocks immediately.
        </div>
      </div>

    </div>
  </main>

  <script>
    let pollTimer = null;

    async function pollQueueData() {
      try {
        const resp = await fetch('api/telemedicine_actions.php?action=get_queue');
        const data = await resp.json();
        if (data && data.success) {
          updateConsoleUI(data);
        }
      } catch (e) {
        console.error('Queue poll error:', e);
      }
    }

    function updateConsoleUI(data) {
      // 1. Update waiting count
      const count = data.waiting_count || 0;
      document.getElementById('bannerWaitingCount').textContent = `${count} Waiting`;
      document.getElementById('queueCounterPill').textContent = `${count} Patients`;

      // 2. Update active patient box
      const activePatient = data.active_patient;
      const nameEl = document.getElementById('activePatientName');
      const metaEl = document.getElementById('activePatientMeta');
      const stripEl = document.getElementById('activeComplaintStrip');
      const complaintText = document.getElementById('activeComplaintText');
      const completeBtn = document.getElementById('btnCompleteSession');

      if (activePatient) {
        nameEl.textContent = `Token #${activePatient.token_number} — ${activePatient.patient_name}`;
        metaEl.innerHTML = `<strong>Phone:</strong> ${activePatient.phone || 'N/A'} &bull; <strong>Gender:</strong> ${activePatient.gender || 'N/A'} &bull; <span style="color:#059669; font-weight:700;">In Room Consultation</span>`;
        if (activePatient.reason_for_visit || activePatient.symptoms) {
          stripEl.style.display = 'block';
          complaintText.textContent = activePatient.reason_for_visit || activePatient.symptoms;
        } else {
          stripEl.style.display = 'none';
        }
        completeBtn.style.display = 'inline-flex';
      } else {
        nameEl.textContent = 'No Patient in Consultation';
        metaEl.textContent = count > 0 
          ? `${count} patient(s) waiting in queue. Click "Call Next Patient" to admit.`
          : 'Chamber is idle. Waiting for patient queue requests.';
        stripEl.style.display = 'none';
        completeBtn.style.display = 'none';
      }

      // 3. Render waiting patients table
      const tbody = document.getElementById('waitingQueueTbody');
      const waiting = data.waiting_patients || [];
      if (waiting.length === 0) {
        tbody.innerHTML = `
          <tr>
            <td colspan="5" style="text-align: center; color: #94a3b8; padding: 2rem;">
              No patients currently waiting in queue.
            </td>
          </tr>
        `;
      } else {
        tbody.innerHTML = waiting.map((p, idx) => `
          <tr>
            <td>
              <span style="font-weight: 800; color: #2563eb; background: #eff6ff; padding: 4px 8px; border-radius: 6px; font-size: 0.82rem;">
                Token #${p.token_number}
              </span>
            </td>
            <td>
              <strong style="color: #0f172a; display: block;">${escapeHtml(p.patient_name)}</strong>
              <span style="font-size: 0.74rem; color: #64748b;">${escapeHtml(p.phone || '')} &bull; ${escapeHtml(p.gender || '')}</span>
            </td>
            <td>
              <span style="color: #334155; font-size: 0.8rem;">
                ${escapeHtml(p.reason_for_visit || p.symptoms || 'General Tele-Consultation')}
              </span>
            </td>
            <td>
              <span style="color: #64748b; font-size: 0.78rem;">
                ${idx === 0 ? '<strong style="color: #059669;">Next in Line</strong>' : (idx + 1) + ' in Queue'}
              </span>
            </td>
            <td>
              <span style="font-size: 0.7rem; font-weight: 800; padding: 3px 8px; border-radius: 999px; background: #fef3c7; color: #b45309;">
                WAITING
              </span>
            </td>
          </tr>
        `).join('');
      }
    }

    async function triggerCallNext() {
      const btn = document.getElementById('btnCallNext');
      btn.disabled = true;
      btn.style.opacity = '0.7';

      try {
        const formData = new FormData();
        formData.append('action', 'call_next');
        const resp = await fetch('api/telemedicine_actions.php', { method: 'POST', body: formData });
        const res = await resp.json();
        if (res && res.success) {
          await pollQueueData();
        } else {
          alert(res.message || 'Unable to advance queue.');
        }
      } catch (e) {
        console.error(e);
        alert('Network error calling next patient.');
      } finally {
        btn.disabled = false;
        btn.style.opacity = '1';
      }
    }

    async function triggerCompleteSession() {
      const btn = document.getElementById('btnCompleteSession');
      btn.disabled = true;

      try {
        const formData = new FormData();
        formData.append('action', 'complete_session');
        const resp = await fetch('api/telemedicine_actions.php', { method: 'POST', body: formData });
        const res = await resp.json();
        if (res && res.success) {
          await pollQueueData();
        }
      } catch (e) {
        console.error(e);
      } finally {
        btn.disabled = false;
      }
    }

    async function saveMeetingConfig(e) {
      e.preventDefault();
      const form = document.getElementById('meetingConfigForm');
      const btn = document.getElementById('btnSaveConfig');
      const formData = new FormData(form);
      formData.append('action', 'update_link');

      btn.disabled = true;
      btn.textContent = 'Saving...';

      try {
        const resp = await fetch('api/telemedicine_actions.php', { method: 'POST', body: formData });
        const res = await resp.json();
        if (res && res.success) {
          alert('Consultation room meeting link updated!');
          const link = document.getElementById('inputMeetingLink').value;
          document.getElementById('btnLaunchHost').href = link;
        } else {
          alert(res.message || 'Error updating link.');
        }
      } catch (e) {
        alert('Failed to save meeting link.');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Save Meeting Link';
      }
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/[&<>"']/g, function(m) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
      });
    }

    // Start Real-Time Zero-Reload Polling every 3.5 seconds
    pollQueueData();
    pollTimer = setInterval(pollQueueData, 3500);
  </script>
</body>
</html>

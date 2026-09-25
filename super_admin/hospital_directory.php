<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — Facility Command & Multi-Hospital Directory
 *
 * Implements:
 * 1. Synchronized Header System Telemetry Monitor (Normal: 72 BPM emerald, Surge: 118 BPM crimson)
 * 2. Interactive Facility Cards with hover micro-lift and 1-click drilldown to bed_monitor.php?hospital_id={id}
 * 3. Authentic SVG Medical Crests for each facility (MedPulse, Square, United, UMCH, Evercare, NIBPS)
 * 4. Mathematically accurate database topology bed breakdowns (Total, Available, ICU Occ %, Occupancy bar)
 * 5. Functional status management with MedPulseDialog themed modals and bubble-isolated controls
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../backend/Services/EmergencyProtocolService.php';

use MedPulse\Services\EmergencyProtocolService;

// Emergency Surge Telemetry State
$emergencyService = new EmergencyProtocolService($pdo);
$activeProtocols  = $emergencyService->getActiveProtocols();
$activeCount      = count($activeProtocols);

// ── AJAX: Toggle hospital operational status ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Session may have expired.']);
        exit;
    }

    if ($_POST['_action'] === 'toggle_status') {
        $hid   = (int)($_POST['hospital_id'] ?? 0);
        $newSt = $_POST['new_status'] ?? '';
        if (!in_array($newSt, ['Active', 'Suspended', 'Maintenance'], true) || $hid < 1) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters provided.']);
            exit;
        }

        // Hospital 1 is the primary apex flagship and cannot be suspended
        if ($hid === 1 && $newSt === 'Suspended') {
            echo json_encode(['success' => false, 'message' => 'MedPulse Primary Flagship is protected and cannot be suspended.']);
            exit;
        }

        try {
            $pdo->prepare("UPDATE hospitals SET operational_status = ? WHERE hospital_id = ?")->execute([$newSt, $hid]);
            $pdo->prepare("
                INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES (?, 'super_admin', 'HOSPITAL_STATUS', ?, 'ADMIN', 'Hospital Status Transition', ?, ?, 'HIGH')
            ")->execute([
                (int)$_SESSION['user_id'],
                "Facility #{$hid} operational status transitioned to: {$newSt}",
                "hospital_id:{$hid}",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            echo json_encode(['success' => true, 'message' => "Facility #{$hid} operational status transitioned to: {$newSt}", 'new_status' => $newSt]);
        } catch (PDOException $e) {
            error_log('Hospital status transition error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error executing status change.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── GET: Query Accurate Database Topology Metrics ─────────────────────────────
try {
    $hospitals = $pdo->query("
        SELECT h.*,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id) AS total_beds,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id AND status = 'Available') AS avail_beds,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id AND status = 'Occupied') AS occ_beds,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id AND status = 'Maintenance') AS maint_beds,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id AND status = 'Emergency Hold') AS held_beds,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id AND (ward_type LIKE '%ICU%' OR ward_type LIKE '%HDU%' OR ward_type = 'CCU')) AS icu_beds,
               (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = h.hospital_id AND (ward_type LIKE '%ICU%' OR ward_type LIKE '%HDU%' OR ward_type = 'CCU') AND status = 'Occupied') AS icu_occ,
               (SELECT COALESCE(MAX(floor_number), 5) FROM hospital_beds WHERE hospital_id = h.hospital_id) AS max_floor,
               (SELECT COUNT(DISTINCT doctor_id) FROM doctor_profiles WHERE hospital_id = h.hospital_id) AS doctor_count,
               (SELECT full_name FROM users WHERE hospital_id = h.hospital_id AND role = 'Admin' ORDER BY user_id ASC LIMIT 1) AS admin_name,
               (SELECT email FROM users WHERE hospital_id = h.hospital_id AND role = 'Admin' ORDER BY user_id ASC LIMIT 1) AS admin_email
        FROM hospitals h
        ORDER BY h.hospital_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Network Aggregates
    $netTotalBeds = 0;
    $netAvailBeds = 0;
    $netOccBeds   = 0;
    $netHeldBeds  = 0;
    $netMaintBeds = 0;
    foreach ($hospitals as $h) {
        $netTotalBeds += (int)($h['total_beds'] ?? 0);
        $netAvailBeds += (int)($h['avail_beds'] ?? 0);
        $netOccBeds   += (int)($h['occ_beds'] ?? 0);
        $netHeldBeds  += (int)($h['held_beds'] ?? 0);
        $netMaintBeds += (int)($h['maint_beds'] ?? 0);
    }
    $netOccPct = $netTotalBeds > 0 ? round(($netOccBeds / $netTotalBeds) * 100) : 0;

} catch (PDOException $e) {
    error_log('Hospital directory fetch error: ' . $e->getMessage());
    $hospitals = [];
    $netTotalBeds = $netAvailBeds = $netOccBeds = $netHeldBeds = $netMaintBeds = $netOccPct = 0;
}

// ── Medical Crest Helper ──────────────────────────────────────────────────────
if (!function_exists('getHospitalCrest')) {
    function getHospitalCrest(int $hospitalId, string $name = ''): string {
        switch ($hospitalId) {
            case 1:
                // MedPulse: Modern pulse cross with vibrant teal/cyan gradient
                return '<div class="hosp-crest-avatar hosp-crest-medpulse" title="MedPulse Hospital & Specialty Care">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestMedPulseDir)"/>
                    <defs>
                      <linearGradient id="crestMedPulseDir" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#0d9488"/>
                        <stop offset="1" stop-color="#06b6d4"/>
                      </linearGradient>
                    </defs>
                    <path d="M15 8h6v7h7v6h-7v7h-6v-7H8v-6h7V8z" fill="rgba(255,255,255,0.22)"/>
                    <path d="M6 18h7l2-5 3 10 3-7 2 3h7" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </div>';
            case 2:
                // Square Hospital: Signature geometric medical cross
                return '<div class="hosp-crest-avatar hosp-crest-square" title="Square Hospital Ltd">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestSquareDir)"/>
                    <defs>
                      <linearGradient id="crestSquareDir" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#e11d48"/>
                        <stop offset="1" stop-color="#9f1239"/>
                      </linearGradient>
                    </defs>
                    <rect x="7" y="7" width="22" height="22" rx="4" stroke="rgba(255,255,255,0.35)" stroke-width="1.5" fill="none"/>
                    <path d="M15 10h6v5h5v6h-5v5h-6v-5h-5v-6h5v-5z" fill="#ffffff"/>
                  </svg>
                </div>';
            case 3:
                // United Hospital: Elegant shield-and-cross
                return '<div class="hosp-crest-avatar hosp-crest-united" title="United Hospital Ltd">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestUnitedDir)"/>
                    <defs>
                      <linearGradient id="crestUnitedDir" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#1d4ed8"/>
                        <stop offset="1" stop-color="#0284c7"/>
                      </linearGradient>
                    </defs>
                    <path d="M18 7L8 11v7c0 6.6 4.3 12.3 10 14 5.7-1.7 10-7.4 10-14v-7L18 7z" fill="rgba(255,255,255,0.18)" stroke="#ffffff" stroke-width="1.4"/>
                    <path d="M16 13h4v4h4v4h-4v4h-4v-4h-4v-4h4v-4z" fill="#ffffff"/>
                  </svg>
                </div>';
            case 4:
                // UMCH: Academic caduceus / medical graduation crest
                return '<div class="hosp-crest-avatar hosp-crest-umch" title="United Medical College Hospital">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestUmchDir)"/>
                    <defs>
                      <linearGradient id="crestUmchDir" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#6366f1"/>
                        <stop offset="1" stop-color="#8b5cf6"/>
                      </linearGradient>
                    </defs>
                    <line x1="18" y1="8" x2="18" y2="28" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round"/>
                    <circle cx="18" cy="8" r="2.2" fill="#fde047"/>
                    <path d="M12 14c4-2 8-2 12 0-4 3-8 3-12 0zM12 21c4-2 8-2 12 0-4 3-8 3-12 0z" stroke="#ffffff" stroke-width="1.6" fill="none" stroke-linecap="round"/>
                    <path d="M10 11l8-4 8 4-8 4-8-4z" fill="rgba(253,224,71,0.3)"/>
                  </svg>
                </div>';
            case 5:
                // Evercare: Contemporary care heart-loop
                return '<div class="hosp-crest-avatar hosp-crest-evercare" title="Evercare Hospital Dhaka">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestEvercareDir)"/>
                    <defs>
                      <linearGradient id="crestEvercareDir" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#059669"/>
                        <stop offset="1" stop-color="#10b981"/>
                      </linearGradient>
                    </defs>
                    <path d="M18 28s-9-5.4-9-12a5.5 5.5 0 0 1 9-4.2A5.5 5.5 0 0 1 27 16c0 6.6-9 12-9 12z" stroke="#ffffff" stroke-width="1.8" fill="rgba(255,255,255,0.15)"/>
                    <path d="M16 14h4v3h3v4h-3v3h-4v-3h-3v-4h3v-3z" fill="#ffffff"/>
                  </svg>
                </div>';
            case 6:
                // NIBPS: National burn phoenix/shield crest
                return '<div class="hosp-crest-avatar hosp-crest-nibps" title="National Institute of Burn and Plastic Surgery">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestNibpsDir)"/>
                    <defs>
                      <linearGradient id="crestNibpsDir" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#ea580c"/>
                        <stop offset="1" stop-color="#dc2626"/>
                      </linearGradient>
                    </defs>
                    <path d="M18 7c3 4 5 7 5 10 0 4-3 7-5 7s-5-3-5-7c0-3 2-6 5-10z" fill="rgba(254,240,138,0.4)" stroke="#fef08a" stroke-width="1.3"/>
                    <path d="M16 16h4v3h3v3h-3v3h-4v-3h-3v-3h3v-3z" fill="#ffffff"/>
                    <path d="M8 18c2 5 6 9 10 11 4-2 8-6 10-11" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round" fill="none"/>
                  </svg>
                </div>';
            default:
                $initials = strtoupper(substr($name ?: 'HP', 0, 2));
                return '<div class="hosp-crest-avatar hosp-crest-default" style="background:var(--sa-grad);">
                  <span style="font-size:0.82rem;font-weight:800;color:#fff;">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</span>
                </div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Facility Command &amp; Hospital Directory</title>
  <meta name="description" content="MedPulse Super Admin Facility Command Center — multi-hospital network directory, bed monitor drill-down, and operational governance.">

  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%237c3aed'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><polygon points='32,8 38,22 54,24 42,36 45,52 32,44 19,52 22,36 10,24 26,22' fill='rgba(255,255,255,0.9)'/></svg>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Design System -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">

  <style>
    :root {
      --sa-accent:        #7c3aed;
      --sa-accent-soft:   rgba(124, 58, 237, 0.10);
      --sa-accent-border: rgba(124, 58, 237, 0.22);
      --sa-grad:          linear-gradient(135deg, #7c3aed 0%, #0d9488 100%);
    }

    .sa-welcome-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      background: var(--sa-grad);
      color: #fff;
      border-radius: 20px;
      font-size: .72rem;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    /* Welcome Header Layout */
    .welcome-banner {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 16px;
      margin-bottom: 20px;
    }
    .welcome-text {
      flex: 1;
      min-width: 280px;
    }
    .welcome-text h1 {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--text-heading);
      letter-spacing: -0.02em;
      margin: 4px 0 6px;
    }
    .welcome-text p {
      font-size: 0.86rem;
      color: var(--text-muted);
      line-height: 1.5;
      max-width: 680px;
    }
    .banner-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    /* Header Live Telemetry Pill */
    .sa-telemetry-badge {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 7px 16px;
      border-radius: 40px;
      font-size: 0.74rem;
      font-weight: 700;
      backdrop-filter: blur(8px);
      transition: all 0.25s ease;
      white-space: nowrap;
    }
    .sa-telemetry-badge.telemetry-normal {
      background: rgba(16, 185, 129, 0.08);
      border: 1px solid rgba(16, 185, 129, 0.32);
      color: #065f46;
      box-shadow: 0 1px 4px rgba(16, 185, 129, 0.1);
    }
    .sa-telemetry-badge.telemetry-surge {
      background: linear-gradient(135deg, rgba(244, 63, 94, 0.14) 0%, rgba(225, 29, 72, 0.22) 100%);
      border: 1.5px solid rgba(244, 63, 94, 0.6);
      color: #9f1239;
      box-shadow: 0 0 16px rgba(244, 63, 94, 0.22);
    }
    .ecg-track {
      width: 52px;
      height: 18px;
      display: flex;
      align-items: center;
    }
    .ecg-svg {
      width: 52px;
      height: 18px;
      overflow: visible;
    }
    .ecg-pulse-line {
      fill: none;
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-linejoin: round;
      stroke-dasharray: 80;
      stroke-dashoffset: 80;
    }
    .telemetry-normal .ecg-pulse-line {
      stroke: #10b981;
      animation: ecgSweepDir 2.2s linear infinite;
    }
    .telemetry-surge .ecg-pulse-line {
      stroke: #f43f5e;
      animation: ecgSweepDir 1.1s linear infinite;
    }
    @keyframes ecgSweepDir {
      0% { stroke-dashoffset: 80; }
      50% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -80; }
    }
    .telemetry-info {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .telemetry-bpm {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-weight: 800;
      letter-spacing: -0.01em;
    }
    .telemetry-normal .telemetry-bpm {
      color: #059669;
    }
    .telemetry-surge .telemetry-bpm {
      color: #e11d48;
      animation: bpmSurgePulseDir 0.9s ease-in-out infinite;
    }
    @keyframes bpmSurgePulseDir {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.75; transform: scale(1.06); }
    }
    .telemetry-sep {
      opacity: 0.4;
      font-size: 0.65rem;
    }
    .telemetry-status {
      letter-spacing: 0.04em;
      text-transform: uppercase;
      font-size: 0.70rem;
    }
    .telemetry-pulse-dot {
      position: relative;
      display: inline-flex;
      width: 8px;
      height: 8px;
    }
    .telemetry-pulse-ring {
      position: absolute;
      inset: 0;
      border-radius: 50%;
    }
    .telemetry-normal .telemetry-pulse-ring {
      background: #10b981;
      animation: telRingDir 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .telemetry-surge .telemetry-pulse-ring {
      background: #f43f5e;
      animation: telRingDir 0.9s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .telemetry-pulse-core {
      position: relative;
      width: 8px;
      height: 8px;
      border-radius: 50%;
    }
    .telemetry-normal .telemetry-pulse-core {
      background: #059669;
    }
    .telemetry-surge .telemetry-pulse-core {
      background: #e11d48;
    }
    @keyframes telRingDir {
      75%, 100% {
        transform: scale(2.6);
        opacity: 0;
      }
    }
    @keyframes saPulseDir {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.15); opacity: 0.8; }
    }

    /* Executive Topbar Action Button */
    .btn-header-cta {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 8px 16px;
      background: var(--surface);
      border: 1.5px solid var(--surface-border);
      border-radius: 10px;
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-heading);
      text-decoration: none;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    .btn-header-cta:hover {
      border-color: var(--sa-accent);
      color: var(--sa-accent);
      background: var(--sa-accent-soft);
      transform: translateY(-1px);
    }

    /* Network Executive Rollup Strip */
    .net-summary-strip {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: 22px;
    }
    .net-stat-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 14px;
      padding: 14px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
      transition: border-color 0.2s;
    }
    .net-stat-card:hover {
      border-color: var(--sa-accent-border);
    }
    .net-stat-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .net-stat-icon svg {
      width: 19px;
      height: 19px;
      stroke-width: 2.2;
    }
    .net-stat-val {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.1;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .net-stat-lbl {
      font-size: 0.68rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-top: 2px;
    }

    /* Hospital Cards Grid */
    .hosp-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(410px, 1fr));
      gap: 20px;
      margin-top: 6px;
    }
    @media (max-width: 480px) {
      .hosp-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Interactive Facility Card with Hover Micro-Lift */
    .hosp-card {
      background: var(--surface);
      border: 1.5px solid var(--surface-border);
      border-radius: var(--radius-xl);
      padding: 22px 22px 18px;
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.22s ease;
      overflow: hidden;
    }
    .hosp-card-interactive {
      cursor: pointer;
    }
    .hosp-card-interactive:hover {
      transform: translateY(-6px);
      box-shadow: 0 20px 32px -8px rgba(0, 0, 0, 0.12), 0 8px 16px -4px rgba(0, 0, 0, 0.06);
      border-color: rgba(124, 58, 237, 0.45);
    }
    .hosp-card.status-suspended {
      border-color: rgba(239, 68, 68, 0.35);
      background: #fef2f2;
    }
    .hosp-card.status-suspended:hover {
      border-color: rgba(239, 68, 68, 0.65);
      box-shadow: 0 20px 32px -8px rgba(239, 68, 68, 0.18);
    }
    .hosp-card.status-maintenance {
      border-color: rgba(217, 119, 6, 0.35);
      background: #fef3c7;
    }
    .hosp-card.status-maintenance:hover {
      border-color: rgba(217, 119, 6, 0.65);
      box-shadow: 0 20px 32px -8px rgba(217, 119, 6, 0.18);
    }

    /* Card Header & Crest */
    .hc-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
    }
    .hosp-crest-avatar {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08);
      overflow: hidden;
      transition: transform 0.2s ease;
    }
    .hosp-card:hover .hosp-crest-avatar {
      transform: scale(1.06);
    }
    .hosp-crest-avatar svg {
      width: 100%;
      height: 100%;
      display: block;
      border-radius: 12px;
    }

    .hc-meta {
      flex: 1;
      min-width: 0;
    }
    .hc-name-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .hc-name {
      font-size: 0.96rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.3;
    }
    .hc-code {
      font-size: 0.68rem;
      font-weight: 800;
      color: var(--sa-accent);
      background: var(--sa-accent-soft);
      padding: 2px 7px;
      border-radius: 6px;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      border: 1px solid var(--sa-accent-border);
      display: inline-block;
    }
    .hc-city {
      font-size: 0.74rem;
      color: var(--text-muted);
      margin-top: 3px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Status Pills */
    .hc-status-group {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
      flex-shrink: 0;
    }
    .status-pill {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.69rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .sp-active {
      background: rgba(5, 150, 105, 0.12);
      color: var(--status-green);
      border: 1px solid rgba(5, 150, 105, 0.25);
    }
    .sp-suspended {
      background: rgba(239, 68, 68, 0.12);
      color: var(--status-red);
      border: 1px solid rgba(239, 68, 68, 0.25);
    }
    .sp-maintenance {
      background: rgba(217, 119, 6, 0.12);
      color: var(--status-amber);
      border: 1px solid rgba(217, 119, 6, 0.25);
    }
    .card-drill-hint {
      font-size: 0.68rem;
      font-weight: 700;
      color: var(--sa-accent);
      display: inline-flex;
      align-items: center;
      gap: 3px;
      opacity: 0.85;
      transition: all 0.2s ease;
    }
    .hosp-card:hover .card-drill-hint {
      opacity: 1;
      transform: translateX(3px);
    }

    /* Card Metrics Grid */
    .hc-metrics {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      margin: 14px 0 12px;
    }
    .hc-metric {
      text-align: center;
      padding: 10px 8px;
      background: var(--surface-secondary);
      border-radius: 10px;
      border: 1px solid var(--surface-border-subtle);
      transition: background 0.2s ease;
    }
    .hosp-card:hover .hc-metric {
      background: #f8fafc;
    }
    .hc-metric-val {
      font-size: 1.12rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.1;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
    .hc-metric-key {
      font-size: 0.66rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-top: 4px;
    }

    /* Occupancy Bar Track */
    .hc-bar-track {
      height: 7px;
      background: var(--surface-border);
      border-radius: 99px;
      overflow: hidden;
      margin: 4px 0 12px;
    }
    .hc-bar-fill {
      height: 100%;
      border-radius: 99px;
      background: var(--sa-grad);
      transition: width 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    /* Held Surge Alert Strip */
    .hc-surge-held {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 7px 10px;
      background: linear-gradient(135deg, rgba(225, 29, 72, 0.08) 0%, rgba(244, 63, 94, 0.12) 100%);
      border: 1px solid rgba(225, 29, 72, 0.3);
      border-radius: 9px;
      margin-bottom: 12px;
      font-size: 0.72rem;
      font-weight: 700;
      color: #9f1239;
    }

    /* Admin Info Box */
    .hc-admin {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 12px;
      background: var(--surface-secondary);
      border-radius: 10px;
      border: 1px solid var(--surface-border-subtle);
      margin-bottom: 14px;
    }
    .hc-admin-icon {
      width: 30px;
      height: 30px;
      border-radius: 8px;
      background: var(--sa-accent-soft);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.78rem;
      font-weight: 800;
      color: var(--sa-accent);
      flex-shrink: 0;
    }
    .hc-admin-name {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-heading);
      line-height: 1.2;
    }
    .hc-admin-email {
      font-size: 0.68rem;
      color: var(--text-muted);
      margin-top: 1px;
    }

    /* Card Footer & Isolated Action Buttons */
    .hc-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-top: 12px;
      border-top: 1px solid var(--surface-border);
      gap: 8px;
      flex-wrap: wrap;
    }
    .hc-floors {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-muted);
    }
    .hc-ctrl-btns {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }
    .hc-btn {
      padding: 5px 12px;
      border-radius: 8px;
      font-size: 0.72rem;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      border: 1.5px solid var(--surface-border);
      background: var(--surface);
      color: var(--text-heading);
      transition: all 0.15s ease;
      white-space: nowrap;
    }
    .hc-btn:hover {
      border-color: var(--sa-accent);
      color: var(--sa-accent);
      background: var(--sa-accent-soft);
    }
    .hc-btn.danger {
      border-color: rgba(239, 68, 68, 0.4);
      color: var(--status-red);
    }
    .hc-btn.danger:hover {
      background: var(--status-red);
      color: #fff;
      border-color: var(--status-red);
      box-shadow: 0 2px 8px rgba(239, 68, 68, 0.25);
    }
    .hc-btn.restore {
      border-color: rgba(5, 150, 105, 0.4);
      color: var(--status-green);
    }
    .hc-btn.restore:hover {
      background: var(--status-green);
      color: #fff;
      border-color: var(--status-green);
      box-shadow: 0 2px 8px rgba(5, 150, 105, 0.25);
    }
    .badge-primary-protected {
      font-size: 0.69rem;
      font-weight: 800;
      color: var(--brand-primary);
      background: rgba(2, 132, 199, 0.08);
      border: 1px solid rgba(2, 132, 199, 0.2);
      padding: 3px 8px;
      border-radius: 6px;
      letter-spacing: 0.03em;
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <main class="viewport-full">
    <!-- Top Welcome Banner & Command Telemetry -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">
          <svg style="width:12px;height:12px;stroke:#fff;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
          Super Administrator
        </div>
        <h1>
          Facility Command &amp; Directory
          <svg class="ui-ico" style="stroke: var(--sa-accent); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p>Network-wide facility governance. Monitor live bed capacity, inspect branch leadership, and execute operational transitions.</p>
      </div>

      <div class="banner-actions">
        <!-- Live System Telemetry Monitor Pill -->
        <div class="sa-telemetry-badge <?= $activeCount > 0 ? 'telemetry-surge' : 'telemetry-normal' ?>" 
             id="headerTelemetryPill" 
             title="<?= $activeCount > 0 ? 'National Emergency Surge Active: ' . $activeCount . ' protocol(s)' : 'Network Telemetry Synchronized across all 6 facilities' ?>">
          <div class="ecg-track">
            <svg class="ecg-svg" viewBox="0 0 54 18" preserveAspectRatio="none">
              <path class="ecg-pulse-line" d="M0,9 L12,9 L15,3 L18,15 L21,2 L24,16 L27,9 L32,9 L35,6 L38,11 L41,9 L54,9" />
            </svg>
          </div>
          <div class="telemetry-info">
            <span class="telemetry-bpm font-mono"><?= $activeCount > 0 ? '118 BPM' : '72 BPM' ?></span>
            <span class="telemetry-sep">•</span>
            <span class="telemetry-status">
              <?= $activeCount > 0 ? 'SURGE ACTIVE' : 'SYSTEM NORMAL' ?>
            </span>
          </div>
          <span class="telemetry-pulse-dot">
            <span class="telemetry-pulse-ring"></span>
            <span class="telemetry-pulse-core"></span>
          </span>
        </div>

        <a href="bed_monitor.php" class="btn-header-cta" title="Open central bed monitor">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: currentColor; width:15px; height:15px;"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"></path></svg>
          Bed Monitor
        </a>

        <a href="dashboard.php" class="btn-header-cta" title="Return to Super Admin Overview">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: currentColor; width:15px; height:15px;"><polyline points="15 18 9 12 15 6"></polyline></svg>
          Overview
        </a>
      </div>
    </div>

    <!-- Executive Network Summary Strip -->
    <div class="net-summary-strip">
      <div class="net-stat-card">
        <div class="net-stat-icon" style="background: rgba(124, 58, 237, 0.1); color: var(--sa-accent);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </div>
        <div>
          <div class="net-stat-val"><?= count($hospitals) ?></div>
          <div class="net-stat-lbl">Active Facilities</div>
        </div>
      </div>

      <div class="net-stat-card">
        <div class="net-stat-icon" style="background: rgba(2, 132, 199, 0.1); color: var(--brand-primary);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/></svg>
        </div>
        <div>
          <div class="net-stat-val"><?= number_format($netTotalBeds) ?></div>
          <div class="net-stat-lbl">Network Capacity</div>
        </div>
      </div>

      <div class="net-stat-card">
        <div class="net-stat-icon" style="background: rgba(5, 150, 105, 0.1); color: var(--status-green);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
          <div class="net-stat-val" style="color: var(--status-green);"><?= number_format($netAvailBeds) ?></div>
          <div class="net-stat-lbl">Available Beds</div>
        </div>
      </div>

      <div class="net-stat-card">
        <div class="net-stat-icon" style="background: rgba(217, 119, 6, 0.1); color: var(--status-amber);">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <div class="net-stat-val"><?= $netOccPct ?>%</div>
          <div class="net-stat-lbl">Network Occupancy</div>
        </div>
      </div>

      <div class="net-stat-card">
        <div class="net-stat-icon" style="background: <?= $activeCount > 0 ? 'rgba(225, 29, 72, 0.12)' : 'rgba(16, 185, 129, 0.1)' ?>; color: <?= $activeCount > 0 ? '#e11d48' : 'var(--status-green)' ?>;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </div>
        <div>
          <div class="net-stat-val" style="color: <?= $activeCount > 0 ? '#e11d48' : 'var(--status-green)' ?>;">
            <?= $activeCount ?> <?= $activeCount === 1 ? 'SURGE' : 'SURGES' ?>
          </div>
          <div class="net-stat-lbl">Active Protocols</div>
        </div>
      </div>
    </div>

    <!-- Facility Cards Grid -->
    <div class="hosp-grid" id="hospitalGrid">
      <?php foreach ($hospitals as $h):
        $total   = (int)($h['total_beds'] ?? 0);
        $occ     = (int)($h['occ_beds']   ?? 0);
        $avail   = (int)($h['avail_beds'] ?? 0);
        $maint   = (int)($h['maint_beds'] ?? 0);
        $held    = (int)($h['held_beds']  ?? 0);
        $icu     = (int)($h['icu_beds']   ?? 0);
        $icuOcc  = (int)($h['icu_occ']    ?? 0);
        $maxF    = (int)($h['max_floor']  ?? 5);
        $occPct  = $total > 0 ? round(($occ / $total) * 100) : 0;
        $icuPct  = $icu > 0 ? round(($icuOcc / $icu) * 100) : 0;
        $opSt    = $h['operational_status'] ?? 'Active';
        $hid     = (int)$h['hospital_id'];

        $cardCls = match($opSt) { 
            'Suspended'   => 'hosp-card hosp-card-interactive status-suspended', 
            'Maintenance' => 'hosp-card hosp-card-interactive status-maintenance', 
            default       => 'hosp-card hosp-card-interactive' 
        };
        $pillCls = match($opSt) { 
            'Suspended'   => 'status-pill sp-suspended', 
            'Maintenance' => 'status-pill sp-maintenance', 
            default       => 'status-pill sp-active' 
        };
      ?>
      <div class="<?= $cardCls ?>" id="hcard-<?= $hid ?>" data-hosp-id="<?= $hid ?>" title="Click anywhere on card to open live bed telemetry for <?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?>">
        <div>
          <!-- Header: Authentic Medical Crest + Facility Meta + Status Pill -->
          <div class="hc-header">
            <div style="display:flex; gap:12px; align-items:flex-start; flex:1; min-width:0;">
              <?= getHospitalCrest($hid, $h['name'] ?? '') ?>
              <div class="hc-meta">
                <div class="hc-name-row">
                  <div class="hc-name"><?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <span class="hc-code"><?= htmlspecialchars($h['code'] ?? 'H#' . $hid, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="hc-city" title="<?= htmlspecialchars(($h['city'] ?? '—') . ', ' . ($h['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars(($h['city'] ?? '—') . ' &bull; ' . ($h['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </div>
              </div>
            </div>

            <div class="hc-status-group">
              <span class="<?= $pillCls ?>" id="pill-<?= $hid ?>"><?= htmlspecialchars($opSt, ENT_QUOTES, 'UTF-8') ?></span>
              <span class="card-drill-hint">
                Monitor 
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:12px;height:12px;stroke-width:2.5;"><polyline points="9 18 15 12 9 6"></polyline></svg>
              </span>
            </div>
          </div>

          <!-- Bed Topology Breakdown Metrics -->
          <div class="hc-metrics">
            <div class="hc-metric">
              <div class="hc-metric-val"><?= number_format($total) ?></div>
              <div class="hc-metric-key">Total Beds</div>
            </div>
            <div class="hc-metric">
              <div class="hc-metric-val" style="color:var(--status-green);"><?= number_format($avail) ?></div>
              <div class="hc-metric-key">Available</div>
            </div>
            <div class="hc-metric">
              <div class="hc-metric-val" style="color:<?= $icuPct >= 85 ? 'var(--status-red)' : ($icuPct >= 65 ? 'var(--status-amber)' : 'var(--brand-primary)') ?>;">
                <?= $icu > 0 ? $icuPct . '%' : '—' ?>
              </div>
              <div class="hc-metric-key">ICU Occ. (<?= $icuOcc ?>/<?= $icu ?>)</div>
            </div>
          </div>

          <!-- Occupancy Progress Bar -->
          <div style="display:flex; justify-content:space-between; align-items:center; font-size:.73rem; color:var(--text-muted); font-weight:700; margin-bottom:4px;">
            <span>Occupancy (<?= number_format($occ) ?> / <?= number_format($total) ?> beds)</span>
            <span style="font-weight:800; color:<?= $occPct >= 90 ? 'var(--status-red)' : ($occPct >= 75 ? 'var(--status-amber)' : 'var(--text-heading)') ?>;"><?= $occPct ?>%</span>
          </div>
          <div class="hc-bar-track">
            <div class="hc-bar-fill" style="width:<?= min(100, $occPct) ?>%; background:<?= $occPct >= 90 ? 'var(--status-red)' : ($occPct >= 75 ? 'var(--status-amber)' : 'var(--sa-grad)') ?>;"></div>
          </div>

          <!-- Emergency Surge Held Notice (if any beds held) -->
          <?php if ($held > 0): ?>
            <div class="hc-surge-held">
              <span style="display:flex;align-items:center;gap:6px;">
                <span style="animation:saPulseDir 1.1s infinite;display:inline-block;">⚡</span>
                <span>EMERGENCY SURGE LOCK</span>
              </span>
              <span style="font-family:ui-monospace,monospace;font-weight:800;background:#e11d48;color:#fff;padding:2px 7px;border-radius:5px;font-size:0.69rem;">
                <?= number_format($held) ?> HELD
              </span>
            </div>
          <?php endif; ?>

          <!-- Branch Leadership -->
          <div class="hc-admin">
            <div class="hc-admin-icon"><?= strtoupper(substr($h['admin_name'] ?? 'A', 0, 1)) ?></div>
            <div style="min-width:0;flex:1;">
              <div class="hc-admin-name"><?= htmlspecialchars($h['admin_name'] ?? 'No Admin Assigned', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="hc-admin-email"><?= htmlspecialchars($h['admin_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
        </div>

        <!-- Card Footer & Isolated Action Controls -->
        <div class="hc-footer">
          <span class="hc-floors">Floors: 1 – <?= $maxF ?> &bull; <?= (int)($h['doctor_count'] ?? 0) ?> Doctors</span>
          <div class="hc-ctrl-btns" onclick="event.stopPropagation();">
            <?php if ($opSt !== 'Active'): ?>
              <button class="hc-btn restore" type="button" onclick="toggleHospStatus(<?= $hid ?>, 'Active', event)">Restore Active</button>
            <?php endif; ?>
            <?php if ($opSt !== 'Maintenance'): ?>
              <button class="hc-btn" type="button" onclick="toggleHospStatus(<?= $hid ?>, 'Maintenance', event)">Maintenance</button>
            <?php endif; ?>
            <?php if ($opSt !== 'Suspended' && $hid !== 1): ?>
              <button class="hc-btn danger" type="button" onclick="toggleHospStatus(<?= $hid ?>, 'Suspended', event)">Suspend</button>
            <?php endif; ?>
            <?php if ($hid === 1): ?>
              <span class="badge-primary-protected" title="Primary apex facility cannot be suspended">Primary &bull; Protected</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </main>

  <!-- Modern MedPulse Dialog & Toast Engine -->
  <script src="../assets/js/medpulse_dialog.js"></script>

  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    // Facility Card Drill-Down Listener (Click card anywhere except action buttons)
    document.querySelectorAll('.hosp-card-interactive').forEach(card => {
      card.addEventListener('click', (e) => {
        // Prevent drilldown if user clicked an action button, form control, or interactive element
        if (e.target.closest('.hc-ctrl-btns, button, a, input, select, .no-drilldown')) {
          return;
        }
        const hid = card.getAttribute('data-hosp-id');
        if (hid) {
          window.location.href = `bed_monitor.php?hospital_id=${hid}`;
        }
      });
    });

    // Themed Operational Status Transition
    async function toggleHospStatus(hid, newStatus, evt) {
      if (evt) {
        evt.stopPropagation();
        evt.preventDefault();
      }

      const card = document.getElementById('hcard-' + hid);
      const hospName = card ? card.querySelector('.hc-name').textContent.trim() : `Hospital #${hid}`;

      const isSuspend = newStatus === 'Suspended';
      const isMaint   = newStatus === 'Maintenance';

      const confirmed = await MedPulseDialog.confirm({
        title: isSuspend ? 'Confirm Hospital Suspension' : (isMaint ? 'Confirm Maintenance Mode' : 'Restore Hospital Operations'),
        subtitle: `${hospName} • Facility ID #${hid}`,
        type: isSuspend ? 'danger' : (isMaint ? 'warning' : 'primary'),
        confirmText: isSuspend ? 'Execute Suspension' : (isMaint ? 'Enable Maintenance' : 'Restore to Active'),
        cancelText: 'Abort',
        message: isSuspend 
          ? `Are you sure you want to suspend operations for ${hospName}? All automated admissions will be locked across the network command system.`
          : (isMaint 
              ? `Transition ${hospName} into maintenance mode? Routine telemetry and non-emergency bed admissions will be temporarily suspended.`
              : `Restore ${hospName} to normal active clinical operations across the network command system?`)
      });

      if (!confirmed) return;

      const fd = new FormData();
      fd.append('_action',     'toggle_status');
      fd.append('hospital_id', hid);
      fd.append('new_status',  newStatus);
      fd.append('csrf_token',  CSRF);

      try {
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const d = await r.json();

        if (d.success) {
          MedPulseDialog.toast({
            title: 'Operational Status Updated',
            message: d.message,
            type: 'success',
            duration: 4000
          });

          // Smooth in-place DOM update of pill and card styling
          const pill = document.getElementById('pill-' + hid);
          if (card) {
            card.className = 'hosp-card hosp-card-interactive' + 
              (newStatus === 'Suspended' ? ' status-suspended' : newStatus === 'Maintenance' ? ' status-maintenance' : '');
          }
          if (pill) {
            pill.className = 'status-pill ' + 
              (newStatus === 'Active' ? 'sp-active' : newStatus === 'Suspended' ? 'sp-suspended' : 'sp-maintenance');
            pill.textContent = newStatus;
          }

          // Smoothly reload after 1.2s to refresh action buttons and audit logs
          setTimeout(() => location.reload(), 1200);
        } else {
          MedPulseDialog.toast({
            title: 'Transition Failed',
            message: d.message || 'Error occurred while updating hospital status.',
            type: 'error',
            duration: 4500
          });
        }
      } catch (err) {
        MedPulseDialog.toast({
          title: 'Network Communication Error',
          message: 'Failed to communicate with hospital command service.',
          type: 'error',
          duration: 4500
        });
      }
    }
  </script>
</body>
</html>

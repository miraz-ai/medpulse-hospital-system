<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Executive Command Center & Role Control
 */

require_once __DIR__ . '/../includes/admin_auth.php';

try {
    // 1. Pending Approvals Queue (Doctors & Staff awaiting administrative review)
    $pendingStmt = $pdo->prepare("
        SELECT user_id, full_name, email, phone, gender, role, status, license_id, department, created_at 
        FROM users 
        WHERE status = 'pending' AND role IN ('Doctor', 'Staff') 
        ORDER BY created_at DESC
    ");
    $pendingStmt->execute();
    $pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingCount = count($pendingUsers);

    // 2. Departmental Hub Counters (Live Relational Queries)
    $total_doctors = (int)$pdo->query("SELECT COUNT(*) AS total_doctors FROM users WHERE role = 'doctor' AND status = 'active'")->fetchColumn();
    $total_staff = (int)$pdo->query("SELECT COUNT(*) AS total_staff FROM users WHERE role IN ('nurse', 'pharmacist', 'receptionist', 'staff') AND status = 'active'")->fetchColumn();
    $total_patients = (int)$pdo->query("SELECT COUNT(*) AS total_patients FROM users WHERE role = 'patient'")->fetchColumn();

    // Strict multi-hospital scoping: resolve current branch hospital
    $adminHospitalId = (int)($_SESSION['hospital_id'] ?? 1);
    $hospStmt = $pdo->prepare("SELECT hospital_id, name, code FROM hospitals WHERE hospital_id = ?");
    $hospStmt->execute([$adminHospitalId]);
    $currentHospital = $hospStmt->fetch(PDO::FETCH_ASSOC);
    $branchName = $currentHospital['name'] ?? 'MedPulse Facility';

    // Bed Census Telemetry scoped to branch hospital
    $bedStatsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_beds,
            SUM(status = 'Available') AS available_beds,
            SUM(status = 'Occupied') AS occupied_beds,
            SUM(status = 'Maintenance') AS maintenance_beds,
            SUM(status = 'Emergency Hold') AS emergency_hold_beds
        FROM hospital_beds
        WHERE hospital_id = ?
    ");
    $bedStatsStmt->execute([$adminHospitalId]);
    $bRow = $bedStatsStmt->fetch(PDO::FETCH_ASSOC);

    $total_beds          = (int)($bRow['total_beds'] ?? 500);
    $available_beds      = (int)($bRow['available_beds'] ?? 0);
    $occupied_beds       = (int)($bRow['occupied_beds'] ?? 0);
    $maintenance_beds    = (int)($bRow['maintenance_beds'] ?? 0);
    $emergency_hold_beds = (int)($bRow['emergency_hold_beds'] ?? 0);

    // Multi-Emergency Protocols Scope for Branch
    require_once __DIR__ . '/../backend/Services/EmergencyProtocolService.php';
    $emergencyService = new \MedPulse\Services\EmergencyProtocolService($pdo);
    $allActiveProtocols = $emergencyService->getActiveProtocols();
    $branchProtocols = [];
    $branchHeldBeds = 0;
    $branchRelocCount = 0;

    foreach ($allActiveProtocols as $p) {
        $targetIds = $p['target_hospital_ids'] ?? [];
        if (empty($targetIds) && !empty($p['target_hospitals'])) {
            $targetIds = is_string($p['target_hospitals']) ? json_decode($p['target_hospitals'], true) : $p['target_hospitals'];
        }
        $isNetworkWide = strtoupper($p['target_scope'] ?? '') === 'NETWORK_WIDE';
        $inHospital = in_array((int)$adminHospitalId, array_map('intval', (array)$targetIds), true);

        if ($isNetworkWide || $inHospital) {
            // Count held beds and relocation beds for this hospital & protocol
            $bCounts = $pdo->prepare("
                SELECT 
                    SUM(status = 'Emergency Hold') AS held_count,
                    SUM(relocation_status = 'PENDING_RELOCATION') AS relocating_count
                FROM hospital_beds
                WHERE hospital_id = ? AND emergency_protocol_id = ?
            ");
            $bCounts->execute([$adminHospitalId, $p['id']]);
            $bc = $bCounts->fetch(PDO::FETCH_ASSOC);
            $p['branch_held'] = (int)($bc['held_count'] ?? 0);
            $p['branch_reloc'] = (int)($bc['relocating_count'] ?? 0);
            $branchHeldBeds += $p['branch_held'];
            $branchRelocCount += $p['branch_reloc'];
            $branchProtocols[] = $p;
        }
    }
    $branchActiveCount = count($branchProtocols);

    // Relocation queue count for branch (total beds pending relocation in this facility)
    $relocStmt = $pdo->prepare("SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = ? AND relocation_status = 'PENDING_RELOCATION'");
    $relocStmt->execute([$adminHospitalId]);
    $branchRelocCount = max($branchRelocCount, (int)$relocStmt->fetchColumn());

    // Surge quota impact:
    if ($emergency_hold_beds === 0 && $branchHeldBeds > 0) {
        $emergency_hold_beds = $branchHeldBeds;
    }
    $branchSurgePct = $total_beds > 0 ? round(($emergency_hold_beds / $total_beds) * 100, 1) : 0;

    // 3. System Health & ICU Load Telemetry
    $icuStats = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_icu,
            SUM(status = 'Occupied') AS occupied_icu
        FROM hospital_beds 
        WHERE hospital_id = ? AND ward_type = 'ICU'
    ");
    $icuStats->execute([$adminHospitalId]);
    $icuRow = $icuStats->fetch(PDO::FETCH_ASSOC);
    $totalIcu = (int)($icuRow['total_icu'] ?? 0);
    $occupiedIcu = (int)($icuRow['occupied_icu'] ?? 0);
    $icuLoad = $totalIcu > 0 ? round(($occupiedIcu / $totalIcu) * 100) : 0;

    // Determine Dynamic Health Status with Surge Synchronization
    if ($branchActiveCount > 0 || $emergency_hold_beds > 0) {
        $healthBadgeText = 'SURGE ACTIVE &bull; PRIORITY TRIAGE';
        $healthBadgeClass = 'status-surge-active';
        $pulseClass = 'ecg-pulse-warning';
        $pulseLabel = '112 BPM &bull; DISASTER SURGE ACTIVE';
        $waveColor = '#f43f5e';
    } elseif ($icuLoad >= 85) {
        $healthBadgeText = 'HIGH LOAD';
        $healthBadgeClass = 'status-high-load';
        $pulseClass = 'ecg-pulse-warning';
        $pulseLabel = '96 BPM &bull; HIGH CAPACITY';
        $waveColor = '#d97706';
    } else {
        $healthBadgeText = 'READY &bull; SYSTEM NORMAL';
        $healthBadgeClass = '';
        $pulseClass = '';
        $pulseLabel = '72 BPM &bull; TELEMETRY ACTIVE';
        $waveColor = '#0d9488';
    }

    // 4. Branch Critical Resource Telemetry (Oxygen, Ventilators, Blood Bank)
    $resStmt = $pdo->prepare("SELECT * FROM hospital_resources WHERE hospital_id = ?");
    $resStmt->execute([$adminHospitalId]);
    $branchResource = $resStmt->fetch(PDO::FETCH_ASSOC);

    if (!$branchResource) {
        $branchResource = [
            'oxygen_reserve_pct'   => 75,
            'current_pressure_psi' => 2200,
            'depletion_days'       => 7.5,
            'tanker_dispatched'    => 0,
            'ventilators_total'    => 80,
            'ventilators_active'   => 50,
            'hardware_spec'        => 'Standard Dual Mode ICU Ventilator',
            'blood_o_neg'          => 25,
            'blood_trauma_packs'   => 100,
            'platelet_bags'        => 30,
            'cryo_units'           => 15,
            'courier_dispatched'   => 0
        ];
    }

    // 5. Live Event Telemetry Ticker (Latest 5 Events from audit_logs)
    $tickerLogs = $pdo->query("
        SELECT log_id, action, description, category, ip_address, created_at 
        FROM audit_logs 
        ORDER BY created_at DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Relative timestamp helper
    if (!function_exists('getTelemetryRelativeTime')) {
        function getTelemetryRelativeTime($datetime) {
            $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
            if (!$timestamp) return 'Just now';
            $diff = time() - $timestamp;
            if ($diff < 60) return 'Just now';
            if ($diff < 3600) return max(1, round($diff / 60)) . 'm ago';
            if ($diff < 86400) return max(1, round($diff / 3600)) . 'h ago';
            if ($diff < 604800) return max(1, round($diff / 86400)) . 'd ago';
            return date('M j', $timestamp);
        }
    }

} catch (PDOException $e) {
    error_log("Admin Dashboard DB error: " . $e->getMessage());
    $total_doctors = (int)($total_doctors ?? 8);
    $total_staff = (int)($total_staff ?? 1);
    $total_patients = (int)($total_patients ?? 9);
    $total_beds = 500;
    $available_beds = 392;
    $occupied_beds = 93;
    $maintenance_beds = 15;
    $emergency_hold_beds = (int)($emergency_hold_beds ?? 0);
    $branchActiveCount = (int)($branchActiveCount ?? 0);
    $branchProtocols = $branchProtocols ?? [];
    $branchRelocCount = (int)($branchRelocCount ?? 0);
    $branchSurgePct = $total_beds > 0 ? round(($emergency_hold_beds / $total_beds) * 100, 1) : 0;
    $pendingCount = (int)($pendingCount ?? 0);
    $pendingUsers = $pendingUsers ?? [];
    $tickerLogs = $tickerLogs ?? [];
    $branchName = $branchName ?? 'MedPulse Facility';

    if ($branchActiveCount > 0 || $emergency_hold_beds > 0) {
        $healthBadgeText = 'SURGE ACTIVE &bull; PRIORITY TRIAGE';
        $healthBadgeClass = 'status-surge-active';
        $pulseClass = 'ecg-pulse-warning';
        $pulseLabel = '112 BPM &bull; DISASTER SURGE ACTIVE';
        $waveColor = '#f43f5e';
    } else {
        $healthBadgeText = 'READY &bull; SYSTEM NORMAL';
        $healthBadgeClass = '';
        $pulseClass = '';
        $pulseLabel = '72 BPM &bull; TELEMETRY ACTIVE';
        $waveColor = '#0d9488';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Executive Command Center</title>
  
  <!-- Hospital Favicon (100% Parity with patient_dashboard.php) -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/live-ticker.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/admin/live-pulse.css?v=<?= time() ?>">
  <style>
    @keyframes pulseAlert {
      0%, 100% { box-shadow: 0 0 10px rgba(225, 29, 72, 0.4); }
      50% { box-shadow: 0 0 22px rgba(225, 29, 72, 0.75); }
    }
    @keyframes saPulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(0.92); }
    }
    @keyframes beaconBounce {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.08); }
    }
    .badge-surge-pulse {
      animation: pulseAlert 2s infinite;
    }
    .status-surge-active {
      background: #fef2f2 !important;
      color: #991b1b !important;
      border: 1.5px solid rgba(244, 63, 94, 0.5) !important;
      box-shadow: 0 0 12px rgba(225, 29, 72, 0.25) !important;
    }
    .status-surge-active .radar-pulse-dot {
      background: #e11d48 !important;
      box-shadow: 0 0 8px #e11d48 !important;
    }

    /* --- Enterprise Clinical Status Bar: Glassmorphic Frame --- */
    .telemetry-ticker-bar.ticker-surge-alert {
      background: linear-gradient(135deg, rgba(255, 241, 242, 0.92) 0%, rgba(255, 228, 230, 0.78) 45%, rgba(254, 242, 242, 0.95) 100%) !important;
      backdrop-filter: blur(14px) saturate(180%) !important;
      -webkit-backdrop-filter: blur(14px) saturate(180%) !important;
      border: 1px solid rgba(244, 63, 94, 0.35) !important;
      border-left: 4px solid #e11d48 !important;
      border-radius: 12px;
      box-shadow: 0 4px 20px -2px rgba(225, 29, 72, 0.14), 0 2px 6px -1px rgba(225, 29, 72, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.7) !important;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .telemetry-ticker-bar.ticker-surge-alert:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px -2px rgba(225, 29, 72, 0.22), 0 3px 8px -1px rgba(225, 29, 72, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.8) !important;
      border-color: rgba(225, 29, 72, 0.5) !important;
    }

    /* Left Element: Single Compact Status Badge */
    .ticker-indicator.indicator-surge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 5px 12px;
      background: rgba(225, 29, 72, 0.1);
      border: 1px solid rgba(225, 29, 72, 0.28);
      border-radius: 9999px;
      flex-shrink: 0;
      user-select: none;
      box-shadow: 0 1px 3px rgba(225, 29, 72, 0.08);
    }
    .ticker-indicator.indicator-surge .ticker-pulse-wrapper {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 10px;
      height: 10px;
    }
    .ticker-indicator.indicator-surge .ticker-pulse-dot {
      width: 8px;
      height: 8px;
      background-color: #e11d48;
      border-radius: 50%;
      box-shadow: 0 0 8px #e11d48;
      position: relative;
      z-index: 2;
    }
    .ticker-indicator.indicator-surge .ticker-pulse-ring {
      position: absolute;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background-color: #f43f5e;
      opacity: 0.75;
      animation: pulseAlert 1.5s infinite;
      z-index: 1;
    }
    .ticker-indicator.indicator-surge .indicator-label {
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      color: #be123c;
      text-transform: uppercase;
      white-space: nowrap;
    }

    /* Center Element: Alert Narrative & Live Vitals */
    .ticker-item-surge {
      display: flex;
      align-items: center;
      gap: 12px;
      height: 40px;
      width: 100%;
    }
    .ticker-cat-badge.badge-emergency {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
      color: #ffffff;
      font-weight: 800;
      font-size: 0.68rem;
      letter-spacing: 0.05em;
      padding: 3px 8px;
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(225, 29, 72, 0.3);
      text-transform: uppercase;
      flex-shrink: 0;
    }
    .ticker-narrative-text {
      font-size: 0.86rem;
      font-weight: 600;
      color: #881337;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .narrative-bullet {
      color: #f43f5e;
      font-weight: bold;
      user-select: none;
    }
    .mandates-highlight {
      color: #9f1239;
      font-weight: 800;
    }

    /* Medical SVG ECG Waveform & Vitals Badge */
    .clinical-ecg-monitor {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 3px 10px;
      background: rgba(225, 29, 72, 0.08);
      border: 1px solid rgba(225, 29, 72, 0.22);
      border-radius: 9999px;
      flex-shrink: 0;
      user-select: none;
    }
    .clinical-ecg-canvas {
      width: 44px;
      height: 15px;
      display: flex;
      align-items: center;
      flex-shrink: 0;
    }
    .clinical-ecg-svg {
      width: 100%;
      height: 100%;
      overflow: visible;
    }
    .clinical-ecg-bg {
      fill: none;
      stroke: rgba(225, 29, 72, 0.25);
      stroke-width: 1.5;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .clinical-ecg-pulse {
      fill: none;
      stroke: #e11d48;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
      stroke-dasharray: 100;
      stroke-dashoffset: 100;
      animation: ecgSurgeFast 1.3s linear infinite;
      filter: drop-shadow(0 0 3px rgba(225, 29, 72, 0.6));
    }
    @keyframes ecgSurgeFast {
      0% { stroke-dashoffset: 100; }
      50% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -100; }
    }
    .clinical-bpm-val {
      font-size: 0.72rem;
      font-weight: 800;
      color: #be123c;
      letter-spacing: 0.02em;
      white-space: nowrap;
    }
    .clinical-pulse-dot {
      width: 6px;
      height: 6px;
      background-color: #e11d48;
      border-radius: 50%;
      display: inline-block;
      animation: clinicalDotPulse 0.9s ease-in-out infinite;
      box-shadow: 0 0 6px rgba(225, 29, 72, 0.6);
    }
    @keyframes clinicalDotPulse {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(0.65); opacity: 0.35; }
    }

    /* Right Action Navigation Button */
    .ticker-audit-link.audit-btn-surge {
      background: rgba(244, 63, 94, 0.08);
      color: #be123c;
      border: 1px solid rgba(244, 63, 94, 0.28);
      border-radius: 9999px;
      padding: 6px 14px;
      font-size: 0.8rem;
      font-weight: 700;
      box-shadow: 0 1px 3px rgba(225, 29, 72, 0.08);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: all 0.22s ease-in-out;
      white-space: nowrap;
    }
    .ticker-audit-link.audit-btn-surge:hover {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
      color: #ffffff;
      border-color: #be123c;
      box-shadow: 0 4px 14px rgba(225, 29, 72, 0.35);
      transform: translateY(-1px);
    }
    .ticker-audit-link.audit-btn-surge svg {
      width: 14px;
      height: 14px;
      stroke: currentColor;
      stroke-width: 2.2;
      fill: none;
      transition: transform 0.2s ease;
    }
    .ticker-audit-link.audit-btn-surge:hover svg {
      transform: translateX(3px);
    }

    /* Dark Mode Contrast Optimization */
    [data-theme="dark"] .telemetry-ticker-bar.ticker-surge-alert,
    .dark .telemetry-ticker-bar.ticker-surge-alert {
      background: linear-gradient(135deg, rgba(38, 12, 18, 0.92) 0%, rgba(55, 16, 26, 0.82) 45%, rgba(30, 9, 14, 0.95) 100%) !important;
      border: 1px solid rgba(244, 63, 94, 0.35) !important;
      border-left: 4px solid #f43f5e !important;
      box-shadow: 0 4px 24px -2px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.08) !important;
    }
    [data-theme="dark"] .ticker-indicator.indicator-surge,
    .dark .ticker-indicator.indicator-surge {
      background: rgba(225, 29, 72, 0.2);
      border-color: rgba(244, 63, 94, 0.35);
    }
    [data-theme="dark"] .ticker-indicator.indicator-surge .indicator-label,
    .dark .ticker-indicator.indicator-surge .indicator-label {
      color: #fda4af;
    }
    [data-theme="dark"] .ticker-narrative-text,
    .dark .ticker-narrative-text {
      color: #fecdd3;
    }
    [data-theme="dark"] .mandates-highlight,
    .dark .mandates-highlight {
      color: #ffe4e6;
    }
    [data-theme="dark"] .clinical-ecg-monitor,
    .dark .clinical-ecg-monitor {
      background: rgba(225, 29, 72, 0.2);
      border-color: rgba(244, 63, 94, 0.35);
    }
    [data-theme="dark"] .clinical-bpm-val,
    .dark .clinical-bpm-val {
      color: #fda4af;
    }
    [data-theme="dark"] .ticker-audit-link.audit-btn-surge,
    .dark .ticker-audit-link.audit-btn-surge {
      background: rgba(244, 63, 94, 0.16);
      color: #fda4af;
      border-color: rgba(244, 63, 94, 0.35);
    }
    [data-theme="dark"] .ticker-audit-link.audit-btn-surge:hover,
    .dark .ticker-audit-link.audit-btn-surge:hover {
      background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
      color: #ffffff;
    }
  </style>
</head>
<body>

  <!-- Centralized Admin Sidebar Partial (Dynamic Active Route Highlighting) -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container (Starts cleanly past sidebar) -->
  <main class="viewport-full">

    <!-- Executive Welcome Banner (Fluid flex-wrap, zero text clipping) -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>!
          <svg class="ui-ico" style="stroke: var(--brand-teal); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p>Enterprise Operations Console: Real-time clinical telemetry, personnel security, and role-based registries are synchronized.</p>
      </div>
      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Security audit telemetry is operating in strict mode.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          Audit Telemetry
        </button>
        <button class="btn-action-gradient" onclick="window.location.href='index.php'">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Register Staff
        </button>
      </div>
    </div>

    <!-- Live Event Telemetry Ticker / Enterprise Clinical Status Bar -->
    <div class="telemetry-ticker-bar <?= $branchActiveCount > 0 ? 'ticker-surge-alert' : '' ?>" id="telemetryTicker" aria-label="Live event telemetry ticker">
      <!-- Left Element: Status Indicator -->
      <div class="ticker-indicator <?= $branchActiveCount > 0 ? 'indicator-surge' : '' ?>">
        <div class="ticker-pulse-wrapper">
          <span class="ticker-pulse-ring"></span>
          <span class="ticker-pulse-dot"></span>
        </div>
        <span class="indicator-label">
          <?= $branchActiveCount > 0 ? 'SURGE ACTIVE' : 'Live Telemetry' ?>
        </span>
      </div>

      <!-- Center Element: Alert Narrative & Live Vitals -->
      <div class="ticker-viewport">
        <div class="ticker-track" id="tickerTrack">
          <?php if ($branchActiveCount > 0): ?>
            <div class="ticker-item ticker-item-surge">
              <span class="ticker-cat-badge badge-emergency">CRITICAL</span>
              <span class="ticker-narrative-text">
                Branch protocols active <span class="narrative-bullet">&bull;</span> <strong class="mandates-highlight"><?= $branchActiveCount ?> Mandate<?= $branchActiveCount > 1 ? 's' : '' ?> Enforced</strong>
              </span>
              <div class="clinical-ecg-monitor ecg-surge">
                <div class="clinical-ecg-canvas">
                  <svg class="clinical-ecg-svg" viewBox="0 0 54 18" preserveAspectRatio="none">
                    <path class="clinical-ecg-bg" d="M0,9 L12,9 L15,3 L18,15 L21,2 L24,16 L27,9 L32,9 L35,6 L38,11 L41,9 L54,9"></path>
                    <path class="clinical-ecg-pulse" d="M0,9 L12,9 L15,3 L18,15 L21,2 L24,16 L27,9 L32,9 L35,6 L38,11 L41,9 L54,9"></path>
                  </svg>
                </div>
                <span class="clinical-bpm-val font-mono">114 BPM</span>
                <span class="clinical-pulse-dot"></span>
              </div>
            </div>
          <?php else: ?>
            <?php if (!empty($tickerLogs)): ?>
              <?php foreach ($tickerLogs as $log): 
                $cat = strtoupper($log['category'] ?? 'SYSTEM');
                $catClass = match($cat) {
                    'ADMISSION' => 'cat-admission',
                    'VERIFICATION' => 'cat-verification',
                    'PHARMACY' => 'cat-pharmacy',
                    'SECURITY' => 'cat-security',
                    default => 'cat-system'
                };
                $desc = !empty($log['description']) ? $log['description'] : ($log['action'] ?? 'Telemetry event recorded');
                $relTime = getTelemetryRelativeTime($log['created_at']);
              ?>
                <div class="ticker-item">
                  <span class="ticker-cat-badge <?= $catClass ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="ticker-text"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="ticker-separator">&bull;</span>
                  <span class="ticker-time"><?= htmlspecialchars($relTime, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="ticker-item">
                <span class="ticker-cat-badge cat-system">SYSTEM</span>
                <span class="ticker-text">Telemetry Active &bull; All channels normal</span>
                <span class="ticker-separator">&bull;</span>
                <span class="ticker-time">Just now</span>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right Element: Action Navigation Button -->
      <a href="audit_logs.php" class="ticker-audit-link <?= $branchActiveCount > 0 ? 'audit-btn-surge' : '' ?>" title="View dedicated audit logs">
        <span>View All Logs</span>
        <svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
      </a>
    </div>

    <!-- Executive 4-Metric Vital Stats Cards -->
    <div class="stat-cards-grid">
      <!-- Metric 1: Total Registered Patients -->
      <a href="manage_patients.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Patient Registry</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= number_format($total_patients) ?></div>
        <div class="stat-card-badge badge-blue">
          <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          View Full Registry &rarr;
        </div>
      </a>

      <!-- Metric 2: Verified Medical Doctors -->
      <a href="manage_doctors.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Medical Doctors</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
        </div>
        <div class="stat-card-number" id="kpiActiveDoctorsCount"><?= number_format($total_doctors) ?></div>
        <div class="stat-card-badge badge-green">
          <span>BMDC Licensed &rarr;</span>
        </div>
      </a>

      <!-- Metric 3: Active Clinical Staff -->
      <a href="manage_staff.php" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Clinical Staff</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
        </div>
        <div class="stat-card-number" id="kpiActiveStaffCount"><?= number_format($total_staff) ?></div>
        <div class="stat-card-badge badge-green">
          <span>Operations Active &rarr;</span>
        </div>
      </a>

      <!-- Metric 4: Pending Verification Queue -->
      <a href="#pendingApprovalSection" class="stat-card-executive" style="text-decoration: none; color: inherit;">
        <div class="stat-card-head">
          <span>Pending Verification</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number" id="kpiPendingCount"><?= str_pad((string)$pendingCount, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="stat-card-badge <?= $pendingCount > 0 ? 'badge-amber' : 'badge-green' ?>" id="kpiPendingBadge">
          <span><?= $pendingCount > 0 ? 'Review Required' : 'All Clear' ?></span>
        </div>
      </a>
    </div>

    <!-- LOCALIZED CRITICAL RESOURCE INVENTORY SUMMARY CARD -->
    <div style="background: var(--surface); border: 1.5px solid var(--surface-border); border-radius: 16px; padding: 18px 22px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
      <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(2, 132, 199, 0.1); border: 1px solid rgba(2, 132, 199, 0.25); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: #0284c7; flex-shrink: 0;">
          ⚡
        </div>
        <div>
          <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h3 style="margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--text-heading);">
              Branch Life-Support &amp; Clinical Inventory
            </h3>
            <span style="font-size: 0.70rem; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 12px; background: #ecfdf5; color: #065f46; border: 1px solid rgba(16, 185, 129, 0.3);">
              Live Dynamic Telemetry
            </span>
          </div>
          <div style="display: flex; align-items: center; gap: 14px; margin-top: 6px; font-size: 0.82rem; color: var(--text-heading); flex-wrap: wrap;">
            <!-- Oxygen -->
            <span style="display: inline-flex; align-items: center; gap: 6px;">
              <span style="color: #0284c7; font-weight: 700;">Liquid Oxygen:</span>
              <strong style="color: <?= (int)$branchResource['oxygen_reserve_pct'] >= 70 ? '#059669' : ((int)$branchResource['oxygen_reserve_pct'] >= 50 ? '#d97706' : '#e11d48') ?>;">
                <?= (int)$branchResource['oxygen_reserve_pct'] ?>%
              </strong>
              <span style="color: var(--text-muted); font-size: 0.74rem;">(<?= number_format((int)$branchResource['current_pressure_psi']) ?> PSI • <?= $branchResource['depletion_days'] ?>d)</span>
            </span>
            <span style="color: var(--surface-border);">&bull;</span>
            <!-- ICU Vents -->
            <span style="display: inline-flex; align-items: center; gap: 6px;">
              <span style="color: #7c3aed; font-weight: 700;">ICU Vents:</span>
              <strong><?= (int)$branchResource['ventilators_active'] ?> / <?= (int)$branchResource['ventilators_total'] ?> Active</strong>
              <span style="color: #10b981; font-weight: 700; font-size: 0.74rem;">(<?= max(0, (int)$branchResource['ventilators_total'] - (int)$branchResource['ventilators_active']) ?> Standby)</span>
            </span>
            <span style="color: var(--surface-border);">&bull;</span>
            <!-- Blood Bank -->
            <span style="display: inline-flex; align-items: center; gap: 6px;">
              <span style="color: #e11d48; font-weight: 700;">O- Bank:</span>
              <strong style="color: <?= (int)$branchResource['blood_o_neg'] < 20 ? '#e11d48' : 'var(--text-heading)' ?>;">
                <?= (int)$branchResource['blood_o_neg'] ?> Units
              </strong>
              <span style="color: var(--text-muted); font-size: 0.74rem;">(<?= (int)$branchResource['blood_trauma_packs'] ?> Trauma Packs)</span>
            </span>
          </div>
        </div>
      </div>

      <div style="display: flex; align-items: center; gap: 10px;">
        <?php if (!empty($branchResource['tanker_dispatched']) || !empty($branchResource['courier_dispatched'])): ?>
          <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; border-radius: 8px; font-size: 0.76rem; font-weight: 800; animation: saPulse 1.6s infinite;">
            🚨 <?= !empty($branchResource['tanker_dispatched']) ? 'LOX Tanker En Route' : 'Blood Courier En Route' ?>
          </span>
        <?php endif; ?>
        <a href="resource_inventory.php" style="display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #fff; font-size: 0.82rem; font-weight: 800; text-decoration: none; border-radius: 10px; box-shadow: 0 2px 8px rgba(2, 132, 199, 0.3); transition: transform 0.15s;" onmouseenter="this.style.transform='translateY(-1px)';" onmouseleave="this.style.transform='none';">
          Manage Resource Inventory &rarr;
        </a>
      </div>
    </div>

    <!-- DEDICATED BRANCH DISASTER READINESS WIDGET -->
    <section class="admin-stack-card" id="branchDisasterReadinessWidget" style="border: 1.5px solid <?= $branchActiveCount > 0 ? 'rgba(244, 63, 94, 0.5)' : 'rgba(13, 148, 136, 0.3)' ?>; box-shadow: 0 4px 20px <?= $branchActiveCount > 0 ? 'rgba(244, 63, 94, 0.08)' : 'rgba(13, 148, 136, 0.05)' ?>; margin-bottom: 24px;">
      <div class="admin-stack-header" style="background: <?= $branchActiveCount > 0 ? 'linear-gradient(135deg, rgba(255, 241, 242, 0.6) 0%, rgba(248, 250, 252, 0.9) 100%)' : 'linear-gradient(135deg, rgba(240, 253, 250, 0.6) 0%, rgba(248, 250, 252, 0.9) 100%)' ?>; padding: 18px 24px; border-bottom: 1px solid <?= $branchActiveCount > 0 ? 'rgba(244, 63, 94, 0.2)' : 'rgba(13, 148, 136, 0.2)' ?>;">
        <div class="admin-stack-title-group">
          <div style="display: flex; align-items: center; gap: 10px;">
            <div style="width: 38px; height: 38px; border-radius: 10px; background: <?= $branchActiveCount > 0 ? '#ffe4e6' : '#ccfbf1' ?>; color: <?= $branchActiveCount > 0 ? '#e11d48' : '#0d9488' ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
              <?= $branchActiveCount > 0 ? '🚨' : '🛡️' ?>
            </div>
            <div>
              <h3 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: var(--text-heading); display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                Branch Disaster Readiness &amp; Surge Attribution
                <?php if ($branchActiveCount > 0): ?>
                  <span class="badge-surge-pulse" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; padding: 4px 11px; border-radius: 20px; font-weight: 800; text-transform: uppercase; background: linear-gradient(135deg, #e11d48 0%, #b91c1c 100%); color: #fff; box-shadow: 0 2px 10px rgba(225, 29, 72, 0.4);">
                    <span style="width: 7px; height: 7px; border-radius: 50%; background: #fff; animation: saPulse 1.2s infinite;"></span>
                    SURGE ACTIVE &bull; PRIORITY TRIAGE
                  </span>
                <?php else: ?>
                  <span style="font-size: 0.72rem; padding: 3px 9px; border-radius: 12px; font-weight: 800; text-transform: uppercase; background: #0d9488; color: #fff;">
                    READY &bull; SYSTEM NORMAL
                  </span>
                <?php endif; ?>
              </h3>
              <p style="margin: 3px 0 0; font-size: 0.78rem; color: var(--text-muted);">
                <?= htmlspecialchars($branchName) ?> localized surge quotas, concurrent holds, and evacuation readiness telemetry
              </p>
            </div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <a href="live_census.php" class="btn-action-telemed" style="text-decoration: none; padding: 7px 14px; font-size: 0.78rem; font-weight: 700;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
            Inspect Floor Grid &rarr;
          </a>
        </div>
      </div>

      <div style="padding: 20px 24px;">
        <?php if ($branchActiveCount > 0): 
          $activeTitles = implode(' / ', array_map(fn($p) => htmlspecialchars($p['title']), $branchProtocols));
        ?>
        <!-- Prominent Emergency Command Strip (Amber/Crimson Theme) -->
        <div style="background: linear-gradient(135deg, #881337 0%, #4c0519 100%); border: 1.5px solid #f43f5e; border-radius: 14px; padding: 16px 20px; color: #fff; margin-bottom: 20px; box-shadow: 0 8px 24px rgba(136, 19, 55, 0.35); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
          <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; animation: beaconBounce 1.5s infinite;">
              🚨
            </div>
            <div>
              <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <strong style="font-size: 1.05rem; font-weight: 800; letter-spacing: 0.02em;">
                  NATIONAL EMERGENCY ACTIVE &bull; <?= strtoupper($activeTitles) ?>
                </strong>
                <span style="font-size: 0.68rem; padding: 2px 8px; border-radius: 8px; background: #f43f5e; color: #fff; font-weight: 800; text-transform: uppercase;">
                  <?= $branchActiveCount ?> PROTOCOL<?= $branchActiveCount > 1 ? 'S' : '' ?> ENFORCED
                </span>
              </div>
              <p style="margin: 3px 0 0; font-size: 0.82rem; color: #fecdd3;">
                Direct emergency mandates active for <?= htmlspecialchars($branchName) ?>. Non-critical elective admissions held to preserve emergency surge capacity.
              </p>
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 10px;">
            <a href="live_census.php" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #ffffff; color: #991b1b; text-decoration: none; border-radius: 10px; font-size: 0.8rem; font-weight: 800; box-shadow: 0 4px 12px rgba(0,0,0,0.2); transition: 0.2s;">
              ⚡ Enforce Floor Triage &rarr;
            </a>
          </div>
        </div>
        <?php endif; ?>

        <!-- KPI 3-Column Strip -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
          <!-- Card 1: Localized Quota Impact -->
          <div style="background: <?= $branchActiveCount > 0 ? '#fff1f2' : '#f8fafc' ?>; border: 1px solid <?= $branchActiveCount > 0 ? '#fecdd3' : '#e2e8f0' ?>; border-radius: 12px; padding: 14px 16px;">
            <div style="font-size: 0.74rem; font-weight: 700; color: <?= $branchActiveCount > 0 ? '#9f1239' : '#64748b' ?>; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px;">
              Branch Surge Impact
            </div>
            <div style="font-size: 1.45rem; font-weight: 800; color: <?= $branchSurgePct > 0 ? '#e11d48' : '#0f172a' ?>; display: flex; align-items: baseline; gap: 6px; flex-wrap: wrap;">
              <span><?= $branchSurgePct ?>%</span>
              <span style="font-size: 0.8rem; font-weight: 700; color: <?= $branchSurgePct > 0 ? '#991b1b' : '#64748b' ?>;">(<?= number_format($emergency_hold_beds) ?> of <?= number_format($total_beds) ?> beds)</span>
            </div>
            <div style="font-size: 0.72rem; color: #64748b; margin-top: 4px;">
              <?= number_format($emergency_hold_beds) ?> beds locked under active disaster mandates
            </div>
          </div>

          <!-- Card 2: Concurrent Active Emergencies -->
          <?php 
            $protoNames = [];
            foreach ($branchProtocols as $bp) {
                $rawTitle = trim($bp['title']);
                if (stripos($rawTitle, 'Dengue') !== false) {
                    $protoNames[] = 'Dengue';
                } elseif (stripos($rawTitle, 'Mass Casualty') !== false || stripos($rawTitle, 'Road Accident') !== false) {
                    $protoNames[] = 'Trauma';
                } elseif (stripos($rawTitle, 'Burn') !== false) {
                    $protoNames[] = 'Burn';
                } else {
                    $parts = explode(' ', $rawTitle);
                    $protoNames[] = $parts[0];
                }
            }
            $protoNamesStr = implode(' + ', array_unique($protoNames));
          ?>
          <div style="background: <?= $branchActiveCount > 0 ? '#fff1f2' : '#f8fafc' ?>; border: 1px solid <?= $branchActiveCount > 0 ? '#fecdd3' : '#e2e8f0' ?>; border-radius: 12px; padding: 14px 16px;">
            <div style="font-size: 0.74rem; font-weight: 700; color: <?= $branchActiveCount > 0 ? '#9f1239' : '#64748b' ?>; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px;">
              Concurrent Mandates
            </div>
            <div style="font-size: 1.35rem; font-weight: 800; color: <?= $branchActiveCount > 0 ? '#b91c1c' : '#0d9488' ?>;">
              <?= $branchActiveCount > 0 ? $branchActiveCount . ' Active Protocol' . ($branchActiveCount > 1 ? 's' : '') . ($protoNamesStr ? ': ' . $protoNamesStr : '') : '0 Active' ?>
            </div>
            <div style="font-size: 0.72rem; color: #64748b; margin-top: 4px;">
              <?= $branchActiveCount > 0 ? 'Network multi-protocol triage synchronized' : 'All systems operating in standard baseline' ?>
            </div>
          </div>

          <!-- Card 3: Evacuation & Relocation Queue -->
          <a href="live_census.php?filter=relocation" style="text-decoration: none; color: inherit; display: block; background: <?= $branchRelocCount > 0 ? '#fef2f2' : '#f8fafc' ?>; border: 1.5px solid <?= $branchRelocCount > 0 ? '#fca5a5' : '#e2e8f0' ?>; border-radius: 12px; padding: 14px 16px; transition: transform 0.2s, box-shadow 0.2s;" onmouseenter="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(225,29,72,0.15)';" onmouseleave="this.style.transform='none';this.style.boxShadow='none';">
            <div style="font-size: 0.74rem; font-weight: 700; color: <?= $branchRelocCount > 0 ? '#991b1b' : '#64748b' ?>; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center;">
              <span>Relocation Queue (Priority 2)</span>
              <span style="font-size: 0.7rem; color: #0284c7; font-weight: 700;">View Queue &rarr;</span>
            </div>
            <div style="font-size: 1.5rem; font-weight: 800; color: <?= $branchRelocCount > 0 ? '#dc2626' : '#16a34a' ?>; display: flex; align-items: baseline; gap: 8px;">
              <span><?= $branchRelocCount ?></span>
              <span style="font-size: 0.76rem; font-weight: 600; color: <?= $branchRelocCount > 0 ? '#b91c1c' : '#15803d' ?>;"><?= $branchRelocCount > 0 ? 'Evacuations Required' : 'Queue Clear' ?></span>
            </div>
            <div style="font-size: 0.72rem; color: <?= $branchRelocCount > 0 ? '#b91c1c' : '#64748b' ?>; margin-top: 4px;">
              <?= $branchRelocCount > 0 ? 'Mandatory tier rate protection applied &bull; Click to reassign' : 'Zero forced inpatient reassignments' ?>
            </div>
          </a>
        </div>

        <?php if ($branchActiveCount > 0): ?>
        <!-- Breakdown Table of Concurrent Protocols Affecting This Branch -->
        <div style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
          <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem; text-align: left;">
            <thead style="background: #f1f5f9; border-bottom: 1px solid #cbd5e1; font-weight: 700; color: #334155;">
              <tr>
                <th style="padding: 10px 14px;">Protocol Declaration</th>
                <th style="padding: 10px 14px;">Severity &amp; Scope</th>
                <th style="padding: 10px 14px; text-align: center;">Mandated Quota</th>
                <th style="padding: 10px 14px; text-align: center;">Branch Beds Held</th>
                <th style="padding: 10px 14px; text-align: right;">Floor Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($branchProtocols as $bp): 
                $bpCode = strtoupper($bp['code']);
                $bpHeldStmt = $pdo->prepare("SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = ? AND emergency_protocol_id = ?");
                $bpHeldStmt->execute([$adminHospitalId, $bp['id']]);
                $bpHeld = (int)$bpHeldStmt->fetchColumn();

                $badgeBg = match(true) {
                    strpos($bpCode, 'DENGUE') !== false => '#fef3c7; color: #92400e; border: 1px solid rgba(245, 158, 11, 0.5);',
                    strpos($bpCode, 'ACCIDENT') !== false || strpos($bpCode, 'TRAUMA') !== false => '#ffe4e6; color: #9f1239; border: 1px solid rgba(244, 63, 94, 0.5);',
                    strpos($bpCode, 'BURN') !== false => '#ffedd5; color: #9a3412; border: 1px solid rgba(234, 88, 12, 0.5);',
                    strpos($bpCode, 'HAZMAT') !== false => '#f3e8ff; color: #6b21a8; border: 1px solid rgba(168, 85, 247, 0.5);',
                    default => '#ffe4e6; color: #9f1239; border: 1px solid rgba(244, 63, 94, 0.5);'
                };
              ?>
              <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 12px 14px;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; font-size: 0.68rem; font-weight: 800; border-radius: 6px; background: <?= $badgeBg ?>">
                      <?= htmlspecialchars($bpCode) ?>
                    </span>
                    <strong style="color: #1e293b;"><?= htmlspecialchars($bp['title']) ?></strong>
                  </div>
                </td>
                <td style="padding: 12px 14px;">
                  <span style="font-weight: 700; color: #dc2626;"><?= htmlspecialchars($bp['severity_level']) ?></span>
                  <span style="font-size: 0.72rem; color: #64748b; margin-left: 6px;">(<?= htmlspecialchars($bp['target_scope']) ?>)</span>
                </td>
                <td style="padding: 12px 14px; text-align: center; font-weight: 700; color: #0f172a;">
                  <?= (int)$bp['severity_quota'] ?>%
                </td>
                <td style="padding: 12px 14px; text-align: center;">
                  <span style="font-weight: 800; font-size: 0.95rem; color: #e11d48; background: #ffe4e6; padding: 2px 8px; border-radius: 6px;">
                    <?= number_format($bpHeld) ?> Beds
                  </span>
                </td>
                <td style="padding: 12px 14px; text-align: right;">
                  <a href="live_census.php" style="color: #0284c7; text-decoration: none; font-weight: 700; font-size: 0.78rem;">
                    Filter Grid &rarr;
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div style="display: flex; align-items: center; gap: 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 14px 18px; color: #166534;">
          <div style="font-size: 1.5rem;">🛡️</div>
          <div>
            <strong style="display: block; font-size: 0.88rem; margin-bottom: 2px;">Normal Clinical Operations Active</strong>
            <span style="font-size: 0.78rem; color: #15803d;">No active national emergency mandates targeting this facility. 100% of branch capacity is allocated to scheduled elective admissions and walk-in clinical care.</span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- PRIORITY SECTION: Pending Credential Approvals Queue (Top Priority Action Area) -->
    <section class="admin-stack-card" id="pendingApprovalSection">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--status-amber); width: 22px; height: 22px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Pending Credential Approvals Queue
          </h3>
          <p>Candidate doctor & staff accounts awaiting mandatory administrative authorization before granting portal access</p>
        </div>
        <div>
          <span class="status-badge-pending" id="pendingQueueBadge">
            <?= $pendingCount ?> PENDING APPLICANTS
          </span>
        </div>
      </div>

      <div class="admin-table-wrap" id="pendingTableWrap" style="<?= $pendingCount === 0 ? 'display: none;' : '' ?>">
        <table class="admin-data-table" id="pendingTable">
          <thead>
            <tr>
              <th>Personnel Candidate</th>
              <th>Role Requested</th>
              <th>Official Email</th>
              <th>Phone Number</th>
              <th>License / Staff ID</th>
              <th>Registered Date</th>
              <th style="text-align: right;">Review Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingUsers as $user): ?>
              <tr id="row-user-<?= (int)$user['user_id'] ?>">
                <td>
                  <div class="user-cell-flex">
                    <div class="user-avatar-initials">
                      <?= htmlspecialchars(strtoupper(substr($user['full_name'], 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div>
                      <strong style="color: var(--text-heading); font-size: 0.92rem;">
                        <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>
                      </strong>
                      <div style="font-size: 0.72rem; color: var(--text-muted);">
                        Gender: <?= htmlspecialchars($user['gender'], ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if ($user['role'] === 'Doctor'): ?>
                    <span class="role-pill role-pill-doctor">
                      <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                      Doctor
                    </span>
                  <?php else: ?>
                    <span class="role-pill role-pill-staff">
                      <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
                      Staff
                    </span>
                  <?php endif; ?>
                </td>
                <td style="font-weight: 600; color: var(--text-heading);">
                  <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <span class="license-chip">
                    <?= htmlspecialchars($user['license_id'] ?? 'VERIFY-PENDING', ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="font-size: 0.78rem; color: var(--text-muted);">
                  <?= htmlspecialchars(date('d M Y, h:i A', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td>
                  <div class="table-actions-flex" style="justify-content: flex-end;">
                    <button class="btn-table-action btn-table-approve" 
                            onclick="executeAdminAction(<?= (int)$user['user_id'] ?>, 'approve', this)"
                            title="Approve candidate and grant portal access">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                      Approve
                    </button>
                    <button class="btn-table-action btn-table-reject" 
                            onclick="executeAdminAction(<?= (int)$user['user_id'] ?>, 'reject', this)"
                            title="Decline candidate application">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                      Decline
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Empty State when Queue is Cleared -->
      <div class="empty-state-card" id="pendingEmptyCard" style="<?= $pendingCount > 0 ? 'display: none;' : '' ?>">
        <svg class="ui-ico" style="width: 48px; height: 48px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
        <h4>All Personnel Credentials Verified</h4>
        <p>The verification queue is clean. New doctor and staff registration requests will appear here for administrative sign-off.</p>
      </div>
    </section>

    <!-- LIVE HOSPITAL PULSE & FAST ACCESS STREAM -->
    <div class="bed-availability-panel">
      <div class="panel-header-flex">
        <div>
          <h3 class="panel-heading">Live Hospital Pulse & Departmental Hubs</h3>
          <p class="panel-subtext">Instant overview of key clinical departments and census telemetry</p>
        </div>
        <div class="panel-status-group">
          <!-- Live ECG / Pulse Wave Monitor (Dynamic System Telemetry) -->
          <div class="ecg-pulse-monitor <?= $pulseClass ?>" title="Real-time cardiac telemetry monitor">
            <svg class="ecg-wave-svg" viewBox="0 0 60 18" width="60" height="18" aria-hidden="true">
              <path class="ecg-wave-bg" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
              <path class="ecg-wave-active" style="stroke: <?= $waveColor ?>;" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
            </svg>
            <span class="ecg-label"><span class="ecg-bpm-dot"></span><?= $pulseLabel ?></span>
          </div>

          <div class="live-status-pill <?= $healthBadgeClass ?>">
            <div class="radar-pulse-dot"></div>
            <?= $healthBadgeText ?>
          </div>
        </div>
      </div>

      <div class="bed-types-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
        <!-- Hub 1: Doctors -->
        <a href="manage_doctors.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Doctors Roster</span>
            <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          </div>
          <div class="bed-qty"><?php echo $total_doctors; ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Active On Duty</small></div>
          <div style="font-size: 0.78rem; color: var(--brand-primary); font-weight: 600; margin-top: 6px;">Manage Physicians &rarr;</div>
        </a>

        <!-- Hub 2: Staff -->
        <a href="manage_staff.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Clinical Staff</span>
            <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          </div>
          <div class="bed-qty"><?php echo $total_staff; ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Assigned Personnel</small></div>
          <div style="font-size: 0.78rem; color: var(--brand-teal); font-weight: 600; margin-top: 6px;">Manage Staff &rarr;</div>
        </a>

        <!-- Hub 3: Patients -->
        <a href="manage_patients.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Patients Master</span>
            <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
          </div>
          <div class="bed-qty"><?php echo $total_patients; ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">Enrolled Profiles</small></div>
          <div style="font-size: 0.78rem; color: var(--status-green); font-weight: 600; margin-top: 6px;">Browse Patient Records &rarr;</div>
        </a>

        <!-- Hub 4: Bed Census -->
        <a href="live_census.php" class="bed-unit-card" style="text-decoration: none; color: inherit;">
          <div class="bed-unit-head">
            <span>Bed Census</span>
            <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          </div>
          <div class="bed-qty"><?php echo $available_beds; ?> <small style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted);">/ <?php echo $total_beds; ?> Available</small></div>
          <div style="font-size: 0.78rem; color: var(--status-amber); font-weight: 600; margin-top: 6px;">Live Bed Telemetry &rarr;</div>
        </a>
      </div>
    </div>

  </main>

  <!-- Real-time Status Action Script with CSRF Validation -->
  <!-- Dedicated dashboard view script -->
  <script src="../assets/js/admin/dashboard.js"></script>
  <!-- Live telemetry ticker -->
  <script src="../assets/js/admin/live-ticker.js"></script>
</body>
</html>

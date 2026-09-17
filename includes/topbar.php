<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Global Admin Top Navigation Bar Partial
 * 
 * Features:
 * - Dynamic Clinical ECG Telemetry Pill (ECG wave animation, BPM, status, active beds)
 * - Real-time Hospital Occupancy & ICU Stress Index Calculation
 * - Dynamic Breadcrumb Navigation mapped from active routes
 * - Administrative Profile & Quick Sign Out Actions
 */

// 1. Single-Render Guard
if (defined('MEDPULSE_TOPBAR_RENDERED')) {
    return;
}
define('MEDPULSE_TOPBAR_RENDERED', true);

// 2. Global Hospital Clinical Telemetry Helper
if (!function_exists('getGlobalHospitalTelemetry')) {
    function getGlobalHospitalTelemetry(?PDO $pdo = null): array {
        $fallback = [
            'bpm'         => '72 BPM',
            'class'       => 'telemetry-normal',
            'label'       => 'STABLE',
            'speed'       => '2s',
            'active_beds' => 500,
            'critical_pct'=> 0
        ];

        if (!$pdo) {
            return $fallback;
        }

        try {
            // 1. Total Active Beds across hospital
            $totalBedsStmt = $pdo->query("SELECT COUNT(*) FROM hospital_beds");
            $totalBeds = (int)$totalBedsStmt->fetchColumn();
            $activeBeds = $totalBeds > 0 ? $totalBeds : 500;

            // 2. Critical Care (ICU / CCU) Bed Statistics
            $icuStmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_icu_ccu,
                    SUM(CASE WHEN status = 'Occupied' THEN 1 ELSE 0 END) as occupied_icu_ccu
                FROM hospital_beds
                WHERE ward_type IN ('ICU', 'CCU')
            ");
            $icuRow = $icuStmt->fetch(PDO::FETCH_ASSOC);

            $totalIcu = (int)($icuRow['total_icu_ccu'] ?? 0);
            $occupiedIcu = (int)($icuRow['occupied_icu_ccu'] ?? 0);
            $availableIcu = max(0, $totalIcu - $occupiedIcu);
            $icuPct = $totalIcu > 0 ? ($occupiedIcu / $totalIcu) * 100 : 0;

            // 3. Clinical Stress Index Classification:
            // - Critical (>= 90% ICU occupancy or 0 ICU beds available)
            if ($totalIcu > 0 && ($availableIcu === 0 || $icuPct >= 90)) {
                return [
                    'bpm'         => '124 BPM',
                    'class'       => 'telemetry-critical',
                    'label'       => 'CODE SURGE',
                    'speed'       => '0.7s',
                    'active_beds' => $activeBeds,
                    'critical_pct'=> round($icuPct)
                ];
            // - Elevated / Warning (70% - 89% ICU occupancy)
            } elseif ($icuPct >= 70) {
                return [
                    'bpm'         => '98 BPM',
                    'class'       => 'telemetry-warning',
                    'label'       => 'HIGH LOAD',
                    'speed'       => '1.2s',
                    'active_beds' => $activeBeds,
                    'critical_pct'=> round($icuPct)
                ];
            // - Normal (< 70% ICU occupancy)
            } else {
                return [
                    'bpm'         => '72 BPM',
                    'class'       => 'telemetry-normal',
                    'label'       => 'STABLE',
                    'speed'       => '2s',
                    'active_beds' => $activeBeds,
                    'critical_pct'=> round($icuPct)
                ];
            }
        } catch (Throwable $e) {
            error_log("Global Telemetry Engine Error: " . $e->getMessage());
            return $fallback;
        }
    }
}

// 3. Resolve Telemetry Metrics (Prioritize page-passed values if present, else run global calculation)
if (isset($telemetry_bpm, $telemetry_class, $telemetry_label, $total_active_beds)) {
    $currentTelemetry = [
        'bpm'         => $telemetry_bpm,
        'class'       => $telemetry_class,
        'label'       => $telemetry_label,
        'speed'       => $telemetry_speed ?? '2s',
        'active_beds' => (int)$total_active_beds,
        'critical_pct'=> $icuOccupancyPct ?? 0
    ];
} else {
    $currentTelemetry = getGlobalHospitalTelemetry($pdo ?? null);
}

// Ensure scoped variables exist for consumers
$telemetry_bpm      = $currentTelemetry['bpm'];
$telemetry_class    = $currentTelemetry['class'];
$telemetry_label    = $currentTelemetry['label'];
$total_active_beds  = $currentTelemetry['active_beds'];
$telemetry_speed    = $currentTelemetry['speed'];

// 4. Resolve Active Route & Page Title
$activeRoute = basename($_SERVER['PHP_SELF'] ?? '');
$routeTitleMap = [
    'dashboard.php'         => 'Executive Overview',
    'live_census.php'       => 'Live Bed & Census Telemetry',
    'bed_management.php'    => 'Live Bed & Census Telemetry',
    'inpatient_care.php'    => 'Inpatient Care & Teams',
    'manage_patients.php'   => 'Patient Registry',
    'patient_registry.php'  => 'Patient Registry',
    'patients.php'          => 'Patient Registry',
    'manage_doctors.php'    => 'Doctors Roster',
    'doctors.php'           => 'Doctors Roster',
    'manage_staff.php'      => 'Clinical & Admin Staff',
    'staff.php'             => 'Clinical & Admin Staff',
    'verification_queue.php'=> 'Verification Queue',
    'audit_logs.php'        => 'Audit Logs & Security',
    'billing_management.php'=> 'Billing & Invoices',
    'settings.php'          => 'System Configurations'
];
$displayTitle = $pageTitle ?? ($routeTitleMap[$activeRoute] ?? 'Operations Console');
$adminDisplayName = $adminName ?? 'Administrator';
$adminInitial = strtoupper(substr($adminDisplayName, 0, 1));
?>

<!-- Ensure Global Telemetry Stylesheet is Active -->
<link rel="stylesheet" href="../assets/css/admin/live-pulse.css?v=<?= time() ?>">

<!-- Global Admin Sticky Top Navigation Bar -->
<header class="admin-topbar" id="adminGlobalTopbar" aria-label="Hospital System Telemetry Navigation">
  <div class="admin-topbar-left">
    <nav class="admin-topbar-breadcrumb" aria-label="Breadcrumb">
      <a href="dashboard.php" class="crumb-root" title="MedPulse Executive Overview">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
        <span>MedPulse</span>
      </a>
      <span class="crumb-sep">/</span>
      <span class="crumb-current"><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></span>
    </nav>
  </div>

  <div class="admin-topbar-right">
    <!-- Dynamic Clinical ECG Telemetry Pill -->
    <div class="ecg-pulse-monitor telemetry-pill <?= htmlspecialchars($telemetry_class, ENT_QUOTES, 'UTF-8') ?>" 
         id="globalTelemetryPill"
         style="cursor: default;" 
         title="Real-time clinical telemetry: <?= htmlspecialchars($telemetry_label, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($telemetry_bpm, ENT_QUOTES, 'UTF-8') ?> &bull; <?= (int)$total_active_beds ?> Beds Active)">
      <svg class="ecg-wave-svg" viewBox="0 0 60 18" width="60" height="18" aria-hidden="true">
        <path class="ecg-wave-bg" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
        <path class="ecg-wave-active" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
      </svg>
      <span class="ecg-label">
        <span class="ecg-bpm-dot"></span>
        <span class="ecg-bpm-val"><?= htmlspecialchars($telemetry_bpm, ENT_QUOTES, 'UTF-8') ?></span>
        &bull;
        <span class="ecg-status-text"><?= htmlspecialchars($telemetry_label, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="ecg-label-beds">&bull; <?= (int)$total_active_beds ?> BEDS ACTIVE</span>
      </span>
    </div>

    <!-- Admin Profile Actions -->
    <div class="admin-topbar-profile">
      <div class="admin-topbar-avatar" title="<?= htmlspecialchars($adminDisplayName, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($adminInitial, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div class="admin-topbar-info">
        <span class="admin-topbar-name"><?= htmlspecialchars($adminDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="admin-topbar-role">Super Admin</span>
      </div>
      <a href="../logout.php" class="admin-topbar-logout" title="Sign Out of MedPulse" aria-label="Sign Out">
        <svg class="ui-ico" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      </a>
    </div>
  </div>
</header>

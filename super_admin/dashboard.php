<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — Network Command Center Dashboard
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../backend/Services/EmergencyProtocolService.php';

use MedPulse\Services\EmergencyProtocolService;

$emergencyService    = new EmergencyProtocolService($pdo);
$activeProtocols     = $emergencyService->getActiveProtocols();
$activeCount         = count($activeProtocols);
$activeEmergency     = !empty($activeProtocols) ? $activeProtocols[0] : null;

$totalSurgeHeldBeds  = 0;
$totalSurgeRelocBeds = 0;
foreach ($activeProtocols as $p) {
    $totalSurgeHeldBeds  += (int)($p['live_held_count'] ?? 0);
    $totalSurgeRelocBeds += (int)($p['live_relocating_count'] ?? 0);
}

// --- Selected Hospital Filter (from query string) ---
$filterHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;

try {
    // All hospitals for the switcher dropdown
    $allHospitals = $pdo->query("SELECT hospital_id, name, city FROM hospitals ORDER BY hospital_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Build WHERE clause for hospital filter
    $whereHospital = $filterHospitalId > 0 ? "AND hb.hospital_id = {$filterHospitalId}" : "";

    // --- Top KPI Metrics ---
    $totalNetworkHospitals = count($allHospitals);

    $totalNetworkBeds = (int)$pdo->query(
        "SELECT COUNT(*) FROM hospital_beds hb WHERE 1=1 {$whereHospital}"
    )->fetchColumn();

    $availableLiveBeds = (int)$pdo->query(
        "SELECT COUNT(*) FROM hospital_beds hb WHERE hb.status = 'Available' {$whereHospital}"
    )->fetchColumn();

    $criticalOccupiedBeds = (int)$pdo->query(
        "SELECT COUNT(*) FROM hospital_beds hb WHERE hb.status = 'Occupied' {$whereHospital}"
    )->fetchColumn();

    $emergencyHoldBeds = (int)$pdo->query(
        "SELECT COUNT(*) FROM hospital_beds hb WHERE hb.status = 'Emergency Hold' {$whereHospital}"
    )->fetchColumn();

    // Occupancy percentage
    $occupancyPct = $totalNetworkBeds > 0 ? round(($criticalOccupiedBeds / $totalNetworkBeds) * 100) : 0;

    // Cumulative surge quota percentage across network
    $cumulativeSurgeQuota = 0;
    if ($activeCount > 0) {
        if ($totalNetworkBeds > 0) {
            $cumulativeSurgeQuota = round(($totalSurgeHeldBeds / $totalNetworkBeds) * 100);
        } else {
            $cumulativeSurgeQuota = array_sum(array_column($activeProtocols, 'severity_quota'));
        }
    }

    // --- Multi-Hospital Live Status Table ---
    $hospitalStatusSQL = "
        SELECT
            h.hospital_id,
            h.name,
            h.city,
            h.contact_number,
            COUNT(hb.bed_id)                                       AS total_beds,
            SUM(hb.status = 'Available')                           AS available_beds,
            SUM(hb.status = 'Occupied')                            AS occupied_beds,
            SUM(hb.status = 'Emergency Hold')                      AS hold_beds,
            SUM(hb.relocation_status = 'PENDING_RELOCATION')       AS reloc_beds,
            SUM((hb.ward_type LIKE '%ICU%' OR hb.ward_type LIKE '%HDU%' OR hb.ward_type = 'CCU') AND hb.status = 'Available') AS icu_vacant,
            SUM(hb.ward_type LIKE '%ICU%' OR hb.ward_type LIKE '%HDU%' OR hb.ward_type = 'CCU') AS icu_total
        FROM hospitals h
        LEFT JOIN hospital_beds hb ON hb.hospital_id = h.hospital_id
        " . ($filterHospitalId > 0 ? "WHERE h.hospital_id = {$filterHospitalId}" : "") . "
        GROUP BY h.hospital_id
        ORDER BY h.hospital_id ASC
    ";
    $hospitalRows = $pdo->query($hospitalStatusSQL)->fetchAll(PDO::FETCH_ASSOC);

    // --- Network Bed Breakdown by Ward Type ---
    $wardBreakdownSQL = "
        SELECT hb.ward_type,
               COUNT(*) AS total,
               SUM(hb.status='Available') AS available
        FROM hospital_beds hb
        WHERE 1=1 {$whereHospital}
        GROUP BY hb.ward_type
        ORDER BY hb.ward_type ASC
    ";
    $wardBreakdown = $pdo->query($wardBreakdownSQL)->fetchAll(PDO::FETCH_ASSOC);

    // --- Recent Audit Logs (Network-wide) ---
    $recentLogs = $pdo->query("
        SELECT log_id, action, description, category, ip_address, created_at
        FROM audit_logs
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Relative time helper
    if (!function_exists('saRelTime')) {
        function saRelTime($dt) {
            $ts   = is_numeric($dt) ? (int)$dt : strtotime($dt);
            if (!$ts) return 'Just now';
            $diff = time() - $ts;
            if ($diff < 60)     return 'Just now';
            if ($diff < 3600)   return max(1, round($diff/60)) . 'm ago';
            if ($diff < 86400)  return max(1, round($diff/3600)) . 'h ago';
            if ($diff < 604800) return max(1, round($diff/86400)) . 'd ago';
            return date('M j', $ts);
        }
    }

} catch (PDOException $e) {
    error_log("Super Admin Dashboard DB error: " . $e->getMessage());
    $allHospitals           = [];
    $totalNetworkHospitals  = 3;
    $totalNetworkBeds       = 0;
    $availableLiveBeds      = 0;
    $criticalOccupiedBeds   = 0;
    $occupancyPct           = 0;
    $hospitalRows           = [];
    $wardBreakdown          = [];
    $recentLogs             = [];
}

// Occupancy badge color
if ($occupancyPct >= 85) {
    $occBadgeClass = 'badge-red';
    $networkStatus = 'HIGH LOAD';
    $networkStatusClass = 'status-high-load';
} elseif ($occupancyPct >= 60) {
    $occBadgeClass = 'badge-amber';
    $networkStatus = 'MODERATE';
    $networkStatusClass = '';
} else {
    $occBadgeClass = 'badge-green';
    $networkStatus = 'SYSTEM NORMAL';
    $networkStatusClass = '';
}

// Facility Medical Crest Helper
if (!function_exists('getHospitalCrest')) {
    function getHospitalCrest(int $hospitalId, string $name = ''): string {
        switch ($hospitalId) {
            case 1:
                // MedPulse: Modern pulse cross with vibrant teal/cyan gradient
                return '<div class="hosp-crest-avatar hosp-crest-medpulse" title="MedPulse Hospital & Specialty Care">
                  <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="10" fill="url(#crestMedPulse)"/>
                    <defs>
                      <linearGradient id="crestMedPulse" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
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
                    <rect width="36" height="36" rx="10" fill="url(#crestSquare)"/>
                    <defs>
                      <linearGradient id="crestSquare" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
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
                    <rect width="36" height="36" rx="10" fill="url(#crestUnited)"/>
                    <defs>
                      <linearGradient id="crestUnited" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
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
                    <rect width="36" height="36" rx="10" fill="url(#crestUmch)"/>
                    <defs>
                      <linearGradient id="crestUmch" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
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
                    <rect width="36" height="36" rx="10" fill="url(#crestEvercare)"/>
                    <defs>
                      <linearGradient id="crestEvercare" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
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
                    <rect width="36" height="36" rx="10" fill="url(#crestNibps)"/>
                    <defs>
                      <linearGradient id="crestNibps" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
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
                return '<div class="hosp-crest-avatar hosp-crest-default" style="background:var(--sa-gradient);">
                  <span style="font-size:0.75rem;font-weight:800;color:#fff;">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</span>
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
  <title>MedPulse | Super Admin — Network Command Center</title>
  <meta name="description" content="MedPulse Super Admin Network Dashboard — multi-hospital real-time bed monitor and operations center.">

  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%237c3aed'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><polygon points='32,8 38,22 54,24 42,36 45,52 32,44 19,52 22,36 10,24 26,22' fill='rgba(255,255,255,0.9)'/></svg>">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Design System (Shared with all portals) -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/live-ticker.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/admin/live-pulse.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css?v=<?= time() ?>">

  <style>
    /* =========================================================
       Super Admin — Exclusive Overrides & Network UI Components
       Inherits 100% from patient_dashboard.css design system
    ========================================================= */

    /* Purple-teal brand accent for super_admin role */
    :root {
      --sa-accent:        #7c3aed;
      --sa-accent-soft:   rgba(124, 58, 237, 0.10);
      --sa-accent-border: rgba(124, 58, 237, 0.22);
      --sa-gradient:      linear-gradient(135deg, #7c3aed 0%, #0d9488 100%);
    }

    /* ---- Top bar hospital switcher ---- */
    .sa-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 22px;
      padding: 14px 20px;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-lg);
      box-shadow: 0 1px 4px rgba(0,0,0,.04);
    }

    .sa-topbar-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sa-network-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 12px;
      background: var(--sa-accent-soft);
      border: 1px solid var(--sa-accent-border);
      border-radius: 40px;
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--sa-accent);
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .sa-network-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: var(--sa-accent);
      animation: saPulse 1.8s ease infinite;
    }

    @keyframes saPulse {
      0%,100% { transform: scale(1); opacity:1; }
      50%      { transform: scale(1.5); opacity:.5; }
    }

    .btn-declare-emergency {
      background: linear-gradient(135deg, #e11d48 0%, #dc2626 100%) !important;
      box-shadow: 0 4px 16px rgba(225, 29, 72, 0.40) !important;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
    }
    .btn-declare-emergency:hover {
      background: linear-gradient(135deg, #be123c 0%, #b91c1c 100%) !important;
      transform: translateY(-2px);
      box-shadow: 0 6px 22px rgba(225, 29, 72, 0.55) !important;
    }

    .sa-switcher-group {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sa-switcher-label {
      font-size: 0.78rem;
      font-weight: 600;
      color: var(--text-muted);
    }

    .sa-hospital-select {
      appearance: none;
      background: var(--surface);
      border: 1.5px solid var(--surface-border);
      border-radius: 10px;
      padding: 8px 36px 8px 14px;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-heading);
      font-family: inherit;
      cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 16px;
      transition: border-color .2s, box-shadow .2s;
    }

    .sa-hospital-select:focus {
      outline: none;
      border-color: var(--sa-accent);
      box-shadow: 0 0 0 3px var(--sa-accent-soft);
    }

    .sa-apply-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 18px;
      background: var(--sa-gradient);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 0.82rem;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      transition: opacity .2s, transform .15s;
    }

    .sa-apply-btn:hover { opacity: .88; transform: translateY(-1px); }

    /* ---- KPI cards — SA purple accent ---- */
    .stat-card-executive.sa-purple .stat-card-head svg { stroke: var(--sa-accent); }
    .badge-purple {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 10px; border-radius: 20px;
      background: var(--sa-accent-soft); color: var(--sa-accent);
      font-size: 0.72rem; font-weight: 700;
    }

    /* ---- Hospital status table ---- */
    .sa-table-section {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(0,0,0,.04);
      margin-bottom: 24px;
    }

    .sa-section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      padding: 22px 24px 16px;
      border-bottom: 1px solid var(--surface-border-subtle);
    }

    .sa-section-title {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sa-section-title h3 {
      font-size: 1rem;
      font-weight: 700;
      color: var(--text-heading);
      margin: 0;
    }

    .sa-section-title p {
      font-size: 0.78rem;
      color: var(--text-muted);
      margin: 2px 0 0;
    }

    /* re-use admin table styles but scroll on mobile */
    .sa-table-wrap {
      overflow-x: auto;
    }

    .sa-data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.83rem;
    }

    .sa-data-table thead th {
      padding: 11px 18px;
      background: var(--surface-border-subtle);
      color: var(--text-muted);
      font-weight: 700;
      font-size: 0.72rem;
      letter-spacing: .05em;
      text-transform: uppercase;
      text-align: left;
      white-space: nowrap;
    }

    .sa-data-table tbody tr {
      border-top: 1px solid var(--surface-border-subtle);
      transition: background .15s;
    }

    .sa-data-table tbody tr:hover { background: rgba(124, 58, 237, 0.04); }

    .sa-data-table tbody td {
      padding: 13px 18px;
      color: var(--text-body);
      vertical-align: middle;
    }

    /* ---- Occupancy progress bar ---- */
    .occ-bar-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 120px;
    }

    .occ-bar-bg {
      flex: 1;
      height: 6px;
      background: var(--surface-border);
      border-radius: 10px;
      overflow: hidden;
    }

    .occ-bar-fill {
      height: 100%;
      border-radius: 10px;
      transition: width .5s ease;
    }

    .occ-bar-fill.fill-green  { background: var(--status-green); }
    .occ-bar-fill.fill-amber  { background: var(--status-amber); }
    .occ-bar-fill.fill-red    { background: var(--status-red); }

    .occ-bar-pct {
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--text-heading);
      min-width: 32px;
    }

    /* ---- Hospital name cell ---- */
    .hosp-name-cell {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .hosp-avatar {
      width: 36px; height: 36px;
      border-radius: 10px;
      background: var(--sa-gradient);
      display: flex; align-items: center; justify-content: center;
      font-size: 0.72rem; font-weight: 800; color: #fff;
      flex-shrink: 0;
    }

    /* ---- Network status badge ---- */
    .net-status-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 700;
    }

    .net-status-badge.status-normal   { background: var(--status-green-bg);  color: var(--status-green);  }
    .net-status-badge.status-moderate { background: var(--status-amber-bg);  color: var(--status-amber);  }
    .net-status-badge.status-high     { background: var(--status-red-bg);    color: var(--status-red);    }

    .net-status-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    /* ---- Ward breakdown grid ---- */
    .ward-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 14px;
      padding: 20px 24px;
    }

    .ward-card {
      padding: 16px;
      background: var(--surface-border-subtle);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-md);
      transition: border-color .2s, box-shadow .2s, transform .2s;
      cursor: pointer;
      text-decoration: none;
      display: block;
      color: inherit;
    }

    .ward-card:hover {
      border-color: var(--sa-accent-border);
      box-shadow: 0 8px 20px rgba(124, 58, 237, 0.12);
      transform: translateY(-3px);
    }

    .ward-card-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-muted);
      letter-spacing: .05em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    .ward-card-avail {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1;
      margin-bottom: 4px;
    }

    .ward-card-total {
      font-size: 0.76rem;
      color: var(--text-muted);
    }

    /* ---- Audit log list ---- */
    .sa-audit-list {
      list-style: none;
      padding: 0 24px 20px;
    }

    .sa-audit-item {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 11px 0;
      border-bottom: 1px solid var(--surface-border-subtle);
    }

    .sa-audit-item:last-child { border-bottom: none; }

    .sa-audit-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: var(--sa-accent);
      margin-top: 4px;
      flex-shrink: 0;
    }

    .sa-audit-body { flex: 1; min-width: 0; }

    .sa-audit-action {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-heading);
      margin-bottom: 2px;
    }

    .sa-audit-desc {
      font-size: 0.75rem;
      color: var(--text-muted);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .sa-audit-time {
      font-size: 0.72rem;
      color: var(--text-muted);
      white-space: nowrap;
      flex-shrink: 0;
    }

    /* ---- Two-column bottom layout ---- */
    .sa-bottom-grid {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
      margin-bottom: 24px;
    }

    @media (max-width: 960px) {
      .sa-bottom-grid { grid-template-columns: 1fr; }
    }

    /* ---- Welcome banner SA variant ---- */
    .sa-welcome-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      background: var(--sa-gradient);
      color: #fff;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    /* ---- Live refresh indicator ---- */
    .sa-live-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: var(--status-green-bg);
      color: var(--status-green);
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 700;
    }

    .sa-live-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: var(--status-green);
      animation: saPulse 1.4s ease infinite;
    }

    /* ---- Number emphasis ---- */
    .stat-card-number { font-variant-numeric: tabular-nums; }

    /* badge-red for high-occupancy */
    .badge-red {
      display: inline-flex; align-items:center; gap:5px;
      padding: 4px 10px; border-radius: 20px;
      background: var(--status-red-bg); color: var(--status-red);
      font-size: 0.72rem; font-weight: 700;
    }

    /* =========================================================
       CROSS-HOSPITAL NETWORK TELEMETRY STREAM TICKER
       ========================================================= */
    .net-ticker-strip {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 18px;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-lg);
      margin-bottom: 18px;
      overflow: hidden;
      min-height: 40px;
      position: relative;
    }
    .net-ticker-label {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.68rem;
      font-weight: 800;
      color: var(--sa-accent);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      white-space: nowrap;
      flex-shrink: 0;
      padding-right: 10px;
      border-right: 1px solid var(--surface-border);
    }
    .net-ticker-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--sa-accent);
      animation: saPulse 1.6s ease infinite;
      flex-shrink: 0;
    }
    .net-ticker-viewport {
      flex: 1;
      overflow: hidden;
      position: relative;
      height: 22px;
    }
    .net-ticker-item {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      gap: 10px;
      opacity: 0;
      transform: translateY(6px);
      transition: opacity 0.4s ease, transform 0.4s ease;
      white-space: nowrap;
    }
    .net-ticker-item.active {
      opacity: 1;
      transform: translateY(0);
    }
    .ticker-facility-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .ticker-dot-normal   { background: #10b981; animation: saPulse 2s ease infinite; }
    .ticker-dot-high     { background: #f59e0b; animation: saPulse 1.2s ease infinite; }
    .ticker-dot-critical { background: #ef4444; animation: saPulse 0.8s ease infinite; }
    .net-ticker-text {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-body);
    }
    .net-ticker-text strong {
      font-weight: 800;
      color: var(--text-heading);
    }
    .net-ticker-text .ticker-tag {
      display: inline-block;
      padding: 1px 6px;
      border-radius: 4px;
      font-size: 0.68rem;
      font-weight: 700;
      margin-left: 4px;
      vertical-align: middle;
    }
    .tag-critical { background: #fee2e2; color: #b91c1c; }
    .tag-high     { background: #fef3c7; color: #92400e; }
    .tag-normal   { background: #dcfce7; color: #15803d; }
    .tag-surge    { background: #fce7f3; color: #9d174d; }
    .net-ticker-counter {
      font-size: 0.68rem;
      color: var(--text-muted);
      font-weight: 600;
      white-space: nowrap;
      flex-shrink: 0;
      font-variant-numeric: tabular-nums;
    }
    .sa-telemetry-badge {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 6px 14px;
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
      animation: ecgStrokeSweep 2.2s linear infinite;
    }
    .telemetry-surge .ecg-pulse-line {
      stroke: #f43f5e;
      animation: ecgStrokeSweep 1.1s linear infinite;
    }
    @keyframes ecgStrokeSweep {
      0% {
        stroke-dashoffset: 80;
      }
      50% {
        stroke-dashoffset: 0;
      }
      100% {
        stroke-dashoffset: -80;
      }
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
      animation: bpmSurgePulse 0.9s ease-in-out infinite;
    }
    @keyframes bpmSurgePulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.75; transform: scale(1.05); }
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
      animation: telRing 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .telemetry-surge .telemetry-pulse-ring {
      background: #f43f5e;
      animation: telRing 0.9s cubic-bezier(0, 0, 0.2, 1) infinite;
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
    @keyframes telRing {
      75%, 100% {
        transform: scale(2.6);
        opacity: 0;
      }
    }

    /* =========================================================
       FULLY INTERACTIVE KPI SUMMARY CARDS
       ========================================================= */
    .stat-card-interactive {
      position: relative;
      text-decoration: none;
      display: flex;
      flex-direction: column;
      cursor: pointer;
      overflow: hidden;
      transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.2s ease;
    }
    .stat-card-interactive::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3.5px;
      background: var(--card-accent-gradient, var(--sa-gradient));
      opacity: 0;
      transform: scaleX(0.4);
      transition: opacity 0.25s ease, transform 0.25s ease;
    }
    .stat-card-interactive:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 28px -4px rgba(15, 23, 42, 0.12), 0 4px 10px -2px rgba(15, 23, 42, 0.06);
    }
    .stat-card-interactive:hover::before {
      opacity: 1;
      transform: scaleX(1);
    }
    .stat-card-interactive.kpi-hosp::before {
      --card-accent-gradient: linear-gradient(90deg, #7c3aed, #0d9488);
    }
    .stat-card-interactive.kpi-beds::before {
      --card-accent-gradient: linear-gradient(90deg, #2563eb, #0284c7);
    }
    .stat-card-interactive.kpi-avail::before {
      --card-accent-gradient: linear-gradient(90deg, #10b981, #059669);
    }
    .stat-card-interactive.kpi-occ::before {
      --card-accent-gradient: linear-gradient(90deg, #f59e0b, #ef4444);
    }
    .kpi-head-action {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .kpi-arrow-ico {
      width: 14px;
      height: 14px;
      stroke: var(--text-muted);
      fill: none;
      stroke-width: 2.5;
      stroke-linecap: round;
      stroke-linejoin: round;
      opacity: 0.55;
      transition: transform 0.2s ease, opacity 0.2s ease, stroke 0.2s ease;
    }
    .stat-card-interactive:hover .kpi-arrow-ico {
      transform: translateX(3px);
      opacity: 1;
      stroke: var(--text-heading);
    }

    /* =========================================================
       MULTI-HOSPITAL LIVE STATUS TABLE & SVG CRESTS
       ========================================================= */
    .sa-clickable-row {
      cursor: pointer;
      transition: background-color 0.15s ease;
    }
    .sa-clickable-row:hover {
      background-color: rgba(241, 245, 249, 0.8) !important;
    }
    .sa-btn-drilldown {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 6px 12px;
      font-size: 0.74rem;
      font-weight: 700;
      color: #7c3aed;
      background: rgba(124, 58, 237, 0.08);
      border: 1px solid rgba(124, 58, 237, 0.22);
      border-radius: 8px;
      text-decoration: none;
      transition: all 0.18s ease;
    }
    .sa-btn-drilldown:hover {
      background: #7c3aed;
      color: #ffffff;
      border-color: #7c3aed;
      box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
    }
    .drill-arrow {
      transition: transform 0.18s ease;
      display: inline-block;
    }
    .sa-btn-drilldown:hover .drill-arrow {
      transform: translateX(3px);
    }
    .hosp-crest-avatar {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    .hosp-crest-avatar svg {
      width: 100%;
      height: 100%;
      display: block;
      border-radius: 10px;
    }
    /* =========================================================
       DYNAMIC ALERT CAROUSEL TICKER & MULTI-DISASTER BANNER
       ========================================================= */
    .disaster-carousel-container {
      background: linear-gradient(135deg, #4c0519 0%, #1f0710 100%);
      border: 1.5px solid #f43f5e;
      border-radius: 18px;
      overflow: hidden;
      margin-bottom: 24px;
      box-shadow: 0 10px 30px rgba(136, 19, 55, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
      position: relative;
    }
    .carousel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      padding: 12px 20px;
      background: rgba(0, 0, 0, 0.35);
      border-bottom: 1px solid rgba(244, 63, 94, 0.25);
    }
    .carousel-header-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .carousel-live-ping {
      position: relative;
      display: inline-flex;
      width: 10px;
      height: 10px;
    }
    .carousel-live-ping .ping-ring {
      position: absolute;
      inset: 0;
      border-radius: 50%;
      background: #f43f5e;
      animation: saPingRing 1.2s infinite;
    }
    .carousel-live-ping .ping-core {
      position: relative;
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #e11d48;
    }
    .carousel-header-title {
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      color: #fecdd3;
      text-transform: uppercase;
    }
    .carousel-pills {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }
    .carousel-pill-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      color: #fda4af;
      font-size: 0.72rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: inherit;
    }
    .carousel-pill-btn:hover {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
    }
    .carousel-pill-btn.active {
      background: #e11d48;
      border-color: #f43f5e;
      color: #fff;
      box-shadow: 0 2px 8px rgba(225, 29, 72, 0.4);
    }
    .pill-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      display: inline-block;
    }
    .carousel-slides-track {
      position: relative;
      min-height: 140px;
    }
    .carousel-slide {
      display: none;
      padding: 18px 20px;
      opacity: 0;
      transform: translateY(6px);
      transition: opacity 0.35s ease, transform 0.35s ease;
    }
    .carousel-slide.active {
      display: block;
      opacity: 1;
      transform: translateY(0);
    }
    .slide-content-grid {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 20px;
      align-items: center;
    }
    @media (max-width: 860px) {
      .slide-content-grid {
        grid-template-columns: 1fr;
      }
    }
    .slide-title-row {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 12px;
    }
    .slide-icon {
      font-size: 1.8rem;
      line-height: 1;
    }
    .slide-title {
      font-size: 1.12rem;
      font-weight: 800;
      color: #fff;
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .slide-quota-badge {
      font-size: 0.68rem;
      padding: 2px 8px;
      border-radius: 10px;
      color: #fff;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .slide-id-tag {
      font-size: 0.68rem;
      color: #fda4af;
      font-weight: 700;
      opacity: 0.8;
    }
    .slide-desc {
      font-size: 0.82rem;
      color: #fecdd3;
      margin-top: 4px;
      max-width: 700px;
      line-height: 1.4;
    }
    .slide-facilities-row {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 6px;
    }
    .facilities-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: #fda4af;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .facilities-chips {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }
    .facility-chip {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 2px 8px;
      border-radius: 6px;
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 600;
    }
    .fac-ico {
      width: 12px;
      height: 12px;
      stroke: #fda4af;
      fill: none;
      stroke-width: 2;
    }
    .slide-actions-col {
      display: flex;
      flex-direction: column;
      gap: 12px;
      align-items: flex-end;
    }
    @media (max-width: 860px) {
      .slide-actions-col {
        align-items: flex-start;
      }
    }
    .slide-metrics-row {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .slide-metric {
      padding: 6px 12px;
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 8px;
      text-align: center;
      min-width: 70px;
    }
    .sm-val {
      display: block;
      font-size: 1.05rem;
      font-weight: 800;
      font-family: ui-monospace, monospace;
    }
    .sm-lbl {
      display: block;
      font-size: 0.65rem;
      color: #cbd5e1;
      text-transform: uppercase;
      font-weight: 700;
    }
    .slide-btn-group {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .btn-stand-down-single {
      padding: 7px 14px;
      background: rgba(239, 68, 68, 0.2);
      border: 1px solid #ef4444;
      color: #fecdd3;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      font-family: inherit;
    }
    .btn-stand-down-single:hover {
      background: #ef4444;
      color: #fff;
    }
    .btn-surge-monitor {
      padding: 7px 14px;
      background: #ffffff;
      color: #991b1b;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 800;
      text-decoration: none;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
      transition: transform 0.15s ease;
    }
    .btn-surge-monitor:hover {
      transform: translateY(-1px);
    }
    .carousel-progress-track {
      height: 3px;
      background: rgba(255, 255, 255, 0.1);
      position: relative;
    }
    .carousel-progress-bar {
      height: 100%;
      background: #f43f5e;
      width: 0%;
      transition: width 0.1s linear;
    }
  </style>
</head>
<body>

  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <!-- Central Primary Workspace -->
  <main class="viewport-full">

    <!-- Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">
          <svg style="width:12px;height:12px;stroke:#fff;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
          Super Administrator
        </div>
        <h1>
          <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>!
          <svg class="ui-ico" style="stroke: var(--sa-accent); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </h1>
        <p>Network Command Center: Real-time telemetry, cross-hospital bed monitor, and network governance synchronized across all facilities.</p>
      </div>
      <div class="banner-actions">
        <?php if ($activeCount === 0): ?>
          <a href="bed_monitor.php" class="btn-action-gradient btn-declare-emergency" style="background:linear-gradient(135deg, #e11d48 0%, #dc2626 100%);color:#ffffff;text-decoration:none;display:inline-flex;align-items:center;gap:8px;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;box-shadow:0 4px 16px rgba(225,29,72,0.40);border:1px solid rgba(255,255,255,0.22);padding:9px 18px;border-radius:10px;transition:all 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
            <svg class="ui-ico ui-ico-sm" style="stroke:white;fill:none;stroke-width:2.2;width:17px;height:17px;animation:saPulse 1.2s infinite;flex-shrink:0;" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <span style="display:inline-flex;align-items:center;gap:6px;">
              <span style="display:inline-block;animation:saPulse 1s infinite;">🚨</span>
              DECLARE EMERGENCY PROTOCOL
            </span>
          </a>
        <?php elseif ($activeCount === 1): ?>
          <a href="bed_monitor.php" class="btn-action-gradient" style="background:linear-gradient(135deg,#e11d48 0%,#b91c1c 100%);text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 4px 14px rgba(225,29,72,.35);">
            <span style="display:inline-block;animation:saPulse 1.2s infinite;">🚨</span>
            &bull; 1 PROTOCOL ACTIVE: <?= (int)$activeProtocols[0]['severity_quota'] ?>% (<?= number_format((int)$activeProtocols[0]['live_held_count']) ?> BEDS)
          </a>
        <?php else: ?>
          <a href="bed_monitor.php" class="btn-action-gradient" style="background:linear-gradient(135deg,#e11d48 0%,#991b1b 100%);text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 4px 16px rgba(225,29,72,.45);">
            <span style="display:inline-block;animation:saPulse 0.9s infinite;">🚨</span>
            &bull; <?= $activeCount ?> PROTOCOLS ACTIVE: <?= $cumulativeSurgeQuota ?>% TOTAL SURGE (<?= number_format($totalSurgeHeldBeds) ?> BEDS)
          </a>
        <?php endif; ?>

        <!-- Dynamic ECG Waveform Live Telemetry Bar -->
        <div class="sa-telemetry-badge <?= $activeCount > 0 ? 'telemetry-surge' : 'telemetry-normal' ?>" title="<?= $activeCount > 0 ? 'National Emergency Surge Active: ' . $activeCount . ' concurrent protocol(s)' : 'Network Telemetry Synchronized across all 6 facilities' ?>">
          <div class="ecg-track">
            <svg class="ecg-svg" viewBox="0 0 54 18" preserveAspectRatio="none">
              <path class="ecg-pulse-line" d="M0,9 L12,9 L15,3 L18,15 L21,2 L24,16 L27,9 L32,9 L35,6 L38,11 L41,9 L54,9" />
            </svg>
          </div>
          <div class="telemetry-info">
            <span class="telemetry-bpm font-mono"><?= $activeCount > 0 ? '118 BPM' : '72 BPM' ?></span>
            <span class="telemetry-sep">•</span>
            <span class="telemetry-status">
              <?php if ($activeCount === 0): ?>
                SYSTEM NORMAL
              <?php elseif ($activeCount === 1): ?>
                SURGE PROTOCOL ACTIVE: <?= htmlspecialchars($activeProtocols[0]['title'], ENT_QUOTES, 'UTF-8') ?>
              <?php else: ?>
                <?= $activeCount ?> PROTOCOLS CONCURRENT SURGE (<?= $cumulativeSurgeQuota ?>% QUOTA)
              <?php endif; ?>
            </span>
          </div>
          <span class="telemetry-pulse-dot">
            <span class="telemetry-pulse-ring"></span>
            <span class="telemetry-pulse-core"></span>
          </span>
        </div>
        <button class="btn-action-gradient" onclick="refreshDashboard()">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
          Refresh Data
        </button>
      </div>
    </div>

    <!-- Cross-Hospital Network Situation Telemetry Stream -->
    <div class="net-ticker-strip" id="netTickerStrip">
      <div class="net-ticker-label">
        <span class="net-ticker-dot"></span>
        LIVE NETWORK
      </div>
      <div class="net-ticker-viewport" id="tickerViewport">
        <!-- Facility telemetry items injected by JS -->
      </div>
      <div class="net-ticker-counter">
        <span id="tickerCurrent">1</span> / <span id="tickerTotal">6</span>
      </div>
    </div>

    <!-- Active Emergency Broadcast / Animated Alert Carousel Ticker -->
    <?php if ($activeCount === 1):
      $proto = $activeProtocols[0];
      $badgeColor = $proto['meta']['badge_color'] ?? '#e11d48';
      $targetHospNames = [];
      foreach ($allHospitals as $h) {
        if (in_array((int)$h['hospital_id'], $proto['target_hospital_ids'], true)) {
          $targetHospNames[] = $h['name'];
        }
      }
    ?>
    <div class="single-disaster-banner" id="singleDisasterBanner" data-protocol-id="<?= (int)$proto['id'] ?>" style="background:linear-gradient(135deg,#881337 0%,#4c0519 100%);border:1.5px solid #f43f5e;border-radius:16px;padding:16px 20px;color:#fff;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:0 8px 24px rgba(136,19,55,.4);">
      <div style="display:flex;align-items:center;gap:14px;">
        <div style="font-size:1.8rem;width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;">🚨</div>
        <div>
          <div style="font-weight:800;font-size:1.08rem;display:flex;align-items:center;gap:10px;color:#fff;flex-wrap:wrap;">
            <?= htmlspecialchars($proto['title'], ENT_QUOTES, 'UTF-8') ?>
            <span style="font-size:0.68rem;padding:2px 8px;border-radius:10px;background:<?= $badgeColor ?>;color:#fff;text-transform:uppercase;letter-spacing:.05em;"><?= htmlspecialchars($proto['severity_level'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$proto['severity_quota'] ?>% QUOTA)</span>
            <span style="font-size:0.7rem;color:#fecdd3;font-weight:600;">&bull; <?= number_format((int)$proto['live_held_count']) ?> Beds Locked</span>
          </div>
          <div style="font-size:0.8rem;color:#fecdd3;margin-top:2px;">
            <?= htmlspecialchars($proto['notes'] ?: 'National Emergency Protocol currently enforced across network facilities.', ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <button type="button" class="btn-stand-down-single" onclick="standDownSingleProtocol(<?= (int)$proto['id'] ?>, '<?= htmlspecialchars(addslashes($proto['title']), ENT_QUOTES, 'UTF-8') ?>')">
          ⚡ Stand Down Protocol
        </button>
        <a href="bed_monitor.php" style="padding:9px 18px;background:#ffffff;color:#991b1b;border-radius:10px;font-weight:800;font-size:0.82rem;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,.15);white-space:nowrap;">
          Central Bed Monitor →
        </a>
      </div>
    </div>
    <?php elseif ($activeCount > 1): ?>
    <div class="disaster-carousel-container" id="disasterCarousel" onmouseenter="pauseCarousel()" onmouseleave="resumeCarousel()">
      <!-- Top Bar: Title & Interactive Slide Indicators -->
      <div class="carousel-header">
        <div class="carousel-header-left">
          <span class="carousel-live-ping">
            <span class="ping-ring"></span>
            <span class="ping-core"></span>
          </span>
          <span class="carousel-header-title" id="carouselHeaderTitle">NATIONAL DISASTER PROTOCOL SURGE &bull; <?= $activeCount ?> CONCURRENT THREATS ACTIVE (<?= number_format($totalSurgeHeldBeds) ?> TOTAL BEDS LOCKED)</span>
        </div>
        <div class="carousel-pills" id="carouselPills">
          <?php foreach ($activeProtocols as $idx => $proto): 
            $badgeColor = $proto['meta']['badge_color'] ?? '#e11d48';
          ?>
          <button type="button" class="carousel-pill-btn <?= $idx === 0 ? 'active' : '' ?>" onclick="switchCarouselSlide(<?= $idx ?>)" data-slide="<?= $idx ?>" data-protocol-id="<?= (int)$proto['id'] ?>">
            <span class="pill-dot" style="background:<?= $badgeColor ?>;"></span>
            <?= htmlspecialchars($proto['title'], ENT_QUOTES, 'UTF-8') ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Slides Track -->
      <div class="carousel-slides-track">
        <?php foreach ($activeProtocols as $idx => $proto):
          $badgeColor = $proto['meta']['badge_color'] ?? '#e11d48';
          $targetHospNames = [];
          foreach ($allHospitals as $h) {
            if (in_array((int)$h['hospital_id'], $proto['target_hospital_ids'], true)) {
              $targetHospNames[] = $h['name'];
            }
          }
        ?>
        <div class="carousel-slide <?= $idx === 0 ? 'active' : '' ?>" id="carouselSlide-<?= $idx ?>" data-protocol-id="<?= (int)$proto['id'] ?>" data-slide-index="<?= $idx ?>">
          <div class="slide-content-grid">
            <div class="slide-main">
              <div class="slide-title-row">
                <span class="slide-icon">🚨</span>
                <div>
                  <div class="slide-title">
                    <?= htmlspecialchars($proto['title'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="slide-quota-badge" style="background:<?= $badgeColor ?>;">
                      <?= htmlspecialchars($proto['severity_level'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= (int)$proto['severity_quota'] ?>% QUOTA
                    </span>
                    <span class="slide-id-tag">Protocol #<?= (int)$proto['id'] ?></span>
                  </div>
                  <div class="slide-desc">
                    <?= htmlspecialchars($proto['notes'] ?: ($proto['meta']['description'] ?? 'Enforcing active emergency surge protocols.'), ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </div>
              </div>

              <!-- Target Facilities Badges -->
              <div class="slide-facilities-row">
                <span class="facilities-label">Active Scope:</span>
                <div class="facilities-chips">
                  <?php foreach ($targetHospNames as $hName): ?>
                    <span class="facility-chip">
                      <svg viewBox="0 0 24 24" class="fac-ico"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
                      <?= htmlspecialchars($hName, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <!-- Slide Metrics & Actions -->
            <div class="slide-actions-col">
              <div class="slide-metrics-row">
                <div class="slide-metric">
                  <span class="sm-val text-rose-300"><?= number_format((int)$proto['live_held_count']) ?></span>
                  <span class="sm-lbl">Beds Locked</span>
                </div>
                <div class="slide-metric">
                  <span class="sm-val text-amber-300"><?= number_format((int)$proto['live_relocating_count']) ?></span>
                  <span class="sm-lbl">Relocating</span>
                </div>
                <div class="slide-metric">
                  <span class="sm-val text-cyan-300"><?= count($proto['target_hospital_ids']) ?></span>
                  <span class="sm-lbl">Facilities</span>
                </div>
              </div>

              <div class="slide-btn-group">
                <button type="button" class="btn-stand-down-single" onclick="standDownSingleProtocol(<?= (int)$proto['id'] ?>, '<?= htmlspecialchars(addslashes($proto['title']), ENT_QUOTES, 'UTF-8') ?>')">
                  ⚡ Stand Down Protocol
                </button>
                <a href="bed_monitor.php" class="btn-surge-monitor">
                  Central Monitor →
                </a>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Bottom Bar: Auto-Cycle Progress Indicator -->
      <div class="carousel-progress-track">
        <div class="carousel-progress-bar" id="carouselProgressBar"></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════
         TOP BAR — Hospital Switcher / Filter
    ════════════════════════════════════════════════════════ -->
    <form method="GET" action="dashboard.php" id="hospitalSwitcherForm">
      <div class="sa-topbar">
        <div class="sa-topbar-left">
          <div class="sa-network-pill">
            <span class="sa-network-dot"></span>
            MedPulse Network
          </div>
          <span style="font-size:0.82rem; color:var(--text-muted);">
            <?= $totalNetworkHospitals ?> Hospitals Connected
          </span>
        </div>

        <div class="sa-switcher-group">
          <label class="sa-switcher-label" for="hospitalFilter">Filter by Hospital:</label>
          <select class="sa-hospital-select" id="hospitalFilter" name="hospital_id" onchange="this.form.submit()">
            <option value="0" <?= $filterHospitalId === 0 ? 'selected' : '' ?>>All Hospitals</option>
            <?php foreach ($allHospitals as $h): ?>
              <option value="<?= (int)$h['hospital_id'] ?>" <?= $filterHospitalId === (int)$h['hospital_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="net-status-badge <?= $occupancyPct >= 85 ? 'status-high' : ($occupancyPct >= 60 ? 'status-moderate' : 'status-normal') ?>">
            <span class="net-status-dot"></span>
            <?= $networkStatus ?>
          </div>
        </div>
      </div>
    </form>

    <!-- ═══════════════════════════════════════════════════════
         4 KPI METRIC CARDS
    ════════════════════════════════════════════════════════ -->
    <div class="stat-cards-grid">
      <!-- Card 1: Total Network Hospitals -->
      <a href="hospital_directory.php" class="stat-card-executive stat-card-interactive sa-purple kpi-hosp" title="View Hospital Directory">
        <div class="stat-card-head">
          <span>Network Hospitals</span>
          <div class="kpi-head-action">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><line x1="9" y1="22" x2="9" y2="12"></line><line x1="15" y1="22" x2="15" y2="12"></line><line x1="9" y1="7" x2="15" y2="7"></line></svg>
            <svg class="kpi-arrow-ico" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </div>
        </div>
        <div class="stat-card-number"><?= number_format($totalNetworkHospitals) ?></div>
        <div class="badge-purple">
          <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Fully Connected
        </div>
      </a>

      <!-- Card 2: Total Network Beds -->
      <a href="bed_monitor.php?status=All<?= $filterHospitalId > 0 ? '&hospital_id=' . $filterHospitalId : '' ?>" class="stat-card-executive stat-card-interactive kpi-beds" title="Open Central Bed Monitor">
        <div class="stat-card-head">
          <span>Total Network Beds</span>
          <div class="kpi-head-action">
            <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
            <svg class="kpi-arrow-ico" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </div>
        </div>
        <div class="stat-card-number" id="kpiTotalBeds"><?= number_format($totalNetworkBeds) ?></div>
        <div class="stat-card-badge badge-blue">
          <?= $filterHospitalId > 0 ? '1 Hospital' : 'All Hospitals' ?>
        </div>
      </a>

      <!-- Card 3: Available Live Beds -->
      <a href="bed_monitor.php?status=Available<?= $filterHospitalId > 0 ? '&hospital_id=' . $filterHospitalId : '' ?>" class="stat-card-executive stat-card-interactive kpi-avail" title="Filter Available Live Beds">
        <div class="stat-card-head">
          <span>Available Live Beds</span>
          <div class="kpi-head-action">
            <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
            <svg class="kpi-arrow-ico" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </div>
        </div>
        <div class="stat-card-number" id="kpiAvailBeds"><?= number_format($availableLiveBeds) ?></div>
        <div class="stat-card-badge badge-green">
          <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Ready for Admission
        </div>
      </a>

      <!-- Card 4: Critical / Occupied -->
      <a href="bed_monitor.php?status=Occupied<?= $filterHospitalId > 0 ? '&hospital_id=' . $filterHospitalId : '' ?>" class="stat-card-executive stat-card-interactive kpi-occ" title="Filter Occupied / Critical Beds">
        <div class="stat-card-head">
          <span>Occupied / Critical</span>
          <div class="kpi-head-action">
            <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <svg class="kpi-arrow-ico" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </div>
        </div>
        <div class="stat-card-number" id="kpiOccBeds"><?= number_format($criticalOccupiedBeds) ?></div>
        <div class="stat-card-badge <?= $occBadgeClass ?>" id="kpiOccBadge">
          <?= $occupancyPct ?>% Network Occupancy
        </div>
      </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         MULTI-HOSPITAL LIVE STATUS TABLE
    ════════════════════════════════════════════════════════ -->
    <div class="sa-table-section">
      <div class="sa-section-header">
        <div class="sa-section-title">
          <svg class="ui-ico" style="stroke: var(--sa-accent); width:22px; height:22px;" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          <div>
            <h3>Multi-Hospital Live Status</h3>
            <p>Real-time bed availability and occupancy across all network facilities</p>
          </div>
        </div>
        <div class="sa-live-badge">
          <span class="sa-live-dot"></span>
          Live Data
        </div>
      </div>

      <div class="sa-table-wrap">
        <table class="sa-data-table" id="hospitalStatusTable">
          <thead>
            <tr>
              <th>Hospital</th>
              <th>City</th>
              <th>Total Beds</th>
              <th>Available</th>
              <th>ICU Vacancy</th>
              <th>Occupancy</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($hospitalRows)): ?>
            <tr>
              <td colspan="8" style="text-align:center; padding:40px; color:var(--text-muted);">
                No hospital data found. Please check your database connection.
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($hospitalRows as $row):
              $tot     = (int)($row['total_beds'] ?? 0);
              $avail   = (int)($row['available_beds'] ?? 0);
              $occ     = (int)($row['occupied_beds'] ?? 0);
              $icuVac  = (int)($row['icu_vacant'] ?? 0);
              $icuTot  = (int)($row['icu_total'] ?? 0);
              $occPct  = $tot > 0 ? round(($occ / $tot) * 100) : 0;
              $fillCls = $occPct >= 85 ? 'fill-red' : ($occPct >= 60 ? 'fill-amber' : 'fill-green');
              $stCls   = $occPct >= 85 ? 'status-high' : ($occPct >= 60 ? 'status-moderate' : 'status-normal');
              $stLabel = $occPct >= 85 ? 'High Load' : ($occPct >= 60 ? 'Moderate' : 'Normal');
              $hospId  = (int)($row['hospital_id'] ?? 0);
            ?>
            <tr class="sa-clickable-row" onclick="window.location.href='bed_monitor.php?hospital_id=<?= $hospId ?>'" title="Drill down to <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?> bed monitor">
              <td>
                <div class="hosp-name-cell">
                  <?= getHospitalCrest($hospId, $row['name']) ?>
                  <div>
                    <div style="font-weight:700; color:var(--text-heading); font-size:0.88rem;">
                      <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if (!empty($row['contact_number'])): ?>
                    <div style="font-size:0.72rem; color:var(--text-muted);">
                      <?= htmlspecialchars($row['contact_number'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td>
                <span style="font-size:0.82rem; color:var(--text-body);">
                  <?= htmlspecialchars($row['city'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td>
                <strong style="color:var(--text-heading); font-size:0.92rem;"><?= number_format($tot) ?></strong>
              </td>
              <td>
                <span style="color:var(--status-green); font-weight:700; font-size:0.92rem;"><?= number_format($avail) ?></span>
                <span style="color:var(--text-muted); font-size:0.75rem;"> / <?= number_format($tot) ?></span>
              </td>
              <td>
                <?php if ($icuTot > 0): ?>
                  <span style="font-weight:700; color:var(--brand-primary);"><?= $icuVac ?></span>
                  <span style="color:var(--text-muted); font-size:0.75rem;"> / <?= $icuTot ?></span>
                  <?php if ($icuVac === 0): ?>
                    <span class="net-status-badge status-high" style="padding:2px 7px; font-size:0.66rem; margin-left:4px;">FULL</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--text-muted); font-size:0.78rem;">N/A</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="occ-bar-wrap">
                  <div class="occ-bar-bg">
                    <div class="occ-bar-fill <?= $fillCls ?>" style="width: <?= $occPct ?>%;"></div>
                  </div>
                  <span class="occ-bar-pct"><?= $occPct ?>%</span>
                </div>
              </td>
              <td>
                <div class="net-status-badge <?= $stCls ?>">
                  <span class="net-status-dot"></span>
                  <?= $stLabel ?>
                </div>
              </td>
              <td style="text-align:right; white-space:nowrap;">
                <a href="bed_monitor.php?hospital_id=<?= $hospId ?>" class="sa-btn-drilldown" onclick="event.stopPropagation();" title="Inspect <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?> Live Census">
                  Drill Down <span class="drill-arrow">→</span>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         BOTTOM GRID: Ward Breakdown + Recent Audit Logs
    ════════════════════════════════════════════════════════ -->
    <div class="sa-bottom-grid">

      <!-- Left: Network Bed Breakdown by Ward Type -->
      <div class="sa-table-section" style="margin-bottom:0;">
        <div class="sa-section-header">
          <div class="sa-section-title">
            <svg class="ui-ico" style="stroke: var(--brand-teal); width:20px; height:20px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
            <div>
              <h3>Network Bed Breakdown</h3>
              <p>Availability by ward type across all facilities</p>
            </div>
          </div>
        </div>
        <div class="ward-grid">
          <?php if (empty($wardBreakdown)): ?>
            <div style="grid-column:1/-1; text-align:center; padding:30px; color:var(--text-muted);">No ward data available.</div>
          <?php else: ?>
          <?php foreach ($wardBreakdown as $wd):
            $wTotal  = (int)($wd['total'] ?? 0);
            $wAvail  = (int)($wd['available'] ?? 0);
            $wOccPct = $wTotal > 0 ? round((($wTotal - $wAvail) / $wTotal) * 100) : 0;
            $wFill   = $wOccPct >= 85 ? 'fill-red' : ($wOccPct >= 60 ? 'fill-amber' : 'fill-green');
            $wardSlug = urlencode($wd['ward_type']);
          ?>
          <a href="bed_monitor.php?ward=<?= $wardSlug ?>" class="ward-card" title="View all <?= htmlspecialchars($wd['ward_type'], ENT_QUOTES, 'UTF-8') ?> beds in Bed Monitor">
            <div class="ward-card-label"><?= htmlspecialchars($wd['ward_type'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="ward-card-avail"><?= $wAvail ?></div>
            <div class="ward-card-total">of <?= $wTotal ?> available</div>
            <div style="margin-top:8px;" class="occ-bar-bg">
              <div class="occ-bar-fill <?= $wFill ?>" style="width:<?= $wOccPct ?>%;"></div>
            </div>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right: Recent Network Audit Logs -->
      <div class="sa-table-section" style="margin-bottom:0;">
        <div class="sa-section-header">
          <div class="sa-section-title">
            <svg class="ui-ico" style="stroke: var(--sa-accent); width:20px; height:20px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <div>
              <h3>Audit Logs</h3>
              <p>Recent network events</p>
            </div>
          </div>
          <a href="audit_logs.php" style="font-size:0.78rem; font-weight:700; color:var(--sa-accent); text-decoration:none;">
            View All →
          </a>
        </div>
        <ul class="sa-audit-list">
          <?php if (empty($recentLogs)): ?>
          <li style="padding:30px; text-align:center; color:var(--text-muted); font-size:0.82rem;">No audit events recorded yet.</li>
          <?php else: ?>
          <?php foreach ($recentLogs as $log):
            $action  = htmlspecialchars($log['action'] ?? 'Event', ENT_QUOTES, 'UTF-8');
            $desc    = htmlspecialchars($log['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $relTime = saRelTime($log['created_at']);
          ?>
          <li class="sa-audit-item">
            <span class="sa-audit-dot"></span>
            <div class="sa-audit-body">
              <div class="sa-audit-action"><?= $action ?></div>
              <?php if ($desc): ?>
              <div class="sa-audit-desc" title="<?= $desc ?>"><?= $desc ?></div>
              <?php endif; ?>
            </div>
            <span class="sa-audit-time"><?= htmlspecialchars($relTime, ENT_QUOTES, 'UTF-8') ?></span>
          </li>
          <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

    </div><!-- /.sa-bottom-grid -->

  </main><!-- /.viewport-full -->

  <!-- MedPulse Modern Dialog Engine -->
  <script src="../assets/js/medpulse_dialog.js?v=<?= time() ?>"></script>

  <script>
    // ── Cross-Hospital Network Telemetry Stream Ticker ────────────────────────
    const TICKER_FACILITIES = [
      {
        name: 'Square Hospital',
        text: 'ICU at <strong>100% Capacity</strong> &mdash; 0/91 Vacant',
        tag:  '<span class="ticker-tag tag-critical">Critical Triage Priority</span>',
        dot:  'ticker-dot-critical'
      },
      {
        name: 'NIBPS',
        text: 'Apex Burn Pavilion Active &mdash; <strong>116 General Recovery</strong> beds ready',
        tag:  '<span class="ticker-tag tag-surge">Burn Protocol Active</span>',
        dot:  'ticker-dot-high'
      },
      {
        name: 'United Hospital',
        text: 'Cardiac &amp; Nephrology Wards Stable &mdash; <strong>37 beds available</strong>',
        tag:  '<span class="ticker-tag tag-normal">Stable</span>',
        dot:  'ticker-dot-normal'
      },
      {
        name: 'UMCH',
        text: 'Academic Teaching Wards on Standby for <strong>Dengue HDU</strong> conversion',
        tag:  '<span class="ticker-tag tag-high">HDU Standby</span>',
        dot:  'ticker-dot-high'
      },
      {
        name: 'Evercare Hospital',
        text: 'Multi-disciplinary Trauma &amp; PICU <strong>fully operational</strong>',
        tag:  '<span class="ticker-tag tag-normal">Operational</span>',
        dot:  'ticker-dot-normal'
      },
      {
        name: 'MedPulse Hospital',
        text: 'Flagship Triage Active &mdash; <strong><?= number_format($emergencyHoldBeds) ?> beds</strong> in Surge Hold',
        tag:  '<span class="ticker-tag tag-surge">Surge Active</span>',
        dot:  '<?= $emergencyHoldBeds > 0 ? 'ticker-dot-critical' : 'ticker-dot-normal' ?>'
      }
    ];

    let tickerIdx  = 0;
    const viewport = document.getElementById('tickerViewport');
    const tickerCurrentEl = document.getElementById('tickerCurrent');
    const tickerTotalEl   = document.getElementById('tickerTotal');

    function buildTickerItems() {
      if (!viewport) return;
      tickerTotalEl.textContent = TICKER_FACILITIES.length;
      TICKER_FACILITIES.forEach((f, i) => {
        const el = document.createElement('div');
        el.className = 'net-ticker-item' + (i === 0 ? ' active' : '');
        el.dataset.tickerIdx = i;
        el.innerHTML = `
          <span class="ticker-facility-dot ${f.dot}"></span>
          <span class="net-ticker-text"><strong>[${f.name}]</strong> ${f.text} ${f.tag}</span>
        `;
        viewport.appendChild(el);
      });
    }

    function advanceTicker() {
      const items = viewport ? viewport.querySelectorAll('.net-ticker-item') : [];
      if (!items.length) return;
      items[tickerIdx].classList.remove('active');
      tickerIdx = (tickerIdx + 1) % items.length;
      items[tickerIdx].classList.add('active');
      if (tickerCurrentEl) tickerCurrentEl.textContent = tickerIdx + 1;
    }

    document.addEventListener('DOMContentLoaded', () => {
      buildTickerItems();
      setInterval(advanceTicker, 4000);
    });
    // Auto-refresh the page every 60 seconds to keep data live
    let autoRefreshTimer = setTimeout(() => location.reload(), 60000);

    function refreshDashboard() {
      clearTimeout(autoRefreshTimer);
      if (typeof showToast === 'function') {
        showToast('Refreshing network telemetry…', 'info');
      }
      setTimeout(() => location.reload(), 600);
    }

    // Animate occupancy bars on load
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.occ-bar-fill').forEach(bar => {
        const target = bar.style.width;
        bar.style.width = '0%';
        requestAnimationFrame(() => {
          setTimeout(() => { bar.style.width = target; }, 80);
        });
      });
    });

    // Dynamic Alert Carousel Controller
    const totalSlides = <?= (int)$activeCount ?>;
    let currentSlideIdx = 0;
    let progressTimer = null;
    let progressPct = 0;
    const SLIDE_DURATION = 5000;
    const PROGRESS_INTERVAL = 50;

    function switchCarouselSlide(idx) {
      const slides = document.querySelectorAll('.carousel-slide');
      const pills  = document.querySelectorAll('.carousel-pill-btn');
      const count  = slides.length;
      if (count <= 1) {
        if (count === 1) {
          slides[0].classList.add('active');
          if (pills[0]) pills[0].classList.add('active');
        }
        resetProgress();
        return;
      }
      currentSlideIdx = (idx + count) % count;
      slides.forEach((s, i) => {
        s.classList.toggle('active', i === currentSlideIdx);
      });
      pills.forEach((p, i) => {
        p.classList.toggle('active', i === currentSlideIdx);
      });
      resetProgress();
    }

    function resetProgress() {
      progressPct = 0;
      const bar = document.getElementById('carouselProgressBar');
      if (bar) bar.style.width = '0%';
    }

    function startCarousel() {
      const count = document.querySelectorAll('.carousel-slide').length;
      if (count <= 1) return;
      clearInterval(progressTimer);
      progressTimer = setInterval(() => {
        progressPct += (PROGRESS_INTERVAL / SLIDE_DURATION) * 100;
        const bar = document.getElementById('carouselProgressBar');
        if (bar) bar.style.width = `${Math.min(100, progressPct)}%`;
        if (progressPct >= 100) {
          switchCarouselSlide(currentSlideIdx + 1);
        }
      }, PROGRESS_INTERVAL);
    }

    function pauseCarousel() {
      clearInterval(progressTimer);
    }

    function resumeCarousel() {
      startCarousel();
    }

    if (totalSlides > 1) {
      startCarousel();
    }

    // Stand Down Single Emergency Protocol
    async function standDownSingleProtocol(protoId, title) {
      if (typeof pauseCarousel === 'function') pauseCarousel();

      const escapeHtml = (str) => {
        if (!str) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };

      const confirmed = await MedPulseDialog.confirm({
        title: 'Confirm Protocol De-escalation',
        subtitle: `Disaster Protocol Stand-Down (Protocol ID #${protoId})`,
        type: 'danger',
        confirmText: '⚡ Execute Stand-Down',
        cancelText: 'Abort / Keep Active',
        html: `
          <div style="font-size:0.88rem;color:#334155;line-height:1.55;margin-bottom:12px;">
            You are initiating the official de-escalation for active emergency surge protocol:
          </div>
          <div style="margin-bottom:16px;">
            <span style="display:inline-flex;align-items:center;gap:7px;padding:6px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-size:0.88rem;font-weight:700;color:#991b1b;box-shadow:0 1px 3px rgba(225,29,72,0.08);">
              <span style="width:8px;height:8px;border-radius:50%;background:#e11d48;display:inline-block;box-shadow:0 0 8px #e11d48;"></span>
              ${escapeHtml(title)}
            </span>
          </div>
          <div style="background:#fff1f2;border:1px solid #ffe4e6;border-radius:12px;padding:12px 14px;margin-bottom:14px;">
            <p style="font-size:0.82rem;color:#9f1239;line-height:1.5;margin:0 0 6px;font-weight:700;">
              Operational Protocol De-escalation Directives:
            </p>
            <ul style="font-size:0.8rem;color:#881337;line-height:1.6;margin:0;padding-left:18px;">
              <li>Standing down will <strong>release all held emergency beds</strong> allocated for this surge, immediately restoring them to <em>Available</em>.</li>
              <li>Active beds used during this surge will transition directly to the <strong>sanitization protocol</strong> for clinical decontamination.</li>
              <li>Other active disaster protocols (if any) will remain strictly enforced across the network.</li>
            </ul>
          </div>
          <div style="font-size:0.78rem;color:#64748b;">
            Zero orphaned allocations will remain. This action is permanently audited. Proceed with stand-down?
          </div>
        `
      });

      if (!confirmed) {
        if (typeof resumeCarousel === 'function') resumeCarousel();
        return;
      }

      await executeStandDown(protoId, title);
    }

    async function executeStandDown(protoId, title) {
      const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const fd = new FormData();
      fd.append('_action', 'terminate_emergency');
      fd.append('csrf_token', token);
      if (protoId) {
        fd.append('protocol_id', protoId);
      }

      try {
        const r = await fetch('../backend/api/emergency_surge_action.php', {
          method: 'POST',
          body: fd
        });
        const res = await r.json();

        if (res.success) {
          MedPulseDialog.toast({
            title: 'Protocol De-escalated',
            message: 'Protocol de-escalated successfully. Bed capacities restored.',
            type: 'success',
            duration: 4000
          });

          handleDashboardStandDownSuccess(protoId, res);
        } else {
          MedPulseDialog.toast({
            title: 'De-escalation Failed',
            message: res.message || 'Stand-down operation failed.',
            type: 'error',
            duration: 5000
          });
          if (typeof resumeCarousel === 'function') resumeCarousel();
        }
      } catch (err) {
        MedPulseDialog.toast({
          title: 'Communication Failure',
          message: 'Network error communicating with emergency triage API.',
          type: 'error',
          duration: 5000
        });
        if (typeof resumeCarousel === 'function') resumeCarousel();
      }
    }

    function handleDashboardStandDownSuccess(protoId, res) {
      // 1. Smoothly update KPI metric cards
      const kpiAvail = document.getElementById('kpiAvailBeds');
      const reverted = parseInt(res.reverted_available, 10) || 0;
      if (kpiAvail && reverted > 0) {
        const curVal = parseInt(kpiAvail.textContent.replace(/,/g, ''), 10) || 0;
        kpiAvail.textContent = (curVal + reverted).toLocaleString();
        kpiAvail.style.transition = 'color 0.3s ease, transform 0.3s ease';
        kpiAvail.style.color = 'var(--status-green)';
        kpiAvail.style.transform = 'scale(1.08)';
        setTimeout(() => { kpiAvail.style.transform = 'scale(1)'; }, 400);
      }

      // 2. Smoothly update Carousel or Single Banner
      const singleBanner = document.getElementById('singleDisasterBanner');
      if (singleBanner) {
        singleBanner.style.transition = 'opacity 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
        singleBanner.style.opacity = '0';
        singleBanner.style.maxHeight = '0';
        singleBanner.style.overflow = 'hidden';
        singleBanner.style.padding = '0';
        singleBanner.style.margin = '0';
        setTimeout(() => { singleBanner.remove(); }, 450);
        setTimeout(() => { location.reload(); }, 1200);
        return;
      }

      const carousel = document.getElementById('disasterCarousel');
      if (carousel) {
        const targetSlide = document.querySelector(`.carousel-slide[data-protocol-id="${protoId}"]`);
        const targetPill  = document.querySelector(`.carousel-pill-btn[data-protocol-id="${protoId}"]`);

        if (targetSlide) {
          targetSlide.style.transition = 'all 0.35s ease';
          targetSlide.style.opacity = '0';
          targetSlide.style.transform = 'scale(0.96)';
        }
        if (targetPill) {
          targetPill.style.transition = 'all 0.3s ease';
          targetPill.style.opacity = '0';
          targetPill.style.transform = 'scale(0.8)';
        }

        setTimeout(() => {
          if (targetSlide) targetSlide.remove();
          if (targetPill) targetPill.remove();

          const remainingSlides = document.querySelectorAll('.carousel-slide');
          const remainingPills  = document.querySelectorAll('.carousel-pill-btn');
          const remainingCount  = remainingSlides.length;

          if (remainingCount > 0) {
            const headerTitle = document.getElementById('carouselHeaderTitle');
            if (headerTitle) {
              headerTitle.innerHTML = remainingCount === 1 
                ? 'NATIONAL DISASTER PROTOCOL SURGE &bull; 1 THREAT ACTIVE' 
                : `NATIONAL DISASTER PROTOCOL SURGE &bull; ${remainingCount} CONCURRENT THREATS ACTIVE`;
            }

            remainingPills.forEach((p, idx) => {
              p.setAttribute('data-slide', idx);
              p.setAttribute('onclick', `switchCarouselSlide(${idx})`);
            });
            remainingSlides.forEach((s, idx) => {
              s.id = `carouselSlide-${idx}`;
              s.setAttribute('data-slide-index', idx);
            });

            switchCarouselSlide(0);
            startCarousel();
          } else {
            carousel.style.transition = 'opacity 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
            carousel.style.opacity = '0';
            carousel.style.maxHeight = '0';
            carousel.style.overflow = 'hidden';
            carousel.style.padding = '0';
            carousel.style.margin = '0';
            setTimeout(() => { carousel.remove(); }, 450);
            setTimeout(() => { location.reload(); }, 1200);
          }
        }, 380);
      }
    }
  </script>

</body>
</html>

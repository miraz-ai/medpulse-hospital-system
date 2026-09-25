<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — Network Command Center Dashboard
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';

// --- Selected Hospital Filter (from query string) ---
$filterHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;

try {
    // All hospitals for the switcher dropdown
    $allHospitals = $pdo->query("SELECT hospital_id, name, city FROM hospitals ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Build WHERE clause for hospital filter
    $whereHospital = $filterHospitalId > 0 ? "AND hb.hospital_id = {$filterHospitalId}" : "";

    // --- Top 4 KPI Metrics ---
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

    // Occupancy percentage
    $occupancyPct = $totalNetworkBeds > 0 ? round(($criticalOccupiedBeds / $totalNetworkBeds) * 100) : 0;

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
            SUM(hb.ward_type = 'ICU' AND hb.status = 'Available')  AS icu_vacant,
            SUM(hb.ward_type = 'ICU')                              AS icu_total,
            SUM(hb.ward_type = 'CCU' AND hb.status = 'Available')  AS ccu_vacant
        FROM hospitals h
        LEFT JOIN hospital_beds hb ON hb.hospital_id = h.hospital_id
        " . ($filterHospitalId > 0 ? "WHERE h.hospital_id = {$filterHospitalId}" : "") . "
        GROUP BY h.hospital_id
        ORDER BY h.name ASC
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

    .sa-data-table tbody tr:hover { background: rgba(2,132,199,.03); }

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
      transition: border-color .2s, box-shadow .2s;
    }

    .ward-card:hover {
      border-color: var(--sa-accent-border);
      box-shadow: 0 4px 14px var(--sa-accent-soft);
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
        <div class="sa-live-badge">
          <span class="sa-live-dot"></span>
          Network Live
        </div>
        <button class="btn-action-gradient" onclick="refreshDashboard()">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
          Refresh Data
        </button>
      </div>
    </div>

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
      <div class="stat-card-executive sa-purple">
        <div class="stat-card-head">
          <span>Network Hospitals</span>
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><line x1="9" y1="22" x2="9" y2="12"></line><line x1="15" y1="22" x2="15" y2="12"></line><line x1="9" y1="7" x2="15" y2="7"></line></svg>
        </div>
        <div class="stat-card-number"><?= number_format($totalNetworkHospitals) ?></div>
        <div class="badge-purple">
          <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Fully Connected
        </div>
      </div>

      <!-- Card 2: Total Network Beds -->
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Total Network Beds</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
        </div>
        <div class="stat-card-number" id="kpiTotalBeds"><?= number_format($totalNetworkBeds) ?></div>
        <div class="stat-card-badge badge-blue">
          <?= $filterHospitalId > 0 ? '1 Hospital' : 'All Hospitals' ?>
        </div>
      </div>

      <!-- Card 3: Available Live Beds -->
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Available Live Beds</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
        </div>
        <div class="stat-card-number" id="kpiAvailBeds"><?= number_format($availableLiveBeds) ?></div>
        <div class="stat-card-badge badge-green">
          <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Ready for Admission
        </div>
      </div>

      <!-- Card 4: Critical / Occupied -->
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Occupied / Critical</span>
          <svg class="ui-ico" style="stroke: var(--status-amber);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </div>
        <div class="stat-card-number" id="kpiOccBeds"><?= number_format($criticalOccupiedBeds) ?></div>
        <div class="stat-card-badge <?= $occBadgeClass ?>" id="kpiOccBadge">
          <?= $occupancyPct ?>% Network Occupancy
        </div>
      </div>
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
            </tr>
          </thead>
          <tbody>
            <?php if (empty($hospitalRows)): ?>
            <tr>
              <td colspan="7" style="text-align:center; padding:40px; color:var(--text-muted);">
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
              $initials = strtoupper(substr($row['name'], 0, 2));
            ?>
            <tr>
              <td>
                <div class="hosp-name-cell">
                  <div class="hosp-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
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
          ?>
          <div class="ward-card">
            <div class="ward-card-label"><?= htmlspecialchars($wd['ward_type'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="ward-card-avail"><?= $wAvail ?></div>
            <div class="ward-card-total">of <?= $wTotal ?> available</div>
            <div style="margin-top:8px;" class="occ-bar-bg">
              <div class="occ-bar-fill <?= $wFill ?>" style="width:<?= $wOccPct ?>%;"></div>
            </div>
          </div>
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

  <script>
    // Auto-refresh the page every 60 seconds to keep data live
    let autoRefreshTimer = setTimeout(() => location.reload(), 60000);

    function refreshDashboard() {
      clearTimeout(autoRefreshTimer);
      showToast('Refreshing network telemetry…', 'success');
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
  </script>

</body>
</html>

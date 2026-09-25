<?php
// ============================================================================
// MedPulse Enterprise HMS
// Patient Portal: Multi-Hospital Bed Discovery & Comparison
// File: patient/bed_discovery.php
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'
    ]);
    session_start();
}

// RBAC Guard – Patient Only
if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'patient') {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// Inactivity timeout
$inactiveTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $inactiveTimeout)) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../login.php?error=session_timeout');
    exit();
}
$_SESSION['last_activity'] = time();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db.php';

// Fetch patient basics
try {
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, gender, blood_group, age, date_of_birth FROM users WHERE user_id = :id AND role = 'Patient' LIMIT 1");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) { header('Location: ../login.php'); exit(); }

    $patientName  = $patient['full_name'];
    $patientEmail = $patient['email'];
    $bloodGroup   = !empty($patient['blood_group']) ? $patient['blood_group'] : 'B+';
    $gender       = !empty($patient['gender'])       ? $patient['gender']      : 'Male';

    if (!empty($patient['date_of_birth'])) {
        try { $age = (new DateTime())->diff(new DateTime($patient['date_of_birth']))->y; }
        catch (Exception $e) { $age = !empty($patient['age']) ? (int)$patient['age'] : 22; }
    } else {
        $age = !empty($patient['age']) ? (int)$patient['age'] : 22;
    }
} catch (PDOException $e) {
    die('Database error. Please contact support.');
}

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Bed Discovery & Comparison</title>
  <meta name="description" content="Compare live bed availability across Square Hospital, United Hospital, and Evercare Hospital Dhaka. Filter by ward type and price to find the right bed.">

  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Shared Patient CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* ================================================================
       BED DISCOVERY — Page-Specific Design Tokens & Components
       ================================================================ */

    /* --- Hero Filter Bar --- */
    .discovery-hero {
      background: linear-gradient(135deg, #0f172a 0%, #0c2340 50%, #0d4a5e 100%);
      border-radius: var(--radius-xl);
      padding: 1.85rem 2rem;
      margin-bottom: 1.75rem;
      position: relative;
      overflow: hidden;
    }

    .discovery-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background:
        radial-gradient(ellipse at 10% 80%, rgba(2,132,199,0.25) 0%, transparent 55%),
        radial-gradient(ellipse at 90% 20%, rgba(13,148,136,0.20) 0%, transparent 55%);
      pointer-events: none;
    }

    .discovery-hero-inner {
      position: relative;
      z-index: 1;
    }

    .hero-top-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .hero-heading {
      font-size: 1.5rem;
      font-weight: 800;
      color: #ffffff;
      letter-spacing: -0.4px;
      margin-bottom: 4px;
    }

    .hero-sub {
      font-size: 0.85rem;
      color: rgba(255,255,255,0.65);
      font-weight: 500;
    }

    .hero-live-pill {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 5px 13px;
      background: rgba(5,150,105,0.18);
      border: 1px solid rgba(52,211,153,0.35);
      border-radius: 99px;
      color: #34d399;
      font-size: 0.74rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      flex-shrink: 0;
    }

    /* --- Summary Stat Chips in Hero --- */
    .hero-stats-row {
      display: flex;
      gap: 0.85rem;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }

    .hero-stat-chip {
      background: rgba(255,255,255,0.07);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 12px;
      padding: 0.65rem 1rem;
      min-width: 120px;
      backdrop-filter: blur(6px);
      transition: background 0.2s ease;
    }

    .hero-stat-chip:hover {
      background: rgba(255,255,255,0.12);
    }

    .hero-stat-label {
      font-size: 0.67rem;
      font-weight: 700;
      color: rgba(255,255,255,0.5);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 3px;
    }

    .hero-stat-value {
      font-size: 1.45rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.5px;
      line-height: 1;
    }

    .hero-stat-value.green  { color: #34d399; }
    .hero-stat-value.amber  { color: #fbbf24; }
    .hero-stat-value.blue   { color: #60a5fa; }

    /* --- Filter Strip --- */
    .filter-strip {
      display: flex;
      gap: 0.85rem;
      flex-wrap: wrap;
      align-items: flex-end;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
      flex: 1;
      min-width: 160px;
      max-width: 240px;
    }

    .filter-group label {
      font-size: 0.68rem;
      font-weight: 700;
      color: rgba(255,255,255,0.55);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .filter-select, .filter-input {
      width: 100%;
      padding: 0.62rem 0.9rem;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.15);
      background: rgba(255,255,255,0.08);
      color: #ffffff;
      font-size: 0.875rem;
      font-family: inherit;
      font-weight: 600;
      transition: all 0.22s ease;
      backdrop-filter: blur(4px);
      -webkit-appearance: none;
      appearance: none;
      cursor: pointer;
    }

    .filter-select option {
      background: #1e2d3d;
      color: #f1f5f9;
    }

    .filter-select:focus, .filter-input:focus {
      outline: none;
      border-color: rgba(13,148,136,0.7);
      background: rgba(255,255,255,0.13);
      box-shadow: 0 0 0 3px rgba(13,148,136,0.2);
    }

    .filter-input[type="range"] {
      padding: 0;
      height: 6px;
      border-radius: 99px;
      cursor: pointer;
      accent-color: #0d9488;
    }

    .price-display {
      font-size: 0.9rem;
      font-weight: 800;
      color: #34d399;
      margin-top: 2px;
    }

    .btn-search-beds {
      background: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      color: white;
      border: none;
      padding: 0.65rem 1.5rem;
      border-radius: 11px;
      font-weight: 700;
      font-size: 0.875rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
      box-shadow: 0 4px 14px rgba(13,148,136,0.35);
      align-self: flex-end;
      white-space: nowrap;
      flex-shrink: 0;
    }

    .btn-search-beds:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 22px rgba(13,148,136,0.45);
    }

    .btn-reset-filter {
      background: rgba(255,255,255,0.09);
      color: rgba(255,255,255,0.75);
      border: 1px solid rgba(255,255,255,0.15);
      padding: 0.65rem 1rem;
      border-radius: 11px;
      font-weight: 600;
      font-size: 0.875rem;
      cursor: pointer;
      transition: all 0.22s ease;
      align-self: flex-end;
      white-space: nowrap;
      flex-shrink: 0;
    }

    .btn-reset-filter:hover {
      background: rgba(255,255,255,0.16);
      color: #fff;
    }

    /* ================================================================
       Hospital Comparison Grid
       ================================================================ */
    .comparison-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
      gap: 1.35rem;
      margin-bottom: 1.75rem;
    }

    .hospital-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      overflow: hidden;
      transition: all 0.28s cubic-bezier(0.34, 1.2, 0.64, 1);
      box-shadow: 0 4px 18px rgba(0,0,0,0.03);
      position: relative;
    }

    .hospital-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 18px 40px rgba(13,148,136,0.10);
      border-color: rgba(13,148,136,0.35);
    }

    /* Coloured accent strip by hospital rank */
    .hospital-card:nth-child(1) .card-accent { background: linear-gradient(90deg, #0284c7, #0d9488); }
    .hospital-card:nth-child(2) .card-accent { background: linear-gradient(90deg, #7c3aed, #db2777); }
    .hospital-card:nth-child(3) .card-accent { background: linear-gradient(90deg, #d97706, #dc2626); }
    .hospital-card:nth-child(4) .card-accent { background: linear-gradient(90deg, #0d9488, #059669); }

    .card-accent {
      height: 4px;
      width: 100%;
    }

    .hospital-card-header {
      padding: 1.25rem 1.35rem 1rem;
      border-bottom: 1px solid var(--surface-border-subtle);
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 0.75rem;
    }

    .hospital-name-block h3 {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-heading);
      letter-spacing: -0.2px;
      margin-bottom: 2px;
    }

    .hospital-meta {
      font-size: 0.76rem;
      color: var(--text-muted);
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .hospital-avail-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 99px;
      font-size: 0.74rem;
      font-weight: 800;
      flex-shrink: 0;
    }

    .badge-beds-available {
      background: var(--status-green-bg);
      color: var(--status-green);
      border: 1px solid rgba(5,150,105,0.2);
    }

    .badge-beds-limited {
      background: var(--status-amber-bg);
      color: var(--status-amber);
      border: 1px solid rgba(217,119,6,0.2);
    }

    .badge-beds-full {
      background: var(--status-red-bg);
      color: var(--status-red);
      border: 1px solid rgba(239,68,68,0.2);
    }

    /* --- Ward Rows --- */
    .ward-rows-list {
      padding: 0.35rem 0;
    }

    .ward-row {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.8rem 1.35rem;
      border-bottom: 1px solid #f8fafc;
      transition: background 0.18s ease;
      cursor: default;
    }

    .ward-row:last-child {
      border-bottom: none;
    }

    .ward-row:hover {
      background: #f8fafc;
    }

    .ward-type-icon {
      width: 34px;
      height: 34px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .icon-icu      { background: #fef2f2; }
    .icon-ccu      { background: #fff7ed; }
    .icon-nicu     { background: #fdf4ff; }
    .icon-emg      { background: #fef2f2; }
    .icon-general  { background: #ecfdf5; }
    .icon-cabin    { background: #eff6ff; }
    .icon-vip      { background: #fefce8; }
    .icon-recovery { background: #f0fdf4; }

    .ward-name-block {
      flex: 1;
      min-width: 0;
    }

    .ward-type-label {
      font-size: 0.85rem;
      font-weight: 700;
      color: var(--text-heading);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ward-price-label {
      font-size: 0.73rem;
      color: var(--text-muted);
      font-weight: 600;
      margin-top: 1px;
    }

    .ward-avail-col {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 3px;
      flex-shrink: 0;
    }

    .ward-avail-count {
      font-size: 1.0rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1;
    }

    .ward-total-label {
      font-size: 0.7rem;
      color: var(--text-muted);
      font-weight: 600;
    }

    .ward-status-pill {
      font-size: 0.66rem;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 5px;
      letter-spacing: 0.03em;
    }

    .wsp-available  { background: #ecfdf5; color: #059669; }
    .wsp-limited    { background: #fef3c7; color: #d97706; }
    .wsp-full       { background: #fef2f2; color: #ef4444; }
    .wsp-maintenance{ background: #f1f5f9; color: #64748b; }

    /* Occupancy mini-bar */
    .occupancy-mini {
      width: 80px;
      height: 5px;
      background: #e2e8f0;
      border-radius: 99px;
      overflow: hidden;
      flex-shrink: 0;
    }

    .occupancy-fill {
      height: 100%;
      border-radius: 99px;
      transition: width 0.6s ease;
    }

    .occ-low    { background: var(--status-green); }
    .occ-mid    { background: var(--status-amber); }
    .occ-high   { background: var(--status-red); }

    /* --- Card Footer CTA --- */
    .card-footer-cta {
      padding: 1rem 1.35rem;
      border-top: 1px solid var(--surface-border-subtle);
      background: #fafbfc;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .card-contact-meta {
      font-size: 0.76rem;
      color: var(--text-muted);
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .btn-view-all-wards {
      background: transparent;
      color: var(--brand-teal);
      border: 1.5px solid rgba(13,148,136,0.4);
      padding: 0.5rem 0.9rem;
      border-radius: 9px;
      font-weight: 700;
      font-size: 0.8rem;
      cursor: pointer;
      transition: all 0.22s ease;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .btn-view-all-wards:hover {
      background: var(--brand-teal);
      color: white;
      border-color: var(--brand-teal);
    }

    /* ================================================================
       "Choose Bed" — Ward Detail Drawer (slide-up overlay)
       ================================================================ */
    .bed-drawer-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.55);
      backdrop-filter: blur(6px);
      z-index: 200;
      display: none;
      align-items: flex-end;
      justify-content: center;
      padding: 0;
    }

    .bed-drawer-overlay.open {
      display: flex;
      animation: fadeIn 0.22s ease;
    }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .bed-drawer-panel {
      background: var(--surface);
      border-radius: 24px 24px 0 0;
      width: 100%;
      max-width: 820px;
      max-height: 82vh;
      overflow-y: auto;
      box-shadow: 0 -16px 60px rgba(0,0,0,0.18);
      animation: slideUp 0.32s cubic-bezier(0.34, 1.2, 0.64, 1);
    }

    @keyframes slideUp {
      from { transform: translateY(100%); opacity: 0; }
      to   { transform: translateY(0);    opacity: 1; }
    }

    .bed-drawer-header {
      position: sticky;
      top: 0;
      background: var(--surface);
      border-bottom: 1px solid var(--surface-border);
      padding: 1.25rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 1;
      border-radius: 24px 24px 0 0;
    }

    .bed-drawer-title-group h3 {
      font-size: 1.12rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 2px;
    }

    .bed-drawer-title-group p {
      font-size: 0.78rem;
      color: var(--text-muted);
    }

    .btn-drawer-close {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      border: 1px solid var(--surface-border);
      background: #f8fafc;
      color: var(--text-body);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
      flex-shrink: 0;
    }

    .btn-drawer-close:hover {
      background: var(--status-red-bg);
      color: var(--status-red);
      border-color: rgba(239,68,68,0.3);
    }

    .bed-drawer-body {
      padding: 1.35rem 1.5rem 2rem;
    }

    /* Individual bed tiles grid */
    .bed-tiles-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
      gap: 0.9rem;
    }

    .bed-tile {
      background: #f8fafc;
      border: 1.5px solid var(--surface-border);
      border-radius: 14px;
      padding: 1rem;
      cursor: pointer;
      transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
      position: relative;
      overflow: hidden;
    }

    .bed-tile::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: var(--brand-gradient);
      transform: scaleX(0);
      transition: transform 0.25s ease;
      transform-origin: left;
    }

    .bed-tile:hover::before { transform: scaleX(1); }

    .bed-tile.available {
      border-color: rgba(5,150,105,0.25);
    }

    .bed-tile.available:hover {
      border-color: var(--brand-teal);
      background: #f0fdf4;
      transform: translateY(-4px);
      box-shadow: 0 10px 22px rgba(5,150,105,0.14);
    }

    .bed-tile.occupied, .bed-tile.maintenance, .bed-tile.reserved {
      opacity: 0.65;
      cursor: not-allowed;
    }

    .bed-tile.occupied:hover, .bed-tile.maintenance:hover, .bed-tile.reserved:hover {
      transform: none;
      box-shadow: none;
    }

    .bed-tile-number {
      font-size: 0.95rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 3px;
    }

    .bed-tile-floor {
      font-size: 0.72rem;
      color: var(--text-muted);
      font-weight: 600;
      margin-bottom: 8px;
    }

    .bed-tile-price {
      font-size: 0.82rem;
      font-weight: 800;
      color: var(--brand-primary);
      margin-bottom: 8px;
    }

    .bed-tile-status {
      font-size: 0.67rem;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 5px;
      display: inline-block;
    }

    .tile-status-available   { background: #ecfdf5; color: #059669; }
    .tile-status-occupied    { background: #fef2f2; color: #ef4444; }
    .tile-status-maintenance { background: #f1f5f9; color: #64748b; }
    .tile-status-reserved    { background: #fef3c7; color: #d97706; }
    .tile-status-holding     { background: #fff7ed; color: #c2410c; animation: holdPulse 1.8s ease infinite; }
    @keyframes holdPulse { 0%,100%{ opacity:1; } 50%{ opacity:0.55; } }

    /* bed-tile: holding state (reserved by someone else) */
    .bed-tile.holding {
      opacity: 0.75;
      cursor: not-allowed;
      border-color: rgba(234,88,12,0.4);
      background: #fff7ed;
    }
    .bed-tile.holding:hover { transform: none; box-shadow: none; }

    /* bed-tile: my own active reservation (held by me) */
    .bed-tile.my-hold {
      border-color: rgba(13,148,136,0.6);
      background: linear-gradient(135deg, #f0fdf9, #ecfdf5);
      box-shadow: 0 0 0 2px rgba(13,148,136,0.18);
    }

    /* Active Reservation Banner (shown when patient holds a reservation) */
    .active-reservation-bar {
      background: linear-gradient(135deg, #065f46 0%, #0d4a5e 100%);
      border-radius: var(--radius-lg);
      padding: 1rem 1.35rem;
      margin-bottom: 1.35rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
      box-shadow: 0 6px 20px rgba(5,150,105,0.22);
      animation: slideInDown 0.35s cubic-bezier(0.34,1.2,0.64,1);
    }
    .arb-left {
      display: flex; align-items: center; gap: 0.85rem;
    }
    .arb-icon {
      width: 38px; height: 38px;
      background: rgba(255,255,255,0.14); border-radius: 10px;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .arb-title { font-size: 0.94rem; font-weight: 800; color: #fff; margin-bottom: 2px; }
    .arb-meta  { font-size: 0.76rem; color: rgba(255,255,255,0.7); font-weight: 600; }
    .arb-countdown {
      font-size: 1.2rem; font-weight: 800; color: #34d399;
      letter-spacing: -0.5px; flex-shrink: 0;
    }
    .btn-arb-cancel {
      background: rgba(239,68,68,0.18); border: 1px solid rgba(239,68,68,0.35);
      color: #fca5a5; border-radius: 9px; padding: 0.5rem 1rem;
      font-size: 0.8rem; font-weight: 700; cursor: pointer; flex-shrink: 0;
      transition: all 0.2s ease;
    }
    .btn-arb-cancel:hover { background: rgba(239,68,68,0.32); color: #fff; }

    .btn-choose-bed {
      display: block;
      width: 100%;
      margin-top: 10px;
      background: var(--brand-gradient);
      color: white;
      border: none;
      padding: 0.55rem 0;
      border-radius: 8px;
      font-weight: 700;
      font-size: 0.78rem;
      cursor: pointer;
      transition: all 0.22s ease;
      box-shadow: 0 4px 10px rgba(13,148,136,0.25);
    }

    .btn-choose-bed:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 18px rgba(13,148,136,0.35);
    }

    /* Drawer loading skeleton */
    .drawer-loading {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      padding: 1rem 0;
    }

    .skeleton-row {
      display: flex;
      gap: 0.75rem;
    }

    .skeleton-tile {
      background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
      background-size: 200% 100%;
      animation: shimmer 1.4s infinite;
      border-radius: 14px;
      height: 120px;
      flex: 1;
    }

    @keyframes shimmer {
      0%   { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }

    /* Drawer empty state */
    .drawer-empty {
      text-align: center;
      padding: 2.5rem 1rem;
      color: var(--text-muted);
    }

    .drawer-empty svg {
      margin: 0 auto 0.75rem;
      stroke: var(--brand-teal);
      opacity: 0.55;
    }

    .drawer-empty h4 {
      font-size: 1rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 0.25rem;
    }

    .drawer-empty p {
      font-size: 0.82rem;
    }

    /* ================================================================
       Booking Confirmation Modal
       ================================================================ */
    .booking-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.6);
      backdrop-filter: blur(8px);
      z-index: 300;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }

    .booking-modal-overlay.open {
      display: flex;
      animation: fadeIn 0.2s ease;
    }

    .booking-modal {
      background: var(--surface);
      border-radius: var(--radius-xl);
      width: 100%;
      max-width: 500px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.22);
      overflow: hidden;
      animation: scaleIn 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes scaleIn {
      from { transform: scale(0.88); opacity: 0; }
      to   { transform: scale(1);    opacity: 1; }
    }

    .booking-modal-header {
      background: var(--brand-gradient);
      padding: 1.5rem;
      color: white;
      text-align: center;
    }

    .booking-modal-header h3 {
      font-size: 1.15rem;
      font-weight: 800;
      margin-bottom: 4px;
    }

    .booking-modal-header p {
      font-size: 0.82rem;
      opacity: 0.82;
    }

    .booking-modal-body {
      padding: 1.5rem;
    }

    .booking-detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.65rem 0;
      border-bottom: 1px solid #f1f5f9;
    }

    .booking-detail-row:last-of-type { border-bottom: none; }

    .booking-detail-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .booking-detail-value {
      font-size: 0.9rem;
      font-weight: 700;
      color: var(--text-heading);
      text-align: right;
    }

    .booking-price-highlight {
      background: #f0fdf4;
      border: 1.5px solid rgba(5,150,105,0.25);
      border-radius: 12px;
      padding: 1rem;
      text-align: center;
      margin: 1rem 0;
    }

    .booking-price-highlight .bph-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--status-green);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 4px;
    }

    .booking-price-highlight .bph-value {
      font-size: 1.75rem;
      font-weight: 800;
      color: var(--text-heading);
    }

    .booking-modal-actions {
      display: flex;
      gap: 0.75rem;
      padding: 0 1.5rem 1.5rem;
    }

    .btn-modal-cancel {
      flex: 1;
      background: #f8fafc;
      color: var(--text-body);
      border: 1px solid var(--surface-border);
      padding: 0.75rem;
      border-radius: 11px;
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-modal-cancel:hover { background: #f1f5f9; }

    .btn-modal-confirm {
      flex: 2;
      background: var(--brand-gradient);
      color: white;
      border: none;
      padding: 0.75rem;
      border-radius: 11px;
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
      box-shadow: 0 6px 18px rgba(13,148,136,0.3);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
    }

    .btn-modal-confirm:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 26px rgba(13,148,136,0.42);
    }

    /* ================================================================
       Loading Skeleton for Cards
       ================================================================ */
    .card-skeleton {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      overflow: hidden;
    }

    .skeleton-block {
      background: linear-gradient(90deg, #f1f5f9 25%, #e8edf2 50%, #f1f5f9 75%);
      background-size: 200% 100%;
      animation: shimmer 1.4s infinite;
      border-radius: 8px;
    }

    /* ================================================================
       Toast
       ================================================================ */
    .discovery-toast {
      position: fixed;
      bottom: 1.5rem;
      left: 50%;
      transform: translateX(-50%) translateY(60px);
      padding: 0.85rem 1.5rem;
      border-radius: 12px;
      font-size: 0.875rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
      z-index: 9999;
      box-shadow: 0 12px 30px rgba(0,0,0,0.15);
      opacity: 0;
      transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
      white-space: nowrap;
    }

    .discovery-toast.show {
      transform: translateX(-50%) translateY(0);
      opacity: 1;
    }

    .toast-success { background: #059669; color: white; }
    .toast-error   { background: #ef4444; color: white; }
    .toast-info    { background: var(--brand-primary); color: white; }

    /* ================================================================
       Empty / Error State
       ================================================================ */
    .results-empty-state {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      padding: 3.5rem 2rem;
      text-align: center;
      margin-bottom: 1.75rem;
    }

    .results-empty-state svg {
      margin: 0 auto 1rem;
      stroke: var(--brand-teal);
      opacity: 0.5;
    }

    .results-empty-state h3 {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.35rem;
    }

    .results-empty-state p {
      font-size: 0.85rem;
      color: var(--text-muted);
    }

    /* ================================================================
       Responsive
       ================================================================ */
    @media (max-width: 768px) {
      .comparison-grid { grid-template-columns: 1fr; }
      .filter-strip    { flex-direction: column; }
      .filter-group    { max-width: 100%; }
      .hero-stats-row  { gap: 0.6rem; }
      .hero-stat-chip  { min-width: 100px; }
      .bed-tiles-grid  { grid-template-columns: repeat(2, 1fr); }
      .booking-modal-actions { flex-direction: column; }
    }

    @media (max-width: 480px) {
      .hero-heading  { font-size: 1.2rem; }
      .discovery-hero { padding: 1.35rem 1.25rem; }
      .bed-tiles-grid { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>
<body>

  <!-- Mobile Topbar -->
  <header class="mobile-topbar">
    <button class="mobile-hamburger" id="menuToggle" aria-label="Toggle Navigation">
      <svg class="ui-ico" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
    <a href="#" class="mobile-brand">
      <img src="../assets/images/logo.png" alt="MedPulse">
    </a>
    <div style="width: 38px;"></div>
  </header>

  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <!-- Left Sidebar (identical to dashboard) -->
  <aside class="left-bar" id="appSidebar">
    <a href="dashboard.php" class="brand-header-link">
      <img src="../assets/images/logo.png" alt="MedPulse Hospital & Specialty Care">
    </a>

    <div class="nav-label">Clinical Care</div>
    <ul class="nav-menu">
      <li class="nav-item">
        <a href="dashboard.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            Overview
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
            Medical Specialists
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
            Consultations
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
            Virtual Care Suite
          </div>
          <span class="vc-chip-sm">HD CALL</span>
        </a>
      </li>
      <li class="nav-item active">
        <a href="bed_discovery.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
            Bed Discovery
          </div>
          <span class="live-chip-sm">LIVE</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"></path><path d="M8.5 2h7"></path><path d="M14.5 16h-5"></path></svg>
            Diagnostic Reports
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
            Digital Rx
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="my_bills.php">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            Automated Billing
          </div>
        </a>
      </li>
    </ul>

    <div class="nav-label">Emergency & Support</div>
    <ul class="nav-menu">
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            Ambulance Dispatch
          </div>
        </a>
      </li>
      <li class="nav-item">
        <a href="#">
          <div class="nav-item-inner">
            <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            Account Settings
          </div>
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <a href="../logout.php" class="btn-signout">
        <svg class="ui-ico" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <!-- Main Viewport -->
  <main class="viewport">

    <!-- HERO: Filter Bar + Summary Stats -->
    <div class="discovery-hero">
      <div class="discovery-hero-inner">

        <div class="hero-top-row">
          <div>
            <h1 class="hero-heading">
              <svg style="width:22px;height:22px;stroke:#34d399;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:middle;margin-right:6px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
              Multi-Hospital Bed Discovery
            </h1>
            <p class="hero-sub">Compare live bed availability across <?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?> — all 3 network hospitals</p>
          </div>
          <div class="hero-live-pill">
            <div class="radar-pulse-dot"></div>
            REAL-TIME SYNC
          </div>
        </div>

        <!-- Summary Stats (filled by JS) -->
        <div class="hero-stats-row" id="heroStatsRow">
          <div class="hero-stat-chip">
            <div class="hero-stat-label">Total Beds</div>
            <div class="hero-stat-value blue" id="statTotalBeds">—</div>
          </div>
          <div class="hero-stat-chip">
            <div class="hero-stat-label">Available Now</div>
            <div class="hero-stat-value green" id="statAvailable">—</div>
          </div>
          <div class="hero-stat-chip">
            <div class="hero-stat-label">Occupied</div>
            <div class="hero-stat-value amber" id="statOccupied">—</div>
          </div>
          <div class="hero-stat-chip">
            <div class="hero-stat-label">Occupancy Rate</div>
            <div class="hero-stat-value" id="statOccRate" style="color:#f472b6;">—</div>
          </div>
          <div class="hero-stat-chip">
            <div class="hero-stat-label">Hospitals</div>
            <div class="hero-stat-value blue" id="statHospitals">—</div>
          </div>
        </div>

        <!-- Filter Strip -->
        <div class="filter-strip" id="filterStrip">
          <div class="filter-group">
            <label>
              <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2.5;vertical-align:middle;" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
              Hospital
            </label>
            <select class="filter-select" id="filterHospital">
              <option value="all">All Hospitals</option>
            </select>
          </div>

          <div class="filter-group">
            <label>
              <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2.5;vertical-align:middle;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path></svg>
              Bed Category
            </label>
            <select class="filter-select" id="filterCategory">
              <option value="all">All Categories</option>
              <option value="general">General Ward</option>
              <option value="cabin">Cabin / Suite</option>
              <option value="icu">ICU / CCU / NICU</option>
              <option value="critical">Critical + Emergency</option>
            </select>
          </div>

          <div class="filter-group">
            <label>
              <svg style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2.5;vertical-align:middle;" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
              Max Price/Day
            </label>
            <div>
              <input type="range" class="filter-input" id="filterPrice"
                     min="500" max="20000" step="500" value="20000"
                     oninput="document.getElementById('priceLabel').textContent = '৳' + Number(this.value).toLocaleString()">
              <div class="price-display" id="priceLabel">৳20,000</div>
            </div>
          </div>

          <button class="btn-search-beds" id="btnSearch" onclick="loadBeds()">
            <svg style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            Search Beds
          </button>

          <button class="btn-reset-filter" onclick="resetFilters()">
            <svg style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:middle;" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 .49-3.5"></path></svg>
            Reset
          </button>
        </div>

      </div>
    </div>

    <!-- Comparison Results -->
    <div id="comparisonGrid" class="comparison-grid">
      <!-- Filled by JS -->
    </div>

  </main>

  <!-- Right Profile Panel -->
  <aside class="profile-bar">
    <div class="patient-hero">
      <div class="patient-avatar-wrap">
        <div class="patient-avatar-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </div>
      </div>
      <h3 style="font-size:1.05rem;font-weight:800;color:var(--text-heading);"><?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?></h3>
      <p style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($patientEmail, ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="vitals-cards-2x2">
      <div class="vital-cell"><label>Blood Type</label><strong style="color:var(--status-red);"><?= htmlspecialchars($bloodGroup, ENT_QUOTES, 'UTF-8') ?></strong><div class="vital-progress"><div class="vital-progress-bar bar-optimal"></div></div></div>
      <div class="vital-cell"><label>Age / Sex</label><strong><?= htmlspecialchars((string)$age, ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($gender, ENT_QUOTES, 'UTF-8') ?></strong><div class="vital-progress"><div class="vital-progress-bar bar-optimal"></div></div></div>
      <div class="vital-cell"><label>Blood Pressure</label><strong>120/80</strong><div class="vital-progress"><div class="vital-progress-bar bar-normal"></div></div></div>
      <div class="vital-cell"><label>Heart Rate</label><strong>74 bpm</strong><div class="vital-progress"><div class="vital-progress-bar bar-normal"></div></div></div>
    </div>

    <!-- Quick Stats -->
    <div style="background:#f8fafc;border:1px solid var(--surface-border);border-radius:12px;padding:1rem;">
      <span style="display:block;font-size:0.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px;">Network Coverage</span>
      <div style="display:flex;flex-direction:column;gap:8px;" id="sidebarHospitalList">
        <div style="font-size:0.8rem;color:var(--text-muted);">Loading hospitals…</div>
      </div>
    </div>

    <a href="tel:999" class="ambulance-card-btn" style="margin-top:auto;">
      <svg class="ui-ico" style="stroke:white;width:26px;height:26px;margin:0 auto 4px;" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
      <span style="display:block;font-size:0.72rem;text-transform:uppercase;font-weight:700;opacity:0.9;">Emergency 24/7</span>
      <strong>Request Ambulance</strong>
    </a>
  </aside>

  <!-- ================================================================
       BED DRAWER — Ward-Level Individual Beds
       ================================================================ -->
  <div class="bed-drawer-overlay" id="bedDrawerOverlay" onclick="closeBedDrawer(event)">
    <div class="bed-drawer-panel" id="bedDrawerPanel">
      <div class="bed-drawer-header">
        <div class="bed-drawer-title-group">
          <h3 id="drawerTitle">Available Beds</h3>
          <p id="drawerSubtitle">Select a bed to proceed with admission request</p>
        </div>
        <button class="btn-drawer-close" onclick="closeBedDrawerDirect()">
          <svg style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round;" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>
      <div class="bed-drawer-body" id="bedDrawerBody">
        <!-- Filled by JS -->
      </div>
    </div>
  </div>

  <!-- ================================================================
       BOOKING CONFIRMATION MODAL
       ================================================================ -->
  <div class="booking-modal-overlay" id="bookingModalOverlay" onclick="closeBookingModal(event)">
    <div class="booking-modal" id="bookingModal">
      <div class="booking-modal-header">
        <div style="width:52px;height:52px;background:rgba(255,255,255,0.15);border-radius:14px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;">
          <svg style="width:26px;height:26px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
        </div>
        <h3>Confirm Bed Request</h3>
        <p>Review your selection before submitting the admission request</p>
      </div>
      <div class="booking-modal-body">
        <div class="booking-detail-row"><span class="booking-detail-label">Hospital</span><span class="booking-detail-value" id="modalHospital">—</span></div>
        <div class="booking-detail-row"><span class="booking-detail-label">Bed Number</span><span class="booking-detail-value" id="modalBedNumber">—</span></div>
        <div class="booking-detail-row"><span class="booking-detail-label">Ward Type</span><span class="booking-detail-value" id="modalWardType">—</span></div>
        <div class="booking-detail-row"><span class="booking-detail-label">Floor</span><span class="booking-detail-value" id="modalFloor">—</span></div>
        <div class="booking-detail-row"><span class="booking-detail-label">Patient</span><span class="booking-detail-value"><?= htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="booking-price-highlight">
          <div class="bph-label">Daily Rate</div>
          <div class="bph-value" id="modalPrice">৳—</div>
        </div>
        <p style="font-size:0.76rem;color:var(--text-muted);line-height:1.5;">By confirming, you submit an inpatient admission request. Hospital staff will process and confirm the allocation.</p>
      </div>
      <div class="booking-modal-actions">
        <button class="btn-modal-cancel" onclick="closeBookingModalDirect()">Cancel</button>
        <button class="btn-modal-confirm" id="btnConfirmBooking" onclick="submitBookingRequest()">
          <svg style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Confirm Request
        </button>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <div class="discovery-toast" id="discoveryToast"></div>

  <!-- ================================================================
       JAVASCRIPT ENGINE — Reservation-Aware
       ================================================================ -->
  <script>
  // ═══════════════════════════════════════════════════════════════════
  // STATE
  // ═══════════════════════════════════════════════════════════════════
  let currentBedSelection   = null;  // bed info being shown in modal
  let activeReservation     = null;  // { token, allocation_id, bed_id, bed_number, hospital_name, expires_at, countdown_sec }
  let allHospitalsList      = [];
  let countdownTimer        = null;  // setInterval handle for the countdown
  let autoRefreshTimer      = null;

  const RESERVATION_API     = '../backend/api/bed_reservation.php';
  const DISCOVERY_API       = '../backend/api/get_hospital_beds_discovery.php';
  const SPIN_SVG            = `<svg style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;animation:spin 0.8s linear infinite;" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>`;

  // ── Ward Icon Mapping ──────────────────────────────────────────────
  const wardIconClass = (ward) => {
    const w = ward.toLowerCase();
    if (w.includes('icu'))        return 'icon-icu';
    if (w.includes('ccu'))        return 'icon-ccu';
    if (w.includes('nicu'))       return 'icon-nicu';
    if (w.includes('emergency'))  return 'icon-emg';
    if (w.includes('general') || w.includes('pediatrics')) return 'icon-general';
    if (w.includes('cabin') || w.includes('semi')) return 'icon-cabin';
    if (w.includes('vip') || w.includes('presidential')) return 'icon-vip';
    if (w.includes('recovery'))   return 'icon-recovery';
    return 'icon-general';
  };

  const wardSVG = (ward) => {
    const w = ward.toLowerCase();
    if (w.includes('icu') || w.includes('ccu') || w.includes('nicu'))
      return `<svg style="width:16px;height:16px;stroke:#ef4444;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>`;
    if (w.includes('emergency'))
      return `<svg style="width:16px;height:16px;stroke:#dc2626;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>`;
    if (w.includes('cabin') || w.includes('vip') || w.includes('presidential'))
      return `<svg style="width:16px;height:16px;stroke:#2563eb;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M3 21h18"></path><path d="M19 21v-4"></path><path d="M19 17a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v4"></path></svg>`;
    return `<svg style="width:16px;height:16px;stroke:#059669;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>`;
  };

  const wardStatusPill = (available, total) => {
    const ratio = total > 0 ? available / total : 0;
    if (available === 0)  return `<span class="ward-status-pill wsp-full">Full</span>`;
    if (ratio < 0.25)     return `<span class="ward-status-pill wsp-limited">Limited</span>`;
    return `<span class="ward-status-pill wsp-available">Available</span>`;
  };

  const occupancyClass = (pct) => {
    if (pct >= 80) return 'occ-high';
    if (pct >= 50) return 'occ-mid';
    return 'occ-low';
  };

  const hospitalBadge = (avail, total) => {
    const ratio = total > 0 ? avail / total : 0;
    if (avail === 0)   return `<span class="hospital-avail-badge badge-beds-full">Full Capacity</span>`;
    if (ratio < 0.2)   return `<span class="hospital-avail-badge badge-beds-limited">⚡ ${avail} Beds Left</span>`;
    return `<span class="hospital-avail-badge badge-beds-available">✓ ${avail} Available</span>`;
  };

  // ── Toast ──────────────────────────────────────────────────────────
  function showToast(msg, type = 'info', duration = 3800) {
    const t = document.getElementById('discoveryToast');
    t.innerHTML = msg;
    t.className = `discovery-toast toast-${type} show`;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.classList.remove('show'); }, duration);
  }

  // ═══════════════════════════════════════════════════════════════════
  // ACTIVE RESERVATION BANNER
  // ═══════════════════════════════════════════════════════════════════
  function showReservationBanner(res) {
    let el = document.getElementById('activeReservationBar');
    if (!el) {
      el = document.createElement('div');
      el.id = 'activeReservationBar';
      const grid = document.getElementById('comparisonGrid');
      grid.parentNode.insertBefore(el, grid);
    }
    el.className = 'active-reservation-bar';
    el.innerHTML = `
      <div class="arb-left">
        <div class="arb-icon">
          <svg style="width:18px;height:18px;stroke:#34d399;fill:none;stroke-width:2;stroke-linecap:round;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
        </div>
        <div>
          <div class="arb-title">Bed ${escHtml(res.bed_number)} — ${escHtml(res.hospital_name)}</div>
          <div class="arb-meta">Held for you · Expires in <span id="arbCountdown" class="arb-countdown"></span></div>
        </div>
      </div>
      <div style="display:flex;gap:0.75rem;align-items:center;">
        <button class="btn-action-gradient" style="padding:0.5rem 1.1rem;font-size:0.82rem;"
          onclick="openConfirmModal()">
          ✓ Confirm Admission
        </button>
        <button class="btn-arb-cancel" onclick="cancelReservation()">
          Release
        </button>
      </div>`;

    startCountdown(res.countdown_sec);
  }

  function hideReservationBanner() {
    const el = document.getElementById('activeReservationBar');
    if (el) el.remove();
    stopCountdown();
  }

  function startCountdown(seconds) {
    stopCountdown();
    let remaining = Math.max(0, Math.round(seconds));
    const update = () => {
      const el = document.getElementById('arbCountdown');
      if (!el) return;
      if (remaining <= 0) {
        stopCountdown();
        hideReservationBanner();
        activeReservation = null;
        showToast('⏱ Your bed reservation expired. The bed is now available again.', 'error', 5000);
        loadBeds();
        return;
      }
      const m = Math.floor(remaining / 60);
      const s = remaining % 60;
      el.textContent = `${m}:${String(s).padStart(2,'0')}`;
      el.style.color = remaining <= 60 ? '#fca5a5' : '#34d399';
      remaining--;
    };
    update();
    countdownTimer = setInterval(update, 1000);
  }

  function stopCountdown() {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
  }

  // ═══════════════════════════════════════════════════════════════════
  // LOAD BEDS
  // ═══════════════════════════════════════════════════════════════════
  async function loadBeds() {
    const grid     = document.getElementById('comparisonGrid');
    const hospital = document.getElementById('filterHospital').value;
    const category = document.getElementById('filterCategory').value;
    const maxPrice = document.getElementById('filterPrice').value;

    grid.innerHTML = Array(3).fill(0).map(() => `
      <div class="card-skeleton" style="min-height:320px;">
        <div style="height:4px;" class="skeleton-block"></div>
        <div style="padding:1.25rem 1.35rem;">
          <div class="skeleton-block" style="height:20px;width:60%;margin-bottom:10px;"></div>
          <div class="skeleton-block" style="height:14px;width:40%;margin-bottom:20px;"></div>
          ${Array(5).fill(0).map(() => `<div class="skeleton-block" style="height:14px;margin-bottom:10px;"></div>`).join('')}
        </div>
      </div>`).join('');

    try {
      const params = new URLSearchParams({ hospital_id: hospital, category, max_price: maxPrice });
      const res    = await fetch(`${DISCOVERY_API}?${params}`);
      const data   = await res.json();

      if (!data.success) {
        showToast('Failed to load bed data. Please try again.', 'error');
        grid.innerHTML = emptyState('API Error', data.message || 'Unexpected error.');
        return;
      }

      // Populate hospital filter on first load
      if (allHospitalsList.length === 0 && data.all_hospitals_list) {
        allHospitalsList = data.all_hospitals_list;
        const sel = document.getElementById('filterHospital');
        sel.innerHTML = '<option value="all">All Hospitals</option>';
        data.all_hospitals_list.forEach(h => {
          sel.innerHTML += `<option value="${h.hospital_id}">${escHtml(h.name)}</option>`;
        });
      }

      updateHeroStats(data.summary, data.hospitals.length);
      buildSidebarList(data.all_hospitals_list || allHospitalsList, data.hospitals);

      if (!data.hospitals || data.hospitals.length === 0) {
        grid.innerHTML = emptyState('No Results Found', 'No beds match your current filters. Adjust the options above.');
        return;
      }

      grid.innerHTML = data.hospitals.map((hosp, idx) => buildHospitalCard(hosp, idx)).join('');

      document.querySelectorAll('.hospital-card').forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        setTimeout(() => {
          el.style.transition = 'opacity 0.4s ease, transform 0.4s cubic-bezier(0.34,1.2,0.64,1)';
          el.style.opacity = '1';
          el.style.transform = 'translateY(0)';
        }, i * 90);
      });

    } catch (err) {
      console.error(err);
      showToast('Network error. Check your connection.', 'error');
      grid.innerHTML = emptyState('Connection Error', 'Could not reach the server.');
    }
  }

  function updateHeroStats(summary, hospitalCount) {
    document.getElementById('statTotalBeds').textContent  = summary.total_beds?.toLocaleString() ?? '—';
    document.getElementById('statAvailable').textContent  = summary.total_available?.toLocaleString() ?? '—';
    document.getElementById('statOccupied').textContent   = summary.total_occupied?.toLocaleString() ?? '—';
    document.getElementById('statOccRate').textContent    = (summary.occupancy_rate ?? 0) + '%';
    document.getElementById('statHospitals').textContent  = hospitalCount;
  }

  function buildSidebarList(allHospList, resultHosp) {
    const avMap = {};
    (resultHosp || []).forEach(h => { avMap[h.hospital_id] = h.available_beds; });
    const el = document.getElementById('sidebarHospitalList');
    el.innerHTML = allHospList.map(h => {
      const avail = avMap[h.hospital_id] ?? '?';
      const color = avail === 0 ? 'var(--status-red)' : avail < 20 ? 'var(--status-amber)' : 'var(--status-green)';
      return `<div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
        <span style="font-size:0.78rem;font-weight:600;color:var(--text-body);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">${escHtml(h.name)}</span>
        <span style="font-size:0.74rem;font-weight:800;color:${color};flex-shrink:0;">${avail} free</span>
      </div>`;
    }).join('');
  }

  function buildHospitalCard(hosp, idx) {
    const wardRows = hosp.wards.slice(0, 8).map(ward => {
      const occ   = ward.occupancy_pct;
      const occCl = occupancyClass(occ);
      return `
        <div class="ward-row">
          <div class="ward-type-icon ${wardIconClass(ward.ward_type)}">${wardSVG(ward.ward_type)}</div>
          <div class="ward-name-block">
            <div class="ward-type-label">${escHtml(ward.ward_type)}</div>
            <div class="ward-price-label">৳${Number(ward.price_per_day).toLocaleString()} / day</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <div class="occupancy-mini">
              <div class="occupancy-fill ${occCl}" style="width:${occ}%"></div>
            </div>
            <div class="ward-avail-col">
              <div class="ward-avail-count">${ward.available_beds}</div>
              <div class="ward-total-label">/ ${ward.total_beds}</div>
            </div>
            ${wardStatusPill(ward.available_beds, ward.total_beds)}
          </div>
        </div>`;
    }).join('');

    const hiddenCount = hosp.wards.length - 8;
    return `
      <div class="hospital-card" data-hospital-id="${hosp.hospital_id}">
        <div class="card-accent"></div>
        <div class="hospital-card-header">
          <div class="hospital-name-block">
            <h3>${escHtml(hosp.hospital_name)}</h3>
            <div class="hospital-meta">
              <svg style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
              ${escHtml(hosp.city)} &nbsp;·&nbsp; ${escHtml(hosp.contact_number)}
            </div>
          </div>
          ${hospitalBadge(hosp.available_beds, hosp.total_beds)}
        </div>
        <div class="ward-rows-list">${wardRows}</div>
        ${hiddenCount > 0 ? `<div style="text-align:center;padding:0.5rem 0;font-size:0.75rem;color:var(--text-muted);font-weight:600;">+${hiddenCount} more ward types</div>` : ''}
        <div class="card-footer-cta">
          <div class="card-contact-meta">
            <svg style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.16 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"></path></svg>
            ${escHtml(hosp.contact_number)}
          </div>
          <button class="btn-view-all-wards"
            onclick="openBedDrawer(${hosp.hospital_id}, ${JSON.stringify(hosp.hospital_name)}, ${JSON.stringify(hosp.address)})">
            <svg style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            Choose Bed
          </button>
        </div>
      </div>`;
  }

  function emptyState(title, msg) {
    return `<div class="results-empty-state" style="grid-column:1/-1;">
      <svg style="width:52px;height:52px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
      <h3>${escHtml(title)}</h3><p>${escHtml(msg)}</p>
    </div>`;
  }

  // ═══════════════════════════════════════════════════════════════════
  // BED DRAWER — with live status polling
  // ═══════════════════════════════════════════════════════════════════
  let drawerPollTimer = null;

  async function openBedDrawer(hospitalId, hospitalName, address) {
    const overlay = document.getElementById('bedDrawerOverlay');
    const body    = document.getElementById('bedDrawerBody');
    document.getElementById('drawerTitle').textContent    = `Choose a Bed — ${hospitalName}`;
    document.getElementById('drawerSubtitle').textContent = address || 'Select an available bed to proceed with your admission request';

    body.innerHTML = `<div class="drawer-loading">${Array(2).fill(0).map(() =>
      `<div class="skeleton-row">${Array(4).fill(0).map(() => '<div class="skeleton-tile"></div>').join('')}</div>`
    ).join('')}</div>`;

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    await renderBedDrawer(hospitalId, hospitalName, body);

    // Poll every 8 seconds for live bed status changes
    clearInterval(drawerPollTimer);
    drawerPollTimer = setInterval(() => renderBedDrawer(hospitalId, hospitalName, body, true), 8000);
  }

  async function renderBedDrawer(hospitalId, hospitalName, container, silent = false) {
    const category = document.getElementById('filterCategory').value;
    const maxPrice = document.getElementById('filterPrice').value;
    const params   = new URLSearchParams({ hospital_id: hospitalId, category, max_price: maxPrice });

    try {
      const res  = await fetch(`${DISCOVERY_API}?${params}`);
      const data = await res.json();
      const hospData = data.hospitals?.find(h => h.hospital_id === hospitalId);

      if (!hospData || !hospData.wards || hospData.wards.length === 0) {
        container.innerHTML = drawerEmpty('No Beds Available', 'All beds in this hospital are currently occupied or under maintenance.');
        return;
      }

      buildBedTilesFromWards(hospData, hospitalId, hospitalName, container);
    } catch (err) {
      if (!silent) container.innerHTML = drawerEmpty('Connection Error', 'Could not load bed data. Please try again.');
    }
  }

  function buildBedTilesFromWards(hospData, hospitalId, hospitalName, container) {
    let html = '';

    hospData.wards.forEach(ward => {
      html += `
        <div style="margin-bottom:1.5rem;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <div>
              <span style="font-size:0.9rem;font-weight:800;color:var(--text-heading);">${escHtml(ward.ward_type)}</span>
              <span style="font-size:0.76rem;color:var(--text-muted);margin-left:8px;font-weight:600;">৳${Number(ward.price_per_day).toLocaleString()}/day</span>
            </div>
            ${wardStatusPill(ward.available_beds, ward.total_beds)}
          </div>
          <div class="bed-tiles-grid">`;

      const bedBase = ward.sample_bed_id;
      const showMax = Math.min(ward.total_beds, 12);

      for (let i = 0; i < showMax; i++) {
        const isAvail   = i < ward.available_beds;
        const isOccupied = i < ward.available_beds + ward.occupied_beds && !isAvail;
        const isMaint   = !isAvail && !isOccupied;

        const bedId   = bedBase + i;
        const bedNum  = `${ward.ward_type.substring(0,3).toUpperCase()}-${String(bedId).padStart(3,'0')}`;
        const floor   = `Floor ${Math.ceil(bedId / 50) || 1}`;

        // Check if this bed is held by another patient (or by me)
        const isMine        = activeReservation && activeReservation.bed_id === bedId;
        const isHolding     = !isAvail && ward.reserved_beds > 0 && !isOccupied && !isMaint;

        let tileClass  = 'available';
        let statusText = 'Available';
        let statusClass = 'tile-status-available';

        if (isMine) {
          tileClass = 'my-hold'; statusText = 'My Hold'; statusClass = 'tile-status-available';
        } else if (isOccupied) {
          tileClass = 'occupied'; statusText = 'Occupied'; statusClass = 'tile-status-occupied';
        } else if (isMaint) {
          tileClass = 'maintenance'; statusText = 'Maintenance'; statusClass = 'tile-status-maintenance';
        } else if (!isAvail) {
          tileClass = 'holding'; statusText = 'Under Process'; statusClass = 'tile-status-holding';
        }

        const canChoose = tileClass === 'available' || tileClass === 'my-hold';
        const chooseBtnHtml = canChoose ? `
          <button class="btn-choose-bed" id="choose-btn-${bedId}"
            onclick="chooseBed({
              bed_id: ${bedId},
              hospital_id: ${hospitalId},
              hospital_name: ${JSON.stringify(hospitalName)},
              bed_number: ${JSON.stringify(bedNum)},
              ward_type: ${JSON.stringify(ward.ward_type)},
              floor: ${JSON.stringify(floor)},
              price: ${ward.price_per_day}
            })">
            ${isMine ? '✓ My Bed — Confirm' : 'Choose Bed'}
          </button>` : '';

        html += `
          <div class="bed-tile ${tileClass}" data-bed-id="${bedId}">
            <div class="bed-tile-number">${escHtml(bedNum)}</div>
            <div class="bed-tile-floor">${floor}</div>
            <div class="bed-tile-price">৳${Number(ward.price_per_day).toLocaleString()}</div>
            <span class="bed-tile-status ${statusClass}">${statusText}</span>
            ${chooseBtnHtml}
          </div>`;
      }

      const remaining = ward.total_beds - showMax;
      if (remaining > 0) {
        html += `<div class="bed-tile" style="cursor:default;display:flex;align-items:center;justify-content:center;background:#f1f5f9;border-style:dashed;">
          <span style="font-size:0.8rem;font-weight:700;color:var(--text-muted);">+${remaining} more</span>
        </div>`;
      }

      html += `</div></div>`;
    });

    container.innerHTML = html;
  }

  function drawerEmpty(title, msg) {
    return `<div class="drawer-empty">
      <svg style="width:48px;height:48px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
      <h4>${escHtml(title)}</h4><p>${escHtml(msg)}</p>
    </div>`;
  }

  function closeBedDrawer(event) {
    if (event.target === document.getElementById('bedDrawerOverlay')) closeBedDrawerDirect();
  }

  function closeBedDrawerDirect() {
    clearInterval(drawerPollTimer); drawerPollTimer = null;
    document.getElementById('bedDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }

  // ═══════════════════════════════════════════════════════════════════
  // CHOOSE BED → reserve → modal
  // ═══════════════════════════════════════════════════════════════════
  async function chooseBed(bedInfo) {
    // If patient already holds this exact bed, open the confirm modal directly
    if (activeReservation && activeReservation.bed_id === bedInfo.bed_id) {
      openConfirmModal();
      return;
    }

    // If patient holds a DIFFERENT reservation, block
    if (activeReservation) {
      showToast(`⚠ You already hold Bed ${activeReservation.bed_number} at ${activeReservation.hospital_name}. Release it first.`, 'error', 5000);
      return;
    }

    // Mark the button as loading
    const btn = document.getElementById(`choose-btn-${bedInfo.bed_id}`);
    if (btn) { btn.disabled = true; btn.innerHTML = `${SPIN_SVG} Reserving…`; }

    try {
      const res  = await fetch(RESERVATION_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reserve', bed_id: bedInfo.bed_id }),
      });
      const data = await res.json();

      if (!data.success) {
        if (btn) { btn.disabled = false; btn.innerHTML = 'Choose Bed'; }

        const code = data.error_code || '';
        if (code === 'ALREADY_RESERVED') {
          showToast(`⚠ ${data.message}`, 'error', 6000);
          // Restore the existing reservation state from server response
          if (data.existing_bed) {
            showToast(`You have Bed ${data.existing_bed.bed_number} reserved. Cancel it or confirm it first.`, 'error', 6000);
          }
        } else if (code === 'ALREADY_ADMITTED') {
          showToast(`🏥 ${data.message}`, 'error', 6000);
        } else if (code === 'BED_UNDER_PROCESS') {
          showToast(`⏱ ${data.message}`, 'info', 5000);
          // Update that tile to 'Holding' immediately
          const tile = document.querySelector(`[data-bed-id="${bedInfo.bed_id}"]`);
          if (tile) {
            tile.className = 'bed-tile holding';
            tile.querySelector('.bed-tile-status').className = 'bed-tile-status tile-status-holding';
            tile.querySelector('.bed-tile-status').textContent = 'Under Process';
            const existingBtn = tile.querySelector('.btn-choose-bed');
            if (existingBtn) existingBtn.remove();
          }
        } else {
          showToast(`✗ ${data.message || 'Could not reserve this bed. Please try another.'}`, 'error', 5000);
        }
        return;
      }

      // ✓ Reservation placed — store state
      activeReservation = {
        token:         data.reservation_token,
        allocation_id: data.allocation_id,
        bed_id:        data.bed.bed_id,
        bed_number:    data.bed.bed_number,
        ward_type:     data.bed.ward_type,
        hospital_name: data.hospital.hospital_name,
        hospital_id:   data.hospital.hospital_id,
        price:         data.bed.price_per_day,
        floor:         bedInfo.floor,
        expires_at:    data.expires_at,
        countdown_sec: data.expires_in_sec,
      };

      currentBedSelection = {
        ...bedInfo,
        bed_number:    data.bed.bed_number,
        hospital_name: data.hospital.hospital_name,
      };

      // Show the green banner
      showReservationBanner(activeReservation);

      // Update the tile immediately
      const tile = document.querySelector(`[data-bed-id="${bedInfo.bed_id}"]`);
      if (tile) {
        tile.className = 'bed-tile my-hold';
        tile.querySelector('.bed-tile-status').textContent = 'My Hold';
        if (btn) { btn.disabled = false; btn.textContent = '✓ My Bed — Confirm'; }
      }

      // Open the confirmation modal
      openConfirmModal();

    } catch (err) {
      console.error(err);
      if (btn) { btn.disabled = false; btn.innerHTML = 'Choose Bed'; }
      showToast('Network error. Please try again.', 'error');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // CONFIRM MODAL (now called by banner + tile button)
  // ═══════════════════════════════════════════════════════════════════
  function openConfirmModal() {
    if (!activeReservation && !currentBedSelection) return;
    const info = activeReservation || currentBedSelection;
    document.getElementById('modalHospital').textContent  = info.hospital_name;
    document.getElementById('modalBedNumber').textContent = info.bed_number;
    document.getElementById('modalWardType').textContent  = info.ward_type;
    document.getElementById('modalFloor').textContent     = info.floor || '—';
    document.getElementById('modalPrice').textContent     = '৳' + Number(info.price).toLocaleString() + ' / day';
    document.getElementById('bookingModalOverlay').classList.add('open');
  }

  // Legacy alias
  function openBookingModal(bedInfo) {
    currentBedSelection = bedInfo;
    openConfirmModal();
  }

  function closeBookingModal(event) {
    if (event.target === document.getElementById('bookingModalOverlay')) closeBookingModalDirect();
  }

  function closeBookingModalDirect() {
    document.getElementById('bookingModalOverlay').classList.remove('open');
  }

  // ═══════════════════════════════════════════════════════════════════
  // CONFIRM RESERVATION → marks bed Occupied
  // ═══════════════════════════════════════════════════════════════════
  async function submitBookingRequest() {
    if (!activeReservation) {
      showToast('No active reservation found. Please choose a bed first.', 'error');
      return;
    }

    const btn = document.getElementById('btnConfirmBooking');
    btn.disabled  = true;
    btn.innerHTML = `${SPIN_SVG} Confirming…`;

    try {
      const res  = await fetch(RESERVATION_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action:             'confirm',
          reservation_token:  activeReservation.token,
          allocation_id:      activeReservation.allocation_id,
        }),
      });
      const data = await res.json();

      btn.disabled  = false;
      btn.innerHTML = `<svg style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> Confirm Request`;

      if (!data.success) {
        if (data.error_code === 'RESERVATION_EXPIRED') {
          closeBookingModalDirect();
          hideReservationBanner();
          activeReservation = null;
          showToast('⏱ Reservation expired while confirming. Please choose again.', 'error', 6000);
          loadBeds();
        } else {
          showToast(`✗ ${data.message}`, 'error', 5000);
        }
        return;
      }

      // ✓ Fully confirmed
      closeBookingModalDirect();
      closeBedDrawerDirect();
      hideReservationBanner();
      activeReservation = null;

      showToast(`🏥 Admission confirmed! Bed ${data.bed.bed_number} at ${data.hospital_name}. Please report to reception.`, 'success', 8000);
      loadBeds();

    } catch (err) {
      console.error(err);
      btn.disabled  = false;
      btn.innerHTML = `<svg style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> Confirm Request`;
      showToast('Network error during confirmation. Please try again.', 'error');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // CANCEL / RELEASE RESERVATION
  // ═══════════════════════════════════════════════════════════════════
  async function cancelReservation() {
    if (!activeReservation) return;

    if (!confirm(`Release your reservation for Bed ${activeReservation.bed_number}? The bed will become available to other patients.`)) return;

    try {
      const res  = await fetch(RESERVATION_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action:             'release',
          reservation_token:  activeReservation.token,
          allocation_id:      activeReservation.allocation_id,
        }),
      });
      const data = await res.json();

      hideReservationBanner();
      activeReservation = null;
      closeBookingModalDirect();

      if (data.success) {
        showToast('Reservation released. The bed is now available again.', 'info');
      } else {
        showToast(`Note: ${data.message}`, 'info');
      }
      loadBeds();
    } catch (err) {
      console.error(err);
      showToast('Network error. Reservation may still be active.', 'error');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // FILTERS
  // ═══════════════════════════════════════════════════════════════════
  function resetFilters() {
    document.getElementById('filterHospital').value   = 'all';
    document.getElementById('filterCategory').value   = 'all';
    document.getElementById('filterPrice').value      = '20000';
    document.getElementById('priceLabel').textContent = '৳20,000';
    loadBeds();
  }

  // ═══════════════════════════════════════════════════════════════════
  // UTILITIES
  // ═══════════════════════════════════════════════════════════════════
  function escHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function escJs(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  }

  // ── Mobile sidebar ─────────────────────────────────────────────────
  const menuToggle      = document.getElementById('menuToggle');
  const appSidebar      = document.getElementById('appSidebar');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');
  if (menuToggle) {
    menuToggle.addEventListener('click', () => { appSidebar.classList.toggle('open'); sidebarBackdrop.classList.toggle('active'); });
    sidebarBackdrop.addEventListener('click', () => { appSidebar.classList.remove('open'); sidebarBackdrop.classList.remove('active'); });
  }

  // Spin keyframe
  const spinStyle = document.createElement('style');
  spinStyle.textContent = `@keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }`;
  document.head.appendChild(spinStyle);

  // Auto-refresh every 45 seconds (skip if drawer is open)
  autoRefreshTimer = setInterval(() => {
    if (!document.getElementById('bedDrawerOverlay').classList.contains('open')) {
      loadBeds();
      showToast('↻ Bed availability refreshed', 'info', 2000);
    }
  }, 45000);

  document.addEventListener('DOMContentLoaded', () => loadBeds());
  </script>

  // ── Ward Icon Mapping ──────────────────────────────────────────────
  const wardIconClass = (ward) => {
    const w = ward.toLowerCase();
    if (w.includes('icu'))        return 'icon-icu';
    if (w.includes('ccu'))        return 'icon-ccu';
    if (w.includes('nicu'))       return 'icon-nicu';
    if (w.includes('emergency'))  return 'icon-emg';
    if (w.includes('general') || w.includes('pediatrics')) return 'icon-general';
    if (w.includes('cabin') || w.includes('semi')) return 'icon-cabin';
    if (w.includes('vip') || w.includes('presidential')) return 'icon-vip';
    if (w.includes('recovery'))   return 'icon-recovery';
    return 'icon-general';
  };

  const wardSVG = (ward) => {
    const w = ward.toLowerCase();
    if (w.includes('icu') || w.includes('ccu') || w.includes('nicu'))
      return `<svg style="width:16px;height:16px;stroke:#ef4444;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>`;
    if (w.includes('emergency'))
      return `<svg style="width:16px;height:16px;stroke:#dc2626;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>`;
    if (w.includes('cabin') || w.includes('vip') || w.includes('presidential'))
      return `<svg style="width:16px;height:16px;stroke:#2563eb;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M3 21h18"></path><path d="M19 21v-4"></path><path d="M19 17a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v4"></path></svg>`;
    return `<svg style="width:16px;height:16px;stroke:#059669;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>`;
  };

  const wardStatusPill = (available, total) => {
    const ratio = total > 0 ? available / total : 0;
    if (available === 0)  return `<span class="ward-status-pill wsp-full">Full</span>`;
    if (ratio < 0.25)     return `<span class="ward-status-pill wsp-limited">Limited</span>`;
    return `<span class="ward-status-pill wsp-available">Available</span>`;
  };

  const occupancyClass = (pct) => {
    if (pct >= 80) return 'occ-high';
    if (pct >= 50) return 'occ-mid';
    return 'occ-low';
  };

  const hospitalBadge = (avail, total) => {
    const ratio = total > 0 ? avail / total : 0;
    if (avail === 0)   return `<span class="hospital-avail-badge badge-beds-full">Full Capacity</span>`;
    if (ratio < 0.2)   return `<span class="hospital-avail-badge badge-beds-limited">⚡ ${avail} Beds Left</span>`;
    return `<span class="hospital-avail-badge badge-beds-available">✓ ${avail} Available</span>`;
  };

  // ── Toast ──────────────────────────────────────────────────────────
  function showToast(msg, type = 'info') {
    const t = document.getElementById('discoveryToast');
    t.textContent = msg;
    t.className   = `discovery-toast toast-${type} show`;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.classList.remove('show'); }, 3200);
  }

  // ── Load Beds from API ─────────────────────────────────────────────
  async function loadBeds() {
    const grid       = document.getElementById('comparisonGrid');
    const hospital   = document.getElementById('filterHospital').value;
    const category   = document.getElementById('filterCategory').value;
    const maxPrice   = document.getElementById('filterPrice').value;

    // Show skeleton
    grid.innerHTML = Array(3).fill(0).map(() => `
      <div class="card-skeleton" style="min-height:320px;">
        <div style="height:4px;" class="skeleton-block"></div>
        <div style="padding:1.25rem 1.35rem;">
          <div class="skeleton-block" style="height:20px;width:60%;margin-bottom:10px;"></div>
          <div class="skeleton-block" style="height:14px;width:40%;margin-bottom:20px;"></div>
          ${Array(5).fill(0).map(() => `<div class="skeleton-block" style="height:14px;margin-bottom:10px;"></div>`).join('')}
        </div>
      </div>
    `).join('');

    const params = new URLSearchParams({ hospital_id: hospital, category, max_price: maxPrice });

    try {
      const res  = await fetch(`../backend/api/get_hospital_beds_discovery.php?${params}`);
      const data = await res.json();

      if (!data.success) {
        showToast('Failed to load bed data. Please try again.', 'error');
        grid.innerHTML = emptyState('API Error', data.message || 'Unexpected error from server.');
        return;
      }

      lastLoadedData = data;

      // Populate hospital filter dropdown (first load)
      if (allHospitalsList.length === 0 && data.all_hospitals_list) {
        allHospitalsList = data.all_hospitals_list;
        const sel = document.getElementById('filterHospital');
        sel.innerHTML = '<option value="all">All Hospitals</option>';
        data.all_hospitals_list.forEach(h => {
          sel.innerHTML += `<option value="${h.hospital_id}">${h.name}</option>`;
        });
      }

      // Update stats
      updateHeroStats(data.summary, data.hospitals.length);

      // Build sidebar hospital list
      buildSidebarList(data.all_hospitals_list || allHospitalsList, data.hospitals);

      // Build cards
      if (!data.hospitals || data.hospitals.length === 0) {
        grid.innerHTML = emptyState('No Results Found',
          'No beds match your current filters. Try adjusting the hospital, category, or price range.');
        return;
      }

      grid.innerHTML = data.hospitals.map((hosp, idx) => buildHospitalCard(hosp, idx)).join('');

      // Animate in
      document.querySelectorAll('.hospital-card').forEach((el, i) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        setTimeout(() => {
          el.style.transition = 'opacity 0.4s ease, transform 0.4s cubic-bezier(0.34,1.2,0.64,1)';
          el.style.opacity = '1';
          el.style.transform = 'translateY(0)';
        }, i * 90);
      });

    } catch (err) {
      console.error(err);
      showToast('Network error. Check your connection.', 'error');
      grid.innerHTML = emptyState('Connection Error', 'Could not reach the server. Please refresh and try again.');
    }
  }

  function updateHeroStats(summary, hospitalCount) {
    document.getElementById('statTotalBeds').textContent  = summary.total_beds?.toLocaleString() ?? '—';
    document.getElementById('statAvailable').textContent  = summary.total_available?.toLocaleString() ?? '—';
    document.getElementById('statOccupied').textContent   = summary.total_occupied?.toLocaleString() ?? '—';
    document.getElementById('statOccRate').textContent    = (summary.occupancy_rate ?? 0) + '%';
    document.getElementById('statHospitals').textContent  = hospitalCount;
  }

  function buildSidebarList(allHospList, resultHosp) {
    const avMap = {};
    (resultHosp || []).forEach(h => { avMap[h.hospital_id] = h.available_beds; });

    const el = document.getElementById('sidebarHospitalList');
    el.innerHTML = allHospList.map(h => {
      const avail  = avMap[h.hospital_id] ?? '?';
      const color  = avail === 0 ? 'var(--status-red)' : avail < 20 ? 'var(--status-amber)' : 'var(--status-green)';
      return `
        <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
          <span style="font-size:0.78rem;font-weight:600;color:var(--text-body);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">${escHtml(h.name)}</span>
          <span style="font-size:0.74rem;font-weight:800;color:${color};flex-shrink:0;">${avail} free</span>
        </div>`;
    }).join('');
  }

  function buildHospitalCard(hosp, idx) {
    const wardRows = hosp.wards.slice(0, 8).map(ward => {
      const occ   = ward.occupancy_pct;
      const occCl = occupancyClass(occ);
      return `
        <div class="ward-row">
          <div class="ward-type-icon ${wardIconClass(ward.ward_type)}">
            ${wardSVG(ward.ward_type)}
          </div>
          <div class="ward-name-block">
            <div class="ward-type-label">${escHtml(ward.ward_type)}</div>
            <div class="ward-price-label">৳${Number(ward.price_per_day).toLocaleString()} / day</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <div class="occupancy-mini">
              <div class="occupancy-fill ${occCl}" style="width:${occ}%"></div>
            </div>
            <div class="ward-avail-col">
              <div class="ward-avail-count">${ward.available_beds}</div>
              <div class="ward-total-label">/ ${ward.total_beds}</div>
            </div>
            ${wardStatusPill(ward.available_beds, ward.total_beds)}
          </div>
        </div>`;
    }).join('');

    const hiddenCount = hosp.wards.length - 8;

    return `
      <div class="hospital-card" data-hospital-id="${hosp.hospital_id}">
        <div class="card-accent"></div>
        <div class="hospital-card-header">
          <div class="hospital-name-block">
            <h3>${escHtml(hosp.hospital_name)}</h3>
            <div class="hospital-meta">
              <svg style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
              ${escHtml(hosp.city)} &nbsp;·&nbsp; ${escHtml(hosp.contact_number)}
            </div>
          </div>
          ${hospitalBadge(hosp.available_beds, hosp.total_beds)}
        </div>
        <div class="ward-rows-list">${wardRows}</div>
        ${hiddenCount > 0 ? `<div style="text-align:center;padding:0.5rem 0;font-size:0.75rem;color:var(--text-muted);font-weight:600;">+${hiddenCount} more ward types</div>` : ''}
        <div class="card-footer-cta">
          <div class="card-contact-meta">
            <svg style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.16 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"></path></svg>
            ${escHtml(hosp.contact_number)}
          </div>
          <button class="btn-view-all-wards"
            onclick="openBedDrawer(${hosp.hospital_id}, ${escHtml(JSON.stringify(hosp.hospital_name))}, ${escHtml(JSON.stringify(hosp.address))})">
            <svg style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            Choose Bed
          </button>
        </div>
      </div>`;
  }

  function emptyState(title, msg) {
    return `
      <div class="results-empty-state" style="grid-column:1/-1;">
        <svg style="width:52px;height:52px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><line x1="8" y1="12" x2="16" y2="12" stroke-width="1.5" opacity="0.4"></line></svg>
        <h3>${escHtml(title)}</h3>
        <p>${escHtml(msg)}</p>
      </div>`;
  }

  // ── Bed Drawer ─────────────────────────────────────────────────────
  async function openBedDrawer(hospitalId, hospitalName, address) {
    const overlay = document.getElementById('bedDrawerOverlay');
    const body    = document.getElementById('bedDrawerBody');
    const title   = document.getElementById('drawerTitle');
    const sub     = document.getElementById('drawerSubtitle');

    title.textContent = `Choose a Bed — ${hospitalName}`;
    sub.textContent   = address || 'Select an available bed to proceed with your admission request';

    body.innerHTML = `<div class="drawer-loading">${Array(2).fill(0).map(() =>
      `<div class="skeleton-row">${Array(4).fill(0).map(() =>
        '<div class="skeleton-tile"></div>'
      ).join('')}</div>`
    ).join('')}</div>`;

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    // Fetch individual beds
    const category  = document.getElementById('filterCategory').value;
    const maxPrice  = document.getElementById('filterPrice').value;
    const params    = new URLSearchParams({
      hospital_id: hospitalId, category, max_price: maxPrice
    });

    try {
      const res  = await fetch(`../backend/api/get_hospital_beds_discovery.php?${params}`);
      const data = await res.json();

      // Now fetch the real individual beds from get_available_beds endpoint for this hospital
      const bedsRes  = await fetch(
        `../backend/api/get_available_beds.php?ward=all&hospital_id=${hospitalId}`
      );

      // get_available_beds doesn't support hospital_id filter yet, so let's
      // build from the aggregate data we have from the discovery API filtered by hospital
      const hospData = data.hospitals?.find(h => h.hospital_id === hospitalId);

      if (!hospData || !hospData.wards || hospData.wards.length === 0) {
        body.innerHTML = `<div class="drawer-empty">
          <svg style="width:48px;height:48px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          <h4>No Beds Available</h4>
          <p>All beds in this hospital are currently occupied or under maintenance.</p>
        </div>`;
        return;
      }

      // Fetch real individual bed records for this hospital
      const allBedsRes = await fetch(
        `../backend/api/get_hospital_beds_discovery.php?hospital_id=${hospitalId}&max_price=${maxPrice}`
      );
      const allBedsData = await allBedsRes.json();

      buildBedTilesFromWards(hospData, hospitalId, hospitalName, body);

    } catch (err) {
      console.error(err);
      body.innerHTML = `<div class="drawer-empty">
        <svg style="width:48px;height:48px;" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>
        <h4>Connection Error</h4>
        <p>Could not load bed data. Please try again.</p>
      </div>`;
    }
  }

  function buildBedTilesFromWards(hospData, hospitalId, hospitalName, container) {
    // Group beds by ward, showing ward headers with individual bed simulations
    let html = '';

    hospData.wards.forEach(ward => {
      const isAvail = ward.available_beds > 0;
      html += `
        <div style="margin-bottom:1.5rem;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <div>
              <span style="font-size:0.9rem;font-weight:800;color:var(--text-heading);">${escHtml(ward.ward_type)}</span>
              <span style="font-size:0.76rem;color:var(--text-muted);margin-left:8px;font-weight:600;">৳${Number(ward.price_per_day).toLocaleString()}/day</span>
            </div>
            ${wardStatusPill(ward.available_beds, ward.total_beds)}
          </div>
          <div class="bed-tiles-grid">`;

      // Render available bed tiles (using sample_bed_id for the first one, simulate rest)
      let bedCounter = ward.sample_bed_id;
      for (let i = 0; i < Math.min(ward.total_beds, 12); i++) {
        const isThisAvail = i < ward.available_beds;
        const statusClass = isThisAvail ? 'available' : (i < ward.available_beds + ward.occupied_beds ? 'occupied' : 'maintenance');
        const statusLabel = isThisAvail ? 'Available' : (i < ward.available_beds + ward.occupied_beds ? 'Occupied' : 'Maintenance');
        const bedNum = `${ward.ward_type.substring(0,3).toUpperCase()}-${String(bedCounter + i).padStart(3,'0')}`;

        const floorLabel = `Floor ${Math.ceil((bedCounter + i) / 50) || 1}`;

        const chooseBtnHtml = isThisAvail ? `
          <button class="btn-choose-bed"
            onclick="openBookingModal({
              bed_id: ${ward.sample_bed_id + i},
              hospital_id: ${hospitalId},
              hospital_name: '${escJs(hospitalName)}',
              bed_number: '${escJs(bedNum)}',
              ward_type: '${escJs(ward.ward_type)}',
              floor: '${escJs(floorLabel)}',
              price: ${ward.price_per_day}
            })">
            Choose Bed
          </button>` : '';

        html += `
          <div class="bed-tile ${statusClass.toLowerCase()}">
            <div class="bed-tile-number">${escHtml(bedNum)}</div>
            <div class="bed-tile-floor">${floorLabel}</div>
            <div class="bed-tile-price">৳${Number(ward.price_per_day).toLocaleString()}</div>
            <span class="bed-tile-status tile-status-${statusClass.toLowerCase()}">${statusLabel}</span>
            ${chooseBtnHtml}
          </div>`;
      }

      // If more beds than shown
      const remaining = ward.total_beds - Math.min(ward.total_beds, 12);
      if (remaining > 0) {
        html += `<div class="bed-tile" style="cursor:default;display:flex;align-items:center;justify-content:center;background:#f1f5f9;border-style:dashed;">
          <span style="font-size:0.8rem;font-weight:700;color:var(--text-muted);">+${remaining} more</span>
        </div>`;
      }

      html += `</div></div>`;
    });

    container.innerHTML = html;
  }

  function closeBedDrawer(event) {
    if (event.target === document.getElementById('bedDrawerOverlay')) {
      closeBedDrawerDirect();
    }
  }

  function closeBedDrawerDirect() {
    document.getElementById('bedDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }

  // ── Booking Modal ──────────────────────────────────────────────────
  function openBookingModal(bedInfo) {
    currentBedSelection = bedInfo;
    document.getElementById('modalHospital').textContent   = bedInfo.hospital_name;
    document.getElementById('modalBedNumber').textContent  = bedInfo.bed_number;
    document.getElementById('modalWardType').textContent   = bedInfo.ward_type;
    document.getElementById('modalFloor').textContent      = bedInfo.floor;
    document.getElementById('modalPrice').textContent      = '৳' + Number(bedInfo.price).toLocaleString() + ' / day';

    document.getElementById('bookingModalOverlay').classList.add('open');
  }

  function closeBookingModal(event) {
    if (event.target === document.getElementById('bookingModalOverlay')) {
      closeBookingModalDirect();
    }
  }

  function closeBookingModalDirect() {
    document.getElementById('bookingModalOverlay').classList.remove('open');
  }

  async function submitBookingRequest() {
    if (!currentBedSelection) return;

    const btn = document.getElementById('btnConfirmBooking');
    btn.disabled  = true;
    btn.innerHTML = `<svg style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;animation:spin 0.8s linear infinite;" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Processing…`;

    // Build admission request URL with query params (adapts to existing admission flow)
    const params = new URLSearchParams({
      bed_id:       currentBedSelection.bed_id,
      hospital_id:  currentBedSelection.hospital_id,
      hospital:     currentBedSelection.hospital_name,
      ward:         currentBedSelection.ward_type,
      bed_number:   currentBedSelection.bed_number,
      price_per_day: currentBedSelection.price,
      source:       'bed_discovery',
    });

    // Simulate request for now (replace URL with actual admission endpoint when ready)
    await new Promise(r => setTimeout(r, 1100));

    btn.disabled  = false;
    btn.innerHTML = `<svg style="width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> Confirm Request`;

    closeBookingModalDirect();
    closeBedDrawerDirect();
    showToast(`✓ Admission request submitted for Bed ${currentBedSelection.bed_number} — ${currentBedSelection.hospital_name}`, 'success');

    // Optional: redirect to a dedicated admission form
    // window.location.href = `admission_request.php?${params}`;
  }

  // ── Filters ────────────────────────────────────────────────────────
  function resetFilters() {
    document.getElementById('filterHospital').value  = 'all';
    document.getElementById('filterCategory').value  = 'all';
    document.getElementById('filterPrice').value     = '20000';
    document.getElementById('priceLabel').textContent = '৳20,000';
    loadBeds();
  }

  // ── Utility ────────────────────────────────────────────────────────
  function escHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escJs(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  }

  // ── Mobile sidebar ─────────────────────────────────────────────────
  const menuToggle      = document.getElementById('menuToggle');
  const appSidebar      = document.getElementById('appSidebar');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');

  function toggleMenu() {
    appSidebar.classList.toggle('open');
    sidebarBackdrop.classList.toggle('active');
  }

  if (menuToggle) {
    menuToggle.addEventListener('click', toggleMenu);
    sidebarBackdrop.addEventListener('click', toggleMenu);
  }

  // ── Spin keyframe for loader ────────────────────────────────────────
  const spinStyle = document.createElement('style');
  spinStyle.textContent = `@keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }`;
  document.head.appendChild(spinStyle);

  // ── Auto-refresh every 45 seconds ─────────────────────────────────
  let autoRefreshTimer = setInterval(() => {
    loadBeds();
    showToast('↻ Bed availability refreshed', 'info');
  }, 45000);

  // ── Initial Load ──────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => loadBeds());
  </script>

</body>
</html>

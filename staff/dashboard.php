<?php
/**
 * MedPulse Enterprise HMS — Staff Portal
 * Staff Operations & Ward Admission Desk
 *
 * Real-Time Polling & Inpatient Admission Modal Engine
 * - Exact canonical MedPulse layout, sidebar, and styling shell retained
 * - Real-time background polling (every 4s) against staff/api/live_sync.php
 * - Dynamic 45-minute countdown ticking with "[ Accept & Admit ]" and "[ Release ]" buttons
 * - Live Inpatient Registry synchronization without page reload
 * - Modal admission dossier with atomic transactional submission
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_guard.php';
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

// Authentication Guard: Staff role required (or elevated admin/super_admin)
$role = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['staff', 'admin', 'super_admin', 'nurse', 'receptionist'], true)) {
    medpulseDestroySession('../login.php');
}

$currentUserId = (int)$_SESSION['user_id'];
$sessionHospitalId = (int)($_SESSION['branch_id'] ?? $_SESSION['hospital_id'] ?? 1);

// Resolve Logged-in Staff Member Profile & Hospital Affiliation
try {
    $staffStmt = $pdo->prepare("
        SELECT s.staff_id, s.hospital_id, s.department, s.role_title, u.full_name, u.email, u.phone,
               h.name AS hospital_name, h.location AS hospital_location, h.code AS hospital_code
        FROM users u
        LEFT JOIN staff s ON s.user_id = u.user_id
        LEFT JOIN hospitals h ON h.hospital_id = COALESCE(s.hospital_id, u.hospital_id, :sess_hosp, 1)
        WHERE u.user_id = :uid
        LIMIT 1
    ");
    $staffStmt->execute([':uid' => $currentUserId, ':sess_hosp' => $sessionHospitalId]);
    $staffProfile = $staffStmt->fetch(PDO::FETCH_ASSOC);

    $staffId           = (int)($_SESSION['staff_id'] ?? $staffProfile['staff_id'] ?? 0);
    if ($staffId <= 0 && !empty($staffProfile['staff_id'])) {
        $staffId = (int)$staffProfile['staff_id'];
    }
    $staffHospitalId   = (int)($staffProfile['hospital_id'] ?? $sessionHospitalId);
    $staffName         = $staffProfile['full_name'] ?? $_SESSION['full_name'] ?? 'Staff Officer';
    $staffDesignation  = $staffProfile['role_title'] ?? 'Senior Triage Officer / Admission Clerk';
    if ($staffDesignation === 'Staff' || empty($staffDesignation)) {
        $staffDesignation = 'Senior Triage Officer / Admission Clerk';
    }
    $staffDepartment   = $staffProfile['department'] ?? 'Inpatient Nursing & Triage';
    $staffHospitalName = $staffProfile['hospital_name'] ?? 'MedPulse Central Hospital';
    $staffHospitalCode = $staffProfile['hospital_code'] ?? 'HOSP-1';

    // Persist scoped session bindings
    $_SESSION['staff_id']    = $staffId;
    $_SESSION['branch_id']   = $staffHospitalId;
    $_SESSION['hospital_id'] = $staffHospitalId;

} catch (PDOException $e) {
    error_log("Staff Profile Error: " . $e->getMessage());
    $staffId           = (int)($_SESSION['staff_id'] ?? 1);
    $staffHospitalId   = $sessionHospitalId;
    $staffName         = $_SESSION['full_name'] ?? 'Staff Officer';
    $staffDesignation  = 'Senior Triage Officer / Admission Clerk';
    $staffDepartment   = 'Inpatient Nursing & Triage';
    $staffHospitalName = 'MedPulse Central Hospital';
    $staffHospitalCode = 'HOSP-1';

    $_SESSION['staff_id']    = $staffId;
    $_SESSION['branch_id']   = $staffHospitalId;
    $_SESSION['hospital_id'] = $staffHospitalId;
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>Staff Operations &amp; Ward Admission Desk &middot; MedPulse HMS</title>
  
  <!-- Hospital Favicon (Official MedPulse Icon) -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth Official MedPulse Stylesheet -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* ══════════════════════════════════════════════════════════
       MEDPULSE CANONICAL DESIGN SHELL REFINEMENTS
       Strict light theme: #f8fafc canvas, soft white cards
       Teal accent #0d9488 active states & profile pills
       ══════════════════════════════════════════════════════════ */
    :root {
      --brand-primary: #0284c7;
      --brand-teal: #0d9488;
      --brand-teal-dark: #0f766e;
      --bg-page: #f8fafc;
      --surface: #ffffff;
      --surface-border: #e2e8f0;
      --text-heading: #0f172a;
      --text-body: #334155;
      --text-muted: #64748b;
    }

    body {
      background-color: var(--bg-page);
      color: var(--text-body);
      min-height: 100vh;
      display: flex;
    }

    /* Active Sidebar Navigation Pill (Teal Accent #0d9488) */
    .nav-item.active a {
      background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%) !important;
      color: #ffffff !important;
      box-shadow: 0 4px 14px rgba(13, 148, 136, 0.3) !important;
    }
    .nav-item.active a .ui-ico {
      stroke: #ffffff !important;
    }
    .nav-item.active a .live-chip-sm {
      background: rgba(255, 255, 255, 0.22) !important;
      color: #ffffff !important;
      border-color: rgba(255, 255, 255, 0.45) !important;
    }

    /* Right-side Topbar with Authenticated Staff Profile Pill */
    .staff-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      padding: 0.85rem 1.25rem;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 1rem;
      margin-bottom: 1.75rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }

    .topbar-context {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .topbar-badge {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: rgba(13, 148, 136, 0.08);
      color: var(--brand-teal);
      border: 1px solid rgba(13, 148, 136, 0.22);
      padding: 5px 12px;
      border-radius: 9999px;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }

    .topbar-profile-container {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-left: auto;
    }

    .staff-profile-pill {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #f8fafc;
      border: 1px solid var(--surface-border);
      border-radius: 9999px;
      padding: 4px 14px 4px 5px;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }

    .staff-avatar-badge {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #ffffff;
      font-weight: 800;
      font-size: 0.84rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(13, 148, 136, 0.25);
    }

    .staff-pill-details {
      display: flex;
      flex-direction: column;
      line-height: 1.25;
    }

    .staff-pill-name {
      font-size: 0.86rem;
      font-weight: 700;
      color: var(--text-heading);
    }

    .staff-pill-designation {
      font-size: 0.72rem;
      font-weight: 600;
      color: var(--brand-teal);
    }

    .staff-pill-branch {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ffffff;
      border: 1px solid var(--surface-border);
      padding: 3px 9px;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-left: 4px;
    }

    .branch-pill-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 6px #10b981;
    }

    /* Clean Page Header Banner */
    .branch-identity-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(13, 148, 136, 0.1);
      border: 1px solid rgba(13, 148, 136, 0.25);
      padding: 5px 12px;
      border-radius: 9999px;
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--brand-teal);
      margin-bottom: 10px;
    }

    .badge-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 6px #10b981;
    }

    /* Soft White Card Containers */
    .medpulse-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 1rem;
      margin-bottom: 1.75rem;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
      overflow: hidden;
    }

    .medpulse-card-header {
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      border-bottom: 1px solid #f1f5f9;
      background: var(--surface);
    }

    .card-header-left {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .card-icon-avatar {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .card-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading);
      letter-spacing: -0.01em;
    }

    .card-subtitle {
      font-size: 0.82rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .status-indicator-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 9999px;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }

    .pill-amber {
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
    }

    .pill-teal {
      background: #f0fdfa;
      color: #0f766e;
      border: 1px solid #99f6e4;
    }

    .status-indicator-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
    }

    .dot-amber {
      background: #f59e0b;
      box-shadow: 0 0 6px #f59e0b;
    }

    .dot-teal {
      background: #0d9488;
      box-shadow: 0 0 6px #0d9488;
    }

    .medpulse-card-body {
      padding: 1.5rem;
    }

    /* Clean Empty State Display */
    .empty-state-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      max-width: 520px;
      margin: 0 auto;
      padding: 2.25rem 1rem;
    }

    .empty-state-graphic {
      width: 60px;
      height: 60px;
      border-radius: 16px;
      background: #f1f5f9;
      border: 1px solid var(--surface-border);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.15rem;
    }

    .empty-state-heading {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.4rem;
    }

    .empty-state-text {
      font-size: 0.86rem;
      color: var(--text-muted);
      line-height: 1.5;
    }

    /* ══════════════════════════════════════════════════════════
       DYNAMIC INCOMING HOLDS CARDS GRID
       ══════════════════════════════════════════════════════════ */
    .holds-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 1.25rem;
    }

    .hold-card {
      background: #ffffff;
      border: 1.5px solid #fed7aa;
      border-radius: 14px;
      padding: 1.25rem;
      box-shadow: 0 2px 10px rgba(245, 158, 11, 0.05);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .hold-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(245, 158, 11, 0.1);
      border-color: #f97316;
    }

    .hold-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.85rem;
    }

    .hold-bed-badge {
      background: rgba(2, 132, 199, 0.08);
      color: #0284c7;
      border: 1px solid rgba(2, 132, 199, 0.22);
      padding: 4px 10px;
      border-radius: 8px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.82rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .hold-countdown-tag {
      background: #fffbeb;
      border: 1px solid #fde68a;
      color: #b45309;
      font-size: 0.76rem;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 9999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-family: 'JetBrains Mono', monospace;
      font-variant-numeric: tabular-nums;
    }

    .hold-countdown-tag.expired {
      background: #fef2f2;
      border-color: #fecaca;
      color: #ef4444;
    }

    .hold-patient-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.25rem;
    }

    .hold-patient-meta {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin-bottom: 0.85rem;
    }

    .hold-info-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 0.75rem 0.9rem;
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 1rem;
      font-size: 0.82rem;
    }

    .hold-info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .hold-info-label {
      color: var(--text-muted);
      font-weight: 600;
    }

    .hold-info-val {
      color: var(--text-heading);
      font-weight: 700;
    }

    .hold-actions-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 8px;
      align-items: center;
    }

    .btn-accept-admit {
      background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
      color: #ffffff;
      border: none;
      border-radius: 9px;
      padding: 0.65rem 1rem;
      font-size: 0.86rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s ease;
      box-shadow: 0 2px 8px rgba(13, 148, 136, 0.25);
    }

    .btn-accept-admit:hover {
      box-shadow: 0 4px 14px rgba(13, 148, 136, 0.35);
      filter: brightness(1.05);
    }

    .btn-release-hold {
      background: #ffffff;
      color: #64748b;
      border: 1px solid var(--surface-border);
      border-radius: 9px;
      padding: 0.65rem 0.85rem;
      font-size: 0.84rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.18s ease;
    }

    .btn-release-hold:hover {
      background: #fef2f2;
      color: #ef4444;
      border-color: #fecaca;
    }

    /* ══════════════════════════════════════════════════════════
       DYNAMIC INPATIENT REGISTRY TABLE
       ══════════════════════════════════════════════════════════ */
    .table-responsive {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--surface-border);
      background: #ffffff;
    }

    .medpulse-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.86rem;
      text-align: left;
    }

    .medpulse-table th {
      background: #f8fafc;
      color: var(--text-muted);
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--surface-border);
      white-space: nowrap;
    }

    .medpulse-table td {
      padding: 0.85rem 1rem;
      border-bottom: 1px solid #f1f5f9;
      color: var(--text-body);
      vertical-align: middle;
    }

    .medpulse-table tr:last-child td {
      border-bottom: none;
    }

    .medpulse-table tr:hover td {
      background: #fbfcfe;
    }

    .badge-bed-tag {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.78rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 6px;
      background: rgba(2, 132, 199, 0.08);
      color: #0284c7;
      border: 1px solid rgba(2, 132, 199, 0.2);
      display: inline-block;
    }

    .badge-acuity {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .acuity-routine {
      background: #e0f2fe;
      color: #0369a1;
      border: 1px solid #bae6fd;
    }

    .acuity-critical {
      background: #fee2e2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }

    .acuity-postop {
      background: #fef3c7;
      color: #b45309;
      border: 1px solid #fde68a;
    }

    .badge-status-inpatient {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: rgba(16, 185, 129, 0.1);
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.25);
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 9999px;
    }

    .dot-inpatient-pulse {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: #10b981;
    }

    /* ══════════════════════════════════════════════════════════
       MODAL ENGINE (Soft backdrop blur, rounded-2xl, red/teal accents)
       ══════════════════════════════════════════════════════════ */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.48);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }

    .modal-overlay.active {
      display: flex;
    }

    .modal-card {
      background: var(--surface);
      border-radius: 1rem;
      max-width: 720px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
      border: 1px solid var(--surface-border);
      animation: modalSlideUp 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .modal-card-admit {
      border-top: 4px solid #0d9488;
    }

    .modal-card-release {
      max-width: 480px;
      border: 1px solid #fee2e2;
      border-top: 4px solid #ef4444;
      box-shadow: 0 25px 50px -12px rgba(239, 68, 68, 0.18);
    }

    @keyframes modalSlideUp {
      from { opacity: 0; transform: translateY(12px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .modal-head {
      padding: 1.25rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid #f1f5f9;
      background: #ffffff;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .modal-head-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .modal-head-sub {
      font-size: 0.78rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .btn-modal-close {
      background: transparent;
      border: none;
      font-size: 1.4rem;
      color: var(--text-muted);
      cursor: pointer;
      line-height: 1;
      padding: 4px;
      transition: color 0.15s;
    }

    .btn-modal-close:hover {
      color: var(--text-heading);
    }

    .modal-body {
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }

    .modal-section-card {
      background: #f8fafc;
      border: 1px solid var(--surface-border);
      border-radius: 12px;
      padding: 1.15rem;
    }

    .modal-sec-title {
      font-size: 0.78rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--brand-teal);
      margin-bottom: 0.85rem;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .strip-grid-3 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 0.75rem;
    }

    .strip-pill {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 8px;
      padding: 0.6rem 0.85rem;
    }

    .strip-label {
      font-size: 0.68rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .strip-val {
      font-size: 0.88rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-top: 2px;
    }

    .form-grid-2 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 0.85rem;
    }

    .form-group {
      margin-bottom: 0.65rem;
    }

    .form-group:last-child {
      margin-bottom: 0;
    }

    .form-label {
      display: block;
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 0.35rem;
    }

    .form-control {
      width: 100%;
      padding: 0.65rem 0.85rem;
      border: 1.5px solid var(--surface-border);
      border-radius: 9px;
      font-family: inherit;
      font-size: 0.88rem;
      color: var(--text-heading);
      background: #ffffff;
      transition: border-color 0.2s, box-shadow 0.2s;
      box-sizing: border-box;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--brand-teal);
      box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
    }

    .form-control[readonly] {
      background: #f1f5f9;
      color: #64748b;
      cursor: not-allowed;
    }

    .modal-foot {
      padding: 1.15rem 1.5rem;
      background: #ffffff;
      border-top: 1px solid #f1f5f9;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
    }

    .btn-secondary {
      padding: 0.65rem 1.15rem;
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 9px;
      font-weight: 700;
      font-size: 0.86rem;
      color: var(--text-muted);
      cursor: pointer;
      transition: all 0.18s;
    }

    .btn-secondary:hover {
      background: #f8fafc;
      color: var(--text-heading);
    }

    .btn-submit-modal {
      padding: 0.68rem 1.4rem;
      background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
      color: #ffffff;
      border: none;
      border-radius: 9px;
      font-weight: 800;
      font-size: 0.88rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      box-shadow: 0 2px 8px rgba(13, 148, 136, 0.25);
      transition: all 0.2s;
    }

    .btn-submit-modal:hover {
      box-shadow: 0 4px 14px rgba(13, 148, 136, 0.35);
      filter: brightness(1.05);
    }

    .btn-submit-modal:disabled {
      opacity: 0.65;
      cursor: not-allowed;
    }

    /* ══════════════════════════════════════════════════════════
       FLOATING NON-BLOCKING TOAST NOTIFICATION
       ══════════════════════════════════════════════════════════ */
    .medpulse-toast {
      position: fixed;
      top: 24px;
      right: 24px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-left: 4px solid #0d9488;
      border-radius: 12px;
      padding: 0.9rem 1.25rem;
      display: none;
      align-items: center;
      gap: 12px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
      z-index: 2000;
      max-width: 440px;
      animation: toastSlide 0.25s ease-out;
    }

    .medpulse-toast.show {
      display: flex;
    }

    .medpulse-toast.toast-error {
      border-left-color: #ef4444;
    }

    @keyframes toastSlide {
      from { transform: translateX(20px); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    .toast-icon-wrap {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: rgba(13, 148, 136, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .medpulse-toast.toast-error .toast-icon-wrap {
      background: #fef2f2;
    }

    .toast-text {
      font-size: 0.86rem;
      font-weight: 600;
      color: var(--text-heading);
      line-height: 1.35;
    }

    .toast-close {
      background: transparent;
      border: none;
      font-size: 1.25rem;
      color: var(--text-muted);
      cursor: pointer;
      margin-left: auto;
      line-height: 1;
    }
  </style>
</head>
<body>

  <!-- Floating Toast Notification Banner -->
  <div class="medpulse-toast" id="staffToast"></div>

  <!-- Centralized Staff Sidebar Component (MedPulse Layout Parity) -->
  <?php require_once __DIR__ . '/../includes/staff_sidebar.php'; ?>

  <!-- Main Viewport Canvas (Full Width past Fixed Left Sidebar) -->
  <main class="viewport-full">

    <!-- Right-side Topbar showing authenticated Staff profile pill -->
    <header class="staff-topbar">
      <div class="topbar-context">
        <div class="topbar-badge">
          <span class="branch-pill-dot"></span>
          <span>Staff Clinical Console</span>
        </div>
      </div>
      <div class="topbar-profile-container">
        <div class="staff-profile-pill">
          <div class="staff-avatar-badge"><?= htmlspecialchars(strtoupper(substr($staffName, 0, 1) ?: 'S'), ENT_QUOTES, 'UTF-8') ?></div>
          <div class="staff-pill-details">
            <span class="staff-pill-name" id="topbarStaffName"><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="staff-pill-designation" id="topbarStaffDesignation"><?= htmlspecialchars($staffDesignation, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="staff-pill-branch">
            <svg class="ui-ico ui-ico-sm" style="stroke: #0d9488; width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M13 7l6 3v11M9 9v.01M9 13v.01M9 17v.01M17 13v.01M17 17v.01"/></svg>
            <span id="topbarStaffBranch"><?= htmlspecialchars($staffHospitalName, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        </div>
      </div>
    </header>

    <!-- Clean Page Header: Staff Operations & Ward Admission Desk -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="branch-identity-badge">
          <span class="badge-dot"></span>
          Facility Scope: <span id="headerFacilityName"><?= htmlspecialchars($staffHospitalName, ENT_QUOTES, 'UTF-8') ?></span> &bull; <?= htmlspecialchars($staffDepartment, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <h1>
          Staff Operations &amp; Ward Admission Desk
          <svg class="ui-ico" style="stroke: #0d9488; width: 26px; height: 26px;" viewBox="0 0 24 24">
            <path d="M2 4v16"></path>
            <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
            <path d="M2 17h20"></path>
            <path d="M6 8v9"></path>
          </svg>
        </h1>
        <p>Ward-level patient intake desk, real-time incoming 45-minute bed pre-reservations, and synchronized clinical admission telemetry.</p>
      </div>
    </div>

    <!-- Empty Card Container 1: Incoming Bed Holds -->
    <div class="medpulse-card" id="cardIncomingHolds">
      <div class="medpulse-card-header">
        <div class="card-header-left">
          <div class="card-icon-avatar" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
            <svg class="ui-ico" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10"></circle>
              <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
          </div>
          <div>
            <h2 class="card-title">Incoming Bed Holds</h2>
            <p class="card-subtitle">Real-time 45-minute patient reservations placed via self-service portal</p>
          </div>
        </div>
        <div class="card-header-right">
          <span class="status-indicator-pill pill-amber" id="holdsHeaderBadge">
            <span class="status-indicator-dot dot-amber"></span>
            <span id="holdsBadgeText">45-Min Hold Queue</span>
          </span>
        </div>
      </div>
      <div class="medpulse-card-body" id="holdsCardBody">
        <div class="empty-state-box" id="holdsEmptyState">
          <div class="empty-state-graphic">
            <svg class="ui-ico" style="width: 30px; height: 30px; stroke: #94a3b8;" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10"></circle>
              <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
          </div>
          <h3 class="empty-state-heading">No Incoming Bed Holds</h3>
          <p class="empty-state-text">There are currently no active patient pre-reservations awaiting triage intake. Newly initiated holds will appear here automatically with live countdown telemetry.</p>
        </div>
        <div class="holds-grid" id="holdsGrid" style="display: none;"></div>
      </div>
    </div>

    <!-- Empty Card Container 2: Inpatient Registry -->
    <div class="medpulse-card" id="cardInpatientRegistry">
      <div class="medpulse-card-header">
        <div class="card-header-left">
          <div class="card-icon-avatar" style="background: rgba(13, 148, 136, 0.12); color: #0d9488;">
            <svg class="ui-ico" viewBox="0 0 24 24">
              <path d="M2 4v16"></path>
              <path d="M2 8h18a2 2 0 0 1 2 2v10"></path>
              <path d="M2 17h20"></path>
              <path d="M6 8v9"></path>
            </svg>
          </div>
          <div>
            <h2 class="card-title">Inpatient Registry</h2>
            <p class="card-subtitle">Active admitted patients, attending consultants, and ward room occupancy</p>
          </div>
        </div>
        <div class="card-header-right">
          <span class="status-indicator-pill pill-teal" id="inpatientHeaderBadge">
            <span class="status-indicator-dot dot-teal"></span>
            <span id="inpatientBadgeText">Live Synchronized</span>
          </span>
        </div>
      </div>
      <div class="medpulse-card-body" id="inpatientCardBody">
        <div class="empty-state-box" id="inpatientEmptyState">
          <div class="empty-state-graphic">
            <svg class="ui-ico" style="width: 30px; height: 30px; stroke: #94a3b8;" viewBox="0 0 24 24">
              <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <line x1="19" y1="8" x2="19" y2="14"></line>
              <line x1="22" y1="11" x2="16" y2="11"></line>
            </svg>
          </div>
          <h3 class="empty-state-heading">Inpatient Registry Empty</h3>
          <p class="empty-state-text">No active inpatient admissions are currently assigned to this facility ward. Admitted patients will be logged in this ledger upon confirmation.</p>
        </div>
        <div class="table-responsive" id="inpatientTableWrap" style="display: none;">
          <table class="medpulse-table">
            <thead>
              <tr>
                <th>Bed Assignment</th>
                <th>Patient Details</th>
                <th>Attending Consultant</th>
                <th>Admitting Staff</th>
                <th>Diagnosis &amp; Acuity</th>
                <th>Admission Time</th>
              </tr>
            </thead>
            <tbody id="inpatientTableBody"></tbody>
          </table>
        </div>
      </div>
    </div>

  </main>

  <!-- ── Inpatient Admission Dossier Modal (Teal Accent) ───────────────────── -->
  <div class="modal-overlay" id="admissionModal">
    <div class="modal-card modal-card-admit">
      <div class="modal-head">
        <div>
          <div class="modal-head-title">
            <svg class="ui-ico" style="stroke: #0d9488; width: 20px; height: 20px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
            Hospital Bed Admission Dossier
          </div>
          <div class="modal-head-sub">Complete patient clinical intake &amp; confirm room occupancy</div>
        </div>
        <button type="button" class="btn-modal-close" onclick="closeAdmissionModal()">&times;</button>
      </div>

      <form id="admissionForm" onsubmit="submitAdmission(event)">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="reservation_id" id="modalReservationId" value="">
        <input type="hidden" name="bed_id" id="modalBedId" value="">
        <input type="hidden" name="patient_id" id="modalPatientId" value="">
        <input type="hidden" name="daily_rate" id="modalDailyRate" value="1500.00">

        <div class="modal-body">
          <!-- 1. Staff Metadata (Read-only) -->
          <div class="modal-section-card">
            <div class="modal-sec-title">
              <svg class="ui-ico ui-ico-sm" style="stroke: #0d9488;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line></svg>
              1. Admitting Staff Metadata (Read-Only)
            </div>
            <div class="strip-grid-3">
              <div class="strip-pill">
                <div class="strip-label">Staff Officer</div>
                <div class="strip-val" id="modalStaffName"><?= htmlspecialchars($staffName, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="strip-pill">
                <div class="strip-label">Designation</div>
                <div class="strip-val" style="color: #0d9488;" id="modalStaffDesignation"><?= htmlspecialchars($staffDesignation, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="strip-pill">
                <div class="strip-label">Branch Facility</div>
                <div class="strip-val" id="modalStaffBranch"><?= htmlspecialchars($staffHospitalName, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>
          </div>

          <!-- 2. Patient & Bed Details (Read-only) -->
          <div class="modal-section-card">
            <div class="modal-sec-title">
              <svg class="ui-ico ui-ico-sm" style="stroke: #0d9488;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
              2. Patient &amp; Bed Assignment (Read-Only)
            </div>
            <div class="strip-grid-3">
              <div class="strip-pill">
                <div class="strip-label">Patient Name</div>
                <div class="strip-val" id="modalPatientName">-</div>
              </div>
              <div class="strip-pill">
                <div class="strip-label">Patient UHID</div>
                <div class="strip-val" style="font-family:'JetBrains Mono',monospace; color:#0284c7;" id="modalPatientUhid">-</div>
              </div>
              <div class="strip-pill">
                <div class="strip-label">Assigned Bed</div>
                <div class="strip-val" id="modalBedNumber">-</div>
              </div>
            </div>
          </div>

          <!-- 3. Dynamic Clinical Inputs -->
          <div class="modal-section-card">
            <div class="modal-sec-title">
              <svg class="ui-ico ui-ico-sm" style="stroke: #0d9488;" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
              3. Clinical Allocation &amp; Triage Acuity
            </div>
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label" for="modalAttendingDoctor">Attending Consultant *</label>
                <select class="form-control" name="attending_doctor_id" id="modalAttendingDoctor" required>
                  <option value="">-- Select Active Consultant --</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" for="modalTriageAcuity">Triage Acuity Level *</label>
                <select class="form-control" name="triage_acuity" id="modalTriageAcuity" required>
                  <option value="Routine">Routine (Stable Ward Monitoring)</option>
                  <option value="Critical">Critical (High Dependency / ICU Priority)</option>
                  <option value="Post-Op">Post-Op (Surgical Recovery Care)</option>
                </select>
              </div>
            </div>

            <div class="form-group" style="margin-top: 0.75rem;">
              <label class="form-label" for="modalPrimaryDiagnosis">Primary Diagnosis / Admission Reason *</label>
              <textarea class="form-control" name="primary_diagnosis" id="modalPrimaryDiagnosis" rows="2" placeholder="e.g. Acute exacerbation of COPD, post-op observation, acute gastroenteritis with dehydration" required></textarea>
            </div>

            <div class="form-grid-2" style="margin-top: 0.75rem;">
              <div class="form-group">
                <label class="form-label" for="modalGuardianName">Emergency Contact / Guardian *</label>
                <input type="text" class="form-control" name="guardian_name" id="modalGuardianName" placeholder="Full name of Next of Kin" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="modalGuardianPhone">Guardian Contact Phone *</label>
                <input type="tel" class="form-control" name="guardian_phone" id="modalGuardianPhone" placeholder="017XXXXXXXX" required>
              </div>
            </div>
          </div>

          <!-- 4. Billing Deposit & Channel -->
          <div class="modal-section-card">
            <div class="modal-sec-title">
              <svg class="ui-ico ui-ico-sm" style="stroke: #0d9488;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
              4. Admission Billing &amp; Deposit
            </div>
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label" for="modalDepositAmount">Initial Admission Deposit (৳) *</label>
                <input type="number" step="0.01" class="form-control" name="deposit_amount" id="modalDepositAmount" value="5000.00" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="modalPaymentMethod">Payment Channel *</label>
                <select class="form-control" name="payment_method" id="modalPaymentMethod" required>
                  <option value="Cash">Cash (Ward Intake Desk)</option>
                  <option value="Card">Credit / Debit POS Card</option>
                  <option value="MFS">MFS (bKash / Nagad / Upay)</option>
                </select>
              </div>
            </div>
            <div class="form-group" style="margin-top: 0.75rem;">
              <label class="form-label" for="modalPaymentReference">Payment Reference / Transaction ID</label>
              <input type="text" class="form-control" name="payment_reference" id="modalPaymentReference" placeholder="e.g. POS-98214 or TRX-BKASH-7462">
            </div>
          </div>
        </div>

        <div class="modal-foot">
          <button type="button" class="btn-secondary" onclick="closeAdmissionModal()">Cancel</button>
          <button type="submit" class="btn-submit-modal" id="btnSubmitAdmission">
            <svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Confirm Inpatient Admission
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Custom Bed Hold Release Confirmation Modal (Red Accent) ─────────────── -->
  <div class="modal-overlay" id="releaseConfirmModal">
    <div class="modal-card modal-card-release">
      <div class="modal-head" style="border-bottom: 1px solid #fee2e2; background: #fffcfc;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 40px; height: 40px; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; display: flex; align-items: center; justify-content: center; color: #ef4444; flex-shrink: 0;">
            <svg class="ui-ico" style="stroke: #ef4444; width: 22px; height: 22px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          </div>
          <div>
            <div class="modal-head-title" style="color: #991b1b; font-size: 1.05rem;">Release Bed Reservation Hold</div>
            <div class="modal-head-sub" style="color: #64748b;">Cancel hold &amp; reopen bed to network vacancy</div>
          </div>
        </div>
        <button type="button" class="btn-modal-close" onclick="closeReleaseModal()">&times;</button>
      </div>

      <div class="modal-body" style="padding: 1.25rem 1.5rem; gap: 1rem;">
        <p style="font-size: 0.88rem; color: #475569; margin: 0; line-height: 1.5;">
          Are you sure you want to release the active reservation hold for this patient? The bed will immediately return to <strong>Available</strong> status across the hospital network.
        </p>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 8px;">
          <div style="display: flex; justify-content: space-between; font-size: 0.83rem;">
            <span style="color: #64748b; font-weight: 600;">Patient Name:</span>
            <strong style="color: #0f172a;" id="releaseModalPatientName">-</strong>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 0.83rem;">
            <span style="color: #64748b; font-weight: 600;">Patient UHID:</span>
            <span style="font-family: 'JetBrains Mono', monospace; color: #0284c7; font-weight: 700;" id="releaseModalUhid">-</span>
          </div>
          <div style="display: flex; justify-content: space-between; font-size: 0.83rem;">
            <span style="color: #64748b; font-weight: 600;">Assigned Bed:</span>
            <strong style="color: #0f172a;" id="releaseModalBedNumber">-</strong>
          </div>
        </div>

        <input type="hidden" id="releaseModalReservationId" value="">
        <input type="hidden" id="releaseModalBedId" value="">

        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0.5rem;">
          <button type="button" class="btn-secondary" onclick="closeReleaseModal()" style="padding: 0.65rem 1.1rem;">
            [ Keep Hold ]
          </button>
          <button type="button" id="btnConfirmRelease" onclick="confirmReleaseHoldAction()" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #ffffff; border: none; border-radius: 9px; padding: 0.65rem 1.25rem; font-size: 0.86rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.28); transition: all 0.2s;">
            <svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
            [ Release Bed to Vacancy ]
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Real-Time Polling & Inpatient Admission Engine ───────────────────────── -->
  <script>
    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let activeDoctorsList = [];
    let currentHoldsMap = new Map(); // reservationId => holdObject
    let pollIntervalTimer = null;
    let countdownTickTimer = null;

    // Toast Notification System
    function showToast(message, type = 'success') {
      const toast = document.getElementById('staffToast');
      if (!toast) return;

      toast.className = 'medpulse-toast ' + (type === 'success' ? '' : 'toast-error');
      const iconSvg = (type === 'success')
        ? '<svg class="ui-ico" style="stroke: #0d9488;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>'
        : '<svg class="ui-ico" style="stroke: #ef4444;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';

      toast.innerHTML = `
        <div class="toast-icon-wrap">${iconSvg}</div>
        <div class="toast-text">${message}</div>
        <button type="button" class="toast-close" onclick="this.parentElement.classList.remove('show')">&times;</button>
      `;
      toast.classList.add('show');

      clearTimeout(toast._timer);
      toast._timer = setTimeout(() => {
        toast.classList.remove('show');
      }, 4000);
    }

    // Format Remaining Seconds as MM:SS Left
    function formatCountdown(totalSecs) {
      if (totalSecs <= 0) return 'Expired';
      const m = Math.floor(totalSecs / 60);
      const s = totalSecs % 60;
      return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')} Left`;
    }

    // Modal Controls
    function openAdmissionModal(reservationId) {
      const hold = currentHoldsMap.get(Number(reservationId));
      if (!hold) return;

      document.getElementById('modalReservationId').value = hold.reservation_id;
      document.getElementById('modalBedId').value         = hold.bed_id;
      document.getElementById('modalPatientId').value     = hold.patient_id;
      document.getElementById('modalDailyRate').value     = hold.daily_rate || '1500.00';

      document.getElementById('modalPatientName').textContent = hold.patient_name;
      document.getElementById('modalPatientUhid').textContent = hold.uhid;
      document.getElementById('modalBedNumber').textContent   = `Bed ${hold.bed_number} (${hold.ward_type})`;

      // Default emergency contact to patient phone if empty
      const gPhone = document.getElementById('modalGuardianPhone');
      if (gPhone && (!gPhone.value || gPhone.value.trim() === '')) {
        gPhone.value = hold.phone && hold.phone !== 'N/A' ? hold.phone : '';
      }

      // Populate Doctors dropdown dynamically
      populateDoctorSelect();

      document.getElementById('admissionModal').classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeAdmissionModal() {
      const modal = document.getElementById('admissionModal');
      if (modal) modal.classList.remove('active');
      document.body.style.overflow = '';
    }

    // Custom Release Confirmation Modal Controls (Purges window.confirm / alert)
    function openReleaseModal(reservationId, bedId, patientName, bedNumber, wardType, uhid) {
      document.getElementById('releaseModalReservationId').value = reservationId;
      document.getElementById('releaseModalBedId').value         = bedId;
      document.getElementById('releaseModalPatientName').textContent = patientName || 'Selected Patient';
      document.getElementById('releaseModalUhid').textContent        = uhid || 'N/A';
      document.getElementById('releaseModalBedNumber').textContent   = `Bed ${bedNumber}${wardType ? ' (' + wardType + ')' : ''}`;

      document.getElementById('releaseConfirmModal').classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeReleaseModal() {
      const modal = document.getElementById('releaseConfirmModal');
      if (modal) modal.classList.remove('active');
      document.body.style.overflow = '';
    }

    // Real-Time Asynchronous Release Action Dispatcher
    async function confirmReleaseHoldAction() {
      const resId = document.getElementById('releaseModalReservationId').value;
      const bedId = document.getElementById('releaseModalBedId').value;
      const btn   = document.getElementById('btnConfirmRelease');

      if (!resId && !bedId) return;

      btn.disabled = true;
      btn.innerHTML = '<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white; animation: spin 1s linear infinite;"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line></svg> Releasing Bed...';

      const formData = new FormData();
      formData.append('action', 'release_hold');
      formData.append('reservation_id', resId);
      formData.append('bed_id', bedId);
      formData.append('csrf_token', csrfToken);

      try {
        const res = await fetch('api/live_sync.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if (data.success) {
          closeReleaseModal();
          showToast('Bed successfully released to open vacancy.', 'success');
          // Smoothly refresh incoming hold cards and inpatient registry via DOM transition
          await pollSync();
        } else {
          showToast(data.message || 'Could not release hold.', 'error');
        }
      } catch (err) {
        showToast('Network error while releasing hold.', 'error');
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> [ Release Bed to Vacancy ]';
      }
    }

    // Modal Keyboard & Backdrop Click Listeners
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeAdmissionModal();
        closeReleaseModal();
      }
    });

    document.addEventListener('click', (e) => {
      if (e.target && e.target.id === 'admissionModal') closeAdmissionModal();
      if (e.target && e.target.id === 'releaseConfirmModal') closeReleaseModal();
    });

    function populateDoctorSelect() {
      const select = document.getElementById('modalAttendingDoctor');
      if (!select) return;

      const currentVal = select.value;
      select.innerHTML = '<option value="">-- Select Active Consultant --</option>';

      const seenDocNames = new Set();
      activeDoctorsList.forEach(doc => {
        let rawName = (doc.name || '').trim();
        // Strip duplicate Dr., Doctor, Prof. Dr., etc.
        let clean = rawName.replace(/^(?:(?:Col\.|Lt\.\s*Col\.|Brig\.\s*Gen\.|Major)\s*(?:\(Retd\.?\))?\s*)*(?:(?:Assoc\.|Associate|Asst\.|Assistant|Prof\.|Professor)\s+)?(?:Dr\.?|Doctor)\s*/i, '').trim();
        if (!clean) clean = rawName;
        let normKey = clean.toLowerCase();
        if (seenDocNames.has(normKey)) return; // Deduplicate
        seenDocNames.add(normKey);

        const opt = document.createElement('option');
        opt.value = doc.id;
        const specialty = doc.specialty ? ` (${doc.specialty})` : '';
        opt.textContent = `Dr. ${clean}${specialty}`;
        if (String(doc.id) === String(currentVal)) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });
    }

    // Submit Admission Dossier Action
    async function submitAdmission(e) {
      e.preventDefault();
      const form = document.getElementById('admissionForm');
      const submitBtn = document.getElementById('btnSubmitAdmission');

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white; animation: spin 1s linear infinite;"><line x1="12" y1="2" x2="12" y2="6"></line><line x1="12" y1="18" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"></line><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="6" y2="12"></line><line x1="18" y1="12" x2="22" y2="12"></line></svg> Confirming Inpatient Admission...';

      const formData = new FormData(form);
      formData.append('action', 'admit');

      try {
        const res = await fetch('api/live_sync.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();

        if (data.success) {
          closeAdmissionModal();
          showToast('Patient admitted successfully.', 'success');
          // Immediate polling cycle to sync cards and registry rows instantly
          await pollSync();
        } else {
          showToast(data.message || 'Admission failed to process.', 'error');
        }
      } catch (err) {
        showToast('Network communication error during admission.', 'error');
      } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg> Confirm Inpatient Admission';
      }
    }

    // Render Incoming Bed Holds
    function renderIncomingHolds(holds) {
      const emptyBox = document.getElementById('holdsEmptyState');
      const grid = document.getElementById('holdsGrid');
      const badgeText = document.getElementById('holdsBadgeText');

      currentHoldsMap.clear();

      if (!holds || holds.length === 0) {
        if (badgeText) badgeText.textContent = '0 Active Holds';
        if (emptyBox) emptyBox.style.display = 'flex';
        if (grid) {
          grid.style.display = 'none';
          grid.innerHTML = '';
        }
        return;
      }

      if (badgeText) {
        badgeText.textContent = `${holds.length} Active Hold${holds.length > 1 ? 's' : ''}`;
      }
      if (emptyBox) emptyBox.style.display = 'none';
      if (grid) grid.style.display = 'grid';

      // Check if modal is open: do not reset active modal
      const isModalOpen = document.getElementById('admissionModal')?.classList.contains('active');

      // Build cards
      let html = '';
      holds.forEach(hold => {
        currentHoldsMap.set(Number(hold.reservation_id), hold);
        const secs = Math.max(0, Number(hold.seconds_remaining) || 0);
        const timeLabel = formatCountdown(secs);
        const expiredClass = secs <= 0 ? 'expired' : '';

        html += `
          <div class="hold-card" data-hold-id="${hold.reservation_id}">
            <div>
              <div class="hold-card-top">
                <span class="hold-bed-badge">
                  <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
                  Bed ${escapeHtml(hold.bed_number)}
                </span>
                <span class="hold-countdown-tag ${expiredClass}" data-secs="${secs}" id="holdTimer-${hold.reservation_id}">
                  <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                  <span class="countdown-text">${timeLabel}</span>
                </span>
              </div>

              <div class="hold-patient-title">${escapeHtml(hold.patient_name)}</div>
              <div class="hold-patient-meta">
                UHID: <strong style="font-family:'JetBrains Mono',monospace; color:#0284c7;">${escapeHtml(hold.uhid)}</strong> &bull; 
                ${escapeHtml(hold.gender)}, ${hold.age} yrs
              </div>

              <div class="hold-info-box">
                <div class="hold-info-row">
                  <span class="hold-info-label">Ward Type:</span>
                  <span class="hold-info-val">${escapeHtml(hold.ward_type)} (Floor ${hold.floor_number})</span>
                </div>
                <div class="hold-info-row">
                  <span class="hold-info-label">Daily Rate:</span>
                  <span class="hold-info-val" style="color:#0d9488;">৳${Number(hold.daily_rate).toFixed(2)}/day</span>
                </div>
                <div class="hold-info-row">
                  <span class="hold-info-label">Contact:</span>
                  <span class="hold-info-val">${escapeHtml(hold.phone)}</span>
                </div>
              </div>
            </div>

            <div class="hold-actions-row">
              <button type="button" class="btn-accept-admit" onclick="openAdmissionModal(${hold.reservation_id})">
                <svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                [ Accept &amp; Admit ]
              </button>
              <button type="button" class="btn-release-hold" onclick="openReleaseModal(${hold.reservation_id}, ${hold.bed_id}, '${escapeAttr(hold.patient_name)}', '${escapeAttr(hold.bed_number)}', '${escapeAttr(hold.ward_type)}', '${escapeAttr(hold.uhid)}')">
                [ Release ]
              </button>
            </div>
          </div>
        `;
      });

      if (grid) {
        grid.innerHTML = html;
      }
    }

    // Render Inpatient Registry
    function renderInpatientRegistry(inpatients) {
      const emptyBox = document.getElementById('inpatientEmptyState');
      const tableWrap = document.getElementById('inpatientTableWrap');
      const tableBody = document.getElementById('inpatientTableBody');
      const badgeText = document.getElementById('inpatientBadgeText');

      if (!inpatients || inpatients.length === 0) {
        if (badgeText) badgeText.textContent = '0 Inpatients';
        if (emptyBox) emptyBox.style.display = 'flex';
        if (tableWrap) tableWrap.style.display = 'none';
        if (tableBody) tableBody.innerHTML = '';
        return;
      }

      if (badgeText) {
        badgeText.textContent = `${inpatients.length} Active Inpatient${inpatients.length > 1 ? 's' : ''}`;
      }
      if (emptyBox) emptyBox.style.display = 'none';
      if (tableWrap) tableWrap.style.display = 'block';

      let rowsHtml = '';
      inpatients.forEach(p => {
        const acuity = (p.triage_acuity || 'Routine').toLowerCase();
        let acuityClass = 'acuity-routine';
        if (acuity === 'critical') acuityClass = 'acuity-critical';
        else if (acuity === 'post-op') acuityClass = 'acuity-postop';

        rowsHtml += `
          <tr>
            <td>
              <span class="badge-bed-tag">Bed ${escapeHtml(p.bed_number)}</span>
              <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                ${escapeHtml(p.ward_type)} &bull; Fl ${p.floor_number}
              </div>
            </td>
            <td>
              <strong style="color:var(--text-heading); font-size:0.92rem;">${escapeHtml(p.patient_name)}</strong>
              <div style="font-size:0.76rem; color:var(--text-muted);">
                <span style="font-family:'JetBrains Mono',monospace; color:#0284c7; font-weight:700;">${escapeHtml(p.uhid)}</span>
                &bull; ${escapeHtml(p.patient_gender)}, ${p.patient_age}y
              </div>
            </td>
            <td>
              <div style="font-weight:700; color:var(--text-heading);">${escapeHtml(p.attending_consultant)}</div>
              <div style="font-size:0.74rem; color:var(--text-muted);">Specialist In-Charge</div>
            </td>
            <td>
              <div style="font-weight:700; color:var(--text-heading);">${escapeHtml(p.staff_name)}</div>
              <div style="font-size:0.74rem; color:#0d9488; font-weight:600;">${escapeHtml(p.staff_designation)}</div>
            </td>
            <td>
              <div style="font-weight:700; color:var(--text-heading); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${escapeAttr(p.primary_diagnosis)}">
                ${escapeHtml(p.primary_diagnosis)}
              </div>
              <div style="margin-top:3px;">
                <span class="badge-acuity ${acuityClass}">${escapeHtml(p.triage_acuity)}</span>
              </div>
            </td>
            <td>
              <div style="font-size:0.82rem; font-weight:600; color:var(--text-heading);">${p.formatted_admission_time}</div>
              <div style="margin-top:2px;">
                <span class="badge-status-inpatient">
                  <span class="dot-inpatient-pulse"></span> Admitted
                </span>
              </div>
            </td>
          </tr>
        `;
      });

      if (tableBody) {
        tableBody.innerHTML = rowsHtml;
      }
    }

    // 1-Second Countdown Ticking Engine
    function tickCountdowns() {
      const tags = document.querySelectorAll('.hold-countdown-tag');
      tags.forEach(tag => {
        let secs = parseInt(tag.getAttribute('data-secs'), 10) || 0;
        if (secs > 0) {
          secs--;
          tag.setAttribute('data-secs', secs);
          const txt = tag.querySelector('.countdown-text');
          if (txt) txt.textContent = formatCountdown(secs);

          if (secs <= 0) {
            tag.classList.add('expired');
            if (txt) txt.textContent = 'Expired';
            // Auto trigger poll on expiration
            pollSync();
          }
        }
      });
    }

    // Background Polling Engine
    async function pollSync() {
      try {
        const res = await fetch('api/live_sync.php?action=sync');
        if (!res.ok) return;

        const data = await res.json();
        if (!data || !data.success) return;

        // Keep active doctors list updated for modal
        if (data.doctors && Array.isArray(data.doctors)) {
          activeDoctorsList = data.doctors;
        }

        // Render sections smoothly
        renderIncomingHolds(data.incoming_holds || []);
        renderInpatientRegistry(data.inpatients || []);

      } catch (err) {
        console.warn('Live sync poll warning:', err);
      }
    }

    // HTML Escaping Utility
    function escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function escapeAttr(str) {
      return escapeHtml(str).replace(/"/g, '&quot;');
    }

    // Initialize On Page Ready
    document.addEventListener('DOMContentLoaded', () => {
      // Immediate initial sync
      pollSync();

      // Poll interval: every 4 seconds
      pollIntervalTimer = setInterval(pollSync, 4000);

      // Countdown tick: every 1 second
      countdownTickTimer = setInterval(tickCountdowns, 1000);
    });
  </script>
</body>
</html>

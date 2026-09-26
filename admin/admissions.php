<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Branch Admin Inpatient Admissions Registry Console
 *
 * Implements:
 * - Scoped strictly to authenticated facility (:session_hospital_id)
 * - Complete real-world admission dossier tracking (Bed #, Patient UHID, Admitting Staff & Designation, Assigned Doctor, Diagnosis, Live Inpatient Status badge)
 * - Instant synchronization with Staff Intake Desk & Super Admin Telemetry
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/tenant_scope.php';

// Session hospital resolution & strict tenant binding
$sessionHospitalId = TenantScope::enforce($pdo, ['admin', 'hospital_admin', 'super_admin']);

// Fetch branch hospital information
$branchStmt = $pdo->prepare("SELECT hospital_id, name, code, address, city, phone FROM hospitals WHERE hospital_id = ?");
$branchStmt->execute([$sessionHospitalId]);
$currentHospital = $branchStmt->fetch(PDO::FETCH_ASSOC);
$branchName = $currentHospital['name'] ?? "Branch Hospital #{$sessionHospitalId}";

// ── GET FILTERS ─────────────────────────────────────────────────────────────
$searchQuery = trim($_GET['q'] ?? '');
$filterAcuity = trim($_GET['acuity'] ?? '');
$filterStatus = trim($_GET['status'] ?? 'Admitted'); // Default to active inpatients
$filterWard = trim($_GET['ward'] ?? '');

// ── QUERY 1: Inpatient Admissions Registry ──────────────────────────────────
$sql = "
    SELECT 
        adm.admission_id,
        adm.admission_number,
        adm.reservation_id,
        adm.hospital_id,
        adm.bed_id,
        adm.patient_id,
        COALESCE(adm.patient_uid, p.patient_uid, CONCAT('MP-P', LPAD(u.user_id, 5, '0'))) AS patient_uid,
        adm.guardian_name,
        adm.guardian_relation,
        adm.guardian_phone,
        adm.admitting_staff_id,
        adm.attending_doctor_id,
        adm.admission_reason,
        adm.primary_diagnosis,
        adm.triage_acuity,
        adm.daily_rate,
        adm.deposit_amount,
        adm.payment_method,
        adm.payment_reference,
        adm.status AS admission_status,
        adm.admitted_at,
        adm.discharged_at,
        adm.created_at,
        -- Patient Demographics
        u.full_name AS patient_name,
        u.email AS patient_email,
        u.phone AS patient_phone,
        u.gender AS patient_gender,
        COALESCE(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), u.age, 0) AS patient_age,
        COALESCE(p.blood_group, u.blood_group, 'Unknown') AS patient_blood_group,
        -- Bed & Ward
        b.bed_number,
        b.ward_type,
        b.floor_number,
        b.status AS current_bed_status,
        -- Admitting Staff Metadata
        COALESCE(su.full_name, 'Senior Triage Officer') AS staff_name,
        COALESCE(stf.role_title, 'Senior Triage Officer / Admission Clerk') AS staff_designation,
        COALESCE(stf.department, 'Inpatient Nursing & Triage') AS staff_department,
        COALESCE(stf.staff_id, adm.admitting_staff_id) AS display_staff_id,
        -- Attending Physician / Consultant
        COALESCE(doc.full_name, 'Consultant On Duty') AS doctor_name,
        COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty
    FROM admissions adm
    JOIN users u ON u.user_id = adm.patient_id
    LEFT JOIN patients p ON (p.user_id = adm.patient_id OR p.id = adm.patient_id)
    JOIN hospital_beds b ON b.bed_id = adm.bed_id
    JOIN hospitals h ON h.hospital_id = adm.hospital_id
    LEFT JOIN staff stf ON stf.staff_id = adm.admitting_staff_id
    LEFT JOIN users su ON (su.user_id = stf.user_id OR su.user_id = adm.admitting_staff_id)
    LEFT JOIN users doc ON doc.user_id = adm.attending_doctor_id
    LEFT JOIN doctor_profiles dp ON dp.user_id = adm.attending_doctor_id
    WHERE adm.hospital_id = :hosp_id
";

$params = [':hosp_id' => $sessionHospitalId];

if ($filterAcuity !== '') {
    $sql .= " AND adm.triage_acuity = :acuity";
    $params[':acuity'] = $filterAcuity;
}

if ($filterStatus !== '' && $filterStatus !== 'all') {
    $sql .= " AND adm.status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterWard !== '') {
    $sql .= " AND b.ward_type = :ward";
    $params[':ward'] = $filterWard;
}

if ($searchQuery !== '') {
    $sql .= " AND (
        u.full_name LIKE :search 
        OR adm.admission_number LIKE :search 
        OR adm.patient_uid LIKE :search 
        OR p.patient_uid LIKE :search 
        OR b.bed_number LIKE :search 
        OR doc.full_name LIKE :search 
        OR adm.primary_diagnosis LIKE :search
    )";
    $params[':search'] = "%{$searchQuery}%";
}

$sql .= " ORDER BY adm.admitted_at DESC";

$admStmt = $pdo->prepare($sql);
$admStmt->execute($params);
$admissionsList = $admStmt->fetchAll(PDO::FETCH_ASSOC);

// ── QUERY 2: Live Inpatient Registry Metrics ────────────────────────────────
$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'Admitted' THEN 1 END) AS active_inpatients,
        COUNT(CASE WHEN status = 'Admitted' AND triage_acuity = 'Critical' THEN 1 END) AS critical_inpatients,
        COUNT(CASE WHEN DATE(admitted_at) = CURRENT_DATE THEN 1 END) AS admitted_today,
        (SELECT COUNT(*) FROM hospital_beds WHERE hospital_id = :hosp_id1 AND status = 'Available') AS available_beds
    FROM admissions
    WHERE hospital_id = :hosp_id2
");
$kpiStmt->execute([
    ':hosp_id1' => $sessionHospitalId,
    ':hosp_id2' => $sessionHospitalId
]);
$metrics = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$activeInpatientsCount  = (int)($metrics['active_inpatients'] ?? 0);
$criticalCount          = (int)($metrics['critical_inpatients'] ?? 0);
$admittedTodayCount     = (int)($metrics['admitted_today'] ?? 0);
$availableBedsCount     = (int)($metrics['available_beds'] ?? 0);

// Ward types for filter dropdown
$wardTypesStmt = $pdo->prepare("SELECT DISTINCT ward_type FROM hospital_beds WHERE hospital_id = ? ORDER BY ward_type ASC");
$wardTypesStmt->execute([$sessionHospitalId]);
$wardTypes = $wardTypesStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Branch Inpatient Registry &amp; Admissions</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  
  <style>
    :root {
      --adm-primary: #0284c7;
      --adm-dark: #0f172a;
      --adm-surface: #ffffff;
      --adm-border: #e2e8f0;
      --adm-text: #1e293b;
      --adm-muted: #64748b;
    }
    body {
      background-color: #f8fafc;
      font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--adm-text);
    }
    .branch-identity-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(2, 132, 199, 0.1);
      border: 1px solid rgba(2, 132, 199, 0.25);
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 0.85rem;
      font-weight: 700;
      color: #0284c7;
      margin-bottom: 12px;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 8px #10b981;
    }
    .subnav-tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 1.5rem;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 10px;
    }
    .subnav-tab {
      padding: 8px 18px;
      border-radius: 8px;
      font-weight: 700;
      font-size: 0.9rem;
      text-decoration: none;
      color: var(--adm-muted);
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .subnav-tab:hover {
      color: #0284c7;
      background: rgba(2, 132, 199, 0.06);
    }
    .subnav-tab.active {
      color: #ffffff;
      background: linear-gradient(135deg, #0284c7, #0d9488);
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
    }
    .stat-card-clean {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1.25rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }
    .stat-icon-box {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .filter-panel {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 1.25rem;
      margin-bottom: 1.75rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .adm-badge-live {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid #a7f3d0;
      padding: 4px 10px;
      border-radius: 9999px;
      font-weight: 700;
      font-size: 0.76rem;
      letter-spacing: 0.03em;
    }
    .adm-badge-live .pulse-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
      animation: pulseGreen 1.8s infinite;
    }
    @keyframes pulseGreen {
      0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
      100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .acuity-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 700;
    }
    .acuity-routine {
      background: #f0fdf4;
      color: #166534;
      border: 1px solid #bbf7d0;
    }
    .acuity-critical {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }
    .acuity-postop {
      background: #fffbeb;
      color: #92400e;
      border: 1px solid #fde68a;
    }
    .dossier-id-chip {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 700;
      font-size: 0.8rem;
      color: #0284c7;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      padding: 3px 8px;
      border-radius: 6px;
      display: inline-block;
    }
    .staff-meta-box {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .staff-name {
      font-weight: 700;
      color: #1e293b;
      font-size: 0.88rem;
    }
    .staff-desig {
      font-size: 0.74rem;
      color: #0284c7;
      font-weight: 600;
    }
    .staff-id-tag {
      font-size: 0.7rem;
      color: #94a3b8;
      font-family: monospace;
    }
    .btn-view-dossier {
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      color: #1e293b;
      font-weight: 700;
      font-size: 0.8rem;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-view-dossier:hover {
      background: #0284c7;
      border-color: #0284c7;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
    }

    /* Modal Styling */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(4px);
      z-index: 10000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .modal-overlay.open {
      display: flex;
    }
    .modal-container {
      background: #ffffff;
      border-radius: 16px;
      width: 100%;
      max-width: 820px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      border: 1px solid #e2e8f0;
      animation: modalSlide 0.2s ease-out;
    }
    @keyframes modalSlide {
      from { transform: translateY(12px) scale(0.98); opacity: 0; }
      to { transform: translateY(0) scale(1); opacity: 1; }
    }
    .modal-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f8fafc;
    }
    .modal-body {
      padding: 1.5rem;
    }
    .dossier-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1.25rem;
    }
    @media (max-width: 768px) {
      .dossier-grid { grid-template-columns: 1fr; }
    }
    .dossier-section {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.25rem;
    }
    .dossier-sec-title {
      font-size: 0.84rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #0284c7;
      margin-bottom: 0.85rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .dossier-field {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
      border-bottom: 1px dashed #e2e8f0;
      font-size: 0.85rem;
    }
    .dossier-field:last-child {
      border-bottom: none;
    }
    .dossier-key {
      color: var(--adm-muted);
      font-weight: 500;
    }
    .dossier-val {
      color: var(--adm-text);
      font-weight: 700;
      text-align: right;
    }
  </style>
</head>
<body>

  <!-- Centralized Admin Sidebar -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container -->
  <main class="viewport-full">

    <!-- Page Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="branch-identity-badge">
          <span class="badge-dot"></span>
          Facility Scope: <?= htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8') ?> (ID: #<?= (int)$sessionHospitalId ?>)
        </div>
        <h1>
          Branch Inpatient Admissions Registry
          <svg class="ui-ico" style="stroke: #0284c7; width: 26px; height: 26px;" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14h6"></path><path d="M9 10h6"></path><path d="M9 18h4"></path></svg>
        </h1>
        <p>Live Inpatient census ledger, intake dossiers, assigned doctors, and seamless synchronization with Staff Intake Desk &amp; Super Admin Telemetry.</p>
      </div>
      <div class="banner-actions">
        <a href="beds.php" class="btn-action-telemed">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><polyline points="10 12 14 12 14 16"></polyline></svg>
          Bed Matrix &amp; Holds
        </a>
        <a href="inpatient_care.php" class="btn-action-gradient">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          Inpatient Care Teams
        </a>
      </div>
    </div>

    <!-- Sub-Navigation Navigation Tabs -->
    <div class="subnav-tabs">
      <a href="manage_patients.php" class="subnav-tab">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
        Master Patient Directory
      </a>
      <a href="admissions.php" class="subnav-tab active">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14h6"></path><path d="M9 10h6"></path><path d="M9 18h4"></path></svg>
        Inpatient Admissions Registry (Live Sync)
      </a>
      <a href="inpatient_care.php" class="subnav-tab">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        Physician Care &amp; Rounding Teams
      </a>
      <a href="beds.php" class="subnav-tab">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path></svg>
        Branch Beds &amp; Pre-Reservations
      </a>
    </div>

    <!-- Quick Vital KPI Stats -->
    <div class="stat-cards-grid" style="margin-bottom: 1.75rem;">
      <div class="stat-card-clean">
        <div class="stat-icon-box" style="background: rgba(2, 132, 199, 0.12); color: #0284c7;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        </div>
        <div>
          <div style="font-size: 0.82rem; font-weight: 600; color: var(--adm-muted);">Active Inpatients</div>
          <div style="font-size: 1.75rem; font-weight: 800; color: #1e293b;"><?= number_format($activeInpatientsCount) ?></div>
          <div style="font-size: 0.74rem; font-weight: 600; color: #10b981;">Currently Under Care</div>
        </div>
      </div>

      <div class="stat-card-clean">
        <div class="stat-icon-box" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
          <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </div>
        <div>
          <div style="font-size: 0.82rem; font-weight: 600; color: var(--adm-muted);">Critical / High Acuity</div>
          <div style="font-size: 1.75rem; font-weight: 800; color: #ef4444;"><?= number_format($criticalCount) ?></div>
          <div style="font-size: 0.74rem; font-weight: 600; color: #ef4444;">Intensive Monitoring</div>
        </div>
      </div>

      <div class="stat-card-clean">
        <div class="stat-icon-box" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
          <svg class="ui-ico" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div>
          <div style="font-size: 0.82rem; font-weight: 600; color: var(--adm-muted);">Admitted Today</div>
          <div style="font-size: 1.75rem; font-weight: 800; color: #10b981;"><?= number_format($admittedTodayCount) ?></div>
          <div style="font-size: 0.74rem; font-weight: 600; color: var(--adm-muted);">Intake Volume</div>
        </div>
      </div>

      <div class="stat-card-clean">
        <div class="stat-icon-box" style="background: rgba(14, 165, 233, 0.12); color: #0ea5e9;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path></svg>
        </div>
        <div>
          <div style="font-size: 0.82rem; font-weight: 600; color: var(--adm-muted);">Available Vacancies</div>
          <div style="font-size: 1.75rem; font-weight: 800; color: #0284c7;"><?= number_format($availableBedsCount) ?></div>
          <div style="font-size: 0.74rem; font-weight: 600; color: #0284c7;">Ready for Admission</div>
        </div>
      </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-panel">
      <form method="GET" action="admissions.php" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;">
        
        <div style="display: flex; flex: 1; min-width: 280px; gap: 10px;">
          <input type="text" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" 
                 placeholder="Search by Patient Name, UHID, Doctor, Diagnosis, Bed #..." 
                 class="filter-select" style="flex: 1; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
          <button type="submit" class="btn-action-telemed" style="padding: 10px 16px;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            Filter
          </button>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
          <select name="acuity" onchange="this.form.submit()" class="filter-select" style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
            <option value="">All Triage Acuities</option>
            <option value="Routine" <?= $filterAcuity === 'Routine' ? 'selected' : '' ?>>Routine Acuity</option>
            <option value="Critical" <?= $filterAcuity === 'Critical' ? 'selected' : '' ?>>Critical / HDU</option>
            <option value="Post-Op" <?= $filterAcuity === 'Post-Op' ? 'selected' : '' ?>>Post-Operative</option>
          </select>

          <select name="ward" onchange="this.form.submit()" class="filter-select" style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
            <option value="">All Wards &amp; Units</option>
            <?php foreach ($wardTypes as $wt): ?>
              <option value="<?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?>" <?= $filterWard === $wt ? 'selected' : '' ?>>
                <?= htmlspecialchars($wt, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select name="status" onchange="this.form.submit()" class="filter-select" style="padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
            <option value="Admitted" <?= $filterStatus === 'Admitted' ? 'selected' : '' ?>>Status: Admitted (Active)</option>
            <option value="Discharged" <?= $filterStatus === 'Discharged' ? 'selected' : '' ?>>Status: Discharged</option>
            <option value="Transferred" <?= $filterStatus === 'Transferred' ? 'selected' : '' ?>>Status: Transferred</option>
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Admission Records</option>
          </select>

          <?php if ($searchQuery !== '' || $filterAcuity !== '' || $filterWard !== '' || $filterStatus !== 'Admitted'): ?>
            <a href="admissions.php" class="btn-action-telemed" style="padding: 10px 14px; color: #ef4444; border-color: rgba(239, 68, 68, 0.3);">
              Reset Filters
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Inpatient Registry Table -->
    <div class="admin-table-wrap" style="background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.03);">
      <table class="admin-data-table" style="margin: 0;">
        <thead>
          <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
            <th style="padding: 14px 16px;">Bed # &amp; Ward</th>
            <th style="padding: 14px 16px;">Patient &amp; UHID</th>
            <th style="padding: 14px 16px;">Admitting Staff Metadata</th>
            <th style="padding: 14px 16px;">Assigned Doctor</th>
            <th style="padding: 14px 16px;">Diagnosis &amp; Acuity</th>
            <th style="padding: 14px 16px;">Live Status</th>
            <th style="padding: 14px 16px; text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($admissionsList)): ?>
            <tr>
              <td colspan="7" style="text-align: center; padding: 4rem 1rem; color: var(--adm-muted);">
                <svg class="ui-ico" style="width: 48px; height: 48px; margin-bottom: 12px; stroke: #94a3b8;" viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b;">No Inpatient Admission Records Found</div>
                <div style="font-size: 0.85rem; margin-top: 4px;">When staff processes an incoming bed reservation or manual intake, it will appear here in real time.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($admissionsList as $adm): 
              $acuityClass = match(strtolower($adm['triage_acuity'])) {
                'critical' => 'acuity-critical',
                'post-op'  => 'acuity-postop',
                default    => 'acuity-routine'
              };
            ?>
              <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;">
                <!-- Bed # & Ward -->
                <td style="padding: 14px 16px;">
                  <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-weight: 800; font-size: 0.96rem; color: #0284c7; background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 8px; border-radius: 6px;">
                      Bed <?= htmlspecialchars($adm['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                  <div style="font-size: 0.78rem; color: var(--adm-muted); margin-top: 4px;">
                    <?= htmlspecialchars($adm['ward_type'], ENT_QUOTES, 'UTF-8') ?> &bull; Floor <?= (int)$adm['floor_number'] ?>
                  </div>
                  <div style="font-size: 0.74rem; font-weight: 600; color: #64748b; margin-top: 2px;">
                    Rate: ৳<?= number_format((float)$adm['daily_rate'], 2) ?>/day
                  </div>
                </td>

                <!-- Patient & UHID -->
                <td style="padding: 14px 16px;">
                  <strong style="color: #0f172a; font-size: 0.94rem; display: block;">
                    <?= htmlspecialchars($adm['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                  <div style="display: flex; gap: 6px; align-items: center; margin-top: 4px; flex-wrap: wrap;">
                    <span class="dossier-id-chip"><?= htmlspecialchars($adm['patient_uid'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span style="font-size: 0.74rem; color: var(--adm-muted);">
                      <?= htmlspecialchars($adm['patient_gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>, <?= (int)$adm['patient_age'] ?> yrs
                    </span>
                    <span style="font-size: 0.72rem; font-weight: 700; color: #dc2626; background: #fee2e2; padding: 1px 5px; border-radius: 4px;">
                      <?= htmlspecialchars($adm['patient_blood_group'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                  <?php if (!empty($adm['guardian_name'])): ?>
                    <div style="font-size: 0.72rem; color: #64748b; margin-top: 4px;">
                      Guardian: <?= htmlspecialchars($adm['guardian_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($adm['guardian_relation'] ?? 'Kin', ENT_QUOTES, 'UTF-8') ?>)
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Admitting Staff Metadata -->
                <td style="padding: 14px 16px;">
                  <div class="staff-meta-box">
                    <span class="staff-name"><?= htmlspecialchars($adm['staff_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="staff-desig"><?= htmlspecialchars($adm['staff_designation'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="staff-id-tag">ID: STF-<?= str_pad((string)$adm['display_staff_id'], 4, '0', STR_PAD_LEFT) ?></span>
                  </div>
                </td>

                <!-- Assigned Doctor -->
                <td style="padding: 14px 16px;">
                  <strong style="color: #0f172a; font-size: 0.9rem; display: block;">
                    <?= htmlspecialchars($adm['doctor_name'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                  <span style="font-size: 0.76rem; color: #0284c7; font-weight: 600; display: inline-block; margin-top: 2px;">
                    <?= htmlspecialchars($adm['doctor_specialty'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>

                <!-- Diagnosis & Acuity -->
                <td style="padding: 14px 16px;">
                  <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <span class="acuity-pill <?= $acuityClass ?>">
                      <?= htmlspecialchars($adm['triage_acuity'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                  <div style="font-size: 0.82rem; font-weight: 600; color: #334155;">
                    <?= htmlspecialchars($adm['primary_diagnosis'] ?: $adm['admission_reason'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size: 0.72rem; color: #94a3b8; margin-top: 2px;">
                    Admitted: <?= date('M d, Y h:i A', strtotime($adm['admitted_at'])) ?>
                  </div>
                </td>

                <!-- Live Status Badge -->
                <td style="padding: 14px 16px;">
                  <?php if ($adm['admission_status'] === 'Admitted'): ?>
                    <span class="adm-badge-live">
                      <span class="pulse-dot"></span>
                      ADMITTED / INPATIENT
                    </span>
                  <?php elseif ($adm['admission_status'] === 'Discharged'): ?>
                    <span style="background: #f1f5f9; color: #475569; font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">
                      DISCHARGED
                    </span>
                  <?php else: ?>
                    <span style="background: #fef3c7; color: #92400e; font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">
                      <?= htmlspecialchars($adm['admission_status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Action Button -->
                <td style="padding: 14px 16px; text-align: right;">
                  <button type="button" class="btn-view-dossier" onclick="viewDossier(<?= htmlspecialchars(json_encode($adm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>)">
                    <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    View Dossier
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>

  <!-- Comprehensive Admission Dossier Inspection Modal -->
  <div class="modal-overlay" id="dossierModal">
    <div class="modal-container">
      <div class="modal-header">
        <div>
          <div style="font-size: 0.8rem; font-weight: 700; color: #0284c7; text-transform: uppercase; letter-spacing: 0.05em;">
            Official Inpatient Admission Dossier
          </div>
          <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 4px 0 0;" id="mDossierNum">
            ADM-00000000-0000
          </h2>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <span id="mStatusBadge" class="adm-badge-live">
            <span class="pulse-dot"></span> ADMITTED
          </span>
          <button type="button" onclick="closeDossierModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; padding: 4px 8px;">
            &times;
          </button>
        </div>
      </div>

      <div class="modal-body">
        <div class="dossier-grid">
          
          <!-- 1. Admitting Staff Metadata -->
          <div class="dossier-section">
            <div class="dossier-sec-title">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
              1. Admitting Staff Metadata
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Officer Name:</span>
              <span class="dossier-val" id="mStaffName">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Designation:</span>
              <span class="dossier-val" id="mStaffDesig" style="color: #0284c7;">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Staff ID:</span>
              <span class="dossier-val" id="mStaffID" style="font-family: monospace;">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Department:</span>
              <span class="dossier-val" id="mStaffDept">—</span>
            </div>
          </div>

          <!-- 2. Patient & Guardian Details -->
          <div class="dossier-section">
            <div class="dossier-sec-title">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
              2. Patient &amp; Guardian Info
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Patient Full Name:</span>
              <span class="dossier-val" id="mPatientName">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">UHID:</span>
              <span class="dossier-val" id="mPatientUID" style="color: #0284c7; font-family: monospace;">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Age / Gender / Blood:</span>
              <span class="dossier-val" id="mPatientDemo">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Emergency Contact:</span>
              <span class="dossier-val" id="mGuardianInfo">—</span>
            </div>
          </div>

          <!-- 3. Clinical Intake Allocation -->
          <div class="dossier-section">
            <div class="dossier-sec-title">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
              3. Clinical Intake Allocation
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Attending Physician:</span>
              <span class="dossier-val" id="mDocName">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Specialty / Wing:</span>
              <span class="dossier-val" id="mDocSpecialty" style="color: #0d9488;">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Triage Acuity:</span>
              <span class="dossier-val" id="mAcuityBadge">—</span>
            </div>
            <div class="dossier-field" style="flex-direction: column; align-items: flex-start; gap: 4px;">
              <span class="dossier-key">Primary Diagnosis / Reason:</span>
              <span class="dossier-val" id="mDiagnosis" style="text-align: left; font-size: 0.85rem; color: #1e293b;">—</span>
            </div>
          </div>

          <!-- 4. Room Assignment & Billing Reference -->
          <div class="dossier-section">
            <div class="dossier-sec-title">
              <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
              4. Room &amp; Financial Ledger
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Bed &amp; Ward:</span>
              <span class="dossier-val" id="mBedWard" style="color: #0284c7;">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Daily Ward Rate:</span>
              <span class="dossier-val" id="mDailyRate">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Admission Deposit Paid:</span>
              <span class="dossier-val" id="mDepositAmount" style="color: #10b981;">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Payment Method / Ref:</span>
              <span class="dossier-val" id="mPaymentMethod">—</span>
            </div>
            <div class="dossier-field">
              <span class="dossier-key">Admission Date &amp; Time:</span>
              <span class="dossier-val" id="mAdmittedAt">—</span>
            </div>
          </div>

        </div>

        <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 10px;">
          <a id="mRoundingLink" href="inpatient_care.php" class="btn-action-gradient" style="padding: 10px 18px; text-decoration: none;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            Open in Inpatient Care &amp; Rounding
          </a>
          <button type="button" onclick="closeDossierModal()" class="btn-action-telemed" style="padding: 10px 18px;">
            Close Dossier
          </button>
        </div>

      </div>
    </div>
  </div>

  <script>
    function viewDossier(data) {
      document.getElementById('mDossierNum').textContent = data.admission_number || 'ADM-RECORD';
      document.getElementById('mStaffName').textContent = data.staff_name || 'Hospital Intake Officer';
      document.getElementById('mStaffDesig').textContent = data.staff_designation || 'Senior Triage Officer / Admission Clerk';
      document.getElementById('mStaffID').textContent = 'STF-' + String(data.display_staff_id || 1).padStart(4, '0');
      document.getElementById('mStaffDept').textContent = data.staff_department || 'Inpatient Nursing & Triage';

      document.getElementById('mPatientName').textContent = data.patient_name || '—';
      document.getElementById('mPatientUID').textContent = data.patient_uid || '—';
      document.getElementById('mPatientDemo').textContent = `${data.patient_gender || 'Male'}, ${data.patient_age || '—'} yrs (${data.patient_blood_group || 'Unknown'})`;
      
      const guardian = data.guardian_name ? `${data.guardian_name} (${data.guardian_relation || 'Kin'}) - ${data.guardian_phone || 'N/A'}` : 'None recorded';
      document.getElementById('mGuardianInfo').textContent = guardian;

      document.getElementById('mDocName').textContent = data.doctor_name || 'Consultant On Duty';
      document.getElementById('mDocSpecialty').textContent = data.doctor_specialty || 'General Medicine';
      document.getElementById('mAcuityBadge').textContent = data.triage_acuity || 'Routine';
      document.getElementById('mDiagnosis').textContent = data.primary_diagnosis || data.admission_reason || 'Under clinical investigation';

      document.getElementById('mBedWard').textContent = `Bed ${data.bed_number} (${data.ward_type}, Fl. ${data.floor_number})`;
      document.getElementById('mDailyRate').textContent = `৳${Number(data.daily_rate || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}/day`;
      document.getElementById('mDepositAmount').textContent = `৳${Number(data.deposit_amount || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}`;
      document.getElementById('mPaymentMethod').textContent = `${data.payment_method || 'Cash'}${data.payment_reference ? ' (Ref: ' + data.payment_reference + ')' : ''}`;
      
      const dateStr = data.admitted_at ? new Date(data.admitted_at).toLocaleString('en-BD', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
      document.getElementById('mAdmittedAt').textContent = dateStr;

      document.getElementById('dossierModal').classList.add('open');
    }

    function closeDossierModal() {
      document.getElementById('dossierModal').classList.remove('open');
    }

    // Close on overlay click
    document.getElementById('dossierModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeDossierModal();
      }
    });
  </script>
</body>
</html>

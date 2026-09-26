<?php
/**
 * MedPulse Enterprise HMS — Staff Portal: Inpatient Intake & Bed Admission Console
 * 
 * Features:
 * - Real-time incoming 45-minute self-service bed holds queue
 * - Pre-filled clinical admission dossier intake modal
 * - Atomic transactional sync across Staff Desk, Branch Admin, and Super Admin Telemetry
 * - Dynamic live countdown timers & multi-tiered registry sync
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/BedReservationController.php';

// Authentication Guard: Staff role required (or elevated admin/super_admin)
$role = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['staff', 'admin', 'super_admin'], true)) {
    medpulseDestroySession('../login.php');
}

$currentUserId = (int)$_SESSION['user_id'];

// Resolve Logged-in Staff Member Profile & Hospital Affiliation
try {
    $staffStmt = $pdo->prepare("
        SELECT s.staff_id, s.hospital_id, s.department, s.role_title, u.full_name, u.email, u.phone,
               h.name AS hospital_name, h.location AS hospital_location, h.code AS hospital_code
        FROM users u
        LEFT JOIN staff s ON s.user_id = u.user_id
        LEFT JOIN hospitals h ON h.hospital_id = COALESCE(s.hospital_id, 1)
        WHERE u.user_id = :uid
        LIMIT 1
    ");
    $staffStmt->execute([':uid' => $currentUserId]);
    $staffProfile = $staffStmt->fetch(PDO::FETCH_ASSOC);

    $staffId = (int)($staffProfile['staff_id'] ?? 0);
    if ($staffId === 0) {
        // Auto-provision staff link if missing
        $initHosp = !empty($_SESSION['hospital_id']) ? (int)$_SESSION['hospital_id'] : 1;
        $insStf = $pdo->prepare("
            INSERT INTO staff (user_id, hospital_id, department, role_title, status)
            VALUES (?, ?, 'Inpatient Nursing & Triage', 'Senior Triage Officer', 'active')
        ");
        $insStf->execute([$currentUserId, $initHosp]);
        $staffId = (int)$pdo->lastInsertId();
    }

    $staffHospitalId   = (int)($staffProfile['hospital_id'] ?? $_SESSION['hospital_id'] ?? 1);
    $staffName         = $staffProfile['full_name'] ?? $_SESSION['full_name'] ?? 'Staff Officer';
    $staffDesignation  = $staffProfile['role_title'] ?? 'Senior Triage Officer / Admission Clerk';
    if ($staffDesignation === 'Staff' || empty($staffDesignation)) {
        $staffDesignation = 'Senior Triage Officer / Admission Clerk';
    }
    $staffDepartment   = $staffProfile['department'] ?? 'Inpatient Nursing & Triage';
    $staffHospitalName = $staffProfile['hospital_name'] ?? 'MedPulse Central Hospital';
    $staffHospitalCode = $staffProfile['hospital_code'] ?? 'HOSP-1';

} catch (PDOException $e) {
    error_log("Staff Profile Error: " . $e->getMessage());
    $staffId           = 1;
    $staffHospitalId   = 1;
    $staffName         = $_SESSION['full_name'] ?? 'Staff Officer';
    $staffDesignation  = 'Senior Triage Officer / Admission Clerk';
    $staffDepartment   = 'Inpatient Nursing & Triage';
    $staffHospitalName = 'MedPulse Central Hospital';
    $staffHospitalCode = 'HOSP-1';
}

// ── Handle Inpatient Admission Submission ───────────────────────────────────
$flashMessage = null;
$flashType    = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_admission') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

    // CSRF verification
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Please refresh the page.']);
            exit;
        }
        $flashMessage = 'Security validation failed. Please refresh and try again.';
        $flashType    = 'error';
    } else {
        $admissionParams = [
            'reservation_id'      => filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
            'bed_id'              => (int)($_POST['bed_id'] ?? 0),
            'patient_id'          => (int)($_POST['patient_id'] ?? 0),
            'hospital_id'         => $staffHospitalId,
            'admitting_staff_id'  => $staffId,
            'staff_user_id'       => $currentUserId,
            'attending_doctor_id' => (int)($_POST['attending_doctor_id'] ?? 0),
            'guardian_name'       => trim($_POST['guardian_name'] ?? ''),
            'guardian_relation'   => trim($_POST['guardian_relation'] ?? 'Next of Kin'),
            'guardian_phone'      => trim($_POST['guardian_phone'] ?? ''),
            'admission_reason'    => trim($_POST['admission_reason'] ?? ''),
            'primary_diagnosis'   => trim($_POST['primary_diagnosis'] ?? ''),
            'triage_acuity'       => trim($_POST['triage_acuity'] ?? 'Routine'),
            'daily_rate'          => (float)($_POST['daily_rate'] ?? 0.0),
            'deposit_amount'      => (float)($_POST['deposit_amount'] ?? 0.0),
            'payment_method'      => trim($_POST['payment_method'] ?? 'Cash'),
            'payment_reference'   => trim($_POST['payment_reference'] ?? '')
        ];

        $admissionResult = BedReservationController::processAdmission($pdo, $admissionParams);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($admissionResult);
            exit;
        }

        if (!empty($admissionResult['success'])) {
            $flashMessage = $admissionResult['message'];
            $flashType    = 'success';
        } else {
            $flashMessage = $admissionResult['message'] ?? 'Admission failed to process.';
            $flashType    = 'error';
        }
    }
}

// ── Handle Bed Hold Cancellation ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_hold') {
    $resId = (int)($_POST['reservation_id'] ?? 0);
    $patId = (int)($_POST['patient_id'] ?? 0);
    if ($resId > 0 && $patId > 0) {
        $cancelResult = BedReservationController::cancelReservation($pdo, $patId, $resId);
        $flashMessage = $cancelResult['message'] ?? 'Reservation updated.';
        $flashType    = !empty($cancelResult['success']) ? 'success' : 'error';
    }
}

// ── Run Maintenance Sweep & Fetch Live Telemetry Data ───────────────────────
BedReservationController::releaseExpiredHolds($pdo);

// 1. Incoming Active 45-Minute Holds for this Facility
$holdsStmt = $pdo->prepare("
    SELECT r.id AS reservation_id, r.hospital_id, r.bed_id, r.patient_id, r.hold_expires_at, r.status, r.created_at,
           b.bed_number, b.ward_type, b.floor_number, COALESCE(b.daily_rate, b.price_per_day, 1500.00) AS daily_rate,
           u.full_name AS patient_name, u.phone AS patient_phone, u.email AS patient_email, u.gender,
           COALESCE(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), u.age, 0) AS age,
           COALESCE(p.patient_uid, CONCAT('MP-', u.user_id)) AS patient_uid,
           COALESCE(p.blood_group, u.blood_group, 'Unknown') AS blood_group,
           COALESCE(p.allergies, 'NKDA') AS allergies,
           TIMESTAMPDIFF(SECOND, NOW(), r.hold_expires_at) AS seconds_remaining
    FROM bed_reservations r
    JOIN hospital_beds b ON r.bed_id = b.bed_id
    JOIN users u ON u.user_id = r.patient_id
    LEFT JOIN patients p ON (p.user_id = r.patient_id OR p.id = r.patient_id)
    WHERE r.hospital_id = :hosp_id
      AND r.status = 'held'
      AND r.hold_expires_at > NOW()
    ORDER BY r.created_at DESC
");
$holdsStmt->execute([':hosp_id' => $staffHospitalId]);
$incomingHolds = $holdsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Active Inpatient Admissions for this Facility
$admissionsStmt = $pdo->prepare("
    SELECT a.*,
           b.bed_number, b.ward_type, b.floor_number,
           u.full_name AS patient_name, u.phone AS patient_phone, u.gender,
           COALESCE(p.blood_group, u.blood_group, 'Unknown') AS blood_group,
           COALESCE(TIMESTAMPDIFF(YEAR, p.dob, CURDATE()), u.age, 0) AS age,
           doc.full_name AS doctor_name, COALESCE(dp.specialty, doc.department, 'General Medicine') AS doctor_specialty,
           stf_u.full_name AS staff_name, COALESCE(stf.role_title, 'Senior Triage Officer') AS staff_role
    FROM admissions a
    JOIN hospital_beds b ON a.bed_id = b.bed_id
    JOIN users u ON a.patient_id = u.user_id
    LEFT JOIN patients p ON (p.user_id = u.user_id OR p.id = u.user_id)
    LEFT JOIN users doc ON a.attending_doctor_id = doc.user_id
    LEFT JOIN doctor_profiles dp ON doc.user_id = dp.user_id
    LEFT JOIN staff stf ON a.admitting_staff_id = stf.staff_id
    LEFT JOIN users stf_u ON stf.user_id = stf_u.user_id
    WHERE a.hospital_id = :hosp_id
      AND a.status = 'Admitted'
    ORDER BY a.admitted_at DESC
    LIMIT 40
");
$admissionsStmt->execute([':hosp_id' => $staffHospitalId]);
$activeAdmissions = $admissionsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Facility Bed Capacity KPI Telemetry
$censusStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_beds,
        SUM(CASE WHEN LOWER(status) = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds,
        SUM(CASE WHEN LOWER(status) = 'available' THEN 1 ELSE 0 END) AS available_beds,
        SUM(CASE WHEN LOWER(status) = 'reserved' THEN 1 ELSE 0 END) AS reserved_beds
    FROM hospital_beds
    WHERE hospital_id = :hosp_id
");
$censusStmt->execute([':hosp_id' => $staffHospitalId]);
$facilityCensus = $censusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalBeds     = (int)($facilityCensus['total_beds'] ?? 0);
$occupiedBeds  = (int)($facilityCensus['occupied_beds'] ?? 0);
$availableBeds = (int)($facilityCensus['available_beds'] ?? 0);
$reservedBeds  = (int)($facilityCensus['reserved_beds'] ?? 0);
$occupancyPct  = $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100) : 0;

// 4. Query Active Approved Doctors for Consultant Allocation Dropdown
$docQuery = $pdo->prepare("
    SELECT u.user_id, u.full_name, COALESCE(dp.specialty, d.specialty, 'General Medicine') AS specialty
    FROM users u
    JOIN doctors d ON (d.user_id = u.user_id OR d.id = u.user_id)
    LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
    WHERE u.role = 'Doctor' AND u.status = 'active'
    ORDER BY u.full_name ASC
");
$docQuery->execute();
$activeDoctors = $docQuery->fetchAll(PDO::FETCH_ASSOC);

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
  <title>Staff Operations &amp; Inpatient Bed Admission Desk &middot; MedPulse HMS</title>
  
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <!-- SweetAlert2 for Instant Modal Action Feedback -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root {
      --brand-primary: #0284c7;
      --brand-teal: #0d9488;
      --brand-dark: #0f172a;
      --surface: #ffffff;
      --surface-subtle: #f8fafc;
      --surface-border: #e2e8f0;
      --text-heading: #0f172a;
      --text-body: #334155;
      --text-muted: #64748b;
      --status-emerald: #10b981;
      --status-amber: #f59e0b;
      --status-crimson: #ef4444;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background: #f1f5f9;
      color: var(--text-body);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* Top Navigation Shell */
    .staff-navbar {
      background: #0f172a;
      color: #ffffff;
      padding: 0.9rem 1.75rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
    }
    .staff-nav-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: #ffffff;
    }
    .staff-brand-icon {
      width: 38px;
      height: 38px;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.15rem;
      color: #ffffff;
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.4);
    }
    .staff-nav-title {
      font-size: 1.1rem;
      font-weight: 800;
      letter-spacing: -0.01em;
    }
    .staff-nav-subtitle {
      font-size: 0.72rem;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
    }
    .staff-nav-user {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .staff-user-chip {
      display: flex;
      align-items: center;
      gap: 10px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.14);
      padding: 5px 12px;
      border-radius: 9999px;
    }
    .staff-avatar-circle {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: var(--brand-teal);
      color: #ffffff;
      font-size: 0.76rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .staff-user-text {
      font-size: 0.82rem;
      font-weight: 700;
      color: #f8fafc;
    }
    .staff-badge-id {
      background: #0284c7;
      color: #ffffff;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 1px 7px;
      border-radius: 6px;
    }
    .btn-signout {
      color: #94a3b8;
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: color 0.2s;
    }
    .btn-signout:hover { color: #f87171; }

    /* Main Container */
    .staff-container {
      max-width: 1360px;
      width: 100%;
      margin: 1.5rem auto 3rem;
      padding: 0 1.25rem;
      flex: 1;
    }

    /* Facility Banner */
    .facility-banner {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      border-radius: 16px;
      padding: 1.5rem 1.75rem;
      color: #ffffff;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 4px 20px rgba(15, 23, 42, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .facility-meta h1 {
      font-size: 1.35rem;
      font-weight: 800;
      margin-bottom: 0.25rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .facility-pill {
      background: rgba(13, 148, 136, 0.2);
      border: 1px solid rgba(13, 148, 136, 0.4);
      color: #2dd4bf;
      font-size: 0.72rem;
      font-weight: 800;
      padding: 2px 10px;
      border-radius: 9999px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .facility-sub {
      color: #94a3b8;
      font-size: 0.86rem;
    }
    .staff-desk-info {
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 12px;
      padding: 0.65rem 1.1rem;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    /* KPI Cards */
    .kpi-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1.25rem;
      margin-bottom: 1.75rem;
    }
    .kpi-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 14px;
      padding: 1.25rem 1.4rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .kpi-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-bottom: 0.35rem;
    }
    .kpi-val {
      font-size: 1.75rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1;
    }
    .kpi-sub {
      font-size: 0.76rem;
      color: var(--text-muted);
      margin-top: 0.3rem;
    }
    .kpi-icon-box {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.35rem;
    }

    /* Queue & Table Panels */
    .panel-box {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 16px;
      padding: 1.5rem;
      margin-bottom: 1.75rem;
      box-shadow: 0 2px 12px rgba(0, 0, 0, 0.03);
    }
    .panel-header-flex {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.25rem;
      flex-wrap: wrap;
      gap: 10px;
    }
    .panel-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .pulse-pill-amber {
      background: #fef3c7;
      color: #b45309;
      border: 1px solid #fde68a;
      font-size: 0.75rem;
      font-weight: 800;
      padding: 2px 8px;
      border-radius: 9999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .pulse-dot-amber {
      width: 7px;
      height: 7px;
      background: #d97706;
      border-radius: 50%;
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(0.85); }
    }

    /* Incoming Holds Cards Grid */
    .holds-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 1.25rem;
    }
    .hold-card {
      background: #ffffff;
      border: 1.5px solid #fed7aa;
      border-radius: 14px;
      padding: 1.25rem;
      box-shadow: 0 4px 14px rgba(249, 115, 22, 0.06);
      transition: all 0.2s ease;
      position: relative;
    }
    .hold-card:hover {
      border-color: #f97316;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(249, 115, 22, 0.12);
    }
    .hold-card-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 0.75rem;
    }
    .hold-bed-badge {
      background: #0284c7;
      color: #ffffff;
      font-weight: 800;
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 0.88rem;
      font-family: 'JetBrains Mono', monospace;
    }
    .hold-timer-chip {
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #e11d48;
      font-weight: 800;
      font-size: 0.78rem;
      padding: 3px 9px;
      border-radius: 9999px;
      display: flex;
      align-items: center;
      gap: 5px;
      font-variant-numeric: tabular-nums;
    }
    .hold-pat-name {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.2rem;
    }
    .hold-pat-meta {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin-bottom: 0.85rem;
    }
    .hold-details-box {
      background: #f8fafc;
      border-radius: 10px;
      padding: 0.75rem 0.9rem;
      font-size: 0.82rem;
      display: flex;
      flex-direction: column;
      gap: 5px;
      margin-bottom: 1rem;
      border: 1px solid #e2e8f0;
    }
    .hold-row {
      display: flex;
      justify-content: space-between;
    }
    .hold-row-key { color: var(--text-muted); font-weight: 600; }
    .hold-row-val { color: var(--text-heading); font-weight: 700; }

    .btn-accept-admission {
      width: 100%;
      background: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      color: #ffffff;
      border: none;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      font-size: 0.88rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
    }
    .btn-accept-admission:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(2, 132, 199, 0.35);
      filter: brightness(1.05);
    }

    /* Tables */
    .table-responsive {
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--surface-border);
    }
    table.data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.86rem;
    }
    table.data-table th {
      background: #f8fafc;
      color: var(--text-muted);
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--surface-border);
    }
    table.data-table td {
      padding: 0.9rem 1rem;
      border-bottom: 1px solid #f1f5f9;
      color: var(--text-body);
      vertical-align: middle;
    }
    table.data-table tr:hover {
      background: #fbfcfe;
    }

    /* Badges */
    .badge-bed {
      background: rgba(2, 132, 199, 0.1);
      color: #0284c7;
      border: 1px solid rgba(2, 132, 199, 0.2);
      font-family: 'JetBrains Mono', monospace;
      font-weight: 800;
      font-size: 0.78rem;
      padding: 2px 7px;
      border-radius: 6px;
    }
    .badge-acuity {
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 0.74rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .acuity-routine { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .acuity-critical { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
    .acuity-postop { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

    .badge-inpatient-live {
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.25);
      font-size: 0.74rem;
      font-weight: 800;
      padding: 3px 9px;
      border-radius: 9999px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .dot-inpatient {
      width: 6px;
      height: 6px;
      background: #10b981;
      border-radius: 50%;
    }

    /* Modal Styling */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(6px);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .modal-overlay.active {
      display: flex;
    }
    .modal-card {
      background: #ffffff;
      border-radius: 20px;
      max-width: 780px;
      width: 100%;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
      border: 1px solid #e2e8f0;
      animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96) translateY(10px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .modal-head {
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #ffffff;
      padding: 1.4rem 1.75rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .modal-head h3 {
      font-size: 1.15rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn-modal-close {
      background: transparent;
      border: none;
      color: #94a3b8;
      font-size: 1.4rem;
      cursor: pointer;
      line-height: 1;
      transition: color 0.15s;
    }
    .btn-modal-close:hover { color: #ffffff; }

    .modal-body {
      padding: 1.75rem;
    }

    /* Modal Form Sections */
    .form-panel {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 1.15rem;
      margin-bottom: 1.25rem;
    }
    .form-panel-title {
      font-size: 0.8rem;
      font-weight: 800;
      color: var(--brand-primary);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 0.85rem;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .form-grid-3 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 0.85rem;
    }
    .form-grid-2 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 0.85rem;
    }
    .form-group {
      margin-bottom: 0.75rem;
    }
    .form-group:last-child { margin-bottom: 0; }
    .form-group label {
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
      border-radius: 10px;
      font-family: inherit;
      font-size: 0.88rem;
      color: var(--text-heading);
      background: #ffffff;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--brand-primary);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    }
    .form-control[readonly] {
      background: #f1f5f9;
      color: #64748b;
      cursor: not-allowed;
    }

    /* Staff Meta Pill Strip */
    .staff-meta-strip {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    .staff-pill-box {
      flex: 1;
      min-width: 170px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 0.6rem 0.85rem;
    }
    .spb-label { font-size: 0.68rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
    .spb-val { font-size: 0.88rem; font-weight: 800; color: var(--text-heading); }

    /* Modal Footer */
    .modal-foot {
      padding: 1.15rem 1.75rem;
      background: #f8fafc;
      border-top: 1px solid var(--surface-border);
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }
    .btn-secondary {
      padding: 0.7rem 1.25rem;
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 10px;
      font-weight: 700;
      font-size: 0.88rem;
      color: var(--text-muted);
      cursor: pointer;
    }
    .btn-confirm-admission {
      padding: 0.7rem 1.5rem;
      background: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      border: none;
      border-radius: 10px;
      font-weight: 800;
      font-size: 0.9rem;
      color: #ffffff;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
    }
    .btn-confirm-admission:hover {
      box-shadow: 0 6px 18px rgba(2, 132, 199, 0.4);
    }
  </style>
</head>
<body>

  <!-- Staff Portal Navigation -->
  <nav class="staff-navbar">
    <a href="dashboard.php" class="staff-nav-brand">
      <div class="staff-brand-icon">
        <i class="fa-solid fa-hospital"></i>
      </div>
      <div>
        <div class="staff-nav-title">MedPulse Staff Portal</div>
        <div class="staff-nav-subtitle">Inpatient Bed Admission &amp; Ward Desk</div>
      </div>
    </a>

    <div class="staff-nav-user">
      <div class="staff-user-chip">
        <div class="staff-avatar-circle">
          <?= strtoupper(substr($staffName, 0, 1)) ?>
        </div>
        <div>
          <span class="staff-user-text"><?= htmlspecialchars($staffName) ?></span>
          <span class="staff-badge-id">STF-<?= str_pad((string)$staffId, 3, '0', STR_PAD_LEFT) ?></span>
        </div>
      </div>
      <a href="../logout.php" class="btn-signout">
        <i class="fa-solid fa-right-from-bracket"></i> Sign Out
      </a>
    </div>
  </nav>

  <div class="staff-container">

    <!-- Flash Alert If Any -->
    <?php if ($flashMessage): ?>
      <script>
        document.addEventListener('DOMContentLoaded', function() {
          Swal.fire({
            icon: '<?= $flashType === 'success' ? 'success' : 'error' ?>',
            title: '<?= $flashType === 'success' ? 'Admission Confirmed' : 'Notification' ?>',
            text: '<?= addslashes($flashMessage) ?>',
            confirmButtonColor: '#0284c7'
          });
        });
      </script>
    <?php endif; ?>

    <!-- Facility & Desk Banner -->
    <div class="facility-banner">
      <div class="facility-meta">
        <h1>
          <?= htmlspecialchars($staffHospitalName) ?>
          <span class="facility-pill"><?= htmlspecialchars($staffHospitalCode) ?></span>
        </h1>
        <div class="facility-sub">
          <i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($staffProfile['hospital_location'] ?? 'Dhaka Central') ?> &bull; Active Emergency Ward Triage Desk
        </div>
      </div>
      <div class="staff-desk-info">
        <div>
          <div style="font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Desk Officer</div>
          <div style="font-size: 0.92rem; font-weight: 800; color: #ffffff;"><?= htmlspecialchars($staffName) ?></div>
        </div>
        <div style="height: 28px; width: 1px; background: rgba(255,255,255,0.2);"></div>
        <div>
          <div style="font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; font-weight: 700;">Designation</div>
          <div style="font-size: 0.85rem; font-weight: 700; color: #2dd4bf;"><?= htmlspecialchars($staffDesignation) ?></div>
        </div>
      </div>
    </div>

    <!-- Live Telemetry KPI Row -->
    <div class="kpi-row">
      <div class="kpi-card" style="border-left: 4px solid #f97316;">
        <div>
          <div class="kpi-label">Incoming 45-Min Bed Holds</div>
          <div class="kpi-val" style="color: #ea580c;"><?= count($incomingHolds) ?></div>
          <div class="kpi-sub">Awaiting patient intake</div>
        </div>
        <div class="kpi-icon-box" style="background: #fff7ed; color: #ea580c;">
          <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
      </div>

      <div class="kpi-card" style="border-left: 4px solid #10b981;">
        <div>
          <div class="kpi-label">Active Facility Inpatients</div>
          <div class="kpi-val" style="color: #059669;"><?= count($activeAdmissions) ?></div>
          <div class="kpi-sub">Currently under care</div>
        </div>
        <div class="kpi-icon-box" style="background: #f0fdf4; color: #059669;">
          <i class="fa-solid fa-bed-pulse"></i>
        </div>
      </div>

      <div class="kpi-card" style="border-left: 4px solid #0284c7;">
        <div>
          <div class="kpi-label">Ward Census &amp; Vacancy</div>
          <div class="kpi-val" style="color: #0284c7;"><?= $availableBeds ?></div>
          <div class="kpi-sub"><?= $occupiedBeds ?> Occupied &bull; <?= $occupancyPct ?>% Capacity</div>
        </div>
        <div class="kpi-icon-box" style="background: #f0f9ff; color: #0284c7;">
          <i class="fa-solid fa-hospital-user"></i>
        </div>
      </div>

      <div class="kpi-card" style="border-left: 4px solid #8b5cf6;">
        <div>
          <div class="kpi-label">Super Admin Telemetry</div>
          <div class="kpi-val" style="color: #7c3aed;">Synced</div>
          <div class="kpi-sub">6 Network Facilities Active</div>
        </div>
        <div class="kpi-icon-box" style="background: #f5f3ff; color: #7c3aed;">
          <i class="fa-solid fa-network-wired"></i>
        </div>
      </div>
    </div>

    <!-- Section 1: Incoming 45-Minute Bed Holds -->
    <div class="panel-box">
      <div class="panel-header-flex">
        <div>
          <h2 class="panel-title">
            <i class="fa-solid fa-hourglass-half" style="color: #f97316;"></i>
            Incoming Patient Bed Holds (45-Minute Window)
          </h2>
          <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
            Patients holding beds via self-service portal. Review details and accept for inpatient intake.
          </p>
        </div>
        <div>
          <span class="pulse-pill-amber">
            <span class="pulse-dot-amber"></span>
            <?= count($incomingHolds) ?> Active Hold<?= count($incomingHolds) === 1 ? '' : 's' ?>
          </span>
        </div>
      </div>

      <?php if (empty($incomingHolds)): ?>
        <div style="text-align: center; padding: 2.5rem 1rem; color: var(--text-muted);">
          <div style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.5rem;"><i class="fa-solid fa-circle-check"></i></div>
          <strong style="color: var(--text-heading); font-size: 1rem; display: block; margin-bottom: 0.25rem;">No Active Incoming Bed Holds</strong>
          <span>All reserved beds have either been admitted to wards or automatically released to network vacancy.</span>
        </div>
      <?php else: ?>
        <div class="holds-grid">
          <?php foreach ($incomingHolds as $hold): ?>
            <div class="hold-card" id="holdCard-<?= (int)$hold['reservation_id'] ?>">
              <div class="hold-card-top">
                <span class="hold-bed-badge">
                  <i class="fa-solid fa-bed"></i> Bed <?= htmlspecialchars($hold['bed_number']) ?>
                </span>
                <span class="hold-timer-chip" data-seconds="<?= (int)$hold['seconds_remaining'] ?>">
                  <i class="fa-regular fa-clock"></i>
                  <span class="timer-readout"><?= sprintf('%02d:%02d', floor($hold['seconds_remaining'] / 60), $hold['seconds_remaining'] % 60) ?></span>
                </span>
              </div>

              <div class="hold-pat-name"><?= htmlspecialchars($hold['patient_name']) ?></div>
              <div class="hold-pat-meta">
                <strong><?= htmlspecialchars($hold['patient_uid']) ?></strong> &bull; <?= htmlspecialchars($hold['gender'] ?? 'N/A') ?>, <?= (int)$hold['age'] ?> yrs &bull; Blood: <?= htmlspecialchars($hold['blood_group']) ?>
              </div>

              <div class="hold-details-box">
                <div class="hold-row">
                  <span class="hold-row-key">Ward Assignment:</span>
                  <span class="hold-row-val"><?= htmlspecialchars($hold['ward_type']) ?> (Floor <?= (int)$hold['floor_number'] ?>)</span>
                </div>
                <div class="hold-row">
                  <span class="hold-row-key">Daily Ward Rate:</span>
                  <span class="hold-row-val" style="color: #0d9488;">৳<?= number_format((float)$hold['daily_rate'], 2) ?>/day</span>
                </div>
                <div class="hold-row">
                  <span class="hold-row-key">Patient Phone:</span>
                  <span class="hold-row-val"><?= htmlspecialchars($hold['patient_phone']) ?></span>
                </div>
              </div>

              <button type="button" 
                      class="btn-accept-admission"
                      data-reservation-id="<?= (int)$hold['reservation_id'] ?>"
                      data-bed-id="<?= (int)$hold['bed_id'] ?>"
                      data-bed-number="<?= htmlspecialchars($hold['bed_number'], ENT_QUOTES, 'UTF-8') ?>"
                      data-ward-type="<?= htmlspecialchars($hold['ward_type'], ENT_QUOTES, 'UTF-8') ?>"
                      data-daily-rate="<?= htmlspecialchars((string)$hold['daily_rate'], ENT_QUOTES, 'UTF-8') ?>"
                      data-patient-id="<?= (int)$hold['patient_id'] ?>"
                      data-patient-uid="<?= htmlspecialchars($hold['patient_uid'], ENT_QUOTES, 'UTF-8') ?>"
                      data-patient-name="<?= htmlspecialchars($hold['patient_name'], ENT_QUOTES, 'UTF-8') ?>"
                      data-patient-age="<?= (int)$hold['age'] ?>"
                      data-patient-gender="<?= htmlspecialchars($hold['gender'] ?? 'Male', ENT_QUOTES, 'UTF-8') ?>"
                      data-patient-phone="<?= htmlspecialchars($hold['patient_phone'], ENT_QUOTES, 'UTF-8') ?>"
                      onclick="openAdmissionModalFromHold(this)">
                <i class="fa-solid fa-file-signature"></i> [ Accept &amp; Process Admission ]
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Section 2: Live Inpatient Ward Registry (Active Facility Admissions) -->
    <div class="panel-box">
      <div class="panel-header-flex">
        <div>
          <h2 class="panel-title">
            <i class="fa-solid fa-hospital-user" style="color: var(--brand-teal);"></i>
            Live Inpatient Ward Registry (Current Facility Inpatients)
          </h2>
          <p style="font-size: 0.82rem; color: var(--text-muted); margin-top: 2px;">
            Synchronized registry across Staff Desk, Branch Admin Inpatient Registry, and Super Admin Telemetry.
          </p>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="badge-inpatient-live">
            <span class="dot-inpatient"></span>
            <?= count($activeAdmissions) ?> Active Inpatient<?= count($activeAdmissions) === 1 ? '' : 's' ?>
          </span>
        </div>
      </div>

      <?php if (empty($activeAdmissions)): ?>
        <div style="text-align: center; padding: 2.5rem 1rem; color: var(--text-muted);">
          No patients are currently admitted in the inpatient ward.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Bed Assignment</th>
                <th>Patient Details</th>
                <th>Admitting Staff</th>
                <th>Attending Consultant</th>
                <th>Clinical Diagnosis &amp; Acuity</th>
                <th>Billing &amp; Deposit</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($activeAdmissions as $adm): ?>
                <?php
                  $acuity = strtolower($adm['triage_acuity'] ?? 'routine');
                  $acuityClass = 'acuity-routine';
                  if ($acuity === 'critical') $acuityClass = 'acuity-critical';
                  elseif ($acuity === 'post-op') $acuityClass = 'acuity-postop';
                ?>
                <tr>
                  <td>
                    <span class="badge-bed">
                      <i class="fa-solid fa-bed"></i> <?= htmlspecialchars($adm['bed_number']) ?>
                    </span>
                    <div style="font-size: 0.76rem; color: var(--text-muted); margin-top: 2px;">
                      <?= htmlspecialchars($adm['ward_type']) ?> &bull; Fl <?= (int)$adm['floor_number'] ?>
                    </div>
                  </td>
                  <td>
                    <strong style="color: var(--text-heading); font-size: 0.92rem;">
                      <?= htmlspecialchars($adm['patient_name']) ?>
                    </strong>
                    <div style="font-size: 0.76rem; color: var(--text-muted);">
                      UHID: <span style="font-family:'JetBrains Mono',monospace; font-weight:700; color:#0284c7;"><?= htmlspecialchars($adm['patient_uid']) ?></span>
                      &bull; <?= htmlspecialchars($adm['gender']) ?>, <?= (int)$adm['age'] ?>y
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 700; color: var(--text-heading);">
                      <?= htmlspecialchars($adm['staff_name'] ?? $staffName) ?>
                    </div>
                    <div style="font-size: 0.74rem; color: #0d9488; font-weight: 600;">
                      <?= htmlspecialchars($adm['staff_role'] ?? $staffDesignation) ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 700; color: var(--text-heading);">
                      Dr. <?= htmlspecialchars($adm['doctor_name'] ?? 'Assigned Duty Doctor') ?>
                    </div>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">
                      <?= htmlspecialchars($adm['doctor_specialty'] ?? 'Clinical Specialist') ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 700; color: var(--text-heading); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($adm['primary_diagnosis'] ?: $adm['admission_reason']) ?>">
                      <?= htmlspecialchars($adm['primary_diagnosis'] ?: $adm['admission_reason']) ?>
                    </div>
                    <div style="margin-top: 3px;">
                      <span class="badge-acuity <?= $acuityClass ?>"><?= htmlspecialchars($adm['triage_acuity']) ?></span>
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 800; color: #0d9488;">
                      ৳<?= number_format((float)$adm['deposit_amount'], 2) ?>
                    </div>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">
                      <?= htmlspecialchars($adm['payment_method']) ?> &bull; ৳<?= number_format((float)$adm['daily_rate'], 2) ?>/day
                    </div>
                  </td>
                  <td>
                    <span class="badge-inpatient-live">
                      <span class="dot-inpatient"></span> Inpatient
                    </span>
                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">
                      <?= date('d M, h:i A', strtotime($adm['admitted_at'])) ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── Inpatient Admission Dossier Modal ───────────────────────────────────── -->
  <div class="modal-overlay" id="admissionModal">
    <div class="modal-card">
      <div class="modal-head">
        <div>
          <h3><i class="fa-solid fa-hospital-user"></i> Hospital Bed Admission Dossier</h3>
          <div style="font-size: 0.76rem; color: #94a3b8;">Complete patient clinical intake &amp; confirm room occupancy</div>
        </div>
        <button type="button" class="btn-modal-close" onclick="closeAdmissionModal()">&times;</button>
      </div>

      <form id="admissionForm" method="POST" action="dashboard.php">
        <input type="hidden" name="action" value="process_admission">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="reservation_id" id="modalReservationId" value="">
        <input type="hidden" name="bed_id" id="modalBedId" value="">
        <input type="hidden" name="patient_id" id="modalPatientId" value="">

        <div class="modal-body">
          
          <!-- 1. Admitting Staff Metadata -->
          <div class="form-panel" style="border-left: 3px solid #0284c7;">
            <div class="form-panel-title">
              <i class="fa-solid fa-id-badge"></i> 1. Admitting Staff Metadata (Officer On Duty)
            </div>
            <div class="staff-meta-strip">
              <div class="staff-pill-box">
                <div class="spb-label">Staff Member Name</div>
                <div class="spb-val"><?= htmlspecialchars($staffName) ?></div>
              </div>
              <div class="staff-pill-box">
                <div class="spb-label">Official Designation</div>
                <div class="spb-val" style="color: #0d9488;"><?= htmlspecialchars($staffDesignation) ?></div>
              </div>
              <div class="staff-pill-box">
                <div class="spb-label">Staff Identity #</div>
                <div class="spb-val" style="font-family:'JetBrains Mono',monospace;">STF-<?= str_pad((string)$staffId, 3, '0', STR_PAD_LEFT) ?></div>
              </div>
            </div>
          </div>

          <!-- 2. Patient & Guardian Details -->
          <div class="form-panel">
            <div class="form-panel-title">
              <i class="fa-solid fa-user-injured"></i> 2. Patient &amp; Emergency Guardian Details
            </div>
            <div class="form-grid-3">
              <div class="form-group">
                <label>Patient UHID</label>
                <input type="text" class="form-control" id="modalPatientUid" readonly>
              </div>
              <div class="form-group">
                <label>Patient Full Name</label>
                <input type="text" class="form-control" id="modalPatientName" readonly>
              </div>
              <div class="form-group">
                <label>Demographics</label>
                <input type="text" class="form-control" id="modalPatientDemo" readonly>
              </div>
            </div>

            <div class="form-grid-3" style="margin-top: 0.75rem;">
              <div class="form-group">
                <label>Emergency Contact / Guardian Name *</label>
                <input type="text" class="form-control" name="guardian_name" id="modalGuardianName" placeholder="Full name of next of kin" required>
              </div>
              <div class="form-group">
                <label>Relation to Patient *</label>
                <select class="form-control" name="guardian_relation" id="modalGuardianRelation" required>
                  <option value="Spouse">Spouse</option>
                  <option value="Parent">Parent / Mother / Father</option>
                  <option value="Child">Son / Daughter</option>
                  <option value="Sibling">Brother / Sister</option>
                  <option value="Guardian">Legal Guardian</option>
                  <option value="Other">Other Kin / Relative</option>
                </select>
              </div>
              <div class="form-group">
                <label>Guardian Contact Phone *</label>
                <input type="tel" class="form-control" name="guardian_phone" id="modalGuardianPhone" placeholder="017XXXXXXXX" required>
              </div>
            </div>
          </div>

          <!-- 3. Clinical Allocation & Acuity -->
          <div class="form-panel">
            <div class="form-panel-title">
              <i class="fa-solid fa-stethoscope"></i> 3. Clinical Allocation &amp; Triage Acuity
            </div>
            <div class="form-grid-2">
              <div class="form-group">
                <label>Attending Physician / Consultant *</label>
                <select class="form-control" name="attending_doctor_id" id="modalAttendingDoctor" required>
                  <option value="">-- Select Active Doctor from Registry --</option>
                  <?php foreach ($activeDoctors as $doc): ?>
                    <option value="<?= (int)$doc['user_id'] ?>">
                      Dr. <?= htmlspecialchars($doc['full_name']) ?> (<?= htmlspecialchars($doc['specialty']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Triage Acuity Level *</label>
                <select class="form-control" name="triage_acuity" id="modalTriageAcuity" required>
                  <option value="Routine">Routine (Stable Inpatient Care)</option>
                  <option value="Critical">Critical (High Dependency / ICU Priority)</option>
                  <option value="Post-Op">Post-Op (Surgical Recovery Care)</option>
                </select>
              </div>
            </div>

            <div class="form-group" style="margin-top: 0.75rem;">
              <label>Admission Reason / Primary Diagnosis *</label>
              <textarea class="form-control" name="primary_diagnosis" id="modalDiagnosis" rows="2" placeholder="e.g. Acute exacerbation of COPD, post-appendectomy observation, severe dehydration..." required></textarea>
            </div>
          </div>

          <!-- 4. Room/Bed Assignment & Billing Reference -->
          <div class="form-panel">
            <div class="form-panel-title">
              <i class="fa-solid fa-receipt"></i> 4. Room/Bed Assignment &amp; Billing Reference
            </div>
            <div class="form-grid-3">
              <div class="form-group">
                <label>Assigned Room / Bed #</label>
                <input type="text" class="form-control" id="modalBedDisplay" readonly>
              </div>
              <div class="form-group">
                <label>Daily Ward Rate (৳)</label>
                <input type="number" step="0.01" class="form-control" name="daily_rate" id="modalDailyRate" required>
              </div>
              <div class="form-group">
                <label>Initial Admission Deposit (৳) *</label>
                <input type="number" step="0.01" class="form-control" name="deposit_amount" id="modalDeposit" value="5000.00" required>
              </div>
            </div>

            <div class="form-grid-2" style="margin-top: 0.75rem;">
              <div class="form-group">
                <label>Deposit Payment Method *</label>
                <select class="form-control" name="payment_method" id="modalPaymentMethod" required>
                  <option value="Cash">Cash (Hospital Reception)</option>
                  <option value="Card">Credit / Debit POS Card</option>
                  <option value="MFS">Mobile Financial Service (bKash / Nagad)</option>
                </select>
              </div>
              <div class="form-group">
                <label>Payment Reference / Transaction ID</label>
                <input type="text" class="form-control" name="payment_reference" placeholder="e.g., POS-994821 or TXN-BKASH-84">
              </div>
            </div>
          </div>

        </div>

        <div class="modal-foot">
          <button type="button" class="btn-secondary" onclick="closeAdmissionModal()">Cancel</button>
          <button type="submit" class="btn-confirm-admission" id="btnSubmitAdmission">
            <i class="fa-solid fa-check-circle"></i> Confirm Inpatient Admission
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // ── Live Countdown Timers for Incoming Bed Holds ──────────────────────────
    function initHoldTimers() {
      const chips = document.querySelectorAll('.hold-timer-chip');
      chips.forEach(chip => {
        let secs = parseInt(chip.getAttribute('data-seconds'), 10) || 0;
        const readout = chip.querySelector('.timer-readout');

        const interval = setInterval(() => {
          if (secs <= 0) {
            clearInterval(interval);
            chip.textContent = 'Hold Expired';
            chip.style.background = '#fef2f2';
            chip.style.color = '#ef4444';
            setTimeout(() => window.location.reload(), 2000);
            return;
          }
          secs--;
          chip.setAttribute('data-seconds', secs);
          const mins = Math.floor(secs / 60);
          const rem = secs % 60;
          if (readout) {
            readout.textContent = `${String(mins).padStart(2, '0')}:${String(rem).padStart(2, '0')}`;
          }
        }, 1000);
      });
    }
    initHoldTimers();

    // ── Admission Modal Controls & Pre-fill ──────────────────────────────────
    function openAdmissionModalFromHold(button) {
      const reservationId = button.getAttribute('data-reservation-id') || '';
      const bedId         = button.getAttribute('data-bed-id') || '';
      const bedNumber     = button.getAttribute('data-bed-number') || '';
      const wardType      = button.getAttribute('data-ward-type') || '';
      const dailyRate     = button.getAttribute('data-daily-rate') || '1500';
      const patientId     = button.getAttribute('data-patient-id') || '';
      const patientUid    = button.getAttribute('data-patient-uid') || '';
      const patientName   = button.getAttribute('data-patient-name') || '';
      const patientAge    = button.getAttribute('data-patient-age') || '';
      const patientGender = button.getAttribute('data-patient-gender') || '';
      const patientPhone  = button.getAttribute('data-patient-phone') || '';

      document.getElementById('modalReservationId').value = reservationId;
      document.getElementById('modalBedId').value         = bedId;
      document.getElementById('modalPatientId').value     = patientId;

      document.getElementById('modalPatientUid').value  = patientUid;
      document.getElementById('modalPatientName').value = patientName;
      document.getElementById('modalPatientDemo').value = `${patientGender}, ${patientAge} yrs`;

      document.getElementById('modalBedDisplay').value  = `${bedNumber} (${wardType})`;
      document.getElementById('modalDailyRate').value   = dailyRate;

      // Default emergency contact to patient phone if empty
      const gPhone = document.getElementById('modalGuardianPhone');
      if (gPhone && !gPhone.value) {
        gPhone.value = patientPhone;
      }

      document.getElementById('admissionModal').classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeAdmissionModal() {
      document.getElementById('admissionModal').classList.remove('active');
      document.body.style.overflow = '';
    }

    // Close on escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeAdmissionModal();
    });

    // ── AJAX Admission Submission & Instant Feedback ──────────────────────────
    const form = document.getElementById('admissionForm');
    const submitBtn = document.getElementById('btnSubmitAdmission');

    if (form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing Transaction...';

        const formData = new FormData(form);
        formData.append('ajax', '1');

        fetch('dashboard.php', {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            closeAdmissionModal();
            Swal.fire({
              icon: 'success',
              title: 'Inpatient Admitted Successfully!',
              html: `
                <div style="font-size:0.92rem; text-align:left; background:#f8fafc; padding:12px; border-radius:10px; border:1px solid #e2e8f0;">
                  <div><strong>Dossier #:</strong> <span style="font-family:'JetBrains Mono',monospace; color:#0284c7;">${data.admission_number}</span></div>
                  <div><strong>Patient:</strong> ${data.patient_name} (${data.patient_uid})</div>
                  <div><strong>Bed Assignment:</strong> Bed ${data.bed_number} (${data.ward_type})</div>
                  <div><strong>Acuity:</strong> ${data.triage_acuity}</div>
                  <div style="margin-top:6px; color:#10b981; font-weight:700;">Synced across Staff Desk, Branch Registry &amp; Super Admin Telemetry.</div>
                </div>
              `,
              confirmButtonText: 'View Updated Inpatient Ledger',
              confirmButtonColor: '#0284c7'
            }).then(() => {
              window.location.reload();
            });
          } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Confirm Inpatient Admission';
            Swal.fire({
              icon: 'error',
              title: 'Admission Failed',
              text: data.message || 'Unable to complete transaction.',
              confirmButtonColor: '#0284c7'
            });
          }
        })
        .catch(err => {
          submitBtn.disabled = false;
          submitBtn.innerHTML = '<i class="fa-solid fa-check-circle"></i> Confirm Inpatient Admission';
          Swal.fire({
            icon: 'error',
            title: 'System Error',
            text: 'A network communication error occurred.',
            confirmButtonColor: '#0284c7'
          });
        });
      });
    }

    // Client-Side History Guard: Kill BFCache and re-verify session on back-navigation
    window.addEventListener("pageshow", function(event) {
      if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
        window.location.reload();
      }
    });
  </script>
</body>
</html>

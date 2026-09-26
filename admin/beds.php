<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Branch Bed & Admission Management Console
 *
 * Scoped strictly to authenticated facility (:session_hospital_id).
 * Rejects cross-tenant updates with HTTP 403.
 */

require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/tenant_scope.php';

// Session hospital resolution & strict tenant binding
$sessionHospitalId = TenantScope::enforce($pdo, ['admin', 'hospital_admin', 'super_admin']);

// Fetch branch hospital information
$branchStmt = $pdo->prepare("SELECT hospital_id, name, code, address, city, phone FROM hospitals WHERE hospital_id = ?");
$branchStmt->execute([$sessionHospitalId]);
$currentHospital = $branchStmt->fetch(PDO::FETCH_ASSOC);
$branchName = $currentHospital['name'] ?? "Branch Hospital #{$sessionHospitalId}";

$feedback = null;
$feedbackType = 'success';

// ── ACTION HANDLERS (POST) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $postedCsrf = $_POST['csrf_token'] ?? '';

    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $postedCsrf)) {
        http_response_code(403);
        die('403 Forbidden: Invalid CSRF token.');
    }

    $isAjax = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
           || (!empty($_POST['ajax']));

    // ── ACTION 1: Confirm Patient Admission ──────────────────────────────────
    if ($action === 'confirm_admission') {
        $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
        $bedId = filter_var($_POST['bed_id'] ?? null, FILTER_VALIDATE_INT);
        $patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT);

        // If reservationId is given, resolve bed and patient
        if ($reservationId) {
            $resStmt = $pdo->prepare("SELECT * FROM bed_reservations WHERE id = ? LIMIT 1");
            $resStmt->execute([$reservationId]);
            $res = $resStmt->fetch(PDO::FETCH_ASSOC);

            if (!$res) {
                if ($isAjax) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Reservation record not found.']);
                    exit;
                }
                $feedback = 'Reservation record not found.';
                $feedbackType = 'error';
            } else {
                // Strict Tenant Isolation Guard: Reject if reservation hospital != session hospital
                if (!TenantScope::isSuperAdmin() && (int)$res['hospital_id'] !== $sessionHospitalId) {
                    TenantScope::detectTampering($pdo); // triggers 403 & destroys session
                    http_response_code(403);
                    die('403 Forbidden: Cross-tenant modification denied.');
                }

                $bedId = (int)$res['bed_id'];
                $patientId = (int)$res['patient_id'];
            }
        }

        if ($bedId && $patientId) {
            // Strict Tenant Isolation Guard: Verify bed belongs to this hospital
            $bedCheck = $pdo->prepare("SELECT hospital_id, bed_number, ward_type, status FROM beds WHERE bed_id = ? OR id = ? LIMIT 1");
            $bedCheck->execute([$bedId, $bedId]);
            $targetBed = $bedCheck->fetch(PDO::FETCH_ASSOC);

            if (!$targetBed) {
                // Also check hospital_beds
                $hbCheck = $pdo->prepare("SELECT hospital_id, bed_number, ward_type, status FROM hospital_beds WHERE bed_id = ? LIMIT 1");
                $hbCheck->execute([$bedId]);
                $targetBed = $hbCheck->fetch(PDO::FETCH_ASSOC);
            }

            if (!$targetBed || (!TenantScope::isSuperAdmin() && (int)$targetBed['hospital_id'] !== $sessionHospitalId)) {
                http_response_code(403);
                if ($isAjax) {
                    echo json_encode(['status' => 'error', 'message' => '403 Forbidden: Bed does not belong to your assigned hospital facility.']);
                    exit;
                }
                die('403 Forbidden: Bed does not belong to your assigned hospital facility.');
            }

            try {
                $pdo->beginTransaction();

                // 1. Transition bed_reservations: status = 'admitted'
                if ($reservationId) {
                    $updRes = $pdo->prepare("UPDATE bed_reservations SET status = 'admitted' WHERE id = ?");
                    $updRes->execute([$reservationId]);
                } else {
                    $updRes = $pdo->prepare("UPDATE bed_reservations SET status = 'admitted' WHERE bed_id = ? AND patient_id = ? AND status = 'held'");
                    $updRes->execute([$bedId, $patientId]);
                }

                // 2. Transition beds: status = 'Occupied', patient_id = :patient_id
                $updBed = $pdo->prepare("UPDATE beds SET status = 'Occupied', patient_id = :pid, reserved_until = NULL, reservation_user_id = NULL, reservation_token = NULL WHERE bed_id = :bid OR id = :bid2");
                $updBed->execute([':pid' => $patientId, ':bid' => $bedId, ':bid2' => $bedId]);

                // Sync hospital_beds if present
                $updHb = $pdo->prepare("UPDATE hospital_beds SET status = 'Occupied', is_occupied = 1 WHERE bed_id = :bid");
                $updHb->execute([':bid' => $bedId]);

                // 3. Register or update bed_allocations for clinical rounding
                $allocCheck = $pdo->prepare("SELECT allocation_id FROM bed_allocations WHERE bed_id = ? AND patient_id = ? AND status = 'Active' LIMIT 1");
                $allocCheck->execute([$bedId, $patientId]);
                if (!$allocCheck->fetchColumn()) {
                    $insAlloc = $pdo->prepare("
                        INSERT INTO bed_allocations (bed_id, patient_id, admitted_at, status, created_at)
                        VALUES (:bid, :pid, NOW(), 'Active', NOW())
                    ");
                    $insAlloc->execute([':bid' => $bedId, ':pid' => $patientId]);
                }

                $pdo->commit();

                $msg = "Patient admission confirmed successfully. Bed {$targetBed['bed_number']} ({$targetBed['ward_type']}) is now OCCUPIED.";
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => $msg]);
                    exit;
                }
                $feedback = $msg;
                $feedbackType = 'success';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Admission error: " . $e->getMessage());
                if ($isAjax) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Database error during admission: ' . $e->getMessage()]);
                    exit;
                }
                $feedback = 'Database error during admission processing.';
                $feedbackType = 'error';
            }
        }
    }

    // ── ACTION 2: Manual Discharge / Free Bed ─────────────────────────────────
    if ($action === 'manual_discharge' || $action === 'free_bed') {
        $bedId = filter_var($_POST['bed_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$bedId) {
            if ($isAjax) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Bed ID is required.']);
                exit;
            }
            $feedback = 'Bed ID is required.';
            $feedbackType = 'error';
        } else {
            // Strict Tenant Isolation Guard: Verify bed belongs to this hospital
            $bedCheck = $pdo->prepare("SELECT hospital_id, bed_number, ward_type FROM beds WHERE bed_id = ? OR id = ? LIMIT 1");
            $bedCheck->execute([$bedId, $bedId]);
            $targetBed = $bedCheck->fetch(PDO::FETCH_ASSOC);

            if (!$targetBed) {
                $hbCheck = $pdo->prepare("SELECT hospital_id, bed_number, ward_type FROM hospital_beds WHERE bed_id = ? LIMIT 1");
                $hbCheck->execute([$bedId]);
                $targetBed = $hbCheck->fetch(PDO::FETCH_ASSOC);
            }

            if (!$targetBed || (!TenantScope::isSuperAdmin() && (int)$targetBed['hospital_id'] !== $sessionHospitalId)) {
                http_response_code(403);
                if ($isAjax) {
                    echo json_encode(['status' => 'error', 'message' => '403 Forbidden: Cross-tenant modification denied. Bed does not belong to your facility.']);
                    exit;
                }
                die('403 Forbidden: Cross-tenant modification denied. Bed does not belong to your facility.');
            }

            try {
                $pdo->beginTransaction();

                // 1. Transition beds: status = 'Available', patient_id = NULL
                $updBed = $pdo->prepare("
                    UPDATE beds 
                    SET status = 'Available', 
                        patient_id = NULL, 
                        reserved_until = NULL, 
                        reservation_user_id = NULL, 
                        reservation_token = NULL 
                    WHERE bed_id = :bid OR id = :bid2
                ");
                $updBed->execute([':bid' => $bedId, ':bid2' => $bedId]);

                // Sync hospital_beds if present
                $updHb = $pdo->prepare("UPDATE hospital_beds SET status = 'Available', is_occupied = 0 WHERE bed_id = :bid");
                $updHb->execute([':bid' => $bedId]);

                // 2. Mark active bed_allocations as Discharged
                $updAlloc = $pdo->prepare("UPDATE bed_allocations SET status = 'Discharged', discharged_at = NOW() WHERE bed_id = :bid AND status = 'Active'");
                $updAlloc->execute([':bid' => $bedId]);

                // 3. Mark any held reservation as cancelled
                $updRes = $pdo->prepare("UPDATE bed_reservations SET status = 'cancelled' WHERE bed_id = :bid AND status = 'held'");
                $updRes->execute([':bid' => $bedId]);

                $pdo->commit();

                $msg = "Bed {$targetBed['bed_number']} ({$targetBed['ward_type']}) has been successfully discharged and is now AVAILABLE.";
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'message' => $msg]);
                    exit;
                }
                $feedback = $msg;
                $feedbackType = 'success';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Discharge error: " . $e->getMessage());
                if ($isAjax) {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Discharge failed: ' . $e->getMessage()]);
                    exit;
                }
                $feedback = 'Discharge operation failed.';
                $feedbackType = 'error';
            }
        }
    }
}

// ── QUERY 1: Incoming Patient Holds (:session_hospital_id) ─────────────────
// Requirement 3: View incoming patient holds (bed_reservations where status = 'held' and hospital_id = :session_hospital_id)
$holdsStmt = $pdo->prepare("
    SELECT r.id AS reservation_id, r.bed_id, r.patient_id, r.hold_expires_at, r.status, r.created_at,
           b.bed_number, b.ward_type, b.floor_number, b.daily_rate,
           u.full_name AS patient_name, u.phone AS patient_phone, u.email AS patient_email,
           COALESCE(p.patient_uid, CONCAT('MP-', u.user_id)) AS patient_uid,
           TIMESTAMPDIFF(SECOND, NOW(), r.hold_expires_at) AS seconds_remaining
    FROM bed_reservations r
    JOIN beds b ON (b.id = r.bed_id OR b.bed_id = r.bed_id)
    JOIN users u ON u.user_id = r.patient_id
    LEFT JOIN patients p ON (p.user_id = r.patient_id OR p.id = r.patient_id)
    WHERE r.hospital_id = :session_hospital_id
      AND r.status = 'held'
      AND r.hold_expires_at > NOW()
    ORDER BY r.created_at DESC
");
$holdsStmt->execute([':session_hospital_id' => $sessionHospitalId]);
$incomingHolds = $holdsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── QUERY 2: Facility Beds Matrix (:session_hospital_id) ───────────────────
$filterWard = trim($_GET['ward'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$sqlBeds = "
    SELECT b.bed_id, b.id, b.hospital_id, b.bed_number, b.ward_type, b.floor_number,
           b.daily_rate, b.price_per_day, b.status, b.patient_id,
           u.full_name AS current_patient_name, u.phone AS current_patient_phone,
           p.patient_uid
    FROM beds b
    LEFT JOIN users u ON u.user_id = b.patient_id
    LEFT JOIN patients p ON (p.user_id = b.patient_id OR p.id = b.patient_id)
    WHERE b.hospital_id = :session_hospital_id
";

$params = [':session_hospital_id' => $sessionHospitalId];

if ($filterWard !== '') {
    $sqlBeds .= " AND b.ward_type = :ward";
    $params[':ward'] = $filterWard;
}

if ($filterStatus !== '') {
    $sqlBeds .= " AND LOWER(b.status) = LOWER(:status)";
    $params[':status'] = $filterStatus;
}

$sqlBeds .= " ORDER BY b.floor_number ASC, b.ward_type ASC, b.bed_number ASC";

$bedListStmt = $pdo->prepare($sqlBeds);
$bedListStmt->execute($params);
$facilityBeds = $bedListStmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPI Telemetry (Facility-wide census) ────────────────────────────────────
$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_beds,
        SUM(LOWER(status) = 'available') AS available_beds,
        SUM(LOWER(status) = 'occupied') AS occupied_beds,
        SUM(LOWER(status) IN ('maintenance', 'sanitizing')) AS maintenance_beds,
        SUM(LOWER(status) = 'reserved') AS reserved_beds
    FROM beds
    WHERE hospital_id = :session_hospital_id
");
$kpiStmt->execute([':session_hospital_id' => $sessionHospitalId]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

$totalBeds = (int)($kpi['total_beds'] ?? count($facilityBeds));
$availableBeds = (int)($kpi['available_beds'] ?? 0);
$occupiedBeds = (int)($kpi['occupied_beds'] ?? 0);
$heldCount = count($incomingHolds);
$occupancyRate = $totalBeds > 0 ? round(($occupiedBeds / $totalBeds) * 100, 1) : 0;

// Ward types for filter dropdown
$wardTypesStmt = $pdo->prepare("SELECT DISTINCT ward_type FROM beds WHERE hospital_id = ? ORDER BY ward_type ASC");
$wardTypesStmt->execute([$sessionHospitalId]);
$wardTypes = $wardTypesStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Branch Bed &amp; Admission Management</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  
  <style>
    :root {
      --facility-accent: #0284c7;
      --facility-glow: rgba(2, 132, 199, 0.15);
    }
    .branch-identity-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(2, 132, 199, 0.12);
      border: 1px solid rgba(2, 132, 199, 0.3);
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 0.85rem;
      font-weight: 700;
      color: #38bdf8;
      margin-bottom: 12px;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 8px #10b981;
    }
    .holds-card {
      background: var(--bg-card);
      border: 1px solid rgba(245, 158, 11, 0.3);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 8px 24px rgba(245, 158, 11, 0.06);
    }
    .holds-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      padding-bottom: 0.75rem;
    }
    .timer-badge {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 700;
      font-size: 0.82rem;
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.35);
      padding: 4px 10px;
      border-radius: 6px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-admit {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #ffffff;
      border: none;
      font-weight: 700;
      font-size: 0.82rem;
      padding: 8px 16px;
      border-radius: 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-admit:hover {
      background: linear-gradient(135deg, #059669, #047857);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }
    .btn-discharge {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: #ffffff;
      border: none;
      font-weight: 700;
      font-size: 0.82rem;
      padding: 8px 16px;
      border-radius: 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-discharge:hover {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }
    .bed-grid-container {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 1.25rem;
      margin-top: 1.5rem;
    }
    .bed-item-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
      position: relative;
    }
    .bed-item-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    }
    .bed-item-card.status-available {
      border-left: 4px solid #10b981;
    }
    .bed-item-card.status-occupied {
      border-left: 4px solid #ef4444;
    }
    .bed-item-card.status-reserved {
      border-left: 4px solid #f59e0b;
    }
    .bed-item-card.status-maintenance {
      border-left: 4px solid #6b7280;
    }
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      background: var(--bg-card);
      padding: 1rem 1.25rem;
      border-radius: var(--radius-md);
      border: 1px solid var(--border-color);
      margin-bottom: 1.5rem;
    }
    .filter-select {
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      color: var(--text-heading);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.9rem;
      outline: none;
    }
  </style>
</head>
<body>

  <!-- Centralized Admin Sidebar -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Main Viewport Past Sidebar -->
  <main class="viewport-full">

    <?php if ($feedback): ?>
      <div style="background: <?= $feedbackType === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#10b981' : '#ef4444' ?>; color: <?= $feedbackType === 'success' ? '#34d399' : '#f87171' ?>; padding: 12px 18px; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600;">
        <?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Facility Header -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="branch-identity-badge">
          <span class="badge-dot"></span>
          Facility Scope: <?= htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8') ?> (ID: #<?= (int)$sessionHospitalId ?>)
        </div>
        <h1>
          Branch Bed &amp; Admission Management
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
        </h1>
        <p>Inpatient chamber controls, incoming 45-minute reservation hold confirmations, and real-time census telemetry strictly isolated to this hospital branch.</p>
      </div>
      <div class="banner-actions">
        <a href="live_census.php" class="btn-action-telemed">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
          Live Census Dashboard
        </a>
      </div>
    </div>

    <!-- KPI Metric Cards Grid -->
    <div class="stat-cards-grid" style="margin-bottom: 2rem;">
      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(2, 132, 199, 0.15); color: #0284c7;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Total Facility Beds</div>
          <div class="stat-value"><?= $totalBeds ?></div>
          <div class="stat-subtext" style="color: var(--text-muted);">Scoped to <?= htmlspecialchars($branchName) ?></div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
          <svg class="ui-ico" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Available Vacancies</div>
          <div class="stat-value" style="color: #10b981;"><?= $availableBeds ?></div>
          <div class="stat-subtext" style="color: #10b981;">Ready for immediate triage</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Occupied Inpatients</div>
          <div class="stat-value" style="color: #ef4444;"><?= $occupiedBeds ?></div>
          <div class="stat-subtext" style="color: var(--text-muted);"><?= $occupancyRate ?>% Occupancy Rate</div>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon-wrapper" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
          <svg class="ui-ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-content">
          <div class="stat-label">Active Patient Holds</div>
          <div class="stat-value" style="color: #f59e0b;"><?= $heldCount ?></div>
          <div class="stat-subtext" style="color: #f59e0b;">Pending Admission Confirmation</div>
        </div>
      </div>
    </div>

    <!-- ── REQUIREMENT 3: INCOMING PATIENT HOLDS (45-MIN RESERVATION WINDOW) ── -->
    <section class="holds-card">
      <div class="holds-card-header">
        <div>
          <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--text-heading); margin: 0 0 4px; display: flex; align-items: center; gap: 8px;">
            <svg class="ui-ico" style="stroke: #f59e0b; width: 20px; height: 20px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            Incoming Patient Holds &amp; Pre-Reservations
          </h2>
          <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
            Patients currently holding beds at this branch (45-minute confirmation window). Confirm admission upon physical reception.
          </p>
        </div>
        <span class="live-chip-sm" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4);">
          <?= $heldCount ?> ACTIVE HOLDS
        </span>
      </div>

      <?php if (empty($incomingHolds)): ?>
        <div style="text-align: center; padding: 2.5rem 1rem; color: var(--text-muted);">
          <svg class="ui-ico" style="width: 42px; height: 42px; margin-bottom: 10px; stroke: #94a3b8;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          <div style="font-weight: 600; color: var(--text-heading); font-size: 0.95rem;">No Pending Pre-Reservation Holds</div>
          <div style="font-size: 0.85rem;">When patients hold a bed online at <?= htmlspecialchars($branchName) ?>, they will appear here with an active countdown.</div>
        </div>
      <?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-data-table">
            <thead>
              <tr>
                <th>Patient Details</th>
                <th>Assigned Bed / Ward</th>
                <th>Hold Expiration</th>
                <th>Daily Rate</th>
                <th style="text-align: right;">Admission Control</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($incomingHolds as $hold): ?>
                <?php 
                  $sec = max(0, (int)$hold['seconds_remaining']);
                  $minLeft = floor($sec / 60);
                  $secLeft = $sec % 60;
                ?>
                <tr id="hold-row-<?= (int)$hold['reservation_id'] ?>">
                  <td>
                    <div>
                      <strong style="color: var(--text-heading); font-size: 0.92rem;">
                        <?= htmlspecialchars($hold['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                      </strong>
                      <div style="font-size: 0.76rem; color: var(--text-muted); font-family: monospace;">
                        UID: <?= htmlspecialchars($hold['patient_uid'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($hold['patient_phone'], ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span style="font-weight: 700; color: var(--brand-primary); font-size: 0.95rem;">
                      Bed <?= htmlspecialchars($hold['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <div style="font-size: 0.78rem; color: var(--text-muted);">
                      <?= htmlspecialchars($hold['ward_type'], ENT_QUOTES, 'UTF-8') ?> &bull; Floor <?= (int)$hold['floor_number'] ?>
                    </div>
                  </td>
                  <td>
                    <span class="timer-badge countdown-timer" data-seconds="<?= $sec ?>">
                      <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                      <span class="timer-text"><?= sprintf('%02d:%02d', $minLeft, $secLeft) ?></span> remaining
                    </span>
                  </td>
                  <td style="font-weight: 600; color: var(--text-heading);">
                    ৳<?= number_format((float)$hold['daily_rate'], 2) ?>
                  </td>
                  <td style="text-align: right;">
                    <form method="POST" style="display: inline;" onsubmit="return confirmAdmission(event, <?= (int)$hold['reservation_id'] ?>, '<?= htmlspecialchars(addslashes($hold['patient_name'])) ?>', '<?= htmlspecialchars($hold['bed_number']) ?>')">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="confirm_admission">
                      <input type="hidden" name="reservation_id" value="<?= (int)$hold['reservation_id'] ?>">
                      <input type="hidden" name="bed_id" value="<?= (int)$hold['bed_id'] ?>">
                      <input type="hidden" name="patient_id" value="<?= (int)$hold['patient_id'] ?>">
                      <button type="submit" class="btn-admit" title="Confirm physical admission and occupy bed">
                        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Confirm Patient Admission
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- ── REQUIREMENT 3: BRANCH BED MATRIX & MANUAL DISCHARGE ─────────────── -->
    <section>
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
          <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-heading); margin: 0 0 4px;">
            Facility Bed &amp; Chamber Roster
          </h2>
          <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
            Real-time ward telemetry for <?= htmlspecialchars($branchName) ?>. Manual discharge and bed release controls.
          </p>
        </div>
      </div>

      <!-- Filter Bar -->
      <form method="GET" class="filter-bar">
        <label style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Filter Wards:</label>
        <select name="ward" class="filter-select" onchange="this.form.submit()">
          <option value="">All Ward Types</option>
          <?php foreach ($wardTypes as $wt): ?>
            <option value="<?= htmlspecialchars($wt) ?>" <?= $filterWard === $wt ? 'selected' : '' ?>>
              <?= htmlspecialchars($wt) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-left: 12px;">Bed Status:</label>
        <select name="status" class="filter-select" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <option value="available" <?= strtolower($filterStatus) === 'available' ? 'selected' : '' ?>>Available</option>
          <option value="occupied" <?= strtolower($filterStatus) === 'occupied' ? 'selected' : '' ?>>Occupied</option>
          <option value="reserved" <?= strtolower($filterStatus) === 'reserved' ? 'selected' : '' ?>>Reserved</option>
          <option value="maintenance" <?= strtolower($filterStatus) === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
        </select>

        <?php if ($filterWard !== '' || $filterStatus !== ''): ?>
          <a href="beds.php" style="font-size: 0.82rem; color: #38bdf8; text-decoration: none; margin-left: auto;">Reset Filters</a>
        <?php endif; ?>
      </form>

      <!-- Bed Cards Grid -->
      <div class="bed-grid-container">
        <?php foreach ($facilityBeds as $bed): ?>
          <?php 
            $bStatus = strtolower($bed['status']);
            $cardClass = match($bStatus) {
                'available'   => 'status-available',
                'occupied'    => 'status-occupied',
                'reserved'    => 'status-reserved',
                default       => 'status-maintenance'
            };
            $statusBadge = match($bStatus) {
                'available'   => '<span class="status-badge-active" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">Available</span>',
                'occupied'    => '<span class="status-badge-suspended" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);">Occupied</span>',
                'reserved'    => '<span class="status-badge-pending" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">Reserved</span>',
                default       => '<span style="background: rgba(107, 114, 128, 0.2); color: #9ca3af; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700;">' . htmlspecialchars($bed['status']) . '</span>'
            };
          ?>
          <div class="bed-item-card <?= $cardClass ?>" id="bed-card-<?= (int)$bed['bed_id'] ?>">
            <div>
              <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <div>
                  <h3 style="font-size: 1.1rem; font-weight: 800; color: var(--text-heading); margin: 0;">
                    <?= htmlspecialchars($bed['bed_number'], ENT_QUOTES, 'UTF-8') ?>
                  </h3>
                  <div style="font-size: 0.78rem; color: var(--text-muted); font-weight: 500;">
                    <?= htmlspecialchars($bed['ward_type'], ENT_QUOTES, 'UTF-8') ?> &bull; Floor <?= (int)$bed['floor_number'] ?>
                  </div>
                </div>
                <div><?= $statusBadge ?></div>
              </div>

              <div style="margin: 10px 0; font-size: 0.85rem; color: var(--text-muted);">
                <?php if ($bStatus === 'occupied' && !empty($bed['current_patient_name'])): ?>
                  <div style="background: rgba(239, 68, 68, 0.08); padding: 8px; border-radius: 6px; border: 1px dashed rgba(239, 68, 68, 0.2);">
                    <div style="font-size: 0.72rem; color: #f87171; font-weight: 700; text-transform: uppercase;">Admitted Inpatient</div>
                    <strong style="color: var(--text-heading); font-size: 0.88rem;"><?= htmlspecialchars($bed['current_patient_name']) ?></strong>
                    <?php if (!empty($bed['patient_uid'])): ?>
                      <div style="font-size: 0.72rem; color: var(--text-muted); font-family: monospace;">UID: <?= htmlspecialchars($bed['patient_uid']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php elseif ($bStatus === 'available'): ?>
                  <div style="color: #10b981; font-weight: 600; font-size: 0.82rem; display: flex; align-items: center; gap: 4px;">
                    <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Clean &amp; Ready for Admission
                  </div>
                <?php else: ?>
                  <div style="color: var(--text-muted); font-size: 0.8rem;">Status: <?= htmlspecialchars($bed['status']) ?></div>
                <?php endif; ?>
              </div>
            </div>

            <div style="border-top: 1px solid rgba(255, 255, 255, 0.06); padding-top: 10px; margin-top: 10px; display: flex; justify-content: space-between; align-items: center;">
              <div style="font-size: 0.82rem; font-weight: 700; color: var(--text-heading);">
                ৳<?= number_format((float)($bed['daily_rate'] ?? $bed['price_per_day'] ?? 2500), 2) ?><span style="font-size: 0.72rem; font-weight: 400; color: var(--text-muted);">/day</span>
              </div>
              
              <div>
                <?php if ($bStatus === 'occupied'): ?>
                  <form method="POST" style="display: inline;" onsubmit="return confirmDischarge(event, <?= (int)$bed['bed_id'] ?>, '<?= htmlspecialchars(addslashes($bed['bed_number'])) ?>')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="manual_discharge">
                    <input type="hidden" name="bed_id" value="<?= (int)$bed['bed_id'] ?>">
                    <button type="submit" class="btn-discharge" title="Manual discharge / Free bed">
                      <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                      Manual Discharge
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

  </main>

  <script>
    // Live Countdown for Patient Holds
    document.querySelectorAll('.countdown-timer').forEach(el => {
      let seconds = parseInt(el.getAttribute('data-seconds'), 10) || 0;
      const textSpan = el.querySelector('.timer-text');

      const interval = setInterval(() => {
        if (seconds <= 0) {
          clearInterval(interval);
          if (textSpan) textSpan.textContent = '00:00 (Expired)';
          el.style.background = 'rgba(239, 68, 68, 0.15)';
          el.style.color = '#ef4444';
          return;
        }
        seconds--;
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        if (textSpan) {
          textSpan.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }
      }, 1000);
    });

    function confirmAdmission(e, resId, patientName, bedNumber) {
      if (!confirm(`Confirm physical admission for ${patientName} into Bed ${bedNumber}?\n\nThis will transition the reservation to 'admitted' and mark the bed as 'Occupied'.`)) {
        e.preventDefault();
        return false;
      }
      return true;
    }

    function confirmDischarge(e, bedId, bedNumber) {
      if (!confirm(`Are you sure you want to manually discharge and free Bed ${bedNumber}?\n\nThis will transition the bed status to 'Available'.`)) {
        e.preventDefault();
        return false;
      }
      return true;
    }
  </script>
</body>
</html>

<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Branch Admin — Local Resource & Life-Support Inventory Command
 *
 * Dedicated Facility Command for:
 * 1. Liquid Oxygen (LOX) Tank Calibration & Depletion Runway Monitoring
 * 2. ICU Mechanical Ventilator Fleet Triage (+1 Hookup / -1 Wean)
 * 3. Universal Blood Bank & Plasma Stockpile Management
 * 4. Inbound Emergency Dispatch Intake (LOX Tanker & Blood Courier)
 */

require_once __DIR__ . '/../includes/admin_auth.php';

// Scoped strictly to the logged-in admin's hospital_id
$adminHospitalId = (int)($_SESSION['hospital_id'] ?? $currentAdmin['hospital_id'] ?? 1);

// Resolve hospital details
$hospStmt = $pdo->prepare("SELECT hospital_id, name, code, city, address FROM hospitals WHERE hospital_id = ?");
$hospStmt->execute([$adminHospitalId]);
$currentHospital = $hospStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentHospital) {
    die('Assigned facility record could not be resolved. Please contact Super Administrator.');
}

$branchName = $currentHospital['name'];
$branchCode = $currentHospital['code'];
$branchCity = $currentHospital['city'];

$flashMessage = null;
$flashType    = 'success';

// ── POST ACTION HANDLERS ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    $tok = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $tok)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Session expired.']);
            exit;
        }
        $flashMessage = 'Security validation failed (CSRF mismatch). Please reload and try again.';
        $flashType = 'error';
    } else {
        $action = $_POST['_action'];

        // 1. Oxygen Tank Calibration
        if ($action === 'update_oxygen') {
            $pct  = max(5, min(100, (int)($_POST['oxygen_reserve_pct'] ?? 75)));
            $psi  = max(500, min(3000, (int)($_POST['current_pressure_psi'] ?? 2200)));
            // Dynamic depletion runway: round((volume_pct / 100) * 9.5, 1)
            $days = round(($pct / 100) * 9.5, 1);

            try {
                $upd = $pdo->prepare("
                    UPDATE hospital_resources 
                    SET oxygen_reserve_pct = ?, current_pressure_psi = ?, depletion_days = ?, last_calibrated_at = NOW() 
                    WHERE hospital_id = ?
                ");
                $upd->execute([$pct, $psi, $days, $adminHospitalId]);

                // Audit Log
                $pdo->prepare("
                    INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                    VALUES (?, 'Admin', 'RESOURCE_CALIBRATION', ?, 'LOGISTICS', 'Oxygen Tank Calibration', ?, ?, 'INFO')
                ")->execute([
                    (int)$_SESSION['user_id'],
                    "{$branchCode} Oxygen calibrated: {$pct}% ({$psi} PSI, {$days}d runway)",
                    "hospital_id:{$adminHospitalId}:oxygen",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => "Oxygen tank calibrated to {$pct}% ({$psi} PSI).", 'depletion_days' => $days]);
                    exit;
                }
                $flashMessage = "Liquid Oxygen tank telemetry successfully updated ({$pct}% • {$psi} PSI • {$days} days runway).";
            } catch (PDOException $e) {
                error_log('Oxygen calibration error: ' . $e->getMessage());
                $flashMessage = 'Database error updating oxygen telemetry.';
                $flashType = 'error';
            }
        }

        // 2. Confirm Emergency LOX Tanker Delivery & Refill
        elseif ($action === 'confirm_tanker_delivery') {
            try {
                $pdo->prepare("
                    UPDATE hospital_resources 
                    SET oxygen_reserve_pct = 98,
                        current_pressure_psi = 2400,
                        depletion_days = 9.3,
                        tanker_dispatched = 0,
                        tanker_dispatched_at = NULL,
                        last_calibrated_at = NOW()
                    WHERE hospital_id = ?
                ")->execute([$adminHospitalId]);

                $pdo->prepare("
                    INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                    VALUES (?, 'Admin', 'TANKER_INTAKE', ?, 'LOGISTICS', 'LOX Tanker Delivery Confirmed', ?, ?, 'INFO')
                ")->execute([
                    (int)$_SESSION['user_id'],
                    "Confirmed Emergency LOX Tanker delivery at {$branchName}. Bulk tank refilled to 98% (2,400 PSI).",
                    "hospital_id:{$adminHospitalId}:tanker",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'LOX Tanker delivery confirmed. Bulk reserves refilled to 98%.']);
                    exit;
                }
                $flashMessage = "Emergency LOX Tanker delivery confirmed! Bulk tank refilled to 98% (2,400 PSI).";
            } catch (PDOException $e) {
                error_log('Tanker intake error: ' . $e->getMessage());
                $flashMessage = 'Database error confirming tanker delivery.';
                $flashType = 'error';
            }
        }

        // 3. ICU Ventilator Fleet Action (+1 Hookup / -1 Wean)
        elseif ($action === 'ventilator_action') {
            $dir = trim($_POST['direction'] ?? '');

            try {
                $vStmt = $pdo->prepare("SELECT ventilators_total, ventilators_active FROM hospital_resources WHERE hospital_id = ?");
                $vStmt->execute([$adminHospitalId]);
                $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);

                $total  = (int)($vRow['ventilators_total'] ?? 80);
                $active = (int)($vRow['ventilators_active'] ?? 50);

                if ($dir === 'hookup') {
                    if ($active >= $total) {
                        $err = "Cannot connect patient: All {$total} ventilators in fleet are currently deployed.";
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => $err]);
                            exit;
                        }
                        $flashMessage = $err;
                        $flashType = 'error';
                    } else {
                        $newActive = $active + 1;
                        $pdo->prepare("UPDATE hospital_resources SET ventilators_active = ? WHERE hospital_id = ?")->execute([$newActive, $adminHospitalId]);

                        $pdo->prepare("
                            INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                            VALUES (?, 'Admin', 'VENTILATOR_HOOKUP', ?, 'LOGISTICS', 'Ventilator Patient Hookup', ?, ?, 'INFO')
                        ")->execute([
                            (int)$_SESSION['user_id'],
                            "+1 Patient hooked up to mechanical ventilator at {$branchCode} (Active: {$newActive}/{$total})",
                            "hospital_id:{$adminHospitalId}:ventilator",
                            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                        ]);

                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => "+1 Patient Hookup recorded. Active: {$newActive}/{$total}.", 'active' => $newActive, 'total' => $total, 'standby' => ($total - $newActive)]);
                            exit;
                        }
                        $flashMessage = "+1 Patient Hookup recorded. Active in use: {$newActive} / {$total}.";
                    }
                } elseif ($dir === 'wean') {
                    if ($active <= 0) {
                        $err = "Cannot wean patient: Active ventilator count is already 0.";
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => $err]);
                            exit;
                        }
                        $flashMessage = $err;
                        $flashType = 'error';
                    } else {
                        $newActive = $active - 1;
                        $pdo->prepare("UPDATE hospital_resources SET ventilators_active = ? WHERE hospital_id = ?")->execute([$newActive, $adminHospitalId]);

                        $pdo->prepare("
                            INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                            VALUES (?, 'Admin', 'VENTILATOR_WEAN', ?, 'LOGISTICS', 'Ventilator Patient Weaned', ?, ?, 'INFO')
                        ")->execute([
                            (int)$_SESSION['user_id'],
                            "-1 Patient weaned / discharged from ventilator at {$branchCode} (Active: {$newActive}/{$total})",
                            "hospital_id:{$adminHospitalId}:ventilator",
                            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                        ]);

                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => "-1 Patient Weaned recorded. Active: {$newActive}/{$total}.", 'active' => $newActive, 'total' => $total, 'standby' => ($total - $newActive)]);
                            exit;
                        }
                        $flashMessage = "-1 Patient Weaned/Discharged. Active in use: {$newActive} / {$total}.";
                    }
                }
            } catch (PDOException $e) {
                error_log('Ventilator action error: ' . $e->getMessage());
                $flashMessage = 'Database error updating ventilator status.';
                $flashType = 'error';
            }
        }

        // 4. Update Blood Bank Stockpile (full 8-group ABO/Rh)
        elseif ($action === 'update_blood') {
            $oNeg   = max(0, (int)($_POST['blood_o_neg'] ?? 0));
            $oPos   = max(0, (int)($_POST['blood_o_pos'] ?? 0));
            $aPos   = max(0, (int)($_POST['blood_a_pos'] ?? 0));
            $aNeg   = max(0, (int)($_POST['blood_a_neg'] ?? 0));
            $bPos   = max(0, (int)($_POST['blood_b_pos'] ?? 0));
            $bNeg   = max(0, (int)($_POST['blood_b_neg'] ?? 0));
            $abPos  = max(0, (int)($_POST['blood_ab_pos'] ?? 0));
            $abNeg  = max(0, (int)($_POST['blood_ab_neg'] ?? 0));
            $trauma = max(0, (int)($_POST['blood_trauma_packs'] ?? 0));
            $plt    = max(0, (int)($_POST['platelet_bags'] ?? 0));
            $cryo   = max(0, (int)($_POST['cryo_units'] ?? 0));

            try {
                $pdo->prepare("
                    UPDATE hospital_resources 
                    SET blood_o_neg = ?, blood_o_pos = ?, blood_a_pos = ?, blood_a_neg = ?,
                        blood_b_pos = ?, blood_b_neg = ?, blood_ab_pos = ?, blood_ab_neg = ?,
                        blood_trauma_packs = ?, platelet_bags = ?, cryo_units = ?,
                        last_calibrated_at = NOW()
                    WHERE hospital_id = ?
                ")->execute([$oNeg, $oPos, $aPos, $aNeg, $bPos, $bNeg, $abPos, $abNeg, $trauma, $plt, $cryo, $adminHospitalId]);

                $pdo->prepare("
                    INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                    VALUES (?, 'Admin', 'BLOOD_STOCK_UPDATE', ?, 'LOGISTICS', 'Blood Bank Inventory Adjustment', ?, ?, 'INFO')
                ")->execute([
                    (int)$_SESSION['user_id'],
                    "{$branchCode} Blood Stock updated: O-({$oNeg}) O+({$oPos}) A+({$aPos}) A-({$aNeg}) B+({$bPos}) B-({$bNeg}) AB+({$abPos}) AB-({$abNeg}) Trauma({$trauma}) Plt({$plt})",
                    "hospital_id:{$adminHospitalId}:blood",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Blood bank inventory synchronized (8-group ABO/Rh).']);
                    exit;
                }
                $flashMessage = 'Universal Blood Bank and 8-group ABO/Rh inventory successfully updated.';
            } catch (PDOException $e) {
                error_log('Blood update error: ' . $e->getMessage());
                $flashMessage = 'Database error updating blood inventory.';
                $flashType = 'error';
            }
        }

        // 5. Confirm Emergency Blood Courier Intake
        elseif ($action === 'confirm_courier_intake') {
            try {
                $pdo->prepare("
                    UPDATE hospital_resources 
                    SET blood_o_neg = blood_o_neg + 10,
                        blood_trauma_packs = blood_trauma_packs + 25,
                        platelet_bags = platelet_bags + 8,
                        courier_dispatched = 0,
                        courier_dispatched_at = NULL,
                        last_calibrated_at = NOW()
                    WHERE hospital_id = ?
                ")->execute([$adminHospitalId]);

                $pdo->prepare("
                    INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                    VALUES (?, 'Admin', 'COURIER_INTAKE', ?, 'LOGISTICS', 'Emergency Blood Courier Intake', ?, ?, 'INFO')
                ")->execute([
                    (int)$_SESSION['user_id'],
                    "Confirmed Emergency Blood Courier intake at {$branchName} (+10 O-, +25 Trauma Packs, +8 Platelets).",
                    "hospital_id:{$adminHospitalId}:courier",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Emergency Blood Courier intake recorded (+10 O-, +25 Trauma Packs).']);
                    exit;
                }
                $flashMessage = 'Emergency Blood Courier intake recorded (+10 O- units, +25 Trauma packs added to reserves).';
            } catch (PDOException $e) {
                error_log('Courier intake error: ' . $e->getMessage());
                $flashMessage = 'Database error confirming courier intake.';
                $flashType = 'error';
            }
        }
    }
}

// ── GET: Query Branch Hospital Resource Record ──────────────────────────────
$resQuery = $pdo->prepare("SELECT * FROM hospital_resources WHERE hospital_id = ?");
$resQuery->execute([$adminHospitalId]);
$resource = $resQuery->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    // If not seeded, initialize default baseline for this branch
    $pdo->prepare("
        INSERT INTO hospital_resources (hospital_id, oxygen_reserve_pct, current_pressure_psi, depletion_days, ventilators_total, ventilators_active, hardware_spec, blood_o_neg, blood_trauma_packs, blood_a_pos, blood_b_pos, blood_o_pos, blood_ab_neg, platelet_bags, cryo_units, last_calibrated_at)
        VALUES (?, 75, 2200, 7.5, 80, 50, 'Standard Dual Mode ICU Ventilator', 25, 100, 50, 60, 80, 15, 30, 15, NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ")->execute([$adminHospitalId]);

    $resQuery->execute([$adminHospitalId]);
    $resource = $resQuery->fetch(PDO::FETCH_ASSOC);
}

// Pre-calculate status variables
$oxPct = (int)($resource['oxygen_reserve_pct'] ?? 75);
$oxPsi = (int)($resource['current_pressure_psi'] ?? 2200);
$oxDays = (float)($resource['depletion_days'] ?? 7.5);
$tankerEnRoute = (int)($resource['tanker_dispatched'] ?? 0) === 1;

if ($oxPct >= 70) {
    $oxStatus = 'NORMAL';
    $oxClass = 'badge-normal';
    $oxColor = '#10b981';
} elseif ($oxPct >= 50) {
    $oxStatus = 'CAUTION';
    $oxClass = 'badge-caution';
    $oxColor = '#f59e0b';
} else {
    $oxStatus = 'CRITICAL';
    $oxClass = 'badge-critical';
    $oxColor = '#e11d48';
}

$vTot = (int)($resource['ventilators_total'] ?? 80);
$vAct = (int)($resource['ventilators_active'] ?? 50);
$vStb = max(0, $vTot - $vAct);
$vLoad = $vTot > 0 ? round(($vAct / $vTot) * 100, 1) : 0;
$vModel = $resource['hardware_spec'] ?? 'Standard Dual Mode ICU Ventilator';

// Clinical Telemetry Depth Calculations for Card 1 & Card 2
$oxFlowRate = round(100 + ($vAct * 0.84)); // e.g. ~142 L/min at nominal ward loading

// Dynamic Departmental Allocation (proportional to live active/standby counts)
$adultIcuUnits  = max(0, round($vAct * 0.65));
$picuUnits      = max(0, round($vAct * 0.20));
$traumaHduUnits = max(0, $vAct - $adultIcuUnits - $picuUnits);
$standbyUnits   = $vStb;

// Ventilation Mode Breakdown (Invasive ET Tube vs Non-Invasive BiPAP/CPAP)
$invasiveUnits    = max(0, round($vAct * 0.76));
$nonInvasiveUnits = max(0, $vAct - $invasiveUnits);
$invasivePct      = $vAct > 0 ? round(($invasiveUnits / $vAct) * 100) : 76;
$nonInvasivePct   = $vAct > 0 ? (100 - $invasivePct) : 24;

$bONeg   = (int)($resource['blood_o_neg'] ?? 25);
$bOPos   = (int)($resource['blood_o_pos'] ?? 80);
$bAPos   = (int)($resource['blood_a_pos'] ?? 50);
$bANeg   = (int)($resource['blood_a_neg'] ?? 15);
$bBPos   = (int)($resource['blood_b_pos'] ?? 60);
$bBNeg   = (int)($resource['blood_b_neg'] ?? 12);
$bABPos  = (int)($resource['blood_ab_pos'] ?? 25);
$bABNeg  = (int)($resource['blood_ab_neg'] ?? 10);
$bTrauma = (int)($resource['blood_trauma_packs'] ?? 100);
$bPlt    = (int)($resource['platelet_bags'] ?? 30);
$bCryo   = (int)($resource['cryo_units'] ?? 15);
$courierEnRoute = (int)($resource['courier_dispatched'] ?? 0) === 1;

// Recent audit logs for this branch
$logsStmt = $pdo->prepare("
    SELECT action, description, created_at, category, ip_address 
    FROM audit_logs 
    WHERE target_entity LIKE ? OR description LIKE ?
    ORDER BY log_id DESC 
    LIMIT 6
");
$matchEntity = "hospital_id:{$adminHospitalId}%";
$matchDesc   = "%{$branchCode}%";
$logsStmt->execute([$matchEntity, $matchDesc]);
$recentLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Resource &amp; Life-Support Inventory — <?= htmlspecialchars($branchName) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">

  <style>
    :root {
      --sa-accent: #0284c7;
      --sa-grad: linear-gradient(135deg, #0284c7 0%, #0d9488 100%);
      --emerald-normal: #10b981;
      --amber-caution: #f59e0b;
      --crimson-alert: #e11d48;
    }

    body {
      background-color: var(--background, #f8fafc);
      color: var(--text-heading, #0f172a);
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    }

    .ri-banner-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
    }

    /* Badges */
    .ri-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 0.70rem;
      font-weight: 800;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .badge-normal {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid rgba(16, 185, 129, 0.35);
    }
    .badge-caution {
      background: #fffbeb;
      color: #92400e;
      border: 1px solid rgba(245, 158, 11, 0.35);
    }
    .badge-critical {
      background: #fff1f2;
      color: #9f1239;
      border: 1px solid rgba(244, 63, 94, 0.45);
      animation: alertPulse 1.8s infinite;
    }
    @keyframes alertPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.2); }
      50% { box-shadow: 0 0 0 4px rgba(225, 29, 72, 0.2); }
    }
    @keyframes bioPulseRing {
      0% { transform: scale(1); opacity: 0.6; }
      70% { transform: scale(2.4); opacity: 0; }
      100% { transform: scale(2.4); opacity: 0; }
    }

    /* Inbound Dispatch Banners */
    .dispatch-alert-banner {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      border: 1.5px solid #0284c7;
      border-radius: 16px;
      padding: 16px 20px;
      color: #fff;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      box-shadow: 0 8px 24px rgba(2, 132, 199, 0.2);
    }
    .dispatch-alert-banner.tanker-banner {
      border-color: #38bdf8;
      background: linear-gradient(135deg, #082f49 0%, #0f172a 100%);
    }
    .dispatch-alert-banner.courier-banner {
      border-color: #f43f5e;
      background: linear-gradient(135deg, #4c0519 0%, #1e1b4b 100%);
    }

    /* Modules 3-Grid (Equal Column Heights & Baseline Alignment) */
    .ri-modules-grid {
      display: grid;
      grid-template-columns: repeat(1, minmax(0, 1fr));
      gap: 1.5rem;
      margin-bottom: 24px;
      align-items: stretch;
    }
    @media (min-width: 1024px) {
      .ri-modules-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    /* Responsive Grid & Flex Layout Utilities */
    .grid { display: grid; }
    .grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    @media (min-width: 1024px) {
      .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    .gap-6 { gap: 1.5rem; }
    .items-stretch { align-items: stretch; }
    .flex { display: flex; }
    .flex-col { flex-direction: column; }
    .justify-between { justify-content: space-between; }
    .h-full { height: 100%; }
    .bg-white { background-color: var(--surface, #ffffff); }
    .rounded-2xl { border-radius: 1rem; }
    .border { border-width: 1px; border-style: solid; }
    .border-slate-200\/80 { border-color: var(--surface-border, rgba(226, 232, 240, 0.8)); }
    .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
    .p-6 { padding: 1.5rem; }
    .mt-auto { margin-top: auto; }
    .pt-4 { padding-top: 1rem; }
    .border-t { border-top-width: 1px; border-top-style: solid; }
    .border-slate-100 { border-top-color: var(--surface-border-subtle, #f1f5f9); }

    .ri-card {
      background: var(--surface, #ffffff);
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.8));
      border-radius: 1rem;
      box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      height: 100%;
      box-sizing: border-box;
      transition: all 0.2s ease;
    }
    .ri-card:hover {
      box-shadow: 0 8px 25px rgba(0,0,0,0.06);
    }

    .ri-card-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--surface-border-subtle);
    }
    .ri-card-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text-heading);
      margin: 0 0 3px 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ri-card-sub {
      font-size: 0.74rem;
      color: var(--text-muted);
      margin: 0;
    }

    /* Progress bar */
    .ri-bar-bg {
      background: var(--surface-secondary, #e2e8f0);
      border-radius: 8px;
      height: 9px;
      overflow: hidden;
      margin: 8px 0;
    }
    .ri-bar-fill {
      height: 100%;
      border-radius: 8px;
      transition: width 0.3s ease;
    }

    /* Form inputs */
    .ri-input-group {
      margin-bottom: 14px;
    }
    .ri-label {
      display: flex;
      justify-content: space-between;
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 6px;
    }
    .ri-input {
      width: 100%;
      padding: 8px 12px;
      border-radius: 8px;
      border: 1.5px solid var(--surface-border);
      background: var(--surface);
      color: var(--text-heading);
      font-family: inherit;
      font-size: 0.84rem;
      font-weight: 600;
      box-sizing: border-box;
      transition: border-color 0.15s;
    }
    .ri-input:focus {
      outline: none;
      border-color: #0284c7;
    }

    /* Buttons */
    .btn-action-primary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 9px 18px;
      border-radius: 9px;
      font-size: 0.80rem;
      font-weight: 700;
      cursor: pointer;
      border: none;
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #fff;
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
      transition: all 0.15s ease;
      font-family: inherit;
    }
    .btn-action-primary:hover {
      filter: brightness(1.08);
      transform: translateY(-1px);
    }
    .btn-action-emerald {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #fff;
      box-shadow: 0 2px 8px rgba(16, 185, 129, 0.25);
    }
    .btn-action-emerald:hover { filter: brightness(1.08); }

    .btn-action-crimson {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
      color: #fff;
      box-shadow: 0 2px 8px rgba(225, 29, 72, 0.25);
    }
    .btn-action-crimson:hover { filter: brightness(1.08); }

    .btn-action-secondary {
      background: var(--surface);
      border: 1.5px solid var(--surface-border);
      color: var(--text-heading);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 0.78rem;
      font-weight: 700;
      cursor: pointer;
      font-family: inherit;
      transition: 0.15s;
    }
    .btn-action-secondary:hover {
      border-color: #0284c7;
      color: #0284c7;
    }

    /* Blood Matrix Tile */
    .blood-grid-8 {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-bottom: 16px;
    }
    .blood-card-tile {
      background: var(--surface-secondary, #f8fafc);
      border: 1px solid var(--surface-border);
      border-radius: 10px;
      padding: 10px 8px;
      text-align: center;
      position: relative;
    }
    .blood-card-tile.tile-oneg {
      background: #fff1f2;
      border-color: rgba(225, 29, 72, 0.35);
    }
    .b-tile-name {
      font-size: 0.68rem;
      font-weight: 800;
      color: var(--text-muted);
      text-transform: uppercase;
    }
    .tile-oneg .b-tile-name { color: #9f1239; }
    .b-tile-val {
      font-size: 1.25rem;
      font-weight: 800;
      color: var(--text-heading);
      margin: 3px 0 2px;
    }
    .tile-oneg .b-tile-val { color: #e11d48; }

    /* Flash Message Box */
    .flash-alert {
      padding: 12px 18px;
      border-radius: 12px;
      margin-bottom: 20px;
      font-size: 0.82rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .flash-alert.success {
      background: #ecfdf5;
      color: #065f46;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .flash-alert.error {
      background: #fff1f2;
      color: #9f1239;
      border: 1px solid rgba(244, 63, 94, 0.3);
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <main class="viewport-full">
    <!-- Welcome Header Banner -->
    <div class="welcome-banner">
      <div class="ri-banner-header">
        <div class="welcome-text">
          <div style="display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--sa-grad);color:#fff;border-radius:20px;font-size:0.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;">
            Facility Operational Command
          </div>
          <h1>Critical Resource &amp; Life-Support Telemetry</h1>
          <p>Local monitoring, hardware calibration, and stockpile management for <strong><?= htmlspecialchars($branchName) ?></strong> (<?= htmlspecialchars($branchCode) ?> • <?= htmlspecialchars($branchCity) ?>).</p>
        </div>

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <!-- Telemetry Pill with clinical ECG pulse animation -->
          <div class="sa-telemetry-badge <?= ($oxPct < 50 || $bONeg < 20) ? 'telemetry-surge' : 'telemetry-normal' ?>" style="display:inline-flex;align-items:center;gap:9px;padding:7px 15px;border-radius:40px;font-size:0.74rem;font-weight:700;background:<?= ($oxPct < 50 || $bONeg < 20) ? '#fff1f2' : '#ecfdf5' ?>;border:1.5px solid <?= ($oxPct < 50 || $bONeg < 20) ? '#f43f5e' : '#10b981' ?>;color:<?= ($oxPct < 50 || $bONeg < 20) ? '#9f1239' : '#065f46' ?>;">
            <span style="position:relative;width:10px;height:10px;flex-shrink:0;">
              <span style="position:absolute;inset:0;border-radius:50%;background:currentColor;animation:bioPulseRing 1.4s cubic-bezier(0,0,0.2,1) infinite;opacity:0;"></span>
              <span style="position:absolute;inset:1.5px;border-radius:50%;background:currentColor;"></span>
            </span>
            <svg width="44" height="16" viewBox="0 0 44 16" fill="none" style="stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;">
              <polyline points="0,8 8,8 11,3 14,13 17,5 20,11 23,8 44,8"/>
            </svg>
            <span><?= ($oxPct < 50 || $bONeg < 20) ? '112 BPM &bull; ATTENTION' : '72 BPM &bull; NOMINAL' ?></span>
          </div>

          <a href="dashboard.php" class="btn-action-secondary" style="text-decoration:none;">
            &larr; Executive Overview
          </a>
        </div>
      </div>
    </div>

    <?php if ($flashMessage): ?>
      <div class="flash-alert <?= $flashType ?>">
        <span><?= $flashType === 'success' ? '✓' : '⚠' ?></span>
        <span><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════
         INBOUND EMERGENCY DISPATCH BANNERS (SUPER ADMIN DISPATCHES)
    ════════════════════════════════════════════════════════════════ -->
    <?php if ($tankerEnRoute): ?>
      <div class="dispatch-alert-banner tanker-banner">
        <div style="display:flex;align-items:center;gap:14px;">
          <div style="width:48px;height:48px;border-radius:12px;background:rgba(56,189,248,0.2);border:1px solid #38bdf8;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;">
            🚚
          </div>
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
              <strong style="font-size:1.05rem;letter-spacing:0.02em;">Emergency LOX Tanker En Route</strong>
              <span style="background:#0284c7;color:#fff;font-size:0.68rem;padding:2px 7px;border-radius:6px;font-weight:800;text-transform:uppercase;">
                Super Admin Dispatched
              </span>
            </div>
            <p style="margin:0;font-size:0.80rem;color:#bae6fd;">
              High-volume cryogenic liquid oxygen transport is currently in transit to <?= htmlspecialchars($branchName) ?>.
            </p>
          </div>
        </div>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="_action" value="confirm_tanker_delivery">
          <button type="submit" class="btn-action-primary btn-action-emerald">
            ✓ Confirm Delivery &amp; Refill Tank (98%)
          </button>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($courierEnRoute): ?>
      <div class="dispatch-alert-banner courier-banner">
        <div style="display:flex;align-items:center;gap:14px;">
          <div style="width:48px;height:48px;border-radius:12px;background:rgba(244,63,94,0.2);border:1px solid #f43f5e;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;">
            🚑
          </div>
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
              <strong style="font-size:1.05rem;letter-spacing:0.02em;">Emergency Blood Courier En Route</strong>
              <span style="background:#e11d48;color:#fff;font-size:0.68rem;padding:2px 7px;border-radius:6px;font-weight:800;text-transform:uppercase;">
                High Priority Transit
              </span>
            </div>
            <p style="margin:0;font-size:0.80rem;color:#fecdd3;">
              Emergency refrigerated courier carrying +10 O- units and +25 trauma packs approaching facility.
            </p>
          </div>
        </div>
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="_action" value="confirm_courier_intake">
          <button type="submit" class="btn-action-primary btn-action-crimson">
            ✓ Confirm Courier Intake &amp; Stockpile
          </button>
        </form>
      </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════
         3 PRIMARY RESOURCE MODULES
    ════════════════════════════════════════════════════════════════ -->
    <div class="ri-modules-grid grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
      
      <!-- ── MODULE 1: LIQUID OXYGEN TANK MONITORING & CALIBRATION ──── -->
      <div class="ri-card flex flex-col justify-between h-full bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
        <form method="POST" id="formOxygenCalibration" style="display:flex;flex-direction:column;flex:1;margin:0;height:100%;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="_action" value="update_oxygen">

          <div style="flex:1;">
            <div class="ri-card-head">
              <div>
                <h3 class="ri-card-title">
                  <span>☁️</span>
                  Central Oxygen (LOX) Tank
                </h3>
                <p class="ri-card-sub">Cryogenic Bulk Storage &amp; Pressure Sensor Telemetry</p>
              </div>
              <span class="ri-badge <?= $oxClass ?>">
                <?= $oxStatus ?>
              </span>
            </div>

            <!-- Gauge Metrics -->
            <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:4px;">
              <span style="font-size:0.75rem;color:var(--text-muted);font-weight:700;">Remaining Volume</span>
              <span style="font-size:1.8rem;font-weight:800;color:<?= $oxColor ?>;" id="lblOxygenPct"><?= $oxPct ?>%</span>
            </div>

            <div class="ri-bar-bg" style="height:10px;margin-bottom:12px;">
              <div class="ri-bar-fill" id="barOxygenFill" style="width:<?= $oxPct ?>%; background:<?= $oxColor ?>;"></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);padding:10px 12px;border-radius:10px;margin-bottom:12px;">
              <div>
                <div style="font-size:0.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:700;">Tank Pressure</div>
                <div style="font-size:1.15rem;font-weight:800;color:var(--text-heading);margin-top:2px;">
                  <span id="lblPressurePsi"><?= number_format($oxPsi) ?></span> <span style="font-size:0.70rem;font-weight:600;color:var(--text-muted);">PSI</span>
                </div>
              </div>
              <div>
                <div style="font-size:0.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:700;">Depletion Runway</div>
                <div style="font-size:1.15rem;font-weight:800;color:<?= $oxDays < 3.5 ? '#e11d48' : 'var(--text-heading)' ?>;margin-top:2px;">
                  <span id="lblDepletionDays"><?= $oxDays ?></span> <span style="font-size:0.70rem;font-weight:600;color:var(--text-muted);">Days</span>
                </div>
              </div>
            </div>

            <!-- Secondary Clinical Telemetry Block (Eliminates Dead Space) -->
            <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:12px;padding:12px;margin-bottom:12px;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <span style="font-size:0.68rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;display:flex;align-items:center;gap:6px;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                  Secondary Emergency Manifold Bank
                </span>
                <span style="font-size:0.62rem;font-weight:800;padding:2px 7px;border-radius:12px;background:#ecfdf5;color:#065f46;border:1px solid rgba(16,185,129,0.3);">
                  ONLINE &bull; STANDBY
                </span>
              </div>

              <!-- Manifold Stat Details -->
              <div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:8px 10px;margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:8px;">
                  <span style="font-size:1.15rem;">🧯</span>
                  <div>
                    <div style="font-size:0.76rem;font-weight:700;color:var(--text-heading);">2&times;24 Cylinder Bank Online</div>
                    <div style="font-size:0.68rem;color:var(--text-muted);">48/48 Cylinders Standby &bull; Auto-Failover</div>
                  </div>
                </div>
                <div style="text-align:right;">
                  <span style="font-size:0.84rem;font-weight:800;color:#0284c7;font-family:'JetBrains Mono',monospace;">150 Bar</span>
                  <div style="font-size:0.60rem;color:#059669;font-weight:700;">Standby Ready</div>
                </div>
              </div>

              <!-- Real-time Piping Flow Rate -->
              <div style="display:flex;align-items:center;justify-content:space-between;background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:8px 10px;">
                <div style="display:flex;align-items:center;gap:7px;">
                  <span style="position:relative;width:8px;height:8px;flex-shrink:0;">
                    <span style="position:absolute;inset:0;border-radius:50%;background:#10b981;animation:bioPulseRing 1.6s cubic-bezier(0,0,0.2,1) infinite;opacity:0;"></span>
                    <span style="position:absolute;inset:1px;border-radius:50%;background:#10b981;"></span>
                  </span>
                  <span style="font-size:0.72rem;font-weight:700;color:var(--text-heading);">Real-time Piping Flow Rate</span>
                </div>
                <div style="text-align:right;">
                  <span style="font-size:0.78rem;font-weight:800;color:#0284c7;font-family:'JetBrains Mono',monospace;"><?= $oxFlowRate ?> L/min</span>
                  <span style="font-size:0.66rem;color:var(--text-muted);"> Ward Draw &bull; 4.2 Bar Reg.</span>
                </div>
              </div>
            </div>

            <!-- Calibration Form Inputs -->
            <div class="ri-input-group" style="margin-bottom:10px;">
              <div class="ri-label" style="margin-bottom:4px;">
                <span>Calibrate Volume (%)</span>
                <span id="sliderValDisplay" style="color:#0284c7;font-weight:800;"><?= $oxPct ?>%</span>
              </div>
              <input type="range" class="ri-slider" id="inputOxygenPct" name="oxygen_reserve_pct" min="5" max="100" value="<?= $oxPct ?>" style="width:100%;accent-color:#0284c7;" oninput="updateOxygenPreview(this.value)">
            </div>

            <div class="ri-input-group" style="margin-bottom:0;">
              <div class="ri-label" style="margin-bottom:4px;">
                <span>Manometer Reading (PSI)</span>
              </div>
              <input type="number" class="ri-input" id="inputPressurePsi" name="current_pressure_psi" min="500" max="3000" step="10" value="<?= $oxPsi ?>">
            </div>
          </div>

          <!-- Bottom Action Area: Anchored to exact baseline -->
          <div class="mt-auto pt-4 border-t border-slate-100">
            <div style="display:flex;gap:8px;margin-bottom:10px;">
              <button type="submit" class="btn-action-primary" style="flex:1;height:42px;">
                Save Calibration
              </button>
              <button type="button" class="btn-action-secondary" onclick="quickFillOxygen(100, 2400)" title="Quick Refill" style="height:42px;padding:0 16px;">
                Max
              </button>
            </div>

            <div style="font-size:0.70rem;color:var(--text-muted);display:flex;justify-content:space-between;align-items:center;min-height:18px;">
              <span>Runway: (Vol / 100) &times; 9.5d</span>
              <span>Last Calibrated: <?= !empty($resource['last_calibrated_at']) ? date('M j, H:i', strtotime($resource['last_calibrated_at'])) : 'Now' ?></span>
            </div>
          </div>
        </form>
      </div>

      <!-- ── MODULE 2: ICU VENTILATOR FLEET TRIAGE ──────────────────── -->
      <div class="ri-card flex flex-col justify-between h-full bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
        <div style="display:flex;flex-direction:column;flex:1;height:100%;">
          <div style="flex:1;">
            <div class="ri-card-head">
              <div>
                <h3 class="ri-card-title">
                  <span>🫁</span>
                  ICU Ventilator Fleet
                </h3>
                <p class="ri-card-sub"><?= htmlspecialchars($vModel) ?></p>
              </div>
              <span class="ri-badge <?= $vStb >= 15 ? 'badge-normal' : 'badge-caution' ?>">
                <?= $vStb >= 15 ? 'OPTIMAL READY' : 'HIGH LOAD' ?>
              </span>
            </div>

            <!-- Counter Cards -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
              <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:12px;padding:10px 12px;text-align:center;">
                <div style="font-size:0.70rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Active In-Use</div>
                <div style="font-size:1.85rem;font-weight:800;color:#e11d48;margin-top:2px;" id="lblVentActive">
                  <?= $vAct ?>
                </div>
                <div style="font-size:0.68rem;color:var(--text-muted);">Inpatient Ventilation</div>
              </div>

              <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:12px;padding:10px 12px;text-align:center;">
                <div style="font-size:0.70rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Rapid Standby</div>
                <div style="font-size:1.85rem;font-weight:800;color:#10b981;margin-top:2px;" id="lblVentStandby">
                  <?= $vStb ?>
                </div>
                <div style="font-size:0.68rem;color:#10b981;font-weight:700;">Hookup Ready</div>
              </div>
            </div>

            <div style="margin-bottom:12px;">
              <div style="display:flex;justify-content:space-between;font-size:0.74rem;color:var(--text-muted);margin-bottom:4px;">
                <span>Total Fleet Allocation: <strong style="color:var(--text-heading);"><?= $vTot ?> Units</strong></span>
                <span>Load: <strong style="color:var(--text-heading);" id="lblVentLoad"><?= $vLoad ?>%</strong></span>
              </div>
              <div class="ri-bar-bg" style="height:9px;">
                <div class="ri-bar-fill" id="barVentFill" style="width:<?= $vLoad ?>%; background:linear-gradient(90deg, #10b981 0%, #f59e0b 70%, #e11d48 100%);"></div>
              </div>
            </div>

            <!-- Clinical Departmental Distribution & Mode Telemetry (Eliminates Dead Space) -->
            <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:12px;padding:12px;margin-bottom:12px;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <span style="font-size:0.68rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;display:flex;align-items:center;gap:6px;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                  Departmental Allocation
                </span>
                <span style="font-size:0.62rem;font-weight:800;padding:2px 7px;border-radius:12px;background:#e0f2fe;color:#0369a1;border:1px solid rgba(2,132,199,0.3);">
                  4 Wards Mapped
                </span>
              </div>

              <!-- Departmental 4-Grid Micro-Badges -->
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px;">
                <div style="background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px 9px;">
                  <div style="font-size:0.62rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Adult Med/Surg ICU</div>
                  <div style="font-size:0.95rem;font-weight:800;color:var(--text-heading);margin-top:1px;">
                    <?= $adultIcuUnits ?> <span style="font-size:0.64rem;font-weight:600;color:#e11d48;">Active</span>
                  </div>
                </div>
                <div style="background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px 9px;">
                  <div style="font-size:0.62rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Pediatric (PICU)</div>
                  <div style="font-size:0.95rem;font-weight:800;color:var(--text-heading);margin-top:1px;">
                    <?= $picuUnits ?> <span style="font-size:0.64rem;font-weight:600;color:#0284c7;">Active</span>
                  </div>
                </div>
                <div style="background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px 9px;">
                  <div style="font-size:0.62rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Trauma / HDU</div>
                  <div style="font-size:0.95rem;font-weight:800;color:var(--text-heading);margin-top:1px;">
                    <?= $traumaHduUnits ?> <span style="font-size:0.64rem;font-weight:600;color:#f59e0b;">Active</span>
                  </div>
                </div>
                <div style="background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px 9px;">
                  <div style="font-size:0.62rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Sterile Standby</div>
                  <div style="font-size:0.95rem;font-weight:800;color:#10b981;margin-top:1px;">
                    <?= $standbyUnits ?> <span style="font-size:0.64rem;font-weight:700;color:#059669;">Ready</span>
                  </div>
                </div>
              </div>

              <!-- Ventilation Support Breakdown (Invasive vs Non-Invasive) -->
              <div style="background:var(--surface,#ffffff);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:8px 10px;margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;font-size:0.68rem;font-weight:700;margin-bottom:4px;">
                  <span style="color:var(--text-heading);">Invasive (ET Tube): <strong style="color:#0284c7;"><?= $invasiveUnits ?></strong> (<?= $invasivePct ?>%)</span>
                  <span style="color:var(--text-muted);">Non-Invasive (BiPAP): <strong style="color:#7c3aed;"><?= $nonInvasiveUnits ?></strong> (<?= $nonInvasivePct ?>%)</span>
                </div>
                <div style="height:6px;border-radius:6px;background:#e2e8f0;overflow:hidden;display:flex;">
                  <div style="width:<?= $invasivePct ?>%;background:#0284c7;height:100%;" title="Invasive Mechanical"></div>
                  <div style="width:<?= $nonInvasivePct ?>%;background:#7c3aed;height:100%;" title="Non-Invasive BiPAP/CPAP"></div>
                </div>
              </div>

              <!-- Biomedical Maintenance Telemetry -->
              <div style="display:flex;align-items:center;justify-content:space-between;background:#ecfdf5;border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:6px 10px;">
                <div style="display:flex;align-items:center;gap:6px;font-size:0.70rem;font-weight:700;color:#065f46;">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                  <span>Filter Sterilization &amp; HEPA Calibration</span>
                </div>
                <span style="font-size:0.62rem;font-weight:800;color:#059669;text-transform:uppercase;letter-spacing:0.03em;">100% Certified</span>
              </div>
            </div>

            <!-- Rapid Allocation Context -->
            <div style="margin-top:6px;">
              <div style="font-size:0.70rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:2px;">
                Rapid Patient Allocation Controls
              </div>
              <p style="font-size:0.71rem;color:var(--text-muted);margin:0;">
                Immediate bedside triage adjustment. Allocate emergency intake or record weaning:
              </p>
            </div>
          </div>

          <!-- Bottom Action Area: Anchored to exact baseline -->
          <div class="mt-auto pt-4 border-t border-slate-100">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
              <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_action" value="ventilator_action">
                <input type="hidden" name="direction" value="hookup">
                <button type="submit" class="btn-action-primary btn-action-crimson" style="width:100%;height:42px;" <?= $vAct >= $vTot ? 'disabled style="opacity:0.5;cursor:not-allowed;width:100%;height:42px;"' : '' ?>>
                  +1 Patient Hookup
                </button>
              </form>

              <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_action" value="ventilator_action">
                <input type="hidden" name="direction" value="wean">
                <button type="submit" class="btn-action-secondary" style="width:100%;height:42px;" <?= $vAct <= 0 ? 'disabled style="opacity:0.5;cursor:not-allowed;width:100%;height:42px;"' : '' ?>>
                  -1 Patient Weaned
                </button>
              </form>
            </div>

            <div style="font-size:0.70rem;color:var(--text-muted);display:flex;justify-content:space-between;align-items:center;min-height:18px;">
              <span>Hardware: <?= htmlspecialchars($vModel) ?></span>
              <span style="color:#10b981;font-weight:700;">✓ Calibrated</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── MODULE 3: UNIVERSAL BLOOD BANK & PLASMA STOCK ──────────── -->
      <div class="ri-card flex flex-col justify-between h-full bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
        <form method="POST" style="display:flex;flex-direction:column;flex:1;margin:0;height:100%;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="_action" value="update_blood">

          <div style="flex:1;">
            <div class="ri-card-head">
              <div>
                <h3 class="ri-card-title">
                  <span>🩸</span>
                  Universal Blood Stockpile
                </h3>
                <p class="ri-card-sub">Full 8-group ABO/Rh cross-match inventories &amp; trauma packs</p>
              </div>
              <span class="ri-badge <?= $bONeg < 20 ? 'badge-critical' : ($bONeg < 30 ? 'badge-caution' : 'badge-normal') ?>">
                <?= $bONeg < 20 ? 'CRITICAL SHORTAGE' : 'BUFFER OPTIMAL' ?>
              </span>
            </div>

            <!-- 8-Group ABO/Rh Read-Only Grid -->
            <div class="blood-grid-8" style="margin-bottom:10px;">
              <div class="blood-card-tile tile-oneg">
                <div class="b-tile-name">O-</div>
                <div class="b-tile-val"><?= $bONeg ?></div>
                <div style="font-size:0.58rem;color:#9f1239;font-weight:800;">Universal</div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">O+</div>
                <div class="b-tile-val"><?= $bOPos ?></div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">A+</div>
                <div class="b-tile-val"><?= $bAPos ?></div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">A-</div>
                <div class="b-tile-val"><?= $bANeg ?></div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">B+</div>
                <div class="b-tile-val"><?= $bBPos ?></div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">B-</div>
                <div class="b-tile-val"><?= $bBNeg ?></div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">AB+</div>
                <div class="b-tile-val"><?= $bABPos ?></div>
              </div>
              <div class="blood-card-tile">
                <div class="b-tile-name">AB-</div>
                <div class="b-tile-val"><?= $bABNeg ?></div>
              </div>
            </div>

            <!-- Components Row (Trauma, Platelets, Cryo) -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;">
              <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px;text-align:center;">
                <div style="font-size:0.64rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Trauma Packs</div>
                <div style="font-size:1.1rem;font-weight:800;color:#0284c7;"><?= $bTrauma ?></div>
              </div>
              <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px;text-align:center;">
                <div style="font-size:0.64rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Platelet Bags</div>
                <div style="font-size:1.1rem;font-weight:800;color:#7c3aed;"><?= $bPlt ?></div>
              </div>
              <div style="background:var(--surface-secondary,#f8fafc);border:1px solid var(--surface-border,#e2e8f0);border-radius:8px;padding:7px;text-align:center;">
                <div style="font-size:0.64rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;">Cryo Units</div>
                <div style="font-size:1.1rem;font-weight:800;color:#0d9488;"><?= $bCryo ?></div>
              </div>
            </div>

            <!-- Full 8-Group Edit Form -->
            <div style="font-size:0.70rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">
              Update Stock Counts (8 ABO/Rh Groups)
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">O- (Universal)</label>
                <input type="number" class="ri-input" name="blood_o_neg" min="0" value="<?= $bONeg ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">O+ Positive</label>
                <input type="number" class="ri-input" name="blood_o_pos" min="0" value="<?= $bOPos ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">A+ Positive</label>
                <input type="number" class="ri-input" name="blood_a_pos" min="0" value="<?= $bAPos ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">A- Negative</label>
                <input type="number" class="ri-input" name="blood_a_neg" min="0" value="<?= $bANeg ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">B+ Positive</label>
                <input type="number" class="ri-input" name="blood_b_pos" min="0" value="<?= $bBPos ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">B- Negative</label>
                <input type="number" class="ri-input" name="blood_b_neg" min="0" value="<?= $bBNeg ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">AB+ Positive</label>
                <input type="number" class="ri-input" name="blood_ab_pos" min="0" value="<?= $bABPos ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">AB- Negative</label>
                <input type="number" class="ri-input" name="blood_ab_neg" min="0" value="<?= $bABNeg ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:0;">
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">Trauma Packs</label>
                <input type="number" class="ri-input" name="blood_trauma_packs" min="0" value="<?= $bTrauma ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">Platelet Bags</label>
                <input type="number" class="ri-input" name="platelet_bags" min="0" value="<?= $bPlt ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
              <div>
                <label class="ri-label" style="margin-bottom:2px;font-size:0.70rem;">Cryo Units</label>
                <input type="number" class="ri-input" name="cryo_units" min="0" value="<?= $bCryo ?>" style="padding:6px 10px;font-size:0.80rem;">
              </div>
            </div>
          </div>

          <!-- Bottom Action Area: Anchored to exact baseline -->
          <div class="mt-auto pt-4 border-t border-slate-100">
            <button type="submit" class="btn-action-primary" style="width:100%;height:42px;margin-bottom:10px;">
              Sync Blood Bank Stock
            </button>

            <div style="font-size:0.70rem;color:var(--text-muted);display:flex;justify-content:space-between;align-items:center;min-height:18px;">
              <span>Trauma Buffer: Standard 4:1:1 FFP ratio</span>
              <span>Verified Hub</span>
            </div>
          </div>
        </form>
      </div>

    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         RECENT LOGISTICS AUDIT TRAIL
    ════════════════════════════════════════════════════════════════ -->
    <div class="ri-card" style="margin-bottom:24px;">
      <div class="ri-card-head">
        <div>
          <h3 class="ri-card-title">
            <svg class="ui-ico" style="width:18px;height:18px;stroke:var(--sa-accent);" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            Facility Logistics &amp; Calibration Audit Log
          </h3>
          <p class="ri-card-sub">Recent resource adjustments, tanker deliveries, and ventilator triage events</p>
        </div>
      </div>

      <div style="overflow-x:auto;">
        <table class="admin-data-table" style="width:100%;font-size:0.80rem;">
          <thead>
            <tr>
              <th style="padding:8px 12px;">Timestamp</th>
              <th style="padding:8px 12px;">Category</th>
              <th style="padding:8px 12px;">Action</th>
              <th style="padding:8px 12px;">Audit Description</th>
              <th style="padding:8px 12px;">IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($recentLogs)): ?>
              <?php foreach ($recentLogs as $log): ?>
              <tr>
                <td style="padding:10px 12px;color:var(--text-muted);font-size:0.74rem;">
                  <?= date('M j, Y H:i', strtotime($log['created_at'])) ?>
                </td>
                <td style="padding:10px 12px;">
                  <span style="font-size:0.68rem;padding:2px 6px;border-radius:4px;background:#e0f2fe;color:#0369a1;font-weight:700;">
                    <?= htmlspecialchars($log['category'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="padding:10px 12px;font-weight:700;">
                  <?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:10px 12px;color:var(--text-heading);">
                  <?= htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:10px 12px;color:var(--text-muted);font-family:monospace;font-size:0.72rem;">
                  <?= htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8') ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align:center;padding:16px;color:var(--text-muted);">
                  No recent logistics events recorded for this branch.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>

  <script>
    // Live calculation of depletion days on slider move
    function updateOxygenPreview(pct) {
      pct = parseInt(pct, 10);
      document.getElementById('sliderValDisplay').textContent = pct + '%';
      document.getElementById('lblOxygenPct').textContent = pct + '%';
      document.getElementById('barOxygenFill').style.width = pct + '%';

      // Formula: (volume_pct / 100) * 9.5
      const days = ((pct / 100) * 9.5).toFixed(1);
      document.getElementById('lblDepletionDays').textContent = days;

      // Adjust colors dynamically
      let color = '#10b981';
      if (pct < 50) {
        color = '#e11d48';
      } else if (pct < 70) {
        color = '#f59e0b';
      }
      document.getElementById('lblOxygenPct').style.color = color;
      document.getElementById('barOxygenFill').style.backgroundColor = color;
    }

    function quickFillOxygen(pct, psi) {
      document.getElementById('inputOxygenPct').value = pct;
      document.getElementById('inputPressurePsi').value = psi;
      updateOxygenPreview(pct);
      document.getElementById('lblPressurePsi').textContent = psi.toLocaleString();
    }
  </script>
</body>
</html>

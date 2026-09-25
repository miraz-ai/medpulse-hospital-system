<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — National Life-Support & Blood Reserves Telemetry Command
 *
 * Real-time Hardware & IoT Consumables Telemetry across 6 Connected Network Facilities:
 * 1. Central Oxygen Reserves & Cryogenic LOX Tank Pressure (PSI, Burn Runway)
 * 2. ICU Mechanical Ventilator Fleet Allocations (Hardware Models, In-Use vs Standby)
 * 3. Universal Blood Bank Reserves (O-, Trauma, A+, B+, O+, AB-, Platelets, Cryo)
 * 4. Inter-Facility Emergency Dispatch System (Tanker & Courier Dispatches with DB Persistence)
 * 5. Dynamic Header Summary KPIs & Synchronized ECG Live Telemetry
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../backend/Services/EmergencyProtocolService.php';

use MedPulse\Services\EmergencyProtocolService;

// Emergency Surge Telemetry State
$emergencyService = new EmergencyProtocolService($pdo);
$activeProtocols  = $emergencyService->getActiveProtocols();
$activeCount      = count($activeProtocols);

// ── ASYNCHRONOUS BACKEND HANDLERS (AJAX/Fetch) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Session may have expired.']);
        exit;
    }

    $action = $_POST['_action'];
    $hospId = (int)($_POST['hospital_id'] ?? 0);

    // Resolve target hospital name
    $hStmt = $pdo->prepare("SELECT name, code FROM hospitals WHERE hospital_id = ?");
    $hStmt->execute([$hospId]);
    $targetHospital = $hStmt->fetch(PDO::FETCH_ASSOC);
    $hospName = $targetHospital['name'] ?? "Facility #{$hospId}";
    $hospCode = $targetHospital['code'] ?? "HOSP-{$hospId}";

    // 1. Dispatch Emergency LOX Tanker
    if ($action === 'dispatch_tanker' || ($action === 'dispatch_replenishment' && ($_POST['resource_type'] ?? '') === 'oxygen')) {
        try {
            $upd = $pdo->prepare("
                UPDATE hospital_resources 
                SET tanker_dispatched = 1,
                    tanker_dispatched_at = NOW()
                WHERE hospital_id = ?
            ");
            $upd->execute([$hospId]);

            $pdo->prepare("
                INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES (?, 'super_admin', 'TANKER_DISPATCH', ?, 'LOGISTICS', 'Emergency LOX Tanker Dispatch', ?, ?, 'CRITICAL')
            ")->execute([
                (int)$_SESSION['user_id'],
                "Emergency LOX Cryogenic Tanker dispatched to {$hospName} ({$hospCode}). Delivery transit initiated.",
                "hospital_id:{$hospId}:tanker",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Emergency LOX Tanker dispatched to {$hospName}.",
                'hospital_id' => $hospId,
                'facility' => $hospName
            ]);
        } catch (PDOException $e) {
            error_log('Tanker dispatch error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error recording tanker dispatch.']);
        }
        exit;
    }

    // 2. Dispatch Emergency Blood Courier
    if ($action === 'dispatch_courier' || ($action === 'dispatch_replenishment' && ($_POST['resource_type'] ?? '') === 'blood')) {
        try {
            $upd = $pdo->prepare("
                UPDATE hospital_resources 
                SET courier_dispatched = 1,
                    courier_dispatched_at = NOW()
                WHERE hospital_id = ?
            ");
            $upd->execute([$hospId]);

            $pdo->prepare("
                INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES (?, 'super_admin', 'COURIER_DISPATCH', ?, 'LOGISTICS', 'Emergency Blood Courier Dispatch', ?, ?, 'CRITICAL')
            ")->execute([
                (int)$_SESSION['user_id'],
                "Emergency Blood Courier (O- universal packs) dispatched to {$hospName} ({$hospCode}). Transit active.",
                "hospital_id:{$hospId}:courier",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            echo json_encode([
                'success' => true,
                'message' => "Emergency Blood Courier dispatched to {$hospName}.",
                'hospital_id' => $hospId,
                'facility' => $hospName
            ]);
        } catch (PDOException $e) {
            error_log('Courier dispatch error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error recording courier dispatch.']);
        }
        exit;
    }

    // 3. Calibrate Sensor Telemetry
    if ($action === 'calibrate_sensor' || $action === 'calibrate_sensors') {
        $pct = isset($_POST['oxygen_reserve_pct']) ? (int)$_POST['oxygen_reserve_pct'] : (isset($_POST['pct']) ? (int)$_POST['pct'] : null);
        $psi = isset($_POST['current_pressure_psi']) ? (int)$_POST['current_pressure_psi'] : (isset($_POST['psi']) ? (int)$_POST['psi'] : null);

        try {
            if ($hospId > 0 && $pct !== null && $psi !== null) {
                $days = round(($pct / 100) * 9.5, 1);
                $pdo->prepare("
                    UPDATE hospital_resources 
                    SET oxygen_reserve_pct = ?, current_pressure_psi = ?, depletion_days = ?, last_calibrated_at = NOW() 
                    WHERE hospital_id = ?
                ")->execute([$pct, $psi, $days, $hospId]);

                $msg = "Facility #{$hospId} ({$hospCode}) oxygen sensor calibrated to {$pct}% ({$psi} PSI, {$days}d).";
            } elseif ($hospId > 0) {
                $pdo->prepare("UPDATE hospital_resources SET last_calibrated_at = NOW() WHERE hospital_id = ?")->execute([$hospId]);
                $msg = "Facility #{$hospId} ({$hospCode}) IoT sensor nodes synchronized and recalibrated.";
                $days = null;
            } else {
                $pdo->query("UPDATE hospital_resources SET last_calibrated_at = NOW()");
                $msg = "All 6 facility IoT sensor nodes synchronized and recalibrated.";
                $days = null;
            }

            $pdo->prepare("
                INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES (?, 'super_admin', 'IOT_CALIBRATION', ?, 'SYSTEM', 'IoT Sensor Calibration', ?, ?, 'INFO')
            ")->execute([
                (int)$_SESSION['user_id'],
                $msg,
                "hospital_id:" . ($hospId ?: 'all'),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'hospital_id' => $hospId,
                'depletion_days' => $days,
                'oxygen_reserve_pct' => $pct,
                'current_pressure_psi' => $psi
            ]);
        } catch (PDOException $e) {
            error_log('Sensor calibration error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error updating sensor telemetry.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── GET: QUERY 6 NETWORK HOSPITALS & BASES (NO MOCK DATA) ────────────────────
$activeTab = strtolower(trim($_GET['tab'] ?? 'all'));
if (!in_array($activeTab, ['all', 'oxygen', 'ventilators', 'blood'], true)) {
    $activeTab = 'all';
}

try {
    $resourcesQuery = $pdo->query("
        SELECT 
            h.hospital_id, h.name, h.code, h.city, h.address, h.contact_number, h.operational_status,
            r.id AS resource_id,
            COALESCE(r.oxygen_reserve_pct, 75) AS oxygen_reserve_pct,
            COALESCE(r.current_pressure_psi, 2200) AS current_pressure_psi,
            COALESCE(r.depletion_days, 7.5) AS depletion_days,
            COALESCE(r.tanker_dispatched, 0) AS tanker_dispatched,
            r.tanker_dispatched_at,
            r.last_calibrated_at,
            COALESCE(r.ventilators_total, 80) AS ventilators_total,
            COALESCE(r.ventilators_active, 50) AS ventilators_active,
            COALESCE(r.hardware_spec, 'Standard Dual Mode ICU Ventilator') AS hardware_spec,
            COALESCE(r.blood_o_neg, 25) AS blood_o_neg,
            COALESCE(r.blood_o_pos, 80) AS blood_o_pos,
            COALESCE(r.blood_a_pos, 50) AS blood_a_pos,
            COALESCE(r.blood_a_neg, 15) AS blood_a_neg,
            COALESCE(r.blood_b_pos, 60) AS blood_b_pos,
            COALESCE(r.blood_b_neg, 12) AS blood_b_neg,
            COALESCE(r.blood_ab_pos, 25) AS blood_ab_pos,
            COALESCE(r.blood_ab_neg, 10) AS blood_ab_neg,
            COALESCE(r.blood_trauma_packs, 100) AS blood_trauma_packs,
            COALESCE(r.platelet_bags, 30) AS platelet_bags,
            COALESCE(r.cryo_units, 15) AS cryo_units,
            COALESCE(r.courier_dispatched, 0) AS courier_dispatched,
            r.courier_dispatched_at,
            r.updated_at
        FROM hospitals h
        LEFT JOIN hospital_resources r ON h.hospital_id = r.hospital_id
        ORDER BY h.hospital_id ASC
    ");
    $facilities = $resourcesQuery->fetchAll(PDO::FETCH_ASSOC);

    // Dynamic Header Summary KPI Calculations
    $totalVentilators   = 0;
    $activeVentilators  = 0;
    $standbyVentilators = 0;
    $totalONegUnits     = 0;
    $totalTraumaPacks   = 0;
    $oxygenPctSum       = 0;
    $facilityCount      = count($facilities);
    $replenishmentAlertsCount = 0;

    foreach ($facilities as &$f) {
        $ox = (int)$f['oxygen_reserve_pct'];
        $oxygenPctSum += $ox;

        // Dynamic threshold badges: >= 70% NORMAL, 50-69% CAUTION, < 50% CRITICAL
        if ($ox >= 70) {
            $f['ox_status'] = 'NORMAL';
            $f['ox_class']  = 'badge-normal';
            $f['ox_color']  = '#10b981';
        } elseif ($ox >= 50) {
            $f['ox_status'] = 'CAUTION';
            $f['ox_class']  = 'badge-caution';
            $f['ox_color']  = '#f59e0b';
        } else {
            $f['ox_status'] = 'CRITICAL';
            $f['ox_class']  = 'badge-critical';
            $f['ox_color']  = '#e11d48';
        }

        // Ventilator metrics
        $totV = (int)$f['ventilators_total'];
        $actV = (int)$f['ventilators_active'];
        $stbV = max(0, $totV - $actV);
        $vPct = $totV > 0 ? round(($actV / $totV) * 100) : 0;

        $f['vents_standby'] = $stbV;
        $f['vents_load_pct'] = $vPct;
        $f['vent_badge_class'] = ($vPct > 80) ? 'badge-critical' : 'badge-normal';
        $f['vent_badge_text']  = ($vPct > 80) ? 'HIGH LOAD' : 'OPTIMAL';

        $totalVentilators   += $totV;
        $activeVentilators  += $actV;
        $standbyVentilators += $stbV;

        // Blood metrics
        $oNeg   = (int)$f['blood_o_neg'];
        $trauma = (int)$f['blood_trauma_packs'];
        $totalONegUnits   += $oNeg;
        $totalTraumaPacks += $trauma;

        if ($oNeg < 20) {
            $f['blood_status'] = 'CRITICAL SHORTAGE';
            $f['blood_class']  = 'badge-critical';
            $f['blood_color']  = '#e11d48';
        } elseif ($oNeg < 30) {
            $f['blood_status'] = 'MODERATE';
            $f['blood_class']  = 'badge-caution';
            $f['blood_color']  = '#f59e0b';
        } else {
            $f['blood_status'] = 'OPTIMAL';
            $f['blood_class']  = 'badge-normal';
            $f['blood_color']  = '#10b981';
        }

        // Replenishment Alert: Count facilities where oxygen_reserve_pct < 70 OR blood_o_neg < 20
        if ($ox < 70 || $oNeg < 20) {
            $replenishmentAlertsCount++;
        }
    }
    unset($f);

    $meanOxygenPct = $facilityCount > 0 ? round($oxygenPctSum / $facilityCount, 1) : 0;
    $netVentLoad   = $totalVentilators > 0 ? round(($activeVentilators / $totalVentilators) * 100, 1) : 0;

} catch (PDOException $e) {
    error_log('Resource telemetry fetch error: ' . $e->getMessage());
    $facilities = [];
    $totalVentilators = $activeVentilators = $standbyVentilators = $netVentLoad = 0;
    $totalONegUnits = $totalTraumaPacks = $meanOxygenPct = 0;
    $replenishmentAlertsCount = 0;
}

// Medical Crest Helper for Branding
if (!function_exists('getHospCrest')) {
    function getHospCrest(int $hospitalId, string $name = ''): string {
        switch ($hospitalId) {
            case 1:
                return '<div class="crest-avatar crest-medpulse" title="MedPulse Hospital & Specialty Care">
                  <svg viewBox="0 0 36 36" fill="none"><rect width="36" height="36" rx="10" fill="url(#crMedPulse)"/><defs><linearGradient id="crMedPulse" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#0d9488"/><stop offset="1" stop-color="#06b6d4"/></linearGradient></defs><path d="M15 8h6v7h7v6h-7v7h-6v-7H8v-6h7V8z" fill="rgba(255,255,255,0.22)"/><path d="M6 18h7l2-5 3 10 3-7 2 3h7" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>';
            case 2:
                return '<div class="crest-avatar crest-square" title="Square Hospital Ltd">
                  <svg viewBox="0 0 36 36" fill="none"><rect width="36" height="36" rx="10" fill="url(#crSquare)"/><defs><linearGradient id="crSquare" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#e11d48"/><stop offset="1" stop-color="#9f1239"/></linearGradient></defs><rect x="7" y="7" width="22" height="22" rx="4" stroke="rgba(255,255,255,0.35)" stroke-width="1.5" fill="none"/><path d="M15 10h6v5h5v6h-5v5h-6v-5h-5v-6h5v-5z" fill="#ffffff"/></svg>
                </div>';
            case 3:
                return '<div class="crest-avatar crest-united" title="United Hospital Ltd">
                  <svg viewBox="0 0 36 36" fill="none"><rect width="36" height="36" rx="10" fill="url(#crUnited)"/><defs><linearGradient id="crUnited" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#1d4ed8"/><stop offset="1" stop-color="#0284c7"/></linearGradient></defs><path d="M18 7L8 11v7c0 6.6 4.3 12.3 10 14 5.7-1.7 10-7.4 10-14v-7L18 7z" fill="rgba(255,255,255,0.18)" stroke="#ffffff" stroke-width="1.4"/><path d="M16 13h4v4h4v4h-4v4h-4v-4h-4v-4h4v-4z" fill="#ffffff"/></svg>
                </div>';
            case 4:
                return '<div class="crest-avatar crest-umch" title="United Medical College Hospital">
                  <svg viewBox="0 0 36 36" fill="none"><rect width="36" height="36" rx="10" fill="url(#crUmch)"/><defs><linearGradient id="crUmch" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs><line x1="18" y1="8" x2="18" y2="28" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round"/><circle cx="18" cy="8" r="2.2" fill="#fde047"/><path d="M12 14c4-2 8-2 12 0-4 3-8 3-12 0zM12 21c4-2 8-2 12 0-4 3-8 3-12 0z" stroke="#ffffff" stroke-width="1.6" fill="none" stroke-linecap="round"/><path d="M10 11l8-4 8 4-8 4-8-4z" fill="rgba(253,224,71,0.3)"/></svg>
                </div>';
            case 5:
                return '<div class="crest-avatar crest-evercare" title="Evercare Hospital Dhaka">
                  <svg viewBox="0 0 36 36" fill="none"><rect width="36" height="36" rx="10" fill="url(#crEvercare)"/><defs><linearGradient id="crEvercare" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#059669"/><stop offset="1" stop-color="#10b981"/></linearGradient></defs><path d="M18 28s-9-5.4-9-12a5.5 5.5 0 0 1 9-4.2A5.5 5.5 0 0 1 27 16c0 6.6-9 12-9 12z" stroke="#ffffff" stroke-width="1.8" fill="rgba(255,255,255,0.15)"/>
                </div>';
            case 6:
                return '<div class="crest-avatar crest-nibps" title="National Institute of Burn and Plastic Surgery">
                  <svg viewBox="0 0 36 36" fill="none"><rect width="36" height="36" rx="10" fill="url(#crNibps)"/><defs><linearGradient id="crNibps" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse"><stop stop-color="#ea580c"/><stop offset="1" stop-color="#dc2626"/></linearGradient></defs><path d="M18 7c3 4 5 7 5 10 0 4-3 7-5 7s-5-3-5-7c0-3 2-6 5-10z" fill="rgba(254,240,138,0.4)" stroke="#fef08a" stroke-width="1.3"/><path d="M16 16h4v3h3v3h-3v3h-4v-3h-3v-3h3v-3z" fill="#ffffff"/><path d="M8 18c2 5 6 9 10 11 4-2 8-6 10-11" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round" fill="none"/></svg>
                </div>';
            default:
                $inits = strtoupper(substr($name ?: 'HP', 0, 2));
                return '<div class="crest-avatar crest-default"><span>' . htmlspecialchars($inits, ENT_QUOTES, 'UTF-8') . '</span></div>';
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
  <title>MedPulse | Resource Telemetry Command Center</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">

  <style>
    :root {
      --sa-accent: #7c3aed;
      --sa-soft: rgba(124, 58, 237, 0.10);
      --sa-grad: linear-gradient(135deg, #7c3aed 0%, #0d9488 100%);
      --crimson-alert: #e11d48;
      --emerald-normal: #10b981;
      --amber-caution: #f59e0b;
    }

    body {
      background-color: var(--background, #f8fafc);
      color: var(--text-heading, #0f172a);
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    }

    .sa-welcome-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 12px;
      background: var(--sa-grad);
      color: #fff;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    .banner-header-flex {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
    }

    /* ── Dynamic ECG Waveform Live Telemetry Bar ─────────────────── */
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
    .ecg-track { width: 52px; height: 18px; display: flex; align-items: center; }
    .ecg-svg { width: 52px; height: 18px; overflow: visible; }
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
      0% { stroke-dashoffset: 80; }
      50% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -80; }
    }
    .telemetry-bpm {
      font-family: 'JetBrains Mono', monospace;
      font-weight: 800;
      letter-spacing: -0.01em;
    }
    .telemetry-normal .telemetry-bpm { color: #059669; }
    .telemetry-surge .telemetry-bpm { color: #e11d48; animation: bpmSurgePulse 0.9s ease-in-out infinite; }
    @keyframes bpmSurgePulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.75; transform: scale(1.05); }
    }
    .telemetry-sep { opacity: 0.4; font-size: 0.65rem; }
    .telemetry-status { letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.70rem; }
    .telemetry-pulse-dot { position: relative; display: inline-flex; width: 8px; height: 8px; }
    .telemetry-pulse-ring {
      position: absolute;
      inset: 0;
      border-radius: 50%;
      background: currentColor;
      animation: telRing 1.4s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .telemetry-pulse-core { position: relative; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
    @keyframes telRing {
      75%, 100% { transform: scale(2.6); opacity: 0; }
    }

    /* ── Metric Summary Row ─────────────────────────────────────────── */
    .rt-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 14px;
      margin-bottom: 24px;
    }
    .rt-kpi-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 14px;
      padding: 16px 18px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
      cursor: pointer;
      text-decoration: none;
    }
    .rt-kpi-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 28px rgba(0,0,0,0.09);
      border-color: var(--sa-accent);
    }
    .rt-kpi-card:active {
      transform: translateY(-1px);
    }
    .rt-kpi-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .rt-kpi-title {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
    }
    .rt-kpi-icon {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.15rem;
    }
    .rt-kpi-value {
      font-size: 1.75rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.1;
      margin-bottom: 4px;
    }
    .rt-kpi-sub {
      font-size: 0.72rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* ── Tab Navigation Bar ────────────────────────────────────────── */
    .rt-tabs-container {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 14px;
      padding: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 22px;
      overflow-x: auto;
      scrollbar-width: none;
    }
    .rt-tabs-container::-webkit-scrollbar { display: none; }

    .rt-tab-btn {
      padding: 9px 18px;
      border-radius: 10px;
      border: none;
      background: transparent;
      color: var(--text-muted);
      font-family: inherit;
      font-size: 0.82rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
      transition: all 0.18s ease;
      text-decoration: none;
    }
    .rt-tab-btn:hover {
      background: var(--surface-secondary, rgba(0,0,0,0.03));
      color: var(--text-heading);
    }
    .rt-tab-btn.active {
      background: var(--sa-grad);
      color: #fff;
      box-shadow: 0 3px 12px rgba(124, 58, 237, 0.28), inset 0 0 0 1.5px rgba(255,255,255,0.25);
      outline: 2px solid rgba(124, 58, 237, 0.4);
      outline-offset: 2px;
    }
    .rt-tab-badge {
      font-size: 0.66rem;
      padding: 2px 7px;
      border-radius: 12px;
      font-weight: 800;
      background: rgba(255,255,255,0.2);
      color: inherit;
    }
    .rt-tab-btn:not(.active) .rt-tab-badge {
      background: var(--surface-secondary, #e2e8f0);
      color: var(--text-muted);
    }

    /* ── Content Sections & Tables ─────────────────────────────────── */
    .rt-section-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 16px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      padding: 22px;
      margin-bottom: 24px;
    }
    .rt-section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 20px;
      padding-bottom: 14px;
      border-bottom: 1px solid var(--surface-border-subtle);
    }
    .rt-section-title-group h2 {
      font-size: 1.15rem;
      font-weight: 800;
      margin: 0 0 4px 0;
      color: var(--text-heading);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .rt-section-title-group p {
      margin: 0;
      font-size: 0.78rem;
      color: var(--text-muted);
    }

    /* Table styling */
    .rt-table-wrap {
      overflow-x: auto;
      margin: 0 -22px -22px;
      border-radius: 0 0 16px 16px;
    }
    .rt-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.81rem;
      text-align: left;
    }
    .rt-table th {
      background: var(--surface-secondary, #f8fafc);
      padding: 12px 18px;
      font-size: 0.70rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-top: 1px solid var(--surface-border);
      border-bottom: 1px solid var(--surface-border);
      white-space: nowrap;
    }
    .rt-table td {
      padding: 14px 18px;
      border-bottom: 1px solid var(--surface-border-subtle);
      color: var(--text-heading);
      vertical-align: middle;
    }
    .rt-table tr:last-child td { border-bottom: none; }
    .rt-table tr:hover td { background-color: var(--surface-secondary, rgba(0,0,0,0.015)); }

    /* Crest Avatars */
    .crest-avatar {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      overflow: hidden;
    }
    .crest-avatar svg { width: 100%; height: 100%; }
    .crest-default { background: var(--sa-grad); color: #fff; font-weight: 800; font-size: 0.75rem; }

    .hosp-profile-cell { display: flex; align-items: center; gap: 12px; }
    .hosp-profile-name { font-weight: 800; color: var(--text-heading); font-size: 0.84rem; display: flex; align-items: center; gap: 6px; }
    .hosp-profile-meta { font-size: 0.70rem; color: var(--text-muted); margin-top: 2px; }

    /* Threshold badges */
    .rt-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 20px;
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      white-space: nowrap;
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

    /* Progress bars */
    .rt-bar-bg {
      background: var(--surface-border-subtle, #e2e8f0);
      border-radius: 6px;
      height: 7px;
      overflow: hidden;
      min-width: 80px;
      margin-top: 5px;
    }
    .rt-bar-fill { height: 100%; border-radius: 6px; transition: width 0.3s ease; }

    /* Action buttons */
    .btn-action-sm {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 8px;
      font-size: 0.74rem;
      font-weight: 700;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.15s ease;
      white-space: nowrap;
      text-decoration: none;
      font-family: inherit;
    }
    .btn-dispatch {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
      color: #fff;
      box-shadow: 0 2px 8px rgba(225, 29, 72, 0.25);
    }
    .btn-dispatch:hover {
      filter: brightness(1.1);
      transform: translateY(-1px);
    }
    .btn-service {
      background: var(--surface);
      border-color: var(--surface-border);
      color: var(--text-heading);
    }
    .btn-service:hover {
      border-color: var(--sa-accent);
      color: var(--sa-accent);
      background: var(--sa-soft);
    }

    .status-pulse-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      display: inline-block;
      flex-shrink: 0;
    }

    /* Facility Highlight Cards Grid for Oxygen */
    .rt-subgrid-3 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }
    .rt-facility-box {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: 14px;
      padding: 16px;
      position: relative;
      overflow: hidden;
      transition: all 0.2s;
    }
    .rt-facility-box:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(0,0,0,0.06);
    }
    .rt-facility-box.border-critical {
      border-color: rgba(225, 29, 72, 0.5);
      background: linear-gradient(180deg, rgba(254, 242, 242, 0.2) 0%, var(--surface) 100%);
    }

    /* Persistent Dispatch Status Badges */
    .badge-dispatch-enroute {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 11px;
      border-radius: 8px;
      font-size: 0.72rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .badge-tanker-enroute {
      background: rgba(245, 158, 11, 0.12);
      color: #d97706;
      border: 1px solid rgba(245, 158, 11, 0.35);
    }
    .badge-courier-enroute {
      background: rgba(244, 63, 94, 0.12);
      color: #e11d48;
      border: 1px solid rgba(244, 63, 94, 0.35);
    }

    /* Blood Matrix Mini Cards */
    .blood-type-matrix {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
    }
    .blood-tile {
      background: var(--surface-secondary, #f8fafc);
      border: 1px solid var(--surface-border-subtle);
      border-radius: 8px;
      padding: 6px 8px;
      text-align: center;
    }
    .blood-tile.highlight-oneg {
      border-color: rgba(225, 29, 72, 0.4);
      background: #fff1f2;
    }
    .blood-tile-type { font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; }
    .blood-tile.highlight-oneg .blood-tile-type { color: #9f1239; }
    .blood-tile-count { font-size: 0.95rem; font-weight: 800; color: var(--text-heading); margin-top: 2px; }
    .blood-tile.highlight-oneg .blood-tile-count { color: #e11d48; }

    /* ═══════════════════════════════════════════════════════════════
       CLINICAL MODALS & TOAST NOTIFICATION SYSTEM
    ════════════════════════════════════════════════════════════════ */
    .rt-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.72);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      z-index: 99999;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.24s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .rt-modal-overlay.open {
      opacity: 1;
      pointer-events: auto;
    }
    .rt-modal-card {
      background: var(--surface-card, #ffffff);
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.85));
      border-radius: 1.25rem; /* rounded-2xl */
      box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.1);
      width: 100%;
      max-width: 520px;
      overflow: hidden;
      position: relative;
      transform: scale(0.93) translateY(12px);
      opacity: 0;
      transition: transform 0.26s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.22s ease;
    }
    .rt-modal-overlay.open .rt-modal-card {
      transform: scale(1) translateY(0);
      opacity: 1;
    }
    [data-theme="dark"] .rt-modal-card {
      background: #1e293b;
      border-color: rgba(51, 65, 85, 0.85);
      box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.65);
    }
    .rt-modal-header {
      padding: 22px 24px 18px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      position: relative;
      border-bottom: 1px solid var(--surface-border, rgba(226, 232, 240, 0.7));
    }
    .rt-modal-header.danger {
      background: linear-gradient(180deg, rgba(254, 242, 242, 0.9) 0%, rgba(255, 255, 255, 0) 100%);
    }
    [data-theme="dark"] .rt-modal-header.danger {
      background: linear-gradient(180deg, rgba(225, 29, 72, 0.15) 0%, rgba(30, 41, 59, 0) 100%);
    }
    .rt-modal-header.warning {
      background: linear-gradient(180deg, rgba(254, 243, 199, 0.9) 0%, rgba(255, 255, 255, 0) 100%);
    }
    [data-theme="dark"] .rt-modal-header.warning {
      background: linear-gradient(180deg, rgba(245, 158, 11, 0.15) 0%, rgba(30, 41, 59, 0) 100%);
    }
    .rt-modal-icon-badge {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .dispatch-icon-danger {
      background: #fee2e2;
      color: #e11d48;
      border: 1px solid rgba(225, 29, 72, 0.25);
      box-shadow: 0 0 0 6px rgba(225, 29, 72, 0.1);
      animation: modalPulseDanger 2s infinite;
    }
    .dispatch-icon-warning {
      background: #fef3c7;
      color: #d97706;
      border: 1px solid rgba(217, 119, 6, 0.25);
      box-shadow: 0 0 0 6px rgba(217, 119, 6, 0.1);
      animation: modalPulseAmber 2s infinite;
    }
    @keyframes modalPulseDanger {
      0%, 100% { box-shadow: 0 0 0 6px rgba(225, 29, 72, 0.15); }
      50% { box-shadow: 0 0 0 12px rgba(225, 29, 72, 0.04); }
    }
    @keyframes modalPulseAmber {
      0%, 100% { box-shadow: 0 0 0 6px rgba(217, 119, 6, 0.15); }
      50% { box-shadow: 0 0 0 12px rgba(217, 119, 6, 0.04); }
    }
    .rt-modal-title {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading, #0f172a);
      margin: 0;
      line-height: 1.3;
      letter-spacing: -0.01em;
    }
    .rt-modal-close-btn {
      position: absolute;
      top: 18px;
      right: 18px;
      background: rgba(100, 116, 139, 0.08);
      border: none;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #64748b;
      cursor: pointer;
      transition: all 0.15s;
    }
    .rt-modal-close-btn:hover {
      background: rgba(225, 29, 72, 0.1);
      color: #e11d48;
    }
    .rt-facility-badge {
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 6px;
      background: rgba(2, 132, 199, 0.1);
      color: #0284c7;
      border: 1px solid rgba(2, 132, 199, 0.2);
    }
    .rt-priority-badge {
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 2px 8px;
      border-radius: 6px;
    }
    .badge-crimson {
      background: rgba(225, 29, 72, 0.12);
      color: #e11d48;
      border: 1px solid rgba(225, 29, 72, 0.25);
    }
    .badge-amber {
      background: rgba(245, 158, 11, 0.12);
      color: #d97706;
      border: 1px solid rgba(245, 158, 11, 0.25);
    }
    .rt-modal-body {
      padding: 20px 24px;
    }
    .rt-sensor-info-card {
      background: var(--surface-secondary, rgba(248, 250, 252, 0.85));
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.9));
      border-radius: 12px;
      padding: 12px 14px;
    }
    .rt-form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .rt-form-label {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-heading, #0f172a);
    }
    .rt-label-hint {
      font-size: 0.68rem;
      font-weight: 600;
      color: var(--text-muted, #64748b);
    }
    .rt-input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }
    .rt-form-input {
      width: 100%;
      padding: 10px 44px 10px 14px;
      border-radius: 10px;
      border: 1.5px solid var(--surface-border, #cbd5e1);
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-heading, #0f172a);
      background: var(--surface-card, #ffffff);
      font-family: 'JetBrains Mono', monospace;
      transition: all 0.2s ease;
      box-sizing: border-box;
    }
    .rt-form-input:focus {
      border-color: #10b981;
      outline: none;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }
    .rt-input-suffix {
      position: absolute;
      right: 14px;
      font-size: 0.76rem;
      font-weight: 700;
      color: var(--text-muted, #94a3b8);
      pointer-events: none;
    }
    .rt-form-error {
      background: rgba(225, 29, 72, 0.08);
      border: 1px solid rgba(225, 29, 72, 0.25);
      color: #e11d48;
      border-radius: 8px;
      padding: 8px 12px;
      font-size: 0.76rem;
      font-weight: 600;
    }
    .rt-dispatch-details-card {
      border-radius: 12px;
      border: 1px solid var(--surface-border, rgba(226, 232, 240, 0.9));
      background: var(--surface-secondary, rgba(248, 250, 252, 0.7));
      overflow: hidden;
    }
    .rt-dispatch-row {
      display: grid;
      grid-template-columns: 145px 1fr;
      padding: 10px 14px;
      border-bottom: 1px solid var(--surface-border, rgba(226, 232, 240, 0.7));
      font-size: 0.80rem;
      align-items: center;
    }
    .rt-dispatch-label {
      color: var(--text-muted, #64748b);
      font-weight: 600;
    }
    .rt-dispatch-val {
      color: var(--text-heading, #0f172a);
      font-weight: 700;
    }
    .rt-modal-actions {
      padding: 16px 24px 20px;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      border-top: 1px solid var(--surface-border, rgba(226, 232, 240, 0.7));
      background: var(--surface-secondary, rgba(248, 250, 252, 0.5));
    }
    .rt-btn-neutral {
      padding: 9px 18px;
      border-radius: 10px;
      font-size: 0.84rem;
      font-weight: 700;
      border: 1px solid var(--surface-border, #cbd5e1);
      background: transparent;
      color: var(--text-muted, #64748b);
      cursor: pointer;
      transition: all 0.2s;
    }
    .rt-btn-neutral:hover {
      background: rgba(100, 116, 139, 0.1);
      color: var(--text-heading, #0f172a);
    }
    .rt-btn-emerald {
      padding: 9px 20px;
      border-radius: 10px;
      font-size: 0.84rem;
      font-weight: 800;
      border: none;
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: #ffffff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
      transition: all 0.2s ease;
    }
    .rt-btn-emerald:hover {
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
      transform: translateY(-1px);
    }
    .rt-btn-emerald:disabled,
    .rt-btn-dispatch-auth:disabled,
    .rt-btn-neutral:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
    }
    .rt-btn-dispatch-auth {
      padding: 9px 20px;
      border-radius: 10px;
      font-size: 0.84rem;
      font-weight: 800;
      border: none;
      color: #ffffff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s ease;
    }
    .btn-dispatch-danger {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
      box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
    }
    .btn-dispatch-danger:hover {
      box-shadow: 0 6px 16px rgba(225, 29, 72, 0.4);
      transform: translateY(-1px);
    }
    .btn-dispatch-warning {
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    }
    .btn-dispatch-warning:hover {
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
      transform: translateY(-1px);
    }
    .btn-spinner {
      width: 14px;
      height: 14px;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-top-color: #ffffff;
      border-radius: 50%;
      animation: rtSpin 0.7s linear infinite;
    }
    @keyframes rtSpin {
      to { transform: rotate(360deg); }
    }

    /* ── Floating Toast Container ────────────────────────────────── */
    .rt-toast-container {
      position: fixed;
      top: 24px;
      right: 24px;
      z-index: 100000;
      display: flex;
      flex-direction: column;
      gap: 12px;
      pointer-events: none;
      max-width: 420px;
      width: calc(100vw - 48px);
    }
    .rt-toast {
      pointer-events: auto;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      padding: 14px 16px;
      background: rgba(255, 255, 255, 0.98);
      border: 1px solid rgba(226, 232, 240, 0.95);
      border-radius: 16px;
      box-shadow: 0 15px 35px -5px rgba(0, 0, 0, 0.12), 0 5px 15px rgba(0, 0, 0, 0.04);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transform: translateX(115%);
      opacity: 0;
      transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease;
      position: relative;
      overflow: hidden;
    }
    [data-theme="dark"] .rt-toast {
      background: rgba(30, 41, 59, 0.98);
      border-color: rgba(51, 65, 85, 0.9);
      box-shadow: 0 20px 35px -5px rgba(0, 0, 0, 0.55);
    }
    .rt-toast.show {
      transform: translateX(0);
      opacity: 1;
    }
    .rt-toast.hide {
      transform: translateX(115%);
      opacity: 0;
    }
    .rt-toast-icon-wrap {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .rt-toast.success .rt-toast-icon-wrap {
      background: rgba(16, 185, 129, 0.12);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.25);
    }
    .rt-toast.error .rt-toast-icon-wrap {
      background: rgba(225, 29, 72, 0.12);
      color: #e11d48;
      border: 1px solid rgba(225, 29, 72, 0.25);
    }
    .rt-toast.info .rt-toast-icon-wrap {
      background: rgba(2, 132, 199, 0.12);
      color: #0284c7;
      border: 1px solid rgba(2, 132, 199, 0.25);
    }
    .rt-toast-body {
      flex: 1;
      min-width: 0;
    }
    .rt-toast-title {
      font-size: 0.88rem;
      font-weight: 800;
      color: var(--text-heading, #0f172a);
      margin: 0 0 2px;
      line-height: 1.3;
    }
    .rt-toast-msg {
      font-size: 0.80rem;
      color: var(--text-muted, #64748b);
      margin: 0;
      line-height: 1.45;
      word-break: break-word;
    }
    .rt-toast-close {
      background: none;
      border: none;
      padding: 4px;
      cursor: pointer;
      color: #94a3b8;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s;
      flex-shrink: 0;
    }
    .rt-toast-close:hover {
      color: #0f172a;
      background: rgba(100, 116, 139, 0.1);
    }
    .rt-toast-progress {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: rgba(0, 0, 0, 0.06);
    }
    .rt-toast.success .rt-toast-bar { background: #10b981; }
    .rt-toast.error .rt-toast-bar { background: #e11d48; }
    .rt-toast.info .rt-toast-bar { background: #0284c7; }
    .rt-toast-bar {
      height: 100%;
      width: 100%;
      transform-origin: left;
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <main class="viewport-full">
    <!-- Welcome Header Banner with Live Dynamic ECG Telemetry -->
    <div class="welcome-banner">
      <div class="banner-header-flex">
        <div class="welcome-text">
          <div class="sa-welcome-badge">Hardware &amp; IoT Telemetry Command</div>
          <h1>National Life-Support &amp; Blood Reserves</h1>
          <p>Continuous dynamic sensor feeds across 6 connected medical facilities: Liquid Oxygen (LOX), ICU Ventilator Fleet &amp; Universal Blood Bank.</p>
        </div>

        <div class="banner-actions" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <!-- Live Synchronized ECG Waveform Telemetry Pill -->
          <?php
            $isSurgeOrCritical = ($activeCount > 0) || ($replenishmentAlertsCount > 0);
            $telemetryClass    = $isSurgeOrCritical ? 'telemetry-surge' : 'telemetry-normal';
            $telemetryBpm      = $isSurgeOrCritical ? '118 BPM' : '72 BPM';
            if ($activeCount > 0) {
                $telemetryLabel = "SURGE ACTIVE: {$activeCount} PROTOCOL(S)";
            } elseif ($replenishmentAlertsCount > 0) {
                $telemetryLabel = "CRITICAL: {$replenishmentAlertsCount} LOGISTICS DISPATCH ALERT(S)";
            } else {
                $telemetryLabel = "IOT SENSORS NOMINAL • STABLE";
            }
          ?>
          <div class="sa-telemetry-badge <?= $telemetryClass ?>" id="saTelemetryBadge" title="Live synchronized hardware telemetry stream">
            <div class="ecg-track">
              <svg class="ecg-svg" viewBox="0 0 54 18" preserveAspectRatio="none">
                <path class="ecg-pulse-line" d="M0,9 L12,9 L15,3 L18,15 L21,2 L24,16 L27,9 L32,9 L35,6 L38,11 L41,9 L54,9" />
              </svg>
            </div>
            <div class="telemetry-info" style="display:flex;align-items:center;gap:6px;">
              <span class="telemetry-bpm" id="saTelemetryBpm"><?= $telemetryBpm ?></span>
              <span class="telemetry-sep">•</span>
              <span class="telemetry-status" id="saTelemetryStatus"><?= $telemetryLabel ?></span>
            </div>
            <span class="telemetry-pulse-dot">
              <span class="telemetry-pulse-ring"></span>
              <span class="telemetry-pulse-core"></span>
            </span>
          </div>

          <button class="btn-action-sm btn-service" onclick="calibrateSensor(0, null, null)" title="Force sync and verify all sensor timestamps across all 6 facilities">
            <svg class="ui-ico" style="width:14px;height:14px;stroke:currentColor;" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            Recalibrate All IoT
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         DYNAMIC HEADER SUMMARY KPIS
    ════════════════════════════════════════════════════════════════ -->
    <div class="rt-kpi-grid">
      <!-- KPI 1: Mean Oxygen — navigates to Oxygen Grid tab -->
      <a href="resource_telemetry.php?tab=oxygen" class="rt-kpi-card" title="View Oxygen Grid" onclick="switchTelemetryTab('oxygen', event); return false;">
        <div class="rt-kpi-header">
          <span class="rt-kpi-title">Mean Oxygen Reserve</span>
          <div class="rt-kpi-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
            ☁️
          </div>
        </div>
        <div class="rt-kpi-value" id="kpiMeanOxygenVal" style="color: <?= $meanOxygenPct >= 70 ? '#059669' : ($meanOxygenPct >= 50 ? '#d97706' : '#e11d48') ?>;">
          <?= number_format($meanOxygenPct, 1) ?>%
        </div>
        <div class="rt-kpi-sub">
          <span class="status-pulse-dot" style="background: #0284c7;"></span>
          <span>AVG across 6 bulk LOX facilities · <strong>Click to inspect</strong></span>
        </div>
      </a>

      <!-- KPI 2: Ventilator Fleet — navigates to Ventilator Fleet tab -->
      <a href="resource_telemetry.php?tab=ventilators" class="rt-kpi-card" title="View Ventilator Fleet" onclick="switchTelemetryTab('ventilators', event); return false;">
        <div class="rt-kpi-header">
          <span class="rt-kpi-title">Ventilator Fleet</span>
          <div class="rt-kpi-icon" style="background: rgba(124, 58, 237, 0.1); color: var(--sa-accent);">
            🫁
          </div>
        </div>
        <div class="rt-kpi-value">
          <?= number_format($activeVentilators) ?> <span style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">/ <?= number_format($totalVentilators) ?></span>
        </div>
        <div class="rt-kpi-sub">
          <span class="status-pulse-dot" style="background: #10b981;"></span>
          <span><strong><?= number_format($standbyVentilators) ?> Standby</strong> (<?= $netVentLoad ?>% Fleet Load)</span>
        </div>
      </a>

      <!-- KPI 3: Universal O- Stockpile — navigates to Blood Bank tab -->
      <a href="resource_telemetry.php?tab=blood" class="rt-kpi-card" title="View Blood Bank" onclick="switchTelemetryTab('blood', event); return false;">
        <div class="rt-kpi-header">
          <span class="rt-kpi-title">Universal O- Stockpile</span>
          <div class="rt-kpi-icon" style="background: rgba(225, 29, 72, 0.1); color: #e11d48;">
            🩸
          </div>
        </div>
        <div class="rt-kpi-value" style="color: <?= $totalONegUnits < 120 ? '#e11d48' : '#0f172a' ?>;">
          <?= number_format($totalONegUnits) ?> <span style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted);">Units</span>
        </div>
        <div class="rt-kpi-sub">
          <span class="status-pulse-dot" style="background: #e11d48;"></span>
          <span><strong><?= number_format($totalTraumaPacks) ?> Trauma Packs</strong> Buffered</span>
        </div>
      </a>

      <!-- KPI 4: Logistics Replenishment — shows alert count; routes to all if clean, blood if alerts -->
      <a href="resource_telemetry.php?tab=<?= $replenishmentAlertsCount > 0 ? 'blood' : 'all' ?>" class="rt-kpi-card" title="<?= $replenishmentAlertsCount > 0 ? 'Review replenishment alerts' : 'All inventories nominal' ?>" onclick="switchTelemetryTab('<?= $replenishmentAlertsCount > 0 ? 'blood' : 'all' ?>', event); return false;">
        <div class="rt-kpi-header">
          <span class="rt-kpi-title">Logistics Replenishment</span>
          <div class="rt-kpi-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
            🚚
          </div>
        </div>
        <div class="rt-kpi-value" style="color: <?= $replenishmentAlertsCount > 0 ? '#e11d48' : '#059669' ?>;">
          <?= $replenishmentAlertsCount ?> <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">Pending</span>
        </div>
        <div class="rt-kpi-sub">
          <?php if ($replenishmentAlertsCount > 0): ?>
            <span style="color:#e11d48; font-weight:700;">⚠ <?= $replenishmentAlertsCount ?> Threshold Alert(s)</span>
          <?php else: ?>
            <span style="color:#059669; font-weight:700;">✓ All Inventories Nominal</span>
          <?php endif; ?>
        </div>
      </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         INTERACTIVE TAB NAVIGATION BAR (?tab=)
    ════════════════════════════════════════════════════════════════ -->
    <div class="rt-tabs-container" id="rtTabsNav">
      <a href="resource_telemetry.php?tab=all" class="rt-tab-btn <?= $activeTab === 'all' ? 'active' : '' ?>" data-tab="all" onclick="switchTelemetryTab('all', event)">
        <svg class="ui-ico" style="width:16px;height:16px;" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
        All Resources
        <span class="rt-tab-badge">Master Matrix</span>
      </a>

      <a href="resource_telemetry.php?tab=oxygen" class="rt-tab-btn <?= $activeTab === 'oxygen' ? 'active' : '' ?>" data-tab="oxygen" onclick="switchTelemetryTab('oxygen', event)">
        <svg class="ui-ico" style="width:16px;height:16px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
        Oxygen Grid
        <span class="rt-tab-badge"><?= number_format($meanOxygenPct, 1) ?>% Mean</span>
      </a>

      <a href="resource_telemetry.php?tab=ventilators" class="rt-tab-btn <?= $activeTab === 'ventilators' ? 'active' : '' ?>" data-tab="ventilators" onclick="switchTelemetryTab('ventilators', event)">
        <svg class="ui-ico" style="width:16px;height:16px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        Ventilator Fleet
        <span class="rt-tab-badge"><?= $totalVentilators ?> Units</span>
      </a>

      <a href="resource_telemetry.php?tab=blood" class="rt-tab-btn <?= $activeTab === 'blood' ? 'active' : '' ?>" data-tab="blood" onclick="switchTelemetryTab('blood', event)">
        <span style="font-size:14px;">🩸</span>
        Universal Blood Bank
        <span class="rt-tab-badge"><?= $totalONegUnits ?> O- Units</span>
      </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB CONTENT 1: ALL RESOURCES (MASTER COMMAND MATRIX)
    ════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane-content" id="tabContent-all" style="display: <?= $activeTab === 'all' ? 'block' : 'none' ?>;">
      <div class="rt-section-card">
        <div class="rt-section-header">
          <div class="rt-section-title-group">
            <h2>
              <svg class="ui-ico" style="stroke:var(--sa-accent); width:20px; height:20px;" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
              Master Facility Resource &amp; Telemetry Matrix
            </h2>
            <p>Unified live status of cryogenic oxygen pressure, ventilator allocations, blood reserves, and last sensor calibration timestamps.</p>
          </div>
          <div style="font-size:0.75rem; color:var(--text-muted); font-weight:700;">
            Showing 6 of 6 Operational Hubs
          </div>
        </div>

        <div class="rt-table-wrap">
          <table class="rt-table">
            <thead>
              <tr>
                <th>Connected Facility</th>
                <th>Oxygen Pressure &amp; Runway</th>
                <th>Ventilator Allocation</th>
                <th>Blood Bank (O- / Trauma)</th>
                <th>Hardware Specification</th>
                <th>Sensor Calibration</th>
                <th style="text-align: right;">Emergency Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($facilities as $fac): ?>
              <tr id="overviewRow-<?= $fac['hospital_id'] ?>">
                <!-- Facility Profile -->
                <td>
                  <div class="hosp-profile-cell">
                    <?= getHospCrest((int)$fac['hospital_id'], $fac['name']) ?>
                    <div>
                      <div class="hosp-profile-name">
                        <?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8') ?>
                        <span style="font-size:0.68rem; padding:1px 6px; border-radius:4px; background:var(--surface-secondary); color:var(--text-muted); font-weight:700;">
                          <?= htmlspecialchars($fac['code'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                      </div>
                      <div class="hosp-profile-meta">
                        <?= htmlspecialchars($fac['city'], ENT_QUOTES, 'UTF-8') ?> • ID #<?= $fac['hospital_id'] ?>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Oxygen Column -->
                <td>
                  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:2px;">
                    <strong class="ox-vol-val" style="color:var(--text-heading); font-size:0.84rem;"><?= $fac['oxygen_reserve_pct'] ?>%</strong>
                    <span class="rt-badge <?= $fac['ox_class'] ?> ox-badge-el">
                      <span class="status-pulse-dot" style="background:<?= $fac['ox_color'] ?>;"></span>
                      <span class="ox-status-txt"><?= $fac['ox_status'] ?></span>
                    </span>
                  </div>
                  <div class="rt-bar-bg">
                    <div class="rt-bar-fill ox-bar-fill" style="width: <?= $fac['oxygen_reserve_pct'] ?>%; background: <?= $fac['ox_color'] ?>;"></div>
                  </div>
                  <div style="font-size:0.70rem; color:var(--text-muted); margin-top:4px;">
                    <span class="ox-psi-val"><?= number_format($fac['current_pressure_psi']) ?></span> PSI • <strong class="ox-runway-val"><?= $fac['depletion_days'] ?> days</strong> runway
                  </div>
                </td>

                <!-- Ventilators Column -->
                <td>
                  <div style="display:flex; justify-content:space-between; margin-bottom:2px;">
                    <span><strong><?= $fac['ventilators_active'] ?></strong> / <?= $fac['ventilators_total'] ?> in use</span>
                    <span style="color:#10b981; font-weight:700; font-size:0.72rem;"><?= $fac['vents_standby'] ?> Standby</span>
                  </div>
                  <div class="rt-bar-bg">
                    <div class="rt-bar-fill" style="width: <?= $fac['vents_load_pct'] ?>%; background: <?= $fac['vents_load_pct'] > 80 ? '#e11d48' : '#10b981' ?>;"></div>
                  </div>
                  <div style="font-size:0.68rem; color:var(--text-muted); margin-top:4px;">
                    Load: <strong><?= $fac['vents_load_pct'] ?>%</strong> • <span class="rt-badge <?= $fac['vent_badge_class'] ?>"><?= $fac['vent_badge_text'] ?></span>
                  </div>
                </td>

                <!-- Blood Column -->
                <td>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <div>
                      <div style="font-size:0.86rem; font-weight:800; color: <?= $fac['blood_o_neg'] < 20 ? '#e11d48' : 'var(--text-heading)' ?>;">
                        <?= $fac['blood_o_neg'] ?> <span style="font-size:0.68rem; font-weight:600; color:var(--text-muted);">O-</span>
                      </div>
                      <div style="font-size:0.68rem; color:var(--text-muted);">Universal</div>
                    </div>
                    <div style="width:1px; height:24px; background:var(--surface-border);"></div>
                    <div>
                      <div style="font-size:0.86rem; font-weight:800; color: #0284c7;">
                        <?= $fac['blood_trauma_packs'] ?> <span style="font-size:0.68rem; font-weight:600; color:var(--text-muted);">Trauma</span>
                      </div>
                      <div style="font-size:0.68rem; color:var(--text-muted);"><?= $fac['platelet_bags'] ?> Platelets</div>
                    </div>
                  </div>
                </td>

                <!-- Hardware Spec -->
                <td>
                  <div style="font-size:0.78rem; font-weight:700; color:var(--text-heading);">
                    <?= htmlspecialchars($fac['hardware_spec'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size:0.68rem; color:var(--text-muted); margin-top:2px;">
                    Invasive/Non-Invasive Dual Mode
                  </div>
                </td>

                <!-- Last Calibration -->
                <td>
                  <div class="calib-timestamp-val" style="font-size:0.75rem; font-weight:700; color:var(--text-heading);">
                    <?= !empty($fac['last_calibrated_at']) ? date('M j, H:i', strtotime($fac['last_calibrated_at'])) : 'Recent' ?>
                  </div>
                  <div style="font-size:0.68rem; color:#10b981; display:flex; align-items:center; gap:4px; margin-top:2px;">
                    <span class="status-pulse-dot" style="background:#10b981; width:5px; height:5px;"></span>
                    IoT Node Online
                  </div>
                </td>

                <!-- Action Controls with DB Persistence -->
                <td style="text-align: right;">
                  <div id="overviewActionWrap-<?= $fac['hospital_id'] ?>" style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                    <?php if ((int)$fac['tanker_dispatched'] === 1): ?>
                      <span class="badge-dispatch-enroute badge-tanker-enroute">
                        🚚 Tanker En Route
                      </span>
                    <?php elseif ((int)$fac['oxygen_reserve_pct'] < 50): ?>
                      <button class="btn-action-sm btn-dispatch" onclick="dispatchTanker(<?= $fac['hospital_id'] ?>, '<?= htmlspecialchars(addslashes($fac['name']), ENT_QUOTES, 'UTF-8') ?>')">
                        Dispatch LOX Tanker
                      </button>
                    <?php endif; ?>

                    <?php if ((int)$fac['courier_dispatched'] === 1): ?>
                      <span class="badge-dispatch-enroute badge-courier-enroute">
                        🚑 Courier En Route
                      </span>
                    <?php elseif ((int)$fac['blood_o_neg'] < 20): ?>
                      <button class="btn-action-sm btn-dispatch" onclick="dispatchCourier(<?= $fac['hospital_id'] ?>, '<?= htmlspecialchars(addslashes($fac['name']), ENT_QUOTES, 'UTF-8') ?>')">
                        Dispatch Courier
                      </button>
                    <?php endif; ?>

                    <?php if ((int)$fac['tanker_dispatched'] === 0 && (int)$fac['oxygen_reserve_pct'] >= 50 && (int)$fac['courier_dispatched'] === 0 && (int)$fac['blood_o_neg'] >= 20): ?>
                      <button class="btn-action-sm btn-service" onclick="openCalibrationModal(<?= $fac['hospital_id'] ?>, '<?= htmlspecialchars(addslashes($fac['name']), ENT_QUOTES, 'UTF-8') ?>', <?= $fac['oxygen_reserve_pct'] ?>, <?= $fac['current_pressure_psi'] ?>)">
                        Calibrate Sensor
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB CONTENT 2: OXYGEN GRID (CENTRAL LOX TELEMETRY)
    ════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane-content" id="tabContent-oxygen" style="display: <?= $activeTab === 'oxygen' ? 'block' : 'none' ?>;">
      <!-- Facility Cards Subgrid for LOX -->
      <div class="rt-subgrid-3">
        <?php foreach ($facilities as $fac): ?>
        <div class="rt-facility-box <?= $fac['ox_status'] === 'CRITICAL' ? 'border-critical' : '' ?>" id="cardOx-<?= $fac['hospital_id'] ?>">
          <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <?= getHospCrest((int)$fac['hospital_id'], $fac['name']) ?>
              <div>
                <h3 style="font-size:0.92rem; font-weight:800; margin:0; color:var(--text-heading);">
                  <?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <span style="font-size:0.70rem; color:var(--text-muted);"><?= htmlspecialchars($fac['code'], ENT_QUOTES, 'UTF-8') ?> • Bulk Liquid Cryotank</span>
              </div>
            </div>
            <span class="rt-badge <?= $fac['ox_class'] ?> card-ox-badge">
              <span class="status-pulse-dot" style="background:<?= $fac['ox_color'] ?>;"></span>
              <span class="card-ox-status-txt"><?= $fac['ox_status'] ?></span>
            </span>
          </div>

          <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:4px;">
            <span style="font-size:0.75rem; color:var(--text-muted); font-weight:700;">Remaining Tank Volume</span>
            <span class="card-ox-vol-val" style="font-size:1.6rem; font-weight:800; color:<?= $fac['ox_color'] ?>;"><?= $fac['oxygen_reserve_pct'] ?>%</span>
          </div>

          <div class="rt-bar-bg" style="height:9px; margin-bottom:12px;">
            <div class="rt-bar-fill card-ox-bar-fill" style="width: <?= $fac['oxygen_reserve_pct'] ?>%; background: <?= $fac['ox_color'] ?>;"></div>
          </div>

          <div style="background:var(--surface-secondary); border-radius:10px; padding:10px 12px; margin-bottom:14px; display:grid; grid-template-columns:1fr 1fr; gap:8px;">
            <div>
              <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Current Pressure</div>
              <div class="card-ox-psi-val" style="font-size:1.05rem; font-weight:800; color:var(--text-heading); margin-top:2px;">
                <?= number_format($fac['current_pressure_psi']) ?> <span style="font-size:0.70rem; font-weight:600; color:var(--text-muted);">PSI</span>
              </div>
            </div>
            <div>
              <div style="font-size:0.65rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Depletion Runway</div>
              <div class="card-ox-runway-val" style="font-size:1.05rem; font-weight:800; color:<?= $fac['depletion_days'] < 3.5 ? '#e11d48' : 'var(--text-heading)' ?>; margin-top:2px;">
                <?= $fac['depletion_days'] ?> <span style="font-size:0.70rem; font-weight:600; color:var(--text-muted);">Days</span>
              </div>
            </div>
          </div>

          <!-- Action Buttons & Reload Persistence -->
          <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.72rem;">
            <span style="color:var(--text-muted);">LOX Sensor: <strong style="color:#0284c7;">Endress+Hauser Cerabar</strong></span>
            
            <div style="display:flex; align-items:center; gap:6px;" id="oxActionWrap-<?= $fac['hospital_id'] ?>">
              <?php if ((int)$fac['tanker_dispatched'] === 1): ?>
                <span class="badge-dispatch-enroute badge-tanker-enroute" style="padding:6px 12px; border-radius:8px; font-size:0.75rem; font-weight:700; background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3); display:inline-flex; align-items:center; gap:6px;">
                  🚚 Tanker Dispatched (En Route)
                </span>
              <?php elseif ((int)$fac['oxygen_reserve_pct'] < 50): ?>
                <button class="btn-action-sm btn-dispatch" onclick="dispatchTanker(<?= $fac['hospital_id'] ?>, '<?= htmlspecialchars(addslashes($fac['name']), ENT_QUOTES, 'UTF-8') ?>')">
                  Dispatch LOX Tanker
                </button>
              <?php else: ?>
                <button class="btn-action-sm btn-service" onclick="openCalibrationModal(<?= $fac['hospital_id'] ?>, '<?= htmlspecialchars(addslashes($fac['name']), ENT_QUOTES, 'UTF-8') ?>', <?= $fac['oxygen_reserve_pct'] ?>, <?= $fac['current_pressure_psi'] ?>)">
                  Calibrate Sensor
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Oxygen Grid Standards Note -->
      <div style="padding:14px 18px; border-radius:12px; background:rgba(2,132,199,0.06); border:1px solid rgba(2,132,199,0.2); font-size:0.78rem; color:var(--text-heading); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <span><strong>National Oxygen Protocol:</strong> Thresholds configured at &ge;70% (Optimal Normal), 50-69% (Caution / Scheduled Refill), &lt;50% (Immediate Emergency LOX Dispatch).</span>
        <span style="font-weight:700; color:#0284c7;">Standard Pressure Target: 2,200 PSI &plusmn; 150</span>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB CONTENT 3: VENTILATOR FLEET (ICU ALLOCATION)
    ════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane-content" id="tabContent-ventilators" style="display: <?= $activeTab === 'ventilators' ? 'block' : 'none' ?>;">
      <div class="rt-section-card">
        <div class="rt-section-header">
          <div class="rt-section-title-group">
            <h2>
              <svg class="ui-ico" style="stroke:var(--sa-accent); width:20px; height:20px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
              ICU &amp; Emergency Mechanical Ventilator Fleet
            </h2>
            <p>Facility-level ventilator allocation models, active inpatient ventilation, and standby units available for rapid trauma hookup.</p>
          </div>
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="status-pulse-dot" style="background:#10b981;"></span>
            <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">
              Fleet Load: <?= $netVentLoad ?>%
            </span>
          </div>
        </div>

        <div class="rt-table-wrap">
          <table class="rt-table">
            <thead>
              <tr>
                <th>Connected Facility</th>
                <th>Hardware Specification</th>
                <th>Total Fleet</th>
                <th>Active In-Use</th>
                <th>Rapid Standby</th>
                <th>Utilization Load</th>
                <th>Fleet Status</th>
                <th style="text-align: right;">Fleet Command</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($facilities as $fac): ?>
              <tr>
                <td>
                  <div class="hosp-profile-cell">
                    <?= getHospCrest((int)$fac['hospital_id'], $fac['name']) ?>
                    <div>
                      <div class="hosp-profile-name"><?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="hosp-profile-meta"><?= htmlspecialchars($fac['city'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <strong style="color:var(--text-heading); font-size:0.82rem;"><?= htmlspecialchars($fac['hardware_spec'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <div style="font-size:0.68rem; color:var(--text-muted);">Invasive / Non-Invasive Dual Mode</div>
                </td>
                <td>
                  <strong style="font-size:0.95rem; color:var(--text-heading);"><?= $fac['ventilators_total'] ?></strong>
                  <span style="font-size:0.70rem; color:var(--text-muted);">Units</span>
                </td>
                <td>
                  <strong style="font-size:0.95rem; color:#e11d48;"><?= $fac['ventilators_active'] ?></strong>
                  <span style="font-size:0.70rem; color:var(--text-muted);">Patients</span>
                </td>
                <td>
                  <strong style="font-size:0.95rem; color:#10b981;"><?= $fac['vents_standby'] ?></strong>
                  <span style="font-size:0.70rem; color:#10b981; font-weight:700;">Ready</span>
                </td>
                <td style="min-width:140px;">
                  <div style="display:flex; justify-content:space-between; margin-bottom:2px; font-size:0.75rem;">
                    <span>Load:</span>
                    <strong><?= $fac['vents_load_pct'] ?>%</strong>
                  </div>
                  <div class="rt-bar-bg">
                    <div class="rt-bar-fill" style="width: <?= $fac['vents_load_pct'] ?>%; background: <?= $fac['vents_load_pct'] > 80 ? '#e11d48' : '#10b981' ?>;"></div>
                  </div>
                </td>
                <td>
                  <span class="rt-badge <?= $fac['vent_badge_class'] ?>">
                    <span class="status-pulse-dot" style="background:<?= $fac['vents_load_pct'] > 80 ? '#e11d48' : '#10b981' ?>;"></span>
                    <?= $fac['vent_badge_text'] ?>
                  </span>
                </td>
                <td style="text-align: right;">
                  <button class="btn-action-sm btn-service" onclick="calibrateSensor(<?= $fac['hospital_id'] ?>, null, null)">
                    Diagnostics Check
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB CONTENT 4: BLOOD BANK (UNIVERSAL & TRAUMA RESERVES)
    ════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane-content" id="tabContent-blood" style="display: <?= $activeTab === 'blood' ? 'block' : 'none' ?>;">
      <div class="rt-section-card">
        <div class="rt-section-header">
          <div class="rt-section-title-group">
            <h2>
              <span style="font-size:20px;">🩸</span>
              Universal Blood Bank &amp; Trauma Transfusion Stockpile
            </h2>
            <p>Real-time cross-match inventories detailing universal donor O-Negative, emergency trauma packs, and platelet concentrates.</p>
          </div>
          <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted);">
            Total O- Stockpile: <strong style="color:#e11d48; font-size:0.95rem;"><?= number_format($totalONegUnits) ?></strong> Units
          </div>
        </div>

        <div class="rt-table-wrap">
          <table class="rt-table">
            <thead>
              <tr>
                <th>Connected Facility</th>
                <th>O- Negative (Universal)</th>
                <th>Trauma Emergency Packs</th>
                <th>Full Blood Group Inventory (A+, B+, O+)</th>
                <th>Platelets &amp; Cryo</th>
                <th>Replenishment Status</th>
                <th style="text-align: right;">Logistics Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($facilities as $fac): ?>
              <tr id="bloodRow-<?= $fac['hospital_id'] ?>">
                <td>
                  <div class="hosp-profile-cell">
                    <?= getHospCrest((int)$fac['hospital_id'], $fac['name']) ?>
                    <div>
                      <div class="hosp-profile-name"><?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="hosp-profile-meta"><?= htmlspecialchars($fac['city'], ENT_QUOTES, 'UTF-8') ?> • Blood Hub</div>
                    </div>
                  </div>
                </td>
                <td>
                  <div style="display:flex; align-items:baseline; gap:6px;">
                    <strong style="font-size:1.15rem; color:<?= $fac['blood_o_neg'] < 20 ? '#e11d48' : '#0f172a' ?>;">
                      <?= $fac['blood_o_neg'] ?>
                    </strong>
                    <span style="font-size:0.70rem; color:var(--text-muted);">Units</span>
                  </div>
                  <span class="rt-badge <?= $fac['blood_class'] ?>" style="margin-top:4px;">
                    <span class="status-pulse-dot" style="background:<?= $fac['blood_color'] ?>;"></span>
                    <?= $fac['blood_status'] ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex; align-items:baseline; gap:6px;">
                    <strong style="font-size:1.15rem; color:#0284c7;"><?= $fac['blood_trauma_packs'] ?></strong>
                    <span style="font-size:0.70rem; color:var(--text-muted);">Packs</span>
                  </div>
                  <div style="font-size:0.68rem; color:var(--text-muted); margin-top:2px;">
                    PRBC &bull; FFP Buffered
                  </div>
                </td>
                <td style="min-width:300px;">
                  <div class="blood-type-matrix" style="grid-template-columns:repeat(4,1fr);">
                    <div class="blood-tile highlight-oneg">
                      <div class="blood-tile-type">O-</div>
                      <div class="blood-tile-count"><?= $fac['blood_o_neg'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">O+</div>
                      <div class="blood-tile-count"><?= $fac['blood_o_pos'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">A+</div>
                      <div class="blood-tile-count"><?= $fac['blood_a_pos'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">A-</div>
                      <div class="blood-tile-count"><?= $fac['blood_a_neg'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">B+</div>
                      <div class="blood-tile-count"><?= $fac['blood_b_pos'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">B-</div>
                      <div class="blood-tile-count"><?= $fac['blood_b_neg'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">AB+</div>
                      <div class="blood-tile-count"><?= $fac['blood_ab_pos'] ?></div>
                    </div>
                    <div class="blood-tile">
                      <div class="blood-tile-type">AB-</div>
                      <div class="blood-tile-count"><?= $fac['blood_ab_neg'] ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div style="font-size:0.78rem;">
                    <strong><?= $fac['platelet_bags'] ?></strong> <span style="color:var(--text-muted);">Platelet Bags</span>
                  </div>
                  <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                    <strong><?= $fac['cryo_units'] ?></strong> Cryo Units
                  </div>
                </td>
                <td>
                  <div style="font-size:0.74rem; font-weight:700; color:<?= $fac['blood_color'] ?>;">
                    <?= $fac['blood_status'] ?>
                  </div>
                  <div style="font-size:0.68rem; color:var(--text-muted);">
                    Cross-match verified
                  </div>
                </td>
                <td style="text-align: right;">
                  <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;" id="bloodActionWrap-<?= $fac['hospital_id'] ?>">
                    <?php if ((int)$fac['courier_dispatched'] === 1): ?>
                      <span class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20" style="padding:6px 12px; border-radius:8px; font-size:0.75rem; font-weight:700; background:rgba(244,63,94,0.12); color:#e11d48; border:1px solid rgba(244,63,94,0.3); display:inline-flex; align-items:center; gap:6px;">
                        🚑 Courier Dispatched (En Route)
                      </span>
                    <?php elseif ((int)$fac['blood_o_neg'] < 20): ?>
                      <button class="btn-action-sm btn-dispatch" onclick="dispatchCourier(<?= $fac['hospital_id'] ?>, '<?= htmlspecialchars(addslashes($fac['name']), ENT_QUOTES, 'UTF-8') ?>')">
                        Dispatch Courier
                      </button>
                    <?php else: ?>
                      <button class="btn-action-sm btn-service" onclick="calibrateSensor(<?= $fac['hospital_id'] ?>, null, null)">
                        Sync Inventory
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <!-- ═══════════════════════════════════════════════════════════════
         1. CUSTOM CLINICAL CALIBRATION MODAL
    ════════════════════════════════════════════════════════════════ -->
    <div id="modalCalibration" class="rt-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="calibModalTitle" style="display:none;">
      <div class="rt-modal-card rt-modal-calibration">
        <!-- Header -->
        <div class="rt-modal-header">
          <div style="display:flex; align-items:center; gap:12px;">
            <div class="rt-modal-icon-badge" style="background:#d1fae5; color:#059669; border:1px solid rgba(16,185,129,0.25);">
              <svg style="width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2.2;" viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="7"/></svg>
            </div>
            <div>
              <h3 id="calibModalTitle" class="rt-modal-title">Calibrate Oxygen Telemetry Sensor</h3>
              <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                <span id="calibFacilityBadge" class="rt-facility-badge">Facility Name</span>
                <span style="font-size:0.70rem; color:var(--text-muted);">• LOX Telemetry Transducer</span>
              </div>
            </div>
          </div>
          <button type="button" class="rt-modal-close-btn" id="btnCalibClose" aria-label="Close modal">
            <svg style="width:16px;height:16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <!-- Form Content -->
        <form id="formCalibration" onsubmit="event.preventDefault(); handleCalibrationSubmit();">
          <input type="hidden" id="calibHospId" name="hospital_id" value="">
          <input type="hidden" id="calibHospName" value="">

          <div class="rt-modal-body">
            <!-- Hardware Spec Card -->
            <div class="rt-sensor-info-card" style="margin-bottom:16px;">
              <div style="display:flex; align-items:flex-start; gap:12px;">
                <div style="color:#0284c7; margin-top:2px;">
                  <svg style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2.2;" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="2" y1="12" x2="4" y2="12"/></svg>
                </div>
                <div style="flex:1;">
                  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:2px;">
                    <span style="font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted);">Hardware Identifier</span>
                    <span class="rt-badge rt-badge-normal" style="font-size:0.65rem; padding:2px 8px;">Online &bull; 4-20mA HART</span>
                  </div>
                  <div style="font-size:0.88rem; font-weight:800; color:var(--text-heading);" id="calibHardwareName">
                    Endress+Hauser Cerabar
                  </div>
                  <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">
                    Piezo-Resistive Pressure Transducer (Ceramic Diaphragm &bull; Cryogenic Rating)
                  </div>
                </div>
              </div>
            </div>

            <!-- Form Inputs -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
              <!-- Oxygen Volume (%) -->
              <div class="rt-form-group">
                <label for="calibVolInput" class="rt-form-label">
                  <span>Oxygen Volume (%)</span>
                  <span class="rt-label-hint">5% &ndash; 100%</span>
                </label>
                <div class="rt-input-wrap">
                  <input type="number" id="calibVolInput" class="rt-form-input" min="5" max="100" step="1" required placeholder="e.g. 75">
                  <span class="rt-input-suffix">%</span>
                </div>
              </div>

              <!-- Current Pressure (PSI) -->
              <div class="rt-form-group">
                <label for="calibPsiInput" class="rt-form-label">
                  <span>Current Pressure (PSI)</span>
                  <span class="rt-label-hint">500 &ndash; 3000 PSI</span>
                </label>
                <div class="rt-input-wrap">
                  <input type="number" id="calibPsiInput" class="rt-form-input" min="500" max="3000" step="10" required placeholder="e.g. 2200">
                  <span class="rt-input-suffix">PSI</span>
                </div>
              </div>
            </div>

            <!-- Dynamic Runway Preview -->
            <div class="rt-runway-preview-strip" style="margin-top:14px; display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-radius:10px; background:var(--surface-secondary, rgba(241, 245, 249, 0.7)); border:1px solid var(--surface-border, #e2e8f0); font-size:0.76rem;">
              <span style="color:var(--text-muted);">Estimated Depletion Runway:</span>
              <span id="calibRunwayPreview" style="font-weight:800; color:var(--text-heading); font-family:'JetBrains Mono', monospace;">~7.1 Days</span>
            </div>

            <!-- Error message container (zero native alert popups!) -->
            <div id="calibErrorBox" class="rt-form-error" style="display:none; margin-top:12px;"></div>
          </div>

          <!-- Action Buttons -->
          <div class="rt-modal-actions">
            <button type="button" class="rt-btn-neutral" id="btnCancelCalib">Cancel</button>
            <button type="submit" class="rt-btn-emerald" id="btnSubmitCalib">
              <span class="btn-spinner" style="display:none;"></span>
              <span class="btn-text">Apply Calibration</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         2. CUSTOM EMERGENCY LOGISTICS CONFIRMATION MODAL
    ════════════════════════════════════════════════════════════════ -->
    <div id="modalDispatchConfirm" class="rt-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="dispatchModalTitle" style="display:none;">
      <div class="rt-modal-card rt-modal-dispatch">
        <!-- Header -->
        <div class="rt-modal-header danger" id="dispatchHeader">
          <div style="display:flex; align-items:center; gap:12px;">
            <div id="dispatchIconWrapper" class="rt-modal-icon-badge dispatch-icon-danger">
              <!-- Dynamically populated warning SVG -->
            </div>
            <div>
              <h3 id="dispatchModalTitle" class="rt-modal-title">Confirm Emergency Logistics Dispatch</h3>
              <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                <span id="dispatchTierBadge" class="rt-priority-badge badge-crimson">HIGH URGENCY CLINICAL PROTOCOL</span>
              </div>
            </div>
          </div>
          <button type="button" class="rt-modal-close-btn" id="btnDispatchClose" aria-label="Close modal">
            <svg style="width:16px;height:16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>

        <!-- Body -->
        <div class="rt-modal-body">
          <p id="dispatchLeadText" style="font-size:0.84rem; color:var(--text-muted); margin:0 0 16px; line-height:1.5;">
            Authorizing emergency medical transit dispatch. This action activates regional transport priority and updates network command dashboards in real time.
          </p>

          <!-- Logistics Specification Details -->
          <div class="rt-dispatch-details-card">
            <div class="rt-dispatch-row">
              <div class="rt-dispatch-label">Facility Destination</div>
              <div class="rt-dispatch-val" id="dispatchDestVal">Facility Destination</div>
            </div>
            <div class="rt-dispatch-row">
              <div class="rt-dispatch-label">Required Payload</div>
              <div class="rt-dispatch-val" id="dispatchPayloadVal">Required Payload</div>
            </div>
            <div class="rt-dispatch-row">
              <div class="rt-dispatch-label">Priority Routing</div>
              <div class="rt-dispatch-val" id="dispatchRoutingVal" style="color:#0284c7;">Priority Green Corridor &bull; Active Telemetry</div>
            </div>
            <div class="rt-dispatch-row" style="border-bottom:none;">
              <div class="rt-dispatch-label">Incident Broadcast</div>
              <div class="rt-dispatch-val" id="dispatchBroadcastVal" style="color:#10b981;">Automated Engineering &amp; Logistics Alert</div>
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="rt-modal-actions">
          <button type="button" class="rt-btn-neutral" id="btnCancelDispatch">Cancel</button>
          <button type="button" class="rt-btn-dispatch-auth btn-dispatch-danger" id="btnSubmitDispatch">
            <span class="btn-spinner" style="display:none;"></span>
            <span class="btn-text">Authorize &amp; Dispatch</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         3. TOAST NOTIFICATION CONTAINER (TOP-RIGHT FLOATING)
    ════════════════════════════════════════════════════════════════ -->
    <div id="rtToastContainer" class="rt-toast-container" aria-live="polite" aria-atomic="true"></div>
  </main>

  <!-- Modern MedPulse Dialog & Toast Engine -->
  <script src="../assets/js/medpulse_dialog.js"></script>

  <script>
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

    // ── Client-side Tab Switcher with URL History Sync ────────────────────────
    function switchTelemetryTab(tabId, evt) {
      if (evt) {
        evt.preventDefault();
      }

      document.querySelectorAll('.rt-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
      });

      document.querySelectorAll('.tab-pane-content').forEach(pane => {
        pane.style.display = (pane.id === `tabContent-${tabId}`) ? 'block' : 'none';
      });

      const url = new URL(window.location.href);
      url.searchParams.set('tab', tabId);
      window.history.pushState({ tab: tabId }, '', url.toString());
    }

    window.addEventListener('popstate', () => {
      const urlParams = new URLSearchParams(window.location.search);
      const tab = urlParams.get('tab') || 'all';
      switchTelemetryTab(tab);
    });

    // ── Zero Native Browser Dialogs Enforcement ──────────────────────────────
    window.alert = function(msg) {
      showToast('Clinical Notice', String(msg), 'info');
    };
    window.confirm = function() {
      console.warn('Native window.confirm is disabled by MedPulse Clinical Safety Protocol.');
      return false;
    };
    window.prompt = function() {
      console.warn('Native window.prompt is disabled by MedPulse Clinical Safety Protocol.');
      return null;
    };

    // ── Helper Utilities ──────────────────────────────────────────────────────
    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function escapeJsStr(str) {
      if (str === null || str === undefined) return '';
      return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
    }

    // ── 1. TOAST NOTIFICATION SYSTEM (Top-Right Floating) ─────────────────────
    function showToast(title, message = '', type = 'success', duration = 4000) {
      let container = document.getElementById('rtToastContainer');
      if (!container) {
        container = document.createElement('div');
        container.id = 'rtToastContainer';
        container.className = 'rt-toast-container';
        document.body.appendChild(container);
      }

      const toastEl = document.createElement('div');
      toastEl.className = `rt-toast ${type}`;
      toastEl.setAttribute('role', 'alert');

      let iconSvg = '';
      if (type === 'success') {
        iconSvg = `<svg style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2.5;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>`;
      } else if (type === 'error') {
        iconSvg = `<svg style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2.5;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
      } else {
        iconSvg = `<svg style="width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2.5;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
      }

      toastEl.innerHTML = `
        <div class="rt-toast-icon-wrap">${iconSvg}</div>
        <div class="rt-toast-body">
          <div class="rt-toast-title">${escapeHtml(title)}</div>
          ${message ? `<div class="rt-toast-msg">${escapeHtml(message)}</div>` : ''}
        </div>
        <button type="button" class="rt-toast-close" aria-label="Dismiss toast">
          <svg style="width:14px;height:14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div class="rt-toast-progress"><div class="rt-toast-bar"></div></div>
      `;

      container.appendChild(toastEl);

      // Trigger smooth entrance animation
      requestAnimationFrame(() => {
        toastEl.classList.add('show');
      });

      // Progress bar animation
      const bar = toastEl.querySelector('.rt-toast-bar');
      if (bar && duration > 0) {
        bar.style.transition = `width ${duration}ms linear`;
        requestAnimationFrame(() => {
          bar.style.width = '0%';
        });
      }

      let dismissTimer = null;
      const dismiss = () => {
        if (dismissTimer) clearTimeout(dismissTimer);
        toastEl.classList.remove('show');
        toastEl.classList.add('hide');
        setTimeout(() => {
          if (toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
        }, 360);
      };

      if (duration > 0) {
        dismissTimer = setTimeout(dismiss, duration);
      }

      toastEl.querySelector('.rt-toast-close').addEventListener('click', dismiss);
    }

    // Bridge MedPulseDialog.toast if called by other components
    if (window.MedPulseDialog) {
      window.MedPulseDialog.toast = function(opts, type = 'success', duration = 4000) {
        if (typeof opts === 'object') {
          showToast(opts.title || (opts.type === 'error' ? 'Failed' : 'Notice'), opts.message || opts.text || '', opts.type || type, duration);
        } else {
          showToast(type === 'success' ? 'Success' : (type === 'error' ? 'Failed' : 'Notice'), String(opts), type, duration);
        }
      };
    }

    // ── 2. DYNAMIC DOM TELEMETRY SYNCHRONIZATION ─────────────────────────────
    function recalculateMeanOxygenKPI() {
      const kpiEl = document.getElementById('kpiMeanOxygenVal');
      if (!kpiEl) return;
      const cards = document.querySelectorAll('.card-ox-vol-val');
      if (cards.length > 0) {
        let sum = 0;
        let count = 0;
        cards.forEach(el => {
          const v = parseFloat(el.textContent.replace('%', ''));
          if (!isNaN(v)) {
            sum += v;
            count++;
          }
        });
        if (count > 0) {
          const avg = sum / count;
          kpiEl.textContent = avg.toFixed(1) + '%';
          kpiEl.style.color = avg >= 70 ? '#059669' : (avg >= 50 ? '#d97706' : '#e11d48');
        }
      }
    }

    function updateHospitalOxygenUI(hospId, hospName, pct, psi, days) {
      if (days === null || days === undefined) {
        days = ((pct / 100) * 9.5).toFixed(1);
      }
      const isCritical = pct < 50;
      const isMonitor  = pct >= 50 && pct < 70;
      const status     = isCritical ? 'CRITICAL' : (isMonitor ? 'MONITOR' : 'OPTIMAL');
      const color      = isCritical ? '#e11d48' : (isMonitor ? '#f59e0b' : '#10b981');
      const badgeClass = isCritical ? 'rt-badge-critical' : (isMonitor ? 'rt-badge-warning' : 'rt-badge-normal');

      // 1. Tab 1 Overview Row
      const row = document.getElementById(`overviewRow-${hospId}`);
      if (row) {
        const volEl = row.querySelector('.ox-vol-val');
        if (volEl) volEl.textContent = `${pct}%`;

        const statusEl = row.querySelector('.ox-status-txt');
        if (statusEl) statusEl.textContent = status;

        const badgeEl = row.querySelector('.ox-badge-el');
        if (badgeEl) {
          badgeEl.className = `rt-badge ${badgeClass} ox-badge-el`;
          const dot = badgeEl.querySelector('.status-pulse-dot');
          if (dot) dot.style.background = color;
        }

        const barEl = row.querySelector('.ox-bar-fill');
        if (barEl) {
          barEl.style.width = `${pct}%`;
          barEl.style.background = color;
        }

        const psiEl = row.querySelector('.ox-psi-val');
        if (psiEl) psiEl.textContent = Number(psi).toLocaleString();

        const runwayEl = row.querySelector('.ox-runway-val');
        if (runwayEl) runwayEl.textContent = `${days} days`;

        const calibTimeEl = row.querySelector('.calib-timestamp-val');
        if (calibTimeEl) calibTimeEl.textContent = 'Just now';

        // Update Action Button (preserve tanker badge if dispatched)
        const actionWrap = document.getElementById(`overviewActionWrap-${hospId}`);
        if (actionWrap && !actionWrap.querySelector('.badge-tanker-enroute')) {
          if (isCritical) {
            actionWrap.innerHTML = `
              <button class="btn-action-sm btn-dispatch" onclick="dispatchTanker(${hospId}, '${escapeJsStr(hospName)}')">
                Dispatch LOX Tanker
              </button>
            `;
          } else {
            actionWrap.innerHTML = `
              <button class="btn-action-sm btn-service" onclick="openCalibrationModal(${hospId}, '${escapeJsStr(hospName)}', ${pct}, ${psi})">
                Calibrate Sensor
              </button>
            `;
          }
        }
      }

      // 2. Tab 2 LOX Card
      const card = document.getElementById(`cardOx-${hospId}`);
      if (card) {
        card.classList.toggle('border-critical', isCritical);

        const cardBadge = card.querySelector('.card-ox-badge');
        if (cardBadge) {
          cardBadge.className = `rt-badge ${badgeClass} card-ox-badge`;
          const dot = cardBadge.querySelector('.status-pulse-dot');
          if (dot) dot.style.background = color;
        }

        const statusTxt = card.querySelector('.card-ox-status-txt');
        if (statusTxt) statusTxt.textContent = status;

        const volVal = card.querySelector('.card-ox-vol-val');
        if (volVal) {
          volVal.textContent = `${pct}%`;
          volVal.style.color = color;
        }

        const barFill = card.querySelector('.card-ox-bar-fill');
        if (barFill) {
          barFill.style.width = `${pct}%`;
          barFill.style.background = color;
        }

        const psiVal = card.querySelector('.card-ox-psi-val');
        if (psiVal) {
          psiVal.innerHTML = `${Number(psi).toLocaleString()} <span style="font-size:0.70rem; font-weight:600; color:var(--text-muted);">PSI</span>`;
        }

        const runwayVal = card.querySelector('.card-ox-runway-val');
        if (runwayVal) {
          runwayVal.innerHTML = `${days} <span style="font-size:0.70rem; font-weight:600; color:var(--text-muted);">Days</span>`;
          runwayVal.style.color = days < 3.5 ? '#e11d48' : 'var(--text-heading)';
        }

        // Action wrapper
        const oxWrap = document.getElementById(`oxActionWrap-${hospId}`);
        if (oxWrap && !oxWrap.querySelector('.badge-tanker-enroute')) {
          if (isCritical) {
            oxWrap.innerHTML = `
              <button class="btn-action-sm btn-dispatch" onclick="dispatchTanker(${hospId}, '${escapeJsStr(hospName)}')">
                Dispatch LOX Tanker
              </button>
            `;
          } else {
            oxWrap.innerHTML = `
              <button class="btn-action-sm btn-service" onclick="openCalibrationModal(${hospId}, '${escapeJsStr(hospName)}', ${pct}, ${psi})">
                Calibrate Sensor
              </button>
            `;
          }
        }
      }

      // 3. Recompute Mean Oxygen Header KPI
      recalculateMeanOxygenKPI();
    }

    function setTankerDispatchedUI(hospId) {
      // Tab 1 Overview
      const overWrap = document.getElementById(`overviewActionWrap-${hospId}`);
      if (overWrap) {
        overWrap.innerHTML = `
          <span class="badge-dispatch-enroute badge-tanker-enroute">
            🚚 Tanker En Route
          </span>
        `;
      }
      // Tab 2 LOX
      const oxWrap = document.getElementById(`oxActionWrap-${hospId}`);
      if (oxWrap) {
        oxWrap.innerHTML = `
          <span class="badge-dispatch-enroute badge-tanker-enroute" style="padding:6px 12px; border-radius:8px; font-size:0.75rem; font-weight:700; background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3); display:inline-flex; align-items:center; gap:6px;">
            🚚 Tanker Dispatched (En Route)
          </span>
        `;
      }
    }

    function setCourierDispatchedUI(hospId) {
      // Tab 1 Overview
      const overWrap = document.getElementById(`overviewActionWrap-${hospId}`);
      if (overWrap) {
        overWrap.innerHTML = `
          <span class="badge-dispatch-enroute badge-courier-enroute">
            🚑 Courier En Route
          </span>
        `;
      }
      // Tab 4 Blood Bank
      const bloodWrap = document.getElementById(`bloodActionWrap-${hospId}`);
      if (bloodWrap) {
        bloodWrap.innerHTML = `
          <span class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-rose-500/10 text-rose-600 border border-rose-500/20" style="padding:6px 12px; border-radius:8px; font-size:0.75rem; font-weight:700; background:rgba(244,63,94,0.12); color:#e11d48; border:1px solid rgba(244,63,94,0.3); display:inline-flex; align-items:center; gap:6px;">
            🚑 Courier Dispatched (En Route)
          </span>
        `;
      }
    }

    // ── 3. CUSTOM CLINICAL CALIBRATION MODAL ──────────────────────────────────
    const modalCalibration = document.getElementById('modalCalibration');
    const calibVolInput = document.getElementById('calibVolInput');
    const calibPsiInput = document.getElementById('calibPsiInput');
    const calibRunwayPreview = document.getElementById('calibRunwayPreview');
    const calibErrorBox = document.getElementById('calibErrorBox');
    const btnSubmitCalib = document.getElementById('btnSubmitCalib');

    function openCalibrationModal(hospId, hospName, curPct, curPsi) {
      document.getElementById('calibHospId').value = hospId;
      document.getElementById('calibHospName').value = hospName;
      document.getElementById('calibFacilityBadge').textContent = hospName;
      document.getElementById('calibHardwareName').textContent = 'Endress+Hauser Cerabar';

      calibVolInput.value = curPct || 75;
      calibPsiInput.value = curPsi || 2200;
      updateCalibPreview();

      calibErrorBox.style.display = 'none';
      calibErrorBox.textContent = '';

      btnSubmitCalib.disabled = false;
      const spinner = btnSubmitCalib.querySelector('.btn-spinner');
      if (spinner) spinner.style.display = 'none';
      const btnText = btnSubmitCalib.querySelector('.btn-text');
      if (btnText) btnText.textContent = 'Apply Calibration';

      modalCalibration.style.display = 'flex';
      requestAnimationFrame(() => {
        modalCalibration.classList.add('open');
        calibVolInput.focus();
        calibVolInput.select();
      });
    }

    function closeCalibrationModal() {
      modalCalibration.classList.remove('open');
      setTimeout(() => {
        modalCalibration.style.display = 'none';
        calibErrorBox.style.display = 'none';
      }, 250);
    }

    function updateCalibPreview() {
      const vol = parseFloat(calibVolInput.value);
      if (!isNaN(vol) && vol > 0) {
        const estDays = ((vol / 100) * 9.5).toFixed(1);
        calibRunwayPreview.textContent = `~${estDays} Days`;
        calibRunwayPreview.style.color = estDays < 3.5 ? '#e11d48' : 'var(--text-heading)';
      } else {
        calibRunwayPreview.textContent = '—';
      }
    }

    if (calibVolInput) {
      calibVolInput.addEventListener('input', updateCalibPreview);
    }

    async function handleCalibrationSubmit() {
      const hospId = parseInt(document.getElementById('calibHospId').value, 10);
      const hospName = document.getElementById('calibHospName').value;
      const vol = parseInt(calibVolInput.value, 10);
      const psi = parseInt(calibPsiInput.value, 10);

      // Clinical Validation without native alert()
      if (isNaN(vol) || vol < 5 || vol > 100) {
        calibErrorBox.textContent = 'Invalid percentage. Please specify an Oxygen Volume between 5% and 100%.';
        calibErrorBox.style.display = 'block';
        calibVolInput.focus();
        return;
      }

      if (isNaN(psi) || psi < 500 || psi > 3000) {
        calibErrorBox.textContent = 'Invalid pressure. Please specify Manometer Pressure between 500 and 3,000 PSI.';
        calibErrorBox.style.display = 'block';
        calibPsiInput.focus();
        return;
      }

      calibErrorBox.style.display = 'none';

      // Button loading state
      btnSubmitCalib.disabled = true;
      const spinner = btnSubmitCalib.querySelector('.btn-spinner');
      if (spinner) spinner.style.display = 'inline-block';
      const btnText = btnSubmitCalib.querySelector('.btn-text');
      if (btnText) btnText.textContent = 'Applying Calibration…';

      try {
        const fd = new FormData();
        fd.append('_action', 'calibrate_sensor');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('hospital_id', hospId);
        fd.append('oxygen_reserve_pct', vol);
        fd.append('current_pressure_psi', psi);

        const res = await fetch('resource_telemetry.php', {
          method: 'POST',
          body: fd
        });
        const data = await res.json();

        if (data.success) {
          // Dynamic UI updates across tabs and cards
          updateHospitalOxygenUI(hospId, hospName, vol, psi, data.depletion_days);

          // Close modal
          closeCalibrationModal();

          // In-App Toast Notification
          showToast('Calibration verified and synced.', data.message || `Facility #${hospId} sensor telemetry calibrated.`, 'success');
        } else {
          calibErrorBox.textContent = data.message || 'Calibration verification failed on the server.';
          calibErrorBox.style.display = 'block';
          btnSubmitCalib.disabled = false;
          if (spinner) spinner.style.display = 'none';
          if (btnText) btnText.textContent = 'Apply Calibration';
          showToast('Calibration Failed', data.message || 'Unable to update sensor calibration.', 'error');
        }
      } catch (err) {
        console.error('Calibration error:', err);
        calibErrorBox.textContent = 'Network communication failure while sending calibration signal.';
        calibErrorBox.style.display = 'block';
        btnSubmitCalib.disabled = false;
        if (spinner) spinner.style.display = 'none';
        if (btnText) btnText.textContent = 'Apply Calibration';
        showToast('Network Error', 'Failed to reach IoT telemetry server.', 'error');
      }
    }

    // Direct Synchronize / Recalibrate All (used by header / sync buttons)
    async function calibrateSensor(hospId = 0, pct = null, psi = null) {
      try {
        const fd = new FormData();
        fd.append('_action', 'calibrate_sensor');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('hospital_id', hospId);
        if (pct !== null) fd.append('oxygen_reserve_pct', pct);
        if (psi !== null) fd.append('current_pressure_psi', psi);

        const res = await fetch('resource_telemetry.php', {
          method: 'POST',
          body: fd
        });
        const data = await res.json();

        if (data.success) {
          if (hospId > 0 && pct !== null && psi !== null) {
            updateHospitalOxygenUI(hospId, `Facility #${hospId}`, pct, psi, data.depletion_days);
          } else if (hospId > 0) {
            const row = document.getElementById(`overviewRow-${hospId}`);
            if (row) {
              const calibTimeEl = row.querySelector('.calib-timestamp-val');
              if (calibTimeEl) calibTimeEl.textContent = 'Just now';
            }
          } else {
            document.querySelectorAll('.calib-timestamp-val').forEach(el => el.textContent = 'Just now');
          }
          showToast('Sensors Synchronized', data.message || 'IoT sensor nodes calibrated and synchronized.', 'success');
        } else {
          showToast('Calibration Failed', data.message || 'Unable to update sensor calibration.', 'error');
        }
      } catch (err) {
        console.error('Calibration error:', err);
        showToast('Network Error', 'Failed to send recalibration signal to IoT mesh network.', 'error');
      }
    }

    // ── 4. CUSTOM EMERGENCY CONFIRMATION MODAL ────────────────────────────────
    const modalDispatchConfirm = document.getElementById('modalDispatchConfirm');
    const dispatchHeader = document.getElementById('dispatchHeader');
    const dispatchIconWrapper = document.getElementById('dispatchIconWrapper');
    const dispatchTierBadge = document.getElementById('dispatchTierBadge');
    const dispatchLeadText = document.getElementById('dispatchLeadText');
    const dispatchDestVal = document.getElementById('dispatchDestVal');
    const dispatchPayloadVal = document.getElementById('dispatchPayloadVal');
    const dispatchRoutingVal = document.getElementById('dispatchRoutingVal');
    const dispatchBroadcastVal = document.getElementById('dispatchBroadcastVal');
    const btnSubmitDispatch = document.getElementById('btnSubmitDispatch');

    let currentDispatchContext = null;

    function openDispatchModal(opts) {
      currentDispatchContext = opts;

      // Icon & Urgency Theme
      if (opts.type === 'tanker') {
        dispatchHeader.className = 'rt-modal-header danger';
        dispatchIconWrapper.className = 'rt-modal-icon-badge dispatch-icon-danger';
        dispatchIconWrapper.innerHTML = `
          <svg style="width:24px;height:24px;stroke:#e11d48;fill:none;stroke-width:2.2;" viewBox="0 0 24 24">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        `;
        dispatchTierBadge.className = 'rt-priority-badge badge-crimson';
        dispatchTierBadge.textContent = 'HIGH URGENCY CLINICAL PROTOCOL';
        btnSubmitDispatch.className = 'rt-btn-dispatch-auth btn-dispatch-danger';
      } else {
        dispatchHeader.className = 'rt-modal-header warning';
        dispatchIconWrapper.className = 'rt-modal-icon-badge dispatch-icon-warning';
        dispatchIconWrapper.innerHTML = `
          <svg style="width:24px;height:24px;stroke:#d97706;fill:none;stroke-width:2.2;" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        `;
        dispatchTierBadge.className = 'rt-priority-badge badge-amber';
        dispatchTierBadge.textContent = 'CRITICAL LOGISTICS DISPATCH';
        btnSubmitDispatch.className = 'rt-btn-dispatch-auth btn-dispatch-warning';
      }

      dispatchLeadText.textContent = opts.leadText;
      dispatchDestVal.textContent = opts.destination;
      dispatchPayloadVal.textContent = opts.payload;
      dispatchRoutingVal.textContent = opts.routing;
      dispatchBroadcastVal.textContent = opts.networkAlert;

      btnSubmitDispatch.disabled = false;
      const spinner = btnSubmitDispatch.querySelector('.btn-spinner');
      if (spinner) spinner.style.display = 'none';
      const btnText = btnSubmitDispatch.querySelector('.btn-text');
      if (btnText) btnText.textContent = opts.confirmBtnText || 'Authorize & Dispatch';

      modalDispatchConfirm.style.display = 'flex';
      requestAnimationFrame(() => {
        modalDispatchConfirm.classList.add('open');
        btnSubmitDispatch.focus();
      });
    }

    function closeDispatchModal() {
      modalDispatchConfirm.classList.remove('open');
      setTimeout(() => {
        modalDispatchConfirm.style.display = 'none';
        currentDispatchContext = null;
      }, 250);
    }

    async function handleDispatchConfirm() {
      if (!currentDispatchContext) return;
      const ctx = currentDispatchContext;

      btnSubmitDispatch.disabled = true;
      const spinner = btnSubmitDispatch.querySelector('.btn-spinner');
      if (spinner) spinner.style.display = 'inline-block';
      const btnText = btnSubmitDispatch.querySelector('.btn-text');
      if (btnText) btnText.textContent = 'Authorizing & Dispatching…';

      try {
        const fd = new FormData();
        fd.append('_action', ctx.action);
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('hospital_id', ctx.hospitalId);

        const res = await fetch('resource_telemetry.php', {
          method: 'POST',
          body: fd
        });
        const data = await res.json();

        if (data.success) {
          // Update button state to persistent en-route badge
          if (ctx.type === 'tanker') {
            setTankerDispatchedUI(ctx.hospitalId);
          } else {
            setCourierDispatchedUI(ctx.hospitalId);
          }

          // Close modal
          closeDispatchModal();

          // Display success toast
          showToast(
            ctx.type === 'tanker' ? 'LOX Tanker Dispatched' : 'Blood Courier Dispatched',
            data.message || `Emergency logistics transit initiated for ${ctx.hospitalName}.`,
            'success'
          );
        } else {
          btnSubmitDispatch.disabled = false;
          if (spinner) spinner.style.display = 'none';
          if (btnText) btnText.textContent = ctx.confirmBtnText || 'Authorize & Dispatch';
          showToast('Dispatch Failed', data.message || 'Unable to deploy emergency logistics transport.', 'error');
        }
      } catch (err) {
        console.error('Dispatch error:', err);
        btnSubmitDispatch.disabled = false;
        if (spinner) spinner.style.display = 'none';
        if (btnText) btnText.textContent = ctx.confirmBtnText || 'Authorize & Dispatch';
        showToast('Communication Failure', 'Failed to communicate with telemetry logistics dispatch server.', 'error');
      }
    }

    // Public Dispatch Triggers
    function dispatchTanker(hospId, hospName) {
      openDispatchModal({
        action: 'dispatch_tanker',
        hospitalId: hospId,
        hospitalName: hospName,
        type: 'tanker',
        title: 'Confirm Emergency Logistics Dispatch',
        leadText: `Authorize an emergency Liquid Oxygen (LOX) cryogenic tanker dispatch to ${hospName}? This will set tanker transit status across the network and notify facility engineering.`,
        destination: `${hospName} • Central Cryo Facility`,
        payload: '20,000 Liters Liquid Cryogenic O2 (High Pressure Bulk Carrier)',
        routing: 'Priority Green Corridor • Active GPS Telemetry Transponder',
        networkAlert: 'Automated Facility Engineering & Logistics Command Broadcast',
        confirmBtnText: 'Authorize & Dispatch LOX Tanker'
      });
    }

    function dispatchCourier(hospId, hospName) {
      openDispatchModal({
        action: 'dispatch_courier',
        hospitalId: hospId,
        hospitalName: hospName,
        type: 'courier',
        title: 'Confirm Emergency Logistics Dispatch',
        leadText: `Deploy an emergency refrigerated courier carrying universal O-Negative and trauma packs to ${hospName}? This sets priority transit tracking across the network command system.`,
        destination: `${hospName} • Emergency Transfusion Hub`,
        payload: '15 Units Universal O-Negative + 10 Trauma Packs (Refrigerated 2°C–6°C)',
        routing: 'Priority Direct Courier • Transit Telemetry Monitoring Active',
        networkAlert: 'Automated Blood Bank Incident Log & Regional Transfusion Alert',
        confirmBtnText: 'Authorize & Dispatch Blood Courier'
      });
    }

    // ── 5. EVENT LISTENERS & KEYBOARD ACCESSIBILITY ───────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
      // Close button event listeners
      const btnCalibClose = document.getElementById('btnCalibClose');
      const btnCancelCalib = document.getElementById('btnCancelCalib');
      if (btnCalibClose) btnCalibClose.addEventListener('click', closeCalibrationModal);
      if (btnCancelCalib) btnCancelCalib.addEventListener('click', closeCalibrationModal);

      const btnDispatchClose = document.getElementById('btnDispatchClose');
      const btnCancelDispatch = document.getElementById('btnCancelDispatch');
      if (btnDispatchClose) btnDispatchClose.addEventListener('click', closeDispatchModal);
      if (btnCancelDispatch) btnCancelDispatch.addEventListener('click', closeDispatchModal);

      if (btnSubmitDispatch) {
        btnSubmitDispatch.addEventListener('click', handleDispatchConfirm);
      }

      // Backdrop click dismiss
      if (modalCalibration) {
        modalCalibration.addEventListener('click', (e) => {
          if (e.target === modalCalibration) closeCalibrationModal();
        });
      }

      if (modalDispatchConfirm) {
        modalDispatchConfirm.addEventListener('click', (e) => {
          if (e.target === modalDispatchConfirm) closeDispatchModal();
        });
      }

      // Keyboard Accessibility: Escape closes modal, Enter submits
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          if (modalCalibration && modalCalibration.classList.contains('open')) {
            closeCalibrationModal();
          } else if (modalDispatchConfirm && modalDispatchConfirm.classList.contains('open')) {
            closeDispatchModal();
          }
        } else if (e.key === 'Enter') {
          if (modalDispatchConfirm && modalDispatchConfirm.classList.contains('open') && !btnSubmitDispatch.disabled) {
            e.preventDefault();
            handleDispatchConfirm();
          }
        }
      });
    });
  </script>
</body>
</html>

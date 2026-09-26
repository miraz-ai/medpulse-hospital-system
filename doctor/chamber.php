<?php
/**
 * MedPulse Doctor Portal — OPD Clinical Chamber Console & Live Medical Dossier
 * Sequential Queue Progression, Patient Triage Dossier, Real-Time Rolling Delta & Session Controls
 */

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';
require_once __DIR__ . '/../controllers/AppointmentController.php';

$doctorUserId = (int)$_SESSION['user_id'];

// ── Handle Action Progression (POST & AJAX) ─────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    $response = ['success' => false, 'message' => 'Invalid action requested.'];

    switch ($action) {
        case 'start_session':
            $response = AppointmentController::startChamberSession($pdo, $doctorUserId);
            break;

        case 'call_next':
            $actualDuration = isset($_POST['actual_duration']) ? (int)$_POST['actual_duration'] : null;
            $response = AppointmentController::callNextPatient($pdo, $doctorUserId, $actualDuration);
            break;

        case 'hold_token':
            $appId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;
            $response = AppointmentController::holdCurrentToken($pdo, $doctorUserId, $appId);
            break;

        case 'end_session':
            $response = AppointmentController::endChamberSession($pdo, $doctorUserId);
            break;
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => $response['success'] ? 'success' : 'error', 'data' => $response]);
        exit();
    }

    $msgParam = urlencode($response['message'] ?? 'Action completed');
    header("Location: chamber.php?notice=" . ($response['success'] ? '1' : '0') . "&msg={$msgParam}");
    exit();
}

// ── Fetch Doctor Profile & Details ─────────────────────────────────────────
try {
    $docStmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.phone, u.gender,
               dp.specialty, dp.designation, dp.military_rank, dp.qualifications,
               dp.bmdc_license_number, dp.consultation_fee,
               dp.room_number, dp.shift_timings, dp.session_status,
               dp.current_serving_token, dp.accumulated_delta_minutes,
               dp.avg_consultation_time
        FROM users u
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $docStmt->execute([$doctorUserId]);
    $doctor = $docStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $doctor = null;
}

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? $_SESSION['full_name'] ?? 'Doctor');
$displayName = formatDoctorTitle($cleanName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Medicine & OPD', ENT_QUOTES, 'UTF-8');
$roomNumber = htmlspecialchars($doctor['room_number'] ?? 'Chamber 101', ENT_QUOTES, 'UTF-8');
$shiftTimings = htmlspecialchars($doctor['shift_timings'] ?? '09:00 AM - 02:00 PM', ENT_QUOTES, 'UTF-8');
$bmdcLicense = htmlspecialchars($doctor['bmdc_license_number'] ?? 'BMDC-VERIFIED', ENT_QUOTES, 'UTF-8');

$sessionStatus = strtolower($doctor['session_status'] ?? 'idle');
$accumulatedDelta = (int)($doctor['accumulated_delta_minutes'] ?? 0);
$avgMins = (int)(($doctor['avg_consultation_time'] ?? 0) ?: 10);

// ── Fetch Queue Data ────────────────────────────────────────────────────────
$queueData = AppointmentController::getDoctorTodayQueue($pdo, $doctorUserId);
$todayQueue = $queueData['patients'] ?? [];

$currentInChamber = null;
$waitingPatients = [];
$completedCount = 0;

foreach ($todayQueue as $item) {
    $st = strtolower($item['status'] ?? '');
    $qs = strtolower($item['queue_status'] ?? '');
    
    if (($st === 'in_consultation' || $qs === 'serving') && !$currentInChamber) {
        $currentInChamber = $item;
    } elseif (in_array($st, ['booked', 'checked_in']) && $qs !== 'completed') {
        $waitingPatients[] = $item;
    } elseif ($st === 'completed' || $qs === 'completed') {
        $completedCount++;
    }
}

$nextInQueue = $waitingPatients[0] ?? null;
$upcomingThree = array_slice($waitingPatients, 0, 3);
$waitingCount = count($waitingPatients);
$totalBooked = count($todayQueue);
$remainingCapacity = max(0, 25 - $totalBooked);

// ── If Patient Active in Chamber: Fetch Medical Dossier Records ─────────────
$patientPastReports = [];
$patientPastPrescriptions = [];
$bpVal = '120/80';
$pulseVal = '74';
$sugarVal = '5.6';
$tempVal = '98.6';
$isAllergic = false;
$allergiesText = 'NKDA (No Known Drug Allergies)';
$chiefComplaint = 'General Consultation';
$actualStartTs = time();

if ($currentInChamber) {
    $pId = (int)$currentInChamber['patient_id'];
    
    // Past Diagnostic Reports
    try {
        $pastReportsStmt = $pdo->prepare("
            SELECT report_id, test_name, test_category, delivery_status, report_file_path, uploaded_at
            FROM diagnostic_reports
            WHERE patient_id = ?
            ORDER BY uploaded_at DESC
            LIMIT 8
        ");
        $pastReportsStmt->execute([$pId]);
        $patientPastReports = $pastReportsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $patientPastReports = [];
    }

    // Past Prescriptions
    try {
        $pastRxStmt = $pdo->prepare("
            SELECT p.prescription_id, p.diagnosis_notes, p.vitals_summary, p.prescribed_at, u.full_name AS doctor_name
            FROM prescriptions p
            LEFT JOIN users u ON p.doctor_id = u.user_id
            WHERE p.patient_id = ?
            ORDER BY p.prescribed_at DESC
            LIMIT 8
        ");
        $pastRxStmt->execute([$pId]);
        $patientPastPrescriptions = $pastRxStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $patientPastPrescriptions = [];
    }

    // Parse Baseline Vitals
    $vitalsRaw = $currentInChamber['baseline_vitals'] ?? '';
    if (!empty($vitalsRaw)) {
        if (preg_match('/BP:\s*([0-9\/\s]+mmHg|[0-9\/]+)/i', $vitalsRaw, $m)) {
            $bpVal = trim(str_replace('mmHg', '', $m[1]));
        }
        if (preg_match('/Pulse:\s*([0-9]+)/i', $vitalsRaw, $m)) {
            $pulseVal = trim($m[1]);
        }
        if (preg_match('/(Sugar|Glucose|HbA1c):\s*([0-9\.]+\s*(mmol\/L|mg\/dL)?)/i', $vitalsRaw, $m)) {
            $sugarVal = trim($m[2]);
        }
        if (preg_match('/(Temp|Temperature):\s*([0-9\.]+\s*(°F|°C)?)/i', $vitalsRaw, $m)) {
            $tempVal = trim($m[2]);
        }
    }

    // Allergies & Alerts
    $rawAllergies = trim($currentInChamber['allergies'] ?? '');
    if (!empty($rawAllergies)) {
        $allergiesText = $rawAllergies;
        $up = strtoupper($rawAllergies);
        if (!str_starts_with($up, 'NKDA') && $up !== 'NONE' && $up !== 'NIL' && $up !== 'NO') {
            $isAllergic = true;
        }
    }

    // Chief Complaint (prioritize intake symptoms submitted at booking)
    if (!empty($currentInChamber['symptoms'])) {
        $chiefComplaint = $currentInChamber['symptoms'];
    } elseif (!empty($currentInChamber['reason_for_visit'])) {
        $chiefComplaint = $currentInChamber['reason_for_visit'];
    }

    // Actual consultation start time
    if (!empty($currentInChamber['actual_start_time'])) {
        $parsedStart = strtotime($currentInChamber['actual_start_time']);
        $actualStartTs = ($parsedStart > 0) ? $parsedStart : time();
    } else {
        $actualStartTs = time();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | OPD Chamber Console &amp; Medical Dossier</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">

  <style>
    /* ── High-End Chamber Header & Session Controls ───────────────────────── */
    .chamber-header-card {
      background: linear-gradient(135deg, #090d16 0%, #111827 50%, #1e293b 100%);
      color: #ffffff;
      padding: 1.75rem 2rem;
      border-radius: 18px;
      margin-bottom: 2rem;
      box-shadow: 0 12px 30px -8px rgba(15, 23, 42, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1.5rem;
    }
    .session-badge-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.35rem 0.85rem;
      border-radius: 9999px;
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }
    .session-badge-live {
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border: 1px solid rgba(52, 211, 153, 0.4);
    }
    .session-badge-idle {
      background: rgba(245, 158, 11, 0.15);
      color: #fbbf24;
      border: 1px solid rgba(251, 191, 36, 0.4);
    }
    .session-badge-completed {
      background: rgba(148, 163, 184, 0.15);
      color: #cbd5e1;
      border: 1px solid rgba(203, 213, 225, 0.3);
    }
    .pacing-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.32rem 0.75rem;
      border-radius: 8px;
      font-size: 0.75rem;
      font-weight: 700;
    }
    .pacing-ahead {
      background: rgba(16, 185, 129, 0.15);
      color: #34d399;
      border: 1px solid rgba(52, 211, 153, 0.3);
    }
    .pacing-delayed {
      background: rgba(245, 158, 11, 0.15);
      color: #fbbf24;
      border: 1px solid rgba(251, 191, 36, 0.3);
    }
    .pacing-normal {
      background: rgba(56, 189, 248, 0.15);
      color: #38bdf8;
      border: 1px solid rgba(56, 189, 248, 0.3);
    }
    .pulse-dot {
      display: inline-block;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #10b981;
      box-shadow: 0 0 10px #10b981;
      animation: pulse 1.6s infinite ease-in-out;
    }
    .pulse-dot-amber {
      display: inline-block;
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #f59e0b;
      box-shadow: 0 0 8px #f59e0b;
    }
    @keyframes pulse {
      0% { transform: scale(0.9); opacity: 0.75; }
      50% { transform: scale(1.35); opacity: 1; }
      100% { transform: scale(0.9); opacity: 0.75; }
    }

    /* ── Main Layout: 2-Column Split ───────────────────────────────────────── */
    .chamber-main-grid {
      display: grid;
      grid-template-columns: 1.45fr 1fr;
      gap: 1.75rem;
      margin-bottom: 2rem;
      align-items: start;
    }
    @media (max-width: 1050px) {
      .chamber-main-grid {
        grid-template-columns: 1fr;
      }
    }

    /* ── Active Medical Dossier Card ──────────────────────────────────────── */
    .dossier-card {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      position: relative;
    }
    .dossier-card.has-patient {
      border: 2px solid #059669;
      box-shadow: 0 14px 35px -5px rgba(5, 150, 105, 0.12);
    }
    .dossier-top-banner {
      background: linear-gradient(135deg, #064e3b 0%, #065f46 60%, #047857 100%);
      color: #ffffff;
      padding: 1.25rem 1.75rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .dossier-body {
      padding: 1.75rem;
      display: flex;
      flex-direction: column;
      gap: 1.35rem;
    }
    .patient-identity-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1.25rem;
      padding-bottom: 1.25rem;
      border-bottom: 1px solid #f1f5f9;
    }
    .giant-token-hero {
      font-size: 2.25rem;
      font-weight: 800;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 68px;
      height: 68px;
      border-radius: 16px;
      background: #10b981;
      color: #ffffff;
      box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
    }
    .patient-chips-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-top: 0.35rem;
    }
    .pt-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.25rem 0.65rem;
      border-radius: 6px;
      font-size: 0.76rem;
      font-weight: 700;
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
    }
    .pt-chip-blood {
      background: #fef2f2;
      color: #dc2626;
      border-color: #fecaca;
    }
    .pt-chip-uid {
      background: #eff6ff;
      color: #1d4ed8;
      border-color: #dbeafe;
    }

    /* ── Live Consultation Stopwatch ───────────────────────────────────────── */
    .stopwatch-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: rgba(0, 0, 0, 0.3);
      padding: 0.45rem 0.95rem;
      border-radius: 10px;
      border: 1px solid rgba(255, 255, 255, 0.15);
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.92rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: #a7f3d0;
    }
    .stopwatch-badge.overtime {
      color: #fcd34d;
      border-color: rgba(251, 191, 36, 0.4);
    }

    /* ── Chief Complaint Box ──────────────────────────────────────────────── */
    .chief-complaint-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-left: 4px solid #0284c7;
      border-radius: 10px;
      padding: 1.15rem 1.25rem;
    }
    .chief-complaint-label {
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #0369a1;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.45rem;
    }
    .chief-complaint-quote {
      font-size: 1.05rem;
      font-weight: 700;
      color: #0f172a;
      line-height: 1.45;
    }

    /* ── 4-Grid Baseline Vitals ────────────────────────────────────────────── */
    .vitals-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 0.75rem;
    }
    @media (max-width: 600px) {
      .vitals-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }
    .vital-tile {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 0.85rem 1rem;
      text-align: center;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
      transition: all 0.2s ease;
    }
    .vital-tile:hover {
      border-color: #cbd5e1;
      transform: translateY(-1px);
    }
    .vital-label {
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      color: #64748b;
      margin-bottom: 0.25rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.3rem;
    }
    .vital-value {
      font-size: 1.25rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.1;
    }
    .vital-unit {
      font-size: 0.72rem;
      font-weight: 600;
      color: #64748b;
    }

    /* ── Medical Alerts Banners ────────────────────────────────────────────── */
    .alert-banner {
      border-radius: 10px;
      padding: 0.85rem 1.15rem;
      display: flex;
      align-items: center;
      gap: 0.85rem;
    }
    .alert-danger {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-left: 4px solid #ef4444;
      color: #991b1b;
    }
    .alert-safe {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-left: 4px solid #10b981;
      color: #166534;
    }

    /* ── Dossier Action Button Strip ──────────────────────────────────────── */
    .dossier-actions {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-top: 0.5rem;
      padding-top: 1.25rem;
      border-top: 1px solid #f1f5f9;
    }
    .btn-rx-action {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      border: none;
      border-radius: 10px;
      padding: 0.75rem 1.25rem;
      font-size: 0.88rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-decoration: none;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
      transition: all 0.2s ease;
    }
    .btn-rx-action:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(2, 132, 199, 0.35);
    }
    .btn-records-action {
      background: #f8fafc;
      color: #334155;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      padding: 0.75rem 1.25rem;
      font-size: 0.88rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-records-action:hover {
      background: #f1f5f9;
      border-color: #94a3b8;
    }
    .btn-hold-action {
      background: #fffbeb;
      color: #b45309;
      border: 1px solid #fde68a;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      font-size: 0.85rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-hold-action:hover {
      background: #fef3c7;
      border-color: #f59e0b;
    }
    .btn-finish-call {
      background: linear-gradient(135deg, #059669 0%, #047857 100%);
      color: #ffffff;
      border: none;
      border-radius: 10px;
      padding: 0.75rem 1.45rem;
      font-size: 0.88rem;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(5, 150, 105, 0.3);
      margin-left: auto;
      transition: all 0.2s ease;
    }
    .btn-finish-call:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(5, 150, 105, 0.4);
    }

    /* ── Vacant Chamber Empty State ───────────────────────────────────────── */
    .vacant-chamber-card {
      padding: 3.5rem 2rem;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
      border-radius: 18px;
      border: 2px dashed #cbd5e1;
    }

    /* ── Upcoming Queue Drawer / Preview (Right Column) ────────────────────── */
    .preview-card {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }
    .preview-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding-bottom: 0.75rem;
      border-bottom: 1px solid #f1f5f9;
    }
    .preview-title {
      font-size: 0.85rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #0284c7;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .queued-item-row {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1rem 1.15rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      transition: all 0.2s ease;
    }
    .queued-item-row:hover {
      background: #f0f9ff;
      border-color: #bae6fd;
      transform: translateX(2px);
    }
    .queued-token-pill {
      font-size: 1.15rem;
      font-weight: 800;
      color: #0284c7;
      background: #e0f2fe;
      border: 1px solid #bae6fd;
      padding: 0.35rem 0.75rem;
      border-radius: 8px;
      min-width: 50px;
      text-align: center;
    }
    .queued-item-body {
      flex: 1;
      min-width: 0;
    }
    .queued-item-name {
      font-size: 0.94rem;
      font-weight: 700;
      color: #0f172a;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .queued-item-complaint {
      font-size: 0.78rem;
      color: #64748b;
      margin-top: 0.2rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* ── Real-Time Pacing & Rolling Delta Card ────────────────────────────── */
    .pacing-infobox {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.15rem;
    }

    /* ── Diagnostics & Past Records Modal ─────────────────────────────────── */
    .modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(4px);
      z-index: 9999;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .modal-backdrop.open {
      display: flex;
    }
    .modal-card {
      background: #ffffff;
      border-radius: 20px;
      width: 100%;
      max-width: 760px;
      max-height: 88vh;
      overflow-y: auto;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      border: 1px solid #cbd5e1;
      display: flex;
      flex-direction: column;
    }
    .modal-header {
      padding: 1.5rem 1.75rem;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      background: #ffffff;
      z-index: 2;
    }
    .modal-tab-nav {
      display: flex;
      gap: 0.5rem;
      padding: 0.75rem 1.75rem;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
    }
    .modal-tab-btn {
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 700;
      border: 1px solid transparent;
      background: transparent;
      color: #64748b;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .modal-tab-btn.active {
      background: #ffffff;
      color: #0284c7;
      border-color: #cbd5e1;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
    }
    .modal-body {
      padding: 1.75rem;
    }
  </style>
</head>
<body>

  <!-- Shared Doctor Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <main class="viewport-full">
    
    <!-- Chamber Command Header with Session Controls -->
    <div class="chamber-header-card">
      <div style="flex: 1; min-width: 300px;">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
          <?php if ($sessionStatus === 'live'): ?>
            <span class="session-badge-pill session-badge-live">
              <span class="pulse-dot"></span> LIVE CHAMBER IN SESSION
            </span>
          <?php elseif ($sessionStatus === 'completed'): ?>
            <span class="session-badge-pill session-badge-completed">
              <span class="pulse-dot-amber" style="background: #94a3b8; box-shadow: none;"></span> SESSION COMPLETED TODAY
            </span>
          <?php else: ?>
            <span class="session-badge-pill session-badge-idle">
              <span class="pulse-dot-amber"></span> CHAMBER IDLE / READY
            </span>
          <?php endif; ?>

          <!-- Rolling Buffer Delta Badge -->
          <?php if ($accumulatedDelta < 0): ?>
            <span class="pacing-pill pacing-ahead">
              ⚡ Running <?= abs($accumulatedDelta) ?> mins ahead of schedule
            </span>
          <?php elseif ($accumulatedDelta > 0): ?>
            <span class="pacing-pill pacing-delayed">
              ⏱ Delayed by ~<?= $accumulatedDelta ?> mins
            </span>
          <?php else: ?>
            <span class="pacing-pill pacing-normal">
              ✓ On schedule (Target: <?= $avgMins ?> mins/pt)
            </span>
          <?php endif; ?>
        </div>

        <h1 style="font-size: 1.85rem; font-weight: 800; color: #ffffff; margin: 0; letter-spacing: -0.5px;">
          <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <div style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.86rem; color: #94a3b8; margin-top: 0.45rem; flex-wrap: wrap;">
          <span><?= $specialty ?></span>
          <span>&bull;</span>
          <span style="color: #38bdf8; font-weight: 700;"><?= $roomNumber ?></span>
          <span>&bull;</span>
          <span>Shift: <?= $shiftTimings ?></span>
          <span>&bull;</span>
          <span>BMDC: <?= $bmdcLicense ?></span>
        </div>
      </div>

      <!-- Action Buttons & Shift Stats -->
      <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <!-- Session Action Controls -->
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <?php if ($sessionStatus !== 'live'): ?>
            <button type="button" onclick="triggerSessionAction('start_session')" class="btn-rx-action" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
              Start Chamber / Go Live
            </button>
          <?php else: ?>
            <button type="button" onclick="confirmEndSession()" class="btn-records-action" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border-color: rgba(239, 68, 68, 0.3);">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect></svg>
              End Chamber Session
            </button>
          <?php endif; ?>
        </div>

        <!-- Shift Stat Counters -->
        <div style="display: flex; gap: 0.75rem;">
          <div style="background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 12px; padding: 0.65rem 1.15rem; text-align: center;">
            <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #94a3b8;">Shift Load</div>
            <div style="font-size: 1.35rem; font-weight: 800; color: #38bdf8;"><?= $totalBooked ?> / 25</div>
          </div>
          <div style="background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 12px; padding: 0.65rem 1.15rem; text-align: center;">
            <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #94a3b8;">In Queue</div>
            <div style="font-size: 1.35rem; font-weight: 800; color: #fbbf24;"><?= $waitingCount ?></div>
          </div>
          <div style="background: rgba(255, 255, 255, 0.07); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 12px; padding: 0.65rem 1.15rem; text-align: center;">
            <div style="font-size: 0.68rem; text-transform: uppercase; font-weight: 700; color: #94a3b8;">Completed</div>
            <div style="font-size: 1.35rem; font-weight: 800; color: #34d399;"><?= $completedCount ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Progression Grid (Dossier + Upcoming Queue) -->
    <div class="chamber-main-grid">
      
      <!-- Primary Left Column: Active Patient Medical Dossier -->
      <?php if ($currentInChamber): ?>
        <div class="dossier-card has-patient">
          
          <!-- Top Bar: Active Status & Consultation Stopwatch -->
          <div class="dossier-top-banner">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
              <span class="pulse-dot" style="width: 10px; height: 10px;"></span>
              <span style="font-size: 0.82rem; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;">
                ACTIVE PATIENT MEDICAL DOSSIER
              </span>
            </div>

            <!-- Real-Time Consultation Elapsed Stopwatch -->
            <div class="stopwatch-badge" id="stopwatchContainer">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
              <span>Elapsed:</span>
              <span id="consultationTimer">00:00</span>
              <span style="color: rgba(255,255,255,0.4); font-size: 0.75rem;">/ <?= $avgMins ?>m</span>
            </div>
          </div>

          <!-- Dossier Body Content -->
          <div class="dossier-body">
            
            <!-- Patient Identity Row -->
            <div class="patient-identity-row">
              <div style="display: flex; align-items: center; gap: 1.25rem;">
                <div class="giant-token-hero">
                  #<?= (int)$currentInChamber['token_number'] ?>
                </div>
                <div>
                  <h2 style="margin: 0; font-size: 1.45rem; font-weight: 800; color: #0f172a; letter-spacing: -0.3px;">
                    <?= htmlspecialchars($currentInChamber['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                  </h2>
                  <div class="patient-chips-row">
                    <span class="pt-chip pt-chip-uid">
                      UHID: <?= htmlspecialchars($currentInChamber['patient_uid'] ?? ('MP-P-' . str_pad((string)$currentInChamber['patient_id'], 4, '0', STR_PAD_LEFT)), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="pt-chip">
                      <?= !empty($currentInChamber['age']) ? ((int)$currentInChamber['age'] . ' yrs') : 'Adult' ?> &bull; <?= htmlspecialchars($currentInChamber['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="pt-chip pt-chip-blood">
                      Blood: <?= htmlspecialchars($currentInChamber['blood_group'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="pt-chip">
                      Phone: <?= htmlspecialchars($currentInChamber['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                </div>
              </div>

              <div>
                <span class="pt-chip" style="background: #f0fdf4; color: #059669; border-color: #a7f3d0; font-weight: 800; font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                  Slot: <?= htmlspecialchars($currentInChamber['time_slot'] ?? 'Morning', ENT_QUOTES, 'UTF-8') ?>
                </span>
              </div>
            </div>

            <!-- Medical Alerts & Allergy Sentinel Banner -->
            <?php if ($isAllergic): ?>
              <div class="alert-banner alert-danger">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#ef4444" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <div style="flex: 1;">
                  <div style="font-size: 0.76rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">CRITICAL ALLERGY ALERT</div>
                  <div style="font-size: 0.94rem; font-weight: 700; margin-top: 0.15rem;">
                    Known Drug Allergies: <?= htmlspecialchars($allergiesText, ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <div class="alert-banner alert-safe">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#10b981" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><polyline points="9 12 11 14 15 10"></polyline></svg>
                <div style="flex: 1;">
                  <div style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">ALLERGY STATUS: VERIFIED NKDA</div>
                  <div style="font-size: 0.88rem; font-weight: 600;">
                    No known drug allergies reported on file.
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <!-- Chief Complaint / Intake Symptoms Card -->
            <div class="chief-complaint-box">
              <div class="chief-complaint-label">
                <span>
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block; vertical-align:text-top; margin-right: 4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                  CHIEF COMPLAINT (INTAKE NOTE)
                </span>
                <span style="font-size: 0.7rem; color: #64748b; font-weight: 600;">Captured via Patient Booking Portal</span>
              </div>
              <div class="chief-complaint-quote">
                “<?= htmlspecialchars($chiefComplaint, ENT_QUOTES, 'UTF-8') ?>”
              </div>
              <?php if (!empty($currentInChamber['reason_for_visit']) && $currentInChamber['reason_for_visit'] !== $chiefComplaint): ?>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.45rem;">
                  <strong>Clinical Category:</strong> <?= htmlspecialchars($currentInChamber['reason_for_visit'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Recorded Baseline Vitals (4-Metric Grid) -->
            <div>
              <div style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; color: #475569; letter-spacing: 0.5px; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                <span>RECORDED BASELINE VITALS</span>
                <span style="font-size: 0.7rem; color: #94a3b8; font-weight: 600;">Triage Baseline / Patient Profile</span>
              </div>
              <div class="vitals-grid">
                <div class="vital-tile">
                  <div class="vital-label">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#ef4444" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
                    Blood Pressure
                  </div>
                  <div class="vital-value"><?= htmlspecialchars($bpVal, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="vital-unit">mmHg</div>
                </div>

                <div class="vital-tile">
                  <div class="vital-label">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#0284c7" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    Pulse Rate
                  </div>
                  <div class="vital-value"><?= htmlspecialchars($pulseVal, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="vital-unit">bpm (Resting)</div>
                </div>

                <div class="vital-tile">
                  <div class="vital-label">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#f59e0b" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="m10 15 5-3-5-3v6Z"></path></svg>
                    Blood Sugar
                  </div>
                  <div class="vital-value"><?= htmlspecialchars($sugarVal, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="vital-unit">mmol/L (Fasting)</div>
                </div>

                <div class="vital-tile">
                  <div class="vital-label">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#10b981" stroke-width="2"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"></path></svg>
                    Body Temp
                  </div>
                  <div class="vital-value"><?= htmlspecialchars($tempVal, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="vital-unit">°F (Normal)</div>
                </div>
              </div>
            </div>

            <!-- Quick Action Buttons Strip -->
            <div class="dossier-actions">
              <!-- Open Digital Rx -->
              <a href="prescriptions.php?patient_id=<?= (int)$currentInChamber['patient_id'] ?>&appointment_id=<?= (int)$currentInChamber['id'] ?>" class="btn-rx-action">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                Open Digital Rx
              </a>

              <!-- View Past Diagnostics/Records -->
              <button type="button" onclick="openRecordsModal()" class="btn-records-action">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                View Diagnostics &amp; History (<?= count($patientPastReports) + count($patientPastPrescriptions) ?>)
              </button>

              <!-- Hold / Skip Current Token -->
              <button type="button" onclick="triggerHoldCurrentToken(<?= (int)$currentInChamber['id'] ?>, <?= (int)$currentInChamber['token_number'] ?>)" class="btn-hold-action" title="Temporarily delay or hold this patient">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                Hold / Skip
              </button>

              <!-- Finish & Call Next Token -->
              <button type="button" id="btnFinishAndNext" onclick="triggerCallNextWithDuration()" class="btn-finish-call">
                <span>Finish &amp; Call Next Patient</span>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
              </button>
            </div>

          </div>
        </div>
      <?php else: ?>
        <!-- Vacant Chamber State Card -->
        <div class="vacant-chamber-card">
          <div style="width: 72px; height: 72px; border-radius: 50%; background: #f0fdf4; display: flex; align-items: center; justify-content: center; margin-bottom: 1.25rem; border: 1px solid #bbf7d0;">
            <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#10b981" stroke-width="1.8"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>
          </div>
          <h2 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem 0;">
            Chamber is Currently Vacant
          </h2>
          <p style="font-size: 0.92rem; color: #64748b; max-width: 440px; margin: 0 0 1.5rem 0;">
            <?php if ($nextInQueue): ?>
              Next patient waiting: <strong style="color: #0284c7;">#<?= (int)$nextInQueue['token_number'] ?> &mdash; <?= htmlspecialchars($nextInQueue['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>. Ready to admit into consultation.
            <?php else: ?>
              All scheduled appointments for this shift have been served or no patients are currently waiting.
            <?php endif; ?>
          </p>

          <div>
            <button type="button" id="btnCallFirst" onclick="triggerCallNextWithDuration()" class="btn-rx-action" style="padding: 0.95rem 2rem; font-size: 1.05rem;" <?= empty($nextInQueue) ? 'disabled' : '' ?>>
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
              Admit Next Patient (#<?= $nextInQueue ? (int)$nextInQueue['token_number'] : '—' ?>)
            </button>
          </div>
        </div>
      <?php endif; ?>

      <!-- Right Column: Upcoming Queue Drawer / Preview (Next 3 Patients) -->
      <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        
        <div class="preview-card">
          <div class="preview-header">
            <span class="preview-title">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              UPCOMING IN QUEUE (NEXT 3)
            </span>
            <span style="font-size: 0.74rem; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 0.2rem 0.6rem; border-radius: 6px;">
              <?= $waitingCount ?> WAITING
            </span>
          </div>

          <?php if (empty($upcomingThree)): ?>
            <div style="text-align: center; padding: 2rem 1rem; color: #94a3b8;">
              <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="m9 11 3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
              <div style="font-size: 0.9rem; font-weight: 700; color: #64748b;">No Queued Patients Waiting</div>
              <div style="font-size: 0.76rem; margin-top: 0.2rem;">Chamber queue is currently clear.</div>
            </div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
              <?php foreach ($upcomingThree as $idx => $upPt): 
                $uToken = (int)$upPt['token_number'];
                $uComplaint = !empty($upPt['symptoms']) ? $upPt['symptoms'] : ($upPt['reason_for_visit'] ?? 'Consultation');
              ?>
                <div class="queued-item-row">
                  <div class="queued-token-pill">#<?= $uToken ?></div>
                  <div class="queued-item-body">
                    <div class="queued-item-name">
                      <?= htmlspecialchars($upPt['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div style="font-size: 0.74rem; color: #64748b; margin-top: 0.15rem;">
                      <?= !empty($upPt['age']) ? ((int)$upPt['age'] . ' yrs') : 'Adult' ?> &bull; <?= htmlspecialchars($upPt['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> &bull; <span style="color: #0284c7; font-weight: 600;"><?= htmlspecialchars($upPt['time_slot'] ?? 'Morning', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="queued-item-complaint" title="<?= htmlspecialchars($uComplaint, ENT_QUOTES, 'UTF-8') ?>">
                      “<?= htmlspecialchars($uComplaint, ENT_QUOTES, 'UTF-8') ?>”
                    </div>
                  </div>
                  <?php if (!$currentInChamber && $idx === 0): ?>
                    <button type="button" onclick="triggerCallNextWithDuration()" class="btn-rx-action" style="padding: 0.4rem 0.75rem; font-size: 0.76rem; border-radius: 8px;">
                      Call
                    </button>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Real-Time Rolling Delta & Pacing Insights Card -->
        <div class="preview-card">
          <div class="preview-header">
            <span class="preview-title" style="color: #0f172a;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 10"></polyline></svg>
              REAL-TIME PACING &amp; ROLLING BUFFER
            </span>
          </div>

          <div class="pacing-infobox">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
              <span style="font-size: 0.8rem; color: #64748b; font-weight: 600;">Standard Consultation Benchmark:</span>
              <strong style="font-size: 0.86rem; color: #0f172a;"><?= $avgMins ?> mins / patient</strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
              <span style="font-size: 0.8rem; color: #64748b; font-weight: 600;">Accumulated Chamber Delta:</span>
              <?php if ($accumulatedDelta < 0): ?>
                <span style="color: #059669; font-weight: 800; font-size: 0.88rem;">-<?= abs($accumulatedDelta) ?> mins (Ahead)</span>
              <?php elseif ($accumulatedDelta > 0): ?>
                <span style="color: #d97706; font-weight: 800; font-size: 0.88rem;">+<?= $accumulatedDelta ?> mins (Delayed)</span>
              <?php else: ?>
                <span style="color: #0284c7; font-weight: 800; font-size: 0.88rem;">0 mins (Strictly on Time)</span>
              <?php endif; ?>
            </div>
            <p style="font-size: 0.74rem; color: #64748b; margin: 0; line-height: 1.4; border-top: 1px dashed #cbd5e1; padding-top: 0.65rem;">
              Whenever a consultation completes ahead or behind schedule, the delta propagates dynamically across all patient dashboards to maintain accurate estimated arrival times.
            </p>
          </div>
        </div>

      </div>

    </div>

    <!-- OPD Sequential Session Queue Table (1 - 25 Sequential List) -->
    <section class="admin-stack-card">
      <div class="admin-stack-header" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
            Today's OPD Session Serial List (1 - 25 Sequential)
          </h3>
          <p>Sorted strictly by assigned token number. Cap 25 per date and shift.</p>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
          <button onclick="window.location.reload()" class="btn-teal-action" style="padding: 0.48rem 0.85rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem;">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
            Refresh Queue
          </button>
        </div>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table" id="opdQueueTable">
          <thead>
            <tr>
              <th style="width: 80px;">Token</th>
              <th>Patient Name</th>
              <th>Contact Phone</th>
              <th>Shift Slot</th>
              <th>Chief Complaint / Reason</th>
              <th>Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($todayQueue)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                  No patient appointments registered for today's session.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($todayQueue as $pt): 
                $tNum = (int)$pt['token_number'];
                $sVal = strtolower($pt['status'] ?? '');
                $qVal = strtolower($pt['queue_status'] ?? '');
                $isServing = ($sVal === 'in_consultation' || $qVal === 'serving');
                $isCompleted = ($sVal === 'completed' || $qVal === 'completed');
                $ptComplaint = !empty($pt['symptoms']) ? $pt['symptoms'] : ($pt['reason_for_visit'] ?? 'General Consultation');
              ?>
                <tr id="queue-row-<?= $tNum ?>" style="<?= $isServing ? 'background: rgba(16, 185, 129, 0.08); font-weight: 600;' : '' ?>">
                  <td>
                    <span class="live-chip-sm" style="font-size: 0.95rem; font-weight: 800; padding: 4px 10px; background: <?= $isServing ? '#ecfdf5; color: #059669; border: 1px solid #a7f3d0;' : ($isCompleted ? '#f1f5f9; color: #64748b; border: 1px solid #cbd5e1;' : '#e0f2fe; color: #0284c7; border: 1px solid #bae6fd;') ?>">
                      #<?= $tNum ?>
                    </span>
                  </td>
                  <td>
                    <strong style="font-size: 0.92rem; color: var(--text-heading);">
                      <?= htmlspecialchars($pt['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                    </strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted);">
                      <?= !empty($pt['age']) ? ((int)$pt['age'] . ' yrs') : 'Adult' ?> &bull; <?= htmlspecialchars($pt['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td style="font-size: 0.84rem; color: var(--text-muted);"><?= htmlspecialchars($pt['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <strong style="font-size: 0.84rem; color: var(--brand-primary);"><?= htmlspecialchars($pt['time_slot'] ?? 'Morning', ENT_QUOTES, 'UTF-8') ?></strong>
                    <div style="font-size: 0.72rem; color: var(--text-muted);"><?= !empty($pt['appointment_time']) ? date('h:i A', strtotime($pt['appointment_time'])) : 'Scheduled' ?></div>
                  </td>
                  <td style="max-width: 260px;">
                    <div style="font-size: 0.84rem; color: var(--text-body); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($ptComplaint, ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($ptComplaint, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($isServing): ?>
                      <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-weight: 800;">
                        <span class="pulse-dot" style="width: 6px; height: 6px; margin-right: 4px;"></span>
                        INSIDE CHAMBER
                      </span>
                    <?php elseif ($isCompleted): ?>
                      <span class="chip-disbursed" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">Completed</span>
                    <?php else: ?>
                      <span class="chip-consult" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">Waiting</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: right;">
                    <?php if ($isServing): ?>
                      <a href="prescriptions.php?patient_id=<?= (int)$pt['patient_id'] ?>&appointment_id=<?= (int)$pt['id'] ?>" class="btn-action-gradient" style="padding: 0.4rem 0.85rem; font-size: 0.76rem; text-decoration: none;">
                        Prescribe Rx &rarr;
                      </a>
                    <?php elseif (!$isCompleted): ?>
                      <button type="button" onclick="triggerCallNextWithDuration()" class="btn-teal-action" style="padding: 0.4rem 0.75rem; font-size: 0.76rem;">
                        Call #<?= $tNum ?>
                      </button>
                    <?php else: ?>
                      <span style="font-size: 0.76rem; color: #94a3b8;">Served</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <!-- Past Diagnostics & Clinical Records Modal -->
  <div class="modal-backdrop" id="recordsModal">
    <div class="modal-card">
      <div class="modal-header">
        <div>
          <h3 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #0f172a;">
            Patient Medical Dossier &amp; History
          </h3>
          <p style="margin: 0.25rem 0 0 0; font-size: 0.82rem; color: #64748b;">
            <?= $currentInChamber ? htmlspecialchars($currentInChamber['patient_name'], ENT_QUOTES, 'UTF-8') : 'Patient' ?> &bull; Historical Lab Diagnostics &amp; Prescriptions
          </p>
        </div>
        <button type="button" onclick="closeRecordsModal()" style="background: #f1f5f9; border: none; border-radius: 8px; width: 34px; height: 34px; font-size: 1.1rem; cursor: pointer; color: #64748b;">
          &times;
        </button>
      </div>

      <div class="modal-tab-nav">
        <button type="button" class="modal-tab-btn active" id="tabBtnReports" onclick="switchModalTab('reports')">
          Diagnostic Reports (<?= count($patientPastReports) ?>)
        </button>
        <button type="button" class="modal-tab-btn" id="tabBtnRx" onclick="switchModalTab('rx')">
          Past Prescriptions (<?= count($patientPastPrescriptions) ?>)
        </button>
      </div>

      <div class="modal-body">
        
        <!-- Tab 1: Diagnostic Laboratory Reports -->
        <div id="tabContentReports">
          <?php if (empty($patientPastReports)): ?>
            <div style="text-align: center; padding: 2.5rem 1rem; color: #94a3b8;">
              <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
              <div style="font-weight: 700; color: #64748b;">No Diagnostic Reports Found</div>
              <div style="font-size: 0.8rem; margin-top: 0.25rem;">This patient has no previously uploaded pathology or radiology reports.</div>
            </div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
              <?php foreach ($patientPastReports as $rep): 
                $rDate = !empty($rep['uploaded_at']) ? date('M j, Y h:i A', strtotime($rep['uploaded_at'])) : '—';
              ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem 1.15rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                  <div>
                    <div style="font-size: 0.95rem; font-weight: 800; color: #0f172a;">
                      <?= htmlspecialchars($rep['test_name'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div style="font-size: 0.76rem; color: #64748b; margin-top: 0.2rem;">
                      Category: <span style="font-weight: 700; color: #0284c7;"><?= htmlspecialchars($rep['test_category'], ENT_QUOTES, 'UTF-8') ?></span> &bull; Uploaded: <?= $rDate ?>
                    </div>
                  </div>
                  <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0; font-size: 0.74rem;">
                      <?= htmlspecialchars($rep['delivery_status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if (!empty($rep['report_file_path'])): ?>
                      <a href="../<?= htmlspecialchars($rep['report_file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn-rx-action" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">
                        View PDF
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Tab 2: Past Digital Prescriptions -->
        <div id="tabContentRx" style="display: none;">
          <?php if (empty($patientPastPrescriptions)): ?>
            <div style="text-align: center; padding: 2.5rem 1rem; color: #94a3b8;">
              <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom: 0.5rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><line x1="9" y1="15" x2="15" y2="15"></line></svg>
              <div style="font-weight: 700; color: #64748b;">No Past Prescriptions on Record</div>
              <div style="font-size: 0.8rem; margin-top: 0.25rem;">This patient has no prior recorded digital prescriptions in MedPulse.</div>
            </div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
              <?php foreach ($patientPastPrescriptions as $rx): 
                $rxDate = !empty($rx['prescribed_at']) ? date('M j, Y', strtotime($rx['prescribed_at'])) : '—';
              ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem 1.15rem;">
                  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                    <strong style="color: #0284c7; font-size: 0.88rem;">
                      #RX-<?= str_pad((string)$rx['prescription_id'], 4, '0', STR_PAD_LEFT) ?>
                    </strong>
                    <span style="font-size: 0.76rem; color: #64748b;"><?= $rxDate ?></span>
                  </div>
                  <div style="font-size: 0.88rem; font-weight: 700; color: #0f172a; margin-bottom: 0.35rem;">
                    <?= htmlspecialchars($rx['diagnosis_notes'] ?? 'Clinical review', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="font-size: 0.76rem; color: #64748b;">
                    Vitals: <?= htmlspecialchars($rx['vitals_summary'] ?? 'BP: 120/80 mmHg', ENT_QUOTES, 'UTF-8') ?> &bull; Attending: <?= htmlspecialchars($rx['doctor_name'] ?? 'Consultant', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <script>
    // Audio Chime Synthesizer
    function playCallChime() {
      try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15); // A5
        gain.gain.setValueAtTime(0.25, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.45);
      } catch (e) {}
    }

    // Live Consultation Elapsed Stopwatch
    const consultationStartTs = <?= $currentInChamber ? ($actualStartTs * 1000) : 'null' ?>;
    const avgConsultationMinutes = <?= $avgMins ?>;

    function updateConsultationTimer() {
      if (!consultationStartTs) return;
      const now = Date.now();
      const elapsedMs = Math.max(0, now - consultationStartTs);
      const totalSeconds = Math.floor(elapsedMs / 1000);
      const mins = Math.floor(totalSeconds / 60);
      const secs = totalSeconds % 60;
      
      const el = document.getElementById('consultationTimer');
      if (el) {
        el.textContent = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
      }

      const container = document.getElementById('stopwatchContainer');
      if (container && mins >= avgConsultationMinutes) {
        container.classList.add('overtime');
      }
    }

    if (consultationStartTs) {
      updateConsultationTimer();
      setInterval(updateConsultationTimer, 1000);
    }

    // Modal Control Functions
    function openRecordsModal() {
      const modal = document.getElementById('recordsModal');
      if (modal) modal.classList.add('open');
    }
    function closeRecordsModal() {
      const modal = document.getElementById('recordsModal');
      if (modal) modal.classList.remove('open');
    }
    function switchModalTab(tab) {
      const btnReports = document.getElementById('tabBtnReports');
      const btnRx = document.getElementById('tabBtnRx');
      const contentReports = document.getElementById('tabContentReports');
      const contentRx = document.getElementById('tabContentRx');

      if (tab === 'reports') {
        btnReports.classList.add('active');
        btnRx.classList.remove('active');
        contentReports.style.display = 'block';
        contentRx.style.display = 'none';
      } else {
        btnRx.classList.add('active');
        btnReports.classList.remove('active');
        contentReports.style.display = 'none';
        contentRx.style.display = 'block';
      }
    }
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeRecordsModal();
    });

    // Chamber Action Handlers
    async function triggerSessionAction(actionName) {
      const formData = new FormData();
      formData.append('action', actionName);
      formData.append('ajax', '1');

      try {
        const res = await fetch('chamber.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        const json = await res.json();
        if (json.status === 'success') {
          if (typeof showToast === 'function') {
            showToast(json.data.message || 'Action executed successfully.', 'success');
          }
          setTimeout(() => window.location.reload(), 400);
        } else {
          alert(json.data?.message || 'Action failed.');
        }
      } catch (err) {
        window.location.reload();
      }
    }

    function confirmEndSession() {
      if (confirm("End today's chamber session? Active consultation will be marked completed.")) {
        triggerSessionAction('end_session');
      }
    }

    async function triggerHoldCurrentToken(appointmentId, tokenNumber) {
      if (!confirm(`Place Token #${tokenNumber} on temporary hold and return to waiting queue?`)) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'hold_token');
      formData.append('appointment_id', appointmentId);
      formData.append('ajax', '1');

      try {
        const res = await fetch('chamber.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        const json = await res.json();
        if (json.status === 'success') {
          if (typeof showToast === 'function') {
            showToast(json.data.message || 'Token placed on hold.', 'success');
          }
          setTimeout(() => window.location.reload(), 400);
        } else {
          alert(json.data?.message || 'Error placing token on hold.');
        }
      } catch (e) {
        window.location.reload();
      }
    }

    // Call Next Token with duration calculation
    async function triggerCallNextWithDuration() {
      const btn = document.getElementById('btnFinishAndNext') || document.getElementById('btnCallFirst');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<span style="display:inline-block; animation: spin 1s infinite linear;">↻</span> Advancing Queue...`;
      }

      let actualMins = null;
      if (consultationStartTs) {
        const elapsedSecs = Math.floor((Date.now() - consultationStartTs) / 1000);
        actualMins = Math.max(1, Math.round(elapsedSecs / 60));
      }

      const formData = new FormData();
      formData.append('action', 'call_next');
      if (actualMins !== null) {
        formData.append('actual_duration', actualMins);
      }
      formData.append('ajax', '1');

      try {
        const res = await fetch('chamber.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });
        const json = await res.json();
        if (json.status === 'success') {
          playCallChime();
          if (typeof showToast === 'function') {
            showToast(json.data.message || 'Next patient called into chamber!', 'success');
          }
          setTimeout(() => window.location.reload(), 450);
        } else {
          if (typeof showToast === 'function') {
            showToast(json.data?.message || 'No more patients waiting in queue.', 'error');
          } else {
            alert(json.data?.message || 'No more patients waiting in queue.');
          }
          if (btn) {
            btn.disabled = false;
            btn.innerHTML = `Finish &amp; Call Next Patient &rarr;`;
          }
        }
      } catch (err) {
        window.location.reload();
      }
    }

    // Background Queue Polling every 15 seconds
    setInterval(() => {
      fetch('../backend/api/opd_queue.php?action=doctor_queue')
        .then(r => r.json())
        .then(res => {
          if (res.status === 'success') {
            const serverCount = res.data.queue ? res.data.queue.length : (res.data.patients ? res.data.patients.length : 0);
            const currentRows = document.querySelectorAll('#opdQueueTable tbody tr[id^="queue-row-"]').length;
            if (serverCount !== currentRows) {
              window.location.reload();
            }
          }
        })
        .catch(() => {});
    }, 15000);
  </script>
</body>
</html>

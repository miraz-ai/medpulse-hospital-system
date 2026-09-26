<?php
/**
 * MedPulse Outpatient Department (OPD) — Specialist Appointment Booking
 * 
 * Features:
 * - Direct specialist selection across network hospital facilities
 * - Shift capacity inspection (Strict 25-patient cap per session)
 * - Sequential token generation (#1, #2, #3...)
 * - Duplicate booking protection
 */

require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../controllers/AppointmentController.php';

$patientId = (int)$_SESSION['user_id'];
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
       || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

// ── 0. Route GET requests to new Discovery Grid ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Location: specialists.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

// ── 1. Handle Appointment Booking Form Submission ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $appointmentDate = trim($_POST['appointment_date'] ?? date('Y-m-d'));
    $timeSlot = trim($_POST['time_slot'] ?? 'Morning');
    $reason   = trim($_POST['reason_for_visit'] ?? '');
    $symptoms = trim($_POST['symptoms']          ?? '');

    if ($doctorId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Please select an attending medical doctor.']);
            exit();
        }
        $errorMsg = 'Please select an attending medical doctor.';
    } else {
        $result = AppointmentController::bookAppointment($pdo, $patientId, $doctorId, $appointmentDate, $timeSlot, $reason, $symptoms);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => $result['success'] ? 'success' : 'error',
                'message' => $result['message'],
                'data'    => $result
            ]);
            exit();
        }

        if ($result['success']) {
            header("Location: dashboard.php?booking_success=1&token=" . $result['token_number']);
            exit();
        } else {
            $errorMsg = $result['message'];
        }
    }
}

// ── 2. Query Active Approved Doctors Across Network Facilities ───────────────
try {
    $docQuery = $pdo->prepare("
        SELECT u.user_id, u.full_name, dp.specialty, dp.designation, dp.qualifications,
               dp.consultation_fee, dp.room_number, dp.available_days,
               h.hospital_id, h.name AS hospital_name, h.city AS hospital_city
        FROM doctors d
        INNER JOIN users u ON (d.user_id = u.user_id OR d.id = u.user_id)
        INNER JOIN doctor_profiles dp ON u.user_id = dp.user_id
        LEFT JOIN hospitals h ON COALESCE(d.hospital_id, dp.hospital_id) = h.hospital_id
        WHERE u.role = 'Doctor' 
          AND u.status = 'active'
          AND d.status IN ('active', 'approved')
          AND dp.approval_status = 'approved'
          AND u.password_hash IS NOT NULL AND u.password_hash != ''
        ORDER BY h.hospital_id ASC, u.full_name ASC
    ");
    $docQuery->execute();
    $doctors = $docQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to query doctors in book_appointment.php: " . $e->getMessage());
    $doctors = [];
}

// ── 3. Query Patient Demographics for Display ─────────────────────────────────
$stmtP = $pdo->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
$stmtP->execute([$patientId]);
$patientInfo = $stmtP->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book OPD Specialist Consultation &middot; MedPulse Hospital System</title>
  
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  
  <style>
    .booking-container {
      max-width: 900px;
      margin: 2rem auto;
      padding: 0 1.25rem;
    }
    .booking-card {
      background: #ffffff;
      border: 1px solid var(--border-subtle);
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.06);
      padding: 2rem 2.25rem;
    }
    .booking-header {
      margin-bottom: 2rem;
      border-bottom: 1px solid var(--border-subtle);
      padding-bottom: 1.25rem;
    }
    .booking-header h1 {
      font-size: 1.6rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.35rem;
    }
    .booking-header p {
      color: var(--text-muted);
      font-size: 0.9rem;
    }
    .form-group {
      margin-bottom: 1.4rem;
    }
    .form-group label {
      display: block;
      font-size: 0.85rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 0.45rem;
    }
    .form-group select,
    .form-group input,
    .form-group textarea {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      font-family: inherit;
      font-size: 0.92rem;
      color: var(--text-heading);
      background-color: #f8fafc;
      transition: all 0.2s ease;
    }
    .form-group select:focus,
    .form-group input:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--brand-primary);
      background-color: #ffffff;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    }
    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;
    }
    @media (max-width: 640px) {
      .form-grid-2 { grid-template-columns: 1fr; }
    }
    .slot-pill-group {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.85rem;
    }
    .slot-pill-group input {
      display: none;
    }
    .slot-pill-group label {
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      padding: 0.85rem 1rem;
      text-align: center;
      cursor: pointer;
      font-weight: 600;
      color: var(--text-muted);
      background: #f8fafc;
      transition: all 0.2s ease;
      margin-bottom: 0;
    }
    .slot-pill-group input:checked + label {
      border-color: var(--brand-primary);
      background: rgba(2, 132, 199, 0.08);
      color: var(--brand-primary);
      box-shadow: 0 0 0 2px var(--brand-primary);
    }
    .slot-sub {
      display: block;
      font-size: 0.75rem;
      font-weight: 500;
      color: var(--text-muted);
      margin-top: 3px;
    }
    .capacity-banner {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 12px;
      padding: 0.9rem 1.15rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.86rem;
      color: #1e40af;
    }
    .alert-error-box {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
      padding: 0.85rem 1.1rem;
      border-radius: 10px;
      font-size: 0.88rem;
      margin-bottom: 1.5rem;
    }
    .btn-submit-booking {
      width: 100%;
      padding: 0.85rem 1.5rem;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #ffffff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .btn-submit-booking:hover {
      opacity: 0.94;
      transform: translateY(-1px);
    }
  </style>
</head>
<body style="background: var(--surface-bg);">

  <div class="booking-container">
    <div style="margin-bottom: 1.25rem;">
      <a href="dashboard.php" style="display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); text-decoration: none; font-size: 0.88rem; font-weight: 600;">
        <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Back to Patient Dashboard
      </a>
    </div>

    <div class="booking-card">
      <div class="booking-header">
        <h1>Schedule Outpatient Specialist Consultation</h1>
        <p>Real-time sequential token allocation with automated chamber queue tracking (Capped at 25 patients/session)</p>
      </div>

      <?php if (!empty($errorMsg)): ?>
        <div class="alert-error-box">
          <strong>Booking Unsuccessful:</strong> <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <div class="capacity-banner">
        <svg class="ui-ico" style="stroke: #2563eb; width: 22px; height: 22px; flex-shrink: 0;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <div>
          <strong>Chamber Quality Policy:</strong> To ensure dedicated patient care, each doctor's shift is strictly capped at <strong>25 patients</strong>. Serial tokens are assigned strictly sequentially (1, 2, 3...).
        </div>
      </div>

      <form action="book_appointment.php" method="POST" id="opdBookingForm">
        <div class="form-group">
          <label for="doctorSelect">Select Attending Specialist <span style="color: var(--status-red);">*</span></label>
          <select id="doctorSelect" name="doctor_id" required>
            <option value="" disabled selected>-- Choose Medical Specialist & Facility --</option>
            <?php foreach ($doctors as $d): ?>
              <option value="<?= (int)$d['user_id'] ?>">
                <?= htmlspecialchars($d['full_name']) ?> &middot; <?= htmlspecialchars($d['specialty']) ?> &middot; <?= htmlspecialchars($d['hospital_name'] ?? 'MedPulse Central') ?> (Fee: ৳<?= number_format((float)$d['consultation_fee']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-grid-2">
          <div class="form-group">
            <label for="appDate">Appointment Date <span style="color: var(--status-red);">*</span></label>
            <input type="date" id="appDate" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
          </div>

          <div class="form-group">
            <label>Select Chamber Shift <span style="color: var(--status-red);">*</span></label>
            <div class="slot-pill-group">
              <div>
                <input type="radio" id="slotMorning" name="time_slot" value="Morning" checked>
                <label for="slotMorning">
                  Morning Shift
                  <span class="slot-sub">09:00 AM &ndash; 01:00 PM</span>
                </label>
              </div>
              <div>
                <input type="radio" id="slotEvening" name="time_slot" value="Evening">
                <label for="slotEvening">
                  Evening Shift
                  <span class="slot-sub">04:00 PM &ndash; 08:00 PM</span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label for="visitReason">Clinical Concern / Reason for Visit (Optional)</label>
          <textarea id="visitReason" name="reason_for_visit" rows="2" placeholder="Briefly describe your reason for consulting the specialist..."></textarea>
        </div>

        <div class="form-group">
          <label for="symptomsField"
                 style="display:flex; align-items:center; gap:6px;">
            <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:#0d9488;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            Primary Symptoms / Chief Complaint
            <span style="font-size:0.78rem;font-weight:500;color:var(--text-muted);">(Optional)</span>
          </label>
          <textarea
            id="symptomsField"
            name="symptoms"
            rows="3"
            maxlength="1000"
            placeholder="e.g., High fever for 3 days, severe cough, chest tightness"
            style="resize:vertical;"
          ></textarea>
          <div style="text-align:right;font-size:0.72rem;color:var(--text-muted);margin-top:4px;">
            <span id="symptomsCount">0</span>/1000 characters
          </div>
        </div>

        <button type="submit" class="btn-submit-booking" id="btnConfirmBooking">
          <svg class="ui-ico ui-ico-sm" style="stroke: white;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Confirm OPD Appointment &amp; Issue Token
        </button>
      </form>
    </div>
  </div>

<script>
  (function () {
    var ta  = document.getElementById('symptomsField');
    var cnt = document.getElementById('symptomsCount');
    if (ta && cnt) {
      ta.addEventListener('input', function () {
        var len = ta.value.length;
        cnt.textContent = len;
        cnt.style.color = len > 900 ? '#dc2626' : '';
      });
    }
  })();
</script>
</body>
</html>

<?php
/**
 * MedPulse Doctor Portal — Consultations & Schedule Manager
 * Outpatient queue, appointment booking tracking, and clinical consultation records.
 */

require_once __DIR__ . '/../includes/doctor_auth.php';
require_once __DIR__ . '/../includes/doctor_helpers.php';

$doctorUserId = (int)$_SESSION['user_id'];

// Fetch Doctor Info
try {
    $docStmt = $pdo->prepare("
        SELECT u.full_name, dp.specialty, dp.designation, dp.military_rank, dp.qualifications, dp.bmdc_license_number, dp.room_number, dp.shift_timings
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

$cleanName = cleanDoctorBaseName($doctor['full_name'] ?? 'Doctor');
$displayName = formatDoctorTitle($cleanName, $doctor['designation'] ?? null, $doctor['military_rank'] ?? null);
$specialty = htmlspecialchars($doctor['specialty'] ?? 'General Surgery & Critical Care', ENT_QUOTES, 'UTF-8');

// Fetch All Appointments for this Doctor scoped to current hospital facility
try {
    $sessionHospitalId = (int)($_SESSION['hospital_id'] ?? 1);
    $appStmt = $pdo->prepare("
        SELECT a.id, a.appointment_id, a.appointment_date, a.appointment_time, 
               COALESCE(a.token_number, a.serial_number) AS serial_number,
               a.reason_for_visit, a.status, a.created_at,
               u.user_id AS patient_id, u.full_name AS patient_name, u.email, u.phone, u.gender, u.blood_group,
               p.patient_uid
        FROM appointments a
        JOIN users u ON a.patient_id = u.user_id
        LEFT JOIN patients p ON (p.user_id = u.user_id OR p.id = a.patient_id)
        WHERE a.doctor_id = :doc_id AND a.hospital_id = :hosp_id
        ORDER BY a.appointment_date DESC, a.token_number ASC
    ");
    $appStmt->execute([':doc_id' => $doctorUserId, ':hosp_id' => $sessionHospitalId]);
    $allAppointments = $appStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allAppointments = [];
}

// Group appointments into Today, Upcoming, and Completed
$todayAppointments = [];
$upcomingAppointments = [];
$pastAppointments = [];

$todayStr = date('Y-m-d');

foreach ($allAppointments as $app) {
    if ($app['appointment_date'] === $todayStr) {
        $todayAppointments[] = $app;
    } elseif ($app['appointment_date'] > $todayStr) {
        $upcomingAppointments[] = $app;
    } else {
        $pastAppointments[] = $app;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Consultations &amp; Schedule</title>
  
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
</head>
<body>

  <!-- Shared Production Doctor Sidebar -->
  <?php require_once __DIR__ . '/../includes/doctor_sidebar.php'; ?>

  <!-- Main Viewport -->
  <main class="viewport-full">

    <!-- Header Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>
          Consultations &amp; OPD Schedule
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 24px; height: 24px;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
        </h1>
        <p>Outpatient queue, patient bookings, and clinical consultation roster for <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <div class="banner-actions">
        <a href="dashboard.php" class="btn-action-telemed" style="text-decoration: none;">
          &larr; Overview
        </a>
        <a href="prescriptions.php" class="btn-action-gradient" style="text-decoration: none;">
          Clinical Prescriptions &rarr;
        </a>
      </div>
    </div>

    <!-- Quick Vital Cards -->
    <div class="stat-cards-grid">
      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Today's Consultations</span>
          <svg class="ui-ico" style="stroke: var(--brand-primary);" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= count($todayAppointments) ?></div>
        <div class="stat-card-badge badge-blue"><?= date('M j, Y') ?></div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Upcoming Schedule</span>
          <svg class="ui-ico" style="stroke: var(--brand-teal);" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line></svg>
        </div>
        <div class="stat-card-number"><?= count($upcomingAppointments) ?></div>
        <div class="stat-card-badge badge-green">Future Bookings</div>
      </div>

      <div class="stat-card-executive">
        <div class="stat-card-head">
          <span>Completed Consultations</span>
          <svg class="ui-ico" style="stroke: var(--status-green);" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="stat-card-number"><?= count($pastAppointments) ?></div>
        <div class="stat-card-badge badge-green">Archive History</div>
      </div>
    </div>

    <!-- Appointments Table Section -->
    <section class="admin-stack-card" style="margin-top: 1.75rem;">
      <div class="admin-stack-header">
        <div class="admin-stack-title-group">
          <h3>
            <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line></svg>
            Patient Consultation Queue
          </h3>
          <p>Chronological listing of registered outpatient appointments</p>
        </div>
        <span class="live-chip-sm" style="background: #e0f2fe; color: #0284c7; border-color: #bae6fd; font-size: 0.76rem;">
          <?= count($allAppointments) ?> TOTAL CONSULTATIONS
        </span>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>Serial</th>
              <th>Patient Profile</th>
              <th>Appointment Date &amp; Slot</th>
              <th>Chief Complaint / Reason</th>
              <th>Contact Phone</th>
              <th>Consultation Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allAppointments)): ?>
              <tr>
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No consultation records found in your queue.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($allAppointments as $app): 
                $appTimeFormatted = date('h:i A', strtotime($app['appointment_time']));
                $appDateFormatted = date('M j, Y', strtotime($app['appointment_date']));
                $isToday = $app['appointment_date'] === $todayStr;
              ?>
                <tr>
                  <td>
                    <span class="live-chip-sm" style="background: #e0f2fe; color: #0284c7; border-color: #bae6fd; font-size: 0.75rem;">
                      #<?= (int)$app['serial_number'] ?>
                    </span>
                  </td>
                  <td>
                    <div class="user-cell-flex">
                      <div class="user-avatar-initials"><?= strtoupper(substr($app['patient_name'], 0, 2)) ?></div>
                      <div>
                        <strong style="font-size: 0.92rem; color: var(--text-heading);"><?= htmlspecialchars($app['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <div style="font-size: 0.72rem; color: var(--text-muted);">
                          <?= htmlspecialchars($app['gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> &bull; UHID: MP-P-<?= str_pad((string)$app['patient_id'], 4, '0', STR_PAD_LEFT) ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <strong style="color: var(--brand-primary); font-size: 0.86rem;"><?= $appDateFormatted ?></strong>
                    <div style="font-size: 0.74rem; color: var(--text-muted);"><?= $appTimeFormatted ?> <?= $isToday ? '<span class="live-chip-sm" style="background:#fef3c7;color:#d97706;border-color:#fcd34d;font-size:10px;padding:1px 4px;">TODAY</span>' : '' ?></div>
                  </td>
                  <td style="max-width: 250px;">
                    <div style="font-size: 0.82rem; color: var(--text-body); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                      <?= htmlspecialchars($app['reason_for_visit'] ?? 'General Medical Review', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td style="font-size: 0.82rem; color: var(--text-muted);"><?= htmlspecialchars($app['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <?php if ($app['status'] === 'Completed'): ?>
                      <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">COMPLETED</span>
                    <?php elseif ($app['status'] === 'In-Consultation'): ?>
                      <span class="live-chip-sm" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">IN CONSULTATION</span>
                    <?php else: ?>
                      <span class="live-chip-sm" style="background: #eff6ff; color: #2563eb; border-color: #bfdbfe;">SCHEDULED</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align: right;">
                    <a href="prescriptions.php?patient_id=<?= (int)$app['patient_id'] ?>" class="btn-action-gradient" style="padding: 0.4rem 0.8rem; font-size: 0.76rem; text-decoration: none;">
                      Begin Rx
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</body>
</html>

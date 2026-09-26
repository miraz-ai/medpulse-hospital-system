<?php
/**
 * MedPulse Enterprise HMS — Medical Specialists Discovery & 1-Click OPD Booking
 * 
 * Features:
 * - Real-time verified database doctor discovery grid
 * - Instant search and specialty/department filter pills
 * - 1-Click interactive booking modal with pre-selected doctor info
 * - Strict 25-patient session cap & sequential token generation
 * - Direct redirect to patient/dashboard.php with live queue activation
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/patient_auth.php';
require_once __DIR__ . '/../controllers/AppointmentController.php';

$patientId   = (int)$_SESSION['user_id'];
$patientName = $_SESSION['user_name'] ?? 'Patient';

$bookingError   = null;
$preselectDocId = isset($_GET['book_doctor_id']) ? (int)$_GET['book_doctor_id'] : 0;

// ── Handle Booking Form Submission ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_booking') {
    // CSRF verification
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $bookingError = 'Security validation failed. Please refresh and try again.';
    } else {
        $doctorId        = (int)($_POST['doctor_id'] ?? 0);
        $appointmentDate = trim($_POST['appointment_date'] ?? date('Y-m-d'));
        $timeSlot        = trim($_POST['time_slot'] ?? 'Morning');
        $symptoms        = trim($_POST['symptoms'] ?? '');
        $reason          = !empty($symptoms) ? $symptoms : 'Specialist Consultation';

        if ($doctorId <= 0) {
            $bookingError = 'Please select a valid medical specialist.';
        } else {
            $result = AppointmentController::bookAppointment($pdo, $patientId, $doctorId, $appointmentDate, $timeSlot, $reason, $symptoms);
            if (!empty($result['success'])) {
                header("Location: dashboard.php?booking_success=1&token=" . (int)($result['token_number'] ?? 1));
                exit();
            } else {
                $bookingError = $result['message'] ?? 'Unable to complete appointment booking. Please try again.';
                $preselectDocId = $doctorId; // Re-open modal for user
            }
        }
    }
}

// ── Query Approved Active Doctors Strictly From Database ─────────────────────
try {
    $docQuery = $pdo->prepare("
        SELECT 
            u.user_id, u.full_name, u.email,
            COALESCE(dp.specialty, d.specialty, 'General Medicine') AS specialty,
            COALESCE(dp.designation, 'Consultant Specialist') AS designation,
            COALESCE(dp.qualifications, 'MBBS') AS qualifications,
            COALESCE(dp.consultation_fee, 1000.00) AS consultation_fee,
            COALESCE(dp.room_number, d.chamber_room_no, 'Room 101') AS room_number,
            COALESCE(dp.available_days, 'Daily (Mon - Fri)') AS available_days,
            COALESCE(dp.shift_start_time, '09:00:00') AS shift_start_time,
            COALESCE(dp.shift_end_time, '17:00:00') AS shift_end_time,
            COALESCE(h.name, d.hospital_name, 'MedPulse Hospital & Specialty Care') AS hospital_name,
            COALESCE(h.city, 'Dhaka') AS hospital_city
        FROM users u
        INNER JOIN doctors d ON (d.user_id = u.user_id OR d.id = u.user_id)
        LEFT JOIN doctor_profiles dp ON u.user_id = dp.user_id
        LEFT JOIN hospitals h ON COALESCE(d.hospital_id, dp.hospital_id) = h.hospital_id
        WHERE u.role = 'Doctor' 
          AND u.status = 'active'
          AND (d.status IS NULL OR d.status IN ('active', 'approved'))
        ORDER BY u.full_name ASC
    ");
    $docQuery->execute();
    $doctors = $docQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Failed to query doctors in specialists.php: " . $e->getMessage());
    $doctors = [];
}

// ── Extract Specialty Filters ────────────────────────────────────────────────
$specialties = [];
foreach ($doctors as $doc) {
    $spec = trim($doc['specialty'] ?? '');
    if (!empty($spec) && !in_array($spec, $specialties, true)) {
        $specialties[] = $spec;
    }
}
sort($specialties);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Medical Specialists Directory &middot; MedPulse Hospital System</title>
  
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  
  <style>
    /* Scoped Styles for Doctor Discovery Grid & Booking Modal */
    .specialists-view {
      max-width: 1280px;
      margin: 0 auto;
    }

    /* Search & Filter Header */
    .filter-section {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 16px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
    }
    .search-row {
      display: flex;
      gap: 1rem;
      align-items: center;
      margin-bottom: 1.25rem;
      flex-wrap: wrap;
    }
    .search-input-wrap {
      flex: 1;
      min-width: 280px;
      position: relative;
    }
    .search-input-wrap svg {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      pointer-events: none;
      width: 18px;
      height: 18px;
    }
    .search-input-wrap input {
      width: 100%;
      padding: 0.75rem 1rem 0.75rem 2.6rem;
      border: 1.5px solid var(--surface-border);
      border-radius: 12px;
      font-size: 0.92rem;
      font-family: inherit;
      background: #f8fafc;
      color: var(--text-heading);
      transition: all 0.2s ease;
    }
    .search-input-wrap input:focus {
      outline: none;
      background: #ffffff;
      border-color: var(--brand-primary);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    }
    .search-counter {
      font-size: 0.86rem;
      color: var(--text-muted);
      font-weight: 600;
      white-space: nowrap;
    }

    /* Specialty Filter Pills */
    .filter-pills {
      display: flex;
      gap: 0.5rem;
      overflow-x: auto;
      padding-bottom: 4px;
      scrollbar-width: thin;
    }
    .filter-pills::-webkit-scrollbar {
      height: 4px;
    }
    .filter-pills::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 4px;
    }
    .spec-pill {
      background: #f1f5f9;
      color: var(--text-body);
      border: 1px solid transparent;
      padding: 0.45rem 0.95rem;
      border-radius: 999px;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .spec-pill:hover {
      background: #e2e8f0;
      color: var(--text-heading);
    }
    .spec-pill.active {
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #ffffff;
      box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
    }

    /* Doctor Grid Layout */
    .doctor-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
      gap: 1.5rem;
      margin-bottom: 3rem;
    }

    /* Doctor Card */
    .doc-card {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 18px;
      padding: 1.5rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }
    .doc-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 12px 24px -8px rgba(2, 132, 199, 0.12), 0 4px 12px rgba(0, 0, 0, 0.04);
      border-color: #cbd5e1;
    }
    .doc-card-top {
      display: flex;
      gap: 1rem;
      align-items: flex-start;
      margin-bottom: 1rem;
    }
    .doc-avatar {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(2, 132, 199, 0.12), rgba(13, 148, 136, 0.15));
      color: var(--brand-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      font-weight: 800;
      flex-shrink: 0;
      border: 1px solid rgba(2, 132, 199, 0.2);
    }
    .doc-header-info {
      flex: 1;
      min-width: 0;
    }
    .doc-name {
      font-size: 1.08rem;
      font-weight: 800;
      color: var(--text-heading);
      margin-bottom: 0.2rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .doc-designation {
      font-size: 0.8rem;
      color: var(--text-muted);
      font-weight: 600;
      margin-bottom: 0.45rem;
    }
    .doc-specialty-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.65rem;
      border-radius: 6px;
      font-size: 0.76rem;
      font-weight: 700;
      background: rgba(2, 132, 199, 0.09);
      color: var(--brand-primary);
      border: 1px solid rgba(2, 132, 199, 0.2);
    }

    /* Card Details List */
    .doc-details-list {
      background: #f8fafc;
      border-radius: 12px;
      padding: 0.85rem 1rem;
      margin-bottom: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      font-size: 0.83rem;
    }
    .doc-detail-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: var(--text-body);
    }
    .doc-detail-row svg {
      width: 15px;
      height: 15px;
      stroke: var(--brand-teal);
      flex-shrink: 0;
    }
    .doc-detail-text {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Card Bottom Row */
    .doc-card-bottom {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      border-top: 1px solid var(--surface-border-subtle);
      padding-top: 1rem;
      margin-top: auto;
    }
    .doc-fee-box {
      display: flex;
      flex-direction: column;
    }
    .doc-fee-label {
      font-size: 0.72rem;
      color: var(--text-muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .doc-fee-val {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading);
    }
    .btn-book-card {
      padding: 0.65rem 1.15rem;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #ffffff;
      border: none;
      border-radius: 10px;
      font-size: 0.86rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s ease;
      box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
    }
    .btn-book-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
      opacity: 0.96;
    }

    /* Empty Grid State */
    .no-results-state {
      grid-column: 1 / -1;
      background: #ffffff;
      border: 1px dashed var(--surface-border);
      border-radius: 16px;
      padding: 3.5rem 1.5rem;
      text-align: center;
      display: none;
    }
    .no-results-state svg {
      width: 48px;
      height: 48px;
      stroke: var(--text-muted);
      margin-bottom: 1rem;
    }
    .no-results-state h3 {
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 0.35rem;
    }
    .no-results-state p {
      color: var(--text-muted);
      font-size: 0.88rem;
    }

    /* ── Sleek 1-Click Booking Modal ─────────────────────────────────────── */
    .modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(4px);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 1.25rem;
      opacity: 0;
      transition: opacity 0.25s ease;
    }
    .modal-backdrop.open {
      display: flex;
      opacity: 1;
    }
    .booking-modal-box {
      background: #ffffff;
      width: 100%;
      max-width: 580px;
      border-radius: 20px;
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.2);
      border: 1px solid rgba(255, 255, 255, 0.8);
      overflow: hidden;
      transform: scale(0.96);
      transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      max-height: 90vh;
      display: flex;
      flex-direction: column;
    }
    .modal-backdrop.open .booking-modal-box {
      transform: scale(1);
    }
    .modal-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid var(--surface-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #ffffff;
    }
    .modal-header h3 {
      font-size: 1.15rem;
      font-weight: 800;
      color: var(--text-heading);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .modal-close-btn {
      background: #f1f5f9;
      border: none;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      color: var(--text-body);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      font-weight: 700;
      transition: all 0.2s ease;
    }
    .modal-close-btn:hover {
      background: #e2e8f0;
      color: #ef4444;
    }
    .modal-body {
      padding: 1.5rem;
      overflow-y: auto;
    }

    /* Selected Doctor Pill in Modal */
    .modal-doctor-preview {
      background: linear-gradient(135deg, rgba(2, 132, 199, 0.05), rgba(13, 148, 136, 0.08));
      border: 1.5px solid rgba(2, 132, 199, 0.18);
      border-radius: 14px;
      padding: 1rem 1.15rem;
      margin-bottom: 1.25rem;
      display: flex;
      gap: 12px;
      align-items: center;
    }
    .modal-doc-avatar {
      width: 46px;
      height: 46px;
      border-radius: 12px;
      background: var(--brand-primary);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      font-weight: 800;
      flex-shrink: 0;
    }
    .modal-doc-info {
      flex: 1;
      min-width: 0;
    }
    .modal-doc-name {
      font-size: 1rem;
      font-weight: 800;
      color: var(--text-heading);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .modal-doc-sub {
      font-size: 0.78rem;
      color: var(--brand-teal);
      font-weight: 600;
      margin-top: 2px;
    }

    /* Modal Form Elements */
    .form-field {
      margin-bottom: 1.25rem;
    }
    .form-field label {
      display: block;
      font-size: 0.84rem;
      font-weight: 700;
      color: var(--text-heading);
      margin-bottom: 0.45rem;
    }
    .form-field input[type="date"],
    .form-field textarea {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1.5px solid var(--surface-border);
      border-radius: 10px;
      font-family: inherit;
      font-size: 0.9rem;
      color: var(--text-heading);
      background-color: #f8fafc;
      transition: all 0.2s ease;
    }
    .form-field input[type="date"]:focus,
    .form-field textarea:focus {
      outline: none;
      background-color: #ffffff;
      border-color: var(--brand-primary);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    }
    .form-field textarea {
      min-height: 80px;
      resize: vertical;
    }
    .field-hint {
      font-size: 0.76rem;
      color: var(--text-muted);
      margin-top: 0.35rem;
    }

    /* Interactive Shift Selector Pills */
    .shift-toggle-group {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.75rem;
    }
    .shift-toggle-group input[type="radio"] {
      display: none;
    }
    .shift-label {
      border: 1.5px solid var(--surface-border);
      border-radius: 12px;
      padding: 0.75rem 0.85rem;
      cursor: pointer;
      transition: all 0.2s ease;
      background: #f8fafc;
      display: flex;
      flex-direction: column;
      gap: 2px;
      user-select: none;
    }
    .shift-label:hover {
      background: #f1f5f9;
      border-color: #cbd5e1;
    }
    .shift-toggle-group input[type="radio"]:checked + .shift-label {
      border-color: var(--brand-primary);
      background: rgba(2, 132, 199, 0.08);
      box-shadow: 0 0 0 2px var(--brand-primary);
    }
    .shift-title {
      font-size: 0.88rem;
      font-weight: 700;
      color: var(--text-heading);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .shift-toggle-group input[type="radio"]:checked + .shift-label .shift-title {
      color: var(--brand-primary);
    }
    .shift-hours {
      font-size: 0.75rem;
      color: var(--text-muted);
      font-weight: 500;
    }

    /* Modal Cap Info Banner */
    .modal-cap-banner {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 10px;
      padding: 0.75rem 0.95rem;
      font-size: 0.8rem;
      color: #1e40af;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: flex-start;
      gap: 8px;
      line-height: 1.4;
    }
    .modal-cap-banner svg {
      width: 18px;
      height: 18px;
      stroke: #2563eb;
      flex-shrink: 0;
      margin-top: 1px;
    }

    /* Modal Submit Button */
    .btn-submit-modal {
      width: 100%;
      padding: 0.85rem 1.5rem;
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-teal));
      color: #ffffff;
      border: none;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 800;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.28);
    }
    .btn-submit-modal:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(2, 132, 199, 0.35);
      opacity: 0.96;
    }

    /* Booking Error Alert Banner */
    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 12px;
      padding: 0.85rem 1.15rem;
      margin-bottom: 1.5rem;
      color: #991b1b;
      font-size: 0.88rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <main class="viewport">
    <div class="specialists-view">

      <!-- Header Section -->
      <div class="welcome-banner" style="margin-bottom: 1.75rem;">
        <div class="welcome-text">
          <h1>Medical Specialists Directory</h1>
          <p>Verified consultants across MedPulse network facilities. Select a doctor to instantly book your OPD consultation and generate your sequential queue token.</p>
        </div>
        <div class="banner-actions">
          <a href="appointments.php" class="btn-secondary" style="text-decoration:none;">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            My Consultations
          </a>
        </div>
      </div>

      <!-- Booking Error Message If Any -->
      <?php if (!empty($bookingError)): ?>
        <div class="alert-error">
          <svg class="ui-ico" style="stroke: #ef4444;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <div>
            <strong>Booking Request Notice:</strong> <?= htmlspecialchars($bookingError, ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Search & Specialty Filter Bar -->
      <div class="filter-section">
        <div class="search-row">
          <div class="search-input-wrap">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" id="doctorSearchInput" placeholder="Search doctor by name, department, chamber room, or facility..." autocomplete="off">
          </div>
          <div class="search-counter" id="doctorCounter">
            Showing <?= count($doctors) ?> verified doctors
          </div>
        </div>

        <!-- Filter Pills -->
        <div class="filter-pills" id="filterPillsContainer">
          <button type="button" class="spec-pill active" data-specialty="all">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
            All Specialists (<?= count($doctors) ?>)
          </button>
          <?php foreach ($specialties as $sp): ?>
            <?php 
              $countForSpec = 0;
              foreach ($doctors as $d) {
                  if (strcasecmp(trim($d['specialty'] ?? ''), $sp) === 0) $countForSpec++;
              }
            ?>
            <button type="button" class="spec-pill" data-specialty="<?= htmlspecialchars($sp, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($sp) ?> (<?= $countForSpec ?>)
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Doctor Profile Cards Grid -->
      <div class="doctor-grid" id="doctorGrid">
        <?php foreach ($doctors as $doc): ?>
          <?php
            $initials = '';
            $parts = explode(' ', trim($doc['full_name']));
            foreach ($parts as $p) {
                if (!empty($p) && !in_array(strtolower($p), ['dr.', 'dr', 'prof.', 'professor'])) {
                    $initials .= strtoupper($p[0]);
                    if (strlen($initials) >= 2) break;
                }
            }
            if (empty($initials)) $initials = 'DR';
            $feeFormatted = number_format((float)$doc['consultation_fee']);
          ?>
          <div class="doc-card" 
               data-doc-id="<?= (int)$doc['user_id'] ?>"
               data-doc-name="<?= htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8') ?>"
               data-specialty="<?= htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8') ?>"
               data-hospital="<?= htmlspecialchars($doc['hospital_name'], ENT_QUOTES, 'UTF-8') ?>"
               data-room="<?= htmlspecialchars($doc['room_number'], ENT_QUOTES, 'UTF-8') ?>"
               data-fee="৳<?= $feeFormatted ?>"
               data-search="<?= htmlspecialchars(strtolower($doc['full_name'] . ' ' . $doc['specialty'] . ' ' . $doc['hospital_name'] . ' ' . $doc['room_number']), ENT_QUOTES, 'UTF-8') ?>">
            
            <div>
              <div class="doc-card-top">
                <div class="doc-avatar">
                  <?= htmlspecialchars($initials) ?>
                </div>
                <div class="doc-header-info">
                  <div class="doc-name" title="<?= htmlspecialchars($doc['full_name']) ?>">
                    <?= htmlspecialchars($doc['full_name']) ?>
                  </div>
                  <div class="doc-designation">
                    <?= htmlspecialchars($doc['designation']) ?> &middot; <?= htmlspecialchars($doc['qualifications']) ?>
                  </div>
                  <span class="doc-specialty-badge">
                    <?= htmlspecialchars($doc['specialty']) ?>
                  </span>
                </div>
              </div>

              <div class="doc-details-list">
                <div class="doc-detail-row">
                  <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                  <span class="doc-detail-text"><?= htmlspecialchars($doc['hospital_name']) ?> (<?= htmlspecialchars($doc['hospital_city']) ?>)</span>
                </div>
                <div class="doc-detail-row">
                  <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                  <span class="doc-detail-text">Chamber: <strong><?= htmlspecialchars($doc['room_number']) ?></strong></span>
                </div>
                <div class="doc-detail-row">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                  <span class="doc-detail-text">Schedule: <?= htmlspecialchars($doc['available_days']) ?> &middot; Morning &amp; Evening</span>
                </div>
              </div>
            </div>

            <div class="doc-card-bottom">
              <div class="doc-fee-box">
                <span class="doc-fee-label">Consultation Fee</span>
                <span class="doc-fee-val">৳<?= $feeFormatted ?></span>
              </div>
              <button type="button" class="btn-book-card btn-trigger-book"
                      data-doc-id="<?= (int)$doc['user_id'] ?>"
                      data-doc-name="<?= htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                      data-specialty="<?= htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8') ?>"
                      data-hospital="<?= htmlspecialchars($doc['hospital_name'], ENT_QUOTES, 'UTF-8') ?>"
                      data-room="<?= htmlspecialchars($doc['room_number'], ENT_QUOTES, 'UTF-8') ?>"
                      data-fee="৳<?= $feeFormatted ?>">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: #ffffff;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                + Book Consultation
              </button>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Empty Filter State -->
        <div class="no-results-state" id="noResultsState">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
          <h3>No Matching Specialists Found</h3>
          <p>Try searching for a different doctor name or clear your department filter.</p>
        </div>
      </div>

    </div>
  </main>

  <!-- ── 1-Click Streamlined Booking Modal ───────────────────────────────── -->
  <div class="modal-backdrop" id="bookingModalBackdrop" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="booking-modal-box">
      
      <div class="modal-header">
        <h3 id="modalTitle">
          <svg class="ui-ico" style="stroke: var(--brand-primary); width: 22px; height: 22px;" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          OPD Chamber Consultation
        </h3>
        <button type="button" class="modal-close-btn" id="closeBookingModal" aria-label="Close dialog">&times;</button>
      </div>

      <div class="modal-body">
        
        <!-- Pre-selected Doctor Summary Card -->
        <div class="modal-doctor-preview">
          <div class="modal-doc-avatar" id="modalDocAvatar">DR</div>
          <div class="modal-doc-info">
            <div class="modal-doc-name" id="modalDocName">Attending Specialist</div>
            <div class="modal-doc-sub" id="modalDocSub">Specialty &middot; Chamber Room</div>
          </div>
          <div style="text-align: right; flex-shrink: 0;">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Fee</div>
            <div style="font-size: 1.05rem; font-weight: 800; color: var(--text-heading);" id="modalDocFee">৳1,000</div>
          </div>
        </div>

        <div class="modal-cap-banner">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <div>
            <strong>Guaranteed Sequential Token:</strong> Shifts strictly capped at 25 patients. Tokens are issued in real-time (#1, #2, #3...) with live rolling countdown on your dashboard.
          </div>
        </div>

        <form action="specialists.php" method="POST" id="opdQuickBookingForm">
          <input type="hidden" name="action" value="confirm_booking">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="doctor_id" id="modalDoctorIdInput" value="0">

          <!-- 1. Appointment Date -->
          <div class="form-field">
            <label for="modalAppDate">1. Appointment Date <span style="color: #ef4444;">*</span></label>
            <input type="date" id="modalAppDate" name="appointment_date" required
                   min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            <div class="field-hint">Today's bookings immediately appear in the live chamber queue.</div>
          </div>

          <!-- 2. Chamber Shift (Interactive Pill Selector) -->
          <div class="form-field">
            <label>2. Chamber Shift <span style="color: #ef4444;">*</span></label>
            <div class="shift-toggle-group">
              <label>
                <input type="radio" name="time_slot" value="Morning" checked>
                <div class="shift-label">
                  <div class="shift-title">
                    Morning Shift
                    <svg class="ui-ico ui-ico-sm" style="stroke: #f59e0b;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                  </div>
                  <div class="shift-hours">09:00 AM &ndash; 01:00 PM</div>
                </div>
              </label>

              <label>
                <input type="radio" name="time_slot" value="Evening">
                <div class="shift-label">
                  <div class="shift-title">
                    Evening Shift
                    <svg class="ui-ico ui-ico-sm" style="stroke: #6366f1;" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                  </div>
                  <div class="shift-hours">04:00 PM &ndash; 08:00 PM</div>
                </div>
              </label>
            </div>
          </div>

          <!-- 3. Primary Symptoms / Reason for Visit -->
          <div class="form-field">
            <label for="modalSymptoms">3. Primary Symptoms / Reason for Visit (Optional)</label>
            <textarea id="modalSymptoms" name="symptoms" 
                      placeholder="e.g., High fever for 3 days, acute chest tightness, severe migraine, routine prescription renewal..."></textarea>
            <div class="field-hint">Transmitted securely to the doctor's live chamber console for intake triage.</div>
          </div>

          <!-- Big Action Submit Button -->
          <button type="submit" class="btn-submit-modal" id="submitBookingBtn">
            <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: #ffffff;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            Confirm Booking &amp; Get Token
          </button>
        </form>

      </div>
    </div>
  </div>

  <!-- ── Interactive Filtering & Modal Javascript ────────────────────────── -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput       = document.getElementById('doctorSearchInput');
      const counterEl         = document.getElementById('doctorCounter');
      const filterPills       = document.querySelectorAll('.spec-pill');
      const cards             = document.querySelectorAll('.doc-card');
      const noResultsState    = document.getElementById('noResultsState');

      const modalBackdrop     = document.getElementById('bookingModalBackdrop');
      const closeModalBtn     = document.getElementById('closeBookingModal');
      const modalDoctorId     = document.getElementById('modalDoctorIdInput');
      const modalDocName      = document.getElementById('modalDocName');
      const modalDocSub       = document.getElementById('modalDocSub');
      const modalDocFee       = document.getElementById('modalDocFee');
      const modalDocAvatar    = document.getElementById('modalDocAvatar');
      const submitBtn         = document.getElementById('submitBookingBtn');
      const bookingForm       = document.getElementById('opdQuickBookingForm');

      let currentSpecialty = 'all';

      // ── Filter Cards Function ──────────────────────────────────────────────
      function filterDoctors() {
        const query = (searchInput ? searchInput.value : '').toLowerCase().trim();
        let visibleCount = 0;

        cards.forEach(card => {
          const cardSearch = card.getAttribute('data-search') || '';
          const cardSpec   = card.getAttribute('data-specialty') || '';

          const matchesQuery = !query || cardSearch.includes(query);
          const matchesSpec  = (currentSpecialty === 'all') || (cardSpec.toLowerCase() === currentSpecialty.toLowerCase());

          if (matchesQuery && matchesSpec) {
            card.style.display = 'flex';
            visibleCount++;
          } else {
            card.style.display = 'none';
          }
        });

        if (counterEl) {
          counterEl.textContent = `Showing ${visibleCount} verified doctor${visibleCount === 1 ? '' : 's'}`;
        }
        if (noResultsState) {
          noResultsState.style.display = (visibleCount === 0) ? 'block' : 'none';
        }
      }

      if (searchInput) {
        searchInput.addEventListener('input', filterDoctors);
      }

      filterPills.forEach(pill => {
        pill.addEventListener('click', function() {
          filterPills.forEach(p => p.classList.remove('active'));
          this.classList.add('active');
          currentSpecialty = this.getAttribute('data-specialty') || 'all';
          filterDoctors();
        });
      });

      // ── Open Booking Modal ────────────────────────────────────────────────
      function openModalForDoctor(docData) {
        if (!docData) return;
        modalDoctorId.value    = docData.id || '0';
        modalDocName.textContent = docData.name || 'Doctor';
        modalDocSub.textContent  = `${docData.specialty || ''} · ${docData.room || ''} (${docData.hospital || ''})`;
        modalDocFee.textContent  = docData.fee || '৳1,000';

        // Compute initials
        let initials = 'DR';
        if (docData.name) {
          const parts = docData.name.replace(/^(Dr\.|Prof\.|Professor)\s+/i, '').trim().split(' ');
          if (parts.length >= 2) {
            initials = (parts[0][0] + parts[1][0]).toUpperCase();
          } else if (parts[0]) {
            initials = parts[0].substring(0, 2).toUpperCase();
          }
        }
        modalDocAvatar.textContent = initials;

        modalBackdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
      }

      function closeModal() {
        modalBackdrop.classList.remove('open');
        document.body.style.overflow = '';
      }

      // Card trigger buttons
      document.querySelectorAll('.btn-trigger-book').forEach(btn => {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          const docData = {
            id:        this.getAttribute('data-doc-id'),
            name:      this.getAttribute('data-doc-name'),
            specialty: this.getAttribute('data-specialty'),
            hospital:  this.getAttribute('data-hospital'),
            room:      this.getAttribute('data-room'),
            fee:       this.getAttribute('data-fee')
          };
          openModalForDoctor(docData);
        });
      });

      if (closeModalBtn) {
        closeModalBtn.addEventListener('click', closeModal);
      }
      if (modalBackdrop) {
        modalBackdrop.addEventListener('click', function(e) {
          if (e.target === modalBackdrop) closeModal();
        });
      }
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modalBackdrop.classList.contains('open')) {
          closeModal();
        }
      });

      // Submit loading feedback
      if (bookingForm && submitBtn) {
        bookingForm.addEventListener('submit', function() {
          submitBtn.disabled = true;
          submitBtn.innerHTML = `
            <svg class="ui-ico ui-ico-sm" style="animation: spin 1s linear infinite; stroke: #fff;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="16"></circle></svg>
            Allocating Token &amp; Syncing Queue...
          `;
        });
      }

      // ── Auto-Open Modal if Preselected Doctor in URL ───────────────────────
      const preselectId = <?= $preselectDocId ?>;
      if (preselectId > 0) {
        const targetCard = document.querySelector(`.doc-card[data-doc-id="${preselectId}"]`);
        if (targetCard) {
          const btn = targetCard.querySelector('.btn-trigger-book');
          if (btn) btn.click();
        }
      }
    });
  </script>
</body>
</html>

<?php
/**
 * MedPulse Enterprise HMS — Virtual Care Suite / Telemedicine
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/patient_auth.php';
$patientName = $_SESSION['user_name'] ?? 'Patient';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Virtual Care Suite &middot; MedPulse Hospital System</title>
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
</head>
<body>
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <main class="viewport">
    <div class="welcome-banner">
      <div class="welcome-text">
        <h1>Virtual Care Suite &middot; HD Telemedicine</h1>
        <p>Encrypted audio/video clinical sessions and doctor-patient telehealth consultations.</p>
      </div>
    </div>

    <div class="patient-card-stack" style="background:#fff; border:1px solid var(--surface-border); border-radius:16px; padding:2.5rem; text-align:center; max-width:800px; margin:2rem auto;">
      <div style="width:64px; height:64px; border-radius:50%; background:rgba(37,99,235,0.1); color:#2563eb; display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
        <svg style="width:32px; height:32px; stroke:currentColor;" fill="none" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
      </div>
      <h3 style="font-size:1.25rem; font-weight:800; color:var(--text-heading); margin-bottom:0.5rem;">No Active Telehealth Call In Session</h3>
      <p style="color:var(--text-muted); font-size:0.9rem; max-width:480px; margin:0 auto 1.5rem;">
        Telemedicine rooms activate 15 minutes before your scheduled appointment time. You can book an OPD video consultation with an attending specialist.
      </p>
      <a href="appointments.php" class="btn-action-gradient" style="text-decoration:none; padding:0.65rem 1.4rem; font-size:0.85rem; font-weight:700; border-radius:8px; display:inline-block;">
        Book Teleconsultation &rarr;
      </a>
    </div>
  </main>
</body>
</html>

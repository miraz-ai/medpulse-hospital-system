<?php
/**
 * MedPulse Enterprise HMS — Digital Prescriptions (Rx)
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
  <title>Digital Rx &middot; MedPulse Hospital System</title>
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
        <h1>Digital Prescriptions (Rx)</h1>
        <p>Verified doctor prescriptions, medication dosages, and pharmacy dispensing instructions.</p>
      </div>
    </div>

    <div class="patient-card-stack" style="background:#fff; border:1px solid var(--surface-border); border-radius:16px; padding:2.5rem; text-align:center; max-width:800px; margin:2rem auto;">
      <div style="width:64px; height:64px; border-radius:50%; background:rgba(16,185,129,0.1); color:#059669; display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
        <svg style="width:32px; height:32px; stroke:currentColor;" fill="none" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
      </div>
      <h3 style="font-size:1.25rem; font-weight:800; color:var(--text-heading); margin-bottom:0.5rem;">Digital Prescriptions Archive</h3>
      <p style="color:var(--text-muted); font-size:0.9rem; max-width:480px; margin:0 auto 1.5rem;">
        All e-prescriptions issued during your clinical OPD consultations or hospital admissions are archived here for direct pharmacy dispensing.
      </p>
      <a href="appointments.php" class="btn-action-gradient" style="text-decoration:none; padding:0.65rem 1.4rem; font-size:0.85rem; font-weight:700; border-radius:8px; display:inline-block;">
        Consult Attending Specialist &rarr;
      </a>
    </div>
  </main>
</body>
</html>

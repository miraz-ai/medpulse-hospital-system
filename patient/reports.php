<?php
/**
 * MedPulse Enterprise HMS — Diagnostic Reports
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
  <title>Diagnostic Reports &middot; MedPulse Hospital System</title>
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
        <h1>Diagnostic Reports &amp; Lab Results</h1>
        <p>Pathology, radiology, and diagnostic imaging securely synchronized across MedPulse hospitals.</p>
      </div>
    </div>

    <div class="patient-card-stack" style="background:#fff; border:1px solid var(--surface-border); border-radius:16px; padding:2.5rem; text-align:center; max-width:800px; margin:2rem auto;">
      <div style="width:64px; height:64px; border-radius:50%; background:rgba(13,148,136,0.1); color:#0d9488; display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
        <svg style="width:32px; height:32px; stroke:currentColor;" fill="none" viewBox="0 0 24 24"><path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"></path><path d="M8.5 2h7"></path><path d="M14.5 16h-5"></path></svg>
      </div>
      <h3 style="font-size:1.25rem; font-weight:800; color:var(--text-heading); margin-bottom:0.5rem;">Diagnostic Data Records</h3>
      <p style="color:var(--text-muted); font-size:0.9rem; max-width:480px; margin:0 auto 1.5rem;">
        Completed lab tests and imaging investigations ordered by your attending doctors will automatically appear here once released by the laboratory department.
      </p>
      <a href="dashboard.php" class="btn-action-gradient" style="text-decoration:none; padding:0.65rem 1.4rem; font-size:0.85rem; font-weight:700; border-radius:8px; display:inline-block;">
        Return to Overview &rarr;
      </a>
    </div>
  </main>
</body>
</html>

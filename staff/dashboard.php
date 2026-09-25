<?php
/**
 * MedPulse Staff Portal Scaffold
 */
require_once __DIR__ . '/../includes/session_guard.php';

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'staff') {
    medpulseDestroySession('../login.php');
}

$staffName = htmlspecialchars($_SESSION['full_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Portal | MedPulse Hospital System</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
  <div class="admin-layout" style="padding: 2.5rem; text-align: center; max-width: 800px; margin: 4rem auto; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
    <div style="font-size: 3rem; color: #0284c7; margin-bottom: 1rem;"><i class="fa-solid fa-hospital-user"></i></div>
    <h1 style="font-size: 1.8rem; margin-bottom: 0.5rem; color: #1e293b;">Staff Operations Workspace</h1>
    <p style="color: #64748b; margin-bottom: 1.5rem;">Welcome, <?= $staffName ?>. Staff operations module is actively scheduled for deployment.</p>
    <a href="../logout.php" class="btn btn-primary" style="display: inline-block; padding: 0.75rem 1.5rem; background: #0284c7; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 500;">
      <i class="fa-solid fa-right-from-bracket"></i> Sign Out
    </a>
  </div>
  <script>
    // Client-Side History Guard: Kill BFCache and re-verify session on back-navigation
    window.addEventListener("pageshow", function(event) {
      if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
        window.location.reload();
      }
    });
  </script>
</body>
</html>

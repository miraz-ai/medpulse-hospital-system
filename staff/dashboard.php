<?php
/**
 * MedPulse Staff Portal Scaffold
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: ../login.php");
    exit();
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
</body>
</html>

<?php
/**
 * MedPulse Doctor Portal — Home Dashboard
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Doctor') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) { $p = session_get_cookie_params(); setcookie(session_name(),'',time()-3600,$p["path"],$p["domain"],$p["secure"],$p["httponly"]); }
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$doctorName = htmlspecialchars($_SESSION['full_name'] ?? 'Doctor', ENT_QUOTES, 'UTF-8');
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Doctor Portal | MedPulse Hospital System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;font-family:'Plus Jakarta Sans',-apple-system,sans-serif;}
    body{min-height:100vh;background:linear-gradient(135deg,#f0f9ff 0%,#ecfdf5 100%);display:flex;align-items:center;justify-content:center;padding:24px;}
    .portal-card{background:#fff;border-radius:24px;box-shadow:0 16px 48px rgba(2,132,199,.12);max-width:700px;width:100%;overflow:hidden;}
    .portal-header{background:linear-gradient(135deg,#0284c7,#0d9488);padding:32px 36px;color:#fff;position:relative;overflow:hidden;}
    .portal-header::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);}
    .portal-header .greeting{font-size:.7rem;font-weight:700;opacity:.75;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;}
    .portal-header h1{font-size:1.6rem;font-weight:800;margin-bottom:6px;position:relative;z-index:1;}
    .portal-header p{font-size:.82rem;opacity:.8;position:relative;z-index:1;}
    .portal-body{padding:28px 36px 32px;}
    .nav-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:24px;}
    .nav-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;text-decoration:none;color:#0f172a;display:flex;align-items:center;gap:14px;transition:all .22s cubic-bezier(.34,1.56,.64,1);}
    .nav-card:hover{background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.09);transform:translateY(-3px);border-color:#bae6fd;}
    .nav-card.earnings:hover{border-color:#a7f3d0;}
    .nav-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .nav-icon svg{fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;width:22px;height:22px;}
    .nav-icon-blue{background:#e0f2fe;} .nav-icon-blue svg{stroke:#0284c7;}
    .nav-icon-green{background:#ecfdf5;} .nav-icon-green svg{stroke:#059669;}
    .nav-label{font-size:.84rem;font-weight:700;color:#0f172a;margin-bottom:2px;}
    .nav-desc{font-size:.72rem;color:#94a3b8;}
    .signout{display:inline-flex;align-items:center;gap:8px;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 20px;font-size:.82rem;font-weight:700;text-decoration:none;transition:background .18s;}
    .signout:hover{background:#fee2e2;}
    .signout svg{stroke:#dc2626;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;width:16px;height:16px;}
    @media(max-width:520px){.nav-grid{grid-template-columns:1fr}.portal-header,.portal-body{padding:22px}}
  </style>
</head>
<body>
  <div class="portal-card">
    <div class="portal-header">
      <div class="greeting"><?php echo $greeting; ?>, Doctor</div>
      <h1>Dr. <?php echo $doctorName; ?></h1>
      <p>Welcome to your MedPulse Clinical Workspace. Your modules are ready.</p>
    </div>
    <div class="portal-body">
      <div class="nav-grid">
        <a href="my_earnings.php" class="nav-card earnings">
          <div class="nav-icon nav-icon-green">
            <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          </div>
          <div>
            <div class="nav-label">Earnings Ledger</div>
            <div class="nav-desc">Consultation fees &amp; disbursements</div>
          </div>
        </a>
        <a href="#" class="nav-card">
          <div class="nav-icon nav-icon-blue">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          </div>
          <div>
            <div class="nav-label">Clinical Overview</div>
            <div class="nav-desc">Coming soon — patient rounds &amp; schedules</div>
          </div>
        </a>
      </div>
      <a href="../logout.php" class="signout">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </div>
</body>
</html>

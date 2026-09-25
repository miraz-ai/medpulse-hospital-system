<?php
/**
 * MedPulse Super Admin — Hospital Directory
 */
require_once __DIR__ . '/../includes/super_admin_auth.php';

try {
    $hospitals = $pdo->query("
        SELECT h.*,
               COUNT(hb.bed_id) AS total_beds,
               SUM(hb.status='Available') AS available_beds,
               COUNT(dp.doctor_id) AS total_doctors
        FROM hospitals h
        LEFT JOIN hospital_beds hb ON hb.hospital_id = h.hospital_id
        LEFT JOIN doctor_profiles dp ON dp.hospital_id = h.hospital_id
        GROUP BY h.hospital_id
        ORDER BY h.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $hospitals = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Hospital Directory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    :root { --sa-accent:#7c3aed; --sa-accent-soft:rgba(124,58,237,.10); --sa-gradient:linear-gradient(135deg,#7c3aed 0%,#0d9488 100%); }
    .hosp-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:20px; padding:20px 0; }
    .hosp-card { background:var(--surface); border:1px solid var(--surface-border); border-radius:var(--radius-xl); padding:24px; transition:box-shadow .2s, border-color .2s; }
    .hosp-card:hover { box-shadow:0 8px 30px rgba(124,58,237,.12); border-color:rgba(124,58,237,.3); }
    .hosp-card-logo { width:48px;height:48px;border-radius:14px;background:var(--sa-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;font-weight:800;margin-bottom:14px; }
    .hosp-card-name { font-size:1rem;font-weight:800;color:var(--text-heading);margin-bottom:4px; }
    .hosp-card-meta { font-size:0.78rem;color:var(--text-muted);margin-bottom:14px; }
    .hosp-stat-row { display:flex;gap:14px; }
    .hosp-stat { flex:1;background:var(--surface-border-subtle);border-radius:10px;padding:10px;text-align:center; }
    .hosp-stat-val { font-size:1.1rem;font-weight:800;color:var(--text-heading); }
    .hosp-stat-label { font-size:0.65rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em; }
    .sa-welcome-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--sa-gradient);color:#fff;border-radius:20px;font-size:0.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;}
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
  <main class="viewport-full">
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">Super Administrator</div>
        <h1>Hospital Directory</h1>
        <p>Complete registry of all MedPulse network facilities with live operational statistics.</p>
      </div>
    </div>

    <div class="hosp-cards">
      <?php foreach ($hospitals as $h):
        $tot   = (int)($h['total_beds'] ?? 0);
        $avail = (int)($h['available_beds'] ?? 0);
        $docs  = (int)($h['total_doctors'] ?? 0);
        $init  = strtoupper(substr($h['name'], 0, 2));
      ?>
      <div class="hosp-card">
        <div class="hosp-card-logo"><?= htmlspecialchars($init, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="hosp-card-name"><?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="hosp-card-meta">
          <?= htmlspecialchars($h['city'], ENT_QUOTES, 'UTF-8') ?>
          <?php if (!empty($h['address'])): ?>· <?= htmlspecialchars($h['address'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          <?php if (!empty($h['contact_number'])): ?><br><?= htmlspecialchars($h['contact_number'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        </div>
        <div class="hosp-stat-row">
          <div class="hosp-stat">
            <div class="hosp-stat-val"><?= $tot ?></div>
            <div class="hosp-stat-label">Total Beds</div>
          </div>
          <div class="hosp-stat">
            <div class="hosp-stat-val" style="color:var(--status-green)"><?= $avail ?></div>
            <div class="hosp-stat-label">Available</div>
          </div>
          <div class="hosp-stat">
            <div class="hosp-stat-val" style="color:var(--brand-primary)"><?= $docs ?></div>
            <div class="hosp-stat-label">Doctors</div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($hospitals)): ?>
        <p style="color:var(--text-muted); padding:20px;">No hospitals found in the database.</p>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>

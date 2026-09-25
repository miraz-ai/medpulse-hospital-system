<?php
/**
 * MedPulse Super Admin — Central Bed Monitor
 */
require_once __DIR__ . '/../includes/super_admin_auth.php';

$filterHospId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$filterWard   = trim($_GET['ward'] ?? '');

try {
    $allHospitals = $pdo->query("SELECT hospital_id, name FROM hospitals ORDER BY name")->fetchAll();

    $wardTypes = $pdo->query("SELECT DISTINCT ward_type FROM hospital_beds ORDER BY ward_type")->fetchAll(PDO::FETCH_COLUMN);

    $params = [];
    $where  = "WHERE 1=1";
    if ($filterHospId > 0) { $where .= " AND hb.hospital_id = ?"; $params[] = $filterHospId; }
    if ($filterWard !== '') { $where .= " AND hb.ward_type = ?"; $params[] = $filterWard; }

    $beds = $pdo->prepare("
        SELECT hb.bed_id, hb.bed_number, hb.ward_type, hb.status,
               hb.price_per_day, h.name AS hospital_name, h.city
        FROM hospital_beds hb
        LEFT JOIN hospitals h ON h.hospital_id = hb.hospital_id
        {$where}
        ORDER BY h.name, hb.ward_type, hb.bed_number
    ");
    $beds->execute($params);
    $beds = $beds->fetchAll();

} catch (PDOException $e) { error_log($e->getMessage()); $beds = []; $allHospitals = []; $wardTypes = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Central Bed Monitor</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <style>
    :root{--sa-accent:#7c3aed;--sa-accent-soft:rgba(124,58,237,.10);--sa-gradient:linear-gradient(135deg,#7c3aed 0%,#0d9488 100%);}
    .filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:16px 20px;background:var(--surface);border:1px solid var(--surface-border);border-radius:var(--radius-lg);margin-bottom:22px;}
    .f-select{appearance:none;background:var(--surface);border:1.5px solid var(--surface-border);border-radius:10px;padding:8px 34px 8px 12px;font-size:0.82rem;font-weight:600;color:var(--text-heading);font-family:inherit;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;background-size:14px;transition:.2s;}
    .f-select:focus{outline:none;border-color:var(--sa-accent);}
    .f-btn{padding:8px 18px;background:var(--sa-gradient);color:#fff;border:none;border-radius:10px;font-size:0.82rem;font-weight:700;font-family:inherit;cursor:pointer;}
    .bed-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}
    .bed-tile{padding:14px;border-radius:var(--radius-md);border:1.5px solid var(--surface-border);background:var(--surface);transition:.2s;}
    .bed-tile:hover{box-shadow:0 4px 14px rgba(0,0,0,.07);}
    .bed-tile.avail{border-color:rgba(5,150,105,.3);background:#f0fdf4;}
    .bed-tile.occupied{border-color:rgba(239,68,68,.3);background:#fef2f2;}
    .bed-tile.maintenance{border-color:rgba(217,119,6,.3);background:#fef3c7;}
    .bed-tile-num{font-size:1rem;font-weight:800;color:var(--text-heading);}
    .bed-tile-ward{font-size:0.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin:3px 0;}
    .bed-tile-hosp{font-size:0.72rem;color:var(--text-muted);}
    .bed-status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px;}
    .dot-avail{background:var(--status-green);}
    .dot-occupied{background:var(--status-red);}
    .dot-maint{background:var(--status-amber);}
    .bed-price{font-size:0.72rem;color:var(--brand-teal);font-weight:600;margin-top:4px;}
    .sa-welcome-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--sa-gradient);color:#fff;border-radius:20px;font-size:0.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;}
    .section-label{font-size:0.78rem;font-weight:700;color:var(--text-muted);letter-spacing:.06em;text-transform:uppercase;padding:12px 0 8px;border-bottom:1px solid var(--surface-border-subtle);margin-bottom:14px;}
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
  <main class="viewport-full">
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">Super Administrator</div>
        <h1>Central Bed Monitor</h1>
        <p>Real-time view of every bed across all network hospitals — filterable by facility and ward type.</p>
      </div>
      <div class="banner-actions">
        <span style="font-size:0.82rem;color:var(--text-muted);"><?= count($beds) ?> beds shown</span>
      </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="bed_monitor.php" class="filter-bar">
      <label style="font-size:0.82rem;font-weight:600;color:var(--text-muted);">Hospital:</label>
      <select class="f-select" name="hospital_id" onchange="this.form.submit()">
        <option value="0">All Hospitals</option>
        <?php foreach ($allHospitals as $h): ?>
        <option value="<?= (int)$h['hospital_id'] ?>" <?= $filterHospId === (int)$h['hospital_id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
        <?php endforeach; ?>
      </select>

      <label style="font-size:0.82rem;font-weight:600;color:var(--text-muted);">Ward:</label>
      <select class="f-select" name="ward" onchange="this.form.submit()">
        <option value="">All Wards</option>
        <?php foreach ($wardTypes as $w): ?>
        <option value="<?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>" <?= $filterWard === $w ? 'selected' : '' ?>>
          <?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>
        </option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="f-btn">Apply</button>
      <a href="bed_monitor.php" style="font-size:0.82rem;font-weight:600;color:var(--text-muted);text-decoration:none;padding:8px;">Reset</a>
    </form>

    <!-- Legend -->
    <div style="display:flex;gap:16px;margin-bottom:18px;flex-wrap:wrap;">
      <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:var(--text-body);"><span class="bed-status-dot dot-avail"></span>Available</span>
      <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:var(--text-body);"><span class="bed-status-dot dot-occupied"></span>Occupied</span>
      <span style="display:flex;align-items:center;gap:6px;font-size:0.78rem;font-weight:600;color:var(--text-body);"><span class="bed-status-dot dot-maint"></span>Maintenance</span>
    </div>

    <!-- Bed Grid -->
    <?php if (empty($beds)): ?>
      <div style="text-align:center;padding:60px;color:var(--text-muted);">No beds found matching the selected filters.</div>
    <?php else: ?>
    <div class="bed-grid">
      <?php foreach ($beds as $bed):
        $statusKey = strtolower($bed['status']);
        $tileClass = match($statusKey) { 'available' => 'avail', 'occupied' => 'occupied', default => 'maintenance' };
        $dotClass  = match($statusKey) { 'available' => 'dot-avail', 'occupied' => 'dot-occupied', default => 'dot-maint' };
      ?>
      <div class="bed-tile <?= $tileClass ?>">
        <div class="bed-tile-num"><?= htmlspecialchars($bed['bed_number'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="bed-tile-ward"><?= htmlspecialchars($bed['ward_type'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="bed-tile-hosp"><?= htmlspecialchars($bed['hospital_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div style="margin-top:6px;">
          <span class="bed-status-dot <?= $dotClass ?>"></span>
          <span style="font-size:0.75rem;font-weight:700;color:var(--text-heading);"><?= htmlspecialchars($bed['status'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if (!empty($bed['price_per_day'])): ?>
        <div class="bed-price">৳<?= number_format((float)$bed['price_per_day'], 0) ?>/day</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </main>
</body>
</html>

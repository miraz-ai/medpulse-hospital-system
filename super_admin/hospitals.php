<?php
/**
 * MedPulse Super Admin — Hospital Directory with Operational Status Toggle
 */
require_once __DIR__ . '/../includes/super_admin_auth.php';

// ── AJAX: Toggle hospital operational status ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json');

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF mismatch.']);
        exit;
    }

    if ($_POST['_action'] === 'toggle_status') {
        $hid    = (int)($_POST['hospital_id'] ?? 0);
        $newSt  = $_POST['new_status'] ?? '';
        if (!in_array($newSt, ['Active', 'Suspended', 'Maintenance'], true) || $hid < 1) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            exit;
        }
        // Protect hospital 1 from suspension
        if ($hid === 1 && $newSt === 'Suspended') {
            echo json_encode(['success' => false, 'message' => 'Primary hospital cannot be suspended.']);
            exit;
        }
        try {
            $pdo->prepare("UPDATE hospitals SET operational_status = ? WHERE hospital_id = ?")->execute([$newSt, $hid]);
            $pdo->prepare("
                INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES (?, 'super_admin', 'HOSPITAL_STATUS', ?, 'ADMIN', 'Hospital Status Toggle', ?, ?, 'HIGH')
            ")->execute([
                (int)$_SESSION['user_id'],
                "Hospital #{$hid} operational status set to: {$newSt}",
                "hospital_id:{$hid}",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
            echo json_encode(['success' => true, 'message' => "Hospital #$hid status → $newSt", 'new_status' => $newSt]);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'message' => 'DB error.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── GET: Fetch hospitals with bed stats ───────────────────────────────────────
try {
    $hospitals = $pdo->query("
        SELECT h.*,
               COUNT(b.bed_id)                    AS total_beds,
               SUM(b.status = 'Available')         AS avail_beds,
               SUM(b.status = 'Occupied')          AS occ_beds,
               SUM(b.status = 'Maintenance')       AS maint_beds,
               SUM(b.ward_type LIKE '%ICU%' OR b.ward_type LIKE '%HDU%' OR b.ward_type = 'CCU')   AS icu_beds,
               SUM((b.ward_type LIKE '%ICU%' OR b.ward_type LIKE '%HDU%' OR b.ward_type = 'CCU') AND b.status = 'Occupied') AS icu_occ,
               MAX(b.floor_number)                 AS max_floor,
               COUNT(DISTINCT dp.doctor_id)        AS doctor_count,
               u.full_name AS admin_name, u.email AS admin_email
        FROM hospitals h
        LEFT JOIN hospital_beds b ON b.hospital_id = h.hospital_id
        LEFT JOIN doctor_profiles dp ON dp.hospital_id = h.hospital_id
        LEFT JOIN users u ON u.hospital_id = h.hospital_id AND u.role = 'Admin'
        GROUP BY h.hospital_id
        ORDER BY h.hospital_id
    ")->fetchAll();
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
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Hospital Directory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">
  <style>
    :root{--sa-accent:#7c3aed;--sa-grad:linear-gradient(135deg,#7c3aed 0%,#0d9488 100%);}
    .sa-welcome-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--sa-grad);color:#fff;border-radius:20px;font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;}

    .hosp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:18px;margin-top:22px;}
    .hosp-card{background:var(--surface);border:1.5px solid var(--surface-border);border-radius:var(--radius-xl);padding:22px 22px 18px;position:relative;transition:box-shadow .2s;}
    .hosp-card:hover{box-shadow:0 8px 28px rgba(0,0,0,.09);}
    .hosp-card.status-suspended{border-color:rgba(239,68,68,.4);background:#fef2f2;}
    .hosp-card.status-maintenance{border-color:rgba(217,119,6,.4);background:#fef3c7;}

    .hc-header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;}
    .hc-icon{width:44px;height:44px;border-radius:12px;background:var(--sa-grad);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .hc-icon svg{width:22px;height:22px;stroke:#fff;fill:none;stroke-width:2;}
    .hc-name{font-size:.92rem;font-weight:800;color:var(--text-heading);line-height:1.3;}
    .hc-code{font-size:.7rem;font-weight:700;color:var(--sa-accent);text-transform:uppercase;letter-spacing:.07em;margin-top:2px;}
    .hc-city{font-size:.75rem;color:var(--text-muted);margin-top:2px;}

    .status-pill{padding:4px 10px;border-radius:20px;font-size:.7rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;}
    .sp-active{background:rgba(5,150,105,.12);color:var(--status-green);}
    .sp-suspended{background:rgba(239,68,68,.12);color:var(--status-red);}
    .sp-maintenance{background:rgba(217,119,6,.12);color:var(--status-amber);}

    .hc-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:14px 0;}
    .hc-metric{text-align:center;padding:8px;background:var(--surface-secondary);border-radius:10px;}
    .hc-metric-val{font-size:1.05rem;font-weight:800;color:var(--text-heading);}
    .hc-metric-key{font-size:.67rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;}

    .hc-bar-track{height:6px;background:var(--surface-border);border-radius:99px;overflow:hidden;margin:6px 0 12px;}
    .hc-bar-fill{height:100%;border-radius:99px;background:var(--sa-grad);}

    .hc-admin{display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--surface-secondary);border-radius:10px;margin-top:10px;}
    .hc-admin-icon{width:28px;height:28px;border-radius:8px;background:var(--surface-border);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;color:var(--sa-accent);}
    .hc-admin-name{font-size:.78rem;font-weight:700;color:var(--text-heading);}
    .hc-admin-email{font-size:.68rem;color:var(--text-muted);}

    .hc-footer{display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:12px;border-top:1px solid var(--surface-border);}
    .hc-floors{font-size:.72rem;font-weight:700;color:var(--text-muted);}
    .hc-ctrl-btns{display:flex;gap:6px;}
    .hc-btn{padding:5px 12px;border-radius:8px;font-size:.72rem;font-weight:700;font-family:inherit;cursor:pointer;border:1.5px solid var(--surface-border);background:var(--surface);color:var(--text-heading);transition:.15s;}
    .hc-btn:hover{border-color:var(--sa-accent);color:var(--sa-accent);}
    .hc-btn.danger{border-color:rgba(239,68,68,.4);color:var(--status-red);}
    .hc-btn.danger:hover{background:var(--status-red);color:#fff;}
    .hc-btn.restore{border-color:rgba(5,150,105,.4);color:var(--status-green);}
    .hc-btn.restore:hover{background:var(--status-green);color:#fff;}

    .sa-toast{position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:12px;font-size:.82rem;font-weight:600;display:none;align-items:center;gap:8px;z-index:2000;}
    .sa-toast.show{display:flex;}
    .sa-toast-dot{width:8px;height:8px;border-radius:50%;background:#10b981;}
    .sa-toast.t-error .sa-toast-dot{background:#ef4444;}
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <main class="viewport-full">
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">Super Administrator</div>
        <h1>Hospital Directory</h1>
        <p>Network-wide facility management. Toggle operational status, inspect branch admins, and monitor bed coverage per facility.</p>
      </div>
    </div>

    <div class="hosp-grid">
      <?php foreach ($hospitals as $h):
        $total   = (int)($h['total_beds'] ?? 0);
        $occ     = (int)($h['occ_beds']   ?? 0);
        $avail   = (int)($h['avail_beds'] ?? 0);
        $icu     = (int)($h['icu_beds']   ?? 0);
        $icuOcc  = (int)($h['icu_occ']   ?? 0);
        $maxF    = (int)($h['max_floor']  ?? 5);
        $occPct  = $total > 0 ? round(($occ/$total)*100) : 0;
        $opSt    = $h['operational_status'] ?? 'Active';
        $hid     = (int)$h['hospital_id'];
        $cardCls = match($opSt) { 'Suspended' => 'hosp-card status-suspended', 'Maintenance' => 'hosp-card status-maintenance', default => 'hosp-card' };
        $pillCls = match($opSt) { 'Suspended' => 'status-pill sp-suspended', 'Maintenance' => 'status-pill sp-maintenance', default => 'status-pill sp-active' };
      ?>
      <div class="<?= $cardCls ?>" id="hcard-<?= $hid ?>">
        <div class="hc-header">
          <div style="display:flex;gap:12px;align-items:flex-start;">
            <div class="hc-icon">
              <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div>
              <div class="hc-name"><?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="hc-code"><?= htmlspecialchars($h['code'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="hc-city"><?= htmlspecialchars(($h['city'] ?? '—') . ', ' . ($h['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          </div>
          <span class="<?= $pillCls ?>" id="pill-<?= $hid ?>"><?= htmlspecialchars($opSt, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="hc-metrics">
          <div class="hc-metric">
            <div class="hc-metric-val"><?= number_format($total) ?></div>
            <div class="hc-metric-key">Total Beds</div>
          </div>
          <div class="hc-metric">
            <div class="hc-metric-val" style="color:var(--status-green);"><?= number_format($avail) ?></div>
            <div class="hc-metric-key">Available</div>
          </div>
          <div class="hc-metric">
            <div class="hc-metric-val" style="color:var(--status-red);"><?= $icu > 0 ? round(($icuOcc/$icu)*100) . '%' : '—' ?></div>
            <div class="hc-metric-key">ICU Occ.</div>
          </div>
        </div>

        <div style="display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-muted);font-weight:600;margin-bottom:3px;">
          <span>Occupancy</span><span><?= $occPct ?>%</span>
        </div>
        <div class="hc-bar-track">
          <div class="hc-bar-fill" style="width:<?= $occPct ?>%;background:<?= $occPct >= 90 ? 'var(--status-red)' : ($occPct >= 70 ? 'var(--status-amber)' : 'var(--sa-grad)') ?>;"></div>
        </div>

        <div class="hc-admin">
          <div class="hc-admin-icon"><?= strtoupper(substr($h['admin_name'] ?? 'A', 0, 1)) ?></div>
          <div>
            <div class="hc-admin-name"><?= htmlspecialchars($h['admin_name'] ?? 'No Admin Assigned', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="hc-admin-email"><?= htmlspecialchars($h['admin_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>

        <div class="hc-footer">
          <span class="hc-floors">Floors: 1 – <?= $maxF ?> &bull; <?= (int)($h['doctor_count'] ?? 0) ?> Doctors</span>
          <div class="hc-ctrl-btns">
            <?php if ($opSt !== 'Active'): ?>
              <button class="hc-btn restore" onclick="toggleHospStatus(<?= $hid ?>, 'Active')">Restore Active</button>
            <?php endif; ?>
            <?php if ($opSt !== 'Maintenance'): ?>
              <button class="hc-btn" onclick="toggleHospStatus(<?= $hid ?>, 'Maintenance')">Maintenance</button>
            <?php endif; ?>
            <?php if ($opSt !== 'Suspended' && $hid !== 1): ?>
              <button class="hc-btn danger" onclick="toggleHospStatus(<?= $hid ?>, 'Suspended')">Suspend</button>
            <?php endif; ?>
            <?php if ($hid === 1): ?>
              <span style="font-size:.69rem;color:var(--text-muted);padding:4px;">Primary — Protected</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </main>

  <div class="sa-toast" id="saToast"><span class="sa-toast-dot"></span><span id="saToastMsg"></span></div>

  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    function saToast(msg, type = 'success') {
      const t = document.getElementById('saToast');
      t.className = 'sa-toast show t-' + type;
      document.getElementById('saToastMsg').textContent = msg;
      clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('show'), 4500);
    }

    async function toggleHospStatus(hid, newStatus) {
      const card = document.getElementById('hcard-' + hid);
      const hospName = card ? card.querySelector('.hc-name').textContent.trim() : `Hospital #${hid}`;

      const confirmed = await MedPulseDialog.confirm({
        title: 'Change Operational Status',
        subtitle: `${hospName} (ID #${hid})`,
        type: newStatus === 'Suspended' ? 'danger' : (newStatus === 'Maintenance' ? 'warning' : 'primary'),
        confirmText: `Set to ${newStatus}`,
        cancelText: 'Cancel',
        message: `Are you sure you want to transition ${hospName} to "${newStatus}" status?`
      });

      if (!confirmed) return;

      const fd = new FormData();
      fd.append('_action',    'toggle_status');
      fd.append('hospital_id', hid);
      fd.append('new_status',  newStatus);
      fd.append('csrf_token',  CSRF);
      try {
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
          MedPulseDialog.toast({
            title: 'Hospital Status Updated',
            message: d.message,
            type: 'success',
            duration: 3500
          });
          // Update pill and card class without reload
          const pill = document.getElementById('pill-'  + hid);
          if (card) {
            card.className = 'hosp-card' + (newStatus === 'Suspended' ? ' status-suspended' : newStatus === 'Maintenance' ? ' status-maintenance' : '');
          }
          if (pill) {
            pill.className = 'status-pill ' + (newStatus === 'Active' ? 'sp-active' : newStatus === 'Suspended' ? 'sp-suspended' : 'sp-maintenance');
            pill.textContent = newStatus;
          }
          // Reload after 1.4s to refresh buttons
          setTimeout(() => location.reload(), 1400);
        } else {
          MedPulseDialog.toast({
            title: 'Status Update Failed',
            message: d.message || 'Error occurred.',
            type: 'error',
            duration: 4000
          });
        }
      } catch(e) {
        MedPulseDialog.toast({
          title: 'Network Error',
          message: 'Network error updating hospital status.',
          type: 'error'
        });
      }
    }
  </script>
  <!-- Dedicated MedPulse Modern Dialog & Toast Engine -->
  <script src="../assets/js/medpulse_dialog.js"></script>
</body>
</html>

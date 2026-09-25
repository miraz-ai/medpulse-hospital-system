<?php
/**
 * MedPulse Super Admin — Central Network Bed Monitor
 * Paginated across 3,450 beds across 6 Operational Facilities
 * Integrated National Emergency Triage Engine, Dynamic Surge Scaling, and Stand-Down Restoration
 */
require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../backend/Services/EmergencyProtocolService.php';

use MedPulse\Services\EmergencyProtocolService;

$emergencyService    = new EmergencyProtocolService($pdo);
$activeProtocols     = $emergencyService->getActiveProtocols();
$activeCount         = count($activeProtocols);
$activeEmergency     = !empty($activeProtocols) ? $activeProtocols[0] : null;

$totalSurgeHeldBeds  = 0;
$totalSurgeRelocBeds = 0;
foreach ($activeProtocols as $p) {
    $totalSurgeHeldBeds  += (int)($p['live_held_count'] ?? 0);
    $totalSurgeRelocBeds += (int)($p['live_relocating_count'] ?? 0);
}

// ── AJAX: Force-override bed status ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json');

    $tok = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $tok)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token mismatch.']);
        exit;
    }

    $action = $_POST['_action'];
    $bedId  = (int)($_POST['bed_id'] ?? 0);

    if ($action === 'override_status' && $bedId > 0) {
        $newStatus = $_POST['new_status'] ?? '';
        $allowed   = ['Maintenance', 'Emergency Hold', 'Available', 'Reserved', 'Sanitizing'];
        if (!in_array($newStatus, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status.']);
            exit;
        }
        try {
            $upd = $pdo->prepare("UPDATE hospital_beds SET status = ? WHERE bed_id = ?");
            $upd->execute([$newStatus, $bedId]);

            // Audit log
            $pdo->prepare("
                INSERT INTO audit_logs (actor_id, actor_role, action, description, category, action_name, target_entity, ip_address, security_level)
                VALUES (?, 'super_admin', 'BED_OVERRIDE', ?, 'ADMIN', 'Force Status Override', ?, ?, 'HIGH')
            ")->execute([
                (int)$_SESSION['user_id'],
                "Super Admin forced bed #{$bedId} to status: {$newStatus}",
                "bed_id:{$bedId}",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            echo json_encode(['success' => true, 'message' => "Bed #{$bedId} status forced to {$newStatus}.", 'new_status' => $newStatus]);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error.']);
        }
        exit;
    }

    if ($action === 'get_bed_detail' && $bedId > 0) {
        try {
            $bed = $pdo->prepare("
                SELECT b.bed_id, b.bed_number, b.ward_type, b.floor_number, b.status,
                       b.relocation_status, b.emergency_protocol_id,
                       b.price_per_day, b.reserved_until,
                       h.name AS hospital_name, h.city, h.code AS hospital_code,
                       ba.admitted_at, ba.allocation_id,
                       p.user_id AS patient_id, p.full_name AS patient_name, p.email AS patient_email, p.phone AS patient_phone,
                       d.full_name AS doctor_name, d.email AS doctor_email
                FROM hospital_beds b
                LEFT JOIN hospitals h ON h.hospital_id = b.hospital_id
                LEFT JOIN bed_allocations ba ON ba.bed_id = b.bed_id AND ba.status = 'Active'
                LEFT JOIN users p ON ba.patient_id = p.user_id
                LEFT JOIN users d ON ba.attending_doctor_id = d.user_id
                WHERE b.bed_id = ?
                LIMIT 1
            ");
            $bed->execute([$bedId]);
            $row = $bed->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Bed not found.']); exit; }

            // Fetch audit data for this specific bed
            $auditStmt = $pdo->prepare("
                SELECT action, description, created_at, actor_role, ip_address
                FROM audit_logs
                WHERE target_entity = :e1 OR target_entity = :e2 OR description LIKE :desc
                ORDER BY log_id DESC
                LIMIT 5
            ");
            $auditStmt->execute([
                ':e1'   => "bed_id:{$bedId}",
                ':e2'   => $row['bed_number'],
                ':desc' => "%{$row['bed_number']}%"
            ]);
            $audits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'bed' => $row, 'audits' => $audits]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── GET: Page render ──────────────────────────────────────────────────────────
$filterHospId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;
$filterWard   = trim($_GET['ward']   ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterFloor  = isset($_GET['floor']) && is_numeric($_GET['floor']) ? (int)$_GET['floor'] : 0;
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 60; // 60 bed tiles per page for smooth rendering

try {
    $allHospitals = $pdo->query("SELECT hospital_id, name, code, city FROM hospitals ORDER BY hospital_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $wardTypes    = $pdo->query("SELECT DISTINCT ward_type FROM hospital_beds ORDER BY ward_type")->fetchAll(PDO::FETCH_COLUMN);
    $statusTypes  = ['Available', 'Occupied', 'Maintenance', 'Emergency Hold', 'Sanitizing', 'Reserved'];

    // Dynamic floors for selected hospital
    $floorQuery = $filterHospId > 0
        ? "SELECT DISTINCT floor_number FROM hospital_beds WHERE hospital_id = $filterHospId ORDER BY floor_number"
        : "SELECT DISTINCT floor_number FROM hospital_beds ORDER BY floor_number";
    $allFloors = $pdo->query($floorQuery)->fetchAll(PDO::FETCH_COLUMN);

    // Build WHERE
    $where = ['1=1'];
    $params = [];
    if ($filterHospId > 0) { $where[] = 'hb.hospital_id = ?'; $params[] = $filterHospId; }
    if ($filterWard  !== '') { $where[] = 'hb.ward_type = ?'; $params[] = $filterWard; }
    if ($filterStatus !== '') { 
        if ($filterStatus === 'Relocation Needed') {
            $where[] = "hb.relocation_status = 'PENDING_RELOCATION'";
        } else {
            $where[] = 'hb.status = ?'; 
            $params[] = $filterStatus; 
        }
    }
    if ($filterFloor > 0)  { $where[] = 'hb.floor_number = ?'; $params[] = $filterFloor; }
    if ($search !== '') { $where[] = 'hb.bed_number LIKE ?'; $params[] = "%$search%"; }
    $whereStr = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM hospital_beds hb WHERE $whereStr");
    $countStmt->execute($params);
    $total      = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $bedStmt = $pdo->prepare("
        SELECT hb.bed_id, hb.bed_number, hb.ward_type, hb.floor_number, hb.status, hb.price_per_day,
               hb.relocation_status, hb.emergency_protocol_id,
               h.name AS hospital_name,
               ep.code AS ep_code, ep.title AS ep_title, ep.severity_level AS ep_severity
        FROM hospital_beds hb
        LEFT JOIN hospitals h ON h.hospital_id = hb.hospital_id
        LEFT JOIN emergency_protocols ep ON ep.id = hb.emergency_protocol_id
        WHERE $whereStr
        ORDER BY h.hospital_id ASC, hb.floor_number ASC, hb.bed_id ASC
        LIMIT $perPage OFFSET $offset
    ");
    $bedStmt->execute($params);
    $beds = $bedStmt->fetchAll();

    // Network summary for top chips
    $netStats = $pdo->query("
        SELECT COUNT(*) total,
               SUM(status='Available') avail,
               SUM(status='Occupied') occ,
               SUM(status='Maintenance') maint,
               SUM(status='Emergency Hold') hold,
               SUM(status='Sanitizing') sanitizing,
               SUM(relocation_status='PENDING_RELOCATION') pending_reloc
        FROM hospital_beds
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $beds = []; $allHospitals = []; $wardTypes = []; $allFloors = [];
    $total = 0; $totalPages = 1; $netStats = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Central Bed Monitor &amp; Emergency Command</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css">
  <style>
    :root{
      --sa-accent:#7c3aed;
      --sa-soft:rgba(124,58,237,.10);
      --sa-grad:linear-gradient(135deg,#7c3aed 0%,#0d9488 100%);
      --crimson-alert:#e11d48;
      --crimson-soft:rgba(225,29,72,.12);
    }
    .sa-welcome-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--sa-grad);color:#fff;border-radius:20px;font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;}

    /* Top banner header flex */
    .banner-header-flex{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;}
    .banner-actions{display:flex;gap:10px;align-items:center;}

    .btn-declare-emergency{
      display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:linear-gradient(135deg,#e11d48 0%,#b91c1c 100%);
      color:#fff;border:none;border-radius:12px;font-size:0.86rem;font-weight:800;cursor:pointer;
      box-shadow:0 4px 18px rgba(225,29,72,.35);transition:all .2s;text-transform:uppercase;letter-spacing:.04em;
      animation:pulseAlert 2.4s infinite;
    }
    .btn-declare-emergency:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(225,29,72,.48);filter:brightness(1.08);}

    .btn-active-surge-pill{
      display:inline-flex;align-items:center;gap:8px;padding:9px 18px;background:#fef2f2;border:1.5px solid #ef4444;
      color:#991b1b;border-radius:12px;font-size:0.82rem;font-weight:800;cursor:pointer;transition:.2s;
    }
    .btn-active-surge-pill:hover{background:#fee2e2;}

    @keyframes pulseAlert{
      0%,100%{box-shadow:0 4px 18px rgba(225,29,72,.35);}
      50%{box-shadow:0 4px 28px rgba(225,29,72,.75);}
    }

    /* Emergency Alert Banner */
    .emergency-broadcast-banner{
      background:linear-gradient(135deg,#881337 0%,#4c0519 100%);
      border:1.5px solid #f43f5e;border-radius:16px;padding:18px 22px;color:#fff;margin-bottom:24px;
      display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;box-shadow:0 10px 30px rgba(136,19,55,.45);
    }
    .eb-left{display:flex;align-items:center;gap:16px;}
    .eb-icon-beacon{
      width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
      display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;animation:beaconBounce 1.5s infinite;
    }
    @keyframes beaconBounce{0%,100%{transform:scale(1);}50%{transform:scale(1.08);}}
    .eb-title-group h2{margin:0 0 4px;font-size:1.15rem;font-weight:800;letter-spacing:.02em;color:#fff;display:flex;align-items:center;gap:10px;}
    .eb-tag{font-size:0.68rem;padding:2px 8px;border-radius:10px;background:#f43f5e;color:#fff;font-weight:800;text-transform:uppercase;letter-spacing:.06em;}
    .eb-sub{font-size:0.82rem;color:#fecdd3;margin:0;}
    .eb-stats-bar{display:flex;gap:16px;background:rgba(0,0,0,.25);padding:8px 16px;border-radius:10px;border:1px solid rgba(255,255,255,.1);}
    .eb-stat-item{display:flex;flex-direction:column;}
    .eb-stat-num{font-size:1.05rem;font-weight:800;color:#fff;}
    .eb-stat-label{font-size:0.65rem;color:#fda4af;text-transform:uppercase;font-weight:700;letter-spacing:.05em;}

    .btn-stand-down{
      padding:9px 18px;background:#ffffff;color:#991b1b;border:none;border-radius:10px;font-size:0.82rem;font-weight:800;
      cursor:pointer;transition:.18s;box-shadow:0 4px 12px rgba(0,0,0,.15);white-space:nowrap;
    }
    .btn-stand-down:hover{background:#fee2e2;color:#7f1d1d;}

    /* Chip stats */
    .net-chips{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
    .net-chip{padding:10px 16px;background:var(--surface);border:1px solid var(--surface-border);border-radius:12px;display:flex;align-items:center;gap:8px;}
    .net-chip-val{font-size:1.1rem;font-weight:800;color:var(--text-heading);}
    .net-chip-label{font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;}

    /* Filter bar */
    .sa-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:14px 18px;background:var(--surface);border:1px solid var(--surface-border);border-radius:var(--radius-lg);margin-bottom:20px;}
    .f-sel{appearance:none;background:var(--surface);border:1.5px solid var(--surface-border);border-radius:9px;padding:7px 32px 7px 11px;font-size:.81rem;font-weight:600;color:var(--text-heading);font-family:inherit;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;background-size:13px;}
    .f-sel:focus{outline:none;border-color:var(--sa-accent);}
    .f-input{background:var(--surface);border:1.5px solid var(--surface-border);border-radius:9px;padding:7px 11px;font-size:.81rem;font-family:inherit;color:var(--text-heading);}
    .f-input:focus{outline:none;border-color:var(--sa-accent);}
    .f-btn{padding:7px 16px;background:var(--sa-grad);color:#fff;border:none;border-radius:9px;font-size:.81rem;font-weight:700;font-family:inherit;cursor:pointer;}
    .f-reset{font-size:.78rem;font-weight:600;color:var(--text-muted);text-decoration:none;padding:7px;}

    /* Bed tile grid */
    .bed-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:11px;margin-bottom:24px;}
    .bed-tile{padding:13px 12px;border-radius:12px;border:1.5px solid var(--surface-border);background:var(--surface);cursor:pointer;transition:box-shadow .18s, border-color .18s, transform .15s;position:relative;}
    .bed-tile:hover{box-shadow:0 6px 20px rgba(0,0,0,.09);border-color:var(--sa-accent);transform:translateY(-2px);}
    .bed-tile.t-avail{border-color:rgba(5,150,105,.3);background:#f0fdf4;}
    .bed-tile.t-avail:hover{border-color:var(--status-green);}
    .bed-tile.t-occ{border-color:rgba(239,68,68,.3);background:#fef2f2;}
    .bed-tile.t-occ:hover{border-color:var(--status-red);}
    .bed-tile.t-maint{border-color:rgba(217,119,6,.3);background:#fef3c7;}
    .bed-tile.t-maint:hover{border-color:var(--status-amber);}
    .bed-tile.t-hold {
      border: 1.5px solid rgba(244, 63, 94, 0.5);
      background: linear-gradient(135deg, rgba(255, 241, 242, 0.75) 0%, #f8fafc 100%);
      box-shadow: inset 0 0 0 1px rgba(244, 63, 94, 0.15), 0 2px 10px rgba(244, 63, 94, 0.08);
      position: relative;
    }
    .bed-tile.t-hold:hover {
      border-color: #e11d48;
      box-shadow: inset 0 0 0 1px rgba(225, 29, 72, 0.3), 0 8px 24px rgba(225, 29, 72, 0.16);
      transform: translateY(-2px);
    }
    .bed-tile.t-hold .bed-num {
      color: #9f1239;
    }
    .bed-tile.t-sanitizing{border-color:#0284c7;background:#f0f9ff;}
    .bed-tile.t-res{border-color:rgba(37,99,235,.3);background:#eff6ff;}

    /* Pending relocation badge */
    .reloc-badge-pill{
      position:absolute;top:-6px;right:-6px;background:#e11d48;color:#fff;font-size:0.6rem;font-weight:800;
      padding:2px 6px;border-radius:10px;box-shadow:0 2px 6px rgba(225,29,72,.4);letter-spacing:.04em;
    }

    .bed-num {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: .88rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--text-heading);
      margin-bottom: 3px;
    }
    .bed-ward{font-size:.67rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .bed-hosp{font-size:.68rem;color:var(--text-muted);margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .bed-status-row{display:flex;align-items:center;gap:4px;justify-content:space-between;}
    .bd-dot{width:7px;height:7px;border-radius:50%;}
    .bd-avail{background:var(--status-green);}
    .bd-occ{background:var(--status-red);}
    .bd-maint{background:var(--status-amber);}
    .bd-hold{background:#e11d48;}
    .bd-sanitizing{background:#0284c7;}
    .bd-res{background:var(--status-blue);}
    .bd-price{font-size:.66rem;color:var(--brand-teal);font-weight:700;}
    .floor-badge{font-size:.62rem;font-weight:800;padding:1px 5px;background:rgba(124,58,237,.12);color:var(--sa-accent);border-radius:5px;}

    /* Pagination */
    .pager{display:flex;gap:6px;align-items:center;justify-content:center;padding:20px 0;flex-wrap:wrap;}
    .pager a,.pager span{padding:6px 12px;border-radius:8px;font-size:.81rem;font-weight:600;text-decoration:none;border:1px solid var(--surface-border);color:var(--text-body);transition:.15s;}
    .pager a:hover{border-color:var(--sa-accent);color:var(--sa-accent);}
    .pager .pg-cur{background:var(--sa-grad);color:#fff;border-color:transparent;}
    .pager .pg-dots{border:none;pointer-events:none;color:var(--text-muted);}

    /* Modal standard */
    .sa-modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(5px);z-index:1000;align-items:center;justify-content:center;}
    .sa-modal-bg.open{display:flex;}
    .sa-modal{background:var(--surface);border-radius:var(--radius-xl);padding:26px;max-width:560px;width:94%;box-shadow:0 20px 60px rgba(0,0,0,.22);position:relative;animation:modalIn .22s cubic-bezier(.34,1.56,.64,1);max-height:90vh;overflow-y:auto;}
    @keyframes modalIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
    .sa-modal-close{position:absolute;top:16px;right:18px;background:transparent;border:none;cursor:pointer;font-size:1.2rem;color:var(--text-muted);line-height:1;}
    .sa-modal-title{font-size:1.1rem;font-weight:800;color:var(--text-heading);margin-bottom:4px;}
    .sa-modal-sub{font-size:.78rem;color:var(--text-muted);margin-bottom:16px;}
    .sa-detail-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--surface-border-subtle);}
    .sa-detail-row:last-child{border-bottom:none;}
    .sa-detail-key{font-size:.78rem;font-weight:700;color:var(--text-muted);}
    .sa-detail-val{font-size:.82rem;font-weight:600;color:var(--text-heading);text-align:right;max-width:65%;}
    .sa-override-section{margin-top:16px;padding-top:14px;border-top:1px solid var(--surface-border);}
    .sa-override-title{font-size:.76rem;font-weight:800;color:var(--sa-accent);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;}
    .sa-override-btns{display:flex;gap:8px;flex-wrap:wrap;}
    .sa-override-btn{padding:7px 14px;border:1.5px solid var(--surface-border);border-radius:8px;font-size:.78rem;font-weight:700;font-family:inherit;cursor:pointer;background:var(--surface);color:var(--text-heading);transition:.15s;}
    .sa-override-btn:hover{border-color:var(--sa-accent);color:var(--sa-accent);}
    .sa-override-btn.danger{border-color:var(--status-amber);color:var(--status-amber);}
    .sa-override-btn.danger:hover{background:var(--status-amber);color:#fff;}
    .sa-override-btn.hold{border-color:#e11d48;color:#e11d48;}
    .sa-override-btn.hold:hover{background:#e11d48;color:#fff;}
    .sa-override-btn.restore{border-color:var(--status-green);color:var(--status-green);}
    .sa-override-btn.restore:hover{background:var(--status-green);color:#fff;}
    .sa-audit-box{margin-top:16px;padding-top:14px;border-top:1px solid var(--surface-border);}
    .sa-audit-title{font-size:.76rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;}
    .sa-audit-item{font-size:.72rem;padding:7px 9px;border-radius:7px;background:var(--surface-secondary);margin-bottom:6px;border-left:3px solid var(--sa-accent);}
    .sa-audit-meta{display:flex;justify-content:space-between;color:var(--text-muted);font-size:.66rem;margin-bottom:2px;}

    /* Emergency Modal Specifics */
    .emergency-modal{max-width:720px;width:95%;}
    .proto-grid{display:grid;grid-template-columns:1fr;gap:9px;margin-bottom:18px;}
    .proto-card{border:1.5px solid var(--surface-border);border-radius:12px;padding:12px 14px;cursor:pointer;transition:.18s;display:flex;align-items:flex-start;gap:12px;background:var(--surface);}
    .proto-card:hover{border-color:#e11d48;background:#fff5f5;}
    .proto-card.selected{border-color:#e11d48;background:#fff1f2;box-shadow:0 0 0 1px #e11d48;}
    .proto-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;background:rgba(225,29,72,.1);color:#e11d48;}
    .proto-title{font-size:0.86rem;font-weight:800;color:var(--text-heading);margin-bottom:3px;}
    .proto-desc{font-size:0.75rem;color:var(--text-muted);line-height:1.35;}

    .quota-preset-group{display:flex;gap:8px;margin-bottom:12px;}
    .btn-preset{flex:1;padding:8px 10px;border-radius:8px;border:1.5px solid var(--surface-border);background:var(--surface);font-size:0.78rem;font-weight:800;cursor:pointer;transition:.15s;text-align:center;}
    .btn-preset:hover{border-color:var(--sa-accent);}
    .btn-preset.active{background:linear-gradient(135deg,#e11d48 0%,#b91c1c 100%);color:#fff;border-color:transparent;}

    .slider-row{display:flex;align-items:center;gap:14px;margin-bottom:18px;}
    .slider-row input[type="range"]{flex:1;accent-color:#e11d48;}
    .quota-badge{padding:4px 10px;background:#fef2f2;border:1px solid #ef4444;color:#991b1b;border-radius:8px;font-size:0.82rem;font-weight:800;min-width:55px;text-align:center;}

    .target-hosp-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px;}
    .hosp-check-label{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--surface-border);border-radius:8px;font-size:0.78rem;font-weight:600;cursor:pointer;background:var(--surface);}
    .hosp-check-label:hover{background:var(--surface-secondary);}
    .hosp-check-label input{accent-color:#e11d48;}
    .hosp-subtag{font-size:0.65rem;color:#e11d48;font-weight:700;}

    /* Live Preview Counter Box */
    .preview-box{background:#0f172a;border-radius:12px;padding:14px 16px;color:#fff;margin-bottom:20px;border:1px solid rgba(255,255,255,.1);}
    .pb-head{font-size:0.72rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;display:flex;justify-content:space-between;}
    .pb-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;}
    .pb-metric-item{background:rgba(255,255,255,.05);padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.07);}
    .pb-val{font-size:1.15rem;font-weight:800;color:#fff;}
    .pb-lbl{font-size:0.65rem;color:#94a3b8;text-transform:uppercase;font-weight:700;}

    .btn-exec-surge{width:100%;padding:12px;background:linear-gradient(135deg,#e11d48 0%,#b91c1c 100%);color:#fff;border:none;border-radius:10px;font-size:0.88rem;font-weight:800;cursor:pointer;box-shadow:0 4px 16px rgba(225,29,72,.4);text-transform:uppercase;letter-spacing:.04em;}
    .btn-exec-surge:hover{filter:brightness(1.1);}

    /* Toast */
    .sa-toast{position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:12px 20px;border-radius:12px;font-size:.82rem;font-weight:600;display:none;align-items:center;gap:8px;z-index:2000;box-shadow:0 8px 24px rgba(0,0,0,.2);}
    .sa-toast.show{display:flex;}
    .sa-toast.t-success .sa-toast-dot{background:#10b981;}
    .sa-toast.t-error .sa-toast-dot{background:#ef4444;}
    .sa-toast-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}

    /* Emergency Hold Status Badge & Ping Dot Variants */
    .sa-hold-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 2px 7px;
      border-radius: 6px;
      background: #ffe4e6;
      color: #9f1239;
      border: 1px solid rgba(244, 63, 94, 0.35);
      font-size: 0.67rem;
      font-weight: 800;
      letter-spacing: 0.02em;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .badge-surge-amber {
      background: #fef3c7 !important;
      color: #92400e !important;
      border-color: rgba(217, 119, 6, 0.4) !important;
    }
    .badge-surge-amber .sa-ping-ring { background: #f59e0b !important; }
    .badge-surge-amber .sa-ping-dot { background: #d97706 !important; }

    .badge-surge-crimson {
      background: #ffe4e6 !important;
      color: #9f1239 !important;
      border-color: rgba(244, 63, 94, 0.4) !important;
    }
    .badge-surge-crimson .sa-ping-ring { background: #f43f5e !important; }
    .badge-surge-crimson .sa-ping-dot { background: #e11d48 !important; }

    .badge-surge-vermillion {
      background: #ffedd5 !important;
      color: #9a3412 !important;
      border-color: rgba(234, 88, 12, 0.4) !important;
    }
    .badge-surge-vermillion .sa-ping-ring { background: #fb923c !important; }
    .badge-surge-vermillion .sa-ping-dot { background: #ea580c !important; }

    .badge-surge-sky {
      background: #e0f2fe !important;
      color: #0369a1 !important;
      border-color: rgba(2, 132, 199, 0.4) !important;
    }
    .badge-surge-sky .sa-ping-ring { background: #38bdf8 !important; }
    .badge-surge-sky .sa-ping-dot { background: #0284c7 !important; }

    .badge-surge-purple {
      background: #f3e8ff !important;
      color: #6b21a8 !important;
      border-color: rgba(147, 51, 234, 0.4) !important;
    }
    .badge-surge-purple .sa-ping-ring { background: #c084fc !important; }
    .badge-surge-purple .sa-ping-dot { background: #9333ea !important; }
    .sa-ping-container {
      position: relative;
      display: inline-flex;
      width: 7px;
      height: 7px;
    }
    .sa-ping-ring {
      position: absolute;
      inset: 0;
      border-radius: 50%;
      background: #f43f5e;
      animation: saPingRing 1.4s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .sa-ping-dot {
      position: relative;
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #e11d48;
    }
    @keyframes saPingRing {
      75%, 100% {
        transform: scale(2.4);
        opacity: 0;
      }
    }

    /* Dynamic ECG Waveform Live Telemetry Bar */
    .sa-telemetry-badge {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 6px 14px;
      border-radius: 40px;
      font-size: 0.74rem;
      font-weight: 700;
      backdrop-filter: blur(8px);
      transition: all 0.25s ease;
      white-space: nowrap;
    }
    .sa-telemetry-badge.telemetry-normal {
      background: rgba(16, 185, 129, 0.08);
      border: 1px solid rgba(16, 185, 129, 0.32);
      color: #065f46;
      box-shadow: 0 1px 4px rgba(16, 185, 129, 0.1);
    }
    .sa-telemetry-badge.telemetry-surge {
      background: linear-gradient(135deg, rgba(244, 63, 94, 0.14) 0%, rgba(225, 29, 72, 0.22) 100%);
      border: 1.5px solid rgba(244, 63, 94, 0.6);
      color: #9f1239;
      box-shadow: 0 0 16px rgba(244, 63, 94, 0.22);
    }
    .ecg-track {
      width: 52px;
      height: 18px;
      display: flex;
      align-items: center;
    }
    .ecg-svg {
      width: 52px;
      height: 18px;
      overflow: visible;
    }
    .ecg-pulse-line {
      fill: none;
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-linejoin: round;
      stroke-dasharray: 80;
      stroke-dashoffset: 80;
    }
    .telemetry-normal .ecg-pulse-line {
      stroke: #10b981;
      animation: ecgStrokeSweep 2.2s linear infinite;
    }
    .telemetry-surge .ecg-pulse-line {
      stroke: #f43f5e;
      animation: ecgStrokeSweep 1.1s linear infinite;
    }
    @keyframes ecgStrokeSweep {
      0% { stroke-dashoffset: 80; }
      50% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -80; }
    }
    .telemetry-info {
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .telemetry-bpm {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-weight: 800;
      letter-spacing: -0.01em;
    }
    .telemetry-normal .telemetry-bpm {
      color: #059669;
    }
    .telemetry-surge .telemetry-bpm {
      color: #e11d48;
      animation: bpmSurgePulse 0.9s ease-in-out infinite;
    }
    @keyframes bpmSurgePulse {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.75; transform: scale(1.05); }
    }
    .telemetry-sep {
      opacity: 0.4;
      font-size: 0.65rem;
    }
    .telemetry-status {
      letter-spacing: 0.04em;
      text-transform: uppercase;
      font-size: 0.70rem;
    }
    .telemetry-pulse-dot {
      position: relative;
      display: inline-flex;
      width: 8px;
      height: 8px;
    }
    .telemetry-pulse-ring {
      position: absolute;
      inset: 0;
      border-radius: 50%;
    }
    .telemetry-normal .telemetry-pulse-ring {
      background: #10b981;
      animation: telRing 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .telemetry-surge .telemetry-pulse-ring {
      background: #f43f5e;
      animation: telRing 0.9s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    .telemetry-pulse-core {
      position: relative;
      width: 8px;
      height: 8px;
      border-radius: 50%;
    }
    .telemetry-normal .telemetry-pulse-core {
      background: #059669;
    }
    .telemetry-surge .telemetry-pulse-core {
      background: #e11d48;
    }
    @keyframes telRing {
      75%, 100% {
        transform: scale(2.6);
        opacity: 0;
      }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <main class="viewport-full">
    <!-- Welcome Header with Emergency Button -->
    <div class="welcome-banner">
      <div class="banner-header-flex">
        <div class="welcome-text">
          <div class="sa-welcome-badge">Super Administrator</div>
          <h1>Central Bed Monitor &amp; Emergency Command</h1>
          <p>Real-time telemetry across 6 operational hospitals (<?= number_format((int)($netStats['total'] ?? 0)) ?> total beds) with integrated disaster surge engine.</p>
        </div>
        <div class="banner-actions" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <!-- Dynamic ECG Waveform Live Telemetry Bar -->
          <div class="sa-telemetry-badge <?= $activeCount > 0 ? 'telemetry-surge' : 'telemetry-normal' ?>" title="<?= $activeCount > 0 ? 'National Emergency Surge Protocol Active: ' . $activeCount . ' protocol(s)' : 'Bed Telemetry Synchronized across all 6 facilities' ?>">
            <div class="ecg-track">
              <svg class="ecg-svg" viewBox="0 0 54 18" preserveAspectRatio="none">
                <path class="ecg-pulse-line" d="M0,9 L12,9 L15,3 L18,15 L21,2 L24,16 L27,9 L32,9 L35,6 L38,11 L41,9 L54,9" />
              </svg>
            </div>
            <div class="telemetry-info">
              <span class="telemetry-bpm font-mono"><?= $activeCount > 0 ? '118 BPM' : '72 BPM' ?></span>
              <span class="telemetry-sep">•</span>
              <span class="telemetry-status">
                <?php if ($activeCount === 0): ?>
                  SYSTEM NORMAL
                <?php elseif ($activeCount === 1): ?>
                  SURGE ACTIVE: <?= htmlspecialchars($activeProtocols[0]['title'], ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                  <?= $activeCount ?> PROTOCOLS CONCURRENT SURGE (<?= number_format($totalSurgeHeldBeds) ?> BEDS)
                <?php endif; ?>
              </span>
            </div>
            <span class="telemetry-pulse-dot">
              <span class="telemetry-pulse-ring"></span>
              <span class="telemetry-pulse-core"></span>
            </span>
          </div>

          <?php if ($activeCount === 1): ?>
            <button type="button" class="btn-active-surge-pill" onclick="openEmergencyModal()">
              <span class="bd-dot bd-hold" style="animation:beaconBounce 1s infinite;"></span>
              SURGE ACTIVE: <?= htmlspecialchars($activeProtocols[0]['title'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$activeProtocols[0]['severity_quota'] ?>%)
            </button>
          <?php elseif ($activeCount > 1): ?>
            <button type="button" class="btn-active-surge-pill" onclick="openEmergencyModal()">
              <span class="bd-dot bd-hold" style="animation:beaconBounce 0.8s infinite;"></span>
              SURGE ACTIVE: <?= $activeCount ?> CONCURRENT PROTOCOLS (<?= number_format($totalSurgeHeldBeds) ?> BEDS)
            </button>
          <?php else: ?>
            <button type="button" class="btn-declare-emergency" onclick="openEmergencyModal()">
              <svg class="ui-ico" style="width:16px;height:16px;stroke:#fff;" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
              Declare National Emergency / Triage Surge
            </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Active Emergency Broadcast Banner -->
    <?php if ($activeCount > 0): ?>
    <div class="emergency-broadcast-banner">
      <div class="eb-left">
        <div class="eb-icon-beacon">🚨</div>
        <div class="eb-title-group">
          <h2>
            <?php if ($activeCount === 1): ?>
              <?= htmlspecialchars($activeProtocols[0]['title'], ENT_QUOTES, 'UTF-8') ?>
              <span class="eb-tag"><?= htmlspecialchars($activeProtocols[0]['severity_level'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$activeProtocols[0]['severity_quota'] ?>% QUOTA)</span>
            <?php else: ?>
              <?= $activeCount ?> Concurrent National Emergency Protocols Active
              <span class="eb-tag" style="background:#dc2626;">CONCURRENT SURGE</span>
            <?php endif; ?>
          </h2>
          <p class="eb-sub">
            <?php if ($activeCount === 1): ?>
              <?= htmlspecialchars($activeProtocols[0]['notes'] ?: 'National Emergency Protocol currently enforced across MedPulse Network facilities.', ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
              Concurrent crisis protocols currently locked: <?= implode(', ', array_map(fn($p) => htmlspecialchars($p['title']), $activeProtocols)) ?>.
            <?php endif; ?>
          </p>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div class="eb-stats-bar">
          <div class="eb-stat-item">
            <span class="eb-stat-num" style="color:#fda4af;"><?= number_format($totalSurgeHeldBeds) ?></span>
            <span class="eb-stat-label">Total Held</span>
          </div>
          <div class="eb-stat-item">
            <span class="eb-stat-num" style="color:#fde047;"><?= number_format($totalSurgeRelocBeds) ?></span>
            <span class="eb-stat-label">Pending Reloc</span>
          </div>
          <div class="eb-stat-item">
            <span class="eb-stat-num" style="color:#7dd3fc;"><?= $activeCount ?></span>
            <span class="eb-stat-label">Protocols</span>
          </div>
        </div>

        <button type="button" class="btn-stand-down" onclick="confirmStandDown()">
          ⚡ Stand Down &amp; Restore Operations
        </button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Network chips -->
    <div class="net-chips">
      <div class="net-chip">
        <span class="net-chip-val"><?= number_format((int)($netStats['total'] ?? 0)) ?></span>
        <span class="net-chip-label">Total Beds</span>
      </div>
      <div class="net-chip">
        <span class="net-chip-val" style="color:var(--status-green);"><?= number_format((int)($netStats['avail'] ?? 0)) ?></span>
        <span class="net-chip-label">Available</span>
      </div>
      <div class="net-chip">
        <span class="net-chip-val" style="color:var(--status-red);"><?= number_format((int)($netStats['occ'] ?? 0)) ?></span>
        <span class="net-chip-label">Occupied</span>
      </div>
      <div class="net-chip">
        <span class="net-chip-val" style="color:#e11d48;"><?= number_format((int)($netStats['hold'] ?? 0)) ?></span>
        <span class="net-chip-label">Emergency Hold</span>
      </div>
      <div class="net-chip">
        <span class="net-chip-val" style="color:#0284c7;"><?= number_format((int)($netStats['sanitizing'] ?? 0)) ?></span>
        <span class="net-chip-label">Sanitizing</span>
      </div>
      <div class="net-chip">
        <span class="net-chip-val" style="color:var(--status-amber);"><?= number_format((int)($netStats['maint'] ?? 0)) ?></span>
        <span class="net-chip-label">Maintenance</span>
      </div>
      <?php if (!empty($netStats['pending_reloc'])): ?>
      <div class="net-chip" style="border-color:#ef4444;background:#fef2f2;">
        <span class="net-chip-val" style="color:#b91c1c;"><?= number_format((int)$netStats['pending_reloc']) ?></span>
        <span class="net-chip-label" style="color:#b91c1c;">Pending Relocation</span>
      </div>
      <?php endif; ?>
      <div class="net-chip" style="margin-left:auto;">
        <span class="net-chip-val"><?= $total ?></span>
        <span class="net-chip-label">Filtered Beds</span>
      </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="bed_monitor.php" class="sa-filter-bar" id="filterForm">
      <select class="f-sel" name="hospital_id" onchange="this.form.submit()">
        <option value="0">All 6 Hospitals</option>
        <?php foreach ($allHospitals as $h): ?>
          <option value="<?= (int)$h['hospital_id'] ?>" <?= $filterHospId === (int)$h['hospital_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?> (<?= $h['code'] ?>)
          </option>
        <?php endforeach; ?>
      </select>

      <select class="f-sel" name="ward" onchange="this.form.submit()">
        <option value="">All Ward Types</option>
        <?php foreach ($wardTypes as $w): ?>
          <option value="<?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>" <?= $filterWard === $w ? 'selected' : '' ?>>
            <?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select class="f-sel" name="status" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach ($statusTypes as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
        <option value="Relocation Needed" <?= $filterStatus === 'Relocation Needed' ? 'selected' : '' ?>>⚠️ Relocation Needed</option>
      </select>

      <select class="f-sel" name="floor" onchange="this.form.submit()">
        <option value="0">All Floors (1-10)</option>
        <?php foreach ($allFloors as $fl): ?>
          <option value="<?= (int)$fl ?>" <?= $filterFloor === (int)$fl ? 'selected' : '' ?>>Floor <?= (int)$fl ?></option>
        <?php endforeach; ?>
      </select>

      <input class="f-input" type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search bed (e.g. NIBPS, EMG)…">
      <button class="f-btn" type="submit">Apply</button>
      <a class="f-reset" href="bed_monitor.php">Reset</a>

      <!-- preserve page=1 on filter change -->
      <input type="hidden" name="page" value="1">
    </form>

    <!-- Legend -->
    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
      <?php foreach ([
        ['Available','bd-avail'],
        ['Occupied','bd-occ'],
        ['Emergency Hold','bd-hold'],
        ['Sanitizing','bd-sanitizing'],
        ['Maintenance','bd-maint'],
        ['Reserved','bd-res']
      ] as [$lbl, $cls]): ?>
        <span style="display:flex;align-items:center;gap:5px;font-size:.77rem;font-weight:600;color:var(--text-body);">
          <span class="bd-dot <?= $cls ?>"></span><?= $lbl ?>
        </span>
      <?php endforeach; ?>
      <span style="margin-left:auto;font-size:.77rem;color:var(--text-muted);">
        Page <?= $page ?> / <?= $totalPages ?> &bull; Click any tile to inspect &amp; override
      </span>
    </div>

    <!-- Bed tiles -->
    <?php if (empty($beds)): ?>
      <div style="text-align:center;padding:60px;color:var(--text-muted);">No beds match the selected filters.</div>
    <?php else: ?>
    <div class="bed-grid" id="bedGrid">
      <?php foreach ($beds as $bed):
        $st  = strtolower($bed['status']);
        $tc  = match($st) { 
          'available' => 't-avail', 
          'occupied' => 't-occ', 
          'maintenance' => 't-maint', 
          'emergency hold' => 't-hold', 
          'sanitizing' => 't-sanitizing',
          default => 't-res' 
        };
        $dc  = match($st) { 
          'available' => 'bd-avail', 
          'occupied' => 'bd-occ', 
          'maintenance' => 'bd-maint', 
          'emergency hold' => 'bd-hold', 
          'sanitizing' => 'bd-sanitizing',
          default => 'bd-res' 
        };
        $isReloc = ($bed['relocation_status'] === 'PENDING_RELOCATION');
      ?>
      <div class="bed-tile <?= $tc ?>" id="tile-<?= (int)$bed['bed_id'] ?>" onclick="openBedModal(<?= (int)$bed['bed_id'] ?>)">
        <?php if ($isReloc): ?>
          <span class="reloc-badge-pill">⚠️ RELOCATE</span>
        <?php endif; ?>
        <div class="bed-num"><?= htmlspecialchars($bed['bed_number'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="bed-ward" title="<?= htmlspecialchars($bed['ward_type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($bed['ward_type'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="bed-hosp"><?= htmlspecialchars($bed['hospital_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="bed-status-row">
          <?php if ($st === 'emergency hold'): 
            $epCode = strtoupper($bed['ep_code'] ?? '');
            $surgeLabel = 'SURGE: EMERGENCY HOLD';
            $surgeBadgeClass = 'badge-surge-crimson';

            if ($epCode === 'DENGUE_EPIDEMIC') {
                $surgeLabel = 'SURGE: DENGUE ISOLATION';
                $surgeBadgeClass = 'badge-surge-amber';
            } elseif ($epCode === 'MASS_CASUALTY') {
                $surgeLabel = 'SURGE: TRAUMA / ACCIDENT';
                $surgeBadgeClass = 'badge-surge-crimson';
            } elseif ($epCode === 'BURN_DISASTER') {
                $surgeLabel = 'SURGE: BURN DISASTER';
                $surgeBadgeClass = 'badge-surge-vermillion';
            } elseif ($epCode === 'NATURAL_DISASTER') {
                $surgeLabel = 'SURGE: NATURAL DISASTER';
                $surgeBadgeClass = 'badge-surge-sky';
            } elseif ($epCode === 'HAZMAT') {
                $surgeLabel = 'SURGE: HAZMAT QUARANTINE';
                $surgeBadgeClass = 'badge-surge-purple';
            } elseif (!empty($bed['ep_title'])) {
                $surgeLabel = 'SURGE: ' . strtoupper(htmlspecialchars($bed['ep_title']));
            }
          ?>
            <span class="sa-hold-badge <?= $surgeBadgeClass ?>">
              <span class="sa-ping-container">
                <span class="sa-ping-ring"></span>
                <span class="sa-ping-dot"></span>
              </span>
              <?= $surgeLabel ?>
            </span>
          <?php else: ?>
            <span style="display:flex;align-items:center;gap:4px;">
              <span class="bd-dot <?= $dc ?>"></span>
              <span style="font-size:.72rem;font-weight:700;color:var(--text-heading);"><?= htmlspecialchars($bed['status'], ENT_QUOTES, 'UTF-8') ?></span>
            </span>
          <?php endif; ?>
          <span class="floor-badge">F<?= (int)$bed['floor_number'] ?></span>
        </div>
        <?php if (!empty($bed['price_per_day'])): ?>
        <div class="bd-price" style="margin-top:4px;">৳<?= number_format((float)$bed['price_per_day'], 0) ?>/day</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Prev</a>
      <?php endif; ?>

      <?php
        $startP = max(1, $page - 3);
        $endP   = min($totalPages, $page + 3);
        if ($startP > 1) { echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a>'; if ($startP > 2) echo '<span class="pg-dots">…</span>'; }
        for ($p = $startP; $p <= $endP; $p++):
      ?>
        <?php if ($p === $page): ?>
          <span class="pg-cur"><?= $p ?></span>
        <?php else: ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php
        if ($endP < $totalPages) { if ($endP < $totalPages - 1) echo '<span class="pg-dots">…</span>'; echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '">' . $totalPages . '</a>'; }
      ?>

      <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </main>

  <!-- ── MODAL 1: Declare National Emergency / Triage Surge ──────────────────── -->
  <div class="sa-modal-bg" id="emergencyModalBg">
    <div class="sa-modal emergency-modal" role="dialog" aria-modal="true">
      <button class="sa-modal-close" onclick="closeEmergencyModal()" aria-label="Close">✕</button>
      <div class="sa-modal-title" style="color:#e11d48;display:flex;align-items:center;gap:8px;">
        <span>🚨</span> National Emergency Triage &amp; Surge Engine
      </div>
      <div class="sa-modal-sub">
        Enforce dynamic capacity surge locks across MedPulse enterprise facilities according to real-world clinical profiles.
      </div>

      <!-- 1. Protocol Selector Cards -->
      <div style="font-size:0.76rem;font-weight:800;color:var(--text-heading);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
        1. Select Core Disaster Protocol
      </div>
      <div class="proto-grid">
        <!-- Dengue -->
        <div class="proto-card selected" data-code="DENGUE_EPIDEMIC" onclick="selectProtocol(this)">
          <div class="proto-icon" style="background:rgba(217,119,6,.12);color:#d97706;">💧</div>
          <div style="flex:1;">
            <div class="proto-title">Dengue Epidemic Surge</div>
            <div class="proto-desc">Converts general floor/wing into Dengue Isolation HDU equipped for continuous IV fluid &amp; platelet tracking.</div>
          </div>
        </div>

        <!-- Fire / Burn -->
        <div class="proto-card" data-code="BURN_DISASTER" onclick="selectProtocol(this)">
          <div class="proto-icon" style="background:rgba(220,38,38,.12);color:#dc2626;">🔥</div>
          <div style="flex:1;">
            <div class="proto-title">Fire / Industrial Burn Disaster (Apex Routing)</div>
            <div class="proto-desc">Enforces priority surge locks strictly on burn-equipped facilities: Primary Apex routing to NIBPS (Hospital 6) &amp; secondary surge locks on MedPulse Floor 3 Burn Unit.</div>
          </div>
        </div>

        <!-- Mass Casualty -->
        <div class="proto-card" data-code="MASS_CASUALTY" onclick="selectProtocol(this)">
          <div class="proto-icon" style="background:rgba(225,29,72,.12);color:#e11d48;">🚑</div>
          <div style="flex:1;">
            <div class="proto-title">Mass Casualty / Road Accident</div>
            <div class="proto-desc">Locks Emergency triage wards and Trauma ICU capacity across relevant highway/city proximity facilities (Evercare, Square, UMCH, MedPulse).</div>
          </div>
        </div>

        <!-- Natural Disaster -->
        <div class="proto-card" data-code="NATURAL_DISASTER" onclick="selectProtocol(this)">
          <div class="proto-icon" style="background:rgba(2,132,199,.12);color:#0284c7;">🌊</div>
          <div style="flex:1;">
            <div class="proto-title">Natural Disaster (Flood / Cyclone Outbreak)</div>
            <div class="proto-desc">Prioritizes lower-floor general beds for mass casualty intake, oral rehydration stations, and waterborne illness isolation.</div>
          </div>
        </div>

        <!-- HAZMAT -->
        <div class="proto-card" data-code="HAZMAT" onclick="selectProtocol(this)">
          <div class="proto-icon" style="background:rgba(147,51,234,.12);color:#9333ea;">☣️</div>
          <div style="flex:1;">
            <div class="proto-title">HAZMAT / Toxic Chemical Exposure</div>
            <div class="proto-desc">Quarantines isolated negative-airflow units with complete regular patient isolation, chemical showers, and antidote stores.</div>
          </div>
        </div>
      </div>

      <!-- 2. Surge Quota Selector -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:0.76rem;font-weight:800;color:var(--text-heading);text-transform:uppercase;letter-spacing:.05em;">
          2. Surge Quota Scaling
        </span>
        <span class="quota-badge" id="quotaDisplay">20% Moderate</span>
      </div>

      <div class="quota-preset-group">
        <button type="button" class="btn-preset" data-pct="10" onclick="setQuotaPreset(10, 'Alert', this)">10% Alert</button>
        <button type="button" class="btn-preset active" data-pct="20" onclick="setQuotaPreset(20, 'Moderate', this)">20% Moderate</button>
        <button type="button" class="btn-preset" data-pct="35" onclick="setQuotaPreset(35, 'Severe', this)">35% Severe</button>
        <button type="button" class="btn-preset" data-pct="50" onclick="setQuotaPreset(50, 'National Crisis', this)">50% National Crisis</button>
      </div>

      <div class="slider-row">
        <input type="range" id="quotaSlider" min="10" max="50" step="5" value="20" oninput="onQuotaSlider(this.value)">
      </div>

      <!-- 3. Dynamic Facility Target Filter -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:0.76rem;font-weight:800;color:var(--text-heading);text-transform:uppercase;letter-spacing:.05em;">
          3. Targeted Facilities Scope
        </span>
        <div style="font-size:0.75rem;font-weight:700;">
          <a href="javascript:void(0)" onclick="selectAllHospitals(true)" style="color:#0d9488;text-decoration:none;margin-right:8px;">Select All</a>
          <a href="javascript:void(0)" onclick="selectAllHospitals(false)" style="color:var(--text-muted);text-decoration:none;">Clear</a>
        </div>
      </div>

      <div class="target-hosp-grid">
        <label class="hosp-check-label">
          <input type="checkbox" name="target_hosp" value="1" checked onchange="refreshPreview()">
          <div>
            <div>MedPulse Hospital (Flagship)</div>
            <div class="hosp-subtag">Floor 3 Burn Unit (25 Beds)</div>
          </div>
        </label>

        <label class="hosp-check-label">
          <input type="checkbox" name="target_hosp" value="2" checked onchange="refreshPreview()">
          <div>
            <div>Square Hospital Ltd</div>
            <div style="font-size:0.65rem;color:var(--text-muted);">Neuro &amp; Trauma ICU</div>
          </div>
        </label>

        <label class="hosp-check-label">
          <input type="checkbox" name="target_hosp" value="3" checked onchange="refreshPreview()">
          <div>
            <div>United Hospital Ltd</div>
            <div style="font-size:0.65rem;color:var(--text-muted);">Cardiac ICU &amp; Dialysis</div>
          </div>
        </label>

        <label class="hosp-check-label">
          <input type="checkbox" name="target_hosp" value="4" checked onchange="refreshPreview()">
          <div>
            <div>UMCH (United Med College)</div>
            <div style="font-size:0.65rem;color:var(--text-muted);">Academic Wards &amp; Pediatrics</div>
          </div>
        </label>

        <label class="hosp-check-label">
          <input type="checkbox" name="target_hosp" value="5" checked onchange="refreshPreview()">
          <div>
            <div>Evercare Hospital Dhaka</div>
            <div style="font-size:0.65rem;color:var(--text-muted);">Trauma, Multi-ICU, PICU</div>
          </div>
        </label>

        <label class="hosp-check-label" style="border-color:#f43f5e;background:#fff1f2;">
          <input type="checkbox" name="target_hosp" value="6" checked onchange="refreshPreview()">
          <div>
            <div style="color:#991b1b;font-weight:800;">NIBPS (National Burn Institute)</div>
            <div class="hosp-subtag" style="color:#b91c1c;">National Apex Burn Pavilion (500 Beds)</div>
          </div>
        </label>
      </div>

      <!-- 4. Live Preview Counter -->
      <div class="preview-box">
        <div class="pb-head">
          <span>⚡ Live Transition Algorithm Preview</span>
          <span id="pbStatusText" style="color:#38bdf8;">Calculated</span>
        </div>
        <div class="pb-metrics">
          <div class="pb-metric-item">
            <div class="pb-val" id="pbQuotaBeds">0</div>
            <div class="pb-lbl">Target Quota Beds</div>
          </div>
          <div class="pb-metric-item">
            <div class="pb-val" id="pbHoldBeds" style="color:#4ade80;">0</div>
            <div class="pb-lbl">Priority 1 (Available → Hold)</div>
          </div>
          <div class="pb-metric-item">
            <div class="pb-val" id="pbRelocBeds" style="color:#f87171;">0</div>
            <div class="pb-lbl">Priority 2 (Pending Relocation)</div>
          </div>
        </div>
        <div id="pbHospBreakdown" style="font-size:0.72rem;color:#cbd5e1;line-height:1.4;">
          Calculating network response capacity…
        </div>
      </div>

      <!-- Execute Button -->
      <button type="button" class="btn-exec-surge" onclick="submitEmergencyDeclaration()">
        🚨 Execute Disaster Protocol Surge &amp; Lock Capacity
      </button>
    </div>
  </div>

  <!-- ── MODAL 2: Bed Detail & Override Modal ────────────────────────────────── -->
  <div class="sa-modal-bg" id="bedModalBg">
    <div class="sa-modal" role="dialog" aria-modal="true">
      <button class="sa-modal-close" onclick="closeBedModal()" aria-label="Close">✕</button>
      <div class="sa-modal-title" id="modalBedNum">Loading…</div>
      <div class="sa-modal-sub" id="modalBedSub"></div>
      <div id="modalDetails"></div>
      <div class="sa-override-section">
        <div class="sa-override-title">⚡ Force Status Override</div>
        <div class="sa-override-btns">
          <button class="sa-override-btn danger" onclick="forceOverride('Maintenance')">→ Maintenance</button>
          <button class="sa-override-btn hold" onclick="forceOverride('Emergency Hold')">→ Emergency Hold</button>
          <button class="sa-override-btn restore" onclick="forceOverride('Available')">→ Mark Available</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <div class="sa-toast" id="saToast"><span class="sa-toast-dot"></span><span id="saToastMsg"></span></div>

  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    let activeBedId = null;
    let selectedProtocolCode = 'DENGUE_EPIDEMIC';
    let currentQuotaPct = 20;
    let currentSeverityLabel = 'Moderate';

    function saToast(msg, type = 'success') {
      const t = document.getElementById('saToast');
      const m = document.getElementById('saToastMsg');
      t.className = 'sa-toast show t-' + type;
      m.textContent = msg;
      clearTimeout(t._timer);
      t._timer = setTimeout(() => t.classList.remove('show'), 4500);
    }

    // ── Emergency Modal Controller ──────────────────────────────────────────
    function openEmergencyModal() {
      document.getElementById('emergencyModalBg').classList.add('open');
      refreshPreview();
    }

    function closeEmergencyModal() {
      document.getElementById('emergencyModalBg').classList.remove('open');
    }

    document.getElementById('emergencyModalBg').addEventListener('click', e => {
      if (e.target === document.getElementById('emergencyModalBg')) closeEmergencyModal();
    });

    function selectProtocol(card) {
      document.querySelectorAll('.proto-card').forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      selectedProtocolCode = card.dataset.code;

      // Smart routing logic for Fire / Industrial Burn Disaster:
      // Auto-lock NIBPS (Apex Hospital 6) + MedPulse (Floor 3 Burn Unit Hospital 1)
      if (selectedProtocolCode === 'BURN_DISASTER') {
        document.querySelectorAll('input[name="target_hosp"]').forEach(cb => {
          cb.checked = (cb.value === '6' || cb.value === '1');
        });
      } else if (selectedProtocolCode === 'MASS_CASUALTY') {
        document.querySelectorAll('input[name="target_hosp"]').forEach(cb => {
          cb.checked = ['1', '2', '4', '5'].includes(cb.value);
        });
      }

      refreshPreview();
    }

    function setQuotaPreset(pct, label, btn) {
      document.querySelectorAll('.btn-preset').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentQuotaPct = pct;
      currentSeverityLabel = label;
      document.getElementById('quotaSlider').value = pct;
      document.getElementById('quotaDisplay').textContent = `${pct}% ${label}`;
      refreshPreview();
    }

    function onQuotaSlider(val) {
      currentQuotaPct = parseInt(val, 10);
      currentSeverityLabel = currentQuotaPct >= 50 ? 'National Crisis' : currentQuotaPct >= 35 ? 'Severe' : currentQuotaPct >= 20 ? 'Moderate' : 'Alert';
      document.getElementById('quotaDisplay').textContent = `${currentQuotaPct}% ${currentSeverityLabel}`;

      document.querySelectorAll('.btn-preset').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.pct, 10) === currentQuotaPct);
      });
      refreshPreview();
    }

    function selectAllHospitals(checked) {
      document.querySelectorAll('input[name="target_hosp"]').forEach(cb => cb.checked = checked);
      refreshPreview();
    }

    function getSelectedHospitalIds() {
      return Array.from(document.querySelectorAll('input[name="target_hosp"]:checked')).map(cb => parseInt(cb.value, 10));
    }

    async function refreshPreview() {
      const hospIds = getSelectedHospitalIds();
      if (hospIds.length === 0) {
        document.getElementById('pbQuotaBeds').textContent = '0';
        document.getElementById('pbHoldBeds').textContent = '0';
        document.getElementById('pbRelocBeds').textContent = '0';
        document.getElementById('pbHospBreakdown').textContent = 'Please select at least one hospital facility.';
        return;
      }

      try {
        const fd = new FormData();
        fd.append('_action', 'preview_surge');
        fd.append('protocol_code', selectedProtocolCode);
        fd.append('quota_pct', currentQuotaPct);
        fd.append('target_hospitals', hospIds.join(','));

        const r = await fetch('../backend/api/emergency_surge_action.php', { method: 'POST', body: fd });
        const d = await r.json();

        if (d.success && d.preview) {
          const p = d.preview;
          document.getElementById('pbQuotaBeds').textContent = p.target_quota_beds.toLocaleString();
          document.getElementById('pbHoldBeds').textContent  = p.beds_to_hold.toLocaleString();
          document.getElementById('pbRelocBeds').textContent = p.beds_to_relocate.toLocaleString();

          let details = `Scope: ${p.total_target_scope_beds.toLocaleString()} total beds in ${hospIds.length} facilities. Available matching: ${p.available_in_scope}. `;
          if (p.beds_to_relocate > 0) {
            details += `⚠️ Quota exceeds available capacity by ${p.beds_to_relocate} beds. Priority 2 will flag ${p.beds_to_relocate} occupied beds as PENDING_RELOCATION for branch transfer.`;
          } else {
            details += `✓ All quota capacity satisfied from existing Available beds without inpatient relocation.`;
          }
          document.getElementById('pbHospBreakdown').textContent = details;
        }
      } catch (e) {
        console.error("Preview failed", e);
      }
    }

    async function submitEmergencyDeclaration() {
      const hospIds = getSelectedHospitalIds();
      if (hospIds.length === 0) {
        MedPulseDialog.toast({
          title: 'Facility Selection Required',
          message: 'Please select at least one target hospital facility before activating surge locks.',
          type: 'warning'
        });
        return;
      }

      // Gather rich context for the confirmation modal
      const card = document.querySelector('.proto-card.selected');
      const protoTitle = card ? card.querySelector('.proto-title').textContent.trim() : selectedProtocolCode;
      
      const facilityLabels = Array.from(document.querySelectorAll('input[name="target_hosp"]:checked')).map(cb => {
        const wrap = cb.closest('.hosp-check-label');
        return wrap ? wrap.querySelector('div > div:first-child').textContent.trim() : `Hospital #${cb.value}`;
      });

      const quotaBeds = parseInt(document.getElementById('pbQuotaBeds').textContent.replace(/,/g, ''), 10) || 0;
      const holdBeds = parseInt(document.getElementById('pbHoldBeds').textContent.replace(/,/g, ''), 10) || 0;
      const relocBeds = parseInt(document.getElementById('pbRelocBeds').textContent.replace(/,/g, ''), 10) || 0;

      const confirmed = await MedPulseDialog.surgeConfirm({
        protocolCode: selectedProtocolCode,
        protocolTitle: protoTitle,
        quotaPct: currentQuotaPct,
        severityLabel: currentSeverityLabel,
        quotaBeds: quotaBeds,
        holdBeds: holdBeds,
        relocBeds: relocBeds,
        facilities: facilityLabels
      });

      if (!confirmed) return;

      const fd = new FormData();
      fd.append('_action', 'declare_emergency');
      fd.append('protocol_code', selectedProtocolCode);
      fd.append('quota_pct', currentQuotaPct);
      fd.append('target_scope', hospIds.length === 6 ? 'NETWORK_WIDE' : 'TARGETED');
      hospIds.forEach(id => fd.append('target_hospitals[]', id));
      fd.append('csrf_token', CSRF);

      try {
        const r = await fetch('../backend/api/emergency_surge_action.php', { method: 'POST', body: fd });
        const d = await r.json();

        if (d.success) {
          closeEmergencyModal();
          MedPulseDialog.toast({
            title: 'Disaster Surge Enforced',
            message: d.message,
            type: 'success',
            duration: 4000
          });
          setTimeout(() => location.reload(), 1400);
        } else {
          MedPulseDialog.toast({
            title: 'Surge Trigger Failed',
            message: d.message || 'Emergency surge trigger failed.',
            type: 'error',
            duration: 5000
          });
        }
      } catch (e) {
        MedPulseDialog.toast({
          title: 'Network Error',
          message: 'An error occurred while communicating with the emergency surge API.',
          type: 'error'
        });
      }
    }

    async function confirmStandDown(protoId = null, protoTitle = null) {
      const isSingle = !!protoId;
      const targetLabel = protoTitle || (isSingle ? `Protocol #${protoId}` : 'ALL ACTIVE PROTOCOLS');
      
      const confirmed = await MedPulseDialog.confirm({
        title: isSingle ? `Terminate Protocol: ${targetLabel}` : 'Terminate All Emergency Protocols',
        subtitle: isSingle ? 'Disaster Stand-Down & Bed Capacity Restoration' : 'Network-Wide Stand-Down & Normal Operations Restoration',
        type: 'warning',
        confirmText: isSingle ? 'Stand-Down Protocol' : 'Execute Stand-Down All',
        cancelText: 'Keep Active',
        html: `
          <div style="font-size:0.86rem;color:#334155;line-height:1.55;margin-bottom:14px;">
            ${isSingle 
              ? `You are about to terminate active disaster protocol <strong>${targetLabel}</strong> and restore its locked capacity.` 
              : 'You are about to terminate <strong>ALL</strong> active national emergency protocols across all facilities.'}
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-bottom:14px;">
            <ul style="font-size:0.8rem;color:#475569;line-height:1.6;margin:0;padding-left:18px;">
              <li><strong>Revert Holds:</strong> Unassigned Emergency Hold beds for this protocol immediately revert to <em>'Available'</em> for public discovery.</li>
              <li><strong>Clinical Sanitization:</strong> Active disaster treatment beds transition to <em>'Sanitizing'</em> for clinical decontamination.</li>
              <li><strong>Selective Dismissal:</strong> ${isSingle ? 'Broadcast alerts for this protocol will be cleared.' : 'All broadcast alert banners will be dismissed across all hospital censuses.'}</li>
            </ul>
          </div>
          <div style="font-size:0.78rem;color:#64748b;">
            Zero orphaned allocations will remain. Proceed with stand-down?
          </div>
        `
      });

      if (!confirmed) return;

      const fd = new FormData();
      fd.append('_action', 'terminate_emergency');
      if (protoId) {
        fd.append('protocol_id', protoId);
      }
      fd.append('csrf_token', CSRF);

      try {
        const r = await fetch('../backend/api/emergency_surge_action.php', { method: 'POST', body: fd });
        const d = await r.json();

        if (d.success) {
          MedPulseDialog.toast({
            title: 'Protocol Terminated',
            message: d.message,
            type: 'success',
            duration: 3500
          });
          setTimeout(() => location.reload(), 1400);
        } else {
          MedPulseDialog.toast({
            title: 'Stand-Down Error',
            message: d.message || 'Stand-down failed.',
            type: 'error',
            duration: 5000
          });
        }
      } catch (e) {
        MedPulseDialog.toast({
          title: 'Network Error',
          message: 'An error occurred while terminating the emergency protocol.',
          type: 'error'
        });
      }
    }

    // ── Bed Modal Details & Override ─────────────────────────────────────────
    async function openBedModal(bedId) {
      activeBedId = bedId;
      document.getElementById('bedModalBg').classList.add('open');
      document.getElementById('modalBedNum').textContent = 'Loading…';
      document.getElementById('modalBedSub').textContent = '';
      document.getElementById('modalDetails').innerHTML = '<p style="color:var(--text-muted);font-size:.82rem;padding:12px 0;">Fetching bed telemetry…</p>';

      try {
        const fd = new FormData();
        fd.append('_action', 'get_bed_detail');
        fd.append('bed_id', bedId);
        fd.append('csrf_token', CSRF);
        const r  = await fetch('bed_monitor.php', { method: 'POST', body: fd });
        const d  = await r.json();

        if (!d.success) { document.getElementById('modalDetails').innerHTML = `<p style="color:var(--status-red);">${d.message}</p>`; return; }

        const b = d.bed;
        document.getElementById('modalBedNum').textContent = b.bed_number;
        document.getElementById('modalBedSub').textContent = `${b.hospital_name} · Floor ${b.floor_number} · ${b.ward_type}`;

        const statusColor = b.status === 'Available' ? 'var(--status-green)' 
                          : b.status === 'Occupied'  ? 'var(--status-red)' 
                          : b.status === 'Emergency Hold' ? '#e11d48' 
                          : b.status === 'Sanitizing' ? '#0284c7'
                          : 'var(--status-amber)';

        const rows = [
          ['Status',        `<strong style="color:${statusColor};">${b.status}</strong>`],
          ['Relocation Flag', b.relocation_status === 'PENDING_RELOCATION' 
            ? '<strong style="color:#e11d48;">⚠️ PENDING RELOCATION</strong>' 
            : '<span style="color:var(--text-muted);">None</span>'],
          ['Daily Rate',    b.price_per_day ? `৳${Number(b.price_per_day).toLocaleString()}/day` : '—'],
          ['Hospital',      b.hospital_name],
          ['City',          b.city || '—'],
          ['Patient',       b.patient_name ? `${b.patient_name} (#P-${b.patient_id || ''})` : '<span style="color:var(--text-muted);">Vacant / Unoccupied</span>'],
        ];

        if (b.patient_name) {
          if (b.patient_phone) rows.push(['Patient Phone', b.patient_phone]);
          if (b.patient_email) rows.push(['Patient Email', b.patient_email]);
          rows.push(['Doctor', b.doctor_name || 'Unassigned']);
          if (b.doctor_email) rows.push(['Doctor Contact', b.doctor_email]);
          rows.push(['Admitted At', b.admitted_at ? new Date(b.admitted_at).toLocaleString('en-BD') : 'Active Care']);
        } else {
          rows.push(['Attending Doctor', b.doctor_name || '<span style="color:var(--text-muted);">None assigned</span>']);
        }

        if (b.reserved_until) {
          rows.push(['Reserved Until', new Date(b.reserved_until).toLocaleString('en-BD')]);
        }

        let html = rows.map(([k,v]) => `<div class="sa-detail-row"><span class="sa-detail-key">${k}</span><span class="sa-detail-val">${v}</span></div>`).join('');

        // Render Recent Activity / Audit Trail
        const audits = d.audits || [];
        if (audits.length > 0) {
          html += `<div class="sa-audit-box">
            <div class="sa-audit-title">📋 Recent Bed Activity &amp; Audit Trail</div>`;
          audits.forEach(a => {
            const dt = new Date(a.created_at).toLocaleString('en-BD', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
            html += `<div class="sa-audit-item">
              <div class="sa-audit-meta">
                <span><strong>${a.action || 'EVENT'}</strong> (${a.actor_role || 'SYSTEM'})</span>
                <span>${dt}</span>
              </div>
              <div>${a.description || 'Action performed.'}</div>
            </div>`;
          });
          html += `</div>`;
        }

        document.getElementById('modalDetails').innerHTML = html;

      } catch(e) {
        document.getElementById('modalDetails').innerHTML = '<p style="color:var(--status-red);">Network error while loading bed telemetry.</p>';
      }
    }

    function closeBedModal() {
      document.getElementById('bedModalBg').classList.remove('open');
      activeBedId = null;
    }

    document.getElementById('bedModalBg').addEventListener('click', e => {
      if (e.target === document.getElementById('bedModalBg')) closeBedModal();
    });

    async function forceOverride(newStatus) {
      if (!activeBedId) return;
      const bedNum = document.getElementById('modalBedNum').textContent;

      const confirmed = await MedPulseDialog.confirm({
        title: 'Force Bed Status Override',
        subtitle: `Bed ${bedNum} (ID #${activeBedId})`,
        type: newStatus === 'Emergency Hold' ? 'danger' : (newStatus === 'Maintenance' ? 'warning' : 'primary'),
        confirmText: `Set to ${newStatus}`,
        cancelText: 'Cancel',
        message: `Force transition Bed ${bedNum} to "${newStatus}"? This overrides standard clinical workflow and will be permanently recorded in the audit log.`
      });

      if (!confirmed) return;

      const fd = new FormData();
      fd.append('_action',    'override_status');
      fd.append('bed_id',     activeBedId);
      fd.append('new_status', newStatus);
      fd.append('csrf_token', CSRF);

      try {
        const r = await fetch('bed_monitor.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
          MedPulseDialog.toast({
            title: 'Status Updated',
            message: d.message,
            type: 'success',
            duration: 3000
          });
          closeBedModal();
          setTimeout(() => location.reload(), 1200);
        } else {
          MedPulseDialog.toast({
            title: 'Override Failed',
            message: d.message || 'Override failed.',
            type: 'error',
            duration: 4000
          });
        }
      } catch(e) {
        MedPulseDialog.toast({
          title: 'Network Error',
          message: 'Network error during bed override.',
          type: 'error'
        });
      }
    }
  </script>
  <!-- Dedicated MedPulse Modern Dialog & Toast Engine -->
  <script src="../assets/js/medpulse_dialog.js"></script>
</body>
</html>

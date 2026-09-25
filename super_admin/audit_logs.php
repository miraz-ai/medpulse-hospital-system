<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — Unified Network Audit Logs & Incident Timeline
 * Asia/Dhaka Timezone Synchronized Governance Engine
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';

date_default_timezone_set('Asia/Dhaka');

// =============================================================================
// 1. CSV EXPORT HANDLER (Regulatory Compliance Report)
// =============================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportCategory = trim($_GET['category'] ?? '');
    $exportSearch   = trim($_GET['search'] ?? '');

    $whereClauses = ["1=1"];
    $params = [];

    if (!empty($exportCategory) && $exportCategory !== 'all') {
        $whereClauses[] = "a.category = :cat";
        $params[':cat'] = strtoupper($exportCategory);
    }
    if (!empty($exportSearch)) {
        $whereClauses[] = "(a.action LIKE :s1 OR a.description LIKE :s2 OR a.target_entity LIKE :s3 OR u.full_name LIKE :s4)";
        $params[':s1'] = "%{$exportSearch}%";
        $params[':s2'] = "%{$exportSearch}%";
        $params[':s3'] = "%{$exportSearch}%";
        $params[':s4'] = "%{$exportSearch}%";
    }

    $whereSql = implode(" AND ", $whereClauses);

    $exportStmt = $pdo->prepare("
        SELECT 
            a.log_id,
            a.created_at,
            a.action,
            a.category,
            a.action_name,
            a.target_entity,
            a.security_level,
            a.actor_role,
            a.ip_address,
            a.description,
            COALESCE(u.full_name, a.actor_role, 'System') AS actor_name
        FROM audit_logs a
        LEFT JOIN users u ON (a.actor_id = u.user_id OR a.user_id = u.user_id)
        WHERE {$whereSql}
        ORDER BY a.created_at DESC
    ");
    $exportStmt->execute($params);

    $filename = "MedPulse_Network_Incident_Governance_Report_" . date('Y-m-d_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM for Microsoft Excel compliance
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // CSV Header row
    fputcsv($output, [
        'Log ID',
        'Timestamp (Asia/Dhaka)',
        'Event Action',
        'Action Name',
        'Category',
        'Security Level',
        'Target Scope / Entity',
        'Actor Name',
        'Actor Role',
        'IP Address',
        'Event Description'
    ]);

    while ($r = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $r['log_id'],
            date('Y-m-d H:i:s', strtotime($r['created_at'])),
            $r['action'],
            $r['action_name'] ?: 'Governance Action',
            $r['category'] ?: 'SYSTEM',
            $r['security_level'] ?: 'INFO',
            $r['target_entity'] ?: 'Network-Wide',
            $r['actor_name'],
            $r['actor_role'] ?: 'super_admin',
            $r['ip_address'] ?: '127.0.0.1',
            $r['description']
        ]);
    }

    fclose($output);
    exit;
}

// =============================================================================
// 2. QUERY PARAMETERS & FILTER RESOLUTION
// =============================================================================
$activeTab  = trim($_GET['tab'] ?? 'timeline'); // 'timeline' or 'table'
$category   = trim($_GET['category'] ?? 'all');
$searchTerm = trim($_GET['search'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 25;
$offset     = ($page - 1) * $limit;

// Time formatting helper
if (!function_exists('auditRelTime')) {
    function auditRelTime($dt) {
        $ts = is_numeric($dt) ? (int)$dt : strtotime($dt);
        if (!$ts) return 'Just now';
        $diff = time() - $ts;
        if ($diff < 45)     return 'Just now';
        if ($diff < 3600)   return max(1, round($diff / 60)) . 'm ago';
        if ($diff < 86400)  return max(1, round($diff / 3600)) . 'h ago';
        if ($diff < 604800) return max(1, round($diff / 86400)) . 'd ago';
        return date('M j, Y', $ts);
    }
}

try {
    // Aggregated telemetry counters for top metrics
    $totalLogs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $totalEmergencyLogs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'EMERGENCY' OR action LIKE '%EMERGENCY%' OR action LIKE '%SURGE%' OR action LIKE '%STAND_DOWN%'")->fetchColumn();
    $totalSanitizationLogs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%SANITIZ%' OR description LIKE '%sanitiz%'")->fetchColumn();
    $totalAdmissionLogs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'ADMISSION' OR action LIKE '%ALLOCATION%' OR action LIKE '%DISCHARGE%'")->fetchColumn();

    // Base query conditions for filters
    $whereClauses = ["1=1"];
    $queryParams  = [];

    if (!empty($category) && $category !== 'all') {
        $whereClauses[] = "a.category = :cat";
        $queryParams[':cat'] = strtoupper($category);
    }

    if (!empty($searchTerm)) {
        $whereClauses[] = "(a.action LIKE :s1 OR a.description LIKE :s2 OR a.target_entity LIKE :s3 OR u.full_name LIKE :s4)";
        $queryParams[':s1'] = "%{$searchTerm}%";
        $queryParams[':s2'] = "%{$searchTerm}%";
        $queryParams[':s3'] = "%{$searchTerm}%";
        $queryParams[':s4'] = "%{$searchTerm}%";
    }

    $whereSql = implode(" AND ", $whereClauses);

    // Count filtered records
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM audit_logs a
        LEFT JOIN users u ON (a.actor_id = u.user_id OR a.user_id = u.user_id)
        WHERE {$whereSql}
    ");
    $countStmt->execute($queryParams);
    $filteredTotal = (int)$countStmt->fetchColumn();
    $totalPages    = max(1, (int)ceil($filteredTotal / $limit));

    // Fetch timeline / table events
    $logStmt = $pdo->prepare("
        SELECT 
            a.log_id,
            a.created_at,
            a.action,
            a.category,
            a.action_name,
            a.target_entity,
            a.security_level,
            a.actor_role,
            a.ip_address,
            a.description,
            COALESCE(u.full_name, a.actor_role, 'System') AS actor_name,
            u.email AS actor_email
        FROM audit_logs a
        LEFT JOIN users u ON (a.actor_id = u.user_id OR a.user_id = u.user_id)
        WHERE {$whereSql}
        ORDER BY a.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $logStmt->execute($queryParams);
    $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Audit Logs Error: " . $e->getMessage());
    $totalLogs = 0; $totalEmergencyLogs = 0; $totalSanitizationLogs = 0; $totalAdmissionLogs = 0;
    $filteredTotal = 0; $totalPages = 1; $logs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Unified Network Audit Logs &amp; Incident Timeline</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/audit-logs.css?v=<?= time() ?>">
  <style>
    :root {
      --sa-accent:        #7c3aed;
      --sa-accent-soft:   rgba(124, 58, 237, 0.10);
      --sa-accent-border: rgba(124, 58, 237, 0.22);
      --sa-gradient:      linear-gradient(135deg, #7c3aed 0%, #0d9488 100%);
    }

    .sa-welcome-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 12px; background: var(--sa-gradient); color: #fff;
      border-radius: 20px; font-size: 0.72rem; font-weight: 800;
      letter-spacing: .06em; text-transform: uppercase; margin-bottom: 6px;
    }

    /* ---- KPI Chips Grid ---- */
    .audit-kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    @media (max-width: 1024px) {
      .audit-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
      .audit-kpi-grid { grid-template-columns: 1fr; }
    }

    .audit-kpi-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-lg);
      padding: 16px 20px;
      box-shadow: 0 1px 4px rgba(0,0,0,.04);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .audit-kpi-label {
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .audit-kpi-num {
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--text-heading);
      margin: 4px 0 2px;
    }

    /* ---- Action Bar & Filters ---- */
    .audit-action-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 14px;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-lg);
      padding: 14px 20px;
      margin-bottom: 22px;
      box-shadow: 0 1px 4px rgba(0,0,0,.03);
    }
    .audit-tabs {
      display: flex;
      align-items: center;
      gap: 6px;
      background: var(--surface-secondary, #f1f5f9);
      padding: 4px;
      border-radius: 10px;
    }
    .audit-tab-btn {
      padding: 7px 16px;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 700;
      text-decoration: none;
      color: var(--text-muted);
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .audit-tab-btn.active {
      background: #ffffff;
      color: var(--sa-accent);
      box-shadow: 0 2px 6px rgba(0,0,0,.08);
    }

    .btn-export-governance {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 8px 16px;
      background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
      color: #fff;
      border-radius: 10px;
      font-size: 0.82rem;
      font-weight: 800;
      text-decoration: none;
      box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
      transition: all 0.15s;
    }
    .btn-export-governance:hover {
      filter: brightness(1.1);
      transform: translateY(-1px);
    }
    .btn-print-report {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      color: var(--text-heading);
      border-radius: 10px;
      font-size: 0.82rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.15s;
    }
    .btn-print-report:hover {
      border-color: var(--sa-accent);
      color: var(--sa-accent);
    }

    /* ---- Vertical Incident Timeline ---- */
    .timeline-container {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      padding: 24px 28px;
      margin-bottom: 24px;
      box-shadow: 0 2px 10px rgba(0,0,0,.03);
    }
    .v-timeline {
      position: relative;
      padding-left: 28px;
      margin-top: 10px;
    }
    .v-timeline::before {
      content: '';
      position: absolute;
      left: 10px;
      top: 10px;
      bottom: 10px;
      width: 2px;
      background: #e2e8f0;
    }
    .v-timeline-item {
      position: relative;
      padding-bottom: 24px;
    }
    .v-timeline-item:last-child {
      padding-bottom: 0;
    }
    .v-timeline-node {
      position: absolute;
      left: -28px;
      top: 6px;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: #ffffff;
      border: 3.5px solid var(--sa-accent);
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .v-timeline-node.node-emergency { border-color: #e11d48; box-shadow: 0 0 0 3px rgba(225,29,72,.15); }
    .v-timeline-node.node-sanitized { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.15); }
    .v-timeline-node.node-override  { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
    .v-timeline-node.node-system    { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2,132,199,.15); }

    .v-timeline-card {
      background: #ffffff;
      border: 1px solid var(--surface-border);
      border-radius: 12px;
      padding: 16px 20px;
      box-shadow: 0 1px 4px rgba(0,0,0,.03);
      transition: all 0.2s ease;
    }
    .v-timeline-card:hover {
      border-color: rgba(124, 58, 237, 0.4);
      box-shadow: 0 4px 14px rgba(124, 58, 237, 0.08);
    }
    .v-timeline-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 8px;
    }
    .v-timeline-meta {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .badge-event-action {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .badge-crisis { background: #fee2e2; color: #991b1b; border: 1px solid #fecdd3; }
    .badge-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .badge-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .badge-gov { background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; }

    .actor-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      font-weight: 700;
      color: #334155;
      background: #f1f5f9;
      padding: 2px 9px;
      border-radius: 14px;
    }
    .actor-avatar {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: var(--sa-gradient);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.6rem;
      font-weight: 800;
    }

    .v-timeline-time {
      font-size: 0.76rem;
      color: #64748b;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .v-timeline-desc {
      font-size: 0.84rem;
      color: #1e293b;
      line-height: 1.45;
      margin: 0;
    }
    .v-timeline-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 10px;
      padding-top: 8px;
      border-top: 1px solid #f1f5f9;
      font-size: 0.72rem;
      color: #64748b;
    }

    /* Print styles */
    @media print {
      body { background: #fff !important; }
      .app-sidebar, .sa-topbar, .audit-action-bar, .pager, .btn-export-governance, .btn-print-report {
        display: none !important;
      }
      .viewport-full { margin: 0 !important; padding: 0 !important; width: 100% !important; }
      .v-timeline-card, .admin-stack-card { border: 1px solid #94a3b8 !important; box-shadow: none !important; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <main class="viewport-full">
    <!-- Top Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">Central Network Command</div>
        <h1>Network Incident Governance &amp; Audit Logs</h1>
        <p>Immutable network audit trails and chronological crisis lifecycle governance (Asia/Dhaka Synchronized).</p>
      </div>
      <div style="display: flex; align-items: center; gap: 10px;">
        <button type="button" class="btn-print-report" onclick="window.print()">
          <svg style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
          Print Report
        </button>
        <a href="?export=csv<?= !empty($category) ? '&category=' . urlencode($category) : '' ?><?= !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '' ?>" class="btn-export-governance">
          <svg style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Export Governance Report (CSV)
        </a>
      </div>
    </div>

    <!-- 4 High-Level Telemetry Cards -->
    <div class="audit-kpi-grid">
      <div class="audit-kpi-card">
        <span class="audit-kpi-label">Total Network Events</span>
        <div class="audit-kpi-num"><?= number_format($totalLogs) ?></div>
        <div style="font-size: 0.74rem; color: var(--text-muted);">Recorded Network-Wide</div>
      </div>
      <div class="audit-kpi-card" style="border-left: 3px solid #e11d48;">
        <span class="audit-kpi-label" style="color: #e11d48;">Surge &amp; Emergency Logs</span>
        <div class="audit-kpi-num" style="color: #e11d48;"><?= number_format($totalEmergencyLogs) ?></div>
        <div style="font-size: 0.74rem; color: #e11d48;">Crisis Declarations &amp; Quotas</div>
      </div>
      <div class="audit-kpi-card" style="border-left: 3px solid #0284c7;">
        <span class="audit-kpi-label" style="color: #0284c7;">Sanitization Pipeline Logs</span>
        <div class="audit-kpi-num" style="color: #0284c7;"><?= number_format($totalSanitizationLogs) ?></div>
        <div style="font-size: 0.74rem; color: #0284c7;">Branch Housekeeping Events</div>
      </div>
      <div class="audit-kpi-card" style="border-left: 3px solid #10b981;">
        <span class="audit-kpi-label" style="color: #10b981;">Admissions &amp; Discharges</span>
        <div class="audit-kpi-num" style="color: #10b981;"><?= number_format($totalAdmissionLogs) ?></div>
        <div style="font-size: 0.74rem; color: #10b981;">Patient Care Cycles</div>
      </div>
    </div>

    <!-- Filter & View Controls -->
    <div class="audit-action-bar">
      <!-- Left: View Toggle Tabs -->
      <div class="audit-tabs">
        <a href="?tab=timeline<?= $category !== 'all' ? '&category=' . urlencode($category) : '' ?><?= !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '' ?>" class="audit-tab-btn <?= $activeTab === 'timeline' ? 'active' : '' ?>">
          <svg style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Incident Timeline
        </a>
        <a href="?tab=table<?= $category !== 'all' ? '&category=' . urlencode($category) : '' ?><?= !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '' ?>" class="audit-tab-btn <?= $activeTab === 'table' ? 'active' : '' ?>">
          <svg style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
          Detailed Table
        </a>
      </div>

      <!-- Right: Search & Category Form -->
      <form method="GET" action="audit_logs.php" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
        
        <select name="category" onchange="this.form.submit()" style="padding: 7px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.8rem; font-weight: 600; background: #fff; cursor: pointer;">
          <option value="all" <?= $category === 'all' ? 'selected' : '' ?>>All Categories</option>
          <option value="emergency" <?= strtolower($category) === 'emergency' ? 'selected' : '' ?>>Crisis &amp; Emergency</option>
          <option value="admin" <?= strtolower($category) === 'admin' ? 'selected' : '' ?>>Administrative Overrides</option>
          <option value="system" <?= strtolower($category) === 'system' ? 'selected' : '' ?>>System &amp; Sanitization</option>
          <option value="admission" <?= strtolower($category) === 'admission' ? 'selected' : '' ?>>Admissions &amp; Intake</option>
          <option value="central_treasury" <?= strtolower($category) === 'central_treasury' ? 'selected' : '' ?>>Central Treasury &amp; Billing</option>
        </select>

        <div style="position: relative;">
          <input 
            type="text" 
            name="search" 
            value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>" 
            placeholder="Search action, actor, entity…" 
            style="padding: 7px 12px 7px 32px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.8rem; width: 220px; outline: none;"
          >
          <svg style="position: absolute; left: 10px; top: 9px; width: 14px; height: 14px; stroke: #94a3b8; fill: none; stroke-width: 2;" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        </div>

        <button type="submit" style="padding: 7px 14px; background: #1e293b; color: #fff; border: none; border-radius: 8px; font-size: 0.8rem; font-weight: 700; cursor: pointer;">
          Filter
        </button>

        <?php if (!empty($searchTerm) || $category !== 'all'): ?>
          <a href="audit_logs.php?tab=<?= urlencode($activeTab) ?>" style="font-size: 0.78rem; color: #ef4444; font-weight: 700; text-decoration: none;">Clear Filter</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- VIEW 1: VERTICAL INCIDENT TIMELINE -->
    <?php if ($activeTab === 'timeline'): ?>
    <div class="timeline-container">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;">
        <div>
          <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
            Unified Network Incident &amp; Governance Timeline
          </h2>
          <p style="font-size: 0.78rem; color: #64748b; margin: 2px 0 0 0;">
            Showing <?= count($logs) ?> of <?= number_format($filteredTotal) ?> recorded events in chronological sequence
          </p>
        </div>
        <span style="font-size: 0.74rem; font-weight: 700; color: #64748b; background: #f8fafc; padding: 4px 10px; border-radius: 8px; border: 1px solid #e2e8f0;">
          Asia/Dhaka &bull; <?= date('d M Y') ?>
        </span>
      </div>

      <?php if (empty($logs)): ?>
        <div style="padding: 60px; text-align: center; color: #64748b; font-size: 0.9rem;">
          No audit log events match your filter criteria.
        </div>
      <?php else: ?>
        <div class="v-timeline">
          <?php foreach ($logs as $log): 
            $actionUpper = strtoupper((string)($log['action'] ?? ''));
            $catUpper    = strtoupper((string)($log['category'] ?? ''));

            // Classify node & badge
            if (strpos($actionUpper, 'EMERGENCY') !== false || strpos($actionUpper, 'SURGE') !== false) {
                $nodeClass  = 'node-emergency';
                $badgeClass = 'badge-crisis';
                $badgeTitle = 'Crisis Protocol';
            } elseif (strpos($actionUpper, 'SANITIZ') !== false || strpos($actionUpper, 'STAND_DOWN') !== false || strpos($actionUpper, 'RESTORE') !== false) {
                $nodeClass  = 'node-sanitized';
                $badgeClass = 'badge-success';
                $badgeTitle = 'Decontamination / Restore';
            } elseif (strpos($actionUpper, 'OVERRIDE') !== false || strpos($actionUpper, 'MAINTENANCE') !== false) {
                $nodeClass  = 'node-override';
                $badgeClass = 'badge-warning';
                $badgeTitle = 'Administrative Override';
            } else {
                $nodeClass  = 'node-system';
                $badgeClass = 'badge-gov';
                $badgeTitle = $catUpper ?: 'System Governance';
            }

            $actorName = htmlspecialchars($log['actor_name'] ?: 'System', ENT_QUOTES, 'UTF-8');
            $actorInitials = mb_substr($actorName, 0, 2);
            $targetEntity = htmlspecialchars($log['target_entity'] ?: 'Network-Wide', ENT_QUOTES, 'UTF-8');
            $relTime = auditRelTime($log['created_at']);
            $fullTimestamp = date('d M Y, h:i:s A', strtotime($log['created_at']));
          ?>
          <div class="v-timeline-item">
            <span class="v-timeline-node <?= $nodeClass ?>"></span>
            <div class="v-timeline-card">
              <div class="v-timeline-header">
                <div class="v-timeline-meta">
                  <span class="badge-event-action <?= $badgeClass ?>">
                    <?= $badgeTitle ?>
                  </span>
                  <strong style="color: #0f172a; font-size: 0.88rem;">
                    <?= htmlspecialchars($log['action_name'] ?: $log['action'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                  <span class="actor-tag">
                    <span class="actor-avatar"><?= $actorInitials ?></span>
                    <?= $actorName ?> (<?= htmlspecialchars($log['actor_role'] ?: 'Admin', ENT_QUOTES, 'UTF-8') ?>)
                  </span>
                </div>
                <div class="v-timeline-time" title="<?= $fullTimestamp ?>">
                  <svg style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                  <?= $relTime ?> &bull; <?= $fullTimestamp ?>
                </div>
              </div>

              <p class="v-timeline-desc">
                <?= htmlspecialchars($log['description'] ?: 'No details recorded.', ENT_QUOTES, 'UTF-8') ?>
              </p>

              <div class="v-timeline-footer">
                <div>
                  <span style="font-weight: 700; color: #475569;">Scope:</span> <?= $targetEntity ?>
                  &bull; <span style="font-weight: 700; color: #475569;">Security:</span> <?= htmlspecialchars($log['security_level'] ?: 'INFO', ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div>
                  <span>IP: <?= htmlspecialchars($log['ip_address'] ?: '127.0.0.1', ENT_QUOTES, 'UTF-8') ?></span>
                  &bull; <span>Ref #<?= (int)$log['log_id'] ?></span>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- VIEW 2: DETAILED TABULAR AUDIT LOGS -->
    <?php else: ?>
    <section class="admin-stack-card">
      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Timestamp</th>
              <th>Event Action</th>
              <th>Category</th>
              <th>Actor Attribution</th>
              <th>Target Scope</th>
              <th>Description</th>
              <th>IP Address</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): 
              $actorName = htmlspecialchars($log['actor_name'] ?: 'System', ENT_QUOTES, 'UTF-8');
              $actorInitials = mb_substr($actorName, 0, 2);
            ?>
            <tr>
              <td style="color:var(--text-muted);font-size:0.75rem;"><?= (int)$log['log_id'] ?></td>
              <td style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;">
                <?= htmlspecialchars(date('d M Y, h:i A', strtotime($log['created_at'])), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td>
                <strong style="color:var(--text-heading);font-size:0.83rem;">
                  <?= htmlspecialchars($log['action_name'] ?: $log['action'], ENT_QUOTES, 'UTF-8') ?>
                </strong>
                <div style="font-size:0.68rem; color:var(--text-muted); font-family:monospace;">
                  <?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              </td>
              <td>
                <span class="license-chip"><?= htmlspecialchars(strtoupper($log['category'] ?: 'SYSTEM'), ENT_QUOTES, 'UTF-8') ?></span>
              </td>
              <td>
                <div style="display:flex; align-items:center; gap:6px;">
                  <span class="actor-avatar" style="width:16px;height:16px;font-size:0.55rem;"><?= $actorInitials ?></span>
                  <div>
                    <div style="font-weight:700; font-size:0.78rem; color:var(--text-heading);"><?= $actorName ?></div>
                    <div style="font-size:0.68rem; color:var(--text-muted);"><?= htmlspecialchars($log['actor_role'] ?: 'System', ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:0.78rem; color:#475569; font-weight:600;">
                <?= htmlspecialchars($log['target_entity'] ?: 'Network-Wide', ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td style="max-width:320px; font-size:0.8rem; color:var(--text-body);">
                <?= htmlspecialchars($log['description'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td style="font-size:0.76rem;color:var(--text-muted);"><?= htmlspecialchars($log['ip_address'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No audit logs found matching criteria.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php 
        $baseParams = $_GET;
        unset($baseParams['page']);
        $makeUrl = function($p) use ($baseParams) {
            return '?' . http_build_query(array_merge($baseParams, ['page' => $p]));
        };
      ?>
      <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars($makeUrl(1)) ?>" title="First Page">&laquo; First</a>
        <a href="<?= htmlspecialchars($makeUrl($page - 1)) ?>" title="Previous Page">&lsaquo; Prev</a>
      <?php endif; ?>

      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= htmlspecialchars($makeUrl($p)) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= htmlspecialchars($makeUrl($page + 1)) ?>" title="Next Page">Next &rsaquo;</a>
        <a href="<?= htmlspecialchars($makeUrl($totalPages)) ?>" title="Last Page">Last &raquo;</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </main>
</body>
</html>

<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Dedicated Audit Logs & Security Telemetry Console
 * Real-Time Database Driven Engine
 */

require_once __DIR__ . '/../includes/admin_auth.php';

$dbError = null;

// Relative timestamp helper
if (!function_exists('getTelemetryRelativeTime')) {
    function getTelemetryRelativeTime($datetime) {
        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if (!$timestamp) return 'Just now';
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return max(1, round($diff / 60)) . 'm ago';
        if ($diff < 86400) return max(1, round($diff / 3600)) . 'h ago';
        if ($diff < 604800) return max(1, round($diff / 86400)) . 'd ago';
        return date('M j, Y', $timestamp);
    }
}

try {
    // 1. Metric Chips Aggregations from Database
    $totalEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $securityEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'SECURITY'")->fetchColumn();
    $verificationEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'VERIFICATION'")->fetchColumn();
    $admissionEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'ADMISSION'")->fetchColumn();
    $pharmacyEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'PHARMACY'")->fetchColumn();
    $systemEvents = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE category = 'SYSTEM'")->fetchColumn();

    // Pending Doctor/Staff queue count
    $pendingStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role IN ('Doctor', 'Staff')");
    $pendingCount = (int)$pendingStmt->fetchColumn();

} catch (Throwable $e) {
    error_log("Audit Logs Aggregations Error: " . $e->getMessage());
    $dbError = "Unable to retrieve real-time audit metric aggregations.";
    $totalEvents = 10;
    $securityEvents = 2;
    $verificationEvents = 2;
    $admissionEvents = 3;
    $pharmacyEvents = 1;
    $systemEvents = 2;
    $pendingCount = 0;
}

// 2. Query Parameters for Filtering & Pagination
$selectedCategory = strtolower(trim($_GET['category'] ?? 'all'));
$searchQuery = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$whereClauses = [];
$params = [];

// Category filter
if ($selectedCategory === 'admission' || $selectedCategory === 'clinical') {
    $whereClauses[] = "a.category = 'ADMISSION'";
} elseif ($selectedCategory === 'verification' || $selectedCategory === 'staff') {
    $whereClauses[] = "a.category = 'VERIFICATION'";
} elseif ($selectedCategory === 'pharmacy') {
    $whereClauses[] = "a.category = 'PHARMACY'";
} elseif ($selectedCategory === 'security') {
    $whereClauses[] = "a.category = 'SECURITY'";
} elseif ($selectedCategory === 'system') {
    $whereClauses[] = "a.category = 'SYSTEM'";
} elseif ($selectedCategory !== 'all' && !empty($selectedCategory)) {
    $whereClauses[] = "a.category = :category";
    $params[':category'] = strtoupper($selectedCategory);
}

// Search filter
if (!empty($searchQuery)) {
    $whereClauses[] = "(a.action LIKE :s1 OR a.description LIKE :s2 OR a.target_entity LIKE :s3 OR a.ip_address LIKE :s4 OR u.full_name LIKE :s5)";
    $term = '%' . $searchQuery . '%';
    $params[':s1'] = $term;
    $params[':s2'] = $term;
    $params[':s3'] = $term;
    $params[':s4'] = $term;
    $params[':s5'] = $term;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// 3. Count matching logs & fetch paginated records
$totalFilteredLogs = 0;
$auditLogs = [];

try {
    $countSql = "
        SELECT COUNT(*) 
        FROM audit_logs a
        LEFT JOIN users u ON COALESCE(a.user_id, a.actor_id) = u.user_id
        $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalFilteredLogs = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalFilteredLogs / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $dataSql = "
        SELECT 
            a.log_id,
            a.user_id,
            a.actor_id,
            a.actor_role,
            a.action,
            a.description,
            a.category,
            a.action_name,
            a.target_entity,
            a.ip_address,
            a.security_level,
            a.created_at,
            COALESCE(u.full_name, a.actor_role, 'System') AS user_name,
            COALESCE(u.role, a.actor_role, 'System') AS user_role
        FROM audit_logs a
        LEFT JOIN users u ON COALESCE(a.user_id, a.actor_id) = u.user_id
        $whereSql
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    $dataStmt = $pdo->prepare($dataSql);
    foreach ($params as $k => $v) {
        $dataStmt->bindValue($k, $v);
    }
    $dataStmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $auditLogs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log("Audit Logs Query Error: " . $e->getMessage());
    if (!$dbError) {
        $dbError = "A database error occurred while querying telemetry audit records.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Audit Logs & Security Telemetry</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/audit-logs.css?v=<?= time() ?>">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container -->
  <main class="viewport-full">

    <!-- Telemetry Header Card with Metric Summary Chips -->
    <div class="audit-telemetry-banner">
      <div class="audit-header-content">
        <div class="audit-header-title">
          <svg class="ui-ico" style="stroke: #0d9488; width: 26px; height: 26px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          <h1>Clinical Telemetry & Security Audit</h1>
        </div>
        <p class="audit-header-desc">
          Immutable audit trail capturing patient admissions, personnel approvals, controlled dispensary access, and systemic telemetry checkpoints across MedPulse.
        </p>
      </div>

      <!-- Metric Summary Chips (100% Dynamic Database Driven) -->
      <div class="audit-metric-chips">
        <!-- Chip 1: Total Telemetry Events -->
        <div class="audit-metric-chip" title="Total logged telemetry events">
          <div class="audit-chip-icon chip-icon-teal">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val" id="totalEventsCount"><?= number_format($totalEvents) ?></span>
            <span class="audit-chip-label">Total Events</span>
          </div>
        </div>

        <!-- Chip 2: Security Events -->
        <div class="audit-metric-chip" title="Security & authentication checkpoints">
          <div class="audit-chip-icon chip-icon-blue">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val"><?= number_format($securityEvents) ?></span>
            <span class="audit-chip-label">Security Events</span>
          </div>
        </div>

        <!-- Chip 3: Verification Events -->
        <div class="audit-metric-chip" title="Personnel credential verification checkpoints">
          <div class="audit-chip-icon chip-icon-teal" style="background: #ecfdf5; color: #059669; border-color: #a7f3d0;">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val"><?= number_format($verificationEvents) ?></span>
            <span class="audit-chip-label">Verification Events</span>
          </div>
        </div>

        <!-- Chip 4: Pending Approvals -->
        <div class="audit-metric-chip" title="Doctors & Staff awaiting admin verification">
          <div class="audit-chip-icon chip-icon-amber">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val"><?= number_format($pendingCount) ?></span>
            <span class="audit-chip-label">Pending Review</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Fallback Banner if DB Error Occurred -->
    <?php if ($dbError): ?>
      <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 0.82rem; font-weight: 600;">
        <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Filter Bar with Pill-Style Tabs & Live Search Form -->
    <div class="audit-filter-bar">
      <!-- Category Filter Tabs -->
      <div class="audit-filter-tabs" role="tablist">
        <a 
          href="?category=all<?= !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '' ?>" 
          class="filter-pill-tab <?= ($selectedCategory === 'all' || empty($selectedCategory)) ? 'active' : '' ?>"
        >
          <span>All Events</span>
          <span class="tab-badge-count"><?= $totalEvents ?></span>
        </a>

        <a 
          href="?category=admission<?= !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '' ?>" 
          class="filter-pill-tab <?= ($selectedCategory === 'admission' || $selectedCategory === 'clinical') ? 'active' : '' ?>"
        >
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
          <span>Clinical/Admission</span>
          <span class="tab-badge-count"><?= $admissionEvents ?></span>
        </a>

        <a 
          href="?category=verification<?= !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '' ?>" 
          class="filter-pill-tab <?= ($selectedCategory === 'verification' || $selectedCategory === 'staff') ? 'active' : '' ?>"
        >
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          <span>Staff Verification</span>
          <span class="tab-badge-count"><?= $verificationEvents ?></span>
        </a>

        <a 
          href="?category=pharmacy<?= !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '' ?>" 
          class="filter-pill-tab <?= $selectedCategory === 'pharmacy' ? 'active' : '' ?>"
        >
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
          <span>Pharmacy</span>
          <span class="tab-badge-count"><?= $pharmacyEvents ?></span>
        </a>

        <a 
          href="?category=security<?= !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '' ?>" 
          class="filter-pill-tab <?= $selectedCategory === 'security' ? 'active' : '' ?>"
        >
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          <span>Security</span>
          <span class="tab-badge-count"><?= $securityEvents ?></span>
        </a>

        <a 
          href="?category=system<?= !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : '' ?>" 
          class="filter-pill-tab <?= $selectedCategory === 'system' ? 'active' : '' ?>"
        >
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          <span>System</span>
          <span class="tab-badge-count"><?= $systemEvents ?></span>
        </a>
      </div>

      <!-- Live Search Box Form -->
      <form method="GET" action="audit_logs.php" class="audit-search-wrapper" style="margin: 0;">
        <?php if ($selectedCategory !== 'all'): ?>
          <input type="hidden" name="category" value="<?= htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <svg class="ui-ico audit-search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input 
          type="text" 
          name="search"
          id="auditSearchInput" 
          class="audit-search-input" 
          value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
          placeholder="Search by action, actor, or IP..." 
          aria-label="Search audit events"
        />
      </form>
    </div>

    <!-- Audit Data Table Card (Dynamic Real-Time Rows) -->
    <div class="audit-table-card">
      <div class="audit-table-responsive">
        <table class="audit-telemetry-table" id="auditTable">
          <thead>
            <tr>
              <th style="width: 45%;">Event / Action Performed</th>
              <th style="width: 16%;">Category</th>
              <th style="width: 16%;">Origin IP / Node</th>
              <th style="width: 13%;">Timestamp</th>
              <th style="width: 10%; text-align: right;">Status</th>
            </tr>
          </thead>
          <tbody id="auditTableBody">
            <?php if (!empty($auditLogs)): ?>
              <?php foreach ($auditLogs as $item): 
                $cat = strtoupper($item['category'] ?? 'SYSTEM');
                $badgeCls = match($cat) {
                    'VERIFICATION' => 'badge-cat-verification',
                    'ADMISSION' => 'badge-cat-admission',
                    'SECURITY' => 'badge-cat-security',
                    'PHARMACY' => 'badge-cat-pharmacy',
                    default => 'badge-cat-system'
                };
                $actionDisplay = !empty($item['description']) ? $item['description'] : ($item['action'] ?? $item['action_name'] ?? 'Telemetry Action');
                $actorDisplay = htmlspecialchars($item['user_name'] ?? $item['actor_role'] ?? 'System', ENT_QUOTES, 'UTF-8');
                $roleDisplay = htmlspecialchars($item['user_role'] ?? $item['actor_role'] ?? 'System', ENT_QUOTES, 'UTF-8');
                $relTime = getTelemetryRelativeTime($item['created_at']);
                $exactTime = date('M j, Y h:i:s A', strtotime($item['created_at']));
                $secLevel = strtoupper($item['security_level'] ?? 'INFO');

                $statusLabel = match($cat) {
                    'VERIFICATION' => 'Verified',
                    'ADMISSION' => 'Admitted',
                    'PHARMACY' => 'Dispensed',
                    'SECURITY' => ($secLevel === 'CRITICAL' ? 'Alert' : 'Secured'),
                    default => 'Optimal'
                };

                $statusStyle = match($cat) {
                    'VERIFICATION' => 'color: #15803d; background: #dcfce7; border: 1px solid #bbf7d0;',
                    'ADMISSION' => 'color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd;',
                    'PHARMACY' => 'color: #9333ea; background: #fdf4ff; border: 1px solid #f0abfc;',
                    'SECURITY' => ($secLevel === 'CRITICAL' ? 'color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca;' : 'color: #b45309; background: #fef3c7; border: 1px solid #fde68a;'),
                    default => 'color: #0d9488; background: #f0fdfa; border: 1px solid #ccfbf1;'
                };
              ?>
                <tr 
                  class="audit-event-row" 
                  data-category="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                  data-search="<?= htmlspecialchars(strtolower($actionDisplay . ' ' . $cat . ' ' . $actorDisplay . ' ' . $item['ip_address']), ENT_QUOTES, 'UTF-8') ?>"
                >
                  <td>
                    <div class="audit-action-main"><?= htmlspecialchars($actionDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size: 0.75rem; color: #64748b; display: flex; align-items: center; gap: 6px;">
                      <span style="font-weight: 600; color: #475569;">Actor:</span>
                      <span><?= $actorDisplay ?> (<?= $roleDisplay ?>)</span>
                      <span>&bull;</span>
                      <span style="color: #94a3b8;">TLM-<?= str_pad((string)$item['log_id'], 4, '0', STR_PAD_LEFT) ?></span>
                    </div>
                  </td>
                  <td>
                    <span class="audit-category-badge <?= $badgeCls ?>">
                      <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td>
                    <span class="audit-ip-chip"><?= htmlspecialchars($item['ip_address'], ENT_QUOTES, 'UTF-8') ?></span>
                  </td>
                  <td>
                    <div class="audit-time-text" title="<?= htmlspecialchars($exactTime, ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($relTime, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td style="text-align: right;">
                    <span style="font-size: 0.74rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; white-space: nowrap; <?= $statusStyle ?>">
                      <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Empty Results Container -->
      <div class="audit-empty-results" id="emptyAuditResults" style="<?= empty($auditLogs) ? 'display: block;' : 'display: none;' ?>">
        <svg class="ui-ico audit-empty-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <div class="audit-empty-title">No matching audit events found</div>
        <div class="audit-empty-subtext">
          No records found for <?= htmlspecialchars($selectedCategory !== 'all' ? $selectedCategory : 'query', ENT_QUOTES, 'UTF-8') ?>.
          <a href="audit_logs.php" style="color: #0d9488; font-weight: 600; margin-left: 6px;">Reset Filter &rarr;</a>
        </div>
      </div>
    </div>

    <!-- Pagination Controls (15 rows per page) -->
    <?php if ($totalFilteredLogs > $perPage): ?>
      <div class="audit-pagination">
        <div class="audit-page-info">
          Showing <strong><?= min($totalFilteredLogs, $offset + 1) ?> - <?= min($totalFilteredLogs, $offset + count($auditLogs)) ?></strong> of <strong><?= $totalFilteredLogs ?></strong> audit events
        </div>
        <div class="audit-page-links">
          <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="audit-page-btn" title="First Page">&laquo; First</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="audit-page-btn" title="Previous Page">&lsaquo; Prev</a>
          <?php else: ?>
            <span class="audit-page-btn disabled">&laquo; First</span>
            <span class="audit-page-btn disabled">&lsaquo; Prev</span>
          <?php endif; ?>

          <?php
          $startP = max(1, $page - 2);
          $endP = min($totalPages, $page + 2);
          for ($p = $startP; $p <= $endP; $p++):
          ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="audit-page-btn <?= $p === $page ? 'active' : '' ?>">
              <?= $p ?>
            </a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="audit-page-btn" title="Next Page">Next &rsaquo;</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="audit-page-btn" title="Last Page">Last &raquo;</a>
          <?php else: ?>
            <span class="audit-page-btn disabled">Next &rsaquo;</span>
            <span class="audit-page-btn disabled">Last &raquo;</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </main>

  <!-- Interactive Client-side Real-time Search Handler -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const searchInput = document.getElementById('auditSearchInput');
      const tableRows = document.querySelectorAll('.audit-event-row');
      const emptyState = document.getElementById('emptyAuditResults');
      const tableElement = document.getElementById('auditTable');

      if (searchInput && tableRows.length > 0) {
        searchInput.addEventListener('input', (e) => {
          const query = e.target.value.trim().toLowerCase();
          let visibleCount = 0;

          tableRows.forEach(row => {
            const rowSearchText = row.getAttribute('data-search') || '';
            if (!query || rowSearchText.includes(query)) {
              row.style.display = '';
              visibleCount++;
            } else {
              row.style.display = 'none';
            }
          });

          if (visibleCount === 0) {
            if (tableElement) tableElement.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
          } else {
            if (tableElement) tableElement.style.display = 'table';
            if (emptyState) emptyState.style.display = 'none';
          }
        });
      }
    });
  </script>

</body>
</html>

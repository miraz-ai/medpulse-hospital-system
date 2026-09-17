<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Dedicated Audit Logs & Security Telemetry Console
 */

require_once __DIR__ . '/../includes/admin_auth.php';

// Retrieve live pending count for telemetry metric chip
try {
    $pCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending' AND role IN ('Doctor', 'Staff')");
    $pendingCount = (int)$pCountStmt->fetchColumn();
} catch (Throwable $e) {
    $pendingCount = 0;
}

// 6-Row Clinical Telemetry & Security Audit Dataset
$telemetryEvents = [
    [
        'id'        => 'TLM-9401',
        'action'    => 'Dr. Ayesha Siddiqua credentials approved by Miraz',
        'category'  => 'VERIFICATION',
        'ip'        => '192.168.1.14',
        'time'      => '2m ago',
        'exact_time'=> date('h:i:s A', strtotime('-2 minutes')),
        'actor'     => 'Miraz (Admin)',
        'level'     => 'Verified',
        'badge_cls' => 'badge-cat-verification',
        'tag'       => 'Staff Verification'
    ],
    [
        'id'        => 'TLM-9402',
        'action'    => 'Emergency intake: Patient #4881 assigned to Ward 3B',
        'category'  => 'ADMISSION',
        'ip'        => '192.168.1.8',
        'time'      => '6m ago',
        'exact_time'=> date('h:i:s A', strtotime('-6 minutes')),
        'actor'     => 'Station 3B Intake',
        'level'     => 'Admitted',
        'badge_cls' => 'badge-cat-admission',
        'tag'       => 'Clinical/Admission'
    ],
    [
        'id'        => 'TLM-9403',
        'action'    => 'Pharmacy batch #409 narcotics locker accessed',
        'category'  => 'SECURITY',
        'ip'        => '192.168.1.22',
        'time'      => '15m ago',
        'exact_time'=> date('h:i:s A', strtotime('-15 minutes')),
        'actor'     => 'Chief Pharmacist',
        'level'     => 'Secured Access',
        'badge_cls' => 'badge-cat-security',
        'tag'       => 'Pharmacy'
    ],
    [
        'id'        => 'TLM-9404',
        'action'    => 'Database schema migration & integrity check verified',
        'category'  => 'SYSTEM',
        'ip'        => '127.0.0.1',
        'time'      => '1h ago',
        'exact_time'=> date('h:i:s A', strtotime('-1 hour')),
        'actor'     => 'Core Daemon',
        'level'     => 'System Integrity',
        'badge_cls' => 'badge-cat-system',
        'tag'       => 'System Security'
    ],
    [
        'id'        => 'TLM-9405',
        'action'    => 'New staff registration request: Dr. Rahman',
        'category'  => 'VERIFICATION',
        'ip'        => '192.168.1.30',
        'time'      => '2h ago',
        'exact_time'=> date('h:i:s A', strtotime('-2 hours')),
        'actor'     => 'Portal Gateway',
        'level'     => 'Pending Review',
        'badge_cls' => 'badge-cat-verification',
        'tag'       => 'Staff Verification'
    ],
    [
        'id'        => 'TLM-9406',
        'action'    => 'Automated telemetry heartbeat check: System Normal (72 BPM)',
        'category'  => 'SYSTEM',
        'ip'        => 'Internal Bus',
        'time'      => '3h ago',
        'exact_time'=> date('h:i:s A', strtotime('-3 hours')),
        'actor'     => 'ECG Pulse Daemon',
        'level'     => 'Optimal',
        'badge_cls' => 'badge-cat-system',
        'tag'       => 'System Security'
    ]
];
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
  <link rel="stylesheet" href="../assets/css/audit-logs.css?v=<?= time() ?>">
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

      <!-- Metric Summary Chips -->
      <div class="audit-metric-chips">
        <!-- Chip 1: Total Telemetry Events -->
        <div class="audit-metric-chip">
          <div class="audit-chip-icon chip-icon-teal">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val" id="totalEventsCount"><?= count($telemetryEvents) ?></span>
            <span class="audit-chip-label">Telemetry Events</span>
          </div>
        </div>

        <!-- Chip 2: Security Checkpoints -->
        <div class="audit-metric-chip">
          <div class="audit-chip-icon chip-icon-blue">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val">18/18</span>
            <span class="audit-chip-label">Checkpoints OK</span>
          </div>
        </div>

        <!-- Chip 3: Pending Approvals -->
        <div class="audit-metric-chip">
          <div class="audit-chip-icon chip-icon-amber">
            <svg class="ui-ico ui-ico-sm" style="stroke: currentColor;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          </div>
          <div class="audit-chip-info">
            <span class="audit-chip-val"><?= (int)$pendingCount ?></span>
            <span class="audit-chip-label">Pending Approvals</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Filter Bar with Pill-Style Tabs & Live Search -->
    <div class="audit-filter-bar">
      <div class="audit-filter-tabs" role="tablist">
        <button class="filter-pill-tab active" data-filter="all">
          <span>All Events</span>
          <span class="tab-badge-count" id="badgeCountAll"><?= count($telemetryEvents) ?></span>
        </button>
        <button class="filter-pill-tab" data-filter="Clinical/Admission">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
          <span>Clinical/Admission</span>
        </button>
        <button class="filter-pill-tab" data-filter="Staff Verification">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          <span>Staff Verification</span>
        </button>
        <button class="filter-pill-tab" data-filter="Pharmacy">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
          <span>Pharmacy</span>
        </button>
        <button class="filter-pill-tab" data-filter="System Security">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          <span>System Security</span>
        </button>
      </div>

      <!-- Live Search Box -->
      <div class="audit-search-wrapper">
        <svg class="ui-ico audit-search-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <input 
          type="text" 
          id="auditSearchInput" 
          class="audit-search-input" 
          placeholder="Filter by keyword, actor, or IP..." 
          aria-label="Search audit events"
        />
      </div>
    </div>

    <!-- Audit Data Table Card -->
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
            <?php foreach ($telemetryEvents as $item): ?>
              <tr 
                class="audit-event-row" 
                data-category="<?= htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') ?>"
                data-tag="<?= htmlspecialchars($item['tag'], ENT_QUOTES, 'UTF-8') ?>"
                data-search="<?= htmlspecialchars(strtolower($item['action'] . ' ' . $item['category'] . ' ' . $item['actor'] . ' ' . $item['ip'] . ' ' . $item['tag']), ENT_QUOTES, 'UTF-8') ?>"
              >
                <td>
                  <div class="audit-action-main"><?= htmlspecialchars($item['action'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div style="font-size: 0.75rem; color: #64748b; display: flex; align-items: center; gap: 6px;">
                    <span style="font-weight: 600; color: #475569;">Actor:</span>
                    <span><?= htmlspecialchars($item['actor'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span>&bull;</span>
                    <span style="color: #94a3b8;"><?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                </td>
                <td>
                  <span class="audit-category-badge <?= $item['badge_cls'] ?>">
                    <?= htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td>
                  <span class="audit-ip-chip"><?= htmlspecialchars($item['ip'], ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td>
                  <div class="audit-time-text" title="<?= htmlspecialchars($item['exact_time'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                </td>
                <td style="text-align: right;">
                  <span style="font-size: 0.74rem; font-weight: 700; color: #0d9488; background: #f0fdfa; border: 1px solid #ccfbf1; padding: 3px 8px; border-radius: 6px; white-space: nowrap;">
                    <?= htmlspecialchars($item['level'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Empty Results Container -->
      <div class="audit-empty-results" id="emptyAuditResults">
        <svg class="ui-ico audit-empty-icon" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        <div class="audit-empty-title">No matching audit events found</div>
        <div class="audit-empty-subtext">Try adjusting your search query or selecting a different category tab.</div>
      </div>
    </div>

  </main>

  <!-- Interactive Filter & Live Search Handler -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const filterTabs = document.querySelectorAll('.filter-pill-tab');
      const searchInput = document.getElementById('auditSearchInput');
      const tableRows = document.querySelectorAll('.audit-event-row');
      const emptyState = document.getElementById('emptyAuditResults');
      const tableElement = document.getElementById('auditTable');

      let currentFilter = 'all';
      let currentQuery = '';

      function applyFilters() {
        let visibleCount = 0;
        const normalizedQuery = currentQuery.trim().toLowerCase();

        tableRows.forEach(row => {
          const rowCategory = row.getAttribute('data-category') || '';
          const rowTag = row.getAttribute('data-tag') || '';
          const rowSearchText = row.getAttribute('data-search') || '';

          // Check category tab match
          let categoryMatch = false;
          if (currentFilter === 'all') {
            categoryMatch = true;
          } else if (currentFilter === 'Clinical/Admission') {
            categoryMatch = (rowCategory === 'ADMISSION' || rowTag.includes('Admission'));
          } else if (currentFilter === 'Staff Verification') {
            categoryMatch = (rowCategory === 'VERIFICATION' || rowTag.includes('Verification'));
          } else if (currentFilter === 'Pharmacy') {
            categoryMatch = (rowTag.includes('Pharmacy') || rowSearchText.includes('pharmacy') || rowSearchText.includes('batch'));
          } else if (currentFilter === 'System Security') {
            categoryMatch = (rowCategory === 'SYSTEM' || rowCategory === 'SECURITY' || rowTag.includes('System'));
          }

          // Check keyword search match
          const queryMatch = !normalizedQuery || rowSearchText.includes(normalizedQuery);

          if (categoryMatch && queryMatch) {
            row.style.display = '';
            visibleCount++;
          } else {
            row.style.display = 'none';
          }
        });

        // Toggle table header vs empty state
        if (visibleCount === 0) {
          if (tableElement) tableElement.style.display = 'none';
          if (emptyState) emptyState.style.display = 'block';
        } else {
          if (tableElement) tableElement.style.display = 'table';
          if (emptyState) emptyState.style.display = 'none';
        }
      }

      // Tab switching event
      filterTabs.forEach(tab => {
        tab.addEventListener('click', () => {
          filterTabs.forEach(t => t.classList.remove('active'));
          tab.classList.add('active');
          currentFilter = tab.getAttribute('data-filter') || 'all';
          applyFilters();
        });
      });

      // Live search input event
      if (searchInput) {
        searchInput.addEventListener('input', (e) => {
          currentQuery = e.target.value;
          applyFilters();
        });
      }
    });
  </script>

</body>
</html>

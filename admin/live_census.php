<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Dedicated Clinical Census & Live Ward Bed Telemetry Interface
 */

require_once __DIR__ . '/../includes/admin_auth.php';

// Mock Bed Matrix Dataset (12 Representative Beds)
$bedSlots = [
    [
        'code'      => 'BED-ICU-01',
        'ward'      => 'ICU - Critical Care',
        'ward_key'  => 'ICU - Critical Care',
        'status'    => 'occupied',
        'patient'   => 'Tamim Iqbal',
        'patient_id'=> '#P-4012',
        'doctor'    => 'Dr. Ayesha Siddiqua',
        'vitals'    => 'Cardiac Monitored • O2 98%',
        'since'     => 'Adm: 2d ago'
    ],
    [
        'code'      => 'BED-ICU-02',
        'ward'      => 'ICU - Critical Care',
        'ward_key'  => 'ICU - Critical Care',
        'status'    => 'occupied',
        'patient'   => 'Nusrat Jahan',
        'patient_id'=> '#P-4088',
        'doctor'    => 'Dr. Rafiqul Islam',
        'vitals'    => 'Ventilator Mode • BP 120/80',
        'since'     => 'Adm: 14h ago'
    ],
    [
        'code'      => 'BED-ICU-03',
        'ward'      => 'ICU - Critical Care',
        'ward_key'  => 'ICU - Critical Care',
        'status'    => 'maintenance',
        'patient'   => '',
        'patient_id'=> '',
        'doctor'    => '',
        'vitals'    => 'UV Sterilization Cycle Active',
        'since'     => 'ETA: 20m'
    ],
    [
        'code'      => 'BED-EMG-01',
        'ward'      => 'Emergency Ward 3B',
        'ward_key'  => 'Emergency Ward 3B',
        'status'    => 'occupied',
        'patient'   => 'Farhana Akter',
        'patient_id'=> '#P-4881',
        'doctor'    => 'Dr. Mahbubur Rahman',
        'vitals'    => 'Trauma Triage • Stable',
        'since'     => 'Adm: 1h ago'
    ],
    [
        'code'      => 'BED-EMG-02',
        'ward'      => 'Emergency Ward 3B',
        'ward_key'  => 'Emergency Ward 3B',
        'status'    => 'available',
        'patient'   => '',
        'patient_id'=> '',
        'doctor'    => '',
        'vitals'    => 'Clean & Sanitized',
        'since'     => 'Rapid Intake Ready'
    ],
    [
        'code'      => 'BED-EMG-03',
        'ward'      => 'Emergency Ward 3B',
        'ward_key'  => 'Emergency Ward 3B',
        'status'    => 'occupied',
        'patient'   => 'Kazi Nazrul',
        'patient_id'=> '#P-4902',
        'doctor'    => 'Dr. Mahbubur Rahman',
        'vitals'    => 'Observation • IV Fluid On',
        'since'     => 'Adm: 3h ago'
    ],
    [
        'code'      => 'BED-GEN-01',
        'ward'      => 'General Ward A',
        'ward_key'  => 'General Ward A',
        'status'    => 'available',
        'patient'   => '',
        'patient_id'=> '',
        'doctor'    => '',
        'vitals'    => 'Standard Ward Allocation Ready',
        'since'     => 'Open Bed'
    ],
    [
        'code'      => 'BED-GEN-02',
        'ward'      => 'General Ward A',
        'ward_key'  => 'General Ward A',
        'status'    => 'occupied',
        'patient'   => 'Anisur Zaman',
        'patient_id'=> '#P-3819',
        'doctor'    => 'Dr. Ayesha Siddiqua',
        'vitals'    => 'Post-Op Recovery • Day 3',
        'since'     => 'Adm: 3d ago'
    ],
    [
        'code'      => 'BED-GEN-03',
        'ward'      => 'General Ward A',
        'ward_key'  => 'General Ward A',
        'status'    => 'available',
        'patient'   => '',
        'patient_id'=> '',
        'doctor'    => '',
        'vitals'    => 'Full Linen & Monitor Arm Check',
        'since'     => 'Open Bed'
    ],
    [
        'code'      => 'BED-GEN-04',
        'ward'      => 'General Ward A',
        'ward_key'  => 'General Ward A',
        'status'    => 'maintenance',
        'patient'   => '',
        'patient_id'=> '',
        'doctor'    => '',
        'vitals'    => 'Facility Deep Sanitize Protocol',
        'since'     => 'ETA: 45m'
    ],
    [
        'code'      => 'BED-PED-01',
        'ward'      => 'Pediatrics',
        'ward_key'  => 'Pediatrics',
        'status'    => 'occupied',
        'patient'   => 'Master Rayan',
        'patient_id'=> '#P-5102',
        'doctor'    => 'Dr. Sultana Razia',
        'vitals'    => 'Pediatric Care • Stable',
        'since'     => 'Adm: 1d ago'
    ],
    [
        'code'      => 'BED-PED-02',
        'ward'      => 'Pediatrics',
        'ward_key'  => 'Pediatrics',
        'status'    => 'available',
        'patient'   => '',
        'patient_id'=> '',
        'doctor'    => '',
        'vitals'    => 'Pediatric Bassinet & Crib Inspected',
        'since'     => 'Open Bed'
    ]
];

// Ward counts for badges
$wardCounts = [
    'all' => count($bedSlots),
    'ICU - Critical Care' => 0,
    'Emergency Ward 3B' => 0,
    'General Ward A' => 0,
    'Pediatrics' => 0
];
foreach ($bedSlots as $b) {
    if (isset($wardCounts[$b['ward_key']])) {
        $wardCounts[$b['ward_key']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Live Bed & Clinical Census Telemetry</title>
  
  <!-- Hospital Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%230284c7'/><stop offset='100%25' stop-color='%230d9488'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><path d='M32 46s-14-9.5-14-19a9 9 0 0 1 14-7.5A9 9 0 0 1 46 27c0 9.5-14 19-14 19z' fill='rgba(255,255,255,0.2)'/><path d='M19 32h6l3-6 5 13 4-8 3 3h5' fill='none' stroke='%23ffffff' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'/></svg>">
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Single Source of Truth External CSS -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/live-pulse.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/live-census.css?v=<?= time() ?>">
</head>
<body>

  <!-- Centralized Admin Sidebar Partial -->
  <?php require_once __DIR__ . '/../includes/admin_sidebar.php'; ?>

  <!-- Central Primary Workspace Container -->
  <main class="viewport-full">

    <!-- Header Banner with Animated ECG Pulse Badge -->
    <div class="welcome-banner" style="margin-bottom: 24px;">
      <div class="welcome-text">
        <h1 style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
          Live Bed & Clinical Census
          <div class="ecg-pulse-monitor" style="cursor: default;" title="Real-time cardiac telemetry monitor">
            <svg class="ecg-wave-svg" viewBox="0 0 60 18" width="60" height="18" aria-hidden="true">
              <path class="ecg-wave-bg" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
              <path class="ecg-wave-active" d="M 0 9 L 10 9 L 13 6.5 L 16 9 L 20 9 L 22 11 L 25 2 L 28 16 L 31 9 L 35 9 L 40 5.5 L 45 9 L 60 9" pathLength="100"></path>
            </svg>
            <span class="ecg-label"><span class="ecg-bpm-dot"></span>72 BPM &bull; CENSUS ACTIVE</span>
          </div>
        </h1>
        <p>Real-time inpatient occupancy, emergency admission allocations, intensive care load, and rapid triage routing across MedPulse.</p>
      </div>

      <div class="banner-actions">
        <button class="btn-action-telemed" onclick="showToast('Admissions dispatcher is actively routing.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          Direct Admission
        </button>
        <button class="btn-action-gradient" onclick="showToast('Live ward telemetry synchronized.', 'success')">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Sync Telemetry
        </button>
      </div>
    </div>

    <!-- 4 Top Metrics Overview Cards -->
    <div class="census-metrics-grid">
      <!-- Metric 1: Total Ward Beds -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Total Ward Beds</span>
          <div class="census-card-icon icon-teal">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
          </div>
        </div>
        <div class="census-card-value">67</div>
        <div class="census-card-badge badge-active">
          <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          Total Hospital Capacity
        </div>
      </div>

      <!-- Metric 2: Occupied Beds -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Occupied Beds</span>
          <div class="census-card-icon icon-blue">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
          </div>
        </div>
        <div class="census-card-value">22</div>
        <div class="census-card-badge badge-warning">
          <span>Active Inpatient Care</span>
        </div>
      </div>

      <!-- Metric 3: Available Capacity -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Available Capacity</span>
          <div class="census-card-icon icon-green">
            <svg class="ui-ico" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          </div>
        </div>
        <div class="census-card-value" style="color: #16a34a;">45</div>
        <div class="census-card-badge badge-open">
          <span>Open for Allocation</span>
        </div>
      </div>

      <!-- Metric 4: Critical / ICU Occupancy -->
      <div class="census-metric-card">
        <div class="census-card-top">
          <span class="census-card-label">Critical / ICU Occupancy</span>
          <div class="census-card-icon icon-rose">
            <svg class="ui-ico" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
        </div>
        <div class="census-card-value" style="color: #dc2626;">85%</div>
        <div class="census-card-badge badge-critical">
          <span>High Load Pressure</span>
        </div>
      </div>
    </div>

    <!-- Ward Floor Filter Bar -->
    <div class="census-filter-bar">
      <div class="census-filter-tabs" role="tablist">
        <button class="ward-filter-tab active" data-ward="all">
          <span>All Wards</span>
          <span class="ward-tab-count"><?= $wardCounts['all'] ?></span>
        </button>
        <button class="ward-filter-tab" data-ward="ICU - Critical Care">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          <span>ICU - Critical Care</span>
          <span class="ward-tab-count"><?= $wardCounts['ICU - Critical Care'] ?></span>
        </button>
        <button class="ward-filter-tab" data-ward="Emergency Ward 3B">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
          <span>Emergency Ward 3B</span>
          <span class="ward-tab-count"><?= $wardCounts['Emergency Ward 3B'] ?></span>
        </button>
        <button class="ward-filter-tab" data-ward="General Ward A">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect></svg>
          <span>General Ward A</span>
          <span class="ward-tab-count"><?= $wardCounts['General Ward A'] ?></span>
        </button>
        <button class="ward-filter-tab" data-ward="Pediatrics">
          <svg class="ui-ico ui-ico-sm" style="width: 14px; height: 14px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"></circle><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"></path></svg>
          <span>Pediatrics</span>
          <span class="ward-tab-count"><?= $wardCounts['Pediatrics'] ?></span>
        </button>
      </div>

      <!-- Status Legend -->
      <div class="census-legend">
        <div class="legend-item">
          <span class="legend-dot dot-available"></span>
          <span>Available</span>
        </div>
        <div class="legend-item">
          <span class="legend-dot dot-occupied"></span>
          <span>Occupied</span>
        </div>
        <div class="legend-item">
          <span class="legend-dot dot-maintenance"></span>
          <span>Maintenance</span>
        </div>
      </div>
    </div>

    <!-- Interactive Bed Matrix Grid -->
    <div class="bed-matrix-grid" id="bedMatrixGrid">
      <?php foreach ($bedSlots as $slot): ?>
        <div 
          class="bed-slot-card slot-<?= $slot['status'] ?>" 
          data-ward="<?= htmlspecialchars($slot['ward_key'], ENT_QUOTES, 'UTF-8') ?>"
        >
          <div>
            <!-- Bed Card Header -->
            <div class="bed-card-header">
              <div class="bed-code-group">
                <div class="bed-icon-badge">
                  <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path></svg>
                </div>
                <div>
                  <div class="bed-code"><?= htmlspecialchars($slot['code'], ENT_QUOTES, 'UTF-8') ?></div>
                  <span class="bed-ward-tag"><?= htmlspecialchars($slot['ward'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>

              <!-- Status Pill -->
              <?php if ($slot['status'] === 'available'): ?>
                <span class="bed-status-pill status-available-pill">Available</span>
              <?php elseif ($slot['status'] === 'occupied'): ?>
                <span class="bed-status-pill status-occupied-pill">Occupied</span>
              <?php else: ?>
                <span class="bed-status-pill status-maintenance-pill">Sanitizing</span>
              <?php endif; ?>
            </div>

            <!-- Bed Card Body -->
            <div class="bed-card-body">
              <?php if ($slot['status'] === 'occupied'): ?>
                <div class="bed-patient-info">
                  <div class="patient-name">
                    <span><?= htmlspecialchars($slot['patient'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="patient-id"><?= htmlspecialchars($slot['patient_id'], ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                  <div class="patient-meta">
                    <span class="attending-doctor">
                      <svg class="ui-ico" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                      <?= htmlspecialchars($slot['doctor'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
                </div>
                <div style="font-size: 0.74rem; color: #475569; display: flex; justify-content: space-between;">
                  <span><?= htmlspecialchars($slot['vitals'], ENT_QUOTES, 'UTF-8') ?></span>
                  <span style="color: #64748b;"><?= htmlspecialchars($slot['since'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              <?php elseif ($slot['status'] === 'available'): ?>
                <div class="bed-vacant-msg">
                  <svg class="ui-ico ui-ico-sm" style="stroke: #16a34a; width: 16px; height: 16px;" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                  <span>Ready for immediate inpatient placement</span>
                </div>
                <div style="font-size: 0.74rem; color: #166534; opacity: 0.85;">
                  <?= htmlspecialchars($slot['vitals'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php else: ?>
                <div class="bed-maint-msg">
                  <svg class="ui-ico ui-ico-sm" style="stroke: #d97706; width: 16px; height: 16px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                  <span>Sanitization protocol in execution</span>
                </div>
                <div style="font-size: 0.74rem; color: #92400e; opacity: 0.85;">
                  <?= htmlspecialchars($slot['vitals'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($slot['since'], ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Quick Action Buttons on Hover -->
          <div class="bed-actions-bar">
            <?php if ($slot['status'] === 'available'): ?>
              <button 
                type="button" 
                class="btn-bed-action btn-bed-primary"
                onclick="showToast('Initiating patient admission assignment for <?= htmlspecialchars($slot['code'], ENT_QUOTES, 'UTF-8') ?>', 'success')"
              >
                <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Allocate Patient
              </button>
            <?php elseif ($slot['status'] === 'occupied'): ?>
              <button 
                type="button" 
                class="btn-bed-action btn-bed-primary"
                onclick="showToast('Loading cardiac & vitals telemetry for <?= htmlspecialchars($slot['code'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($slot['patient'], ENT_QUOTES, 'UTF-8') ?>)', 'success')"
              >
                <svg class="ui-ico ui-ico-sm" style="width: 12px; height: 12px;" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                View Telemetry
              </button>
              <button 
                type="button" 
                class="btn-bed-action btn-bed-secondary"
                onclick="showToast('Discharge process initialized for <?= htmlspecialchars($slot['patient'], ENT_QUOTES, 'UTF-8') ?>', 'success')"
              >
                Discharge
              </button>
            <?php else: ?>
              <button 
                type="button" 
                class="btn-bed-action btn-bed-secondary"
                onclick="showToast('<?= htmlspecialchars($slot['code'], ENT_QUOTES, 'UTF-8') ?> marked as Sanitized & Ready.', 'success')"
              >
                Mark Ready
              </button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- Empty Ward Container -->
      <div class="census-empty-ward" id="emptyWardNotice">
        <h4 style="font-size: 1rem; color: #1e293b; margin-bottom: 4px;">No beds registered in this ward</h4>
        <p style="font-size: 0.82rem; color: #64748b;">Select another ward filter tab above to view active bed slots.</p>
      </div>
    </div>

  </main>

  <!-- Interactive Ward Floor Filter Script -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const wardTabs = document.querySelectorAll('.ward-filter-tab');
      const bedCards = document.querySelectorAll('.bed-slot-card');
      const emptyNotice = document.getElementById('emptyWardNotice');

      wardTabs.forEach(tab => {
        tab.addEventListener('click', () => {
          wardTabs.forEach(t => t.classList.remove('active'));
          tab.classList.add('active');

          const selectedWard = tab.getAttribute('data-ward') || 'all';
          let visibleCount = 0;

          bedCards.forEach(card => {
            const cardWard = card.getAttribute('data-ward') || '';
            if (selectedWard === 'all' || cardWard === selectedWard) {
              card.style.display = 'flex';
              visibleCount++;
            } else {
              card.style.display = 'none';
            }
          });

          if (visibleCount === 0) {
            emptyNotice.style.display = 'block';
          } else {
            emptyNotice.style.display = 'none';
          }
        });
      });
    });
  </script>

</body>
</html>

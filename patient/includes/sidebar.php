<?php
/**
 * MedPulse Enterprise HMS — Unified Patient Portal Canonical Sidebar
 * Consistent Navigation Across All Patient Views with Dynamic Route Highlighting
 */

$currentRoute = basename($_SERVER['PHP_SELF'] ?? '');

// Contextual Emergency Dispatch Data
$sosPatientInfo = null;
$sosHospitals = [];
if (isset($pdo) && !empty($_SESSION['user_id'])) {
    try {
        $pStmt = $pdo->prepare("SELECT id, user_id, patient_uid, full_name, phone FROM patients WHERE user_id = ? LIMIT 1");
        $pStmt->execute([(int)$_SESSION['user_id']]);
        $sosPatientInfo = $pStmt->fetch(PDO::FETCH_ASSOC);

        $hStmt = $pdo->query("SELECT id, hospital_id, name, city, contact_number, address FROM hospitals ORDER BY id ASC");
        $sosHospitals = $hStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Failed to load emergency modal context: " . $e->getMessage());
    }
}
?>
<!-- Mobile Topbar with Native Hamburger -->
<header class="mobile-topbar">
  <button class="mobile-hamburger" id="menuToggle" aria-label="Toggle Navigation">
    <svg class="ui-ico" viewBox="0 0 24 24"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
  </button>
  <a href="dashboard.php" class="mobile-brand">
    <img src="../assets/images/logo.png" alt="MedPulse">
  </a>
  <div style="width: 38px;"></div>
</header>

<!-- Dark Backdrop Overlay for Mobile Slideout -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Left Sidebar (Clean Compact 8pt Golden Spacing) -->
<aside class="left-bar" id="appSidebar">
  <a href="dashboard.php" class="brand-header-link">
    <img src="../assets/images/logo.png" alt="MedPulse Hospital &amp; Specialty Care">
  </a>

  <div class="nav-label">Clinical Care</div>
  <ul class="nav-menu">
    <!-- Overview -->
    <li class="nav-item <?= in_array($currentRoute, ['dashboard.php', 'portal.php', '']) ? 'active' : '' ?>">
      <a href="dashboard.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          Overview
        </div>
      </a>
    </li>

    <!-- Medical Specialists -->
    <li class="nav-item <?= in_array($currentRoute, ['specialists.php', 'doctors.php']) ? 'active' : '' ?>">
      <a href="specialists.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          Medical Specialists
        </div>
      </a>
    </li>

    <!-- Consultations -->
    <li class="nav-item <?= in_array($currentRoute, ['appointments.php', 'book_appointment.php']) ? 'active' : '' ?>">
      <a href="appointments.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="m9 16 2 2 4-4"></path></svg>
          Consultations
        </div>
      </a>
    </li>

    <!-- Virtual Care Suite -->
    <li class="nav-item <?= ($currentRoute === 'telemedicine.php') ? 'active' : '' ?>">
      <a href="telemedicine.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
          Virtual Care Suite
        </div>
        <span class="vc-chip-sm" style="font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 999px; background: rgba(59, 130, 246, 0.15); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.3);">HD CALL</span>
      </a>
    </li>

    <!-- Live Bed Census -->
    <li class="nav-item <?= ($currentRoute === 'reserve_bed.php') ? 'active' : '' ?>">
      <a href="reserve_bed.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>
          Live Bed Census
        </div>
        <span class="live-chip-sm" style="font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 999px; background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3);">45M HOLD</span>
      </a>
    </li>

    <!-- Diagnostic Reports (Archived for future release)
    <li class="nav-item <?= ($currentRoute === 'reports.php') ? 'active' : '' ?>">
      <a href="reports.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14.5 2v17.5c0 1.4-1.1 2.5-2.5 2.5h0c-1.4 0-2.5-1.1-2.5-2.5V2"></path><path d="M8.5 2h7"></path><path d="M14.5 16h-5"></path></svg>
          Diagnostic Reports
        </div>
      </a>
    </li>
    -->

    <!-- Digital Rx (Archived for future release)
    <li class="nav-item <?= ($currentRoute === 'prescriptions.php') ? 'active' : '' ?>">
      <a href="prescriptions.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>
          Digital Rx
        </div>
      </a>
    </li>
    -->

    <!-- Automated Billing -->
    <li class="nav-item <?= in_array($currentRoute, ['billing.php', 'my_bills.php']) ? 'active' : '' ?>">
      <a href="billing.php">
        <div class="nav-item-inner">
          <svg class="ui-ico" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
          Automated Billing
        </div>
      </a>
    </li>
  </ul>

  <div class="nav-label">Emergency &amp; Support</div>
  <ul class="nav-menu">
    <li class="nav-item">
      <a href="javascript:void(0)" onclick="openEmergencySosModal(event);" style="color: #ef4444; cursor: pointer;">
        <div class="nav-item-inner">
          <svg class="ui-ico" style="stroke: #ef4444;" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
          Emergency Ambulance (999)
        </div>
        <span style="font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 999px; background: rgba(239, 68, 68, 0.15); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3);">24/7 SOS</span>
      </a>
    </li>
  </ul>

  <div class="sidebar-footer">
    <a href="../logout.php" class="btn-signout">
      <svg class="ui-ico" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
      Sign Out
    </a>
  </div>
</aside>

<!-- URGENT SOS MODAL (Authentic Bangladesh Emergency & Branch Fleet Protocol) -->
<div id="emergencySosModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); padding: 1.25rem; overflow-y: auto; align-items: center; justify-content: center;">
  <div style="background: #ffffff; width: 100%; max-width: 580px; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(220, 38, 38, 0.25), 0 0 0 1px rgba(239, 68, 68, 0.2); overflow: hidden; margin: auto; animation: sosModalPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);">
    
    <!-- Modal Header -->
    <div style="background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%); color: #ffffff; padding: 1.5rem; position: relative;">
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
        <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 999px;">
          <span style="width: 8px; height: 8px; border-radius: 50%; background: #ffffff; display: inline-block; animation: sosPulse 1s infinite alternate;"></span>
          CRITICAL EMERGENCY RESPONSE &bull; BANGLADESH
        </span>
        <button type="button" onclick="closeEmergencySosModal();" style="background: rgba(255,255,255,0.18); border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; cursor: pointer; transition: background 0.2s;">
          <svg style="width: 16px; height: 16px; stroke: currentColor; stroke-width: 2.5;" fill="none" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>
      <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0; line-height: 1.25;">Emergency Ambulance Dispatch</h2>
      <p style="font-size: 0.85rem; margin: 0.35rem 0 0; opacity: 0.92;">Direct national emergency dial or active hospital branch fleet dispatch.</p>
    </div>

    <!-- Modal Body -->
    <div style="padding: 1.5rem;">
      
      <!-- Option A: Authentic Bangladesh National Emergency Direct Dials -->
      <div style="margin-bottom: 1.5rem;">
        <div style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 0.65rem;">
          Instant National Direct Dials
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem;">
          <!-- 999 National Ambulance -->
          <a href="tel:999" style="display: flex; flex-direction: column; justify-content: space-between; background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 1rem; text-decoration: none; color: #991b1b; transition: all 0.2s ease;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
                <span style="background: #dc2626; color: #fff; font-size: 0.68rem; font-weight: 800; padding: 2px 7px; border-radius: 6px;">NATIONAL</span>
                <svg style="width: 20px; height: 20px; stroke: #dc2626;" fill="none" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
              </div>
              <strong style="font-size: 1.35rem; font-weight: 800; color: #b91c1c; display: block; line-height: 1;">Dial 999</strong>
              <span style="font-size: 0.76rem; color: #7f1d1d; margin-top: 4px; display: block;">National Emergency Ambulance, Fire &amp; Police</span>
            </div>
            <div style="margin-top: 0.65rem; font-size: 0.72rem; font-weight: 700; color: #dc2626; display: flex; align-items: center; gap: 4px;">
              Direct Call &rarr;
            </div>
          </a>

          <!-- 16263 Shastho Batayan -->
          <a href="tel:16263" style="display: flex; flex-direction: column; justify-content: space-between; background: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 12px; padding: 1rem; text-decoration: none; color: #065f46; transition: all 0.2s ease;">
            <div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem;">
                <span style="background: #059669; color: #fff; font-size: 0.68rem; font-weight: 800; padding: 2px 7px; border-radius: 6px;">DGHS</span>
                <svg style="width: 20px; height: 20px; stroke: #059669;" fill="none" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
              </div>
              <strong style="font-size: 1.35rem; font-weight: 800; color: #047857; display: block; line-height: 1;">Dial 16263</strong>
              <span style="font-size: 0.76rem; color: #064e3b; margin-top: 4px; display: block;">Shastho Batayan &bull; Ministry Health Helpline</span>
            </div>
            <div style="margin-top: 0.65rem; font-size: 0.72rem; font-weight: 700; color: #059669; display: flex; align-items: center; gap: 4px;">
              Direct Call &rarr;
            </div>
          </a>
        </div>
      </div>

      <div style="border-top: 1px dashed #e2e8f0; margin: 1.25rem 0;"></div>

      <!-- Option B: Request Branch Ambulance Dispatch -->
      <div id="branchDispatchContainer">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
          <div>
            <div style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">
              Hospital Branch Fleet Dispatch
            </div>
            <h3 style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin: 2px 0 0;">Request Branch Ambulance</h3>
          </div>
          <span style="font-size: 0.72rem; font-weight: 700; background: #f1f5f9; color: #475569; padding: 4px 8px; border-radius: 6px;">
            Rapid Fleet Unit
          </span>
        </div>

        <form id="sosBranchDispatchForm" onsubmit="submitBranchAmbulanceDispatch(event);">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          
          <!-- Registered Patient Badge -->
          <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem 0.9rem; margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between; font-size: 0.82rem;">
            <div>
              <span style="color: #64748b; font-size: 0.7rem; text-transform: uppercase; font-weight: 700; display: block;">Registered Patient</span>
              <strong style="color: #0f172a;"><?= htmlspecialchars($sosPatientInfo['full_name'] ?? $_SESSION['user_name'] ?? 'Patient', ENT_QUOTES, 'UTF-8') ?></strong>
              <span style="color: #64748b; margin-left: 6px; font-size: 0.75rem;">(<?= htmlspecialchars($sosPatientInfo['patient_uid'] ?? 'MP-2026', ENT_QUOTES, 'UTF-8') ?>)</span>
            </div>
            <span style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 0.7rem; padding: 2px 8px; border-radius: 999px;">Verified</span>
          </div>

          <!-- Contact Phone & Hospital Branch -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 0.85rem;">
            <div>
              <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
                Contact Number <span style="color: #dc2626;">*</span>
              </label>
              <input type="tel" name="contact_phone" required value="<?= htmlspecialchars($sosPatientInfo['phone'] ?? '01783203318', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 017XXXXXXXX" style="width: 100%; padding: 0.6rem 0.75rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-family: inherit;">
            </div>

            <div>
              <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
                Dispatch Hospital Branch <span style="color: #dc2626;">*</span>
              </label>
              <select name="hospital_id" id="sosHospitalSelect" required style="width: 100%; padding: 0.6rem 0.75rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background: #fff; font-family: inherit;">
                <?php if (!empty($sosHospitals)): ?>
                  <?php foreach ($sosHospitals as $hosp): ?>
                    <option value="<?= (int)($hosp['hospital_id'] ?? $hosp['id']) ?>" data-phone="<?= htmlspecialchars($hosp['contact_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($hosp['name']) ?> (<?= htmlspecialchars($hosp['city'] ?? 'Dhaka') ?>)
                    </option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="1" data-phone="+880-2-9881234">MedPulse Hospital &amp; Specialty Care (Dhaka)</option>
                <?php endif; ?>
              </select>
            </div>
          </div>

          <!-- Pickup Address -->
          <div style="margin-bottom: 0.85rem;">
            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
              Pickup Address &amp; Nearest Landmark <span style="color: #dc2626;">*</span>
            </label>
            <input type="text" name="pickup_address" required placeholder="House/Holding #, Road #, Sector/Area, Dhaka" value="Road 4, Dhanmondi, Dhaka" style="width: 100%; padding: 0.6rem 0.75rem; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-family: inherit;">
          </div>

          <!-- Urgency Level -->
          <div style="margin-bottom: 1.15rem;">
            <label style="display: block; font-size: 0.75rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
              Ambulance Unit Acuity
            </label>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
              <label style="display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 10px; border-radius: 6px; cursor: pointer;">
                <input type="radio" name="urgency_level" value="ICU / Life Support Ambulance" checked> ICU / Cardiac Life Support
              </label>
              <label style="display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 10px; border-radius: 6px; cursor: pointer;">
                <input type="radio" name="urgency_level" value="Emergency Trauma Transport"> Emergency Trauma Unit
              </label>
              <label style="display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 10px; border-radius: 6px; cursor: pointer;">
                <input type="radio" name="urgency_level" value="Standard Patient Transit"> Standard Transit
              </label>
            </div>
          </div>

          <button type="submit" id="btnSubmitDispatch" style="width: 100%; background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%); color: #fff; border: none; padding: 0.85rem; font-size: 0.95rem; font-weight: 800; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35); transition: opacity 0.2s;">
            <svg style="width: 20px; height: 20px; stroke: #fff;" fill="none" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            <span>Request Branch Ambulance Dispatch</span>
          </button>
        </form>

        <!-- Live Success Feedback -->
        <div id="sosDispatchSuccess" style="display: none; background: #ecfdf5; border: 1.5px solid #6ee7b7; border-radius: 12px; padding: 1.25rem; text-align: center;">
          <div style="width: 48px; height: 48px; border-radius: 50%; background: #10b981; color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 0.75rem;">
            <svg style="width: 24px; height: 24px; stroke: currentColor; stroke-width: 3;" fill="none" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
          </div>
          <h4 style="font-size: 1.15rem; font-weight: 800; color: #065f46; margin: 0 0 0.35rem;">Ambulance Fleet Notified!</h4>
          <p style="font-size: 0.84rem; color: #047857; margin: 0 0 0.85rem;" id="sosSuccessMessage">
            Dispatch order transmitted to the hospital emergency fleet coordinator.
          </p>
          <div style="background: #ffffff; border: 1px solid #a7f3d0; border-radius: 8px; padding: 0.75rem; text-align: left; font-size: 0.8rem; margin-bottom: 1rem;">
            <div><strong>Dispatch ID:</strong> <span id="sosDispatchId" style="font-family: monospace; color: #047857;">-</span></div>
            <div><strong>Hospital:</strong> <span id="sosBranchName">-</span></div>
            <div style="margin-top: 4px;"><strong>Direct Fleet Hotline:</strong> <a href="#" id="sosBranchPhoneLink" style="font-weight: 800; color: #dc2626; text-decoration: underline;">-</a></div>
          </div>
          <div style="display: flex; gap: 0.5rem; justify-content: center;">
            <button type="button" onclick="closeEmergencySosModal();" style="background: #059669; color: #fff; border: none; padding: 0.55rem 1.25rem; font-size: 0.82rem; font-weight: 700; border-radius: 6px; cursor: pointer;">
              Done
            </button>
            <button type="button" onclick="resetEmergencySosForm();" style="background: #f1f5f9; color: #475569; border: none; padding: 0.55rem 1rem; font-size: 0.82rem; font-weight: 700; border-radius: 6px; cursor: pointer;">
              New Request
            </button>
          </div>
        </div>

      </div>

    </div>

    <!-- Modal Footer -->
    <div style="background: #f8fafc; border-top: 1px solid #f1f5f9; padding: 0.75rem 1.5rem; display: flex; align-items: center; justify-content: space-between; font-size: 0.72rem; color: #64748b;">
      <span>Govt. DGHS &amp; BMDC Emergency Coordination Standard</span>
      <button type="button" onclick="closeEmergencySosModal();" style="background: none; border: none; font-size: 0.72rem; font-weight: 700; color: #64748b; cursor: pointer; text-decoration: underline;">
        Dismiss
      </button>
    </div>

  </div>
</div>

<style>
  @keyframes sosPulse {
    0% { transform: scale(0.95); opacity: 0.7; }
    100% { transform: scale(1.15); opacity: 1; }
  }
  @keyframes sosModalPop {
    0% { opacity: 0; transform: scale(0.96) translateY(8px); }
    100% { opacity: 1; transform: scale(1) translateY(0); }
  }
</style>

<script>
  (function() {
    const menuToggle = document.getElementById('menuToggle');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function toggleMenu() {
      if (appSidebar) appSidebar.classList.toggle('open');
      if (sidebarBackdrop) sidebarBackdrop.classList.toggle('active');
    }

    if (menuToggle) menuToggle.addEventListener('click', toggleMenu);
    if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', toggleMenu);
  })();

  // Global SOS Modal Handlers
  window.openEmergencySosModal = function(e) {
    if (e && e.preventDefault) e.preventDefault();
    const modal = document.getElementById('emergencySosModal');
    if (modal) {
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
  };

  window.closeEmergencySosModal = function() {
    const modal = document.getElementById('emergencySosModal');
    if (modal) {
      modal.style.display = 'none';
      document.body.style.overflow = '';
    }
  };

  // Close on Backdrop Click & ESC Key
  document.addEventListener('click', function(e) {
    const modal = document.getElementById('emergencySosModal');
    if (e.target === modal) {
      closeEmergencySosModal();
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeEmergencySosModal();
    }
  });

  window.resetEmergencySosForm = function() {
    const form = document.getElementById('sosBranchDispatchForm');
    const successDiv = document.getElementById('sosDispatchSuccess');
    if (form) form.style.display = 'block';
    if (successDiv) successDiv.style.display = 'none';
  };

  window.submitBranchAmbulanceDispatch = async function(e) {
    e.preventDefault();
    const form = document.getElementById('sosBranchDispatchForm');
    const btn = document.getElementById('btnSubmitDispatch');
    const successDiv = document.getElementById('sosDispatchSuccess');
    
    if (!form || !btn) return;
    
    const formData = new FormData(form);
    const origHtml = btn.innerHTML;
    
    btn.disabled = true;
    btn.style.opacity = '0.7';
    btn.innerHTML = '<span>Transmitting SOS Dispatch...</span>';

    try {
      const resp = await fetch('api/request_ambulance.php', {
        method: 'POST',
        body: formData
      });
      const data = await resp.json();

      if (data && data.success) {
        form.style.display = 'none';
        if (successDiv) {
          successDiv.style.display = 'block';
          document.getElementById('sosDispatchId').textContent = data.dispatch_id || 'AMB-DISPATCHED';
          document.getElementById('sosBranchName').textContent = data.hospital_name || 'Hospital Branch';
          const phoneLink = document.getElementById('sosBranchPhoneLink');
          if (phoneLink) {
            phoneLink.textContent = data.hospital_phone || 'Call Branch';
            phoneLink.href = 'tel:' + (data.hospital_phone || '999');
          }
        }
      } else {
        alert(data.message || 'Failed to dispatch ambulance. Please dial 999 immediately.');
      }
    } catch (err) {
      console.error(err);
      alert('Network error connecting to fleet dispatch. Please dial 999 directly.');
    } finally {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.innerHTML = origHtml;
    }
  };
</script>

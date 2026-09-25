<?php
/**
 * MedPulse Enterprise Hospital Management System
 * Super Admin — Executive Financial Governance & Macro Treasury
 *
 * Strictly Read-Only Executive Macro View:
 * - Aggregates cross-branch network revenue, collections, and arrears.
 * - Multi-tenant isolation: No cashier collection or bill editing powers.
 * - HIPAA Compliance: Granular patient clinical charges and identities are masked.
 * - Real-time security telemetry: Tracks payment collections & blocked cross-branch intrusions.
 */

require_once __DIR__ . '/../includes/super_admin_auth.php';
require_once __DIR__ . '/../config/db.php';

// Helper: Mask Patient Identity per HIPAA standards
function maskPatientName(?int $patientId, ?string $rawName): string {
    if (!$patientId) {
        return 'Patient [Restricted]';
    }
    $initial = !empty($rawName) ? strtoupper(substr(trim($rawName), 0, 1)) . '.' : 'X.';
    return 'Patient #' . str_pad((string)$patientId, 4, '0', STR_PAD_LEFT) . " ({$initial} — HIPAA Protected)";
}

// Helper: Mask itemized clinical description to high-level clinical classification
function maskClinicalCategory(?string $categories): string {
    if (empty($categories)) {
        return 'General Facility Care Services';
    }
    $parts = explode(',', $categories);
    $sanitized = array_map(function($c) {
        $c = trim($c);
        return match($c) {
            'Bed Charge'       => 'Inpatient Room & Facility Stay',
            'Consultation'     => 'Physician Care & Consultation',
            'Diagnostic Test'  => 'Diagnostic & Laboratory Evaluation',
            'Pharmacy'         => 'Pharmaceutical Clinical Provision',
            default            => 'Clinical Services'
        };
    }, $parts);
    return implode(' • ', array_unique($sanitized));
}

// Facility Switcher Filter
$filterHospitalId = isset($_GET['hospital_id']) ? (int)$_GET['hospital_id'] : 0;

try {
    // 1. Fetch all hospitals for switcher dropdown
    $hospStmt = $pdo->query("SELECT hospital_id, name, code, city, operational_status FROM hospitals ORDER BY hospital_id ASC");
    $allHospitals = $hospStmt->fetchAll(PDO::FETCH_ASSOC);

    // SQL filter fragments
    $whereHospParam = [];
    $hospWhereInv   = "";
    $hospWherePay   = "";
    if ($filterHospitalId > 0) {
        $hospWhereInv = " WHERE hospital_id = :hid ";
        $hospWherePay = " WHERE p.hospital_id = :hid ";
        $whereHospParam = [':hid' => $filterHospitalId];
    }

    // 2. Executive Network Macro KPIs
    $kpiStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(net_payable), 0) AS gross_invoiced,
            COALESCE(SUM(paid_amount), 0) AS total_collected,
            COALESCE(SUM(due_amount),  0) AS total_due,
            COUNT(*)                      AS total_invoices,
            SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status != 'Paid' THEN 1 ELSE 0 END) AS pending_count
        FROM invoices
        {$hospWhereInv}
    ");
    $kpiStmt->execute($whereHospParam);
    $macroKpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    $grossInvoiced    = (float)$macroKpi['gross_invoiced'];
    $totalCollected   = (float)$macroKpi['total_collected'];
    $totalOutstanding = (float)$macroKpi['total_due'];
    $totalInvoices    = (int)$macroKpi['total_invoices'];
    $paidInvoices     = (int)$macroKpi['paid_count'];
    $pendingInvoices  = (int)$macroKpi['pending_count'];
    $recoveryRate     = $grossInvoiced > 0 ? round(($totalCollected / $grossInvoiced) * 100, 1) : 0;

    // 3. Facility-wise Breakdown (Branch Treasury Comparison)
    $facilityStmt = $pdo->query("
        SELECT 
            h.hospital_id,
            h.name AS hospital_name,
            h.code AS hospital_code,
            h.city,
            h.operational_status,
            COUNT(i.invoice_id)             AS invoice_count,
            COALESCE(SUM(i.net_payable), 0) AS gross_invoiced,
            COALESCE(SUM(i.paid_amount), 0) AS total_collected,
            COALESCE(SUM(i.due_amount), 0)  AS total_due,
            SUM(CASE WHEN i.status = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN i.status != 'Paid' AND i.invoice_id IS NOT NULL THEN 1 ELSE 0 END) AS pending_count
        FROM hospitals h
        LEFT JOIN invoices i ON h.hospital_id = i.hospital_id
        GROUP BY h.hospital_id, h.name, h.code, h.city, h.operational_status
        ORDER BY gross_invoiced DESC, h.hospital_id ASC
    ");
    $facilityBreakdown = $facilityStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Payment Method Distribution across Network
    $methodSql = "
        SELECT 
            payment_method,
            COUNT(*) AS tx_count,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM invoice_payments p
        " . ($filterHospitalId > 0 ? "WHERE p.hospital_id = :hid" : "") . "
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ";
    $methodStmt = $pdo->prepare($methodSql);
    $methodStmt->execute($whereHospParam);
    $paymentMethods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Recent Macro Transactions (HIPAA Masked)
    $txSql = "
        SELECT 
            p.payment_id,
            p.hospital_id,
            p.invoice_id,
            p.amount,
            p.payment_method,
            p.transaction_reference,
            p.payment_date,
            i.invoice_number,
            i.status AS invoice_status,
            i.patient_id,
            pat.full_name AS patient_name,
            h.name AS hospital_name,
            h.code AS hospital_code,
            u_col.full_name AS collector_name
        FROM invoice_payments p
        JOIN invoices i ON p.invoice_id = i.invoice_id
        JOIN hospitals h ON p.hospital_id = h.hospital_id
        LEFT JOIN users pat ON i.patient_id = pat.user_id
        LEFT JOIN users u_col ON p.collected_by_user_id = u_col.user_id
        " . ($filterHospitalId > 0 ? "WHERE p.hospital_id = :hid" : "") . "
        ORDER BY p.payment_date DESC, p.payment_id DESC
        LIMIT 20
    ";
    $txStmt = $pdo->prepare($txSql);
    $txStmt->execute($whereHospParam);
    $recentTransactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Security Audit Trail (Financial events and intrusion attempts)
    $auditStmt = $pdo->query("
        SELECT 
            a.log_id,
            a.actor_id,
            a.actor_role,
            a.action,
            a.description,
            a.category,
            a.target_entity,
            a.security_level,
            a.ip_address,
            a.created_at,
            u.full_name AS actor_name,
            u.hospital_id AS actor_hosp_id,
            h.name AS actor_hosp_name
        FROM audit_logs a
        LEFT JOIN users u ON a.actor_id = u.user_id
        LEFT JOIN hospitals h ON u.hospital_id = h.hospital_id
        WHERE a.action IN ('PAYMENT_COLLECTED', 'UNAUTHORIZED_PAYMENT_ATTEMPT', 'INVOICE_GENERATED', 'DOCTOR_PAYOUT_APPROVED')
           OR a.category = 'SECURITY'
        ORDER BY a.created_at DESC
        LIMIT 25
    ");
    $financialAudits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database communication error in Financial Overview: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <title>MedPulse | Super Admin — Executive Financial Governance</title>
  <meta name="description" content="MedPulse Executive Financial Command & Macro Treasury Overview. Strict read-only multi-tenant isolation and HIPAA data masking.">

  <!-- Favicon -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><defs><linearGradient id='g' x1='0%25' y1='0%25' x2='0%25' y2='100%25'><stop offset='0%25' stop-color='%2310b981'/><stop offset='100%25' stop-color='%237c3aed'/></linearGradient></defs><rect width='64' height='64' rx='18' fill='url(%23g)'/><text x='32' y='42' font-size='30' font-weight='800' fill='white' text-anchor='middle'>৳</text></svg>">

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

  <!-- Core Design System -->
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/live-ticker.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/admin/live-pulse.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../assets/css/medpulse_dialog.css?v=<?= time() ?>">

  <style>
    :root {
      --sa-accent:        #7c3aed;
      --sa-accent-soft:   rgba(124, 58, 237, 0.10);
      --sa-accent-border: rgba(124, 58, 237, 0.22);
      --sa-gradient:      linear-gradient(135deg, #7c3aed 0%, #0d9488 100%);
      --emerald-gradient: linear-gradient(135deg, #059669 0%, #10b981 100%);
      --amber-gradient:   linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
      --rose-gradient:    linear-gradient(135deg, #e11d48 0%, #f43f5e 100%);
    }

    .font-mono {
      font-family: 'JetBrains Mono', monospace;
    }

    /* Executive Governance Notice Banner */
    .executive-boundary-banner {
      background: linear-gradient(135deg, rgba(30, 41, 59, 0.98), rgba(15, 23, 42, 1));
      border: 1px solid rgba(148, 163, 184, 0.2);
      border-left: 4px solid #10b981;
      border-radius: 14px;
      padding: 16px 20px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }

    .boundary-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 20px;
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .boundary-text h4 {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 800;
      color: #f8fafc;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .boundary-text p {
      margin: 4px 0 0;
      font-size: 0.8rem;
      color: #94a3b8;
      line-height: 1.4;
    }

    /* Facility Selector Bar */
    .sa-filter-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 14px;
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      padding: 14px 20px;
      margin-bottom: 24px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.03);
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .filter-label {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .sa-select-input {
      padding: 8px 36px 8px 14px;
      border-radius: 10px;
      border: 1.5px solid var(--surface-border);
      background: var(--surface);
      color: var(--text-heading);
      font-family: inherit;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      transition: all 0.2s;
    }

    .sa-select-input:focus {
      outline: none;
      border-color: var(--sa-accent);
      box-shadow: 0 0 0 3px var(--sa-accent-soft);
    }

    /* Macro KPI Stat Cards Grid */
    .sa-macro-kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
      margin-bottom: 24px;
    }

    @media (max-width: 1200px) {
      .sa-macro-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
      .sa-macro-kpi-grid { grid-template-columns: 1fr; }
    }

    .macro-kpi-card {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      padding: 20px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.03);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: transform 0.2s, box-shadow 0.2s;
    }

    .macro-kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    }

    .macro-kpi-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
    }

    .macro-kpi-card.card-revenue::before { background: var(--emerald-gradient); }
    .macro-kpi-card.card-invoiced::before { background: var(--sa-gradient); }
    .macro-kpi-card.card-arrears::before { background: var(--rose-gradient); }
    .macro-kpi-card.card-efficiency::before { background: var(--amber-gradient); }

    .kpi-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
    }

    .kpi-title {
      font-size: 0.76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
    }

    .kpi-icon-wrap {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
    }

    .card-revenue .kpi-icon-wrap { background: rgba(16, 185, 129, 0.12); color: #10b981; }
    .card-invoiced .kpi-icon-wrap { background: rgba(124, 58, 237, 0.12); color: #7c3aed; }
    .card-arrears .kpi-icon-wrap { background: rgba(244, 63, 94, 0.12); color: #f43f5e; }
    .card-efficiency .kpi-icon-wrap { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }

    .kpi-value {
      font-size: 1.65rem;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.1;
      margin-bottom: 6px;
    }

    .kpi-subtext {
      font-size: 0.75rem;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* Section Panels */
    .sa-panel {
      background: var(--surface);
      border: 1px solid var(--surface-border);
      border-radius: var(--radius-xl);
      overflow: hidden;
      box-shadow: 0 2px 12px rgba(0,0,0,0.03);
      margin-bottom: 24px;
    }

    .sa-panel-header {
      padding: 18px 22px;
      border-bottom: 1px solid var(--surface-border-subtle);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .sa-panel-title {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .sa-panel-title h3 {
      font-size: 0.98rem;
      font-weight: 800;
      color: var(--text-heading);
      margin: 0;
    }

    .sa-panel-title p {
      font-size: 0.76rem;
      color: var(--text-muted);
      margin: 2px 0 0;
    }

    /* Data Tables */
    .sa-table-responsive {
      overflow-x: auto;
    }

    .sa-financial-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
      text-align: left;
    }

    .sa-financial-table thead th {
      background: var(--surface-secondary, #f8fafc);
      padding: 12px 16px;
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-bottom: 1px solid var(--surface-border);
    }

    .sa-financial-table tbody tr {
      border-bottom: 1px solid var(--surface-border-subtle);
      transition: background 0.15s;
    }

    .sa-financial-table tbody tr:hover {
      background: rgba(124, 58, 237, 0.03);
    }

    .sa-financial-table tbody td {
      padding: 14px 16px;
      vertical-align: middle;
      color: var(--text-body);
    }

    /* Progress Bar */
    .recovery-bar-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 110px;
    }

    .recovery-bar-track {
      flex: 1;
      height: 6px;
      background: var(--surface-border);
      border-radius: 10px;
      overflow: hidden;
    }

    .recovery-bar-fill {
      height: 100%;
      border-radius: 10px;
      background: var(--emerald-gradient);
    }

    .recovery-bar-val {
      font-size: 0.75rem;
      font-weight: 700;
      color: var(--text-heading);
      min-width: 38px;
    }

    /* Hospital Crest Badge */
    .hosp-badge-cell {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .hosp-initials {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: var(--sa-gradient);
      color: #fff;
      font-size: 0.75rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    /* HIPAA Masked Badge */
    .hipaa-mask-tag {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 7px;
      border-radius: 6px;
      background: rgba(100, 116, 139, 0.1);
      color: #475569;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.03em;
    }

    /* Status Badges */
    .badge-paid {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      border-radius: 20px;
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
      font-weight: 700;
      font-size: 0.72rem;
    }

    .badge-pending {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      border-radius: 20px;
      background: rgba(245, 158, 11, 0.12);
      color: #d97706;
      font-weight: 700;
      font-size: 0.72rem;
    }

    .badge-alert-crit {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 8px;
      border-radius: 20px;
      background: rgba(239, 68, 68, 0.15);
      color: #b91c1c;
      font-weight: 800;
      font-size: 0.72rem;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Grid 2-col */
    .sa-grid-two {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    @media (max-width: 1024px) {
      .sa-grid-two { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <!-- Navigation Sidebar -->
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>

  <!-- Central Primary Workspace -->
  <main class="viewport-full">

    <!-- Topbar Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge" style="background:var(--emerald-gradient);">
          <svg style="width:12px;height:12px;stroke:#fff;fill:none;stroke-width:2;" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
          Executive Financial Governance
        </div>
        <h1>
          Network Macro Treasury & Billing Audit
          <svg class="ui-ico" style="stroke: #10b981; width: 24px; height: 24px;" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </h1>
        <p>Enterprise cross-facility financial ledger, macro collection performance, and security intrusion auditing across all branches.</p>
      </div>

      <div class="banner-actions">
        <button class="btn-action-gradient" onclick="location.reload()" style="background:var(--sa-gradient);">
          <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24" style="stroke: white;"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
          Refresh Telemetry
        </button>
      </div>
    </div>

    <!-- Executive Boundary & HIPAA Privacy Banner -->
    <div class="executive-boundary-banner">
      <div style="display:flex; align-items:center; gap:14px;">
        <div style="font-size:1.8rem; width:44px; height:44px; border-radius:10px; background:rgba(16,185,129,0.15); display:flex; align-items:center; justify-content:center; color:#10b981;">
          🛡️
        </div>
        <div class="boundary-text">
          <h4>
            Super Admin Executive Oversight Mode
            <span class="boundary-badge">Auditor Restricted</span>
            <span class="hipaa-mask-tag">HIPAA Standards Enforced</span>
          </h4>
          <p>
            Transactional powers (Cash Collection, Bill Settlement, Line-Item Modification) are strictly restricted to local Branch Administrative Controllers.
            To uphold patient privacy laws (HIPAA/DGHS), granular patient diagnostic charges and personal identifiers are sanitized in this centralized macro command view.
          </p>
        </div>
      </div>
    </div>

    <!-- Facility Switcher Filter Bar -->
    <div class="sa-filter-bar">
      <form method="GET" action="financial_overview.php" id="hospFilterForm" class="filter-group">
        <span class="filter-label">Filter Facility:</span>
        <select name="hospital_id" class="sa-select-input" onchange="document.getElementById('hospFilterForm').submit()">
          <option value="0" <?= $filterHospitalId === 0 ? 'selected' : '' ?>>🌐 All Network Facilities (Consolidated Macro)</option>
          <?php foreach ($allHospitals as $h): ?>
            <option value="<?= (int)$h['hospital_id'] ?>" <?= $filterHospitalId === (int)$h['hospital_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($h['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($h['code'], ENT_QUOTES, 'UTF-8') ?>) — <?= htmlspecialchars($h['city'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($filterHospitalId > 0): ?>
          <a href="financial_overview.php" style="font-size:0.75rem; color:#ef4444; font-weight:700; text-decoration:none;">✕ Clear Filter</a>
        <?php endif; ?>
      </form>

      <div style="font-size:0.78rem; color:var(--text-muted);">
        Active Node: <strong><?= $filterHospitalId > 0 ? htmlspecialchars($allHospitals[array_search($filterHospitalId, array_column($allHospitals, 'hospital_id'))]['name'] ?? 'Facility') : 'Whole Health Network (6 Branches)' ?></strong>
      </div>
    </div>

    <!-- Macro KPI Metrics Grid -->
    <div class="sa-macro-kpi-grid">
      <!-- 1. Realized Revenue -->
      <div class="macro-kpi-card card-revenue">
        <div>
          <div class="kpi-head">
            <span class="kpi-title">Total Realized Revenue</span>
            <div class="kpi-icon-wrap">৳</div>
          </div>
          <div class="kpi-value font-mono">৳<?= number_format($totalCollected, 2) ?></div>
        </div>
        <div class="kpi-subtext">
          <span style="color:#059669; font-weight:700;">✓ Verified Collected</span> across branch cashiers
        </div>
      </div>

      <!-- 2. Gross Invoiced -->
      <div class="macro-kpi-card card-invoiced">
        <div>
          <div class="kpi-head">
            <span class="kpi-title">Gross Invoiced Value</span>
            <div class="kpi-icon-wrap">📋</div>
          </div>
          <div class="kpi-value font-mono">৳<?= number_format($grossInvoiced, 2) ?></div>
        </div>
        <div class="kpi-subtext">
          <strong><?= number_format($totalInvoices) ?></strong> total invoices issued
        </div>
      </div>

      <!-- 3. Outstanding Arrears -->
      <div class="macro-kpi-card card-arrears">
        <div>
          <div class="kpi-head">
            <span class="kpi-title">Outstanding Arrears (Due)</span>
            <div class="kpi-icon-wrap">⚠️</div>
          </div>
          <div class="kpi-value font-mono" style="color:#e11d48;">৳<?= number_format($totalOutstanding, 2) ?></div>
        </div>
        <div class="kpi-subtext">
          <strong><?= number_format($pendingInvoices) ?></strong> invoices pending full settlement
        </div>
      </div>

      <!-- 4. Recovery Rate -->
      <div class="macro-kpi-card card-efficiency">
        <div>
          <div class="kpi-head">
            <span class="kpi-title">Network Collection Rate</span>
            <div class="kpi-icon-wrap">📈</div>
          </div>
          <div class="kpi-value font-mono" style="color:#d97706;"><?= $recoveryRate ?>%</div>
        </div>
        <div class="kpi-subtext">
          <span><?= number_format($paidInvoices) ?> settled / <?= number_format($totalInvoices) ?> total</span>
        </div>
      </div>
    </div>

    <!-- Facility-wise Breakdown Table -->
    <div class="sa-panel">
      <div class="sa-panel-header">
        <div class="sa-panel-title">
          <svg class="ui-ico" style="stroke:var(--sa-accent); width:20px; height:20px;" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
          <div>
            <h3>Hospital Branch Financial Comparison & Performance</h3>
            <p>Breakdown of billings, collections, arrears, and recovery rates across all operational facilities</p>
          </div>
        </div>
        <span class="hipaa-mask-tag">Multi-Tenant Scoped</span>
      </div>

      <div class="sa-table-responsive">
        <table class="sa-financial-table">
          <thead>
            <tr>
              <th>Hospital Facility</th>
              <th>Location</th>
              <th>Invoices</th>
              <th>Gross Invoiced (৳)</th>
              <th>Collected Revenue (৳)</th>
              <th>Outstanding Due (৳)</th>
              <th>Recovery Efficiency</th>
              <th>Operational Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($facilityBreakdown as $fb): 
              $fGross = (float)$fb['gross_invoiced'];
              $fColl  = (float)$fb['total_collected'];
              $fDue   = (float)$fb['total_due'];
              $fRate  = $fGross > 0 ? round(($fColl / $fGross) * 100, 1) : 0;
              $code   = htmlspecialchars($fb['hospital_code'] ?: 'HP', ENT_QUOTES, 'UTF-8');
            ?>
            <tr>
              <td>
                <div class="hosp-badge-cell">
                  <div class="hosp-initials"><?= htmlspecialchars(substr($code, 0, 3), ENT_QUOTES, 'UTF-8') ?></div>
                  <div>
                    <div style="font-weight:700; color:var(--text-heading);"><?= htmlspecialchars($fb['hospital_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size:0.72rem; color:var(--text-muted);">Code: <?= $code ?> &bull; Branch #<?= (int)$fb['hospital_id'] ?></div>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($fb['city'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <strong><?= number_format((int)$fb['invoice_count']) ?></strong>
                <div style="font-size:0.7rem; color:var(--text-muted);"><?= (int)$fb['paid_count'] ?> paid &bull; <?= (int)$fb['pending_count'] ?> pending</div>
              </td>
              <td class="font-mono">৳<?= number_format($fGross, 2) ?></td>
              <td class="font-mono" style="color:#059669; font-weight:700;">৳<?= number_format($fColl, 2) ?></td>
              <td class="font-mono" style="color:<?= $fDue > 0 ? '#e11d48' : 'var(--text-muted)' ?>; font-weight:<?= $fDue > 0 ? '700' : '500' ?>;">
                ৳<?= number_format($fDue, 2) ?>
              </td>
              <td>
                <div class="recovery-bar-wrap">
                  <div class="recovery-bar-track">
                    <div class="recovery-bar-fill" style="width: <?= min(100, $fRate) ?>%;"></div>
                  </div>
                  <span class="recovery-bar-val font-mono"><?= $fRate ?>%</span>
                </div>
              </td>
              <td>
                <span class="badge-paid" style="<?= $fb['operational_status'] === 'Active' ? '' : 'background:rgba(239,68,68,0.1); color:#ef4444;' ?>">
                  ● <?= htmlspecialchars($fb['operational_status'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Grid Two: Payment Channel Distribution & Central Security Audit Trail -->
    <div class="sa-grid-two">
      <!-- 1. Payment Channels -->
      <div class="sa-panel">
        <div class="sa-panel-header">
          <div class="sa-panel-title">
            <svg class="ui-ico" style="stroke:#059669; width:20px; height:20px;" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <div>
              <h3>Realized Revenue by Payment Channel</h3>
              <p>Network treasury collection method distribution</p>
            </div>
          </div>
        </div>

        <div class="sa-table-responsive">
          <table class="sa-financial-table">
            <thead>
              <tr>
                <th>Channel</th>
                <th>Transactions</th>
                <th>Volume (৳)</th>
                <th>Share %</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($paymentMethods)): ?>
                <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:2rem;">No verified payment ledger transactions yet.</td></tr>
              <?php else: ?>
                <?php 
                $sumPay = array_sum(array_column($paymentMethods, 'total_amount'));
                foreach ($paymentMethods as $pm): 
                  $pAmt = (float)$pm['total_amount'];
                  $pct  = $sumPay > 0 ? round(($pAmt / $sumPay) * 100, 1) : 0;
                ?>
                <tr>
                  <td>
                    <div style="font-weight:700; color:var(--text-heading); display:flex; align-items:center; gap:8px;">
                      <span>💳</span> <?= htmlspecialchars($pm['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td><strong><?= number_format((int)$pm['tx_count']) ?></strong> txns</td>
                  <td class="font-mono" style="font-weight:700; color:#059669;">৳<?= number_format($pAmt, 2) ?></td>
                  <td>
                    <div class="recovery-bar-wrap">
                      <div class="recovery-bar-track">
                        <div class="recovery-bar-fill" style="width: <?= min(100, $pct) ?>%; background: var(--sa-gradient);"></div>
                      </div>
                      <span class="recovery-bar-val font-mono"><?= $pct ?>%</span>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- 2. Security & Financial Audit Trail -->
      <div class="sa-panel">
        <div class="sa-panel-header">
          <div class="sa-panel-title">
            <svg class="ui-ico" style="stroke:#e11d48; width:20px; height:20px;" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <div>
              <h3>Security & Financial Intrusion Guard Log</h3>
              <p>Auditing cash collections and blocked cross-branch tampering</p>
            </div>
          </div>
          <span class="boundary-badge" style="background:rgba(239,68,68,0.1); color:#ef4444;">Live Guard</span>
        </div>

        <div class="sa-table-responsive" style="max-height: 380px; overflow-y: auto;">
          <table class="sa-financial-table">
            <thead>
              <tr>
                <th>Event / Security Level</th>
                <th>Facility & Actor</th>
                <th>Target / Description</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($financialAudits)): ?>
                <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:2rem;">No recent financial audit events.</td></tr>
              <?php else: ?>
                <?php foreach ($financialAudits as $fa): 
                  $isCritical = $fa['security_level'] === 'CRITICAL' || $fa['action'] === 'UNAUTHORIZED_PAYMENT_ATTEMPT';
                ?>
                <tr style="<?= $isCritical ? 'background: rgba(239,68,68,0.05);' : '' ?>">
                  <td>
                    <?php if ($isCritical): ?>
                      <span class="badge-alert-crit">🚨 BLOCKED INTRUSION</span>
                    <?php else: ?>
                      <span class="badge-paid">✓ <?= htmlspecialchars($fa['action'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="font-weight:700; color:var(--text-heading); font-size:0.78rem;">
                      <?= htmlspecialchars($fa['actor_name'] ?: 'System', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div style="font-size:0.7rem; color:var(--text-muted);">
                      <?= htmlspecialchars($fa['actor_hosp_name'] ?: 'Branch #' . ($fa['actor_hosp_id'] ?? 'Central'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-size:0.75rem; color:var(--text-body); max-width:280px; word-break:break-word;">
                      <?= htmlspecialchars($fa['description'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php if (!empty($fa['target_entity'])): ?>
                      <div style="font-size:0.7rem; color:var(--sa-accent); font-weight:700;">Target: <?= htmlspecialchars($fa['target_entity'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:0.72rem; color:var(--text-muted); white-space:nowrap;">
                    <?= date('d M H:i', strtotime($fa['created_at'])) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Recent Verified Cashier Ledger (HIPAA Masked Macro Stream) -->
    <div class="sa-panel">
      <div class="sa-panel-header">
        <div class="sa-panel-title">
          <svg class="ui-ico" style="stroke:#10b981; width:20px; height:20px;" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          <div>
            <h3>Recent Network Collections Ledger (Macro View)</h3>
            <p>Verified cash payments logged across hospital branches with HIPAA patient masking</p>
          </div>
        </div>
        <span class="hipaa-mask-tag">🔒 Clinical Details Redacted per Privacy Standards</span>
      </div>

      <div class="sa-table-responsive">
        <table class="sa-financial-table">
          <thead>
            <tr>
              <th>Receipt #</th>
              <th>Branch Facility</th>
              <th>Invoice Number</th>
              <th>Patient (HIPAA Masked)</th>
              <th>Collected Amount</th>
              <th>Payment Method</th>
              <th>Branch Cashier</th>
              <th>Collection Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentTransactions)): ?>
              <tr>
                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No payment ledger records found for the selected branch scope.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentTransactions as $tx): 
                $maskedPat = maskPatientName((int)$tx['patient_id'], $tx['patient_name'] ?? '');
              ?>
              <tr>
                <td class="font-mono" style="font-weight:700;">#PAY-<?= (int)$tx['payment_id'] ?></td>
                <td>
                  <span style="font-weight:700; color:var(--text-heading);"><?= htmlspecialchars($tx['hospital_name'], ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td class="font-mono" style="font-weight:600; color:var(--sa-accent);"><?= htmlspecialchars($tx['invoice_number'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <span class="hipaa-mask-tag">
                    <svg style="width:10px;height:10px;stroke:#64748b;fill:none;stroke-width:2;" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <?= htmlspecialchars($maskedPat, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td class="font-mono" style="font-weight:800; color:#059669;">
                  ৳<?= number_format((float)$tx['amount'], 2) ?>
                </td>
                <td>
                  <span style="font-weight:600; font-size:0.78rem;">
                    <?= htmlspecialchars($tx['payment_method'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                  <?php if (!empty($tx['transaction_reference'])): ?>
                    <span style="font-size:0.7rem; color:var(--text-muted); display:block;">Ref: <?= htmlspecialchars($tx['transaction_reference'], ENT_QUOTES, 'UTF-8') ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-size:0.8rem; font-weight:600; color:var(--text-heading);"><?= htmlspecialchars($tx['collector_name'] ?: 'Staff', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td style="font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                  <?= date('d M Y, h:i A', strtotime($tx['payment_date'])) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>

</body>
</html>

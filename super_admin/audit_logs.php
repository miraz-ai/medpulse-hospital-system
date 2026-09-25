<?php
/**
 * MedPulse Super Admin — Network Audit Logs
 */
require_once __DIR__ . '/../includes/super_admin_auth.php';

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $logs  = $pdo->prepare("SELECT log_id, action, description, category, ip_address, user_id, created_at FROM audit_logs ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
    $logs->execute();
    $logs = $logs->fetchAll();
    $totalPages = max(1, (int)ceil($total / $limit));
} catch (PDOException $e) { error_log($e->getMessage()); $logs = []; $total = 0; $totalPages = 1; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MedPulse | Network Audit Logs</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/patient_dashboard.css">
  <link rel="stylesheet" href="../assets/css/admin/audit-logs.css?v=<?= time() ?>">
  <style>
    :root{--sa-accent:#7c3aed;--sa-accent-soft:rgba(124,58,237,.10);--sa-gradient:linear-gradient(135deg,#7c3aed 0%,#0d9488 100%);}
    .sa-welcome-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--sa-gradient);color:#fff;border-radius:20px;font-size:0.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;}
    .pager{display:flex;gap:8px;justify-content:center;padding:20px 0;}
    .pager a,.pager span{padding:7px 14px;border-radius:8px;font-size:0.82rem;font-weight:600;text-decoration:none;border:1px solid var(--surface-border);color:var(--text-body);}
    .pager a:hover{border-color:var(--sa-accent);color:var(--sa-accent);}
    .pager .current{background:var(--sa-gradient);color:#fff;border-color:transparent;}
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/../includes/super_admin_sidebar.php'; ?>
  <main class="viewport-full">
    <div class="welcome-banner">
      <div class="welcome-text">
        <div class="sa-welcome-badge">Super Administrator</div>
        <h1>Network Audit Logs</h1>
        <p><?= number_format($total) ?> total events across the entire MedPulse network.</p>
      </div>
    </div>

    <section class="admin-stack-card">
      <div class="admin-table-wrap">
        <table class="admin-data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Action</th>
              <th>Category</th>
              <th>Description</th>
              <th>IP Address</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
              <td style="color:var(--text-muted);font-size:0.75rem;"><?= (int)$log['log_id'] ?></td>
              <td><strong style="color:var(--text-heading);font-size:0.85rem;"><?= htmlspecialchars($log['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td>
                <span class="license-chip"><?= htmlspecialchars(strtoupper($log['category'] ?? 'SYSTEM'), ENT_QUOTES, 'UTF-8') ?></span>
              </td>
              <td style="max-width:340px;font-size:0.8rem;color:var(--text-body);">
                <?= htmlspecialchars($log['description'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td style="font-size:0.78rem;color:var(--text-muted);"><?= htmlspecialchars($log['ip_address'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;">
                <?= htmlspecialchars(date('d M Y, h:i A', strtotime($log['created_at'])), ENT_QUOTES, 'UTF-8') ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No audit logs found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>">← Prev</a><?php endif; ?>
      <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="?page=<?= $p ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

  </main>
</body>
</html>

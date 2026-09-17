/**
 * MedPulse Admin – Verification Queue View Script
 * Handles: approve / reject pending doctor/staff applications with animated
 * row removal and live counter sync (badge, sidebar, KPI card).
 * Depends on: showToast() defined in admin_sidebar.php
 */
document.addEventListener('DOMContentLoaded', () => {

  window.executeAdminAction = async function (userId, action, buttonEl) {
    const row = document.getElementById(`row-user-${userId}`);
    const originalHtml = buttonEl.innerHTML;
    buttonEl.disabled = true;
    buttonEl.innerHTML = '<span style="font-size: 0.72rem;">Updating...</span>';

    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    try {
      const formData = new FormData();
      formData.append('user_id', userId);
      formData.append('action', action);
      formData.append('csrf_token', csrfToken);

      const response = await fetch('../backend/admin_actions.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();

      if (result.status === 'success') {
        showToast(result.message, 'success');

        if (action === 'approve' || action === 'reject') {
          if (row) {
            row.style.transition = 'all 0.35s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(20px)';
            setTimeout(() => {
              row.remove();
              syncPendingCounters();
            }, 350);
          }
        }
      } else {
        showToast(result.message || 'Operation failed. Please retry.', 'error');
        buttonEl.disabled = false;
        buttonEl.innerHTML = originalHtml;
      }
    } catch (err) {
      showToast('Server communication failure. Check connection.', 'error');
      buttonEl.disabled = false;
      buttonEl.innerHTML = originalHtml;
    }
  };

  window.syncPendingCounters = function () {
    const tbody = document.querySelector('#pendingTable tbody');
    const rows = tbody ? tbody.querySelectorAll('tr') : [];
    const remaining = rows.length;

    const headerBadge = document.getElementById('pendingQueueBadge');
    if (headerBadge) {
      headerBadge.innerText = `${remaining} PENDING APPLICANTS`;
    }

    const sidebarBadge = document.getElementById('sidebarPendingBadge');
    if (sidebarBadge) {
      if (remaining > 0) {
        sidebarBadge.innerText = `${remaining} NEW`;
      } else {
        sidebarBadge.style.display = 'none';
      }
    }

    const kpiNumber = document.getElementById('kpiPendingCount');
    if (kpiNumber) {
      kpiNumber.innerText = String(remaining).padStart(2, '0');
    }

    const kpiBadge = document.getElementById('kpiPendingBadge');
    if (kpiBadge) {
      if (remaining === 0) {
        kpiBadge.className = 'stat-card-badge badge-green';
        kpiBadge.innerHTML = '<span>All Clear</span>';
      } else {
        kpiBadge.className = 'stat-card-badge badge-amber';
        kpiBadge.innerHTML = '<span>Review Required</span>';
      }
    }

    if (remaining === 0) {
      const tableWrap = document.getElementById('pendingTableWrap');
      const emptyCard = document.getElementById('pendingEmptyCard');
      if (tableWrap) tableWrap.style.display = 'none';
      if (emptyCard) emptyCard.style.display = 'block';
    }
  };

});

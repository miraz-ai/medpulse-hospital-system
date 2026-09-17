/**
 * MedPulse Admin – Manage Doctors View Script
 * Handles: suspend / activate doctor accounts with optimistic in-DOM badge
 * and action-cell updates (no full page reload needed).
 * Depends on: showToast() defined in admin_sidebar.php
 */
document.addEventListener('DOMContentLoaded', () => {

  window.executeAdminAction = async function (userId, action, buttonEl) {
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

        const statusCell = document.getElementById(`status-cell-${userId}`);
        const actionCell = document.getElementById(`action-cell-${userId}`);

        if (action === 'suspend') {
          if (statusCell) {
            statusCell.innerHTML = '<span class="status-badge-suspended"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>Suspended</span>';
          }
          if (actionCell) {
            actionCell.innerHTML = `
              <button class="btn-table-action btn-table-activate" onclick="executeAdminAction(${userId}, 'activate', this)" title="Reactivate portal access">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Reactivate
              </button>
            `;
          }
        } else if (action === 'activate') {
          if (statusCell) {
            statusCell.innerHTML = '<span class="status-badge-active"><svg class="ui-ico ui-ico-sm" style="width: 10px; height: 10px;" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>Active</span>';
          }
          if (actionCell) {
            actionCell.innerHTML = `
              <button class="btn-table-action btn-table-suspend" onclick="executeAdminAction(${userId}, 'suspend', this)" title="Suspend portal access">
                <svg class="ui-ico ui-ico-sm" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                Suspend
              </button>
            `;
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

});

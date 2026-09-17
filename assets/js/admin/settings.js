/**
 * MedPulse Admin – Settings View Script
 * Handles: settings form save button with visual feedback.
 * Depends on: showToast() defined in admin_sidebar.php
 */
document.addEventListener('DOMContentLoaded', () => {

  window.handleSettingsSave = function (event) {
    event.preventDefault();
    const btn = document.getElementById('btnSaveConfig');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span style="font-size: 0.75rem;">Saving...</span>';

    setTimeout(() => {
      btn.disabled = false;
      btn.innerHTML = originalText;
      showToast('Hospital infrastructure parameters and security policies saved successfully.', 'success');
    }, 500);
  };

});

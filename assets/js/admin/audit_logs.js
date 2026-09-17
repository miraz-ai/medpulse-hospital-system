/**
 * MedPulse Admin – Audit Logs View Script
 * Handles: real-time client-side search/filter on the audit events table.
 */
document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.getElementById('auditSearchInput');
  const tableRows   = document.querySelectorAll('.audit-event-row');
  const emptyState  = document.getElementById('emptyAuditResults');
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
        if (emptyState)   emptyState.style.display   = 'block';
      } else {
        if (tableElement) tableElement.style.display = 'table';
        if (emptyState)   emptyState.style.display   = 'none';
      }
    });
  }
});

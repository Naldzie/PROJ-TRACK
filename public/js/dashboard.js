/* ══════════════════════════════════════════════════════════════
   DASHBOARD JS
   public/js/dashboard.js

   Requires DASHBOARD_FILTER_URL to be defined in the blade
   before this file loads (inline <script> block in dashboard.blade.php).

   Contains:
     - dbSwitchTab()           Tab switching logic
     - filterDashboardTable()  AJAX filter for ongoing projects table
══════════════════════════════════════════════════════════════ */


// ── TAB SWITCHING ─────────────────────────────────────────────────────────
// Called by onclick="dbSwitchTab(this)" on each .db-tab-btn button.
// Activates the clicked tab and its corresponding pane.
// Special case: invalidates Leaflet map size when map tab opens.
function dbSwitchTab(btn) {
    document.querySelectorAll('.db-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.db-tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');

    const target = document.getElementById(btn.dataset.target);
    target.classList.add('active');

    // Fix Leaflet map rendering when tab becomes visible
    if (btn.dataset.target === 'tab-map' && window.campusMap) {
        setTimeout(() => window.campusMap.invalidateSize(), 100);
    }
}


// ── DASHBOARD TABLE FILTER (AJAX) ─────────────────────────────────────────
// Fetches filtered project rows from DashboardController@filter.
// Replaces the table tbody with the server response.
// Clicking the same active pill again restores the default 10 rows.
// Requires DASHBOARD_FILTER_URL defined in blade before this file loads.

let dbActiveFilter = null;

async function filterDashboardTable(type, btn) {

    // Toggle: clicking the currently active pill clears the filter
    if (dbActiveFilter === type) {
        type = 'default';
    }

    // Update pill highlight — remove from all, apply to clicked one
    document.querySelectorAll('.alert-pill')
        .forEach(p => p.classList.remove('active-filter'));
    if (type !== 'default' && btn) {
        btn.classList.add('active-filter');
    }

    // Show loading spinner while fetch is in progress
    const tbody = document.querySelector('.db-proj-tbl tbody');
    tbody.innerHTML = `
        <tr>
            <td colspan="6" class="db-empty">
                <div class="spinner-border spinner-border-sm" role="status"
                     style="width:16px;height:16px;margin-bottom:8px"></div>
                <div class="db-empty-title">Loading projects…</div>
            </td>
        </tr>`;

    try {
        const res = await fetch(DASHBOARD_FILTER_URL + '?type=' + type, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!res.ok) throw new Error('Server returned ' + res.status);

        const html = await res.text();
        tbody.innerHTML = html;

        // Update the filter badge above the table
        const badge = document.getElementById('db-filter-badge');
        const label = document.getElementById('db-filter-label');

        if (type === 'default') {
            badge.style.display = 'none';
            dbActiveFilter = null;
        } else {
            const labelMap = {
                overdue: 'Overdue Projects',
                atrisk:  'At Risk',
                pending: 'Pending Updates',
                inspection_overdue: 'Overdue Inspections',
                pending_extensions: 'Extension Requests'
            };
            label.textContent = 'Showing: ' + labelMap[type];
            badge.style.display = 'inline-flex';
            dbActiveFilter = type;
        }

    } catch (err) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="db-empty" style="color:var(--red-600)">
                    <i class="bi bi-exclamation-circle"></i>
                    <div class="db-empty-title">Failed to load</div>
                    <div>Please refresh the page to try again.</div>
                </td>
            </tr>`;
    }
}
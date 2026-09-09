<!-- === S-CURVE HEADER PROPOSALS MANAGEMENT MODAL (E1-2 review) === -->
<div class="modal fade" id="manageScurveHeadersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-table"></i> Review S-Curve Layouts
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Header blocks captured from failed S-curve imports.
                    <strong>Approve</strong> to trust a layout for its contractor (column maps
                    attach in a later step),
                    <strong>Reject</strong> to discard it. Approved layouts only ever fill in
                    when the built-in rules miss — nothing approved here can override them.
                </p>

                <!-- Status filter tabs -->
                <ul class="nav nav-pills gap-2 mb-3" id="schFilterTabs">
                    <li class="nav-item">
                        <button class="nav-link active btn-sm" data-sch-filter="proposed" onclick="schSetFilter('proposed')">Proposed</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link btn-sm" data-sch-filter="live" onclick="schSetFilter('live')">Live</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link btn-sm" data-sch-filter="all" onclick="schSetFilter('all')">All</button>
                    </li>
                </ul>

                <!-- Captures table -->
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Header block</th>
                                <th>Contractor</th>
                                <th title="How many failed imports produced these headers">Seen</th>
                                <th title="Where this capture was learned">Origin</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="scurveHeadersList">
                            <!-- Captures loaded here -->
                        </tbody>
                    </table>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// ===================================================
// E1-2 REVIEW — S-CURVE HEADER PROPOSALS
// Cloned from the keyword review desk. Differences: header-block first column,
// contractor column (blank = global map), has_map marker for E1-3, same status
// tabs, approve/reject instead of add/edit, data-* delegation (quote-safe),
// staff+admin gates.
// ===================================================
let _schFilter = 'proposed';

// Base path for the review endpoints, role-aware (/admin or /staff).
function schBase() {
    const path = window.location.pathname || '';
    return path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
}

function schHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    };
}

// ===================================================
// EXTRACT ERROR MESSAGE FROM LARAVEL RESPONSE
// ===================================================
function schExtractErrorMessage(data, fallback = 'Something went wrong') {
    if (data && data.errors) {
        const firstField = Object.values(data.errors)[0];
        if (Array.isArray(firstField) && firstField.length > 0) {
            return firstField[0];
        }
    }
    return (data && data.message) ? data.message : fallback;
}

// ===================================================
// ESCAPE HTML
// ===================================================
function schEscapeHtml(text) {
    if (text === null || text === undefined) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ===================================================
// FILTER TABS
// ===================================================
function schSetFilter(status) {
    _schFilter = status;
    document.querySelectorAll('#schFilterTabs .nav-link').forEach(el => {
        el.classList.toggle('active', el.getAttribute('data-sch-filter') === status);
    });
    loadScurveHeadersForManagement();
}

// ===================================================
// LOAD CAPTURES FOR REVIEW
// ===================================================
async function loadScurveHeadersForManagement() {
    try {
        const url = _schFilter === 'all'
            ? schBase() + '/scurve-header-proposals/all'
            : schBase() + '/scurve-header-proposals/all?status=' + encodeURIComponent(_schFilter);
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const rows = await response.json();

        const tbody = document.getElementById('scurveHeadersList');
        tbody.innerHTML = '';

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-muted">No ' + schEscapeHtml(_schFilter) + ' layouts yet. Failed S-curve imports will appear here.</td></tr>';
            return;
        }

        let html = '';
        rows.forEach((row) => {
            const badge = row.status === 'live'
                ? '<span class="badge bg-success">Live</span>'
                : '<span class="badge bg-warning text-dark">Proposed</span>';
            const header = row.header_rows_text
                ? schEscapeHtml(row.header_rows_text.substring(0, 120)) + (row.header_rows_text.length > 120 ? '…' : '')
                : '<span class="text-muted">(empty header block)</span>';
            const headerTip = row.header_rows_text
                ? ' title="' + schEscapeHtml(row.header_rows_text.substring(0, 500)) + '"'
                : '';
            const contractor = row.contractor
                ? schEscapeHtml(row.contractor)
                : '<span class="text-muted">Global</span>';
            const origin = row.source === 'AI-assisted'
                ? '<span class="badge bg-info text-dark" title="Kept from an AI-assisted read">AI-assisted</span>'
                : '<span class="badge bg-secondary" title="Captured from a failed import">Auto-capture</span>';
            const mapMark = row.has_map
                ? ' <span class="badge bg-primary" title="A column map is attached">Mapped</span>'
                : '';
            const approveBtn = row.status === 'live' ? '' :
                '<button class="btn btn-sm btn-outline-success" data-sch-approve="' + row.id + '" title="Trust this layout"><i class="bi bi-check-lg"></i> Approve</button>';
            html += '<tr>' +
                '<td><span' + headerTip + '>' + header + '</span>' + mapMark + '</td>' +
                '<td>' + contractor + '</td>' +
                '<td><span class="badge bg-secondary">' + row.occurrences + '×</span></td>' +
                '<td>' + origin + '</td>' +
                '<td>' + badge + '</td>' +
                '<td class="text-end"><div class="d-flex gap-2 justify-content-end">' + approveBtn +
                '<button class="btn btn-sm btn-outline-danger" data-sch-reject="' + row.id + '" title="Discard this capture"><i class="bi bi-trash"></i></button>' +
                '</div></td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
    } catch (error) {
        console.error('Error loading S-curve layouts:', error);
        showToast('Failed to load S-curve layouts', 'error');
    }
}

// ===================================================
// APPROVE / REJECT (delegated — IDs travel via data-*, never string args)
// ===================================================
document.addEventListener('click', function (e) {
    const approveBtn = e.target.closest ? e.target.closest('[data-sch-approve]') : null;
    if (approveBtn) {
        schApproveProposal(approveBtn.getAttribute('data-sch-approve'));
        return;
    }
    const rejectBtn = e.target.closest ? e.target.closest('[data-sch-reject]') : null;
    if (rejectBtn) {
        schRejectProposal(rejectBtn.getAttribute('data-sch-reject'));
    }
});

async function schApproveProposal(id) {
    try {
        const response = await fetch(schBase() + '/scurve-header-proposals/' + encodeURIComponent(id) + '/approve', {
            method: 'PATCH',
            headers: schHeaders()
        });

        let data = {};
        try {
            data = await response.json();
        } catch {}

        if (!response.ok) {
            showToast(schExtractErrorMessage(data, 'Failed to approve layout'), 'error');
            return;
        }

        showToast('Layout approved — the reader may now consult it', 'success');
        loadScurveHeadersForManagement();
    } catch (error) {
        console.error('Error approving layout:', error);
        showToast('Network error — failed to approve layout', 'error');
    }
}

function schRejectProposal(id) {
    showDeleteConfirmation(
        'Reject Layout',
        'Discard this captured header block?<br><small style="color:#6b7280;">Future failed imports can capture it again.</small>',
        async () => {
            try {
                const response = await fetch(schBase() + '/scurve-header-proposals/' + encodeURIComponent(id), {
                    method: 'DELETE',
                    headers: schHeaders()
                });

                let data = {};
                try {
                    data = await response.json();
                } catch {}

                if (!response.ok) {
                    showToast(schExtractErrorMessage(data, 'Failed to reject layout'), 'error');
                    return;
                }

                showToast('Layout rejected', 'success');
                loadScurveHeadersForManagement();
            } catch (error) {
                console.error('Error rejecting layout:', error);
                showToast('Network error — failed to reject layout', 'error');
            }
        }
    );
}

// ===================================================
// LOAD WHEN MODAL OPENS (standalone — no return-modal chaining;
// the review desk is its own destination, not stacked on create)
// ===================================================
document.addEventListener('show.bs.modal', function (e) {
    if (e.target.id === 'manageScurveHeadersModal') {
        loadScurveHeadersForManagement();
    }
});

// ===================================================
// LAUNCHER (used by menu entries)
// ===================================================
function openManageScurveHeaders() {
    const modalEl = document.getElementById('manageScurveHeadersModal');
    if (!modalEl) return;
    new bootstrap.Modal(modalEl).show();
}
</script>

<!-- === PARSER KEYWORD PROPOSALS MANAGEMENT MODAL (L2 review) === -->
<div class="modal fade" id="manageParserKeywordsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-magic"></i> Review Learned Keywords
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Wording suggestions harvested from project-form corrections.
                    <strong>Approve</strong> to let the reader use a wording,
                    <strong>Reject</strong> to discard it. Learned wording only ever fills a box
                    when the built-in labels miss — nothing approved here can override them.
                </p>

                <!-- Status filter tabs -->
                <ul class="nav nav-pills gap-2 mb-3" id="pkpFilterTabs">
                    <li class="nav-item">
                        <button class="nav-link active btn-sm" data-pkp-filter="proposed" onclick="pkpSetFilter('proposed')">Proposed</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link btn-sm" data-pkp-filter="live" onclick="pkpSetFilter('live')">Live</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link btn-sm" data-pkp-filter="all" onclick="pkpSetFilter('all')">All</button>
                    </li>
                </ul>

                <!-- Proposals table -->
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Suggested wording</th>
                                <th>Box</th>
                                <th title="How many corrections produced this wording">Seen</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="parserKeywordsList">
                            <!-- Proposals loaded here -->
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
// L2 REVIEW — PARSER KEYWORD PROPOSALS
// Cloned from the budget-source manage modal. Differences: table (not list-group),
// status filter tabs, approve/reject instead of add/edit, data-* delegation (no
// inline string args, so quotes in wording cannot break buttons), staff+admin gates.
// ===================================================
let _pkpFilter = 'proposed';

// Base path for the review endpoints, role-aware (/admin or /staff).
function pkpBase() {
    const path = window.location.pathname || '';
    return path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
}

function pkpHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    };
}

// ===================================================
// EXTRACT ERROR MESSAGE FROM LARAVEL RESPONSE
// ===================================================
function pkpExtractErrorMessage(data, fallback = 'Something went wrong') {
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
function pkpEscapeHtml(text) {
    if (text === null || text === undefined) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ===================================================
// FIELD LABELS (core field name → human box name)
// ===================================================
function pkpFieldLabel(field) {
    const map = {
        title: 'Project Title', location: 'Location', description: 'Description',
        budget: 'Contract Price', abc: 'ABC', budgetSource: 'Budget Source',
        category: 'Category', startDate: 'Start Date', endDate: 'End Date',
        duration: 'Duration', contractor: 'Contractor',
    };
    return map[field] || field;
}

// ===================================================
// FILTER TABS
// ===================================================
function pkpSetFilter(status) {
    _pkpFilter = status;
    document.querySelectorAll('#pkpFilterTabs .nav-link').forEach(el => {
        el.classList.toggle('active', el.getAttribute('data-pkp-filter') === status);
    });
    loadParserKeywordsForManagement();
}

// ===================================================
// LOAD PROPOSALS FOR REVIEW
// ===================================================
async function loadParserKeywordsForManagement() {
    try {
        const url = _pkpFilter === 'all'
            ? pkpBase() + '/parser-keyword-proposals/all'
            : pkpBase() + '/parser-keyword-proposals/all?status=' + encodeURIComponent(_pkpFilter);
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const rows = await response.json();

        const tbody = document.getElementById('parserKeywordsList');
        tbody.innerHTML = '';

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted">No ' + pkpEscapeHtml(_pkpFilter) + ' suggestions yet. Corrections made while creating projects will appear here.</td></tr>';
            return;
        }

        let html = '';
        rows.forEach((row) => {
            const badge = row.status === 'live'
                ? '<span class="badge bg-success">Live</span>'
                : '<span class="badge bg-warning text-dark">Proposed</span>';
            const label = row.label_phrase ? pkpEscapeHtml(row.label_phrase) : '<span class="text-muted">(field count only — no wording found)</span>';
            const excerpt = row.excerpt
                ? '<br><small class="text-muted" title="' + pkpEscapeHtml(row.excerpt) + '">' + pkpEscapeHtml(row.excerpt.substring(0, 90)) + (row.excerpt.length > 90 ? '…' : '') + '</small>'
                : '';
            const approveBtn = row.status === 'live' ? '' :
                '<button class="btn btn-sm btn-outline-success" data-pkp-approve="' + row.id + '" title="Let the reader use this wording"><i class="bi bi-check-lg"></i> Approve</button>';
            html += '<tr>' +
                '<td>' + label + excerpt + '</td>' +
                '<td>' + pkpEscapeHtml(pkpFieldLabel(row.field)) + '</td>' +
                '<td><span class="badge bg-secondary">' + row.occurrences + '×</span></td>' +
                '<td>' + badge + '</td>' +
                '<td class="text-end"><div class="d-flex gap-2 justify-content-end">' + approveBtn +
                '<button class="btn btn-sm btn-outline-danger" data-pkp-reject="' + row.id + '" title="Discard this suggestion"><i class="bi bi-trash"></i></button>' +
                '</div></td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
    } catch (error) {
        console.error('Error loading keyword proposals:', error);
        showToast('Failed to load keyword suggestions', 'error');
    }
}

// ===================================================
// APPROVE / REJECT (delegated — IDs travel via data-*, never string args)
// ===================================================
document.addEventListener('click', function (e) {
    const approveBtn = e.target.closest ? e.target.closest('[data-pkp-approve]') : null;
    if (approveBtn) {
        pkpApproveProposal(approveBtn.getAttribute('data-pkp-approve'));
        return;
    }
    const rejectBtn = e.target.closest ? e.target.closest('[data-pkp-reject]') : null;
    if (rejectBtn) {
        pkpRejectProposal(rejectBtn.getAttribute('data-pkp-reject'));
    }
});

async function pkpApproveProposal(id) {
    try {
        const response = await fetch(pkpBase() + '/parser-keyword-proposals/' + encodeURIComponent(id) + '/approve', {
            method: 'PATCH',
            headers: pkpHeaders()
        });

        let data = {};
        try {
            data = await response.json();
        } catch {}

        if (!response.ok) {
            showToast(pkpExtractErrorMessage(data, 'Failed to approve suggestion'), 'error');
            return;
        }

        showToast('Wording approved — the reader may now use it', 'success');
        loadParserKeywordsForManagement();
    } catch (error) {
        console.error('Error approving proposal:', error);
        showToast('Network error — failed to approve suggestion', 'error');
    }
}

function pkpRejectProposal(id) {
    showDeleteConfirmation(
        'Reject Suggestion',
        'Discard this learned wording suggestion?<br><small style="color:#6b7280;">Future corrections can suggest it again.</small>',
        async () => {
            try {
                const response = await fetch(pkpBase() + '/parser-keyword-proposals/' + encodeURIComponent(id), {
                    method: 'DELETE',
                    headers: pkpHeaders()
                });

                let data = {};
                try {
                    data = await response.json();
                } catch {}

                if (!response.ok) {
                    showToast(pkpExtractErrorMessage(data, 'Failed to reject suggestion'), 'error');
                    return;
                }

                showToast('Suggestion rejected', 'success');
                loadParserKeywordsForManagement();
            } catch (error) {
                console.error('Error rejecting proposal:', error);
                showToast('Network error — failed to reject suggestion', 'error');
            }
        }
    );
}

// ===================================================
// LOAD WHEN MODAL OPENS (standalone — no return-modal chaining;
// the review desk is its own destination, not stacked on create)
// ===================================================
document.addEventListener('show.bs.modal', function (e) {
    if (e.target.id === 'manageParserKeywordsModal') {
        loadParserKeywordsForManagement();
    }
});

// ===================================================
// LAUNCHER (used by menu entries + create-project popup button)
// ===================================================
function openManageParserKeywords() {
    const modalEl = document.getElementById('manageParserKeywordsModal');
    if (!modalEl) return;
    new bootstrap.Modal(modalEl).show();
}
</script>

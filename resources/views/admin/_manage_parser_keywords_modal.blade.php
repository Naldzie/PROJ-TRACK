<!-- === PARSER KEYWORD PROPOSALS MANAGEMENT MODAL (L2 review) === -->
{{-- Styles: public/css/admin/projects/parser-keywords-modal.css (linked by both layouts).
     2026-09-21 redesign: fixed-size dialog (only the list scrolls), the learned wording is
     the headline of each row, and the source excerpt is hidden behind a toggle. --}}
<div class="modal fade" id="manageParserKeywordsModal" tabindex="-1" aria-labelledby="pkpTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered pkp-dialog">
        <div class="modal-content pkp-content">

            <!-- Header -->
            <div class="pkp-header">
                <div class="pkp-header-icon"><i class="bi bi-magic"></i></div>
                <div>
                    <h5 class="pkp-title" id="pkpTitle">Review Learned Keywords</h5>
                    <p class="pkp-subtitle">Wording the document reader picked up from your corrections and AI-assisted reads.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body: help line, toolbar, then the scrolling list -->
            <div class="pkp-body">
                <details class="pkp-help">
                    <summary>
                        <i class="bi bi-info-circle"></i>
                        <span>Approve wording you want the reader to use.</span>
                        <span class="pkp-help-link">How review works</span>
                    </summary>
                    <div class="pkp-help-text">
                        Each suggestion is a <strong>wording</strong> (the label printed next to a value in a
                        document) and the <strong>box</strong> it should fill on the project form.
                        <strong>Approve</strong> lets the reader use that wording; <strong>Reject</strong>
                        records that it should not be suggested again. Learned wording only ever fills a
                        box when the built-in labels miss — nothing approved here can override them.
                    </div>
                </details>

                <div class="pkp-toolbar">
                    <!-- Status filter segments (counts filled by pkpRender) -->
                    <div class="pkp-segments" id="pkpFilterTabs" role="tablist">
                        <button type="button" class="pkp-segment active" data-pkp-filter="proposed" onclick="pkpSetFilter('proposed')">
                            Proposed <span class="pkp-count" data-pkp-count="proposed">0</span>
                        </button>
                        <button type="button" class="pkp-segment" data-pkp-filter="live" onclick="pkpSetFilter('live')">
                            Live <span class="pkp-count" data-pkp-count="live">0</span>
                        </button>
                        <button type="button" class="pkp-segment" data-pkp-filter="rejected" onclick="pkpSetFilter('rejected')">
                            Rejected <span class="pkp-count" data-pkp-count="rejected">0</span>
                        </button>
                        <button type="button" class="pkp-segment" data-pkp-filter="all" onclick="pkpSetFilter('all')">
                            All <span class="pkp-count" data-pkp-count="all">0</span>
                        </button>
                        {{-- Admin-only: deleted rejected wording (restore / permanent delete).
                             The endpoints are admin-only on the server too; this only hides the tab. --}}
                        @if(auth()->check() && auth()->user()->role === 'admin')
                            <span class="pkp-segment-sep" aria-hidden="true"></span>
                            <button type="button" class="pkp-segment pkp-segment-deleted" data-pkp-filter="deleted" onclick="pkpSetFilter('deleted')">
                                <i class="bi bi-trash3"></i> Deleted <span class="pkp-count" data-pkp-count="deleted">0</span>
                            </button>
                        @endif
                    </div>

                    <div class="pkp-search">
                        <i class="bi bi-search"></i>
                        <input type="search" id="pkpSearch" placeholder="Search wording or box" autocomplete="off"
                               oninput="pkpSetSearch(this.value)">
                    </div>
                </div>

                <!-- The only scrolling region: suggestions render here -->
                <div class="pkp-list" id="parserKeywordsList"></div>
            </div>

            <!-- Footer -->
            <div class="pkp-footer">
                <span class="pkp-footer-note" id="pkpFooterNote"></span>
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// ===================================================
// L2 REVIEW — PARSER KEYWORD PROPOSALS
// Cloned from the budget-source manage modal. Differences: card list, status filter
// segments, approve/reject instead of add/edit, data-* delegation (no inline string
// args, so quotes in wording cannot break buttons), staff+admin gates.
// 2026-09-21: the list is fetched ONCE per open/refresh and the segments + search
// filter it in the browser, so switching tabs is instant and every tab shows a count.
// ===================================================
let _pkpFilter = 'proposed';
let _pkpSearch = '';
let _pkpRows = [];
let _pkpDeleted = [];
let _pkpCore = null;
// Admin sees the Deleted tab (2026-09-21). Display only — the server enforces it.
const PKP_IS_ADMIN = @json(auth()->check() && auth()->user()->role === 'admin');

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
        scurveMonth: 'S-Curve Month',
    };
    return map[field] || field;
}

// ===================================================
// FILTER SEGMENTS + SEARCH (client-side over _pkpRows)
// ===================================================
function pkpSetFilter(status) {
    _pkpFilter = status;
    document.querySelectorAll('#pkpFilterTabs .pkp-segment').forEach(el => {
        const on = el.getAttribute('data-pkp-filter') === status;
        el.classList.toggle('active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    pkpRender();
}

// Search box handler: filters by wording or box name as the reviewer types.
function pkpSetSearch(value) {
    _pkpSearch = String(value || '').trim().toLowerCase();
    pkpRender();
}

// ===================================================
// WORTH-APPROVING INDICATOR (2026-09-20). The desk showed a reviewer the phrase
// and asked them to judge it, without telling them the one thing that decides
// its worth: whether a BUILT-IN rule already covers that wording. On the test
// document one approved phrase restated a hardcoded rule and changed nothing,
// while the other found the only correct value — and they looked identical here.
// The core is lazily imported because this desk lives in the LAYOUTS (every
// page) while document-parser-core.js is only loaded by the create-project
// modal. Best-effort: if it cannot load, the indicator simply stays quiet.
// ===================================================
async function pkpLoadCore() {
    if (_pkpCore) return _pkpCore;
    try {
        if (window.documentParserCore) {
            _pkpCore = window.documentParserCore;
        } else {
            const mod = await import('{{ asset('js/admin/projects/document-parser-core.js') }}');
            _pkpCore = (mod && mod.default) ? mod.default : null;
        }
    } catch (e) { _pkpCore = null; }
    return _pkpCore;
}

// Returns the worth chip HTML for one row, or '' when the core cannot tell.
function pkpWorth(field, phrase) {
    if (!_pkpCore || typeof _pkpCore.builtInCoversPhrase !== 'function' || !phrase) return '';
    let covered = null;
    try { covered = _pkpCore.builtInCoversPhrase(field, phrase); } catch (e) { return ''; }
    if (covered === true) {
        return '<div class="pkp-worth is-covered"><i class="bi bi-dash-circle"></i> '
            + 'A built-in rule already reads this wording — approving it adds nothing.</div>';
    }
    if (covered === false) {
        return '<div class="pkp-worth is-new"><i class="bi bi-stars"></i> '
            + 'Finds a value no built-in rule does.</div>';
    }
    return ''; // null = cannot tell; say nothing rather than guess
}

// Returns the consequence chip: what this phrase would actually READ from the
// excerpt it was mined from, or '' when the core cannot say.
//
// ⚠ WHY THIS EXISTS BESIDE pkpWorth AND NOT INSTEAD OF IT. pkpWorth answers "is this
// wording new?", and on the 2026-09-23 pdfdocstest5 batch that question endorsed three
// rows that were each wrong — "Implementing Office", "LOCATION" and "Name of Procuring
// Entity" were all reported as finding something no built-in rule does, because NOVELTY
// IS EXACTLY WHAT A WRONG LABEL HAS MOST OF. The two chips answer different halves:
// worth = is it new, consequence = is it right. Neither is sufficient alone.
//
// It also separates the pair a reviewer cannot otherwise tell apart:
//   "Approved Budget for the Contract; PB"  ->  4834297.00   (OCR lost the decimal)
//   "| TOTAL ESTIMATED PROJECT COST P"      ->  4834297.59   (the true ABC)
function pkpConsequence(field, phrase, excerpt) {
    if (!_pkpCore || typeof _pkpCore.previewLearnedExtraction !== 'function' || !phrase) return '';
    let r = null;
    try { r = _pkpCore.previewLearnedExtraction(field, phrase, excerpt); } catch (e) { return ''; }
    // null = the phrase does not match its own excerpt. Say nothing rather than guess —
    // same discipline as pkpWorth's "cannot tell".
    if (!r) return '';
    if (!r.ok) {
        return '<div class="pkp-consequence is-refused"><i class="bi bi-exclamation-triangle"></i> '
            + 'Matches here, but the value after it is refused — approving this would teach a phrase that reads nothing.</div>';
    }
    return '<div class="pkp-consequence"><i class="bi bi-arrow-return-right"></i> '
        + 'From this excerpt it would read: <strong>' + pkpEscapeHtml(String(r.value)) + '</strong></div>';
}

// Excerpt with the learned wording highlighted (case-insensitive, escaped piecewise
// so the <mark> is the only markup that ever reaches the DOM).
function pkpHighlight(excerpt, phrase) {
    const text = String(excerpt || '');
    const needle = String(phrase || '');
    const at = needle ? text.toLowerCase().indexOf(needle.toLowerCase()) : -1;
    if (at === -1) return pkpEscapeHtml(text);
    return pkpEscapeHtml(text.slice(0, at))
        + '<mark>' + pkpEscapeHtml(text.slice(at, at + needle.length)) + '</mark>'
        + pkpEscapeHtml(text.slice(at + needle.length));
}

// ===================================================
// LOAD PROPOSALS FOR REVIEW (all statuses, once; pkpRender filters)
// ===================================================
async function loadParserKeywordsForManagement() {
    const list = document.getElementById('parserKeywordsList');
    if (list && _pkpRows.length === 0) {
        list.innerHTML = pkpEmptyState('bi-hourglass-split', 'Loading suggestions…', '');
    }
    try {
        const [response, deletedResponse] = await Promise.all([
            fetch(pkpBase() + '/parser-keyword-proposals/all', { headers: { 'Accept': 'application/json' } }),
            // Deleted rows are an admin-only list, always under /admin.
            PKP_IS_ADMIN ? fetch('/admin/parser-keyword-proposals/deleted', { headers: { 'Accept': 'application/json' } }) : null,
            pkpLoadCore(),
        ]);
        const rows = await response.json();
        _pkpRows = Array.isArray(rows) ? rows : [];
        let deleted = [];
        if (deletedResponse && deletedResponse.ok) {
            try { deleted = await deletedResponse.json(); } catch (e) { deleted = []; }
        }
        _pkpDeleted = Array.isArray(deleted) ? deleted : [];
        pkpRender();
    } catch (error) {
        console.error('Error loading keyword proposals:', error);
        if (list) list.innerHTML = pkpEmptyState('bi-exclamation-triangle', 'Could not load suggestions', 'Close and reopen this window to try again.');
        showToast('Failed to load keyword suggestions', 'error');
    }
}

// Centred placeholder used for loading, empty and error states.
function pkpEmptyState(icon, title, text) {
    return '<div class="pkp-empty">'
        + '<div class="pkp-empty-icon"><i class="bi ' + icon + '"></i></div>'
        + '<div class="pkp-empty-title">' + pkpEscapeHtml(title) + '</div>'
        + (text ? '<div class="pkp-empty-text">' + pkpEscapeHtml(text) + '</div>' : '')
        + '</div>';
}

// ===================================================
// RENDER — counts on every segment, then the filtered card list.
// Called by: loadParserKeywordsForManagement(), pkpSetFilter(), pkpSetSearch().
// ===================================================
function pkpRender() {
    const list = document.getElementById('parserKeywordsList');
    if (!list) return;

    // Segment counts always describe the whole set, not the search result.
    // "All" means everything ON THE DESK — deleted rows live only in their own tab.
    const counts = { proposed: 0, live: 0, rejected: 0, all: _pkpRows.length, deleted: _pkpDeleted.length };
    _pkpRows.forEach(r => { if (counts[r.status] !== undefined) counts[r.status]++; });
    document.querySelectorAll('#manageParserKeywordsModal [data-pkp-count]').forEach(el => {
        el.textContent = counts[el.getAttribute('data-pkp-count')] || 0;
    });

    const inStatus = _pkpFilter === 'deleted'
        ? _pkpDeleted.map(r => Object.assign({}, r, { _deleted: true }))
        : (_pkpFilter === 'all' ? _pkpRows : _pkpRows.filter(r => r.status === _pkpFilter));
    const shown = !_pkpSearch ? inStatus : inStatus.filter(r =>
        String(r.label_phrase || '').toLowerCase().includes(_pkpSearch)
        || pkpFieldLabel(r.field).toLowerCase().includes(_pkpSearch));

    const note = document.getElementById('pkpFooterNote');
    if (note) {
        note.textContent = inStatus.length === 0 ? ''
            : (_pkpSearch ? shown.length + ' of ' + inStatus.length + ' shown' : inStatus.length + (inStatus.length === 1 ? ' suggestion' : ' suggestions'));
    }

    if (inStatus.length === 0) {
        const empty = {
            proposed: ['bi-inbox', 'Nothing waiting for review', 'Corrections made while creating projects will appear here as suggestions.'],
            live: ['bi-check2-circle', 'No approved wording yet', 'Approved suggestions move here and are used by the document reader.'],
            rejected: ['bi-x-circle', 'Nothing rejected', 'Rejected suggestions are kept here so they are not suggested again.'],
            deleted: ['bi-trash3', 'Nothing deleted', 'Rejected wording that someone deleted appears here. Restore it, or delete it permanently.'],
            all: ['bi-inbox', 'No suggestions yet', 'Corrections made while creating projects will appear here as suggestions.'],
        }[_pkpFilter] || ['bi-inbox', 'No suggestions', ''];
        list.innerHTML = pkpEmptyState(empty[0], empty[1], empty[2]);
        return;
    }
    if (shown.length === 0) {
        list.innerHTML = pkpEmptyState('bi-search', 'No matches', 'No suggestion in this tab matches “' + _pkpSearch + '”.');
        return;
    }

    list.innerHTML = shown.map(pkpRowHtml).join('');
}

// One suggestion card. The WORDING is the headline; the box is a chip beside the
// status; the source excerpt stays hidden until "Show where it was found".
function pkpRowHtml(row) {
    // A deleted row is shown as its own state (admin's Deleted tab only).
    const status = row._deleted ? 'deleted'
        : (['live', 'rejected'].includes(row.status) ? row.status : 'proposed');
    const statusLabel = { proposed: 'Proposed', live: 'Live', rejected: 'Rejected', deleted: 'Deleted' }[status];

    // AI-4 origin: AI-assisted rows carry AI-taught wording (Decision A3).
    const origin = row.source === 'AI-assisted'
        ? '<span class="pkp-origin-ai" title="Kept from an AI-assisted read"><i class="bi bi-robot"></i> From an AI-assisted read</span>'
        : '<span title="Learned from a human correction"><i class="bi bi-pencil"></i> From a correction</span>';

    const phrase = row.label_phrase
        ? '<div class="pkp-phrase"><span class="pkp-q">“</span>' + pkpEscapeHtml(row.label_phrase) + '<span class="pkp-q">”</span></div>'
        : '<div class="pkp-phrase is-empty">No wording found — kept as a count only</div>';

    const context = row.excerpt
        ? '<button type="button" class="pkp-context-toggle" data-pkp-context="' + row.id + '" aria-expanded="false">'
            + '<i class="bi bi-chevron-right"></i> <span>Show where it was found</span></button>'
          + '<div class="pkp-context" id="pkpContext' + row.id + '" hidden>' + pkpHighlight(row.excerpt, row.label_phrase) + '</div>'
        : '';

    // L-2: a row with no wording has nothing to approve — it is kept only as a
    // count. Hiding the button says so before the server has to. Reject is hidden
    // on rejected rows: it only sets status='rejected', so there it would do nothing.
    // Deleted rows get only Restore / Delete permanently (admin).
    const approveBtn = (status === 'live' || status === 'deleted' || !row.label_phrase) ? '' :
        '<button class="btn btn-sm btn-success" data-pkp-approve="' + row.id + '" title="Let the reader use this wording">'
        + '<i class="bi bi-check-lg"></i> ' + (status === 'rejected' ? 'Approve instead' : 'Approve') + '</button>';
    const rejectBtn = (status === 'rejected' || status === 'deleted') ? '' :
        '<button class="btn btn-sm btn-outline-danger" data-pkp-reject="' + row.id + '" title="Stop suggesting this wording">'
        + '<i class="bi bi-x-lg"></i> Reject</button>';
    // Rejected rows can be tidied off the desk; the refusal still holds (server: withTrashed).
    const trashBtn = status !== 'rejected' ? '' :
        '<button class="btn btn-sm btn-outline-secondary" data-pkp-trash="' + row.id + '" title="Remove from the desk — it stays rejected">'
        + '<i class="bi bi-trash3"></i> Delete</button>';
    const restoreBtn = status !== 'deleted' ? '' :
        '<button class="btn btn-sm btn-outline-primary" data-pkp-restore="' + row.id + '" title="Put it back in the Rejected tab">'
        + '<i class="bi bi-arrow-counterclockwise"></i> Restore</button>';
    const forceBtn = status !== 'deleted' ? '' :
        '<button class="btn btn-sm btn-outline-danger" data-pkp-force="' + row.id + '" title="Erase it for good">'
        + '<i class="bi bi-trash3-fill"></i> Delete permanently</button>';
    const buttons = approveBtn + rejectBtn + trashBtn + restoreBtn + forceBtn;

    // When it was deleted, for the Deleted tab's meta line.
    let deletedOn = '';
    if (status === 'deleted' && row.deleted_at) {
        const d = new Date(row.deleted_at);
        if (!isNaN(d)) {
            deletedOn = '<span title="When it was deleted"><i class="bi bi-clock-history"></i> Deleted '
                + pkpEscapeHtml(d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })) + '</span>';
        }
    }

    const seen = parseInt(row.occurrences, 10) || 0;

    return '<div class="pkp-item is-' + status + '">'
        + '<div class="pkp-item-main">'
        +   '<div class="pkp-item-top">'
        +     '<span class="pkp-field" title="The project-form box this wording fills"><span class="pkp-field-lead">Fills</span> ' + pkpEscapeHtml(pkpFieldLabel(row.field)) + '</span>'
        +     '<span class="pkp-status is-' + status + '">' + statusLabel + '</span>'
        +   '</div>'
        +   phrase
        +   '<div class="pkp-meta">'
        +     '<span title="How many corrections produced this wording"><i class="bi bi-arrow-repeat"></i> Seen ' + seen + (seen === 1 ? ' time' : ' times') + '</span>'
        +     origin
        +     deletedOn
        +   '</div>'
        +   (status === 'deleted' ? '' : pkpWorth(row.field, row.label_phrase))
        +   (status === 'deleted' ? '' : pkpConsequence(row.field, row.label_phrase, row.excerpt))
        +   (context ? '<div>' + context + '</div>' : '')
        + '</div>'
        + (buttons ? '<div class="pkp-actions">' + buttons + '</div>' : '')
        + '</div>';
}

// ===================================================
// APPROVE / REJECT / CONTEXT TOGGLE (delegated — IDs travel via data-*, never string args)
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
        return;
    }
    // Deleted-wording actions (2026-09-21).
    const trashBtn = e.target.closest ? e.target.closest('[data-pkp-trash]') : null;
    if (trashBtn) {
        pkpTrashProposal(trashBtn.getAttribute('data-pkp-trash'));
        return;
    }
    const restoreBtn = e.target.closest ? e.target.closest('[data-pkp-restore]') : null;
    if (restoreBtn) {
        pkpRestoreProposal(restoreBtn.getAttribute('data-pkp-restore'));
        return;
    }
    const forceBtn = e.target.closest ? e.target.closest('[data-pkp-force]') : null;
    if (forceBtn) {
        pkpForceDeleteProposal(forceBtn.getAttribute('data-pkp-force'));
        return;
    }
    // Show/hide the source excerpt for one suggestion.
    const ctxBtn = e.target.closest ? e.target.closest('[data-pkp-context]') : null;
    if (ctxBtn) {
        const box = document.getElementById('pkpContext' + ctxBtn.getAttribute('data-pkp-context'));
        if (!box) return;
        const open = box.hasAttribute('hidden');
        box.toggleAttribute('hidden', !open);
        ctxBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        ctxBtn.querySelector('i').className = 'bi ' + (open ? 'bi-chevron-down' : 'bi-chevron-right');
        ctxBtn.querySelector('span').textContent = open ? 'Hide where it was found' : 'Show where it was found';
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

        // The browser caches the live word set per tab; a server-side approval is
        // invisible to it, so the pre-approval set would keep serving imports for
        // the rest of the TTL. Drop it here so the very next import re-fetches.
        try {
            if (typeof window.bustLearnedKeywordsCache === 'function') window.bustLearnedKeywordsCache();
        } catch (e) { /* busting is best-effort — never blocks the approval */ }

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
        'Reject this learned wording?<br><small style="color:#6b7280;">It moves to the Rejected tab and will not be suggested again. You can still approve it from there.</small>',
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

                // Same reason as approve: a rejected wording must stop being served
                // from the per-tab cache immediately, not after the TTL.
                try {
                    if (typeof window.bustLearnedKeywordsCache === 'function') window.bustLearnedKeywordsCache();
                } catch (e) { /* busting is best-effort */ }

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
// DELETE / RESTORE / PERMANENT DELETE (2026-09-21)
// Delete: admin + staff, rejected rows only; the wording STAYS refused.
// Restore + permanent delete: admin only (server-enforced; /admin URLs).
// ===================================================

// Shared request for the three actions: toast the outcome, then reload the desk.
// None of them changes the LIVE word set (only rejected rows are involved), so
// unlike approve/reject there is no learned-keyword cache to bust.
async function pkpSend(method, url, okMessage, failMessage) {
    try {
        const response = await fetch(url, { method: method, headers: pkpHeaders() });
        let data = {};
        try { data = await response.json(); } catch {}
        if (!response.ok) {
            showToast(pkpExtractErrorMessage(data, failMessage), 'error');
            return;
        }
        showToast(okMessage, 'success');
        loadParserKeywordsForManagement();
    } catch (error) {
        console.error(failMessage, error);
        showToast('Network error — ' + failMessage.toLowerCase(), 'error');
    }
}

// Soft-delete a rejected row after confirmation.
function pkpTrashProposal(id) {
    showDeleteConfirmation(
        'Delete Rejected Wording',
        'Remove this rejected wording from the list?<br><small style="color:#6b7280;">It stays rejected and will not be suggested again. An admin can restore it.</small>',
        () => pkpSend('DELETE', pkpBase() + '/parser-keyword-proposals/' + encodeURIComponent(id) + '/trash',
            'Rejected wording deleted', 'Failed to delete wording')
    );
}

// Put a deleted row back on the desk (it returns to Rejected). No confirm: it is undoable.
function pkpRestoreProposal(id) {
    pkpSend('PATCH', '/admin/parser-keyword-proposals/' + encodeURIComponent(id) + '/restore',
        'Restored to Rejected', 'Failed to restore wording');
}

// Erase a deleted row for good, after a confirmation that says what is lost.
function pkpForceDeleteProposal(id) {
    showDeleteConfirmation(
        'Delete Permanently',
        'Permanently delete this wording?<br><small style="color:#6b7280;">This cannot be undone. The system forgets it was ever rejected, so a future correction may suggest it again.</small>',
        () => pkpSend('DELETE', '/admin/parser-keyword-proposals/' + encodeURIComponent(id) + '/force',
            'Wording permanently deleted', 'Failed to delete wording permanently')
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

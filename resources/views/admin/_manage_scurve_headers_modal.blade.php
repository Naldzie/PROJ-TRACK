<!-- === S-CURVE HEADER PROPOSALS MANAGEMENT MODAL (E1-2 review) === -->
{{-- Styles: public/css/admin/projects/scurve-layouts-modal.css (linked by both layouts).
     2026-09-21 redesign, same as the Review Learned Keywords modal: fixed-size dialog
     (only the list scrolls), one card per layout led by its contractor, a readiness line
     saying whether approval would work, and the captured header block collapsed behind a
     toggle. The Teach modal below is unchanged. --}}
<div class="modal fade" id="manageScurveHeadersModal" tabindex="-1" aria-labelledby="schTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered sch-dialog">
        <div class="modal-content sch-content">

            <!-- Header -->
            <div class="sch-header">
                <div class="sch-header-icon"><i class="bi bi-table"></i></div>
                <div>
                    <h5 class="sch-title" id="schTitle">Review S-Curve Layouts</h5>
                    <p class="sch-subtitle">Column layouts captured from S-curve files the built-in rules could not read.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body: help line, toolbar, then the scrolling list -->
            <div class="sch-body">
                <details class="sch-help">
                    <summary>
                        <i class="bi bi-info-circle"></i>
                        <span>Approve a layout to trust it, or teach its columns yourself.</span>
                        <span class="sch-help-link">How review works</span>
                    </summary>
                    <div class="sch-help-text">
                        Each card is the header block of an S-curve file (Excel or PDF) whose columns
                        the built-in rules could not find. <strong>Approve</strong> trusts the layout for
                        its contractor. <strong>Teach</strong> lets you point at its columns yourself — it
                        saves a variant, so alternating formats both keep working.
                        <strong>Reject</strong> discards the capture; a future failed import can capture it
                        again. Approved layouts only ever fill in when the built-in rules miss — nothing
                        approved here can override them.
                    </div>
                </details>

                <div class="sch-toolbar">
                    <!-- Status filter segments (counts filled by schRender) -->
                    <div class="sch-segments" id="schFilterTabs" role="tablist">
                        <button type="button" class="sch-segment active" data-sch-filter="proposed" onclick="schSetFilter('proposed')">
                            Proposed <span class="sch-count" data-sch-count="proposed">0</span>
                        </button>
                        <button type="button" class="sch-segment" data-sch-filter="live" onclick="schSetFilter('live')">
                            Live <span class="sch-count" data-sch-count="live">0</span>
                        </button>
                        <button type="button" class="sch-segment" data-sch-filter="all" onclick="schSetFilter('all')">
                            All <span class="sch-count" data-sch-count="all">0</span>
                        </button>
                    </div>

                    <div class="sch-search">
                        <i class="bi bi-search"></i>
                        <input type="search" id="schSearch" placeholder="Search contractor" autocomplete="off"
                               oninput="schSetSearch(this.value)">
                    </div>
                </div>

                <!-- The only scrolling region: layout cards render here -->
                <div class="sch-list" id="scurveHeadersList"></div>
            </div>

            <!-- Footer -->
            <div class="sch-footer">
                <span class="sch-footer-note" id="schFooterNote"></span>
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<!-- === TEACH S-CURVE COLUMNS MODAL (Phase-A point-and-teach) === -->
<!-- Standalone (never stacked on the review desk): opened from a Teach button,
     closed back to the reloaded desk. Click-to-assign on a rendered grid —
     no typed letters required. -->
<div class="modal fade" id="teachScurveColumnsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-table"></i> Teach S-Curve Columns
                    <small class="text-muted" id="teachContextLine"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">
                    Click a field, then click where it lives on the grid — a <strong>column letter</strong>
                    for a column field, an <strong>R-number</strong> for a row field.
                    Rows tagged <span class="badge bg-primary">HEADER</span> hold the labels;
                    <span class="badge bg-warning text-dark">SUMMARY</span> rows are reference only, and
                    <span class="badge bg-success">CUMULATIVE</span> marks the running-total row a baseline is read from.
                    R-numbers are kept order, not Excel rows. Hover any button for what it needs.
                </p>
                {{-- PDF-4 Stage 2 layout: the grid is the click surface and the whole
                     point of the screen, so it gets the full width, with the palette in
                     a band above it.
                     2026-09-15 (user): rows are picked the SAME way columns are. The
                     header-row checkbox list and the two row-role lists are gone; the
                     palette carries a "Rows" line (Header row / Not an activity /
                     Cumulative row) and an armed row field takes clicks on the grid's
                     R-numbers, exactly as an armed column field takes column letters.
                     The Save payload is unchanged. --}}
                <div class="mb-1" title="Arm a field, then click on the grid: column letters for column fields, R-numbers for row fields. Clicking something already taken moves it."><strong>Click a field, then its column letter(s) or row number(s) on the grid</strong></div>
                <div id="teachFieldPalette" class="mb-2"></div>

                <div id="teachGrid" class="mb-2" title="Click a column letter or an R-number to assign it to the armed field." style="overflow:auto;max-height:430px;border:1px solid #dee2e6;border-radius:8px;"></div>

                <div class="row g-3">
                    <div class="col-md-7">
                        <div class="mb-1"><strong>Your teaching</strong></div>
                        <div id="teachReadout" class="small text-muted mb-1">Nothing assigned yet.</div>
                        <div id="teachHint" class="small text-danger"></div>
                        {{-- Advisory only, never gates Save: a pick that is legal but
                             probably not what the reviewer meant. --}}
                        <div id="teachRoleNote" class="small" style="color:#997404;"></div>
                        {{-- PDF only (2026-09-15): do the taught month columns add up to
                             the TOTAL row's printed total? Live "Adds up" / "Not checked"
                             line; a mismatch shows here as a warning on the first Save
                             click, and Save becomes "Save anyway". Never blocks. --}}
                        <div id="teachTotalCheck" class="small mt-1"></div>
                    </div>
                    <div class="col-md-5">
                        <div class="mb-1"><strong>Save as</strong></div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="teachMode" id="teachModeVariant" value="variant" checked>
                            <label class="form-check-label small" for="teachModeVariant">New variant <small class="text-muted">(keeps the old map — alternating formats both keep working)</small></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="teachMode" id="teachModeReplace" value="replace">
                            <label class="form-check-label small" for="teachModeReplace">Replace this map <small class="text-muted">(drops back to Proposed for re-review)</small></label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="teachSaveBtn" title="Save as a new variant (or replace) — approval is still required before it can fill anything." onclick="schSaveTeaching()">Save layout</button>
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
// MINI-GRID — render the stored header block as a real table
// ===================================================
// The raw 120-char dump is unreadable (user report 2026-09-10). The stored text
// already carries structure (COLUMNS legend + R<n> [ROLE] lines), so parse and
// render it: lettered columns, role badges, dimmed empties. Capped + scrollable
// so wide bundles never break the desk. textContent-free zone — everything via
// schEscapeHtml (header text is contractor-controlled).
// ---------------------------------------------------------------------------
// PURE LOGIC LIVES IN public/js/admin/projects/scurve-teach-core.js (2026-09-15),
// loaded as a module by both layouts and exposed as window.scurveTeachCore, so it
// runs under `npm run test:js`. The sch* functions below that only delegate keep
// their old names so no call site in this file had to change. Nothing here may
// call the core at the top level: module scripts run after this classic script
// is parsed, and every call below happens on a user action.
// ---------------------------------------------------------------------------
function schCore() {
    if (!window.scurveTeachCore) {
        // A stale cached layout, or the script tag missing from a layout, lands
        // here. Say what to do instead of failing deep inside a render.
        throw new Error('S-curve teach logic (scurve-teach-core.js) is not loaded — hard-refresh the page.');
    }
    return window.scurveTeachCore;
}

function schSplitCsv(line) { return schCore().splitCsv(line); }

// Parse stored header text into { letters, rows: [{n, role, cells}] }, or null.
function schParseHeaderBlock(text) { return schCore().parseHeaderBlock(text); }

// CUMULATIVE is called out separately from SUMMARY on purpose: a schedule prints
// two rows that both label themselves '%', and only one of them is the running total
// a baseline is adopted from. They looked identical in the grid.
const SCH_ROLE_BADGE = { META: 'secondary', HEADER: 'primary', DATA: 'light text-dark border', SUMMARY: 'warning text-dark', CUMULATIVE: 'success' };

// Compact grid for the list card (first 8 rows × 16 cols, scrollable). 2026-09-21:
// own classes (.sch-grid / .sch-role) instead of Bootstrap badges — the app's global
// .badge styling rendered the R-numbers as large grey buttons that crowded the cells.
function schMiniGrid(text) {
    const parsed = schParseHeaderBlock(text);
    if (!parsed) {
        return '<div class="sch-grid-note">'
            + (text ? schEscapeHtml(String(text).substring(0, 120)) + '…' : '(empty header block)')
            + '</div>';
    }
    const maxCols = 16, maxRows = 8;
    const letters = parsed.letters.slice(0, maxCols);
    let html = '<div class="sch-grid"><table><thead><tr><th></th>';
    letters.forEach(l => { html += '<th>' + schEscapeHtml(l) + '</th>'; });
    html += '</tr></thead><tbody>';
    parsed.rows.slice(0, maxRows).forEach(r => {
        const role = String(r.role || '').replace(/[^A-Z]/g, '');
        html += '<tr><th><span class="sch-role role-' + role + '" title="' + schEscapeHtml(role || 'row') + '">R' + r.n + '</span></th>';
        for (let c = 0; c < letters.length; c++) {
            const v = (r.cells[c] !== undefined ? String(r.cells[c]).trim() : '');
            html += v === '' ? '<td class="is-empty"></td>' : '<td>' + schEscapeHtml(v) + '</td>';
        }
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    if (parsed.rows.length > maxRows || parsed.letters.length > maxCols) {
        html += '<div class="sch-grid-note">Showing the first ' + Math.min(maxRows, parsed.rows.length)
            + ' rows × ' + letters.length + ' columns — open Teach for the full grid.</div>';
    }
    if (String(text).length >= 4000) {
        html += '<div class="sch-grid-note is-warn">Stored block hit the 4000-character cap — its tail may be cut.</div>';
    }
    return html;
}

// ===================================================
// FILTER SEGMENTS + SEARCH (client-side over _schRowsCache)
// 2026-09-21: the desk fetches ALL layouts once and filters in the browser, so tab
// switches are instant and every tab shows a count. _schRowsCache therefore holds
// every row, which is also what the Teach modal's lookup-by-id needs.
// ===================================================
let _schSearch = '';

function schSetFilter(status) {
    _schFilter = status;
    document.querySelectorAll('#schFilterTabs .sch-segment').forEach(el => {
        const on = el.getAttribute('data-sch-filter') === status;
        el.classList.toggle('active', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    schRender();
}

// Search box handler: filters by contractor name ("any contractor" matches global rows).
function schSetSearch(value) {
    _schSearch = String(value || '').trim().toLowerCase();
    schRender();
}

// Centred placeholder used for loading, empty and error states.
function schEmptyState(icon, title, text) {
    return '<div class="sch-empty">'
        + '<div class="sch-empty-icon"><i class="bi ' + icon + '"></i></div>'
        + '<div class="sch-empty-title">' + schEscapeHtml(title) + '</div>'
        + (text ? '<div class="sch-empty-text">' + schEscapeHtml(text) + '</div>' : '')
        + '</div>';
}

// ===================================================
// LOAD CAPTURES FOR REVIEW (all statuses, once; schRender filters)
// Called by: the modal's show event, approve/reject, and the Teach save path.
// ===================================================
async function loadScurveHeadersForManagement() {
    const list = document.getElementById('scurveHeadersList');
    if (list && (!_schRowsCache || _schRowsCache.length === 0)) {
        list.innerHTML = schEmptyState('bi-hourglass-split', 'Loading layouts…', '');
    }
    try {
        const response = await fetch(schBase() + '/scurve-header-proposals/all', { headers: { 'Accept': 'application/json' } });
        const rows = await response.json();
        // Cache rows for the teach modal (lookup by id, no extra endpoint).
        _schRowsCache = Array.isArray(rows) ? rows : [];
        schRender();
    } catch (error) {
        console.error('Error loading S-curve layouts:', error);
        if (list) list.innerHTML = schEmptyState('bi-exclamation-triangle', 'Could not load layouts', 'Close and reopen this window to try again.');
        showToast('Failed to load S-curve layouts', 'error');
    }
}

// ===================================================
// RENDER — counts on every segment, then the filtered card list.
// Called by: loadScurveHeadersForManagement(), schSetFilter(), schSetSearch().
// ===================================================
function schRender() {
    const list = document.getElementById('scurveHeadersList');
    if (!list) return;
    const rows = _schRowsCache || [];

    const counts = { proposed: 0, live: 0, all: rows.length };
    rows.forEach(r => { if (counts[r.status] !== undefined) counts[r.status]++; });
    document.querySelectorAll('#manageScurveHeadersModal [data-sch-count]').forEach(el => {
        el.textContent = counts[el.getAttribute('data-sch-count')] || 0;
    });

    const inStatus = _schFilter === 'all' ? rows : rows.filter(r => r.status === _schFilter);
    const shown = !_schSearch ? inStatus : inStatus.filter(r =>
        (r.contractor ? String(r.contractor) : 'any contractor global').toLowerCase().includes(_schSearch));

    const note = document.getElementById('schFooterNote');
    if (note) {
        note.textContent = inStatus.length === 0 ? ''
            : (_schSearch ? shown.length + ' of ' + inStatus.length + ' shown' : inStatus.length + (inStatus.length === 1 ? ' layout' : ' layouts'));
    }

    if (inStatus.length === 0) {
        const empty = {
            proposed: ['bi-inbox', 'Nothing waiting for review', 'S-curve files the built-in rules cannot read will have their layout captured here.'],
            live: ['bi-check2-circle', 'No approved layouts yet', 'Approved and taught layouts move here and are used when the built-in rules miss.'],
            all: ['bi-inbox', 'No layouts yet', 'S-curve files the built-in rules cannot read will have their layout captured here.'],
        }[_schFilter] || ['bi-inbox', 'No layouts', ''];
        list.innerHTML = schEmptyState(empty[0], empty[1], empty[2]);
        return;
    }
    if (shown.length === 0) {
        list.innerHTML = schEmptyState('bi-search', 'No matches', 'No layout in this tab matches “' + _schSearch + '”.');
        return;
    }

    list.innerHTML = shown.map(schRowHtml).join('');
}

// What the attached column map says, as plain text (or '' when nothing to say).
// Excel: DESC=C · AMT=F · M1=I+J …   PDF: page 1: M1–M12 · page 2: M13–M20.
function schMapSummary(row) {
    try {
        const cm = row.column_map || null;
        if (!row.has_map || !cm || typeof cm !== 'object') return '';
        if (cm.kind === 'pdf') {
            // PDF-4 (2026-09-13): a PDF map is GEOMETRY (page-width fractions), not
            // column letters — describe how many printed pages it covers and which
            // months each one carries.
            const pages = Array.isArray(cm.pages) ? cm.pages : [];
            return pages.map((p, i) => {
                const months = (p.monthCols || []).map(mc => mc.index).filter(n => n >= 1);
                if (!months.length) return 'page ' + (i + 1) + ': no months';
                return 'page ' + (i + 1) + ': M' + months[0]
                    + (months.length > 1 ? '–M' + months[months.length - 1] : '');
            }).join(' · ');
        }
        const L = (v) => (v === null || v === undefined) ? null : schIndexToLetter(v);
        const bits = [];
        const dc = L(cm.descCol);
        if (dc) bits.push('DESC=' + dc);
        const cc = L(cm.contractCol);
        if (cc) bits.push('AMT=' + cc);
        const wc = L(cm.weightCol);
        if (wc) bits.push('WT=' + wc);
        const spans = cm.monthSpans || {};
        Object.keys(spans).map(Number).filter(n => n >= 1).sort((x, y) => x - y).forEach(m => {
            const cols = (spans[m] || []).map(L).filter(Boolean);
            if (cols.length) bits.push('M' + m + '=' + cols.join('+'));
        });
        return bits.join(' · ');
    } catch (e) { return ''; /* summary is best-effort */ }
}

// One layout card. Headline = whose layout it is; the READINESS line says whether
// approving it would let the reader use it; the header block stays collapsed.
function schRowHtml(row) {
    const status = row.status === 'live' ? 'live' : 'proposed';

    // E1-PDF: PDF captures land in this same desk; the two file kinds are read by
    // different engines, so a reviewer should know which one they are teaching.
    const kind = row.source_type === 'pdf'
        ? '<span class="sch-pill kind-pdf" title="Captured from a PDF schedule"><i class="bi bi-file-earmark-pdf"></i> PDF</span>'
        : '<span class="sch-pill kind-excel" title="Captured from an Excel workbook"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</span>';
    const statusPill = '<span class="sch-pill is-' + status + '">' + (status === 'live' ? 'Live' : 'Proposed') + '</span>';

    const contractor = row.contractor
        ? '<div class="sch-contractor">' + schEscapeHtml(row.contractor) + '</div>'
        : '<div class="sch-contractor is-global">Any contractor <small>(global layout)</small></div>';

    const origin = row.source === 'AI-assisted'
        ? '<span class="sch-origin-ai" title="Kept from an AI-assisted read"><i class="bi bi-robot"></i> From an AI-assisted read</span>'
        : (row.source === 'manual-teach'
            ? '<span title="Taught by a reviewer"><i class="bi bi-hand-index"></i> Taught by a reviewer</span>'
            : (row.source === 'ai-derived'
                ? '<span class="sch-origin-ai" title="Learned from a successful AI read — needs review"><i class="bi bi-robot"></i> Learned from an AI read</span>'
                : '<span title="Captured from a failed import"><i class="bi bi-camera"></i> Auto-captured</span>'));
    const seen = parseInt(row.occurrences, 10) || 0;

    // READINESS. A row with NO map is not merely "not Mapped" — the reader has
    // nothing to read it with, so approving it changes nothing (2026-09-14). An
    // UNNUMBERED PDF capture (positional wording — the roof prints "30 days" six
    // times) keeps its geometry with no month numbers, and the reader refuses a
    // month-less page, so approving it is a no-op too (2026-09-14 acceptance run).
    const cm = row.column_map || null;
    const pdfPages = (cm && cm.kind === 'pdf' && Array.isArray(cm.pages)) ? cm.pages : [];
    const unnumbered = pdfPages.length > 0 && pdfPages.every(
        (p) => p.unnumbered === true || !(p.monthCols || []).some(mc => mc.index >= 1)
    );
    const summary = schMapSummary(row);
    let ready;
    if (!row.has_map) {
        ready = '<div class="sch-ready is-warn"><i class="bi bi-exclamation-triangle"></i><span>'
            + '<strong>Needs teaching.</strong> No column map could be worked out from this file, so approving it'
            + ' alone will not make it readable. Use Teach to point at its columns.</span></div>';
    } else if (unnumbered) {
        ready = '<div class="sch-ready is-warn"><i class="bi bi-exclamation-triangle"></i><span>'
            + '<strong>Months not numbered.</strong> This layout records where the columns sit but not which month'
            + ' each one is, because the page labels them all the same way. Teach the months first, then approve.</span></div>';
    } else {
        ready = '<div class="sch-ready is-ok"><i class="bi bi-check-circle"></i><span>'
            + '<strong>Column map ready</strong>' + (summary ? ' · <code>' + schEscapeHtml(summary) + '</code>' : '')
            + '</span></div>';
    }

    const grid = row.header_rows_text
        ? '<button type="button" class="sch-grid-toggle" data-sch-grid="' + row.id + '" aria-expanded="false">'
            + '<i class="bi bi-chevron-right"></i> <span>Show captured header block</span></button>'
          + '<div class="sch-grid-wrap" id="schGrid' + row.id + '" hidden></div>'
        : '<div class="sch-grid-note">(empty header block)</div>';

    // A row the reader cannot use must not offer Approve as if it would do
    // something (2026-09-14). The server refuses these independently; this only
    // stops the wasted click.
    const unreadable = !row.has_map || unnumbered;
    const approveBtn = status === 'live' ? ''
        : (unreadable
            ? '<button class="btn btn-sm btn-outline-secondary" disabled'
                + ' title="There is nothing here the reader can use yet — teach this layout\'s columns first,'
                + ' then approve."><i class="bi bi-check-lg"></i> Approve</button>'
            : '<button class="btn btn-sm btn-success" data-sch-approve="' + row.id + '" title="Trust this layout"><i class="bi bi-check-lg"></i> Approve</button>');
    const teachBtn = '<button class="btn btn-sm btn-outline-primary" data-sch-teach="' + row.id + '" title="Teach column positions for this layout"><i class="bi bi-table"></i> Teach</button>';
    const rejectBtn = '<button class="btn btn-sm btn-outline-danger" data-sch-reject="' + row.id + '" title="Discard this capture"><i class="bi bi-x-lg"></i> Reject</button>';

    return '<div class="sch-item is-' + status + '">'
        + '<div class="sch-item-main">'
        +   '<div class="sch-item-top">' + kind + statusPill + '</div>'
        +   contractor
        +   '<div class="sch-meta">'
        +     '<span title="How many failed imports produced these headers"><i class="bi bi-arrow-repeat"></i> Seen ' + seen + (seen === 1 ? ' time' : ' times') + '</span>'
        +     origin
        +   '</div>'
        +   ready
        +   grid
        + '</div>'
        + '<div class="sch-actions">' + approveBtn + teachBtn + rejectBtn + '</div>'
        + '</div>';
}

// Show/hide one card's header block. The grid is built on first open only — a
// desk of wide captures no longer renders every table up front.
function schToggleGrid(btn) {
    const id = btn.getAttribute('data-sch-grid');
    const box = document.getElementById('schGrid' + id);
    if (!box) return;
    const open = box.hasAttribute('hidden');
    if (open && !box.hasChildNodes()) {
        const row = (_schRowsCache || []).find(r => String(r.id) === String(id));
        try {
            box.innerHTML = row ? schMiniGrid(row.header_rows_text) : '';
        } catch (e) {
            // schCore() throws with a hard-refresh hint when the teach core is not loaded.
            box.innerHTML = '<div class="sch-grid-note is-warn">' + schEscapeHtml(e.message) + '</div>';
        }
    }
    box.toggleAttribute('hidden', !open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.querySelector('i').className = 'bi ' + (open ? 'bi-chevron-down' : 'bi-chevron-right');
    btn.querySelector('span').textContent = open ? 'Hide captured header block' : 'Show captured header block';
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
        return;
    }
    const teachBtn = e.target.closest ? e.target.closest('[data-sch-teach]') : null;
    if (teachBtn) {
        schTeachColumns(teachBtn.getAttribute('data-sch-teach'));
        return;
    }
    // Show/hide a card's captured header block (2026-09-21).
    const gridBtn = e.target.closest ? e.target.closest('[data-sch-grid]') : null;
    if (gridBtn) {
        schToggleGrid(gridBtn);
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
// TEACH COLUMNS (Phase-A point-and-teach — click, never type)
// ===================================================
let _schRowsCache = [];
let _schTeach = null;

// 0-based index → Excel letters (mirrors the server letter() helper).
function schIndexToLetter(i) { return schCore().indexToLetter(i); }

function schTeachColumns(id) {
    try {
        const row = (_schRowsCache || []).find(r => String(r.id) === String(id));
        if (!row) { showToast('Layout no longer listed — reload and retry', 'error'); return; }
        const parsed = schParseHeaderBlock(row.header_rows_text || '');
        if (!parsed) { showToast('This capture has no readable header block', 'error'); return; }
        _schTeach = {
            id: row.id,
            contractor: row.contractor || 'Global',
            occurrences: row.occurrences,
            status: row.status,
            letters: parsed.letters,
            rows: parsed.rows,
            field: 'desc',
            assigns: { desc: null, code: null, weight: null, contract: null, months: {} },
            headerRows: [],
            // One nudge per teaching session (see schNudgeHeaderPicker). Lives on
            // _schTeach, which is rebuilt per capture, so re-opening the desk on a
            // different row nudges again while re-rendering the same one does not.
            headerNudged: false,
            // Row roles. sourceType decides whether the pickers are offered at all —
            // read from the row rather than guessed, because the server refuses a
            // PDF row outright and the UI must agree with it BEFORE the reviewer
            // spends effort on a pick that cannot be saved.
            sourceType: (row.source_type || 'excel'),
            excludedRows: [],
            cumulativeRow: null,
            // How many months this layout looks like it has, so the palette offers
            // that many buttons instead of a fixed wall of twelve.
            suggestedMonths: schCore().suggestedMonthsFor(row.column_map),
            // Adds-up check (2026-09-15). A block at the 4000-char store limit may
            // have lost its TOTAL row, which the "Not checked" line then says.
            blockTruncated: String(row.header_rows_text || '').length >= 4000,
            // Cells cut off at the page edge when the PDF was printed ("14:K").
            edgeCells: parsed.edgeCells || [],
            // The month assignments a mismatch warning was shown for; Save goes
            // through ("Save anyway") only while they are unchanged.
            saveWarnKey: null,
        };
        // Default header rows: tagged HEADER lines (max 2), EMPTY when none is
        // tagged — the reviewer must then pick (F6). See defaultHeaderRows().
        _schTeach.headerRows = schCore().defaultHeaderRows(parsed);
        // Prefill from an attached map: PDF bands -> letters by dominant overlap on
        // every page (defects 6 + 7); Excel indices -> letters, then header-row and
        // row-role memory (defect 12). All rules, and why, in prefillFromMap().
        try {
            schCore().prefillFromMap(_schTeach, row.column_map || null, parsed);
        } catch (e) { /* prefill is best-effort */ }
        // PDF-4: say up front when this row cannot be taught, instead of letting the
        // reviewer assign every column and only then meet a refusal on Save.
        _schTeach.blocked = null;
        if (row.source_type === 'pdf') {
            const cm = row.column_map || null;
            if (!cm || cm.kind !== 'pdf' || !Array.isArray(cm.pages) || !cm.pages.length) {
                _schTeach.blocked = 'This capture did not record where its columns sit on the page, so there is nothing to point at yet. Re-import the PDF to capture it again.';
            }
            // Multi-page layouts are no longer blocked: the grid renders every
            // printed page as one table with the letters running on across the seam,
            // so the months on later pages can be pointed at like any other.
        }
        const ctx = document.getElementById('teachContextLine');
        if (ctx) ctx.textContent = '— ' + _schTeach.contractor + ' · seen ' + _schTeach.occurrences + '× · ' + _schTeach.status;
        schRenderTeachPalette();
        schRenderTeachGrid();
        schRenderTeachReadout();
        new bootstrap.Modal(document.getElementById('teachScurveColumnsModal')).show();
    } catch (e) {
        console.error('Error opening teach modal:', e);
        showToast('Could not open the teaching view', 'error');
    }
}

// Item 3c (2026-09-16): the last three are EXCEL-ONLY. On a PDF they were never stored
// or read — buildTaughtPdfMap used them as widening stops and then discarded them — so
// they are disabled with the reason, the way the two row roles already are.
const SCH_TEACH_FIELDS = [
    { key: 'desc', label: 'Description', tip: 'Work-names column (e.g. TASK DESCRIPTION, WORK DETAILS) — activities are read from here. Required.' },
    { key: 'contract', label: 'Contract amount', excelOnly: true, tip: 'Peso column holding each activity\u2019s cost (e.g. BUDGET COST) — the baseline denominator. Optional.' },
    { key: 'weight', label: 'Weight', excelOnly: true, tip: 'Percent-weight column (e.g. % WEIGHT). Optional.' },
    { key: 'code', label: 'Item code', excelOnly: true, tip: 'Row numbering column (e.g. ITEM). Optional.' },
];
// PDF-4 Stage 2 — months are built on demand. A fixed wall of twelve buttons
// pushed the grid off the screen on every layout, however few months it had.
// SCH_TEACH_MAX is the ceiling the server also enforces (months max:MAX_MONTHS —
// 60 since 2026-09-16; it was 12, so a longer project could not be taught). Buttons
// still appear one at a time via "+ month", so a short layout's palette is unchanged.
const SCH_TEACH_MAX_MONTHS = {{ \App\Services\SCurveImportService::MAX_MONTHS }};
function schMonthField(m) {
    return { key: 'm' + m, label: 'Month ' + m, tip: 'Monthly peso columns for Month ' + m + ' — click 1 letter for single-column months (P1), 2 for paired months (value written twice).' };
}
// How many month buttons to offer: what the capture suggests, what is already
// assigned, and one spare to grow into — never fewer than two, since a layout
// needs two months to be a schedule at all.
function schMonthsToShow() {
    if (!_schTeach) return 2;
    const assigned = Object.keys(_schTeach.assigns.months || {}).map(Number).filter(n => n >= 1);
    const suggested = _schTeach.suggestedMonths || 0;
    const highest = Math.max(2, suggested, assigned.length ? Math.max.apply(null, assigned) : 0);
    return Math.min(SCH_TEACH_MAX_MONTHS, highest + (highest < SCH_TEACH_MAX_MONTHS ? 1 : 0));
}

// Row fields (2026-09-15). Armed like column fields, but they take clicks on the
// grid's R-numbers. KEY NAMES MUST NOT START WITH "m": month fields are m1..m12
// and several dispatchers used to test indexOf('m') === 0 (regression restudy,
// trap (a)). Every dispatcher now asks scurveTeachCore.isMonthField() (/^m\d+$/)
// or schIsRowField().
const SCH_ROW_FIELDS = [
    { key: 'rowHeader', label: 'Header row', tip: 'The line(s) holding the column labels. Detected automatically — arm this and click an R-number only if the labels print on a different line. At most two; click again to clear.' },
    { key: 'rowSkip', label: 'Not an activity', excelOnly: true, tip: 'Rows the system must not add up as work items — author totals, cash-flow or duration lines. Arm this and click their R-numbers; click again to clear.' },
    { key: 'rowCurve', label: 'Cumulative row', excelOnly: true, tip: 'The author’s printed running-total row (the S-curve). Reference only — marking it also marks it Not an activity. Pick one; click again to clear.' },
];
// The keys above must match scurveTeachCore.ROW_FIELD_KEYS (pinned by
// tests/js/scurve-teach-core.test.mjs, which reads this file's SCH_ROW_FIELDS keys).

// Why the two role fields are disabled on a PDF row — one wording, from the core.
function schRowPdfReason() { return schCore().ROW_PDF_REASON; }

// Item 3c — why Contract amount / Weight / Item code are disabled on a PDF row.
function schColPdfReason() { return schCore().COL_PDF_REASON; }

// Item 3b — the letters the description BAND covers on a PDF layout, recomputed from
// the current picks. A PDF description is an x-range, not a column: the reader keeps
// every token left of the band's right edge, and the band runs to the first month.
// Display only — assigns.desc stays the one letter the server is given.
function schDescBand() {
    if (!_schTeach || _schTeach.sourceType !== 'pdf') return [];
    // Until the reviewer touches the description or a month, show the band the LAYOUT
    // ACTUALLY HOLDS (remembered by prefillFromMap). Once they change either, that
    // band is history and the computed one — what saving will store — is the honest
    // answer. Both clicks clear descBand, so this needs no staleness check.
    if (_schTeach.descBand && _schTeach.descBand.length) return _schTeach.descBand;
    return schCore().pdfDescBandLetters(_schTeach.letters, _schTeach.assigns);
}

// Is this letter part of the description band without being the letter that was picked?
function schInDescBand(letter) {
    if (!_schTeach || _schTeach.assigns.desc === letter) return false;
    return schDescBand().indexOf(letter) !== -1;
}

// Is this palette key a row field (as opposed to a column field)?
function schIsRowField(key) { return schCore().isRowField(key); }

// The human label of a row field ("Header row"), for messages.
function schRowFieldTitle(key) {
    const f = SCH_ROW_FIELDS.find(x => x.key === key);
    return f ? f.label : key;
}

function schTeachFieldLabel(key) {
    if (key === 'desc') return 'DESC';
    if (key === 'contract') return 'AMT';
    if (key === 'weight') return 'WT';
    if (key === 'code') return 'CODE';
    // Was indexOf('m') === 0 — the same trap schTeachClickLetter had.
    if (schCore().isMonthField(key)) return 'M' + key.substring(1);
    return key;
}

// What a row field currently holds, for its palette button ("R7 + R8", "none").
function schRowFieldValue(key) {
    if (!_schTeach) return '';
    const list = key === 'rowHeader' ? _schTeach.headerRows
        : key === 'rowSkip' ? _schTeach.excludedRows
        : (_schTeach.cumulativeRow ? [_schTeach.cumulativeRow] : []);
    return list.length ? list.map(n => 'R' + n).join(' + ') : 'none';
}

/**
 * Render the field palette: a "Columns" line and a "Rows" line.
 * Called by: schTeachColumns() on open, every click handler, schNudgeHeaderPicker().
 * Row buttons show what they hold, so the three row picks stay readable without the
 * old summary band. On a PDF row the two role buttons are disabled WITH the reason
 * (a control that vanishes teaches the reviewer nothing); Header row stays usable.
 */
function schRenderTeachPalette() {
    const host = document.getElementById('teachFieldPalette');
    if (!host || !_schTeach) return;
    host.innerHTML = '';
    const line = (label) => {
        const div = document.createElement('div');
        div.className = 'd-flex flex-wrap align-items-center gap-1 mb-1';
        const tag = document.createElement('span');
        tag.className = 'text-muted small me-1';
        tag.style.minWidth = '4.5em';
        tag.textContent = label;
        div.appendChild(tag);
        host.appendChild(div);
        return div;
    };
    const armButton = (f, text) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.id = 'teachFieldBtn-' + f.key;
        b.className = 'btn btn-sm ' + (_schTeach.field === f.key ? 'btn-primary' : 'btn-outline-secondary');
        b.textContent = text;
        b.title = f.tip || f.label;
        // The grid is redrawn too: its R-numbers are outlined while a row field is
        // armed, which is how the reviewer sees where the next click should go.
        b.onclick = function () { _schTeach.field = f.key; schRenderTeachPalette(); schRenderTeachGrid(); };
        return b;
    };

    const cols = line('Columns');
    const isPdfCols = _schTeach.sourceType === 'pdf';
    const shown = SCH_TEACH_FIELDS.slice();
    const upto = schMonthsToShow();
    for (let m = 1; m <= upto; m++) shown.push(schMonthField(m));
    shown.forEach(f => {
        const b = armButton(f, f.label);
        // Item 3c — same treatment as the two row roles: disabled WITH the reason,
        // never removed. A control that vanishes teaches the reviewer nothing.
        if (f.excelOnly && isPdfCols) {
            b.disabled = true;
            b.title = schColPdfReason();
        }
        cols.appendChild(b);
    });
    if (isPdfCols) {
        const why = document.createElement('span');
        why.className = 'small text-muted ms-1';
        why.textContent = schColPdfReason();
        cols.appendChild(why);
    }
    if (upto < SCH_TEACH_MAX_MONTHS) {
        const more = document.createElement('button');
        more.type = 'button';
        more.className = 'btn btn-sm btn-outline-primary';
        more.textContent = '+ month';
        more.title = 'Offer another month button — for schedules longer than this layout appears to be.';
        more.onclick = function () {
            _schTeach.suggestedMonths = schMonthsToShow() + 1;
            schRenderTeachPalette();
        };
        cols.appendChild(more);
    }

    const rows = line('Rows');
    const isPdf = _schTeach.sourceType === 'pdf';
    SCH_ROW_FIELDS.forEach(f => {
        const b = armButton(f, f.label + ': ' + schRowFieldValue(f.key));
        if (f.excelOnly && isPdf) {
            b.disabled = true;
            b.title = schRowPdfReason();
            // A disabled button swallows hover in some browsers, so the reason is
            // also printed once beside the buttons.
        }
        rows.appendChild(b);
    });
    if (isPdf) {
        const why = document.createElement('span');
        why.className = 'small text-muted ms-1';
        why.textContent = schRowPdfReason();
        rows.appendChild(why);
    }
}

/**
 * Show the reviewer the control that is blocking Save.
 *
 * MEASURED CAUSE (2026-09-14): the save gate in schRenderTeachReadout is strictly
 * ordered — description, then months, then header row — so "Pick at least one
 * header row." only ever surfaces once the other two already pass. By then it
 * prints in #teachHint BELOW the grid, while the control it refers to is the
 * collapsed "Header row: none picked yet" line ABOVE it, whose picker is shut by
 * default. The reviewer is told WHAT is wrong in one place and WHERE to fix it in
 * another, with a screenful of grid in between. That is how a session ends with
 * "I can't save even though I've assigned everything."
 *
 * Arms the "Header row" field and flashes its button ONCE per teaching session.
 * 2026-09-15: the checkbox list this used to open is gone (rows are now clicked on
 * the grid), so the nudge ARMS the field instead — the reviewer's next click on an
 * R-number then does the right thing. Once, because re-arming on every render would
 * hijack a reviewer who deliberately armed something else; the flag sits on
 * _schTeach so a different capture nudges again.
 * Called by: schRenderTeachReadout() when the header row is the only thing blocking Save.
 */
function schNudgeHeaderPicker() {
    if (!_schTeach || _schTeach.headerNudged) return;
    _schTeach.headerNudged = true;
    _schTeach.field = 'rowHeader';
    // Neither render calls back into the readout, so this cannot recurse.
    schRenderTeachPalette();
    schRenderTeachGrid();
    const btn = document.getElementById('teachFieldBtn-rowHeader');
    if (!btn) return;
    btn.style.boxShadow = '0 0 0 3px #dc3545';
    try { btn.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { /* older browsers */ }
    setTimeout(function () { btn.style.boxShadow = ''; }, 2400);
}

// One R-number click for an armed row field -> { changed, message }. The click
// rules and every refusal are documented in scurveTeachCore.applyRowPick().
function schApplyRowPick(t, field, n) { return schCore().applyRowPick(t, field, n); }

// Does row n hold a value under an assigned month column? (null = no months yet)
function schRowHasMonthValues(t, n) { return schCore().rowHasMonthValues(t, n); }

// Click on an R-number in the teach grid. Row fields take it; with a column field
// armed the click is explained rather than silently ignored.
function schTeachClickRow(n) {
    if (!_schTeach) return;
    if (!schIsRowField(_schTeach.field)) {
        showToast('Arm "Header row", "Not an activity" or "Cumulative row" first, then click the R-number.', 'info');
        return;
    }
    const res = schApplyRowPick(_schTeach, _schTeach.field, n);
    if (res.message) { showToast(res.message, 'warning'); }
    if (!res.changed) return;
    schRenderTeachPalette();
    schRenderTeachGrid();
    schRenderTeachReadout();
}

// Short human label for a row ("TOTAL"), for toasts and the advisory.
function schRowLabel(r) { return schCore().rowLabel(r); }

// Browser twin of SCurveImportService::rowSignature(); parity pinned from both
// suites by tests/fixtures/scurve_row_signature_cases.json.
function schRowSignature(cells) { return schCore().rowSignature(cells); }

// (2026-09-15) schRenderTeachHeaderRows / schRenderTeachRowRoles and their
// checkbox/radio lists were removed: rows are picked by clicking R-numbers on the
// grid (schTeachClickRow -> schApplyRowPick), shown by tint + tag in the grid and
// by the palette's Rows line. Excel-only rationale for the two role fields is
// unchanged — see scurveTeachCore.ROW_PDF_REASON.

// Which field (if any) currently claims a column letter.
function schTeachClaim(letter) {
    if (!_schTeach) return null;
    const a = _schTeach.assigns;
    if (a.desc === letter) return 'desc';
    if (a.code === letter) return 'code';
    if (a.weight === letter) return 'weight';
    if (a.contract === letter) return 'contract';
    const found = Object.keys(a.months).find(k => (a.months[k] || []).indexOf(letter) !== -1);
    return found ? 'm' + found : null;
}

function schTeachClickLetter(letter) {
    if (!_schTeach) return;
    const field = _schTeach.field;
    const a = _schTeach.assigns;
    // A row field is armed: a column letter means nothing to it. Say so rather than
    // dropping the click, and assign nothing — a stray letter must never land in a
    // row pick or a column field the reviewer did not arm.
    if (schIsRowField(field)) {
        showToast('"' + schRowFieldTitle(field) + '" takes R-numbers — click a row on the left, or arm a column field first.', 'info');
        return;
    }
    // Item 3c — the three Excel-only column fields cannot be armed on a PDF (their
    // buttons are disabled), but say so rather than assigning silently if one ever is.
    if (_schTeach.sourceType === 'pdf' && (field === 'contract' || field === 'weight' || field === 'code')) {
        showToast(schColPdfReason(), 'info');
        return;
    }
    if (field === 'desc' || field === 'code' || field === 'weight' || field === 'contract') {
        a[field] = (a[field] === letter) ? null : letter;
        // Item 3b — the band remembered from the stored map describes the OLD pick, so
        // it must not outlive it; schDescBand() recomputes from the new one.
        if (field === 'desc') _schTeach.descBand = null;
    } else if (schCore().isMonthField(field)) {
        // 2026-09-14: was indexOf('m') === 0, which claims EVERY field key
        // beginning with m. The row-role work was one palette button away from
        // adding such a key, and the failure would have been near-undebuggable:
        // clicking a column with "mark" armed would assign it to month "ark",
        // a month number that quietly never matches anything.
        const m = field.substring(1);
        const list = a.months[m] || [];
        // A column belongs to exactly one field — steal it from elsewhere first.
        schTeachUnassign(letter);
        const at = list.indexOf(letter);
        if (at !== -1) { list.splice(at, 1); }
        else if (list.length < 2) { list.push(letter); }
        else { showToast('At most two columns per month — click one to remove it first', 'warning'); return; }
        if (list.length) a.months[m] = list; else delete a.months[m];
        // Item 3b — the first month is the description band's right edge, so moving a
        // month can move the band. Drop the remembered one and recompute.
        _schTeach.descBand = null;
    }
    schRenderTeachPalette();
    schRenderTeachGrid();
    schRenderTeachReadout();
}

function schTeachUnassign(letter) {
    if (!_schTeach) return;
    const a = _schTeach.assigns;
    ['desc', 'code', 'weight', 'contract'].forEach(k => { if (a[k] === letter) a[k] = null; });
    Object.keys(a.months).forEach(k => {
        a.months[k] = (a.months[k] || []).filter(l => l !== letter);
        if (!a.months[k].length) delete a.months[k];
    });
}

function schRenderTeachGrid() {
    const host = document.getElementById('teachGrid');
    if (!host || !_schTeach) return;
    let html = '<table class="table table-sm table-bordered mb-0" style="font-size:11px;">'
        + '<thead class="table-light"><tr><th></th>';
    _schTeach.letters.forEach(l => {
        const claim = schTeachClaim(l);
        // Item 3b — a letter inside the description band but not the one picked gets a
        // faded DESC badge, so the reviewer sees how far the band really reaches
        // without being told it was assigned column by column.
        const inBand = !claim && schInDescBand(l);
        html += '<th style="cursor:pointer;white-space:nowrap;" data-sch-letter="' + schEscapeHtml(l) + '" title="Assign ' + schEscapeHtml(l) + '">'
            + schEscapeHtml(l)
            + (claim ? '<br><span class="badge bg-primary" style="font-size:9px;">' + schEscapeHtml(schTeachFieldLabel(claim)) + '</span>' : '')
            + (inBand ? '<br><span class="badge bg-primary" style="font-size:9px;opacity:.45;"'
                + ' title="Part of the description band — the work names are read from every column up to the first month.">DESC</span>' : '')
            + '</th>';
    });
    html += '</tr></thead><tbody>';
    // Row picks are drawn in the row itself — a tint plus a tag beside the R-number
    // — the way column picks are drawn as badges under the letter. The R badge keeps
    // its r.role colour: picks live in _schTeach, NEVER in r.role, because the role
    // tag (and the CUMULATIVE badge) is what the capture itself said.
    const tintOf = {
        HDR: { bg: '#e7f1ff', empty: '#dbe8fb', tag: 'bg-primary', tip: 'Header row' },
        SKIP: { bg: '#eceff1', empty: '#e2e6ea', tag: 'bg-secondary', tip: 'Not an activity' },
        CURVE: { bg: '#e6f4ea', empty: '#d6ecdc', tag: 'bg-success', tip: 'Cumulative row (also not an activity)' },
    };
    const rowArmed = schIsRowField(_schTeach.field);
    _schTeach.rows.forEach(r => {
        const badge = SCH_ROLE_BADGE[r.role] || 'secondary';
        const pick = _schTeach.cumulativeRow === r.n ? 'CURVE'
            : _schTeach.headerRows.indexOf(r.n) !== -1 ? 'HDR'
            : _schTeach.excludedRows.indexOf(r.n) !== -1 ? 'SKIP' : '';
        const tint = pick ? tintOf[pick] : null;
        html += '<tr><td style="white-space:nowrap;cursor:pointer;' + (tint ? 'background:' + tint.bg + ';' : '') + '"'
            + ' data-sch-row="' + r.n + '"'
            + ' title="' + (rowArmed ? 'Click to set R' + r.n + ' as ' + schEscapeHtml(schRowFieldTitle(_schTeach.field)) : 'Arm a row field above, then click to assign R' + r.n) + '">'
            + '<span class="badge bg-' + badge + '" style="font-size:9px;' + (rowArmed ? 'outline:1px dashed #0d6efd;outline-offset:1px;' : '') + '">R' + r.n + '</span>'
            + (pick ? ' <span class="badge ' + tint.tag + '" style="font-size:8px;" title="' + tint.tip + '">' + pick + '</span>' : '')
            + '</td>';
        for (let c = 0; c < _schTeach.letters.length; c++) {
            const v = (r.cells[c] !== undefined ? String(r.cells[c]).trim() : '');
            if (v === '') {
                html += '<td style="background:' + (tint ? tint.empty : '#f8f9fa') + ';"></td>';
            } else if (schCore().isEdgeCell(_schTeach, r.n, _schTeach.letters[c])) {
                // Page-edge mark (2026-09-15): the print cut this cell's text off at the
                // page edge (the roof's "495,173." — the full 495,173.83 is on page 2),
                // so it is shown faded and labelled rather than like a real value.
                html += '<td style="color:#9ca3af;font-style:italic;' + (tint ? 'background:' + tint.bg + ';' : '') + '"'
                    + ' title="Cut off at the page edge — only part of this cell was printed on this page. It is probably repeated in full on the next page, so this column is usually left untaught.">'
                    + schEscapeHtml(v) + ' <span class="badge bg-warning text-dark" style="font-size:8px;font-style:normal;">cut</span></td>';
            } else {
                html += '<td' + (tint ? ' style="background:' + tint.bg + ';"' : '') + '>' + schEscapeHtml(v) + '</td>';
            }
        }
        html += '</tr>';
    });
    // One line of explanation above the grid whenever any cell carries the mark.
    const edgeNote = (_schTeach.edgeCells && _schTeach.edgeCells.length)
        ? '<div class="small px-2 py-1" style="color:#92400e;background:#fffbeb;border-bottom:1px solid #fde68a;">'
            + 'Cells marked <span class="badge bg-warning text-dark" style="font-size:8px;">cut</span> were cut off at the page edge when this PDF was printed — '
            + 'usually a partial copy of a column printed in full on the next page. Leave such a column untaught.</div>'
        : '';
    host.innerHTML = edgeNote + html + '</tbody></table>';
    // Column letters and R-numbers are SEPARATE targets with separate handlers: a
    // row handler must never shadow the column-header clicks, which are the only
    // way to teach PDF months (regression restudy, trap (f)).
    host.querySelectorAll('[data-sch-letter]').forEach(th => {
        th.onclick = function () { schTeachClickLetter(th.getAttribute('data-sch-letter')); };
    });
    host.querySelectorAll('td[data-sch-row]').forEach(td => {
        td.onclick = function () { schTeachClickRow(parseInt(td.getAttribute('data-sch-row'), 10)); };
    });
}

function schRenderTeachReadout() {
    const host = document.getElementById('teachReadout');
    const hint = document.getElementById('teachHint');
    const save = document.getElementById('teachSaveBtn');
    if (!host || !_schTeach) return;
    const a = _schTeach.assigns;
    const parts = [];
    // Item 3b — on a PDF, say the whole band ("Description=B..G"), because that is
    // what the reader uses. One letter was the old, misleading answer (finding A).
    if (a.desc) {
        const band = schDescBand();
        parts.push('Description=' + (band.length > 1 ? band[0] + '..' + band[band.length - 1] : a.desc));
    }
    if (a.contract) parts.push('Contract amount=' + a.contract);
    if (a.weight) parts.push('Weight=' + a.weight);
    if (a.code) parts.push('Code=' + a.code);
    const months = Object.keys(a.months).sort((x, y) => x - y);
    months.forEach(m => parts.push('M' + m + '=' + a.months[m].join('+')));
    // 2026-09-14: with no header row ticked, 'Headers R' + [].join('+R') rendered
    // a bare "Headers R ·" — which reads as a row named R and hides the very fact
    // that nothing is picked. Say so instead.
    const head = _schTeach.headerRows.length
        ? 'Headers ' + _schTeach.headerRows.map(n => 'R' + n).join('+')
        : 'No header row picked';
    // Row roles read back in the same line, so the reviewer can see at a glance
    // that they excluded something — an exclusion they forgot they made is the
    // failure mode worth surfacing, since its effect is invisible in the numbers.
    if (_schTeach.sourceType !== 'pdf') {
        if (_schTeach.excludedRows.length) {
            parts.push('Not activities=' + _schTeach.excludedRows.map(n => 'R' + n).join('+'));
        }
        if (_schTeach.cumulativeRow) {
            parts.push('Cumulative=R' + _schTeach.cumulativeRow);
        }
    }
    host.textContent = parts.length
        ? head + ' · ' + parts.join(' · ')
        : 'Nothing assigned yet.';
    // PDF-4: a row the server will refuse says so FIRST, before the reviewer
    // spends time assigning columns. The server refuses independently — this only
    // saves the wasted effort.
    let problem = _schTeach.blocked || '';
    if (problem === '') {
        if (!a.desc) problem = 'Pick the Description column first.';
        else if (months.length < 2) problem = 'Assign at least two months.';
        else if (!_schTeach.headerRows.length) {
            problem = 'Pick at least one header row — arm "Header row" above the grid, then click its R-number.';
            schNudgeHeaderPicker();
        }
    }
    if (hint) hint.textContent = problem;
    if (save) save.disabled = problem !== '';

    // Advisory, never a Save gate: a cumulative pick with nothing under the month
    // columns is legal, but the reader will find no printed curve on it. Found on
    // DB row 118 (2026-09-15), where TOTAL was picked instead of the % row beneath.
    const note = document.getElementById('teachRoleNote');
    if (note) {
        let advice = '';
        if (_schTeach.sourceType !== 'pdf' && _schTeach.cumulativeRow) {
            const n = _schTeach.cumulativeRow;
            if (schRowHasMonthValues(_schTeach, n) === false) {
                const r = _schTeach.rows.find(x => x.n === n);
                advice = 'R' + n + (r ? ' (' + schRowLabel(r) + ')' : '') + ' has no values under the month columns you picked, '
                    + 'so no printed curve can be read from it. The cumulative row is usually the one with a percentage in every month.';
            }
        }
        note.textContent = advice;
    }

    schRenderTotalCheck();
}

// The month assignments as a comparable string — a warning belongs to exactly these.
function schMonthsKey() {
    if (!_schTeach) return '';
    const a = _schTeach.assigns.months || {};
    return Object.keys(a).sort((x, y) => x - y).map(k => k + '=' + (a[k] || []).join('+')).join(';');
}

/**
 * Render the PDF adds-up line under the readout and label the Save button.
 * Called by: schRenderTeachReadout() on every change, schSaveTeaching() on a warning.
 *
 * Live: "Adds up" (green) or "Not checked" (grey). A mismatch is NOT shown live —
 * mid-teaching every partial assignment would be one — but only once Save is
 * clicked (see schSaveTeaching), and it stays while the months are unchanged, with
 * Save relabelled "Save anyway". Any change to the months withdraws the warning.
 */
function schRenderTotalCheck() {
    const host = document.getElementById('teachTotalCheck');
    const save = document.getElementById('teachSaveBtn');
    if (!_schTeach) return;
    const check = schCore().taughtTotalCheck(_schTeach);
    if (_schTeach.saveWarnKey !== null && _schTeach.saveWarnKey !== schMonthsKey()) {
        _schTeach.saveWarnKey = null;
    }
    const warned = _schTeach.saveWarnKey !== null && check && check.status === 'mismatch';
    if (host) {
        host.textContent = '';
        host.style.cssText = '';
        if (warned) {
            host.style.cssText = 'color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:6px 10px;';
            host.textContent = schCore().taughtTotalMessage(check);
        } else if (check && check.status === 'adds-up') {
            host.style.color = '#15803d';
            host.textContent = schCore().taughtTotalMessage(check);
        } else if (check && check.status === 'unchecked') {
            host.style.color = '#6b7280';
            host.textContent = schCore().taughtTotalMessage(check);
        }
    }
    if (save && save.textContent !== 'Saving…') save.textContent = warned ? 'Save anyway' : 'Save layout';
}

async function schSaveTeaching() {
    if (!_schTeach) return;
    // PDF adds-up check (2026-09-15, user decision: warn, never refuse). The first
    // click on a mismatch shows the warning and relabels Save; a second click with
    // the same months saves. The import guard still refuses a reading that does not
    // add up, so a layout saved anyway cannot quietly import a wrong baseline.
    try {
        const check = schCore().taughtTotalCheck(_schTeach);
        if (check && check.status === 'mismatch' && _schTeach.saveWarnKey !== schMonthsKey()) {
            _schTeach.saveWarnKey = schMonthsKey();
            schRenderTotalCheck();
            const host = document.getElementById('teachTotalCheck');
            try { if (host) host.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { /* older browsers */ }
            return;
        }
    } catch (e) { /* the check is advisory — it must never block a save */ }
    const a = _schTeach.assigns;
    const months = {};
    Object.keys(a.months).forEach(k => { months[k] = a.months[k]; });
    const modeEl = document.querySelector('input[name="teachMode"]:checked');
    const payload = {
        desc: a.desc,
        code: a.code,
        weight: a.weight,
        contract: a.contract,
        months: months,
        header_rows: _schTeach.headerRows.slice(),
        mode: modeEl ? modeEl.value : 'variant',
    };
    // Sent only for Excel rows, and only when actually taught. The server refuses
    // these fields on a PDF row rather than dropping them silently, so sending an
    // empty pair there would turn every PDF save into a 422.
    if (_schTeach.sourceType !== 'pdf') {
        if (_schTeach.excludedRows.length) { payload.excluded_rows = _schTeach.excludedRows.slice(); }
        if (_schTeach.cumulativeRow) { payload.cumulative_row = _schTeach.cumulativeRow; }
    }
    const saveBtn = document.getElementById('teachSaveBtn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }
    try {
        const response = await fetch(schBase() + '/scurve-header-proposals/' + encodeURIComponent(_schTeach.id) + '/columns', {
            method: 'PATCH',
            headers: schHeaders(),
            body: JSON.stringify(payload),
        });
        let data = {};
        try { data = await response.json(); } catch (e) {}
        if (!response.ok) {
            showToast(schExtractErrorMessage(data, 'Failed to save layout'), 'error');
            return;
        }
        const modalEl = document.getElementById('teachScurveColumnsModal');
        const inst = bootstrap.Modal.getInstance(modalEl);
        if (inst) inst.hide();
        showToast(data.message || 'Layout saved for review', 'success');
        loadScurveHeadersForManagement();
    } catch (error) {
        console.error('Error saving teaching:', error);
        showToast('Network error — failed to save layout', 'error');
    } finally {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save layout'; }
        // Restore "Save anyway" if a warning still stands (a failed save keeps the modal open).
        try { schRenderTotalCheck(); } catch (e) { /* label only */ }
    }
}

// ===================================================
// LAUNCHER (used by menu entries)
// ===================================================
function openManageScurveHeaders() {
    const modalEl = document.getElementById('manageScurveHeadersModal');
    if (!modalEl) return;
    new bootstrap.Modal(modalEl).show();
}
</script>

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
                    <strong>Approve</strong> to trust a layout for its contractor,
                    <strong>Teach</strong> to point at its columns yourself (saves a variant —
                    alternating formats both keep working),
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

// Compact grid for the list cell (first 8 rows × 16 cols, scrollable).
function schMiniGrid(text) {
    const parsed = schParseHeaderBlock(text);
    if (!parsed) {
        return '<span class="text-muted">'
            + (text ? schEscapeHtml(String(text).substring(0, 120)) + '…' : '(empty header block)')
            + '</span>';
    }
    const maxCols = 16, maxRows = 8;
    const letters = parsed.letters.slice(0, maxCols);
    let html = '<div style="max-height:150px;overflow:auto;max-width:520px;">'
        + '<table class="table table-sm table-bordered mb-0" style="font-size:10px;">'
        + '<thead class="table-light"><tr><th style="position:sticky;top:0;"></th>';
    letters.forEach(l => { html += '<th style="position:sticky;top:0;">' + schEscapeHtml(l) + '</th>'; });
    html += '</tr></thead><tbody>';
    parsed.rows.slice(0, maxRows).forEach(r => {
        const badge = SCH_ROLE_BADGE[r.role] || 'secondary';
        html += '<tr><td><span class="badge bg-' + badge + '" style="font-size:9px;">R' + r.n + '</span></td>';
        for (let c = 0; c < letters.length; c++) {
            const v = (r.cells[c] !== undefined ? String(r.cells[c]).trim() : '');
            html += v === ''
                ? '<td style="background:#f8f9fa;"></td>'
                : '<td>' + schEscapeHtml(v) + '</td>';
        }
        html += '</tr>';
    });
    html += '</tbody></table>';
    if (parsed.rows.length > maxRows || parsed.letters.length > maxCols) {
        html += '<div class="text-muted" style="font-size:10px;">Showing first ' + Math.min(maxRows, parsed.rows.length)
            + ' rows × ' + letters.length + ' columns — open Teach columns for the full grid.</div>';
    }
    if (String(text).length >= 4000) {
        html += '<div class="text-warning" style="font-size:10px;">Stored block hit the 4000-char cap — tail may be cut.</div>';
    }
    return html + '</div>';
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

        // Cache rows for the teach modal (lookup by id, no extra endpoint).
        _schRowsCache = Array.isArray(rows) ? rows : [];

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
                ? schMiniGrid(row.header_rows_text)
                : '<span class="text-muted">(empty header block)</span>';
            const headerTip = '';
            const contractor = row.contractor
                ? schEscapeHtml(row.contractor)
                : '<span class="text-muted">Global</span>';
            const origin = row.source === 'AI-assisted'
                ? '<span class="badge bg-info text-dark" title="Kept from an AI-assisted read">AI-assisted</span>'
                : (row.source === 'manual-teach'
                    ? '<span class="badge bg-dark" title="Taught by a reviewer">Taught</span>'
                    : (row.source === 'ai-derived'
                        ? '<span class="badge bg-info text-dark" title="Learned from a successful AI read — needs review">AI-derived</span>'
                        : '<span class="badge bg-secondary" title="Captured from a failed import">Auto-capture</span>'));
            // A row with NO map is not merely "not Mapped" — the reader has nothing
            // to read it with, so approving it changes nothing and the next import
            // still fails. That happens whenever the rules could not find the header
            // (every row tags [DATA]), which is exactly the layout most in need of
            // teaching. Say so where the badge would have been (2026-09-14).
            const mapMark = row.has_map
                ? ' <span class="badge bg-primary" title="A column map is attached">Mapped</span>'
                : ' <span class="badge bg-warning text-dark" title="No column map was derived from this capture,'
                    + ' so the reader cannot use it. Teach its columns — approving alone will not make the file'
                    + ' readable.">Needs teaching</span>';
            // E1-PDF: PDF captures land in this same desk, so say which file kind a
            // row came from — the two are read by different engines and a reviewer
            // approving columns should know which one they are teaching.
            const kindMark = row.source_type === 'pdf'
                ? ' <span class="badge bg-danger" title="Captured from a PDF schedule">PDF</span>'
                : ' <span class="badge bg-success" title="Captured from an Excel workbook">Excel</span>';
            // Inline map summary (2026-09-10 reversal companion): reviewers approve
            // AI-sourced columns, so show WHAT they'd approve (DESC=C · M1=I+J…).
            let mapSummary = '';
            try {
                const cm = row.column_map || null;
                if (row.has_map && cm && cm.kind === 'pdf') {
                    // PDF-4 (2026-09-13): a PDF map is GEOMETRY (page-width fractions),
                    // not column letters, so the Excel reader below finds none of its
                    // keys and PDF rows showed no summary at all. Describe what a PDF
                    // map actually says: how many printed pages it covers and which
                    // months each one carries.
                    const pages = Array.isArray(cm.pages) ? cm.pages : [];
                    const bits = pages.map((p, i) => {
                        const months = (p.monthCols || []).map(mc => mc.index).filter(n => n >= 1);
                        if (!months.length) return 'page ' + (i + 1) + ': no months';
                        return 'page ' + (i + 1) + ': M' + months[0]
                            + (months.length > 1 ? '–M' + months[months.length - 1] : '');
                    });
                    // An AMBIGUOUS capture (positional wording — the roof prints "30
                    // days" six times, so nothing in the bytes says which month a
                    // column is) keeps its geometry with the months left UNNUMBERED.
                    // The reader refuses a month-less page, so APPROVING such a row
                    // changes nothing: the next import still answers "could not read
                    // the S-curve". That is correct, and until now it was also
                    // SILENT — the desk offered Approve like any other row and never
                    // said the click was a no-op (2026-09-14 acceptance run).
                    const unnumbered = pages.length > 0 && pages.every(
                        (p) => p.unnumbered === true || !(p.monthCols || []).some(mc => mc.index >= 1)
                    );
                    if (unnumbered) {
                        mapSummary = '<div style="font-size:10px;color:#b45309;font-weight:600;"'
                            + ' title="This layout records where the columns sit but not which month each one is,'
                            + ' because the page labels them all the same way. Approving it will not make the file'
                            + ' readable — teach the months first, then approve.">'
                            + '⚠ Months not numbered — approving alone will not read this file. Teach it first.'
                            + '</div>';
                    } else if (bits.length) {
                        const title = pages.length > 1
                            ? 'Printed across ' + pages.length + ' pages, shown here as one grid'
                            : 'Learned column bands — what approval would trust';
                        mapSummary = '<div class="text-muted" style="font-size:10px;" title="' + schEscapeHtml(title) + '">'
                            + schEscapeHtml(bits.join(' · ')) + '</div>';
                    }
                } else if (row.has_map && cm && typeof cm === 'object') {
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
                    if (bits.length) {
                        mapSummary = '<div class="text-muted" style="font-size:10px;" title="Taught/learned column positions — what approval would trust">'
                            + schEscapeHtml(bits.join(' · ')) + '</div>';
                    }
                }
            } catch (e) { /* summary is best-effort */ }
            // A row the reader cannot use must not offer Approve as if it would do
            // something (2026-09-14). Approving one used to flip it to live and
            // change nothing — the next import still failed. The server refuses
            // these independently; this only stops the wasted click.
            const cmForApprove = row.column_map || null;
            const unreadable = !row.has_map
                || (cmForApprove && cmForApprove.kind === 'pdf'
                    && Array.isArray(cmForApprove.pages) && cmForApprove.pages.length > 0
                    && cmForApprove.pages.every(p => !(p.monthCols || []).some(mc => mc.index >= 1)));
            const approveBtn = row.status === 'live' ? ''
                : (unreadable
                    ? '<button class="btn btn-sm btn-outline-secondary" disabled'
                        + ' title="There is nothing here the reader can use yet — teach this layout\'s columns first,'
                        + ' then approve."><i class="bi bi-check-lg"></i> Approve</button>'
                    : '<button class="btn btn-sm btn-outline-success" data-sch-approve="' + row.id + '" title="Trust this layout"><i class="bi bi-check-lg"></i> Approve</button>');
            const teachBtn = '<button class="btn btn-sm btn-outline-primary" data-sch-teach="' + row.id + '" title="Teach column positions for this layout"><i class="bi bi-table"></i> Teach</button>';
            html += '<tr>' +
                '<td><span' + headerTip + '>' + header + '</span>' + kindMark + mapMark + mapSummary + '</td>' +
                '<td>' + contractor + '</td>' +
                '<td><span class="badge bg-secondary">' + row.occurrences + '×</span></td>' +
                '<td>' + origin + '</td>' +
                '<td>' + badge + '</td>' +
                '<td class="text-end"><div class="d-flex gap-2 justify-content-end">' + approveBtn + teachBtn +
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
        return;
    }
    const teachBtn = e.target.closest ? e.target.closest('[data-sch-teach]') : null;
    if (teachBtn) {
        schTeachColumns(teachBtn.getAttribute('data-sch-teach'));
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

const SCH_TEACH_FIELDS = [
    { key: 'desc', label: 'Description', tip: 'Work-names column (e.g. TASK DESCRIPTION, WORK DETAILS) — activities are read from here. Required.' },
    { key: 'contract', label: 'Contract amount', tip: 'Peso column holding each activity\u2019s cost (e.g. BUDGET COST) — the baseline denominator. Optional.' },
    { key: 'weight', label: 'Weight', tip: 'Percent-weight column (e.g. % WEIGHT). Optional.' },
    { key: 'code', label: 'Item code', tip: 'Row numbering column (e.g. ITEM). Optional.' },
];
// PDF-4 Stage 2 — months are built on demand. A fixed wall of twelve buttons
// pushed the grid off the screen on every layout, however few months it had.
// SCH_TEACH_MAX is the ceiling the server also enforces (months max:12).
const SCH_TEACH_MAX_MONTHS = 12;
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
    const shown = SCH_TEACH_FIELDS.slice();
    const upto = schMonthsToShow();
    for (let m = 1; m <= upto; m++) shown.push(schMonthField(m));
    shown.forEach(f => cols.appendChild(armButton(f, f.label)));
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
    if (field === 'desc' || field === 'code' || field === 'weight' || field === 'contract') {
        a[field] = (a[field] === letter) ? null : letter;
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
        html += '<th style="cursor:pointer;white-space:nowrap;" data-sch-letter="' + schEscapeHtml(l) + '" title="Assign ' + schEscapeHtml(l) + '">'
            + schEscapeHtml(l)
            + (claim ? '<br><span class="badge bg-primary" style="font-size:9px;">' + schEscapeHtml(schTeachFieldLabel(claim)) + '</span>' : '')
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
            html += v === ''
                ? '<td style="background:' + (tint ? tint.empty : '#f8f9fa') + ';"></td>'
                : '<td' + (tint ? ' style="background:' + tint.bg + ';"' : '') + '>' + schEscapeHtml(v) + '</td>';
        }
        html += '</tr>';
    });
    host.innerHTML = html + '</tbody></table>';
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
    if (a.desc) parts.push('Description=' + a.desc);
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
}

async function schSaveTeaching() {
    if (!_schTeach) return;
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

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
                    Click a field, then click its column letter(s) on the grid.
                    Rows tagged <span class="badge bg-primary">HEADER</span> hold the labels;
                    <span class="badge bg-warning text-dark">SUMMARY</span> rows are reference only, and
                    <span class="badge bg-success">CUMULATIVE</span> marks the running-total row a baseline is read from.
                    R-numbers are kept order, not Excel rows. Hover any button for what it needs.
                </p>
                {{-- PDF-4 Stage 2 layout: the grid is the click surface and the whole
                     point of the screen, so it gets the full width. The palette sits
                     ABOVE it as a single band, and the header-row picker — auto-detected
                     and rarely changed — collapses to one line instead of a tall
                     checkbox column that used to push the grid off the screen. --}}
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2 p-1 rounded" id="teachHeaderBand"
                     style="transition:box-shadow .2s,background-color .2s;">
                    <span class="text-muted small" title="The line holding the column labels. Detected automatically; change it only if the labels print on a different line.">
                        <strong>Header row</strong>
                    </span>
                    <span id="teachHeaderSummary" class="small"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="teachHeaderToggle"
                            title="Pick a different line as the one holding the column labels."
                            onclick="schToggleHeaderPicker()">change</button>
                </div>
                <div id="teachHeaderRows" class="mb-2 d-flex flex-wrap gap-2" style="display:none !important;"></div>

                <div class="mb-1" title="Arm a field, then click its column letter(s) on the grid. Assigned columns show badges; clicking a taken letter moves it."><strong>Click a field, then its column(s) on the grid</strong></div>
                <div id="teachFieldPalette" class="d-flex flex-wrap gap-1 mb-2"></div>

                <div id="teachGrid" class="mb-2" title="Click a column letter to assign it to the armed field." style="overflow:auto;max-height:430px;border:1px solid #dee2e6;border-radius:8px;"></div>

                <div class="row g-3">
                    <div class="col-md-7">
                        <div class="mb-1"><strong>Your teaching</strong></div>
                        <div id="teachReadout" class="small text-muted mb-1">Nothing assigned yet.</div>
                        <div id="teachHint" class="small text-danger"></div>
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
function schSplitCsv(line) {
    // Quote-aware split (fputcsv quoting) — naive comma-split breaks on
    // quoted cells like "CONTRACT AMOUNT" or "1,000,000".
    const out = [];
    let cur = '';
    let quoted = false;
    for (let i = 0; i < line.length; i++) {
        const ch = line[i];
        if (quoted) {
            if (ch === '"') {
                if (line[i + 1] === '"') { cur += '"'; i++; }
                else { quoted = false; }
            } else { cur += ch; }
        } else if (ch === '"') { quoted = true; }
        else if (ch === ',') { out.push(cur); cur = ''; }
        else { cur += ch; }
    }
    out.push(cur);
    return out;
}

// Parse stored header text into { letters, rows: [{n, role, cells}] }.
// Returns null when the block carries no usable structure.
function schParseHeaderBlock(text) {
    if (!text) return null;
    const lines = String(text).split(/\r?\n/).map(s => s.trim()).filter(s => s !== '');
    if (lines.length < 2 || lines[0].indexOf('COLUMNS:') !== 0) return null;
    const legend = lines[0].substring('COLUMNS:'.length).split('|')[0] || '';
    const letters = legend.split(',').map(s => s.trim()).filter(s => s !== '');
    if (!letters.length) return null;
    const rows = [];
    for (let i = 1; i < lines.length; i++) {
        const m = lines[i].match(/^R(\d+)\s*\[([A-Z]+)\]:\s?(.*)$/);
        if (!m) continue;
        rows.push({ n: parseInt(m[1], 10), role: m[2], cells: schSplitCsv(m[3] || '') });
    }
    if (!rows.length) return null;
    return { letters, rows };
}

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

function schIndexToLetter(i) {
    // 0-based index → Excel letters (mirrors the server letter() helper).
    let s = '';
    i = parseInt(i, 10) + 1;
    while (i > 0) {
        const m = (i - 1) % 26;
        s = String.fromCharCode(65 + m) + s;
        i = Math.floor((i - 1) / 26);
    }
    return s;
}

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
            // How many months this layout looks like it has, so the palette offers
            // that many buttons instead of a fixed wall of twelve.
            suggestedMonths: (function () {
                try {
                    const cm = row.column_map;
                    if (cm && Array.isArray(cm.pages)) {
                        let n = 0;
                        cm.pages.forEach(pg => { (pg.monthCols || []).forEach(mc => { n = Math.max(n, mc.index); }); });
                        if (n) return n;
                        // An UNNUMBERED capture has the columns but not the numbering —
                        // offer one button per column that is not the description.
                        const cols = cm.pages.reduce((a, pg) => a + (pg.pseudoCols || []).length, 0);
                        if (cols > 2) return Math.min(12, cols - 2);
                    }
                    const spans = (cm && cm.monthSpans) || {};
                    const keys = Object.keys(spans).map(Number).filter(k => k >= 1);
                    if (keys.length) return Math.max.apply(null, keys);
                } catch (e) { /* best-effort sizing */ }
                return 0;
            })(),
        };
        // Default header rows: tagged HEADER lines (max 2). When NOTHING is tagged
        // (unknown files), default to EMPTY and force an explicit pick — defaulting
        // to the first line would usually arm the title row (F6 wart, 2026-09-10).
        // The readout + save gate already require ≥1 row.
        const tagged = parsed.rows.filter(r => r.role === 'HEADER').map(r => r.n).slice(0, 2);
        _schTeach.headerRows = tagged;
        // Prefill from an attached map (indices → letters).
        try {
            const cm = row.column_map || null;
            if (cm && cm.kind === 'pdf') {
                // PDF-4 (2026-09-13): a PDF map stores BANDS as page-width
                // fractions, not column letters, so reading cm.descCol here found
                // nothing and PDF rows opened with an empty palette. Walk the
                // recorded pseudoCols back to letters instead — the same bridge the
                // server uses to turn a taught letter into a band.
                // EVERY page, not just the first (2026-09-14). §3 made the grid one
                // table with letters running on across the seam, but this still read
                // cm.pages[0], so reopening a taught roof layout showed M1–M5 and
                // silently dropped M6/M7 — the months that live on the printed page
                // two. The reviewer saw their own saved work come back incomplete.
                // Letters are globally unique, so each page's bands resolve against
                // its OWN pseudoCols and the results simply merge.
                const pages = Array.isArray(cm.pages) ? cm.pages : [];
                pages.forEach(page => {
                    if (!page) return;
                    const pseudo = page.pseudoCols || [];
                    // DOMINANT overlap, not ANY overlap (2026-09-14).
                    //
                    // Month bands and pseudo-columns are two INDEPENDENT griddings of
                    // the same page — bands come from the midpoints between header
                    // labels, columns from clustering every token in the table — so
                    // their edges land a few thousandths apart. An any-overlap test
                    // therefore handed each month its own column AND a sliver of the
                    // next, prefilling M1=F+G · M2=G+H · … Measured on the roof: the
                    // real column is covered 90–99%, the neighbour 3–10%, so the two
                    // cases are nowhere near each other.
                    // This was never cosmetic — saving that prefill is REFUSED with
                    // "Month 2 starts left of where month 1 ends", so the reviewer had
                    // to clear every duplicate by hand before Save would work.
                    // A column is claimed when the overlap is most of the COLUMN or
                    // most of the BAND: either one alone is not enough. Column-share
                    // catches the ordinary case (a band covering its column); band-
                    // share catches a band narrower than the column it sits inside.
                    // Requiring BOTH to be small before rejecting is what keeps a
                    // continuation page's inherited, empty desc band — which grazes
                    // month 6's column by 4% — from claiming it as the description.
                    const lettersIn = (x, xEnd) => {
                        const bandWidth = (xEnd - x) || 1e-9;
                        return pseudo.map(pc => {
                            const colWidth = (pc.xEnd - pc.x) || 1e-9;
                            const overlap = Math.min(pc.xEnd, xEnd) - Math.max(pc.x, x);
                            return {
                                letter: String(pc.letter || '').toUpperCase(),
                                keep: overlap > 1e-9
                                    && (overlap / colWidth >= 0.5 || overlap / bandWidth >= 0.5),
                            };
                        }).filter(s => s.letter && s.keep).map(s => s.letter);
                    };
                    // The description is taught on ONE page (a continuation page
                    // legitimately has none), so the first page that yields one wins.
                    if (!_schTeach.assigns.desc) {
                        _schTeach.assigns.desc = lettersIn(page.descX, page.descRight)[0] || null;
                    }
                    (page.monthCols || []).forEach(mc => {
                        const cols = lettersIn(mc.x, mc.xEnd);
                        if (cols.length) _schTeach.assigns.months[mc.index] = cols;
                    });
                });
            } else if (cm && typeof cm === 'object') {
                const L = (v) => (v === null || v === undefined) ? null : schIndexToLetter(v);
                _schTeach.assigns.desc = L(cm.descCol);
                _schTeach.assigns.code = L(cm.codeCol);
                _schTeach.assigns.weight = L(cm.weightCol);
                _schTeach.assigns.contract = L(cm.contractCol);
                const spans = cm.monthSpans || {};
                Object.keys(spans).forEach(k => {
                    _schTeach.assigns.months[k] = (spans[k] || []).map(L).filter(Boolean);
                });
            }
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
        schRenderTeachHeaderRows();
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

function schTeachFieldLabel(key) {
    if (key === 'desc') return 'DESC';
    if (key === 'contract') return 'AMT';
    if (key === 'weight') return 'WT';
    if (key === 'code') return 'CODE';
    if (key.indexOf('m') === 0) return 'M' + key.substring(1);
    return key;
}

function schRenderTeachPalette() {
    const host = document.getElementById('teachFieldPalette');
    if (!host || !_schTeach) return;
    host.innerHTML = '';
    const shown = SCH_TEACH_FIELDS.slice();
    const upto = schMonthsToShow();
    for (let m = 1; m <= upto; m++) shown.push(schMonthField(m));
    shown.forEach(f => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + (_schTeach.field === f.key ? 'btn-primary' : 'btn-outline-secondary');
        b.textContent = f.label;
        b.title = f.tip || f.label;
        b.onclick = function () { _schTeach.field = f.key; schRenderTeachPalette(); };
        host.appendChild(b);
    });
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
        host.appendChild(more);
    }
}

// The header-row picker is collapsed by default — it is detected correctly almost
// always, and expanded it cost more vertical space than the grid it sat beside.
function schToggleHeaderPicker() {
    const host = document.getElementById('teachHeaderRows');
    if (!host) return;
    const hidden = host.style.display === 'none';
    host.style.setProperty('display', hidden ? 'flex' : 'none', 'important');
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
 * Opens the picker and flashes the band ONCE per teaching session. Once, because
 * re-forcing it open on every render would fight a reviewer who deliberately
 * collapsed it — the flag sits on _schTeach so a different capture nudges again.
 */
function schNudgeHeaderPicker() {
    if (!_schTeach || _schTeach.headerNudged) return;
    _schTeach.headerNudged = true;
    const host = document.getElementById('teachHeaderRows');
    if (host && host.style.display === 'none') {
        host.style.setProperty('display', 'flex', 'important');
    }
    const band = document.getElementById('teachHeaderBand');
    if (!band) return;
    band.style.boxShadow = '0 0 0 2px #dc3545';
    band.style.backgroundColor = '#fff5f5';
    try { band.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { /* older browsers */ }
    setTimeout(function () {
        band.style.boxShadow = '';
        band.style.backgroundColor = '';
    }, 2400);
}

function schRenderTeachHeaderRows() {
    const host = document.getElementById('teachHeaderRows');
    if (!host || !_schTeach) return;
    const summary = document.getElementById('teachHeaderSummary');
    if (summary) {
        summary.textContent = _schTeach.headerRows.length
            ? _schTeach.headerRows.map(n => 'R' + n).join(' + ')
            : 'none picked yet';
        summary.className = 'small ' + (_schTeach.headerRows.length ? 'text-success' : 'text-danger');
    }
    host.innerHTML = '';
    _schTeach.rows.forEach(r => {
        const label = document.createElement('label');
        label.className = 'd-block small';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'form-check-input me-1';
        cb.checked = _schTeach.headerRows.indexOf(r.n) !== -1;
        cb.onchange = function () {
            const at = _schTeach.headerRows.indexOf(r.n);
            if (cb.checked && at === -1) {
                if (_schTeach.headerRows.length >= 2) {
                    showToast('At most two header rows — uncheck one first', 'warning');
                    cb.checked = false;
                    return;
                }
                _schTeach.headerRows.push(r.n);
            } else if (!cb.checked && at !== -1) {
                _schTeach.headerRows.splice(at, 1);
            }
            schRenderTeachHeaderRows();
            schRenderTeachReadout();
        };
        label.appendChild(cb);
        label.appendChild(document.createTextNode('R' + r.n + ' [' + r.role + ']'));
        host.appendChild(label);
    });
}

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
    if (field === 'desc' || field === 'code' || field === 'weight' || field === 'contract') {
        a[field] = (a[field] === letter) ? null : letter;
    } else if (field.indexOf('m') === 0) {
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
    _schTeach.rows.forEach(r => {
        const badge = SCH_ROLE_BADGE[r.role] || 'secondary';
        html += '<tr><td style="white-space:nowrap;"><span class="badge bg-' + badge + '" style="font-size:9px;">R' + r.n + '</span></td>';
        for (let c = 0; c < _schTeach.letters.length; c++) {
            const v = (r.cells[c] !== undefined ? String(r.cells[c]).trim() : '');
            html += v === ''
                ? '<td style="background:#f8f9fa;"></td>'
                : '<td>' + schEscapeHtml(v) + '</td>';
        }
        html += '</tr>';
    });
    host.innerHTML = html + '</tbody></table>';
    host.querySelectorAll('[data-sch-letter]').forEach(th => {
        th.onclick = function () { schTeachClickLetter(th.getAttribute('data-sch-letter')); };
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
            problem = 'Pick at least one header row — use "Header row" above the grid.';
            schNudgeHeaderPicker();
        }
    }
    if (hint) hint.textContent = problem;
    if (save) save.disabled = problem !== '';
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

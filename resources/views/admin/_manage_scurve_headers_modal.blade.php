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
                    Click a field on the left, then click its column letter(s) on the grid.
                    Rows tagged <span class="badge bg-primary">HEADER</span> hold the labels;
                    <span class="badge bg-warning text-dark">SUMMARY</span> rows are reference only.
                    R-numbers are kept order, not Excel rows.
                </p>
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-2"><strong>1. Header row(s)</strong> <small class="text-muted">(1–2)</small></div>
                        <div id="teachHeaderRows" class="mb-3"></div>
                        <div class="mb-2"><strong>2. Click a field, then its column(s)</strong></div>
                        <div id="teachFieldPalette" class="d-flex flex-wrap gap-1 mb-3"></div>
                        <div class="mb-2"><strong>3. Save as</strong></div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="teachMode" id="teachModeVariant" value="variant" checked>
                            <label class="form-check-label" for="teachModeVariant">New variant <small class="text-muted">(keeps the old map — alternating formats both keep working)</small></label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="teachMode" id="teachModeReplace" value="replace">
                            <label class="form-check-label" for="teachModeReplace">Replace this map <small class="text-muted">(drops back to Proposed for re-review)</small></label>
                        </div>
                        <div class="mb-2"><strong>Your teaching</strong></div>
                        <div id="teachReadout" class="small text-muted mb-2">Nothing assigned yet.</div>
                        <div id="teachHint" class="small text-danger"></div>
                    </div>
                    <div class="col-md-9">
                        <div id="teachGrid" style="overflow:auto;max-height:420px;border:1px solid #dee2e6;border-radius:8px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="teachSaveBtn" onclick="schSaveTeaching()">Save layout</button>
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

const SCH_ROLE_BADGE = { META: 'secondary', HEADER: 'primary', DATA: 'light text-dark border', SUMMARY: 'warning text-dark' };

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
                    : '<span class="badge bg-secondary" title="Captured from a failed import">Auto-capture</span>');
            const mapMark = row.has_map
                ? ' <span class="badge bg-primary" title="A column map is attached">Mapped</span>'
                : '';
            const approveBtn = row.status === 'live' ? '' :
                '<button class="btn btn-sm btn-outline-success" data-sch-approve="' + row.id + '" title="Trust this layout"><i class="bi bi-check-lg"></i> Approve</button>';
            const teachBtn = '<button class="btn btn-sm btn-outline-primary" data-sch-teach="' + row.id + '" title="Teach column positions for this layout"><i class="bi bi-table"></i> Teach</button>';
            html += '<tr>' +
                '<td><span' + headerTip + '>' + header + '</span>' + mapMark + '</td>' +
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
        };
        // Default header rows: tagged HEADER lines (max 2), else the first line.
        const tagged = parsed.rows.filter(r => r.role === 'HEADER').map(r => r.n).slice(0, 2);
        _schTeach.headerRows = tagged.length ? tagged : [parsed.rows[0].n];
        // Prefill from an attached map (indices → letters).
        try {
            const cm = row.column_map || null;
            if (cm && typeof cm === 'object') {
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
    { key: 'desc', label: 'Description' },
    { key: 'contract', label: 'Contract' },
    { key: 'weight', label: 'Weight' },
    { key: 'code', label: 'Item code' },
];
for (let m = 1; m <= 12; m++) SCH_TEACH_FIELDS.push({ key: 'm' + m, label: 'Month ' + m });

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
    SCH_TEACH_FIELDS.forEach(f => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + (_schTeach.field === f.key ? 'btn-primary' : 'btn-outline-secondary');
        b.textContent = f.label;
        b.onclick = function () { _schTeach.field = f.key; schRenderTeachPalette(); };
        host.appendChild(b);
    });
}

function schRenderTeachHeaderRows() {
    const host = document.getElementById('teachHeaderRows');
    if (!host || !_schTeach) return;
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
    if (a.contract) parts.push('Contract=' + a.contract);
    if (a.weight) parts.push('Weight=' + a.weight);
    if (a.code) parts.push('Code=' + a.code);
    const months = Object.keys(a.months).sort((x, y) => x - y);
    months.forEach(m => parts.push('M' + m + '=' + a.months[m].join('+')));
    host.textContent = parts.length
        ? 'Headers R' + _schTeach.headerRows.join('+R') + ' · ' + parts.join(' · ')
        : 'Nothing assigned yet.';
    let problem = '';
    if (!a.desc) problem = 'Pick the Description column first.';
    else if (months.length < 2) problem = 'Assign at least two months.';
    else if (!_schTeach.headerRows.length) problem = 'Pick at least one header row.';
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

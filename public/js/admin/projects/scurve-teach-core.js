/**
 * scurve-teach-core.js  (ESM module)
 *
 * Pure logic of the S-Curve Layouts desk and its Teach modal
 * (resources/views/admin/_manage_scurve_headers_modal.blade.php). No DOM access,
 * so every rule here runs under `npm run test:js`
 * (tests/js/scurve-teach-core.test.mjs).
 *
 * WHY THIS FILE EXISTS (2026-09-15). These rules used to live in the modal's
 * inline <script>, where nothing could test them: the prefill that picks one
 * column per month (defect 7), the prefill that restores header and role rows
 * (defect 12), and the click rules for row fields. They were only ever checked by
 * one-off probes that cut the functions out of the Blade text. The modal now
 * keeps the DOM half and calls these.
 *
 * Loaded with <script type="module"> by BOTH role layouts beside the desk include
 * (app.blade.php, stafflayouts.blade.php) — a layout-wide desk needs its
 * dependency loaded layout-wide, the lesson of the 2026-09-13
 * showDeleteConfirmation bug. Attaches itself to window.scurveTeachCore for the
 * modal's classic script.
 */

/* ─── Stored header block ─────────────────────────────────────────────────── */

// Quote-aware CSV split (fputcsv quoting) — a naive comma split breaks on quoted
// cells like "CONTRACT AMOUNT" or "1,000,000".
export function splitCsv(line) {
    const out = [];
    let cur = '';
    let quoted = false;
    const text = String(line == null ? '' : line);
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (quoted) {
            if (ch === '"') {
                if (text[i + 1] === '"') { cur += '"'; i++; }
                else { quoted = false; }
            } else { cur += ch; }
        } else if (ch === '"') { quoted = true; }
        else if (ch === ',') { out.push(cur); cur = ''; }
        else { cur += ch; }
    }
    out.push(cur);
    return out;
}

// Parse stored header text into { letters, rows: [{n, role, cells}] }, or null
// when the block carries no usable structure. The desk list and the Teach modal
// both read the block through this one function.
export function parseHeaderBlock(text) {
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
        rows.push({ n: parseInt(m[1], 10), role: m[2], cells: splitCsv(m[3] || '') });
    }
    if (!rows.length) return null;
    return { letters, rows };
}

// 0-based column index -> Excel letters (0 = A, 26 = AA). Mirrors the server's
// SCurveImportService::letterFor().
export function indexToLetter(i) {
    let s = '';
    let n = parseInt(i, 10) + 1;
    while (n > 0) {
        const m = (n - 1) % 26;
        s = String.fromCharCode(65 + m) + s;
        n = Math.floor((n - 1) / 26);
    }
    return s;
}

/* ─── Row text ────────────────────────────────────────────────────────────── */

/**
 * A short human label for a row ("TOTAL", "CUMULATIVE % ACHIEVED"), for toasts
 * and the readout. Built from the row's own non-numeric cells — the same material
 * the server turns into the stored signature — so what the reviewer reads is what
 * gets matched.
 */
export function rowLabel(r) {
    const words = ((r && r.cells) || [])
        .map(c => String(c == null ? '' : c).trim())
        .filter(c => c !== '' && !/^-?[\d,.\s%]+$/.test(c));
    const text = words.join(' ').replace(/\s+/g, ' ').trim();
    return text.length > 46 ? text.slice(0, 45) + '…' : text;
}

// PHP is_numeric() on an already-trimmed cell: optional sign, digits with an
// optional fraction (or a bare fraction), optional exponent.
export const PHP_NUMERIC = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;

/**
 * Browser twin of SCurveImportService::rowSignature() — MUST stay in step with it.
 *
 * Turns a stored block row back into the text signature a taught map stores
 * (excludeSigs / cumulativeSig). Display-only: the server re-signs on save, and
 * the reader matches the live workbook, never this function.
 * Mirrors PHP exactly: drop empty and numeric cells, join with a space, lowercase,
 * collapse ASCII whitespace (PCRE \s without /u), PHP trim().
 * PARITY IS PINNED FROM BOTH SIDES by one shared case list,
 * tests/fixtures/scurve_row_signature_cases.json — read by the JS test and by
 * SCurveImportTest. Change the function on one side and a suite goes red.
 */
export function rowSignature(cells) {
    const words = [];
    (cells || []).forEach(c => {
        const t = String(c == null ? '' : c).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
        if (t === '' || PHP_NUMERIC.test(t)) return;
        words.push(t);
    });
    return words.join(' ').toLowerCase()
        .replace(/[ \t\n\x0B\f\r]+/g, ' ')
        .replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
}

/* ─── Opening the Teach modal: starting state from a stored capture ───────── */

/**
 * How many month buttons the palette should offer for this capture's map.
 * PDF: the highest numbered month, else (an UNNUMBERED capture) one per column
 * that is not the description, capped at 12. Excel: the highest month span key.
 * Best-effort: 0 when nothing can be judged.
 */
export function suggestedMonthsFor(cm) {
    try {
        if (cm && Array.isArray(cm.pages)) {
            let n = 0;
            cm.pages.forEach(pg => { ((pg && pg.monthCols) || []).forEach(mc => { n = Math.max(n, mc.index); }); });
            if (n) return n;
            const cols = cm.pages.reduce((a, pg) => a + ((pg && pg.pseudoCols) || []).length, 0);
            if (cols > 2) return Math.min(12, cols - 2);
        }
        const spans = (cm && cm.monthSpans) || {};
        const keys = Object.keys(spans).map(Number).filter(k => k >= 1);
        if (keys.length) return Math.max.apply(null, keys);
    } catch (e) { /* best-effort sizing */ }
    return 0;
}

// Default header rows: lines tagged HEADER, at most two. When nothing is tagged
// (unknown files) the default is EMPTY and the reviewer must pick — defaulting to
// the first line would usually arm the title row (F6 wart, 2026-09-10).
export function defaultHeaderRows(parsed) {
    return ((parsed && parsed.rows) || []).filter(r => r.role === 'HEADER').map(r => r.n).slice(0, 2);
}

/**
 * Which pseudo-column letters does a PDF band [x, xEnd) claim?
 *
 * DOMINANT overlap, not ANY overlap (defect 7, 2026-09-14). Month bands and
 * pseudo-columns are two INDEPENDENT griddings of the same page — bands from the
 * midpoints between header labels, columns from clustering every token — so their
 * edges land a few thousandths apart. Any-overlap handed each month its own column
 * AND a sliver of the next (M1=F+G · M2=G+H …), and saving that is refused with
 * "Month 2 starts left of where month 1 ends". Measured on the roof: the real
 * column is covered 90–99%, the neighbour 3–10%.
 * A column is claimed when the overlap is most of the COLUMN or most of the BAND.
 * Requiring BOTH to be small before rejecting keeps a continuation page's
 * inherited empty desc band (grazing month 6's column by 4%) from claiming it,
 * while a band narrower than its column is still recognised.
 */
export function lettersInBand(pseudoCols, x, xEnd) {
    const bandWidth = (xEnd - x) || 1e-9;
    return (pseudoCols || []).map(pc => {
        const colWidth = (pc.xEnd - pc.x) || 1e-9;
        const overlap = Math.min(pc.xEnd, xEnd) - Math.max(pc.x, x);
        return {
            letter: String(pc.letter || '').toUpperCase(),
            keep: overlap > 1e-9 && (overlap / colWidth >= 0.5 || overlap / bandWidth >= 0.5),
        };
    }).filter(s => s.letter && s.keep).map(s => s.letter);
}

/**
 * Fill a fresh teaching state `t` from a stored column map. Mutates `t`.
 *
 * Expects t = { assigns: {desc, code, weight, contract, months: {}},
 *               headerRows: [...defaults], excludedRows: [], cumulativeRow: null }.
 * Called by: schTeachColumns() in the modal, right after building `t`.
 *
 * Best-effort by contract, exactly as the inline version was: a malformed map
 * leaves whatever was already filled, and the three Excel stages each have their
 * own guard so one failing cannot undo the others.
 *
 *   PDF map   every page (not just the first — defect 6), bands -> letters by
 *             dominant overlap; the description comes from the first page that
 *             yields one (a continuation page has none).
 *   Excel map column indices -> letters; then header-row memory (headerRows,
 *             display-only, invalid/unknown R ignored, max 2); then row-role
 *             memory (defect 12): re-sign each non-header block row and tick the
 *             rows the map's excludeSigs / cumulativeSig name — ONE row per
 *             signature, first in block order, because the server refuses two
 *             picks that read the same.
 */
export function prefillFromMap(t, cm, parsed) {
    const rows = (parsed && parsed.rows) || [];
    try {
        if (cm && cm.kind === 'pdf') {
            const pages = Array.isArray(cm.pages) ? cm.pages : [];
            pages.forEach(page => {
                if (!page) return;
                const pseudo = page.pseudoCols || [];
                if (!t.assigns.desc) {
                    t.assigns.desc = lettersInBand(pseudo, page.descX, page.descRight)[0] || null;
                }
                (page.monthCols || []).forEach(mc => {
                    const cols = lettersInBand(pseudo, mc.x, mc.xEnd);
                    if (cols.length) t.assigns.months[mc.index] = cols;
                });
            });
            return;
        }
        if (!cm || typeof cm !== 'object') return;

        const L = (v) => (v === null || v === undefined) ? null : indexToLetter(v);
        t.assigns.desc = L(cm.descCol);
        t.assigns.code = L(cm.codeCol);
        t.assigns.weight = L(cm.weightCol);
        t.assigns.contract = L(cm.contractCol);
        const spans = cm.monthSpans || {};
        Object.keys(spans).forEach(k => {
            t.assigns.months[k] = (spans[k] || []).map(L).filter(Boolean);
        });

        try {
            const saved = Array.isArray(cm.headerRows) ? cm.headerRows : [];
            const known = {};
            rows.forEach(r => { known[r.n] = true; });
            const kept = saved.map(n => parseInt(n, 10)).filter(n => n >= 1 && n <= 40 && known[n]);
            if (kept.length) t.headerRows = kept.slice(0, 2);
        } catch (e2) { /* best-effort */ }

        try {
            const exSigs = Array.isArray(cm.excludeSigs)
                ? cm.excludeSigs.map(s => String(s).trim()).filter(s => s !== '')
                : [];
            const cuSig = typeof cm.cumulativeSig === 'string' ? cm.cumulativeSig.trim() : '';
            if (exSigs.length || cuSig) {
                const claimed = {};
                rows
                    .filter(r => t.headerRows.indexOf(r.n) === -1 && r.role !== 'HEADER')
                    .forEach(r => {
                        const sig = rowSignature(r.cells);
                        if (sig === '' || claimed[sig]) return;
                        if (exSigs.indexOf(sig) !== -1 || sig === cuSig) {
                            claimed[sig] = true;
                            t.excludedRows.push(r.n);
                            if (sig === cuSig) t.cumulativeRow = r.n;
                        }
                    });
                t.excludedRows.sort((x, y) => x - y);
            }
        } catch (e3) { /* best-effort */ }
    } catch (e) { /* best-effort */ }
}

/* ─── Row fields: Header row / Not an activity / Cumulative row ───────────── */

// Palette keys of the row fields. They must NOT start with "m": month fields are
// m1..m12, and dispatchers once tested indexOf('m') === 0 (restudy trap (a)).
export const ROW_FIELD_KEYS = ['rowHeader', 'rowSkip', 'rowCurve'];
export const ROW_PDF_REASON = 'Excel layouts only — a PDF layout finds its cumulative row by shape.';

// Is this palette key a row field (as opposed to a column field)?
export function isRowField(key) {
    return ROW_FIELD_KEYS.indexOf(key) !== -1;
}

// Is this palette key a month field (m1..m12)?
export function isMonthField(key) {
    return /^m\d+$/.test(String(key));
}

/**
 * Apply ONE click on an R-number for an armed row field. Mutates `t`.
 * Returns { changed, message } — `message` is a refusal the caller shows.
 * Called by: schTeachClickRow() in the modal.
 *
 * Every refusal mirrors one SCurveHeaderProposalController::teachColumns() makes
 * on Save, so the reviewer hears it on the click instead:
 *   - a row tagged HEADER is never summed, so it cannot be excluded;
 *   - a row the reviewer picked as a header row cannot also carry a role;
 *   - a row with no label text cannot be recognised again (empty signature);
 *   - two role rows that read the same cannot be told apart at read time;
 *   - either role field on a PDF row.
 * Picking a header row STEALS it from the role picks (the lists are disjoint by
 * construction), matching how a column click moves a letter between fields.
 */
export function applyRowPick(t, field, n) {
    const row = (t.rows || []).find(r => r.n === n);
    if (!row) return { changed: false, message: 'R' + n + ' is not in this block.' };
    const drop = (list, v) => { const at = list.indexOf(v); if (at !== -1) list.splice(at, 1); };
    const byNumber = (x, y) => x - y;

    if (field === 'rowHeader') {
        if (t.headerRows.indexOf(n) !== -1) {
            drop(t.headerRows, n);
            return { changed: true, message: null };
        }
        if (t.headerRows.length >= 2) {
            return { changed: false, message: 'At most two header rows — click R' + t.headerRows.join(' or R') + ' to clear one first.' };
        }
        t.headerRows.push(n);
        t.headerRows.sort(byNumber);
        drop(t.excludedRows, n);
        if (t.cumulativeRow === n) t.cumulativeRow = null;
        return { changed: true, message: null };
    }

    if (field !== 'rowSkip' && field !== 'rowCurve') return { changed: false, message: null };
    if (t.sourceType === 'pdf') return { changed: false, message: ROW_PDF_REASON };

    // Clicking a row that already holds this role clears it.
    if (field === 'rowSkip' && t.excludedRows.indexOf(n) !== -1) {
        drop(t.excludedRows, n);
        // The curve row must never be summed as work, so un-excluding it also
        // un-marks it as the curve.
        if (t.cumulativeRow === n) t.cumulativeRow = null;
        return { changed: true, message: null };
    }
    if (field === 'rowCurve' && t.cumulativeRow === n) {
        // Kept as Not an activity (visible on the grid) — a curve row the reviewer
        // just un-marked is still not work; they can clear that separately.
        t.cumulativeRow = null;
        return { changed: true, message: null };
    }

    if (row.role === 'HEADER') {
        return { changed: false, message: 'R' + n + ' is tagged HEADER — it is never added up as an activity, so there is nothing to exclude.' };
    }
    if (t.headerRows.indexOf(n) !== -1) {
        return { changed: false, message: 'R' + n + ' is a header row you picked — arm "Header row" and click it to release it first.' };
    }
    const sig = rowSignature(row.cells);
    if (sig === '') {
        return { changed: false, message: 'R' + n + ' has no label text to recognise it by — pick a row that names itself.' };
    }
    const twin = t.excludedRows.find(m => m !== n && rowSignature(((t.rows || []).find(r => r.n === m) || {}).cells) === sig);
    if (twin !== undefined) {
        return { changed: false, message: 'R' + n + ' reads the same as R' + twin + ' — the system could not tell them apart in the file.' };
    }

    if (t.excludedRows.indexOf(n) === -1) {
        t.excludedRows.push(n);
        t.excludedRows.sort(byNumber);
    }
    if (field === 'rowCurve') t.cumulativeRow = n;
    return { changed: true, message: null };
}

/**
 * Does row n hold a value under any column assigned to a month?
 * Returns null when no month is assigned yet (nothing to judge against).
 * Called by: schRenderTeachReadout() for the advisory cumulative-row note — a
 * running-total row prints a figure in the month columns; a TOTAL row does not.
 */
export function rowHasMonthValues(t, n) {
    const row = (t.rows || []).find(r => r.n === n);
    const letters = [];
    Object.keys((t.assigns && t.assigns.months) || {}).forEach(k => {
        (t.assigns.months[k] || []).forEach(l => letters.push(l));
    });
    if (!row || !letters.length) return null;
    return letters.some(l => {
        const c = (t.letters || []).indexOf(l);
        const v = c === -1 ? '' : String(row.cells[c] == null ? '' : row.cells[c]).trim();
        return /\d/.test(v);
    });
}

/* ─── Attach to window for the modal's classic script ─────────────────────── */

if (typeof window !== 'undefined') {
    window.scurveTeachCore = {
        splitCsv,
        parseHeaderBlock,
        indexToLetter,
        rowLabel,
        rowSignature,
        suggestedMonthsFor,
        defaultHeaderRows,
        lettersInBand,
        prefillFromMap,
        ROW_FIELD_KEYS,
        ROW_PDF_REASON,
        isRowField,
        isMonthField,
        applyRowPick,
        rowHasMonthValues,
    };
}

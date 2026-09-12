/**
 * scurve-pdf-parser-core.js  (ESM module)
 *
 * Pure, DOM-free helpers for importing S-curve data from contractor PDF
 * schedules (e.g. the CONSCHEDSAVINGS-style A3 table). This mirrors the
 * server-side Excel importer (SCurveImportService) but works entirely in the
 * browser using pdf.js text items (which carry x/y coordinates).
 *
 * The hard part is layout: the table's columns overflow onto a second page,
 * so we infer column x-boundaries from the header line, assign numeric tokens
 * to month columns by their x-position, then merge pages (columns or rows).
 *
 * Loaded with <script type="module">; attaches to window.scurvePdfParserCore
 * for the classic-script consumer (scurve-pdf-parser.js). In Node the file is
 * imported directly for unit tests (tests/js/scurve-pdf-parser.test.mjs).
 */

const MONTHS = {
    january: 1, february: 2, march: 3, april: 4, may: 5, june: 6,
    july: 7, august: 8, september: 9, october: 10, november: 11, december: 12,
    jan: 1, feb: 2, mar: 3, apr: 4, jun: 6, jul: 7, aug: 8,
    sep: 9, sept: 9, oct: 10, nov: 11, dec: 12,
};

export function isNumericToken(str) {
    const s = String(str || '').trim().replace(/[₱,]/g, '');
    return /^\d+(\.\d+)?$/.test(s);
}

export function parseNumeric(str) {
    const s = String(str || '').trim().replace(/[₱,]/g, '');
    const n = parseFloat(s);
    return isFinite(n) ? n : null;
}

// F-D — normalize taught month-header labels to bare words ("SEGMENT 1" →
// "segment"). Pure; untrusted input yields [] (reader behavior unchanged).
function normalizeMonthWords(monthWords) {
    const out = [];
    (Array.isArray(monthWords) ? monthWords : []).forEach((w) => {
        const s = String(w || '').trim().toLowerCase().replace(/\s*(\d{1,2})$/, '');
        if (s.length >= 2 && out.indexOf(s) === -1) out.push(s);
    });
    return out;
}

function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function matchMonthLabel(str, monthWords) {
    const s = String(str || '').trim().toLowerCase();
    const m = s.match(/^(?:month|m)\s*(\d{1,2})$/);
    if (m) {
        const n = parseInt(m[1], 10);
        return (n >= 1 && n <= 12) ? n : null;
    }
    // P1 — contractor shorthand months ("P1"–"P12", "Period/Phase N"), mirrored
    // from the Excel relaxed detector (E1-3 widening). Single-column spans only.
    const p = s.match(/^(?:p|period|phase)\s*(\d{1,2})$/);
    if (p) {
        const n = parseInt(p[1], 10);
        return (n >= 1 && n <= 12) ? n : null;
    }
    // P1-follow-up — stage/quarter months ("STAGE 1"–"STAGE 6", "Q1"–"Q4").
    // Proven necessary by the 6-stage specimen (STAGE matched no tier at all).
    // Single-column spans only.
    const g = s.match(/^(?:stage|stg|quarter|qtr|q)\s*(\d{1,2})$/);
    if (g) {
        const n = parseInt(g[1], 10);
        return (n >= 1 && n <= 12) ? n : null;
    }
    // P1 — ordinal months ("1st."–"12th.", roof family), single-column spans.
    const o = s.match(/^(\d{1,2})(st|nd|rd|th)\.?$/);
    if (o) {
        const n = parseInt(o[1], 10);
        return (n >= 1 && n <= 12) ? n : null;
    }
    const known = MONTHS[s] || null;
    if (known) return known;
    // F-D — learned month-header words (approved scurveMonth rows): a taught
    // "SEGMENT 1" generalizes to "SEGMENT N" (word + number 1..12). Lowest
    // priority — every hardcoded tier above already won its ties. Omitted
    // list = today's behavior exactly.
    const learned = normalizeMonthWords(monthWords);
    for (const w of learned) {
        const lm = s.match(new RegExp('^' + escapeRegExp(w) + '\\s*(\\d{1,2})$'));
        if (lm) {
            const n = parseInt(lm[1], 10);
            if (n >= 1 && n <= 12) return n;
        }
    }
    return null;
}

// Levenshtein distance (fuzzy header match needs no library for short labels).
function levenshtein(a, b) {
    const m = a.length;
    const n = b.length;
    if (!m) return n;
    if (!n) return m;
    let prev = new Array(n + 1);
    for (let j = 0; j <= n; j++) prev[j] = j;
    for (let i = 1; i <= m; i++) {
        const cur = [i];
        for (let j = 1; j <= n; j++) {
            cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
        prev = cur;
    }
    return prev[n];
}

// P1 — typo-tolerant header match (mirrors the Excel R2a headerFuzzyMatch):
// Levenshtein ≤ 2, long words only (short words never fuzz — exact regexes own
// them), anchored full-cell by the caller. Catches contractor misspellings like
// DESCRITPION → description while far words ("NO. OF DAYS") stay rejected.
export function headerFuzzyMatch(label, words, maxDist = 2) {
    const s = String(label || '').trim().toLowerCase();
    if (s === '' || s.length > 100) return false;
    for (const word of words) {
        if (word.length < 6) continue;
        if (levenshtein(s, word) <= maxDist) return true;
    }
    return false;
}

export function isDescriptionLabel(str) {
    const s = String(str || '').trim();
    if (/^(description|particulars|work\s*items?|activities?|scope|items?|item|list\s*of\s*works?|description\s*of\s*works?)$/i.test(s)) {
        return true;
    }
    // P1 — loose contractor wordings ("WORK DETAILS", "PAY ITEM") + typo
    // tolerance. Same vocabulary as the Excel relaxed detector; the hard
    // ≥2-months gate in detectHeaderColumns keeps loose matches from
    // hallucinating headers on their own.
    // P1-follow-up adds bare "tasks" (SCHEDULED TASKS matched no tier at all —
    // the list had "activities" but never task/tasks).
    if (/\b(descript\w*|detail\w*|particular\w*|activit\w*|tasks?|pay\s*item|bill\s*of|work\s*items?|scope|specification)\b/i.test(s)) {
        return true;
    }
    return headerFuzzyMatch(s, ['description', 'particulars', 'scope of work']);
}

export function isTotalLabel(str) {
    return /^(grand\s*)?total/i.test(String(str || '').trim());
}

export function isSummaryLabel(str) {
    // P1 — the roof family's "Commulative" typo joins the vocabulary (deterministic
    // word match; the Levenshtein path stays reserved for description labels).
    return /accomplished|cumulat\w*|commulat\w*|percent|%|cost\b/i.test(String(str || '').trim());
}

export function isNumericPct(str) {
    const s = String(str || '').trim();
    if (s.indexOf('%') === -1) return false;
    return /^[\d,]+(\.\d+)?%?$/.test(s.replace(/%$/, '').trim());
}

export function parseNumericPct(str) {
    const s = String(str || '').trim().replace('%', '').replace(/,/g, '');
    const n = parseFloat(s);
    return isFinite(n) ? n : null;
}

/* ─── PDF.js item handling ────────────────────────────────────────────────── */

// Group pdf.js text items into visual lines by Y-position tolerance, then sort
// each line's items left-to-right. PDF.js coordinates are bottom-up, so the
// top of the page has the largest y — descending sort yields reading order.
export function groupItemsIntoLines(items) {
    const Y_TOLERANCE = 2;
    const lines = [];
    for (const item of items) {
        const y = item.transform ? item.transform[5] : (item.y || 0);
        let line = null;
        for (const existing of lines) {
            if (Math.abs(existing.y - y) <= Y_TOLERANCE) { line = existing; break; }
        }
        if (!line) {
            line = { y, items: [] };
            lines.push(line);
        }
        line.items.push(item);
    }
    lines.sort((a, b) => b.y - a.y);
    for (const line of lines) {
        line.items.sort((a, b) => {
            const ax = a.transform ? a.transform[4] : (a.x || 0);
            const bx = b.transform ? b.transform[4] : (b.x || 0);
            return ax - bx;
        });
    }
    return lines;
}

// Convert grouped lines into compact token lines [{y, tokens:[{str,x,xEnd}]}].
export function linesToTokenLines(lines) {
    return (lines || []).map((line) => ({
        y: line.y,
        tokens: (line.items || []).map((item) => {
            const x = item.transform ? item.transform[4] : (item.x || 0);
            const w = item.width || 0;
            return { str: item.str, x, xEnd: x + w };
        }).sort((a, b) => a.x - b.x),
    }));
}

/* ─── Header detection ────────────────────────────────────────────────────── */

function longestNumberRun(bare) {
    let best = [];
    let current = [];
    let prev = null;
    for (const n of Object.keys(bare).map(Number).sort((a, b) => a - b)) {
        if (prev !== null && n === prev + 1) current.push(n);
        else current = [n];
        if (current.length > best.length) best = current;
        prev = n;
    }
    return best;
}

function detectHeaderColumns(tokens, nextLineTokens, opts) {
    const pageMaxX = opts.pageMaxX || 0;
    const lineIndex = opts.lineIndex || 0;
    const sorted = [...tokens].sort((a, b) => a.x - b.x);

    const descTokens = sorted.filter((t) => isDescriptionLabel(t.str));
    let descX = descTokens.length ? Math.min(...descTokens.map((t) => t.x)) : null;

    // E1-PDF capture only (opts.relaxed): accept ANY repeated "WORD N" wording as
    // months, not just the vocabulary the reader knows. Geometry below is shared
    // verbatim so the two paths can never drift; only the wording is loosened, and
    // only here — a relaxed read is a CAPTURE CANDIDATE that a human must approve
    // before it can ever fill a baseline (exactly the Excel deal).
    if (opts.relaxed) {
        // Indexed wording first ("SEGMENT 1..5"), then positional columns — the
        // roof family labels every month by duration ("30 days" ×6 + "7 days"),
        // so there is no index to read and only the repeated SHAPE identifies the
        // grid. A continuation page needs only 2 columns: the family was already
        // established, and tail pages carry few (the same relaxation the strict
        // detector makes for continuation pages).
        const indexed = relaxedMonthColumns(sorted);
        const relaxed = indexed.length >= 2
            ? indexed
            : positionalMonthColumns(sorted, opts.continuation ? 2 : 3);
        if (relaxed.length >= 2) {
            // Desc anchor: a known label if present, else the leftmost wordy token
            // left of the first month — what a reader would point at.
            if (descX === null) {
                const firstMonthX = relaxed[0].x;
                const wordy = sorted.filter((t) => t.x < firstMonthX
                    && /[A-Za-z]{2,}/.test(String(t.str || '')));
                if (wordy.length) descX = Math.min(...wordy.map((t) => t.x));
            }
            // No description column is normal on the OVERFLOW page of a split
            // table — the names were printed on page 1 and only value columns
            // continue here. Same allowance the strict detector makes, with the
            // same thresholds, so the two cannot diverge.
            if (descX === null) {
                const minNoDesc = opts.continuation ? 2 : 3;
                if (!(lineIndex < 8 && relaxed.length >= minNoDesc)) return null;
            }

            return finalizeHeaderBands(relaxed, sorted, descX, pageMaxX);
        }
        return null;
    }

    const explicit = [];
    const labelOnly = [];
    const bare = {};
    const monthWords = opts.monthWords || null;
    for (const t of sorted) {
        const idx = matchMonthLabel(t.str, monthWords);
        if (idx !== null) {
            explicit.push({ index: idx, x: t.x, xEnd: t.xEnd });
            continue;
        }
        if (/^(month|m)$/i.test(t.str.trim())) {
            labelOnly.push(t);
            continue;
        }
        const bm = t.str.trim().match(/^(\d{1,2})$/);
        if (bm) {
            const n = parseInt(bm[1], 10);
            if (n >= 1 && n <= 12 && (descX === null || t.x > descX)) {
                bare[n] = { x: t.x, xEnd: t.xEnd };
            }
        }
    }

    let months = explicit;
    if (months.length < 2) {
        const run = longestNumberRun(bare);
        if (run.length >= 2) {
            months = run.map((n) => ({ index: n, x: bare[n].x, xEnd: bare[n].xEnd }));
        }
    }
    if (months.length < 2 && labelOnly.length >= 2 && nextLineTokens) {
        const nextBare = {};
        for (const t of nextLineTokens) {
            const bm = t.str.trim().match(/^(\d{1,2})$/);
            if (bm) {
                const n = parseInt(bm[1], 10);
                if (n >= 1 && n <= 12) nextBare[n] = { x: t.x, xEnd: t.xEnd };
            }
        }
        const run = longestNumberRun(nextBare);
        if (run.length >= 2) {
            months = run.map((n) => ({ index: n, x: nextBare[n].x, xEnd: nextBare[n].xEnd }));
        }
    }

    // Continuation pages (a prior page already yielded a table header) relax the
    // no-desc minimum to 2 months (tail pages like STAGE 5/6 carry few columns),
    // but additionally require at least one EXPLICIT month label — bare "1 2"
    // number lines elsewhere must never become headers.
    const minNoDesc = opts.continuation ? 2 : 3;
    const allowNoDesc = lineIndex < 8 && months.length >= minNoDesc;
    if ((descTokens.length === 0 && !allowNoDesc) || months.length < 2) return null;
    if (descTokens.length === 0 && opts.continuation && explicit.length < 1) return null;

    return finalizeHeaderBands(months, sorted, descX, pageMaxX);
}

/**
 * Turn located month labels into column BANDS plus the description window.
 *
 * Shared by the strict reader and the relaxed capture detector so the geometry
 * has exactly one definition — the wording is the only thing that differs
 * between them, and this is what keeps that true.
 */
function finalizeHeaderBands(monthTokens, sorted, descX, pageMaxX) {
    let months = [...monthTokens].sort((a, b) => a.index - b.index);
    months = months.map((m, i) => ({
        index: m.index,
        x: m.x,
        xEnd: months[i + 1] ? months[i + 1].x : (pageMaxX + 1),
    }));

    // P2-b — description right edge: the x of the first header token right of
    // the description label (e.g. QTY/UNIT/BUDGET COST sit between the desc and
    // the months in contractor tables). Without it every value left of the first
    // month (item codes, quantities, peso totals) bleeds into activity names.
    // Falls back to the first month start when the desc label is last.
    // P3 — BLANK tokens can never be a column: pdf.js emits empty/whitespace text
    // items for padded cells, and one sitting just right of the description label
    // used to become the bound (e.g. an empty item at x=76 between ITEM and
    // SCHEDULED TASKS), collapsing the description window so every activity name
    // was cut off and rows fell back to "Activity N".
    let descRight = null;
    if (descX !== null) {
        for (const t of sorted) {
            if (String(t.str || '').trim() === '') continue;
            if (t.x > descX && !isDescriptionLabel(t.str)) {
                if (descRight === null || t.x < descRight) descRight = t.x;
            }
        }
    }

    return {
        descX: descX !== null ? descX : Math.max(0, months[0].x - 200),
        descRight: descRight !== null ? descRight : months[0].x,
        monthCols: months,
    };
}

/**
 * E1-PDF — month columns from ANY repeated "WORD N" wording (capture only).
 *
 * The reader knows a fixed vocabulary (Month/M/P/STAGE/Period/Phase, plus taught
 * words). A capture must recognise wording nobody has taught yet — that is the
 * whole point — so this groups tokens by their WORD STEM and keeps the stem with
 * the most ascending indices. Requiring a shared stem is what stops a header line
 * of unrelated numbers ("ITEM 1 … QTY 2") from posing as months.
 *
 * Pure. Returns [{index, x, xEnd}] (≥2) or [].
 */
function relaxedMonthColumns(sorted) {
    const byStem = {};
    for (const t of sorted) {
        const hit = relaxedMonthLabel(t.str);
        if (!hit) continue;
        const bucket = byStem[hit.stem] || (byStem[hit.stem] = {});
        // First occurrence of an index wins: reprinted headers keep the leftmost.
        if (!bucket[hit.index]) bucket[hit.index] = { index: hit.index, x: t.x, xEnd: t.xEnd };
    }

    let best = [];
    for (const stem of Object.keys(byStem)) {
        const cols = Object.values(byStem[stem]).sort((a, b) => a.index - b.index);
        // Left-to-right order must agree with the numbering, or these are not
        // month columns — they are numbers that happen to share a word.
        let ordered = true;
        for (let i = 1; i < cols.length; i++) {
            if (cols[i].x <= cols[i - 1].x) { ordered = false; break; }
        }
        if (ordered && cols.length > best.length) best = cols;
    }

    return best.length >= 2 ? best : [];
}

/**
 * E1-PDF — month columns identified by REPETITION rather than by an index.
 *
 * The roof family prints the same label over every month ("30 days" six times,
 * one of them "30days"), so month identity is purely positional: the Nth
 * repetition is month N. Whitespace is normalized away precisely because these
 * labels are typed inconsistently.
 *
 * Deliberately stricter than the indexed path — THREE repetitions minimum, since
 * two identical words prove far less than an ascending 1,2,3 does, and a wrong
 * grid here would invent a schedule. The caller additionally refuses any
 * candidate whose bands hold no numbers in the rows beneath.
 *
 * Pure. Returns [{index, x, xEnd}] in left-to-right order, or [].
 */
function positionalMonthColumns(sorted, minColumns = 3) {
    const byShape = {};
    for (const t of sorted) {
        const raw = String(t.str || '').trim();
        if (raw === '' || !/[A-Za-z]/.test(raw)) continue;
        // Group by SHAPE, not by identical text: the roof's final column reads
        // "7 days" where the rest read "30 days", and keying on the exact string
        // made that tail month invisible — a 7-month schedule captured as 6.
        // Masking digit runs unites them ("#days"); whitespace is normalized away
        // because these labels are typed inconsistently ("30days").
        const key = raw.toLowerCase().replace(/\s+/g, '').replace(/\d+/g, '#');
        (byShape[key] = byShape[key] || []).push(t);
    }

    let best = [];
    for (const key of Object.keys(byShape)) {
        const group = byShape[key].slice().sort((a, b) => a.x - b.x);
        if (group.length >= minColumns && group.length <= 12 && group.length > best.length) {
            best = group;
        }
    }

    return best.map((t, i) => ({ index: i + 1, x: t.x, xEnd: t.xEnd }));
}

/**
 * "SEGMENT 1" → {stem: 'segment', index: 1}; "P3" → {stem: 'p', index: 3}.
 * Null for anything that is not a word followed by a 1..12 index.
 */
export function relaxedMonthLabel(str) {
    const s = String(str || '').trim();
    const m = s.match(/^([A-Za-z][A-Za-z.\- ]{0,20}?)\s*[-#]?\s*(\d{1,2})$/);
    if (!m) return null;
    const index = parseInt(m[2], 10);
    if (!(index >= 1 && index <= 12)) return null;
    const stem = m[1].trim().toLowerCase().replace(/\s+/g, ' ');
    if (stem === '') return null;

    return { stem, index };
}

/* ─── Row assembly ────────────────────────────────────────────────────────── */

function buildRows(tokenLines, header, startIndex, ctx) {
    const rows = [];
    const summaryCandidates = [];
    let totalRowSum = null;
    let sawSummary = false;
    const firstMonthX = header.monthCols[0].x;
    // P2-b — description lives strictly between its label column and the next
    // header column (QTY/UNIT/COST sit left of the months). Falls back to the
    // old first-month boundary when the header gave no right edge.
    const descLimit = header.descRight != null ? Math.min(header.descRight, firstMonthX) : firstMonthX;
    const context = ctx || {};
    const priorSummary = !!context.priorSummary;

    for (let i = startIndex; i < tokenLines.length; i++) {
        const tokens = tokenLines[i].tokens;

        const descTokens = tokens.filter((t) => t.x < descLimit);
        let descText = descTokens.map((t) => t.str).join(' ').trim();
        descText = descText.replace(/^\s*\d{1,3}\s+/, '').trim();
        // Continuation value rows carry no words — a purely numeric desc is a
        // column value, never an activity name.
        if (/^[\d,\s.₱%#]+$/.test(descText)) descText = '';

        // P2-f — single assignment by token CENTER: a wide token straddling a
        // month boundary used to count in BOTH spans (the 19.99-style drift).
        // NOTE: token lines carry x/xEnd (computed from item widths upstream),
        // never width — center from xEnd, not t.width (which is undefined here).
        const monthVals = {};
        const monthPresence = {};
        let hasMonthValue = false;
        for (const t of tokens) {
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            const cx = (t.x + xEnd) / 2;
            let mc = null;
            for (let k = 0; k < header.monthCols.length; k++) {
                const c = header.monthCols[k];
                const isLast = k === header.monthCols.length - 1;
                if (cx >= c.x && (isLast ? cx <= c.xEnd : cx < c.xEnd)) { mc = c; break; }
            }
            if (!mc) continue;
            const s = t.str.trim();
            if (isNumericToken(s)) {
                monthVals[mc.index] = (monthVals[mc.index] || 0) + (parseNumeric(s) || 0);
                monthPresence[mc.index] = true;
                hasMonthValue = true;
            } else if (/^#+$/.test(s)) {
                // P1 — Excel column-width overflow ("###"): the value exists but
                // is unreadable. Presence flag only — never summed (Decision 10).
                monthPresence[mc.index] = true;
                hasMonthValue = true;
            } else if (/^\d[\d,]*(\.\d+)?\s*%$/.test(s)) {
                // P1 — physical-copy weighted % (e.g. "1.20%"): intentional
                // activity weight, parsed as a number (Decision 10).
                monthVals[mc.index] = (monthVals[mc.index] || 0) + (parseFloat(s.replace('%', '').replace(/,/g, '')) || 0);
                monthPresence[mc.index] = true;
                hasMonthValue = true;
            }
        }

        if (!hasMonthValue && descText === '') continue;
        if (isSummaryLabel(descText)) {
            sawSummary = true;
            // P2-e — keep labeled summary rows as reference candidates (values per
            // span) instead of dropping them outright; the curve never moves.
            const vals = {};
            let any = false;
            for (const mcc of header.monthCols) {
                if (monthVals[mcc.index] !== undefined && monthVals[mcc.index] > 0) {
                    vals[mcc.index] = monthVals[mcc.index];
                    any = true;
                }
            }
            if (any) summaryCandidates.push({ values: vals });
            continue;
        }
        // P2-c — continuation-page summary exclusion: a desc-less row on a page
        // whose prior pages showed an EXPLICIT summary, carrying small strictly
        // ascending values up to exactly 100 across every month span, is the
        // summary reprinted without labels — not an activity. Genuine percent
        // activities only trip this when a labeled summary already exists in the
        // same file, which is precisely when the summary reading wins.
        if (descText === '' && priorSummary) {
            const keys = header.monthCols.map((mcc) => mcc.index);
            const vs = keys.map((k) => monthVals[k]);
            if (vs.length >= 2 && vs.every((v) => v !== undefined)
                && vs.every((v) => v < 1000) && vs[vs.length - 1] === 100
                && vs.every((v, k) => k === 0 || v > vs[k - 1])) {
                sawSummary = true;
                continue;
            }
        }
        if (isTotalLabel(descText)) {
            let s = Object.values(monthVals).reduce((a, b) => a + b, 0);
            if (!(s > 0)) {
                // P2-d — off-column TOTAL fallback (mirrors the Excel rule): the
                // contract figure often sits left of the month spans (budgets do).
                // Largest numeric token wins; sub-1000 guards against item codes.
                let best = 0;
                for (const t of tokens) {
                    if (isNumericToken(t.str)) {
                        const v = parseNumeric(t.str) || 0;
                        if (v > best) best = v;
                    }
                }
                if (best >= 1000) s = best;
            }
            if (s > 0) totalRowSum = s;
            continue;
        }

        rows.push({
            description: descText || ('Activity ' + (rows.length + 1)),
            months: monthVals,
            presence: monthPresence,
            // P3 — the row's vertical position on its page. Column-split prints
            // repeat the same rows at the same y on every page, so mergePages
            // uses this to align pages instead of guessing by row order.
            y: tokenLines[i].y,
            // P3 — did this row carry a real name, or is "Activity N" a stand-in?
            // A nameless row on a column-overflow page with no counterpart at its
            // height is page furniture (a reprinted cumulative/total line), not an
            // activity — mergePages drops those instead of inventing a row.
            named: descText !== '',
        });
    }

    return { rows, totalRowSum, summaryCandidates, sawSummary };
}

/* ─── Page merging ────────────────────────────────────────────────────────── */

/**
 * P3 — pair a column-overflow page's rows with the already-merged rows by their
 * vertical position.
 *
 * A table whose COLUMNS overflow onto another page reprints the same rows at the
 * same y on both pages (proven on the SEGMENT specimen: header 595.90/595.90,
 * row 3 537.79/537.79, row 4 523.27/523.27). The tail page often prints FEWER
 * rows than the first — only those with values in its months — so pairing by row
 * ORDER attributes the wrong activity names to the late months.
 *
 * Refuses (returns null) whenever the evidence is not clean: missing y's, a tie
 * inside the tolerance, two incoming rows claiming one row, or nothing matching.
 * Callers then fall back to the positional merge and its warning.
 *
 * Called by: mergePages() for disjoint-month pages only.
 * @returns {Map<number, number>|null}  incoming row index → merged row index.
 */
function matchRowsByY(mergedRows, incomingRows) {
    if (mergedRows.length < 2 || !incomingRows.length) return null;
    const isY = (r) => r && typeof r.y === 'number' && isFinite(r.y);
    if (!mergedRows.every(isY) || !incomingRows.every(isY)) return null;

    // Tolerance = half the median gap between adjacent rows: tight enough that a
    // neighbouring row can never win, loose enough for print rounding drift.
    const sorted = mergedRows.map((r) => r.y).sort((a, b) => b - a);
    const gaps = [];
    for (let i = 1; i < sorted.length; i++) {
        const g = Math.abs(sorted[i - 1] - sorted[i]);
        if (g > 0) gaps.push(g);
    }
    if (!gaps.length) return null;
    gaps.sort((a, b) => a - b);
    const tolerance = gaps[Math.floor(gaps.length / 2)] / 2;

    const pairs = new Map();
    const claimed = new Set();
    for (let i = 0; i < incomingRows.length; i++) {
        let best = -1;
        let bestDist = Infinity;
        let runnerUp = Infinity;
        for (let j = 0; j < mergedRows.length; j++) {
            const d = Math.abs(mergedRows[j].y - incomingRows[i].y);
            if (d < bestDist) { runnerUp = bestDist; bestDist = d; best = j; }
            else if (d < runnerUp) { runnerUp = d; }
        }
        // No partner in range = a row this page adds; the caller appends it.
        if (bestDist > tolerance) continue;
        // Two candidates in range, or a row already spoken for: the pages do not
        // line up the way a column split does — refuse rather than guess.
        if (runnerUp <= tolerance || claimed.has(best)) return null;
        claimed.add(best);
        pairs.set(i, best);
    }
    return pairs.size > 0 ? pairs : null;
}

function mergePages(pageResults) {
    let mergedRows = [];
    let mergedMonths = [];
    let totalContract = null;
    const warnings = [];

    for (const pr of pageResults) {
        const pageMonths = pr.header.monthCols.map((mc) => mc.index);

        if (mergedMonths.length === 0) {
            mergedRows = pr.rows.map((r) => ({ ...r, months: { ...r.months }, presence: { ...(r.presence || {}) } }));
            mergedMonths = pageMonths.slice();
            totalContract = pr.totalRowSum;
            continue;
        }

        const overlap = pageMonths.some((m) => mergedMonths.indexOf(m) !== -1);
        if (overlap) {
            // Same months repeated (or overlapping) → continuation rows.
            mergedRows.push(...pr.rows.map((r) => ({ ...r, months: { ...r.months }, presence: { ...(r.presence || {}) } })));
        } else {
            // Disjoint months → the page holds the overflowing COLUMNS of the
            // same rows, so its values must land on the RIGHT rows.
            // P3 — align by row y when the pages agree on it (column splits
            // reprint rows at identical heights); only guess by position — and
            // warn — when they do not.
            const byY = matchRowsByY(mergedRows, pr.rows);
            const copyInto = (target, src) => {
                for (const key of Object.keys(src.months)) {
                    target.months[Number(key)] = src.months[Number(key)];
                }
                const srcPresence = src.presence || {};
                if (!target.presence) target.presence = {};
                for (const key of Object.keys(srcPresence)) {
                    target.presence[Number(key)] = srcPresence[key];
                }
            };

            if (byY) {
                let orphans = 0;
                for (let i = 0; i < pr.rows.length; i++) {
                    if (byY.has(i)) {
                        copyInto(mergedRows[byY.get(i)], pr.rows[i]);
                    } else if (pr.rows[i].named) {
                        // A NAMED row with no counterpart is genuinely new here.
                        mergedRows.push({ ...pr.rows[i], months: { ...pr.rows[i].months }, presence: { ...(pr.rows[i].presence || {}) } });
                    } else {
                        // Nameless AND unmatched: values that belong to no activity
                        // on the description page (usually a cumulative/total line
                        // reprinted on the overflow page). Attributing them would
                        // invent an activity, so they are left out — and said out
                        // loud, because leaving numbers out must never be silent.
                        orphans++;
                    }
                }
                if (orphans > 0) {
                    warnings.push('Heads-up: ' + orphans + ' unnamed value row'
                        + (orphans === 1 ? '' : 's') + ' on a continuation page matched no activity and '
                        + (orphans === 1 ? 'was' : 'were') + ' left out. Verify the monthly split before locking.');
                }
            } else {
                // P2-c — positional alignment is a guess: flag row-count drift so
                // a human verifies instead of silently misattributing months.
                if (pr.rows.length !== mergedRows.length) {
                    warnings.push('Heads-up: pages carry different row counts (' + mergedRows.length + ' vs ' + pr.rows.length
                        + ') — months were aligned by position. Verify the monthly split before locking.');
                }
                const n = Math.min(mergedRows.length, pr.rows.length);
                for (let i = 0; i < n; i++) {
                    copyInto(mergedRows[i], pr.rows[i]);
                }
                for (let i = n; i < pr.rows.length; i++) {
                    mergedRows.push({ ...pr.rows[i], months: { ...pr.rows[i].months }, presence: { ...(pr.rows[i].presence || {}) } });
                }
            }
        }

        for (const m of pageMonths) {
            if (mergedMonths.indexOf(m) === -1) mergedMonths.push(m);
        }
        if (totalContract === null && pr.totalRowSum) totalContract = pr.totalRowSum;
    }

    mergedMonths.sort((a, b) => a - b);
    return { rows: mergedRows, months: mergedMonths, totalContract, warnings };
}

/* ─── Cumulative months ───────────────────────────────────────────────────── */

export function buildMonthsFromRows(rows, monthIndices, totalContract, extra) {
    const opts = extra || {};
    const warnings = [];
    const totals = {};
    const activitiesByMonth = {};
    for (const idx of monthIndices) {
        totals[idx] = 0;
        activitiesByMonth[idx] = [];
    }

    for (const row of rows) {
        for (const idx of monthIndices) {
            const v = row.months[idx] || 0;
            if (v > 0) {
                totals[idx] += v;
                activitiesByMonth[idx].push(row.description);
                if (opts.debug) opts.debug.push({ t: 'contrib', month: idx, activity: row.description, value: v });
            } else if (row.presence && row.presence[idx] && activitiesByMonth[idx].indexOf(row.description) === -1) {
                // P1 — presence-only months (## overflow): the activity visibly
                // spans this month even though its value is unreadable. Listed
                // without moving the curve (totals untouched).
                activitiesByMonth[idx].push(row.description);
            }
        }
    }

    let contract = totalContract && totalContract > 0 ? totalContract : null;
    if (!contract || contract <= 0) {
        contract = monthIndices.reduce((s, idx) => s + totals[idx], 0);
    }
    if (!contract || contract <= 0) {
        throw new Error('No contract amount could be determined from the S-curve PDF.');
    }

    const months = [];
    let running = 0;
    let prev = 0;
    let lastNonZero = 0;
    for (const idx of monthIndices) {
        if (totals[idx] > 0) lastNonZero = idx;
    }

    for (const idx of monthIndices) {
        const pct = (totals[idx] / contract) * 100;
        running += pct;
        let cum = running;
        if (cum < prev) {
            warnings.push(`Month ${idx} cumulative % was clamped to keep the curve monotonic.`);
            cum = prev;
        }
        cum = Math.min(cum, 100);
        running = cum;
        prev = cum;

        const names = activitiesByMonth[idx];
        const desc = names.length ? names.join(', ') : '';
        months.push({
            month_index: idx,
            planned_progress_percent: Math.round(cum * 100) / 100,
            description: desc.length > 200 ? desc.slice(0, 200) + '…' : desc,
            activities: names.map((name) => ({ activity_name: name, description: null })),
        });
    }

    const finalMonth = lastNonZero > 0 ? lastNonZero : (monthIndices.length ? monthIndices[monthIndices.length - 1] : 0);
    const fm = months.find((m) => m.month_index === finalMonth);
    if (fm) {
        fm.planned_progress_percent = 100;
        running = 100;
    }

    // P2-e — author-block reference + warn (mirrors the Excel 2b redesign): the
    // plan curve above NEVER moves while activity math exists. Each summary value
    // sitting in a month span is compared against our cumulative at that month;
    // gaps past 1.0 warn with the cause named. Percent-shaped blocks (max ≤ 100)
    // compare directly; peso blocks scale through the contract. Weekly path has no
    // activity math to compare against and never reaches this branch.
    const summaryReference = [];
    const candidates = (opts && opts.summaryCandidates) || [];
    if (lastNonZero > 0 && candidates.length > 0) {
        const byMonth = {};
        months.forEach((m) => { byMonth[m.month_index] = m.planned_progress_percent; });
        candidates.forEach((cand) => {
            const keys = Object.keys(cand.values || {}).map(Number);
            const maxVal = keys.length ? Math.max(...keys.map((k) => cand.values[k])) : 0;
            const asPercent = maxVal <= 100;
            keys.forEach((m) => {
                if (byMonth[m] === undefined) return;
                const raw = cand.values[m];
                const summaryPct = Math.round((asPercent ? raw : (contract > 0 ? (raw / contract) * 100 : 0)) * 100) / 100;
                const gap = Math.round(Math.abs(summaryPct - byMonth[m]) * 100) / 100;
                summaryReference.push({ month: m, summary_percent: summaryPct, activity_percent: byMonth[m], gap });
                if (opts.debug) opts.debug.push({ t: 'ref', month: m, summary: summaryPct, activity: byMonth[m], gap });
                if (gap > 1.0) {
                    warnings.push(`Heads-up (Month ${m}): the file's own summary says ${summaryPct}%, but its activity rows add up to`
                        + ` ${byMonth[m]}%. The summary was likely computed against a different total. Your baseline below uses the activity math —`
                        + ' no action needed.');
                }
            });
        });
    }

    return {
        months,
        meta: {
            contractAmount: Math.round(contract * 100) / 100,
            weightSum: 0,
            monthCount: monthIndices.length,
            activityCount: rows.length,
            warnings,
            summaryReference,
        },
    };
}

/* ─── Weekly-schedule layout (WEEK N columns + monthly cumulative %) ──────── */

function pickMostTokensRow(tokenLines, pred) {
    let best = null;
    for (const line of tokenLines) {
        const matched = line.tokens.filter((t) => pred(t));
        if (matched.length >= 2 && (!best || matched.length > best.matched.length)) {
            best = { line, matched };
        }
    }
    return best;
}

// The cumulative % row is the row whose tokens are all %-formatted numbers in
// strict ascending order that tops out near 100. The incremental % row (also
// %-formatted) has equal/repeated values, so strict ascension filters it out.
function findCumulativeRow(tokenLines) {
    let best = null;
    for (const line of tokenLines) {
        const pcts = line.tokens.filter((t) => isNumericPct(t.str));
        if (pcts.length < 3) continue;
        const vals = pcts.map((t) => parseNumericPct(t.str));
        let increasing = true;
        for (let i = 1; i < vals.length; i++) {
            if (vals[i] <= vals[i - 1]) { increasing = false; break; }
        }
        if (!increasing) continue;
        if (Math.max(...vals) < 50) continue;
        if (!best || pcts.length > best.pcts.length) best = { pcts, vals };
    }
    return best;
}

function nearestPct(x, pctCols) {
    let best = null;
    let bestDist = Infinity;
    for (const pc of pctCols) {
        const d = Math.abs(pc.x - x);
        if (d < bestDist) { bestDist = d; best = pc; }
    }
    if (bestDist > 60) return null;
    return parseNumericPct(best.str);
}

// Some schedules lay the curve out by WEEK columns grouped under month-span
// labels (e.g. "DECEMBER" spanning weeks 1-5, "JANUARY" weeks 6-8). The month
// label x positions act as column boundaries; the month-end cumulative % is
// the cumulative-row value at the last week column inside each boundary span.
export function parseWeeklySchedulePdf(itemsByPage) {
    const months = [];
    const indexByName = {};

    for (const items of itemsByPage || []) {
        const tokenLines = linesToTokenLines(groupItemsIntoLines(items || []));

        const weekRow = pickMostTokensRow(tokenLines, (t) => /^WEEK\s*\d+$/i.test(t.str));
        if (!weekRow) continue;
        const monthRow = pickMostTokensRow(tokenLines, (t) => matchMonthLabel(t.str) !== null);
        if (!monthRow) continue;
        const cumRow = findCumulativeRow(tokenLines);
        if (!cumRow) continue;

        const weekCols = weekRow.matched
            .filter((t) => /^WEEK\s*\d+$/i.test(t.str))
            .sort((a, b) => a.x - b.x);
        const boundaries = monthRow.matched
            .sort((a, b) => a.x - b.x);
        const pctCols = cumRow.pcts.sort((a, b) => a.x - b.x);

        if (weekCols.length < 2 || boundaries.length < 2) continue;

        for (let k = 0; k < boundaries.length; k++) {
            const b = boundaries[k];
            const nextX = k + 1 < boundaries.length ? (boundaries[k + 1].x - 30) : Infinity;
            const curX = k > 0 ? (b.x - 30) : 0;
            const inMonth = weekCols.filter((w) => w.x >= curX && w.x < nextX);
            if (inMonth.length === 0) continue;
            const lastWeek = inMonth[inMonth.length - 1];
            const pct = nearestPct(lastWeek.x, pctCols);
            if (pct === null || pct <= 0) continue;

            const name = b.str.trim().toLowerCase();
            if (!(name in indexByName)) {
                indexByName[name] = months.length + 1;
                months.push({ name, pct });
            } else {
                months[indexByName[name] - 1].pct = pct;
            }
        }
    }

    if (months.length < 2) return null;

    let prev = 0;
    for (const m of months) {
        if (m.pct <= prev) return null;
        prev = m.pct;
    }
    months[months.length - 1].pct = 100;

    return {
        months: months.map((m, i) => ({
            month_index: i + 1,
            planned_progress_percent: Math.round(m.pct * 100) / 100,
            description: m.name.charAt(0).toUpperCase() + m.name.slice(1),
            activities: [],
        })),
        meta: {
            contractAmount: 0,
            weightSum: 0,
            monthCount: months.length,
            activityCount: 0,
            warnings: [],
        },
    };
}

/* ─── Orchestration ───────────────────────────────────────────────────────── */

/**
 * Parse an S-curve PDF from its per-page pdf.js text items.
 *
 * @param {Array<Array<object>>} itemsByPage  textContent.items per page.
 * @param {Object} [opts]  {debug: Array, monthWords: Array<string>} — learned
 *          month-header words (F-D, lowest priority; omitted = today exactly).
 * @returns {{months: Array, meta: Object}|null}  Same shape as the Excel
 *          importer, or null when no S-curve table layout is recognised.
 */
export function parseSCurvePdf(itemsByPage, opts) {
    const options = opts || {};
    const pageResults = [];
    let priorSummary = false;
    let priorHeader = null;

    for (let p = 0; p < itemsByPage.length; p++) {
        const lines = groupItemsIntoLines(itemsByPage[p] || []);
        const tokenLines = linesToTokenLines(lines);
        const pageMaxX = Math.max(0, ...tokenLines.flatMap((l) => l.tokens.map((t) => t.xEnd)));

        let header = null;
        let headerIndex = -1;
        for (let i = 0; i < tokenLines.length; i++) {
            const next = tokenLines[i + 1] ? tokenLines[i + 1].tokens : null;
            const h = detectHeaderColumns(tokenLines[i].tokens, next, { pageMaxX, lineIndex: i, continuation: priorHeader !== null, monthWords: options.monthWords || null });
            if (h) { header = h; headerIndex = i; break; }
        }

        if (!header) continue;
        if (!priorHeader) priorHeader = header;
        if (options.debug) options.debug.push({ t: 'header', page: p, months: header.monthCols.map((mc) => mc.index), descX: header.descX });
        // P2-c — continuation pages inherit the summary context (not the geometry):
        // a desc-less page following an explicit summary gets the stricter reading.
        const parsed = buildRows(tokenLines, header, headerIndex + 1, { priorSummary, debug: options.debug });
        if (parsed.sawSummary) priorSummary = true;
        pageResults.push({ header, rows: parsed.rows, totalRowSum: parsed.totalRowSum, summaryCandidates: parsed.summaryCandidates });
    }

    if (pageResults.length > 0) {
        const merged = mergePages(pageResults);
        if (merged.rows.length > 0 && merged.months.length > 0) {
            // Summary candidates survive only single-page reads for now: positional
            // merging across different x-grids cannot attribute reference values.
            const summaryCandidates = pageResults.length === 1 ? pageResults[0].summaryCandidates : [];
            const out = buildMonthsFromRows(merged.rows, merged.months, merged.totalContract, { summaryCandidates, debug: options.debug });
            (merged.warnings || []).forEach((w) => out.meta.warnings.unshift(w));
            return out;
        }
    }

    return parseWeeklySchedulePdf(itemsByPage);
}

/**
 * Weak-read check (F-C): does this parsed PDF curve deserve an AI-assist offer?
 *
 * Pure and side-effect free. Weak when months are short (<2), when half or
 * more of the listed names are generic fallbacks ("Activity N"), or when the
 * author's own summary gaps past 1.0. Empty parses are failures, not weak —
 * callers handle those in their catch branches.
 *
 * @param {{months: Array, meta: Object}|null} parsed  parseSCurvePdf output.
 * @returns {{weak: boolean, reasons: Array<string>}}
 */
export function isWeakPdfCurve(parsed) {
    const reasons = [];
    if (!parsed || !Array.isArray(parsed.months) || parsed.months.length === 0) {
        return { weak: false, reasons };
    }
    if (parsed.months.length < 2) reasons.push('months-short');
    const names = [];
    parsed.months.forEach((m) => {
        ((m && m.activities) || []).forEach((a) => {
            const n = a && typeof a === 'object' ? a.activity_name : a;
            if (typeof n === 'string' && n.trim() !== '') names.push(n.trim());
        });
    });
    // Nameless curves (weekly path emits []) are not weak by names — only
    // generic-fallback floods are. Weekly successes must never offer AI.
    if (names.length > 0) {
        const generic = names.filter((n) => /^Activity \d+$/i.test(n)).length;
        if (generic / names.length >= 0.5) reasons.push('generic-names');
    }
    const refs = (parsed.meta && parsed.meta.summaryReference) || [];
    if (refs.some((r) => r && typeof r.gap === 'number' && r.gap > 1.0)) {
        reasons.push('summary-gap');
    }
    return { weak: reasons.length > 0, reasons };
}

/* ─── E1-PDF capture: derive a layout candidate from an unread PDF ────────── */

export const PDF_MAP_VERSION = 1;

// Cells are stored as CSV so the desk and teachColumns can parse them with the
// very same reader the Excel slice uses (str_getcsv / schSplitCsv).
function csvCell(value) {
    const s = String(value == null ? '' : value).trim();

    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function columnLetter(i) {
    let n = i;
    let out = '';
    do {
        out = String.fromCharCode(65 + (n % 26)) + out;
        n = Math.floor(n / 26) - 1;
    } while (n >= 0);

    return out;
}

/**
 * E1-PDF / PDF-1 — propose a layout for a PDF the reader could not parse.
 *
 * Mirrors the Excel capture deal: when the rules find nothing, look again with
 * the wording loosened, and offer what you see for a human to approve. Nothing
 * here can reach a baseline on its own.
 *
 * Returns { map, block } or null:
 *  - map   stores FRACTIONS of the page width (never points), so the same layout
 *          still matches at a different page size or DPI, plus a pseudo-column
 *          legend so the desk's existing teach grid can address it by letter.
 *  - block is the byte-compatible twin of serializeForAi's output
 *          ("COLUMNS: A,B,… | …" then "R<n> [ROLE]: csv"), which is what lets the
 *          review desk render and teach a PDF row with no new UI.
 *
 * Pure and read-only: callers post it, they do not act on it.
 */
export function derivePdfLayout(itemsByPage, maxRows = 15) {
    const pages = Array.isArray(itemsByPage) ? itemsByPage : [];
    const found = [];
    let block = null;

    for (let p = 0; p < Math.min(pages.length, 20); p++) {
        const tokenLines = linesToTokenLines(groupItemsIntoLines(pages[p] || []));
        const pageMaxX = Math.max(0, ...tokenLines.flatMap((l) => l.tokens.map((t) => t.xEnd)));
        if (!(pageMaxX > 0)) continue;

        for (let i = 0; i < Math.min(tokenLines.length, 8); i++) {
            const header = detectHeaderColumns(tokenLines[i].tokens, null, {
                pageMaxX,
                lineIndex: i,
                relaxed: true,
                continuation: found.length > 0,
            });
            if (!header) continue;

            // A grid is only a schedule if numbers actually live under it. Without
            // this, a repeated word anywhere on a page could propose a layout and
            // invent months out of nothing — the relaxed detector is permissive by
            // design, so this is where that permissiveness is paid for.
            if (!bandsHoldNumbers(tokenLines, i, header.monthCols)) continue;

            // Pseudo-columns: the header's own printed tokens, in reading order.
            // Each owns the strip from its x to the next one's — the same banding
            // the month columns use, so a taught letter converts back to a band.
            const anchors = tokenLines[i].tokens
                .filter((t) => String(t.str || '').trim() !== '')
                .sort((a, b) => a.x - b.x);
            if (anchors.length < 2) continue;
            const pseudoCols = anchors.map((t, k) => ({
                letter: columnLetter(k),
                x: t.x / pageMaxX,
                xEnd: (anchors[k + 1] ? anchors[k + 1].x : pageMaxX + 1) / pageMaxX,
            }));

            const sig = anchors
                .map((t) => String(t.str || '').trim().toLowerCase())
                .filter((s) => s !== '');

            found.push({
                pageHint: p,
                lineHint: i,
                sig,
                indexed: relaxedMonthColumns([...tokenLines[i].tokens].sort((a, b) => a.x - b.x)).length >= 2,
                descX: header.descX / pageMaxX,
                descRight: header.descRight / pageMaxX,
                monthCols: header.monthCols.map((mc) => ({
                    index: mc.index,
                    x: mc.x / pageMaxX,
                    xEnd: mc.xEnd / pageMaxX,
                })),
                pseudoCols,
                headerLine: anchors.map((t) => String(t.str || '').trim()).join(' '),
            });

            // The desk shows the FIRST table page — where the names and labels are.
            if (block === null) {
                block = buildPdfSliceBlock(tokenLines, i, anchors, header.monthCols, maxRows);
            }
            break;
        }
    }

    if (found.length === 0) return null;

    // Column-split tables are the norm here, not the exception: the roof spreads
    // 7 months over 2 pages and SEGMENT spreads 5. A one-page map would teach a
    // short schedule, so every page that yielded a header rides in the map.
    //
    // Numbering is only trustworthy when the LABELS carry it. Indexed wording
    // ("SEGMENT 3,4,5") is self-describing, so a split merges unambiguously.
    // Positional wording restarts at 1 on every page and the roof's page 2 even
    // REPEATS its last column — nothing in the bytes says which month that is.
    // Rather than guess, such a capture is offered with NO map: the row still
    // reaches the desk, and a reviewer supplies the numbering (the same
    // null-map-then-teach path Excel captures already use).
    const ambiguous = found.length > 1 && found.some((f) => !f.indexed);

    return {
        map: ambiguous ? null : {
            v: PDF_MAP_VERSION,
            kind: 'pdf',
            pages: found.map(({ indexed, ...page }) => page),
        },
        block,
        ambiguous,
    };
}

/**
 * Do the proposed month bands actually hold values in the rows below?
 *
 * The PDF twin of the Excel mapSpansHoldNumbers gate: proof against real tokens
 * that this grid carries a schedule. Requires at least two DIFFERENT bands with
 * a numeric or percent value (percent matters — the roof family's cells are
 * "16%", not pesos), so one stray figure cannot vouch for a whole layout.
 *
 * Called by: derivePdfLayout().
 */
function bandsHoldNumbers(tokenLines, headerIndex, monthCols) {
    const filled = {};
    for (let i = headerIndex + 1; i < Math.min(tokenLines.length, headerIndex + 16); i++) {
        for (const t of tokenLines[i].tokens) {
            const s = String(t.str || '').trim();
            if (s === '' || !(isNumericToken(s) || isNumericPct(s) || /^\d[\d,]*(\.\d+)?\s*%$/.test(s))) {
                continue;
            }
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            const centre = (t.x + xEnd) / 2;
            for (const mc of monthCols) {
                if (centre >= mc.x && centre < mc.xEnd) { filled[mc.index] = true; break; }
            }
        }
        if (Object.keys(filled).length >= 2) return true;
    }

    return Object.keys(filled).length >= 2;
}

/**
 * Serialize the table's neighbourhood in the Excel slice's exact shape.
 *
 * Roles follow the same meaning the AI slice uses: lines above the header are
 * META, the header line is HEADER, the rest are DATA, and an author's summary
 * line is SUMMARY. Tokens are assigned to the pseudo-column whose band holds
 * their CENTRE — the same single-assignment rule buildRows uses, so a value
 * straddling a boundary cannot land in two cells.
 *
 * Called by: derivePdfLayout().
 */
function buildPdfSliceBlock(tokenLines, headerIndex, anchors, monthCols, maxRows) {
    const letters = anchors.map((t, k) => columnLetter(k));
    const bands = anchors.map((t, k) => ({
        x: t.x,
        xEnd: anchors[k + 1] ? anchors[k + 1].x : Infinity,
    }));
    // Which pseudo-columns are VALUE columns (they overlap a month band)? Role
    // detection must ignore them: isSummaryLabel matches a bare "%", so judging a
    // row by its numeric cells tagged every percent-valued activity as SUMMARY.
    // Labels decide a row's role; values never do.
    const isValueBand = bands.map((b) => (monthCols || []).some(
        (mc) => b.x < mc.xEnd && mc.x < b.xEnd
    ));

    const lines = ['COLUMNS: ' + letters.join(',')
        + ' | META: title block | HEADER: column labels'
        + ' | DATA: activity rows — compute months from these ONLY'
        + ' | SUMMARY: author totals (CUMULATIVE %/COST/Cash Flow) — reference only, NEVER copy into months'];

    const start = Math.max(0, headerIndex - 2);
    let kept = 0;
    for (let i = start; i < tokenLines.length && kept < maxRows; i++) {
        const cells = new Array(letters.length).fill('');
        let any = false;
        for (const t of tokenLines[i].tokens) {
            const text = String(t.str || '').trim();
            if (text === '') continue;
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            const centre = (t.x + xEnd) / 2;
            let slot = -1;
            for (let k = 0; k < bands.length; k++) {
                if (centre >= bands[k].x && centre < bands[k].xEnd) { slot = k; break; }
            }
            if (slot === -1) slot = centre < bands[0].x ? 0 : bands.length - 1;
            cells[slot] = cells[slot] ? cells[slot] + ' ' + text : text;
            any = true;
        }
        if (!any) continue;

        let role = 'DATA';
        if (i === headerIndex) role = 'HEADER';
        else if (i < headerIndex) role = 'META';
        else if (cells.some((c, k) => !isValueBand[k] && (isSummaryLabel(c) || isTotalLabel(c)))) {
            role = 'SUMMARY';
        }

        kept++;
        lines.push('R' + kept + ' [' + role + ']: ' + cells.map(csvCell).join(','));
    }

    return lines.join('\n');
}

/* ─── Attach to window for the classic consumer script ───────────────────── */

if (typeof window !== 'undefined') {
    window.scurvePdfParserCore = {
        isNumericToken,
        parseNumeric,
        matchMonthLabel,
        isDescriptionLabel,
        isTotalLabel,
        isSummaryLabel,
        isNumericPct,
        parseNumericPct,
        groupItemsIntoLines,
        linesToTokenLines,
        parseSCurvePdf,
        parseWeeklySchedulePdf,
        isWeakPdfCurve,
        relaxedMonthLabel,
        derivePdfLayout,
        PDF_MAP_VERSION,
    };
}
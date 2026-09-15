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
    // P6 — how wide the columns of this header actually are, so the LAST one need
    // not run to the raw page edge. Every band but the last is bounded by the next
    // month's start; the last had nothing to its right and swallowed whatever the
    // print put there (on the roof, the S-curve chart). Measured across every
    // sample PDF, that open tail holds NO tokens, so bounding it changes no read —
    // it just closes the hole. It is defence in depth, not the roof's cure: on that
    // page the bound computes past the chart and clamps back to the edge anyway;
    // chartScaleTokens is what actually keeps the picture out of the table.
    const labelGaps = [];
    for (let i = 1; i < months.length; i++) labelGaps.push(months[i].x - months[i - 1].x);
    labelGaps.sort((a, b) => a - b);
    const typicalWidth = labelGaps.length ? labelGaps[Math.floor(labelGaps.length / 2)] : 0;
    months = months.map((m, i) => ({
        index: m.index,
        x: m.x,
        xEnd: months[i + 1]
            ? months[i + 1].x
            : (typicalWidth > 0 ? Math.min(m.x + typicalWidth, pageMaxX + 1) : (pageMaxX + 1)),
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

/**
 * P4 — collect every page's summary candidates into one set.
 *
 * Single-page reads behave exactly as before. For a column-split file, each page
 * numbered its months through its OWN header, so the indices are absolute and can
 * be unioned directly — this is the one place a multi-page merge needs no
 * positional guessing, which is why the old "single page only" rule was stricter
 * than it had to be.
 *
 * Printed candidates (found by shape) are kept apart from labelled ones so a file
 * carrying both does not interleave two different authors' rows. A month claimed
 * twice with conflicting values refuses the whole set: a disagreeing reference is
 * worse than none.
 *
 * Called by: parseSCurvePdf().
 */
function mergeSummaryCandidates(pageResults) {
    if (pageResults.length === 1) return pageResults[0].summaryCandidates || [];

    const groups = { printed: {}, labelled: {} };
    let conflict = false;
    for (const pr of pageResults) {
        for (const cand of (pr.summaryCandidates || [])) {
            const bucket = groups[cand.printed ? 'printed' : 'labelled'];
            for (const k of Object.keys(cand.values || {})) {
                if (bucket[k] !== undefined && bucket[k] !== cand.values[k]) { conflict = true; }
                bucket[k] = cand.values[k];
            }
        }
    }
    if (conflict) return [];

    const out = [];
    for (const kind of ['printed', 'labelled']) {
        if (Object.keys(groups[kind]).length > 0) {
            out.push({ values: groups[kind], printed: kind === 'printed' });
        }
    }
    return out;
}

/**
 * P4 — is this UNLABELLED row the author's printed cumulative curve?
 *
 * The roof family exposed why a label cannot be the handle. Its cumulative row
 * prints "Commulative" 4.6pt below its own values (Y_TOLERANCE is 2, so they are
 * different lines) and the only token left on the values' line — a bare "%" —
 * falls between the description limit and the first month band, so it reaches
 * neither. The row therefore arrives here nameless and, before this, was summed
 * as if it were an activity: that is where the roof's 403%-of-contract total
 * came from.
 *
 * So the row is identified by SHAPE, the same way findCumulativeRow does it for
 * the weekly path: percent-written cells, strictly ascending, topping out near
 * completion. Each guard earns its place on this file —
 *   - percent-shaped  rejects the peso TOTAL and Cash Flow rows, which ascend too
 *   - strictly ascending rejects the per-month row (6.32/17.99/17.99/… repeats)
 *   - max >= 50       rejects that same row a second way, and any small weight run
 *
 * A continuation page prints the curve's tail with no neighbours to compare
 * against (the roof's page 2 carries a lone "100.00%"), so ascension cannot be
 * tested there. A single value is trusted ONLY when it sits at the SAME HEIGHT as
 * a cumulative row already found on an earlier page — column-split prints share
 * row Y positions exactly, which is the property P3's matchRowsByY is built on.
 * Without that tie the roof's page 2 offered three rival tails (100%, 50%, 100%)
 * and the conflicting set had to be thrown away. One number on its own is never
 * evidence of a curve.
 *
 * Called by: buildRows(). Returns {monthIndex: percent} or null.
 */
function cumulativeRowValues(monthVals, monthPct, monthCols, y, priorCumulativeYs) {
    const present = [];
    for (const mc of monthCols) {
        const v = monthVals[mc.index];
        if (v === undefined) continue;
        // One peso cell is enough to prove this is not a printed percent curve.
        if (!monthPct[mc.index]) return null;
        present.push({ index: mc.index, value: v });
    }
    if (present.length === 0) return null;
    if (Math.max(...present.map((p) => p.value)) < 50) return null;

    const ys = priorCumulativeYs || [];
    if (ys.length > 0) {
        // Once the file has shown WHERE its cumulative row sits, every later page
        // must agree: column-split prints share row Y positions exactly, the
        // property P3's matchRowsByY is built on. Ascension cannot be re-tested on
        // an overflow page (it may carry one cell, and its values continue a run
        // that started elsewhere), so height is the only honest tie. Without it the
        // roof's page 2 offered three rival tails — 100%, then 0%/50%, then the
        // real 99.14%/100% — and the contradictory set had to be discarded whole.
        if (!Number.isFinite(y)) return null;
        if (!ys.some((py) => Math.abs(py - y) <= 2)) return null;
    } else {
        // The first cumulative row in the file has to earn it on shape alone.
        if (present.length < 2) return null;
        for (let i = 1; i < present.length; i++) {
            if (present[i].value <= present[i - 1].value) return null;
        }
    }

    const values = {};
    present.forEach((p) => { values[p.index] = p.value; });
    return values;
}

/**
 * P6 — find the S-curve CHART's own scale, so its numbers are never read as cells.
 *
 * An S-curve document prints a picture as well as a table, and the picture has a
 * scale down its side (100% / 75 / 50% / 25% / 0%). Those labels are ordinary text
 * items, so when one lands inside a month band the reader takes them for that
 * month's data. It bit the roof because the LAST band runs to the page edge
 * (finalizeHeaderBands) and the chart sits inside it — see §9 of
 * textfiles/document_parser_pdf_layout_learning_plan.md for the full measurement.
 *
 * THE TELL IS THAT A SCALE COUNTS DOWN. A month's column read down the page is
 * activity values in whatever order the activities sit, and cumulative figures
 * count up; nothing in these tables descends 100 -> 0 on a fixed x. Measured over
 * every sample PDF: 71 vertical numeric stacks, exactly ONE descends — the roof's
 * axis — and it also starts at 100, ends at 0, and is evenly spaced. All four
 * conditions are required (user decision 2026-09-13): the zero-false-positive
 * reading was measurable, and a clipped or 80/60/40/20 axis can be revisited on
 * evidence rather than guessed at now.
 *
 * BOTH TOKEN SHAPES COUNT. pdf.js splits "75%" into "75" and "%", so a
 * percent-only predicate sees four rungs and the spacing test then fails (the
 * 100->50 gap doubles). Bare numerics are accepted for that reason.
 *
 * Works on the page's own tokenLines — the same geometry the reader uses, so there
 * is one definition of where things are, the rule finalizeHeaderBands already
 * follows for the strict and relaxed detectors.
 *
 * Pure. Called by: buildRows(), once per page.
 * @returns {Set} the token objects belonging to a chart scale (empty when none).
 */
export function chartScaleTokens(tokenLines) {
    const PCT = /^\d[\d,]*(\.\d+)?\s*%$/;
    const BARE = /^\d[\d,]*(\.\d+)?$/;
    const CLUSTER_X = 3;      // same-column tolerance, in points
    const EVENNESS = 0.25;    // max allowed spread between rung gaps

    const points = [];
    for (const line of tokenLines || []) {
        for (const t of line.tokens || []) {
            const s = String(t.str || '').trim();
            if (!PCT.test(s) && !BARE.test(s)) continue;
            const v = parseFloat(s.replace(/[%,]/g, ''));
            if (!Number.isFinite(v)) continue;
            points.push({ value: v, x: t.x, y: line.y, token: t });
        }
    }

    // Group into vertical columns by x.
    points.sort((a, b) => a.x - b.x);
    const columns = [];
    for (const p of points) {
        const hit = columns.find((c) => Math.abs(c.x - p.x) <= CLUSTER_X);
        if (hit) hit.points.push(p);
        else columns.push({ x: p.x, points: [p] });
    }

    const scale = new Set();
    for (const col of columns) {
        if (col.points.length < 3) continue;
        const down = col.points.slice().sort((a, b) => b.y - a.y);   // top -> bottom
        // Strictly descending, from exactly 100 to exactly 0: a scale, not a column.
        if (!down.every((p, i) => i === 0 || p.value < down[i - 1].value)) continue;
        if (down[0].value !== 100 || down[down.length - 1].value !== 0) continue;
        // Evenly spaced — a drawn axis is; a run of table rows need not be.
        const gaps = [];
        for (let i = 1; i < down.length; i++) gaps.push(down[i - 1].y - down[i].y);
        const widest = Math.max(...gaps);
        const tightest = Math.min(...gaps);
        if (!(widest > 0) || (widest - tightest) / widest > EVENNESS) continue;
        down.forEach((p) => scale.add(p.token));
    }

    return scale;
}

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
    // P4 — heights of cumulative rows found on EARLIER pages, so a lone tail
    // value on a continuation page can be tied to the row it continues.
    const priorCumulativeYs = context.priorCumulativeYs || [];
    const cumulativeYs = [];
    // P6 — the chart's own scale, found once for the page. Its numbers sit in the
    // table's column space but belong to the picture, not the table.
    const scaleTokens = chartScaleTokens(tokenLines);

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
        // P4 — which cells were written as percentages. The author's printed
        // cumulative row is always percent-shaped, so this is what separates it
        // from a peso row carrying the same ascending shape.
        const monthPct = {};
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
            // P6 — a chart scale label is not a cell, however squarely it lands in
            // a band. Skipping it here (rather than repairing the damage later)
            // fixes both ways it hurt on the roof at once: the scale rung printed
            // on the SAME LINE as a real row no longer inflates that row's month,
            // and a rung on its own line no longer becomes a row that mergePages
            // can glue onto a neighbour by height.
            if (scaleTokens.has(t)) continue;
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
                monthPct[mc.index] = true;
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
        // P4 — the author's printed cumulative row, found by shape because this
        // file family gives it no reachable label (see cumulativeRowValues).
        // Captured as a reference candidate AND excluded from the activity rows:
        // leaving it in is what inflated the roof's totals to 403% of contract.
        // Labelled summaries never reach here — isSummaryLabel above claims them.
        if (descText === '') {
            const cumVals = cumulativeRowValues(monthVals, monthPct, header.monthCols,
                tokenLines[i].y, priorCumulativeYs.concat(cumulativeYs));
            if (cumVals) {
                sawSummary = true;
                cumulativeYs.push(tokenLines[i].y);
                summaryCandidates.push({ values: cumVals, y: tokenLines[i].y, printed: true });
                if (context.debug) context.debug.push({ t: 'printedCumulative', y: tokenLines[i].y, values: cumVals });
                continue;
            }
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
            pct: monthPct,
        });
    }

    return { rows, totalRowSum, summaryCandidates, sawSummary, cumulativeYs };
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
                // P4 — carry the percent-shape flags across the page seam too. An
                // overflow page has no description column, so its rows are nameless
                // and only survive by merging into their page-1 twin; without this
                // the merged activity looked as though it had no cells in the
                // continued months, and the roof's last two months listed nothing.
                const srcPct = src.pct || {};
                if (!target.pct) target.pct = {};
                for (const key of Object.keys(srcPct)) {
                    target.pct[Number(key)] = srcPct[key];
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

/**
 * P4 — turn a summary candidate into a curve, or refuse.
 *
 * Mirrors the Excel adoption at SCurveImportService.php:1606-1650. A candidate
 * only becomes a baseline if it reads like a cumulative curve on its own terms:
 * at least two months, non-decreasing, and finishing near completion. The
 * PRINTED candidate (found by row shape) is preferred over a labelled one,
 * because the labelled bucket is whatever isSummaryLabel happened to claim —
 * that predicate matches a bare "%" and the word "cost", so it is the looser of
 * the two.
 *
 * Percent-shaped blocks are taken as written; peso blocks scale through the
 * contract, the same rule the reference comparison uses.
 *
 * Called by: buildMonthsFromRows(). Returns {values, jumbled} or null.
 */
function adoptPrintedCurve(candidates, monthIndices, contract) {
    const ordered = (candidates || []).slice().sort((a, b) => (b.printed ? 1 : 0) - (a.printed ? 1 : 0));

    for (const cand of ordered) {
        const keys = Object.keys(cand.values || {}).map(Number)
            .filter((k) => monthIndices.indexOf(k) !== -1)
            .sort((a, b) => a - b);
        if (keys.length < 2) continue;

        const raw = keys.map((k) => cand.values[k]);
        const maxVal = Math.max(...raw);
        const asPercent = maxVal <= 100;
        const values = {};
        let jumbled = false;
        let prev = -1;
        for (const k of keys) {
            const v = asPercent
                ? cand.values[k]
                : (contract > 0 ? (cand.values[k] / contract) * 100 : 0);
            if (v < prev) jumbled = true;
            prev = v;
            values[k] = v;
        }

        // A curve that never approaches completion is not the author's summary —
        // more likely a per-month block or a stray percent column.
        if (Math.max(...Object.values(values)) < 50) continue;

        return { values, jumbled };
    }

    return null;
}

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

    let rawRunning = 0;
    for (const idx of monthIndices) {
        const pct = (totals[idx] / contract) * 100;
        rawRunning += pct;
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

    // ─── P4: adopt the author's printed curve when the activity math cannot be
    // trusted ────────────────────────────────────────────────────────────────
    //
    // Rule 1 (activity math wins) and rule 2 (summary = reference + warn) are
    // unchanged below. This is rule 3, the PDF twin of
    // SCurveImportService.php:1606, and it fires on two conditions only:
    //
    //   (a) there is no activity math at all (lastNonZero === 0) — Excel parity;
    //   (b) the activity values do not share the contract's denominator, proved
    //       by the RAW cumulative overshooting 100 by a wide margin.
    //
    // (b) exists because of the roof family, whose printed cells are each
    // activity's own TIME FRACTION over its own duration (=30/187, =23/115), not
    // a share of the contract. The workbook multiplies them by cost in hidden
    // columns AJ:AS; the PDF export keeps only the visible grid, so those columns
    // never reach the page. Summing what is left totals 403% of contract. A
    // well-formed weighted table lands on exactly 100, so 100 here is the
    // definitional endpoint rather than a tuned threshold.
    //
    // The test needs an INDEPENDENT contract figure. When no TOTAL row was found,
    // `contract` is the sum of the month totals, making the ratio 1.0 by
    // construction and the overshoot undetectable — so (b) is gated on
    // totalContract and only (a) applies to such files.
    const candidates = (opts && opts.summaryCandidates) || [];
    const hasIndependentContract = !!(totalContract && totalContract > 0);
    const denominatorMismatch = hasIndependentContract && rawRunning > 105;
    const activityMathUnusable = lastNonZero === 0 || denominatorMismatch;

    let curveSource = 'activities';
    if (activityMathUnusable) {
        const adopted = adoptPrintedCurve(candidates, monthIndices, contract);
        if (!adopted) {
            // Refuse rather than hand back a curve known to be wrong. The caller
            // treats this exactly like an unrecognised layout: the "upload the
            // .xlsx" notice plus the AI assist offer.
            return null;
        }
        curveSource = 'summary';
        // P4 — the activity LISTS have to be rebuilt too. They were assembled from
        // the same cells this branch has just declared unusable, so they carried
        // every row that happened to hold a number in a band: "Project Duration"
        // (a days row), the Cash Flow pesos, and the nameless leftovers that show
        // up as "Activity 11". Keep only rows that are NAMED and whose cell in
        // this month is written as a percentage — on a file of per-activity
        // fractions that is exactly the set of activities scheduled that month,
        // and it is the same set the AI reports.
        for (const m of months) {
            const names = [];
            for (const row of rows) {
                if (!row.named) continue;
                if (!row.pct || !row.pct[m.month_index]) continue;
                if (!(row.months[m.month_index] > 0)) continue;
                if (names.indexOf(row.description) === -1) names.push(row.description);
            }
            const joined = names.join(', ');
            m.description = joined.length > 200 ? joined.slice(0, 200) + '…' : joined;
            m.activities = names.map((name) => ({ activity_name: name, description: null }));
        }
        let run = 0;
        for (const m of months) {
            let v = adopted.values[m.month_index];
            if (v === undefined) v = run;
            if (v < run) v = run;
            v = Math.min(v, 100);
            run = v;
            m.planned_progress_percent = Math.round(v * 100) / 100;
        }
        const lastM = months[months.length - 1];
        if (lastM) lastM.planned_progress_percent = 100;

        warnings.push(denominatorMismatch
            ? 'Heads-up: this file’s monthly cells are percentages of each activity’s own'
              + ' duration, not shares of the contract, so they cannot be added up. The baseline'
              + ' below is built from the file’s own cumulative row instead. Please verify'
              + ' carefully before locking.'
            : 'Heads-up: no usable activity values were found, so the baseline below is built'
              + ' from the file’s own cumulative row. Please verify carefully before locking.');
        if (adopted.jumbled) {
            warnings.push('Heads-up: the summary values were not in cumulative order — review carefully.');
        }
        // Comparing the adopted curve against the activity math it replaced would
        // be comparing it against itself, so the reference is reported without gaps.
        const ref = Object.keys(adopted.values).map(Number).sort((a, b) => a - b).map((m) => ({
            month: m,
            summary_percent: Math.round(adopted.values[m] * 100) / 100,
            activity_percent: null,
            gap: null,
        }));
        return {
            months,
            meta: {
                contractAmount: Math.round(contract * 100) / 100,
                weightSum: 0,
                monthCount: monthIndices.length,
                activityCount: rows.length,
                warnings,
                summaryReference: ref,
                curveSource,
            },
        };
    }

    // P2-e — author-block reference + warn (mirrors the Excel 2b redesign): the
    // plan curve above NEVER moves while activity math exists. Each summary value
    // sitting in a month span is compared against our cumulative at that month;
    // gaps past 1.0 warn with the cause named. Percent-shaped blocks (max ≤ 100)
    // compare directly; peso blocks scale through the contract. Weekly path has no
    // activity math to compare against and never reaches this branch.
    const summaryReference = [];
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
            curveSource,
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
/**
 * PDF-2 — can a learned layout's page be recognised on THIS page?
 *
 * Signature matching mirrors the Excel consult: at least two remembered header
 * cells must be found (exactly, or within the same typo tolerance the reader
 * already uses), and at least half of them. Bands are then rehydrated by
 * multiplying the stored FRACTIONS by this page's width, which is why a map
 * still fits a differently sized print of the same layout.
 *
 * bandsHoldNumbers has the final say: a signature can match on wording alone,
 * but a layout only applies if numbers actually sit under its month columns.
 *
 * Called by: collectPdfPages() on the map pass.
 */
function matchMapPage(tokenLines, pageMaxX, mapPage, allowSingleMonth) {
    const sig = (mapPage.sig || [])
        .map((s) => String(s || '').trim().toLowerCase())
        .filter((s) => s !== '');
    if (sig.length < 2) return null;

    const monthCols = (mapPage.monthCols || []).map((mc) => ({
        index: mc.index,
        x: mc.x * pageMaxX,
        xEnd: mc.xEnd * pageMaxX,
    }));
    // P4 — "two months or it is not a schedule" is right for a map that owns the
    // whole table, and wrong for a CONTINUATION page: the roof's overflow page
    // carries exactly one column ("7 days"), and rejecting it here is what kept
    // month 7 unreadable. A lone month is admitted only when the map has other
    // pages to be a continuation OF; a single-page map still needs two.
    if (monthCols.length < 1) return null;
    if (monthCols.length < 2 && !allowSingleMonth) return null;

    for (let i = 0; i < Math.min(tokenLines.length, 12); i++) {
        const words = tokenLines[i].tokens
            .map((t) => String(t.str || '').trim().toLowerCase())
            .filter((s) => s !== '');
        if (words.length < 2) continue;

        let hits = 0;
        for (const s of sig) {
            if (words.some((w) => w === s || headerFuzzyMatch(w, [s]))) hits++;
        }
        if (hits < 2 || hits < Math.ceil(sig.length / 2)) continue;
        // The stored bands must agree with THIS page's own month labels
        // (see bandsHoldLabels). Wording alone cannot tell two prints of the same
        // family apart when every label reads "30 days".
        if (!bandsHoldLabels(tokenLines, i, monthCols)) continue;
        // P4 — arity-aware, for the same reason the derivation had to become so:
        // a column-overflow page can carry a SINGLE month (the roof's "7 days"
        // tail), and demanding two filled bands is a test it can never pass. The
        // guard still refuses a page whose one band holds nothing.
        if (!bandsHoldNumbers(tokenLines, i, monthCols, Math.min(2, monthCols.length))) continue;

        return {
            header: {
                descX: (mapPage.descX || 0) * pageMaxX,
                descRight: (mapPage.descRight || 0) * pageMaxX,
                monthCols,
            },
            headerIndex: i,
        };
    }

    return null;
}

/**
 * Does every stored month band sit over one of this page's own column labels?
 *
 * 2026-09-14 (user-reported, roof PDF). A single-page roof layout from an earlier
 * build (DB rows 114-116) stored SEVEN equal bands across page one, while the
 * real print carries SIX "30 days" labels there (months 1-5 plus an empty stub;
 * months 6-7 print on page two). The signature matched — every label is
 * "30 days" — and numbers sat under enough bands, so the layout applied and read
 * 6.32 / 24.31 / 42.30 / 42.30 / 59.71 / 77.03 / 100: month 4 repeated, 99.14
 * lost, July and January with no activities. Measured: its month-4 band
 * (0.652-0.744 of the page width) holds no label at all, and every later band
 * reads its neighbour's column.
 *
 * A band built from a header (derived, AI-derived or taught from the grid)
 * always covers its own label, so asking for one is cheap for a layout that fits
 * and decisive for one that does not. The reviewer's taught roof layout passes:
 * each of its bands holds exactly its own label, and the stub column it left out
 * is simply unclaimed — so the rule is "every band has a label", never "every
 * label has a band".
 *
 * A label is a token carrying a LETTER on the header line or the line directly
 * below it (a split "MONTH" / "1" header). Numeric tokens never count: under the
 * header they are data, and data under a band is bandsHoldNumbers' question.
 * Lines ABOVE the header are not consulted — the roof prints "187 days" there,
 * and it would rescue exactly the misfit band this exists to catch.
 *
 * Called by: matchMapPage().
 */
function bandsHoldLabels(tokenLines, headerIndex, monthCols) {
    const labelCentres = [];
    [headerIndex, headerIndex + 1].forEach((li) => {
        const line = tokenLines[li];
        if (!line) return;
        for (const t of line.tokens) {
            const text = String(t.str || '').trim();
            if (!/[A-Za-z]/.test(text)) continue;
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            labelCentres.push((t.x + xEnd) / 2);
        }
    });
    return monthCols.every((mc) => labelCentres.some((c) => c >= mc.x && c < mc.xEnd));
}

/**
 * Is this stored map safe to consult at all?
 *
 * Version, kind and a non-empty page list are gated here. (This comment used to
 * claim a SHAPE check that would ignore single-page maps of a column-split table;
 * the code never did that. What actually refuses a map whose geometry does not fit
 * the page is bandsHoldLabels, per page, in matchMapPage.)
 */
function usableLayoutMap(map) {
    return !!map
        && map.v === PDF_MAP_VERSION
        && map.kind === 'pdf'
        && Array.isArray(map.pages)
        && map.pages.length > 0;
}

/* ─── Read guard: refuse a reading the document itself contradicts ────────── */
//
// 2026-09-15 (teach-grid plan §13, findings C + D, guard measurement pass). Two
// printed BUHI schedules read WRONG with no learned layout involved, and looked
// like any other baseline preview. The checks below were chosen by measurement
// over 11 PDFs × up to 5 reads each (rules, taught month word, the live database
// layouts, capture maps, AI maps): each fires only on the specimen it exists for,
// and on no correct read. They guard those two measured failure shapes; they are
// not a general proof that a reading is right.

// Left-block column labels (never month words). A line carrying three or more
// distinct kinds of these is a table's label line.
const LEFT_BLOCK_LABEL_RE = /^(item(\s*no\.?)?|no\.|description|descritpion|particulars|scheduled\s*tasks|amount|contract(\s*amount)?|unit(\s*cost)?|qty\.?|quantity|%\s*weight|weight|no\.\s*of|days|budget(\s*cost)?|3%\s*discount)\b/i;

// The most distinct left-block label kinds found on any single line of a page.
function maxLeftBlockLabelKinds(tokenLines) {
    let best = 0;
    for (const line of tokenLines) {
        const kinds = new Set();
        for (const t of line.tokens) {
            const s = String(t.str || '').trim();
            if (LEFT_BLOCK_LABEL_RE.test(s)) kinds.add(s.toLowerCase().slice(0, 6));
        }
        if (kinds.size > best) best = kinds.size;
    }
    return best;
}

// A cell's value as the guard reads it: a plain number or a percent-written one.
function guardCellValue(str) {
    const s = String(str || '').trim();
    if (isNumericToken(s)) return parseNumeric(s);
    if (/^\d[\d,]*(\.\d+)?\s*%$/.test(s)) return parseFloat(s.replace(/[%,]/g, ''));
    return null;
}

// Money-style figure for a refusal message ("32,588,531.43").
function guardFigure(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Does the read's own TOTAL row add up?
 *
 * The TOTAL row is found on a month page by a TOTAL label left of the first month
 * band. Its month cells are summed with the reader's own band assignment (token
 * centre, last band inclusive). On a COLUMN-SPLIT print the row continues on the
 * pages holding the other months, on a label-less line at the same height below
 * that page's own header (the P3 property) — so those cells are added too. Judging
 * page by page was measured to FALSELY flag the correct two-page roof read.
 *
 * Returns null when there is nothing to judge (no TOTAL row, no month cells in it,
 * or no printed figure left of the months — the 10-month, 6-stage and SEGMENT
 * prints), else { match, monthSum, printed, nearest }.
 *
 * Called by: readRefusal(). Pure.
 */
function totalRowReconciliation(pageResults) {
    const centre = (t) => (t.x + ((t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x)) / 2;
    const inBands = (bands, cx) => bands.some((b, k) =>
        cx >= b.x && (k === bands.length - 1 ? cx <= b.xEnd : cx < b.xEnd));

    let anchor = null;
    for (const pr of pageResults) {
        if (!pr.tokenLines || pr.headerIndex < 0) continue;
        const first = pr.header.monthCols[0].x;
        for (let i = pr.headerIndex + 1; i < pr.tokenLines.length; i++) {
            if (pr.tokenLines[i].tokens.some((t) => isTotalLabel(t.str) && centre(t) < first)) {
                anchor = { pr, line: pr.tokenLines[i] };
                break;
            }
        }
        if (anchor) break;
    }
    if (!anchor) return null;

    const anchorMonths = anchor.pr.header.monthCols.map((mc) => mc.index);
    const offset = anchor.line.y - anchor.pr.tokenLines[anchor.pr.headerIndex].y;
    let monthSum = 0;
    let monthCells = 0;
    const printed = [];

    for (const pr of pageResults) {
        if (!pr.tokenLines || pr.headerIndex < 0) continue;
        let line = null;
        if (pr === anchor.pr) {
            line = anchor.line;
        } else if (pr.header.monthCols.every((mc) => anchorMonths.indexOf(mc.index) === -1)) {
            // Only a page holding OTHER months continues the row; a page repeating
            // the same months is a row continuation, and its line at this height is
            // some other activity.
            const headerY = pr.tokenLines[pr.headerIndex].y;
            line = pr.tokenLines.find((l, li) => li > pr.headerIndex && Math.abs((l.y - headerY) - offset) <= 2.5) || null;
        }
        if (!line) continue;
        const bands = pr.header.monthCols;
        for (const t of line.tokens) {
            const v = guardCellValue(t.str);
            if (v === null) continue;
            const cx = centre(t);
            if (inBands(bands, cx)) {
                monthSum += v;
                monthCells++;
            } else if (pr === anchor.pr && cx < bands[0].x) {
                printed.push(v);
            }
        }
    }

    if (monthCells === 0 || printed.length === 0) return null;
    const tolerance = Math.max(1, monthSum * 0.001);
    const nearest = printed.reduce((a, b) => (Math.abs(b - monthSum) < Math.abs(a - monthSum) ? b : a));
    return { match: Math.abs(nearest - monthSum) <= tolerance, monthSum, printed, nearest };
}

/**
 * Should this reading be refused? Returns null (keep it) or
 * { code, message, detail } — the message is reviewer-facing.
 *
 * 'split-down'     a page carries the table's left-block label line but no month
 *                  header, AND every activity came out nameless. Both facts are
 *                  required: either alone is plausible on a legitimate print. The
 *                  12-page BUHI print (names on p1-p3, months on p4-p12) is the
 *                  specimen.
 * 'total-mismatch' the TOTAL row's month cells do not add up to any figure the row
 *                  prints left of the months. The one-page BUHI print (a month cell
 *                  left of every band) is the specimen: 32,346,214 read against
 *                  32,588,531.43 printed.
 *
 * Applies to learned layouts as much as to the rules, so an approved layout that
 * still does not add up is refused too — the file's own TOTAL row is the arbiter.
 *
 * Called by: readPageResults(). Pure.
 */
function readRefusal(pageResults, labelOnlyPages, out) {
    const names = [];
    (out.months || []).forEach((m) => {
        ((m && m.activities) || []).forEach((a) => {
            const n = a && typeof a === 'object' ? a.activity_name : a;
            if (typeof n === 'string' && n.trim() !== '') names.push(n.trim());
        });
    });
    const allNameless = names.length > 0 && names.every((n) => /^Activity \d+$/.test(n));
    if (labelOnlyPages.length > 0 && allNameless) {
        return {
            code: 'split-down',
            message: 'This PDF prints the activity names and the monthly columns on separate pages,'
                + ' so the offline reader cannot tell which figures belong to which activity.',
            detail: { labelOnlyPages: labelOnlyPages.map((p) => p + 1) },
        };
    }

    const total = totalRowReconciliation(pageResults);
    if (total && !total.match) {
        return {
            code: 'total-mismatch',
            message: 'The monthly figures read from this PDF add up to ' + guardFigure(total.monthSum)
                + ', but its own TOTAL row prints ' + guardFigure(total.nearest)
                + ' — some cells were not read into the right month.',
            detail: { monthSum: total.monthSum, printed: total.printed },
        };
    }
    return null;
}

/**
 * Walk the pages, collecting one table read per page.
 *
 * liveMaps === null is the RULES pass (what the reader has always done). With
 * maps, the same walk runs against remembered layouts instead — the rows, the
 * merge and the curve are built by exactly the same code either way, so a
 * learned layout can only change WHERE the reader looks, never how it counts.
 */
function collectPdfPages(itemsByPage, options, liveMaps) {
    const pageResults = [];
    let priorSummary = false;
    let priorCumulativeYs = [];
    let priorHeader = null;
    let mapId = null;
    const usedMapPages = new Set();
    // Read guard ('split-down'): pages that carry the table's left-block label line
    // but no month header. Recorded here because only this walk sees every page.
    const labelOnlyPages = [];

    for (let p = 0; p < itemsByPage.length; p++) {
        const lines = groupItemsIntoLines(itemsByPage[p] || []);
        const tokenLines = linesToTokenLines(lines);
        const pageMaxX = Math.max(0, ...tokenLines.flatMap((l) => l.tokens.map((t) => t.xEnd)));

        let header = null;
        let headerIndex = -1;

        if (!liveMaps) {
            for (let i = 0; i < tokenLines.length; i++) {
                const next = tokenLines[i + 1] ? tokenLines[i + 1].tokens : null;
                const h = detectHeaderColumns(tokenLines[i].tokens, next, { pageMaxX, lineIndex: i, continuation: priorHeader !== null, monthWords: options.monthWords || null });
                if (h) { header = h; headerIndex = i; break; }
            }
        } else if (pageMaxX > 0) {
            // One map owns a whole file: once a map has spoken for this file, only
            // its other pages are tried, so two layouts can never interleave.
            for (const entry of liveMaps) {
                if (mapId !== null && entry.id !== mapId) continue;
                if (!usableLayoutMap(entry.map)) continue;
                for (let mp = 0; mp < entry.map.pages.length; mp++) {
                    // P4 — a stored page describes ONE physical page, so it may be
                    // consumed once. Without this the roof's page-0 entry (six
                    // "30 days") also matched the overflow page, whose only new
                    // column is the "7 days" tail — month 7 was never read and the
                    // overflow page was re-read as months 1-6, manufacturing a
                    // summary row that contradicted the real one. Same spirit as
                    // "one map owns a file", one level down.
                    if (usedMapPages.has(entry.id + ':' + mp)) continue;
                    const hit = matchMapPage(tokenLines, pageMaxX, entry.map.pages[mp], entry.map.pages.length > 1);
                    if (!hit) continue;
                    header = hit.header;
                    headerIndex = hit.headerIndex;
                    mapId = entry.id;
                    usedMapPages.add(entry.id + ':' + mp);
                    break;
                }
                if (header) break;
            }
        }

        if (!header) {
            if (maxLeftBlockLabelKinds(tokenLines) >= 3) labelOnlyPages.push(p);
            continue;
        }
        if (!priorHeader) priorHeader = header;
        if (options.debug) options.debug.push({ t: 'header', page: p, months: header.monthCols.map((mc) => mc.index), descX: header.descX, viaMap: !!liveMaps });
        // P2-c — continuation pages inherit the summary context (not the geometry):
        // a desc-less page following an explicit summary gets the stricter reading.
        const parsed = buildRows(tokenLines, header, headerIndex + 1, { priorSummary, priorCumulativeYs, debug: options.debug });
        if (parsed.sawSummary) priorSummary = true;
        if (parsed.cumulativeYs && parsed.cumulativeYs.length) {
            priorCumulativeYs = priorCumulativeYs.concat(parsed.cumulativeYs);
        }
        // tokenLines + headerIndex ride along for the read guard's TOTAL-row check;
        // mergePages and the month builder never read them.
        pageResults.push({ header, rows: parsed.rows, totalRowSum: parsed.totalRowSum, summaryCandidates: parsed.summaryCandidates, tokenLines, headerIndex });
    }

    return { pageResults, mapId, labelOnlyPages };
}

/**
 * Turn one pass's page reads into a curve — or refuse it.
 *
 * Returns exactly one of:
 *   { out }       a reading to return as-is;
 *   { refusal }   a reading the document contradicts (readRefusal);
 *   { fallback }  nothing buildable here (no rows/months, or buildMonthsFromRows
 *                 refused) — the caller moves on to the weekly reader, exactly as
 *                 before the guard existed.
 *
 * Called by: parseSCurvePdf(), once for the rules pass and once for the maps pass.
 */
function readPageResults(pass, mapId, options) {
    const pageResults = pass.pageResults;
    const merged = mergePages(pageResults);
    if (!(merged.rows.length > 0 && merged.months.length > 0)) return { fallback: true };

    // P4 — candidates now survive a MULTI-PAGE read. The old rule dropped
    // them outright, which is why a column-split file reported no reference
    // at all (the roof: summaryReference []). Each page numbers its own
    // months through its own header, so a candidate's month indices are
    // already absolute — they are unioned, never positionally guessed.
    // A month claimed twice with DIFFERENT values is a genuine conflict and
    // the whole candidate set is refused rather than half-trusted.
    const summaryCandidates = mergeSummaryCandidates(pageResults);
    const out = buildMonthsFromRows(merged.rows, merged.months, merged.totalContract, { summaryCandidates, debug: options.debug });
    // P4 — a null here is a deliberate REFUSAL: the activity math was
    // unusable and no printed curve could stand in for it. Fall through to
    // the weekly reader and, failing that, to the honest "not recognised"
    // answer, rather than return a curve known to be wrong.
    if (!out) return { fallback: true };

    const refusal = readRefusal(pageResults, pass.labelOnlyPages || [], out);
    if (refusal) {
        // A refusal of a LEARNED layout's reading says so (2026-09-15): the fix is then
        // usually in S-Curve Layouts (a month taught one column off), not a new file.
        // First live case: a hand-taught two-page roof layout with page 1's months on
        // G..K instead of F..J, which the committed reader would have imported a month
        // early — the old message sent the user to the Excel file instead.
        refusal.viaMap = mapId !== null;
        if (refusal.viaMap) refusal.mapId = mapId;
        if (options.debug) options.debug.push({ t: 'refused', viaMap: refusal.viaMap, code: refusal.code, detail: refusal.detail });
        return { refusal };
    }

    (merged.warnings || []).forEach((w) => out.meta.warnings.unshift(w));
    if (mapId !== null) {
        out.meta.mapUsed = true;
        out.meta.mapId = mapId;
        out.meta.warnings.unshift('Heads-up: this PDF was read with a learned layout for this'
            + ' contractor — the months below came from that mapping. No action needed unless'
            + ' something looks off.');
    }
    return { out };
}

/**
 * Read an S-curve PDF: rules first, learned layouts second, the weekly reader last.
 *
 * opts: { monthWords, liveMaps, debug, onRefusal }
 *   onRefusal(refusal) — optional; called with { code, message, detail } when the
 *   file is left unread BECAUSE the read guard refused it (so the caller can say
 *   why instead of a generic "not recognised"). Never called on a normal miss.
 *
 * Returns { months, meta } or null.
 */
export function parseSCurvePdf(itemsByPage, opts) {
    const options = opts || {};
    const hasMaps = Array.isArray(options.liveMaps) && options.liveMaps.length > 0;
    let refusal = null;

    // Two passes, mirroring the Excel reader: the RULES read every page first, and
    // a learned layout is consulted only when the rules produced nothing usable. A
    // file the rules can read is therefore byte-identical to before, whatever has
    // been approved — maps extend the reader, they never override it.
    //
    // 2026-09-15 — "nothing usable" now also covers a rules reading the read guard
    // REFUSED. That is what lets a learned layout rescue such a file: without it a
    // layout would never be consulted on a page the rules recognise but misread
    // (the one-page BUHI print). The layout's own reading faces the same guard.
    const rules = collectPdfPages(itemsByPage, options, null);
    if (rules.pageResults.length > 0) {
        const r = readPageResults(rules, null, options);
        if (r.out) return r.out;
        if (r.fallback) return parseWeeklySchedulePdf(itemsByPage);
        refusal = r.refusal;
    }

    if (hasMaps) {
        const viaMaps = collectPdfPages(itemsByPage, options, options.liveMaps);
        if (viaMaps.pageResults.length > 0) {
            const r = readPageResults(viaMaps, viaMaps.mapId, options);
            if (r.out) return r.out;
            if (r.refusal) refusal = r.refusal;
        }
    }

    const weekly = parseWeeklySchedulePdf(itemsByPage);
    if (weekly || !refusal) return weekly;

    // Left unread on purpose: tell the caller why (best-effort, never throws).
    if (typeof options.onRefusal === 'function') {
        try { options.onRefusal(refusal); } catch (e) { /* reporting never blocks a read */ }
    }
    return null;
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
    const sheets = [];

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

            const anchors = tokenLines[i].tokens
                .filter((t) => String(t.str || '').trim() !== '')
                .sort((a, b) => a.x - b.x);
            if (anchors.length < 2) continue;

            // Pseudo-columns come from the TABLE, not from this one line. Reading
            // them off the header alone collapsed whole columns into each other —
            // the roof's header prints "NO." then jumps to "Amount", so DESCRIPTION,
            // UNIT and QTY. all landed in one 227pt band and the reviewer was shown
            // a table the document does not contain. See detectPseudoColumns.
            // Chart-scale labels are excluded here for the same reason the reader
            // excludes them (P6): a picture must not invent a column.
            const scaleTokens = chartScaleTokens(tokenLines);
            const columns = detectPseudoColumns(tokenLines, i, tokenLines.length, scaleTokens);
            if (columns.length < 2) continue;
            const pseudoCols = columns.map((c, k) => ({
                letter: columnLetter(k),
                x: c.x / pageMaxX,
                xEnd: (columns[k + 1] ? columns[k + 1].x : pageMaxX + 1) / pageMaxX,
            }));

            // The signature still comes from the header LINE — it is what matchMapPage
            // compares against the tokens of a page, and it must keep describing the
            // line this map was read from.
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

            // Every page that yielded a header is kept, so the desk can render the
            // WHOLE table rather than just the sheet the names happen to be on.
            sheets.push({ tokenLines, headerIndex: i, columns, monthCols: header.monthCols, scaleTokens });
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

    // An ambiguous capture keeps its GEOMETRY and loses only its NUMBERING.
    // It used to lose both: the map went null and the whole `found` array went with
    // it, pseudoCols included — so the rows this path exists to hand a reviewer
    // arrived carrying letters and cell text but not one coordinate, and teaching
    // them was impossible. The months are emptied because nothing in the bytes says
    // which month a repeated "30 days" column is; that is the reviewer's to supply.
    // usableLayoutMap() refuses a page with no months, so an untaught capture still
    // cannot be read from — it is offered for teaching, not trusted.
    const mapPages = found.map(({ indexed, ...page }) => (
        ambiguous ? { ...page, monthCols: [], unnumbered: true } : page
    ));

    // The desk renders ONE table for the whole schedule, not one sheet of it. A
    // column-split print is a single table cut down the middle, and showing only the
    // first half meant a reviewer could never point at the months printed on the
    // second — on the roof they could reach M1–M5 of a 7-month file, so teaching it
    // was impossible by construction. Letters run on across the seam (page 1 = A…K,
    // page 2 = L…M), which is what keeps ONE legend and ONE R-numbering, so the desk
    // grid, schParseHeaderBlock and parseStoredSlice all work unchanged.
    const block = buildMergedSliceBlock(sheets, maxRows);
    let letterAt = 0;
    for (let k = 0; k < mapPages.length; k++) {
        const own = (sheets[k] ? sheets[k].columns.length : mapPages[k].pseudoCols.length);
        mapPages[k].pseudoCols = mapPages[k].pseudoCols.map((pc, j) => ({
            ...pc,
            letter: columnLetter(letterAt + j),
        }));
        letterAt += own;
    }

    return {
        map: {
            v: PDF_MAP_VERSION,
            kind: 'pdf',
            pages: mapPages,
        },
        block,
        ambiguous,
    };
}

/**
 * PDF-4 Stage 2 — render every printed page of one table as a single grid.
 *
 * A column-split export is one table cut down the middle, and the two halves share
 * their row heights exactly (the P3 property the reader already relies on to merge
 * them). So the rows can be paired and laid side by side, giving the reviewer the
 * whole schedule at once — including the summary block, whose cumulative row is the
 * figure a baseline is adopted from.
 *
 * Rows are aligned by their offset from their OWN page's header line, because the
 * two headers do not sit at the same height (the roof's are 1.44pt apart), so a
 * shared origin would drift. A row present on one page and absent on the other
 * simply leaves those cells empty, which is what the print looks like.
 *
 * Pure. Called by: derivePdfLayout().
 */
function buildMergedSliceBlock(sheets, maxRows) {
    if (!sheets.length) return '';
    if (sheets.length === 1) {
        const s = sheets[0];
        return buildPdfSliceBlock(s.tokenLines, s.headerIndex, s.columns, s.monthCols, maxRows, s.scaleTokens);
    }

    const Y_TOL = 3;
    const lead = sheets[0];
    const letters = [];
    let offset = 0;
    const spans = sheets.map((s) => {
        const span = { from: offset, count: s.columns.length };
        for (let k = 0; k < s.columns.length; k++) letters.push(columnLetter(offset + k));
        offset += s.columns.length;
        return span;
    });

    const cellsFor = (sheet, lineIndex, into, span) => {
        if (lineIndex < 0 || lineIndex >= sheet.tokenLines.length) return false;
        const bands = sheet.columns;
        let any = false;
        for (const t of sheet.tokenLines[lineIndex].tokens) {
            const text = String(t.str || '').trim();
            if (text === '') continue;
            if (sheet.scaleTokens && sheet.scaleTokens.has(t)) continue;
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            const centre = (t.x + xEnd) / 2;
            let slot = -1;
            for (let k = 0; k < bands.length; k++) {
                const end = bands[k + 1] ? bands[k + 1].x : Infinity;
                if (centre >= bands[k].x && centre < end) { slot = k; break; }
            }
            if (slot === -1) slot = centre < bands[0].x ? 0 : bands.length - 1;
            const at = span.from + slot;
            into[at] = into[at] ? into[at] + ' ' + text : text;
            any = true;
        }
        return any;
    };

    const isValueBand = [];
    sheets.forEach((s, si) => {
        s.columns.forEach((c, k) => {
            const end = s.columns[k + 1] ? s.columns[k + 1].x : Infinity;
            isValueBand[spans[si].from + k] = (s.monthCols || []).some(
                (mc) => c.x < mc.xEnd && mc.x < end
            );
        });
    });

    const scanned = [];
    for (let i = Math.max(0, lead.headerIndex - 2); i < lead.tokenLines.length; i++) {
        const cells = new Array(letters.length).fill('');
        let any = cellsFor(lead, i, cells, spans[0]);

        // Pair each later page by the row's offset from its own header.
        const wanted = lead.tokenLines[i].y;
        for (let si = 1; si < sheets.length; si++) {
            const s = sheets[si];
            const shift = lead.tokenLines[lead.headerIndex].y - s.tokenLines[s.headerIndex].y;
            let bestIndex = -1;
            let bestDist = Infinity;
            for (let j = 0; j < s.tokenLines.length; j++) {
                const d = Math.abs((s.tokenLines[j].y + shift) - wanted);
                if (d < bestDist) { bestDist = d; bestIndex = j; }
            }
            if (bestIndex >= 0 && bestDist <= Y_TOL) {
                any = cellsFor(s, bestIndex, cells, spans[si]) || any;
            }
        }
        if (!any) continue;

        let role = 'DATA';
        if (i === lead.headerIndex) role = 'HEADER';
        else if (i < lead.headerIndex) role = 'META';
        else if (cells.some((c, k) => !isValueBand[k] && (isSummaryLabel(c) || isTotalLabel(c)))) {
            role = 'SUMMARY';
        }
        // Only a DATA or SUMMARY row may be promoted. This used to be unconditional,
        // so a header or title line whose month cells happen to read as rising
        // percentages ("10% 50% 100%" as column labels) lost its HEADER tag and the
        // teach picker opened with no header row — the fifth-round Excel symptom by
        // another route (2026-09-15). NOT `else if`: a real cumulative row usually
        // matches isSummaryLabel first, and `else if` would kill that upgrade.
        if (role !== 'HEADER' && role !== 'META' && isCumulativeShapedRow(cells, isValueBand)) role = 'CUMULATIVE';
        scanned.push({ role, cells });
    }

    const shown = scanned.filter((r, k) => k < maxRows || r.role === 'SUMMARY' || r.role === 'CUMULATIVE');
    const lines = ['COLUMNS: ' + letters.join(',')
        + ' | META: title block | HEADER: column labels'
        + ' | DATA: activity rows — compute months from these ONLY'
        + ' | SUMMARY: author totals (TOTAL/COST/Cash Flow) — reference only, NEVER copy into months'
        + ' | CUMULATIVE: the printed running curve — reference only, NEVER copy into months'];
    shown.forEach((row, k) => {
        lines.push('R' + (k + 1) + ' [' + row.role + ']: ' + row.cells.map(csvCell).join(','));
    });

    return lines.join('\n');
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
/**
 * P4 — how far right do this page's ACTIVITY rows actually reach?
 *
 * Returns the highest month index carrying a percent cell in a named row, or
 * null when the page has no such rows (peso tables, summary-only pages) — in
 * which case the caller must not trim anything.
 *
 * The narrowness is the point. A phantom column's band is not empty: on the roof
 * the TOTAL pesos, the cumulative percentages and the duration row all reach into
 * it, so "does anything sit here" answers yes and proves nothing. Requiring a
 * NAMED row (a word left of the first month band) with two or more PERCENT cells
 * isolates the activity rows from every kind of author total.
 *
 * Called by: derivePdfLayoutFromAi().
 */
function activityBandReach(tokenLines, headerIndex, monthCols) {
    if (!monthCols.length) return null;
    const firstX = monthCols[0].x;
    let reach = null;

    for (let i = headerIndex + 1; i < Math.min(tokenLines.length, headerIndex + 16); i++) {
        const toks = tokenLines[i].tokens;
        const named = toks.some((t) => t.x < firstX && /[A-Za-z]{2,}/.test(String(t.str || '')));
        if (!named) continue;

        let highest = null;
        let count = 0;
        for (const t of toks) {
            const s = String(t.str || '').trim();
            if (!/^\d[\d,]*(\.\d+)?\s*%$/.test(s)) continue;
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            const centre = (t.x + xEnd) / 2;
            for (const mc of monthCols) {
                if (centre >= mc.x && centre < mc.xEnd) {
                    count++;
                    if (highest === null || mc.index > highest) highest = mc.index;
                    break;
                }
            }
        }
        // One cell is not a row's reach — a stray percentage proves nothing.
        if (count >= 2 && highest !== null && (reach === null || highest > reach)) {
            reach = highest;
        }
    }

    return reach;
}

function bandsHoldNumbers(tokenLines, headerIndex, monthCols, minFilled = 2) {
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
        if (Object.keys(filled).length >= minFilled) return true;
    }

    return Object.keys(filled).length >= minFilled;
}

/**
 * PDF-4 Stage 2 — find the table's REAL columns, using every row it prints.
 *
 * The desk grid used to take its columns from the HEADER LINE's tokens alone, and
 * on a real contractor sheet that is not where the columns are. Measured on the
 * roof: its header line prints "NO." then jumps straight to "Amount", so one band
 * ran 35.4 -> 262.0 and swallowed four separate columns — the reviewer saw
 * "DESCRITPION UNIT QTY." in a single cell, and "ITEM NO." split across two rows
 * because ITEM is printed one line above NO.. The columns were never missing from
 * the document; they were missing from the line we happened to look at.
 *
 * So: cluster the x-positions of EVERY token in the block, then merge neighbouring
 * clusters back together where the data says they are one column.
 *
 * THE MERGE RULE, and it is checkable rather than tuned: two adjacent clusters are
 * the same column only if NO ROW holds a token in both. A right-aligned money
 * column drifts across several x values (the roof's amounts sit at 257/262/263/267
 * depending on how wide the number is) and no row carries two of them, so they
 * rejoin. The item letter at 38.6 and the work name at 53.9 appear TOGETHER on
 * every activity row, so they stay apart — correctly, since one is ITEM NO. and the
 * other is DESCRIPTION. Merging runs closest-gap-first so the nearest columns claim
 * each other before a distant pair can.
 *
 * SCOPE — start at the HEADER line, never above it. The lines above carry document
 * metadata ("CONTRACT NAME: …", "LOCATION: …", a centred title) whose label/value
 * pairs follow the page, not the table, and letting them vote tore real columns
 * apart: the SEGMENT specimen's ITEM NO. header separated from its own 1/2 values
 * because a metadata label above happened to share their rows. Those lines still
 * RENDER — they simply do not define where columns are. A multi-line header loses
 * nothing by this: the roof's UNIT and QTY. labels sit on a metadata line, but the
 * same columns are printed again as "LOT" and "1.00" on every activity row.
 *
 * Pure. Returns [{x, xEnd}] left to right, or [] when there is nothing to read.
 * Called by: buildPdfSliceBlock(), derivePdfLayout(), derivePdfLayoutFromAi().
 */
export function detectPseudoColumns(tokenLines, startIndex, endIndex, skipTokens) {
    const CLUSTER_X = 4;      // same-column tolerance for identical alignment
    const skip = skipTokens || null;

    const points = [];
    for (let i = Math.max(0, startIndex); i < Math.min(tokenLines.length, endIndex); i++) {
        for (const t of tokenLines[i].tokens || []) {
            const text = String(t.str || '').trim();
            if (text === '') continue;
            if (skip && skip.has(t)) continue;
            // A lone currency mark or punctuation is DECORATION, not a column. It
            // still renders — it just does not get a vote on where columns are.
            // Without this the roof grew SEVEN spurious columns: its TOTAL row
            // prints "₱" before every figure, and because the mark and its amount
            // share that row they can never merge, so each ₱ claimed a strip of its
            // own and split the month columns in half.
            if (!/[\p{L}\p{N}]/u.test(text)) continue;
            const xEnd = (t.xEnd !== undefined && t.xEnd !== null) ? t.xEnd : t.x;
            points.push({ x: t.x, xEnd: Math.max(xEnd, t.x), row: i });
        }
    }
    if (points.length === 0) return [];

    // Seed: tokens sharing an x are unarguably the same column.
    points.sort((a, b) => a.x - b.x);
    let cols = [];
    for (const p of points) {
        const last = cols[cols.length - 1];
        if (last && Math.abs(last.x - p.x) <= CLUSTER_X) {
            last.xs.push(p.x);
            last.xEnds.push(p.xEnd);
            last.rows.add(p.row);
            continue;
        }
        cols.push({ xs: [p.x], xEnds: [p.xEnd], rows: new Set([p.row]) });
    }

    // Two seeds are one column when they never share a row AND they occupy the same
    // strip of the page. Overlap is what tells a right-aligned money column from two
    // genuinely different columns that merely never co-occur:
    //   the roof's amounts sit at 257/262/267 but all END at ~305, so their strips
    //   lie almost exactly on top of one another — one column, drifting left as the
    //   figures get wider;
    //   its DESCRIPTION (53.9, running to ~189 on the longest name) and UNIT (187.8)
    //   never co-occur either, because each activity prints its name on one line and
    //   "LOT 1.00 amount" on the next — but their strips barely touch, so they stay
    //   apart, which is what the reviewer needs to see.
    const OVERLAP_MIN = 0.5;
    const overlapFraction = (a, b) => {
        const aX = Math.min(...a.xs), aEnd = Math.max(...a.xEnds);
        const bX = Math.min(...b.xs), bEnd = Math.max(...b.xEnds);
        const shared = Math.min(aEnd, bEnd) - Math.max(aX, bX);
        const narrower = Math.min(aEnd - aX, bEnd - bX);
        if (!(narrower > 0)) return shared >= 0 ? 1 : 0;
        return shared / narrower;
    };

    for (;;) {
        let best = -1;
        let bestOverlap = OVERLAP_MIN;
        for (let k = 0; k + 1 < cols.length; k++) {
            let collides = false;
            for (const r of cols[k + 1].rows) {
                if (cols[k].rows.has(r)) { collides = true; break; }
            }
            if (collides) continue;
            const ov = overlapFraction(cols[k], cols[k + 1]);
            if (ov <= bestOverlap) continue;
            bestOverlap = ov;
            best = k;
        }
        if (best === -1) break;
        const merged = cols[best + 1];
        cols[best].xs.push(...merged.xs);
        cols[best].xEnds.push(...merged.xEnds);
        merged.rows.forEach((r) => cols[best].rows.add(r));
        cols.splice(best + 1, 1);
    }

    cols = cols.map((c) => ({ x: Math.min(...c.xs), xEnd: Math.max(...c.xEnds) }));

    // Bands run from each column's start to the next one's, so every token on the
    // page lands somewhere — the same left-to-right banding the reader uses.
    return cols.map((c, k) => ({
        x: c.x,
        xEnd: cols[k + 1] ? cols[k + 1].x : (c.xEnd + 1),
    }));
}

/**
 * PDF-4 Stage 2 — is this summary line the CUMULATIVE curve?
 *
 * A schedule prints two rows that both label themselves "%": the per-month share
 * and the running total. In the grid they looked identical, and the running total
 * is the one a baseline is actually adopted from — so a reviewer could not tell the
 * decisive row from the one beside it. Worse, this family gives the cumulative row
 * no reachable label at all: the word "Commulative" is printed 4.6pt below its own
 * figures and lands on a line of its own.
 *
 * Identified by SHAPE, exactly as the reader identifies it (cumulativeRowValues):
 * percent-written cells, strictly ascending, finishing near completion. Display
 * only — it changes a role tag in the block, never a number.
 *
 * Called by: buildPdfSliceBlock(), buildMergedSliceBlock().
 */
function isCumulativeShapedRow(cells, isValueBand) {
    const values = [];
    for (let k = 0; k < cells.length; k++) {
        if (!isValueBand[k]) continue;
        const text = String(cells[k] || '').trim();
        if (text === '') continue;
        if (!/^\d[\d,]*(\.\d+)?\s*%$/.test(text)) return false;
        values.push(parseFloat(text.replace(/[%,]/g, '')));
    }
    if (values.length < 3) return false;
    // NON-DECREASING, not strictly ascending — the reader can demand strict because
    // it reads one page at a time, but this grid shows the pages MERGED, and the
    // seam repeats a value: the roof's month 6 is printed as the last column of
    // page one and again as the first of page two, so the row reads
    // "… 99.14, 99.14, 100.00". Requiring a rise overall is what still separates it
    // from the per-month row beside it, which falls (17.99 -> 17.41) and so fails
    // here whatever the seam does.
    for (let i = 1; i < values.length; i++) if (values[i] < values[i - 1]) return false;
    return values[values.length - 1] >= 50 && values[values.length - 1] > values[0];
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
function buildPdfSliceBlock(tokenLines, headerIndex, columns, monthCols, maxRows, skipTokens) {
    const letters = columns.map((c, k) => columnLetter(k));
    const bands = columns.map((c, k) => ({
        x: c.x,
        xEnd: columns[k + 1] ? columns[k + 1].x : Infinity,
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
        + ' | SUMMARY: author totals (TOTAL/COST/Cash Flow) — reference only, NEVER copy into months'
        + ' | CUMULATIVE: the printed running curve — reference only, NEVER copy into months'];

    const start = Math.max(0, headerIndex - 2);
    // Read the whole neighbourhood first, then choose what to show. The old loop
    // stopped dead at maxRows, and on a real sheet that is one row too early: the
    // roof spends ten rows on activity/"LOT 1.00 amount" pairs, so the window closed
    // on its per-month "%" line and the CUMULATIVE row — the row the baseline is
    // actually adopted from — never reached the desk. A reviewer was being asked to
    // trust a layout while the evidence for it sat just below the fold.
    const scanned = [];
    for (let i = start; i < tokenLines.length; i++) {
        const cells = new Array(letters.length).fill('');
        let any = false;
        for (const t of tokenLines[i].tokens) {
            const text = String(t.str || '').trim();
            if (text === '') continue;
            // P6 — the S-curve picture's own scale is not a cell. Drawing it made
            // phantom rows holding only "100%" or "25%", and put a stray "50%"
            // beside a real value in the same cell.
            if (skipTokens && skipTokens.has(t)) continue;
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
        // Same guard as buildMergedSliceBlock: never promote a HEADER or META line
        // (see the comment there for why `else if` is the wrong fix).
        if (role !== 'HEADER' && role !== 'META' && isCumulativeShapedRow(cells, isValueBand)) role = 'CUMULATIVE';

        scanned.push({ role, cells });
    }

    // The budget buys the head of the table; SUMMARY rows are then always added,
    // wherever they fell. They cost little (a handful of rows at most) and they are
    // the part a reviewer most needs to see.
    const shown = scanned.filter((r, k) => k < maxRows || r.role === 'SUMMARY' || r.role === 'CUMULATIVE');

    let kept = 0;
    for (const row of shown) {
        kept++;
        lines.push('R' + kept + ' [' + row.role + ']: ' + row.cells.map(csvCell).join(','));
    }

    return lines.join('\n');
}

/**
 * PDF-3 — locate month columns using the labels the AI reported.
 *
 * The AI already names each month's header ("month 1 is headed 30 days"), so the
 * columns can be found by matching those words against the printed tokens. Two
 * properties make this the strongest of the three strategies: the INDEX comes
 * from the AI rather than from position, and the POSITION comes from the page
 * rather than from the AI — neither side is trusted for what it cannot know.
 *
 * Duplicates resolve by order, which is what the roof family needs: its months
 * are all labelled "30 days", so month 1 takes the leftmost, month 2 the next,
 * and so on. Labels not printed on this page are skipped, not forced.
 *
 * Pure. Returns [{index, x, xEnd}] ascending in x, or [].
 */
function aiLabelMonthColumns(sorted, labels, wanted) {
    const norm = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, '');
    const used = {};
    const cols = [];

    for (const idx of wanted) {
        const want = norm(labels[idx]);
        if (want === '') continue;
        for (let i = 0; i < sorted.length; i++) {
            if (used[i]) continue;
            if (norm(sorted[i].str) !== want) continue;
            used[i] = true;
            cols.push({ index: idx, x: sorted[i].x, xEnd: sorted[i].xEnd });
            break;
        }
    }

    // Reading order must agree with the numbering, or these are not the columns
    // the AI meant.
    for (let i = 1; i < cols.length; i++) {
        if (cols[i].x <= cols[i - 1].x) return [];
    }

    return cols;
}

/**
 * PDF-3 — propose a layout from a verified AI read.
 *
 * This is what reaches the roof family: its months carry no index at all, so
 * capture alone must refuse to number them (see derivePdfLayout's ambiguity
 * rule), but an AI answer states the numbering outright. The AI supplies WHICH
 * month each header is; the page supplies WHERE it sits. Nothing is invented —
 * a label the page does not print simply yields no column.
 *
 * A continuation page may contribute a SINGLE month here, unlike the capture
 * path: the index is stated rather than inferred, so one matched label is honest
 * evidence and the roof's lone "7 days" tail column can finally be placed.
 *
 * Returns { map, block } or null. Callers store it as ai-derived, never live.
 */
export function derivePdfLayoutFromAi(itemsByPage, labels, aiMonths, maxRows = 15) {
    const pages = Array.isArray(itemsByPage) ? itemsByPage : [];
    const known = Object.keys(labels || {})
        .map(Number)
        .filter((n) => Number.isInteger(n) && n >= 1 && n <= 12)
        .sort((a, b) => a - b);
    if (known.length < 2) return null;

    const found = [];
    const assigned = {};
    const sheets = [];

    for (let p = 0; p < Math.min(pages.length, 20); p++) {
        const tokenLines = linesToTokenLines(groupItemsIntoLines(pages[p] || []));
        const pageMaxX = Math.max(0, ...tokenLines.flatMap((l) => l.tokens.map((t) => t.xEnd)));
        if (!(pageMaxX > 0)) continue;

        // Each page places only months no earlier page has placed, so a repeated
        // header on an overflow page cannot re-claim month 1.
        const wanted = known.filter((n) => !assigned[n]);
        if (wanted.length === 0) break;
        const minCols = found.length > 0 ? 1 : 2;

        for (let i = 0; i < Math.min(tokenLines.length, 10); i++) {
            const sorted = [...tokenLines[i].tokens].sort((a, b) => a.x - b.x);
            let cols = aiLabelMonthColumns(sorted, labels, wanted);
            if (cols.length < minCols) continue;

            const descTokens = sorted.filter((t) => isDescriptionLabel(t.str));
            let descX = descTokens.length ? Math.min(...descTokens.map((t) => t.x)) : null;
            if (descX === null) {
                const wordy = sorted.filter((t) => t.x < cols[0].x
                    && /[A-Za-z]{2,}/.test(String(t.str || '')));
                if (wordy.length) descX = Math.min(...wordy.map((t) => t.x));
            }

            // P4 — a label whose column carries no ACTIVITY data on this page is a
            // CONTINUATION STUB, not a column: its figures were printed on the NEXT
            // page under a reprint of the same label. The roof prints six "30 days"
            // on page 1 but its activity rows hold only five values; claiming the
            // sixth stranded month 6 on an empty band and left its real data on
            // page 2 unmapped, so December listed nothing but page furniture.
            //
            // "Activity data" has to be judged narrowly, because the band is NOT
            // empty — the TOTAL pesos, the cumulative row and the duration row all
            // reach into it. Only rows that are NAMED (a word left of the first
            // month band) and carry two or more PERCENT-written cells count, which
            // on this family is exactly the activity rows. Peso-valued tables yield
            // no such rows, so nothing is dropped there and the rule stays inert.
            let header = finalizeHeaderBands(cols, sorted, descX, pageMaxX);
            const reach = activityBandReach(tokenLines, i, header.monthCols);
            if (reach !== null) {
                const trimmed = cols.filter((c) => c.index <= reach);
                if (trimmed.length >= minCols && trimmed.length < cols.length) {
                    // The dropped label still marks where its column BEGAN, and the
                    // new last band would otherwise run to the page edge and swallow
                    // the stub column’s values (the roof summed 77.03 + 99.14 into
                    // month 5). Stop the band at the stub instead.
                    const stubX = cols.filter((c) => c.index > reach).map((c) => c.x).sort((a, b) => a - b)[0];
                    cols = trimmed;
                    header = finalizeHeaderBands(cols, sorted, descX, pageMaxX);
                    const lastBand = header.monthCols[header.monthCols.length - 1];
                    if (lastBand && stubX !== undefined && stubX > lastBand.x) {
                        lastBand.xEnd = Math.min(lastBand.xEnd, stubX);
                    }
                }
            }

            if (!bandsHoldNumbers(tokenLines, i, header.monthCols, Math.min(2, header.monthCols.length))) continue;

            const anchors = sorted.filter((t) => String(t.str || '').trim() !== '');
            if (anchors.length < 2) continue;

            // Same table-wide column detection the capture path uses, so a row
            // learned from an AI answer renders and teaches exactly like one the
            // rules captured — two derivations of the same thing must not disagree.
            const scaleTokens = chartScaleTokens(tokenLines);
            const columns = detectPseudoColumns(tokenLines, i, tokenLines.length, scaleTokens);
            if (columns.length < 2) continue;

            found.push({
                pageHint: p,
                lineHint: i,
                sig: anchors.map((t) => String(t.str || '').trim().toLowerCase()).filter((s) => s !== ''),
                descX: header.descX / pageMaxX,
                descRight: header.descRight / pageMaxX,
                monthCols: header.monthCols.map((mc) => ({
                    index: mc.index,
                    x: mc.x / pageMaxX,
                    xEnd: mc.xEnd / pageMaxX,
                })),
                pseudoCols: columns.map((c, k) => ({
                    letter: columnLetter(k),
                    x: c.x / pageMaxX,
                    xEnd: (columns[k + 1] ? columns[k + 1].x : pageMaxX + 1) / pageMaxX,
                })),
                headerLine: anchors.map((t) => String(t.str || '').trim()).join(' '),
            });
            cols.forEach((c) => { assigned[c.index] = true; });
            sheets.push({ tokenLines, headerIndex: i, columns, monthCols: header.monthCols, scaleTokens });
            break;
        }
    }

    if (found.length === 0) return null;
    // Fewer than two months placed in total is not a schedule worth teaching.
    if (Object.keys(assigned).length < 2) return null;

    // ONE GRID AND ONE LEGEND, exactly as the capture path builds them.
    //
    // §2/§3 gave derivePdfLayout table-wide columns and a merged, letter-continuous
    // grid and left this twin behind, which made every ai-derived row un-teachable:
    // its block was page ONE only (roof: A…K, six month labels, no month 7) while
    // its MAP spanned both pages, and its page-2 pseudoCols restarted their letters
    // at A,B. In the teach modal month 6 therefore landed on letter A — the same
    // letter as the description column — which is what the 2026-09-14 acceptance
    // run reported as a "duplicate month 1 label".
    //
    // Two derivations of the same table must not disagree about what a letter means.
    const block = buildMergedSliceBlock(sheets, maxRows);
    let letterAt = 0;
    for (let k = 0; k < found.length; k++) {
        const own = (sheets[k] ? sheets[k].columns.length : found[k].pseudoCols.length);
        found[k].pseudoCols = found[k].pseudoCols.map((pc, j) => ({
            ...pc,
            letter: columnLetter(letterAt + j),
        }));
        letterAt += own;
    }

    const map = { v: PDF_MAP_VERSION, kind: 'pdf', pages: found };

    // SELF-CHECK — read the file back with the very map about to be taught, and
    // require it to reproduce the curve the AI just produced.
    //
    // Finding the columns is not the same as reading the table. The roof family
    // proves it: its cells are PER-ACTIVITY PERCENTAGES, so a month total needs
    // each activity's cost weighting it, while this reader sums raw cell values.
    // Its bands can be located perfectly and the resulting curve is still wrong.
    // A map that cannot reproduce the answer must never be offered for approval,
    // because approval is what makes it fill baselines unattended.
    if (Array.isArray(aiMonths) && aiMonths.length > 0) {
        let replay = null;
        try {
            replay = parseSCurvePdf(itemsByPage, { liveMaps: [{ id: 0, map }] });
        } catch (e) { replay = null; }
        if (!replay || !replay.meta || replay.meta.mapUsed !== true) return null;

        const got = {};
        (replay.months || []).forEach((m) => { got[m.month_index] = m.planned_progress_percent; });
        for (const m of aiMonths) {
            const idx = Number(m && m.month_index);
            const want = Number(m && m.planned_progress_percent);
            if (!Number.isFinite(idx) || !Number.isFinite(want)) continue;
            if (!(idx in got)) return null;
            if (Math.abs(got[idx] - want) > 1.0) return null;
        }
    }

    return {
        map,
        block,
        months: Object.keys(assigned).map(Number).sort((a, b) => a - b),
    };
}

/**
 * P5 — read the file ourselves with the layout the AI just described, so its
 * answer can be checked against the document instead of taken on trust.
 *
 * The PDF twin of the Excel rule at SCurveImportController::applyDerivedLayout.
 * There the server re-reads the sheet with the columns the model reported and
 * lets the FILE win on disagreement; here the same idea has to run in the
 * browser, because the server owns no PDF reader — pdf.js in the page is the
 * only reader there is.
 *
 * The computation is not new. PDF-3's self-check already replays the file
 * through the AI-derived map; it simply throws the result away on disagreement,
 * which is exactly when it is worth the most. On the roof, a model that answered
 * with cost-weighted arithmetic over the printed whole-number percentages
 * (6.69 / 24.60 / … / 100 / 100) was contradicted by the file's own cumulative
 * row (6.32 / 24.31 / … / 100) — and the correct reading had already been
 * computed and discarded.
 *
 * TRUST GATE — deliberately narrower than the Excel one. The map is built from
 * the AI's OWN labels, so a mislabelled month poisons the replay as well; the
 * reading is only allowed to overrule the model when it comes from the AUTHOR'S
 * PRINTED CUMULATIVE ROW (curveSource 'summary'), which is the document stating
 * its own curve rather than us recomputing one. A replay resting on activity
 * math shares too much of its reasoning with the answer it would be judging, so
 * it stays advisory and this returns null.
 *
 * Pure. Returns the file's own {months, meta} or null when it cannot be trusted.
 */
export function crossCheckPdfAgainstFile(itemsByPage, labels, aiMonths) {
    if (!Array.isArray(aiMonths) || aiMonths.length === 0) return null;

    // aiMonths is NOT passed through: that parameter runs the teaching
    // self-check, which returns null precisely when the two disagree.
    const derived = derivePdfLayoutFromAi(itemsByPage, labels, null);
    if (!derived || !derived.map) return null;

    let replay = null;
    try {
        replay = parseSCurvePdf(itemsByPage, { liveMaps: [{ id: 0, map: derived.map }] });
    } catch (e) {
        return null;
    }
    if (!replay || !replay.meta || replay.meta.mapUsed !== true) return null;
    if (replay.meta.curveSource !== 'summary') return null;

    // Full coverage or nothing: a reading that is missing months cannot stand in
    // for an answer that has them all.
    const got = {};
    (replay.months || []).forEach((m) => { got[m.month_index] = m.planned_progress_percent; });
    for (const m of aiMonths) {
        const idx = Number(m && m.month_index);
        if (!Number.isFinite(idx) || !(idx in got)) return null;
    }
    if ((replay.months || []).length < 2) return null;

    return replay;
}

/**
 * P5 — do the file's own months differ from the AI's by more than the tolerance?
 *
 * Deliberately NOT the 1.0 used by the Excel path and PDF-3's self-check. That
 * threshold asks "is this the same LAYOUT"; this asks "are these the same
 * NUMBERS", and the roof proves the difference matters. A model answering with
 * cost-weighted arithmetic over the printed whole-number percentages returned
 * 6.69 / 24.60 / 42.51 / 60.32 / 77.55 / 100 / 100 against a true
 * 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 — every gap UNDER one point,
 * the largest 0.86 — yet that curve reaches completion a month early and shows no
 * work at all in its final month. A 1.0 tolerance calls it agreement.
 *
 * So the comparison runs at display precision: if the reviewer would read
 * different numbers on screen, they are different. Months the AI did not report
 * are ignored — this asks whether the model contradicts the file, not whether the
 * two cover the same ground.
 */
export function pdfCurvesDisagree(fileMonths, aiMonths, tolerance = 0.05) {
    const got = {};
    (fileMonths || []).forEach((m) => { got[m.month_index] = m.planned_progress_percent; });
    for (const m of (aiMonths || [])) {
        const idx = Number(m && m.month_index);
        const want = Number(m && m.planned_progress_percent);
        if (!Number.isFinite(idx) || !Number.isFinite(want)) continue;
        if (!(idx in got)) continue;
        if (Math.abs(got[idx] - want) > tolerance) return true;
    }
    return false;
}

/* ─── AI assist page images: render size (shrink-to-fit, 2026-09-15) ──────── */

// Usual render scale for AI page images: pdf.js measures pages in 72-DPI points,
// so 2.08 is ~150 DPI.
export const PDF_SLICE_SCALE = 2.08;
// Longest side, in pixels, a page image may be rendered at (the AI-3 canvas guard).
export const PDF_SLICE_MAX_PX = 4000;

/**
 * Render scale for one AI assist page image.
 * Called by: buildSCurvePdfSlices (scurve-pdf-parser.js), with the page's size at
 * scale 1 (points).
 *
 * Returns { scale, shrunk, dpi }, or null for a page with no usable size.
 *
 * Before 2026-09-15 a page whose ~150 DPI render exceeded 4000 px was SKIPPED. The
 * one-page BUHI print is 2592 x 1728 pt (5391 px at 2.08) and it is the file's only
 * page, so AI assist rendered nothing and failed "could not be rendered to images".
 * Shrinking instead costs no detail the model would have seen: Gemini 3 gives every
 * image a fixed token budget whatever its pixel size, so a larger render is reduced
 * on arrival anyway. The 4000 px ceiling stays as the browser-canvas guard.
 */
export function pdfSliceRenderScale(width, height) {
    const longest = Math.max(Number(width), Number(height));
    if (!Number.isFinite(longest) || !(longest > 0)) return null;
    const scale = Math.min(PDF_SLICE_SCALE, PDF_SLICE_MAX_PX / longest);

    return { scale, shrunk: scale < PDF_SLICE_SCALE, dpi: Math.round(scale * 72) };
}

/**
 * Reviewer-facing note for page images that had to be shrunk before the AI read
 * them, or null when none were.
 * Called by: scurve-import-ui.js (_trySCurvePdfAiAssist) — added to the import notes
 * on an AI answer, and to the error when the AI fails.
 *
 * shrunkPages: [{ page, dpi }] as reported by buildSCurvePdfSlices. The lowest DPI is
 * named, because that page is where small figures are likeliest to be misread.
 * opts.failed: the AI call failed, so there are no months to call inaccurate — the
 *   note then warns that a retry would carry the same risk.
 *
 * Advice order (user, 2026-09-15): the original Excel first; failing that, a PDF
 * split LEFT-TO-RIGHT onto two pages with every row and the header repeated on each —
 * the column-split shape the offline reader already merges (roof, 6-stage, 10-month)
 * and that gives each AI image its own budget. A top-to-bottom split is the shape the
 * read guard refuses, hence "every row on each page".
 */
export function pdfSliceShrinkNote(shrunkPages, opts) {
    const dpis = (Array.isArray(shrunkPages) ? shrunkPages : [])
        .map((p) => Number(p && p.dpi))
        .filter((d) => Number.isFinite(d) && d > 0);
    if (dpis.length === 0) return null;
    const which = dpis.length === 1 ? 'page was' : dpis.length + ' pages were';
    const shrunk = ' too large to send to the AI at full size and had to be shrunk to about '
        + Math.min(...dpis) + ' DPI (the usual is 150)';
    const advice = ' For a dependable baseline, import the original Excel (.xlsx) S-curve file;'
        + ' failing that, a PDF split left-to-right onto two pages, with every row and the'
        + ' header rows repeated on each page, reads better than one oversized page.';

    if (opts && opts.failed) {
        return 'Heads-up: this PDF’s ' + which + shrunk + ', so even if a retry succeeds, small'
            + ' figures may be misread.' + advice;
    }
    return 'Heads-up: this PDF’s ' + which + shrunk + ' before the AI read it. Small figures'
        + ' may have been misread, so these months may be inaccurate.' + advice;
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
        derivePdfLayoutFromAi,
        crossCheckPdfAgainstFile,
        pdfCurvesDisagree,
        pdfSliceRenderScale,
        pdfSliceShrinkNote,
        PDF_MAP_VERSION,
    };
}
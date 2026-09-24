// ===================================================
// DOCUMENT PARSER CORE (pure, testable)
// All parsing/cleanup logic for the PDF autofill feature,
// kept free of DOM/PDF.js dependencies so it can be unit
// tested with `node --test`.
//
// Loaded in the browser with <script type="module"> (classic
// scripts cannot contain `export`). It attaches itself to
// window.documentParserCore for the classic document-parser.js
// script to consume.
//
// In Node the same file is imported as an ESM module:
//   import core from '.../document-parser-core.js'
// ===================================================

const DocumentParserCore = (function () {
    'use strict';

    // ─── GENERIC CLEANUP HELPERS ────────────────────────────────────────────

    // Clean up a raw string: trim, collapse internal whitespace, strip trailing punctuation.
    function cleanValue(raw) {
        if (raw === null || raw === undefined) return '';
        return String(raw)
            .trim()
            .replace(/\s+/g, ' ')
            .replace(/[:\-,;.]+$/, '')
            .trim();
    }

    // Convert an ALL-CAPS string to Title Case ("CONSTRUCTION OF BUILDING" → "Construction of Building").
    function toTitleCase(str) {
        const minor = new Set(['of', 'the', 'and', 'for', 'in', 'at', 'to', 'a', 'an', 'by', 'or']);
        return String(str).toLowerCase().split(' ').map((word, i) => {
            if (i === 0 || !minor.has(word)) {
                return word.charAt(0).toUpperCase() + word.slice(1);
            }
            return word;
        }).join(' ');
    }

    // Dynamic word-to-number converter for durations and budget PESOS words (replaces hardcoded 1-90 map).
    // Handles "ninety", "ninety-one", "one hundred", "three hundred fifty", "six million ..." etc. up to 3650 days
    // and budget pesos words up to millions (e.g., SIX MILLION ...). Approach B — lightweight vendored equivalent
    // of npm "words-to-numbers" per no-vite convention.
    // Called by: parseDurationToDays() when digit missing but word near "calendar days"; also budget word fallback.
    function wordsToNumber(phrase) {
        if (!phrase) return null;
        let s = String(phrase).toLowerCase().replace(/-/g, ' ').replace(/\s+/g, ' ').trim();
        if (!s) return null;
        const UNITS = { zero:0, one:1, two:2, three:3, four:4, five:5, six:6, seven:7, eight:8, nine:9, ten:10, eleven:11, twelve:12, thirteen:13, fourteen:14, fifteen:15, sixteen:16, seventeen:17, eighteen:18, nineteen:19 };
        const TENS = { twenty:20, thirty:30, forty:40, fifty:50, sixty:60, seventy:70, eighty:80, ninety:90 };
        const SCALES = { hundred:100, thousand:1000, million:1000000 };
        // Quick check: must contain at least one number word near calendar days context
        const hasNumberWord = Object.keys(UNITS).concat(Object.keys(TENS)).concat(Object.keys(SCALES)).some(w => s.includes(w));
        if (!hasNumberWord) return null;
        // Try to extract the contiguous number-word sequence (e.g., "three hundred fifty" inside "within three hundred fifty calendar days")
        // Find the longest substring that is only number words / and
        const tokens = s.split(' ').filter(Boolean);
        let best = null;
        for (let start = 0; start < tokens.length; start++) {
            let total = 0; let current = 0; let matched = 0;
            for (let i = start; i < tokens.length; i++) {
                const tok = tokens[i];
                if (tok === 'and') continue;
                if (UNITS.hasOwnProperty(tok)) { current += UNITS[tok]; matched++; }
                else if (TENS.hasOwnProperty(tok)) { current += TENS[tok]; matched++; }
                else if (SCALES.hasOwnProperty(tok)) {
                    if (current === 0) current = 1;
                    current *= SCALES[tok];
                    // Thousand AND million both flush the running group into the total —
                    // otherwise a later hundred/thousand compounds onto the millions
                    // ("four million eight hundred…" minted 400,000,834,297).
                    if (SCALES[tok] >= 1000) { total += current; current = 0; }
                    matched++;
                } else break;
            }
            total += current;
            if (matched > 0 && total > 0) {
                if (best === null || total > best) best = total; // prefer larger (e.g., 350 over 3)
            }
        }
        return best;
    }

    // Parse a human date into YYYY-MM-DD (HTML date input format). Returns null on failure.
    // Supports: "January 15, 2026" / "Jan. 15, 2026" / "15/01/2026" / "01-15-2026" / "2026-01-15".
    function parseToInputDate(raw) {
        if (!raw) return null;
        raw = String(raw).trim();

        const monthNames = {
            january: '01', february: '02', march: '03', april: '04',
            may: '05', june: '06', july: '07', august: '08',
            september: '09', october: '10', november: '11', december: '12',
            jan: '01', feb: '02', mar: '03', apr: '04',
            jun: '06', jul: '07', aug: '08', sep: '09', oct: '10', nov: '11', dec: '12',
        };

        // "Month DD, YYYY" or "Month DD YYYY" — or OCR-misread "Month DD. YYYY" (period for
        // comma, e.g. "March 13. 2026"). The [,.] class keeps comma behavior byte-identical.
        const wordy = raw.match(/^([A-Za-z]+)\.?\s+(\d{1,2})[,.]?\s+(\d{4})$/);
        if (wordy) {
            const month = monthNames[wordy[1].toLowerCase()];
            if (month) {
                return `${wordy[3]}-${month}-${wordy[2].padStart(2, '0')}`;
            }
        }

        // "YYYY-MM-DD" already correct
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;

        // "DD/MM/YYYY" — assume DD/MM/YYYY for Philippine docs
        const slashDMY = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (slashDMY) {
            const [, d, m, y] = slashDMY;
            return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
        }

        // "MM-DD-YYYY"
        const dashMDY = raw.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
        if (dashMDY) {
            const [, m, d, y] = dashMDY;
            return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
        }

        return null;
    }

    // Diagnostic flag: set window._parserDebug = true in console to see extraction logs
    // Called by: browser console — no code change needed, just `window._parserDebug = true` then re-upload.

    // Convert a duration phrase into days ("240" → 240, "8 months" → 240, "1 year" → 365). Returns null on failure.
    // Enhanced 2026-09-04: trust word numbers even if digit missing. "NINETY (90) calendar days" → 90,
    // pure "NINETY calendar days" → 90. Ignores "THREE PERCENT (3%)" via % filter.
    // Called by: extractDuration() via asDays normalizer; also fallback for "within NINETY ...".
    function parseDurationToDays(raw) {
        const _dbg = (typeof window !== 'undefined' && window._parserDebug);
        if (_dbg) console.debug('[Parser] parseDurationToDays raw:', JSON.stringify(String(raw).slice(0,120)));
        if (raw === null || raw === undefined) return null;
        let s = String(raw).trim();
        if (!s) return null;
        // Ignore percent context: "THREE PERCENT (3%) discount" should not be duration
        if (/%/.test(s) && /percent/i.test(s)) {
            // But if it also has "calendar days" later, keep that part
            const calPart = s.match(/(.+calendar\s*days.*)/i);
            if (calPart) s = calPart[1];
            else {
                if (_dbg) console.debug('[Parser] parseDurationToDays ignored percent without calendar days:', JSON.stringify(s.slice(0,80)));
                return null;
            }
        }
        // Try digit in parentheses first: "NINETY (90) calendar days" → 90
        const paren = s.match(/\(\s*(\d{1,4})\s*\)/);
        if (paren) {
            const num = parseInt(paren[1], 10);
            if (num > 0 && num <= 3650) {
                if (/month/i.test(s)) return String(Math.round(num * 30));
                if (/year/i.test(s)) return String(num * 365);
                return String(num);
            }
        }
        // Word-to-digit dynamic parser (replaces hardcoded 1-90 map).
        // Approach B: words-to-numbers logic for 1..3650 (e.g., "NINETY" → 90, "NINETY-ONE" → 91,
        // "ONE HUNDRED TWENTY" → 120, "THREE HUNDRED FIFTY" → 350) without needing a separate
        // npm package — lightweight dynamic function `wordsToNumber` handles hyphen + "and".
        // Keeps document-parser-core.js pure and testable (no external CDN), same idea as
        // "words-to-numbers" npm lib (v1.5.1) but vendored inline per no-vite convention.
        const lower = s.toLowerCase();
        const wordNum = wordsToNumber(lower);
        if (_dbg && wordNum !== null) console.debug('[Parser] parseDurationToDays wordNum:', wordNum, 'from', JSON.stringify(lower.slice(0,60)));
        if (wordNum !== null && wordNum > 0 && wordNum <= 3650) {
            // Allow month/year even without calendar days (e.g., "8 months"), but for plain numbers require calendar days to avoid budget 29/100
            if (/calendar\s*days|days|month|year/i.test(s)) {
                const out = /month/i.test(s) ? String(Math.round(wordNum * 30)) : /year/i.test(s) ? String(wordNum * 365) : String(wordNum);
                if (_dbg) console.debug('[Parser] parseDurationToDays word→digit result:', out, 'from', JSON.stringify(s.slice(0,80)));
                return out;
            }
        }
        // Fallback: first digits — allow plain number if it's from a duration label (e.g., "No. of calendar days" → "120" has no "days" in value, but label was duration)
        // To avoid picking budget 29/100 (which has PESOS/MILLION words), require calendar days/month/year unless value is pure number
        const isPureNumber = /^\s*[\d,]+(?:\.\d+)?\s*$/.test(s) || /^\s*\d+\s*(months?|years?)?\s*$/i.test(s);
        if (!/calendar\s*days|days|month|year/i.test(s) && !isPureNumber) {
            // If it contains budget words like PESOS/MILLION without duration context, reject
            if (/pesos|million|thousand/i.test(s)) {
                if (_dbg) console.debug('[Parser] parseDurationToDays reject budget word without calendar days:', JSON.stringify(s.slice(0,80)));
                return null;
            }
            // For plain numbers like "120" coming from "No. of calendar days" next line, allow — but only if caller was duration label
            // Since parseDurationToDays doesn't know caller context, allow pure numbers as duration (e.g., "120")
            if (!isPureNumber) {
                if (_dbg) console.debug('[Parser] parseDurationToDays no calendar days context for', JSON.stringify(s.slice(0,80)));
                return null;
            }
        }
        const m = s.match(/(\d{1,4})/);
        if (!m) {
            if (_dbg) console.debug('[Parser] parseDurationToDays no digit and no wordNum for', JSON.stringify(s.slice(0,80)));
            return null;
        }
        const num = parseInt(m[1], 10);
        if (!num || num <= 0 || num > 3650) return null;
        if (/%/.test(s) && !/calendar\s*days/i.test(s)) {
            if (_dbg) console.debug('[Parser] parseDurationToDays ignore stray % num', num, 'from', JSON.stringify(s.slice(0,80)));
            return null;
        }
        const out2 = /month/i.test(s) ? String(Math.round(num * 30)) : /year/i.test(s) ? String(num * 365) : String(num);
        if (_dbg) console.debug('[Parser] parseDurationToDays digit fallback result:', out2, 'from', JSON.stringify(s.slice(0,80)));
        return out2;
    }

    // ─── PDF TEXT RECONSTRUCTION ────────────────────────────────────────────

    // Group PDF.js text items into visual lines by Y-position tolerance, then sort
    // each line's items by X-position. This fixes multi-column/table scrambling.
    // Items are objects with { str, transform: [..,x,..,y], width, hasEOL }.
    function groupItemsIntoLines(items) {
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

        // Sort lines top-to-bottom, items left-to-right.
        // NOTE: PDF.js coordinates are bottom-up (origin at the bottom-left, y
        // grows upward), so the TOP of the page has the LARGEST y. Descending
        // sort therefore yields true reading order. Ascending would emit the
        // page upside-down (bottom line first).
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

    // Join a line's text items into a single string, inserting a space when the
    // X-gap between items is too large (fixes glued words) or an explicit EOL flag.
    function lineItemsToString(items) {
        let out = '';
        let prevEndX = null;

        for (const item of items) {
            const x = item.transform ? item.transform[4] : (item.x || 0);
            const width = item.width || 0;

            if (out.length > 0 && prevEndX !== null) {
                const gap = x - prevEndX;
                if (gap > 1.5 || item.hasEOL) out += ' ';
            }

            out += item.str;
            prevEndX = x + width;
        }

        return out;
    }

    // Reconstruct one PDF page's text content (items array) into plain text with real line breaks.
    function pageItemsToText(items) {
        const lines = groupItemsIntoLines(items);
        return lines.map(line => lineItemsToString(line.items)).join('\n');
    }

    // ─── LINE-BASED LABEL → VALUE LOOKUP ────────────────────────────────────

    // Build a trimmed, non-empty line array from raw extracted text.
    function buildLinesFromText(text) {
        return String(text || '')
            .split('\n')
            .map(l => l.trim())
            .filter(l => l.length > 0);
    }

    // Find a value near a label line. For each label pattern:
    //   1. If the same line carries a value after the label, use it (if it passes
    //      the normalizer).
    //   2. Otherwise, take the NEXT non-empty line as the value.
    //   3. NEW: also try the next-two-lines (BOQs put value 2 lines below label).
    // normalize(value) returns the cleaned string or null to reject. labelPatterns
    // are regexes whose first capture group is the optional inline value.
    // Called by: extractTitle(), extractLocation(), extractBudget(), extractABC(),
    //            extractBudgetSource(), extractCategory(), extractContractor(),
    //            extractStartDate(), extractEndDate(), extractDuration()
    function findLabeledValue(lines, labelPatterns, normalize) {
        const nonEmpty = (lines || []).map(l => String(l).trim()).filter(Boolean);

        lineLoop: for (let i = 0; i < nonEmpty.length; i++) {
            const line = nonEmpty[i];

            for (const pattern of labelPatterns) {
                const match = line.match(pattern);
                if (!match) continue;

                // Same-line value
                if (match[1] !== undefined && String(match[1]).trim().length > 0) {
                    // Step 1i-1: an explicit placeholder ("xxx", "n/a", "–") means this
                    // occurrence is intentionally empty — skip the whole line instead of
                    // sliding into the next section's date/figure.
                    if (isPlaceholderValue(match[1])) continue lineLoop;
                    const inline = normalize ? normalize(cleanValue(match[1])) : cleanValue(match[1]);
                    if (inline !== null && inline !== '') return inline;
                }

                // Next-line value (tables/contracts put the value below the label)
                const next = nonEmpty[i + 1];
                if (next) {
                    // Step 1i-1: placeholder on the next line also closes this occurrence.
                    if (isPlaceholderValue(next)) continue lineLoop;
                    const candidate = normalize ? normalize(cleanValue(next)) : cleanValue(next);
                    if (candidate !== null && candidate !== '') return candidate;
                }

                // Next-two-lines value (some BOQs/tables put label, blank line, value)
                const next2 = nonEmpty[i + 2];
                if (next2) {
                    const candidate2 = normalize ? normalize(cleanValue(next2)) : cleanValue(next2);
                    if (candidate2 !== null && candidate2 !== '') return candidate2;
                }
            }
        }

        return null;
    }

    // Normalizer helpers used by the field extractors.
    const lenRange = (min, max) => (v) => (v && v.length >= min && v.length <= max) ? v : null;
    // B-1 (2026-09-20) — a funding source is a NAME, and a name has a shape.
    // WHY THIS EXISTS: extractBudgetSource validated with lenRange(2, 100), which is a
    // length check and nothing else, so ANY 2-to-100-character tail following a funding
    // label was accepted. It never bit while most scanned pages went unread; the moment
    // the contrast fix made six more pages of a real scan readable, a misread tail
    // arrived as "Accor, Fost =" — and the preview does not merely SHOW a budget source,
    // it offers to CREATE it as a row in budget_sources. Garbage in a text box is a
    // nuisance; garbage offered as a permanent database row is a different matter.
    // WHAT A SOURCE LOOKS LIKE: a short name or acronym — GAA, CHED, DPWH, LGU, GAD,
    // "Trust Fund", "CHED-HEDF", "General Appropriations Act (GAA)", "20% Development
    // Fund". Never a sentence, never an equation, never a bare number.
    // DELIBERATELY BIASED TOWARDS REJECTING. A rejected value leaves the field empty for
    // the reviewer to type, and the L1-L3 learning loop lets them teach it so the next
    // document fills free. An accepted misread invites a junk row nobody unmakes.
    // Called by: extractBudgetSource().
    const SOURCE_STOP_WORDS = new Set(['the', 'in', 'of', 'and', 'with', 'for', 'to',
        'from', 'at', 'by', 'as', 'is', 'are', 'was', 'were', 'that', 'this', 'which',
        'shall', 'said', 'per', 'such', 'any', 'all']);
    function asSourceName(v) {
        const t = cleanValue(v || '').replace(/\s+/g, ' ').trim();
        if (t.length < 2 || t.length > 60) return null;
        // Name punctuation only. '=', '|', '_', '*', '<', '>', '#', '@', '"', ':' and the
        // rest are OCR debris or a label bleeding in — never part of a source's name.
        if (!/^[A-Za-z0-9 .,&()\-\/'%]+$/.test(t)) return null;
        // A name opens on a letter, a digit or a bracket and closes on a letter, digit,
        // bracket, full stop or percent. The leading digit is deliberate: "20% Development
        // Fund" is a real LGU source. It costs nothing, because a purely numeric misread
        // like "05142005" is caught by the two-consecutive-letters rule just below.
        if (!/^[A-Za-z0-9(]/.test(t) || !/[A-Za-z0-9).%]$/.test(t)) return null;
        // At least one real word, so "3 1" and "05142005" are out.
        if (!/[A-Za-z]{2}/.test(t)) return null;
        // A source is a name, not a clause. Six words is already generous for
        // "General Appropriations Act (GAA) FY 2025".
        const words = t.split(' ').filter(Boolean);
        if (words.length > 6) return null;
        // A fragment caught mid-sentence starts on a function word; a name does not.
        if (SOURCE_STOP_WORDS.has(words[0].toLowerCase().replace(/[^a-z]/g, ''))) return null;
        // Punctuation-heavy strings are debris even when every character is allowed.
        const compact = t.replace(/\s/g, '');
        const punct = (compact.match(/[.,&()\-\/'%]/g) || []).length;
        if (punct / compact.length > 0.25) return null;
        return t;
    }
    // B-4 (2026-09-20) — a contractor field holds ONE company, not a table row.
    // WHY THIS EXISTS: the contractor was validated by length alone (3..150), and the
    // value-side heuristic accepts any 5-to-150-character line carrying a construction
    // word. An ABSTRACT OF BIDS lists every bidder across one printed row, so once the
    // contrast fix made that page readable the row itself became the best-looking
    // candidate and won: "DONBER CONSTRUCTION | 301 ENGINEERS | SKYSCRAPER CONS. &
    // ENGINEERING SERVICES | CHRISTINAJOY ..." — four companies in the field, and the
    // real contractor (SKYSCRAPER, named on the contract page as "hereinafter called the
    // CONTRACTOR") demoted. Before that page could be read, the contract page won and
    // the field was right.
    // THE SIGNAL IS THE PIPE. A '|' in this text is a CELL BOUNDARY — either the line
    // reconstructor joining columns, or tesseract reading a table's printed vertical
    // rule. It is never part of a company's name, so a candidate carrying one is a ROW.
    // ⚠ WHAT THIS DOES NOT CATCH: a bidder table drawn WITHOUT vertical rules, whose
    // cells OCR into one pipe-less line. The length ceiling below is the only guard
    // against that, and it is a weak one. Noted rather than papered over.
    // Called by: extractContractor(), isContractorValueCandidate().
    function asCompanyName(v) {
        const t = cleanValue(v || '').trim();
        if (t.length < 5 || t.length > 100) return null;
        if (t.indexOf('|') !== -1) return null;
        return t;
    }
    // B-2 (2026-09-20) — how close is this value to a row that already exists?
    // WHY: the preview can CREATE a missing category or budget source. B-1 stopped
    // obvious debris, but a value can be well-shaped and still wrong — OCR confuses C
    // and G, so GAA arrives as CAA, passes every shape check, and becomes a near-
    // duplicate row that silently splits every report grouping by source.
    // Returns { name, distance } for the closest existing name within `max` edits, or
    // null. Case- and space-insensitive, because "Trust  fund" and "Trust Fund" are the
    // same row to a human and should not become two.
    // Pure, so the suite tests it; the preview does the warning.
    // Called by: renderChoiceCreateAction() in document-parser.js.
    function editDistance(a, b) {
        const s1 = String(a || ''), s2 = String(b || '');
        if (s1 === s2) return 0;
        if (!s1.length) return s2.length;
        if (!s2.length) return s1.length;
        // Two rolling rows rather than the full matrix — these are short names.
        let prev = new Array(s2.length + 1);
        let cur = new Array(s2.length + 1);
        for (let j = 0; j <= s2.length; j++) prev[j] = j;
        for (let i = 1; i <= s1.length; i++) {
            cur[0] = i;
            for (let j = 1; j <= s2.length; j++) {
                const cost = s1.charAt(i - 1) === s2.charAt(j - 1) ? 0 : 1;
                cur[j] = Math.min(cur[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
            }
            const t = prev; prev = cur; cur = t;
        }
        return prev[s2.length];
    }
    function nearestName(value, names, max) {
        const norm = (t) => String(t || '').toLowerCase().replace(/\s+/g, ' ').trim();
        const v = norm(value);
        if (!v) return null;
        const limit = (typeof max === 'number') ? max : 2;
        let best = null;
        for (const raw of (names || [])) {
            const n = norm(raw);
            if (!n) continue;
            const d = editDistance(v, n);
            // An exact match is not a near miss — the caller wants "did you mean", and
            // distance 0 means the value IS that row and no creation is in question.
            if (d > limit) continue;
            if (!best || d < best.distance) best = { name: String(raw), distance: d };
            if (best.distance === 0) break;
        }
        return best;
    }
    // B-1b (2026-09-20) — the same hole B-1 closed, next door.
    // extractCategory validated with lenRange(3, 50): length and nothing else, exactly
    // what extractBudgetSource had. The preview can CREATE a missing category, and a
    // category is not an ordinary label — CategorySeeder defines exactly four, and the
    // dashboards and reports GROUP BY them. A junk fifth category does not merely sit
    // in a table; it splits every figure that counts projects by type.
    // B-2 already shields the worst route (a SCANNED document can no longer create
    // anything, and a human now types the name), so this is the remaining layer rather
    // than the only one.
    // HOW IT DIFFERS FROM asSourceName: a funding source is often an acronym and can
    // legitimately open on a digit ("20% Development Fund"); a CATEGORY is an ordinary
    // English noun phrase — New Construction, Renovation, Repair, Maintenance. So this
    // opens on a letter, takes no percent sign, and allows fewer words.
    // ⚠ THE STRONGER FIX IS STILL NOT THIS ONE. With only four valid values, matching
    // the extracted text against the live list (nearestName already exists for it) beats
    // any shape test — but the list lives in the DB and the core is pure, so it would
    // have to be plumbed through parseProjectInfo's opts. Noted, not done.
    // Called by: extractCategory().
    function asCategoryName(v) {
        const t = cleanValue(v || '').replace(/\s+/g, ' ').trim();
        if (t.length < 3 || t.length > 50) return null;
        // No pipe: that is a table cell boundary, as B-4 found for the contractor.
        if (!/^[A-Za-z0-9 .,&()\-\/']+$/.test(t)) return null;
        if (!/^[A-Za-z]/.test(t) || !/[A-Za-z0-9).]$/.test(t)) return null;
        if (!/[A-Za-z]{2}/.test(t)) return null;
        const words = t.split(' ').filter(Boolean);
        if (words.length > 5) return null;
        if (SOURCE_STOP_WORDS.has(words[0].toLowerCase().replace(/[^a-z]/g, ''))) return null;
        const compact = t.replace(/\s/g, '');
        const letters = (compact.match(/[A-Za-z]/g) || []).length;
        if (letters / compact.length < 0.6) return null;
        return t;
    }
    // B-3 / B-5 (2026-09-20) — a value that ran into its neighbours.
    // THREE SYMPTOMS, ONE CAUSE. A scanned page has no columns, only a picture of them,
    // so OCR reads a printed ROW as one line and whatever sat to the right is glued on:
    //   title       "] Rehabilitation/Refurbishment of Canteen..."  (a leading artifact)
    //   location    "...Polytechnic Colleges Date: 05142005"       (the next column)
    //   contractor  "i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom"  (both ends)
    // B-4 caught the version of this that carries a '|'. These carry only a space, so
    // the cell boundary is invisible and something else has to mark it.
    //
    // stripEdgeDebris: a one-character token at either end that is not a word is a
    // speck, a bracket, a margin mark — never part of a name. "i " and "]" go.
    // cutAtEmbeddedLabel: a "Word:" inside a value means the NEXT FIELD started there.
    //   ⚠ SCOPED ON PURPOSE. This is applied to LOCATION and CONTRACTOR, where a colon
    //   is never part of the value, and NOT to the title, where "Phase 2: Completion"
    //   is a real title and cutting it would destroy content. A rule that is right for
    //   one field is not automatically right for its neighbour.
    // Called by: extractLocation(), extractContractor(), extractTitle() (debris only).
    function stripEdgeDebris(v) {
        let t = String(v || '').trim();
        // Leading: a lone non-word character, or a single letter followed by a space and
        // then a real word (the OCR speck that reads as "i" or "l").
        t = t.replace(/^[^A-Za-z0-9(]+/, '').trim();
        t = t.replace(/^[A-Za-z]\s+(?=[A-Z0-9])/, '').trim();
        // Trailing: punctuation and lone characters left behind by a cut.
        t = t.replace(/[^A-Za-z0-9).%]+$/, '').trim();
        return t;
    }
    function cutAtEmbeddedLabel(v) {
        const t = String(v || '').trim();
        // A label is the SINGLE word immediately before a colon, somewhere past the
        // start. The first one wins, because everything after it belongs to another field.
        // ⚠ ONE WORD, NOT TWO, and the difference is not cosmetic: allowing an optional
        // second word let the pattern swallow real content as part of the label —
        // "…& ENGINEERING NES :" cut at ENGINEERING and "…Colleges Date:" cut at
        // Colleges. Both lost a word of the answer. Matching the minimum is correct here.
        // RESIDUE, accepted: a genuinely two-word label ("Project Location:") leaves its
        // first word behind on the value. That is a stray word, not a lost one.
        const m = t.match(/\s+[A-Za-z][A-Za-z.]{1,14}\s*:/);
        if (!m || m.index <= 0) return t;
        return t.slice(0, m.index).trim();
    }
    const asDate = (v) => parseToInputDate(v);
    const asDays = (v) => parseDurationToDays(v);
    // Placeholder tokens mean "intentionally empty" (bundle "xxx" for no revision,
    // "none", "n/a", bare dashes). Shared by the single-match lookup (Step 1i-1: stop,
    // never slide) and the pooled collector below.
    // Called by: findLabeledValue(), collectBudgetCandidates().
    function isPlaceholderValue(t) {
        return /^(?:x{2,}|none|n\/?a+|nil|null|[-–—.]+|#+)$/i.test(cleanValue(t || ''));
    }
    // Glue split money tokens back together before parsing (Step 1e-2).
    // Scanned bundle PDFs often break "6,495,049.29" across a line/cell gap as
    // "6,495,0 49.29" or "6,495,049 .29" or "6, 495,049.29". Without gluing,
    // asMoney returns only the tail (e.g., 5049.29).
    // Called by: asMoney() on every money string.
    function glueMoneyFragments(s) {
        if (!s) return s;
        let out = String(s);
        // Step 1h-1: glue comma-space ONLY for thousands groups (exactly 3 digits).
        // "P 6, 495,049.29" → "P 6,495,049.29" still glues, but "December 9, 2025"
        // (4-digit year) is left alone — previously it glued to "9,2025" = 92025.
        out = out.replace(/,\s+(\d{3}(?!\d))/g, ',$1');
        // "6,495,0 49.29" → "6,495,049.29" (space between head and decimal tail)
        out = out.replace(/(\d)\s+(\d{2,3}\.\d{2}\b)/g, '$1$2');
        // "6,495,049 .29" → "6,495,049.29" (space before decimal)
        out = out.replace(/(\d)\s+\.(\d{2}\b)/g, '$1.$2');
        return out;
    }
    // Parse a money value. Prefers Php/P/₱-prefixed amounts to avoid bare 44/100 fractions.
    // Enhanced 2026-09-04: for lines like "PESOS & 44/100 (Php 32,588,531.44)" the first
    // number is 44 — we must prefer the Php amount, not the fraction.
    // Additional adjustment 2026-09-04: handle "(P 6,495,049.29)" single-P as Php alias (NET TOTAL P6,495,049.29) and (P 6,495,049.29).
    const asMoney = (v) => {
        let s = glueMoneyFragments(String(v));
        // N1 (was Step 1h-3, marker-scoped): thousand-dot strip now applies to EVERY
        // money parse, because both the pooled vote AND the single-match/banner path use
        // this one reader — the tail-vs-truth tie previously survived on the banner path.
        // Still narrow (digit-dot-exactly-3-digits + digit/comma behind), so version text
        // like "Section 1.495" never matches (no trailing digit/comma).
        // Guard: only when the string holds a SINGLE decimal group (".29" once). On
        // multi-figure lines ("6,495,049.29 100.00 ...") the glue may fuse neighbours
        // ("049.29100.00") and stripping would eat a real decimal dot — skip instead and
        // let the first-figure rule below take the head value.
        const decimalGroups = s.match(/\.\d{2}\b/g) || [];
        if (decimalGroups.length === 1 && /\d\.\d{3}(?=[\d,])/.test(s)) {
            s = s.replace(/(\d)\.(\d{3})(?=[\d,])/g, '$1$2');
        }
        // Prefer Php/P/₱-prefixed amount first (Php or single P in "(P 6,495,049.29)" context)
        const phpM = s.match(/(?:php|₱)\s*([\d,]+(?:\.\d{1,2})?)/i) || s.match(/\(\s*P\s*([\d,]+\.\d{2})\s*\)/i) || s.match(/\bP\s*([\d,]+\.\d{2})/i);
        if (phpM) {
            const num = parseFloat(phpM[1].replace(/,/g, ''));
            if (isFinite(num) && num > 1000) return num.toFixed(2);
        }
        const m = s.match(/([\d,]+(?:\.\d{1,2})?)/);
        if (!m) return null;
        const num = parseFloat(m[1].replace(/,/g, ''));
        // Ignore tiny fractions like 44 from 44/100 unless no better amount exists and value >100
        if (!isFinite(num) || num <= 0) return null;
        if (num < 1000 && !phpM) {
            // Check if there's a larger number later in the string (handles 6,495,049.29 vs 29)
            const allNums = [...s.matchAll(/([\d,]+(?:\.\d{1,2})?)/g)].map(x => parseFloat(x[1].replace(/,/g, ''))).filter(n => isFinite(n) && n > 1000);
            if (allNums.length > 0) return Math.max(...allNums).toFixed(2);
        }
        // Weak-find guard (list markers): a marker-less tiny value ("2. Fringe Benefits" → 2.00,
        // stray percents/page fragments) is never project-level money. Php/₱/pesos/amount-marked
        // figures carry their own context and are trusted (marked "(Php 50.00)" still fills; bare
        // P is deliberately NOT an exemption — P-adjacent fractions lie, per the 44/100 saga).
        // Lives here so BOTH tracks inherit it (single-match extractors + pooled collectors share
        // this one reader — same lesson as the N1 shared strip).
        if (num < 100 && !/(?:php|₱|pesos|amount)/i.test(s)) return null;
        return num.toFixed(2);
    };

    // Budget normalizer for label lookups (Step 1h-2 mirrored into the single-match path).
    // Same rule as the pooled collector: a date-like value without any money marker is
    // rejected, so "Revised Contract Amount" sliding into "March 9, 2025" yields null
    // (and the placeholder stop in findLabeledValue handles the "xxx" shape).
    // Called by: extractBudget() for its revised/final/generic label lookups.
    const asBudget = (t) => {
        if (!/(?:php|₱|\bP\b|pesos|amount)/i.test(t || '') && asDate(t)) return null;
        return asMoney(t);
    };

    // ─── FIELD EXTRACTORS ───────────────────────────────────────────────────

    // Project Title: label line (or next line) or an ALL-CAPS line that looks like a title.
    // Enhanced in Phase 5 S-curve exclusion + scanned contract 2026-09-04:
    // - Added standalone "Project:" (italic Project: Completion of … in WHEREAS clause).
    // - Demoted ALL-CAPS fallback when a Title-Case "Project:" line exists — contractor bold
    //   ALL-CAPS (CHRISTINA JOY…) should not steal the title.
    // - Greedy continuation 2026-09-04: if "Project: Completion of Three-Storey" is split
    //   across 2-3 lines (Academic Building, Buhi Campus on next line), join until
    //   "," or "hereinafter" or DESCRIPTION_NEXT_FIELD.
    function extractTitle(lines, text, extraTitleKeywords) {
        // Try greedy: find "Project:" line and join continuation lines
        const nonEmpty = (lines || []).map(l => String(l).trim()).filter(Boolean);
        // L3: learned title phrases widen continuation acceptance at the 3 join sites below
        // (hardcoded phrases still match first; learned is an OR fallback, never a replacement).
        const learnedTitleJoin = normalizeKeywordList(extraTitleKeywords);
        const titleJoinHit = (next) => {
            const s = String(next || '').toLowerCase();
            return TITLE_VALUE_KEYWORDS.some(kw => s.includes(kw)) ||
                (learnedTitleJoin.length > 0 && learnedTitleJoin.some(kw => s.includes(kw)));
        };
        // Cut a title where the location begins inline ("... - Savings located at ...").
        // The acceptance certificate writes title + location on one reflowed line.
        // Called by: contract-label scan + greedy joins below.
        const cutAtLocatedAt = (s) => String(s || '').split(/\s+located\s+at\b/i)[0].trim();
        // Step 1f-1: guarded "Contract:" title label (acceptance certificates say
        // "Contract: Construction of Four-Storey Academic Building (...) - Savings").
        // Guard: reject Contract Amount/Time/Price/Period/Duration/No./Number lines.
        // Returns the full title or null so the Project-label path below still runs.
        const scanContractLabelTitle = () => {
            for (let i = 0; i < nonEmpty.length; i++) {
                const line = nonEmpty[i];
                if (!/contract\s*:/i.test(line)) continue;
                if (/contract\s*(amount|time|price|period|duration|no\.?|number)/i.test(line)) continue;
                const m = line.match(/contract\s*:\s*(.*)/i);
                if (!m) continue;
                let value = cutAtLocatedAt(cleanValue(m[1] || ''));
                if (!value || value.length < 5) {
                    const nxt = cutAtLocatedAt(cleanValue(nonEmpty[i + 1] || ''));
                    if (nxt && nxt.length >= 5) value = nxt;
                }
                if (!value) continue;
                if (!/construction|building|renovation|rehabilitation|provision|completion|upgrading|repair|engineering/i.test(value.toLowerCase())) continue;
                // Short split title → join continuation lines (same rules as Project path).
                if (value.length < 60) {
                    const parts = [value];
                    for (let j = i + 1; j < nonEmpty.length && parts.join(' ').length < 200; j++) {
                        const next = nonEmpty[j];
                        if (/located\s+at\b/i.test(next)) {
                            const before = cutAtLocatedAt(next);
                            if (before && before.length >= 5) parts.push(before);
                            break;
                        }
                        if (DESCRIPTION_NEXT_FIELD.test(next)) break;
                        if (/hereinafter|to\s+be\s+completed\s+within|WITNESSETH|WHEREAS/i.test(next)) break;
                        const isAllCapsPart = next === next.toUpperCase() && /[A-Z]/.test(next) && next.length >= 5 && next.length <= 150;
                        if (/^(?:[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*,?)/.test(next) || titleJoinHit(next) || isAllCapsPart) {
                            parts.push(cutAtLocatedAt(next));
                            if (parts.join(' ').length >= 40) break;
                        } else if (parts.length > 0) break;
                    }
                    if (parts.length > 1) value = parts.join(' ').replace(/-\s+(?=[A-Za-z])/g, '-');
                }
                value = cutAtLocatedAt(value);
                if (/^oe\s+/i.test(value)) value = value.replace(/^oe\s+/i, '').trim();
                const normalized = lenRange(20, 200)(cleanValue(value));
                if (normalized) return normalized;
            }
            return null;
        };
        // Prefer the Contract-label title when it is fuller than the Project-label one
        // (acceptance page carries the "(College ...)-Savings" tail the bundle's
        // "Project name" block truncates). Falls through when absent/short.
        const contractTitle = scanContractLabelTitle();
        if (contractTitle) return contractTitle;
        for (let i = 0; i < nonEmpty.length; i++) {
            const line = nonEmpty[i];
            const m = line.match(/(?:project\s*title|name\s*of\s*project|project\s*name|title\s*of\s*project|project)\s*[:\-]\s*(.*)/i);
            if (!m) continue;
            // Step 1f-1: cut inline location tail ("... - Savings located at CSPC ...").
            let value = cutAtLocatedAt(cleanValue(m[1] || ''));
            // If same-line value empty or short (split title), try next lines greedily
            if (!value || value.length < 30) {
                const inline = value;
                // Try to build greedy title from next lines until stop condition
                const greedyParts = [];
                if (inline) greedyParts.push(inline);
                for (let j = i + 1; j < nonEmpty.length && greedyParts.join(' ').length < 200; j++) {
                    const next = nonEmpty[j];
                    // Stop at next field label or hereinafter / to be completed within
                    if (DESCRIPTION_NEXT_FIELD.test(next)) break;
                    if (/hereinafter|to\s+be\s+completed\s+within|WITNESSETH|WHEREAS/i.test(next)) {
                        // If next line contains hereinafter but also has title continuation before comma, take up to comma
                        const beforeHere = next.split(/,?\s*hereinafter/i)[0].trim();
                        if (beforeHere && beforeHere.length >= 5 && greedyParts.length < 2) {
                            greedyParts.push(beforeHere);
                        }
                        break;
                    }
                    // Greedy join: if next line looks like title continuation (Title Case, contains building etc.)
                    // Step 1e-3: also accept ALL-CAPS continuation ("ACADEMIC BUILDING (COLLEGE ...)-SAVINGS")
                    // which fails the Title-Case test and has no value-keyword phrase.
                    const isAllCapsTitlePart = (t) => t === t.toUpperCase() && /[A-Z]/.test(t) && t.length >= 5 && t.length <= 150 && /BUILDING|CAMPUS|SAVINGS|COLLEGE|SCIENCES|ACADEMIC|CONSTRUCTION|COMPLETION/i.test(t);
                    if (/^(?:[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*,?)/.test(next) || titleJoinHit(next) || isAllCapsTitlePart(next)) {
                        // Step 1f-1: stop the title where the location starts inline.
                        if (/located\s+at\b/i.test(next)) {
                            const before = cutAtLocatedAt(next);
                            if (before && before.length >= 5) greedyParts.push(before);
                            break;
                        }
                        greedyParts.push(next);
                        // If next line ends with comma, title continues; if ends without comma and next line is WHEREAS, stop
                        if (next.endsWith(',')) continue;
                        // Check if we've got a plausible title length
                        const joined = greedyParts.join(' ');
                        if (joined.length >= 20 && /building|campus|completion|provision|rehabilitation/i.test(joined.toLowerCase())) {
                            // Peek ahead: if next+1 is WHEREAS, stop after this
                            const peek = nonEmpty[j + 1] || '';
                            if (/WHEREAS|WITNESSETH/i.test(peek)) break;
                        }
                    } else if (greedyParts.length > 0) {
                        break;
                    }
                }
                if (greedyParts.length > 0) {
                    // Step 1e-3: glue hyphen line-breaks ("FOUR- / STOREY" → "FOUR-STOREY").
                    let joined = cleanValue(greedyParts.join(' ').replace(/-\s+(?=[A-Za-z])/g, '-'));
                    if (/^oe\s+/i.test(joined)) joined = joined.replace(/^oe\s+/i, '').trim();
                    const normalized = lenRange(5, 200)(joined);
                    if (normalized) return normalized;
                }
            } else {
                // Same-line has value but might be truncated (e.g., "Completion of Three-Storey" only) — greedy expand if short
                if (value.length < 40) {
                    const greedyParts = [value];
                    for (let j = i + 1; j < nonEmpty.length && greedyParts.join(' ').length < 200; j++) {
                        const next = nonEmpty[j];
                        if (DESCRIPTION_NEXT_FIELD.test(next)) break;
                        if (/hereinafter/i.test(next)) {
                            const before = next.split(/,?\s*hereinafter/i)[0].trim();
                            if (before) greedyParts.push(before);
                            break;
                        }
                        // Step 1e-3: accept ALL-CAPS continuation here too (second loop
                        // pushes any 5-150 char line, which already covers ALL-CAPS).
                        if (next.length >= 5 && next.length <= 150) {
                            greedyParts.push(next);
                            const peek = nonEmpty[j + 1] || '';
                            if (/WHEREAS/i.test(peek) || !titleJoinHit(next) && greedyParts.length >= 2) break;
                            if (greedyParts.join(' ').length >= 40) break;
                        }
                    }
                    if (greedyParts.length > 1) {
                        let joined = cleanValue(greedyParts.join(' ').replace(/-\s+(?=[A-Za-z])/g, '-'));
                        if (/^oe\s+/i.test(joined)) joined = joined.replace(/^oe\s+/i, '').trim();
                        const normalized = lenRange(5, 200)(joined);
                        if (normalized && normalized.length > value.length) return normalized;
                    }
                }
                let out = value;
                if (/^oe\s+/i.test(out)) out = out.replace(/^oe\s+/i, '').trim();
                const normalized = lenRange(5, 200)(out);
                if (normalized) return normalized;
            }
        }

        // OCR artifact strip: "oe Completion..." where "the" misread
        const stripOe = (v) => /^oe\s+/i.test(v) ? v.replace(/^oe\s+/i, '').trim() : v;
        const labeled = findLabeledValue(lines, [
            /(?:project\s*title|name\s*of\s*project|project\s*name)\s*[:\-]\s*(.*)/i,
            /(?:title\s*of\s*project)\s*[:\-]\s*(.*)/i,
            /(?:project)\s*[:\-]\s*(.*)/i,
        ], (v) => { const c = lenRange(5, 200)(v); return c ? stripOe(c) : null; });
        if (labeled) return stripOe(labeled);

        // Fallback: an ALL-CAPS line that looks like a project title
        const candidate = (lines || []).find(line => (
            line === line.toUpperCase() &&
            line.length >= 15 &&
            line.length <= 150 &&
            /construction|renovation|repair|installation|upgrading|improvement|building/i.test(line)
        ));
        return candidate ? toTitleCase(candidate) : null;
    }

    // Location / Project Site — project location only, NOT contractor address.
    // Phase 5 fix 2026-09-04: removed generic "address" to avoid Contractor Address false-positive.
    // Keep only project-specific: location, project site/site address, project location, barangay/brgy.
    // CSPC campus value is often just "CSPC" or "CSPC - Buhi Campus" (geotag deferred).
    function extractLocation(lines) {
        // B-3: a scanned page reads a printed ROW as one line, so the column to the right
        // arrives glued on ("…Polytechnic Colleges Date: 05142005"). Cut where the next
        // field starts, then drop any speck left at either end, THEN validate length.
        return findLabeledValue(lines, [
            /(?:location|project\s*site|site\s*address|project\s*location|barangay|brgy\.?|project\s*address)\s*[:\-]\s*(.*)/i,
        ], (v) => lenRange(3, 200)(stripEdgeDebris(cutAtEmbeddedLabel(v))));
    }

    // Labels that start another field — used to terminate a prose description
    // block so it doesn't swallow the rest of the document (single newlines
    // between lines must NOT be treated as a description boundary).
    // NOTE: Expanded in Phase 5 to include ABC standalone, budget source,
    //       category, winning bidder, and NTP variations so description
    //       stops before those fields. Location generic "address" removed.
    // Step 1e: also include Original/Revised Contract Amount, Original Contract
    // Time / Contract Time, and Date of Effectivity so description stops there too.
    const DESCRIPTION_NEXT_FIELD = /^\s*(?:approved\s*budget\s*(?:for\s*the\s*contract)?(?:\s*\(abc\))?|abc|original\s*contract\s*amount|revised\s*contract\s*amount|contract\s*amount|project\s*cost|total\s*cost|budget|contract\s*price|source\s*of\s*funds?|funding\s*source|budget\s*source|fund\s*source|funding\s*agency|source\s*of\s*financing|category|project\s*category|project\s*type|type\s*of\s*project|nature\s*of\s*project|classification|contractor|awarded\s*to|winning\s*bidder|proponent|company\s*name|firm\s*name|contractor\s*name|start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|ntp|date\s*started|contract\s*start|date\s*of\s*effectivity(?:\s*of\s*contract)?|effectivity|end\s*date|completion\s*date|target\s*completion|contract\s*end|date\s*of\s*completion|expected\s*completion|duration|contract\s*period|contract\s*duration|original\s*contract\s*time|contract\s*time|no\.?\s*of\s*(?:calendar\s*)?days|number\s*of\s*days|calendar\s*days?|working\s*days?|location|project\s*site|site\s*address|project\s*location|barangay|brgy\.?|project\s*address|project\s*title|name\s*of\s*project|project\s*name|title\s*of\s*project|workers?|manpower|headcount)\s*[:\-]/i;

    // Description / Scope of Work — prose, so collect the label line plus every
    // following line until another known field label starts (or the text ends).
    function extractDescription(text) {
        const lines = buildLinesFromText(text);

        for (let i = 0; i < lines.length; i++) {
            const m = lines[i].match(
                /(?:scope\s*of\s*work|project\s*description|description|objectives?|work\s*description)\s*[:\-]\s*(.*)/i
            );
            if (!m) continue;

            const parts = [];
            if (m[1] && m[1].trim()) parts.push(m[1].trim());

            for (let j = i + 1; j < lines.length; j++) {
                if (DESCRIPTION_NEXT_FIELD.test(lines[j])) break;
                parts.push(lines[j]);
            }

            const value = cleanValue(parts.join(' '));
            if (value && value.length >= 20) {
                return value.substring(0, 500);
            }
        }
        return null;
    }

    // L3-TEST TEMP BLINDING — ⬛ LIFTED 2026-09-18 (pilot readiness, item N1).
    // WHAT IT DID: with this ON, extractABC()'s hardcoded patterns were replaced by an
    // EMPTY list, the corroborated Total/Approved-Budget-Amount reassignment (F-B1) was
    // skipped, the ABC words-shape fallback was skipped, and collectAbcCandidates()
    // (ABC-pooling) slept. A clean selectable file therefore MISSED on ABC on purpose,
    // so the teach -> approve -> fills-free learning-loop round trip could be demonstrated
    // end to end on a document that would otherwise have read straight away.
    // WHY IT IS OFF NOW: the pilot hands the normal-doc parser to PMU staff with real
    // scanned contracts. Blinded, a document that plainly states "Approved Budget for the
    // Contract (ABC): PHP X" comes back with an empty ABC box, which reads as a defect.
    // The demo value no longer outweighs that. See textfiles/pilot_testing_readiness_plan.md
    // section 5, item N1, and the learning-loop plan section 6.
    // TO RE-BLIND FOR A DEMO: set this back to true and expect two INTENDED JS failures
    // (F-B1 conditional, L3 hardcoded-ABC) plus the sleep-asserting fixtures to flip back.
    const L3_TEST_BLIND_ABC = false;

    // Approved Budget / Contract Amount — Contract Price (winning bid after discount).
    // NOTE: Phase 5 keeps this as Contract Price (contract_price). Standalone ABC is separate.
    // Enhanced 2026-09-04: prioritize "final Contract Amount is … (Php X)" over generic budget,
    // and handle "WHEREAS, after applying 3% discount, the final Contract Amount is …".
    function extractBudget(lines) {
        // Step 1e: Revised Contract Amount wins over Original (user decision 11).
        // Check revised first — a completed project with a change order should
        // auto-fill the current amount, not the original bid.
        const revised = findLabeledValue(lines, [
            /(?:revised\s*contract\s*amount)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ], asBudget);
        if (revised) return revised;

        // First try: final/after-discount contract amount context anchor (scanned contract Image 3)
        const finalAmount = findLabeledValue(lines, [
            /(?:final\s*contract\s*amount\s*is)\s*(.*)/i,
            /(?:after\s+applying.*3%\s*discount.*contract\s*amount\s*is)\s*(.*)/i,
            /(?:contract\s*amount\s*is)\s*(.*)/i,
        ], asBudget);
        if (finalAmount) return finalAmount;

        // F-B1 conditional: a bare "budget [Amount]" line means the ABC ceiling — but ONLY
        // when the file also carries an Original/Revised figure (corroborated reassignment,
        // extractABC claims it). Alone, such lines keep today's price behavior.
        const linesNorm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        const hasOrigRevisedBudget = linesNorm.some(l => /original\s*contract\s*amount|revised\s*contract\s*amount/i.test(l));
        const generic = findLabeledValue(lines, [
            hasOrigRevisedBudget
                ? /(?:original\s*contract\s*amount|contract\s*amount|contract\s*price|project\s*cost|total\s*cost|budget(?!\s*amount))\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i
                : /(?:original\s*contract\s*amount|contract\s*amount|contract\s*price|project\s*cost|total\s*cost|budget)\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
            /(?:approved\s*budget\s*for\s*the\s*contract)\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ], asBudget);
        if (generic) return generic;

        // Fallback: PESOS + Php/P/n pattern without clear label (e.g., "SIX MILLION ... PESOS & 29/100 (Php 6,495,049.29)" or "PESOS & 29/100 (P 6,495,049.29)" or "(n 6,495,049.29)" box)
        // Use values themselves as keyword — any line with PESOS + (Php/P/n amount) on same 1-2 lines only (user: yes n with PESOS only).
        // Handles split across lines: "PESOS & 29/100" on one line, "(Php 6,495,049.29)" or "(P 6,495,049.29)" or "(n 6,495,049.29)" box on next.
        const norm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        for (let i = 0; i < norm.length; i++) {
            const line = norm[i];
            if (/pesos/i.test(line)) {
                const combined = [line, norm[i+1] || '', norm[i+2] || ''].join(' ');
                // n alias for box: (n 6,495,049.29) only when PESOS present on same window (user: yes)
                if (/\(p(?:hp)?\s*[\d,]+\.\d{2}\)/i.test(combined) || /\(n\s*[\d,]+\.\d{2}\)/i.test(combined)) {
                    const m = asMoney(combined);
                    if (m) return m;
                }
                if (/\(p(?:hp)?\s*[\d,]+\.\d{2}\)/i.test(line) || /\(n\s*[\d,]+\.\d{2}\)/i.test(line)) {
                    const m = asMoney(line);
                    if (m) return m;
                }
            }
        }
        // Second fallback: "the amount of ..." clause contains Php amount (also handle split)
        for (let i = 0; i < norm.length; i++) {
            const line = norm[i];
            if (/the\s+amount\s+of/i.test(line)) {
                const combined = [line, norm[i+1] || '', norm[i+2] || ''].join(' ');
                const m2 = asMoney(combined);
                if (m2) return m2;
            }
        }
        // Third fallback: any large Php/P amount as contract price when no label at all (single contract file assumption)
        // Default to contract amount per user Q2: PESOS word not required if Php/P >1M exists
        // Handles NET TOTAL P6,495,049.29 (single P, no Php) and NET TOTAL ... (Php X)
        for (let i = 0; i < norm.length; i++) {
            const line = norm[i];
            let combined = [line, norm[i+1] || ''].join(' ');
            // Step 1h-3: same period-thousands fix as the collector (OCR "6.495,049.29").
            // Single-decimal guard (see asMoney): multi-figure windows keep their dots.
            if (/(?:php|₱)/i.test(combined) && ((combined.match(/\.\d{2}\b/g) || []).length === 1)) combined = combined.replace(/(\d)\.(\d{3})(?=[\d,])/g, '$1$2');
            // Look for "(Php X)" or "(P X)" or "NET TOTAL P X" pattern even without PESOS
            const phpOnly = combined.match(/\(p(?:hp)?\s*([\d,]+\.\d{2})\)/i) || combined.match(/NET\s*TOTAL\s*(?:P\s*)?([\d,]+\.\d{2})/i) || combined.match(/\bP\s*([\d,]+\.\d{2})/i);
            if (phpOnly) {
                const m3 = asMoney(combined);
                if (m3 && parseFloat(m3) > 100000) return m3; // >100k to avoid 44/100 tiny
            }
        }
        // Fourth fallback: budget word amount when Php numeric absent (OCR garbled Php) — use PESOS words
        // Only if none of ABC keywords exist (per user Q1: default to contract amount but only if ABC absent)
        // Strict 2026-09-04 per user: require ALL CAPS bold style + "& 29/100" cents + PESOS last word (as both
        // Buhi and CHS have "SIX MILLION … PESOS & 29/100" ALL CAPS, not Title-Case)
        const hasAbcLabel = norm.some(l => /^\s*abc\s*[:\-]/i.test(l) || /approved\s*budget.*contract.*\(abc\)/i.test(l));
        if (!hasAbcLabel) {
            for (let i = 0; i < norm.length; i++) {
                const line = norm[i];
                // Strict: ALL CAPS + PESOS last word + million/thousand + & \d+/100 required (user: yes required)
                if (!/million|thousand/i.test(line) || !/pesos/i.test(line)) continue;
                // & 29/100 must be on same 2-line window (user: yes required)
                const combined2 = [line, norm[i+1] || ''].join(' ');
                if (!/&\s*\d+\s*\/\s*100/i.test(combined2)) continue;
                // ALL CAPS check: the PESOS line must be uppercase (as both files have SIX MILLION … PESOS)
                const pesosLine = /pesos/i.test(line) ? line : norm[i+1] || '';
                const isAllCaps = pesosLine === pesosLine.toUpperCase() && /[A-Z]/.test(pesosLine);
                if (!isAllCaps) continue;
                const mW = combined2.match(/(.+?)\s+pesos/i);
                if (mW) {
                    const wordVal = wordsToNumber(mW[1]);
                    if (wordVal && wordVal > 1000) {
                        const frac = combined2.match(/&\s*(\d+)\s*\/\s*100/i);
                        const cents = frac ? parseInt(frac[1],10)/100 : 0;
                        const total = wordVal + cents;
                        if (total > 1000) return total.toFixed(2);
                    }
                }
            }
        }
        return null;
    }

    // Written-cents guard (N1a, 2026-09-18). A funds-certificate line spells the amount in
    // words and ends "… PESOS & 59/100" — the "59/100" is the CENTS, not the amount. asMoney
    // happily reads 59 out of it, so the un-blinded ABC label patterns were returning P59.00
    // for a P4,834,297.59 certificate AND short-circuiting the words-shape rule that reads it
    // correctly. True only when EVERY digit in the text belongs to an NN/100 cents marker, so
    // a real figure ("Php 4,834,297.59") is untouched.
    // Called by: extractABC() validator; collectAbcCandidates() before asMoney().
    function isCentsFractionOnly(text) {
        const s = String(text || '');
        if (!/\d/.test(s)) return false;
        const stripped = s.replace(/\b\d{1,2}\s*\/\s*100\b/g, ' ');
        return !/\d/.test(stripped);
    }

    // Approved Budget for the Contract (ABC) — standalone ABC label.
    // Phase 5 NEW: distinguishes ABC ceiling from Contract Price (savings = ABC - price).
    // Called by: parseProjectInfo() → returns abc field; budget uses extractBudget().
    // Labels: "ABC:" , "Approved Budget for the Contract (ABC):" , "ABC (PHP)".
    // F-B1: "Total/Approved Budget Amount" lines ALSO feed ABC — but ONLY when the same
    // file carries an Original/Revised Contract Amount (corroborated reassignment). Alone,
    // such lines keep today's price behavior so boxes never empty on files that mean price.
    function extractABC(lines) {
        const norm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        const hasOrigRevised = norm.some(l => /original\s*contract\s*amount|revised\s*contract\s*amount/i.test(l));
        const patterns = L3_TEST_BLIND_ABC ? [] : [
            /(?:approved\s*budget\s*for\s*the\s*contract\s*(?:\(abc\))?|abc)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ];
        if (hasOrigRevised && !L3_TEST_BLIND_ABC) {
            patterns.push(/(?:total\s*budget\s*amount|approved\s*budget|budget\s*amount)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i);
        }
        // N1a: reject a "59/100" cents marker before it can pose as the amount and
        // short-circuit the words-shape rule below.
        const direct = findLabeledValue(lines, patterns, (t) => (isCentsFractionOnly(t) ? null : asMoney(t)));
        if (direct) return direct;
        // Layer-2 rule A (ABC words-shape): PESOS-words fallback for funds-cert lines when the
        // Php numeric is absent (garbled) — same wordsToNumber machinery as extractBudget's fourth
        // fallback, but keyed to ABC context (approved-budget / funds-availability neighborhood).
        // Same strict shape (ALL CAPS + PESOS + million/thousand + & /100 on a 2-line window).
        // Blinded-gated with everything else here so the clean-file demo still misses honestly.
        // Called by: parseProjectInfo() → abc field (single-match track).
        if (!L3_TEST_BLIND_ABC) {
            const wordsVal = readAbcWordsAmount(norm);
            if (wordsVal) return wordsVal;
        }
        return null;
    }

    // Shared ABC words-shape reader (single-match + pooled collector use one rule, so both
    // tracks behave identically — the 92025 saga taught us divergent tracks confuse everyone).
    // Strict: ABC context + ALL CAPS + PESOS last word + million/thousand + & cents/100.
    // Returns the "DDDDDDD.CC" string or null. Pure (no DOM).
    // Called by: extractABC() fallback; collectAbcCandidates() 'words-shape' pattern.
    function readAbcWordsAmount(norm) {
        for (let i = 0; i < norm.length; i++) {
            const v = abcWordsAtWindow(norm[i], norm[i + 1] || '');
            if (v) return v;
        }
        return null;
    }

    // One 2-line window of the ABC words-shape rule. Returns the amount string or null.
    // Strict: ABC context + an ALL-CAPS amount run (MILLION/THOUSAND … PESOS) + & cents/100.
    // Only the amount run itself must be caps — surrounding prose may be any case (funds-cert
    // lines read "with an approved budget … of FOUR MILLION … PESOS & 59/100").
    // Pure (no DOM). Called by: readAbcWordsAmount(); collectAbcCandidates().
    function abcWordsAtWindow(line, nextLine) {
        const combined = [line, nextLine || ''].join(' ');
        // ABC context required: approved-budget phrasing or funds-availability phrasing.
        if (!/approved\s*budget\s*for\s*the\s*contract|availability\s*of\s*funds/i.test(combined)) return null;
        // & 59/100 must be on the same 2-line window.
        if (!/&\s*\d+\s*\/\s*100/i.test(combined)) return null;
        // ALL-CAPS amount run ending in PESOS (hyphens tolerated: "NINETY-SEVEN PESOS").
        const mCaps = combined.match(/([A-Z][A-Z\s\-]*\b(?:MILLION|THOUSAND)\b[A-Z\s\-]*PESOS)/);
        if (!mCaps) return null;
        const wordVal = wordsToNumber(mCaps[1]);
        if (wordVal && wordVal > 1000) {
            const frac = combined.match(/&\s*(\d+)\s*\/\s*100/i);
            const cents = frac ? parseInt(frac[1], 10) / 100 : 0;
            const total = wordVal + cents;
            if (total > 1000) return total.toFixed(2);
        }
        return null;
    }

    // Budget Source / Funding Source — new in Phase 5.
    // Maps to budget_sources table (9 seeded: GAA, CHED, DPWH, LGU, GAD...).
    // Table lookup is done in document-parser.js (Option A: admin-only warning).
    // B-1: validated by SHAPE (asSourceName) rather than by length. The preview offers
    // an unmatched value for CREATION as a budget_sources row, so a misread accepted
    // here becomes a permanent junk record — see asSourceName for the full reasoning.
    function extractBudgetSource(lines) {
        return findLabeledValue(lines, [
            /(?:source\s*of\s*funds?|funding\s*source|budget\s*source|fund\s*source|funding\s*agency|source\s*of\s*financing)\s*[:\-]\s*(.*)/i,
        ], asSourceName);
    }

    // Category / Project Type — new in Phase 5.
    // Maps to categories table (4 seeded: New Construction, Renovation, Repair, Maintenance).
    // B-1b: validated by SHAPE (asCategoryName) rather than by length, because the
    // preview can create a missing category and the reports group by this column.
    function extractCategory(lines) {
        return findLabeledValue(lines, [
            /(?:category|project\s*category|project\s*type|type\s*of\s*project|nature\s*of\s*project|classification)\s*[:\-]\s*(.*)/i,
        ], asCategoryName);
    }

    // Contract / Project Start Date.
    // Enhanced in Phase 5: added NTP variations + next-two-lines.
    function extractStartDate(lines) {
        return findLabeledValue(lines, [
            /(?:start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|ntp|date\s*started|contract\s*start|date\s*of\s*effectivity(?:\s*of\s*(?:the\s*)?contract)?|effectivity)\s*[:\-]?\s*(.*)/i,
        ], asDate);
    }

    // Contract / Project End Date.
    function extractEndDate(lines) {
        return findLabeledValue(lines, [
            /(?:end\s*date|completion\s*date|target\s*completion|contract\s*end|date\s*of\s*completion|expected\s*completion)\s*[:\-]?\s*(.*)/i,
        ], asDate);
    }

    // Contract Duration in days.
    // Enhanced 2026-09-04: also handles "within NINETY (90) calendar days" without explicit Duration: label,
    // and prefers word-mapped 90 over stray 3% number. Uses within…calendar days clause.
    // Additional adjustment 2026-09-04: handle split across lines (NINETY on one line, "calendar days" on next)
    // and fuzzy OCR "NlNETY" (I→l) with Levenshtein ≤1.
    function extractDuration(lines) {
        const normLines = (lines || []).map(l => String(l).trim()).filter(Boolean);
        // D-total: "Total Contract Time" (extensions included — the binding duration) outranks
        // "Original Contract Time" when present; Original stays the fallback. Amber note still
        // offers both on genuine close calls (handled by the pooled disagree check).
        const totalFirst = findLabeledValue(normLines, [
            /(?:total\s*contract\s*time)\s*[:\-]?\s*(.*)/i,
        ], asDays);
        if (totalFirst) return totalFirst;
        // First try labeled patterns (Duration: etc.)
        // Step 1e: also accept "Original Contract Time" / "Contract Time" (CHS bundle).
        let labeled = findLabeledValue(normLines, [
            /(?:duration|contract\s*period|contract\s*duration|original\s*contract\s*time|contract\s*time|no\.?\s*of\s*(?:calendar\s*)?days|number\s*of\s*days|calendar\s*days?|working\s*days?)\s*[:\-]?\s*(.*)/i,
        ], asDays);
        if (labeled) return labeled;

        // Helper: fuzzy contains for OCR typo (NINETY vs NlNETY) — Levenshtein ≤1 for 6+ char words
        const fuzzyIncludes = (hay, needle) => {
            const h = hay.toLowerCase();
            const n = needle.toLowerCase();
            if (h.includes(n)) return true;
            if (n.length < 6) return false;
            // Check each word in hay for distance 1 to needle
            for (const w of h.split(/\s+/)) {
                if (Math.abs(w.length - n.length) > 1) continue;
                let diff = 0;
                const len = Math.max(w.length, n.length);
                for (let i = 0; i < len; i++) if (w[i] !== n[i]) diff++;
                if (diff <= 1) return true;
            }
            return false;
        };

        // Fallback: scan for "within ... calendar days" clause, also handling split across 2 lines
        for (let i = 0; i < normLines.length; i++) {
            const line = normLines[i];
            // Try single line "within NINETY (90) calendar days"
            let m = String(line).match(/within\s+(.+?calendar\s*days.*)/i);
            if (m) {
                const candidate = asDays(m[1]);
                if (candidate) return candidate;
                const candidateFull = asDays(m[0]);
                if (candidateFull) return candidateFull;
            }
            // Try combined with next line: "within NINETY (90)" + "calendar days upon receipt..."
            const combined2 = [line, normLines[i+1] || ''].join(' ');
            m = combined2.match(/within\s+(.+?calendar\s*days.*)/i);
            if (m) {
                const c = asDays(m[1]);
                if (c) return c;
            }
            // Fuzzy: line has NINETY-like word but OCR "NlNETY" — still treat as duration
            if (fuzzyIncludes(line, 'ninety') && /calendar\s*days/i.test(line + ' ' + (normLines[i+1]||''))) {
                const c = asDays(line + ' ' + (normLines[i+1]||''));
                if (c) return c;
            }
        }
        // Second fallback: any line with "calendar days" + a number/word near it (also check combined)
        for (let i = 0; i < normLines.length; i++) {
            const line = normLines[i];
            if (/calendar\s*days/i.test(line)) {
                const c = asDays(line);
                if (c) return c;
                // Try combined with previous line that has the number word (split case)
                const prev = normLines[i-1] || '';
                if (prev && /ninety|eighty|seventy|sixty|fifty|forty|thirty|twenty|hundred/i.test(prev.toLowerCase())) {
                    const combined = prev + ' ' + line;
                    const c2 = asDays(combined);
                    if (c2) return c2;
                }
            }
        }
        return null;
    }

    // Contractor Name (used for fuzzy-matching the dropdown).
    // Enhanced in Phase 5: added winning bidder + contractor name synonyms.
    // Greedy cut 2026-09-04: strip " (hereinafter called the ...)" tail so preview shows clean company name.
    function extractContractor(lines) {
        let raw = findLabeledValue(lines, [
            /(?:contractor|contractor\s*name|awarded\s*to|winning\s*bidder|proponent|company\s*name|firm\s*name)\s*[:\-]\s*(.*)/i,
        ], asCompanyName);
        if (!raw) return null;
        // Try labeled with next-two-lines already handled by findLabeledValue, but also handle inline wrap:
        // If contractor name is bold ALL-CAPS across 2 lines, findLabeledValue already joins next line.
        // Now strip parenthetical " (hereinafter called the ..." tail (Image 2 bold/italic).
        raw = String(raw).trim();
        // Cut at " (hereinafter" or " (hereinafter called the"
        const cut = raw.split(/\s*\(hereinafter.*/i)[0].trim();
        // Also cut at " — hereinafter" etc. and trailing " (hereinafter called the "CONTRACTOR")"
        const cleaned = cut.replace(/\s*\(.*$/, '').trim();
        // B-4: try the paren-stripped name, then the uncut form — the second chance is
        // for a name that stripping made too short. If NEITHER is a company name the
        // field stays empty: the old `|| cleanValue(cut)` returned the raw text whatever
        // the validator said, which meant a rejected row came back regardless.
        // B-5: strip the OCR speck and cut anything that ran in from the next column
        // BEFORE validating, so a recoverable name is recovered rather than refused.
        const tidy = (x) => stripEdgeDebris(cutAtEmbeddedLabel(stripEdgeDebris(x || '')));
        return asCompanyName(tidy(cleaned || cut)) || asCompanyName(tidy(cut)) || null;
    }

    // ─── VALUE-SIDE KEYWORD DICTIONARIES (HARDCODED Phase 5) ───────────────────
    // Used ONLY as fallback when NO label matched — never replaces label search.
    // Hardcoded per user 2026-09-03; flagged for future DB-driven parser_keywords.

    // ─── L3: LEARNED VOCABULARY (parser_keywords, live rows) ─────────────────────
    // The learning loop harvests (label-phrase → field) pairs from user corrections; staff/admin
    // approve them live. The reader consults them as LOWEST-priority fallback only — every
    // hardcoded rule runs first, so an empty table reproduces today's behavior exactly.
    // Two consultation shapes (both substring, case-insensitive):
    //   value-side: title/contractor phrases widen isTitle/ContractorValueCandidate + the 3
    //     greedy-join sites in extractTitle. Learned-value matches keep LENGTH bounds only —
    //     the heuristic shape gates are skipped because a reviewed vocabulary outranks heuristics.
    //   label-side: per-field phrases become extra findLabeledValue patterns with the field's own
    //     normalizer (this is what lets a loop-discovered "Estimated Project Outlay" fill ABC).
    // Called by: parseProjectInfo() fallback passes; wrapper loads pairs via /all?status=live.
    // Pure (no DOM/fetch) — lists arrive via opts.learned = { labels: { field: [phrase] } }.
    function normalizeKeywordList(list) {
        const out = [];
        for (const k of (list || [])) {
            const s = String(k || '').toLowerCase().trim();
            if (s && out.indexOf(s) === -1) out.push(s);
            if (out.length >= 200) break;
        }
        return out;
    }

    // Title value phrases — 15 hardcoded per user list.
    const TITLE_VALUE_KEYWORDS = [
        'provision of', 'completion and provision of',
        'upgrading/renovation of', 'upgrading', 'renovation',
        'embankment works', 'rehabilitation of', 'refurbishment of',
        'rehabilitation/refurbishment of', 'rehabilitation/rectification of',
        'enhancement of', 'analysis, design, and retrofitting',
        'analysis', 'retrofitting', 'restructuring/rehabilitation',
        'restructuring', 'repair', 'design and build of', 'construction of',
        'completion of'
    ];

    // Contractor suffixes inside the name itself — 6+ per user list.
    const CONTRACTOR_VALUE_KEYWORDS = [
        'engineering enterprises', 'construction and supply',
        'construction & supply', 'construction and development corporation',
        'construction and engineering services', 'construction',
        'builders and construction supplies', 'builders', 'general contractor',
        'trading and construction', 'development corporation'
    ];

    // Check if a line looks like a title based purely on value keywords.
    // Called by: parseProjectInfo() fallback when extractTitle labeled returns null.
    // Returns the cleaned line or null. Confidence lower than label (60 vs 95).
    // Also strips OCR "oe " artifact where "the" misread as "oe" at line start.
    // L3: extraKeywords (learned phrases) are tried only when no hardcoded phrase hits,
    // and learned hits skip the construction-word gate (length bounds stay) — reviewed
    // vocabulary outranks heuristics.
    function isTitleValueCandidate(line, extraKeywords) {
        let raw = String(line || '').trim();
        // OCR artifact: "oe Completion..." → strip leading "oe "
        if (/^oe\s+/i.test(raw)) raw = raw.replace(/^oe\s+/i, '').trim();
        const s = raw.toLowerCase().trim();
        if (s.length < 15 || s.length > 200) return null;
        if (TITLE_VALUE_KEYWORDS.some(kw => s.includes(kw))) {
            // Must be Title Case-ish or ALL-CAPS-ish and contain a construction word
            if (!/construction|renovation|repair|installation|upgrading|improvement|building|embankment|rehabilitation|refurbishment|retrofitting|restructuring|provision|completion|enhancement|analysis/i.test(s)) return null;
            return cleanValue(raw);
        }
        const extra = normalizeKeywordList(extraKeywords);
        if (extra.length && extra.some(kw => s.includes(kw))) return cleanValue(raw);
        return null;
    }

    // Check if a line looks like a contractor name based on suffix keywords.
    // Returns cleaned line or null. Strips parenthetical tail like " (hereinafter..."
    // L3: same learned-fallback shape as titles (length bounds stay, suffix gate skipped).
    function isContractorValueCandidate(line, extraKeywords) {
        let raw = String(line || '').trim();
        // Strip parenthetical tail for clean name
        raw = raw.split(/\s*\(hereinafter.*/i)[0].trim().split(/\s*\(.*$/)[0].trim();
        // B-5b (2026-09-20): tidy the VALUE-SIDE path too. B-5 wired stripEdgeDebris and
        // cutAtEmbeddedLabel into extractContractor's LABELED path only, and the document
        // that motivated the whole fix has no "Contractor:" label on that line — it is
        // found by this heuristic. So the field still came back
        // "i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom" in the browser while
        // the unit test of the labeled path passed. Tidying BEFORE the shape checks means
        // a recoverable name is recovered rather than refused, exactly as it is there.
        raw = stripEdgeDebris(cutAtEmbeddedLabel(stripEdgeDebris(raw)));
        const s = raw.toLowerCase().trim();
        if (s.length < 5 || s.length > 150) return null;
        // B-4 / L3 PRECEDENCE FIX (2026-09-20). A '|' is a CELL BOUNDARY, and this check
        // runs on EVERY path INCLUDING the learned one. That is deliberate: learned
        // phrases match by SUBSTRING, so a taught "skyscraper construction" would also
        // match the bid-abstract ROW that contains it, and B-4's defect would walk back
        // in through the learned door. No amount of teaching makes a table row into one
        // company, so this is structural rather than heuristic.
        if (raw.indexOf('|') !== -1) return null;
        // L3 FIRST. The loop's documented rule is that a human-reviewed vocabulary
        // OUTRANKS the heuristics, and it keeps the LENGTH BOUNDS ONLY (the 5..150
        // above). It is checked before the hardcoded keywords rather than after, because
        // after is unreachable in practice: almost every contractor line contains one of
        // those keywords, so the hardcoded branch returned — null included — before the
        // learned branch was ever consulted. A phrase the reviewer taught and approved
        // could therefore be refused by a heuristic it was meant to overrule.
        const extra = normalizeKeywordList(extraKeywords);
        if (extra.length && extra.some(kw => s.includes(kw))) return cleanValue(raw);
        if (CONTRACTOR_VALUE_KEYWORDS.some(kw => s.includes(kw))) {
            // The HARDCODED guess is the strict path: it has no label and no human
            // behind it, so it also takes asCompanyName's tighter length ceiling.
            if (!asCompanyName(raw)) return null;
            if (!/\b(construction|engineering|builders|enterprises|corporation|supply|contractor|trading|development)\b/i.test(s)) return null;
            return cleanValue(raw);
        }
        return null;
    }

    // ─── STEP 1e-4/1e-5: POOLED VOTE HELPERS ───────────────────────────────────
    // Collect EVERY find (not just the first) so repeats inside one bundle file
    // and across files can vote together. Same pool feeds two tracks:
    //   VALUE track (fills preview): one pooled pile across all files.
    //   WARNING track (red banner): one winner per file (kept in document-parser.js).
    // Section weights keep recall ("try all pages") without letting inspection /
    // accomplishment pages outvote the contract summary.

    // Weight for a candidate from its nearby lines (Step 1e-5).
    // Contract summary = 1.0, neutral = 0.85, supporting section = 0.6.
    // Called by: collectFieldCandidates() for every candidate.
    // L-9 (2026-09-20): hoisted out of parseProjectInfo so the POOLED path can build
    // the same patterns and apply the same normalizers. Defined ONCE on purpose — two
    // copies of a normalizer table is exactly the kind of thing that drifts silently
    // and then disagrees with itself about what a learned label means.
    // Called by: parseProjectInfo() and parseProjectInfoPooled().
    const stripOeTitle = (v) => /^oe\s+/i.test(v) ? v.replace(/^oe\s+/i, '').trim() : v;
    function learnedLabelPattern(ph) {
        return new RegExp('^\\s*(?:' + String(ph).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')(?=[:\\-\\d]|$|\\s+\\d)\\s*[:\\-]?\\s*(.*)', 'i');
    }
    const LEARNED_NORMALIZERS = {
        title: (v) => { const c = lenRange(5, 200)(v); return c ? stripOeTitle(c) : null; },
        location: (v) => lenRange(3, 200)(stripEdgeDebris(cutAtEmbeddedLabel(v))),
        budget: asBudget,
        abc: asMoney,
        budgetSource: asSourceName,
        category: asCategoryName,
        startDate: asDate,
        endDate: asDate,
        duration: asDays,
        contractor: (v) => asCompanyName(stripEdgeDebris(cutAtEmbeddedLabel(stripEdgeDebris(v || '')))),
    };
    const learnedNormalizerFor = (field) => LEARNED_NORMALIZERS[field] || null;

    function getSectionWeight(contextText) {
        const s = String(contextText || '');
        const isContract = /contract\s*amount|contract\s*time|effectivity|original\s*contract|revised\s*contract/i.test(s);
        if (isContract) return 1.0;
        // Step 1f-3: accomplishment / breakdown tables count less than before (0.4,
        // was 0.6) so a repeated table figure cannot outvote one contract-summary hit.
        const isTable = /accomplishment|quantity|breakdown|\btable\b/i.test(s);
        const isSupporting = /inspection|accomplishment|acceptance|time\s*elapsed/i.test(s);
        if (isTable && !isContract) return 0.4;
        if (isSupporting) return 0.5;
        return 0.85;
    }

    // Normalize a value for voting (same rule as document-parser.js).
    // N2: hardened — '&' reads as 'and', and ALL punctuation/spacing collapses, so
    // same-project trivial variants (")-Savings" vs ") -Savings", "&" vs "AND") vote
    // equal while truly different values ("Building A" vs "Building B") still differ.
    // Called by: voteCandidatePool(), voteCandidatePoolDebug().
    function normalizeForPool(v) {
        if (!v) return '';
        let s = String(v).trim().toLowerCase();
        s = s.replace(/\s*\(hereinafter.*/i, '').trim();
        s = s.replace(/&/g, ' and ');
        s = s.replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
        return s;
    }

    // Collect every budget candidate in one file (labeled + fallbacks).
    // Each entry: { value, labeled, weight, fileName, pattern, excerpt }.
    // Step 1g-1: pattern = which label/fallback matched; excerpt = ±2-line source
    // snippet so the vote inspector shows WHERE each find came from.
    // Called by: parseProjectInfoPooled() per file.
    function collectBudgetCandidates(lines, fileName) {
        const out = [];
        const norm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        // Step 1f-3: ±2-line window so a table row sees the table header above it
        // (a ±1 window misses it for the 2nd+ row, letting repeats outvote summary).
        const ctxOf = (i) => [norm[i - 2] || '', norm[i - 1] || '', norm[i] || '', norm[i + 1] || '', norm[i + 2] || ''].join(' ');
        // Step 1g-1: short source excerpt for the inspector (same ±2 window).
        const excerptOf = (i) => [norm[i - 2] || '', norm[i - 1] || '', norm[i] || '', norm[i + 1] || '', norm[i + 2] || ''].filter(Boolean).join(' | ').slice(0, 180);
        // All labeled hits (revised + final-anchor + generic incl. original).
        // Step 1i-3: 'total-row' leads — table bottom-row totals ("TOTAL CONTRACT AMOUNT
        // 6,495,049.29 ...") get their own named pattern with summary trust instead of
        // riding the generic substring accident.
        const labelRes = [
            { name: 'total-row', re: /(?:total\s*contract\s*amount)\s*[:\-]?\s*(.*)/i, labeled: true },
            { name: 'revised', re: /(?:revised\s*contract\s*amount)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i, labeled: true },
            { name: 'final-anchor', re: /(?:final\s*contract\s*amount\s*is)\s*(.*)/i, labeled: true },
            { name: 'discount-anchor', re: /(?:after\s+applying.*3%\s*discount.*contract\s*amount\s*is)\s*(.*)/i, labeled: true },
            { name: 'amount-is', re: /(?:contract\s*amount\s*is)\s*(.*)/i, labeled: true },
            { name: 'generic', re: /(?:original\s*contract\s*amount|contract\s*amount|contract\s*price|project\s*cost|total\s*cost|budget)\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i, labeled: true },
        ];
        // Step 1i-1: placeholder tokens mean "intentionally empty" — shared helper
        // isPlaceholderValue() (defined above near cleanValue); a placeholder slot
        // stops this occurrence (see the tryStrs loop below).
        // Step 1i-3: lines already claimed by 'total-row' (below) skip the generic
        // substring re-match so one bottom-row total votes once, not twice.
        const totalRowHit = new Set();
        // F-B1 conditional mirror of extractBudget: bare-"budget" lines are ABC, not price,
        // but ONLY in files carrying an Original/Revised figure (extractABC claims them there).
        const fileHasOrigRevised = norm.some(l => /original\s*contract\s*amount|revised\s*contract\s*amount/i.test(l));
        for (let i = 0; i < norm.length; i++) {
            for (const { name, re, labeled } of labelRes) {
                if (name === 'generic' && totalRowHit.has(i)) continue;
                if (name === 'generic' && fileHasOrigRevised &&
                    /budget/i.test(norm[i]) &&
                    !/contract\s*amount|contract\s*price|project\s*cost|total\s*cost|original|approved\s*budget\s*for/i.test(norm[i])) continue;
                const m = norm[i].match(re);
                if (!m) continue;
                const tryStrs = [cleanValue(m[1] || ''), cleanValue(norm[i + 1] || ''), cleanValue(norm[i + 2] || '')];
                for (let ti = 0; ti < tryStrs.length; ti++) {
                    let t = tryStrs[ti];
                    if (!t) continue;
                    // Step 1i-1: placeholder slot → EMPTY for this occurrence. Stop here:
                    // sliding further would steal the next section's date/figure.
                    if (isPlaceholderValue(t)) break;
                    // Step 1h-2: never mint money from a date ("December 9, 2025" is not
                    // 92025). A date-like value without any money marker is skipped even
                    // if a budget label matched nearby (e.g., Revised Contract Amount
                    // followed by an effectivity/expiry date line in a bundle).
                    if (!/(?:php|₱|\bP\b|pesos|amount)/i.test(t) && asDate(t)) continue;
                    // 1i follow-up F1: a MATCHED budget label is itself money context, so
                    // strip OCR thousand-dots here too even with no Php marker ("Total Cost
                    // 6.495,049.29" reads full). Narrow pattern only (dot + exactly 3 digits
                    // + digit/comma behind) — version numbers like "Section 1.495" never match.
                    // Same single-decimal guard as asMoney: multi-figure rows keep every dot
                    // so the first-figure rule below takes the head value.
                    if (/\d\.\d{3}(?=[\d,])/.test(t) && ((t.match(/\.\d{2}\b/g) || []).length === 1)) t = t.replace(/(\d)\.(\d{3})(?=[\d,])/g, '$1$2');
                    const val = asMoney(t);
                    // Step 1f-3: a money label inside a breakdown table without any
                    // contract word nearby ("Total Cost P 92,025" per line item) is a
                    // weaker witness than the contract summary — halve its weight so
                    // repeats of a table figure cannot outvote one summary hit.
                    if (val) {
                        const ctx = ctxOf(i);
                        let w = getSectionWeight(ctx);
                        if (!/contract\s*amount|revised|original|effectivity|contract\s*time|contract\s*price/i.test(ctx)) w *= 0.5;
                        // Step 1i-2: same-line values outrank slid ones (ti 0 = label's own
                        // line, ti 1-2 = borrowed from below). A slid date/figure can no
                        // longer tie a same-line price before frequency even counts.
                        if (ti > 0) w *= 0.8;
                        out.push({ value: val, labeled, weight: w, fileName, pattern: name, excerpt: excerptOf(i), line: i });
                        if (name === 'total-row') totalRowHit.add(i);
                        break;
                    }
                }
            }
        }
        // All fallback Php amounts (glued, >100k guard kept).
        // 1i follow-up F2: 'bare-php' closes the coverage gap where first-match sees a
        // "Php 6,495,049.29" with no parens/NET-TOTAL/P-prefix (e.g., recreated crops) but
        // the pool sat out. Unlabeled + low weight so labeled contract hits still win.
        const fbRes = [
            { name: 'paren-php', re: /\(p(?:hp)?\s*([\d,]+\.\d{2})\)/i },
            { name: 'net-total', re: /NET\s*TOTAL\s*(?:P\s*)?([\d,]+\.\d{2})/i },
            { name: 'p-prefix', re: /\bP\s*([\d,]+\.\d{2})/i },
            { name: 'bare-php', re: /(?:php|₱)\s*([\d,]+\.\d{2})/i },
        ];
        for (let i = 0; i < norm.length; i++) {
            let combined = glueMoneyFragments([norm[i], norm[i + 1] || ''].join(' '));
            // Step 1h-3: same period-thousands fix for the fallback scan (OCR
            // "6.495,049.29" blinds the \(Php ...\) / P-prefix patterns otherwise).
            // Single-decimal guard (see asMoney): multi-figure windows keep their dots.
            if (/(?:php|₱)/i.test(combined) && ((combined.match(/\.\d{2}\b/g) || []).length === 1)) combined = combined.replace(/(\d)\.(\d{3})(?=[\d,])/g, '$1$2');
            let fbName = null;
            for (const fb of fbRes) { if (fb.re.test(combined)) { fbName = fb.name; break; } }
            if (fbName) {
                const val = asMoney(combined);
                // Step 1f-3: unlabeled guesses count less (0.5, was 0.8) — a contract
                // label (1.0) beats several table guesses even when they repeat.
                if (val && parseFloat(val) > 100000) out.push({ value: val, labeled: false, weight: getSectionWeight(combined) * 0.5, fileName, pattern: fbName, excerpt: excerptOf(i), line: i });
            }
        }
        return out;
    }

    // Collect every ABC (Approved Budget for the Contract) candidate in one file.
    // Mirrors collectBudgetCandidates() shape { value, labeled, weight, fileName, pattern,
    // excerpt } so ABC rides the same pooled vote + debug + disagree machinery.
    // LABELED-ONLY by design: a random peso figure is never ABC, so budget's unlabeled
    // Php fallbacks stay budget-exclusive and the two tracks never double-count one figure.
    // Blinding-gated (L3_TEST_BLIND_ABC): while true the collector sleeps (returns []) so
    // the clean-file demo still misses honestly; the single-match learned path carries
    // approved rows meanwhile. Post-restore it collects with zero further code changes.
    // Called by: parseProjectInfoPooled() per file.
    function collectAbcCandidates(lines, fileName) {
        if (L3_TEST_BLIND_ABC) return [];
        const out = [];
        const norm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        const ctxOf = (i) => [norm[i - 2] || '', norm[i - 1] || '', norm[i] || '', norm[i + 1] || '', norm[i + 2] || ''].join(' ');
        const excerptOf = (i) => [norm[i - 2] || '', norm[i - 1] || '', norm[i] || '', norm[i + 1] || '', norm[i + 2] || ''].filter(Boolean).join(' | ').slice(0, 180);
        const labelRes = [
            { name: 'abc-line', re: /(?:approved\s*budget\s*for\s*the\s*contract\s*(?:\(abc\))?|\babc\b)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i, labeled: true },
        ];
        // F-B1 conditional mirror of extractABC: bare "budget [Amount]" / "approved budget"
        // lines are ABC — but ONLY in files carrying an Original/Revised figure (the budget
        // collector skips those same lines there, so no double-vote across tracks).
        const fileHasOrigRevised = norm.some(l => /original\s*contract\s*amount|revised\s*contract\s*amount/i.test(l));
        if (fileHasOrigRevised) {
            labelRes.push({ name: 'f-b1-budget', re: /(?:total\s*budget\s*amount|approved\s*budget|budget\s*amount)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i, labeled: true });
        }
        for (let i = 0; i < norm.length; i++) {
            for (const { name, re, labeled } of labelRes) {
                const m = norm[i].match(re);
                if (!m) continue;
                const tryStrs = [cleanValue(m[1] || ''), cleanValue(norm[i + 1] || ''), cleanValue(norm[i + 2] || '')];
                for (let ti = 0; ti < tryStrs.length; ti++) {
                    let t = tryStrs[ti];
                    if (!t) continue;
                    // Placeholder slot → EMPTY for this occurrence (never slide into dates).
                    if (isPlaceholderValue(t)) break;
                    // Never mint money from a date (same 1h-2 guard as budget).
                    if (!/(?:php|₱|\bP\b|pesos|amount)/i.test(t) && asDate(t)) continue;
                    // Thousand-dot strip for matched ABC labels (same narrow F1 rule).
                    if (/\d\.\d{3}(?=[\d,])/.test(t) && ((t.match(/\.\d{2}\b/g) || []).length === 1)) t = t.replace(/(\d)\.(\d{3})(?=[\d,])/g, '$1$2');
                    // N1a: a "& 59/100" written-cents marker is not the amount (same guard
                    // extractABC uses, so both ABC tracks agree — see isCentsFractionOnly).
                    if (isCentsFractionOnly(t)) continue;
                    const val = asMoney(t);
                    if (val) {
                        const ctx = ctxOf(i);
                        let w = getSectionWeight(ctx);
                        if (!/contract\s*amount|revised|original|effectivity|contract\s*time|contract\s*price|approved\s*budget|\babc\b/i.test(ctx)) w *= 0.5;
                        // Same-line values outrank slid ones (1i-2).
                        if (ti > 0) w *= 0.8;
                        out.push({ value: val, labeled, weight: w, fileName, pattern: name, excerpt: excerptOf(i), line: i });
                        break;
                    }
                }
            }
        }
        // Words-shape (layer-2 rule A): funds-cert PESOS-words figure when the Php numeric is
        // absent — same shared rule as the single-match fallback, so both tracks agree.
        // Exempt from table-halving: the ABC context itself is the contract word (same
        // rationale as the 'total-row' exemption in the budget collector).
        for (let i = 0; i < norm.length; i++) {
            const wv = abcWordsAtWindow(norm[i], norm[i + 1] || '');
            if (wv) out.push({ value: wv, labeled: true, weight: getSectionWeight(ctxOf(i)), fileName, pattern: 'words-shape', excerpt: excerptOf(i), line: i });
        }
        return out;
    }

    // Collect every duration candidate in one file.
    // Each entry: { value, labeled, weight, fileName, pattern, excerpt } (Step 1g-1).
    // Called by: parseProjectInfoPooled() per file.
    function collectDurationCandidates(lines, fileName) {
        const out = [];
        const norm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        // Step 1g-1: excerpt for the inspector.
        const excerptOf = (i) => [norm[i - 2] || '', norm[i - 1] || '', norm[i] || '', norm[i + 1] || '', norm[i + 2] || ''].filter(Boolean).join(' | ').slice(0, 180);
        const labelRe = /(?:duration|contract\s*period|contract\s*duration|original\s*contract\s*time|contract\s*time|no\.?\s*of\s*(?:calendar\s*)?days|number\s*of\s*days|calendar\s*days?|working\s*days?)\s*[:\-]?\s*(.*)/i;
        for (let i = 0; i < norm.length; i++) {
            const m = norm[i].match(labelRe);
            if (m) {
                // D-total: "Total Contract Time" (extensions included) outranks Original in the
                // pool too — same rule as the single-match path. Amber still offers close calls.
                const isTotal = /total\s*contract\s*time/i.test(norm[i]);
                for (const t of [cleanValue(m[1] || ''), cleanValue(norm[i + 1] || ''), cleanValue(norm[i + 2] || '')]) {
                    if (!t) continue;
                    const val = asDays(t);
                    if (val) {
                        let w = getSectionWeight([norm[i - 1] || '', norm[i], norm[i + 1] || ''].join(' '));
                        if (isTotal) w *= 1.25;
                        out.push({ value: val, labeled: true, weight: w, fileName, pattern: isTotal ? 'total-contract-time' : 'labeled', excerpt: excerptOf(i), line: i });
                        break;
                    }
                }
            }
            // "within N days calendar days" clause on this line or paired with next.
            for (const t of [norm[i], [norm[i], norm[i + 1] || ''].join(' ')]) {
                const wm = String(t).match(/within\s+(.+?calendar\s*days.*)/i);
                // Step 1f-3: unlabeled duration guesses count less (0.5, was 0.8).
                if (wm) {
                    const val = asDays(wm[1]) || asDays(wm[0]);
                    if (val) { out.push({ value: val, labeled: false, weight: getSectionWeight(t) * 0.5, fileName, pattern: 'within-clause', excerpt: excerptOf(i), line: i }); break; }
                }
            }
        }
        return out;
    }

    // Collect every start/end date candidate in one file.
    // Each entry: { value, labeled, weight, fileName, pattern, excerpt } (Step 1g-1).
    // Called by: parseProjectInfoPooled() per file.
    function collectDateCandidates(lines, fileName, which) {
        const out = [];
        const norm = (lines || []).map(l => String(l).trim()).filter(Boolean);
        // Step 1g-1: excerpt for the inspector.
        const excerptOf = (i) => [norm[i - 2] || '', norm[i - 1] || '', norm[i] || '', norm[i + 1] || '', norm[i + 2] || ''].filter(Boolean).join(' | ').slice(0, 180);
        const re = which === 'start'
            ? /(?:start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|ntp|date\s*started|contract\s*start|date\s*of\s*effectivity(?:\s*of\s*(?:the\s*)?contract)?|effectivity)\s*[:\-]?\s*(.*)/i
            : /(?:end\s*date|completion\s*date|target\s*completion|contract\s*end|date\s*of\s*completion|expected\s*completion)\s*[:\-]?\s*(.*)/i;
        for (let i = 0; i < norm.length; i++) {
            const m = norm[i].match(re);
            if (!m) continue;
            for (const t of [cleanValue(m[1] || ''), cleanValue(norm[i + 1] || ''), cleanValue(norm[i + 2] || '')]) {
                if (!t) continue;
                const val = asDate(t);
                if (val) { out.push({ value: val, labeled: true, weight: getSectionWeight([norm[i - 1] || '', norm[i], norm[i + 1] || ''].join(' ')), fileName, pattern: 'labeled', excerpt: excerptOf(i), line: i }); break; }
            }
        }
        return out;
    }

    // Vote one pooled pile: weighted frequency first, tie-break labeled count,
    // then full-length money (digit-count) so tails like 5049.29 lose to full.
    // Called by: parseProjectInfoPooled() per field.
    // Same pesos, different cents: prefer the reading that HAS cents.
    // WHY (user decision D-A2, 2026-09-24): OCR truncation LOSES digits, it does not
    // invent them, so ".77" is positive evidence of a real reading while ".00" is what
    // a lost-cents read produces. On the measured document the revised amount appears
    // as BOTH 4,323,196.00 and 4,323,196.77, and every earlier tie-break treats them as
    // equal — score, labeled count and digit length are all identical — so the winner
    // fell to a localeCompare that puts "00" first and picks the truncated twin.
    // ⚠ NARROW ON PURPOSE: it fires only when the INTEGER PESO PARTS MATCH EXACTLY, so
    // an honestly round amount that is nobody's twin is never touched.
    // Returns <0 when a wins, >0 when b wins, 0 when the rule does not apply.
    function centsPreference(aVal, bVal) {
        const split = (v) => {
            const m = /^(\d+)\.(\d{2})$/.exec(String(v || '').replace(/,/g, ''));
            return m ? { pesos: m[1], cents: m[2] } : null;
        };
        const A = split(aVal), B = split(bVal);
        if (!A || !B || A.pesos !== B.pesos) return 0;
        const aZero = A.cents === '00', bZero = B.cents === '00';
        if (aZero === bZero) return 0;
        return aZero ? 1 : -1;
    }

    // Is this candidate a "Revised Contract Amount" reading?
    // Called by: splitRevisedBudget(). One place, so the pattern name is not spread.
    function isRevisedBudgetPattern(pattern) {
        return String(pattern || '') === 'revised';
    }

    // Split budget candidates into the revised reading and everything else.
    // WHY (user decision 11, implemented 2026-09-24 as ROUTING, not precedence):
    // the form now has its own Revised Contract Price box, so a revised amount is no
    // longer a rival reading of the contract price — it is a DIFFERENT FACT with its
    // own home. Routing it out is strictly safer than the precedence reorder this was
    // first designed as: the contract-price vote is not touched at all.
    // ⚠ ONLY THE 'revised'-PATTERN CANDIDATES MOVE. A document that also states the
    // same figure generically keeps that generic candidate in the contract-price pool,
    // where it competes on its own merits. Measured on pdfdocstest5: the contract-price
    // winner is unchanged at 4,204,128.84 after the split.
    function splitRevisedBudget(cands) {
        const revised = [], rest = [];
        for (const c of (cands || [])) (isRevisedBudgetPattern(c && c.pattern) ? revised : rest).push(c);
        return { revised, rest };
    }

    function voteCandidatePool(cands) {
        if (!cands || cands.length === 0) return null;
        const groups = new Map(); // norm -> { original, score, labeledCount, len }
        for (const c of cands) {
            const norm = normalizeForPool(c.value);
            if (!norm) continue;
            if (!groups.has(norm)) groups.set(norm, { original: c.value, score: 0, labeledCount: 0, len: String(c.value).replace(/\D/g, '').length });
            const g = groups.get(norm);
            g.score += (c.weight || 0.85);
            if (c.labeled) g.labeledCount += 1;
        }
        let best = null;
        for (const g of groups.values()) {
            if (!best || g.score > best.score || (g.score === best.score && (g.labeledCount > best.labeledCount || (g.labeledCount === best.labeledCount && (g.len > best.len || (g.len === best.len && centsPreference(g.original, best.original) < 0)))))) best = g;
        }
        return best ? best.original : null;
    }

    // Vote inspector: same scoring as voteCandidatePool but returns the full table
    // so the browser console can show every find, its votes, and the winner.
    // Row: { value, norm, count, score, labeledCount, files, patterns, excerpts }.
    // Sorted by score desc. Step 1g-1: patterns = distinct matched label/fallback
    // names; excerpts = up to 2 distinct source snippets (WHERE each find came from).
    // Called by: parseProjectInfoPooled() for _debug; document-parser.js logs it
    // when window._parserDebug is true. Pure (no DOM) so tests can assert it.
    function voteCandidatePoolDebug(cands) {
        const rows = [];
        if (!cands || cands.length === 0) return { winner: null, rows };
        const groups = new Map(); // norm -> { original, score, count, labeledCount, files:Set, patterns:Set, excerpts:[] }
        // INDEPENDENCE (2026-09-20) — THE SAME LINE READ TWICE IS ONE PIECE OF
        // EVIDENCE, NOT TWO. Two rules can match the same line and produce the same
        // value: a hardcoded pattern and a taught phrase that restates it, or two
        // hardcoded patterns whose wordings overlap. Counting both inflated the score
        // and the count of a value that had exactly one source.
        // THE USER FOUND THIS BY ASKING WHETHER A LEARNED PHRASE WOULD SHARE WORDING
        // WITH A HARDCODED ONE. It does: 'Approved Budget for the Contract' is both a
        // taught phrase and the hardcoded abc-line rule, and on the test document they
        // both read the SAME DAMAGED LINE ("4,834,297 5g"). The false corroboration
        // pushed that wrong value past 2x the correct one, which silenced the
        // disagreement note — so the right answer disappeared precisely because two
        // rules agreed about one line.
        // Finds with no line recorded are never deduped; they keep their own vote.
        const seenLines = new Set();
        for (const c of cands) {
            const norm = normalizeForPool(c.value);
            if (!norm) continue;
            if (c.line !== undefined && c.line !== null) {
                const key = norm + '@' + (c.fileName || '') + '#' + c.line;
                if (seenLines.has(key)) {
                    // Still record the PATTERN — knowing both rules found it is useful
                    // in the inspector — but do not let it vote a second time.
                    const g0 = groups.get(norm);
                    if (g0 && c.pattern) g0.patterns.add(c.pattern);
                    continue;
                }
                seenLines.add(key);
            }
            if (!groups.has(norm)) groups.set(norm, { original: c.value, score: 0, count: 0, labeledCount: 0, files: new Set(), patterns: new Set(), excerpts: [] });
            const g = groups.get(norm);
            g.score += (c.weight || 0.85);
            g.count += 1;
            if (c.labeled) g.labeledCount += 1;
            if (c.fileName) g.files.add(c.fileName);
            if (c.pattern) g.patterns.add(c.pattern);
            if (c.excerpt && g.excerpts.length < 2 && g.excerpts.indexOf(c.excerpt) === -1) g.excerpts.push(c.excerpt);
        }
        for (const [norm, g] of groups.entries()) {
            rows.push({ value: g.original, norm, count: g.count, score: Math.round(g.score * 100) / 100, labeledCount: g.labeledCount, files: [...g.files], patterns: [...g.patterns], excerpts: g.excerpts });
        }
        // L-9: A LEARNED-ONLY VALUE NEVER OUTRANKS ONE WITH HARDCODED SUPPORT.
        // Enforced here rather than by weight, because weights accumulate: enough
        // learned finds would eventually outvote a hardcoded one, and "enough" is not a
        // policy anyone chose. A row counts as hardcoded-backed if ANY of its patterns
        // is not a learned one, so corroboration between the two lifts the row into the
        // trusted group rather than dragging it down.
        const backed = (r) => (r.patterns || []).some(p => String(p).indexOf('learned:') !== 0) ? 1 : 0;
        rows.sort((a, b) => backed(b) - backed(a) || b.score - a.score || b.labeledCount - a.labeledCount
            // D-A2 before the alphabetical fallback: localeCompare puts "…196.00"
            // ahead of "…196.77" and would hand the box the cents-truncated twin.
            || centsPreference(a.value, b.value) || a.value.localeCompare(b.value));
        return { winner: rows.length ? rows[0].value : null, rows };
    }

    // Choice B disagree check (Step 1g-2): do the top-2 finds both look trusted but
    // disagree? Trigger: ≥2 rows, both labeled, different values (guaranteed by
    // grouping), winner score < 2× runner-up. Returns { winner, runnerUp } or null.
    // Silent on a clear winner so clean files see no extra note (automation goal).
    // Called by: parseProjectInfoPooled() per pooled field. Pure (no DOM).
    function pooledDisagree(debugRows) {
        if (!debugRows || debugRows.length < 2) return null;
        const [first, second] = debugRows;
        if (!first.labeledCount || !second.labeledCount) return null;
        if (first.score >= 2 * second.score) return null;
        return {
            winner: { value: first.value, count: first.count, files: first.files },
            runnerUp: { value: second.value, count: second.count, files: second.files },
        };
    }

    // Mismatch grouping for the P1 banner verdict (which files look connected vs odd).
    // Input: getMismatchInfo-style variants [{ value, files, count }] sorted desc.
    // Output: { connected: [fileNames of the top variant], outliers: [{ value, files }] }.
    // A single variant (or none) means no split — outliers is empty, no buttons render.
    // Called by: document-parser.js mismatch banner. Pure (no DOM) so tests assert it.
    function splitMismatchGroups(variants) {
        if (!variants || variants.length < 2) return { connected: [], outliers: [] };
        const [top, ...rest] = variants;
        return { connected: [...(top.files || [])], outliers: rest.map(v => ({ value: v.value, files: [...(v.files || [])] })) };
    }

    // Soup scorer for photo-read text (G-soup): is this page worth a second opinion?
    // Signals (calibrated 2026-09-04 on pasted production samples):
    //   symRatio   = share of chars outside [alnum + space + .,;:()%-]
    //   weirdFrac  = share of tokens <40% alphanumeric, or longer than 20 chars
    //   SOUP ≈ 0.37–0.72 combined | TABLE (legit pipes) ≈ 0.29 | CLEAN/FRAG/NUMS/DATES = 0.
    //   TABLE2 (legit but noisy, ~~ and pipes) ≈ 0.58 — trips the bar (accepted cost: the
    //     retry KEEPS THE BETTER text, so worst case is one wasted attempt).
    // Rule: symRatio >= 0.02 (real symbols present, not just odd words) AND
    //   (weirdFrac + symRatio) >= 0.32. Single-signal rules misfire: symbols alone hit
    //   currency-heavy lines, weird-tokens alone hit legit tables.
    // Called by: document-parser.js recognizeEnglish() gate + tests. Pure (no DOM).
    function scoreOcrSoup(text) {
        const s = String(text || '');
        if (!s.trim()) return { score: 1, weirdFrac: 1, symRatio: 1, soupy: true };
        const syms = (s.match(/[^A-Za-z0-9\s.,:;()%/\-]/g) || []).length;
        const toks = s.split(/\s+/).filter(Boolean);
        let weird = 0;
        for (const t of toks) {
            const al = (t.match(/[A-Za-z0-9]/g) || []).length;
            if (al / t.length < 0.4 || t.length > 20) weird++;
        }
        const symRatio = syms / Math.max(1, s.length);
        const weirdFrac = weird / Math.max(1, toks.length);
        const soupy = symRatio >= 0.02 && (weirdFrac + symRatio) >= 0.32;
        return { score: Math.round((weirdFrac + symRatio) * 100) / 100, weirdFrac: Math.round(weirdFrac * 1000) / 1000, symRatio: Math.round(symRatio * 1000) / 1000, soupy };
    }

    // Keep-cleaner minimum-length guard for the OCR PSM retry ladder (2026-09-06).
    // A PSM retry may dethrone the current best ONLY when the challenger is both
    // cleaner (non-soupy) AND substantive (10+ usable chars). A long-soupy incumbent
    // (e.g. test4 statement page with real amounts) survives a short-clean fragment
    // challenger; a short-soupy incumbent still upgrades to a clean 10+ read.
    // Soupy challengers never dethrone; clean-vs-clean and soupy-vs-soupy keep the
    // first (document order, no churn). Empty best always fills on first usable read.
    // Called by: document-parser.js recognizeEnglish(). Pure (no DOM).
    function shouldReplaceOcrBest(bestText, bestSoupy, challengerText, challengerSoupy) {
        if (!bestText) return true;
        if (!challengerSoupy && bestSoupy) {
            return String(challengerText || '').trim().length >= 10;
        }
        return false;
    }

    // Mine a candidate label for a user-corrected value (L1 learning loop, Option 2).
    // Surveys EVERY matching line (M2) and returns the best-anchored one: a match whose
    // neighborhood already looks label-ish outranks a bare occurrence; ties keep document
    // order. Matching is forgiving: money ignores separators ("6,495,049.29" matches OCR
    // "6.495,049.29"), text matches case-insensitively, short numerics match whole tokens
    // only (M1: "205" anchors "Total Contract Time 205" but can never hit inside "2025").
    // Returns null when the value cannot be anchored (typed from knowledge, reformatted
    // date) — the correction is still recorded, just without a label candidate.
    // Called by: document-parser.js harvestCorrections() at Apply. Pure (no DOM).
    // Multi-label survey: up to `cap` DISTINCT gated {label, excerpt} rows, ranked
    // gated-first with document order breaking ties (the M2 order). Near-twin labels
    // ('...)-Savings' vs '... ) -Savings') collapse to one via a normalized key so one
    // correction yields distinct phrasings, not punctuation variants. Ungated hits never
    // label — when nothing gated exists, a single count-only {label: null} row is returned
    // (preserves today's harvest behavior for unanchorable values).
    // Called by: document-parser.js harvestCorrections() at Apply. Pure (no DOM).
    // TEXT FIELDS ANCHOR BY TEXT, WHATEVER CHARACTERS THEY HAPPEN TO CONTAIN
    // (2026-09-20). The strategy used to be chosen by "does this value contain a
    // digit", which is right for money and durations and wrong for everything else. A
    // reviewer who disambiguated a duplicate title by appending " 2" turned the whole
    // title into a DIGIT match: the miner looked for a bare "2" anywhere in the
    // document, found one in an unrelated line, and mined "Amount" as the label for a
    // project title. The user saw the result on the desk as
    // "(field count only — no wording found) = 1 | Ea Page; of12 | _ (LA".
    // The same trap catches "Brgy. 3", "301 Engineers", "Phase 2" — any text value
    // with a numeral in it.
    // The field now decides. Callers that pass no field keep the old behaviour, so
    // existing tests and the legacy single-label wrapper are unaffected.
    const TEXT_ANCHOR_FIELDS = ['title', 'location', 'description', 'contractor', 'budgetSource', 'category'];
    function mineCorrectionLabels(docText, finalValue, cap, field) {
        const raw = String(finalValue || '').trim();
        if (raw.length < 3) return [];
        const lines = buildLinesFromText(docText || '');
        const anchorAsText = field ? TEXT_ANCHOR_FIELDS.indexOf(field) !== -1 : false;
        const hasDigit = !anchorAsText && /\d/.test(raw);
        const digitSig = raw.replace(/\D/g, '');
        const lowerVal = raw.toLowerCase();
        // M1: short digit strings anchor whole tokens only — bounded by non-digit AND
        // non-separator so "205" skips "1,205,000" as well as "2025".
        let shortTokenRe = null;
        if (hasDigit && digitSig.length > 0 && digitSig.length < 4) {
            const esc = digitSig.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            try { shortTokenRe = new RegExp('(?<![\\d,.])' + esc + '(?![\\d,.])'); } catch (e) { shortTokenRe = null; }
        }
        const hits = [];
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            let at = -1;
            if (hasDigit && digitSig.length >= 4) {
                // Numeric: compare digit runs ignoring separators, so user-typed
                // "6,495,049.29" anchors OCR "6.495,049.29" and vice versa.
                const runs = line.match(/[\d][\d,.\s]*\d|\d/g) || [];
                let cursor = 0;
                for (const run of runs) {
                    const pos = line.indexOf(run, cursor);
                    cursor = pos + run.length;
                    const runSig = run.replace(/\D/g, '');
                    // Both sides need 4+ digits: a tiny run like "90" must never anchor
                    // a longer value like "2024-12-09" (its digits contain "90").
                    if (runSig.length >= 4 && (runSig.includes(digitSig) || digitSig.includes(runSig))) { at = pos; break; }
                }
            } else if (shortTokenRe) {
                const m = line.match(shortTokenRe);
                if (m && m.index !== undefined) at = m.index;
            } else if (!hasDigit) {
                const idx = line.toLowerCase().indexOf(lowerVal);
                if (idx !== -1) at = idx;
            } else {
                continue;
            }
            if (at === -1) continue;
            let label = cleanValue(line.slice(0, at)).slice(0, 80);
            if (!label) label = cleanValue(lines[i - 1] || '').slice(0, 80);
            const excerpt = [lines[i - 1] || '', line, lines[i + 1] || ''].filter(Boolean).join(' | ').slice(0, 200);
            // F-GATE score for M2 ranking (same rule that filters junk fragments).
            const rawBefore = line.slice(0, at);
            const gated = !label || /(amount|budget|price|cost|total|contract|\babc\b|fee|\bsum\b|bill|charge|payment)/i.test(label) || /[:\-–—]\s*$/.test(rawBefore);
            hits.push({ label: label || null, excerpt, gated, i });
        }
        if (!hits.length) return [];
        // M2: best-anchored first (gated beats ungated), document order breaks ties.
        hits.sort((a, b) => ((b.gated ? 1 : 0) - (a.gated ? 1 : 0)) || (a.i - b.i));
        const limit = Math.min(Math.max(cap || 3, 1), 3);
        const out = [];
        const seen = new Set();
        for (const h of hits) {
            // F-GATE: junk-adjacent fragments (e.g., ")F CANTEEN AT Public" before an ABC
            // figure) must not become dictionary labels — counted, never labeled.
            if (!h.gated || !h.label) continue;
            const key = h.label.toLowerCase().replace(/&/g, 'and').replace(/[^a-z0-9]+/g, ' ').trim();
            if (!key || seen.has(key)) continue;
            seen.add(key);
            out.push({ label: h.label, excerpt: h.excerpt });
            if (out.length >= limit) break;
        }
        if (out.length) return out;
        const best = hits[0];
        return [{ label: null, excerpt: best.excerpt }];
    }

    function mineCorrectionLabel(docText, finalValue, field) {
        // Legacy single-label wrapper: returns the survey winner (or null when unanchored).
        // Kept so existing callers and fixtures behave exactly as before multi-label.
        // Called by: document-parser.js harvestCorrections() fallback; tests.
        const rows = mineCorrectionLabels(docText, finalValue, 1, field);
        return rows.length ? rows[0] : null;
    }

    // Pooled parse across files (Step 1e-4 VALUE track + ABC-pooling).
    // Accepts [{ text, lines?, fileName }]; collects ALL candidates per file into
    // ONE pile per field and votes. Returns { budget, abc, duration, startDate, endDate }.
    // Per-file single values (parseProjectInfo) are still used for the WARNING track.
    // Called by: document-parser.js runDocumentAutofill() when available.
    // L-9 (2026-09-20) — LEARNED LABELS JOIN THE POOL.
    // Until now a learned label lived on a separate track: it could fill a field the
    // hardcoded rules missed, but it never entered the vote, so it had no count, no
    // files, no excerpt in the inspector, and two learned phrases disagreeing had to be
    // adjudicated by a second mechanism bolted alongside (§31, §32).
    // Now they are collected as ordinary candidates, which buys three things:
    //   · CORROBORATION BECOMES VISIBLE — a learned phrase and a hardcoded rule landing
    //     on the same value now show as one row carrying both patterns, which nobody
    //     could see before;
    //   · a learned find appears in the vote inspector, with the phrase that found it;
    //   · two learned phrases that disagree are handled by the SAME pooledDisagree that
    //     handles everything else, so there is one adjudicator instead of two.
    // ⚠ AND THEY CAN NEVER OUTRANK A HARDCODED FIND. That guarantee is enforced in the
    // SORT (see voteCandidatePoolDebug), not by the weight — weights accumulate, so
    // enough learned finds would eventually outvote a hardcoded one, and "enough" is
    // not a policy anybody chose. L3's documented rule is that learned vocabulary is
    // the lowest-priority fallback; this keeps that exactly while ending the split.
    // Called by: parseProjectInfoPooled().
    function collectLearnedCandidates(lines, fileName, field, taught, norm) {
        const out = [];
        if (!taught || !taught.length || !norm) return out;
        const rows = (lines || []).map(l => String(l).trim()).filter(Boolean);
        const excerptOf = (i) => [rows[i - 1] || '', rows[i] || '', rows[i + 1] || ''].filter(Boolean).join(' | ').slice(0, 180);
        for (const t of taught) {
            for (let i = 0; i < rows.length; i++) {
                const m = rows[i].match(t.pattern);
                if (!m) continue;
                const v = norm(cleanValue(m[1] || ''));
                if (v === null || v === undefined || v === '') continue;
                // The phrase travels with the row so the inspector can say WHICH taught
                // wording produced which value — the whole point of the .00/.59 case.
                const src = String(t.phrase || '').slice(0, 32);
                out.push({
                    value: v,
                    labeled: true,
                    weight: getSectionWeight(excerptOf(i)) * 0.6,
                    fileName,
                    pattern: 'learned:' + src,
                    excerpt: excerptOf(i),
                    line: i,
                });
                break; // one find per phrase per file — a phrase is not evidence twice
            }
        }
        return out;
    }

    // ─── WHAT WOULD THIS TAUGHT PHRASE ACTUALLY PULL OUT? (2026-09-23) ──────────
    //
    // The desk already answers "is this wording NEW?" (builtInCoversPhrase below). That
    // turned out to be the wrong question on its own, and measurably so. On
    // pdfdocstest5 the five AI-proposed rows read:
    //    "| TOTAL ESTIMATED PROJECT COST P"      -> NEW   (and correct)
    //    "Implementing Office"                   -> NEW   (and wrong)
    //    "LOCATION"                              -> NEW   (and wrong)
    //    "Name of Procuring Entity"              -> NEW   (and wrong)
    // ⚠ NOVELTY IS EXACTLY WHAT A WRONG LABEL HAS MOST OF, so the desk's only quality
    // signal was endorsing three rows that would each teach the parser something false.
    //
    // This answers the other half: run the phrase against the excerpt it was mined
    // from and report the value it would produce. On the same five rows that separates
    //    "Approved Budget for the Contract; PB"  -> 4834297.00   (OCR lost the decimal)
    //    "| TOTAL ESTIMATED PROJECT COST P"      -> 4834297.59   (the true ABC)
    // — two rows that are indistinguishable by wording alone — and it makes the three
    // budgetSource rows obvious too, because all three produce the SAME organisation
    // name, which is what a value anchoring to three unrelated labels looks like.
    //
    // ⚠ IT USES THE REAL EXTRACTION PATH, not a description of it: the same
    // learnedLabelPattern, the same cleanValue, the same field normalizer, and the same
    // first-match-wins rule as collectLearnedCandidates. A preview that could disagree
    // with what approval actually does would be worse than showing nothing.
    //
    // The excerpt is stored as lines joined with ' | ' (see excerptOf in the collectors),
    // so it is split back on that separator before matching — learnedLabelPattern is
    // anchored at ^, and a joined excerpt would only ever match its first line.
    //
    // Returns { ok:true, value, line }            — this is what approving it would read
    //         { ok:false, reason:'refused', line } — the phrase matches, but the field's
    //                                                own validator rejects what follows
    //         null                                 — the phrase does not match its own
    //                                                excerpt; nothing honest to show
    // Called by: the parser-keyword desk, via the same lazy import as builtInCoversPhrase.
    function previewLearnedExtraction(field, phrase, excerpt) {
        const norm = learnedNormalizerFor(field);
        if (!norm || !phrase) return null;
        let pattern;
        try { pattern = learnedLabelPattern(phrase); } catch (e) { return null; }
        const rows = String(excerpt || '').split(' | ').map(r => String(r).trim()).filter(Boolean);
        for (const row of rows) {
            const m = row.match(pattern);
            if (!m) continue;
            // First match wins, exactly as collectLearnedCandidates breaks after one
            // find per phrase per file.
            const v = norm(cleanValue(m[1] || ''));
            if (v === null || v === undefined || v === '') return { ok: false, reason: 'refused', line: row };
            return { ok: true, value: v, line: row };
        }
        return null;
    }

    // ─── DOES A TAUGHT PHRASE ADD ANYTHING? (2026-09-20) ────────────────────────
    // The approval desk shows a reviewer the phrase and asks them to judge it, without
    // telling them the one thing that decides its worth: whether a BUILT-IN rule
    // already covers that wording. §34.5b measured both kinds on one document —
    // "Approved Budget for the Contract; PB" restates the hardcoded abc-line rule and
    // changes nothing, while "| TOTAL ESTIMATED PROJECT COST P" is the phrase that
    // found the correct value. They look identical on the desk.
    //
    // THE TEST USES THE REAL RULES RATHER THAN A DESCRIPTION OF THEM: build a line out
    // of the phrase and a probe value, run the field's own hardcoded extractor over it,
    // and see whether the built-in rules pull the probe back out. Nothing is duplicated,
    // so nothing can drift — which is the mistake §33.3 had just finished cleaning up.
    //
    // Returns true (already covered), false (finds what no built-in rule does), or NULL
    // meaning "cannot tell", which is a real answer and must not be dressed up as
    // either of the others.
    // Called by: the parser-keyword desk, via a lazy import.
    const COVERAGE_PROBE = {
        budget: { text: 'Php 1,234,567.89', expect: '1234567.89' },
        abc: { text: 'Php 1,234,567.89', expect: '1234567.89' },
        duration: { text: '777', expect: '777' },
        startDate: { text: 'January 15, 2026', expect: '2026-01-15' },
        endDate: { text: 'January 15, 2026', expect: '2026-01-15' },
        location: { text: 'ZZ Probe Location Site', expect: 'ZZ Probe Location Site' },
        budgetSource: { text: 'ZZProbeSource', expect: 'ZZProbeSource' },
        category: { text: 'ZZ Probe Category', expect: 'ZZ Probe Category' },
    };
    function builtInCoversPhrase(field, phrase) {
        const ph = String(phrase || '').trim();
        if (!ph) return null;
        // ⚠ While the ABC blinding is on its hardcoded rules are EMPTY, so every ABC
        // phrase would look like a discovery. Say "cannot tell" instead of lying.
        if (field === 'abc' && L3_TEST_BLIND_ABC) return null;
        // TITLE and CONTRACTOR are deliberately absent from the probe table: both are
        // found mainly by VALUE-side heuristics, so a probe line would be matched on
        // its own wording rather than on the label, and the answer would be noise.
        const probe = COVERAGE_PROBE[field];
        if (!probe) return null;
        const extractors = {
            budget: extractBudget, abc: extractABC, duration: extractDuration,
            startDate: extractStartDate, endDate: extractEndDate,
            location: extractLocation, budgetSource: extractBudgetSource,
            category: extractCategory,
        };
        const ex = extractors[field];
        if (!ex) return null;
        try {
            const found = ex(buildLinesFromText(ph + ': ' + probe.text)) === probe.expect;
            if (!found) return false;
            // ⚠ A CONTROL RUN, because a probe VALUE can identify itself. "777 calendar
            // days" is found by a built-in rule whatever label sits in front of it, so
            // without this every duration phrase looked already-covered. If a nonsense
            // label pulls the same value out, the built-in rule matched the VALUE and
            // this test cannot say anything about the PHRASE — which is null, not true.
            const control = ex(buildLinesFromText('Zqxj Control Label: ' + probe.text)) === probe.expect;
            return control ? null : true;
        } catch (e) {
            return null;
        }
    }

    function parseProjectInfoPooled(docs, opts) {
        const pile = { budget: [], abc: [], duration: [], startDate: [], endDate: [], revisedBudget: [] };
        // L-9: the same learned vocabulary the single parse consults, as pool candidates.
        const pooledLearnedLabels = ((opts && opts.learned && opts.learned.labels) || {});
        for (const d of (docs || [])) {
            const lines = d.lines || buildLinesFromText(d.text || '');
            const fn = d.fileName || d.name || '';
            pile.budget.push(...collectBudgetCandidates(lines, fn));
            pile.abc.push(...collectAbcCandidates(lines, fn));
            pile.duration.push(...collectDurationCandidates(lines, fn));
            pile.startDate.push(...collectDateCandidates(lines, fn, 'start'));
            pile.endDate.push(...collectDateCandidates(lines, fn, 'end'));
            // L-9: learned labels for the five POOLED fields only. The others keep the
            // single-parse path — there is no pool for them to join.
            for (const f of ['budget', 'abc', 'duration', 'startDate', 'endDate']) {
                const phrases = normalizeKeywordList(pooledLearnedLabels[f]);
                if (!phrases.length) continue;
                const taught = phrases.map(ph => ({ phrase: ph, pattern: learnedLabelPattern(ph) }));
                pile[f].push(...collectLearnedCandidates(lines, fn, f, taught, learnedNormalizerFor(f)));
            }
        }
        // _debug powers the vote inspector (Step 1f-2): every find + winner per field.
        // _disagree powers Choice B notes (Step 1g-2): per-field winner/runnerUp or null.
        // _fileCounts powers the OCR-starvation check (O3): finds per file per field —
        // "bundle: 0" means that file went silent (unread), not outvoted.
        // Decision 11 as ROUTING: the revised readings leave the contract-price pool
        // and vote for their own box instead of competing for this one.
        const budgetSplit = splitRevisedBudget(pile.budget);
        pile.budget = budgetSplit.rest;
        pile.revisedBudget = budgetSplit.revised;

        const bDbg = voteCandidatePoolDebug(pile.budget);
        const rDbg = voteCandidatePoolDebug(pile.revisedBudget);
        const aDbg = voteCandidatePoolDebug(pile.abc);
        const dDbg = voteCandidatePoolDebug(pile.duration);
        const sDbg = voteCandidatePoolDebug(pile.startDate);
        const eDbg = voteCandidatePoolDebug(pile.endDate);
        const dbgFor = (f) => f === 'budget' ? bDbg : f === 'abc' ? aDbg : f === 'duration' ? dDbg : f === 'startDate' ? sDbg : f === 'revisedBudget' ? rDbg : eDbg;

        // CROSS-FIELD EXCLUSION (2026-09-20) — A VALUE THAT HAS ALREADY WON ANOTHER
        // FIELD IS NOT THIS FIELD'S SECOND OPINION.
        // The user noticed the CONTRACT PRICE pick-one had started offering
        // 4,834,297.59 as its alternative. That is the ABC. It reached the budget pool
        // because P-3 made six more pages readable and the ABC figure appears on several
        // of them, so it accumulated enough finds to pass the genuine revised amount.
        // The winner was still right; the ALTERNATIVE was a different field's answer
        // wearing this field's label.
        // ⚠ ONLY THE ALTERNATIVES ARE FILTERED, NEVER THE WINNER. rows[0] is the value
        // in the box, and it must stay in the pair whatever else claims it — a number
        // that is legitimately both the ABC and the contract price (a bid at exactly the
        // ceiling) must not be struck out of its own field.
        // ⚠ AND THIS REMOVES A WRONG ANSWER WITHOUT SUPPLYING A RIGHT ONE. With the ABC
        // gone the runner-up here is an OCR ghost scoring too low to speak, so the note
        // falls SILENT rather than switching to the revised amount. That is honest —
        // there is a clear winner — but it is not the original-vs-revised choice the
        // user remembers. The revised figure is a different FACT about the project, not
        // a competing reading of the same one, and the form has one box for it.
        // ⚠ SCOPED TO ONE DIRECTION, DELIBERATELY: the ABC is struck out of the
        // CONTRACT PRICE's alternatives, and nothing else is filtered anywhere.
        // A general "no field may offer another field's winner" rule was written first
        // and REGRESSED a real case: on a document with no contract price at all, the
        // budget pool is entirely ABC leakage, its winner is one of the ABC's own
        // readings, and the general rule then struck that reading out of the ABC's own
        // pick-one — silencing exactly the .59-vs-.00 note that L-8, L-9 and the
        // independence fix exist to produce.
        // The asymmetry is the evidence, not a preference. The ABC pool's members are
        // competing READINGS OF ONE FIGURE, so filtering them against another field can
        // remove a legitimate alternative. The budget pool legitimately holds DIFFERENT
        // FIGURES — original, revised — plus whatever leaks in, and a runner-up that is
        // exactly the ABC's winner is the ABC leaking rather than a second reading of
        // the price. Only the second case has been observed, so only it is handled.
        const disagreeFor = (field) => {
            const rows = (dbgFor(field) || {}).rows || [];
            if (field !== 'budget' || rows.length < 2) return pooledDisagree(rows);
            const abcWinner = (dbgFor('abc') || {}).winner;
            if (!abcWinner) return pooledDisagree(rows);
            const claimed = normalizeForPool(abcWinner);
            // rows[0] is the value in the box and always stays: a bid at exactly the
            // ceiling is legitimately both, and must not be struck out of its own field.
            const kept = [rows[0]].concat(rows.slice(1).filter(r => r.norm !== claimed));
            return pooledDisagree(kept);
        };
        const fileCounts = {};
        for (const f of ['budget', 'abc', 'duration', 'startDate', 'endDate', 'revisedBudget']) {
            fileCounts[f] = {};
            for (const r of dbgFor(f).rows) {
                for (const fn of r.files) fileCounts[f][fn] = (fileCounts[f][fn] || 0) + r.count;
            }
        }
        return {
            budget: bDbg.winner,
            // Null unless the document actually states a revised amount — the same
            // "absent means never revised" rule the column itself follows.
            revisedBudget: rDbg.winner,
            abc: aDbg.winner,
            duration: dDbg.winner,
            startDate: sDbg.winner,
            endDate: eDbg.winner,
            _debug: { budget: bDbg.rows, revisedBudget: rDbg.rows, abc: aDbg.rows, duration: dDbg.rows, startDate: sDbg.rows, endDate: eDbg.rows },
            _fileCounts: fileCounts,
            _disagree: {
                budget: disagreeFor('budget'),
                revisedBudget: disagreeFor('revisedBudget'),
                abc: disagreeFor('abc'),
                duration: disagreeFor('duration'),
                startDate: disagreeFor('startDate'),
                endDate: disagreeFor('endDate'),
            },
        };
    }

    // ─── PUBLIC API ─────────────────────────────────────────────────────────

    // Parse a document into structured field values. Accepts { text, lines }.
    // Returns { title, location, description, budget, abc, budgetSource, category,
    //           startDate, endDate, duration, contractor, _confidence, _valueKeywordHits }.
    // Phase 5: includes ABC standalone, budgetSource, category, value-side fallbacks, confidence.
    // L3: opts.learned = { labels: { field: [phrase] } } from parser_keywords live rows.
    //   Order per field (hardcoded always wins ties): hardcoded labels → learned labels →
    //   hardcoded value keywords (title/contractor) → learned value keywords. Description has
    //   no learned-label pass (prose blocks don't fit single-line lookup). Learned-label fills
    //   count at label weight in confidence (human-approved vocabulary); learned-value fills count
    //   at value weight like their hardcoded counterparts.
    //   Omitted opts = today's behavior exactly.
    // Called by: document-parser.js runDocumentAutofill() → getCore().parseProjectInfo()
    function parseProjectInfo(doc, opts) {
        const text = doc.text || '';
        const lines = doc.lines || buildLinesFromText(text);
        const learnedLabels = ((opts && opts.learned && opts.learned.labels) || {});
        const learnedFor = (field) => normalizeKeywordList(learnedLabels[field]);
        // Build a literal label pattern from a learned phrase (escaped; mined slices may
        // include the separator and money marker, e.g. "Approved Budget ...: Php" — F-GATE
        // already guarantees label-ish shape or :/- ending at harvest). Anchored at line
        // start; the phrase must be followed by a separator, a digit, end-of-line, or
        // whitespace-then-digit (the swallowed-marker case "...: Php 4,834,297.59").
        // Bare space-then-letters does NOT match, so learned VALUE phrases (title
        // "erection of", contractor suffixes) still fall through to the value path with
        // the full line intact. The lookahead also blocks prefix collisions (ABC vs ABCs).
        // An empty capture means label-alone → next-line fallback in findLabeledValue.
        // Mid-line labels stay unsupported by design.
        // L-9: one definition, shared with the pooled path (see learnedLabelPattern).
        const learnedPatterns = (field) => learnedFor(field).map(learnedLabelPattern);
        // L-9: the per-field normalizers now live at module level as
        // LEARNED_NORMALIZERS so the pooled path applies exactly the same rules.
        // ⚠ THIS ALSO FIXES A DRIFT NOBODY HAD NOTICED: the local copy still used the
        // OLD length-only validators for budgetSource, category, location and
        // contractor, so a LEARNED label bypassed every shape guard the B-queue added.
        // A taught phrase could still fill the budget source with "Accor, Fost =".
        const learnedNormalizers = LEARNED_NORMALIZERS;
        // L-8 (2026-09-20) — A PRIMARY KEY IS NOT A TIE-BREAK.
        // This used to hand findLabeledValue the whole phrase list at once, and that
        // returns the FIRST match. The list arrives in row order, so when a field had
        // two approved phrases the winner was decided by insertion id. On the real
        // document that meant the phrase reading a DAMAGED line won over the one
        // reading a clean one: page 1's OCR says "4,834,297 5g" (the ".59" read as
        // " 5g"), so that label yields 4,834,297.00 while the other yields .59, and
        // approving both made the wrong one win because it was stored first.
        // THE HARDCODED TRACK NEVER WORKED THIS WAY. It pools every find and hands a
        // genuine disagreement to the reviewer through the pick-one control. The
        // learned track now does the same: ask each phrase separately, keep the
        // distinct values, and when two disagree say so rather than silently choosing.
        // The first value is still returned, so a field with one phrase is unchanged.
        const learnedDisagree = {};
        const tryLearnedLabel = (field) => {
            const patterns = learnedPatterns(field);
            if (!patterns.length) return null;
            const norm = learnedNormalizers[field];
            if (!norm) return null;
            const seen = [];
            for (const pat of patterns) {
                const v = findLabeledValue(lines, [pat], norm);
                if (v === null || v === undefined || v === '') continue;
                if (seen.indexOf(v) === -1) seen.push(v);
            }
            if (!seen.length) return null;
            if (seen.length > 1) {
                // Same shape the pooled track produces, so the existing pick-one note
                // renders it without knowing where it came from.
                learnedDisagree[field] = {
                    winner: { value: seen[0], count: 1, files: [] },
                    runnerUp: { value: seen[1], count: 1, files: [] },
                };
            }
            return seen[0];
        };
        // Label-based extraction
        // B-3: the title keeps its own colons — "Phase 2: Completion" is a real title —
        // so only EDGE DEBRIS is stripped here, never an embedded label. A scanned
        // underline read as a bracket ("] Rehabilitation/…") is what this removes.
        let title = stripEdgeDebris(extractTitle(lines, text, learnedFor('title')) || '') || null;
        let contractor = extractContractor(lines);
        let valueKeywordHits = 0;

        // L3 learned-label pass: every still-null field gets its approved phrases.
        // Title keeps its greedy extractTitle result when present; contractor likewise.
        if (!title) title = tryLearnedLabel('title');
        if (!contractor) contractor = tryLearnedLabel('contractor');
        const learnedRestFields = ['location', 'budget', 'abc', 'budgetSource', 'category', 'startDate', 'endDate', 'duration'];

        // Value-side fallback: if no label found, try keyword inside the value itself
        // Low confidence (60) vs label (95) — preview modal shows "guessed — please verify".
        // L3: two passes so hardcoded phrases win ties regardless of line order.
        if (!title) {
            for (const line of lines) {
                const v = isTitleValueCandidate(line);
                if (v) { title = v; valueKeywordHits++; break; }
            }
            if (!title) {
                const extra = learnedFor('title');
                if (extra.length) {
                    for (const line of lines) {
                        const v = isTitleValueCandidate(line, extra);
                        if (v) { title = v; valueKeywordHits++; break; }
                    }
                }
            }
        }
        if (!contractor) {
            for (const line of lines) {
                const v = isContractorValueCandidate(line);
                if (v) { contractor = v; valueKeywordHits++; break; }
            }
            if (!contractor) {
                const extra = learnedFor('contractor');
                if (extra.length) {
                    for (const line of lines) {
                        const v = isContractorValueCandidate(line, extra);
                        if (v) { contractor = v; valueKeywordHits++; break; }
                    }
                }
            }
        }

        const parsed = {
            title: title,
            location: extractLocation(lines),
            description: extractDescription(text),
            budget: extractBudget(lines),
            abc: extractABC(lines),
            budgetSource: extractBudgetSource(lines),
            category: extractCategory(lines),
            startDate: extractStartDate(lines),
            endDate: extractEndDate(lines),
            duration: extractDuration(lines),
            contractor: contractor,
        };
        // L3 learned-label pass for the remaining fields (null-only; hardcoded already ran).
        // Fills count at label weight via countFilled below (no valueKeywordHits increment).
        for (const f of learnedRestFields) {
            if (!parsed[f] && learnedFor(f).length) {
                const v = tryLearnedLabel(f);
                if (v) parsed[f] = v;
            }
        }

        // Confidence scoring: (label hits * 1.0 + valueKeyword hits * 0.6) / total fields
        // Total 11 fields now (was 8). Used by document-parser.js to decide Tier 2/3.
        const labelHits = countFilled(parsed);
        const totalFields = 11;
        const confidence = Math.round(((labelHits + valueKeywordHits * 0.6) / totalFields) * 100);
        parsed._confidence = Math.min(100, confidence);
        // L-8: only present when two approved phrases for one field disagreed. The
        // wrapper merges it into the same channel the pooled disagreement uses.
        if (Object.keys(learnedDisagree).length) parsed._learnedDisagree = learnedDisagree;
        parsed._valueKeywordHits = valueKeywordHits;
        parsed._labelHits = labelHits;

        return parsed;
    }

    // Count how many parsed fields actually have a value.
    // Includes new fields abc, budgetSource, category.
    function countFilled(parsed) {
        // Exclude internal _confidence etc. from count
        const keys = ['title', 'location', 'description', 'budget', 'abc', 'budgetSource', 'category', 'startDate', 'endDate', 'duration', 'contractor'];
        return keys.filter(k => parsed[k] !== null && parsed[k] !== '' && parsed[k] !== undefined).length;
    }

    // AI-3 opt-in trigger (Tier 3, very last): should the preview offer "[Try AI assist?]".
    // Offers when the local pipeline admits weakness — few fills, low confidence, or an
    // unresolved pooled disagreement. Silent on clean runs (including blinded-clean files
    // whose only miss is the blinded ABC: 10/11 fills at ~85 confidence, no disagree).
    // Thresholds are starting values; the AI plan leaves final tuning to live test4 numbers.
    // Pure (no DOM) — the wrapper decides presentation. Called by: runDocumentAutofill().
    function shouldOfferAiAssist(parsed, disagree) {
        const filled = countFilled(parsed || {});
        if (filled === 0) return true;
        if (filled <= 3) return true;
        if (((parsed && parsed._confidence) || 0) < 40) return true;
        if (disagree) {
            for (const k of Object.keys(disagree)) {
                const d = disagree[k];
                if (d && d.winner && d.runnerUp) return true;
            }
        }
        return false;
    }

    return {
        cleanValue,
        toTitleCase,
        parseToInputDate,
        parseDurationToDays,
        groupItemsIntoLines,
        lineItemsToString,
        pageItemsToText,
        buildLinesFromText,
        findLabeledValue,
        extractTitle,
        extractLocation,
        extractDescription,
        extractBudget,
        extractABC,
        // Exported so the suite can assert the RIGHT behaviour for whichever way the
        // switch is set, instead of carrying "intended failures" that hide real ones.
        L3_TEST_BLIND_ABC,
        extractBudgetSource,
        asSourceName,
        centsPreference,
        splitRevisedBudget,
        asCategoryName,
        asCompanyName,
        stripEdgeDebris,
        cutAtEmbeddedLabel,
        editDistance,
        nearestName,
        builtInCoversPhrase,
        previewLearnedExtraction,
        extractCategory,
        extractStartDate,
        extractEndDate,
        extractDuration,
        extractContractor,
        isTitleValueCandidate,
        isContractorValueCandidate,
        TITLE_VALUE_KEYWORDS,
        CONTRACTOR_VALUE_KEYWORDS,
        DESCRIPTION_NEXT_FIELD,
        parseProjectInfo,
        countFilled,
        shouldOfferAiAssist,
        glueMoneyFragments,
        getSectionWeight,
        normalizeForPool,
        collectBudgetCandidates,
        collectAbcCandidates,
        abcWordsAtWindow,
        readAbcWordsAmount,
        isCentsFractionOnly,
        collectDurationCandidates,
        collectDateCandidates,
        voteCandidatePool,
        voteCandidatePoolDebug,
        pooledDisagree,
        splitMismatchGroups,
        scoreOcrSoup,
        shouldReplaceOcrBest,
        mineCorrectionLabel,
        mineCorrectionLabels,
        parseProjectInfoPooled,
    };
})();

// Browser: expose as a global for the classic document-parser.js script.
if (typeof window !== 'undefined') {
    window.documentParserCore = DocumentParserCore;
}

export default DocumentParserCore;
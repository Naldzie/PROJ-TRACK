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
                    if (SCALES[tok] === 1000) { total += current; current = 0; }
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
        return findLabeledValue(lines, [
            /(?:location|project\s*site|site\s*address|project\s*location|barangay|brgy\.?|project\s*address)\s*[:\-]\s*(.*)/i,
        ], lenRange(3, 200));
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

    // Approved Budget / Contract Amount — Contract Price (winning bid after discount).
    // NOTE: Phase 5 keeps this as Contract Price (approved_budget). Standalone ABC is separate.
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
        // L3-TEST TEMP BLINDING (LOCAL ONLY — RESTORE BEFORE ANY COMMIT): ABC patterns disabled
        // so the clean selectable file misses and the loop-first round-trip can run. See plan §6.
        const L3_TEST_BLIND_ABC = true;
        const patterns = L3_TEST_BLIND_ABC ? [] : [
            /(?:approved\s*budget\s*for\s*the\s*contract\s*(?:\(abc\))?|abc)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ];
        if (hasOrigRevised && !L3_TEST_BLIND_ABC) {
            patterns.push(/(?:total\s*budget\s*amount|approved\s*budget|budget\s*amount)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i);
        }
        return findLabeledValue(lines, patterns, asMoney);
    }

    // Budget Source / Funding Source — new in Phase 5.
    // Maps to budget_sources table (9 seeded: GAA, CHED, DPWH, LGU, GAD...).
    // Table lookup is done in document-parser.js (Option A: admin-only warning).
    function extractBudgetSource(lines) {
        return findLabeledValue(lines, [
            /(?:source\s*of\s*funds?|funding\s*source|budget\s*source|fund\s*source|funding\s*agency|source\s*of\s*financing)\s*[:\-]\s*(.*)/i,
        ], lenRange(2, 100));
    }

    // Category / Project Type — new in Phase 5.
    // Maps to categories table (4 seeded: New Construction, Renovation, Repair, Maintenance).
    function extractCategory(lines) {
        return findLabeledValue(lines, [
            /(?:category|project\s*category|project\s*type|type\s*of\s*project|nature\s*of\s*project|classification)\s*[:\-]\s*(.*)/i,
        ], lenRange(3, 50));
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
        ], lenRange(3, 150));
        if (!raw) return null;
        // Try labeled with next-two-lines already handled by findLabeledValue, but also handle inline wrap:
        // If contractor name is bold ALL-CAPS across 2 lines, findLabeledValue already joins next line.
        // Now strip parenthetical " (hereinafter called the ..." tail (Image 2 bold/italic).
        raw = String(raw).trim();
        // Cut at " (hereinafter" or " (hereinafter called the"
        const cut = raw.split(/\s*\(hereinafter.*/i)[0].trim();
        // Also cut at " — hereinafter" etc. and trailing " (hereinafter called the "CONTRACTOR")"
        const cleaned = cut.replace(/\s*\(.*$/, '').trim();
        const normalized = lenRange(3, 150)(cleanValue(cleaned || cut));
        return normalized || cleanValue(cut);
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
        const s = raw.toLowerCase().trim();
        if (s.length < 5 || s.length > 150) return null;
        if (CONTRACTOR_VALUE_KEYWORDS.some(kw => s.includes(kw))) {
            if (!/\b(construction|engineering|builders|enterprises|corporation|supply|contractor|trading|development)\b/i.test(s)) return null;
            return cleanValue(raw);
        }
        const extra = normalizeKeywordList(extraKeywords);
        if (extra.length && extra.some(kw => s.includes(kw))) return cleanValue(raw);
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
                        out.push({ value: val, labeled, weight: w, fileName, pattern: name, excerpt: excerptOf(i) });
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
                if (val && parseFloat(val) > 100000) out.push({ value: val, labeled: false, weight: getSectionWeight(combined) * 0.5, fileName, pattern: fbName, excerpt: excerptOf(i) });
            }
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
                        out.push({ value: val, labeled: true, weight: w, fileName, pattern: isTotal ? 'total-contract-time' : 'labeled', excerpt: excerptOf(i) });
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
                    if (val) { out.push({ value: val, labeled: false, weight: getSectionWeight(t) * 0.5, fileName, pattern: 'within-clause', excerpt: excerptOf(i) }); break; }
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
                if (val) { out.push({ value: val, labeled: true, weight: getSectionWeight([norm[i - 1] || '', norm[i], norm[i + 1] || ''].join(' ')), fileName, pattern: 'labeled', excerpt: excerptOf(i) }); break; }
            }
        }
        return out;
    }

    // Vote one pooled pile: weighted frequency first, tie-break labeled count,
    // then full-length money (digit-count) so tails like 5049.29 lose to full.
    // Called by: parseProjectInfoPooled() per field.
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
            if (!best || g.score > best.score || (g.score === best.score && (g.labeledCount > best.labeledCount || (g.labeledCount === best.labeledCount && g.len > best.len)))) best = g;
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
        for (const c of cands) {
            const norm = normalizeForPool(c.value);
            if (!norm) continue;
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
        rows.sort((a, b) => b.score - a.score || b.labeledCount - a.labeledCount || a.value.localeCompare(b.value));
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
    function mineCorrectionLabel(docText, finalValue) {
        const raw = String(finalValue || '').trim();
        if (raw.length < 3) return null;
        const lines = buildLinesFromText(docText || '');
        const hasDigit = /\d/.test(raw);
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
        if (!hits.length) return null;
        // M2: best-anchored first (gated beats ungated), document order breaks ties.
        hits.sort((a, b) => ((b.gated ? 1 : 0) - (a.gated ? 1 : 0)) || (a.i - b.i));
        const best = hits[0];
        // F-GATE: junk-adjacent fragments (e.g., ")F CANTEEN AT Public" before an ABC
        // figure) must not become dictionary labels — counted, never labeled.
        if (!best.gated) return { label: null, excerpt: best.excerpt };
        if (!best.label) return { label: null, excerpt: best.excerpt };
        return { label: best.label, excerpt: best.excerpt };
    }

    // Pooled parse across files (Step 1e-4 VALUE track).
    // Accepts [{ text, lines?, fileName }]; collects ALL candidates per file into
    // ONE pile per field and votes. Returns { budget, duration, startDate, endDate }.
    // Per-file single values (parseProjectInfo) are still used for the WARNING track.
    // Called by: document-parser.js runDocumentAutofill() when available.
    function parseProjectInfoPooled(docs) {
        const pile = { budget: [], duration: [], startDate: [], endDate: [] };
        for (const d of (docs || [])) {
            const lines = d.lines || buildLinesFromText(d.text || '');
            const fn = d.fileName || d.name || '';
            pile.budget.push(...collectBudgetCandidates(lines, fn));
            pile.duration.push(...collectDurationCandidates(lines, fn));
            pile.startDate.push(...collectDateCandidates(lines, fn, 'start'));
            pile.endDate.push(...collectDateCandidates(lines, fn, 'end'));
        }
        // _debug powers the vote inspector (Step 1f-2): every find + winner per field.
        // _disagree powers Choice B notes (Step 1g-2): per-field winner/runnerUp or null.
        // _fileCounts powers the OCR-starvation check (O3): finds per file per field —
        // "bundle: 0" means that file went silent (unread), not outvoted.
        const bDbg = voteCandidatePoolDebug(pile.budget);
        const dDbg = voteCandidatePoolDebug(pile.duration);
        const sDbg = voteCandidatePoolDebug(pile.startDate);
        const eDbg = voteCandidatePoolDebug(pile.endDate);
        const fileCounts = {};
        for (const f of ['budget', 'duration', 'startDate', 'endDate']) {
            fileCounts[f] = {};
            for (const r of (f === 'budget' ? bDbg : f === 'duration' ? dDbg : f === 'startDate' ? sDbg : eDbg).rows) {
                for (const fn of r.files) fileCounts[f][fn] = (fileCounts[f][fn] || 0) + r.count;
            }
        }
        return {
            budget: bDbg.winner,
            duration: dDbg.winner,
            startDate: sDbg.winner,
            endDate: eDbg.winner,
            _debug: { budget: bDbg.rows, duration: dDbg.rows, startDate: sDbg.rows, endDate: eDbg.rows },
            _fileCounts: fileCounts,
            _disagree: {
                budget: pooledDisagree(bDbg.rows),
                duration: pooledDisagree(dDbg.rows),
                startDate: pooledDisagree(sDbg.rows),
                endDate: pooledDisagree(eDbg.rows),
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
        const learnedPatterns = (field) => learnedFor(field).map(ph =>
            new RegExp('^\\s*(?:' + ph.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')(?=[:\\-\\d]|$|\\s+\\d)\\s*[:\\-]?\\s*(.*)', 'i'));
        // Per-field normalizers for the learned-label pass (mirrors each hardcoded extractor).
        const stripOeTitle = (v) => /^oe\s+/i.test(v) ? v.replace(/^oe\s+/i, '').trim() : v;
        const learnedNormalizers = {
            title: (v) => { const c = lenRange(5, 200)(v); return c ? stripOeTitle(c) : null; },
            location: lenRange(3, 200),
            budget: asBudget,
            abc: asMoney,
            budgetSource: lenRange(2, 100),
            category: lenRange(3, 50),
            startDate: asDate,
            endDate: asDate,
            duration: asDays,
            contractor: lenRange(3, 150),
        };
        const tryLearnedLabel = (field) => {
            const patterns = learnedPatterns(field);
            if (!patterns.length) return null;
            const norm = learnedNormalizers[field];
            if (!norm) return null;
            return findLabeledValue(lines, patterns, norm);
        };
        // Label-based extraction
        let title = extractTitle(lines, text, learnedFor('title'));
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
        extractBudgetSource,
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
        glueMoneyFragments,
        getSectionWeight,
        normalizeForPool,
        collectBudgetCandidates,
        collectDurationCandidates,
        collectDateCandidates,
        voteCandidatePool,
        voteCandidatePoolDebug,
        pooledDisagree,
        splitMismatchGroups,
        scoreOcrSoup,
        shouldReplaceOcrBest,
        mineCorrectionLabel,
        parseProjectInfoPooled,
    };
})();

// Browser: expose as a global for the classic document-parser.js script.
if (typeof window !== 'undefined') {
    window.documentParserCore = DocumentParserCore;
}

export default DocumentParserCore;
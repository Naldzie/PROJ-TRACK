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

        // "Month DD, YYYY" or "Month DD YYYY"
        const wordy = raw.match(/^([A-Za-z]+)\.?\s+(\d{1,2}),?\s+(\d{4})$/);
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

    // Convert a duration phrase into days ("240" → 240, "8 months" → 240, "1 year" → 365). Returns null on failure.
    function parseDurationToDays(raw) {
        if (raw === null || raw === undefined) return null;
        const s = String(raw);
        const m = s.match(/(\d{1,4})/);
        if (!m) return null;
        const num = parseInt(m[1], 10);
        if (!num || num <= 0 || num > 3650) return null;
        if (/month/i.test(s)) return String(Math.round(num * 30));
        if (/year/i.test(s)) return String(num * 365);
        return String(num);
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

        for (let i = 0; i < nonEmpty.length; i++) {
            const line = nonEmpty[i];

            for (const pattern of labelPatterns) {
                const match = line.match(pattern);
                if (!match) continue;

                // Same-line value
                if (match[1] !== undefined && String(match[1]).trim().length > 0) {
                    const inline = normalize ? normalize(cleanValue(match[1])) : cleanValue(match[1]);
                    if (inline !== null && inline !== '') return inline;
                }

                // Next-line value (tables/contracts put the value below the label)
                const next = nonEmpty[i + 1];
                if (next) {
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
    // Parse a money value. Prefers Php-prefixed amounts to avoid bare 44/100 fractions.
    // Enhanced 2026-09-04: for lines like "PESOS & 44/100 (Php 32,588,531.44)" the first
    // number is 44 — we must prefer the Php amount, not the fraction.
    const asMoney = (v) => {
        const s = String(v);
        // Prefer Php/₱-prefixed amount first
        const phpM = s.match(/(?:php|₱)\s*([\d,]+(?:\.\d{1,2})?)/i);
        if (phpM) {
            const num = parseFloat(phpM[1].replace(/,/g, ''));
            if (isFinite(num) && num > 1000) return num.toFixed(2);
        }
        const m = s.match(/([\d,]+(?:\.\d{1,2})?)/);
        if (!m) return null;
        const num = parseFloat(m[1].replace(/,/g, ''));
        // Ignore tiny fractions like 44 from 44/100 unless no better amount exists and value >100
        if (!isFinite(num) || num <= 0) return null;
        if (num < 1000 && phpM === null) {
            // Check if there's a larger number later in the string
            const allNums = [...s.matchAll(/([\d,]+(?:\.\d{1,2})?)/g)].map(x => parseFloat(x[1].replace(/,/g, ''))).filter(n => isFinite(n) && n > 1000);
            if (allNums.length > 0) return Math.max(...allNums).toFixed(2);
        }
        return num.toFixed(2);
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
    function extractTitle(lines, text) {
        // Try greedy: find "Project:" line and join continuation lines
        const nonEmpty = (lines || []).map(l => String(l).trim()).filter(Boolean);
        for (let i = 0; i < nonEmpty.length; i++) {
            const line = nonEmpty[i];
            const m = line.match(/(?:project\s*title|name\s*of\s*project|project\s*name|title\s*of\s*project|project)\s*[:\-]\s*(.*)/i);
            if (!m) continue;
            let value = cleanValue(m[1] || '');
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
                    if (/^(?:[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*,?)/.test(next) || TITLE_VALUE_KEYWORDS.some(kw => next.toLowerCase().includes(kw))) {
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
                    let joined = cleanValue(greedyParts.join(' '));
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
                        if (next.length >= 5 && next.length <= 150) {
                            greedyParts.push(next);
                            const peek = nonEmpty[j + 1] || '';
                            if (/WHEREAS/i.test(peek) || TITLE_VALUE_KEYWORDS.some(kw => next.toLowerCase().includes(kw)) === false && greedyParts.length >= 2) break;
                            if (greedyParts.join(' ').length >= 40) break;
                        }
                    }
                    if (greedyParts.length > 1) {
                        let joined = cleanValue(greedyParts.join(' '));
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
    const DESCRIPTION_NEXT_FIELD = /^\s*(?:approved\s*budget\s*(?:for\s*the\s*contract)?(?:\s*\(abc\))?|abc|contract\s*amount|project\s*cost|total\s*cost|budget|contract\s*price|source\s*of\s*funds?|funding\s*source|budget\s*source|fund\s*source|funding\s*agency|source\s*of\s*financing|category|project\s*category|project\s*type|type\s*of\s*project|nature\s*of\s*project|classification|contractor|awarded\s*to|winning\s*bidder|proponent|company\s*name|firm\s*name|contractor\s*name|start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|date\s*started|contract\s*start|end\s*date|completion\s*date|target\s*completion|contract\s*end|date\s*of\s*completion|expected\s*completion|duration|contract\s*period|contract\s*duration|no\.?\s*of\s*(?:calendar\s*)?days|number\s*of\s*days|calendar\s*days?|working\s*days?|location|project\s*site|site\s*address|project\s*location|barangay|brgy\.?|project\s*address|project\s*title|name\s*of\s*project|project\s*name|title\s*of\s*project|workers?|manpower|headcount)\s*[:\-]/i;

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
        // First try: final/after-discount contract amount context anchor (scanned contract Image 3)
        const finalAmount = findLabeledValue(lines, [
            /(?:final\s*contract\s*amount\s*is)\s*(.*)/i,
            /(?:after\s+applying.*3%\s*discount.*contract\s*amount\s*is)\s*(.*)/i,
            /(?:contract\s*amount\s*is)\s*(.*)/i,
        ], asMoney);
        if (finalAmount) return finalAmount;

        return findLabeledValue(lines, [
            /(?:contract\s*amount|contract\s*price|project\s*cost|total\s*cost|budget)\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
            /(?:approved\s*budget\s*for\s*the\s*contract)\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ], asMoney);
    }

    // Approved Budget for the Contract (ABC) — standalone ABC label.
    // Phase 5 NEW: distinguishes ABC ceiling from Contract Price (savings = ABC - price).
    // Called by: parseProjectInfo() → returns abc field; budget uses extractBudget().
    // Labels: "ABC:" , "Approved Budget for the Contract (ABC):" , "ABC (PHP)"
    function extractABC(lines) {
        return findLabeledValue(lines, [
            /(?:approved\s*budget\s*for\s*the\s*contract\s*(?:\(abc\))?|abc)\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ], asMoney);
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
            /(?:start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|ntp|date\s*started|contract\s*start)\s*[:\-]?\s*(.*)/i,
        ], asDate);
    }

    // Contract / Project End Date.
    function extractEndDate(lines) {
        return findLabeledValue(lines, [
            /(?:end\s*date|completion\s*date|target\s*completion|contract\s*end|date\s*of\s*completion|expected\s*completion)\s*[:\-]?\s*(.*)/i,
        ], asDate);
    }

    // Contract Duration in days.
    function extractDuration(lines) {
        return findLabeledValue(lines, [
            /(?:duration|contract\s*period|contract\s*duration|no\.?\s*of\s*(?:calendar\s*)?days|number\s*of\s*days|calendar\s*days?|working\s*days?)\s*[:\-]?\s*(.*)/i,
        ], asDays);
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
    function isTitleValueCandidate(line) {
        let raw = String(line || '').trim();
        // OCR artifact: "oe Completion..." → strip leading "oe "
        if (/^oe\s+/i.test(raw)) raw = raw.replace(/^oe\s+/i, '').trim();
        const s = raw.toLowerCase().trim();
        if (s.length < 15 || s.length > 200) return null;
        const hasKeyword = TITLE_VALUE_KEYWORDS.some(kw => s.includes(kw));
        if (!hasKeyword) return null;
        // Must be Title Case-ish or ALL-CAPS-ish and contain a construction word
        if (!/construction|renovation|repair|installation|upgrading|improvement|building|embankment|rehabilitation|refurbishment|retrofitting|restructuring|provision|completion|enhancement|analysis/i.test(s)) return null;
        return cleanValue(raw);
    }

    // Check if a line looks like a contractor name based on suffix keywords.
    // Returns cleaned line or null. Strips parenthetical tail like " (hereinafter..."
    function isContractorValueCandidate(line) {
        let raw = String(line || '').trim();
        // Strip parenthetical tail for clean name
        raw = raw.split(/\s*\(hereinafter.*/i)[0].trim().split(/\s*\(.*$/)[0].trim();
        const s = raw.toLowerCase().trim();
        if (s.length < 5 || s.length > 150) return null;
        const hasSuffix = CONTRACTOR_VALUE_KEYWORDS.some(kw => s.includes(kw));
        if (!hasSuffix) return null;
        if (!/\b(construction|engineering|builders|enterprises|corporation|supply|contractor|trading|development)\b/i.test(s)) return null;
        return cleanValue(raw);
    }

    // ─── PUBLIC API ─────────────────────────────────────────────────────────

    // Parse a document into structured field values. Accepts { text, lines }.
    // Returns { title, location, description, budget, abc, budgetSource, category,
    //           startDate, endDate, duration, contractor, _confidence, _valueKeywordHits }.
    // Phase 5: includes ABC standalone, budgetSource, category, value-side fallbacks, confidence.
    // Called by: document-parser.js runDocumentAutofill() → getCore().parseProjectInfo()
    function parseProjectInfo(doc) {
        const text = doc.text || '';
        const lines = doc.lines || buildLinesFromText(text);
        // Label-based extraction
        let title = extractTitle(lines, text);
        let contractor = extractContractor(lines);
        let valueKeywordHits = 0;

        // Value-side fallback: if no label found, try keyword inside the value itself
        // Low confidence (60) vs label (95) — preview modal shows "guessed — please verify".
        if (!title) {
            for (const line of lines) {
                const v = isTitleValueCandidate(line);
                if (v) { title = v; valueKeywordHits++; break; }
            }
        }
        if (!contractor) {
            for (const line of lines) {
                const v = isContractorValueCandidate(line);
                if (v) { contractor = v; valueKeywordHits++; break; }
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
    };
})();

// Browser: expose as a global for the classic document-parser.js script.
if (typeof window !== 'undefined') {
    window.documentParserCore = DocumentParserCore;
}

export default DocumentParserCore;
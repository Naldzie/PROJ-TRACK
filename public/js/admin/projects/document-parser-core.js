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
        lines.sort((a, b) => a.y - b.y);
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
    // normalize(value) returns the cleaned string or null to reject. labelPatterns
    // are regexes whose first capture group is the optional inline value.
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
            }
        }

        return null;
    }

    // Normalizer helpers used by the field extractors.
    const lenRange = (min, max) => (v) => (v && v.length >= min && v.length <= max) ? v : null;
    const asDate = (v) => parseToInputDate(v);
    const asDays = (v) => parseDurationToDays(v);
    const asMoney = (v) => {
        const m = String(v).match(/(?:php|₱)?\s*([\d,]+(?:\.\d{1,2})?)/);
        if (!m) return null;
        const num = parseFloat(m[1].replace(/,/g, ''));
        return (isFinite(num) && num > 0) ? num.toFixed(2) : null;
    };

    // ─── FIELD EXTRACTORS ───────────────────────────────────────────────────

    // Project Title: label line (or next line) or an ALL-CAPS line that looks like a title.
    function extractTitle(lines, text) {
        const labeled = findLabeledValue(lines, [
            /(?:project\s*title|name\s*of\s*project|project\s*name)\s*[:\-]\s*(.*)/i,
            /(?:title\s*of\s*project)\s*[:\-]\s*(.*)/i,
        ], lenRange(5, 200));
        if (labeled) return labeled;

        // Fallback: an ALL-CAPS line that looks like a project title
        const candidate = (lines || []).find(line => (
            line === line.toUpperCase() &&
            line.length >= 15 &&
            line.length <= 150 &&
            /construction|renovation|repair|installation|upgrading|improvement|building/i.test(line)
        ));
        return candidate ? toTitleCase(candidate) : null;
    }

    // Location / Project Site.
    function extractLocation(lines) {
        return findLabeledValue(lines, [
            /(?:location|project\s*site|site\s*address|project\s*location|barangay|address)\s*[:\-]\s*(.*)/i,
        ], lenRange(3, 200));
    }

    // Description / Scope of Work — prose, so a flowing regex over the raw text.
    function extractDescription(text) {
        const match = String(text || '').match(
            /(?:scope\s*of\s*work|project\s*description|description|objectives?|work\s*description)\s*[:\-]\s*([\s\S]{20,800}?)(?:\n{2,}|$)/i
        );
        if (match) {
            const value = cleanValue(match[1]);
            if (value && value.length >= 20) {
                return value.substring(0, 500);
            }
        }
        return null;
    }

    // Approved Budget / Contract Amount (₱ or PHP, optionally with "(ABC)").
    function extractBudget(lines) {
        return findLabeledValue(lines, [
            /(?:approved\s*budget\s*for\s*the\s*contract|contract\s*amount|approved\s*budget|project\s*cost|total\s*cost|budget|contract\s*price)\s*(?:\(abc\))?\s*[:\-]?\s*(?:php|₱)?\s*(.*)/i,
        ], asMoney);
    }

    // Contract / Project Start Date.
    function extractStartDate(lines) {
        return findLabeledValue(lines, [
            /(?:start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|date\s*started|contract\s*start)\s*[:\-]?\s*(.*)/i,
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
    function extractContractor(lines) {
        return findLabeledValue(lines, [
            /(?:contractor|awarded\s*to|proponent|company\s*name|firm\s*name)\s*[:\-]\s*(.*)/i,
        ], lenRange(3, 150));
    }

    // ─── PUBLIC API ─────────────────────────────────────────────────────────

    // Parse a document into structured field values. Accepts { text, lines }.
    // Returns { title, location, description, budget, startDate, endDate, duration, contractor }.
    function parseProjectInfo(doc) {
        const text = doc.text || '';
        const lines = doc.lines || buildLinesFromText(text);
        return {
            title: extractTitle(lines, text),
            location: extractLocation(lines),
            description: extractDescription(text),
            budget: extractBudget(lines),
            startDate: extractStartDate(lines),
            endDate: extractEndDate(lines),
            duration: extractDuration(lines),
            contractor: extractContractor(lines),
        };
    }

    // Count how many parsed fields actually have a value.
    function countFilled(parsed) {
        return Object.values(parsed).filter(v => v !== null && v !== '').length;
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
        extractStartDate,
        extractEndDate,
        extractDuration,
        extractContractor,
        parseProjectInfo,
        countFilled,
    };
})();

// Browser: expose as a global for the classic document-parser.js script.
if (typeof window !== 'undefined') {
    window.documentParserCore = DocumentParserCore;
}

export default DocumentParserCore;
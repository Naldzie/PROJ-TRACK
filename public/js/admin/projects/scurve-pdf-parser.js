// ===================================================
// SCURVE PDF PARSER
// Imports S-curve data from a contractor PDF schedule
// (e.g. CONSCHEDSAVINGS-style A3 tables) entirely in the
// browser. Uses PDF.js (via public/js/vendor/pdfjs-loader.js)
// for text+coordinates and the pure core in
// scurve-pdf-parser-core.js for layout inference.
//
// Exposes window.parseSCurvePdfFile(file) → Promise<{months, meta}|null>.
// null means the layout was not recognised → callers fall back to
// the "please upload the .xlsx" notice.
// ===================================================

async function scurvePdfCore() {
    return window.scurvePdfParserCore || null;
}

/**
 * Parse a PDF File/Blob into baseline months (same shape as the Excel
 * importer). Returns null when no S-curve table is recognised.
 */
window.parseSCurvePdfFile = async function (file) {
    const core = await scurvePdfCore();
    if (!core) throw new Error('The S-curve PDF parser is not loaded.');

    if (typeof window.loadPdfJs !== 'function') {
        throw new Error('PDF.js is not available on this page.');
    }
    const pdfjs = await window.loadPdfJs();

    const data = await file.arrayBuffer();
    const doc = await pdfjs.getDocument({ data }).promise;

    const itemsByPage = [];
    const maxPages = Math.min(doc.numPages, 20);
    for (let p = 1; p <= maxPages; p++) {
        const page = await doc.getPage(p);
        const content = await page.getTextContent();
        itemsByPage.push(content.items || []);
    }

    // F-C stash for the AI slicer: the file (re-rendered on demand), the
    // machine-read words per page (names alignment), and the table pages (the
    // P2-0 header hits — those pages hold the table, so they slice first).
    try {
        window._scurvePdfFile = file;
        window._scurvePdfPageTexts = itemsByPage.map((items) =>
            (items || []).map((it) => (it && it.str) || '').join(' ')
        );
        // PDF-3 — the same words WITH their positions. The AI answers later (the
        // user clicks), by which time this parse is long gone, and a label can
        // only become a column band if we still know where it sat. Compact by
        // design: {str,x,y,width} per token, capped with the same 20-page budget.
        //
        // THESE FOUR KEYS ARE A CONTRACT, not a convenience. The core's own
        // helpers fall back to exactly them when an item carries no pdf.js
        // transform — groupItemsIntoLines reads item.y and linesToTokenLines
        // reads item.x/item.width — so a token in this shape flows through the
        // whole core unchanged. Both omissions fail SILENTLY, and they fail
        // DIFFERENTLY: drop y and every token collapses onto one line at y=0, so
        // the derivation refuses outright (this is how the first cut of this
        // stash shipped dead); drop width and it still SUCCEEDS, with every band
        // drifted, which would teach a skewed layout instead of teaching
        // nothing. See the plan file's §9 entry for 2026-09-13 before changing
        // the shape; tests/js/scurve-pdf-parser.test.mjs pins both.
        window._scurvePdfPageTokens = itemsByPage.map((items) =>
            (items || []).map((it) => ({
                str: (it && it.str) || '',
                x: it && it.transform ? it.transform[4] : ((it && it.x) || 0),
                y: it && it.transform ? it.transform[5] : ((it && it.y) || 0),
                width: (it && it.width) || 0,
            }))
        );
    } catch (e) { /* stash is best-effort */ }

    // F-D — learned month-header words (approved scurveMonth rows) ride as the
    // lowest-priority fallback. The L3 loader is SHARED (learned-keywords-loader.js,
    // loaded by the role layouts), so this resolves on the create wizard AND the
    // project-detail Baseline tab; contractors and any failure reproduce today's
    // behavior exactly — never blocks the import.
    let monthWords = null;
    try {
        if (typeof window.loadLearnedKeywords === 'function') {
            const pairs = await window.loadLearnedKeywords();
            const words = pairs && pairs.labels && pairs.labels.scurveMonth;
            if (Array.isArray(words) && words.length) monthWords = words;
        }
    } catch (e) { /* learned vocabulary is best-effort */ }

    // PDF-2 — approved LAYOUTS for this file type. Consulted only when the rules
    // recognise nothing at all (the core runs them first and keeps their answer),
    // so a readable PDF is unaffected by whatever has been approved. Any failure
    // yields none, which is exactly today's behavior.
    let liveMaps = null;
    try {
        if (typeof window.loadLiveScurveLayouts === 'function') {
            const maps = await window.loadLiveScurveLayouts();
            if (Array.isArray(maps) && maps.length) liveMaps = maps;
        }
    } catch (e) { /* learned layouts are best-effort */ }

    // P2-0 — month-attribution debug: set window.__scurvePdfDebug = true in the
    // console before importing to log which page/row/cell built each month total.
    const debug = [];
    const out = core.parseSCurvePdf(itemsByPage, { debug, monthWords, liveMaps });

    // E1-PDF (PDF-1) — a PDF the rules could not read proposes a layout for the
    // review desk, exactly as a failed Excel import does. Derived from the page's
    // own geometry (never from rule-side header detection, or the files that most
    // need learning could never get it), stashed for the caller to post. Nothing
    // here is used: a capture only becomes readable after a human approves it.
    try {
        window._scurvePdfCapture = (!out && typeof core.derivePdfLayout === 'function')
            ? core.derivePdfLayout(itemsByPage)
            : null;
    } catch (e) { window._scurvePdfCapture = null; }
    try {
        if (window.__scurvePdfDebug && debug.length) console.info('[scurve-pdf] attribution', debug);
        const pages = [];
        debug.forEach((d) => {
            if (d && d.t === 'header' && Number.isInteger(d.page) && pages.indexOf(d.page) === -1) {
                pages.push(d.page);
            }
        });
        window._scurvePdfTablePages = pages;
    } catch (e) { /* logging never blocks imports */ }
    return out;
};

/**
 * Render the weakest S-curve PDF pages to image slices for the F-B endpoint.
 *
 * Re-opens the stashed file (table pages first, then leading pages), renders
 * at ~150 DPI (scale 2.08) with the AI-3 pixel budget (skip absurd canvases),
 * and returns { slices: [{data, mimeType}], pageWords: [...] } for those
 * pages. Best-effort: skips unreadable pages, throws only when nothing
 * sliceable remains.
 */
window.buildSCurvePdfSlices = async function (maxN) {
    const file = window._scurvePdfFile || null;
    if (!file) throw new Error('Original file no longer available — re-import to retry.');
    if (typeof window.loadPdfJs !== 'function') {
        throw new Error('PDF.js is not available on this page.');
    }
    const limit = Math.max(1, Math.min(maxN || 2, 3));
    const pdfjs = await window.loadPdfJs();
    const buf = await file.arrayBuffer();
    const doc = await pdfjs.getDocument({ data: buf }).promise;
    try {
        const tablePages = (window._scurvePdfTablePages || []).filter(
            (p) => Number.isInteger(p) && p >= 0 && p < doc.numPages
        );
        const picks = tablePages.slice(0, limit);
        for (let p = 0; picks.length < limit && p < Math.min(doc.numPages, 20); p++) {
            if (picks.indexOf(p) === -1) picks.push(p);
        }
        const texts = window._scurvePdfPageTexts || [];
        const slices = [];
        const pageWords = [];
        for (const p of picks) {
            try {
                const page = await doc.getPage(p + 1);
                const viewport = page.getViewport({ scale: 2.08 });
                // AI-3 pixel budget: absurd canvases never ride the request.
                if (Math.max(viewport.width, viewport.height) > 4000) continue;
                const canvas = document.createElement('canvas');
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                const url = canvas.toDataURL('image/png');
                if (!url || url.length <= 1000) continue;
                slices.push({ data: url.split(',')[1], mimeType: 'image/png' });
                pageWords.push(texts[p] || '');
                if (slices.length >= limit) break;
            } catch (e) { /* next page */ }
        }
        if (slices.length === 0) {
            throw new Error('The PDF pages could not be rendered to images.');
        }
        return { slices, pageWords };
    } finally {
        try { await doc.destroy(); } catch (e) { /* cleanup is best-effort */ }
    }
};
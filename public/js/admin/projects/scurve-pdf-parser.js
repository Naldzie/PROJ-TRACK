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

    // P2-0 — month-attribution debug: set window.__scurvePdfDebug = true in the
    // console before importing to log which page/row/cell built each month total.
    const debug = [];
    const out = core.parseSCurvePdf(itemsByPage, { debug });
    try {
        if (window.__scurvePdfDebug && debug.length) console.info('[scurve-pdf] attribution', debug);
    } catch (e) { /* logging never blocks imports */ }
    return out;
};
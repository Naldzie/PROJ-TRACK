// ===================================================
// DOCUMENT PARSER
// Extracts project information from uploaded PDFs and
// autofills the create-project form fields.
//
// DEPENDENCIES:
//   - document-parser-core.js (ESM module → window.documentParserCore)
//   - PDF.js (via public/js/vendor/pdfjs-loader.js → window.pdfjsLib)
//   - tesseract.js (lazy CDN, OCR fallback for scanned PDFs)
// CALLED BY:    form-navigation.js (on page 1 → 2 transition) and the
//               "Preview Extracted Data" button on Page 1.
// ===================================================

// Access the pure parser core lazily (the ESM core module may not have
// executed yet when this classic script runs).
function getCore() {
    return window.documentParserCore || {};
}

// ===================================================
// ENTRY POINT
// Parses all pending PDFs and shows the preview modal
// (Apply fills the form). Returns a Promise.
// Superseded runs (started again while an older run is
// still in flight) abort quietly via the run-id guard.
// ===================================================
let _autofillRunId = 0;

// Cancel any in-flight autofill run (called when the create-project modal
// closes/reopens). Bumping the run id supersedes the current run, so its
// guards abort it before it can re-show the scanning banner or pop the
// preview modal while the modal is closed. The banner element is also
// removed entirely so nothing stale can linger across modal sessions.
function cancelDocumentAutofill() {
    _autofillRunId++;
    _ocrPagesDone = 0;
    // S3: fresh-popup-fresh-state — clear saved vote/disagreement/scan state AND remove
    // the dynamically built banner/note boxes, so no previous run can linger on screen
    // (stale-banner bug: old red banner survived modal close/reopen into single-file runs).
    // Runs on every modal open (via resetModalToCreateMode), NOT per preview run, so the
    // re-scan depth chosen inside a session is preserved for that session's re-runs.
    if (typeof window !== 'undefined') {
        window._autofillMismatch = null;
        window._autofillDisagree = null;
        window._autofillVoteDebug = null;
        delete window._ocrPageLimit;
        // R2 rotations are per-session manual fixes — a fresh popup starts upright.
        window._ocrRotations = {};
        window._ocrRotateTargets = [];
        // OSD probe + engine-failure flag are per-session: a fresh popup re-probes once
        // (network conditions may have changed), never inheriting a pinned fallback.
        window._ocrLangMode = null;
        window._ocrOsdMisses = 0;
        window._ocrEngineFailed = false;
        // F-COPY/L1 stashes belong to the run that made them — never reuse across sessions.
        window._autofillDocTexts = [];
        window._autofillDocNames = [];
        window._autofillOriginal = null;
        // Snapshots: original Files are per-session too (object URLs / buffers must not leak).
        window._autofillPdfFiles = [];
    }
    for (const id of ['autofillPreviewMismatch', 'autofillPreviewTruncated', 'autofillPreviewCopyWrap']) {
        try {
            const el = document.getElementById(id);
            if (el && el.remove) el.remove();
        } catch (e) { /* best-effort cleanup */ }
    }
    removeAutofillBanner();
    closeAutofillPreview();
}

function removeAutofillBanner() {
    const banner = document.getElementById('autofillBanner');
    if (banner && banner.remove) banner.remove();
}

async function runDocumentAutofill() {
    // Only run in create mode — never overwrite data when editing
    if (window._projectModalMode === 'edit') return;

    const myRunId = ++_autofillRunId;

    // Get only PDF files from the pending documents list
    // Phase 5 2026-09-04: exclude S-curve PDFs from normal doc scan — they have separate parser
    // and their numbers (e.g., 600k) previously bled into budget/title (normal-first priority).
    const pdfDocs = (getPendingDocuments() || []).filter(doc => {
        if (!doc.fileName.toLowerCase().endsWith('.pdf')) return false;
        // Skip S-curve / Schedule-typed PDFs (use scurveImportCore if loaded, else filename heuristic)
        try {
            const core = window.scurveImportCore;
            if (core && typeof core.isSCurveCandidate === 'function') {
                if (core.isSCurveCandidate(doc)) return false;
            } else if (/s[-_\s]?curve|schedule/i.test(doc.fileName)) {
                return false;
            }
        } catch (e) { /* ignore */ }
        return true;
    });

    if (pdfDocs.length === 0) {
        hideAutofillBanner();
        return;
    }

    showAutofillScanning('Scanning documents…');

    // Snapshots (2026-09-07): keep the original File per PDF so the per-page "save image"
    // diagnostic can re-render any scanned page later without a server round-trip.
    // Stashed per run; cleared in cancelDocumentAutofill with the other run state.
    try {
        window._autofillPdfFiles = (pdfDocs || []).map(d => ({ fileName: d.fileName, file: d.file }));
    } catch (e) { /* stash is best-effort */ }

    try {
        // Phase 5 Step 1d: per-file extraction + voting (greedy title / cut contractor already handled in core).
        // This gives cross-file validation: 3 files agreeing raises confidence, 2 different titles flags mismatch.
        const perFileData = []; // [{ fileName, text, parsed }]
        let ocrUsed = false;
        // O2/N3: per-file scan report — { fileName, scannedTotal, scannedRead, truncated }.
        // Rendered in the preview note ("read X of Y scanned pages"); proves partial reads.
        const scanReports = [];
        // O2: files whose scanned pages exceeded the photo-read budget (partial read).
        const truncatedFiles = [];

        for (const doc of pdfDocs) {
            if (myRunId !== _autofillRunId) return;

            try {
                const { text, ocr, truncated, scannedTotal, scannedRead, ocrPages, allLandscape, scannedPageNums, landscapePages, pagesVisited, numPages } = await extractTextFromPDF(doc.file, myRunId);
                if (ocr) ocrUsed = true;
                // O2: remember files whose scan was cut short by the page budget.
                if (truncated) truncatedFiles.push(doc.fileName);
                // N3/R2/R-reach: remember real counts + WHICH pages were photo-read for the note
                // and the per-page rotate control (even uncut ones). allLandscape flags fully-
                // sideways scans for the pre-preview straighten-all rescue.
                if (ocr || (scannedTotal || 0) > 0) {
                    scanReports.push({ fileName: doc.fileName, scannedTotal: scannedTotal || 0, scannedRead: scannedRead || 0, truncated: !!truncated, ocrPages: ocrPages || [], allLandscape: !!allLandscape, scannedPageNums: scannedPageNums || [], landscapePages: landscapePages || [], pagesVisited: pagesVisited || 0, numPages });
                }
                if (text && text.trim().length > 0) {
                    const parsedSingle = getCore().parseProjectInfo({ text });
                    perFileData.push({ fileName: doc.fileName, text, parsed: parsedSingle });
                }
                // L1 learning loop: keep the raw document texts so Apply-time mining can
                // anchor a corrected value back to its source line (never sent to server).
                // F-COPY reuses the same stash (plus file names) for the copy-text button —
                // recorded for EVERY processed file, even empty/unparsed ones, so failed
                // runs stay diagnosable (copy button included on the empty-run warning).
                try {
                    const texts = [];
                    const names = [];
                    for (const doc of pdfDocs) {
                        const entry = perFileData.find(e => e.fileName === doc.fileName);
                        texts.push(entry ? entry.text : '');
                        names.push(doc.fileName);
                    }
                    window._autofillDocTexts = texts;
                    window._autofillDocNames = names;
                } catch (e) { /* stash is best-effort */ }
            } catch (err) {
                // One file failing shouldn't stop the rest
                console.warn('[DocumentParser] Could not read PDF:', doc.fileName, err);
            }
        }

        if (myRunId !== _autofillRunId) return;

        if (perFileData.length === 0) {
            // Engine failure (reader itself never loaded) is NOT an empty document —
            // say so explicitly instead of blaming the files.
            const engineFailed = (typeof window !== 'undefined') && window._ocrEngineFailed;
            // R-reach: stash reports even on empty runs so the straighten-all rescue below
            // can name the sideways files (no preview ever opens for them).
            try { window._autofillScanReports = scanReports.slice(); } catch (e) {}
            showAutofillWarning(
                engineFailed
                    ? 'The scanned-page reader could not load (network needed on first use). ' +
                      'Check your connection, reopen this popup to retry, then preview again.'
                    : 'Could not extract text from the uploaded PDF(s). ' +
                      'The files may be scanned images with no readable text.',
                { straighten: true }
            );
            return;
        }

        // Vote per field across files (>50% = auto-fill, else top vote + red banner let user pick)
        // Step 1e-4 VALUE track: pool ALL finds from ALL files (not winners-only) when
        // core.parseProjectInfoPooled exists; WARNING track (red banner) still uses
        // per-file winners via getMismatchInfo so file accountability is kept.
        let parsed = voteParsedFields(perFileData);
        // Step 1g-2 Choice B: per-field winner/runnerUp pairs from the pooled vote
        // (null when one clear winner). Set inside the pooled block below.
        let pooledDisagreeInfo = null;
        try {
            const core = getCore();
            if (core && typeof core.parseProjectInfoPooled === 'function' && perFileData.length > 0) {
                const pooledDocs = perFileData.map(e => ({ text: e.text, fileName: e.fileName }));
                const pooled = core.parseProjectInfoPooled(pooledDocs) || {};
                // Override only fields the pool resolved (budget/duration/dates);
                // keep voted title/contractor/location/etc. from per-file vote.
                for (const f of ['budget', 'duration', 'startDate', 'endDate']) {
                    if (pooled[f]) parsed[f] = pooled[f];
                }
                // Step 1f-2 vote inspector: stash + log every find and the winner.
                // How to use: in the browser console run `window._parserDebug = true`,
                // then upload the PDF(s) again. Copy the tables and paste them here.
                try {
                    window._autofillVoteDebug = pooled._debug || null;
                    pooledDisagreeInfo = pooled._disagree || null;
                    if (typeof window !== 'undefined' && window._parserDebug && pooled._debug) {
                        console.groupCollapsed('[Parser] pooled vote inspector (all finds → winner)');
                        for (const f of ['budget', 'duration', 'startDate', 'endDate']) {
                            const rows = (pooled._debug && pooled._debug[f]) || [];
                            console.log('[Parser] field:', f, '| winner:', pooled[f], '| finds:', rows.length);
                            if (rows.length && console.table) console.table(rows.slice(0, 10));
                        }
                        // O3: finds per file per field — "bundle: 0" means that file went
                        // silent (unread/truncated), not outvoted.
                        if (pooled._fileCounts) console.log('[Parser] finds per file:', pooled._fileCounts);
                        console.groupEnd();
                    }
                } catch (e) { /* inspector is best-effort only */ }
            }
        } catch (e) { /* pooled vote is best-effort — fall back to per-file vote */ }
        const mismatchInfo = getMismatchInfo(perFileData);

        if (myRunId !== _autofillRunId) return;

        if (getCore().countFilled(parsed) === 0) {
            // R-reach: same stash so the straighten-all rescue can name sideways files here too.
            try { window._autofillScanReports = scanReports.slice(); } catch (e) {}
            showAutofillWarning(
                'Text was extracted from the documents, but no recognisable project ' +
                'fields were found. Please fill in the fields manually.',
                { straighten: true }
            );
            return;
        }

        // If files disagree (e.g., 2 titles for 2 different projects), red banner will show in preview.
        // Do NOT block Apply — let user pick (user choice 2026-09-04).
        if (mismatchInfo.hasMismatch) {
            // Store for preview modal to render
            window._autofillMismatch = mismatchInfo;
        } else {
            window._autofillMismatch = null;
        }

        // Step 1g-2 Choice B: per-field winner/runnerUp pairs from the pooled vote.
        // Stored for the preview modal to render amber pick-one notes. Silent when clear.
        window._autofillDisagree = pooledDisagreeInfo;

        // Show the preview modal — nothing is written to the form until Apply.
        // S2: the run id travels with the call so a slow older run can never paint over
        // a newer preview (stale-banner class of bug). S0: every render logs its run id,
        // file list, and banner decision — the next stale render self-identifies.
        try {
            console.log('[Parser] preview run ' + myRunId + ': files=[' + perFileData.map(e => e.fileName).join(', ') + '] mismatch=' + (!!mismatchInfo.hasMismatch));
            // P2: the vote tables are stashed on EVERY preview (flag or not) — this hint line
            // means nobody has to memorize the recovery command or re-upload for tables.
            console.log('[Parser] tip: type window._autofillVoteDebug in the console to inspect all finds (values, patterns, excerpts, files).');
            // R-reach: scan reports stashed per run so the straighten-all rescue can name
            // sideways files even from the preview path.
            window._autofillScanReports = scanReports.slice();
        } catch (e) { /* logging is best-effort */ }
        showAutofillPreview(parsed, { ocrUsed, mismatch: mismatchInfo, disagree: window._autofillDisagree, truncatedFiles, scanReports, runId: myRunId });

    } catch (err) {
        console.error('[DocumentParser] Autofill failed:', err);
        // Silent failure — the user can still fill the form manually
        hideAutofillBanner();
    }
}

// ===================================================
// PDF.JS + OCR LOADING
// ===================================================

// Resolve the PDF.js library (loader tries multiple CDNs).
async function getPdfJs() {
    if (window.pdfjsLib) return window.pdfjsLib;
    if (typeof window.loadPdfJs === 'function') return window.loadPdfJs();

    // Legacy fallback: wait up to 3s for an external loader to set window.pdfjsLib.
    await new Promise((resolve, reject) => {
        let waited = 0;
        const check = setInterval(() => {
            waited += 100;
            if (typeof window.pdfjsLib !== 'undefined') {
                clearInterval(check);
                resolve();
            } else if (waited >= 3000) {
                clearInterval(check);
                reject(new Error('PDF.js did not load.'));
            }
        }, 100);
    });
    return window.pdfjsLib;
}

// Lazy-load tesseract.js (UMD build exposes window.Tesseract). Reuses the promise.
let _tesseractPromise = null;
function loadTesseract() {
    if (window.Tesseract) return Promise.resolve(window.Tesseract);
    if (!_tesseractPromise) {
        _tesseractPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
            script.onload = () => resolve(window.Tesseract);
            script.onerror = () => {
                _tesseractPromise = null;
                reject(new Error('Could not load tesseract.js'));
            };
            document.head.appendChild(script);
        });
    }
    return _tesseractPromise;
}

// OCR a textless PDF page by rendering it to a canvas and running tesseract.
// O1: the scanned-page budget is PER FILE (reset by extractTextFromPDF for each file)
// and adjustable per run via window._ocrPageLimit (default OCR_PAGE_LIMIT = 10).
// Each photo-read page costs ~2s on slow connections — see the re-scan control.
let _ocrPagesDone = 0;
const OCR_PAGE_LIMIT = 10;
function ocrPageLimit() {
    const custom = (typeof window !== 'undefined') ? Number(window._ocrPageLimit) : NaN;
    if (isFinite(custom) && custom >= 1 && custom <= 50) return Math.floor(custom);
    return OCR_PAGE_LIMIT;
}
// Warm the photo-reader script when the create-project popup opens (called from
// resetModalToCreateMode). Downloads the reader code early so the first preview does not
// pay the full load cost mid-scan. Language data still fetches on first recognize —
// that part is covered by the probe below, not here. Best-effort, never throws.
function warmTesseract() {
    try {
        loadTesseract().catch(() => { /* offline/CDN hiccup — lazy load retries later */ });
    } catch (e) { /* best-effort */ }
}

// OCR group 1: light canvas cleanup (no new library) — grayscale + contrast stretch via
// plain 2D canvas APIs, so washed-out photocopies reach the reader with fuller blacks.
// Runs on the already-rendered canvas, in place, best-effort (any failure keeps the original).
// F2 conditional gate (2026-09-07): the global stretch is applied ONLY to washed-out pages
// (no deep blacks to anchor on + compressed tonal range) and SKIPPED on already-clean pages,
// restoring pre-group1 behavior exactly where pixels look healthy. An unconditional remap
// calibrated to outlier specks (stamps, glare) can crush faint cell text into the background —
// the prime suspect for test4 p1 @0° + p2 @90° yielding 0 on upright, human-crisp pixels.
// Decision is logged under window._parserDebug (applied/skipped + sampled range).
// Called by: ocrPage() once per scanned page, before any recognize() attempt.
function preprocessOcrCanvas(canvas) {
    try {
        const ctx = canvas.getContext('2d');
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const d = img.data;
        let lo = 255, hi = 0, sum = 0, n = 0;
        for (let i = 0; i < d.length; i += 16) {
            const g = (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
            if (g < lo) lo = g;
            if (g > hi) hi = g;
            sum += g; n++;
        }
        if (hi - lo < 8) return;
        // F2 gate: stretch only washed-out pages (faded gray text, no true blacks, narrow
        // range). Clean pages (real black text, healthy range) skip untouched — the stretch
        // is near-identity for them at best and outlier-calibrated damage at worst.
        const washed = (lo >= 100) && ((hi - lo) <= 140);
        if (typeof window !== 'undefined' && window._parserDebug) {
            try { console.log('[Parser] OCR cleanup ' + (washed ? 'applied' : 'skipped') + ': lo=' + Math.round(lo) + ' hi=' + Math.round(hi) + ' mean=' + Math.round(sum / Math.max(1, n))); } catch (e) {}
        }
        if (!washed) return;
        const range = (hi - lo) || 1;
        for (let i = 0; i < d.length; i += 4) {
            const g = (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
            const v = Math.max(0, Math.min(255, Math.round(((g - lo) / range) * 255)));
            d[i] = v; d[i + 1] = v; d[i + 2] = v;
        }
        ctx.putImageData(img, 0, 0);
    } catch (e) { /* preprocessing is best-effort — original canvas still works */ }
}

// OCR group 1: plain-English recognize with a PSM retry ladder (no new library).
// Default segmentation first; pages that come back short (<10 chars) retry as a uniform
// text block (PSM 6: tables/forms) then sparse text (PSM 11: fragments).
// F3' (2026-09-07): a final single-column rung (PSM 4) fires ONLY while the page is still
// EMPTY (no text at all yet) — the one layout prior that fits a sparse table page and appears
// nowhere else in the ladder. Soupy-but-yielding pages already paid 3 reads and must not be
// taxed a 4th. (Literal PSM-6-first was verified redundant: the ladder already runs PSM 6 on
// every empty page, and each recognize() is an independent worker, so order is meaningless.)
// G-soup: pages that come back LONG but soupy (symbol salad per core.scoreOcrSoup) get one
// PSM 6 retry, keeping whichever reads cleaner — never a blind replace (a noisy-but-legit
// table row must survive its own retry). Retries fire ONLY on failure; clean pages pay nothing.
// Page budget counts pages, not attempts (worst case 4 reads per dead-empty page — bounded).
// Returns '' only when every attempt throws (engine failure).
// Called by: ocrPage() for every plain-'eng' read (OSD retry, OSD-throw fallback, steady path).
async function recognizeEnglish(tesseract, canvas, markEngineFailed) {
    const core = getCore();
    const soupOf = (t) => {
        try {
            if (core && typeof core.scoreOcrSoup === 'function') return core.scoreOcrSoup(t);
        } catch (e) {}
        return { soupy: (t || '').trim().length < 10 };
    };
    const attempts = [{}, { tessedit_pageseg_mode: '6' }, { tessedit_pageseg_mode: '11' }, { tessedit_pageseg_mode: '4', emptyOnly: true }];
    let best = '';
    let bestSoupy = true;
    let threw = false;
    for (let ai = 0; ai < attempts.length; ai++) {
        const raw = attempts[ai];
        // Attempt 0 always runs. Later attempts run only while the best so far is unusable:
        // too short, or long-but-soupy (G-soup gate).
        if (ai > 0) {
            if (best.trim().length >= 10 && !bestSoupy) break;
            // F3': the PSM 4 rung is empty-pages-only — any text at all (even soupy) skips it.
            if (raw.emptyOnly && best.trim().length > 0) break;
        }
        // Strip the control flag before handing options to tesseract (it is ours, not its).
        const options = {};
        for (const k of Object.keys(raw)) { if (k !== 'emptyOnly') options[k] = raw[k]; }
        let text = '';
        try {
            const hasOptions = options && Object.keys(options).length > 0;
            const ret = hasOptions
                ? await tesseract.recognize(canvas, 'eng', options)
                : await tesseract.recognize(canvas, 'eng');
            text = (ret && ret.data && ret.data.text) || '';
        } catch (err) {
            threw = true;
            continue;
        }
        if (!text.trim()) continue;
        const s = soupOf(text);
        // Keep-cleaner + minimum-length guard (2026-09-06): a PSM retry dethrones only
        // when cleaner AND 10+ chars (core.shouldReplaceOcrBest). Long-soupy incumbents
        // survive short-clean fragments (test4 yield fix); short-soupy still upgrades.
        // Keep the cleaner read: non-soupy beats soupy outright; two soupy (or two clean)
        // results keep the first (document order) — no churn between equals.
        let replace = (!best || (!s.soupy && bestSoupy));
        try {
            if (core && typeof core.shouldReplaceOcrBest === 'function') {
                replace = core.shouldReplaceOcrBest(best, bestSoupy, text, !!s.soupy);
            }
        } catch (e) { /* helper is best-effort — fall back to legacy rule above */ }
        if (replace) {
            best = text;
            bestSoupy = !!s.soupy;
        }
        if (typeof window !== 'undefined' && window._parserDebug) {
            try { console.log('[Parser] OCR attempt ' + ai + ': chars=' + text.trim().length + ' soupy=' + (!!s.soupy)); } catch (e) {}
        }
    }
    if (!best && threw) {
        try { markEngineFailed(); } catch (e) {}
    }
    return best;
}

// Scale-2 fallback experiment (2026-09-07, ABC-or-bust): last pre-group1 delta.
// Re-renders a still-empty page at scale 2, bypasses cleanup, and takes a single default-PSM
// shot — the verbatim pre-group1 recipe (which read test4's statement page). Keeps whichever
// yields under the 10-char rule; yielding pages never reach here. Debug-logged, best-effort,
// budget counts pages (the caller already incremented for this page — no double count).
// Called by: ocrPage() before returning short text (OSD-dead path excluded: engine broken).
async function ocrPageScale2Fallback(page, tesseract, rotationDeg, markEngineFailed) {
    try {
        const canvas = document.createElement('canvas');
        const viewport = page.getViewport({ scale: 2, rotation: rotationDeg || 0 });
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        // NOTE: no preprocessOcrCanvas and no ladder — one default shot, like pre-group1.
        const ret = await tesseract.recognize(canvas, 'eng');
        const text = (ret && ret.data && ret.data.text) || '';
        if (typeof window !== 'undefined' && window._parserDebug) {
            try { console.log('[Parser] OCR scale-2 fallback: chars=' + text.trim().length); } catch (e) {}
        }
        return (text.trim().length >= 10) ? text : '';
    } catch (err) {
        try { markEngineFailed(); } catch (e) {}
        return '';
    }
}

async function ocrPage(page, runId, rotationDeg = 0) {
    if (_ocrPagesDone >= ocrPageLimit()) return '';
    if (runId !== _autofillRunId) return '';

    const tesseract = await loadTesseract();
    // OCR group 1: render sharper — tesseract's documented sweet spot is ~300 DPI, we rendered
    // ~150-200 at scale 2. Scale 3 costs ~2.25x pixels per page but directly helps small/faint
    // text like table cells. The per-file page budget still caps total time.
    // F1 render cap (2026-09-07): giant canvases from 12MB phone-photo PDFs (test4 class)
    // choke tesseract to silent empty — even on perfectly upright, human-crisp pages (proven
    // by pixels: test4 p1 @0° + p2 @90° yield 0). Cap the longest side (~3000px), aspect
    // preserved; small pages keep full scale 3. Best-effort: any failure uses uncapped.
    // Called by: ocrPage() for every scanned page before render.
    let scale = 3;
    let viewport = page.getViewport({ scale, rotation: rotationDeg || 0 });
    try {
        const OCR_MAX_SIDE = 3000;
        const longest = Math.max(viewport.width, viewport.height);
        if (longest > OCR_MAX_SIDE) {
            scale = scale * (OCR_MAX_SIDE / longest);
            viewport = page.getViewport({ scale, rotation: rotationDeg || 0 });
            if (typeof window !== 'undefined' && window._parserDebug) {
                try { console.log('[Parser] OCR render capped: longest=' + Math.round(longest) + ' → scale=' + scale.toFixed(2)); } catch (e) {}
            }
        }
    } catch (e) { /* cap is best-effort — uncapped viewport still works */ }
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    if (runId !== _autofillRunId) return '';
    showAutofillScanning('Scanning documents… (OCR reading scanned pages)');
    preprocessOcrCanvas(canvas);
    // R1 auto orientation, stabilized: probe orientation mode ONCE per session, then commit.
    // 'eng+osd' straightens tilted/sideways phone-photo pages, but on this CDN build the OSD
    // data can fail with "LSTM requested, but not present" — retrying per page spams red errors
    // and doubles time. So: first page tries osd; any failure pins plain 'eng' for the session.
    // window._ocrLangMode: null = untried, 'osd' = working, 'eng' = pinned fallback.
    const useOsd = (typeof window === 'undefined' || window._ocrLangMode === undefined || window._ocrLangMode === null || window._ocrLangMode === 'osd');
    const markEngineFailed = () => { try { window._ocrEngineFailed = true; } catch (e) {} };
    if (useOsd) {
        try {
            const { data } = await tesseract.recognize(canvas, 'eng+osd');
            const osdText = (data && data.text) || '';
            // Empty-aware pinning (corrected model 2026-09-04): orientation failure does not
            // always throw — it may resolve EMPTY (red wall, no text). Empty counts as failure
            // for THIS page (retry plain immediately), but only CONSECUTIVE empties pin the
            // session — a single blank page must not rob later tilted pages of orientation.
            if (osdText.trim().length >= 10) {
                try { window._ocrLangMode = 'osd'; window._ocrOsdMisses = 0; } catch (e) {}
                if (typeof window !== 'undefined' && window._parserDebug && data && data.orientation) {
                    console.log('[Parser] OCR orientation:', data.orientation);
                }
                _ocrPagesDone++;
                return osdText;
            }
            let misses = 0;
            try {
                misses = (window._ocrOsdMisses || 0) + 1;
                window._ocrOsdMisses = misses;
                if (misses >= 2) {
                    window._ocrLangMode = 'eng';
                    console.warn('[Parser] orientation read empty twice running, using plain OCR for this session.');
                }
            } catch (e) {}
            const retry = await recognizeEnglish(tesseract, canvas, markEngineFailed);
            _ocrPagesDone++;
            // Scale-2 fallback: same empty page, pre-group1 recipe (run alive only).
            if (retry.trim().length < 10 && runId === _autofillRunId) {
                const fb = await ocrPageScale2Fallback(page, tesseract, rotationDeg, markEngineFailed);
                if (fb.trim().length >= 10) return fb;
            }
            return retry;
        } catch (err) {
            // Pin plain English for the rest of the session — one red line, not one per page.
            try {
                window._ocrLangMode = 'eng';
                console.warn('[Parser] orientation read unavailable, using plain OCR for this session.');
            } catch (e) {}
            try {
                const retry = await recognizeEnglish(tesseract, canvas, markEngineFailed);
                _ocrPagesDone++;
                // Scale-2 fallback: same empty page, pre-group1 recipe (run alive only).
                if (retry.trim().length < 10 && runId === _autofillRunId) {
                    const fb = await ocrPageScale2Fallback(page, tesseract, rotationDeg, markEngineFailed);
                    if (fb.trim().length >= 10) return fb;
                }
                return retry;
            } catch (err2) {
                markEngineFailed();
                return '';
            }
        }
    }
    try {
        const text = await recognizeEnglish(tesseract, canvas, markEngineFailed);
        _ocrPagesDone++;
        // Scale-2 fallback: same empty page, pre-group1 recipe (run alive only).
        if (text.trim().length < 10 && runId === _autofillRunId) {
            const fb = await ocrPageScale2Fallback(page, tesseract, rotationDeg, markEngineFailed);
            if (fb.trim().length >= 10) return fb;
        }
        return text;
    } catch (err) {
        markEngineFailed();
        return '';
    }
}

// ===================================================
// STEP 1 — PDF TEXT EXTRACTION
// Reconstructs lines from PDF.js text items (spacing +
// multi-column safe), OCRs textless pages, caps page count,
// and stops early once every field is found.
// ===================================================
async function extractTextFromPDF(file, runId) {
    const pdfjs = await getPdfJs();
    const arrayBuffer = await file.arrayBuffer();
    const pdf = await pdfjs.getDocument({ data: arrayBuffer }).promise;

    const core = getCore();
    const maxPages = Math.min(pdf.numPages, 50);
    // O1: fair per-file budget — every file gets its own scanned pages regardless of
    // upload order (previously the 10-page cap was shared first-come-first-served).
    _ocrPagesDone = 0;
    let fullText = '';
    let ocrUsed = false;
    // O2/N3: truncation + real-count tracking — a file with more scanned pages than the
    // photo-read budget must say so instead of posing a partial read as confident.
    // scannedTotal = textless pages met; scannedRead = pages actually photo-read.
    // R2: ocrPages records WHICH pages were photo-read so the per-page rotate control
    // can offer exactly those pages (mixed tilted/straight bundles).
    let ocrTruncated = false;
    let scannedTotal = 0;
    let scannedRead = 0;
    const ocrPages = [];
    // R-reach: landscape tracking — a fully-sideways scan (every scanned page wider than
    // tall) is the test4 shape. Recorded per file so the empty-run warning can offer a
    // straighten-all rescue even though no preview ever opens.
    // C1: per-PAGE shape ledger (not file-level only) — straighten-all must turn ONLY
    // sideways pages, never straight ones in mixed bundles.
    let landscapeCount = 0;
    const scannedPageNums = [];
    const landscapePages = [];
    let pagesVisited = 0;

    for (let pageNum = 1; pageNum <= maxPages; pageNum++) {
        // Abort if the run was cancelled/superseded while we were awaiting
        if (runId !== _autofillRunId) break;
        pagesVisited = pageNum;

        const page = await pdf.getPage(pageNum);
        const textContent = await page.getTextContent();

        let pageText = core.pageItemsToText(textContent.items || []);

        // OCR fallback for textless (scanned) pages
        if ((pageText || '').trim().length < 10) {
            scannedTotal++;
            scannedPageNums.push(pageNum);
            let isLandscape = false;
            try {
                const dim = page.getViewport({ scale: 1 });
                isLandscape = dim.width > dim.height * 1.1;
                if (isLandscape) {
                    landscapeCount++;
                    // C1: ledger the sideways pages individually.
                    landscapePages.push(pageNum);
                }
            } catch (e) { /* aspect is best-effort */ }
            if (_ocrPagesDone >= ocrPageLimit()) ocrTruncated = true;
            // R2: per-page manual rotation (degrees stashed by the rotate control).
            const rotKey = file.name + '#' + pageNum;
            const rotDeg = (window._ocrRotations && window._ocrRotations[rotKey]) || 0;
            const ocrText = await ocrPage(page, runId, rotDeg);
            if (ocrText && ocrText.trim().length >= 10) {
                pageText = ocrText;
                ocrUsed = true;
                scannedRead++;
                ocrPages.push(pageNum);
            }
            // Yield instrumentation for the 3→1 investigation: per-page chars + rotation,
            // debug-gated so normal runs stay quiet.
            if (typeof window !== 'undefined' && window._parserDebug) {
                try { console.log('[Parser] OCR page ' + pageNum + ': yielded=' + (pageText || '').trim().length + ' rot=' + (rotDeg || 0)); } catch (e) {}
            }
        }

        fullText += pageText + '\n\n';

        // Full-scan default (no early break): EVERY page reads up to the per-file budget,
        // even after all boxes fill — an early exit can false-complete on WEAK finds (a ghost
        // price filling the boxes while truth pages never read), and the miner/vote need full
        // text. Text pages cost ~nothing; scanned pages stay capped by the per-file budget.
    }

    return { text: fullText, ocr: ocrUsed, truncated: ocrTruncated, scannedTotal, scannedRead, numPages: pdf.numPages, ocrPages, allLandscape: scannedTotal > 0 && landscapeCount >= scannedTotal, scannedPageNums, landscapePages, pagesVisited };
}

// ===================================================
// VOTING / CROSS-FILE VALIDATION (Step 1d)
// Per-file extract then vote >50% majority, red banner let user pick on mismatch.
// ===================================================

// Normalize a value for voting: lowercase, trim, strip hereinafter tail, collapse spaces.
// N2: hardened (mirrors core normalizeForPool) — '&' reads as 'and', ALL punctuation and
// spacing collapses, so trivial same-project variants vote equal while real differences
// ("Building A" vs "Building B") still mismatch.
function normalizeForVote(v) {
    if (!v) return '';
    let s = String(v).trim().toLowerCase();
    s = s.replace(/\s*\(hereinafter.*/i, '').trim();
    s = s.replace(/&/g, ' and ');
    s = s.replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    return s;
}

// Vote across per-file parsed results. Returns single merged parsed object.
// For each field, count normalized values, pick most common (>50% = high confidence).
// Single file: just return its parsed (60 guessed single-file fallback).
function voteParsedFields(perFileData) {
    if (!perFileData || perFileData.length === 0) return {};
    if (perFileData.length === 1) {
        // Single file: return as-is but clean contractor parentheses already done in core
        // Also strip "oe " OCR artifact at start of title (t -> o misread)
        const single = Object.assign({}, perFileData[0].parsed);
        if (single.title && /^oe\s+/i.test(single.title)) single.title = single.title.replace(/^oe\s+/i, '').trim();
        return single;
    }

    const fields = ['title', 'location', 'description', 'budget', 'abc', 'budgetSource', 'category', 'startDate', 'endDate', 'duration', 'contractor'];
    const voted = {};
    for (const field of fields) {
        const freq = new Map(); // normalized -> { original, count }
        const originalMap = new Map(); // normalized -> original first seen
        for (const entry of perFileData) {
            const raw = entry.parsed[field];
            if (!raw) continue;
            const norm = normalizeForVote(raw);
            if (!norm) continue;
            if (!freq.has(norm)) { freq.set(norm, 0); originalMap.set(norm, raw); }
            freq.set(norm, freq.get(norm) + 1);
        }
        if (freq.size === 0) { voted[field] = null; continue; }
        // Find max count
        let bestNorm = null; let bestCount = 0;
        for (const [norm, count] of freq.entries()) {
            if (count > bestCount) { bestCount = count; bestNorm = norm; }
        }
        // >50% majority = auto-fill with high confidence, else top vote = still auto-fill but flagged
        voted[field] = originalMap.get(bestNorm) || null;
        voted['_' + field + '_votes'] = bestCount;
        voted['_' + field + '_total'] = perFileData.length;
    }
    // Preserve internal confidence from first file merged with vote boost
    const avgConfidence = Math.round(perFileData.reduce((sum, e) => sum + (e.parsed._confidence || 0), 0) / perFileData.length);
    voted._confidence = Math.min(100, avgConfidence);
    // Clean known OCR artifact
    if (voted.title && /^oe\s+/i.test(voted.title)) voted.title = voted.title.replace(/^oe\s+/i, '').trim();
    // Cut contractor tail if still present (fallback)
    if (voted.contractor) voted.contractor = voted.contractor.split(/\s*\(hereinafter.*/i)[0].trim();
    return voted;
}

// Build mismatch info for red banner: detects when files have different values for same field (mis-upload 2 projects).
function getMismatchInfo(perFileData) {
    if (!perFileData || perFileData.length <= 1) return { hasMismatch: false, details: [] };
    const fields = ['title', 'contractor', 'budget', 'budgetSource', 'category'];
    const details = [];
    for (const field of fields) {
        const normToFiles = new Map(); // norm -> [fileNames]
        for (const entry of perFileData) {
            const raw = entry.parsed[field];
            if (!raw) continue;
            const norm = normalizeForVote(raw);
            if (!norm) continue;
            if (!normToFiles.has(norm)) normToFiles.set(norm, []);
            normToFiles.get(norm).push(entry.fileName);
        }
        if (normToFiles.size > 1) {
            // Mismatch: same field has different values across files
            const variants = [];
            for (const [norm, files] of normToFiles.entries()) {
                // Find original
                const orig = perFileData.find(e => normalizeForVote(e.parsed[field]) === norm).parsed[field];
                variants.push({ value: orig, norm, files, count: files.length });
            }
            variants.sort((a,b) => b.count - a.count);
            details.push({ field, variants });
        }
    }
    return { hasMismatch: details.length > 0, details };
}

// ===================================================
// AUTOFILL FIELD TRACKING
// Tracks which fields autofill actually wrote, so a later
// clear only touches those — never the user's manual input.
// ===================================================
let _autofilledFields = new Set();

function _markAutofilled(elementId) {
    _autofilledFields.add(elementId);
}

// L1 learning loop: preview input id → core field name (shared by the snapshot,
// the harvest diff, and the disagree-note map below).
// Called by: showAutofillPreview() snapshot, harvestCorrections() diff.
const AUTOFILL_CORE_FIELDS = {
    projectTitle: 'title',
    projectLocation: 'location',
    projectDescription: 'description',
    projectCategory: 'category',
    projectBudget: 'budget',
    projectAbc: 'abc',
    projectBudgetSource: 'budgetSource',
    plannedStartDate: 'startDate',
    plannedEndDate: 'endDate',
    plannedDuration: 'duration',
    contractorSelect: 'contractor',
};

// ===================================================
// PREVIEW MODAL
// Shows the parsed fields for review; nothing is written
// to the form until the user clicks Apply.
// ===================================================
// F-COPY: copy exactly what the system read (all files, with file headers) to the
// clipboard, so a miss diagnoses to the character without screenshots. Uses the L1
// stash (never the server). Falls back to a temp textarea where clipboard API is blocked.
// Called by: inline onclick on the "Copy read text" button in the preview modal.
window._copyExtractedText = function () {
    try {
        const texts = window._autofillDocTexts || [];
        const names = window._autofillDocNames || [];
        if (!texts.length) {
            if (typeof showToast === 'function') showToast('Nothing to copy yet — run the preview first', 'error');
            return;
        }
        const out = texts.map((t, i) => '=== ' + (names[i] || ('file ' + (i + 1))) + ' ===\n' + t).join('\n\n');
        const done = () => { if (typeof showToast === 'function') showToast('Extracted text copied — paste it in chat', 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(out).then(done, () => fallbackCopy(out, done));
        } else {
            fallbackCopy(out, done);
        }
    } catch (e) { /* copy is best-effort */ }
    function fallbackCopy(text, done) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            done();
        } catch (e2) { /* best-effort */ }
    }
};

// Snapshots (2026-09-07 diagnostic, queue item 2): re-render one scanned page as a
// downscaled PNG and download it, so pixels can be pasted in chat instead of inferred
// from soup dumps. Reads the stashed original File (no server), applies the page's live
// manual rotation, renders offscreen at ~1.5 scale. Index-indirect (quote-safe) like the
// rotate buttons; all failures swallowed with a toast (never blocks preview).
// Called by: inline onclick on the per-page snapshot buttons (gray note + empty warning).
window._saveOcrPageImage = async function (reportIdx, pageNum) {
    try {
        const reports = window._autofillScanReports || [];
        const files = window._autofillPdfFiles || [];
        const r = reports[reportIdx];
        const fileName = r ? r.fileName : ((window._ocrRotateTargets || [])[reportIdx]);
        if (!fileName || !pageNum) return;
        const entry = (files || []).find(f => f && f.fileName === fileName);
        const file = entry ? entry.file : null;
        if (!file) {
            if (typeof showToast === 'function') showToast('Original file no longer available — re-upload to snapshot', 'error');
            return;
        }
        const rots = (typeof window !== 'undefined' && window._ocrRotations) ? window._ocrRotations : {};
        const rotDeg = rots[fileName + '#' + pageNum] || 0;
        const pdfjs = await getPdfJs();
        const buf = await file.arrayBuffer();
        const pdf = await pdfjs.getDocument({ data: buf }).promise;
        if (!pdf || pageNum < 1 || pageNum > pdf.numPages) return;
        const page = await pdf.getPage(pageNum);
        const viewport = page.getViewport({ scale: 1.5, rotation: rotDeg || 0 });
        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        const blob = await new Promise((resolve) => {
            try {
                if (canvas.toBlob) canvas.toBlob(b => resolve(b), 'image/png');
                else resolve(null);
            } catch (e) { resolve(null); }
        });
        if (!blob) {
            if (typeof showToast === 'function') showToast('Could not render that page', 'error');
            return;
        }
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const safe = String(fileName).replace(/[^\w.\-]+/g, '_').slice(0, 60);
        a.href = url;
        a.download = 'snapshot-' + safe + '-p' + pageNum + '-rot' + (rotDeg || 0) + '.png';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { try { a.remove(); URL.revokeObjectURL(url); } catch (e) {} }, 4000);
        if (typeof showToast === 'function') showToast('Page image saved — paste it in chat', 'success');
    } catch (e) { /* snapshot is best-effort */ }
};

// R-reach straighten-all rescue, C1-targeted: rotate ONLY the sideways-flagged pages
// (per-page shape ledger), never straight ones in mixed bundles. Uniformly sideways files
// (the test4 class) behave exactly as before — every scanned page turns. Session rotations
// persist for the re-run (cancel is NOT called).
// Called by: inline onclick on the empty-warning rescue button (no preview exists here).
window._straightenAllAndRescan = function () {
    try {
        const reports = window._autofillScanReports || [];
        window._ocrRotations = window._ocrRotations || {};
        for (const r of reports) {
            if (!r || !r.allLandscape) continue;
            // C1: prefer the per-page ledger; fall back to all scanned pages only when the
            // ledger is absent (old stashes predate it).
            const targets = (r.landscapePages && r.landscapePages.length)
                ? r.landscapePages
                : (r.scannedPageNums && r.scannedPageNums.length ? r.scannedPageNums : (r.ocrPages || []));
            for (const pn of targets) {
                const key = r.fileName + '#' + pn;
                window._ocrRotations[key] = (((window._ocrRotations[key] || 0) + 90) % 360);
            }
        }
    } catch (e) { /* rotation bookkeeping is best-effort */ }
    hideAutofillBanner();
    runDocumentAutofill();
};

// R2 manual per-page rotation: cycle 0→90→180→270→0 for one photo-read page, then
// re-run the whole preview (rotation persists in window._ocrRotations and applies at
// render time inside extractTextFromPDF). Full re-read cost (~2s/page) — the button is
// per tilted page, so routine pages are never re-read needlessly... (whole-run re-read
// is accepted: rotation is a rare manual rescue, correctness first).
// Called by: inline onclick on the per-page ⟳ buttons (index-indirected, quote-safe).
window._rotateOcrPage = function (reportIdx, pageNum) {
    try {
        const targets = window._ocrRotateTargets || [];
        const fileName = targets[reportIdx];
        if (!fileName || !pageNum) return;
        window._ocrRotations = window._ocrRotations || {};
        const key = fileName + '#' + pageNum;
        window._ocrRotations[key] = ((window._ocrRotations[key] || 0) + 90) % 360;
    } catch (e) { /* rotation bookkeeping is best-effort */ }
    closeAutofillPreview();
    runDocumentAutofill();
};

// Adjustable scan depth, N4: re-scan at the selector's chosen pages-per-file.
// Reads #ocrPageSelect (10/25/40/50, default 10), stores it for this session, closes the
// preview, and re-runs autofill. Opt-in per preview; routine uploads stay fast.
// Called by: inline onclick on the "Re-scan" button in the gray scan note.
window._rescanWithSelection = function () {
    try {
        const sel = document.getElementById('ocrPageSelect');
        const picked = sel ? Number(sel.value) : NaN;
        if (isFinite(picked) && picked >= 1 && picked <= 50) window._ocrPageLimit = Math.floor(picked);
    } catch (e) { /* keep previous depth on any failure */ }
    closeAutofillPreview();
    runDocumentAutofill();
};

// P1: remove an outlier file set from the red mismatch banner, then re-scan the rest.
// Index refers to window._mismatchOutliers stashed at render (quote-safe indirection).
// Ejects matching pending documents by fileName, closes the preview, re-runs autofill.
// Called by: inline onclick on the banner's "Remove & re-scan" buttons.
window._removeMismatchFiles = function (idx) {
    try {
        // window._mismatchOutliers[idx] is the outlier group's file-name array.
        const groups = window._mismatchOutliers || [];
        const names = Array.isArray(groups[idx]) ? groups[idx] : [];
        if (names.length > 0 && typeof getPendingDocuments === 'function' && typeof removeDocument === 'function') {
            const pending = getPendingDocuments() || [];
            pending.filter(d => d && names.indexOf(d.fileName) !== -1).forEach(d => {
                try { removeDocument(d.id); } catch (e) { /* per-file best-effort */ }
            });
        }
    } catch (e) { /* removal is best-effort */ }
    closeAutofillPreview();
    runDocumentAutofill();
};

// One-click pick for Step 1g-2 Choice B disagree notes in the preview modal.
// Writes the chosen value into the field's preview input (user can still edit).
// Called by: inline onclick on the amber note buttons built by showAutofillPreview().
window._pickDisagreeValue = function (inputId, val) {
    const el = document.getElementById(inputId);
    if (el) { el.value = val; el.focus(); }
};

function showAutofillPreview(parsed, { ocrUsed = false } = {}) {
    const modalEl = document.getElementById('autofillPreviewModal');
    if (!modalEl) {
        // No preview markup (should not happen) — fall back to direct apply
        applyAutofillFromPreview();
        return;
    }
    // S2 render guard: ignore renders from superseded runs (a slow older run must never
    // paint over a newer preview — stale-banner class of bug). Logged when it triggers.
    try {
        const optsRunId = (arguments[1] && arguments[1].runId !== undefined) ? arguments[1].runId : null;
        if (optsRunId !== null && typeof _autofillRunId !== 'undefined' && optsRunId !== _autofillRunId) {
            console.warn('[Parser] ignoring stale preview render from run ' + optsRunId);
            return;
        }
    } catch (e) { /* guard is best-effort — render normally on any failure */ }

    // Phase 5: expanded fields — ABC standalone, Budget Source, Category.
    // Hardcoded value-keyword fallback (TITLE/CONTRACTOR) already merged into parsed.
    const fields = [
        { id: 'projectTitle', label: 'Project Title', value: parsed.title },
        { id: 'projectLocation', label: 'Location', value: parsed.location },
        { id: 'projectDescription', label: 'Description', value: parsed.description },
        { id: 'projectCategory', label: 'Category', value: parsed.category },
        { id: 'projectBudget', label: 'Contract Price', value: parsed.budget },
        { id: 'projectAbc', label: 'ABC (Approved Budget for Contract)', value: parsed.abc },
        { id: 'projectBudgetSource', label: 'Budget Source', value: parsed.budgetSource },
        { id: 'plannedStartDate', label: 'Start Date', value: parsed.startDate },
        { id: 'plannedEndDate', label: 'End Date', value: parsed.endDate },
        { id: 'plannedDuration', label: 'Duration (days)', value: parsed.duration },
        { id: 'contractorSelect', label: 'Contractor', value: parsed.contractor },
    ];

    const tbody = document.getElementById('autofillPreviewRows');
    if (tbody) {
        // Date fields use the native calendar picker (like the create modal's
        // date inputs). Their values are normalised to YYYY-MM-DD at render so
        // the calendar shows the extracted date correctly.
        const core = getCore();
        const DATE_FIELDS = ['plannedStartDate', 'plannedEndDate'];
        // Step 1g-2 Choice B: amber pick-one notes for pooled fields whose top-2
        // finds both look trusted but disagree. Keyed by CORE field name; silent
        // when the vote has one clear winner (automation goal: clean files see nothing).
        const disagree = (arguments[1] && arguments[1].disagree) ? arguments[1].disagree : (window._autofillDisagree || null);
        const DISAGREE_INPUT = { budget: 'projectBudget', duration: 'plannedDuration', startDate: 'plannedStartDate', endDate: 'plannedEndDate' };
        const disagreeNoteFor = (inputId) => {
            if (!disagree) return '';
            for (const coreField of Object.keys(DISAGREE_INPUT)) {
                if (DISAGREE_INPUT[coreField] !== inputId) continue;
                const d = disagree[coreField];
                if (!d || !d.winner || !d.runnerUp) continue;
                // Parser outputs are numeric/date strings (no quotes) — safe for onclick.
                const wBtn = '<button type="button" class="btn btn-sm btn-outline-warning me-1" onclick="window._pickDisagreeValue(\'previewInput-' + inputId + '\',\'' + escapeHtml(d.winner.value) + '\')">' + escapeHtml(d.winner.value) + '</button>';
                const rBtn = '<button type="button" class="btn btn-sm btn-outline-warning" onclick="window._pickDisagreeValue(\'previewInput-' + inputId + '\',\'' + escapeHtml(d.runnerUp.value) + '\')">' + escapeHtml(d.runnerUp.value) + '</button>';
                return '<div class="alert alert-warning py-1 px-2 mt-1 mb-0" style="font-size:0.85em;">' +
                    '<i class="bi bi-question-circle"></i> I found two different values — please pick the right one:<br>' + wBtn + rBtn + '</div>';
            }
            return '';
        };
        tbody.innerHTML = fields.map(f => {
            const isDate = DATE_FIELDS.indexOf(f.id) !== -1;
            const value = isDate ? (core.parseToInputDate(f.value) || '') : (f.value || '');
            return `
                <tr>
                    <td class="autofill-preview-label">${escapeHtml(f.label)}</td>
                    <td>
                        <input type="${isDate ? 'date' : 'text'}" class="form-control form-control-sm" id="previewInput-${f.id}"
                            value="${value ? escapeHtml(value) : ''}"
                            placeholder="${f.value ? '' : 'Not found — fill manually'}" />
                        ${disagreeNoteFor(f.id)}
                    </td>
                </tr>
            `;
        }).join('');
        // L1 learning loop: snapshot what extraction guessed per CORE field, so Apply can
        // diff guesses vs user-kept values. Date-normalized exactly like the inputs above.
        try {
            const snap = {};
            for (const f of fields) {
                const isDate = DATE_FIELDS.indexOf(f.id) !== -1;
                snap[AUTOFILL_CORE_FIELDS[f.id] || f.id] = isDate ? (core.parseToInputDate(f.value) || '') : (f.value || '');
            }
            window._autofillOriginal = snap;
        } catch (e) { window._autofillOriginal = null; /* snapshot is best-effort */ }
    }

    const ocrNote = document.getElementById('autofillPreviewOcrNote');
    if (ocrNote) ocrNote.style.display = ocrUsed ? '' : 'none';

    // F-COPY button: always available while a preview has document text behind it
    // (S1 rebuild pattern — remove any previous node first so re-renders stay clean).
    try {
        const oldCopy = document.getElementById('autofillPreviewCopyWrap');
        if (oldCopy && oldCopy.remove) oldCopy.remove();
        const hasTexts = (window._autofillDocTexts || []).some(t => t && String(t).trim().length > 0);
        if (hasTexts) {
            const copyWrap = document.createElement('div');
            copyWrap.id = 'autofillPreviewCopyWrap';
            copyWrap.className = 'd-flex justify-content-end mt-2';
            copyWrap.innerHTML = '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._copyExtractedText()" title="Copy exactly what the system read, to paste in chat for diagnosis">Copy read text</button>';
            const container = modalEl.querySelector('.modal-body') || modalEl;
            container.appendChild(copyWrap);
        }
    } catch (e) { /* copy button is best-effort */ }

    // O2/N3/N4 gray scan note + page-count selector (replaces the fixed +15 stepping).
    // S1: rebuild-instead-of-reuse — the old box (if any) is REMOVED first, so no previous
    // run's note can linger into this preview (stale-banner class of bug).
    // Wording has no "budget" word; reports REAL per-file counts ("read X of Y scanned
    // pages in <file>"); the selector (10/25/40/50 per file, default 10) carries the
    // ≈2s/page warning beside it. Opt-in per preview; routine uploads stay fast.
    try {
        const oldTrunc = document.getElementById('autofillPreviewTruncated');
        if (oldTrunc && oldTrunc.remove) oldTrunc.remove();
    } catch (e) { /* best-effort */ }
    const reports = (arguments[1] && arguments[1].scanReports) ? arguments[1].scanReports : [];
    const truncated = (arguments[1] && arguments[1].truncatedFiles) ? arguments[1].truncatedFiles : [];
    if (ocrUsed || (reports && reports.length > 0)) {
        const truncEl = document.createElement('div');
        truncEl.id = 'autofillPreviewTruncated';
        truncEl.className = 'alert alert-secondary d-flex flex-column gap-2 mt-3';
        const container = modalEl.querySelector('.modal-body') || modalEl;
        container.appendChild(truncEl);
        const limit = (typeof ocrPageLimit === 'function') ? ocrPageLimit() : 10;
        // N-note + C2: three honest states — complete (visited everything; how many yielded
        // text) vs cut (stopped at the budget). Never one sentence meaning both.
        const lines = reports.map((r, ri) => {
            const visited = r.pagesVisited || r.numPages || '?';
            const total = r.numPages || '?';
            const base = r.truncated
                ? 'In ' + escapeHtml(r.fileName) + ': visited ' + visited + ' of ' + total + ' pages; ' + r.scannedRead + ' of ' + r.scannedTotal + ' scanned pages read (' + limit + ' per file selected) — later pages were not scanned.'
                : 'In ' + escapeHtml(r.fileName) + ': visited all ' + total + ' pages; ' + r.scannedRead + ' of ' + r.scannedTotal + ' scanned pages yielded text (' + limit + ' per file selected) — scan complete.';
            // R2: per-page rotate buttons for exactly the photo-read pages (mixed bundles
            // tilt page-by-page). Index-indirected (quote-safe); label shows live degrees.
            let rotBtns = '';
            try {
                const rots = (typeof window !== 'undefined' && window._ocrRotations) ? window._ocrRotations : {};
                rotBtns = ((r.ocrPages && r.ocrPages.length) ? r.ocrPages : []).slice(0, 12).map((pn) => {
                    const deg = rots[r.fileName + '#' + pn] || 0;
                    return '<button type="button" class="btn btn-sm btn-outline-secondary" data-rot-file="' + ri + '" data-rot-page="' + pn + '" onclick="window._rotateOcrPage(' + ri + ',' + pn + ')" title="Rotate page ' + pn + ' 90° and re-read (auto orientation applies too)">⟳ p.' + pn + (deg ? ' @' + deg + '°' : '') + '</button>';
                }).join(' ');
                if (rotBtns) rotBtns = '<div class="d-flex gap-1 flex-wrap mt-1"><span style="font-size:0.85em;" class="text-muted">Straighten a tilted page:</span>' + rotBtns + '</div>';
            } catch (e) { rotBtns = ''; /* buttons are best-effort */ }
            // Stash this report's identity for the rotate handler (quote-safe indirection).
            try {
                window._ocrRotateTargets = window._ocrRotateTargets || [];
                window._ocrRotateTargets[ri] = r.fileName;
            } catch (e) { /* best-effort */ }
            // Snapshots (2026-09-07): per-page save-image buttons for ALL scanned pages
            // (scannedPageNums ledger — approved scope; rotated-but-yielding soup must stay
            // visible). Index-indirect like rotate; handler re-renders at ~1.5 scale PNG.
            let snapBtns = '';
            try {
                const pages = ((r.scannedPageNums && r.scannedPageNums.length) ? r.scannedPageNums : ((r.ocrPages && r.ocrPages.length) ? r.ocrPages : [])).slice(0, 12);
                snapBtns = pages.map((pn) => {
                    return '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._saveOcrPageImage(' + ri + ',' + pn + ')" title="Save page ' + pn + ' as a PNG image to paste in chat">⤓ p.' + pn + '</button>';
                }).join(' ');
                if (snapBtns) snapBtns = '<div class="d-flex gap-1 flex-wrap mt-1"><span style="font-size:0.85em;" class="text-muted">Save page image:</span>' + snapBtns + '</div>';
            } catch (e) { snapBtns = ''; /* buttons are best-effort */ }
            return base + rotBtns + snapBtns;
        });
        if (lines.length === 0 && (truncated && truncated.length > 0)) {
            lines.push('Only the first ' + limit + ' scanned pages were read in: ' + truncated.map(f => escapeHtml(f)).join(', ') + '. Later pages were not scanned.');
        }
        const opts = [10, 25, 40, 50].map(n => '<option value="' + n + '"' + (n === limit ? ' selected' : '') + '>' + n + ' pages per file</option>').join('');
        truncEl.innerHTML = '<i class="bi bi-info-circle"></i> <span>' + (lines.join('<br>') || 'Scanned pages were read.') + '</span>' +
            '<div class="d-flex align-items-center gap-2 flex-wrap"><label class="mb-0" style="font-size:0.85em;" for="ocrPageSelect">Read depth (≈2s per page):</label>' +
            '<select id="ocrPageSelect" class="form-select form-select-sm" style="width:auto;">' + opts + '</select>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._rescanWithSelection()">Re-scan</button></div>';
        truncEl.style.display = '';
    }

    // Mismatch (mis-upload 2 different projects) — red banner, let user pick, do NOT block Apply
    // S1: rebuild-instead-of-reuse — any previous run's banner node is REMOVED first, so a
    // stale "2/3 files" banner can never survive into a single-file preview.
    // S0: the render decision is logged (run id + show/hide) for future diagnosis.
    const mismatch = arguments[1] && arguments[1].mismatch ? arguments[1].mismatch : window._autofillMismatch;
    try {
        const oldMis = document.getElementById('autofillPreviewMismatch');
        if (oldMis && oldMis.remove) oldMis.remove();
    } catch (e) { /* best-effort */ }
    let mismatchEl = null;
    if (mismatch && mismatch.hasMismatch && mismatch.details && mismatch.details.length > 0) {
        mismatchEl = document.createElement('div');
        mismatchEl.id = 'autofillPreviewMismatch';
        mismatchEl.className = 'alert alert-danger d-flex flex-column gap-2 mt-3';
        const container = modalEl.querySelector('.modal-body') || modalEl;
        container.appendChild(mismatchEl);
        const rows = mismatch.details.map(d => {
            const label = d.field === 'title' ? 'Project Title' : d.field === 'contractor' ? 'Contractor' : d.field;
            const variants = d.variants.map(v => escapeHtml(v.value) + ' — ' + v.files.map(f => escapeHtml(f)).join(', ') + ' (' + v.count + ' file' + (v.count>1?'s':'') + ')').join('<br>');
            return '<div><strong>' + escapeHtml(label) + ' mismatch — files disagree:</strong><br><span style="font-size:0.9em;">' + variants + '</span></div>';
        }).join('');
        // P1: grouped verdict + outlier remove buttons ("3 files look like one project…
        // 1 file looks different [Remove & re-scan]"). Title variants identify the split;
        // outlier file lists are stashed by index (quote-safe) for the remove handler.
        // Nothing renders on agreement (single variant) — clean runs stay quiet.
        let verdictHtml = '';
        try {
            const core = (typeof getCore === 'function') ? getCore() : null;
            const idDetail = ((mismatch.details || []).find(d => d.field === 'title')) || mismatch.details[0];
            const groups = (core && typeof core.splitMismatchGroups === 'function')
                ? core.splitMismatchGroups(idDetail.variants || [])
                : { connected: [], outliers: [] };
            if (groups.outliers.length > 0) {
                window._mismatchOutliers = groups.outliers.map(o => o.files || []);
                const outRows = groups.outliers.map((o, i) => {
                    const names = (o.files || []).map(f => escapeHtml(f)).join(', ');
                    return '<div class="d-flex align-items-center gap-2 flex-wrap mt-1"><span style="font-size:0.9em;">Looks different: <strong>' + names + '</strong></span>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger" onclick="window._removeMismatchFiles(' + i + ')">Remove &amp; re-scan</button></div>';
                }).join('');
                verdictHtml = '<div class="mt-2 pt-2 border-top"><strong>Grouped files:</strong> ' +
                    '<span style="font-size:0.9em;">' + groups.connected.map(f => escapeHtml(f)).join(', ') + ' look like one project.</span>' + outRows + '</div>';
            } else {
                window._mismatchOutliers = null;
            }
        } catch (e) { verdictHtml = ''; /* verdict is best-effort — banner still shows */ }
        mismatchEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> <strong>Possible mis-upload detected — files appear to be from different projects.</strong> Please verify which file to keep. The preview shows the majority vote (' + (mismatch.details[0].variants[0].count) + '/' + (mismatch.details[0].variants.reduce((s,v)=>s+v.count,0)) + ' files). You can still edit and Apply.' + '<div class="mt-2">' + rows + '</div>' + verdictHtml;
        mismatchEl.style.display = '';
    }
    // S0: log the banner decision with the run id (stale-render diagnosis).
    try {
        const rid = (arguments[1] && arguments[1].runId !== undefined) ? arguments[1].runId : '?';
        console.log('[Parser] preview render run ' + rid + ': mismatch banner ' + (mismatchEl ? 'SHOWN' : 'hidden'));
    } catch (e) { /* logging is best-effort */ }

    hideAutofillBanner();

    const instance = bootstrap.Modal.getOrCreateInstance(modalEl);
    instance.show();
}

function closeAutofillPreview() {
    const modalEl = document.getElementById('autofillPreviewModal');
    if (modalEl) {
        const instance = bootstrap.Modal.getInstance(modalEl);
        if (instance) instance.hide();
    }
}

// Apply the previewed data to the form (Apply button in the preview modal).
// Reads the (possibly edited) input values from the preview rows.
// Phase 5: includes abc, budgetSource, category.
function applyAutofillFromPreview() {
    const read = (id) => {
        const el = document.getElementById('previewInput-' + id);
        return el ? el.value.trim() : '';
    };

    // Date inputs require YYYY-MM-DD — normalise any human-typed date.
    const core = getCore();
    const normDate = (v) => (core.parseToInputDate(v) || v);

    const parsed = {
        title: read('projectTitle'),
        location: read('projectLocation'),
        description: read('projectDescription'),
        budget: read('projectBudget'),
        abc: read('projectAbc'),
        budgetSource: read('projectBudgetSource'),
        category: read('projectCategory'),
        startDate: normDate(read('plannedStartDate')),
        endDate: normDate(read('plannedEndDate')),
        duration: read('plannedDuration'),
        contractor: read('contractorSelect'),
    };

    // Clear only previously autofilled values, then fill freshly
    clearAutofillFields();
    autofillFormFields(parsed);

    // L1 learning loop: harvest (label → field) proposals from corrected boxes.
    // Fire-and-forget — extraction output is already final; this only teaches future runs.
    harvestCorrections(parsed);

    // Remember the user applied this run so the Next hook doesn't re-prompt.
    window._autofillApplied = true;

    closeAutofillPreview();
}

// ===================================================
// L1 LEARNING LOOP — HARVEST CORRECTIONS (Option 2)
// Diffs the preview snapshot (what extraction guessed) against the applied values
// (what the user kept). Every corrected box becomes one proposal: the mined label
// phrase + excerpt, or a label-less count when the value cannot be anchored back to
// the documents. Values themselves are NEVER sent — labels only (Decision L0b).
// A corrected box implies the user resolved any ambiguity (including amber picks),
// so disagree state is recorded as context, never as a veto.
// Called by: applyAutofillFromPreview(). Best-effort — failures never break Apply.
function harvestCorrections(applied) {
    try {
        const orig = window._autofillOriginal || null;
        const texts = window._autofillDocTexts || [];
        if (!orig || !texts.length) return;
        const core = getCore();
        if (!core || typeof core.mineCorrectionLabel !== 'function') return;
        const path = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
        if (path.indexOf('/contractor/') !== -1) return;
        const base = path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
        const docText = texts.join('\n\n');
        const disagree = window._autofillDisagree || null;
        const proposals = [];
        for (const inputId of Object.keys(AUTOFILL_CORE_FIELDS)) {
            const field = AUTOFILL_CORE_FIELDS[inputId];
            const before = String((orig[field] ?? ''));
            const after = String(((applied || {})[field] ?? '')).trim();
            // Untouched, cleared, or too-short boxes teach nothing.
            if (!after || after === before || after.length < 3) continue;
            const mined = core.mineCorrectionLabel(docText, after);
            proposals.push({
                field,
                label: mined ? mined.label : null,
                excerpt: mined ? mined.excerpt : null,
                had_disagree: !!(disagree && disagree[field]),
            });
        }
        if (!proposals.length) return;
        const token = (window._projectModalConfig && window._projectModalConfig.csrfToken) ||
            (((typeof document !== 'undefined') && document.querySelector('meta[name="csrf-token"]')) || {}).content || '';
        fetch(base + '/parser-keyword-proposals/collect', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ proposals }),
            keepalive: true,
        }).catch(() => { /* collection is best-effort */ });
    } catch (e) { /* collection is best-effort — never break Apply */ }
}

// ===================================================
// CLEAR PREVIOUSLY AUTOFILLED FIELDS
// Only clears fields that autofill wrote (tracked in
// _autofilledFields). User-typed values are preserved.
// ===================================================
function clearAutofillFields() {
    _autofilledFields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const indicator = document.getElementById(_parseIndicatorId(id));
        if (indicator) indicator.remove();
    });
    _autofilledFields.clear();

    // Clear title validator feedback since the field is now empty
    if (typeof titleValidator !== 'undefined') titleValidator.clear();
}

// ===================================================
// PARSE INDICATOR HELPERS
// Show a small hint below each field after autofill —
// either "auto-filled" or "could not extract".
// ===================================================
function _parseIndicatorId(fieldId) {
    return 'parse-indicator-' + fieldId;
}

function showParseIndicator(fieldId, success) {
    const field = document.getElementById(fieldId);
    if (!field) return;

    const indicatorId = _parseIndicatorId(fieldId);
    let indicator = document.getElementById(indicatorId);

    if (!indicator) {
        indicator = document.createElement('small');
        indicator.id = indicatorId;
        // Insert right after the field element (or its parent wrapper for input-groups)
        const insertAfter = field.closest('.input-group') || field;
        insertAfter.insertAdjacentElement('afterend', indicator);
    }

    if (success) {
        indicator.className = 'text-primary d-block mt-1';
        indicator.innerHTML = '<i class="bi bi-magic"></i> Auto-filled from document — please review.';
    } else {
        indicator.className = 'text-primary d-block mt-1';
        indicator.innerHTML = '<i class="bi bi-info-circle"></i> Could not extract this field from the document — please fill in manually.';
    }
}

// ===================================================
// STEP 2 — AUTOFILL FORM FIELDS
// Only fills fields that are currently empty and records
// them as autofilled. Returns the number filled.
// ===================================================
function autofillFormFields(parsed) {
    let filledCount = 0;

    // Helper: set a text/number/date input only if it's empty
    // Tracks which fields autofill wrote for selective clear later.
    function setIfEmpty(elementId, value) {
        const el = document.getElementById(elementId);
        if (!el) return;

        if (value && !el.value.trim()) {
            el.value = value;
            // Trigger a change event so any listeners (e.g. date calculators) react
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            _markAutofilled(elementId);
            filledCount++;
            showParseIndicator(elementId, true);
        } else if (!value) {
            showParseIndicator(elementId, false);
        }
        // If value exists but field was already filled by user — show nothing (respect user input)
    }

    // --- Page 2 fields ---
    setIfEmpty('projectTitle', parsed.title);
    setIfEmpty('projectLocation', parsed.location);

    // --- Page 3 fields ---
    setIfEmpty('projectDescription', parsed.description);
    // Category is a select — fuzzy table lookup (Option A: admin-only warning)
    if (parsed.category) {
        tryMatchCategoryDropdown(parsed.category);
    } else {
        showParseIndicator('projectCategory', false);
    }

    // --- Page 4 fields (Budget & Contractor) ---
    setIfEmpty('projectBudget', parsed.budget);
    setIfEmpty('projectAbc', parsed.abc);
    // Budget Source select — fuzzy table lookup (Option A)
    if (parsed.budgetSource) {
        tryMatchBudgetSourceDropdown(parsed.budgetSource);
    } else {
        showParseIndicator('projectBudgetSource', false);
    }

    setIfEmpty('plannedStartDate', parsed.startDate);
    setIfEmpty('plannedEndDate', parsed.endDate);
    setIfEmpty('plannedDuration', parsed.duration);

    // --- Contractor: fuzzy match the dropdown (existing) ---
    if (parsed.contractor) {
        tryMatchContractorDropdown(parsed.contractor);
    } else {
        showParseIndicator('contractorSelect', false);
    }

    // Trigger title validation so the "available" check runs
    if (parsed.title && typeof titleValidator !== 'undefined') {
        titleValidator.validate();
    }

    return filledCount;
}

/**
 * Try to find the best matching option in the contractor <select>
 * by comparing the extracted contractor name against option text.
 * Only sets the value if the match looks confident.
 */
function tryMatchContractorDropdown(extractedName) {
    const select = document.getElementById('contractorSelect');
    if (!select || !extractedName) return;

    const normalised = extractedName.toLowerCase().trim();
    let bestOption = null;
    let bestScore = 0;

    for (const option of select.options) {
        if (!option.value) continue; // skip the placeholder
        const optionText = option.textContent.toLowerCase().trim();

        // Score: does the option contain words from the extracted name?
        const words = normalised.split(/\s+/).filter(w => w.length > 3);
        let matches = 0;
        for (const word of words) {
            if (optionText.includes(word)) matches++;
        }
        const score = words.length > 0 ? matches / words.length : 0;

        if (score > bestScore) {
            bestScore = score;
            bestOption = option;
        }
    }

    // Only select if we're at least 50% confident
    if (bestOption && bestScore >= 0.5 && !select.value) {
        select.value = bestOption.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        _markAutofilled('contractorSelect');
        showParseIndicator('contractorSelect', true);
    } else {
        showParseIndicator('contractorSelect', false);
    }
}

/**
 * Try to match Budget Source dropdown (Option A: strict admin-only).
 * If no ≥0.5 match, keep raw text in preview but warn inline:
 * "Budget source \"X\" not found — ask admin via (+) (admin only)".
 */
function tryMatchBudgetSourceDropdown(extractedName) {
    const select = document.getElementById('projectBudgetSource');
    if (!select || !extractedName) return;

    const normalised = extractedName.toLowerCase().trim();
    let bestOption = null;
    let bestScore = 0;

    for (const option of select.options) {
        if (!option.value) continue;
        const optionText = option.textContent.toLowerCase().trim();
        const words = normalised.split(/\s+/).filter(w => w.length > 2);
        let matches = 0;
        for (const word of words) {
            if (optionText.includes(word)) matches++;
        }
        const score = words.length > 0 ? matches / words.length : (optionText === normalised ? 1 : 0);
        if (score > bestScore) { bestScore = score; bestOption = option; }
    }

    if (bestOption && bestScore >= 0.5 && !select.value) {
        select.value = bestOption.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        _markAutofilled('projectBudgetSource');
        showParseIndicator('projectBudgetSource', true);
    } else {
        // Option A: do not auto-create, show warning with admin-only hint
        const indicatorId = _parseIndicatorId('projectBudgetSource');
        let indicator = document.getElementById(indicatorId);
        if (!indicator) {
            const field = document.getElementById('projectBudgetSource');
            const insertAfter = field ? (field.closest('.input-group') || field) : null;
            if (insertAfter) {
                indicator = document.createElement('small');
                indicator.id = indicatorId;
                insertAfter.insertAdjacentElement('afterend', indicator);
            }
        }
        if (indicator) {
            indicator.className = 'text-warning d-block mt-1';
            indicator.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Budget source "' + escapeHtml(extractedName) + '" not found in database — select existing or ask admin via (+) Budget Sources (admin only).';
        }
    }
}

/**
 * Try to match Category dropdown (Option A: strict admin-only).
 */
function tryMatchCategoryDropdown(extractedName) {
    const select = document.getElementById('projectCategory');
    if (!select || !extractedName) return;

    const normalised = extractedName.toLowerCase().trim();
    let bestOption = null;
    let bestScore = 0;

    for (const option of select.options) {
        if (!option.value) continue;
        const optionText = option.textContent.toLowerCase().trim();
        const words = normalised.split(/\s+/).filter(w => w.length > 2);
        let matches = 0;
        for (const word of words) {
            if (optionText.includes(word)) matches++;
        }
        const score = words.length > 0 ? matches / words.length : (optionText === normalised ? 1 : 0);
        if (score > bestScore) { bestScore = score; bestOption = option; }
    }

    if (bestOption && bestScore >= 0.5 && !select.value) {
        select.value = bestOption.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        _markAutofilled('projectCategory');
        showParseIndicator('projectCategory', true);
    } else {
        const indicatorId = _parseIndicatorId('projectCategory');
        let indicator = document.getElementById(indicatorId);
        if (!indicator) {
            const field = document.getElementById('projectCategory');
            const insertAfter = field ? (field.closest('.input-group') || field) : null;
            if (insertAfter) {
                indicator = document.createElement('small');
                indicator.id = indicatorId;
                insertAfter.insertAdjacentElement('afterend', indicator);
            }
        }
        if (indicator) {
            indicator.className = 'text-warning d-block mt-1';
            indicator.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Category "' + escapeHtml(extractedName) + '" not found — select existing or ask admin via (+) Category.';
        }
    }
}

// ===================================================
// UI BANNER — shown at the top of the current page
// ===================================================
function showAutofillScanning(message) {
    const banner = getOrCreateAutofillBanner();
    banner.className = 'alert alert-info autofill-banner d-flex align-items-center gap-2 mb-3';
    banner.innerHTML = `
        <span class="spinner-border spinner-border-sm flex-shrink-0" role="status" aria-hidden="true"></span>
        <span class="flex-grow-1"><strong>${message}</strong> Extracting project information from your uploaded files.</span>
        <button type="button" class="btn-close flex-shrink-0" aria-label="Dismiss" onclick="hideAutofillBanner()"></button>
    `;
    banner.style.display = 'flex';
}

function showAutofillWarning(message, opts) {
    const banner = getOrCreateAutofillBanner();
    banner.className = 'alert alert-warning autofill-banner d-flex align-items-start gap-2 mb-3';
    // F-COPY on empty runs: failures must stay diagnosable — offer the read text for chat
    // when any file yielded text (even unparsable). Engine-failure runs pass copy:false.
    let copyBtn = '';
    try {
        const wantCopy = !opts || opts.copy !== false;
        const hasTexts = (window._autofillDocTexts || []).some(t => t && String(t).trim().length > 0);
        if (wantCopy && hasTexts && typeof window._copyExtractedText === 'function') {
            copyBtn = '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="window._copyExtractedText()">Copy what was read</button>';
        }
    } catch (e) { /* copy affordance is best-effort */ }
    // R-reach: when every scanned page of a file is wider-than-tall (the sideways-scan
    // shape), offer a one-click straighten-all rescue — these files never reach the preview,
    // so without this button they have NO manual path at all (test4 class).
    let straightenBtn = '';
    // Snapshots (2026-09-07): same per-page save-image buttons on empty runs — a fully
    // unread file is exactly when pixels are needed most. ALL scanned pages per report.
    let snapBtns = '';
    try {
        const reports = window._autofillScanReports || [];
        // Stash identities so the index-indirect snapshot handler resolves file names here too.
        try {
            window._ocrRotateTargets = window._ocrRotateTargets || [];
            reports.forEach((r, ri) => { if (r && r.fileName) window._ocrRotateTargets[ri] = r.fileName; });
        } catch (e) { /* best-effort */ }
        const btns = [];
        reports.forEach((r, ri) => {
            if (!r) return;
            const pages = ((r.scannedPageNums && r.scannedPageNums.length) ? r.scannedPageNums : ((r.ocrPages && r.ocrPages.length) ? r.ocrPages : [])).slice(0, 12);
            pages.forEach((pn) => {
                btns.push('<button type="button" class="btn btn-sm btn-outline-secondary mt-2 me-1" onclick="window._saveOcrPageImage(' + ri + ',' + pn + ')" title="Save page ' + pn + ' of ' + escapeHtml(r.fileName) + ' as PNG to paste in chat">⤓ ' + escapeHtml(r.fileName) + ' p.' + pn + '</button>');
            });
        });
        if (btns.length) snapBtns = '<div>' + btns.join('') + '</div>';
    } catch (e) { snapBtns = ''; /* snapshot affordance is best-effort */ }
    try {
        const wantStraighten = !opts || opts.straighten !== false;
        const reports = window._autofillScanReports || [];
        const sideways = reports.filter(r => r && r.allLandscape && (r.scannedRead || 0) > 0);
        if (wantStraighten && sideways.length > 0 && typeof window._straightenAllAndRescan === 'function') {
            const names = sideways.map(r => escapeHtml(r.fileName)).join(', ');
            straightenBtn = '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="window._straightenAllAndRescan()" title="Rotate every scanned page 90° and re-read">Straighten sideways pages &amp; re-read (' + names + ')</button>';
        }
    } catch (e) { /* rescue affordance is best-effort */ }
    banner.innerHTML = `
        <i class="bi bi-exclamation-triangle flex-shrink-0 fs-5"></i>
        <div>
            <strong>Auto-fill notice:</strong>
            <span class="d-block" style="font-size: 0.875em;">${message}</span>
            ${copyBtn}
            ${straightenBtn}
            ${snapBtns}
        </div>
        <button type="button" class="btn-close ms-auto flex-shrink-0" aria-label="Dismiss" onclick="hideAutofillBanner()"></button>
    `;
    banner.style.display = 'flex';
}

function hideAutofillBanner() {
    const banner = document.getElementById('autofillBanner');
    if (banner) banner.style.display = 'none';
}

/**
 * Find or create the autofill banner element at the top of the
 * currently visible form page (Page 2 for the Next flow, Page 1
 * when triggered by the Preview button).
 */
function getOrCreateAutofillBanner() {
    let banner = document.getElementById('autofillBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'autofillBanner';
        banner.style.display = 'none';
        const target = document.querySelector('.form-page.active') || document.getElementById('page2');
        if (target) {
            target.insertBefore(banner, target.firstChild);
        }
    }
    return banner;
}

// ===================================================
// UTILITY HELPERS
// ===================================================
function escapeHtml(unsafe) {
    return String(unsafe == null ? '' : unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
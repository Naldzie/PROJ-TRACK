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
// M-1 — OSD ORIENTATION PATH DISABLED ON THIS BUILD (2026-09-20)
// 'eng+osd' asks the LSTM-only tesseract core we load from the CDN
// (tesseract.js-core v5.1.1) to load the legacy osd.traineddata, which that core
// cannot run: every call throws "LSTM requested, but not present!!". The path could
// therefore never straighten a page. What it did instead, every fresh modal session:
// burn the first two scanned pages on an engine that cannot work, print two walls of
// red stack trace, and then trip the session-wide 'eng' pin below - which is why the
// SAME file read 3 pages one run and 4 the next.
// Measured verbatim from the console: normal_doc_parser_restudy_plan.md 11.2-11.3.
// The OSD machinery below is left INTACT and simply unreachable while this is false;
// flip it to true if the core/traineddata pairing is ever fixed.
// NOTE: this is AUTOMATIC orientation only. The reviewer's manual "See & straighten"
// rotation (window._ocrRotations) is a different path and is unaffected.
const OCR_OSD_ENABLED = false;

// ===================================================
// T2 — THE DEBUG FLAG SURVIVES A RELOAD (2026-09-20)
// window._parserDebug gates every per-page OCR log, and it could only be set by typing
// into the console. That meant debug logging was ON exactly when devtools was OPEN and
// OFF when it was shut — so the "devtools open reads 3 pages, closed reads 4" question
// could never be measured, because measuring it required the console. The flag is
// logging-only at all of its sites (restudy §12.8), so it cannot change what a page
// yields; the confound was in how it gets SET, not in what it does.
// Hydrating the same flag from localStorage at load dissolves that: turn logging on
// once, close devtools, reload, run, then reopen devtools and read the log back.
// The pure core reads window._parserDebug too (document-parser-core.js:138), so
// hydrating the window flag covers both halves of the parser with no other change.
// Called by: nothing — the hydrator runs once at script load.
const PARSER_DEBUG_KEY = 'pmuParserDebug';
(function hydrateParserDebugFlag() {
    try {
        if (typeof window === 'undefined') return;
        // An explicit in-session override always wins over the stored preference.
        if (window._parserDebug) return;
        if (localStorage.getItem(PARSER_DEBUG_KEY) === '1') window._parserDebug = true;
    } catch (e) { /* storage can be blocked or absent; debug logging is never load-bearing */ }
})();

// Turn parser debug logging on or off ACROSS RELOADS in one console call.
// Usage: window._parserDebugPersist(true) → reload → run with devtools shut → reopen
// devtools and scroll back. window._parserDebugPersist(false) clears it again.
// Called by: the browser console only.
window._parserDebugPersist = function (on) {
    const want = (on !== false);
    try {
        if (want) localStorage.setItem(PARSER_DEBUG_KEY, '1');
        else localStorage.removeItem(PARSER_DEBUG_KEY);
    } catch (e) { /* fall through — the window flag below still serves this session */ }
    try { window._parserDebug = want; } catch (e) {}
    return 'parser debug ' + (want ? 'ON' : 'OFF') + ' (persists across reloads)';
};

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
    // No run survives a cancel, so nothing may keep the scan indicator alive either.
    _autofillScansInFlight = 0;
    try { removeAutofillScanStatus(); } catch (e) { /* best-effort */ }
    // The photo-read cache is per modal session, like the rotations it is keyed
    // against — a reopened popup must never serve text read from a file that is
    // no longer the one attached. The preview's open documents go with it, or a
    // session that previewed several files holds them all until reload.
    try { _ocrTextCache = new Map(); } catch (e) { /* best-effort */ }
    try { releaseOcrPreviewDocs(); } catch (e) { /* best-effort */ }
    // A page choice belongs to the documents it was made against — a new session
    // must fall back to the system's own ranking rather than an orphaned pick.
    try { window._ocrAiPick = null; } catch (e) { /* best-effort */ }
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
        // M-1: while OCR_OSD_ENABLED is false there is nothing to probe, so the session
        // starts already pinned to plain 'eng' - no run can take the broken path, and no
        // run can inherit a pin from the run before it (that inheritance was OCR-2).
        window._ocrLangMode = OCR_OSD_ENABLED ? null : 'eng';
        window._ocrOsdMisses = 0;
        window._ocrEngineFailed = false;
        // F-COPY/L1 stashes belong to the run that made them — never reuse across sessions.
        window._autofillDocTexts = [];
        window._autofillDocNames = [];
        window._autofillOriginal = null;
        // AI-3/AI-4 stashes: provenance stamps + busy/merged flags belong to the preview run.
        window._autofillAiAssisted = {};
        window._autofillAiBusy = false;
        window._autofillAiMerged = false;
        // Snapshots: original Files are per-session too (object URLs / buffers must not leak).
        window._autofillPdfFiles = [];
    }
    for (const id of ['autofillPreviewMismatch', 'autofillPreviewTruncated', 'autofillPreviewCopyWrap', 'autofillPreviewAi']) {
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

// L3 — live learned vocabulary (parser_keywords rows with status=live).
// The loader itself now lives in the SHARED learned-keywords-loader.js, which the
// role layouts load on every page — this script is wizard-only, and the detail
// Baseline tab needs the same reader for S-curve PDF imports (bug (a), see that
// file's header). Consumers go through window.loadLearnedKeywords with a typeof
// guard, so a missing loader degrades to the hardcoded labels exactly as an empty
// table does.

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

    // This run is now in flight — the spinner is allowed to paint from here until the
    // finally below takes it back down. Placed before the first showAutofillScanStatus()
    // so that call is not itself suppressed by the in-flight gate.
    _autofillScansInFlight++;
    _autofillCurrentFile = ''; // no file named until the read loop reaches one
    showAutofillScanStatus('Reading your documents…');

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
        // L3: live learned vocabulary, once per run (sessionStorage-cached, best-effort).
        // Shared loader (role layouts); absent loader = today's hardcoded behavior.
        let learnedKeywords = { labels: {} };
        try {
            if (typeof window.loadLearnedKeywords === 'function') {
                learnedKeywords = await window.loadLearnedKeywords();
            }
        } catch (e) { /* reader falls back to hardcoded */ }

        for (const doc of pdfDocs) {
            if (myRunId !== _autofillRunId) return;
            // Name the file the indicator is currently reading. Module-scoped because
            // ocrPage() is several frames down and only receives the page, not the doc.
            _autofillCurrentFile = doc.fileName || "";

            try {
                const { text, ocr, truncated, scannedTotal, scannedRead, ocrPages, allLandscape, scannedPageNums, landscapePages, pagesVisited, numPages, pageChars, soupyPages } = await extractTextFromPDF(doc.file, myRunId);
                if (ocr) ocrUsed = true;
                // O2: remember files whose scan was cut short by the page budget.
                if (truncated) truncatedFiles.push(doc.fileName);
                // N3/R2/R-reach: remember real counts + WHICH pages were photo-read for the note
                // and the per-page rotate control (even uncut ones). allLandscape flags fully-
                // sideways scans for the pre-preview straighten-all rescue.
                if (ocr || (scannedTotal || 0) > 0) {
                    scanReports.push({ fileName: doc.fileName, scannedTotal: scannedTotal || 0, scannedRead: scannedRead || 0, truncated: !!truncated, ocrPages: ocrPages || [], allLandscape: !!allLandscape, scannedPageNums: scannedPageNums || [], landscapePages: landscapePages || [], pagesVisited: pagesVisited || 0, numPages, pageChars: pageChars || {}, soupyPages: soupyPages || [] });
                }
                if (text && text.trim().length > 0) {
                    const parsedSingle = getCore().parseProjectInfo({ text }, { learned: learnedKeywords });
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
                // Override only fields the pool resolved (budget/abc/duration/dates);
                // keep voted title/contractor/location/etc. from per-file vote.
                for (const f of ['budget', 'abc', 'duration', 'startDate', 'endDate']) {
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
                        for (const f of ['budget', 'abc', 'duration', 'startDate', 'endDate']) {
                            const rows = (pooled._debug && pooled._debug[f]) || [];
                            if (!rows.length) {
                                // Empty pool is honest, not a miss: the wrapper keeps the
                                // single-match/learned value (e.g. abc while its collector
                                // sleeps under test-blinding). Say so instead of "winner: null".
                                console.log('[Parser] field:', f, '| no pooled finds — single-match/learned value kept');
                                continue;
                            }
                            console.log('[Parser] field:', f, '| winner:', pooled[f], '| finds:', rows.length);
                            if (console.table) console.table(rows.slice(0, 10));
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

        // AI-3 opt-in trigger (Tier 3, very last): offer only when the local pipeline admits
        // weakness (few fills, low confidence, unresolved disagree). Silent on clean runs —
        // including blinded-clean files whose only miss is the blinded ABC (Decision A2:
        // high fill count + confidence with no disagree never offers). Stamps reset per run.
        window._autofillAiAssisted = {};
        window._autofillAiBusy = false;
        window._autofillAiMerged = false;
        var aiOffer = false;
        try {
            var aiCore = (typeof getCore === 'function') ? getCore() : null;
            if (aiCore && typeof aiCore.shouldOfferAiAssist === 'function') {
                aiOffer = !!aiCore.shouldOfferAiAssist(parsed, pooledDisagreeInfo);
            }
        } catch (e) { aiOffer = false; /* trigger is best-effort — preview still shows */ }

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
        showAutofillPreview(parsed, { ocrUsed, mismatch: mismatchInfo, disagree: window._autofillDisagree, truncatedFiles, scanReports, runId: myRunId, aiAssist: aiOffer });

    } catch (err) {
        console.error('[DocumentParser] Autofill failed:', err);
        // Silent failure — the user can still fill the form manually
        hideAutofillBanner();
    } finally {
        // This run is over, whatever happened. Clear the spinner if it is still the
        // spinner — showAutofillWarning() stamps its own state, so a notice this run
        // raised (weak parse, unread pages, the AI offer) survives untouched, and a
        // NEWER run's spinner is left alone because it re-stamps 'scanning' after this.
        // Before this the spinner was cleared only by showAutofillPreview() and by
        // closing the modal, so any path that ended without opening the preview left
        // it turning on a finished run.
        _autofillScansInFlight = Math.max(0, _autofillScansInFlight - 1);
        try { removeAutofillScanStatus(); } catch (e) { /* cleanup is best-effort */ }
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
// How many autofill scans are actually running right now. The "Scanning…" spinner
// is allowed to exist ONLY while this is above zero: it is raised from several
// places (the run start, and again per page during OCR), and before 2026-09-18 a
// call that arrived after a run had finished could repaint it with nothing left to
// clear it, so it sat spinning on a completed run until the modal was closed.
// Incremented by runDocumentAutofill() and decremented in its finally.
let _autofillScansInFlight = 0;
// Watchdog state for the scanning spinner: when it was last painted, and the timer
// that takes it down when the painting stops. See armAutofillSpinnerWatchdog().
let _autofillSpinnerTick = 0;
let _autofillSpinnerTimer = null;
// The file the scan indicator should name right now — set per file in the read loop.
let _autofillCurrentFile = '';
// Photo-read results for this modal session, keyed file#size#page@rotation. The
// rotation is IN the key, so a page the reviewer turns always misses and is
// genuinely re-read; the size guards against a different file arriving under a
// name already seen. Empty reads are cached too — see the note in ocrPage() and
// the deliberate escape hatch in _rescanWithSelection().
// Cleared with the rest of the session state in cancelDocumentAutofill().
let _ocrTextCache = new Map();
// How many pages the AI assist may ever be shown. Slice-only is a standing rule
// of this feature (never the whole file), so the number is named rather than
// repeated at each call site — and the AI page picker caps its selection to it.
const AI_SLICE_PAGES = 2;
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

// ===================================================
// P-3 — THE DOCUMENT-BAND CONTRAST STRETCH (2026-09-20)
// WHAT WAS WRONG, measured rather than guessed (restudy §17.1, §18):
//   The old gate keyed on lo/hi — the darkest and lightest pixel on the canvas — and
//   the stretch mapped [lo, hi] onto [0, 255]. On a photographed document lo is set by
//   the BINDER SHADOW and hi by the white PAGE MARGIN, neither of which is the
//   document. On the test scan that was 1.05% and 5.78% of the pixels: seven percent,
//   none of it text, both disabling the gate AND making the mapping the identity
//   function, so the stretch could not have helped even if it had fired.
//   Meanwhile the document itself lived between gray 120 and 180 — paper ~167, text
//   ~135, a separation of 32 levels out of 255 where a clean scan has ~215.
// WHAT THIS DOES INSTEAD: takes both the decision and the mapping from the page's OWN
//   band — percentiles computed over the document pixels, ignoring the near-white
//   margin — so a speck of shadow and a strip of margin can no longer decide either.
// THE MEASURED SEPARATOR: p5 of the document band ("how dark are the darkest strokes")
//   split all ten pages of the test scan with no exception — the four that read sat at
//   69-108, the six that yielded nothing at 113-145.
// ⚠ THOSE THRESHOLDS COME FROM ONE FILE. They are deliberately named constants, not
//   inline numbers, because real PMU scans (pilot plan §5 N2) are what should set them.
// ===================================================
const OCR_CLEANUP_MODE = 'band';        // 'band' = this rule · 'legacy' = the old lo/hi
                                        // gate, unchanged · 'off' = no cleanup at all
const OCR_BAND_WHITE_FLOOR = 240;       // at or above this is page margin, not document
const OCR_BAND_LOW_PCT = 0.05;          // the ink end of the band — the text strokes
const OCR_BAND_HIGH_PCT = 0.95;         // the paper end of the band
const OCR_BAND_STROKES_DARK_ENOUGH = 110; // whole-canvas p5 at or below this already
                                        // reads fine, so the page is left alone
                                        // (the §18.1 line, in the gap 108..113)
const OCR_BAND_MIN_WIDTH = 12;          // a band narrower than this cannot be stretched
                                        // without amplifying sensor noise into strokes
const OCR_BAND_MIN_DOC_SHARE = 0.05;    // a canvas that is almost all margin has no
                                        // document to take statistics from

// OCR group 1: light canvas cleanup (no new library) — grayscale + contrast stretch via
// plain 2D canvas APIs, so washed-out photocopies reach the reader with fuller blacks.
// Runs on the already-rendered canvas, in place, best-effort (any failure keeps the original).
// The F2 conditional gate (2026-09-07) still holds in spirit — a page whose strokes are
// already dark is left EXACTLY alone, because an unconditional remap calibrated to outlier
// specks can crush faint text into the background (the old suspect for test4 p1 @0° + p2
// @90° yielding 0 on upright, human-crisp pixels). What changed is what "already dark"
// means: it is now read from the document's own band, not from two outlier pixels.
// Decision is logged under window._parserDebug (applied/skipped, the sampled range, the
// band and its verdict, plus the T3 percentile/ink-fraction diagnostics).
// Called by: ocrPage() once per scanned page, before any recognize() attempt.
function preprocessOcrCanvas(canvas) {
    try {
        const ctx = canvas.getContext('2d');
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const d = img.data;
        let lo = 255, hi = 0, sum = 0, n = 0;
        // T3: a 256-bin gray histogram filled in the SAME pass — one array increment per
        // sample, so it costs nothing measurable. Everything below (the band, the
        // percentiles, the ink fractions) is read out of this one histogram rather than
        // by walking the pixels again.
        const hist = new Uint32Array(256);
        for (let i = 0; i < d.length; i += 16) {
            const g = (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
            if (g < lo) lo = g;
            if (g > hi) hi = g;
            sum += g; n++;
            hist[g | 0]++;
        }
        if (hi - lo < 8) return;

        // Percentile over ALL sampled pixels — used for the diagnostics only.
        const pct = (frac) => {
            const want = Math.max(1, Math.round(n * frac));
            let acc = 0;
            for (let v = 0; v < 256; v++) { acc += hist[v]; if (acc >= want) return v; }
            return 255;
        };
        const inkBelow = (t) => { let acc = 0; for (let v = 0; v < t; v++) acc += hist[v]; return acc; };

        // THE DOCUMENT BAND. Percentiles taken over the document pixels only — everything
        // below the white floor — so the page margin cannot drag the paper end up to 255
        // the way it drags `hi` up to 255 today. This is what makes one rule work for a
        // page that is 94% photo and a page that is half blank margin.
        let docN = 0;
        for (let v = 0; v < OCR_BAND_WHITE_FLOOR; v++) docN += hist[v];
        const bandPct = (frac) => {
            const want = Math.max(1, Math.round(docN * frac));
            let acc = 0;
            for (let v = 0; v < OCR_BAND_WHITE_FLOOR; v++) { acc += hist[v]; if (acc >= want) return v; }
            return OCR_BAND_WHITE_FLOOR - 1;
        };
        const docShare = docN / Math.max(1, n);
        const dLo = docN ? bandPct(OCR_BAND_LOW_PCT) : 0;
        const dHi = docN ? bandPct(OCR_BAND_HIGH_PCT) : 255;

        // THE GATE QUANTITY IS THE WHOLE-CANVAS PERCENTILE, NOT THE BAND ONE, AND THE
        // DIFFERENCE MATTERS. §18.1 measured its ten-page separation (readers 69-108,
        // dead 113-145) on the whole-canvas p5, and the band p5 is a DIFFERENT NUMBER:
        // excluding the margin moves the same percentile further down the dark tail.
        // On page 3 that is 109 against 112 — enough to fall the wrong side of the line
        // and skip the very page the rule exists for. So the gate keys on the quantity
        // that was actually validated; only the MAPPING uses the band.
        const strokeDark = pct(OCR_BAND_LOW_PCT);

        // THE DECISION. Each refusal carries its own reason so the ledger says WHY a page
        // was left alone — "skipped" with no reason is what made the old gate unreadable.
        const legacyWashed = (lo >= 100) && ((hi - lo) <= 140);
        let apply = false, sLo = lo, sHi = hi, why = '';
        if (OCR_CLEANUP_MODE === 'off') {
            why = 'disabled';
        } else if (OCR_CLEANUP_MODE === 'legacy') {
            apply = legacyWashed; why = legacyWashed ? 'legacy washed' : 'legacy clean';
        } else if (docShare < OCR_BAND_MIN_DOC_SHARE) {
            why = 'band: almost no document (' + (docShare * 100).toFixed(1) + '% )';
        } else if ((dHi - dLo) < OCR_BAND_MIN_WIDTH) {
            why = 'band: too narrow to stretch (' + (dHi - dLo) + ')';
        } else if (strokeDark <= OCR_BAND_STROKES_DARK_ENOUGH) {
            why = 'band: strokes already dark (' + strokeDark + ')';
        } else {
            apply = true; sLo = dLo; sHi = dHi; why = 'band';
        }

        if (typeof window !== 'undefined' && window._parserDebug) {
            // Diagnostics. The gate keys on the band now, but lo/hi/mean are still
            // reported FIRST and unchanged so every earlier ledger in the restudy plan
            // stays directly comparable against a new run.
            //   pN   = the Nth-percentile gray over the whole canvas
            //   ink< = the share of sampled pixels below a darkness threshold
            //   band = the document's own [ink .. paper] range, margin excluded
            //   gain = what the chosen mapping multiplies contrast by (1.00 = unchanged)
            try {
                const share = (c) => ((c / Math.max(1, n)) * 100).toFixed(2) + '%';
                console.log('[Parser] OCR cleanup ' + (apply ? 'applied' : 'skipped')
                    + ': lo=' + Math.round(lo) + ' hi=' + Math.round(hi) + ' mean=' + Math.round(sum / Math.max(1, n))
                    + ' p1=' + pct(0.01) + ' p5=' + pct(0.05) + ' p50=' + pct(0.50)
                    + ' p75=' + pct(0.75) + ' p95=' + pct(0.95) + ' p99=' + pct(0.99)
                    + ' ink<128=' + share(inkBelow(128)) + ' ink<160=' + share(inkBelow(160))
                    + ' | band=' + dLo + '..' + dHi + ' doc=' + (docShare * 100).toFixed(1) + '%'
                    + ' gain=' + (255 / Math.max(1, sHi - sLo)).toFixed(2)
                    + ' why=' + why);
            } catch (e) {}
        }

        if (!apply) return;
        // Map the chosen range onto the full scale. Values outside it clamp, which is the
        // point rather than a side effect: the binder shadow goes to black and the page
        // margin to white, and the document's own band gets the whole range to itself.
        const range = (sHi - sLo) || 1;
        for (let i = 0; i < d.length; i += 4) {
            const g = (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
            const v = Math.max(0, Math.min(255, Math.round(((g - sLo) / range) * 255)));
            d[i] = v; d[i + 1] = v; d[i + 2] = v;
        }
        ctx.putImageData(img, 0, 0);
    } catch (e) { /* preprocessing is best-effort — original canvas still works */ }
}

// Auto-crop dark margins (2026-09-07, ABC-or-bust): phone photos carry dark surround,
// binder holes and curl shadows that poison segmentation into "empty page".
// STRICT-SURROUND RULE (crop-harm fix): a strip counts as surround ONLY at ≥85% dark
// (light fraction < 0.15) — the old ≥50%-light stop walked inward through curl-shadow
// gradients and amputated yielding text (test4 p2 lost ~15 chars/line). Gradients no longer
// qualify. FALLBACK ROLE ONLY: never a pre-step — called solely by ocrPageCropFallback on
// dead pages, so yielding pages stay byte-identical to the F2-verdict path.
// Returns the kept fraction (0 = skipped/failed) so the caller can skip a pointless re-read.
// Skips when already full-bleed (>95% kept), degenerate (<50% kept or <50px side), or tiny.
// Best-effort: any failure keeps the original canvas. Debug-logged.
// Called by: ocrPageCropFallback() only.
function autocropOcrCanvas(canvas) {
    const dbg = (msg) => {
        try {
            if (typeof window !== 'undefined' && window._parserDebug) console.log('[Parser] OCR crop ' + msg);
        } catch (e) {}
    };
    try {
        const W = canvas.width, H = canvas.height;
        if (!W || !H || W < 100 || H < 100) { dbg('skipped (tiny canvas)'); return 0; }
        const ctx = canvas.getContext('2d');
        const img = ctx.getImageData(0, 0, W, H);
        const d = img.data;
        const grayAt = (x, y) => {
            const i = ((y * W) + x) * 4;
            return (d[i] * 299 + d[i + 1] * 587 + d[i + 2] * 114) / 1000;
        };
        // Light = paper (≥180). Stride keeps the scan cheap on 3000px canvases.
        // Strict surround: edge strips must be ≥85% DARK (light < 0.15) to cut — hole specks
        // and gradient shadows inside never qualify, so text is never amputated.
        const stride = Math.max(1, Math.floor(Math.min(W, H) / 400));
        const lightFracRow = (y) => {
            let light = 0, n = 0;
            for (let x = 0; x < W; x += stride) { n++; if (grayAt(x, y) >= 180) light++; }
            return n ? light / n : 0;
        };
        const lightFracCol = (x) => {
            let light = 0, n = 0;
            for (let y = 0; y < H; y += stride) { n++; if (grayAt(x, y) >= 180) light++; }
            return n ? light / n : 0;
        };
        let top = 0, bottom = H - 1, left = 0, right = W - 1;
        while (top < bottom && lightFracRow(top) < 0.15) top++;
        while (bottom > top && lightFracRow(bottom) < 0.15) bottom--;
        while (left < right && lightFracCol(left) < 0.15) left++;
        while (right > left && lightFracCol(right) < 0.15) right--;
        // Small margin outward, but only through light pixels (never re-add surround).
        const m = 10;
        for (let k = 1; k <= m; k++) {
            if (top - k >= 0 && lightFracRow(top - k) >= 0.7) top = top - k; else break;
        }
        for (let k = 1; k <= m; k++) {
            if (bottom + k < H && lightFracRow(bottom + k) >= 0.7) bottom = bottom + k; else break;
        }
        for (let k = 1; k <= m; k++) {
            if (left - k >= 0 && lightFracCol(left - k) >= 0.7) left = left - k; else break;
        }
        for (let k = 1; k <= m; k++) {
            if (right + k < W && lightFracCol(right + k) >= 0.7) right = right + k; else break;
        }
        const cw = right - left + 1, ch = bottom - top + 1;
        const kept = (cw * ch) / (W * H);
        if (kept > 0.95) { dbg('skipped (full-bleed)'); return 0; }
        if (kept < 0.5 || cw < 50 || ch < 50) { dbg('skipped (degenerate kept=' + kept.toFixed(2) + ')'); return 0; }
        const tmp = document.createElement('canvas');
        tmp.width = cw; tmp.height = ch;
        tmp.getContext('2d').drawImage(canvas, left, top, cw, ch, 0, 0, cw, ch);
        canvas.width = cw; canvas.height = ch;
        canvas.getContext('2d').drawImage(tmp, 0, 0);
        dbg('cropped x=' + left + ' y=' + top + ' w=' + cw + ' h=' + ch);
        return kept;
    } catch (e) { /* crop is best-effort — original canvas still works */ }
    return 0;
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

// Crop fallback (2026-09-07, ABC-or-bust): last rung for dead pages — re-renders at the main
// scale, applies the strict-surround auto-crop, then one default-PSM shot. Runs ONLY when the
// crop actually removed something (kept < 1); a skipped crop means the default shot already ran
// on identical pixels. Keeps whichever yields (10-char rule). Debug-logged, best-effort,
// budget counts pages (the caller already incremented for this page — no double count).
// Called by: ocrPage() dead-page fallback chain only (never the pre-step path).
async function ocrPageCropFallback(page, tesseract, rotationDeg, markEngineFailed, scale) {
    try {
        const canvas = document.createElement('canvas');
        const viewport = page.getViewport({ scale: scale || 3, rotation: rotationDeg || 0 });
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        const kept = autocropOcrCanvas(canvas) || 0;
        if (!(kept > 0) || kept > 0.95) return '';
        // NOTE: no preprocessOcrCanvas here — the crop rung isolates the geometry variable;
        // the wash-gate already had its say on the uncropped canvas.
        const ret = await tesseract.recognize(canvas, 'eng');
        const text = (ret && ret.data && ret.data.text) || '';
        if (typeof window !== 'undefined' && window._parserDebug) {
            try { console.log('[Parser] OCR crop fallback: chars=' + text.trim().length); } catch (e) {}
        }
        return (text.trim().length >= 10) ? text : '';
    } catch (err) {
        try { markEngineFailed(); } catch (e) {}
        return '';
    }
}

async function ocrPage(page, runId, rotationDeg = 0, cacheKey = '') {
    if (_ocrPagesDone >= ocrPageLimit()) return '';
    if (runId !== _autofillRunId) return '';

    // PHOTO-READ CACHE (2026-09-14). Re-reading a page at an angle it has already
    // been read at produces the same text for ~2s of work — and every rotate used
    // to re-read EVERY textless page of EVERY file, so a one-page nudge on a
    // ten-page scan cost about twenty seconds. The key carries the rotation, so a
    // page the reviewer actually turned always misses and is genuinely re-read;
    // only untouched pages are served from memory.
    //
    // Placed AFTER the budget gate and counted like a real read on purpose: the
    // set of pages attempted, the truncation flag and the ledgers must stay
    // byte-identical to an uncached run. Skipping the accounting would quietly
    // let later pages into a budget that was meant to be spent.
    if (cacheKey && _ocrTextCache.has(cacheKey)) {
        _ocrPagesDone++;
        return _ocrTextCache.get(cacheKey);
    }

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
    // Name the page, so the strip visibly MOVES while the read is working — a reviewer
    // watching a static spinner cannot tell progress from a hang.
    showAutofillScanStatus('Reading scanned page ' + (page && page.pageNumber ? page.pageNumber : '?') + '…');
    // NOTE (2026-09-07 crop-harm fix): NO pre-step auto-crop here — it amputated yielding text
    // (test4 p2 lost ~15 chars/line). Crop runs solely as the last dead-page fallback rung below.
    preprocessOcrCanvas(canvas);
    // R1 auto orientation, stabilized: probe orientation mode ONCE per session, then commit.
    // 'eng+osd' straightens tilted/sideways phone-photo pages, but on this CDN build the OSD
    // data can fail with "LSTM requested, but not present" — retrying per page spams red errors
    // and doubles time. So: first page tries osd; any failure pins plain 'eng' for the session.
    // window._ocrLangMode: null = untried, 'osd' = working, 'eng' = pinned fallback.
    // M-1: the build-level switch is tested FIRST, so a page read before any modal reset
    // has run (window._ocrLangMode still undefined) cannot slip onto the broken path.
    const useOsd = OCR_OSD_ENABLED && (typeof window === 'undefined' || window._ocrLangMode === undefined || window._ocrLangMode === null || window._ocrLangMode === 'osd');
    const markEngineFailed = () => { try { window._ocrEngineFailed = true; } catch (e) {} };
    // Dead-page fallback chain (2026-09-07): proven path first, experiments as rungs —
    // uncropped ladder result → scale-2 re-render → cropped re-render. Each rung runs only while
    // the page is still short (<10 chars) and the run is alive; the first yielding rung wins and
    // later rungs never fire. Budget still counts pages, never attempts.
    const tryDeadPageFallbacks = async (current) => {
        if (current.trim().length >= 10 || runId !== _autofillRunId) return current;
        const fb = await ocrPageScale2Fallback(page, tesseract, rotationDeg, markEngineFailed);
        if (fb.trim().length >= 10) return fb;
        if (runId !== _autofillRunId) return current;
        const cf = await ocrPageCropFallback(page, tesseract, rotationDeg, markEngineFailed, scale);
        if (cf.trim().length >= 10) return cf;
        return current;
    };
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
            return await tryDeadPageFallbacks(retry);
        } catch (err) {
            // Pin plain English for the rest of the session — one red line, not one per page.
            try {
                window._ocrLangMode = 'eng';
                console.warn('[Parser] orientation read unavailable, using plain OCR for this session.');
            } catch (e) {}
            try {
                const retry = await recognizeEnglish(tesseract, canvas, markEngineFailed);
                _ocrPagesDone++;
                return await tryDeadPageFallbacks(retry);
            } catch (err2) {
                markEngineFailed();
                return '';
            }
        }
    }
    try {
        const text = await recognizeEnglish(tesseract, canvas, markEngineFailed);
        _ocrPagesDone++;
        return await tryDeadPageFallbacks(text);
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
    // Per-page yield ledgers (2026-09-14): how much text each photo-read page gave
    // and whether it looked like symbol salad. Feeds the page strip's explanation.
    const pageChars = {};
    const soupyPages = [];
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
            // Cache key carries the angle, so turning a page re-reads it for real
            // while every untouched page comes back from memory (2026-09-14).
            const cacheKey = file.name + '#' + (file.size || 0) + '#' + pageNum + '@' + rotDeg;
            const ocrText = await ocrPage(page, runId, rotDeg, cacheKey);
            // Cache the EMPTY result too. A dead page costs the most (the PSM
            // ladder plus the scale-2 and crop fallbacks, up to ~6 recognitions),
            // and those are exactly the pages a rotate-heavy session revisits.
            // Tradeoff written down because it is real: photo-reading wobbles
            // slightly run to run (learning-loop plan, §6 2026-09-07), so caching
            // an empty forgoes the chance that an unchanged page reads next time.
            // The read-depth "Re-scan" clears the cache, which is the deliberate
            // way to ask for a genuinely fresh read.
            if (cacheKey && runId === _autofillRunId) {
                try { _ocrTextCache.set(cacheKey, ocrText || ''); } catch (e) { /* cache is best-effort */ }
            }
            if (ocrText && ocrText.trim().length >= 10) {
                pageText = ocrText;
                ocrUsed = true;
                scannedRead++;
                ocrPages.push(pageNum);
            }
            // Per-page yield, RECORDED not just logged (2026-09-14). The count was
            // computed here all along and thrown away into a debug console line, so
            // the page strip could say a page was unread but never WHY — "nothing at
            // all" and "forty characters of garbage" are different problems with
            // different remedies, and only the second is worth rotating for.
            // Soupiness is re-scored from the page text rather than plumbed out of
            // recognizeEnglish (which returns a bare string): the scorer is pure and
            // cheap, so re-running it costs nothing and changes no signature.
            try {
                const yielded = (pageText || '').trim();
                pageChars[pageNum] = yielded.length;
                if (yielded.length > 0 && core && typeof core.scoreOcrSoup === 'function') {
                    const s = core.scoreOcrSoup(yielded);
                    if (s && s.soupy) soupyPages.push(pageNum);
                }
            } catch (e) { /* instrumentation must never break a read */ }
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

    return { text: fullText, ocr: ocrUsed, truncated: ocrTruncated, scannedTotal, scannedRead, numPages: pdf.numPages, ocrPages, allLandscape: scannedTotal > 0 && landscapeCount >= scannedTotal, scannedPageNums, landscapePages, pagesVisited, pageChars, soupyPages };
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

// ── OCR PAGE LEDGER — ONE READING, SHARED BY EVERY SURFACE ──────────────────
// The scan report already knows which pages were photo-read, which of those
// yielded text, which are landscape, and what angle each carries. Until now the
// selection expression, the rotation lookup and the read/unread test were
// copy-pasted FOUR times across two functions (the preview's gray note and the
// amber notice) — which is exactly why those two surfaces already word the same
// tooltip differently. These helpers are the single reading; the page preview
// added next consumes the same structure, so a third surface cannot drift too.
// Pure apart from reading the two window stashes. Called by: showAutofillPreview,
// showAutofillWarning, and the page-preview panel.

// Pages a file offers for rotating or snapshotting: the scanned ledger when we
// have one, else whatever actually yielded text. The 12-per-file cap is the one
// both surfaces have always applied — kept so this factoring changes nothing.
function ocrListedPagesOf(report) {
    if (!report) return [];
    const scanned = (report.scannedPageNums && report.scannedPageNums.length) ? report.scannedPageNums : null;
    const read = (report.ocrPages && report.ocrPages.length) ? report.ocrPages : [];
    return (scanned || read).slice(0, 12);
}

// Every page a file can be rotated through — UNCAPPED, unlike ocrListedPagesOf.
// The distinction is real and was previously implicit: the surfaces show at most
// 12 buttons per file, but "rotate all" has always turned every scanned page,
// including ones with no button. Kept that way; named so it reads as deliberate.
function ocrRotatablePagesOf(report) {
    if (!report) return [];
    const scanned = (report.scannedPageNums && report.scannedPageNums.length) ? report.scannedPageNums : null;
    return scanned || (report.ocrPages || []);
}

// Live manual rotation for one page, in degrees. The key format is load-bearing:
// ocrPage(), _saveOcrPageImage() and buildAiSlices() all index _ocrRotations by
// exactly `fileName#pageNum`, so this must never invent its own.
function ocrRotationOf(fileName, pageNum) {
    try {
        const rots = (typeof window !== 'undefined' && window._ocrRotations) ? window._ocrRotations : {};
        return rots[fileName + '#' + pageNum] || 0;
    } catch (e) { return 0; }
}

// Did OCR get usable text out of this page? ocrPages holds the pages that cleared
// the 10-char bar; anything else in the scanned ledger was photo-read and came
// back empty. That distinction is what the `*` badge has always meant.
function ocrPageIsUnread(report, pageNum) {
    if (!report) return true;
    const read = (report.ocrPages && report.ocrPages.length) ? report.ocrPages : [];
    return read.indexOf(pageNum) === -1;
}

// Per-page facts for one file, in page order — the structure every page-aware
// surface should consume rather than re-deriving. Landscape comes from the
// per-page ledger (C1), falling back to the file-level flag for stashes captured
// before that ledger existed.
function ocrPageFactsOf(report) {
    if (!report) return [];
    const landscape = (report.landscapePages && report.landscapePages.length) ? report.landscapePages : null;
    const chars = report.pageChars || {};
    const soupy = report.soupyPages || [];
    return ocrListedPagesOf(report).map((pn) => {
        const read = !ocrPageIsUnread(report, pn);
        const n = Number(chars[pn]);
        return {
            pageNum: pn,
            rotation: ocrRotationOf(report.fileName, pn),
            read: read,
            landscape: landscape ? landscape.indexOf(pn) !== -1 : !!report.allLandscape,
            chars: isFinite(n) ? n : null,
            soupy: soupy.indexOf(pn) !== -1,
            why: ocrPageWhy(read, isFinite(n) ? n : null, soupy.indexOf(pn) !== -1),
        };
    });
}

// Say what happened to a page in words a reviewer can act on. "Nothing at all"
// and "forty characters of garbage" are different problems: the first is usually
// orientation or a dark scan, the second usually means the page WAS read and is
// simply not worth reading. Only the first is worth turning the page for.
function ocrPageWhy(read, chars, soupy) {
    if (read && soupy) return 'read, but the text looks garbled';
    if (read) return chars ? 'text read (' + chars + ' characters)' : 'text read';
    if (chars === null || chars === 0) return 'nothing at all — try turning it';
    if (soupy) return 'only garbled marks (' + chars + ' characters)';
    return 'too little to use (' + chars + ' characters)';
}

// The rotate and snapshot handlers resolve a file by INDEX (quote-safe — file
// names reach inline onclick attributes otherwise), so any surface rendering
// those buttons must publish the index→name map FIRST or every button is a
// silent no-op. Both surfaces did this separately; now neither can forget.
// NOTE the map is assigned by index and never truncated, so a shorter later run
// leaves stale tail entries until cancelDocumentAutofill() clears it.
function stashOcrRotateTargets(reports) {
    try {
        window._ocrRotateTargets = window._ocrRotateTargets || [];
        (reports || []).forEach((r, ri) => { if (r && r.fileName) window._ocrRotateTargets[ri] = r.fileName; });
    } catch (e) { /* best-effort */ }
}

// Are the diagnostic affordances wanted? The snapshot downloads and the read-text
// dump exist to get pixels and raw OCR output into a chat window — they are for
// whoever is debugging the parser, not for the person creating a project, and
// before the live preview existed they were the ONLY way to see a page at all.
// Now that the page is on screen they are noise, so they hide behind the same flag
// the per-page yield logging already uses: window._parserDebug = true.
// Called by: showAutofillWarning() and the preview's gray scan note.
function ocrDebugAffordances() {
    try { return !!(typeof window !== 'undefined' && window._parserDebug); } catch (e) { return false; }
}

// Pages whose CURRENT angle has never been photo-read — i.e. what a re-read would
// actually cost. Derived from the cache rather than tracked in a dirty set, so it
// cannot drift out of step with what the reader would really do.
// Called by: the preview panel's Re-read button label.
function ocrPendingRereadPages(report) {
    if (!report) return [];
    let size = 0;
    try {
        const files = window._autofillPdfFiles || [];
        for (const f of files) { if (f && f.fileName === report.fileName) { size = (f.file && f.file.size) || 0; break; } }
    } catch (e) { /* best-effort */ }
    return ocrRotatablePagesOf(report).filter((pn) => {
        const rot = ocrRotationOf(report.fileName, pn);
        return !_ocrTextCache.has(report.fileName + '#' + size + '#' + pn + '@' + rot);
    });
}

// ── LIVE PAGE PREVIEW (2026-09-14) ───────────────────────────────────────────
// The reviewer used to be asked to straighten pages they could not see — the only
// way to look was to download a snapshot per page. This renders the page itself,
// at its current angle, beside the controls that turn it.
//
// It does NOT reuse the document viewer modal, for two reasons: that modal renders
// PDFs in an iframe so the rotate control there is Chrome's own and unreachable
// from our JS, and more decisively the file has not been uploaded yet, so there is
// no URL to give it. See the plan's §3.1.
//
// Open documents are kept per file for the modal session — reopening the PDF on
// every click is the slow part — and released in cancelDocumentAutofill().
const _ocrPreviewDocs = new Map();
let _ocrPreview = null;      // { reportIdx, pageNum }
let _ocrPreviewToken = 0;    // supersedes an in-flight render, like the run id

// Resolve (and memoise) the pdf.js document for a stashed file.
async function ocrPreviewDocFor(fileName) {
    if (_ocrPreviewDocs.has(fileName)) return _ocrPreviewDocs.get(fileName);
    const files = window._autofillPdfFiles || [];
    let entry = null;
    for (const f of files) { if (f && f.fileName === fileName) { entry = f; break; } }
    if (!entry || !entry.file || typeof entry.file.arrayBuffer !== 'function') return null;
    const pdfjs = await getPdfJs();
    const buf = await entry.file.arrayBuffer();
    const doc = await pdfjs.getDocument({ data: buf }).promise;
    _ocrPreviewDocs.set(fileName, doc);
    return doc;
}

// Release every open document. Called by cancelDocumentAutofill() — without this
// a modal session that previewed several files holds them all until reload.
function releaseOcrPreviewDocs() {
    try {
        _ocrPreviewDocs.forEach((doc) => { try { doc.destroy(); } catch (e) {} });
        _ocrPreviewDocs.clear();
    } catch (e) { /* best-effort */ }
    _ocrPreview = null;
}

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
                : ocrRotatablePagesOf(r);
            for (const pn of targets) {
                const key = r.fileName + '#' + pn;
                window._ocrRotations[key] = (((window._ocrRotations[key] || 0) + 90) % 360);
            }
        }
    } catch (e) { /* rotation bookkeeping is best-effort */ }
    hideAutofillBanner();
    runDocumentAutofill();
};

// P-ROT bulk rotate-all (2026-09-07, testing aid): rotate EVERY scanned page of one file
// +90° and re-run, regardless of landscape flags or yield. Index-indirect (quote-safe) like
// the per-page handler; session rotations persist for the re-run (cancel is NOT called).
// Called by: inline onclick on the "rotate all" buttons (gray note + empty warning).
window._rotateAllScanned = function (reportIdx) {
    try {
        const reports = window._autofillScanReports || [];
        const r = reports[reportIdx];
        if (!r) return;
        window._ocrRotations = window._ocrRotations || {};
        const targets = ocrRotatablePagesOf(r);
        for (const pn of targets) {
            const key = r.fileName + '#' + pn;
            window._ocrRotations[key] = (((window._ocrRotations[key] || 0) + 90) % 360);
        }
    } catch (e) { /* rotation bookkeeping is best-effort */ }
    // 2026-09-14: turning is now INSTANT. It used to re-run the whole pipeline over
    // every uploaded PDF — ~20s for one click on a ten-page scan — which is why the
    // preview panel exists at all: you could not see what you were turning, and you
    // paid twenty seconds to find out. Re-reading is the Re-read button now, and the
    // photo-read cache makes it cost only the pages actually turned.
    renderOcrPagePreview();
};

// ── WHICH PAGES GO TO THE AI ─────────────────────────────────────────────────
// The ranking has always been "whichever pages OCR handled worst" — unread first,
// then read — and it has never had any notion of whether a page CONTAINS the
// fields. A blank page, a signature sheet or a photo of a stamp therefore ranks
// FIRST, precisely because it yielded nothing. That is the system's blind spot and
// it cannot be fixed from the inside: only the person looking at the page knows.
// So the ranking is now shown with its reason, and can be overridden.
// Pure over the stashes. Called by: buildAiSlices() and the preview panel.
function ocrAiCandidates(maxN) {
    const out = [];
    try {
        const reports = window._autofillScanReports || [];
        for (let ri = 0; ri < reports.length && out.length < maxN; ri++) {
            const r = reports[ri];
            if (!r || !r.fileName) continue;
            const scanned = (r.scannedPageNums && r.scannedPageNums.length) ? r.scannedPageNums : [];
            const read = {};
            (r.ocrPages || []).forEach((pn) => { read[pn] = true; });
            // The original ordering, unchanged: pages that yielded nothing first.
            let ordered = scanned.filter((pn) => !read[pn]).concat(scanned.filter((pn) => read[pn]));
            let fallback = false;
            if (!ordered.length && r.numPages) {
                fallback = true;
                ordered = [];
                for (let p = 1; p <= Math.min(r.numPages, 2) && ordered.length < 2; p++) ordered.push(p);
            }
            for (let k = 0; k < ordered.length && out.length < maxN; k++) {
                const pn = ordered[k];
                out.push({
                    reportIdx: ri,
                    fileName: r.fileName,
                    pageNum: pn,
                    reason: fallback ? 'first pages of a file with no photo-read pages'
                        : (read[pn] ? 'read, but among the weakest' : 'the reader got nothing here'),
                });
            }
        }
    } catch (e) { /* selection is best-effort */ }
    return out;
}

// What will ACTUALLY be sent: the reviewer's picks when they have made any, else
// the ranking above. Picks are validated against the current reports, so a stale
// choice from before a re-read can never smuggle a page that no longer exists.
function ocrAiSelection(maxN) {
    try {
        const picked = window._ocrAiPick;
        if (Array.isArray(picked) && picked.length) {
            const reports = window._autofillScanReports || [];
            const valid = [];
            for (const p of picked) {
                if (!p || valid.length >= maxN) continue;
                const ri = reports.findIndex((r) => r && r.fileName === p.fileName);
                if (ri === -1) continue;
                if (ocrRotatablePagesOf(reports[ri]).indexOf(p.pageNum) === -1) continue;
                valid.push({ reportIdx: ri, fileName: p.fileName, pageNum: p.pageNum, reason: 'you chose this page' });
            }
            if (valid.length) return valid;
        }
    } catch (e) { /* fall through to the ranking */ }
    return ocrAiCandidates(maxN);
}

// Add or remove the shown page from the AI selection. The first toggle seeds the
// list from the current ranking, so choosing one page does not silently discard
// the other — the reviewer is adjusting a selection, not starting from nothing.
window._ocrAiTogglePage = function () {
    try {
        if (!_ocrPreview) return;
        const reports = window._autofillScanReports || [];
        const r = reports[_ocrPreview.reportIdx];
        if (!r) return;
        const pn = _ocrPreview.pageNum;
        let list = Array.isArray(window._ocrAiPick) && window._ocrAiPick.length
            ? window._ocrAiPick.slice()
            : ocrAiSelection(AI_SLICE_PAGES).map((c) => ({ fileName: c.fileName, pageNum: c.pageNum }));
        const at = list.findIndex((p) => p && p.fileName === r.fileName && p.pageNum === pn);
        if (at !== -1) list.splice(at, 1);
        else {
            list.push({ fileName: r.fileName, pageNum: pn });
            // Oldest choice makes way — the cap is what keeps this slice-only.
            while (list.length > AI_SLICE_PAGES) list.shift();
        }
        window._ocrAiPick = list.length ? list : null;
    } catch (e) { /* best-effort */ }
    renderOcrPagePreview();
};

// Back to the system's own ranking.
window._ocrAiResetPick = function () {
    window._ocrAiPick = null;
    renderOcrPagePreview();
};

// R2 manual per-page rotation: cycle 0→90→180→270→0 for one photo-read page.
// 2026-09-14 — THIS NO LONGER RE-READS. It used to close the preview and re-run the
// whole pipeline over every uploaded PDF, ~2s per scanned page, so one nudge on a
// ten-page scan cost about twenty seconds AND the reviewer could not see the page
// they were nudging. Now it turns the page, updates the labels, redraws the preview,
// and stops. Re-reading is the Re-read button, and the photo-read cache means it
// costs only the pages whose angle actually changed.
// Called by: inline onclick on the per-page ⟳ buttons (index-indirected, quote-safe)
// and by the preview panel.
window._rotateOcrPage = function (reportIdx, pageNum) {
    try {
        const targets = window._ocrRotateTargets || [];
        const fileName = targets[reportIdx];
        if (!fileName || !pageNum) return;
        window._ocrRotations = window._ocrRotations || {};
        const key = fileName + '#' + pageNum;
        window._ocrRotations[key] = ((window._ocrRotations[key] || 0) + 90) % 360;
    } catch (e) { /* rotation bookkeeping is best-effort */ }
    // Show what was just turned, so the button and the picture agree.
    try {
        if (!_ocrPreview || _ocrPreview.reportIdx !== reportIdx) _ocrPreview = { reportIdx: reportIdx, pageNum: pageNum };
        else _ocrPreview.pageNum = pageNum;
    } catch (e) { /* best-effort */ }
    renderOcrPagePreview();
};

// The panel shell. Rendered empty by whichever surface hosts it; filled by
// renderOcrPagePreview(). One id, so only one panel can ever exist on screen.
function ocrPagePreviewShellHtml() {
    return '<div id="ocrPagePreview" class="mt-2 p-2 border rounded bg-white" style="display:none;">'
        + '<div id="ocrPreviewCaption" class="d-flex align-items-center gap-2 flex-wrap mb-1" style="font-size:0.85em;"></div>'
        + '<div id="ocrPreviewStage" class="d-flex justify-content-center align-items-center bg-light border rounded" style="min-height:180px;overflow:auto;max-height:420px;">'
        + '<span class="text-muted" style="font-size:0.85em;">Pick a page to see it.</span></div>'
        + '<div id="ocrPreviewControls" class="d-flex gap-1 flex-wrap mt-2"></div>'
        + '<div id="ocrPreviewStrip" class="d-flex gap-1 flex-wrap mt-2"></div>'
        + '<div id="ocrPreviewAi" class="d-flex gap-1 flex-wrap align-items-center mt-2 pt-2 border-top"></div>'
        + '</div>';
}

// Draw the selected page at its current angle, plus the caption, controls and the
// page strip. Every failure degrades to a message — a preview must never be able
// to break the autofill flow it sits inside.
// Called by: _ocrPreviewShow/_ocrPreviewStep/_ocrPreviewRotate*, and after a rotate.
async function renderOcrPagePreview() {
    const host = document.getElementById('ocrPagePreview');
    if (!host) return;
    const reports = window._autofillScanReports || [];
    if (!_ocrPreview || !reports[_ocrPreview.reportIdx]) { host.style.display = 'none'; return; }
    const r = reports[_ocrPreview.reportIdx];
    const pages = ocrRotatablePagesOf(r);
    if (!pages.length) { host.style.display = 'none'; return; }
    if (pages.indexOf(_ocrPreview.pageNum) === -1) _ocrPreview.pageNum = pages[0];
    host.style.display = '';

    const pn = _ocrPreview.pageNum;
    const deg = ocrRotationOf(r.fileName, pn);
    const unread = ocrPageIsUnread(r, pn);
    const at = pages.indexOf(pn);

    // Caption — what page this is, and whether the reader got anything from it.
    const cap = document.getElementById('ocrPreviewCaption');
    if (cap) {
        const fact = ocrPageFactsOf(r).filter((f) => f.pageNum === pn)[0];
        cap.innerHTML = '<strong>' + escapeHtml(r.fileName) + '</strong>'
            + '<span class="text-muted">page ' + pn + ' of ' + (r.numPages || pages.length) + '</span>'
            + (deg ? '<span class="badge bg-secondary">turned ' + deg + '°</span>' : '')
            + (unread
                ? '<span class="badge bg-warning text-dark">' + escapeHtml(fact ? fact.why : 'no text read yet') + '</span>'
                : '<span class="badge bg-success">' + escapeHtml(fact ? fact.why : 'text read') + '</span>');
    }

    // Controls. Rotate is INSTANT here — it turns the picture and nothing else.
    const ctl = document.getElementById('ocrPreviewControls');
    if (ctl) {
        const pending = ocrPendingRereadPages(r).length;
        ctl.innerHTML =
            '<button type="button" class="btn btn-sm btn-outline-secondary" ' + (at <= 0 ? 'disabled ' : '') + 'onclick="window._ocrPreviewStep(-1)">‹ prev</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" ' + (at >= pages.length - 1 ? 'disabled ' : '') + 'onclick="window._ocrPreviewStep(1)">next ›</button>'
            + '<button type="button" class="btn btn-sm btn-outline-primary" onclick="window._ocrPreviewRotate()" title="Turn this page 90°. Nothing is re-read until you press Re-read.">⟳ turn this page</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._ocrPreviewRotateFile()" title="Turn every scanned page of this file 90°">⟳ turn whole file</button>'
            + (pending > 0
                ? '<button type="button" class="btn btn-sm btn-primary ms-auto" onclick="window._ocrPreviewReread()" title="Read the turned pages again — only these are re-read">Re-read ' + pending + ' page' + (pending === 1 ? '' : 's') + '</button>'
                : '<span class="ms-auto text-muted" style="font-size:0.85em;">Nothing turned since the last read.</span>');
    }

    // Page strip — what the reader got from each page, and which ones the AI
    // would be shown. A ✦ marks a page in the current AI selection.
    const sel = ocrAiSelection(AI_SLICE_PAGES);
    const inSel = (fileName, pageNum) => sel.some((c) => c.fileName === fileName && c.pageNum === pageNum);
    const strip = document.getElementById('ocrPreviewStrip');
    if (strip) {
        strip.innerHTML = '<span class="text-muted me-1" style="font-size:0.8em;">Pages:</span>' + ocrPageFactsOf(r).map((f) => {
            const cls = f.pageNum === pn ? 'btn-dark' : (f.read ? 'btn-outline-success' : 'btn-outline-warning');
            const ai = inSel(r.fileName, f.pageNum);
            return '<button type="button" class="btn btn-sm ' + cls + '" style="padding:1px 6px;font-size:0.75em;"'
                + ' onclick="window._ocrPreviewShow(' + _ocrPreview.reportIdx + ',' + f.pageNum + ')"'
                + ' title="Page ' + f.pageNum + ' — ' + escapeHtml(f.why) + (f.rotation ? ', turned ' + f.rotation + '°' : '') + (ai ? ' — the AI would read this one' : '') + '">'
                + f.pageNum + (f.read ? '' : '*') + (ai ? ' ✦' : '') + '</button>';
        }).join('');
    }

    // What the AI would be shown, and why — the ranking was invisible until now,
    // and it ranks by "which pages OCR handled worst", which is NOT the same as
    // "which pages hold the contract details". Hence the override.
    const aiRow = document.getElementById('ocrPreviewAi');
    if (aiRow) {
        const mine = Array.isArray(window._ocrAiPick) && window._ocrAiPick.length;
        const chosen = inSel(r.fileName, pn);
        aiRow.innerHTML = '<span class="text-muted" style="font-size:0.8em;">✦ AI assist would read:</span> '
            + (sel.length
                ? sel.map((c) => '<span class="badge bg-light text-dark border" title="' + escapeHtml(c.reason) + '">'
                    + escapeHtml(c.fileName) + ' p.' + c.pageNum + '</span>').join(' ')
                : '<span class="text-muted" style="font-size:0.8em;">nothing yet</span>')
            + ' <button type="button" class="btn btn-sm ' + (chosen ? 'btn-outline-danger' : 'btn-outline-secondary') + '"'
            + ' style="padding:1px 8px;font-size:0.75em;" onclick="window._ocrAiTogglePage()"'
            + ' title="' + (chosen ? 'Do not send this page' : 'Send this page instead — at most ' + AI_SLICE_PAGES + ' are ever sent') + '">'
            + (chosen ? '− this page' : '+ this page') + '</button>'
            + (mine ? ' <button type="button" class="btn btn-sm btn-link" style="padding:1px 4px;font-size:0.75em;" onclick="window._ocrAiResetPick()">use the suggestion</button>' : '');
    }

    // The page itself. Token-guarded so a fast prev/next cannot paint out of order.
    const stage = document.getElementById('ocrPreviewStage');
    if (!stage) return;
    const token = ++_ocrPreviewToken;
    stage.innerHTML = '<span class="text-muted" style="font-size:0.85em;">Rendering page ' + pn + '…</span>';
    try {
        const doc = await ocrPreviewDocFor(r.fileName);
        if (token !== _ocrPreviewToken) return;
        if (!doc || pn < 1 || pn > doc.numPages) {
            stage.innerHTML = '<span class="text-muted" style="font-size:0.85em;">Original file no longer available — re-upload to preview.</span>';
            return;
        }
        const page = await doc.getPage(pn);
        if (token !== _ocrPreviewToken) return;
        // Screen scale, deliberately independent of the OCR render (which uses 3).
        const base = page.getViewport({ scale: 1, rotation: deg || 0 });
        const wrapW = Math.max(240, Math.min(stage.clientWidth || 560, 720));
        const scale = Math.max(0.2, Math.min(2, wrapW / (base.width || wrapW)));
        const viewport = page.getViewport({ scale, rotation: deg || 0 });
        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.style.maxWidth = '100%';
        canvas.style.height = 'auto';
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        if (token !== _ocrPreviewToken) return;
        stage.innerHTML = '';
        stage.appendChild(canvas);
    } catch (e) {
        if (token !== _ocrPreviewToken) return;
        stage.innerHTML = '<span class="text-muted" style="font-size:0.85em;">Could not draw this page.</span>';
    }
}

// Show a page. Called by: the "see pages" button and every strip chip.
window._ocrPreviewShow = function (reportIdx, pageNum) {
    _ocrPreview = { reportIdx: reportIdx, pageNum: pageNum };
    renderOcrPagePreview();
};

// Step through the pages this file offers.
window._ocrPreviewStep = function (delta) {
    try {
        const reports = window._autofillScanReports || [];
        const r = reports[_ocrPreview && _ocrPreview.reportIdx];
        if (!r) return;
        const pages = ocrRotatablePagesOf(r);
        const at = pages.indexOf(_ocrPreview.pageNum);
        const next = pages[at + delta];
        if (next === undefined) return;
        _ocrPreview.pageNum = next;
        renderOcrPagePreview();
    } catch (e) { /* best-effort */ }
};

// Turn the shown page / the whole file. Both DELEGATE to the existing handlers
// rather than repeating the angle arithmetic — one rotation path, so the panel and
// any debug button can never disagree about what a turn means. Both are instant:
// they redraw and re-read nothing, and the Re-read button then costs only the
// pages actually turned, because the photo-read cache is keyed by angle.
window._ocrPreviewRotate = function () {
    if (!_ocrPreview) return;
    window._rotateOcrPage(_ocrPreview.reportIdx, _ocrPreview.pageNum);
};

window._ocrPreviewRotateFile = function () {
    if (!_ocrPreview) return;
    window._rotateAllScanned(_ocrPreview.reportIdx);
};

// Read the turned pages again. This runs the normal pipeline — every ledger is
// recomputed from the same walk as always — but the photo-read cache means only
// pages whose angle changed actually cost a recognition.
window._ocrPreviewReread = function () {
    try { closeAutofillPreview(); } catch (e) { /* no preview on the empty-warning path */ }
    hideAutofillBanner();
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
    // THE DELIBERATE FRESH READ. This button means "read it again properly", so it
    // drops the photo-read cache — which is also the only way back to the old
    // retry-a-flaky-page behaviour now that empty reads are remembered. Re-read
    // (rotations) reuses the cache; Re-scan (this) does not.
    try { _ocrTextCache = new Map(); } catch (e) { /* best-effort */ }
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

// ===================================================
// AI-3 OPT-IN ASSIST (Tier 3, very last)
// Renders the weakest pages to 150 DPI slices, POSTs them to parse-gemini once,
// and merges returned values into EMPTY preview boxes for review (never auto-applies).
// Slice-only (≤2 pages, never the whole file) + unsure-only (banner gates the call).
// Called by: inline onclick on the AI banner button (+ empty-warning button).
// ===================================================
// Pick up to maxN (fileName, pageNum) candidates: unread scanned pages first, then any
// scanned pages, then first pages for text files (scanReports know the ledger).
// Pure selection over stashes (no DOM). Called by: _tryAiAssist*().
async function buildAiSlices(maxN) {
    const out = [];
    try {
        const files = window._autofillPdfFiles || [];
        // ONE source of truth for which pages go (2026-09-14). The ranking used to
        // live inline here, invisible and unchangeable; it is now ocrAiCandidates()
        // and the reviewer's override rides the same call, so what the panel SHOWS
        // and what is actually SENT cannot drift apart.
        const cands = ocrAiSelection(maxN);
        const rots = (typeof window !== 'undefined' && window._ocrRotations) ? window._ocrRotations : {};
        for (let c = 0; c < cands.length; c++) {
            try {
                let entry = null;
                for (let fi = 0; fi < files.length; fi++) {
                    if (files[fi] && files[fi].fileName === cands[c].fileName) { entry = files[fi]; break; }
                }
                if (!entry || !entry.file || typeof entry.file.arrayBuffer !== 'function') continue;
                const rotDeg = rots[cands[c].fileName + '#' + cands[c].pageNum] || 0;
                const pdfjs = await getPdfJs();
                const buf = await entry.file.arrayBuffer();
                const pdf = await pdfjs.getDocument({ data: buf }).promise;
                if (!pdf || cands[c].pageNum < 1 || cands[c].pageNum > pdf.numPages) {
                    try { pdf && pdf.destroy(); } catch (e) {}
                    continue;
                }
                const page = await pdf.getPage(cands[c].pageNum);
                // 150 DPI ≈ scale 2.08 (Letter → ~1650px); skip absurd renders.
                const viewport = page.getViewport({ scale: 2.08, rotation: rotDeg || 0 });
                if (Math.max(viewport.width, viewport.height) > 4000) {
                    try { pdf.destroy(); } catch (e) {}
                    continue;
                }
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                let url = '';
                try { url = canvas.toDataURL('image/png'); } catch (e) { url = ''; }
                try { pdf.destroy(); } catch (e) {}
                if (url && url.length > 1000) out.push({ data: url, mimeType: 'image/png' });
            } catch (e) { /* per-page best-effort — try the next candidate */ }
        }
    } catch (e) { /* slicing is best-effort */ }
    return out;
}

// Merge AI values into EMPTY preview boxes + stamp provenance (Decision A3).
// Returns the filled count. Non-empty boxes keep rule values in v1 (user edits freely).
// Called by: _tryAiAssist().
function mergeAiParsedIntoPreview(aiParsed) {
    let filled = 0;
    try {
        const core = (typeof getCore === 'function') ? getCore() : null;
        const toInput = {};
        try {
            for (const inputId of Object.keys(AUTOFILL_CORE_FIELDS)) {
                toInput[AUTOFILL_CORE_FIELDS[inputId]] = inputId;
            }
        } catch (e) { return 0; }
        window._autofillAiAssisted = window._autofillAiAssisted || {};
        for (const coreField of Object.keys(toInput)) {
            const val = aiParsed ? aiParsed[coreField] : null;
            if (val === null || val === undefined || String(val).trim() === '') continue;
            const el = document.getElementById('previewInput-' + toInput[coreField]);
            if (!el || String(el.value || '').trim() !== '') continue;
            let shown = String(val);
            try {
                if (el.type === 'date' && core) shown = core.parseToInputDate(val) || shown;
            } catch (e) { /* keep raw on normalizer failure */ }
            el.value = shown;
            window._autofillAiAssisted[coreField] = true;
            try {
                const td = el.closest ? el.closest('td') : (el.parentNode || null);
                if (td && !td.querySelector('[data-ai-marker]')) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-info text-dark';
                    badge.setAttribute('data-ai-marker', '1');
                    badge.title = 'Filled by the AI-assisted read — please verify';
                    badge.textContent = 'AI-assisted';
                    td.appendChild(document.createTextNode(' '));
                    td.appendChild(badge);
                }
            } catch (e) { /* marker is best-effort */ }
            filled++;
        }
    } catch (e) { /* merge is best-effort */ }
    return filled;
}

// POST slices to parse-gemini (role-aware, one call). Returns parsed shape or null.
// Stashes the server's message (mapped transport texts like quota/busy/dead-model)
// so failure spots surface it instead of a generic line.
// Called by: _tryAiAssist*().
async function postAiSlices(slices) {
    try { window._autofillAiLastError = null; window._autofillAiFallbackModel = null; } catch (e) {}
    const path = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
    if (path.indexOf('/contractor/') !== -1) return null;
    const base = path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
    const token = (window._projectModalConfig && window._projectModalConfig.csrfToken) ||
        (((typeof document !== 'undefined') && document.querySelector('meta[name="csrf-token"]')) || {}).content || '';
    const res = await fetch(base + '/document/parse-gemini', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ images: slices }),
    });
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok || !data || !data.success || !data.parsed) {
        // Keep the mapped server message (422 transport voices); validation
        // misses carry no message and fall back to the generic lines below.
        try {
            window._autofillAiLastError =
                (!res.ok && data && typeof data.message === 'string' && data.message.trim() !== '')
                    ? data.message.trim()
                    : null;
        } catch (e) {}
        return null;
    }
    // Backup-model provenance (normal-doc Lite fallback): review knows whose homework.
    try { window._autofillAiFallbackModel = data.fallbackModel || null; } catch (e) {}
    return data.parsed;
}

// Server-mapped message from the last assist call, or the generic fallback.
// Called by: _tryAiAssist*() failure spots.
function aiFailureMessage(fallback) {
    try {
        if (typeof window._autofillAiLastError === 'string' && window._autofillAiLastError !== '') {
            return window._autofillAiLastError;
        }
    } catch (e) {}
    return fallback;
}

// Preview-banner entry: slice weakest pages, ask AI once, merge into empty boxes.
// Stale-run guarded; busy-guarded (one call per preview); failures surface inline.
// Called by: inline onclick on the AI banner button.
window._tryAiAssist = async function () {
    if (window._autofillAiBusy) return;
    const runAtClick = (typeof _autofillRunId !== 'undefined') ? _autofillRunId : null;
    const aiEl = document.getElementById('autofillPreviewAi');
    const btn = aiEl ? aiEl.querySelector('button') : null;
    window._autofillAiBusy = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Asking AI…'; }
    try {
        const slices = await buildAiSlices(AI_SLICE_PAGES);
        if (runAtClick !== null && runAtClick !== _autofillRunId) return;
        if (!slices.length) {
            if (typeof showToast === 'function') showToast('No page images available — re-upload to retry', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Try AI assist'; }
            window._autofillAiBusy = false;
            return;
        }
        const parsed = await postAiSlices(slices);
        if (runAtClick !== null && runAtClick !== _autofillRunId) return;
        if (!parsed) {
            try {
                if (aiEl && !aiEl.querySelector('[data-ai-error]')) {
                    const err = document.createElement('div');
                    err.setAttribute('data-ai-error', '1');
                    err.style.fontSize = '0.85em';
                    err.textContent = aiFailureMessage('The AI assistant could not read these pages either — please fill in the fields manually.');
                    aiEl.appendChild(err);
                }
            } catch (e) {}
            if (btn) { btn.disabled = false; btn.textContent = 'Try AI assist'; }
            window._autofillAiBusy = false;
            return;
        }
        const n = mergeAiParsedIntoPreview(parsed);
        try {
            let fallbackNote = '';
            try {
                if (window._autofillAiFallbackModel) {
                    fallbackNote = ' Answered by backup model — verify carefully.';
                }
            } catch (e) {}
            if (aiEl) aiEl.innerHTML = '<i class="bi bi-magic"></i><span style="font-size:0.9em;">AI assist filled <strong>' + n + '</strong> empty box(es) — review, edit freely, then Apply. Boxes with local values were left alone.' + fallbackNote + '</span>';
        } catch (e) {}
        window._autofillAiMerged = true;
    } catch (e) {
        if (typeof showToast === 'function') showToast(aiFailureMessage('AI assist failed — please fill in the fields manually'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Try AI assist'; }
        window._autofillAiBusy = false;
    }
};

// Empty-warning entry (zero-fill runs have no preview): same slice + ask, then OPEN a
// preview with the AI values on an empty baseline so kept rows harvest with AI source.
// Called by: inline onclick on the empty-warning AI button.
window._tryAiAssistFromWarning = async function () {
    if (window._autofillAiBusy) return;
    window._autofillAiBusy = true;
    if (typeof showToast === 'function') showToast('Asking the AI assistant to read the weakest pages…', 'info');
    try {
        const slices = await buildAiSlices(AI_SLICE_PAGES);
        if (!slices.length) {
            if (typeof showToast === 'function') showToast('No page images available — re-upload to retry', 'error');
            window._autofillAiBusy = false;
            return;
        }
        const parsed = await postAiSlices(slices);
        if (!parsed) {
            if (typeof showToast === 'function') showToast(aiFailureMessage('The AI assistant could not read these pages either'), 'error');
            window._autofillAiBusy = false;
            return;
        }
        const core = (typeof getCore === 'function') ? getCore() : null;
        const fields = ['title', 'location', 'description', 'budget', 'abc', 'budgetSource', 'category', 'startDate', 'endDate', 'duration', 'contractor'];
        const full = {};
        window._autofillAiAssisted = {};
        for (const f of fields) {
            const v = parsed[f];
            full[f] = (v === null || v === undefined) ? null : String(v);
            if (full[f] !== null && full[f].trim() !== '') window._autofillAiAssisted[f] = true;
        }
        try { hideAutofillBanner(); } catch (e) {}
        showAutofillPreview(full, { aiAssist: false, emptyBaseline: true, runId: (typeof _autofillRunId !== 'undefined') ? _autofillRunId : null });
        window._autofillAiMerged = true;
    } catch (e) {
        if (typeof showToast === 'function') showToast(aiFailureMessage('AI assist failed'), 'error');
    }
    window._autofillAiBusy = false;
};

function showAutofillPreview(parsed, { ocrUsed = false } = {}) {
    // B-2: the create-in-place gate needs to know whether these values were READ OFF A
    // PHOTOGRAPH or lifted from a text layer, and syncAutofillChoiceFields() runs later
    // (and again on shown.bs.modal), so the flag is stashed rather than threaded.
    try { window._autofillOcrUsed = !!ocrUsed; } catch (e) { /* best-effort */ }
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
    // LAYOUT 2026-09-18: `w` is the Bootstrap column span this field gets on a
    // medium-or-wider screen — 12 for free text that genuinely needs the width
    // (title, location, description), 6 for everything short so it pairs up.
    // Order is grouped by kind rather than by extraction order: the long text
    // first, then what/who, then the money, then the schedule. Nothing downstream
    // depends on this order (the L1 snapshot below is keyed by field name).
    // `choices` names the LIVE form control whose options this field should offer.
    // Rendered as a real <select> (the same dropdown the create form has), filled by
    // syncAutofillChoiceFields() because those lists are fetched asynchronously. An
    // extracted value that matches no option is kept as its own labelled option rather
    // than dropped — and every option's VALUE is the NAME, because Apply and
    // autofillFormFields() match these fields by name, not by id.
    const fields = [
        { id: 'projectTitle', label: 'Project Title', value: parsed.title, w: 12 },
        { id: 'projectLocation', label: 'Location', value: parsed.location, w: 12 },
        { id: 'projectDescription', label: 'Description', value: parsed.description, w: 12 },
        { id: 'projectCategory', label: 'Category', value: parsed.category, w: 6, choices: 'projectCategory' },
        { id: 'contractorSelect', label: 'Contractor', value: parsed.contractor, w: 6, choices: 'contractorSelect' },
        { id: 'projectBudget', label: 'Contract Price', value: parsed.budget, w: 6 },
        { id: 'projectAbc', label: 'ABC (Approved Budget for Contract)', value: parsed.abc, w: 6 },
        { id: 'projectBudgetSource', label: 'Budget Source', value: parsed.budgetSource, w: 6, choices: 'projectBudgetSource' },
        { id: 'plannedDuration', label: 'Duration (days)', value: parsed.duration, w: 6 },
        { id: 'plannedStartDate', label: 'Start Date', value: parsed.startDate, w: 6 },
        { id: 'plannedEndDate', label: 'End Date', value: parsed.endDate, w: 6 },
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
        const DISAGREE_INPUT = { budget: 'projectBudget', abc: 'projectAbc', duration: 'plannedDuration', startDate: 'plannedStartDate', endDate: 'plannedEndDate' };
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
        // AI-3: per-field "AI-assisted" marker for values merged from the AI read.
        // Stamped at merge time in window._autofillAiAssisted (Decision A3 provenance).
        const aiMarkerFor = (inputId) => {
            try {
                const stamped = (typeof window !== 'undefined' && window._autofillAiAssisted) ? window._autofillAiAssisted : null;
                if (!stamped) return '';
                const coreField = (typeof AUTOFILL_CORE_FIELDS !== 'undefined' && AUTOFILL_CORE_FIELDS[inputId]) ? AUTOFILL_CORE_FIELDS[inputId] : null;
                if (!coreField || !stamped[coreField]) return '';
                return ' <span class="badge bg-info text-dark" data-ai-marker="1" title="Filled by the AI-assisted read — please verify">AI-assisted</span>';
            } catch (e) { return ''; }
        };
        // One grid cell per field. Long text spans the row; short fields pair up.
        // Choice fields render as a REAL <select> — the reviewer asked for the same
        // dropdown the create form has. It is populated by syncAutofillChoiceFields()
        // below rather than here, because the live Category / Budget Source lists are
        // FETCHED asynchronously and are usually still empty at the moment this runs.
        tbody.innerHTML = fields.map(f => {
            const isDate = DATE_FIELDS.indexOf(f.id) !== -1;
            const value = isDate ? (core.parseToInputDate(f.value) || '') : (f.value || '');
            const span = f.w === 12 ? 'col-12' : 'col-12 col-md-6';
            const control = f.choices
                // data-extracted remembers what the document said, so a re-sync that
                // arrives after the options load can still select it (or keep it as its
                // own option when it matches nothing).
                ? `<select class="form-select form-select-sm" id="previewInput-${f.id}"
                        data-choices-from="${escapeHtml(f.choices)}" data-extracted="${value ? escapeHtml(value) : ''}">
                        ${value ? `<option value="${escapeHtml(value)}" selected>${escapeHtml(value)}</option>` : '<option value="">Loading options…</option>'}
                   </select>`
                : `<input type="${isDate ? 'date' : 'text'}" class="form-control form-control-sm" id="previewInput-${f.id}"
                        value="${value ? escapeHtml(value) : ''}"
                        placeholder="${f.value ? '' : 'Not found — fill manually'}" />`;
            return `
                <div class="${span}">
                    <div class="afp-cell">
                        <label class="form-label autofill-preview-label mb-1" for="previewInput-${f.id}">${escapeHtml(f.label)}</label>
                        ${control}
                        ${disagreeNoteFor(f.id)}${aiMarkerFor(f.id)}
                    </div>
                </div>
            `;
        }).join('');
        // Fill the dropdowns now, and again when the modal is actually shown (below) —
        // whichever happens after the option fetch lands is the one that sticks.
        try { syncAutofillChoiceFields(); } catch (e) { /* dropdowns are best-effort */ }
        // L1 learning loop: snapshot what extraction guessed per CORE field, so Apply can
        // diff guesses vs user-kept values. Date-normalized exactly like the inputs above.
        // AI-3 emptyBaseline: the AI-from-warning flow opens a preview with no rule guesses —
        // every box starts empty so AI-kept values harvest with AI source (Decision A3).
        try {
            const emptyBaseline = !!(arguments[1] && arguments[1].emptyBaseline);
            const snap = {};
            for (const f of fields) {
                const isDate = DATE_FIELDS.indexOf(f.id) !== -1;
                snap[AUTOFILL_CORE_FIELDS[f.id] || f.id] = emptyBaseline
                    ? ''
                    : (isDate ? (core.parseToInputDate(f.value) || '') : (f.value || ''));
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
        // Debug-only since 2026-09-14 (see ocrDebugAffordances) — a raw-text dump is
        // for diagnosing the parser, not for reviewing a project.
        if (hasTexts && ocrDebugAffordances()) {
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
            // R2 + P-ROT (2026-09-07): per-page rotate buttons for ALL scanned pages
            // (scannedPageNums ledger, ocrPages fallback) — unread pages were previously
            // unactionable. Failed pages carry a "no text yet" badge. Index-indirected
            // (quote-safe); label shows live degrees. Plus a bulk rotate-all for the file.
            // One entry point, same reasoning as the amber notice: the preview panel
            // owns page choice, turning and re-reading, so this row only opens it.
            let rotBtns = '';
            try {
                const pages = ocrRotatablePagesOf(r);
                if (pages.length) {
                    const unread = pages.filter((pn) => ocrPageIsUnread(r, pn)).length;
                    rotBtns = '<div class="d-flex gap-1 flex-wrap mt-1 align-items-center">'
                        + '<span style="font-size:0.85em;" class="text-muted">Pages read from a photo:</span>'
                        + '<button type="button" class="btn btn-sm btn-outline-primary" onclick="window._ocrPreviewShow(' + ri + ',' + pages[0] + ')" title="See these pages, straighten any that are sideways, then read them again">'
                        + '👁 See &amp; straighten'
                        + (unread ? ' <span class="badge bg-warning text-dark">' + unread + ' unread</span>' : '')
                        + '</button></div>';
                }
            } catch (e) { rotBtns = ''; /* buttons are best-effort */ }
            // Stash this report's identity for the rotate handler (quote-safe indirection).
            stashOcrRotateTargets(reports);
            // Snapshots (2026-09-07): per-page save-image buttons for ALL scanned pages
            // (scannedPageNums ledger — approved scope; rotated-but-yielding soup must stay
            // visible). Index-indirect like rotate; handler re-renders at ~1.5 scale PNG.
            let snapBtns = '';
            try {
                snapBtns = (ocrDebugAffordances() ? ocrListedPagesOf(r) : []).map((pn) => {
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
            ocrPagePreviewShellHtml() +
            '<div class="d-flex align-items-center gap-2 flex-wrap"><label class="mb-0" style="font-size:0.85em;" for="ocrPageSelect">Read depth (≈2s per page):</label>' +
            '<select id="ocrPageSelect" class="form-select form-select-sm" style="width:auto;">' + opts + '</select>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._rescanWithSelection()" title="Read every page again from scratch, ignoring what was already read">Re-scan</button></div>';
        truncEl.style.display = '';
        // Keep the panel open across a re-render if it already was (a re-read lands here).
        try { if (_ocrPreview) renderOcrPagePreview(); } catch (e) { /* best-effort */ }
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
    // AI-3 opt-in banner (Tier 3, very last): offered only when the local pipeline admits
    // weakness (aiAssist computed at run time). S1: remove any previous run's node first.
    // One AI call per file max (free-tier guard lives in the click handler + server cap).
    try {
        const oldAi = document.getElementById('autofillPreviewAi');
        if (oldAi && oldAi.remove) oldAi.remove();
    } catch (e) { /* best-effort */ }
    const aiAssist = arguments[1] && arguments[1].aiAssist ? arguments[1].aiAssist : false;
    if (aiAssist) {
        try {
            const pathNow = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
            if (pathNow.indexOf('/contractor/') === -1) {
                const aiEl = document.createElement('div');
                aiEl.id = 'autofillPreviewAi';
                aiEl.className = 'alert alert-warning d-flex align-items-center gap-2 flex-wrap mt-3';
                aiEl.innerHTML = '<i class="bi bi-magic"></i><span style="font-size:0.9em;">Parsing looks uncertain — the local reader could not fill much. <strong>Try AI assist?</strong> Sends at most 2 confusing pages (never the whole file).</span>' +
                    '<button type="button" class="btn btn-sm btn-warning" onclick="window._tryAiAssist()">Try AI assist</button>';
                const container = modalEl.querySelector('.modal-body') || modalEl;
                container.appendChild(aiEl);
            }
        } catch (e) { /* banner is best-effort — preview still shows */ }
    }
    // S0: log the banner decision with the run id (stale-render diagnosis).
    try {
        const rid = (arguments[1] && arguments[1].runId !== undefined) ? arguments[1].runId : '?';
        console.log('[Parser] preview render run ' + rid + ': mismatch banner ' + (mismatchEl ? 'SHOWN' : 'hidden'));
    } catch (e) { /* logging is best-effort */ }

    hideAutofillBanner();

    const instance = bootstrap.Modal.getOrCreateInstance(modalEl);
    // Re-fill the dropdowns once the dialog is actually on screen. The Category and
    // Budget Source lists arrive over the network, so the render-time pass often runs
    // before they exist; this is the second chance. Registered once (the flag guards
    // against stacking a handler per preview) and harmless when the lists were already
    // there. Also clears any spinner that survived to this point.
    try {
        if (!modalEl.dataset.afpSyncBound) {
            modalEl.addEventListener('shown.bs.modal', function () {
                try { syncAutofillChoiceFields(); } catch (e) { /* best-effort */ }
                try { removeAutofillScanStatus(); } catch (e) { /* best-effort */ }
            });
            // CLOSING the preview is the reviewer saying they are done with this read.
            // Reported 2026-09-18: the spinner was still there after the modal was
            // closed, so this is the reported moment itself, covered explicitly.
            modalEl.addEventListener('hidden.bs.modal', function () {
                _autofillScansInFlight = 0;
                try { removeAutofillScanStatus(); } catch (e) { /* best-effort */ }
            });
            modalEl.dataset.afpSyncBound = '1';
        }
    } catch (e) { /* binding is best-effort — the render-time pass still ran */ }
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

// Fill the preview's Category / Budget Source / Contractor dropdowns from the LIVE
// create-project controls, so they offer exactly the options the real form offers —
// including anything added through the (+) buttons, which this file knows nothing about.
//
// WHY IT IS A SEPARATE PASS rather than part of the render: Category and Budget Source
// are fetched over the network (see loadCategories in _create_project_modal.js), so at
// the moment the preview renders those selects usually hold nothing but their
// "-- Select --" placeholder. This runs at render AND again on shown.bs.modal; whichever
// fires after the fetch lands is the one that sticks, and running twice is harmless.
//
// THE OPTION VALUE IS THE NAME, NOT THE ID — deliberately. applyAutofillFromPreview()
// reads .value and autofillFormFields() matches these fields BY NAME, so yielding an id
// here would silently break the fill. An extracted value that matches no option is kept
// as its own option (marked) rather than dropped, which is the "do both" the reviewer
// asked for: a real dropdown that still cannot lose what the document said.
// Called by: showAutofillPreview() at render, and its shown.bs.modal handler.
function syncAutofillChoiceFields() {
    const selects = document.querySelectorAll('#autofillPreviewRows select[data-choices-from]');
    selects.forEach((target) => {
        const sourceId = target.getAttribute('data-choices-from');
        const src = sourceId ? document.getElementById(sourceId) : null;
        let names = [];
        try {
            if (src && src.options) {
                names = Array.from(src.options)
                    .map(o => String(o.textContent || '').trim())
                    // Drop the source's own placeholder rows ("-- Select Category --").
                    .filter(t => t && t.indexOf('--') !== 0);
            }
        } catch (e) { names = []; }
        // What the document said, or what the reviewer has already chosen this session.
        const extracted = (target.getAttribute('data-extracted') || '').trim();
        const chosen = (target.value || '').trim();
        const keep = chosen || extracted;
        const html = [];
        let matched = false;
        names.forEach((n) => {
            const isSel = keep && n.toLowerCase() === keep.toLowerCase();
            if (isSel) matched = true;
            html.push('<option value="' + escapeHtml(n) + '"' + (isSel ? ' selected' : '') + '>' + escapeHtml(n) + '</option>');
        });
        // Nothing in the list matches what we read — keep it, flagged, so Apply can still
        // use it and the reviewer can see what the document actually said.
        if (keep && !matched) {
            html.unshift('<option value="' + escapeHtml(keep) + '" selected>' + escapeHtml(keep) + ' — from document (not in list)</option>');
        }
        html.unshift('<option value="">' + (names.length ? '— none —' : 'No options available') + '</option>');
        target.innerHTML = html.join('');
        // A blank placeholder must not win when we have something to select.
        if (keep) { try { target.value = keep; } catch (e) { /* best-effort */ } }
        // Q3(a) 2026-09-18 — A VALUE THE DOCUMENT GAVE US MUST NOT DIE BECAUSE NOBODY
        // HAS ADDED IT YET. When the extracted name matches no option, offer to create
        // it right here for the two fields where that is safe: Category and Budget
        // Source are simple lookup tables with existing store endpoints and (+) buttons.
        // CONTRACTOR IS DELIBERATELY EXCLUDED — a contractor is not a label, it is a
        // record that logins, notifications, ratings and warranty submissions hang off,
        // and minting one from OCR text would put a hollow record in the very table the
        // pilot seeds with the PMU's real contractors. That case gets a loud note
        // instead (see markUnmatchedChoice below).
        renderChoiceCreateAction(target, sourceId, (keep && !matched) ? keep : '', names);
    });
}

// Put (or remove) the "add a new …" action under a choice field.
// Shown only when: the value came from the document, matches no option, the field is
// one of the two safe ones, and THIS USER may create it — the capability flags mirror
// the same policy that gates the (+) buttons, and the store routes are admin-only.
//
// B-2 (2026-09-20) — THREE CHANGES, because this control writes a PERMANENT ROW to a
// lookup table on the strength of text a machine read off a photograph, and B-1 only
// stopped the debris that LOOKS like debris. A value can be well-shaped and still
// wrong: OCR confuses C and G, so GAA arrives as CAA, passes every check, and becomes
// a near-duplicate row that silently splits every report that groups by source.
//   1. NOT OFFERED AT ALL FOR A SCANNED DOCUMENT. These two fields are always filled by
//      a LABEL match, so "labeled vs value-side guess" does not vary for them — what
//      does vary is whether the text came from a PDF text layer or from OCR, and that
//      is the axis the misreads actually live on. A scan still SHOWS its value and
//      still fills the form; it just cannot mint a row. (See the note it renders.)
//   2. THE REVIEWER TYPES THE NAME. The input starts EMPTY, with the document's text
//      shown beside it for reference. Prefilling it would be a one-click button again,
//      and typing is what turns a misread CAA into a correct GAA.
//   3. A NEAR-MATCH IS NAMED BEFORE ANYTHING IS CREATED. What they type is checked
//      against the rows that already exist, and anything within two edits is called
//      out with a one-click way to use the existing row instead. It WARNS, never
//      blocks — GAD really is one edit from GAA and really is a different fund.
// Called by: syncAutofillChoiceFields().
function renderChoiceCreateAction(target, sourceId, unmatchedValue, names) {
    const HOLDER = 'afp-choice-action';
    let holder = target.parentNode ? target.parentNode.querySelector('.' + HOLDER) : null;
    const cfg = (typeof window !== 'undefined' && window._projectModalConfig) ? window._projectModalConfig : {};
    const CREATABLE = {
        projectCategory: { label: 'category', allowed: !!cfg.canCreateCategory },
        projectBudgetSource: { label: 'budget source', allowed: !!cfg.canCreateBudgetSource },
    };
    const spec = CREATABLE[sourceId];
    const show = !!unmatchedValue && !!spec && spec.allowed;
    if (!show) {
        if (holder && holder.parentNode) holder.parentNode.removeChild(holder);
        return;
    }
    if (!holder) {
        holder = document.createElement('div');
        holder.className = HOLDER + ' mt-1';
        target.insertAdjacentElement('afterend', holder);
    }

    // B-2(1): a value read off a photograph does not get to create a row. Say so — a
    // control that vanishes without explanation reads as a bug.
    const fromScan = !!(typeof window !== 'undefined' && window._autofillOcrUsed);
    if (fromScan) {
        holder.innerHTML = '<div class="small text-muted">'
            + '<i class="bi bi-info-circle"></i> This was read from a SCAN, so it cannot be added as a new '
            + escapeHtml(spec.label) + ' from here. The value above still applies to this project — '
            + 'use the (+) button if it really is new.'
            + '</div>';
        return;
    }

    const inputId = HOLDER + '-in-' + sourceId;
    holder.innerHTML = '<div class="small text-muted mb-1">'
        + 'The document said "<strong>' + escapeHtml(unmatchedValue) + '</strong>", which is not in the list. '
        + 'Type the ' + escapeHtml(spec.label) + ' name to add it:'
        + '</div>'
        + '<div class="d-flex gap-2 align-items-start">'
        + '<input type="text" class="form-control form-control-sm afp-add-name" id="' + escapeHtml(inputId) + '" '
        + 'placeholder="Type the ' + escapeHtml(spec.label) + ' name" autocomplete="off">'
        + '<button type="button" class="btn btn-sm btn-outline-primary afp-add-choice" disabled>'
        + '<i class="bi bi-plus-circle"></i> Add</button>'
        + '</div>'
        + '<div class="afp-add-near small mt-1"></div>';

    const input = holder.querySelector('.afp-add-name');
    const btn = holder.querySelector('.afp-add-choice');
    const nearBox = holder.querySelector('.afp-add-near');
    if (!input || !btn || !nearBox) return;

    // B-2(3): re-checked on every keystroke, so the warning tracks what they will
    // actually create rather than what the document happened to say.
    const review = () => {
        const typed = String(input.value || '').trim();
        btn.disabled = typed.length < 2;
        nearBox.innerHTML = '';
        if (typed.length < 2) return;
        let near = null;
        try {
            const core = getCore();
            if (core && typeof core.nearestName === 'function') near = core.nearestName(typed, names || [], 2);
        } catch (e) { near = null; }
        if (!near) return;
        if (near.distance === 0) {
            // It already exists — creating it would duplicate a row outright.
            btn.disabled = true;
            nearBox.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> "'
                + escapeHtml(near.name) + '" already exists — <a href="#" class="afp-use-near">select it</a> instead.</span>';
        } else {
            nearBox.innerHTML = '<span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> This is very close to "'
                + escapeHtml(near.name) + '" — <a href="#" class="afp-use-near">use that</a> if you meant it.</span>';
        }
        const use = nearBox.querySelector('.afp-use-near');
        if (use) {
            use.addEventListener('click', function (ev) {
                ev.preventDefault();
                try { target.value = near.name; } catch (e) { /* best-effort */ }
                if (holder && holder.parentNode) holder.parentNode.removeChild(holder);
            });
        }
    };
    input.addEventListener('input', review);
    btn.addEventListener('click', function () {
        const typed = String(input.value || '').trim();
        if (typed.length < 2) return;
        createChoiceFromPreview(sourceId, typed, btn);
    });
    review();
}

// Create the missing category / budget source, then refresh the LIVE control and
// re-sync the preview so the new row is selected. Nothing else in the form changes.
// Called by: the "Add as new …" button rendered above.
async function createChoiceFromPreview(sourceId, value, btn) {
    const cfg = (typeof window !== 'undefined' && window._projectModalConfig) ? window._projectModalConfig : {};
    const ROUTES = {
        projectCategory: { url: cfg.categoriesStoreRoute, reload: 'loadProjectCategories', label: 'category' },
        projectBudgetSource: { url: cfg.budgetSourcesStoreRoute, reload: 'loadBudgetSources', label: 'budget source' },
    };
    const spec = ROUTES[sourceId];
    if (!spec || !spec.url || !value) return;
    const original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = 'Adding…'; }
    try {
        const token = cfg.csrfToken
            || (document.querySelector('meta[name="csrf-token"]') || {}).content
            || '';
        const res = await fetch(spec.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ name: value }),
        });
        if (!res.ok) {
            // A duplicate name is the common case and is NOT a failure worth shouting
            // about — the list reload below will surface the existing row either way.
            let msg = 'Could not add the ' + spec.label + ' (' + res.status + ').';
            try {
                const body = await res.json();
                if (body && body.message) msg = body.message;
                else if (body && body.errors) msg = Object.values(body.errors).flat().join(' ');
            } catch (e) { /* non-JSON error body */ }
            if (btn) { btn.disabled = false; btn.innerHTML = original; }
            alert(msg);
            return;
        }
        // Refresh the real control from the server, then re-sync the preview so the
        // freshly created row is both present and selected.
        try {
            const reload = (typeof window !== 'undefined') ? window[spec.reload] : null;
            if (typeof reload === 'function') await reload();
        } catch (e) { /* the option append below still covers the common case */ }
        const live = document.getElementById(sourceId);
        const known = live && live.options
            ? Array.from(live.options).some(o => String(o.textContent || '').trim().toLowerCase() === value.toLowerCase())
            : false;
        if (live && !known) {
            // The reload did not bring it (different role route, cache) — add it locally
            // so the reviewer is not stuck. The next real load will agree.
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            live.appendChild(opt);
        }
        try { syncAutofillChoiceFields(); } catch (e) { /* best-effort */ }
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
        alert('Could not add the ' + spec.label + ' — check your connection and try again.');
    }
}
function applyAutofillFromPreview() {
    // Belt and braces for the stuck spinner: whatever path got us here, the reviewer
    // is now acting on the result, so no scan is meaningfully "in progress" to them.
    try { _autofillScansInFlight = 0; removeAutofillScanStatus(); } catch (e) { /* best-effort */ }
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
// L1 LEARNING LOOP — HARVEST CORRECTIONS (Option 2 + multi-label desk)
// Diffs the preview snapshot (what extraction guessed) against the applied values
// (what the user kept). Every corrected box becomes up to 3 proposals: the ranked
// mined labels + excerpts (or one label-less count when the value cannot be anchored
// back to the documents). Values themselves are NEVER sent — labels only (Decision L0b).
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
        // Multi-label desk: prefer the ranked survey list (up to 3 distinct gated labels
        // per correction) so approvers can pick novel phrasings; legacy single-label miner
        // is the fallback when the list helper is unavailable.
        const mineAll = (typeof core.mineCorrectionLabels === 'function')
            ? (t, v) => (core.mineCorrectionLabels(t, v, 3) || [])
            : (t, v) => { const m = core.mineCorrectionLabel(t, v); return m ? [m] : []; };
        const path = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
        if (path.indexOf('/contractor/') !== -1) return;
        const base = path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
        const docText = texts.join('\n\n');
        const disagree = window._autofillDisagree || null;
        // AI-4 provenance (Decision A3: learn everything, but tag the origin): rows kept
        // from an AI-assisted read post with source 'AI-assisted' for the desk badge.
        const aiStamped = (typeof window !== 'undefined' && window._autofillAiAssisted) ? window._autofillAiAssisted : null;
        const proposals = [];
        for (const inputId of Object.keys(AUTOFILL_CORE_FIELDS)) {
            const field = AUTOFILL_CORE_FIELDS[inputId];
            const before = String((orig[field] ?? ''));
            const after = String(((applied || {})[field] ?? '')).trim();
            // Untouched, cleared, or too-short boxes teach nothing.
            if (!after || after === before || after.length < 3) continue;
            for (const mined of mineAll(docText, after)) {
                proposals.push({
                    field,
                    label: mined ? mined.label : null,
                    excerpt: mined ? mined.excerpt : null,
                    had_disagree: !!(disagree && disagree[field]),
                    source: (aiStamped && aiStamped[field]) ? 'AI-assisted' : 'correction',
                });
                // Server batch cap is 33 (11 fields × 3 labels) — never overflow it.
                if (proposals.length >= 33) break;
            }
            if (proposals.length >= 33) break;
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
        // Q3(b) 2026-09-18 — SAY WHAT WE READ, AND SAY IT LOUDLY.
        // A contractor is never auto-created from a document: it is a record that
        // logins, notifications, ratings and warranty submissions hang off, and one
        // minted from OCR text would be hollow — no company details, no user account —
        // in the very table the pilot seeds with the PMU's real contractors. Nor is the
        // name stored as loose text: projects.contractor_id is an FK, and a project
        // whose contractor exists only as a string would silently drop out of
        // contractor-filtered views, ratings and notifications while LOOKING filled.
        // So the honest outcome is: leave the field empty, and make sure the reviewer
        // sees exactly what the document said so a human can decide. Previously this
        // branch showed a bare "not parsed" indicator and the read was simply lost.
        showParseIndicator('contractorSelect', false);
        setContractorMissNote(extractedName, bestOption, bestScore);
    }
}

// Name the contractor the document gave us, next to the field it could not fill.
// Two cases worth telling apart: nothing resembled it at all, versus there IS a close
// option we were not confident enough to choose for them (a near miss is exactly when
// a human glance settles it in a second).
// Called by: tryMatchContractorDropdown() on every unmatched read.
function setContractorMissNote(extractedName, bestOption, bestScore) {
    try {
        const field = document.getElementById('contractorSelect');
        if (!field) return;
        const id = 'contractorMissNote';
        let note = document.getElementById(id);
        if (!extractedName) {
            if (note && note.parentNode) note.parentNode.removeChild(note);
            return;
        }
        if (!note) {
            note = document.createElement('small');
            note.id = id;
            const anchor = field.closest('.input-group') || field;
            anchor.insertAdjacentElement('afterend', note);
        }
        note.className = 'text-warning d-block mt-1';
        const near = (bestOption && bestScore > 0)
            ? ' Closest match: <strong>' + escapeHtml(String(bestOption.textContent || '').trim()) + '</strong>.'
            : '';
        note.innerHTML = '<i class="bi bi-exclamation-triangle"></i> The document says '
            + '<strong>' + escapeHtml(extractedName) + '</strong>, which is not in the contractor list.'
            + near
            + ' Pick the right one, or add the contractor first — it is not created automatically.';
    } catch (e) { /* the note is an aid, never a blocker */ }
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
// THE SCAN INDICATOR (rebuilt 2026-09-18, third report of it sticking).
//
// It used to be the SAME <div> as the finished-run "Auto-fill notice" warning, told
// apart by a data-state stamp. That sharing is what made clearing it conditional —
// "hide it, but only if it is not currently a warning, and only if no newer run is
// going" — and every one of those conditions leaked in a different way. Three fixes
// failed against it.
//
// So the mechanism is replaced rather than patched again. This is its OWN element,
// created when a scan starts and REMOVED FROM THE DOM when the scan stops. The rule
// is now statable in one line and needs no conditions: IF IT IS ON SCREEN, A SCAN IS
// RUNNING. There is no hidden-but-present state left to get stuck in, and the warning
// banner (#autofillBanner) is a different element that this code never touches.
//
// It also reads differently on purpose (the reviewer asked for a different sign): a
// slim strip beside the documents it is reading, naming the page it is on, so it
// visibly MOVES while work is happening instead of spinning in place.
const AUTOFILL_SCAN_STATUS_ID = 'autofillScanStatus';
// Shorten a file name for the scan indicator without losing what identifies it.
// Keeps the extension (so a reviewer still sees it is the PDF) and trims the stem,
// because contractor file names are long and front-loaded with boilerplate —
// "Rehabilitation-of-Canteen-Contract-FINAL-v3.pdf" must not push the page number
// off the strip. Middle of the stem goes, not the end, so both the distinctive
// start and the extension survive.
function shortScanFileName(name, max) {
    const s = String(name || '').trim();
    const limit = (typeof max === 'number' && max > 8) ? max : 26;
    if (!s || s.length <= limit) return s;
    const dot = s.lastIndexOf('.');
    const ext = (dot > 0 && (s.length - dot) <= 6) ? s.slice(dot) : '';
    const stem = ext ? s.slice(0, dot) : s;
    const keep = Math.max(6, limit - ext.length - 1);
    return stem.slice(0, keep).trimEnd() + '…' + ext;
}
// Called by: runDocumentAutofill() at the start, ocrPage() per scanned page.
// `message` is the activity ("Reading scanned page 7…"); the file being read is added
// here from _autofillCurrentFile so no caller has to carry it.
function showAutofillScanStatus(message) {
    // Same gate as before: no run in flight, nothing to show. Kept because ocrPage()
    // calls this per page and a late call must not be able to create the element.
    if (_autofillScansInFlight <= 0) return;
    let el = document.getElementById(AUTOFILL_SCAN_STATUS_ID);
    if (!el) {
        const anchor = document.getElementById('documentsListContainer')
            || document.querySelector('.form-page.active')
            || document.getElementById('page1');
        if (!anchor) return;
        el = document.createElement('div');
        el.id = AUTOFILL_SCAN_STATUS_ID;
        el.className = 'afp-scan-status';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        // ABOVE the "Added Documents:" block, not below it — the indicator reads as a
        // heading for the list it belongs to rather than as a footnote after it.
        if (anchor.id === 'documentsListContainer') anchor.insertAdjacentElement('beforebegin', el);
        else anchor.insertBefore(el, anchor.firstChild);
    }
    const file = shortScanFileName(_autofillCurrentFile);
    el.innerHTML = '<span class="afp-scan-bar" aria-hidden="true"><i></i></span>'
        + (file ? '<span class="afp-scan-file" title="' + escapeHtml(_autofillCurrentFile) + '">' + escapeHtml(file) + '</span>' : '')
        + '<span class="afp-scan-text">' + escapeHtml(message) + '</span>';
    _autofillSpinnerTick = Date.now();
    armAutofillSpinnerWatchdog();
}

// The only way this indicator goes away — and it is unconditional. Safe to call when
// nothing is showing.
function removeAutofillScanStatus() {
    _autofillCurrentFile = '';
    const el = document.getElementById(AUTOFILL_SCAN_STATUS_ID);
    if (el && el.parentNode) el.parentNode.removeChild(el);
    disarmAutofillSpinnerWatchdog();
}

// THE WATCHDOG stays as the last line of defence, now watching for the element rather
// than a display style. While the indicator exists it checks once a second and removes
// it when either no run is in flight, or nothing has refreshed it for STALE_MS — a live
// scan refreshes per page, so silence that long means the work is over however it ended.
// Self-disarming, so it costs nothing when idle.
const AUTOFILL_SPINNER_STALE_MS = 6000;
function armAutofillSpinnerWatchdog() {
    if (_autofillSpinnerTimer) return;
    try {
        _autofillSpinnerTimer = setInterval(function () {
            const el = document.getElementById(AUTOFILL_SCAN_STATUS_ID);
            if (!el) { disarmAutofillSpinnerWatchdog(); return; }
            const stale = (Date.now() - _autofillSpinnerTick) > AUTOFILL_SPINNER_STALE_MS;
            if (_autofillScansInFlight <= 0 || stale) removeAutofillScanStatus();
        }, 1000);
    } catch (e) { /* watchdog is best-effort — the explicit removals still run */ }
}
function disarmAutofillSpinnerWatchdog() {
    try { if (_autofillSpinnerTimer) clearInterval(_autofillSpinnerTimer); } catch (e) { /* best-effort */ }
    _autofillSpinnerTimer = null;
}

function showAutofillWarning(message, opts) {
    const banner = getOrCreateAutofillBanner();
    // The scan indicator is its own element now, so this warning cannot collide with it
    // and needs no state stamp to protect it.
    banner.className = 'alert alert-warning autofill-banner d-flex align-items-start gap-2 mb-3';
    // F-COPY on empty runs: failures must stay diagnosable — offer the read text for chat
    // when any file yielded text (even unparsable). Engine-failure runs pass copy:false.
    let copyBtn = '';
    try {
        const wantCopy = !opts || opts.copy !== false;
        const hasTexts = (window._autofillDocTexts || []).some(t => t && String(t).trim().length > 0);
        // DEBUG-ONLY since 2026-09-14. This dumps the raw read text for pasting into
        // a chat — a diagnostic, not something a reviewer creating a project needs,
        // and it crowded out the controls that do the actual job. Still one flag
        // away: window._parserDebug = true, then re-run.
        if (ocrDebugAffordances() && wantCopy && hasTexts && typeof window._copyExtractedText === 'function') {
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
        // Done unconditionally: the handlers are index-indirect whether or not the
        // debug buttons render, and the preview's rotate path relies on the same map.
        stashOcrRotateTargets(reports);
        const btns = [];
        (ocrDebugAffordances() ? reports : []).forEach((r, ri) => {
            if (!r) return;
            const pages = ocrListedPagesOf(r);
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
    // P-ROT on empty runs (2026-09-07): per-page ⟳ for ALL scanned pages + bulk rotate-all
    // per file — unread pages previously had NO manual path (ABC sheets are dark AND upright,
    // so rotation is diagnostic here, not rescue). Index-indirect; identities already stashed
    // above for the snapshot handler.
    // 2026-09-14: ONE button per file instead of a wall of them. This used to emit a
    // ⟳ per page plus a ⟳-all — up to 12 per file, before the snapshot row did the
    // same again — which on a ten-page scan buried the notice in twenty-odd controls
    // that all did something the reviewer could not see the result of. The preview
    // panel now owns page choice (its strip), turning (per page and per file) and
    // re-reading, so the notice only has to open it.
    let rotBtns = '';
    try {
        const reports = window._autofillScanReports || [];
        const btns = [];
        reports.forEach((r, ri) => {
            if (!r) return;
            const pages = ocrRotatablePagesOf(r);
            if (!pages.length) return;
            const unread = pages.filter((pn) => ocrPageIsUnread(r, pn)).length;
            btns.push('<button type="button" class="btn btn-sm btn-outline-primary mt-2 me-1" onclick="window._ocrPreviewShow(' + ri + ',' + pages[0] + ')" title="See the scanned pages of ' + escapeHtml(r.fileName) + ', straighten any that are sideways, then read them again">'
                + '👁 See &amp; straighten ' + escapeHtml(r.fileName)
                + (unread ? ' <span class="badge bg-warning text-dark">' + unread + ' unread</span>' : '')
                + '</button>');
        });
        if (btns.length) rotBtns = '<div>' + btns.join('') + '</div>';
    } catch (e) { rotBtns = ''; /* rotate affordance is best-effort */ }
    // AI-3 on empty runs (zero fills, no preview): offer the same opt-in assist — on success
    // a preview OPENS with the AI values on an empty baseline (see emptyBaseline snapshot).
    // Contractors have no endpoint; files must still be stashed for slicing.
    let aiBtn = '';
    try {
        const wantAi = !opts || opts.aiAssist !== false;
        const cPath = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
        const hasFiles = (window._autofillPdfFiles || []).some(f => f && f.file);
        if (wantAi && hasFiles && cPath.indexOf('/contractor/') === -1 && typeof window._tryAiAssistFromWarning === 'function') {
            aiBtn = '<button type="button" class="btn btn-sm btn-warning mt-2" onclick="window._tryAiAssistFromWarning()" title="Send at most 2 confusing pages to the AI assistant (never the whole file)">Try AI assist</button>';
        }
    } catch (e) { aiBtn = ''; /* assist affordance is best-effort */ }
    banner.innerHTML = `
        <i class="bi bi-exclamation-triangle flex-shrink-0 fs-5"></i>
        <div>
            <strong>Auto-fill notice:</strong>
            <span class="d-block" style="font-size: 0.875em;">${message}</span>
            ${copyBtn}
            ${straightenBtn}
            ${rotBtns}
            ${ocrPagePreviewShellHtml()}
            ${snapBtns}
            ${aiBtn}
        </div>
        <button type="button" class="btn-close ms-auto flex-shrink-0" aria-label="Dismiss" onclick="hideAutofillBanner()"></button>
    `;
    banner.style.display = 'flex';
    // A Re-read lands back here with the panel rebuilt empty, so redraw it if the
    // reviewer had one open — otherwise pressing Re-read appears to close the page
    // they were working on.
    try { if (_ocrPreview) renderOcrPagePreview(); } catch (e) { /* best-effort */ }
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
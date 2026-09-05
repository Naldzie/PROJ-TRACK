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

    try {
        // Phase 5 Step 1d: per-file extraction + voting (greedy title / cut contractor already handled in core).
        // This gives cross-file validation: 3 files agreeing raises confidence, 2 different titles flags mismatch.
        const perFileData = []; // [{ fileName, text, parsed }]
        let ocrUsed = false;

        for (const doc of pdfDocs) {
            if (myRunId !== _autofillRunId) return;

            try {
                const { text, ocr } = await extractTextFromPDF(doc.file, myRunId);
                if (ocr) ocrUsed = true;
                if (text && text.trim().length > 0) {
                    const parsedSingle = getCore().parseProjectInfo({ text });
                    perFileData.push({ fileName: doc.fileName, text, parsed: parsedSingle });
                }
            } catch (err) {
                // One file failing shouldn't stop the rest
                console.warn('[DocumentParser] Could not read PDF:', doc.fileName, err);
            }
        }

        if (myRunId !== _autofillRunId) return;

        if (perFileData.length === 0) {
            showAutofillWarning(
                'Could not extract text from the uploaded PDF(s). ' +
                'The files may be scanned images with no readable text.'
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
                        console.groupEnd();
                    }
                } catch (e) { /* inspector is best-effort only */ }
            }
        } catch (e) { /* pooled vote is best-effort — fall back to per-file vote */ }
        const mismatchInfo = getMismatchInfo(perFileData);

        if (myRunId !== _autofillRunId) return;

        if (getCore().countFilled(parsed) === 0) {
            showAutofillWarning(
                'Text was extracted from the documents, but no recognisable project ' +
                'fields were found. Please fill in the fields manually.'
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
        showAutofillPreview(parsed, { ocrUsed, mismatch: mismatchInfo, disagree: window._autofillDisagree });

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
let _ocrPagesDone = 0;
const OCR_PAGE_LIMIT = 10;
async function ocrPage(page, runId) {
    if (_ocrPagesDone >= OCR_PAGE_LIMIT) return '';
    if (runId !== _autofillRunId) return '';

    const tesseract = await loadTesseract();
    const scale = 2;
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    if (runId !== _autofillRunId) return '';
    showAutofillScanning('Scanning documents… (OCR reading scanned pages)');
    const { data } = await tesseract.recognize(canvas, 'eng');
    _ocrPagesDone++;
    return data.text || '';
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
    let fullText = '';
    let ocrUsed = false;

    for (let pageNum = 1; pageNum <= maxPages; pageNum++) {
        // Abort if the run was cancelled/superseded while we were awaiting
        if (runId !== _autofillRunId) break;

        const page = await pdf.getPage(pageNum);
        const textContent = await page.getTextContent();

        let pageText = core.pageItemsToText(textContent.items || []);

        // OCR fallback for textless (scanned) pages
        if ((pageText || '').trim().length < 10) {
            const ocrText = await ocrPage(page, runId);
            if (ocrText && ocrText.trim().length >= 10) {
                pageText = ocrText;
                ocrUsed = true;
            }
        }

        fullText += pageText + '\n\n';

        // Early exit once every field has been found (Phase 5: now 11 fields incl. abc/category/budgetSource)
        if (core.countFilled(core.parseProjectInfo({ text: fullText })) >= 11) break;
    }

    return { text: fullText, ocr: ocrUsed };
}

// ===================================================
// VOTING / CROSS-FILE VALIDATION (Step 1d)
// Per-file extract then vote >50% majority, red banner let user pick on mismatch.
// ===================================================

// Normalize a value for voting: lowercase, trim, strip hereinafter tail, collapse spaces.
function normalizeForVote(v) {
    if (!v) return '';
    let s = String(v).trim().toLowerCase();
    s = s.replace(/\s*\(hereinafter.*/i, '').trim();
    s = s.replace(/\s+/g, ' ');
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

// ===================================================
// PREVIEW MODAL
// Shows the parsed fields for review; nothing is written
// to the form until the user clicks Apply.
// ===================================================
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
    }

    const ocrNote = document.getElementById('autofillPreviewOcrNote');
    if (ocrNote) ocrNote.style.display = ocrUsed ? '' : 'none';

    // Mismatch (mis-upload 2 different projects) — red banner, let user pick, do NOT block Apply
    const mismatch = arguments[1] && arguments[1].mismatch ? arguments[1].mismatch : window._autofillMismatch;
    let mismatchEl = document.getElementById('autofillPreviewMismatch');
    if (mismatch && mismatch.hasMismatch && mismatch.details && mismatch.details.length > 0) {
        if (!mismatchEl) {
            mismatchEl = document.createElement('div');
            mismatchEl.id = 'autofillPreviewMismatch';
            mismatchEl.className = 'alert alert-danger d-flex flex-column gap-2 mt-3';
            const container = modalEl.querySelector('.modal-body') || modalEl;
            container.appendChild(mismatchEl);
        }
        const rows = mismatch.details.map(d => {
            const label = d.field === 'title' ? 'Project Title' : d.field === 'contractor' ? 'Contractor' : d.field;
            const variants = d.variants.map(v => escapeHtml(v.value) + ' — ' + v.files.map(f => escapeHtml(f)).join(', ') + ' (' + v.count + ' file' + (v.count>1?'s':'') + ')').join('<br>');
            return '<div><strong>' + escapeHtml(label) + ' mismatch — files disagree:</strong><br><span style="font-size:0.9em;">' + variants + '</span></div>';
        }).join('');
        mismatchEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> <strong>Possible mis-upload detected — files appear to be from different projects.</strong> Please verify which file to keep. The preview shows the majority vote (' + (mismatch.details[0].variants[0].count) + '/' + (mismatch.details[0].variants.reduce((s,v)=>s+v.count,0)) + ' files). You can still edit and Apply.' + '<div class="mt-2">' + rows + '</div>';
        mismatchEl.style.display = '';
    } else if (mismatchEl) {
        mismatchEl.style.display = 'none';
    }

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

    // Remember the user applied this run so the Next hook doesn't re-prompt.
    window._autofillApplied = true;

    closeAutofillPreview();
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

function showAutofillWarning(message) {
    const banner = getOrCreateAutofillBanner();
    banner.className = 'alert alert-warning autofill-banner d-flex align-items-start gap-2 mb-3';
    banner.innerHTML = `
        <i class="bi bi-exclamation-triangle flex-shrink-0 fs-5"></i>
        <div>
            <strong>Auto-fill notice:</strong>
            <span class="d-block" style="font-size: 0.875em;">${message}</span>
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
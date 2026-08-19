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
    const pdfDocs = (getPendingDocuments() || []).filter(doc =>
        doc.fileName.toLowerCase().endsWith('.pdf')
    );

    if (pdfDocs.length === 0) {
        hideAutofillBanner();
        return;
    }

    showAutofillScanning('Scanning documents…');

    try {
        // Extract text from ALL PDF files, merged into one big string.
        let combinedText = '';
        let ocrUsed = false;

        for (const doc of pdfDocs) {
            if (myRunId !== _autofillRunId) return;

            try {
                const { text, ocr } = await extractTextFromPDF(doc.file, myRunId);
                if (ocr) ocrUsed = true;
                if (text && text.trim().length > 0) {
                    combinedText += '\n\n--- Document: ' + doc.fileName + ' ---\n\n' + text;
                }
            } catch (err) {
                // One file failing shouldn't stop the rest
                console.warn('[DocumentParser] Could not read PDF:', doc.fileName, err);
            }
        }

        if (myRunId !== _autofillRunId) return;

        if (!combinedText.trim()) {
            showAutofillWarning(
                'Could not extract text from the uploaded PDF(s). ' +
                'The files may be scanned images with no readable text.'
            );
            return;
        }

        const parsed = getCore().parseProjectInfo({ text: combinedText });

        if (myRunId !== _autofillRunId) return;

        if (getCore().countFilled(parsed) === 0) {
            showAutofillWarning(
                'Text was extracted from the documents, but no recognisable project ' +
                'fields were found. Please fill in the fields manually.'
            );
            return;
        }

        // Show the preview modal — nothing is written to the form until Apply.
        showAutofillPreview(parsed, { ocrUsed });

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

        // Early exit once every field has been found
        if (core.countFilled(core.parseProjectInfo({ text: fullText })) >= 8) break;
    }

    return { text: fullText, ocr: ocrUsed };
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
function showAutofillPreview(parsed, { ocrUsed = false } = {}) {
    const modalEl = document.getElementById('autofillPreviewModal');
    if (!modalEl) {
        // No preview markup (should not happen) — fall back to direct apply
        applyAutofillFromPreview();
        return;
    }

    const fields = [
        { id: 'projectTitle', label: 'Project Title', value: parsed.title },
        { id: 'projectLocation', label: 'Location', value: parsed.location },
        { id: 'projectDescription', label: 'Description', value: parsed.description },
        { id: 'projectBudget', label: 'Approved Budget', value: parsed.budget },
        { id: 'plannedStartDate', label: 'Start Date', value: parsed.startDate },
        { id: 'plannedEndDate', label: 'End Date', value: parsed.endDate },
        { id: 'plannedDuration', label: 'Duration (days)', value: parsed.duration },
        { id: 'contractorSelect', label: 'Contractor', value: parsed.contractor },
    ];

    const tbody = document.getElementById('autofillPreviewRows');
    if (tbody) {
        tbody.innerHTML = fields.map(f => `
            <tr>
                <td class="autofill-preview-label">${escapeHtml(f.label)}</td>
                <td class="${f.value ? '' : 'text-muted fst-italic'}">${f.value ? escapeHtml(f.value) : 'Not found'}</td>
            </tr>
        `).join('');
    }

    const ocrNote = document.getElementById('autofillPreviewOcrNote');
    if (ocrNote) ocrNote.style.display = ocrUsed ? '' : 'none';

    hideAutofillBanner();
    window._autofillPreviewParsed = parsed;

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
function applyAutofillFromPreview() {
    const parsed = window._autofillPreviewParsed;
    if (!parsed) return;

    // Clear only previously autofilled values, then fill freshly
    clearAutofillFields();
    autofillFormFields(parsed);

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
    setIfEmpty('projectBudget', parsed.budget);

    // --- Page 4 fields ---
    setIfEmpty('plannedStartDate', parsed.startDate);
    setIfEmpty('plannedEndDate', parsed.endDate);
    setIfEmpty('plannedDuration', parsed.duration);

    // --- Contractor: fuzzy match the dropdown ---
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
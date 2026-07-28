// ===================================================
// DOCUMENT PARSER
// Extracts project information from uploaded PDFs
// and autofills the create-project form fields.
//
// DEPENDENCIES: PDF.js (loaded via CDN in blade)
// CALLED BY:    form-navigation.js (on page 1 → 2 transition)
// ===================================================

// ===================================================
// ENTRY POINT
// Called by nextPage() in form-navigation.js
// when the user moves from Page 1 (Documents) to Page 2.
// Returns a Promise that resolves when autofill is done
// (or skipped if no PDFs are present).
// ===================================================
// Incremented every time runDocumentAutofill() starts.
// Each async run captures its own ID; if the ID changes before
// the run finishes, the run knows it has been superseded and
// discards its results instead of updating the UI/fields.
let _autofillRunId = 0;

async function runDocumentAutofill() {
    // Only run in create mode — never overwrite data when editing
    if (window._projectModalMode === 'edit') return;

    // Claim this run's ID. Any previous in-flight run will see
    // that its ID no longer matches and will abort quietly.
    const myRunId = ++_autofillRunId;

    // Get only PDF files from the pending documents list
    const pdfDocs = (getPendingDocuments() || []).filter(doc =>
        doc.fileName.toLowerCase().endsWith('.pdf')
    );

    if (pdfDocs.length === 0) {
        // No PDFs — nothing to parse, proceed silently
        hideAutofillBanner();
        return;
    }

    // Show "scanning..." state in the UI
    showAutofillScanning();

    try {
        // Extract text from ALL PDF files, merge into one big string.
        // More documents = more chances to find each field.
        let combinedText = '';

        for (const doc of pdfDocs) {
            // Abort if a newer run has started while we were awaiting
            if (myRunId !== _autofillRunId) return;

            try {
                const text = await extractTextFromPDF(doc.file);
                if (text && text.trim().length > 0) {
                    combinedText += '\n\n--- Document: ' + doc.fileName + ' ---\n\n' + text;
                }
            } catch (err) {
                // One file failing shouldn't stop the rest
                console.warn('[DocumentParser] Could not read PDF:', doc.fileName, err);
            }
        }

        // Abort if superseded while we were reading files
        if (myRunId !== _autofillRunId) return;

        if (!combinedText.trim()) {
            // PDFs had no extractable text (likely scanned images)
            showAutofillWarning(
                'Could not extract text from the uploaded PDF(s). ' +
                'The files may be scanned images. Please fill in the fields manually.'
            );
            return;
        }

        // DEBUG: Uncomment the line below to see extracted raw text in browser console.
        // This is extremely useful for tuning regex patterns to your actual documents.
        console.log('[DocumentParser] Raw extracted text:\n', combinedText);

        // Parse extracted text into structured field data
        const parsed = parseProjectInfo(combinedText);

        // Abort if superseded while we were parsing
        if (myRunId !== _autofillRunId) return;

        // Count how many fields we actually found
        const filledCount = Object.values(parsed).filter(v => v !== null && v !== '').length;

        if (filledCount === 0) {
            showAutofillWarning(
                'Text was extracted from the documents, but no recognisable project ' +
                'fields were found. Please fill in the fields manually, or check the ' +
                'browser console for the raw extracted text.'
            );
            return;
        }

        // Clear any previously autofilled values before filling with new results.
        // This handles the case where the user went back, swapped the PDF, and
        // came forward again — without this the old values would stay in place
        // because setIfEmpty() skips non-empty fields.
        clearAutofillFields();

        // Fill the form with the freshly parsed data
        const actuallyFilled = autofillFormFields(parsed);

        if (actuallyFilled > 0) {
            showAutofillSuccess(actuallyFilled);
        } else {
            // Fields were already filled in (e.g. user typed something first)
            hideAutofillBanner();
        }

    } catch (err) {
        console.error('[DocumentParser] Autofill failed:', err);
        // Silent failure — the user can still fill the form manually
        hideAutofillBanner();
    }
}

// ===================================================
// STEP 1 — PDF TEXT EXTRACTION
// Uses PDF.js to read a File object and return all
// text content as a single string.
// ===================================================
async function extractTextFromPDF(file) {
    // PDF.js loads via a type="module" script which is async.
    // Wait up to 3 seconds for window.pdfjsLib to become available.
    if (typeof window.pdfjsLib === 'undefined') {
        await new Promise((resolve, reject) => {
            let waited = 0;
            const check = setInterval(() => {
                waited += 100;
                if (typeof window.pdfjsLib !== 'undefined') {
                    clearInterval(check);
                    resolve();
                } else if (waited >= 3000) {
                    clearInterval(check);
                    reject(new Error('PDF.js did not load within 3 seconds. Check the CDN script tag in app.blade.php.'));
                }
            }, 100);
        });
    }

    // Read the file as an ArrayBuffer
    const arrayBuffer = await file.arrayBuffer();

    // Load the PDF document
    const pdf = await window.pdfjsLib.getDocument({ data: arrayBuffer }).promise;

    let fullText = '';

    // Iterate through every page and extract text
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
        const page = await pdf.getPage(pageNum);
        const textContent = await page.getTextContent();

        // Join all text items on this page.
        // PDF.js gives us individual text "chunks" — we reconstruct lines
        // by adding a newline whenever there's a significant Y-position jump.
        let lastY = null;
        let pageText = '';

        for (const item of textContent.items) {
            if (lastY !== null && Math.abs(item.transform[5] - lastY) > 2) {
                pageText += '\n';
            }
            pageText += item.str;
            lastY = item.transform[5];
        }

        fullText += pageText + '\n\n';
    }

    return fullText;
}

// ===================================================
// STEP 2 — PARSE EXTRACTED TEXT INTO FIELDS
// Returns an object with keys matching form field IDs.
// Any field that could not be found is set to null.
//
// TUNING TIP: If a field is not being found, open the
// browser console, uncomment the console.log() in
// runDocumentAutofill(), and examine the raw text to
// see exactly how the label appears in your documents.
// Then adjust the regex pattern for that field below.
// ===================================================
function parseProjectInfo(text) {
    return {
        title:      extractTitle(text),
        location:   extractLocation(text),
        description: extractDescription(text),
        budget:     extractBudget(text),
        startDate:  extractStartDate(text),
        endDate:    extractEndDate(text),
        duration:   extractDuration(text),
        contractor: extractContractor(text),
    };
}

// -------------------------------------------------------
// HELPERS — each returns a string value or null
// -------------------------------------------------------

/**
 * Project Title
 * Looks for lines following common label patterns.
 * Also tries to detect an all-caps line as a title.
 */
function extractTitle(text) {
    // Pattern: "Project Title:", "Project Name:", "Name of Project:", etc.
    const labelPatterns = [
        /(?:project\s*title|name\s*of\s*project|project\s*name)\s*[:\-]\s*(.+)/i,
        /(?:title\s*of\s*project|project)\s*[:\-]\s*(.+)/i,
    ];

    for (const pattern of labelPatterns) {
        const match = text.match(pattern);
        if (match) {
            const value = cleanValue(match[1]);
            if (value && value.length >= 5 && value.length <= 200) {
                return value;
            }
        }
    }

    // Fallback: look for an ALL-CAPS line that looks like a project title
    // (e.g. "CONSTRUCTION OF CSPC ENGINEERING BUILDING")
    const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    for (const line of lines) {
        if (
            line === line.toUpperCase() &&
            line.length >= 15 &&
            line.length <= 150 &&
            /construction|renovation|repair|installation|upgrading|improvement|building/i.test(line)
        ) {
            return toTitleCase(line);
        }
    }

    return null;
}

/**
 * Location / Project Site
 */
function extractLocation(text) {
    const match = text.match(
        /(?:location|project\s*site|site\s*address|project\s*location|barangay|address)\s*[:\-]\s*(.+)/i
    );
    if (match) {
        const value = cleanValue(match[1]);
        if (value && value.length >= 3 && value.length <= 200) {
            return value;
        }
    }
    return null;
}

/**
 * Project Description / Scope of Work
 * Grabs up to 3 sentences after the label.
 */
function extractDescription(text) {
    const match = text.match(
        /(?:scope\s*of\s*work|project\s*description|description|objectives?|work\s*description)\s*[:\-]\s*([\s\S]{20,800}?)(?:\n{2,}|$)/i
    );
    if (match) {
        const value = cleanValue(match[1]);
        if (value && value.length >= 20) {
            // Limit to ~500 characters to avoid pulling in half the document
            return value.substring(0, 500);
        }
    }
    return null;
}

/**
 * Contract Budget / Approved Amount
 * Handles Philippine Peso amounts with or without currency symbols.
 * Handles formats: ₱1,234,567.00  PHP 1,234,567  1234567.00
 */
function extractBudget(text) {
    // Look for amount near common labels
    const labelPatterns = [
        /(?:contract\s*amount|approved\s*budget|project\s*cost|total\s*cost|budget|contract\s*price|abc|approved\s*budget\s*for\s*the\s*contract)\s*[:\-]?\s*(?:php|₱)?\s*([\d,]+(?:\.\d{1,2})?)/i,
        /(?:php|₱)\s*([\d,]+(?:\.\d{1,2})?)/i,
    ];

    for (const pattern of labelPatterns) {
        const match = text.match(pattern);
        if (match) {
            // Remove commas and parse
            const raw = match[1].replace(/,/g, '');
            const value = parseFloat(raw);
            if (!isNaN(value) && value > 0) {
                return value.toFixed(2);
            }
        }
    }
    return null;
}

/**
 * Contract / Project Start Date
 * Supports formats: January 1, 2026 / 01/01/2026 / 2026-01-01 / Jan. 1, 2026
 */
function extractStartDate(text) {
    const contextPatterns = [
        /(?:start\s*date|commencement\s*date|notice\s*to\s*proceed|ntp\s*date|date\s*started|contract\s*start)\s*[:\-]?\s*([A-Za-z]+ \d{1,2},? \d{4}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2})/i,
    ];
    for (const pattern of contextPatterns) {
        const match = text.match(pattern);
        if (match) {
            return parseToInputDate(match[1]);
        }
    }
    return null;
}

/**
 * Contract / Project End Date
 */
function extractEndDate(text) {
    const contextPatterns = [
        /(?:end\s*date|completion\s*date|target\s*completion|contract\s*end|date\s*of\s*completion|expected\s*completion)\s*[:\-]?\s*([A-Za-z]+ \d{1,2},? \d{4}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2})/i,
    ];
    for (const pattern of contextPatterns) {
        const match = text.match(pattern);
        if (match) {
            return parseToInputDate(match[1]);
        }
    }
    return null;
}

/**
 * Contract Duration in days
 */
function extractDuration(text) {
    const match = text.match(
        /(?:duration|contract\s*period|contract\s*duration|no\.?\s*of\s*(?:calendar\s*)?days|number\s*of\s*days|calendar\s*days?|working\s*days?)\s*[:\-]?\s*(\d+)/i
    );
    if (match) {
        const value = parseInt(match[1], 10);
        if (!isNaN(value) && value > 0 && value < 3650) {
            return String(value);
        }
    }
    return null;
}

/**
 * Contractor Name
 * Used for fuzzy-matching the contractor dropdown — not a direct fill.
 */
function extractContractor(text) {
    const match = text.match(
        /(?:contractor|awarded\s*to|proponent|company\s*name|firm\s*name)\s*[:\-]\s*(.+)/i
    );
    if (match) {
        const value = cleanValue(match[1]);
        if (value && value.length >= 3 && value.length <= 150) {
            return value;
        }
    }
    return null;
}

// ===================================================
// CLEAR PREVIOUSLY AUTOFILLED FIELDS
// Called before a fresh autofill run so that swapping
// PDFs always produces a clean fill, not a mix of old
// and new values.
// Only clears fields that were filled by autofill —
// fields the user typed manually are intentionally NOT
// tracked here to keep things simple; if the user typed
// something, going back and clicking Next again will
// leave their manual input alone (the cleared list only
// covers the fields autofill writes to).
// ===================================================
function clearAutofillFields() {
    const fieldIds = [
        'projectTitle',
        'projectLocation',
        'projectDescription',
        'projectBudget',
        'plannedStartDate',
        'plannedEndDate',
        'plannedDuration',
    ];
    fieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    // Also reset the contractor dropdown
    const contractorSelect = document.getElementById('contractorSelect');
    if (contractorSelect) contractorSelect.value = '';
    // Clear title validator feedback since the field is now empty
    if (typeof titleValidator !== 'undefined') titleValidator.clear();
    // Remove all per-field parse indicators
    clearParseIndicators();
}

// ===================================================
// PARSE INDICATOR HELPERS
// Show a small blue hint below each field after autofill
// runs — either "auto-filled" or "could not extract".
// ===================================================

/**
 * ID of the indicator <small> injected below each field.
 * Using a predictable ID lets us find and remove it later.
 */
function _parseIndicatorId(fieldId) {
    return 'parse-indicator-' + fieldId;
}

/**
 * Insert (or update) a blue indicator below the given field element.
 * @param {string} fieldId  - DOM id of the input/select/textarea
 * @param {boolean} success - true = filled, false = not found
 */
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

/**
 * Remove all parse indicators (called from clearAutofillFields).
 */
function clearParseIndicators() {
    const fieldIds = [
        'projectTitle', 'projectLocation',
        'projectDescription', 'projectBudget',
        'plannedStartDate', 'plannedEndDate', 'plannedDuration',
        'contractorSelect',
    ];
    fieldIds.forEach(id => {
        const el = document.getElementById(_parseIndicatorId(id));
        if (el) el.remove();
    });
}

// ===================================================
// STEP 3 — AUTOFILL FORM FIELDS
// Only fills fields that are currently empty.
// Returns the number of fields that were actually filled.
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
        // Indicator shown inside tryMatchContractorDropdown (see below)
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
        showParseIndicator('contractorSelect', true);
    } else {
        showParseIndicator('contractorSelect', false);
    }
}

// ===================================================
// UI BANNER — shown at the top of Page 2
// ===================================================

function showAutofillScanning() {
    const banner = getOrCreateAutofillBanner();
    banner.className = 'alert alert-info autofill-banner d-flex align-items-center gap-2 mb-3';
    banner.innerHTML = `
        <span class="spinner-border spinner-border-sm flex-shrink-0" role="status" aria-hidden="true"></span>
        <span><strong>Scanning documents…</strong> Extracting project information from your uploaded files.</span>
    `;
    banner.style.display = 'flex';
}

function showAutofillSuccess(count) {
    const banner = getOrCreateAutofillBanner();
    banner.className = 'alert alert-success autofill-banner d-flex align-items-center gap-2 mb-3';
    banner.innerHTML = `
        <i class="bi bi-magic flex-shrink-0 fs-5"></i>
        <div>
            <strong>${count} field${count !== 1 ? 's' : ''} pre-filled</strong> from your uploaded documents.
            <span class="text-muted d-block" style="font-size: 0.85em;">Please review and correct any mistakes before continuing.</span>
        </div>
        <button type="button" class="btn-close ms-auto flex-shrink-0" aria-label="Dismiss" onclick="hideAutofillBanner()"></button>
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
 * Find or create the autofill banner element inside Page 2.
 * It lives at the top of the page2 div, before any other content.
 */
function getOrCreateAutofillBanner() {
    let banner = document.getElementById('autofillBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'autofillBanner';
        banner.style.display = 'none';
        // Insert as the first child of page2
        const page2 = document.getElementById('page2');
        if (page2) {
            page2.insertBefore(banner, page2.firstChild);
        }
    }
    return banner;
}

// ===================================================
// UTILITY HELPERS
// ===================================================

/**
 * Clean up a raw regex match value:
 * - Trim whitespace
 * - Remove trailing punctuation
 * - Collapse internal whitespace
 */
function cleanValue(raw) {
    if (!raw) return '';
    return raw
        .trim()
        .replace(/\s+/g, ' ')
        .replace(/[:\-,;.]+$/, '')
        .trim();
}

/**
 * Convert various date formats to YYYY-MM-DD (HTML date input format)
 * Handles:
 *   "January 15, 2026"  →  "2026-01-15"
 *   "Jan. 15, 2026"     →  "2026-01-15"
 *   "15/01/2026"        →  "2026-01-15"
 *   "01-15-2026"        →  "2026-01-15"
 *   "2026-01-15"        →  "2026-01-15" (already correct)
 */
function parseToInputDate(raw) {
    if (!raw) return null;
    raw = raw.trim();

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

    // "DD/MM/YYYY" or "MM/DD/YYYY" — assume DD/MM/YYYY for Philippine docs
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

/**
 * Convert ALL-CAPS string to Title Case
 * "CONSTRUCTION OF BUILDING" → "Construction of Building"
 */
function toTitleCase(str) {
    const minor = new Set(['of', 'the', 'and', 'for', 'in', 'at', 'to', 'a', 'an', 'by', 'or']);
    return str.toLowerCase().split(' ').map((word, i) => {
        if (i === 0 || !minor.has(word)) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }
        return word;
    }).join(' ');
}
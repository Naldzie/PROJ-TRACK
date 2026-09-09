/**
 * scurve-import-ui.js
 *
 * "Import from S-curve" for the Set Up Baseline modal (Phase 2).
 *
 * Two entry points:
 *   1. Create-project wizard: an uploaded Schedule-type Excel/PDF in the
 *      pending-documents list becomes an import button. Excel imports the
 *      curve; PDF shows a friendly notice (PDF S-curves are chart-only).
 *   2. Project detail > Baseline tab: reads a stored Schedule-type document
 *      from the server and preloads the modal in save-immediately mode.
 *
 * The server returns cumulative planned % per month_index (1..12). The real
 * planned_date is derived here from the project's planned start/end dates,
 * so the parser stays free of date logic.
 *
 * Pure helpers live in scurve-import-core.js (ESM, unit-tested).
 */

/* ─── Parse endpoint calls ────────────────────────────────────────────────── */

function scurveCsrfToken() {
    return window.csrfToken || (
        document.querySelector('meta[name="csrf-token"]') || {}
    ).content || '';
}

async function parseSCurveFile(file, parseUrl) {
    var fd = new FormData();
    fd.append('file', file);
    var res = await fetch(parseUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': scurveCsrfToken(), 'Accept': 'application/json' },
        body: fd,
    });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.success) {
        var err = new Error(data.message || 'Could not parse the S-curve file.');
        err.status = res.status;
        err.isNoTable = /No S-curve table found/i.test(data.message || '');
        throw err;
    }
    return data;
}

async function parseSCurveDocument(documentId, parseUrl) {
    var fd = new FormData();
    fd.append('document_id', documentId);
    var res = await fetch(parseUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': scurveCsrfToken(), 'Accept': 'application/json' },
        body: fd,
    });
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.success) {
        var err2 = new Error(data.message || 'Could not parse the selected document.');
        err2.status = res.status;
        err2.isNoTable = /No S-curve table found/i.test(data.message || '');
        throw err2;
    }
    return data;
}

function scurveToast(message, type) {
    // Existing helpers disagree on arg order: admin's showToast(type, message),
    // baseline-schedule-utils' queueToastAfterReload(message, type). Normalize.
    var safeType = type === 'danger' ? 'error' : type;
    if (typeof showToast === 'function') {
        showToast(safeType, message);
        return;
    }
    if (typeof queueToastAfterReload === 'function') {
        queueToastAfterReload(message, safeType);
        return;
    }
    alert(message);
}

/* ─── Import warnings ──────────────────────────────────────────────────────── */
// Import notes render INSIDE the setup modal (stashed via window._scurveImportWarnings),
// never beside the button — and never as a toast (toasts read as errors, notes are FYI).
// This helper only clears any stale beside-button box from older runs.
function scurveShowWarnings(warnings, container) {
    try {
        var el = document.getElementById('scurveImportWarnings');
        if (el && el.remove) el.remove();
    } catch (e) { /* best-effort */ }
}

/* ─── E2 AI assist (Excel Tier 3, opt-in) ────────────────────────────────────── */
// Offered ONLY on no-table 422s for Excel sources (PDFs are declined server-side).
// Clicking POSTs the same source to parse-gemini once (busy-guarded), then rejoins the
// normal success path (normalize → modal → in-modal notes) with an AI-assisted
// provenance note appended. Results merge for review — never auto-applied.
// Called by: both import catch blocks (excel no-table failures only).

function scurveStashWarnings(data) {
    // Shared stash helper for rule + AI success paths (E2 provenance appended here).
    // Idempotent: consecutive duplicate notes collapse (double-fired imports/assists can
    // otherwise paint twin bullets — reported 2026-09-08).
    try {
        var list = (data && data.meta && data.meta.warnings) ? data.meta.warnings.slice() : [];
        if (data && data.meta && data.meta.aiAssisted) {
            list.push('AI-assisted curve — verify before locking the baseline.');
        }
        var deduped = [];
        list.forEach(function (w) {
            if (deduped[deduped.length - 1] !== w) deduped.push(w);
        });
        window._scurveImportWarnings = deduped;
    } catch (e) { window._scurveImportWarnings = []; }
}

function scurveGeminiUrl(parseUrl) {
    return String(parseUrl || '').replace(/\/s-curve\/parse$/, '/s-curve/parse-gemini');
}

function scurveOfferAiAssist(ctx) {
    try {
        var old = document.getElementById('scurveAiAssistWrap');
        if (old && old.remove) old.remove();
        window._scurveAiCtx = ctx;
        var host = ctx && ctx.btn ? ctx.btn.parentElement : document.body;
        var wrap = document.createElement('div');
        wrap.id = 'scurveAiAssistWrap';
        wrap.style.cssText = 'margin-top:6px;padding:6px 10px;border:1px solid #fcd34d;' +
            'background:#fef3c7;color:#92400e;font-size:12px;border-radius:6px;';
        wrap.innerHTML = 'The table was not recognized. <strong>Try AI assist?</strong>' +
            ' Sends a compact text excerpt (never the whole file). ';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-warning';
        btn.textContent = 'Try AI assist';
        // Loading state lives on the button itself (4-8s cold calls look dead otherwise):
        // disable + spinner immediately; _trySCurveAiAssist restores on failure, the node
        // is removed on success.
        btn.setAttribute('onclick', 'this.disabled=true;this.innerHTML=\'<span class="spinner-border spinner-border-sm"></span> Asking AI…\';window._trySCurveAiAssist()');
        wrap.appendChild(btn);
        (host || document.body).appendChild(wrap);
    } catch (e) { /* offer is best-effort */ }
}

window._trySCurveAiAssist = async function () {
    if (window._scurveAiBusy) return;
    var ctx = window._scurveAiCtx || null;
    // Restore the offer button's idle state (loading state was set inline at click).
    var restoreOfferBtn = function () {
        try {
            var wrap = document.getElementById('scurveAiAssistWrap');
            var b = wrap ? wrap.querySelector('button') : null;
            if (b) { b.disabled = false; b.textContent = 'Try AI assist'; }
        } catch (e) {}
    };
    if (!ctx) { restoreOfferBtn(); return; }
    window._scurveAiBusy = true;
    try {
        var fd = new FormData();
        var geminiUrl = scurveGeminiUrl(ctx.parseUrl);
        if (ctx.kind === 'file' && ctx.file) {
            fd.append('file', ctx.file);
        } else if (ctx.kind === 'document' && ctx.documentId) {
            fd.append('document_id', ctx.documentId);
        } else {
            throw new Error('Original file no longer available — re-upload to retry.');
        }
        var res = await fetch(geminiUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': scurveCsrfToken(), 'Accept': 'application/json' },
            body: fd,
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.success || !data.months || !data.months.length) {
            throw new Error((data && data.message) || 'The AI assistant could not read an S-curve from this file.');
        }
        var core = window.scurveImportCore;
        if (!core) throw new Error('Import helpers not loaded.');
        var months = core.normalizeSCurveMonths(data.months, ctx.startDate || '', ctx.endDate || '');
        if (!months.length) throw new Error('The AI response held no usable months.');
        try {
            var old = document.getElementById('scurveAiAssistWrap');
            if (old && old.remove) old.remove();
        } catch (e) {}
        scurveStashWarnings(data);
        if (ctx.flow === 'detail') {
            window.__setupModalContext = { mode: 'save-immediately', existingMonths: months };
            if (typeof openSetupBaselineModal === 'function') openSetupBaselineModal();
            scurveToast('Imported ' + months.length + ' baseline months (AI-assisted). Review, then Done to lock.', 'success');
        } else {
            window._baselineSetupMonths = months;
            if (typeof updateBaselineSetupButton === 'function') updateBaselineSetupButton();
            scurveToast('Imported ' + months.length + ' baseline months from the S-curve file (AI-assisted).', 'success');
            if (typeof openBaselineSetupModal === 'function') openBaselineSetupModal();
        }
    } catch (err) {
        scurveToast(err.message, 'danger');
        restoreOfferBtn();
    } finally {
        window._scurveAiBusy = false;
    }
};

/* ─── Create-project wizard wiring ────────────────────────────────────────── */

function refreshSCurveImportButton() {
    var btn = document.getElementById('scurveImportBtn');
    if (!btn) return;
    var pending = [];
    if (typeof getPendingDocuments === 'function') pending = getPendingDocuments();
    var core = window.scurveImportCore;
    if (!core) return;

    var candidates = pending.filter(core.isSCurveCandidate);

    if (candidates.length === 0) {
        btn.style.display = 'none';
        window._scurvePendingFile = null;
        return;
    }

    var excel = candidates.filter(core.isSCurveExcel);
    if (excel.length > 0) {
        window._scurvePendingFile = excel[0].file;
        btn.dataset.mode = 'excel';
        btn.textContent = 'Import baseline from S-curve file';
        btn.style.display = '';
    } else {
        window._scurvePendingFile = candidates[0].file;
        btn.dataset.mode = 'pdf';
        btn.textContent = 'Import baseline from S-curve file (PDF)';
        btn.style.display = '';
    }
}

/* ─── PDF import helpers ───────────────────────────────────────────────────── */

async function importPDFSCurve(file) {
    if (typeof window.parseSCurvePdfFile !== 'function') {
        throw new Error('The S-curve PDF parser is not loaded.');
    }

    var parsed = await window.parseSCurvePdfFile(file);
    if (!parsed || !parsed.months || parsed.months.length === 0) {
        throw new Error(
            'Could not read the S-curve from this PDF layout. ' +
            'Please upload the Excel (.xlsx) S-curve file instead.'
        );
    }

    return parsed;
}

async function scurveFetchDocument(url, name) {
    var res = await fetch(url);
    if (!res.ok) throw new Error('Could not download the document for parsing.');
    var blob = await res.blob();
    var ext = (name.split('.').pop() || '').toLowerCase();
    var type = ext === 'pdf' ? 'application/pdf' : blob.type;
    return new File([blob], name, { type: type });
}

function initCreateWizardSCurveImport() {
    var btn = document.getElementById('scurveImportBtn');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        var mode = btn.dataset.mode;
        var parseUrl = btn.dataset.parseUrl;
        var startEl = document.getElementById('plannedStartDate');
        var endEl = document.getElementById('plannedEndDate');
        var startDate = startEl && startEl.value ? startEl.value : '';
        var endDate = endEl && endEl.value ? endEl.value : '';
        var core = window.scurveImportCore;
        if (!core) return;

        var file = window._scurvePendingFile;
        if (!file) {
            scurveToast('No S-curve file found in the document list.', 'warning');
            return;
        }

        btn.disabled = true;
        var originalLabel = btn.textContent;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Importing...';

        try {
            var data;
            if (mode === 'pdf') {
                data = await importPDFSCurve(file);
            } else {
                data = await parseSCurveFile(file, parseUrl);
            }
            scurveShowWarnings(null, btn.parentElement);

            if (!data || !data.months || data.months.length === 0) {
                throw new Error('The file contains no baseline months.');
            }

            var months = core.normalizeSCurveMonths(data.months, startDate, endDate);

            if (months.length === 0) {
                throw new Error('The file contains no baseline months.');
            }

            if (window._baselineSetupMonths && window._baselineSetupMonths.length > 0) {
                var ok = window.confirm(
                    'This replaces the current in-memory baseline (' +
                    window._baselineSetupMonths.length + ' months). Continue?'
                );
                if (!ok) return;
            }

            window._baselineSetupMonths = months;
            if (typeof updateBaselineSetupButton === 'function') updateBaselineSetupButton();
            scurveToast(
                'Imported ' + months.length + ' baseline months from the S-curve file.',
                'success'
            );
            scurveStashWarnings(data);

            if (typeof openBaselineSetupModal === 'function') openBaselineSetupModal();
        } catch (err) {
            scurveToast(err.message, 'danger');
            // E2: no-table 422 on an Excel source offers the AI assist (PDFs excluded —
            // the endpoint declines them; the offer carries the original file for retry).
            if (err && err.isNoTable && mode === 'excel' && window._scurvePendingFile) {
                scurveOfferAiAssist({ kind: 'file', file: window._scurvePendingFile, parseUrl: parseUrl, startDate: startDate, endDate: endDate, flow: 'wizard', btn: btn });
            }
        } finally {
            btn.disabled = false;
            btn.textContent = originalLabel;
        }
    });
}

/* ─── Project detail wiring ───────────────────────────────────────────────── */

function initProjectDetailSCurveImport() {
    var btn = document.getElementById('scurveImportBtnDetail');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        var parseUrl = btn.dataset.parseUrl;
        var documentId = btn.dataset.documentId;
        var startDate = btn.dataset.startDate || '';
        var endDate = btn.dataset.endDate || '';
        var core = window.scurveImportCore;
        if (!core) return;

        btn.disabled = true;
        var originalLabel = btn.textContent;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Importing...';

        try {
            var data;
            if (btn.dataset.docType === 'pdf') {
                var file = await scurveFetchDocument(btn.dataset.downloadUrl, btn.dataset.documentName || 's-curve.pdf');
                data = await importPDFSCurve(file);
            } else {
                data = await parseSCurveDocument(documentId, parseUrl);
            }
            scurveShowWarnings(null, btn.parentElement);

            if (!data || !data.months || data.months.length === 0) {
                throw new Error('The document contains no baseline months.');
            }

            var months = core.normalizeSCurveMonths(data.months, startDate, endDate);

            if (months.length === 0) {
                throw new Error('The document contains no baseline months.');
            }

            window.__setupModalContext = {
                mode: 'save-immediately',
                existingMonths: months,
            };
            // Import notes travel into the setup modal (rendered there, cleared on close) —
            // never beside the button, never as a toast. Stash BEFORE opening: the modal
            // reads the stash at render time (shared helper appends AI provenance).
            scurveStashWarnings(data);
            if (typeof openSetupBaselineModal === 'function') openSetupBaselineModal();
            scurveToast(
                'Imported ' + months.length + ' baseline months. Review, then Done to lock.',
                'success'
            );
        } catch (err) {
            scurveToast(err.message, 'danger');
            // E2: same assist offer on the detail flow (stored Excel documents only).
            if (err && err.isNoTable && btn.dataset.docType !== 'pdf') {
                scurveOfferAiAssist({ kind: 'document', documentId: documentId, parseUrl: parseUrl, startDate: startDate, endDate: endDate, flow: 'detail', btn: btn });
            }
        } finally {
            btn.disabled = false;
            btn.textContent = originalLabel;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.__scurveImportUiLoaded) return;
    window.__scurveImportUiLoaded = true;
    initCreateWizardSCurveImport();
    initProjectDetailSCurveImport();
});
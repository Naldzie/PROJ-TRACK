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
        throw new Error(data.message || 'Could not parse the S-curve file.');
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
        throw new Error(data.message || 'Could not parse the selected document.');
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

function scurveWarningsEl(container) {
    var el = document.getElementById('scurveImportWarnings');
    if (!el) {
        el = document.createElement('div');
        el.id = 'scurveImportWarnings';
        el.style.cssText = 'margin-top:6px;padding:6px 10px;border:1px solid #fcd34d;' +
            'background:#fef3c7;color:#92400e;font-size:12px;border-radius:6px;' +
            'display:none;white-space:pre-line;';
        (container || document.body).appendChild(el);
    }
    return el;
}

function scurveShowWarnings(warnings, container) {
    var el = scurveWarningsEl(container);
    if (!warnings || warnings.length === 0) {
        el.style.display = 'none';
        return;
    }
    el.textContent = 'Import notes:\n' + warnings.join('\n');
    el.style.display = 'block';
    scurveToast('Import succeeded with notes: ' + warnings[0], 'warning');
}

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
        window._scurvePendingFile = null;
        btn.dataset.mode = 'pdf';
        btn.textContent = 'Import baseline from S-curve file (PDF)';
        btn.style.display = '';
    }
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

        if (mode === 'pdf') {
            scurveToast(
                'PDF S-curves are chart-only. Please upload the Excel (.xlsx) S-curve file to import the baseline.',
                'warning'
            );
            return;
        }

        var file = window._scurvePendingFile;
        if (!file) {
            scurveToast('No S-curve Excel file found in the document list.', 'warning');
            return;
        }

        btn.disabled = true;
        var originalLabel = btn.textContent;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Importing...';

        try {
            var data = await parseSCurveFile(file, parseUrl);
            scurveShowWarnings(null, btn.parentElement);
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
            scurveShowWarnings(data.meta ? data.meta.warnings : null, btn.parentElement);

            if (typeof openBaselineSetupModal === 'function') openBaselineSetupModal();
        } catch (err) {
            scurveToast(err.message, 'danger');
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
            var data = await parseSCurveDocument(documentId, parseUrl);
            scurveShowWarnings(null, btn.parentElement);
            var months = core.normalizeSCurveMonths(data.months, startDate, endDate);

            if (months.length === 0) {
                throw new Error('The document contains no baseline months.');
            }

            window.__setupModalContext = {
                mode: 'save-immediately',
                existingMonths: months,
            };
            if (typeof openSetupBaselineModal === 'function') openSetupBaselineModal();
            scurveToast(
                'Imported ' + months.length + ' baseline months. Review, then Done to lock.',
                'success'
            );
            scurveShowWarnings(data.meta ? data.meta.warnings : null, btn.parentElement);
        } catch (err) {
            scurveToast(err.message, 'danger');
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
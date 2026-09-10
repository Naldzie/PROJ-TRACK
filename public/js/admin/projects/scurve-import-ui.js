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
    // E1-3: wizard contractor choice scopes learned-map consult (project still
    // unsaved, so the client is the only contractor source — same read as capture).
    try {
        var csel = document.getElementById('contractorSelect');
        if (csel && csel.value) fd.append('contractor_id', csel.value);
    } catch (e) { /* scope is best-effort */ }
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
    // Self-contained renderer (2026-09-10): the shared showToast() helpers
    // disagree on argument order across pages (type-first legacy vs
    // message-first component), which once rendered every import toast as a
    // blue bubble reading just "error"/"success". Rendering our own DOM kills
    // that class instead of picking a side. Same look/position as the global
    // toasts (top-right stack); textContent-only, never HTML.
    // Callers keep calling scurveToast(message, type) unchanged.
    try {
        var safeType = type === 'danger' ? 'error' : (type || 'info');
        var palette = {
            success: { bg: '#f0fdf4', border: '#86efac', text: '#166534', icon: 'bi-check-circle-fill', iconColor: '#16a34a' },
            error: { bg: '#fef2f2', border: '#fca5a5', text: '#991b1b', icon: 'bi-exclamation-circle-fill', iconColor: '#dc2626' },
            warning: { bg: '#fffbeb', border: '#fde047', text: '#92400e', icon: 'bi-exclamation-triangle-fill', iconColor: '#f59e0b' },
            info: { bg: '#f0f9ff', border: '#bae6fd', text: '#0c4a6e', icon: 'bi-info-circle-fill', iconColor: '#0369a1' }
        };
        var c = palette[safeType] || palette.info;
        var container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainerFallback';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;';
            document.body.appendChild(container);
        }
        var toast = document.createElement('div');
        toast.setAttribute('role', 'alert');
        toast.style.cssText = 'background:' + c.bg + ';border:1px solid ' + c.border
            + ';border-radius:8px;padding:14px 16px;box-shadow:0 4px 16px rgba(0,0,0,0.12);'
            + 'display:flex;align-items:flex-start;gap:12px;min-width:260px;max-width:380px;';
        var icon = document.createElement('i');
        icon.className = 'bi ' + c.icon;
        icon.style.cssText = 'font-size:18px;color:' + c.iconColor + ';flex-shrink:0;margin-top:1px;';
        var span = document.createElement('span');
        span.style.cssText = 'flex:1;font-size:14px;font-weight:500;color:' + c.text + ';line-height:1.4;';
        span.textContent = String(message == null ? '' : message);
        var close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.style.cssText = 'background:none;border:none;cursor:pointer;padding:0;color:' + c.text + ';opacity:0.6;font-size:16px;line-height:1;flex-shrink:0;margin-top:1px;';
        close.textContent = '×';
        close.onclick = function () { try { toast.remove(); } catch (e) {} };
        toast.appendChild(icon);
        toast.appendChild(span);
        toast.appendChild(close);
        container.appendChild(toast);
        setTimeout(function () { try { toast.remove(); } catch (e) {} }, 5000);
    } catch (e) {
        try { alert(message); } catch (e2) {}
    }
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
    // Idempotent: duplicate notes collapse ANYWHERE in the list (the service stamps
    // the generic AI note and this helper appends it again — non-consecutive twins
    // reported 2026-09-10 — so dedupe is positional, not just consecutive).
    try {
        var list = (data && data.meta && data.meta.warnings) ? data.meta.warnings.slice() : [];
        if (data && data.meta && data.meta.aiAssisted) {
            list.push('AI-assisted curve — verify before locking the baseline.');
        }
        var seen = {};
        var deduped = [];
        list.forEach(function (w) {
            if (!seen[w]) { seen[w] = true; deduped.push(w); }
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

/* ─── On-demand AI cross-check (second opinion, in-modal) ──────────────────── */
 // The assist offer above exists only on failure — once a layout is learned the
 // import succeeds and AI can never be asked again (reported 2026-09-09: the
 // approve-then-AI test flow is impossible). This quiet secondary link fills that
 // gap. It lives INSIDE the setup modal under the import notes (the beside-button
 // spot is buried behind the modal backdrop) and is rendered by
 // scurveRenderCrossCheckLink(), called at modal open (guarded hook) and after
 // each successful import. Same quota discipline (one call per click,
 // busy-guarded), but READ-ONLY — it compares the AI answer against the live
 // month list and reports, never replacing months (replacement stays in the
 // fallback endpoint path). Agreement is quiet by user choice (a).
 // Called by: import success paths (stash) + modal open hook (render) + modal
 // hide/import-start (clear).
function scurveClearCrossCheck() {
    try {
        window._scurveCrossCheckCtx = null;
        var old = document.getElementById('scurveCrossCheckLink');
        if (old && old.remove) old.remove();
    } catch (e) { /* best-effort */ }
}

// Stash the cross-check source at import success (modal may not exist yet).
function scurveOfferCrossCheck(ctx) {
    try {
        scurveClearCrossCheck();
        if (!ctx || !ctx.baselineMonths || !ctx.baselineMonths.length) return;
        if (!ctx.parseUrl) return;
        if (!(ctx.kind === 'file' && ctx.file)
            && !(ctx.kind === 'document' && ctx.documentId)) return;
        window._scurveCrossCheckCtx = ctx;
        scurveRenderCrossCheckLink();
    } catch (e) { /* offer is best-effort */ }
}

// Render (or re-render) the in-modal link. Reads the LIVE month list so edits
// made before clicking are honored; falls back to the import-time snapshot.
window.scurveRenderCrossCheckLink = function () {
    try {
        var old = document.getElementById('scurveCrossCheckLink');
        if (old && old.remove) old.remove();
        var ctx = window._scurveCrossCheckCtx || null;
        if (!ctx) return;
        var anchor = document.getElementById('setupImportNotes')
            || document.getElementById('setupWarningNotice');
        var host = (anchor && anchor.parentNode) ? anchor.parentNode : null;
        if (!host || !document.getElementById('setupBaselineModal').contains(host)) return;
        var wrap = document.createElement('div');
        wrap.id = 'scurveCrossCheckLink';
        wrap.style.cssText = 'font-size:11px;color:#6b7280;margin:-8px 0 14px 0;';
        wrap.appendChild(document.createTextNode('Second opinion — '));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-secondary';
        btn.style.cssText = 'font-size:11px;padding:1px 10px;border-radius:20px;';
        btn.textContent = 'Cross-check with AI?';
        btn.title = 'Ask the AI assistant to re-read this file and compare (one AI call).';
        btn.setAttribute('onclick', 'window._trySCurveCrossCheck(this)');
        wrap.appendChild(btn);
        // Persistent result box (replaces toast-only replies — toasts are missed).
        var result = document.createElement('div');
        result.id = 'scurveCrossCheckResult';
        result.style.cssText = 'display:none;margin-top:6px;font-size:11px;line-height:1.5;';
        wrap.appendChild(result);
        host.insertBefore(wrap, anchor.nextSibling);
        // Keep the fresh link adjacent to the notes when both render.
        try {
            var notes = document.getElementById('setupImportNotes');
            if (notes && notes.nextSibling !== wrap) host.insertBefore(wrap, notes.nextSibling);
        } catch (e) {}
    } catch (e) { /* offer is best-effort */ }
};

window._trySCurveCrossCheck = async function (clickedBtn) {
    if (window._scurveXBusy) return;
    var ctx = window._scurveCrossCheckCtx || null;
    if (!ctx) return;
    window._scurveXBusy = true;
    // Loading state on the button itself (4-8s cold calls look dead otherwise),
    // mirroring the assist button; plus a persistent result box (never toast-only).
    var wrap = document.getElementById('scurveCrossCheckLink');
    var btn = (clickedBtn && clickedBtn.tagName === 'BUTTON') ? clickedBtn
        : (wrap ? wrap.querySelector('button') : null);
    var resultBox = wrap ? wrap.querySelector('#scurveCrossCheckResult') : null;
    function paintResult(text, kind) {
        try {
            if (!resultBox) return;
            resultBox.style.display = 'block';
            if (kind === 'ok') {
                resultBox.style.cssText = 'display:block;margin-top:6px;font-size:11px;line-height:1.5;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:6px 10px;';
            } else if (kind === 'warn') {
                resultBox.style.cssText = 'display:block;margin-top:6px;font-size:11px;line-height:1.5;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:6px 10px;';
            } else {
                resultBox.style.cssText = 'display:block;margin-top:6px;font-size:11px;line-height:1.5;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:6px 10px;';
            }
            resultBox.textContent = text;
        } catch (e) {}
    }
    function restoreBtn() {
        try {
            if (btn) { btn.disabled = false; btn.innerHTML = 'Cross-check with AI?'; }
        } catch (e) {}
    }
    try {
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking…'; }
        if (resultBox) resultBox.style.display = 'none';
        var fd = new FormData();
        var geminiUrl = scurveGeminiUrl(ctx.parseUrl);
        if (ctx.kind === 'file' && ctx.file) {
            fd.append('file', ctx.file);
        } else if (ctx.kind === 'document' && ctx.documentId) {
            fd.append('document_id', ctx.documentId);
        } else {
            throw new Error('Original file no longer available — re-import to retry.');
        }
        var res = await fetch(geminiUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': scurveCsrfToken(), 'Accept': 'application/json' },
            body: fd,
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.success || !data.months || !data.months.length) {
            throw new Error((data && data.message) || 'The AI assistant could not re-read this file.');
        }
        var core = window.scurveImportCore;
        if (!core) throw new Error('Import helpers not loaded.');
        var aiMonths = core.normalizeSCurveMonths(data.months, ctx.startDate || '', ctx.endDate || '');
        // Compare against the LIVE month list (pre-lock edits honored), falling
        // back to the import-time snapshot. setupLocalMonths carries no index,
        // so position maps 1..N (imports always emit sequential months).
        var liveMonths = null;
        try {
            if (typeof setupLocalMonths !== 'undefined' && setupLocalMonths && setupLocalMonths.length) {
                liveMonths = setupLocalMonths.map(function (m, i) {
                    return { month_index: i + 1, planned_progress_percent: m.planned_progress_percent };
                });
            }
        } catch (e) { liveMonths = null; }
        var baseByMonth = {};
        ((liveMonths && liveMonths.length ? liveMonths : ctx.baselineMonths) || []).forEach(function (m) {
            if (m && m.month_index != null) baseByMonth[m.month_index] = m.planned_progress_percent;
        });
        var divergent = [];
        aiMonths.forEach(function (m) {
            if (m && baseByMonth[m.month_index] != null
                && Math.abs(baseByMonth[m.month_index] - m.planned_progress_percent) > 1.0) {
                divergent.push(m.month_index);
            }
        });
        try {
            console.info('[scurve] cross-check', {
                baseline: ctx.baselineMonths,
                ai: aiMonths,
                divergent: divergent,
            });
        } catch (e) {}
        if (divergent.length === 0) {
            paintResult('AI cross-check agrees with the imported months.', 'ok');
        } else {
            paintResult(
                'AI cross-check differs in Month ' + divergent.slice(0, 3).join(', Month ')
                + (divergent.length > 3 ? ' (and ' + (divergent.length - 3) + ' more)' : '')
                + ' — review before locking.',
                'warn'
            );
        }
        restoreBtn();
    } catch (err) {
        paintResult(err.message, 'err');
        restoreBtn();
    } finally {
        window._scurveXBusy = false;
    }
};

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

/* ─── E1-1 silent header capture (unknown layouts learn future maps) ────────── */
// Fire-and-forget POST of the failed source to the capture endpoint (E1-1). All failures
// swallowed — the error UX is untouched. Wizard sends the live File + Page-4 contractor
// choice (project unsaved, so the client is the only contractor source); detail sends
// document_id (server resolves contractor via Document→project). Contractors never reach
// here (no upload surface, no route).
// Called by: both import catch blocks on no-table 422s (Excel sources only).
function scurveCaptureHeaders(kind, source, extra) {
    try {
        var path = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
        if (path.indexOf('/contractor/') !== -1) return;
        var base = path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
        var fd = new FormData();
        if (kind === 'file' && source) {
            fd.append('file', source);
            try {
                var csel = document.getElementById('contractorSelect');
                if (csel && csel.value) fd.append('contractor_id', csel.value);
            } catch (e) {}
        } else if (kind === 'document' && source) {
            fd.append('document_id', source);
        } else {
            return;
        }
        var token = (window._projectModalConfig && window._projectModalConfig.csrfToken) ||
            (((typeof document !== 'undefined') && document.querySelector('meta[name="csrf-token"]')) || {}).content || '';
        fetch(base + '/scurve-header-proposals/collect', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: fd,
            keepalive: true,
        }).catch(function () {});
    } catch (e) { /* capture is best-effort */ }
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
        scurveClearCrossCheck();

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
            scurveOfferCrossCheck({ kind: 'file', file: window._scurvePendingFile, parseUrl: parseUrl, startDate: startDate, endDate: endDate, baselineMonths: months, btn: btn });
        } catch (err) {
            scurveToast(err.message, 'danger');
            // E2: no-table 422 on an Excel source offers the AI assist (PDFs excluded —
            // the endpoint declines them; the offer carries the original file for retry).
            // E1-1: same failure silently stashes the header block for future maps.
            if (err && err.isNoTable && mode === 'excel' && window._scurvePendingFile) {
                scurveOfferAiAssist({ kind: 'file', file: window._scurvePendingFile, parseUrl: parseUrl, startDate: startDate, endDate: endDate, flow: 'wizard', btn: btn });
                scurveCaptureHeaders('file', window._scurvePendingFile);
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
        scurveClearCrossCheck();

        try {
            var data;
            if (btn.dataset.docType === 'pdf') {
                var file = await scurveFetchDocument(btn.dataset.downloadUrl, btn.dataset.documentName || 's-curve.pdf');
                try { window._scurveDetailFile = file; } catch (e) {}
                data = await importPDFSCurve(file);
            } else {
                try { window._scurveDetailFile = null; } catch (e) {}
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
            // Cross-check source mirrors the import source (fetched PDF file or stored document).
            var xCtx = (btn.dataset.docType === 'pdf' && window._scurveDetailFile)
                ? { kind: 'file', file: window._scurveDetailFile }
                : { kind: 'document', documentId: documentId };
            xCtx.parseUrl = parseUrl;
            xCtx.startDate = startDate;
            xCtx.endDate = endDate;
            xCtx.baselineMonths = months;
            xCtx.btn = btn;
            scurveOfferCrossCheck(xCtx);
        } catch (err) {
            scurveToast(err.message, 'danger');
            // E2: same assist offer on the detail flow (stored Excel documents only).
            // E1-1: same failure silently stashes the header block (server resolves
            // the contractor via Document→project).
            if (err && err.isNoTable && btn.dataset.docType !== 'pdf') {
                scurveOfferAiAssist({ kind: 'document', documentId: documentId, parseUrl: parseUrl, startDate: startDate, endDate: endDate, flow: 'detail', btn: btn });
                scurveCaptureHeaders('document', documentId);
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
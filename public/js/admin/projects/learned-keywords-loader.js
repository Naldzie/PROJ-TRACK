// ===================================================
// LEARNED DATA LOADER (shared)
//
// Single browser-side home for reading the learning desks' LIVE rows.
// Loaded from the ROLE LAYOUTS (app.blade.php / stafflayouts.blade.php), so
// every flow that consults learned data actually has it:
//   - create wizard      → normal-doc autofill (L3 vocabulary)
//   - detail Baseline tab → S-curve PDF import (F-D month words)
//   - the review desks    → cache busting after approve/reject
//
// WHY THIS FILE EXISTS: loadLearnedKeywords() used to be a plain function
// declaration inside document-parser.js, which ONLY the create-project modal
// loads. The detail Baseline tab loads the S-curve scripts without it, so the
// PDF wrapper's `typeof window.loadLearnedKeywords === 'function'` guard
// silently yielded nothing there and detail re-imports could never consult
// approved words (proof record: textfiles/pdf_ai_loop_proof_tests.md, bug (a)).
//
// EXPOSES:
//   window.loadLearnedKeywords()      → Promise<{ labels: { field: [phrase] } }>
//   window.bustLearnedKeywordsCache() → drops the cached set (approve/reject)
//
// CALLED BY: document-parser.js (runDocumentAutofill), scurve-pdf-parser.js
//            (parseSCurvePdfFile), _manage_parser_keywords_modal.blade.php.
// ===================================================

(function () {
    // Legacy sessionStorage keys. The loader NO LONGER WRITES THESE — see the memo
    // note below — but busting still removes them so a set cached by an older build
    // (or during the loop-proof session) can never be served after an upgrade.
    var LEGACY_KEYS = ['parserLiveKeywordsV1', 'parserLiveKeywordsV2'];

    // In-memory memo, deliberately NOT sessionStorage (2026-09-12).
    // The old 24h/15min TTL cache survived page loads, so a table edited or wiped
    // SERVER-side kept being served from the browser for the rest of the TTL —
    // it cost two proof sessions ("the system still remembers"). The cache only
    // ever saved one small GET per import, so it now lives for the page load and
    // no longer: any refresh re-reads the server, which is what everyone expects.
    // _inflight shares one request between callers that ask concurrently.
    var _memo = null;
    var _inflight = null;

    // Fetch the live rows and fold them into { labels: { field: [phrase] } }.
    // Role-based like harvestCorrections; contractors have no route and skip.
    async function fetchLiveKeywords() {
        const EMPTY = { labels: {} };
        try {
            const path = (typeof window !== 'undefined' && window.location) ? window.location.pathname || '' : '';
            if (path.indexOf('/contractor/') !== -1) return EMPTY;
            const base = path.indexOf('/staff/') !== -1 ? '/staff' : '/admin';
            const res = await fetch(base + '/parser-keyword-proposals/all?status=live', {
                headers: { 'Accept': 'application/json' },
            });
            if (!res || !res.ok) return EMPTY;
            const rows = await res.json();
            const pairs = { labels: {} };
            for (const r of (rows || [])) {
                const phrase = String((r && r.label_phrase) || '').trim();
                const field = String((r && r.field) || '').trim();
                if (!phrase || !field) continue;
                (pairs.labels[field] = pairs.labels[field] || []).push(phrase);
            }
            return pairs;
        } catch (e) { return EMPTY; }
    }

    // L3 — live learned vocabulary (parser_keywords rows with status=live).
    // Memoized for the page load (see the memo note above), so repeat imports on
    // one page cost a single request while a refresh always re-reads the server.
    // Consulted by core.parseProjectInfo / the PDF reader as the LOWEST-priority
    // fallback; an empty table (or any failure) reproduces today's behavior
    // exactly. Best-effort — never blocks a preview or an import.
    async function loadLearnedKeywords() {
        if (_memo) return _memo;
        if (!_inflight) {
            _inflight = fetchLiveKeywords()
                .then(function (pairs) {
                    // Never memo an empty desk: the first approval must land on the
                    // next import, not after another page load.
                    const total = Object.keys(pairs.labels || {}).reduce(
                        function (n, f) { return n + pairs.labels[f].length; }, 0
                    );
                    if (total > 0) _memo = pairs;
                    return pairs;
                })
                .finally(function () { _inflight = null; });
        }
        return _inflight;
    }

    // Drop the remembered word set so the very next import re-reads the server.
    // Called by the review desk right after a successful approve/reject: a server
    // write the browser cannot otherwise see would keep serving the pre-approval
    // set for the rest of the page's life (proof record bug (b)). Also clears the
    // legacy sessionStorage caches written by older builds.
    function bustLearnedKeywordsCache() {
        _memo = null;
        _inflight = null;
        for (var i = 0; i < LEGACY_KEYS.length; i++) {
            try { sessionStorage.removeItem(LEGACY_KEYS[i]); } catch (e) { /* best-effort */ }
        }
    }

    window.loadLearnedKeywords = loadLearnedKeywords;
    window.bustLearnedKeywordsCache = bustLearnedKeywordsCache;
})();

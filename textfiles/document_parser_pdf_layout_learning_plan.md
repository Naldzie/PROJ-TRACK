# PDF S-CURVE: DELIVERY FIXES + LAYOUT LEARNING (E1-PDF)
# Parent refs: textfiles/document_parser_pdf_ai_fallback_plan.md (F-A..F-D + B — the PDF AI track)
#              textfiles/document_parser_excel_learning_loop_plan.md (E1/E1-3/Phase-A — the loop being mirrored)
#              textfiles/pdf_ai_loop_proof_tests.md (all live proof results)
# Date: September 12, 2026
# Status: STAGE 1 ✅ BUILT + USER-PROVEN · P3 ⬜ NEXT (evidence in hand) · STAGE 2 (E1-PDF) ⬜ NOT STARTED
# Branch: phase-5-parser-accuracy (Stage 2 may branch phase-6-pdf-layout-learning off it; Rule 12)

===============================================================================
  0. READ THIS FIRST (handoff in 60 seconds)
===============================================================================

  WHAT THIS TRACK IS
    Contractor S-curve schedules arrive as Excel or PDF. Excel already LEARNS
    LAYOUTS (capture → review desk → approve → free offline parse). PDF only
    learns WORDS. This file plans giving PDF the Excel loop, plus the two
    delivery fixes that had to land first.

  WHERE IT STANDS
    - Stage 1 (delivery fixes) BUILT and PROVEN live 2026-09-12. The PDF word
      loop (B / F-D / F-E) is CLOSED: a taught month word makes the offline
      reader parse the TRUE curve with zero AI.
    - P3 (merge-by-Y) is the NEXT item: root-caused with forensic evidence on
      2026-09-12, not yet built. It is cheaper than Stage 2 and fixes the
      activity-name misassignment the proof exposed.
    - Stage 2 (E1-PDF layout learning) is designed but NOT started.

  ORDER OF WORK: P3 → Stage 2 (PDF-1 → PDF-2 → PDF-3 → PDF-4), each proved
  before the next, each needing an explicit user order (Rule 10).

  HARD RULES FOR WHOEVER PICKS THIS UP
    - Blinding stays ON (L3_TEST_BLIND_ABC = true in document-parser-core.js).
      The JS suite therefore shows 2 INTENDED failures — that is green.
    - Sample files live in git-ignored sampledocs/ — reference by name, NEVER
      commit them. One-off scripts go in C:\temp\opencode, outside the repo.
    - No merge to main, and no push, without explicit user words (Rule 12).
    - Log every behavioral change in a plan file BEFORE committing (Rule 11).
    - Browser-proven beats suite-green for DOM/canvas paths; pure/service paths
      may rest on suites. Clean-tree (stash) check before claiming no regressions.
    - Frontend convention: app JS/CSS lives in public/ via asset()/$jsv() — never
      @vite, never resources/ for new feature scripts.

  VERIFY COMMANDS
    npm run test:js          → expect 116 passed + 2 intended blinding fails
    php artisan test         → expect 1140 passed / 7 pre-existing failures
                               (2 ContractorDashboard, 1 DashboardOverdueInspection,
                                2 EmailDraft, 2 default-baseline)
    php artisan view:clear   → after ANY Blade edit
    php artisan route:clear  → after ANY route change (recurring ghost-route incident)

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

  Two learning channels exist for S-curve imports:
    - EXCEL LEARNS LAYOUTS. A "No S-curve table found" 422 silently captures the
      header slice; the server auto-derives a column map (deriveAutoColumnMap);
      the row waits in the S-Curve Layouts desk; approval makes it live; then
      findHeaderViaMaps parses same-layout files with NO AI. AI successes also
      teach (learnMapFromAi), and a reviewer can click-fix columns (Phase A).
    - PDF LEARNS ONLY WORDS. F-D banks month-header wording (field scurveMonth)
      through the Learned Keywords desk.

  WHY PDF LAYOUT LEARNING IS POSSIBLE (correcting an old assumption)
    The note "PDFs have no columns" was written against the OLD server-side
    smalot/pdfparser, which strips bounding boxes. The reader actually in use is
    pdfjs-dist 4.4.168 IN THE BROWSER, which carries x/y — detectHeaderColumns
    already returns {descX, descRight, monthCols:[{index,x,xEnd}]}
    (public/js/admin/projects/scurve-pdf-parser-core.js). Those bands ARE a
    saveable layout. What PDFs lack is stable LETTER ADDRESSES, not coordinates.
    The store already anticipates this: scurve_header_proposals.source_type is
    commented "reserves the PDF twin (E1-PDF)" and collect() already validates
    source_type in:excel,pdf.

===============================================================================
  2. STAGE 1 — DELIVERY FIXES  ✅ BUILT + PROVEN 2026-09-12
===============================================================================

  PROBLEM (from the loop proof): learning worked end-to-end — miss → AI → 5
  proposed rows → approve → Live — but re-import STILL missed on both flows.
  Two delivery defects, neither in the learning code.

  FIX (a) — SHARED LOADER
    loadLearnedKeywords() was a plain function declaration inside
    document-parser.js, which ONLY the create-project modal loads. The detail
    Baseline tab loads the S-curve scripts without it, so the PDF wrapper's
    `typeof window.loadLearnedKeywords === 'function'` guard silently yielded
    nothing and detail re-imports could never consult approved words.
    - NEW public/js/admin/projects/learned-keywords-loader.js holds the loader.
    - Loaded by BOTH role layouts (app.blade.php + stafflayouts.blade.php)
      beside the pdfjs loader, filemtime-versioned. Contractor layout excluded.
    - document-parser.js + scurve-pdf-parser.js both consult
      window.loadLearnedKeywords behind the same typeof guard, so a missing
      loader degrades to hardcoded labels exactly as an empty table does.

  FIX (b) — CACHE
    The live word set was cached in sessionStorage (originally 24h TTL). A
    server-side change — approving elsewhere, or wiping the table in the DB —
    was invisible to the browser, so a stale set kept serving imports. It cost
    two proof sessions ("the system still remembers").
    - The cross-page-load cache is GONE. The set is memoized IN MEMORY for the
      page load (concurrent callers share one in-flight promise; empty desks are
      never memoized), so any refresh re-reads the server.
    - The review desk calls window.bustLearnedKeywordsCache() after a successful
      approve AND reject; busting also removes the legacy
      parserLiveKeywordsV1/V2 sessionStorage keys.
    - LESSON BANKED: do not reintroduce a cross-page-load cache over a table the
      user edits by hand unless a version endpoint exists to validate it.

  ALSO IN STAGE 1 (user request, same session)
    The in-modal PDF AI link used to render only when isWeakPdfCurve() flagged
    the read, so a clean PDF import had no way to ask for a second opinion —
    while the Excel flow always offers its cross-check. Both stash sites (wizard
    + detail) now stash the ctx for EVERY parseable PDF with a `weak` flag, and
    scurveRenderPdfAiLink uses that flag for WORDING ONLY:
      weak   → "This PDF reading looks weak … recommended." + "Try AI assist?"
      strong → "Read offline from the PDF, no AI used. Want a second opinion?"
               + "Cross-check with AI?"
    Quota discipline unchanged: opt-in, one call per click, busy-guarded.

  PROOF (user, live, no console workaround — SEGMENT specimen)
    Approved one SEGMENT row → re-import parsed NATIVELY with zero AI:
    TRUE curve 5 / 20 / 40 / 65 / 100. B / F-D / F-E CLOSED.
    Residual exposed by the same run: activity NAMES misassigned → see §3.

  FILES TOUCHED
    public/js/admin/projects/learned-keywords-loader.js          (NEW)
    public/js/admin/projects/document-parser.js                  (loader removed, consults shared)
    public/js/admin/projects/scurve-pdf-parser.js                (comment only)
    public/js/admin/projects/scurve-import-ui.js                 (always-offer PDF AI link)
    resources/views/layouts/app.blade.php                        (script tag)
    resources/views/layouts/stafflayouts.blade.php               (script tag)
    resources/views/admin/_manage_parser_keywords_modal.blade.php(bust on approve/reject)

===============================================================================
  3. P3 — MERGE BY Y, NOT BY ROW POSITION  ⬜ NEXT (root-caused, not built)
===============================================================================

  SYMPTOM (step-6 proof, rule-only read of the SEGMENT PDF)
    Percentages perfect (5/20/40/65/100) but names wrong:
      M1 Mobilization ✅ · M2 Earthworks ✅ · M3 Mobilization ❌ (should be
      Concrete and Masonry Works) · M4 Earthworks ❌ · M5 Earthworks ❌
      (both should be Finishing and Painting Works)
    Accompanied by: "pages carry different row counts (4 vs 2) — months were
    aligned by position."

  ROOT CAUSE (forensic probe 2026-09-12, read-only, real file through the real
  core in node with pdfjs-dist from node_modules — script in C:\temp\opencode)
    The print splits the table across two pages BY COLUMNS:
      page 1 = ITEM NO. / DESCRIPTION / CONTRACT AMOUNT / % WEIGHT /
               SEGMENT 1 / SEGMENT 2, with 4 activity rows
      page 2 = SEGMENT 3 / 4 / 5 only — NO description column, 2 value lines
    mergePages() sees disjoint months and aligns rows BY INDEX (its own comment
    admits "positional alignment is a guess"), so page-2 row 0 inherits
    Mobilization and row 1 inherits Earthworks.

    BUT THE Y COORDINATES ARE IDENTICAL ACROSS THE PAGES:
      header    page1 y=595.90   page2 y=595.90
      row 3     page1 y=537.79   page2 200000 @ y=537.79
      row 4     page1 y=523.27   page2 250000, 350000 @ y=523.27
    Aligning by Y is exact and deterministic for column-split prints.

  FIX SHAPE (pure core, unit-testable — no AI, no learning, no UI)
    - buildRows() currently pushes {description, months, presence}; tokenLines
      already carry `y` (linesToTokenLines), so carry the row's y through.
    - mergePages(), disjoint-months branch only: when both pages expose row y's
      and their ranges correspond, match each incoming row to the merged row
      with the nearest y inside a tolerance (suggest half the median row gap);
      otherwise fall back to today's positional merge WITH the existing warning.
    - Keep the warning when a positional fallback is actually used; a successful
      y-match should NOT warn (the alignment is then known, not guessed).
    - Fixtures: the SEGMENT column-split shape (expect the 4 real names on the
      right months), a same-y-but-different-page-geometry case that falls back,
      and an unchanged continuation-page case (regression guard).

  WHY P3 COMES BEFORE STAGE 2
    It is a deterministic bug fix with evidence already in hand, it costs no
    quota and no approvals, and it fixes the exact complaint the proof exposed.
    Layout memory (Stage 2) does not address positional merge drift at all.

===============================================================================
  4. STAGE 2 — E1-PDF LAYOUT LEARNING  ⬜ NOT STARTED
===============================================================================

  User-ordered scope 2026-09-12: capture+consult, learn-from-AI, and
  point-and-teach. (Desk rendering for PDF rows was not selected as a separate
  item, but a teach surface cannot exist without it, so the MINIMUM rendering
  is folded into PDF-4.)

  PDF-0 — MAP SCHEMA (decide once, at the start of PDF-1)
    A PDF map must survive a different page size and DPI, so store FRACTIONS:
      { v: 1, kind: 'pdf',
        sig: [normalized header words],   // fuzzy-match key
        descX, descRight,                 // ÷ pageMaxX
        monthCols: [{ index, x, xEnd }],  // ÷ pageMaxX, ascending
        headerLine, pageHint, lineHint }
    Rehydrate by multiplying by the new page's pageMaxX. Guards mirror E1-3:
    version gate (v mismatch never applies), kind/source_type gate (an Excel map
    must never touch a PDF and vice versa), ≥2 ascending months, desc band
    disjoint from month bands, and a months-spans-hold-numbers sanity check
    before a map is allowed to build rows.

  PDF-1 — CAPTURE ON FAILURE (silent, zero behavior change)
    The server has no PDF text extractor, so unlike Excel the CLIENT derives the
    candidate map and posts it.
    - Core: a relaxed sibling of detectHeaderColumns — same geometry, only the
      WORDING loosened (any `WORD N` pattern with ≥2 ascending numbers, looser
      description labels), exported pure and unit-tested. This mirrors the Excel
      delta exactly: relaxed detection is safe ONLY because approval gates it,
      never in the live reader.
    - Wrapper (scurve-pdf-parser.js): on a miss, derive map + serialized header
      block and stash for the UI.
    - UI (scurve-import-ui.js): fire-and-forget capture in the PDF failure
      branches — the wizard branch (~line 867) and the detail branch (~line 962,
      which today explicitly skips capture for docType === 'pdf'). Reuse
      scurveCaptureHeaders with a PDF mode; failures swallowed; error UX intact.
    - Endpoint: extend collect() to accept source_type=pdf with client-supplied
      header_rows_text + column_map (no file/document_id); same hash/upsert/
      occurrences/auto-live-≥3 rules and the same E1a contractor scoping
      (contractor-first, global fallback).
    - Tests: core fixtures (SEGMENT-style hit, junk → null); PHP store /
      repeat-promote / contractor-403 / bad-payload, mirroring
      ScurveHeaderProposalTest.
    - Proof: a failed PDF import leaves exactly ONE proposed PDF row, and both
      existing proof twins still extract byte-identically (reader untouched).

  PDF-2 — READER CONSULTS APPROVED MAPS
    - Core: parseSCurvePdf(itemsByPage, opts) already carries an opts bag (that
      is how monthWords threads) — add liveMaps. Two-pass like E1-3: RULES
      ACROSS ALL PAGES FIRST, maps only when nothing is recognized anywhere, so
      rule-built results stay byte-identical. On a signature hit (reuse the
      core's existing levenshtein / headerFuzzyMatch), rehydrate bands ×
      pageMaxX into the same header struct buildRows already consumes — no
      row-assembly changes.
    - Wrapper: load live PDF maps through the Stage-1 shared loader (same file,
      new function, role-based, contractor-scoped) so BOTH flows consult. This
      is the payoff for fix (a).
    - Meta/UX: mapUsed/mapId in meta + one plain-worded in-modal FYI on
      map-built curves, riding the existing warning stash.
    - Proof: captured-then-approved layout re-imports natively with the FYI note
      and zero AI, on both flows.

  PDF-3 — LEARN-FROM-AI (PDF twin of E1-B)
    Nearly free: the PDF AI path ALREADY returns monthLabels (F-A
    sanitizeMonthLabels → F-B returns them → F-D harvests them). No prompt
    change, and no asking the model for pixel coordinates it cannot give.
    - After a successful AI merge, locate the returned month labels in the page
      TEXT LAYER (already stashed as window._scurvePdfPageTexts / items) and
      read their x positions → bands → map. Labels absent from the page derive
      nothing (same discipline as sanitizeActivityNames).
    - Store through the existing ScurveHeaderProposal::storeAiDerived path:
      ai-derived source, NEVER auto-live, backfilled rows drop to proposed.
      Skip entirely when a map already applied (nothing to learn), mirroring
      learnMapFromAi's preferMapMath rule.
    - Covers what the relaxed detector cannot crack alone — notably the ROOF
      family (zero header hits, duration labels), currently parked as "rule
      family A".

  PDF-4 — POINT-AND-TEACH (+ the minimum desk rendering it requires)
    RECOMMENDED: pseudo-columns reusing the proven Phase-A UI, NOT a click
    surface over a rendered page image (which needs re-rendering, zoom/scroll
    math and a second teach endpoint).
    - Serialize a PDF capture's header lines into the SAME `R<n>[ROLE]: cell,cell`
      block with a COLUMNS legend that schParseHeaderBlock already parses, by
      clustering token x-positions into pseudo-columns A, B, C… The existing
      mini-grid, Teach modal, palette, readout, validation and variant/replace
      semantics then work essentially as-is.
    - teachColumns gains a PDF branch converting taught letters back into
      x-bands via the stored legend, stamping v/sig/kind:'pdf' with the PDF-0
      guards. Approval stays the sole live flip; teaching creates a variant.
    - Desk: PDF/Excel badge + the grid fed from the PDF block.

  STAGE 2 ACCEPTANCE (browser, user)
    On a layout the rules cannot read: import → miss → capture row appears in the
    S-Curve Layouts desk with a PDF badge and a derived map → approve →
    RE-IMPORT PARSES NATIVELY WITH ZERO AI. Separately: an AI-assisted read on a
    map-less file banks an ai-derived proposed layout that is NOT live until
    approved.

===============================================================================
  5. THE SEGMENT SPECIMEN (the reference test pair)
===============================================================================

  sampledocs/SCurve-Test-Segment-Novel-Specimen.xlsx  (+ the printed -pdf.pdf twin)
  Git-ignored. Verified read-only 2026-09-12:

    Header row 10: ITEM NO. | DESCRIPTION | CONTRACT AMOUNT | % WEIGHT |
                   SEGMENT 1 | SEGMENT 2 | SEGMENT 3 | SEGMENT 4 | SEGMENT 5
    1 Mobilization Works          50,000   → SEGMENT 1
    2 Earthworks and Excavation  150,000   → SEGMENT 2
    3 Concrete and Masonry Works 200,000   → SEGMENT 3
    4 Finishing and Painting     600,000   → SEGMENT 4 (250,000) + SEGMENT 5 (350,000)
    TOTAL 1,000,000 / 100%
    TRUE CURVE: 5 / 20 / 40 / 65 / 100

  SCOREBOARD (2026-09-12)
    AI-assisted read   : curve ✅ · names ✅ · Finishing spanning M4+M5 ✅ — EXACT
    Rule-only read     : curve ✅ (native, zero AI, via the taught SEGMENT word)
                         names ❌ on M3/M4/M5 — the P3 positional-merge defect
    "SEGMENT" is unknown to every hardcoded tier, which is what makes this pair
    a valid novel-layout probe. Keep it that way — do not hardcode SEGMENT.

===============================================================================
  6. DECISION LOG
===============================================================================

  Decision 1 — Delivery fixes before layout learning (user, 2026-09-12).
    Context: the word loop was one inch from done; layout learning is multi-phase.
    Decision: land the shared loader + cache fix first.
    Rationale: fix (a) is the SAME plumbing any layout consult needs on the
      detail tab — not a detour.
    Consequence: B/F-D/F-E closed before Stage 2 opened.

  Decision 2 — Kill the cross-page-load word cache outright (2026-09-12).
    Context: it cost two proof sessions; a server-side wipe is invisible to it.
    Options: (a) shorter TTL; (b) refetch-on-miss; (c) in-memory memo only.
    Decision: (c). Rationale: the cache only ever saved one small GET per import,
      while a stale set silently corrupts proofs. Refetch-on-miss was rejected —
      it only fires when the cached set has NO rows for the field, so a
      stale-but-non-empty set still slips through.
    Consequence: refresh always re-reads; approve/reject bust immediately.

  Decision 3 — PDF AI offered on strong reads too (user, 2026-09-12).
    Context: clean PDF imports had no second-opinion path; Excel always offers one.
    Decision: always stash the ctx; the weak flag selects wording only.
    Consequence: no extra quota (opt-in click), parity with the Excel cross-check.

  Decision 4 — P3 before E1-PDF (2026-09-12).
    Context: the proof's residual is a positional merge defect, not a layout gap.
    Decision: fix mergePages by Y first.
    Consequence: E1-PDF starts against a reader whose names are already right on
      column-split prints, so its captures are cleaner.

===============================================================================
  7. FLAGGED FOR FUTURE (unordered — each needs an explicit user order)
===============================================================================

  - F-PDF2 full answer-fingerprint reuse (store a verified AI reading keyed by a
    file-shape hash for zero-AI repeat imports of the SAME file). Build only if
    words + layouts prove insufficient.
  - Desc-label learning (prompt-reported work-names title + the same plumbing);
    F-D covers month words only.
  - months:11 count-sanity guard — one-off sighting on the 10-month file, needs a
    confirming screenshot before any code.
  - Time-budget hardening (PHP 60s overflow → honest 422; sender-only).
  - Clipped display names verdict (check edit/manage for full names first).
  - Blinding lift — a tracked pre-merge-to-main prerequisite, NOT a routine step.
  - Parked: Tier-2 OCR for S-curve PDFs, rule family A (Roof-style), 2c,
    P1/P2 passive mining + lifetime counting.

===============================================================================
  8. SESSION LOGS
===============================================================================

  2026-09-12 — Stage 1 BUILT (shared loader + cache fix), suites green
    (JS 116 + 2 intended blinding fails; PHP 1140 / same 7 pre-existing).
    Full entry in document_parser_pdf_ai_fallback_plan.md §11.

  2026-09-12 — Step-6 re-run PROVEN by user: native parse, TRUE curve, zero AI.
    B / F-D / F-E closed. Residual: activity names misassigned.

  2026-09-12 — Follow-ups built: in-memory memo replaces the sessionStorage
    cache; PDF AI link offered on strong reads (wording-only gate).

  2026-09-12 — Specimen verified + P3 root-caused (read-only probes):
    Excel truth dumped via PhpSpreadsheet; the PDF run through the real core in
    node with pdfjs-dist. Column-split pages share row Y positions exactly, so
    merge-by-Y is deterministic. AI-assisted read confirmed EXACT on curve,
    names and the M4+M5 span. This file created for handoff.

===============================================================================
  9. CONVERSATION CONTINUATION GUIDE
===============================================================================

  1. Read §0, then §3 (P3) — that is the next buildable item.
  2. Ask the user for an explicit order before building anything (Rule 10).
  3. Keep Excel and PDF paths separate: never let a PDF map reach the Excel
     reader or vice versa (source_type / kind gates).
  4. Run both suites before and after; clean-tree stash check before claiming
     zero regressions; view:clear / route:clear per the incident classes.
  5. Log here (and in the PDF AI plan for AI-track changes) before committing.
  6. No merge to main and no push without explicit user words.

===============================================================================
  END OF PLAN
===============================================================================

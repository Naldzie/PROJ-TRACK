# PDF S-CURVE: DELIVERY FIXES + LAYOUT LEARNING (E1-PDF)
# Parent refs: textfiles/document_parser_pdf_ai_fallback_plan.md (F-A..F-D + B — the PDF AI track)
#              textfiles/document_parser_excel_learning_loop_plan.md (E1/E1-3/Phase-A — the loop being mirrored)
#              textfiles/pdf_ai_loop_proof_tests.md (all live proof results)
# Date: September 12, 2026
# Status: STAGE 1 ✅ · P3 ✅ · PDF-1 ✅ BROWSER-PROVEN · PDF-2 ✅ built (consult NOT browser-proven — see the corrected proof entry) · PDF-3/4 ⬜
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
    - P3 (merge-by-Y + the blank-token desc bound) BUILT and BROWSER-PROVEN
      2026-09-12: 6-stage and 10-month PDFs now read exactly on first upload,
      with real names, and the long-standing "19.99 dust" is gone.
    - PDF-1 (capture) BUILT and BROWSER-PROVEN 2026-09-12: rules miss → silent
      capture → the desk row renders with its PDF/Mapped badges and grid.
    - PDF-2 (consult) BUILT, core proven on the real file by node probe, but NOT
      browser-proven: the live run that looked like proof was actually served by
      LIVE MONTH WORDS (SEGMENT 1..5 auto-promoted at seen=3), so the map pass
      never ran. To prove it: reject those words, keep the layout live, re-import
      — and expect the "learned layout" note. See the corrected proof entry.
    - PDF-3 (learn-from-AI) and PDF-4 (teach) NOT started.

  ORDER OF WORK: PDF-3 → PDF-4 (P3, PDF-1 and PDF-2 are done), each proved
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
    npm run test:js          → expect 131 passed + 2 intended blinding fails
    php artisan test         → expect 1156 passed / 7 pre-existing failures
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
  3. P3 — MERGE BY Y, NOT BY ROW POSITION  ✅ BUILT 2026-09-12
===============================================================================

  BUILT: three pure-core changes (see the §8 session log for the full account and
  the measured before/after on every sample PDF). The section below is the
  original spec, kept as the record of what was diagnosed and why.

  WHAT SHIPPED
    (1) rows carry their y + a `named` flag (buildRows).
    (2) mergePages pairs column-overflow pages by ROW HEIGHT (matchRowsByY, with
        a refuse-rather-than-guess contract); positional merge + its warning stay
        as the fallback. Unmatched NAMELESS rows are dropped as page furniture
        and SAID OUT LOUD in a new warning — leaving numbers out is never silent.
    (3) blank tokens can no longer bound the description column — the fix that
        turned out to be the real blocker (see below).

  THE SECOND DEFECT, FOUND WHILE PROVING THE FIRST
    detectHeaderColumns computed the description's right edge from the first
    token right of the desc label WITHOUT skipping blanks. pdf.js emits empty
    text items for padded cells, so an empty item at x=76 (between ITEM and
    SCHEDULED TASKS on the 6-stage file) collapsed the description window to
    nothing. Consequences, all now gone: every activity name fell back to
    "Activity N"; the CUMULATIVE row lost its label, escaped summary detection
    and entered the curve AS AN ACTIVITY; its 5/20/40/… values inflated the
    contract denominator, which is exactly where the long-documented
    "19.99-style dust" came from. This is the F-PDF4 "desc-bound tightening"
    item that was parked pending evidence — the evidence arrived.

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

  2026-09-12 — RESTUDY before building (read-only). Findings that changed scope:
    (a) PDF-2 needs NO new route — getAllForManagement already returns column_map
        /source_type/contractor_id and supports ?status=live, mirrored on both
        roles. (Avoids the stale-route-cache incident class entirely.)
    (b) loadLiveMaps already filters source_type='excel', so PDF rows cannot leak
        into the Excel reader. Only the reverse guard is needed.
    (c) The desk had ZERO source_type handling, so PDF rows would arrive
        unlabelled → the PDF/Excel badge moved into PDF-1 rather than PDF-4.
    (d) serializeForAi's block shape is exactly reproducible
        ("COLUMNS: A,B,… | …" + "R<n> [ROLE]: csv", parsed back by
        parseStoredSlice with str_getcsv) — so a PDF capture that emits the SAME
        shape gets the desk grid, the teach modal and teachColumns for free.
    (e) collect() required file XOR document_id and always called serializeForAi
        — impossible for a PDF; needed a branch.
    (f) Only joined page STRINGS are stashed today, so PDF-3 will need token
        positions retained to locate the AI's monthLabels.
    (g) Gates to mirror from buildTaughtMap: ≥2 months, disjoint spans, labels
        outside spans, ascending order, signature from non-empty header cells.

  2026-09-12 — PDF-1 BUILT (capture). Code committed only after the probes below.
    - Relaxed detection lives INSIDE detectHeaderColumns behind opts.relaxed, and
      the banding was extracted to finalizeHeaderBands() shared by both paths —
      so strict and relaxed geometry cannot drift. Only the WORDING differs.
    - Two ways to find months when the vocabulary is unknown:
      relaxedMonthColumns() groups "WORD N" tokens by STEM and keeps the stem
      with the most ascending indices (a shared stem is what stops "ITEM 1 …
      QTY 2" posing as months); positionalMonthColumns() handles labels with no
      index at all by REPETITION — the roof family prints "30 days" six times, so
      the Nth repetition is month N. Positional demands THREE repetitions (two
      identical words prove far less than an ascending 1,2,3).
    - bandsHoldNumbers() pays for that permissiveness: a candidate is refused
      unless ≥2 different month bands hold numeric OR PERCENT values in the rows
      beneath (the roof's cells are "16%", not pesos).
    - derivePdfLayout() returns {map, block}. The map stores FRACTIONS of
      pageMaxX (so another page size still matches) plus a pseudoCols legend for
      PDF-4; the block is byte-compatible with the Excel slice.
    - Wrapper stashes a candidate ONLY on a rules miss; scurveCaptureHeaders
      gained a 'pdf' mode posting source_type/header_rows_text/column_map (the
      server owns no PDF reader, so the browser's pdf.js pass is the only reader
      there is); collect() gained the PDF branch, refusing a slice that is not a
      COLUMNS block and refusing to store a NON-pdf map on a PDF row; the dedupe
      query now scopes by source_type so Excel and PDF rows can never merge.
      Desk shows a PDF/Excel badge.
    - PROBES ON REAL FILES (the point of building capture first):
        SEGMENT → derives months 1,2 + correct desc band + a clean block.
        ROOF    → derives ALL SIX months from the repeated "30 days" labels,
                  desc band 0.045→0.330 over the names, numbers guard satisfied.
                  This is the family E1-PDF exists for, and the indexed detector
                  alone could NOT see it — positional detection was added for it
                  after the probe proved the gap (user decision).
        All 16 sample-PDF rule reads byte-IDENTICAL before and after.
    - CAUGHT PRE-GREEN (2, both mine): (1) role tagging tested EVERY cell, and
      isSummaryLabel matches a bare "%", so every percent-valued activity row was
      tagged SUMMARY — roles now read label cells only, never value cells;
      (2) restructuring collect() dropped the $hash line (500s on the Excel path)
      — caught by the existing Excel tests, which is exactly why they exist.
    - Tests: +4 JS (relaxedMonthLabel; indexed capture end-to-end incl. block
      shape; positional/roof shape; refusal with no numbers under the grid),
      +3 PHP (client-read slice stored + repeat counts; junk and foreign-map
      refusals; Excel path still XOR-gated). JS 126/124 + the 2 intended blinding
      fails; PHP 1156 passed / same 7 pre-existing.
    - KNOWN, CARRIED INTO PDF-2: on a COLUMN-SPLIT file the capture sees only the
      first page's months (SEGMENT → 1,2). Consult must apply bands across pages,
      or a partial map would build a short, WRONG curve. Do not ship consult
      without this.
    - NOT browser-proven yet: the capture POST and the desk badge.

  2026-09-12 — PDF-2 BUILT (consult). The loop now closes for PDFs.
    - Two passes, mirroring E1-3 exactly: collectPdfPages() walks the pages with
      the RULES first, and a learned layout is consulted ONLY when nothing was
      recognised on any page. A file the rules can read is therefore untouched by
      whatever has been approved — proven by fixture and by the 16-file probe.
    - matchMapPage(): a stored page matches when >=2 of its remembered header
      cells are found on a line (exactly or within the reader's existing typo
      tolerance) AND at least half of them; bands are rehydrated by multiplying
      the stored FRACTIONS by THIS page's width, which is what lets a map fit a
      differently sized print. bandsHoldNumbers() has the final say, so wording
      alone can never make a cover page pose as the table.
    - usableLayoutMap() gates version, kind AND SHAPE. The shape gate is not
      theoretical: pre-correction captures hold a single-page map that would read
      a column-split table short, so they are ignored outright even if a row says
      "live" (this is what neutralises the stale roof row id=55 without touching
      the data).
    - One map owns a file: once a map has matched, only its other pages are tried,
      so two layouts can never interleave within one read.
    - meta gains mapUsed/mapId plus one plain in-modal note, wording borrowed from
      the Excel learned-layout note.
    - Loader: window.loadLiveScurveLayouts() joins the shared learned-data loader
      (same role-base, same contractor exclusion, same per-page-load memo, same
      best-effort [] on failure), filtered to source_type='pdf' rows carrying a
      map. bustLearnedKeywordsCache() clears it too, so approving in the desk
      shows up on the next import. NO new route — getAllForManagement already
      serves this (restudy finding (a)), so the stale-route-cache class is avoided.
    - PROOF ON THE REAL FILE (capture → consult, no AI): the SEGMENT PDF, which
      the rules MISS entirely, parsed through its own captured layout as
      5 / 20 / 40 / 65 / 100 with the true names (Mobilization, Earthworks,
      Concrete and Masonry, Finishing x2 spanning M4+M5) and contract 1,000,000.
      The 6-stage file (rules-readable) came back byte-identical with that map
      present and mapUsed false.
    - Tests +4 JS (an approved layout reads an unreadable file incl. the note;
      rules win outright when they can read it; unusable maps — stale shape, bad
      version, excel kind, empty pages — are ignored rather than half-applied; a
      matching signature over empty bands does not apply).
      JS 133 tests, 131 passed + the 2 intended blinding fails; PHP 1156 passed /
      same 7 pre-existing; all 16 sample-PDF rule reads byte-IDENTICAL.
    - NOT browser-proven yet: capture POST, desk PDF badge, and the approve →
      re-import round trip. The core path is proven on the real file above.

  2026-09-12 — E1-PDF LIVE RUN + A CORRECTED PROOF CLAIM (user, SEGMENT PDF).
    WHAT IS PROVEN (browser, tables wiped first so nothing was inherited):
      - AI assist returns the correct curve + names (the paid path still works).
      - Rules miss → SILENT CAPTURE. The desk row renders exactly as designed:
        red PDF badge, blue Mapped badge, contractor "testcompany", seen 1x,
        Auto-capture origin, Proposed status, and a readable mini-grid (R1/R2
        META, R3 HEADER = ITEM NO./DESCRIPTION/CONTRACT AMOUNT/% WEIGHT/
        SEGMENT 1/SEGMENT 2, R4-R7 DATA, R8 SUMMARY = the TOTAL row).
        ⇒ PDF-1 capture, the endpoint, the badges and the grid are BROWSER-PROVEN.
      - Approving the layout, then re-importing, reads 5/20/40/65/100 with the
        true names and no AI call.
    CLAIM CORRECTED THE SAME HOUR — that last read does NOT prove PDF-2.
      This file first logged it as "E1-PDF browser-proven end to end". It is not.
      The database shows five LIVE scurveMonth words (SEGMENT 1..5, seen=3,
      source AI-assisted) that auto-promoted under the ≥3 rule during the day's
      repeated AI runs. With those live, the RULES find the header on pass 1, the
      map pass never runs, and mapUsed stays false — which is precisely why the
      user saw NO import note. The zero-AI read is real, but it was delivered by
      WORD learning (already proven earlier today), not by the newly approved
      LAYOUT. PDF-2 consult remains BROWSER-UNPROVEN; its core path is proven
      only by the node probe against the real file.
      The absent note is therefore CORRECT behaviour, not the bug it looked like:
      no map was consulted, so no map note. Nothing to fix there.
    HOW TO ACTUALLY PROVE PDF-2 (next session, one run):
      Reject the five live SEGMENT words in Learned Keywords, KEEP the approved
      layout live, then re-import the SEGMENT PDF. The rules must miss, the map
      must fire, and the modal must carry "read with a learned layout for this
      contractor". If the curve comes back without that note, THEN something is
      wrong — but only under those conditions.
    LESSON: two learning channels now read the same file, so a zero-AI read no
    longer identifies WHICH one worked. Isolate the channel under test (wipe or
    reject the other) before claiming a proof, and read meta.mapUsed rather than
    inferring from the curve being right.
    STILL PAGE-1 ONLY IN THE GRID: the teach surface shows SEGMENT 1-2 while the
    stored map holds 3,4,5 as well. The map is complete; only the human-facing
    grid is single-page, which is PDF-4's job.
    OPEN QUESTION for the next session: the user's paste of the post-approval run
    carried no import notes, and a map-built curve is supposed to announce itself
    ("read with a learned layout for this contractor"). The wiring reads sound —
    importPDFSCurve returns {months, meta} and scurveStashWarnings(data, months)
    reads data.meta.warnings — so this may simply be an unpasted section. CONFIRM
    before assuming either way; if the note is genuinely absent, the map-built
    read is silent, which is exactly what the Excel path refuses to be.

  2026-09-12 — PDF-1 CORRECTED for column-split tables (user catch).
    USER CORRECTION: the roof has a SEVENTH month labelled "7 days", on page 2
    (L4: "30 days@40  7 days@116"). The arithmetic confirms it — 6x30 + 7 = the
    187 days printed on page 1. My capture read ONE page, so it proposed 6 months
    for a 7-month schedule; approving that would have taught a wrong curve. The
    same flaw hit SEGMENT (page 1 has SEGMENT 1,2; page 2 has 3,4,5 = 2 of 5).
    BOTH real capture candidates are column-split, so a single-page map was
    simply the wrong shape. Caught by the user reading the file, not by a test —
    every fixture described a single-page table, which is what hid the gap.
    - Shape grouping replaces identical-text grouping: the key masks digit runs
      ("30 days"/"30days"/"7 days" -> "#days"), so a tail column of a different
      length joins its family. That exact-text key is why "7 days" was invisible.
    - Maps are now MULTI-PAGE: { v, kind:'pdf', pages:[{pageHint, sig, descX,
      descRight, monthCols, pseudoCols}, ...] }. Continuation pages relax the
      positional minimum 3 -> 2 once a family is established.
    - CAUGHT WHILE PROBING: continuation pages were still rejected because the
      relaxed branch demanded a description column, which an overflow page never
      has (its names were printed on page 1). The strict detector already allows
      this via allowNoDesc — the relaxed branch now mirrors that rule with the
      same thresholds instead of inventing a second convention.
    - AMBIGUITY IS REFUSED, NOT GUESSED: indexed wording numbers itself, so a
      SEGMENT split merges exactly (page 0 -> 1,2; page 1 -> 3,4,5; map stored).
      Positional wording restarts at 1 on every page and the roof's page 2 even
      repeats its last column, so no numbering can be read from the bytes — that
      capture is stored with column_map NULL. The row still reaches the desk and
      PDF-4's teach supplies the numbering (the proven Excel null-map-then-teach
      path). A guess must never become an approved schedule.
    - PROBED ON THE REAL FILES: SEGMENT -> 2 pages, months 1,2 + 3,4,5, map
      stored, ambiguous=false. ROOF -> ambiguous=true, map NULL, block still
      offered for review. All 16 sample-PDF rule reads still byte-IDENTICAL.
    - Tests +3 (shape grouping unites 30/7 days; indexed split carries both pages
      with true indices; positional split yields a NULL map but still a block);
      2 existing capture fixtures updated to the map.pages shape, and the PHP
      endpoint test now posts the real two-page payload.
      JS 129 tests, 127 passed + the 2 intended blinding fails; PHP 1156 passed /
      same 7 pre-existing.

  2026-09-12 — Stage 1 BUILT (shared loader + cache fix), suites green
    (JS 116 + 2 intended blinding fails; PHP 1140 / same 7 pre-existing).
    Full entry in document_parser_pdf_ai_fallback_plan.md §11.

  2026-09-12 — Step-6 re-run PROVEN by user: native parse, TRUE curve, zero AI.
    B / F-D / F-E closed. Residual: activity names misassigned.

  2026-09-12 — Follow-ups built: in-memory memo replaces the sessionStorage
    cache; PDF AI link offered on strong reads (wording-only gate).

  2026-09-12 — P3 BUILT (code, committed; browser proof is the user's step).
    Pure-core only: no UI, no endpoints, no AI, no learning.
    - buildRows carries each row's `y` and a `named` flag.
    - matchRowsByY(): pairs an overflow page's rows with the merged rows by
      height. Tolerance = HALF the median row gap, so a neighbour can never win.
      Refuses (→ positional fallback + the old warning) on: missing y's, a tie
      inside the tolerance, two rows claiming one partner, or nothing matching.
    - mergePages: y-pairing first, positional only as fallback. A successful
      y-merge does NOT warn (alignment is known, not guessed). Unmatched NAMED
      rows are appended; unmatched NAMELESS rows are dropped as page furniture
      WITH a new spoken warning ("N unnamed value row(s) … left out").
    - detectHeaderColumns: blank/whitespace tokens skipped when computing the
      description's right edge (the second defect — see §3).
    - CAUGHT PRE-GREEN, in order, each by measurement rather than reasoning:
      (i) first cut appended the unmatched cumulative row as a phantom 5th
      activity on the 6-stage file (activities 4 → 5); (ii) dropping it instead
      silently lost a real 240,000 (contract 1,200,305 → 960,305) — which is
      what exposed defect (2): the row it should have matched did not exist
      because its name had been cut off. Fixing the desc bound made both
      symptoms vanish at once. Lesson banked: when a merge rule needs an
      exception, suspect the data feeding it before trusting the exception.
    - MEASURED BEFORE/AFTER, every S-curve sample PDF (real files through the
      real core in node with node_modules/pdfjs-dist):
        SEGMENT   : curve unchanged (5/20/40/65/100) · names M3-M5 FIXED
                    (Concrete / Finishing / Finishing) · warning gone · contract
                    1,000,000 ✓
        6-stage   : curve unchanged (5/20/40/60/80/100) · names now REAL and the
                    per-month split matches the Excel twin exactly
                    (1,2,1,2,1,1) · contract 1,200,305 → 1,200,000 ✓
        10-month  : "19.99-style dust" GONE — now exactly
                    5/10/20/30/50/70/80/90/95/100 · names REAL with the
                    AI-matching counts (1,1,1,1,2,2,1,1,1,1) · contract
                    1,000,525 → 1,000,000 ✓
        CONSCHEDSAVINGS (both copies, the real contractor file): IDENTICAL
        Roof / scurvepdfsample2 / test_page_6: still NULL, unchanged
      All three specimens now report isWeak:false (was generic-names on two), so
      they get the optional "Cross-check with AI?" wording, not the weak one.
      summaryReference stays [] on all — unchanged, still the known P2-c gap.
    - Suites: JS 120 tests, 118 passed + the 2 intended blinding fails (+2
      fixtures: height-alignment and blank-token bound; the old "row-count drift
      warns" fixture SPLIT so the guess-warning is still proven, on a file whose
      heights genuinely do not line up). PHP 1140 passed / same 7 pre-existing.
    - NOT YET BROWSER-PROVEN: re-import the three specimens and confirm the
      month lists show the real names. Node ran the same pure core the browser
      loads, so the risk is low, but DOM proof is the standing rule.

  2026-09-12 — P3 BROWSER-PROVEN + parity sweep (user; full record in
  pdf_ai_loop_proof_tests.md Script 4). 6-stage PDF recognized on FIRST import
  with real names and NO import note; 10-month PDF exact with the dust gone;
  SEGMENT PDF rule re-read identical to the AI after approving one word. Two
  pre-existing gaps surfaced (both Excel-side, neither a regression): the Excel
  AI prompt never asks for activity names (GAP 1), and a file whose header the
  detector cannot find gets every row tagged [DATA], so the AI has no headerRow
  to report and E1-B declines with "no-header-row" (GAP 2).

  2026-09-12 — GAP 1 + GAP 2 FIXED (user-ordered; full notes in
  pdf_ai_loop_proof_tests.md Script 4). Both are Excel-side AI defects the parity
  sweep exposed; neither touches the PDF reader.
    - GAP 1: the Excel AI prompt now asks for per-month "activities", and
      validateSCurveMonths runs them through the SAME printed-words sanitizer the
      PDF path uses, checked against the CSV slice. AI-assisted Excel imports now
      carry real names instead of bare percentages. Adopted-summary curves stay
      nameless by design. +2 service tests.
    - GAP 2: an absent headerRow no longer kills E1-B. inferHeaderRowKey() finds
      the label row from real cells at the columns the AI named (text in the desc
      column and ≥2 month columns; data rows fail because their month cells hold
      amounts), and the prompt now tells the model a DATA-tagged row can still be
      the header. Refusal stays honest when nothing qualifies
      ('header-row-unfindable'); an inferred map equals the explicit one. +2 tests.
    - Suites: JS 120/118 + 2 intended blinding fails (untouched); PHP 1144 passed
      (+4) / same 7 pre-existing.
    - GAP 1 NEEDED A SECOND PASS: the prompt route failed twice (live log
      monthsWithActivities:0 even after the worked example was corrected). Fixed by
      having the SERVER read the sheet with the layout the model DOES report —
      parseWithAiLayout() + applyDerivedLayout(). See pdf_ai_loop_proof_tests.md.
      CORRECTION 2026-09-12 (same day): the prompt route failed because the Excel
      responseSchema never DECLARED "activities" — structured output drops
      undeclared keys, so it was impossible, not refused. The schema now declares
      it (optional, months only). The earlier "the model won't emit it" reading
      recorded here and in commit ac9583b was wrong; full account in the proof file.
    - DERIVED MATH added with it (user decision): when that map's math differs from
      the AI's by >1.0 the sheet wins, gated on a real denominator + full month
      coverage. Caught by the tests: without the gate a degenerate 0/0/100 curve
      would have replaced a good AI answer on files with no contract column.
    - MODEL PROVENANCE recorded: parser is gemini-3.7-flash (E3 had pinned
      gemini-2.5-flash); user chose to stay, since the cross-check backstops it.
    - BROWSER-PROVEN 2026-09-12 (user, 10-month Excel): names on every month AND
      the true curve 5/10/20/30/50/70/80/90/95/100 with all four summary warnings.
      An earlier run of the same file returned 40/60/70 and passed both homework
      guards — that is the run the derived-map cross-check now catches.

  RE-SCOPE NOTE for Stage 2 (raised 2026-09-12, answered: KEEP ALL FOUR PHASES): with P3 landed, the
  offline PDF reader now matches the AI on all three specimens, so E1-PDF's value
  has narrowed to files the rules cannot detect AT ALL (the Roof family, which
  still returns null). USER DECISION 2026-09-12: build ALL FOUR PHASES anyway
  (PDF-1 capture, PDF-2 consult, PDF-3 learn-from-AI, PDF-4 point-and-teach).
  Carry the GAP 2 lesson into PDF-1: a capture whose header the rules could not
  find must still be able to learn, so PDF capture derives its candidate map from
  the page's own geometry rather than depending on rule-side header detection.

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

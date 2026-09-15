# PDF S-CURVE: DELIVERY FIXES + LAYOUT LEARNING (E1-PDF)
# Parent refs: textfiles/document_parser_pdf_ai_fallback_plan.md (F-A..F-D + B — the PDF AI track)
#              textfiles/document_parser_excel_learning_loop_plan.md (E1/E1-3/Phase-A — the loop being mirrored)
#              textfiles/pdf_ai_loop_proof_tests.md (all live proof results)
# Date: September 12, 2026 (last updated September 13, 2026 — PDF-4 Stage 2 built, uncommitted)
# Status: STAGE 1 ✅ · P3 ✅ · PDF-1 ✅ · PDF-2 ✅ — capture AND consult BROWSER-PROVEN 2026-09-12 (channels isolated)
#         PDF-3 ✅ · §6 SUMMARY-ROW ADOPTION ✅ BROWSER-PROVEN 2026-09-13 on both models
#         (roof reads its TRUE curve offline, zero AI)
#         PDF-4 ✅ teach-path BUG FIX (committed 959f0ec) + ✅ STAGE 2 BUILT 2026-09-13
#         but UNCOMMITTED — the teach grid now pictures the real table, merges the
#         printed pages into one, keeps the summary rows and badges the CUMULATIVE
#         one, and multi-page teaching works. Own file, read it before this one:
#         textfiles/document_parser_pdf_teach_grid_plan.md  (its §0 lists every
#         uncommitted file). NOT browser-proven — the user runs that test.
#         P5 AI-vs-file cross-check ✅ BROWSER-PROVEN 2026-09-13 (caught gemini 3.6 live) — §9
#         P6 MONTH-7 CHART-AXIS FIX ✅ BROWSER-PROVEN 2026-09-13 (committed 7c6044b;
#         M7 5→3) — the roof's last known offline defect is closed
#         E4 + E4b PARSER FALLBACK LADDER ✅ BROWSER-PROVEN 2026-09-13 by the user's
#         own laravel.log (3.7 answered after latest 503d twice) — committed 015f8fe/fe3d8a6
#         E5 TIME BUDGET ✅ BROWSER-PROVEN 2026-09-13 (committed 3a77917) — 39s and an
#         honest stop where the previous run died at 74s on PHP's uncatchable fatal
#         E6 PER-PATH TIMEOUT + RUNG PROMOTION ✅ BROWSER-PROVEN 2026-09-13 (1b23422) —
#         promotion gave 3.7 two attempts on a per-day 429, 15s rungs, FULL ladder reached
#         · next work = textfiles/document_parser_pdf_teach_grid_plan.md · 15s images /
#         30s text (Excel uploads a 15MB workbook), and the first rung inherits the
#         primary's attempts when the primary is out for the DAY; see §9
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
    - PDF-2 (consult) BUILT and BROWSER-PROVEN 2026-09-12 with the channels
      isolated (all month words left "proposed", only the layout live): the map
      fired, announced itself, and its SECOND page supplied months 3,4,5 — the
      column-split case. An earlier run that looked like proof was retracted; see
      both entries in the session log.
    - PDF-3 (learn-from-AI) BUILT, and its browser-fatal stash defect FIXED
      2026-09-13 (uncommitted): the stash shape had no y and no width, so the
      derivation returned null on every file and the harvest could never have run.
      Now byte-identical to raw pdf.js items on the real SEGMENT PDF. Forensics,
      fix and fixtures: §9 (2026-09-13).
      §6 has since landed, so PDF-3 is now provable: its self-check PASSES on the
      roof and places all 7 months. Prove it on the ROOF, never on SEGMENT
      (SEGMENT no-ops by design — its ai-derived block hashes identical to its
      auto-capture row, which already holds a map).
    - PDF-4 (teach) NOT started.
    - The ROOF family is now fully diagnosed (§6). Short version: the roof Excel
      already parses to the exact truth curve because it reads the workbook's
      HIDDEN columns AJ:AS; printing to PDF drops those columns, so the PDF keeps
      only per-activity time fractions that cannot be summed. The printed
      cumulative row still holds the true curve, and PDF-3's own AI-derived map
      lands on it exactly — so the roof has a zero-AI path after all. It needs one
      ported rule, not a new idea.

  ORDER OF WORK: ~~PDF-3 stash~~ DONE · ~~summary-row fallback (§6)~~ BUILT and
  BROWSER-PROVEN 2026-09-13 · ~~P5 cross-check~~ BUILT + BROWSER-PROVEN 2026-09-13
  (UNCOMMITTED — see below) →
    1. ~~P6 MONTH-7 CHART-AXIS FIX~~ ✅ BUILT **and BROWSER-PROVEN** 2026-09-13
       (uncommitted). M7 reads 3 activities matching the AI; curve, curveSource,
       months 1-6 and all 16 sample reads unchanged; JS 152/150 + the 2 intended
       blinding fails; PHP 1158 / same 7.
    2. ~~Parser FALLBACK CHAIN~~ ✅ BUILT 2026-09-13 as E4, EXTENDED the same day
       by E4b to the Excel S-curve path and to normal-doc ahead of its Lite rescue
       (see §9 — E4 alone reached only the PDF path). Opt-in via
       AI_PARSER_FALLBACK_MODELS, empty by default, Lite never reachable; the
       provenance note now names the rung that answered. PHP 1164 / same 7.
       NOT browser-proven — it only fires on a real per-model quota/overload.
       The restudy findings that shaped it, kept for the record:
       backupModel() resolves to config('services.gemini.model') = AI_MODEL = Lite,
       so callGeminiWithImages(..., true) on the PDF path would fall straight back
       to the model the E3 verdict excluded. Needs a PARSER-specific chain
       (AI_PARSER_FALLBACK_MODELS), not the shared backup. Two structural notes:
       the existing rescue bypasses postGemini (raw Http::post — no status log, no
       quota-id, no retry), and every backup failure rethrows the ORIGINAL
       exception (:1697/:1704/:1710) — that IS the transport-failure discipline the
       Lite revert was about, and a chain must preserve it. Flipping it also flips
       two tests that pin today's behaviour: GeminiDocumentParserServiceTest
       ::test_pdf_slice_never_falls_back_to_lite (:1241) and SCurvePdfGeminiTest
       ::test_no_lite_rescue_on_sustained_overload (:187).
    3. EARLY-100 GUARD (own order needed). Restudy finding that changes its design:
       a server-side refusal in validatePdfCurve would PREEMPT the P5 cross-check,
       which runs CLIENT-side after a 200 — so on the roof the exact 3.5 answer P5
       now corrects would become "could not read", strictly worse than today. It
       must either live client-side after the cross-check has had its chance, or be
       a server-side WARNING the cross-check can supersede. Second finding:
       sanitizeCurvePoints force-last-100 (:1176) MANUFACTURES repeats, so the guard
       must read pre-sanitizer values — the extractRawPoints idiom at :582 exists
       for exactly this reason.
    4. PDF-4 TEACH-PATH BUG FIX (ordered 2026-09-13). Gate teachColumns by
       source_type: on a pdf row build a genuine PDF-shaped map from the stored
       pseudoCols or refuse honestly — never write an Excel map the reader will
       ignore. Stop mode=replace destroying a working PDF map. Stop the desk
       prefilling and summarising from the Excel shape. The FULL point-and-teach
       (ambiguous-capture geometry, null-map builder, multi-page grid) stays
       PARKED — §9 2026-09-13 is its build sheet.
  Each proved before the next, each needing an explicit user order (Rule 10).

  COMMITTED 2026-09-13 (pushed): 7c6044b = P5 cross-check + P6 chart-axis fix +
  the AGENTS.md handoff rewrite + proof-file Script 6. 015f8fe = E4 parser ladder.
  COMMITTED: 015f8fe = E4 ladder · fe3d8a6 = E4b (all parser paths).
  ALL COMMITTED AND PUSHED: 7c6044b = P5 + P6 · 015f8fe = E4 ladder · fe3d8a6 =
  E4b (all parser paths) · 3a77917 = E5 time budget · 1b23422 = E6 per-path
  timeout + rung promotion. Nothing in this track is waiting in the tree.

  CONFIG ACTION RECOMMENDED, NO CODE: set AI_PARSER_MODEL=gemini-3.7-flash and
  demote/drop the gemini-flash-latest ALIAS. Measured over four live runs, latest
  answered 0 of 8 attempts while 3.7 answered 2 of 4 — an alias carries every
  caller who did not pin a version, so it saturates first.

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
    npm run test:js          → expect 136 tests, 134 passed + 2 intended blinding fails
    php artisan test         → expect 1158 passed / 7 pre-existing failures
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

  BUILT: three pure-core changes (see the §9 session log for the full account and
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
  4. STAGE 2 — E1-PDF LAYOUT LEARNING
     PDF-1 ✅ · PDF-2 ✅ · PDF-3 ⚠️ BUILT, DEAD IN BROWSER (§9) · PDF-4 ⬜
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

  PDF-3 — LEARN-FROM-AI (PDF twin of E1-B)  🔄 BUILT (uncommitted); the stash defect
          that made it return null on every file is FIXED 2026-09-13 and covered by
          two fixtures. Browser proof is blocked on §6, not on the code. The spec
          below is unchanged and still accurate.
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
    ⚠️ RESTUDIED 2026-09-13 — READ THE §9 ENTRY OF THAT DATE BEFORE THIS SECTION.
    The spec below is still structurally sound, but it did not know three things,
    and they change both the scope and the order:
      (a) teaching a PDF row is NOT unbuilt — it is WIRED AND BROKEN. The Teach
          button renders on PDF rows and teachColumns has no source_type branch, so
          it writes an EXCEL-shaped map onto a pdf row, which the reader then
          ignores silently; mode=replace destroys a working PDF map on the way.
          That bug is the ONLY part the user ordered built (2026-09-13).
      (b) an AMBIGUOUS capture stores NO pseudoCols (core :1736-1746 discards them
          with the null map), so the rows this section exists to teach carry no
          coordinates at all. Retaining that geometry is a PREREQUISITE, not a
          detail.
      (c) measured across every sample PDF, the only file in the PDF-4 class is the
          ROOF — and the roof now reads its true curve OFFLINE with zero AI (§6).
          The unreadable files produce no capture row at all. On today's evidence
          full point-and-teach has no file to serve and would need a purpose-built
          specimen for acceptance.
    STATUS: the bug fix is ordered; the rest is PARKED with the §9 restudy as its
    build sheet.

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
  6. THE ROOF FAMILY — WHY EXCEL READS IT AND PDF CANNOT  (diagnosed 2026-09-13)
===============================================================================

  Read this before spending another hour on "why is the roof wrong". The answer
  is not in the parser. It is in the file. Everything below is a read-only probe
  on the real specimens; scripts in C:\temp\opencode.

  THE ROOF EXCEL IS ALREADY PERFECT, TODAY
    sampledocs/CSPC-Rehabilitation-of-Roof-at-Academic-Building-II-S-CURVE.xlsx
    through the real SCurveImportService::parse():
      curve         6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100  <- the TRUTH
      curveSource   activities        contractAmount  2,239,599.72
      activityCount 5                 summaryReference gap = 0.00 on ALL 7 months
    Per-month activity MEMBERSHIP is right too (M5 = 3 activities, M7 = 3, matching
    exactly the non-zero cells of the AQ and AS columns), which is only possible if
    the real numbers were read. Nothing about the roof is broken on the Excel side.

  WHY — IT READS THE WORKBOOK'S HIDDEN COLUMNS
    The workbook hides columns AJ:AS (verified via column dimensions). They hold
    the ONLY correct arithmetic in the file:
      AM19 = "1st."   AN19 = "2nd."  ...  AS19 = "7th"  <- proper ordinal month headers
      AM21 = J21 * F22    <- the month's time fraction x THAT ACTIVITY's contract
                             amount, which sits on the row BELOW the fractions
      AM31 = SUM(AM21:AM30) = 141,466.26   ->   G32 = AM31/F31 = 6.32%
      G33  = G32 ;  K33 = K32+G33 ;  ...                <- the cumulative curve
    SCurveImportService already targets exactly this — see the comment at
    SCurveImportService.php:1273 ("ordinal month labels ... roof family AM:AS") and
    the roof-family contract placement rule at :1412 (a labelled section row carries
    the month formulas while its subtotal sits on the FOLLOWING desc-less row).

  WHAT THE VISIBLE GRID ACTUALLY CONTAINS (and why summing it is meaningless)
    The cells a human sees — and the ONLY ones the print carries — are per-activity
    TIME FRACTIONS over DIFFERENT denominators:
      A  FACILITIES FOR ENGINEER   =30/187 =31/187 =31/187 =30/187 =31/187 =30/187
      C  EARTHWORK                 =23/115 =31/115 =31/115 =30/115 =0/115  =0/4
      H  OTHER WORKS               =0/4    =0/4    =0/4    =0/4    =0/4    =30/34
    Each activity's own row sums to 1 (hidden AL21 = AG21+AD21+Z21+V21+R21+N21+J21
    -> 1). They are NOT percentages of the project and NOT on a common scale, so
    "16% + 16% + 20% + 6% + 0%" is arithmetic on incompatible units. That is the
    8.21 / 39.77 / 100 / 100 read from the PDF-3 probe, fully explained.

  THEREFORE: THE PDF EXPORT IS LOSSY, NOT MISREAD  (user's read, confirmed)
    Printing to PDF keeps the visible area only, so AJ:AS never reach the page. The
    export drops the ordinal month headers AND the cost-weighted amounts in one
    step, and keeps only the fractions. No amount of column-map learning recovers a
    column that was never printed. This is why the roof is the one family where
    "find the columns" and "read the table" come apart.

  BUT THE ANSWER IS STILL PRINTED — ON THE SUMMARY ROW
    The export DOES carry rows 32/33. Measured on page 1 of the PDF:
      y=281.8  [%]  6.32 17.99 17.99 17.41 17.31 22.11   ascending=false  (row 32)
      y=272.8  [%]  6.32 24.31 42.30 59.71 77.03 99.14   ascending=TRUE   (row 33)
    and page 2 continues 99.14 -> 100.00 under the "7 days" tail column.
    isSummaryLabel already matches the bare "%" token sitting at x=294.9 on that
    same line, and cumulative separates from per-month by monotonicity alone.
    (The word "Commulative" is NOT usable as the handle: it sits at y=268.3 while
    its values sit at y=272.9, and Y_TOLERANCE is 2. Use the "%" token.)

    PROVEN 2026-09-13 (read-only, real file, real core): feed PDF-3's AI-derived
    map to the page and the printed cumulative row lands EXACTLY on the bands —
      page 0  bands M1[338-421] M2[421-505] M3[505-588] M4[588-672] M5[672-755] M6[755-796]
              row y=272.8 -> M1=6.32  M2=24.31  M3=42.30  M4=59.71  M5=77.03  M6=99.14
      page 1  band  M7[116-188]
              row y=272.9 -> M7=100.00
    => AI-derived map + a summary-row fallback = the exact truth curve, ZERO AI at
       read time. The roof does NOT have to stay AI-only.

  THE MISSING PIECE IS A PORT, NOT AN INVENTION
    The Excel reader has three rules. The PDF core has only the first two:
      1. activity math WINS while it exists       Excel OK   PDF OK   (core :813)
      2. summary = reference + warn past 1.0      Excel OK   PDF OK   (core :818)
      3. summary ADOPTED as the curve when        Excel OK   PDF MISSING  <- the gap
         activity math is ABSENT — flagged,      (SCurveImportService.php:1606)
         never silent, monotonic-clamped,
         jumble-guarded, curveSource='summary'
    Excel rule 3 fires only on lastNonZero === 0 with >= 2 reference points. It is
    explicitly NOT triggered by disagreement ("Disagreement never triggers this",
    :1601). Porting rule 3 to the PDF core is the cheapest correct fix and reuses a
    design already browser-proven on the Excel side.

  KNOWN WRINKLE FOR WHOEVER BUILDS IT
    The CONTINUATION page's summary row has NO label — page 2 y=272.9 is a bare
    "99.14%  100.00%", because the label column was printed on page 1 only. It must
    be paired by Y, exactly as P3's matchRowsByY already pairs activity rows. Do NOT
    require a label on every page, or the 7th month is lost again — the same defect
    class the user caught when the roof's "7 days" column exposed single-page capture.

  CONSEQUENCE FOR PDF-3 (this is the part that changes the plan)
    PDF-3's self-check currently REFUSES the roof, and that refusal is CORRECT:
    today's reader genuinely cannot reproduce the AI curve from that map. But it is
    not permanent. Add rule 3 and the SAME AI-derived map reproduces the AI's curve,
    the self-check passes, and the roof teaches itself. Order matters:
      summary-row fallback FIRST, then the roof becomes teachable for free.
    Building PDF-4 teach for the roof BEFORE the fallback would produce an approved
    layout that still reads the table wrong — exactly what the self-check exists to
    prevent.

  IS SUMMARY-vs-CALCULATION DISAGREEMENT RARE?  MEASURED 2026-09-13
    ROOF  gap 0.00 on all 7 months                        -> perfect agreement
    BUHI  gaps 0.21 / 0.63 / 1.49 / 3.00 (M3/M6/M9/M12)   -> the documented exception:
          its summary tracks ACCOMPLISHED % against the PRE-DISCOUNT bid, i.e. a
          different divisor, so the two can never agree
    Both facts are needed, and they point in opposite directions on purpose:
    agreement is common enough that the summary is a SAFE FALLBACK when there is no
    activity math, and disagreement is real enough (BUHI) that the summary must
    never REPLACE activity math that exists. That is precisely rules 1-3 above, and
    it is the user's stated model of the system — confirmed correct 2026-09-13.

===============================================================================
  7. DECISION LOG
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
  8. FLAGGED FOR FUTURE (unordered — each needs an explicit user order)
===============================================================================

  - ~~PDF SUMMARY-ROW FALLBACK~~ ✅ BUILT + BROWSER-PROVEN 2026-09-13 on both models
    (§9). The roof reads its TRUE curve offline, zero AI, curveSource 'summary'.
    Kept here only as a pointer: the design rationale, the three upstream defects it
    exposed, and the rejected alternatives are in the §9 entry of that date.
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
  - Parked: Tier-2 OCR for S-curve PDFs, 2c, P1/P2 passive mining + lifetime counting.
    (“Rule family A (Roof-style)” is RETIRED as a park — §6 shows the roof needs the
    summary fallback above, not a bespoke geometry family.)

===============================================================================
  9. SESSION LOGS
===============================================================================

  2026-09-15 — P3 MERGE MEETS A PRINT IT WAS NOT BUILT FOR: THE BUHI PDF (split DOWN
  and ACROSS). Diagnosis only, no code. Full record: textfiles/
  document_parser_pdf_teach_grid_plan.md, the last §13 entry (finding C). Pointer here
  because mergePages / merge-by-Y (§3) and consult (PDF-2) are specified in this file.
    The printed BUHI workbook is a 12-page grid: left block (names, TOTAL, cumulative
    row) on p1-p3 with NO month header; months on p4-p12 in three column groups, each
    continuing down three pages. The reader recognises only p4/p7/p10 and reads a
    wrong 12-month curve (M4 29.47 vs truth 10.59, contract 11.5M vs 32.6M, every name
    "Activity N") with one weak positional warning. P3's column-split pairing assumes
    every table page carries its own month header or is an across-continuation; this
    print breaks both.
    USER DECISION: full support PARKED (revive only if the PMU receives large schedules
    as PDF only). ORDERED: a guard so this shape stops reading as a confident baseline.
    Design notes + hard constraints in the teach-grid plan entry.

  2026-09-14 (late) — PDF-2 CONSULT HARDENED: A STORED LAYOUT MUST FIT THE PAGE'S
  OWN LABELS. Full record: textfiles/document_parser_pdf_teach_grid_plan.md, the last
  §13 entry. Pointer here because PDF-2 (matchMapPage) is specified in this file.
    SYMPTOM (user): with an old approved roof layout live (DB #116), the roof PDF
    read 6.32 / 24.31 / 42.30 / 42.30 / 59.71 / 77.03 / 100 "with a learned layout".
    CAUSE: #116 stored ONE page with SEVEN equal bands; the print has SIX "30 days"
    labels on page one (months 6-7 are on page two). The signature matched on
    wording ("30 days" everywhere) and numbers sat under enough bands, so it applied.
    FIX: matchMapPage now also requires bandsHoldLabels — every stored month band
    contains the centre of a letter-bearing token on the header line or the line
    below. Every band of a layout that fits holds its own label; a misfit leaves
    the file unread (capture + AI offer) instead of reading it wrong.
    CORRECTION TO THIS FILE: the PDF-2 BUILT entry below says "usableLayoutMap()
    gates version, kind AND SHAPE". The code never had a shape gate — only version,
    kind and a non-empty page list. The docblock that made the same claim is fixed;
    bandsHoldLabels is what now refuses geometry that does not fit.
    VERIFIED: +1 JS test (falsified); verify_p6 16 reads 0 changed; 9 sample blocks
    0 changed; the user's taught roof layout (#123) still reads the true curve.

  2026-09-13 — E6 ✅ BROWSER-PROVEN by the user's log, BOTH halves at once, on a real
  per-day quota. The strongest single run this track has produced for the AI ladder.
    19:13:00  latest  429  quota=GenerateRequestsPerDayPerProjectPerModel-FreeTier
    19:13:00  E6 parser rung promoted — primary is out for the day  to=3.7  reason=per-day-quota
    19:13:00  E4 parser fallback  to=gemini-3.7-flash
    19:13:05  3.7  503     <- promoted attempt 1
    19:13:16  3.7  503     <- promoted attempt 2  (PROMOTION, working)
    19:13:16  E4 parser fallback  to=gemini-3.6-flash
    19:13:31  3.6  throwable      <- 15s exactly  (PER-PATH TIMEOUT, working)
    19:13:31  E4 parser fallback  to=gemini-3.5-flash
    19:13:46  3.5  throwable      <- 15s exactly
    THREE things proven in one run:
      - PROMOTION fired on a genuine per-day 429 and gave the first rung TWO attempts,
        exactly as designed — and only the first rung, as the later ones took one each;
      - the 15s image timeout is live: 3.6 and 3.5 each cost 15s where they cost 30
        before, and the log timestamps are exact;
      - THE FULL LADDER WAS REACHED (3.7, 3.6, 3.5). The 15:12 run under E5 alone
        stopped after 3.6 with "time budget spent, skipped gemini-3.5-flash". Buying
        that depth is precisely what E6 was for.
    Total 19:13:00 -> 19:13:46 = 46s of a 50s budget, no PHP fatal.

    USER QUESTION, ANSWERED AND DECIDED: "when it reaches the last model and still has
    the time budget, it does not repeat." Correct, and correct behaviour — ~4s
    remained against a 15s slot, so nothing could start. More generally a SECOND PASS
    CANNOT FIT: a full walk costs ~46s, the budget is capped at min(config,
    max_execution_time - 10) = 50s, and PHP's ceiling is 60s. Looping would require
    raising max_execution_time and would leave a user watching a spinner for two
    minutes for an answer four models have already declined. NOT BUILT, by decision.

  2026-09-13 — NEXT WORK MOVES TO ITS OWN FILE:
    textfiles/document_parser_pdf_teach_grid_plan.md (PDF-4 Stage 2 — make the teach
    grid an accurate picture of the table, merge the printed pages into one grid, keep
    the summary rows and the chart furniture out, and redesign the modal layout).
    Approved by the user 2026-09-13. The measurement that justifies it — the roof's
    real column x-positions versus the single-line bands we draw — is in that file's
    §1, with the probe at C:\temp\opencode\probe_gridfidelity.mjs.

  2026-09-13 — PDF-4 TEACH-PATH BUG FIX ✅ BUILT (code, UNCOMMITTED per user).
  The narrowed scope from the restudy entry below. Nothing speculative was added:
  the parked full point-and-teach is untouched.

    WHAT WAS BROKEN (see the restudy entry for how it was found)
      The desk offers Teach on every row and teachColumns had no source_type
      branch, so a taught PDF row was handed an EXCEL map ({v, sig, cols}). The PDF
      reader ignores it outright — usableLayoutMap gates on kind === 'pdf' — so the
      reviewer saw "saved" and nothing ever changed. mode=replace wrote FIRST, so
      teaching a row that already held a working PDF map DESTROYED it and dropped
      the row to proposed, unwarned.

    WHAT SHIPPED
      SCurveHeaderProposalController:
        - teachColumns branches on source_type. The PDF branch runs BEFORE any
          write, so a refusal can no longer cost a good map.
        - storeTaughtMap() extracted, shared by both branches. Variant-creates-a-
          sibling, replace-drops-to-proposed and approval-is-the-only-live-flip now
          have ONE definition and cannot drift between Excel and PDF.
        - buildTaughtPdfMap(): letters -> bands via the capture's own pseudoCols
          ([{letter, x, xEnd}] as page-width fractions). Two letters on one month
          widen that month to span both. The page's identity (sig, pageHint,
          lineHint, pseudoCols, headerLine) is KEPT and only descX/descRight/
          monthCols are replaced, so the taught map still matches its own page.
          The version is INHERITED from the capture rather than invented — the
          reader gates on an exact match, so a taught map must age with its source.

      REFUSES, each before any write, each with a reviewer-facing reason:
        - no recorded geometry (an AMBIGUOUS capture stores column_map NULL and
          pseudoCols goes with it — letters without coordinates cannot become bands);
        - MULTI-PAGE layouts: the grid renders the first table page only, so a
          reviewer cannot see the months on later pages and saving what they CAN
          see would teach a SHORT schedule. Refused until the grid can show them
          all — that grid work is the parked half of PDF-4;
        - geometry that is not a table: fewer than two months, months not running
          left to right without overlap, or a description column sitting inside a
          month band. Same spirit as buildTaughtMap's gates on the Excel side.

      DESK (_manage_scurve_headers_modal.blade.php):
        - the teach-modal PREFILL reads the PDF shape. It was reading cm.descCol /
          cm.monthSpans, which a PDF map does not have, so PDF rows opened with an
          empty palette inside a best-effort try/catch. It now walks the recorded
          pseudoCols back to letters — the same bridge the server uses, in reverse.
        - the list's inline MAP SUMMARY had the same blindness and showed nothing
          for PDF rows. It now describes what a PDF map actually says: how many
          printed pages it covers and which months each carries.
        - a row the server will refuse says so UP FRONT and disables Save, instead
          of letting the reviewer assign every column and only then meet a refusal.
          The server refuses independently — this only saves the wasted effort.

    A DELIBERATE NON-CHANGE, because it looks like an omission: the header-row
    picker does NOT re-key a PDF map. sig is matched against individual pdf.js
    TOKENS, and a token may CONTAIN A SPACE — the roof's "30 days" is one token.
    Rebuilding sig by splitting the stored cell text would shatter such tokens and
    the map would stop matching its own page. The capture already recorded which
    printed line is the header and its exact tokens, so the taught map keeps them.
    Written down because the next person to look will wonder why the picker seems
    ignored on PDFs.

    TESTS +7 (ScurveHeaderProposalTest 49 -> 56), each pinning a decision:
      - teaching a PDF row produces a PDF-SHAPED map, with the exact bands the
        capture recorded, sig and pseudoCols preserved, and NO Excel 'cols' key —
        this is the bug, under test;
      - a month taught two columns spans both;
      - a multi-page layout refuses AND the original map is byte-identical
        afterwards, with no variant created — the destructive half, under test;
      - a row with no recorded positions refuses honestly;
      - overlapping months and a description inside a month band are both refused,
        and neither saves anything;
      - an unknown letter is refused;
      - EXCEL teaching still produces an Excel map — the new branch is gated and
        the majority path is untouched.

    SUITES: PHP 1184 passed (+7) / the same 7 pre-existing failures BY NAME.
    JS 152 tests, 150 passed + the 2 intended blinding fails — unchanged; no JS
    file was touched (the desk edit is Blade-inline, extracted and node --check'd
    clean, and view:cache rebuilt).

    NOT BROWSER-PROVEN, and the live data makes both paths reachable: row #69 is a
    SINGLE-page pdf capture (9 pseudoCols) and should now teach into a PDF-shaped
    map; row #70 is TWO pages and should refuse with the multi-page message while
    leaving its map untouched. Check the desk also shows a map summary on both
    ("page 1: M1–M7" / "page 1: … · page 2: …"), where PDF rows previously showed
    none.

    STILL PARKED, unchanged: retaining pseudoCols on ambiguous captures, a builder
    for null-map rows, and multi-page grid rendering. The restudy entry below is
    their build sheet.

  2026-09-13 — PDF-4 RESTUDY (read-only, no code). User ordered PDF-4, then
  narrowed it to the bug fix on the findings below. THREE things the spec in §4
  did not know, and one of them is a live destructive bug.

    (1) TEACHING A PDF ROW IS NOT BLOCKED — IT IS BROKEN, AND 'replace' DESTROYS.
      The desk renders the Teach button unconditionally
      (_manage_scurve_headers_modal.blade.php:335 — no source_type check), and
      teachColumns (SCurveHeaderProposalController:262) has no source_type branch
      either. On a PDF row it therefore:
        - parses the block fine (the PDF block is byte-compatible with the Excel
          one, which is the good news below), then
        - converts letters to 0-based COLUMN INDICES, then
        - calls buildTaughtMap() — the EXCEL builder — and writes an Excel-shaped
          {v, sig, cols:{...}} map onto a source_type='pdf' row.
      usableLayoutMap() gates on kind === 'pdf' (core :1351), so the PDF reader
      then IGNORES that map silently: the reviewer teaches, sees "saved", and
      nothing ever changes. Worse, mode=replace overwrites column_map on the row
      FIRST, so teaching a row that already held a working PDF map DESTROYS it and
      drops the row to proposed. Nothing warns.
      The desk's prefill has the mirror of the same defect: schTeachColumns reads
      cm.descCol / cm.monthSpans (:487-493), which a PDF map does not have — it
      carries pages[].descX / pages[].monthCols — so the prefill silently yields
      nothing inside a best-effort try/catch. The LIST's inline map summary
      (:312-322) reads the same Excel keys, which is why PDF rows show no summary.

    (2) THE CASE PDF-4 TARGETS HAS ALREADY THROWN AWAY WHAT IT NEEDS.
      A teach branch converts a taught LETTER back into an x-band, and the lookup
      table for that is pseudoCols — [{letter, x, xEnd}] as fractions of pageMaxX,
      built at core :1689. It is stored INSIDE the map.
      But an AMBIGUOUS capture (positional wording — the roof's repeated "30 days",
      where nothing in the bytes says which month a column is) returns map: null
      and DISCARDS the whole `found` array, pseudoCols with it (core :1736-1746).
      Only the text block reaches the database.
      So the rows PDF-4 exists to teach are exactly the rows that carry letters and
      cell text but NO COORDINATES AT ALL. Confirmed against the live DB: the two
      pdf rows present (#69 auto-capture live, #70 ai-derived proposed) both HAVE
      maps and pseudoCols — neither is a teachable null-map row.
      ⇒ Retaining geometry on the ambiguous path is a PREREQUISITE the §4 spec does
        not mention. Full PDF-4 cannot start without it.

    (3) MEASURED: PDF-4 CURRENTLY HELPS NO FILE IN THE CORPUS.
      Every sample PDF through the real capture path (probe_pdf4.mjs):
        CONSCHEDSAVINGS x2   rules READ   capture: map, 30 pseudoCols
        10-month             rules READ   capture: map, 2 pages
        6-stage              rules READ   capture: map, 2 pages
        SEGMENT              rules MISS   capture: map, 2 pages  -> PDF-2 consult
                                          handles it after approval; no teaching
        ROOF                 rules MISS   capture: AMBIGUOUS -> map NULL  <- the
                                          ONLY PDF-4 case in the corpus
        scurvepdfsample2     rules MISS   capture: NO CANDIDATE AT ALL
        test_page_6          rules MISS   capture: NO CANDIDATE AT ALL
      The one file in the PDF-4 class is the roof — and the roof no longer needs
      teaching, because §6's summary-row adoption reads its TRUE curve offline with
      zero AI. The two files that genuinely cannot be read produce no capture row,
      so teaching cannot reach them either; they need Tier-2 OCR, which is parked.
      This is a further narrowing than §0 already recorded ("value has narrowed").
      It is not "narrow" — on today's evidence it is EMPTY, and full PDF-4 would
      have to be accepted against a specimen built for the purpose.

    ALSO FOUND, carried for whoever builds the full version: the desk grid is
    PAGE-1 ONLY, while PDF maps are routinely multi-page (roof 2, SEGMENT 2,
    10-month 2, 6-stage 2). A reviewer could never assign a month that lives on
    page 2, so multi-page grid rendering is load-bearing for real teaching, not
    cosmetic. Already noted in the 2026-09-12 session log; restated here because it
    belongs with the rest of the scope.

    THE GOOD NEWS, unchanged from the spec: buildPdfSliceBlock emits the SAME
    "COLUMNS: A,B,C | ..." + "R<n> [ROLE]: csv" shape the Excel slice uses, so
    schParseHeaderBlock, the mini-grid, the palette, the readout, validation and
    the variant/replace semantics all already work on PDF rows. The missing pieces
    are the map BUILDER and the geometry to feed it — not the UI.

    USER DECISION 2026-09-13: build the BUG FIX ONLY for now.
      Gate teachColumns by source_type. On a PDF row either build a genuine
      PDF-shaped map from the stored pseudoCols, or refuse with an honest message —
      never write an Excel map the reader will ignore. Stop 'replace' from
      destroying a working PDF map. Stop the desk prefilling (and summarising) from
      the wrong shape.
      Full point-and-teach — the ambiguous-capture geometry prerequisite, the PDF
      map builder for null-map rows, multi-page grid rendering — stays PARKED with
      this restudy as its build sheet, to be revived if a real file ever needs it.

  2026-09-13 — E5 ✅ BROWSER-PROVEN, and E6 BUILT on what its log measured
  (per-path request timeout + rung promotion; code, uncommitted).

    E5 PROVEN BY THE USER'S LOG. The same shape that died at 74s the run before:
      15:12:44  latest  503
      15:12:49  latest  503        (its retry)
      15:12:53  3.7     503
      15:13:23  3.6     throwable  (30s timeout)
      15:13:23  E5 parser ladder stopped — time budget spent, skipped gemini-3.5-flash
    39 seconds, NO fatal, stopped deliberately and said which rung it skipped. The
    15:14 run then completed end to end: 3.7 answered in 8s and the import notes
    carried the E4b provenance line naming it. E4 / E4b / E5 all browser-proven.

    WHAT THE SAME LOG MEASURED, which is why E6 exists
      primary + its retry   ~5s
      3.7                   ~4s
      3.6 (hung)             30s   <- 77% of the walk, on a model that never answered
    One hung connection ate the budget three other models could have used. Across
    four live runs the real answers arrived in 8s and 10s and refusals in 3-9s;
    only a HANG has ever reached 30. And because canAffordAttempt() reserves a slot
    of exactly the timeout, an oversized timeout shrinks the ladder twice over —
    once by what it spends, once by what it reserves.

    THE QUESTION THAT PROMPTED IT, AND WHY THE ANSWER WAS "NOT RETRIES"
    Asked: the primary gets 2 attempts and each rung gets 1 — should rungs get 2?
    Measured over all four runs:
      primary's retry   0 of 4 succeeded   (0 of 8 attempts, counting both tries)
      first rung (3.7)  2 of 4 succeeded
    Retrying the SAME model asks a backend that just refused, seconds later, under
    the same load; a different model has independent quota and load. For identical
    budget the rung is the better bet — and blanket rung retries would REDUCE reach,
    since the budget is already the binding constraint. Decisions (user): lower the
    timeout instead, and KEEP the primary's own retry (all four samples come from
    sustained outages, which is exactly when no retry helps — that is not evidence
    it is useless during a blip).

    "IS THE 503 REALLY A RATE LIMIT GOOGLE IS NOT REPORTING?" — NO, and the log
    settles it: every failure line carries "quota":null. extractQuotaId() parses the
    body for a QuotaFailure block and a genuine quota refusal populates it (seen
    live 2026-09-11 as GenerateRequestsPerDayPerProjectPerModel-FreeTier). 429 means
    YOUR allowance is spent; 503 means the model's serving capacity is saturated for
    everyone. Different questions, both answered honestly.
      BUT THE HUNCH ABOUT gemini-flash-latest HOLDS, for another reason: it is an
      ALIAS, so every caller who does not pin a version lands on it and it saturates
      first. Across the four runs: latest 0 of 8 attempts, gemini-3.7-flash 2 of 4.
      RECOMMENDED CONFIG ACTION (no code): set AI_PARSER_MODEL=gemini-3.7-flash and
      demote or drop latest. Three of the four runs would then have succeeded on the
      first call. E3 already banked the alias as suspect #1 for odd behaviour.

    WHAT SHIPPED — (1) PER-PATH REQUEST TIMEOUT
      DEFAULT_IMAGE_TIMEOUT 15 / DEFAULT_TEXT_TIMEOUT 30, both configurable
      (AI_PARSER_IMAGE_TIMEOUT / AI_PARSER_TEXT_TIMEOUT), clamped 5..120 so a typo
      cannot disable the guard. requestTimeout($where) selects on the tag the
      senders already pass ('images'/'text', plus '-fallback' for a rung), so no new
      per-call state was needed. Used by BOTH Http::timeout() and canAffordAttempt()
      — they must move together or the reservation stops matching the cost.
      NOT ONE NUMBER, and this is the part worth remembering: the Excel path uploads
      the NATIVE WORKBOOK inline at up to 15MB
      (SCurveImportController::FILE_INLINE_MAX_BYTES) and Guzzle's timeout covers
      the UPLOAD, so a large .xlsx on a slow connection can spend 15s on transfer
      alone. All the 8-10s evidence came from PDF image slices. Applying it to Excel
      would have risked breaking imports that work today, so text keeps 30s.
      Effect on the 15:12 run: ~5 + 4 + 15 = 24s, leaving room to actually try 3.5.

    WHAT SHIPPED — (2) RUNG PROMOTION
      Asked: once the primary is rate-limited, does the next model get the primary's
      2 attempts? It did not — postGemini(..., 1) was hardcoded for every rung. And
      on a PER-DAY 429 the primary does not get its two either: postGemini breaks at
      the isPerDayQuota check, because a day-long quota will not heal in seconds.
      The code was not distinguishing two very different situations:
        503 / 502 / 504 / throw  -> the primary may recover; the ladder is a
                                    temporary detour, one attempt per rung is right
        429 per-day, or 404      -> the primary is GONE FOR THE DAY. The first
                                    working rung is not a detour; it is the model
                                    this install is using until midnight.
      primaryIsSpentForToday() now makes that call, and the FIRST rung under it gets
      the primary's attempt budget (2). Later rungs stay at 1 — promoting all of
      them is the blanket-retry idea the measurements rate worst. Every attempt
      still passes canAffordAttempt(), so promotion cannot reintroduce the overrun
      E5 exists to stop, and it is affordable exactly when it applies: a per-day 429
      fails fast in ~3-5s, leaving most of the budget. Logged as "E6 parser rung
      promoted — primary is out for the day" with the reason.

    CAUGHT WHILE WRITING THE TESTS (mine): the first timeout fixture carried a
    leftover Http::fake closure and a $seen array it never asserted on — scaffolding
    from an approach I abandoned mid-write. Removed rather than left in; a test that
    sets up evidence it does not examine reads like a missing assertion.

    TESTS +6: each path asks for its own timeout and a rung inherits its path's;
    the value is configurable and clamped at both ends, with nonsense falling back
    to the default; the budget slot follows the same per-path value (a ~20s window
    admits an image attempt and refuses a text one); a per-day quota promotes the
    first rung (1 primary + 2 promoted); ONLY the first rung is promoted; and a 503
    promotes nothing.
    Untouched and still green: the E5 budget pair and
    test_per_day_quota_fails_fast_with_tomorrow_message.

    phpunit.xml gains AI_PARSER_IMAGE_TIMEOUT / AI_PARSER_TEXT_TIMEOUT, per the
    lesson banked one commit ago — every env-backed knob is pinned the day it is
    added, or somebody's local .env silently reshapes the suite.

    SUITES: PHP 1177 passed (+6) / the same 7 pre-existing failures BY NAME.
    JS 152 tests, 150 passed + the 2 intended blinding fails — unchanged; no JS
    touched, PDF core untouched since P6.

    NOT BROWSER-PROVEN. Two live checks, neither forceable on demand:
      - during the next 503 spell the walk should REACH gemini-3.5-flash — expect
        "E4 parser fallback ... to=gemini-3.5-flash" where 15:12 logged "E5 parser
        ladder stopped", and a hung rung costing ~15s rather than 30;
      - import a real Excel workbook through AI assist and confirm NO new timeout:
        that path deliberately keeps 30s, so this check exists to prove the split
        did not touch it.

  2026-09-13 — E4/E4b LADDER ✅ BROWSER-PROVEN BY THE USER'S OWN LOG, and the same
  log exposed a 60-SECOND FATAL. E5 (time budget) ✅ BUILT in response (uncommitted).

    THE LADDER WORKS LIVE. From storage/logs/laravel.log, the run at 14:44 is the
    proof E4/E4b was missing:
      14:44:09  E3 gemini parser call failed  model=gemini-flash-latest  status=503
      14:44:18  E3 gemini parser call failed  model=gemini-flash-latest  status=503
      14:44:18  E4 parser fallback  from=gemini-flash-latest  to=gemini-3.7-flash
      14:44:28  F-A pdf slice response keys ... months:7
    Primary exhausted its two attempts, the ladder fired, gemini-3.7-flash answered
    and the import got its seven months. The ladder is no longer theory.
    User confirmed separately that gemini-flash 3.5 through 3.8/latest are all live
    IDs on the parser key (the K3 list-call check), so the chain is real.

    AND THE SAME LOG SHOWS THE REQUEST DYING. The 14:41 run:
      14:41:14  latest  503
      14:41:22  latest  503        (its retry, +8s)
      14:41:31  3.7     503        (+9s)
      14:42:01  3.6     throwable  (+30s — the Http timeout)
      14:42:28  FATAL: Maximum execution time of 60 seconds exceeded
    74 seconds against PHP's 60. A FATAL IS NOT CATCHABLE, so the controller never
    returned its mapped 422 — the client received an HTML error page, res.json()
    fell back to {}, and the in-modal error showed the GENERIC "The AI assistant
    could not read an S-curve from these pages."
    The user was told the FILE was unreadable when the truth was that the service
    was overloaded. That is precisely the class of lie the Lite rescue was reverted
    for, arriving through a different door.

    THE ARITHMETIC SAYS IT WAS NOT BAD LUCK
      primary alone:  30 (timeout) + up to 20 (Retry-After cap) + 30  =  80s
      with 3 rungs:   80 + 3x30                                       = 170s
      PHP ceiling:                                                       60s
    Note the primary ALONE already exceeds 60, so the overflow predates the ladder
    — this is the "time-budget hardening" item that sat queued and unordered. The
    ladder simply made it likely enough to hit on the second real test.

    E5 AS BUILT (user decisions 2026-09-13: budget first, revisit later; and stop
    early rather than shrink attempts)
      - config 'parser_time_budget' (env AI_PARSER_TIME_BUDGET, default 50s).
        The effective budget is min(config, max_execution_time - 10) whenever PHP
        declares a limit, so a tighter server narrows it automatically and a
        generous config value can never make it unsafe. CLI reports 0 (unlimited),
        which is why the suite is unaffected.
      - REQUEST_TIMEOUT const (30) replaces the literal in postGemini and doubles
        as the slot canAffordAttempt() must see free.
      - canAffordAttempt() demands a WHOLE slot rather than shrinking the timeout
        to fit (user decision). A squeezed timeout can cut off a model that was
        about to answer, turning a slow success into a failure; stopping early and
        saying why is the honest trade.
      - Checked in two places: postGemini before a RETRY, and climbFallbackLadder
        before each RUNG. The primary's FIRST attempt is always allowed, or a tight
        budget would mean nothing happens at all.
      - A budget-stopped walk logs "E5 parser ladder stopped — time budget spent"
        with the rung it skipped, so a short ladder is diagnosable rather than
        looking like a chain nobody configured.

    WHAT THE USER WILL SEE NOW on a 14:41-style outage: roughly 27 seconds instead
    of a 74-second death, ending in "The AI service is busy (overloaded). Please
    wait a minute and try again." — the true reason, in the mapped voice.

    HONEST LIMITATION, FLAGGED NOT HIDDEN: reserving a full 30s slot while real
    attempts complete in ~10s (measured: successes ≈10s, 503s ≈8-9s; only a hung
    connection reaches 30) makes the ladder SHALLOWER than it needs to be. With a
    50s budget the practical depth is primary x2 + ONE rung. The 14:44 success
    still works (3.7 was rung 1); the 14:41 run would stop after 3.7 instead of
    walking to 3.6 and 3.5. The lever for more depth is REQUEST_TIMEOUT, not the
    budget — the budget is capped by PHP. Lowering it to ~20s would buy a second
    rung inside the same window. NOT DONE: it is beyond the approved scope and
    wants its own measurement of how long a real answer takes.

    CAUGHT WHILE TESTING — AND IT IS THE IMPORTANT FIND OF THIS ENTRY.
    Three unrelated tests suddenly failed: "images fallback to lite on quota"
    expected lastFallbackModel 'lite-model' and got 'gemini-3.7-flash'. Nothing was
    wrong with the code. The user had configured AI_PARSER_FALLBACK_MODELS in the
    real .env, and every test that did not explicitly override
    services.gemini.parser_fallback_models INHERITED IT — so the suite result
    depended on the developer's machine. Anyone with a chain set ran a different
    suite from anyone without one.
    FIX: phpunit.xml pins AI_PARSER_FALLBACK_MODELS="" and AI_PARSER_TIME_BUDGET=50
    for the whole suite. Ladder tests set their own chain through config(), which
    wins over the env.
    LESSON BANKED, and it is the same one as the 2026-09-10 "null BOTH keys"
    incident, one config key further along: A CONFIG A TEST DOES NOT EXPLICITLY
    OVERRIDE IS A CONFIG THE ENVIRONMENT GETS TO CHOOSE. Any new env-backed knob
    that changes behaviour belongs in phpunit.xml the day it is added — not after
    it silently reshapes somebody else's suite.

    TESTS +2: a spent budget refuses the primary's retry AND both rungs while still
    throwing the mapped "busy" voice (one request sent, lastFallbackModel null);
    and a normal budget leaves the ladder completely alone, so the guard cannot
    quietly disable the thing it exists to protect.

    SUITES: PHP 1171 passed (+2) / the same 7 pre-existing failures BY NAME.
    JS 152 tests, 150 passed + the 2 intended blinding fails — unchanged; no JS
    touched, and the PDF reader core is untouched since P6.

    NOT YET RE-PROVEN LIVE. The budget path needs the same treatment the ladder
    just got: run AI assist during an outage (or with a misspelled AI_PARSER_MODEL)
    and confirm the modal now shows the BUSY message within ~30s instead of the
    generic "could not read" after 60. The log should carry "E5 parser ladder
    stopped — time budget spent" when a rung is skipped.

  2026-09-13 — E4b PARSER LADDER EXTENDED TO EVERY PARSER PATH ✅ BUILT (code,
  uncommitted). User catch, same day as E4: "is this only for the pdf scurve? it
  should be for the s curve parsing itself (which means both excel and pdf)."
  Correct, and the gap was mine.

    THE GAP. The three AI paths ride TWO senders, and E4 added the ladder to one:
      normal-doc autofill   callGeminiWithImages()   one-shot Lite rescue
      PDF S-curve           callGeminiWithImages()   the E4 ladder
      Excel S-curve         callGeminiText()         NOTHING AT ALL
    So an Excel import still died on a per-model quota that a PDF import now
    survived — the same feature failing differently depending on which file the
    contractor happened to send. S-curve parsing is ONE feature to the user.

    HONESTLY LOGGED: I scoped E4 to PDF and wrote a test,
    test_excel_path_never_uses_the_parser_ladder, pinning that scope as if it were
    a guarantee. It was pinning MY decision, not a user decision and not a measured
    failure, and it is flipped here. Lesson worth carrying: a test that pins a
    scoping choice reads, later, exactly like a test that pins a safety property.
    Say which one it is in the test's own comment.

    WHY EXCEL IS THE SAFEST PLACE FOR THIS, not the riskiest. That path carries the
    MOST verification of the three:
      - SCurveImportController::applyDerivedLayout re-reads the sheet with the
        columns the model itself reported and lets the FILE win past 1.0;
      - amountsPresentInSheet DECLINES a claimed peso printed nowhere (the PDF twin
        only WARNS);
      - scurveResponseSchema is enforced at the protocol level.
    And the E3 verdict Excel was protected by was about LITE COPYING AUTHOR
    SUMMARIES — not about older Flash versions, which compute. The ladder is a
    VERSION list and Lite is excluded from it by configuration and documentation.

    WHAT SHIPPED
      - climbFallbackLadder() EXTRACTED from callGeminiWithImages so both senders
        share one copy. One copy is what stops the transport-failure discipline,
        the one-shot-per-rung rule and the eligibility gate drifting between paths
        — the same reasoning that keeps finalizeHeaderBands shared between the
        strict and relaxed PDF detectors.
      - callGeminiText (Excel) climbs the same ladder, and now RESETS
        lastFallbackModel at the top. Only the images sender reset it before, so a
        rescue recorded during a PDF call could have been reported as the
        provenance of a later Excel answer on the same instance. Pinned by a test.
      - parseProjectSlice (normal-doc) passes [...ladder, backupModel()] — the
        version ladder FIRST, Lite as the LAST rung (user decision 2026-09-13).
        Autofill is reviewed field by field so a rough fill still beats no fill,
        but there is no reason to reach for the weakest model while a computing one
        is available. With no ladder configured this is byte-identical to the
        2026-09-10 behaviour, which is why the existing Lite tests pass untouched.
      - SCurveImportController::parseWithGemini returns 'fallbackModel'. The Excel
        endpoint never did; without it a rung's answer would reach a baseline
        SILENTLY, which is the one thing this track refuses. No client change was
        needed — scurveStashWarnings is shared by all four call sites and already
        renders the E4 note naming the model that answered.

    A RUNG THAT REFUSES THE REQUEST ENDS THE WALK. A model that cannot honour the
    Excel responseSchema answers 400, which is not ladder-eligible, so the ladder
    stops there rather than offering every remaining version the same refusal — and
    the PRIMARY's voice still speaks. That fell out of the existing eligibility gate
    rather than needing new code; it has a test so it stays true.

    CAUGHT PRE-GREEN (1, mine, and it was the project's OWN banked lesson catching
    me): the provenance-leak test called Http::fake() twice, and a re-called fake
    keeps serving the FIRST stub (banked 2026-09-10, proven then via recorded()).
    The second call drained the exhausted first sequence and threw. Rewritten as ONE
    fake with a four-response sequence covering both calls. The rule is absolute:
    one fake per test.

    TESTS +5 net (one flipped, five added):
      - excel slice falls back down the parser ladder — and 'lite-model' is asserted
        ABSENT from every URL, which is the half of the old guarantee that matters;
      - a rung rejecting the request (400) ends the ladder, second rung never tried;
      - normal-doc tries the ladder BEFORE Lite, asserted by URL ORDER, and still
        reaches Lite when the rung fails;
      - fallback provenance does not leak between calls on one service instance;
      - the Excel ENDPOINT reports fallbackModel, and reports null on a clean read.
    Untouched and still green, which is the point of the empty default chain:
      test_excel_never_falls_back_to_lite, test_pdf_slice_never_falls_back_to_lite,
      test_no_lite_rescue_on_sustained_overload, test_images_fallback_to_lite_on_quota,
      test_images_no_fallback_on_auth_failure.

    SUITES: PHP 1169 passed (+5) / the same 7 pre-existing failures BY NAME.
    JS 152 tests, 150 passed + the 2 intended blinding fails — unchanged; no JS was
    touched, and the PDF reader core is untouched since P6, so that 16-read sample
    regression still stands as measured.

    STILL NOT BROWSER-PROVEN (E4 and E4b together). The ladder only fires on a real
    per-model failure. Cheapest live trigger: set AI_PARSER_FALLBACK_MODELS to a
    working older version, MISSPELL AI_PARSER_MODEL so the primary 404s (404 is
    ladder-eligible), php artisan config:clear, then run AI assist on an EXCEL
    S-curve and on a PDF. Both should succeed, both import notes should NAME the
    rung that answered, and the log should carry "E4 parser fallback". Restoring
    AI_PARSER_MODEL must return both to no-note behaviour.

    ENV: the variable belongs in the real .env (not .env.example, which is the
    committed blank template), beside the other AI_PARSER_* lines:
      AI_PARSER_FALLBACK_MODELS=gemini-3.6-flash,gemini-3.5-flash
    followed by php artisan config:clear. Never list a Lite model. The local .env
    carries several commented-out AI_PARSER_MODEL lines and the LAST UNCOMMENTED
    ONE WINS; parserFallbackModels() drops the primary and duplicates from the
    chain, so a mismatch there is harmless.

  2026-09-13 — E4 PARSER MODEL FALLBACK LADDER ✅ BUILT (code, uncommitted).
  User-ordered after P6. The parser path can now fall back down a VERSION ladder
  when its model hits a per-model quota or overload — without ever reaching Lite.

    THE BLOCKER, CONFIRMED IN CODE BEFORE BUILDING: backupModel() reads
    config('services.gemini.model') = AI_MODEL, and the live .env sets that to
    gemini-flash-lite-latest. So flipping callGeminiWithImages(..., true) on the
    PDF path would have fallen straight back to the model the E3 verdict excluded
    (Lite copies author summaries instead of computing). The fallback machinery was
    never the problem; the BACKUP MODEL was. That is why this is a parser-specific
    ladder and not a flag flip.

    WHAT SHIPPED
      config/services.php — 'parser_fallback_models' => env('AI_PARSER_FALLBACK_MODELS', '').
        Comma-separated, EMPTY BY DEFAULT.
      .env.example — the placeholder plus an explicit "NEVER list a Lite model here"
        with the reason, so the exclusion survives whoever edits the env next.
      GeminiDocumentParserService:
        - parserFallbackModels(): parses the list, drops blanks, duplicates and the
          parser model itself (a copy-pasted env must not make one model answer twice).
        - postGemini() gained a $maxAttempts cap (default 2). A ladder rung passes 1:
          the parser model already spent the retry budget, and retrying every rung
          would keep the user waiting minutes for an answer that is not coming.
          Rungs go through postGemini rather than a bare Http::post precisely so the
          status log, the quota-meter read and the mapped voice all still happen —
          the old Lite rescue bypassed all three.
        - callGeminiWithImages() third parameter changed from `bool $allowFallback`
          to `array $fallbackModels`. Callers now choose their own policy:
            parseProjectSlice()   -> [backupModel()]  (normal-doc Lite rescue, EXACTLY
                                     as before — autofill is reviewed field by field)
            parseSCurvePdfSlice() -> parserFallbackModels()  (version ladder, no Lite)
          Passing [] disables fallback entirely.
      scurve-import-ui.js — the provenance note now NAMES the model that answered
        ("The usual AI model was unavailable, so \"X\" answered instead. Treat these
        months as weaker evidence…") instead of the anonymous "Answered by backup
        model". Weak models on this track fail SYSTEMATICALLY, not randomly, so
        knowing which one answered is a real review aid.

    THE TRANSPORT-FAILURE DISCIPLINE IS PRESERVED, AND PINNED BY A TEST. When every
    rung fails, the ORIGINAL exception is rethrown — a per-day quota still says "try
    again tomorrow" rather than being masked as "the AI found nothing". That masking
    is the actual lesson of the 2026-09-11 Lite revert, and it is the one thing a
    ladder could easily undo. Each rung's own status still decides whether the NEXT
    rung is worth trying; only the MESSAGE the user sees stays the primary's.
    Auth failures (400/401/403) never enter the ladder at all — the key is broken,
    so every rung would fail identically and each attempt is pure latency.

    A CORRECTION TO THE RESTUDY RECORDED IN SECTION 0. That note said two tests
    "will need flipping": test_pdf_slice_never_falls_back_to_lite and
    test_no_lite_rescue_on_sustained_overload. THEY DID NOT. Because the ladder is
    EMPTY unless AI_PARSER_FALLBACK_MODELS is set, an unconfigured install — which
    is what the tests configure — still makes exactly two requests and throws the
    mapped voice. Both tests pass untouched and go on pinning Flash-or-nothing as
    the default. That is a better outcome than the design I sketched: the new
    behaviour is opt-in, and the old guarantee is still under test.

    TESTS +6 (all green), each pinning one decision rather than the happy path:
      - the ladder answers a per-model 429 and Lite is asserted ABSENT from every
        URL (the whole point of a parser-specific chain);
      - a failing rung hands off to the next, with exactly one shot per rung;
      - an exhausted ladder surfaces the PRIMARY's per-day voice, not the rung's
        "busy" — the revert lesson, under test;
      - a 401 never ladders (one request);
      - blanks and the primary model are cleaned out of the configured chain;
      - the Excel path never ladders (callGeminiText has no fallback branch at all,
        and a weak S-curve answer in a baseline is the failure this track exists for).

    SUITES: PHP 1164 passed (+6) / the same 7 pre-existing failures BY NAME.
    JS 152 tests, 150 passed + the 2 intended blinding fails — unchanged, and the
    PDF reader core was NOT touched by this work, so the 16-read sample regression
    from P6 still stands as measured.

    NOT BROWSER-PROVEN, and it cannot be forced cheaply: the ladder only fires on a
    real per-model quota or overload. To prove it live, set
    AI_PARSER_FALLBACK_MODELS to a working older version, exhaust or misname the
    primary (AI_PARSER_MODEL), then run AI assist on a PDF. Expect the import note
    to NAME the rung that answered, and the log to carry "E4 parser fallback".
    A deliberately dead primary (a misspelled model id -> 404) is the cheapest
    trigger, since 404 is ladder-eligible.

    STILL OPEN, UNCHANGED BY THIS WORK: the cross-check only rescues files where a
    trustworthy file reading exists (a printed cumulative row, full coverage, a
    workable AI-derived map). On a file with no printed summary, a weak model's
    wrong curve still passes on the homework and presence warnings alone. That is
    what the early-100 guard is for, and it stays the next queued item.

  2026-09-13 — P6 MONTH-7 CHART-AXIS FIX ✅ BUILT (code, UNCOMMITTED).
  Root cause, chosen rule and the measurements that picked it are below; the BUILD
  RESULT block at the end of this entry records what shipped and what it measured.
  Probes in C:\temp\opencode (probe_m7*.mjs, probe_axis.mjs, probe_ladder2.mjs,
  probe_strict.mjs, verify_fix.mjs, verify_p6.mjs, patch.mjs + helper/lane.txt).
  NOT BROWSER-PROVEN — that step is the user's, and it is named at the end.

    THE MECHANISM (measured, not inferred). Roof page 2 carries the table's
    overflow tail (months 6 and 7) AND the S-curve CHART, whose scale runs down
    its left side: 100% / 75 / 50% / 25% / 0%. finalizeHeaderBands ends the LAST
    month band at the raw page edge (core :341, `xEnd: pageMaxX + 1`), so on that
    page month 7's band is x=116.4 -> 187.6. The chart's scale is printed at
    x≈170.7-173.5 — INSIDE it. The picture's axis is therefore read as month-7
    cells. ONE cause, TWO symptoms:
      (1) the axis "50%" (y=353.59) sits on the SAME TEXT LINE as C EARTHWORK's
          genuine month-7 value of 0% -> monthVals[7] = 0 + 50 = 50, monthPct set,
          so it clears the adopted-path filter (:1052-1063) and C is listed under
          a month in which it has no work;
      (2) the axis "25%" (y=326.33) sits 3.34pt from the peso subtotal row
          "LOT 1.00 1,890,436.78" (y=322.99). matchRowsByY's tolerance on this page
          is ≈5.1 (runner-up 6.86), so it pairs cleanly and glues the chart value
          onto that peso line, which then reads as an activity.
    Result: M7 lists FIVE where the truth is THREE (A, B, H). The CURVE is
    untouched — it is adopted from the printed cumulative row, which the chart
    never reaches. Only the NAMES are wrong.

    TWO CORRECTIONS TO WHAT THIS FILE PREVIOUSLY RECORDED
    (a) "when the cross-check fires we inherit the SYSTEM's activity lists" framed
        this as a cross-check-path problem. It is NOT. A plain LEARNED-LAYOUT read,
        zero AI, produces the same wrong month-7 list — so the candidate refinement
        "keep the AI's activity names for months present in both readings" would
        have fixed one of two paths and left COMMITTED P4 behaviour wrong.
    (b) The two candidate fixes recorded above are not interchangeable, and
        MEASUREMENT killed the preferred one:
          - "tighten matchRowsByY for the column-split case" fixes symptom (2) but
            CANNOT fix symptom (1) — there the axis token shares a text line with a
            real row before any merging happens, so no pairing is involved;
          - "bound the LAST month band rather than letting it run to the page edge"
            computes to x=192.5 on roof page 2 (label 116.4 + median neighbour width
            76.1), still PAST the chart at 172. It does not fix the roof on its own.
        Measured separately: the open tail holds ZERO tokens on every rule-readable
        sample, so bounding it is safe — it is just not the cure.

    THE RULE THAT DOES REACH BOTH: A CHART SCALE COUNTS DOWN. A month's column
    read down the page is activity values in arbitrary order; cumulative figures
    count up. Nothing in a table descends 100 -> 0 on a fixed x.
      MEASURED over all 8 sample PDFs, clustering percent-written AND bare numeric
      tokens by x (±3pt):
        71 vertical stacks (>=3 tokens)      examined
         1 strictly descending                <- the roof's axis, and nothing else
         1 also starts at exactly 100, ends at exactly 0
         1 also evenly spaced (10.5% gap spread against a 25% limit)
      The single match is [100%, 75, 50%, 25%, 0%] at x≈170.7, roof page 2,
      y = 412.25 / 381.70 / 354.36 / 326.33 / 298.15, gaps 30.55 / 27.34 / 28.03 /
      28.18. ZERO false positives at every strictness level tested.
      NOTE FOR WHOEVER BUILDS IT: the "75" rung is a BARE NUMBER — pdf.js splits it
      from its "%". A percent-only predicate sees 4 rungs and the spacing test then
      fails (the 100->50 gap doubles to 57.9). Accept both token shapes or the rule
      silently weakens.

    DECISIONS BANKED (user, 2026-09-13)
      - STRICTNESS: all four conditions — >=3 rungs, strictly descending, first
        rung exactly 100 and last exactly 0, evenly spaced within 25%. Chosen over
        looser variants because zero-false-positive was measurable and a clipped or
        80/60/40/20 axis can be revisited on evidence rather than guessed at now.
      - SCOPE: anywhere on the page, not just the open last band — a chart scale is
        never table data wherever it lands, and this also covers a chart printed
        beside the MIDDLE of a table.
      - The last-band bound ships WITH it as defence in depth (a no-op today, but it
        closes the structural hole that let a chart into a month band at all).

    VERIFIED IN SIMULATION (real roof PDF, real core patched in C:\temp\opencode,
    repo untouched — verify_fix.mjs):
      M7   BEFORE [5] A | B | C EARTHWORK | LOT 1.00 1,890,436.78 | H
           AFTER  [3] A | B | H                 <- matches the AI's own list exactly
      curve      6.32 / 24.31 / 42.3 / 59.71 / 77.03 / 99.14 / 100   UNCHANGED
      curveSource 'summary'                                          UNCHANGED
      M1-M6 counts 4,4,4,4,3,4                                       UNCHANGED
      PDF-3 self-check PASSES, months 1..7                           UNCHANGED
      orphan warning "3 unnamed value rows" -> "2"  (one axis row stops becoming a
        row at all — expected, and the acceptance check that the skip really fired)
      REGRESSION: 8 sample PDFs x plain/taught = 16 reads, 0 CHANGED (curve, names,
      contract, warning count and curveSource all compared).

    WHAT SHIPS (pure core only — no UI, no endpoint, no AI, no learning)
      1. chartScaleTokens(tokenLines) — new pure helper beside buildRows, EXPORTED
         for fixtures (the file already exports internals). Clusters over the page's
         own tokenLines, so geometry has ONE definition — the same principle that
         keeps finalizeHeaderBands shared between the strict and relaxed detectors.
         Returns a Set of token objects.
      2. buildRows computes it once per page beside cumulativeYs (:586) and the
         token->band loop skips members immediately after `if (!mc) continue;`
         (:618). Every read path — rules, learned map, AI cross-check replay —
         funnels through that one loop, so all three are fixed by the one change.
      3. finalizeHeaderBands :341 — last band ends a MEDIAN NEIGHBOUR-WIDTH past its
         own label, clamped to pageMaxX + 1.
      4. +5 fixtures in tests/js/scurve-pdf-parser.test.mjs, transcribed from the
         geometry above: ladder in the last band is not read as cells and the row
         sharing its line keeps its own value (symptom 1); a rung near a real row's
         y does not become an activity (symptom 2); a descending column that does
         NOT start at 100 / end at 0 IS still read (the guard that keeps this from
         eating data); an unevenly spaced 3-rung stack is not claimed; the last band
         ends a neighbour-width past its label.

    NO RE-CAPTURE WAVE: the fix is at READ time and the band bound is a no-op on
    this file (192.5 clamps back to the page edge), so the APPROVED ROOF MAP ALREADY
    LIVE IN THE DB STAYS VALID. No PHP, no Blade, no routes — no route:clear or
    view:clear needed.

    EXPECT AFTER BUILDING
      npm run test:js   -> 152 tests, 150 passed + the 2 intended blinding fails
      php artisan test  -> 1158 passed / the same 7 pre-existing (no PHP touched;
                           clean-tree confirmation only)
      16-read regression -> byte-identical
      BROWSER PROOF (still the standing rule for this path): import the roof PDF on
      the detail Baseline tab with the approved layout live — month 7 must list
      THREE activities, the curve must still read 6.32 / 24.31 / 42.30 / 59.71 /
      77.03 / 99.14 / 100, and the adoption warning must still appear.

    LESSON BANKED (cost me one false negative this session): the first simulation
    reported "fix changes nothing", which looked like evidence against the rule. It
    was the HARNESS — the patch script built the helper inside a shell-quoted
    node -e string and the regex backslashes were eaten, so PCT/NUM matched nothing
    and the skip never fired. Same class as the P3 and PDF-3 lessons, one level out:
    when a rule refuses, suspect the data feeding it — and when a probe reports
    "no change", verify the probe actually applied its own change before believing
    it. patch.mjs now asserts every anchor matched AND that the regexes survived.

    ── BUILD RESULT (same day; code in the working tree, NOT committed) ─────────
    WHAT SHIPPED, all in public/js/admin/projects/scurve-pdf-parser-core.js:
      1. chartScaleTokens(tokenLines) — new pure helper above buildRows, EXPORTED
         for fixtures. Clusters percent-written AND bare numeric tokens by x (±3pt)
         over the page's own tokenLines, and claims a cluster only when all four
         hold: >=3 rungs, strictly descending, first rung exactly 100 and last
         exactly 0, gaps even within 25%. Returns a Set of token objects.
      2. buildRows computes it once per page beside cumulativeYs and the
         token->band loop skips members right after `if (!mc) continue;`. Every
         read path — rules, learned map, AI cross-check replay — funnels through
         that one loop, so all three are fixed by the single change.
      3. finalizeHeaderBands — the last band now ends a MEDIAN NEIGHBOUR-WIDTH past
         its own label, clamped to pageMaxX + 1, instead of running to the raw page
         edge. Defence in depth: measured as a no-op on every sample, and on roof
         page 2 the bound computes past the chart and clamps back anyway. It closes
         the structural hole; chartScaleTokens is what actually cures the roof.
      4. tests/js/scurve-pdf-parser.test.mjs +5 fixtures (section "P6 the chart's
         own scale is not table data"), transcribed from the real page-2 geometry
         including the split '75' + '%' rung.

    MEASURED ON THE REAL FILE (verify_p6.mjs — working tree vs the COMMITTED core
    at HEAD, both run against the real PDFs):
      M7   BEFORE [5] A | B | C EARTHWORK | LOT 1.00 1,890,436.78 | H
           AFTER  [3] A | B | H                  <- matches the AI's own list exactly
      curve       6.32 / 24.31 / 42.3 / 59.71 / 77.03 / 99.14 / 100   UNCHANGED
      curveSource 'summary'  ·  contract 1,725,080.17                 UNCHANGED
      M1-M6 counts 4,4,4,4,3,4                                        UNCHANGED
      PDF-3 self-check PASSES, months 1..7                            UNCHANGED
      orphan warning "3 unnamed value rows" -> "2"  — one axis rung stops becoming a
        row at all, which is the visible proof the skip fired
      REGRESSION: 8 sample PDFs x plain/taught = 16 reads, 0 CHANGED against HEAD
      (curve, per-month names, contract, warning count and curveSource compared).

    CAUGHT PRE-GREEN (1, mine, fixture-side): 'a chart scale rung on its own line
    never becomes a row' asserted deepEqual(names, ['Roofing']) across BOTH months,
    but Roofing is scheduled in both and is correctly listed twice. The expectation
    was wrong, not the code; the assert now compares the DISTINCT set, which is what
    the test was actually about. (Also fixed pre-green: a stray non-English word had
    landed in the new helper's docblock.)

    SUITES
      JS  152 tests, 150 passed + the 2 intended blinding fails (F-B1 conditional
          and L3 hardcoded-ABC — the documented pair, unchanged). Was 147/145.
      PHP 1158 passed / 7 failed = the same 7 pre-existing BY NAME (2 Contractor-
          Dashboard + 1 DashboardOverdueInspection + 2 EmailDraft + 2 default-
          baseline in IssueLifecycle/RiskRegister). No PHP, Blade or route was
          touched, so no route:clear / view:clear and no re-capture wave — the
          APPROVED ROOF MAP ALREADY LIVE IN THE DB STAYS VALID.

    ✅ BROWSER-PROVEN SAME DAY (user, roof PDF, detail Baseline tab, approved layout
    live, zero AI). Every predicted value landed, including the side effect:
      Jul 2025   6.32%  A · B · C EARTHWORK · E FINISHING            4 activities
      Aug 2025  24.31%  A · B · C EARTHWORK · E FINISHING            4
      Sep 2025  42.30%  A · B · C EARTHWORK · E FINISHING            4
      Oct 2025  59.71%  A · B · C EARTHWORK · E FINISHING            4
      Nov 2025  77.03%  A · B · E FINISHING                          3
      Dec 2025  99.14%  A · B · E FINISHING · H OTHER WORKS          4
      Jan 2026 100.00%  A · B · H OTHER WORKS                        3  <- WAS 5
    "C EARTHWORK" is gone from January and "LOT 1.00 1,890,436.78" never appears,
    while C correctly KEEPS its July-October months — the fix removed the chart's
    contamination without touching the activity's real schedule. Import notes
    carried the learned-layout note, the adoption note, and "2 unnamed value rows"
    (was 3): that drop is the visible proof the skip fired rather than some other
    change masking the symptom.
    ⇒ P6 CLOSED. The last known defect in the roof family's offline read is gone;
      the system's own reading now matches the AI's activity lists on all 7 months.

  2026-09-13 — P5 CROSS-CHECK BROWSER-PROVEN (user, gemini-flash 3.6 on the roof).
    The wrong answer was caught and replaced LIVE. Import notes carried "the AI's
    monthly numbers did not match the figures printed in this PDF, so the baseline
    below was read from the file itself using the columns the AI identified", and
    the curve came back 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 — the
    truth. The same file under gemini-flash-latest carried NO such note, because
    the two agree. Both halves proven: it fires when it should and stays silent
    when it should not. THIRD model family to converge on the correct curve.
    [RESOLVED 2026-09-13 by P6 — and the framing below was wrong in a way worth
    keeping: this was NOT a cross-check-path problem. A plain learned-layout read
    showed the same five entries, so "keep the AI's names on the cross-check path"
    would have fixed one path and left committed behaviour broken. See the top
    entry of this section.]
    NOTED WHILE PROVING: when the cross-check fires we inherit the SYSTEM's
    activity lists, so month 7 shows the chart-axis residual (5 entries including
    "LOT 1.00 1,890,436.78") where the AI's own list is correct at 3. Overriding
    the NUMBERS does not require overriding the NAMES — the AI reads printed names
    directly and our attribution has a known defect. Candidate refinement, not yet
    ordered: keep the AI's activity names for months present in both readings.
  2026-09-13 — P5 AI-vs-FILE CROSS-CHECK BUILT for PDFs (user-ordered, Excel
  parity). The file now overrules a contradicting AI answer, as the Excel path
  has done since GAP 1.
    WHERE IT LIVES, AND WHY NOT WHERE ITS TWIN DOES: the Excel cross-check is
    SERVER-side (SCurveImportController::applyDerivedLayout re-reads the sheet
    with PhpSpreadsheet). PDFs cannot copy that — the server owns no PDF reader;
    pdf.js in the browser is the only reader there is. So the PDF twin runs
    CLIENT-side in scurve-import-ui.js, on the AI success path, before the months
    are normalized.
    THE COMPUTATION WAS ALREADY RUNNING. PDF-3's self-check replays the file
    through the AI-derived map and compares it to the AI's curve; it discarded
    that reading on disagreement — exactly when it is worth the most. On the
    user's 3.5 run the true curve had been computed and thrown away.

    THE APPROVED RULE DID NOT WORK, AND THE MEASUREMENT SAID SO BEFORE SHIPPING
    The order was "Excel parity — the file wins on any >1% gap". Against the real
    wrong answer, EVERY GAP IS UNDER ONE POINT:
      AI (3.5) 6.69 / 24.60 / 42.51 / 60.32 / 77.55 / 100   / 100
      file     6.32 / 24.31 / 42.30 / 59.71 / 77.03 /  99.14 / 100
      gaps     0.37 / 0.29  / 0.21  / 0.61  / 0.52  /   0.86 / 0
    A 1.0 tolerance calls that AGREEMENT, so the cross-check would have passed the
    very answer it was built to catch — while that curve reaches completion a
    month early and shows no work at all in its final month.
    CORRECTED: 1.0 answers "is this the same LAYOUT" (its use in the Excel path
    and in PDF-3's self-check is right there). Whether to REPLACE numbers is a
    different question, so pdfCurvesDisagree compares at DISPLAY PRECISION (0.05):
    if the reviewer would read different numbers on screen, they are different.
    This also retires the need for a separate early-100 guard ON THIS PATH — the
    file's own row simply wins. The guard is still worth having where no replay is
    possible; see the earlier entry.

    THE CIRCULARITY GATE (narrower than Excel's, deliberately). The map is derived
    from the AI's OWN labels, so a mislabelled month poisons the replay too. The
    file may overrule the model ONLY when its reading comes from the author's
    printed cumulative row (curveSource 'summary') — the document stating its own
    curve rather than us recomputing one. A replay resting on activity math shares
    too much reasoning with the answer it would be judging, so it returns null and
    stays advisory. Plus full month coverage: a reading missing months cannot
    stand in for an answer that has them all.

    WHEN IT FIRES the AI's own verdicts are dropped (they described arithmetic no
    longer on screen — the same reasoning as the Excel twin at :514), the file's
    warnings ride along minus any learned-layout note (no approved layout was
    used), and a plain note leads: "the AI's monthly numbers did not match the
    figures printed in this PDF, so the baseline below was read from the file
    itself using the columns the AI identified."

    MEASURED ON THE REAL FILE (stash shape, as the browser feeds it):
      wrong answer (3.5) -> file reading 6.32 / 24.31 / 42.30 / 59.71 / 77.03 /
                            99.14 / 100, disagrees TRUE  => file wins
      right answer (3.8) -> identical reading, disagrees FALSE => AI kept, no swap,
                            no false alarm
      SEGMENT (activity-math replay) -> null, correctly refuses to overrule

    FILES: scurve-pdf-parser-core.js (crossCheckPdfAgainstFile, pdfCurvesDisagree,
    both exported and attached to window), scurve-import-ui.js
    (scurveCrossCheckPdfAnswer, called in _trySCurvePdfAiAssist).
    TESTS +4 JS. JS 147 tests, 145 passed + the 2 intended blinding fails. PHP 1158
    passed / same 7 pre-existing. All 16 sample PDFs BYTE-IDENTICAL (the reader is
    untouched — this path only runs after an AI answer).

    NOT BROWSER-PROVEN. To prove it: clear the approved layout so the rules miss,
    run AI assist with gemini-flash 3.5 on the roof, and confirm the import notes
    carry the "did not match the figures printed in this PDF" line and the curve
    reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100. The same run under
    gemini-flash-latest must show NO such note, since the two agree.

  2026-09-13 — P4 BROWSER-PROVEN by the user, on BOTH models. The strongest form
  of proof this track has produced: two different AI answers, one right and one
  wrong, converge on the SAME correct system read once the layout is approved.
    STORED MAP (from the live DB, confirming the phantom-column fix in production):
      page 0 → months 1,2,3,4,5      page 1 → months 6,7
    Previously page 0 claimed 1-6 and page 1 only 7, stranding month 6.
    SYSTEM READ AFTER APPROVAL, identical under both models:
      6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100
      activities M1-M4 = 4, M5 = 3, M6 = 4 — matching the AI exactly
    ⇒ P4 adoption, the phantom-column fix, the multi-page candidate merge, the
      Y-tie and the adopted-path activity filter are all BROWSER-PROVEN.
    [RESOLVED 2026-09-13 by P6 — see the top entry of this section.]
    ONLY REMAINING DEFECT: month 7 lists 5 activities instead of 3 (the S-curve
    chart's Y-axis labels at x ~= 172 falling inside month 7's band). Unchanged
    from the analysis above; still not fixed, still needs an order.

    WHAT THE TWO MODELS SHOWED (and why it matters beyond this file)
      gemini-flash-latest (3.8): returned the TRUE curve, and its import note read
        "these months match the file's own summary exactly — they could not be
        independently verified from the activity rows."
      gemini-flash 3.5: returned 6.69 / 24.60 / 42.51 / 60.32 / 77.55 / 100 / 100
        — to rounding, EXACTLY the cost-weighted arithmetic over the printed
        whole-number percentages measured earlier this session. Its import note
        read "the monthly figures for Month 1, Month 2, Month 3 (and others) could
        not be found printed on the page, so they may have been inferred rather
        than read."
    So the presence gate ALREADY identified the wrong answer as not-printed, and
    the right answer as matching the file's summary. The discriminating signal
    exists today and is simply not acted upon — which is the opening for the next
    item.

  2026-09-13 — NEXT ORDERED ITEM: AI-vs-FILE CROSS-CHECK FOR PDFs (user decision,
  Excel parity). NOT BUILT YET — recorded here so the reasoning is not lost.
    THE GAP: the Excel path already re-reads the sheet with the layout the AI
    itself reported and lets the FILE win on disagreement
    (SCurveImportController::applyDerivedLayout, :420, warning at :518). The PDF
    path has no equivalent — a validated AI answer is used as-is.
    THE MACHINERY IS ALREADY RUNNING: PDF-3's self-check replays the file through
    the AI-derived map and compares it to the AI's curve within 1%. That result is
    used ONLY to decide whether to teach the layout, and is DISCARDED on
    disagreement — which is exactly when it is most valuable. On the 3.5 run it had
    computed the true curve and thrown it away.
    DECISION (user, 2026-09-13): Excel parity — when the replay yields a
    full-coverage curve from a trustworthy source and differs from the AI by more
    than 1%, the FILE wins, with a plain-language note in the same voice as the
    Excel one.
    CAVEAT TO CARRY INTO THE BUILD: the map is derived from the AI's own labels, so
    a mislabelled month poisons the replay too. Gate on full month coverage plus a
    trustworthy source; the adopted printed cumulative row is the strongest source
    (the author's own statement), activity math the weaker one. The Excel path
    carries the same circularity and handles it with coverage/denominator gates.
    The early-100 guard (see the previous entry) stays as a SEPARATE backstop for
    files where no replay is possible at all.

  2026-09-13 — ACTIVITY-PER-MONTH FIXED on the adopted path (user-reported).
    USER REPORT: the roof's curve was right but its activity lists were nonsense —
    every month showing 8 entries including "Activity 11", "Activity 12" and
    "Project Duration"; December showing only 2; January showing 10 including
    "LOT 1.00 1,890,436.78". Correct report. TWO INDEPENDENT CAUSES, both fixed.

    CAUSE 1 — A PHANTOM HEADER COLUMN. Page 1 prints SIX "30 days" labels but its
    activity rows hold only FIVE values: the sixth column's figures overflowed to
    page 2, where the label is reprinted. derivePdfLayoutFromAi matched labels
    greedily, so page 1 claimed months 1-6 and page 2 was left only month 7 —
    stranding month 6 on a band with no activity data AND leaving month 6's real
    figures on page 2 unmapped. That is why December listed nothing but page
    furniture. The true split is months 1-5 / 6-7.
      FIX: activityBandReach() — a label whose column carries no ACTIVITY data on
      this page is a continuation stub and is dropped. "Activity data" is judged
      narrowly on purpose: the band is NOT empty (the TOTAL pesos, the cumulative
      row and the duration row all reach into it), so only rows that are NAMED (a
      word left of the first month band) AND carry >= 2 PERCENT-written cells
      count. Peso tables produce no such rows, so the rule stays inert there —
      which is why all 16 sample reads are unchanged.
      SIDE EFFECT CAUGHT BY MEASUREMENT: trimming let the new last band run to the
      page edge and swallow the stub column's values (month 5 came back as
      77.03 + 99.14 = 176.17). The dropped label still marks where its column
      began, so the surviving band is now clamped there.

    CAUSE 2 — JUNK ROWS WERE ACTIVITIES. The lists were assembled from the very
    cells the adoption branch had just declared unusable, so every row holding a
    number in a band became an "activity": the duration row, the Cash Flow pesos,
    and nameless leftovers rendered as "Activity N".
      FIX: on the adopted path only, rebuild each month's list from rows that are
      NAMED and whose cell in THAT month is written as a percentage. On a file of
      per-activity fractions that is exactly the set scheduled that month. Scoped
      to the adopted path so no other file's attribution moves.

    TWO MORE DEFECTS SURFACED WHILE FIXING THOSE
    (a) P2-c consumed the roof's page-2 cumulative row BEFORE the P4 detector saw
        it (desc-less, ascending, last === 100 — it matches) and dropped it
        silently. P4 capture now runs first; P2-c remains for rows P4 declines.
    (b) mergePages' copyInto merged `months` and `presence` but not the new `pct`
        flags, so a page-2 row's percent evidence never reached its page-1 twin and
        the continued months looked cell-less. Now merged with the rest.

    THE Y-TIE IS NOW UNCONDITIONAL ON LATER PAGES. It was applied only to
    single-value candidates; page 2 then offered THREE rival tails (100%, then
    0%/50%, then the real 99.14%/100%) and the contradictory set was discarded
    whole, refusing the file. Once a file has shown WHERE its cumulative row sits,
    every later page must agree — column-split prints share row Y positions
    exactly (the P3 property). Ascension cannot be re-tested on an overflow page,
    so height is the only honest tie. The first cumulative row in a file still has
    to earn it on shape alone.

    RESULT (real file, real core): curve 6.32 / 24.31 / 42.30 / 59.71 / 77.03 /
    99.14 / 100, curveSource 'summary'. Activities per month now MATCH THE AI
    EXACTLY for months 1-6 (4,4,4,4,3,4). PDF-3's self-check still passes, months
    1..7.

    [RESOLVED 2026-09-13 by P6 — see the top entry of this section. Of the two
    candidate fixes floated below, MEASUREMENT rejected both: bounding the last
    band lands past the chart on this page, and tightening matchRowsByY cannot
    touch the EARTHWORK case at all, because there the axis rung shares a text
    line with a real row. The cure was to recognise the chart scale itself.]
    RESIDUAL, NOT FIXED, CAUSE KNOWN: month 7 lists FIVE activities where the AI
    says three — an extra "C EARTHWORK" and a junk "LOT 1.00 1,890,436.78". Both
    trace to ONE thing: the S-CURVE CHART's Y-axis labels (100% / 75% / 50% / 25%
    / 0% printed at x ~= 172 on page 2) fall INSIDE month 7's band, which runs to
    the page edge. EARTHWORK's real month-7 value is 0%, but the axis "50%" lands
    in the same band and sums to 50; and a page-1 row at y=322.8 merged with the
    axis row at y=326.3, inheriting a percent cell in month 7. This is a chart
    bleeding into the table's column space, not a summary or layout defect. Two
    candidate fixes, neither taken without an order: bound the LAST month band
    rather than letting it run to the page edge, or tighten matchRowsByY for the
    column-split case (genuine split rows share y EXACTLY — 394.4 vs 394.4 —
    whereas the axis rows are 3.5pt off).

    MODEL-METHOD FINDING (user, live): gemini-flash-latest (3.8) returned the true
    curve; gemini-flash 3.5 returned 6.69 / 24.60 / 42.50 / 60.32 / 77.54 / 100 /
    100. Those 3.5 numbers are, to rounding, EXACTLY the cost-weighted arithmetic
    computed from the printed whole-number percentages and measured earlier this
    session (6.69 / 24.6 / 42.51 / 60.32 / 77.55 / 100.05 / 100.91, tail clamped).
    The models did not differ randomly — they used different METHODS, and the
    source's rounding makes the weighted method structurally unable to land on the
    truth. Independent confirmation of the decision to adopt the printed row.

    SUITES: JS 143 tests, 141 passed + the 2 intended blinding fails. PHP 1158
    passed / same 7 pre-existing. All 16 sample PDFs BYTE-IDENTICAL against 017afeb.

    OPEN DESIGN QUESTION (user raised, answer proposed, NOT built): a guard on
    repeated cumulative values. Resolution offered — strictness should follow the
    curve's SOURCE, not its shape: an AI answer hard-refuses on a repeated interior
    value (untrusted by design, refusal costs only a retry); the file's own printed
    row or activity math only WARNS (refusing the author's own numbers is
    overreach); and "100% reached with work still scheduled after it" hard-refuses
    either way, being a contradiction regardless of source. NOTE the user corrected
    an earlier argument of mine: per-activity 0% months are NOT evidence that a
    whole flat month is normal, and shutdown/rain examples describe ACTUAL progress
    while these files carry PLANNED baselines only — verified, the roof workbook
    contains no actual-progress data anywhere (no 'actual'/'accomplished'/'as of'
    wording; rows 49-82 empty; row 33 is purely derived from the plan).

  2026-09-13 — P4 SUMMARY-ROW ADOPTION BUILT (code, uncommitted). The roof now
  reads its TRUE curve offline, with zero AI, and PDF-3's self-check passes on it.
    RESULT ON THE REAL FILE (roof PDF + its AI-derived map, real core in node):
      curve       6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100  = the TRUTH
      curveSource 'summary'  ·  all SEVEN months  ·  adoption warning present
      PDF-3 self-check on the roof: PASSES (was: refused) — months 1..7 placed
    That last line is the point of the work: PDF-3 was unprovable in a browser
    because SEGMENT no-ops and the roof refused. The roof is now its proof file.

    WHAT THE PLAN GOT WRONG, AND WHAT THE MEASUREMENTS SAID INSTEAD
    (a) "A straight port of the Excel rule." It is not. The Excel trigger is
        lastNonZero === 0, but the roof's cells DO parse as numbers — 6 non-zero
        months — so that trigger never fires. A second trigger was needed.
    (b) First candidate trigger, "every activity row's percent cells sum to ~100",
        was REJECTED BY MEASUREMENT: the roof's read yields the 5 real names plus
        15 spurious "Activity N" rows, so no clean per-row test survives.
    (c) A large-gap threshold was rejected as a tuned guess that contradicts BUHI,
        where a 3-point disagreement correctly leaves activity math winning.
    (d) The trigger that works is RAW CUMULATIVE OVERSHOOT. A well-formed weighted
        table's activity values sum to exactly the contract; measured:
          roof (via map)  8.21 / 39.77 / 225.67 / 303.2 / 403.22 / 403.24
          SEGMENT         5 / 20 / 40 / 65 / 100
          6-stage         5 / 20 / 40 / 60 / 80 / 100
          10-month        5 / 10 / 20 / 30 / 50 / 70 / 80 / 90 / 95 / 100
        100 is the definitional endpoint, not a tuned constant. Gated on an
        INDEPENDENT contract figure: with no TOTAL row, contract is the sum of the
        month totals, the ratio is 1.0 by construction and overshoot is
        undetectable — such files get the parity trigger only.
    (e) Cost-weighted math from the printed figures was evaluated as an
        alternative source and REJECTED ON ACCURACY: it recovers the contract
        exactly (2,239,599.72) but drifts to 100.05 / 100.91, overshooting 100,
        because the PDF prints whole-number percentages. The printed cumulative
        row is exact. The summary is both the cheaper AND the more accurate source.

    THREE UPSTREAM DEFECTS FOUND WHILE BUILDING (each blocked the one after it)
    1. isSummaryLabel could never claim the roof's cumulative row. The word
       "Commulative" sits 4.6pt below its own values (Y_TOLERANCE is 2) and the
       only token left on the values' line — a bare "%" — falls between the
       description limit and the first month band, reaching neither. The row
       arrived NAMELESS and was summed as an activity: that is the origin of the
       403%. Detection is now by SHAPE (cumulativeRowValues), never by label:
       percent-written cells, strictly ascending, max >= 50. Each guard earns its
       place on this file — percent-shape rejects the peso TOTAL and Cash Flow
       rows, ascension rejects the per-month row (6.32/17.99/17.99 repeats), and
       max >= 50 rejects it a second way.
    2. ONE STORED MAP PAGE COULD CLAIM SEVERAL PDF PAGES. The roof's page-0 entry
       (six "30 days") also matched the overflow page, so month 7 was never read
       AND the overflow page was re-read as months 1-6, manufacturing a summary
       row that contradicted the real one. A stored page now describes one
       physical page and is consumed once — the same spirit as "one map owns a
       file", one level down.
    3. TWO ARITY ASSUMPTIONS blocked the single-month tail, the same class the
       previous session fixed inside the derivation: matchMapPage called
       bandsHoldNumbers with the default minFilled = 2 (a one-column page can
       never fill two bands) and rejected any stored page with < 2 months
       outright. "Two months or it is not a schedule" is right for a map that owns
       the whole table and wrong for a CONTINUATION page; a lone month is now
       admitted only when the map has other pages to be a continuation OF.

    THE CONTINUATION TAIL IS TIED BY HEIGHT, NOT TRUSTED ON ITS OWN
    A lone percentage on an overflow page cannot be tested for ascension. The
    roof's page 2 offered THREE rival tails (100%, 50%, 100%) and the conflicting
    set was correctly thrown away. A single value is now accepted only when it
    sits at the same height as a cumulative row already found on an earlier page
    — column-split prints share row Y positions exactly, the property P3's
    matchRowsByY is built on. That reduced the roof's page 2 to exactly one
    candidate, {7: 100}, at y=272.93 against the head at y=272.81.

    ALSO: summary candidates now survive a MULTI-PAGE read (they were discarded
    outright, which is why the roof reported summaryReference []). Each page
    numbers its months through its own header, so indices are absolute and are
    unioned — no positional guessing. A month claimed twice with different values
    refuses the whole set; a disagreeing reference is worse than none.

    REFUSAL, NOT A WRONG CURVE (user decision): when the trigger fires and no
    printed curve validates, buildMonthsFromRows returns null and the reader falls
    through to the honest "not recognised" answer plus the AI offer.

    PROVENANCE: meta.curveSource ('activities' | 'summary') now exists on the PDF
    side, matching the Excel service (SCurveImportService.php:1691).

    SUITES / REGRESSION
      JS 143 tests, 141 passed + the 2 intended blinding fails (+5 fixtures:
        adoption; a well-formed file never adopts; unusable cells with no printed
        curve refuse outright; the continuation tail by row height; a stray tail
        percentage is not mistaken for a curve).
      PHP 1158 passed / the same 7 pre-existing failures.
      ALL 16 SAMPLE PDFs BYTE-IDENTICAL against the committed core (017afeb) —
      including both CONSCHEDSAVINGS copies, SEGMENT, 6-stage and 10-month, which
      all stay curveSource 'activities'.

    KNOWN RESIDUAL (not a blocker, not new): the roof's contractAmount still reads
    1,725,080.17 against a true 2,239,599.72 — it picks up a cash-flow cumulative
    figure. The curve is unaffected because percentages are adopted as written, but
    anything that reasons about the contract on this file is still wrong.

    NOT BROWSER-PROVEN. Next step is the browser run: import the roof PDF on BOTH
    flows, confirm the true curve, the adoption warning and no AI call — then
    browser-prove PDF-3 on the roof, which this work makes possible for the first
    time.

  2026-09-13 — PDF-3 STASH DEFECT FIXED (code, uncommitted). One line of behaviour,
  two fixtures, no core change. Diagnosis entry below; this is the fix.
    THE CHANGE — scurve-pdf-parser.js:54 now stashes {str, x, y, width} instead of
    {str, x, xEnd}. Nothing else moved. The core's own fallbacks were already there:
    groupItemsIntoLines reads item.y when an item has no pdf.js transform, and
    linesToTokenLines reads item.x / item.width. Matching those two fallbacks is the
    whole fix, which is why no guard had to be loosened and no signature changed.
    The comment above the stash now states the four keys as a CONTRACT and points
    here, because the failure mode is silent in both directions.
    VERIFIED ON THE REAL FILES (probe_stash_fixed.mjs, real core, real pdfs):
      SEGMENT  raw items -> map, months 1,2,3,4,5
               stash     -> map, months 1,2,3,4,5     IDENTICAL (deep-equal)
      ROOF     raw items -> NULL
               stash     -> NULL                      IDENTICAL
    The roof still refuses, and that is still correct — its self-check cannot pass
    until the summary fallback exists (§6). The point of this run is that the stash
    no longer CHANGES the answer; before it, every answer was null.
    FIXTURES ADDED (+2 JS, at the end of scurve-pdf-parser.test.mjs):
      1. "the stashed token shape derives exactly what raw pdf.js items do" —
         asserts deep-equality of map, months AND block between the two shapes, not
         merely that both are truthy. It carries a stashToken() helper that MUST be
         kept byte-for-byte in step with the wrapper; the comment says so.
      2. "dropping y or width from the stash breaks the derivation" — pins the two
         failure modes separately, because they are NOT the same failure:
           no y     -> derivePdfLayoutFromAi returns null (refuses outright)
           no width -> it SUCCEEDS, with every band drifted (measured: month 1 moved
                       from 0.6593 to 0.7317 of page width)
         The second is the dangerous one and was nearly mis-asserted as a refusal in
         the first draft of this fixture — the run corrected it. A stash that half
         works would teach a SKEWED layout rather than teach nothing, so the test
         asserts the geometry rather than the truthiness. Lesson banked: assert what
         a wrong shape PRODUCES, not that it fails.
    SUITES: JS 138 tests, 136 passed + the 2 intended blinding fails (F-B1 conditional
    and L3 hardcoded-ABC — both the documented pair, unchanged). PHP 1158 passed /
    the same 7 pre-existing failures. No core file touched, so sample-PDF rule reads
    are unchanged by construction.
    STILL NOT BROWSER-PROVEN, and it cannot be yet — read this before trying:
      - SEGMENT will show NOTHING, by design (the no-op documented below).
      - ROOF will show nothing either, because its self-check refuses until the
        summary fallback lands (§6).
      So PDF-3's browser proof is BLOCKED ON §6, not on this fix. Either build the
      summary fallback and prove both together on the roof, or find a third PDF whose
      rules miss and whose capture yields no map.

  2026-09-13 — PDF-3 IS DEAD IN THE BROWSER (one-line stash defect). Diagnosed
  read-only. NOTHING FIXED YET — this is the first thing to do next session.
    SYMPTOM (user): the SEGMENT PDF's first failed import auto-captures as designed,
    but clicking "Try AI assist" afterwards banks NO layout row at all.
    ROOT CAUSE: scurve-pdf-parser.js:54 stashes window._scurvePdfPageTokens as
    {str, x, xEnd}. The core reads NEITHER of those two spellings:
      groupItemsIntoLines uses item.transform[5] ?? item.y      (core :176) -> no y
      linesToTokenLines recomputes xEnd = x + (item.width || 0) (core :205) -> xEnd dropped
    With no y, EVERY token on a page collapses into ONE line at y=0. bandsHoldNumbers
    then has no rows beneath the header, refuses, and derivePdfLayoutFromAi returns
    null on every file, always. The POST is never reached.
    MEASURED on the real SEGMENT PDF (probe_stash.mjs):
      raw pdf.js items (what last session's node probe fed) : map, months 1,2,3,4,5
      _scurvePdfPageTokens shape (what the browser feeds)   : NULL
      lines on page 1 : raw 9  vs  stash 1
      xEnd round-trip : {"str":"CONSTRUCTION SCHEDULE AND S-CURVE","x":52.8,"xEnd":52.8}
    WHY NO TEST CAUGHT IT: tests/js/scurve-pdf-parser.test.mjs builds items through
    item(str,x,y,w) -> {str, transform, width}, i.e. the RAW shape, and last session's
    node probe fed raw pdf.js items too. NOTHING has ever fed derivePdfLayoutFromAi
    the shape the browser actually hands it. Same lesson class as P3: when a rule
    refuses, suspect the data feeding it before you touch the rule.
    THE FIX (one line, NO core change): stash {str, x, y, width} instead of
    {str, x, xEnd}. Both core helpers ALREADY have item.y / item.width fallbacks, so
    the compact stash then flows through unchanged — no signature churn, no relaxing
    of any guard.
    ADD WITH IT: a fixture that feeds derivePdfLayoutFromAi the STASH shape rather
    than raw items, or this exact defect returns the next time the stash is touched.

  2026-09-13 — SECOND, QUIETER REASON THE DESK LOOKED EMPTY (SEGMENT only).
    Even with the stash fixed, SEGMENT will show no new row, and that is by design.
    VERIFIED (probe_dedupe.mjs): the AI-derived block is BYTE-IDENTICAL to the
    auto-capture block — same normalized sha256, 23e7504edc68... — so storeAiDerived
    finds the existing capture row, and because that row already holds a column_map
    the `if ($row->column_map === null)` branch (ScurveHeaderProposal.php:86) is
    skipped entirely. No new row, no status change, not even an occurrences bump.
    Defensible (the capture already learned the same 2-page map, months 1-5, so there
    is genuinely nothing to add) but INVISIBLE, so the feature reads as broken even
    once it works. The family PDF-3 exists for is the roof, whose capture stores
    column_map = NULL and WOULD be backfilled — see §6 for why its self-check refuses
    today and what makes that refusal go away.
    OPEN UX QUESTION (needs a user decision, no code yet): should an ai-derived post
    that lands on an already-mapped row say anything — a desk note, an occurrences
    bump, or nothing? Silence is correct behaviour but poor feedback, and it cost
    this session's confusion.
    PROOF DISCIPLINE NOTE: SEGMENT is now the WRONG specimen for proving PDF-3,
    because its capture already supplies the same map. Prove PDF-3 on the ROOF (or a
    file whose capture is ambiguous), where the ai-derived row has something to add.

  2026-09-13 — ROOF FULLY DIAGNOSED (user's hunch about the source workbook, correct).
    New §6 holds the whole account with measurements. Headlines:
      - the roof EXCEL already parses to the exact truth curve (curveSource
        'activities', gap 0.00 on all 7 months) because it reads the workbook's
        HIDDEN columns AJ:AS, which hold ordinal month headers and cost-weighted
        peso amounts;
      - printing to PDF keeps only the visible area, so those columns never reach the
        page — the PDF is a LOSSY EXPORT, not a misread, and no column map can undo it;
      - the visible cells are per-activity TIME FRACTIONS over different denominators
        (=30/187, =23/115), which is why summing them gave 8.21 / 39.77 / 100 / 100;
      - but the printed cumulative row still carries the TRUE curve, and PDF-3's own
        AI-derived bands land on it exactly (M1..M6 on page 1, M7 on page 2);
      - so the roof has a zero-AI path after all: port the Excel's rule-3 summary
        fallback (SCurveImportService.php:1606) to the PDF core.
    This retires the earlier conclusion that "the roof family stays AI-only". It also
    REORDERS the remaining work: the fallback must come BEFORE any attempt to teach
    the roof, or PDF-4 would approve a layout that still reads the table wrong.

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
    - [CORRECTED 2026-09-14 late: no shape gate was ever built — see the top §9
      entry; bandsHoldLabels now does that job per page.]
      usableLayoutMap() gates version, kind AND SHAPE. The shape gate is not
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

2026-09-12 — PDF-2 CONSULT BROWSER-PROVEN, channels isolated (user).
    The re-test asked for above, run properly this time: both tables cleared, the
    capture redone, ONLY the layout approved. Database state at the moment of the
    import — checked, not assumed:
      scurveMonth words : 5 rows, ALL "proposed" (the loader fetches ?status=live,
                          so the word channel could not contribute a thing)
      pdf layout id=58  : LIVE, 2 pages — page 0 months 1,2 · page 1 months 3,4,5
    Result: the import carried "Heads-up: this PDF was read with a learned layout
    for this contractor…" and the TRUE curve 5 / 20 / 40 / 65 / 100 with the real
    names (Finishing spanning M4+M5).
    ⇒ Four things are proven at once, with the other channel provably out of play:
      1. consult fires when, and only when, the rules find nothing;
      2. the MULTI-PAGE map reads a COLUMN-SPLIT table — months 3,4,5 came from
         the map's second page entry, which is exactly the defect the user caught
         when the roof's "7 days" column exposed single-page capture;
      3. a map-built curve announces itself;
      4. approval is the gate: the same rows sat "proposed" and changed nothing.
    E1-PDF PDF-1 + PDF-2 are now BROWSER-PROVEN end to end, this time on evidence
    that identifies WHICH channel did the reading.

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
  10. CONVERSATION CONTINUATION GUIDE
===============================================================================

  1. Read §0 (status + ORDER OF WORK) first, then the §9 entries from the top —
     they run newest-first and the top few are all 2026-09-13. Everything in §0 is
     BUILT and COMMITTED as of 1b23422: P5, P6, and the AI-resilience run
     E4 → E4b → E5 → E6. NOTHING in this file is waiting to be written.
     NEXT ITEM: PDF-4 point-and-teach (§4), the last unbuilt phase of Stage 2 —
     but read §6 first, because the roof was the family PDF-4 existed for and the
     roof now reads correctly OFFLINE with zero AI, so its value has narrowed.
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

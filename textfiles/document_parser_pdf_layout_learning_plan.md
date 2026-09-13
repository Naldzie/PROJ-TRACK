# PDF S-CURVE: DELIVERY FIXES + LAYOUT LEARNING (E1-PDF)
# Parent refs: textfiles/document_parser_pdf_ai_fallback_plan.md (F-A..F-D + B — the PDF AI track)
#              textfiles/document_parser_excel_learning_loop_plan.md (E1/E1-3/Phase-A — the loop being mirrored)
#              textfiles/pdf_ai_loop_proof_tests.md (all live proof results)
# Date: September 12, 2026 (last updated September 13, 2026 — §6 roof diagnosis + §9 PDF-3 stash defect)
# Status: STAGE 1 ✅ · P3 ✅ · PDF-1 ✅ · PDF-2 ✅ — capture AND consult BROWSER-PROVEN 2026-09-12 (channels isolated)
#         PDF-3 ✅ · §6 SUMMARY-ROW ADOPTION ✅ BROWSER-PROVEN 2026-09-13 on both models
#         (roof reads its TRUE curve offline, zero AI) · PDF-4 ⬜
#         NEXT ORDERED: AI-vs-file cross-check for PDFs (Excel parity) — see §9
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

  ORDER OF WORK: ~~PDF-3 stash~~ DONE · ~~summary-row fallback (§6)~~ BUILT
  2026-09-13 → BROWSER-PROVE both on the ROOF (import on each flow: true curve,
  adoption warning, no AI call; then the PDF-3 harvest) → PDF-4. Each proved
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

  - PDF SUMMARY-ROW FALLBACK — port the Excel rule 3 (SCurveImportService.php:1606)
    to the PDF core so a PDF with no usable activity math adopts the author’s printed
    cumulative row, flagged and jumble-guarded, exactly as Excel does. This is the
    roof family’s only offline path; full evidence, the exact bands it lands on, and
    the unlabelled-continuation-page wrinkle are in §6. Needs an explicit user order:
    it is a NEW READER BEHAVIOUR, not a bug fix, and it must land BEFORE any attempt
    to teach the roof a layout.
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

  1. Read §0, then the 2026-09-13 entries in §9 — the PDF-3 stash defect is the next
     buildable item and it is one line. §6 (the roof) is the next DECISION.
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

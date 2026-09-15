# PDF TEACH GRID: MAKE THE RENDERED TABLE MATCH THE DOCUMENT (PDF-4 STAGE 2)
# Parent refs: textfiles/document_parser_pdf_layout_learning_plan.md (PDF-1..PDF-4, the
#              capture/consult/teach track this continues — read its §4 and the
#              2026-09-13 §9 entries first)
#              textfiles/document_parser_excel_learning_loop_plan.md (Phase-A — the teach
#              UI being reused)
# Date: September 13, 2026 (PDF closed September 14; Excel sweep continuing)
# Status: ✅ THE PDF SIDE IS CLOSED — §2 through §7 built 2026-09-13, §10 BROWSER-PROVEN
#         2026-09-14 after four rounds that found SEVEN defects, all fixed and all
#         re-proven. The roof reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100
#         (counts 4,4,4,4,3,4,3) through BOTH an approved AI-derived layout and a
#         hand-taught one. Reader output never moved — 16-read regression byte-identical.
#         Commits: 23abd20 (build) · 22edd71 (1-3) · 63862dd (4-6) · 4ffbcc0 (7) ·
#         7741341 (close).
#
#         ✅ THE SAME SWEEP ON THE EXCEL PATH IS CLOSED TOO (rounds five-seven, then the
#         row-role plan). ALL COMMITTED AND PUSHED as of 2026-09-14 late evening:
#           defects 8-11 ................ 293b302 · 3ce2f92
#           Excel row-role steps 1-6 .... 547ebb5 · 067c46d · e3c30d6 · 6711243 ·
#                                         b7bf35c · 141af76
#           header-row memory ........... 5e9ad07
#           defect 12 (picks on reopen) . 413f810
#           click-to-pick rows .......... 238a77c
#           latent-defect batch ......... cd2804a
#           teach logic under test ...... scurve-teach-core.js + 29 JS tests +
#                                         shared PHP/JS signature fixture
#           roof misfit-layout fix ...... bandsHoldLabels in matchMapPage
#         Row roles stay Excel-only BY DECISION (§11 Decision 4).
#         ➜ HANDOFF: the LAST §13 entry (2026-09-15, "GUARD MEASUREMENT PASS" — proposed
#           spec, awaiting build order). Read the entry before it too ("THREE FINDINGS
#           FROM A ONE-PAGE ROOF PRINT AND A PRINTED BUHI PDF", findings A-D). The
#           misfit-layout entry opens with a DATE NOTE: entries headed "2026-09-15" above
#           it were really written on 2026-09-14 local time.
#         NEXT ORDERED WORK (user, 2026-09-15): (1) the split-down guard — a PDF
#           printed down-then-across must stop reading as a confident baseline;
#           (3) PDF description-band teaching steps 1-3; item (2), full split-down
#           support, is PARKED. The early-100 guard queues behind both.
#         OWED BY THE USER (small): item-2 browser steps (Excel prefill on reopen;
#           staff layout); a re-printed BUHI specimen (details in the last §13 entry).
#           The one-page roof print is saved (…-S-CURVE-pdf2.pdf).
# Branch: wip/phase-5-parser-accuracy-premerge (Rule 12 — no merge to main without
#         explicit confirmation; this branch also carries an unrelated teammate merge)

===============================================================================
  0. READ THIS FIRST (handoff in 60 seconds)
===============================================================================

  WHAT THIS WAS
    The teach desk shows a reviewer a reconstructed table and asks them to point at
    its columns. That reconstruction was NOT an accurate picture of the document:
    whole columns collapsed into one, a label was split across two rows, the summary
    block was cut off before the cumulative row, only the first printed page was
    drawn, and the S-curve chart's own scale appeared as if it were cells.
    None of that ever affected an IMPORT — the reader parses the real file, and the
    roof has imported correctly throughout. It affected the human deciding whether to
    trust a layout, and it was why the roof could not be taught at all.

  ⚠️ WHERE IT STANDS RIGHT NOW — READ BEFORE TOUCHING ANYTHING
    §2–§7 are BUILT and COMMITTED as 23abd20, on branch
    wip/phase-5-parser-accuracy-premerge (NOT on phase-5-parser-accuracy, which is
    still clean at 959f0ec). That branch also carries 06d89cf, an unrelated merge of
    a teammate's dashboard work — so it mixes two bodies of work and is not a clean
    thing to PR. The old "ELEVEN uncommitted files, check with git status" note that
    stood here was true on 2026-09-13 and is now wrong; `git show --stat 23abd20`
    is the list.

    §10 IS DONE AND PASSED (2026-09-14, after four rounds). The grid itself passed on
    the first run; SEVEN defects surfaced across the rounds, all fixed and all
    re-proven in the browser — commits 22edd71, 63862dd, 4ffbcc0. Six of the seven
    were NOT in the code §2–§7 wrote but in the code that spoke to it and was not
    updated with it. Read the four 2026-09-14 entries in §13 before touching the
    teach path — they carry the measurements, not just the verdicts.

    The delete-confirmation change is NOT part of this plan — it is a separate
    blocking bug found while testing it (§13, round two) and it rides in 23abd20.

  STATE OF PROOF  ✅ BROWSER-PROVEN 2026-09-14
    The roof reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 with counts
    4,4,4,4,3,4,3 through BOTH an approved AI-derived layout and a hand-taught one.
    Suites: PHP 1194 passed / the same 5 pre-existing BY NAME (the 2 Contractor-
    Dashboard failures were fixed by the teammate merge in 06d89cf, not here);
    JS 158 tests, 156 passed + the 2 intended blinding fails.
    Reader regression: 16 reads, 0 changed — the reader never moved throughout.

  THE ONE THING THAT WILL LOOK LIKE A BUG AND IS NOT
    A capture stored BEFORE this work still renders the old way: 15 rows, 2 summary
    rows, page one only, no month 7. The block text feeds header_hash, so a stored
    row cannot retro-fit itself. RE-IMPORT the file to replace it — that is the
    one-time re-capture wave of §8, and it will produce a second proposed row.
    Approve one, reject the other. (This is exactly what the user hit on first look;
    the live row #73 was captured pre-change and had maxMonth=7 in its MAP but only
    eight columns in its BLOCK, which is why the palette stopped at M5.)

  WHY IT MATTERS
    Only TWO real contractor files exist for testing (the roof and BUHI). The roof is
    the only multi-page PDF specimen, so making it teachable is also what makes the
    teach path testable end to end.

  ORDER OF WORK: ~~§2 column detection → §3 page merge → §4 summary rows → §5 chart
  exclusion → §6 modal layout → §7 ambiguous geometry~~ ALL BUILT 2026-09-13 — see
  §13 for what shipped, for the two column rules that were tried and rejected first,
  and for the three self-inflicted bugs worth not repeating.
  REMAINING: nothing. §10 passed 2026-09-14; the seven defects it found are fixed and
  browser-proven. Rule 10 applies before anything new.

  HARD RULES
    - Blinding stays ON (L3_TEST_BLIND_ABC = true). JS showing 2 failures is green.
    - NOTHING here may change what the READER extracts. The 16-read sample regression
      (C:\temp\opencode\verify_p6.mjs) must stay byte-identical — re-run it after any
      touch to scurve-pdf-parser-core.js.
    - NEVER build source containing a REGEX through a shell-quoted string. It ate the
      backslashes three separate times in this track; write it with the file editor
      or from a heredoc'd file, then grep the emitted pattern before trusting a run.
    - Sample files live in git-ignored sampledocs/ — reference by name, never commit.
    - One-off probes go in C:\temp\opencode, outside the repo.
    - Log behavioural changes here before committing (Rule 11).

  VERIFY COMMANDS
    npm run test:js        -> 158 tests, 156 passed + 2 intended blinding fails
    php artisan test       -> 1194 passed / the same 5 pre-existing failures
    node C:\temp\opencode\verify_p6.mjs   -> "16 reads compared … 0 changed"
    node C:\temp\opencode\probe_block.mjs -> the roof's grid as the desk will draw it
    php artisan view:clear && php artisan view:cache   after ANY Blade edit

===============================================================================
  1. THE PROBLEM, MEASURED (roof PDF, read-only probe)
===============================================================================

  probe: C:\temp\opencode\probe_gridfidelity.mjs

  THE THREE HEADER LINES AS PRINTED
    R1  ITEM@33.5     Unit Cost/Contract@248.6   187 days@573.7
    R2  DESCRITPION@97.5   UNIT@187.8   QTY.@218.3
    R3  NO.@35.4   Amount@262.0   30 days@337.6 … 755.3

  BANDS THE CURRENT RULE BUILDS (header line R3's tokens only)
    A   35.4 -> 262.0   ("NO.")        <- a 227pt band
    B  262.0 -> 337.6   ("Amount")
    C..H  the six month columns

  X-POSITIONS THE TABLE ACTUALLY USES (all block rows clustered at 4pt)
     33.5  ITEM / NO.                  <- the item-code column
     38.6  A / B / C / E / H           <- item letters
     53.9  the work names
    187.8  UNIT  … and "LOT" in data rows      (6 occurrences)
    217.5  QTY.  … and "1.00"                  (6 occurrences)
    ~262   Amount — RIGHT-ALIGNED, so x drifts 257 / 262 / 263 / 267
    369.3 / 452.8 / 536.4 / 619.9 / 703.4   the five months on this page

  ROOT CAUSE: pseudoCols are derived from the HEADER LINE ALONE
  (derivePdfLayout, scurve-pdf-parser-core.js ~:1685). R3 prints "NO." and then
  jumps to "Amount", so everything between them becomes ONE band — and DESCRITPION,
  UNIT and QTY., which are printed on R2, collapse into it. "ITEM" (R1) and "NO."
  (R3) are one logical label split across two printed lines, which is why the
  reviewer sees it in two places.
  USER'S WORDS, and they are exactly right: "item no, description, unit, qty,
  unitcost are all a header and are in the header row. but what is being rendered is
  that item in r1 cA, the description, unit and qty all in one cell r2 cA, No (which
  is part of item no) is on r3 cA".

  THREE MORE GAPS FOUND WITH IT
    (a) SUMMARY ROWS ARE CUT OFF. The 15-row window ends at "R15 [SUMMARY]: %" — the
        PER-MONTH row. The CUMULATIVE row (6.32/24.31/42.30/59.71/77.03/99.14) is
        R16. The row the baseline is adopted from is invisible at the exact moment
        someone decides to trust the layout. On the roof EXCEL it is worse: a 15-row
        capture ends before ANY summary row ([SUMMARY] count = 0), because ten rows
        are spent on activity/LOT pairs.
    (b) ONLY PAGE 1 IS DRAWN. The roof's months 6 and 7 are on page 2, so the palette
        can only reach M1–M5. The multi-page teach refusal (built 2026-09-13)
        correctly blocks saving that, which is why teaching the roof is impossible
        today rather than merely wrong.
    (c) CHART FURNITURE IS DRAWN AS DATA. Stray rows holding only 100% / 75 % / 25%,
        and a "0% 50%" cell where the chart's axis overlaps C EARTHWORK's real value.
        P6's chartScaleTokens already excludes these from the READER; the block
        builder does not consult it.

===============================================================================
  2. COLUMN DETECTION FROM THE WHOLE BLOCK  ✅ BUILT (corrected — see §13)
===============================================================================

  Cluster token x-positions across EVERY row in the block, not just the header line.

  THE WRINKLE, and it is the part to get right: right-aligned numeric columns drift.
  The amount column above appears at 257.1 / 262.0 / 263.3 / 267.5 — the same logical
  column, four x values. Clustering on x alone over-splits it.

  RULE AS PLANNED: cluster on x, then merge adjacent clusters whenever merging puts
  no two tokens FROM THE SAME ROW into one cell.
  ⚠️ THAT RULE ALONE WAS NOT ENOUGH — see §13 for the two corrections measurement
  forced. In short: a lone "₱" needed excluding (it shares the TOTAL row with its own
  amount, so it could never merge and split the months in half), the metadata lines
  above the header needed excluding (their label/value pairs tore real columns
  apart), and no-collision had to be joined by OVERLAP — two neighbours are one
  column when their strips substantially cover each other. That last part is what
  rejoins a right-aligned money column while keeping DESCRIPTION and UNIT apart,
  which no-collision alone could not do because they never share a row either.

  pseudoCols keeps its {letter, x, xEnd} shape, so buildTaughtPdfMap (already built)
  needs no change. columnLetter() keeps naming them.

===============================================================================
  3. MERGE THE PAGES INTO ONE GRID  ✅ BUILT
===============================================================================

  PROVEN FEASIBLE 2026-09-13 (probe_merge_grid.mjs). Pairing the roof's two pages by
  row height — the P3 property that column-split prints share row Y — yields one
  coherent table:

    A FACILITIES FOR ENGINEER   16% 17% 17% 16% 17% | 16%  2%
    C EARTHWORK                 20% 27% 27% 26%  0% |  0%  0%
    H OTHER WORKS                0%  0%  0%  0%  0% | 88% 12%
    %  6.32% 24.31% 42.30% 59.71% 77.03% 99.14%     | 99.14% 100.00%

  All seven months AND the complete cumulative row, in one grid.

  ANCHOR PER PAGE, not on a shared origin: the two header lines sit 1.44pt apart
  (417.67 vs 416.23), so align each page by the offset from its OWN header. Reuse the
  matchRowsByY concept from P3.

  LETTERS BECOME GLOBALLY UNIQUE across pages (page 1 = A…H, page 2 = I…J). This is
  what keeps schParseHeaderBlock and parseStoredSlice working UNCHANGED — one legend,
  one R-numbering. Each page's pseudoCols records only its own letters, so
  buildTaughtPdfMap can group taught letters back by page and emit a multi-page map.

  THEN LIFT THE MULTI-PAGE REFUSAL in buildTaughtPdfMap
  (SCurveHeaderProposalController): it exists precisely because the grid could not
  show later pages, and that stops being true here.

===============================================================================
  4. SUMMARY-AWARE ROW BUDGET  ✅ BUILT
===============================================================================

  Fill the budget with header and activity rows as now, then ALWAYS append rows
  tagged [SUMMARY] even past it. The reviewer always sees the cumulative row, TOTAL
  and Cash Flow — the evidence that justifies the layout — without the block growing
  on files that carry none.

  Applies to BOTH sides: buildPdfSliceBlock (PDF) and
  SCurveImportService::keptRowKeys (used by serializeForAi, which the Excel capture
  calls with 15).

  NOTE the AI paths do NOT share this problem and need no change: the Excel AI slice
  uses 40 rows, and the PDF AI sends page IMAGES plus each page's whole text, so
  neither is bounded by the capture window.

===============================================================================
  5. KEEP CHART FURNITURE OUT OF THE GRID  ✅ BUILT
===============================================================================

  Reuse P6's chartScaleTokens in buildPdfSliceBlock. Measured at ZERO false positives
  across all 8 sample PDFs (71 vertical numeric stacks examined, exactly one matched),
  and it is the same definition the reader already uses — one answer to "this is a
  picture, not a cell", rather than two that can drift.

===============================================================================
  6. TEACH MODAL LAYOUT  ✅ BUILT  (user-selected pain points, 2026-09-13)
===============================================================================

  resources/views/admin/_manage_scurve_headers_modal.blade.php

  - HEADER-ROW PICKER COLLAPSES to one line naming the detected row, with a "change"
    affordance. It is auto-detected and rarely altered, yet today it is a
    fifteen-checkbox column consuming most of the left side.
  - MONTH BUTTONS FOLLOW THE CAPTURE: show as many as the layout plausibly has (from
    its own column count) plus an "add month" control, instead of a fixed wall of
    twelve that pushes the grid down.
  - THE GRID GETS THE FREED WIDTH. It is the click surface and the whole point of the
    screen; today it sits in a narrow column scrolling both ways.

===============================================================================
  7. AMBIGUOUS CAPTURES KEEP THEIR GEOMETRY  ✅ BUILT
===============================================================================

  derivePdfLayout returns map: null for positional wording and DISCARDS the whole
  `found` array, pseudoCols with it (core ~:1736). Store the geometry with the months
  left UNNUMBERED instead, so such a capture is teachable rather than a dead row.
  This is what makes the null-map-then-teach path real — the same path Excel captures
  have always used.

===============================================================================
  8. RE-CAPTURE WAVE (expected, not a defect)
===============================================================================

  The block text feeds header_hash, so existing rows will re-capture ONCE as a second
  proposed row on the next import of the same file. Approve one, reject the other.
  Documented incident class — it happened with the slice reform and again with the
  off-by-one roles fix. Doing §2–§5 together costs ONE wave instead of four.

===============================================================================
  9. TESTS
===============================================================================

  - clustering recovers UNIT and QTY as their own columns on the roof shape, and the
    right-aligned amount column stays ONE column;
  - merging two pages yields one row set with all months and globally-unique letters;
  - chart-scale tokens never reach the block;
  - a file whose summary rows sit past the budget still shows them (both formats);
  - a taught multi-page selection produces a MULTI-PAGE PDF map, and the refusal is
    gone;
  - an ambiguous capture is now teachable (geometry retained, months unnumbered);
  - Excel teaching still produces an Excel map — the source_type gate holds.

===============================================================================
  10. VERIFICATION
===============================================================================

  1. php artisan test  -> the same 7 pre-existing failures BY NAME.
     npm run test:js   -> the 2 intended blinding fails and nothing else.
  2. THE 16-READ SAMPLE REGRESSION MUST STAY BYTE-IDENTICAL. None of this touches the
     reader; C:\temp\opencode\verify_p6.mjs already performs exactly this comparison.
  3. BROWSER, THE ROOF — and no test-only code is needed, because the roof already
     misses the RULES and only reads through its approved layout. Set that layout back
     to proposed, then re-import:
       - the capture grid should show ITEM NO. / DESCRIPTION / UNIT / QTY / Amount as
         SEPARATE columns, all seven months, and the cumulative row;
       - teach all seven months -> save (no refusal) -> approve;
       - re-import reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 with
         activity counts 4,4,4,4,3,4,3.
  4. EXCEL SANITY: a BUHI capture should now show its summary block, where a 15-row
     capture previously stopped before reaching any summary row at all.

===============================================================================
  11. DECISION LOG
===============================================================================

  Decision 1 — Fidelity before arrangement (user, 2026-09-13).
    Context: the modal's layout and its CONTENT are both wrong.
    Decision: fix what is drawn (§2–§5) as the substance; the layout work (§6) rides
      with it rather than leading.
    Rationale: a prettier arrangement of a wrong table is still a wrong table.

  Decision 2 — Globally-unique letters over per-page sections (2026-09-13).
    Options: (a) per-page legends and a sectioned grid; (b) one legend whose letters
      continue across pages.
    Decision: (b). Rationale: schParseHeaderBlock, parseStoredSlice and the whole
      Phase-A teach UI keep working untouched, and a column-split print read
      left-to-right IS one wide table — the merged view matches how a human reads the
      two sheets side by side.

  Decision 3 — No ladder loop-back (2026-09-13), recorded here because it was asked
    in the same session. The 19:13 run spent 46s of a 50s budget, and the budget is
    capped by PHP's 60s ceiling, so a second pass cannot fit without raising
    max_execution_time — which would leave a user watching a spinner for two minutes
    for an answer four models have already declined.

  Decision 4 — Row roles ("Not an activity" / "Cumulative row") stay EXCEL-ONLY
    (user asked 2026-09-15 whether leaving PDF out is better; answer: yes, for now).
    Context: the Excel row-role track shipped end to end; PDF rows show both buttons
      disabled with the reason.
    Options: (a) extend teaching to PDF; (b) keep Excel-only, revisit on evidence.
    Decision: (b).
    Rationale:
      - NOTHING TO FIX TODAY. All 8 sample PDFs read correctly (16-read regression);
        no PDF in the corpus sums a summary row as work. The Excel feature exists
        because a wrongly summed row bends every month SILENTLY — the PDF reader
        already has its own guards for exactly that: cumulative detection by SHAPE
        (percent cells, non-decreasing, ends >= 50 — label-independent, so novel
        wordings are already covered), the adopted-path filter (named rows with
        percent cells only), P2-c wordless-summary exclusion, P6 chart-scale
        exclusion, and the "N unnamed value rows left out" warning.
      - THE MATCHING KEY DOES NOT CARRY OVER. Excel roles are stored as text
        signatures and matched against sheet rows. A PDF row is a y-band of tokens;
        on a column-split print the continuation page's rows are NAMELESS (the label
        prints on page 1 only — the roof's page-2 cumulative row is a bare
        "99.14% 100.00%"), so a text signature cannot find them. A PDF version would
        need a new key (y-offset from the header, per page) that must survive a
        different page size — new design, new failure modes, on the reader the user
        spent four acceptance rounds stabilising.
      - Server and UI already refuse it consistently (explicit 422 + disabled
        buttons with a reason), so the gap is honest, not half-wired.
    Revisit when: a real PDF reads wrong because a summary row is summed as an
      activity, or its running-total row is not percent-written (shape detection
      would miss it). Bring that file first; design the key against it.

===============================================================================
  13. BUILD LOG
===============================================================================

  2026-09-13 — §2, §3, §4, §5, §6, §7 ✅ BUILT (code, UNCOMMITTED per user).
  Everything in this plan except the re-capture wave, which happens by itself on
  the next import. Nothing here changed what the reader extracts — the 16-read
  sample regression is byte-identical against the committed core, checked after
  every step.

    §2 COLUMN DETECTION FROM THE WHOLE TABLE — detectPseudoColumns(), exported.
    Reaching the right rule took three measured attempts, and the failures are
    worth keeping because each one looked correct on paper:
      (a) cluster every token, merge neighbours that never share a row. Gave the
          roof its ITEM NO. / DESCRIPTION / UNIT / QTY / Amount columns — but grew
          SEVEN spurious columns, because the TOTAL row prints "₱" before every
          figure and a mark and its own amount share that row, so each ₱ claimed a
          strip and split the months in half. FIX: a token with no letter or digit
          is decoration and gets no vote. It still renders.
      (b) the metadata lines above the header then tore real columns apart — the
          SEGMENT specimen's ITEM NO. header separated from its own 1/2/3 values,
          because a "CONTRACT NAME: …" label above happened to share their rows.
          FIX: detection starts AT the header line, never above it. A multi-line
          header loses nothing: the roof's UNIT and QTY. labels sit on a metadata
          line, but the same columns reprint as "LOT" and "1.00" on every activity
          row.
      (c) requiring shared alignment (same left or right edge) to merge was too
          strict and split every centred header from its right-aligned values.
          REJECTED. What works is OVERLAP: two neighbours are one column when their
          strips substantially cover each other. A right-aligned money column drifts
          left as figures widen (257 / 262 / 267) but all of it ends at ~305, so the
          strips coincide; the roof's DESCRIPTION and UNIT never co-occur either —
          each activity prints its name on one line and "LOT 1.00 amount" on the
          next — but their strips barely touch, so they stay apart.
    MEASURED, roof page 1: the old rule gave 8 columns with one 227pt band holding
    four of them; the new rule gives ITEM NO. | DESCRIPTION | UNIT | QTY | Amount |
    six months. SEGMENT and the 6-stage specimen both improved as well.

    §3 ONE GRID FOR THE WHOLE TABLE — buildMergedSliceBlock(). Pages are paired by
    each row's offset from its OWN page's header (the roof's two headers sit 1.44pt
    apart, so a shared origin drifts). Letters run on across the seam — page 1 = A…K,
    page 2 = L…M — which is what keeps ONE legend and ONE R-numbering, so
    schParseHeaderBlock, parseStoredSlice and the whole Phase-A teach UI work
    untouched. The multi-page teach refusal built earlier the same day is therefore
    LIFTED, and buildTaughtPdfMap now groups taught letters by the page they belong
    to and emits a multi-page map. A month given columns from two different pages is
    refused: a month lives on one sheet.

    §4 SUMMARY ROWS SURVIVE THE BUDGET. The block is scanned whole, then the budget
    buys the head of the table and every [SUMMARY] row is appended wherever it fell.
    On the roof that is the difference between stopping at the per-month "%" line and
    showing R16 — 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100.00, the row the
    baseline is actually adopted from.

    §5 CHART FURNITURE EXCLUDED, reusing P6's chartScaleTokens in both block
    builders. The phantom rows holding only "100%" or "25%" are gone, and so is the
    stray "50%" that shared a cell with a real value.

    §6 MODAL LAYOUT. The grid is the click surface, so it now spans the full width
    with the palette in a band above it. The header-row picker collapses to one line
    naming the detected row with a "change" toggle, instead of a fifteen-checkbox
    column. Month buttons are built on demand from what the capture suggests, with a
    "+ month" control, rather than a fixed wall of twelve pushing the grid off screen.

    §7 AMBIGUOUS CAPTURES KEEP THEIR GEOMETRY. They used to lose the map AND the
    pseudoCols with it, so the rows this path exists to hand a reviewer arrived with
    letters and cell text but not one coordinate. Now the geometry is stored with
    monthCols emptied and unnumbered: true — the numbering is the reviewer's to
    supply. VERIFIED that it is still unreadable until taught: usableLayoutMap
    refuses a month-less page, so an untaught capture is an offer, never a baseline.

    RESULT ON THE ROOF (real file, real core): the block the desk renders is now
      COLUMNS: A…M
      R3  [HEADER]  NO. | Amount | 30 days ×6 | 7 days      <- all SEVEN months
      R4  [DATA]    A | FACILITIES FOR ENGINEER | … | 16% | 2%
      R5  [DATA]    LOT | 1.00 | 14,306.44
      R16 [SUMMARY] % | 6.32% | 24.31% | … | 99.14% | 100.00%
    ITEM NO., DESCRIPTION, UNIT, QTY and Amount are separate columns; both printed
    pages are one table; the cumulative row is visible.

    CAUGHT WHILE TESTING (mine): the first merged-grid fixture used two repeated
    "30 days" labels and the capture refused it — positional wording needs THREE
    repetitions before it will be believed, which is the guard working. Rewritten
    with indexed labels, which number themselves.

    TESTS +5 JS (157 total): columns come from the whole table not the header line;
    a right-aligned money column stays one column; the chart scale never reaches the
    grid; summary rows survive past the budget; both pages render as one grid with
    unique letters. +1 PHP net — the multi-page REFUSAL fixture became a multi-page
    TEACHES fixture, and a new one pins that a month spanning two pages is refused
    without writing. The old "positional split has NO map" fixture now pins the new
    contract: geometry kept, numbering absent, still unreadable until taught.

    SUITES: PHP 1185 passed / the same 7 pre-existing failures BY NAME.
    JS 157 tests, 155 passed + the 2 intended blinding fails.
    READER REGRESSION: 16 sample reads, 0 changed.

    NOT BROWSER-PROVEN. The acceptance run is §10: set the roof's live layout back
    to proposed so the rules miss, re-import, and the capture's grid should show the
    five left-hand columns separately, all seven months, and the cumulative row —
    then teach all seven, save (no refusal), approve, and re-import to
    6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 with counts 4,4,4,4,3,4,3.
    EXPECT A ONE-TIME RE-CAPTURE WAVE (§8): the block text feeds header_hash, so the
    existing rows re-capture once as a second proposed row. Approve one, reject the
    other.

  2026-09-13 (same day, second round) — THREE THINGS FROM THE USER'S FIRST REAL
  LOOK AT THE NEW GRID. Code, still UNCOMMITTED.

    (1) A BLOCKING BUG, NOT OURS BUT IN OUR WAY: "showDeleteConfirmation is not
    defined" on the dashboard, so a captured layout could not be rejected at all.
    CAUSE: the Learned Keywords and S-Curve Layouts desks are included by the
    LAYOUTS (app.blade.php, stafflayouts.blade.php) and can therefore be opened from
    any page — but the component that defines showDeleteConfirmation was included
    PER PAGE (projects, archived, index, baseline-schedule). On the dashboard it was
    simply absent. A layout-wide feature depending on a page-level include.
    FIX: include components/delete-confirmation-modal in both layouts beside the
    desks. It could not simply be added, because four pages already include it and
    the component declares showDeleteConfirmation and a module-level callback at the
    top level of its <script> — a second copy throws "Identifier already declared"
    and kills the whole block. So the component is wrapped in @once first, which
    renders it exactly once however many times it is included. Verified in the
    compiled views: one guard, one render.
    LESSON: when a feature moves into the layout, its dependencies have to move with
    it. This one sat undetected because every page that had been TESTED with the desk
    happened to include the component for its own reasons.

    (2) TWO ROWS BOTH LABELLED "%" — the user's point, and a real trap. A schedule
    prints the per-month share and the running total one above the other, both
    labelled "%", and only the second is what a baseline is adopted from. In the grid
    they were indistinguishable, and this family gives the cumulative row no reachable
    label at all (the word "Commulative" prints 4.6pt below its own figures and lands
    on a line of its own — the same Y_TOLERANCE quirk §6 of the parent plan documents).
    FIX: isCumulativeShapedRow() tags it by SHAPE, exactly as the reader identifies it
    — percent cells, rising, finishing near completion — and the desk renders a green
    CUMULATIVE badge distinct from the amber SUMMARY.
    NON-DECREASING, not strictly ascending, and the reason is the merge: the seam
    repeats month 6 (printed as page one's last column and page two's first), so the
    row reads "… 99.14, 99.14, 100.00". A rise overall still separates it from the
    per-month row, which FALLS (17.99 -> 17.41) and fails whatever the seam does.

    (3) THE USER'S GRID STILL SHOWED 15 ROWS, 2 SUMMARY ROWS AND NO MONTH 7 —
    and that is STALE STORED DATA, not the new code. Checked against the live row:
      #73 ai-derived proposed  rows=15  summaryRows=2  maxMonth=7  COLUMNS: A..H
    captured before this work. Its MAP already spans two pages (months to 7) while
    its BLOCK is page-one-only with eight columns, which is exactly why the palette
    could reach M1-M5 and no further. A fresh capture of the same file now yields
    COLUMNS A..M, all seven month labels on the header row, and the cumulative row.
    ⇒ This is the re-capture wave of §8 arriving. Re-import to replace the row.

    CAUGHT PRE-GREEN (2, both mine, and the first is a repeat offence)
    (a) THE REGEX LOST ITS BACKSLASHES AGAIN. Building the helper through a shell
        -quoted node -e turned /^\d[\d,]*(\.\d+)?\s*%$/ into /^d[d,]*(.d+)?s*%$/, so
        the predicate matched nothing and the row stayed SUMMARY. THIRD time this
        class has bitten in this track (E4b tests, the P6 simulation, now this).
        STANDING RULE, adopt it: never build source containing a regex through a
        shell-quoted string — write it with the file editor, or from a heredoc'd
        file. After any such edit, grep the emitted regex before trusting a result.
        An audit of every file touched this session found no other mangled pattern.
    (b) TAGGING THE ROW THEN DELETED IT. The keep-past-budget filter was
        `k < maxRows || role === 'SUMMARY'`, so renaming the role to CUMULATIVE
        dropped the very row the rename existed to highlight — it vanished from the
        block entirely. Caught by re-running the probe rather than by reasoning.
        Both builders now keep SUMMARY and CUMULATIVE alike.

    SUITES AFTER THIS ROUND: PHP 1185 passed / the same 7 pre-existing BY NAME.
    JS 157 tests, 155 passed + the 2 intended blinding fails.
    READER REGRESSION: 16 sample reads, 0 changed.


  2026-09-14 — §10 BROWSER ACCEPTANCE RUN (user, roof PDF). THE TEST DID ITS JOB:
  the grid renders correctly and THREE REAL DEFECTS SURFACED, one of them a
  regression this very plan introduced. Evidence is the live DB (rows 76-79) plus a
  read-only probe against the real file (C:\temp\opencode\probe_teach78.mjs,
  probe_variants.mjs). Nothing below is inferred.

    WHAT PASSED, and it is the substance of §2-§5
      The auto-capture block (row 76) reads exactly as designed:
        COLUMNS: A..M  (11 on page 1 + 2 on page 2, letters running on)
        R3 [HEADER]: NO. | Amount | 30 days x7 | 7 days     <- all SEVEN months
        R16 [CUMULATIVE]: % | 6.32% | 24.31% | ... | 99.14% | 100.00%
      ITEM NO. / DESCRITPION / UNIT / QTY. / Amount are SEPARATE columns, both
      printed pages are one table, the summary block survives the budget and the
      cumulative row carries its own badge. §2, §3, §4 and §5 are browser-proven.
      The user also spotted, unaided, that column K is a PHANTOM "30 days" — the
      grid made a defect in the DOCUMENT visible, which is what it is for.

    DEFECT 1 — TEACHING CANNOT EXPRESS A MULTI-COLUMN DESCRIPTION BAND.
    THIS IS A REGRESSION INTRODUCED BY §2, and it is the blocker.
      The user taught Description=A, Contract amount=E, M1..M7 = F,G,H,I,J,L,M —
      the months EXACTLY right, K correctly skipped. The read came back
      2.48/10.8/22.65/37.81/56.35/80.18/100 (curveSource 'activities', contract
      11,396,769.88, ten "Activity N" rows) against the truth
      6.32/24.31/42.30/59.71/77.03/99.14/100.
      MEASURED, three maps through the real core on the real file:
        map 77 ai-derived      6.32/24.31/42.3/59.71/77.03/99.14/100  summary
        map 78 as taught       2.48/10.8/22.65/37.81/56.35/80.18/100  activities
        map 78 + desc=B only   2.48/10.8/22.65/37.81/56.35/80.18/100  activities
        map 78 + desc from 77  6.32/24.31/42.3/59.71/77.03/99.14/100  summary
      The DESCRIPTION BAND IS THE SOLE DRIVER. Every other band the user taught is
      fine; swapping in the derived desc band alone restores the exact truth curve.
      ROOT CAUSE: buildTaughtPdfMap sets descX/descRight to ONE pseudo-column
      ($desc['x'] / $desc['xEnd']). Every DERIVED map computes descRight from header
      geometry instead — the boundary before the Amount column — which on the roof
      spans A through D (0.0445 -> 0.3296), FOUR pseudo-columns. A single letter
      cannot express that, and the desk offers no way to say "A through D".
      WHY IT IS OURS: before §2 the old rule collapsed that whole left block into
      ONE 227pt band, so one letter WAS the description column. Splitting it into
      ITEM NO./DESCRIPTION/UNIT/QTY is right for the reviewer and silently broke the
      single-letter teach contract. The lesson is general and worth keeping: CHANGING
      WHAT A LETTER MEANS CHANGES EVERY CONTRACT THAT SPEAKS IN LETTERS.

    DEFECT 2 — derivePdfLayoutFromAi NEVER GOT §2/§3.
      Row 77's MAP is correct (2 pages, M1-M5 / M6-M7) but its BLOCK is page-one
      only (COLUMNS A..K, six month labels, no month 7) and its page-2 pseudoCols
      restart their letters at A,B instead of continuing L,M. In the teach modal M6
      therefore lands on letter A — the same letter as the description column, which
      is exactly the "duplicate month 1 label" the user reported.
      CAUSE: core :2373 uses columnLetter(k) with k restarting per page, and :2381
      calls buildPdfSliceBlock for the FIRST page only. The auto-capture twin at
      :1773/:1767 uses columnLetter(letterAt + j) and buildMergedSliceBlock. §2/§3
      updated derivePdfLayout and left its AI-derived sibling behind.
      CONSEQUENCE: an ai-derived row is effectively un-teachable — the reviewer
      cannot see or point at the months that live on page 2.

    DEFECT 3 — AN UNNUMBERED CAPTURE CAN BE APPROVED, AND NOTHING SAYS IT IS A NO-OP.
      Row 76 is the §7 ambiguous capture: geometry kept, monthCols [], unnumbered
      true. The desk rendered it like any other row, the user approved it, and the
      re-import answered "could not read the S-curve" — usableLayoutMap correctly
      refuses a month-less page. The READER is right; the DESK is silent. Grepped:
      'unnumbered' appears at core :1757 and once in the controller, and NOWHERE in
      _manage_scurve_headers_modal.blade.php.

    ALSO FOUND, NOT PART OF THIS PLAN (latent, no live symptom): storeAiDerived
    matches on (header_hash, contractor_id) with NO source_type scope, while the
    auto-capture path 30 lines below it scopes by source_type precisely so "an Excel
    row and a PDF row must never merge". The controller patches source_type after
    the fact. A colliding hash could hijack an Excel row into a PDF one.

    WHAT THE USER'S OWN QUESTION EXPOSED, answered from the code: AI assist does not
    "overwrite when the layout differs" — storeAiDerived NEVER overwrites an existing
    map. It backfills a NULL map, or creates a SEPARATE row when the hash differs. It
    never compares layouts at all. The two rows here have different hashes precisely
    BECAUSE of Defect 2 (the AI block is page-one-only), so they could never have
    merged.


  2026-09-14 — ALL THREE DEFECTS FIXED (code; browser re-proof is the user's step).

    FIX 1 — A TAUGHT DESCRIPTION IS A BAND, NOT A COLUMN.
    buildTaughtPdfMap now widens descRight to the left edge of the NEAREST thing the
    reviewer assigned to something else on that page — contract, weight, code, or the
    first month. Their own answers bound it, so nothing is guessed, and the widening
    can never reach a month band (the existing overlap guard still runs after it).
      MEASURED — the user's EXACT teaching (desc=A, contract=E, M1..M7 = F,G,H,I,J,L,M)
      replayed through the real builder and the real core on the real roof PDF:
        before   desc 0.038 -> 0.0676   2.48 / 10.8 / 22.65 / 37.81 / 56.35 / 80.18 / 100
        after    desc 0.038 -> 0.3235   6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100
                                        curveSource 'summary', counts 4,4,4,4,3,4,3
      The reviewer changes NOTHING. The clicks they already made now read the truth.
      A FIXTURE HAD TO CHANGE, and it is worth saying why rather than hiding it: the
      PDF teach fixture taught no contract column, so its desc widened over the
      untaught AMOUNT column and its pinned descRight moved 0.30 -> 0.38. The fixture
      now teaches contract='B', which is what a reviewer looking at
      DESCRIPTION | AMOUNT | STINT 1 | STINT 2 would actually say, and the original
      0.30 assertion stands. The widening is pinned by three NEW tests instead.

    FIX 2 — derivePdfLayoutFromAi GETS §2/§3.
    It now collects `sheets` like its twin, calls buildMergedSliceBlock, and re-letters
    pseudoCols with a RUNNING offset. On the real roof PDF the AI-derived row now
    produces exactly what the auto-capture produces:
        page[0] letters A..K  months M1-M5      page[1] letters L,M  months M6,M7
        COLUMNS: A,B,C,D,E,F,G,H,I,J,K,L,M
        R3  [HEADER]     NO. | Amount | 30 days x7 | 7 days
        R16 [CUMULATIVE] % | 6.32% | ... | 99.14% | 100.00%
      Was: COLUMNS A..K, six month labels, no month 7, page-2 letters restarting at A,B.

    FIX 3 — THE DESK SAYS SO WHEN APPROVAL IS A NO-OP.
    An unnumbered capture (every page month-less, or unnumbered:true) now renders an
    amber "Months not numbered — approving alone will not read this file. Teach it
    first." in place of the "page 1: no months" line. Also corrected a stale tooltip
    that still claimed multi-page teaching was refused — §3 lifted that refusal.

    TESTS  +3 PHP (widen-to-contract, widen-to-first-month-when-no-contract, widening
    never overlaps a month) -> ScurveHeaderProposalTest 60 passed.
    +1 JS (an AI-derived layout merges its pages and never reuses a letter) -> 158
    tests, 156 passed + the 2 intended blinding fails.
      FAILING-THEN-PASSING CONFIRMED on the JS one: stashing only the core file makes
      it fail with "no letter may name a column on both pages".
      CAUGHT WHILE WRITING IT (mine, twice): the first three fixture attempts returned
      null and I blamed the change — they were malformed, and the CLEAN tree returned
      null too. The cause was the SELF-CHECK: it replays the file through the map it is
      about to teach and demands the AI's curve back, so an invented aiMonths that the
      invented cells do not produce is rejected. Supplying the curve the cells actually
      yield (4000/8000/10000/11500/12000 over 12,000) made it derive. The plan's own
      standing lesson applied to me: when a rule refuses, suspect the data feeding it.

    READER REGRESSION: 16 sample reads, 0 CHANGED against the committed core.
    PHP: 1190 passed / 5 failed — all pre-existing BY NAME (1 DashboardOverdueInspection
    + 2 EmailDraft + 2 default-baseline). The baseline is 5 now, not 7: the two
    ContractorDashboard failures were fixed by the teammate's work merged in 06d89cf
    (ContractorDashboardTest now 8/8), not by anything here.

    NOT BROWSER-PROVEN. The re-run is the user's: re-import the roof so a fresh
    auto-capture and a fresh ai-derived row are stored, check the ai-derived row's grid
    now shows A..M with all seven months, check the unnumbered auto-capture carries the
    amber warning, then teach it exactly as before and confirm 6.32 / 24.31 / 42.30 /
    59.71 / 77.03 / 99.14 / 100 with counts 4,4,4,4,3,4,3. EXPECT ONE MORE RE-CAPTURE
    WAVE: fix 2 changes the ai-derived block text, so its header_hash changes and the
    old row (77/79) cannot retro-fit. Approve the new one, reject the stale ones.


  2026-09-14 (second round) — THE USER RE-RAN §10 ON A WIPED TABLE. FIX 1 HELD IN
  THE BROWSER — the manual teach now reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 /
  99.14 / 100 with counts 4,4,4,4,3,4,3 and the real activity names. THREE MORE
  DEFECTS, and the first is a consequence of Fix 2 that I did not anticipate.

    DEFECT 4 — FIX 2 MADE THE AI'S NUMBERING UNREACHABLE. The worst of the three,
    and caused by the previous round.
      Once derivePdfLayoutFromAi built the same MERGED block the capture builds,
      the two stopped differing — measured on the real roof PDF, both normalize to
      sha256 75b8b1b9239c6f1d, IDENTICAL. storeAiDerived matches on that hash, found
      the auto-capture row, saw column_map !== null and returned it untouched. So a
      successful AI read banked NOTHING: no ai-derived row appeared at all, and the
      month numbering — the ONE thing an ambiguous capture cannot derive and the AI
      can — was discarded in silence. This is exactly the SEGMENT no-op documented on
      2026-09-13, arriving at the roof through a door the previous fix opened.
      FIX: storeAiDerived backfills when the stored map is null OR UNNUMBERED. New
      shared predicate ScurveHeaderProposal::pdfMapIsUnnumbered() — true only for a
      pdf map whose every page carries no month index; Excel maps, nulls and
      unrecognised shapes answer false, so a guard can never block a layout that
      works. The backfill still drops the row to proposed: a machine-made numbering
      needs a person before it reads a baseline. A NUMBERED map is still never
      overwritten — that rule is untouched and now under test.

    DEFECT 5 — AN UNNUMBERED CAPTURE AUTO-WENT LIVE AT THREE.
      Live row, seen=5, both pages month-less. The >=3 rule corroborates WHERE the
      columns sit; it cannot corroborate WHICH month each one is, because the roof
      prints "30 days" six times however many times you import it. The result was a
      row badged live and Mapped that usableLayoutMap still refuses — the user
      approved nothing and got "could not read the S-curve" anyway, which is the
      same confusion Defect 3 was about, arriving automatically.
      FIX: the auto-live rule now excludes unnumbered captures, using the same
      predicate. Numbered captures still promote at three (pinned).

    DEFECT 6 — THE TEACH PREFILL READ PAGE ONE ONLY, so reopening a taught roof
    layout showed "Description=A · M1=F · M2=G · M3=H · M4=I · M5=J" and silently
    dropped M6/M7. The user's read of it was exactly right: "it might be because m6
    and m7 is on page two and this only show page 1". The SAVE was correct
    throughout — the live map holds M6=L and M7=M — so this was display only, but it
    shows a reviewer their own saved work coming back incomplete, which is worse
    than showing nothing.
      CAUSE: the prefill took cm.pages[0]. §3 made the grid one table with letters
      running on, and this was not updated with it. FIX: walk every page; letters are
      globally unique so each page resolves against its own pseudoCols and the
      results merge. The description is taken from the first page that yields one (a
      continuation page legitimately has none).

    NOT A DEFECT, ASKED AND ANSWERED: the duplicated "30 days" at column K is IN THE
    DOCUMENT. R3 prints seven "30 days" plus "7 days" while the schedule is 6x30 + 7
    = 187 days, and K is empty on every activity row. The grid is showing the file
    faithfully; the user spotted it unaided, which is what the grid is for. And the
    auto-capture still has no month numbering BY DESIGN (§7) — that is the ambiguous
    path, not a failure.

    TESTS +4 PHP (unnumbered never auto-lives; numbered still auto-lives at three;
    an AI numbering backfills an unnumbered capture; a numbered capture is not
    overwritten) -> ScurveHeaderProposalTest 64 passed.
      FAILING-THEN-PASSING CONFIRMED: stashing the model + controller makes exactly
      the two behavioural tests fail; the two guard tests pass either way, which is
      what a guard should do.
    SUITES: PHP 1194 passed / the same 5 pre-existing BY NAME. JS 158 tests, 156
    passed + the 2 intended blinding fails (no JS touched this round).
    READER REGRESSION: 16 sample reads, 0 changed.

    THE LIVE ROW SELF-HEALS: the row that auto-went live unnumbered will be
    backfilled and dropped to proposed by the next successful AI assist on that file
    (Defect 4's fix), so it needs no manual cleanup — though rejecting it is faster.


  2026-09-14 (third round) — AI BACKFILL PROVEN IN THE BROWSER, ONE MORE DEFECT.
    The user wiped the table and re-ran. Defects 4 and 5 are BROWSER-PROVEN: the
    auto-capture became ai-derived (the AI's numbering reached it instead of being
    discarded), it did NOT auto-live, and approving it read
    6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 with counts 4,4,4,4,3,4,3.

    DEFECT 7 — THE PREFILL CLAIMED TWO COLUMNS PER MONTH.
      Reopening the teach modal prefilled M1=F+G · M2=G+H · M3=H+I · M4=I+J ·
      M5=J+K · M6=L+M · M7=M — every month its own column plus a sliver of the next.
      CAUSE: month bands and pseudo-columns are two INDEPENDENT griddings of the
      same page. Bands come from the midpoints between header labels
      (finalizeHeaderBands); columns come from clustering every token in the table
      (detectPseudoColumns). Their edges land a few thousandths apart, and the
      prefill claimed a column on ANY overlap.
      MEASURED on the live row, month band vs column coverage:
        M1 F 99% G 3% · M2 G 97% H 3% · M3 H 97% I 3% · M4 I 97% J 4%
        M5 J 96% K 6% · M6 L 96% M 10% · M7 M 90%
      The real column and the sliver are nowhere near each other, so the rule is not
      a tuned threshold — anything in the 10–90 gap separates them.
      NOT COSMETIC, and this is the part worth keeping: saving that prefill is
      REFUSED ("Month 2 starts left of where month 1 ends"), verified by invoking
      the builder with the exact prefilled payload. The reviewer had to clear every
      duplicate by hand before Save would work — which is what the user did in the
      first round without knowing why.
      FIX: a column is claimed when the overlap is most of the COLUMN or most of the
      BAND. Column-share handles the ordinary case; band-share handles a band
      narrower than the column it sits inside. CAUGHT IN MY OWN FIRST DRAFT: I used
      column-share with a "best single match" fallback, and a continuation page's
      inherited empty desc band [0 -> 0.2161] then claimed month 6's column L (it
      grazes it by 4%, and with nothing above 50% the fallback took the best of a
      bad set). Requiring BOTH shares to be small before rejecting fixes it — page 1
      now yields no description at all, which is correct: a continuation page has none.
      VERIFIED against the real stored map, the rule as written in the Blade:
        page0 DESC -> A+B+C+D (picks A) · M1 F · M2 G · M3 H · M4 I · M5 J
        page1 DESC -> (none)          · M6 L · M7 M
      Exactly the letters the user selected by hand.

    QUESTION ANSWERED (user): "does the individual M label need to be accurate, or
    is it more of an anchor for where the month columns are?" — ACCURATE. The letter
    is a pointer and the BAND is what the reader uses: buildTaughtPdfMap turns the
    taught letters into min(x)..max(xEnd) across them, so an extra letter widens the
    month over its neighbour's cells. It cannot corrupt a baseline silently — the
    left-to-right/no-overlap guard refuses it before any write — but it does block
    the save, which is why a wrong prefill is a real obstacle and not a display nit.

    SUITES: ScurveHeaderProposalTest 64 passed. JS 158 tests, 156 passed + the 2
    intended blinding fails. Reader regression 16 reads, 0 changed. Blade-inline JS
    has no unit harness (the standing convention for this file), so the rule was
    replayed against the REAL stored map in node instead of a synthetic fixture.


  2026-09-14 (fourth round) — ✅ §10 BROWSER-PROVEN. PDF-4 STAGE 2 IS CLOSED.
    The user re-ran the acceptance script and every defect stayed fixed at once:
      - the teach modal prefills ONE letter per month — Headers R3 · Description=A ·
        M1=F · M2=G · M3=H · M4=I · M5=J · M6=L · M7=M — with the phantom column K
        correctly unassigned and months 6 and 7 (printed page two) present;
      - the desk row reads ai-derived, seen 1x, "page 1: M1-M5 · page 2: M6-M7",
        and did NOT auto-live;
      - approving it reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 with
        counts 4,4,4,4,3,4,3 and the real activity names, carrying the learned-layout
        note, the adoption note and the "2 unnamed value rows" note.
    Both routes to a readable roof layout are now proven: APPROVE THE AI-DERIVED ONE,
    or TEACH IT BY HAND. Either lands on the same curve.

    WHAT THE SEVEN DEFECTS HAD IN COMMON, worth carrying forward. Six of the seven
    were not in the code §2-§7 wrote — they were in the code that SPOKE TO it and was
    not updated with it:
      derivePdfLayoutFromAi still built a page-one block with restarting letters;
      buildTaughtPdfMap still read one pseudo-column as the description;
      the teach prefill still read pages[0], then still claimed every column a band
      grazed; storeAiDerived and the auto-live rule still tested `map === null` when
      the meaningful state had become `unnumbered`.
    §2 changed what a LETTER means and §3 changed what a PAGE means. Every consumer
    of those two ideas needed revisiting, and finding them one browser round at a
    time is what four rounds cost. THE LESSON, stated plainly for whoever changes a
    shared representation next: list the readers of a representation before changing
    it, not after the acceptance run fails.

    ALSO CLOSED HERE: the Overview tab's Documents card let a long filename escape
    the card. .left-col/.right-col/.doc-item are grid items defaulting to
    min-width:auto, so content width beat the 380px track and .doc-name's existing
    ellipsis never applied. Unrelated to the parser; it rode with 22edd71.

    STILL OPEN, and deliberately not built: defect 7's fix lives in Blade-inline JS,
    which has no unit harness in this file, so it is pinned only by a replay against
    the real stored map. If a test harness for the desk's inline JS ever exists, that
    rule is the first thing to move into it.
    ALSO NOTED, LATENT, NOT FIXED: storeAiDerived matches on (header_hash,
    contractor_id) with NO source_type scope, while the auto-capture path below it
    scopes by source_type precisely so an Excel row and a PDF row can never merge.
    The controller patches source_type afterwards. No live symptom; a colliding hash
    could hijack an Excel row into a PDF one.


  2026-09-14 (fifth round) — THE SAME SWEEP ON THE EXCEL PATH (SEGMENT specimen).
  The user moved the test to Excel. Two of the three findings are the Excel twins of
  defects already fixed on the PDF side, which is the useful part: the same mistake
  had been made twice in two branches.

    ASKED AND ANSWERED FIRST — "are columns D and E empty because the text in C
    stretches through them (merged cells)?" NO. Read the workbook directly: it has
    ZERO merged ranges. D and E are simply empty in the sheet — the header occupies
    A, C, F, G and H..L, with B blank on the header row but carrying the item numbers
    1-4 on the data rows. The grid is showing the file faithfully.
      WORTH RECORDING, because it confused the read: the block's R-numbers are KEPT
      ORDINALS, not sheet rows. The sheet is 16 rows with 4-9 and 11 empty, so the
      block's R4 (the header) is sheet row 10 and R5..R8 are sheet rows 12..15. The
      LETTERS, however, are the sheet's own columns — verified cell by cell.

    DEFECT 8 — EVERY EXCEL TEACH REFUSAL SAID THE SAME THING.
      buildTaughtMap returns bare null from EIGHT separate gates and the endpoint
      printed one catch-all: "Those columns do not form a usable layout (need a
      description column plus at least two non-overlapping months, left to right)."
      The user's actual mistake was assigning the WEIGHT column to month 1 as well —
      replayed through the endpoint to confirm, and the message never said so.
      Meanwhile the PDF branch has named its gate precisely since 2026-09-13.
      FIX: buildTaughtMap takes a ?string &$reason out-param — the same idiom
      deriveMapFromAiLayout already uses for its refusals — and sets a specific code
      at each gate, carrying the reviewer's own column letters and month numbers.
      explainTaughtMapRefusal() turns those into sentences, with the old wording kept
      as the fallback so a future gate can never produce a blank refusal. New
      SCurveImportService::letterFor() does index -> letter (past Z too).
      VERIFIED live against the real capture:
        weight G also in M1 -> "Column G is both the weight column and part of
                                month 1 — give it to one or the other."
        H in two months     -> "Column H is assigned to two different months…"
        months out of order -> "The months do not run left to right across the grid…"
        desc on empty col D -> "Pick the column holding the work names…"
        the clean teaching  -> saves.

    DEFECT 9 — A MAP-LESS CAPTURE AUTO-WENT LIVE. The Excel twin of Defect 5.
      When the rules cannot find the header — GAP 2, every row tags [DATA], which is
      exactly the layout most in need of teaching — the capture stores column_map
      null. The >=3 rule promoted it, producing a LIVE row the reader has nothing to
      read with: the user approved it and the next import still failed.
      FIX: the auto-live guard now excludes column_map === null as well as an
      unnumbered PDF map.
      A TEST WAS PINNING THE BUG, and the plan's own lesson applies — say which kind
      of property a test pins. test_repeats_increment_and_third_promotes_to_live used
      makeWorkbook, a deliberately underivable stub, so it asserted that a MAP-LESS
      row goes live. It now uses makeNearMissWorkbook, which derives a map, so the
      >=3 rule stays genuinely under test on a row promotion can actually help; the
      map-less case is pinned separately by test_a_map_less_capture_never_auto_lives.

    DEFECT 10 — THE DESK CALLED A MAP-LESS ROW NOTHING AT ALL. Defect 3's twin: a
      row with no map simply lacked the blue "Mapped" badge, which reads as a minor
      difference rather than "the reader cannot use this". It now carries an amber
      "Needs teaching" badge saying approval alone will not make the file readable.

    NOT A DEFECT: the AI-derived row overwriting the auto-capture is the designed
    backfill — storeAiDerived fills a map-LESS row and drops it to proposed. The
    approved AI layout then read 5 / 20 / 40 / 65 / 100 with the true names, which is
    the loop working.

    TESTS +2 PHP (a map-less capture never auto-lives; an Excel teach refusal names
    the clashing column, covering three gates plus a clean save on the same row) and
    1 fixture corrected -> ScurveHeaderProposalTest 66 passed.
    FAILING-THEN-PASSING CONFIRMED: stashing the service + controller fails exactly
    those two.
    SUITES: PHP 1196 passed / the same 5 pre-existing BY NAME. JS 158 tests, 156
    passed + the 2 intended blinding fails. Reader regression 16 reads, 0 changed.

    STILL OPEN ON THE EXCEL PATH, not built: when NO row is tagged [HEADER] the
    picker opens with nothing ticked (the deliberate F6 fix) and says "none picked
    yet" in small text. That is correct but quiet, and it is the second thing that
    blocked this run. A blocked Save should say "pick the header row first" rather
    than leaving the reviewer to find it.


  2026-09-14 (sixth round) — THE "NEEDS TEACHING" BADGE LANDED, AND EXPOSED THE TWO
  THINGS IT DOES NOT DO. Specimen: SCurve-Test-Segment-Novel-Specimen.xlsx (NOT the
  roof — a different file, noted because the two tests are easy to conflate).

    FIRST, A REPORT THAT WAS A MISREADING, AND THE MISREADING IS THE DEFECT.
      The user reported "this is my manual teaching — and it not saved", then in the
      same breath pasted a CORRECT read (5 / 20 / 40 / 65 / 100, real names, "read
      with a learned layout for this contractor"). Checked the table: it HAD saved —
      row 96, manual-teach, live, descCol 2, months 1-5.
      WHY IT LOOKED OTHERWISE: "New variant" creates a SIBLING row and leaves the row
      the reviewer taught FROM untouched, by design (alternating contractor formats
      must both keep working). So the row they were staring at still said "Needs
      teaching" and still had a null map, and the toast said only "Layout variant
      saved for review." Nothing pointed at where the work went.
      FIX: the variant message now says "Saved as a NEW layout, waiting in Proposed —
      approve it to use it. The row you taught from is deliberately left as it was."
      Replace mode likewise says it dropped back to Proposed and needs approving again.

    DEFECT 11 — APPROVE WAS STILL CLICKABLE ON A ROW NOTHING COULD READ.
      The user's words: "but i can still click the approve even without teaching."
      Correct, and the badge added in the fifth round only DESCRIBED the problem —
      the button beside it still invited the click, still flipped the row to live,
      and the next import still failed. Two capture states are affected: an Excel
      capture with column_map null (the rules could not find the header), and an
      ambiguous PDF capture whose months carry no numbering.
      FIX, both sides, because a client gate is not a rule: approve() refuses both
      states with a message naming teaching as the remedy and leaves the status
      alone; the desk renders the button disabled with the same explanation rather
      than removing it (a missing button raises "why can't I approve this?", a
      disabled one with a tooltip answers it).
      DELIBERATELY NOT A HARD BAN ON THE ROW: the same row approves the moment it is
      taught. The guard refuses a STATE, not a row — pinned by a test that teaches
      the refused row and then approves it successfully.

    A SECOND TEST WAS PINNING A BUG, same class as the fifth round's.
      test_staff_can_approve_capture built its row with no column_map, so it asserted
      that a map-less row approves — the exact thing now refused. But that test is
      about WHO may approve; the null map was incidental fixture laziness. It now
      carries a real map, and the unreadable case is pinned separately. Two rounds
      running, a test written for one property turned out to be holding another one
      in place. Worth saying plainly: when a fixture is "whatever compiles", it
      quietly becomes a specification.

    TESTS +1 PHP (approving an unreadable capture is refused — both states, status
    unchanged, and the same row approving once taught) and 1 fixture corrected ->
    ScurveHeaderProposalTest 67 passed. FAILING-THEN-PASSING CONFIRMED.
    SUITES: PHP 1197 passed / the same 5 pre-existing BY NAME. JS 158 tests, 156
    passed + the 2 intended blinding fails. Reader regression 16 reads, 0 changed.

    STILL OPEN, unchanged from the fifth round: when no row is tagged [HEADER] the
    picker opens with nothing ticked and says "none picked yet" in small text. The
    user got past it this round by ticking R4, but a blocked Save should still say
    "pick the header row first" rather than leaving them to find it.

  2026-09-14 (seventh round) — RESTUDY ONLY, NO CODE. The user moved to the 6-STAGE
  Excel specimen, asked for a CUMULATIVE badge and for reviewer-assignable row roles,
  and asked for a codebase restudy before anything was built. This entry is that
  restudy. Nothing below is implemented; the build is a separate order (Rule 10).
  Sources: the real workbooks, the real serializer, and three read-only sweeps of the
  role-tagging, teach-modal and authorization paths.

    THE USER'S THREE CLAIMS — ALL THREE CHECK OUT.
      "SEGMENT has no cumulative row, only a total"   CORRECT (sheet row 16 = TOTAL)
      "6-stage HAS a real cumulative row"             CORRECT (row 8, CUMULATIVE %
                                                       ACHIEVED 5/20/40/60/80/100)
      "so it should be badged cumulative"             CORRECT, AND IT IS NOT.
    serializeForAi() on the three specimens, verbatim:
      6-STAGE  R7 [SUMMARY]: ,TOTAL,,,1200000
               R8 [SUMMARY]: ,"CUMULATIVE % ACHIEVED",,,,5,20,40,60,80,100   <- wrong
      SEGMENT  R4 [DATA]:    "ITEM NO.",,DESCRIPTION,...
      BUHI     R9 [DATA]:    NO.,,,"(IN PESOS)",,AMOUNT,,DAYS,15,15,...

    THE EXCEL PATH NEVER EMITS [CUMULATIVE] AT ALL. labelSliceRows()
    (SCurveImportService.php:190-200) folds a cumulative row into SUMMARY via
    isCumulativeRow(); its role vocabulary is META/HEADER/DATA/SUMMARY, full stop. The
    PDF path has emitted a green CUMULATIVE since the roof work
    (scurve-pdf-parser-core.js:1884 and :2206, via isCumulativeShapedRow). The two
    paths have disagreed about the same idea since that day and nobody was looking at
    both at once. SAME CLASS AS THE SIX DEFECTS OF THE FOURTH ROUND.
      Two more mismatches ride with it: BOTH paths emit a FOUR-role legend
      (SCurveImportService.php:135-141, scurve-pdf-parser-core.js:1889-1892 / :2165-2168),
      so the PDF path emits a fifth tag its own legend never defines; and the Gemini
      prompt (GeminiDocumentParserService.php:1012-1034) teaches the model the same four
      and branches on them ("DO NOT copy SUMMARY values into months"), so a PDF slice's
      cumulative row reaches the model as a tag nothing has explained.

    THE AUTO-TAGGER IS WRONG ON REAL FILES IN THREE DISTINCT WAYS — this is the
    evidence behind the user's instinct to let reviewers correct roles, and it is
    stronger than the request that prompted it:
      6-stage  cumulative row  -> SUMMARY   (no CUMULATIVE exists on this path)
      SEGMENT  header row      -> DATA      (GAP 2: rules cannot find the header)
      BUHI     2nd header line -> DATA      (two-row header, only the first tagged)

    WHAT IS ACTUALLY WORTH TEACHING — TWO LEVELS, NOT THREE.
      Read SCurveImportService.php:1490-1545 before designing anything here. THERE IS
      NO POSITIVE "THIS IS A DATA ROW" DECISION. A row becomes an activity by residue:
        $isActivity = $weight !== null
            || ($hasMonthValue && $description !== '')
            || ($contractAmt !== null && $description !== '');
      Everything not skipped and carrying enough signal IS data. So a reviewer would
      never teach "this is a data row"; they teach the opposite. The two controls that
      mean anything are:
        NOT AN ACTIVITY (summary)  — do not sum this row. Protects the numbers.
        THE CURVE (cumulative)     — a summary row that ALSO supplies the baseline.
      And the user's own observation is right: THE CUMULATIVE ROW LIVES INSIDE THE
      SUMMARY BLOCK. The skip list lumps them together —
        /%?\s*(accomplished|complete)|cumulat\w*|commulat\w*|cost\b|cash\s*flow|
         project\s*duration|^%\s*$/i
      so marking a row cumulative should IMPLY excluding it. One click, coherent model.

    WHICH HALF IS URGENT AND WHICH IS INSURANCE — measured, because the answer is not
    the one the request implies. isCumulativeRow() against real and invented labels:
      6-stage CUMULATIVE % ACHIEVED  FOUND     roof    Commulative (typo)  FOUND
      BUHI    CUMULATIVE % ACCOMPL.  FOUND     SEGMENT TOTAL               missed (right)
      novel   RUNNING TOTAL %        missed    novel   PROGRESS TO DATE    missed
      unlabelled bare % row          missed
      ⇒ NO FILE IN THE CURRENT CORPUS NEEDS A TAUGHT CUMULATIVE ROW. Every real one is
        already found. That half is insurance against novel wordings — the same class
        the loop already serves for novel MONTH wordings (SEGMENT, STAGE N).
      ⇒ THE SUMMARY HALF IS THE HIGHER-STAKES ONE, and it fails SILENTLY: a row wrongly
        summed inflates the contract denominator AND every month total, with no
        warning. The skip regex above is accumulated scar tissue — the roof comment at
        :1493 says the Cash Flow row "absorbs as an activity and distorts the curve
        (diagnosis-proven)". A novel summary label ("Cash Position", "S-Curve Value")
        slips straight through today.
      Recorded because it inverts the priority: the badge has a file TODAY, the summary
      control prevents silent corruption, and the cumulative binding is the speculative
      third. If this is ever split, ship it in that order.

    THE PREVIEW CONSTRAINT, AND THE PROPERTY THAT RESCUES IT. The user asked to see the
    before/after before saving an exclusion. THE CAPTURE STORES NO FILE — only
    header_rows_text (text-only was the deliberate Phase-A decision: "approval IS the
    trust event"), so the server cannot re-parse to compute one. Measured what the block
    alone supports:
      6-STAGE  block rows=8    carries a TOTAL row: yes    <- complete, previewable
      SEGMENT  block rows=9    carries a TOTAL row: yes    <- complete, previewable
      BUHI     block rows=15   carries a TOTAL row: NO     <- hit the 15-row cap
      ROOF     block rows=15   carries a TOTAL row: NO     <- hit the cap
    THE FILES THAT NEED TEACHING ARE THE SMALL NOVEL ONES WHOSE BLOCKS ARE COMPLETE.
    BUHI already parses; the roof reads through the PDF path. So a layered preview never
    lies: always show the row's own contribution (exact, from its cells); show the full
    recomputed curve ONLY when the block's activity amounts reconcile to its TOTAL row
    (self-validating, needs no new field); otherwise say the table is truncated.

    APPROVE PARITY — VERIFIED EQUAL, NO WORK NEEDED (user asked to confirm, not change).
    Admin and staff match at every layer: ScurveHeaderProposalPolicy, all 7 abilities
    in_array($user->role, ['admin','staff']), registered at AppServiceProvider.php:28;
    ensureReviewer() (controller :751-756) called by every endpoint; routes mirrored
    exactly (staff :291-306, admin :776-791 — 5 each, same verbs/paths/methods);
    one shared modal included by both layouts with no role branching inside it.
    Contractors excluded at five independent layers, with a test already pinning it.

    FOUND WHILE LOOKING, NOT IN SCOPE, WRITTEN DOWN SO IT IS NOT RE-DISCOVERED:
      (a) PSR-4 CASE MISMATCH, A REAL LINUX DEPLOYMENT BUG.
          app/Http/Controllers/Admin/SCurveHeaderProposalController.php (capital C)
          declares `class ScurveHeaderProposalController` (lowercase c, :25). It resolves
          today only because routes/web.php:32 imports the FILENAME's spelling. Any
          reference to the declared name fails to autoload on a case-sensitive
          filesystem. The sibling keyword controller is consistent; this one is not.
      (b) THE PDF CUMULATIVE TEST CAN STEAL A HEADER TAG. In both PDF builders it is an
          unconditional `if`, not `else if` (:1884, :2206), so it overwrites HEADER and
          META. A header line satisfying the shape test would lose its tag and the teach
          picker would open empty — the exact symptom of the fifth-round Excel bug,
          reachable on the PDF side by a different route. No sample triggers it yet.
      (c) isCumulativeLabel() (SCurveImportService.php:1210) IS DEAD CODE — zero callers.
          Its docblock describes work isCumulativeRow() actually does.
      (d) The policies are registered but NEVER INVOKED — no authorize(), no Gate::allows,
          no @can for either desk. Enforcement is route middleware + ensureReviewer().
          Fail-safe, not fail-open, but a future reader may trust the wrong thing.
      (e) collect() duplicates the reviewer gate inline instead of calling
          ensureReviewer(), on BOTH desks — identical today, free to drift.

    ⚠️ WHEN THE BADGE FIX IS BUILT IT CHANGES THE BLOCK TEXT, hence header_hash, hence
    ONE MORE RE-CAPTURE WAVE. Same drill: re-import, approve the new row, reject the
    stale one. Third time this has been the cost of improving the block; it is the
    standing price of header_hash being content-derived, not a defect.

  2026-09-14 (seventh round, part two) — REGRESSION-RISK RESTUDY, STILL NO CODE.
  User asked: "is the plan for both excel and pdf? can you do another restudy if it
  can affect the working states". Both are fair, and the answer to the first is worse
  than the plan implied.

    IS IT AN EXCEL PLAN? NO — IT TOUCHES SHARED SURFACES IN THREE PLACES.
      step 1 badge          Excel emitter, but the LEGEND lives in BOTH builders and
                            the Gemini prompt serves both paths
      step 2 row pickers    the teach modal is ONE file serving both; teachColumns()
                            validation is unbranched and runs BEFORE the source_type
                            branch
      step 3 binding        Excel only
      step 4 preview        the shared modal again
      step 5 header gate    the shared modal, and it changes PDF behaviour outright
    Calling it "the Excel plan" would have been the same mistake that produced six of
    the seven defects in the fourth round. Recorded so the next plan says so upfront.

    THE ONE GENUINELY REASSURING FINDING, verified from two directions: A LEGEND OR
    ROLE CHANGE CANNOT STOP A WORKING IMPORT. Layouts are matched at read time by the
    `sig` inside column_map — Excel findHeaderViaMaps() :924-945, PDF usableLayoutMap()
    / matchMapPage() — never by header_hash and never by the block text. The hash is
    dedupe/upsert identity only. So changing the block forks a duplicate PROPOSED row
    at the next capture; the live row keeps its status and keeps being consulted.
    Annoying, not destructive. (Live rows right now: 96 manual-teach live, 97
    auto-capture proposed, both Excel. No PDF row is currently at risk.)

    A CLAIM IN THIS PLAN'S OWN §4 IS WRONG, AND I MEASURED IT TWICE WITHOUT NOTICING.
      §4 says the summary-aware row budget "applies to BOTH sides: buildPdfSliceBlock
      (PDF) and SCurveImportService::keptRowKeys". IT DOES NOT. Both Excel walks —
      the inline one in serializeForAi() :109-128 and its duplicate keptRowKeys()
      :891-909 — hard-break at `$kept >= $maxRows` with no role exemption. Only the two
      PDF builders filter `k < maxRows || role === 'SUMMARY' || role === 'CUMULATIVE'`
      (:1888, :2214).
      THE EVIDENCE WAS ALREADY IN FRONT OF ME: the block-completeness measurement in
      part one showed BUHI and the roof .xlsx at "block rows=15, carries a TOTAL row:
      NO". That is not a coincidence of size — it is this bug. Their summary blocks are
      cut off entirely.
      CONSEQUENCE FOR STEP 1: a CUMULATIVE badge would be INVISIBLE on any Excel file
      whose summary sits past row 15 — which is BUHI, the main real specimen. The badge
      is worth nothing on its own; the Excel budget exemption has to ship with it.

    HOW THE BINDING COULD SILENTLY BEND A CURVE (the sharpest Excel risk).
      $contractTotal is the DENOMINATOR OF EVERY MONTH and is accumulated in the very
      loop exclusions would skip (:1539, :1584-1585). An excludeSig that matches one
      row too many does not just drop that row's month pesos — it drops its contract
      amount from the denominator and EVERY MONTH SHIFTS UP. Drop a row worth 10% of
      the contract and a true 45% month reads 50%.
      Three things make that silent rather than loud: the only hard failure is
      `$activities === 0` (:1561), so 1-of-40 and 39-of-40 both proceed; the weight
      sanity check at :1701 is inert on the many layouts with no weight column; and
      force-last-to-100 (:1613-1615) keeps the curve ending at exactly 100 and passing
      the monotonic clamp, so the output has the right shape, right endpoints and right
      month count while every interior point is wrong.
      Worse tail: an over-broad exclusion can flip $lastNonZero to 0 and hand the whole
      curve to the summary fallback (:1636), replacing the baseline wholesale on the
      strength of a fuzzy string match — and sigCellMatches() (:1020-1033) IS fuzzy
      (bounded substring ≥4 chars, Levenshtein ≤2).
      DESIGN CONSEQUENCE, adopt it: exclusions must affect month-value accumulation and
      the activity count ONLY, never $contractTotal; and every exclusion must name the
      rows it removed in the import notes. Silence is the failure mode.
      By contrast cumulativeSig is materially safer — a wrong pin usually lands on a row
      with no numerics under the month spans and readSummaryReference returns []
      (:1795-1797), degrading to "no reference" rather than a moved curve.

    THE PLUMBING IS CHEAPER THAN FEARED. findHeaderViaMaps() returns a derived header
    array (:947-955) that ALREADY reaches both target sites — buildMonths($rows,
    $header) :1421 and readSummaryReference($rows, $header, ...) :1741. Carrying two
    extra keys needs ZERO signature changes; only that return literal and two local
    reads. And the map validation (:924-945) is a pure allow-list, so old maps without
    the keys and new maps with them both survive.
      ⚠️ DO NOT BUMP MAP_VERSION (:55). The gate at :929 is `!==`, so a bump instantly
      stops EVERY live map applying and those files revert to "No S-curve table found"
      — already pinned by test_version_mismatch_map_is_ignored. The new keys are
      optional and additive; they do not need a version.

    COVERAGE GAP THAT HAS TO BE CLOSED FIRST: every existing test of the row-skipping
    logic and of readSummaryReference goes through the RULE path (parse($path) with no
    maps). NOTHING exercises either through a live map — which is exactly the path this
    change touches. The map tests that do exist assert month percentages on workbooks
    with no cumulative row at all. So the change would land on untested ground; the
    map-path tests must come BEFORE the behaviour.

    THIRTEEN WAYS THIS COULD RE-BREAK THE PDF TEACH FLOW. Listing the ones that are
    sharp, because the flow was only stabilised three rounds ago:
      (a) THE 'm'-PREFIX COLLISION. schTeachClickLetter (:808) and schTeachFieldLabel
          (:708) dispatch on `field.indexOf('m') === 0`. A palette key starting with m
          — 'mark', 'meta', 'mode' — is treated as MONTH "ark". It lands in
          a.months['ark'], survives JSON.stringify, passes the server rules (month KEY
          shape is unvalidated), and buildTaughtPdfMap casts (int)'ark' => 0, producing
          an index-0 band that pdfMapIsUnnumbered does not catch (it tests index >= 1).
          Never name a new field with a leading m.
      (b) A NEW REQUIRED VALIDATION RULE 422s EVERY PDF SAVE. validate() (:305) runs
          BEFORE the source_type branch (:337). New rules must be sometimes|nullable,
          and note 'array' rejects an explicit null — send omitted or [], never null.
      (c) MUTATING r.role IN PLACE would destroy the PDF's CUMULATIVE badge. _schTeach
          .rows feeds both the teach grid (:848) and the header-row checkbox labels
          (:785). Role picks must live in a SEPARATE set keyed by r.n.
      (d) EXTENDING THE PREFILL try BLOCK re-creates the 2026-09-14 M6/M7 defect with
          zero console noise — the PDF prefill sits inside try{...}catch{/*best-effort*/}
          (:582-653). New prefill needs its own try, placed AFTER the PDF block.
      (e) BUMPING PDF_MAP_VERSION "for consistency" silently invalidates every taught
          PDF map: usableLayoutMap requires an exact match and buildTaughtPdfMap
          deliberately INHERITS the capture's version ('v' => $map['v'] ?? 1).
      (f) BINDING ROW CLICK HANDLERS can shadow the column-header clicks at :859 —
          which are the ONLY way to teach PDF months. The modal would look identical
          and simply stop assigning.
      (g) GATING ON THE WRONG SIGNAL. _schTeach does not store source_type at all. The
          tempting proxies both fail: `blocked` is null for every HEALTHY PDF capture,
          so gating on it shows the Excel controls on exactly the PDF rows that work;
          and `cm.kind === 'pdf'` answers false for a PDF row whose map is null. The
          fix is to put sourceType into the literal at :543.
      Also flagged: reusing header_rows for the new pickers (its max:2 would 422 both
      paths), widening the readout gate ahead of `blocked`, and editing the shared
      storeTaughtMap() create([...]) where a careless source_type would flip taught PDF
      variants into Excel rows forever.

    REVISED ORDER THIS IMPLIES (the build still needs its own explicit order):
      1. Excel summary-aware row budget — without it the badge is invisible on BUHI.
      2. Map-path tests for the skip logic and readSummaryReference — close the
         coverage gap before touching either.
      3. The CUMULATIVE badge + legend + prompt.
      4. The header-gate visibility fix (shared modal, small, independent).
      5. Row pickers, with sourceType in _schTeach and non-m field names.
      6. Binding, exclusions never touching $contractTotal, every exclusion named in
         the import notes.

  2026-09-14 (eighth round) — ROW-ROLE WORK RESUMED. STEP 1 OF THE REVISED ORDER:
  THE EXCEL SUMMARY-AWARE ROW BUDGET. ✅ BUILT.

    NO NEW RESTUDY WAS NEEDED and that was checked rather than assumed: since the
    regression study (3ba3feb) the branch gained five commits, ALL of them in
    public/js/admin/projects/document-parser.js (the normal-doc OCR preview track).
    Every S-curve file is untouched — verified with `git log 3ba3feb..HEAD --` over
    the six of them — and both findings still reproduce exactly: 6-stage's
    cumulative row still tags SUMMARY, BUHI and the roof still capture as "15 rows,
    no TOTAL row".

    WHY THIS IS FIRST, and it is the whole point of the reordering: adding a
    CUMULATIVE badge would have been INVISIBLE on BUHI, because BUHI's summary block
    sits past the fifteen-row cap and was never in the block to badge.

    WHAT SHIPPED
      keptRowKeys($grid, $maxRows, ?array $roles = null) now mirrors the PDF rule
      exactly — index over NON-EMPTY rows, keep when `$seen < $maxRows` OR the role
      is SUMMARY/CUMULATIVE (scurve-pdf-parser-core.js :1888/:2214).

      AND serializeForAi NOW CALLS IT instead of running its own copy of the same
      walk. That duplication was the real hazard: keptRowKeys is what turns an
      AI-reported R# back into a sheet row, so the moment the two disagreed about
      which rows are kept, every R# past the cap would resolve to the WRONG ROW in
      silence. One rule now; they cannot drift.

      THE THIRD CALLER WAS THE TRAP, and it was nearly missed. deriveMapFromAiLayout
      (:693) called keptRowKeys($grid, 40) with NO roles — so the slice the AI saw
      would have contained summary rows the resolver did not know about. Fixed by
      passing labelSliceRows there too. Worth stating as a rule: when a walk gains a
      parameter that changes WHICH rows exist, every caller is part of the change.

    MEASURED — before/after on six real workbooks, comparing the R-lines:
      buhi  15 -> 18  gained 3   pre-existing rows changed: 0
      roof  15 -> 18  gained 3   pre-existing rows changed: 0
      6stg   8 ->  8  gained 0   pre-existing rows changed: 0
      seg    9 ->  9  gained 0   pre-existing rows changed: 0
      10mo   8 ->  8  gained 0   pre-existing rows changed: 0
      lone   7 ->  7  gained 0   pre-existing rows changed: 0
    PURELY ADDITIVE. Summary rows sit at the bottom, so they append as R16-R18 and
    NO EARLIER R-NUMBER SHIFTS — which is exactly the property the R# resolver
    depends on. BUHI now shows its TOTAL, "% ACCOMPLISHED" and "CUMULATIVE %
    ACCOMPLISHED" rows; the roof shows TOTAL and both Commulative rows.

    READER UNCHANGED on all 8 sample workbooks — parse() output compared field by
    field (months, curve, curveSource, contract, cumulativeRow, summaryReference
    count, warning count) against a baseline captured before the change. serializeForAi
    feeds the AI slice and the capture, never parse(), and that is now demonstrated
    rather than asserted.

    TESTS +1 (test_summary_rows_survive_the_row_budget): a twelve-activity sheet
    with a budget of 5 keeps exactly 5 head rows plus TOTAL and CUMULATIVE, and R1
    and R5 still hold what they held. FAILING-THEN-PASSING CONFIRMED.
    SUITES: PHP 1198 passed / the same 5 pre-existing BY NAME. JS 158/156 + the 2
    intended blinding fails. PDF reader regression 16 reads, 0 changed.

    ⚠️ RE-CAPTURE WAVE for BUHI-shaped files: their block text changed, so
    header_hash changes and the next import stores a second proposed row. Files that
    already fit under the cap (6-stage, SEGMENT, the 10-month, the lone-body
    specimen) are byte-identical and unaffected.

    NEXT: step 2 of the revised order — map-path tests for the skip logic and
    readSummaryReference, BEFORE the badge. Nothing currently exercises either
    through a live map, which is the path the binding work will touch.

  2026-09-14 — "CAN WE JUST REMOVE THE 15-ROW CAP?" — MEASURED, AND THE ANSWER IS
  COUNTER-INTUITIVE. User question after step 1; no code, recorded so the next
  person does not re-derive it.

    REMOVING THE ROW CAP ALONE WOULD MAKE BUHI WORSE, NOT BETTER. The row cap is
    not the binding constraint — a 4000-CHARACTER STORE TRUNCATION is
    (SCurveHeaderProposalController :123 and :178, mb_substr($text, 0, 4000)), and
    it cuts from the END, which is exactly where the summary rows live. Measured on
    BUHI, simulating what actually reaches the database:
      cap=15   built 18 rows (3 summary)  -> STORED 18 rows (3 summary)   ok
      cap=40   built 43 rows (3 summary)  -> STORED 25 rows (0 SUMMARY)   <-- lost
    So raising the row cap without raising the char cap would SILENTLY UNDO step 1.

    BLOCK SIZE AGAINST THE 4000 LIMIT:
      BUHI  15 rows 2,750 chars fits · 40 rows 7,363 over · uncapped 93 rows 17,074
      ROOF  fits at every cap (27 rows, 3,466 chars)
      6STG  fits at every cap (8 rows, 774 chars)
    BUHI is the only file that strains either limit — ~88 activity rows across 24+
    month columns. Display was never the blocker: the teach grid already scrolls
    both ways and the desk already warns "Stored block hit the 4000-char cap".

    USER'S SECOND QUESTION — "the block stores actual DATA, not just headers; does
    that cause the char issue, and would not saving it help?" HALF RIGHT, and the
    half that is wrong is the useful part.
      DATA IS 91% OF BUHI'S BLOCK: 15,411 of 16,981 chars. Legend 279, header+meta
      592, summary 699 — all the structure is 1,570.
      AND DATA CELLS ARE NEVER READ FUNCTIONALLY. parseStoredSlice returns $rlines
      keyed by R-number, and teachColumns indexes it ONLY by the reviewer's chosen
      header_rows (:383-396) to build $merged and thence the sig. mapSpansHoldNumbers
      validates against the REAL file bytes, not the block. So every data row in the
      block exists for ONE purpose: letting a human look at column B and agree it is
      the description.
      BUT TRIMMING CELLS BARELY HELPS, because the bulk is cell COUNT not cell SIZE
      — 93 rows x 24+ columns is ~2,200 cells, each costing a comma whatever it
      holds. Measured on BUHI:
        as today   16,887      cells<=20  16,083      cells<=12  14,412      cells<=8  11,593
      and by 8 chars "Construction of Temporary Field Office" reads "Construc",
      which destroys the only thing data rows are for while keeping most of the cost.

    THEREFORE, if the full table is ever wanted, the lever is the STORAGE LIMIT, not
    the content. The 4000 is an application choice; the column is TEXT (65,535
    bytes) and BUHI needs ~17,000 — roughly 4x headroom. AI token cost is NOT
    affected: the stored block is serializeForAi($path, 15) while the AI slice is a
    SEPARATE call at 40 rows (controller :88/:686 vs SCurveImportController :91).

    NOT DONE, BY AGREEMENT (user: "we can focus on making the original plan work
    first"). Step 1 removed the symptom that prompted the question and no current
    file is blocked. Raising both limits together (say 15->25 rows and 4000->16000
    chars) is the safe shape when a real file needs more than head + summary; it
    costs another re-capture wave for BUHI-shaped files.

    UNRESOLVED: the user believes CONSCHEDSAVINGS' original workbook is larger than
    BUHI's. Cannot confirm — sampledocs holds only
    CONSTRUCTION-SCHEDULE-AND-S-CURVE_recreated.xlsx, 12 rows / 773 chars, plainly a
    partial recreation rather than the real file. The PDF twin is also a poor
    specimen (mixed image and text; the plan records it as a WEEKLY layout the
    monthly path does not read).

  -----------------------------------------------------------------------------
  2026-09-14 — EXCEL ROW-ROLE WORK, STEP 2 BUILT: PINNING THE MAP PATH BEFORE
  BUILDING ON IT
  -----------------------------------------------------------------------------

    WHY THIS CAME BEFORE THE BADGE. The regression restudy found a coverage gap
    with an uncomfortable shape: every existing test of the activity-loop skip
    vocabulary and of readSummaryReference reaches them through the RULE path
    (parse($path) with no maps). The map-path tests that do exist
    (test_service_parses_via_live_map_when_rules_fail and its siblings) run on
    makeNearMissWorkbook, which has no summary block at all. So the exact code
    path the row-role binding will touch — map-supplied header keys reaching the
    skip at :1520 and the reader at :1766 — was pinned by NOTHING.

    That is the worst possible state to start binding work in. A regression there
    fails SILENTLY: a summary row wrongly summed inflates the contract denominator
    and pushes every month low, and the curve stays monotonic and still ends at
    100, so nothing looks wrong.

    WHAT SHIPPED. tests/Feature/ScurveHeaderProposalTest.php gains one fixture and
    two tests:
      makeMapPathSummaryWorkbook() — "TASK DESCRIPTION" at C10 so the rules miss
        it (same trick as makeNearMissWorkbook), two activities, a TOTAL row, and
        then the three vocabulary rows that matter: CUMULATIVE % ACHIEVED,
        Cash Flow, Project Duration. Each carries numbers under both month spans,
        so a failure to skip shows up as an inflated denominator rather than as
        nothing.
      test_map_path_skips_summary_rows_like_the_rule_path — asserts the rules
        genuinely miss first (otherwise the test proves nothing about maps), then
        derives the map and asserts activityCount 2, contractAmount 400000, and
        the 25/100 curve.
      test_map_path_still_reads_the_summary_reference — asserts summaryReference
        is populated via the map, that month 1 reports the author's 20 against the
        activities' 25 with a 5-point gap, and that curveSource stays 'activities'
        (the reference never moves the curve).

    BOTH PASSED ON FIRST RUN, WHICH PROVES NOTHING, so each was falsified against
    the real code before being kept:
      skip vocabulary at :1520 disabled  -> activityCount 5, not 2. FAILS.
      reader regex at :1779 disabled     -> summaryReference empty. FAILS.
    Service file restored and confirmed byte-identical (git diff --stat empty)
    before the suite run.

    VERIFICATION. php artisan test — 1200 passed (was 1198), 5 failed, and the 5
    are the known pre-existing ones by name: DashboardOverdueInspection count
    badge, 2x EmailDraft, IssueLifecycle default baseline, RiskRegister default
    risk baseline. No code changed, so the 16-read regression and the JS suite are
    unaffected by construction.

    WHAT THIS BUYS STEP 6. When exclusions and cumulativeSig bind at :1520 and
    :1766, an empty taught list must leave behaviour bit-identical. These two
    tests are now the thing that says so on the path the change lives on.

  -----------------------------------------------------------------------------
  2026-09-14 — EXCEL ROW-ROLE WORK, STEP 3 BUILT: THE CUMULATIVE TAG, AND THE
  LEGEND THAT NEVER DEFINED IT
  -----------------------------------------------------------------------------

    WHAT SHIPPED.
      labelSliceRows() :196 — the folded branch
        `isCumulativeRow($row) || isSliceTotalRow($row) -> SUMMARY`
      splits in two, CUMULATIVE tested FIRST. A row reading "CUMULATIVE TOTAL %"
      satisfies both tests and the curve is the stronger fact. The branch sits
      after HEADER/META, so unlike the PDF builders it can never steal a header's
      tag (that defect stays open, see below).

      ALL THREE LEGENDS now define five roles, not four. This is the quieter half
      of the fix and the more interesting one: the PDF builders have emitted
      [CUMULATIVE] since the roof work (:1884, :2206) while their own legends
      listed only META/HEADER/DATA/SUMMARY. So for several rounds the AI has been
      handed a vocabulary word the same prompt never defined. SUMMARY's gloss also
      lied — it read "author totals (CUMULATIVE %/COST/Cash Flow)", naming the very
      label that now belongs to the other tag. Both fixed:
        SUMMARY:    author totals (TOTAL/COST/Cash Flow) — reference only...
        CUMULATIVE: the printed running curve — reference only, NEVER copy...

      GEMINI PROMPT — four edits, and three of them were not optional. The prompt
      does not merely list roles, it BRANCHES on them, so retagging a row silently
      redirects the model:
        - "summary" was "the SUMMARY lines' values ... omit when no SUMMARY row
          exists". After the split, a file whose only such row is the curve has NO
          SUMMARY row, so the model would have dropped the key entirely. Now reads
          CUMULATIVE first, SUMMARY as fallback, omit when neither.
        - "NOT the divisor the SUMMARY row may use" and "never a SUMMARY or TOTAL
          label" both had to grow the new tag or they would stop covering the row
          they were written to protect.
      This is the §2-§7 lesson again, on a smaller scale: LIST THE READERS OF A
      REPRESENTATION BEFORE CHANGING IT. Four of the six edits in this step were in
      code that merely SPOKE TO the tagger.

    WHAT NEEDED NO CHANGE, VERIFIED RATHER THAN ASSUMED.
      - The modal already renders it. SCH_ROLE_BADGE (:228) has carried
        `CUMULATIVE: 'success'` since the PDF work and the legend at :79-80 already
        explains it. Excel captures light up existing UI for free.
      - parseStoredSlice's row regex is `[A-Z]+` (:733) — accepts the longer tag.
      - keptRowKeys already exempts it from the row budget (step 1, :936).
      - Prefill (:578) selects on `role === 'HEADER'`; display sites (:246, :785,
        :848) fall back to 'secondary'. Nothing branches on SUMMARY server-side
        outside the tagger itself.

    EXISTING ASSERTIONS THAT PINNED THE OLD FOLD — inspected row by row, not
    bulk-updated, because a fixture's assertion is a specification until proven
    otherwise:
      ScurveHeaderProposalTest third-family: R7 "TOTAL" stays SUMMARY,
        R8 "CUMULATIVE % ACHIEVED" -> CUMULATIVE.
      SCurveImportTest role placement: R8 "TOTAL" stays SUMMARY,
        R9 "% ACCOMPLISHED" -> CUMULATIVE (isCumulativeRow matches %\s*accomplished).
      KEEPING BOTH HALVES OF EACH PAIR IS THE POINT — they prove the branch splits
      on the LABEL, not on position within the summary block.

    NEW TEST, FALSIFIED BEFORE BEING KEPT.
      test_cumulative_row_survives_the_row_budget — 20 activity rows so the 15-row
      cap certainly bites, with TOTAL and the curve row last. Asserts the block is
      truncated AND still carries both. Removing the exemption in keptRowKeys makes
      it fail ("array contains 'CUMULATIVE'"), so it pins the thing it claims to.
      The tag and the exemption were built in separate steps and the failure they
      jointly prevent is invisible: a truncated block simply looks like a file with
      no cumulative row.

    VERIFICATION — all four guards green.
      php artisan test          1201 passed, same 5 pre-existing failures by name
      npm run test:js           158 tests, 156 pass, 2 intended blinding fails
      verify_p6.mjs             16 reads, 0 changed
      BUHI byte-identical       12 months, curveSource=activities, cumulativeRow=89,
                                contract 32,588,531.43, gaps 3:0.21 6:0.63 9:1.49 12:3

    ⚠ RE-CAPTURE WAVE, AND IT IS WIDER THAN THE PLAN SAID. Identity is
    `sha256(normalized slice text)` for BOTH kinds (controller :134 for Excel,
    :118 for the PDF ai-derived route). The legend line is part of that text, so
    the hash moves for EVERY captured layout — not only files that have a
    cumulative row, and not only Excel. Freshly-approved PDF layouts go stale too.
    Done deliberately in one wave rather than two: the PDF legend was already wrong
    and splitting the fix would cost a second wave for no gain.

    STILL DEFERRED, ON PURPOSE. The PDF CUMULATIVE overwrite (:1884, :2206) is an
    unconditional `if` that can steal HEADER/META. The naive `else if` is WRONG —
    a real cumulative row usually matches isSummaryLabel first, so `else if` would
    kill the working SUMMARY->CUMULATIVE upgrade. The correct guard is
    `role !== 'HEADER' && role !== 'META' && ...`. Not applied here: it changes
    behaviour on a path the user has just finished four rounds of acceptance
    testing on, and it belongs in a change they retest.

  -----------------------------------------------------------------------------
  2026-09-14 — EXCEL ROW-ROLE WORK, STEP 4 BUILT: THE SAVE GATE NOW POINTS AT
  THE CONTROL IT IS TALKING ABOUT
  -----------------------------------------------------------------------------

    THE DEFECT, RESTATED FROM THE USER'S OWN SESSION ("i cant save the manual
    teach even though there is already a description and month column"). They
    eventually got past it by ticking R4, but only by hunting.

    MEASURED CAUSE. schRenderTeachReadout's gate is STRICTLY ORDERED — desc, then
    months, then header row. So "Pick at least one header row." can only ever
    surface AFTER the other two already pass, which is exactly the moment the
    reviewer believes they are finished. And it surfaces in #teachHint BELOW the
    grid, while the control it names is the collapsed "Header row: none picked
    yet" line ABOVE the grid, whose picker is shut by default. The reviewer is
    told WHAT is wrong in one place and WHERE to fix it in another, with a
    screenful of grid in between. Neither half is a bug on its own; the distance
    between them is.

    WHAT SHIPPED.
      schNudgeHeaderPicker() — when the header row IS the blocker, open the picker
        and flash the band red for 2.4s, scrolling it into view.
        ONCE PER TEACHING SESSION, via _schTeach.headerNudged. Re-forcing it open
        on every render would fight a reviewer who deliberately collapsed it, and
        schRenderTeachReadout runs on every checkbox change. The flag lives on
        _schTeach, which is rebuilt per capture, so a different row nudges again
        while re-rendering the same one does not.
      The hint now names the control: "Pick at least one header row — use
        \"Header row\" above the grid."
      READOUT GLITCH FIXED. `'Headers R' + [].join('+R')` rendered a bare
        "Headers R ·" with nothing ticked — which reads as a row literally named
        R, and so concealed the very fact it should have exposed. Now reads
        "No header row picked".
      #teachHeaderBand id added to the wrapper purely as a flash target.

    ORDERING CHECKED, NOT ASSUMED: schRenderTeachHeaderRows() runs at :674 before
    schRenderTeachReadout() at :676, so the picker always has its checkboxes
    before a nudge can open it. If prefill supplies desc + months, the nudge fires
    at open time and the picker is simply already open — the desired behaviour.

    INCIDENTAL FINDING WORTH SOMEONE'S TIME. The reason recorded for defect 7
    being unpinned was "Blade-inline JS has no unit harness". While syntax-checking
    this change, the modal's entire <script> block extracted cleanly and
    `node --check` passed: it contains ZERO Blade directives ({{ }}, @if, @foreach,
    @php all count 0). So a harness is not blocked by Blade at all — the block can
    be awk-extracted and required. Not acted on here; recorded so the next person
    does not re-derive the wrong reason.

    VERIFICATION. node --check on the extracted block: clean. Blade compiles
    (view:clear && view:cache). php artisan test — 1201 passed, same 5 pre-existing
    failures by name. No PHP and no reader code touched, so verify_p6 and the JS
    suite are unaffected by construction.

    BROWSER CHECK STILL OWED (user's step): assign a description and two months on
    a capture with no HEADER-tagged row and confirm the band flashes and the picker
    opens by itself; then collapse it deliberately and confirm it stays collapsed.

  -----------------------------------------------------------------------------
  2026-09-14 — EXCEL ROW-ROLE WORK, STEP 5 BUILT: THE TWO ROW PICKERS
  -----------------------------------------------------------------------------

    ⚠ READ THIS FIRST: AFTER STEP 5 THE TEACHING IS STORED BUT NOT YET HONOURED.
    The map now CARRIES excludeSigs/cumulativeSig; the reader does not consult them
    until step 6. A reviewer who teaches and approves a row role today will see no
    change in the numbers. This is a deliberate split for reviewability, not an
    oversight, and step 6 follows immediately. Do not ship this commit alone.

    THE MODEL, AS BUILT. Two controls, not three, because there is no positive
    "this is a data row" decision anywhere in the reader — a row becomes an
    activity by RESIDUE (SCurveImportService :1490-1545). So:
      NOT AN ACTIVITY (multi-select)  — "do not sum this row". Protects the numbers.
      CUMULATIVE ROW  (single-select) — a summary row that ALSO supplies the
                                        baseline. Picking it EXCLUDES it
                                        automatically: one click, not two.

    TEXT SIGNATURES, NEVER R-NUMBERS. New public static rowSignature() drops
    numeric cells, lowercases and collapses whitespace. R-numbers are kept-ordinals
    over the captured slice, so they shift the instant a contractor inserts a row,
    and an exclusion that slid onto a neighbour would drop a real activity out of
    the contract total with NOTHING VISIBLE TO SHOW FOR IT. The header is already
    matched by text (sig) rather than rowHint for the same reason.
    ONE RULE, TWO CALLERS, ON PURPOSE: teach time reads the stored block, read time
    (step 6) reads the live workbook. The moment those normalizations differ every
    taught role stops matching, silently — the identical failure mode keptRowKeys
    was unified to prevent in step 1.

    ADDITIVE BY CONSTRUCTION. buildTaughtMap emits the two keys ONLY when taught,
    so every map built before row roles existed, and every map taught without using
    them, is byte-identical. findHeaderViaMaps reads only the keys it knows, so an
    older map round-trips unchanged. Pinned by
    test_untaught_rows_leave_the_map_shape_unchanged.

    REFUSALS, AND WHY EACH ONE EXISTS. Every case below is one where storing the
    pick would be WORSE than refusing it, because the damage is invisible at read
    time — a row that quietly stops being summed just makes every month read low:
      numbers-only row  -> empty signature would match every blank row in the file
      header row picked -> it is not summed as an activity anyway; the reviewer has
                           misread the control
      two rows normalizing the same -> cannot be told apart at read time, so
                           excluding one would silently exclude both
      ANY role field on a PDF row -> explicit 422, NOT a silent drop.

    THE PDF GATE IS PRINCIPLED, NOT AN OVERSIGHT. The PDF reader finds its
    cumulative row by SHAPE (isCumulativeShapedRow — percent-written,
    non-decreasing, ends >= 50), which is label-independent and already immune to
    the novel wordings this teaching exists for. Storing sigs it will never consult
    is exactly the half-wired save that cost four acceptance rounds. The band stays
    VISIBLE on a PDF row and says why, rather than vanishing — a control that
    disappears teaches the reviewer nothing.

    THE PDF TEST ASSERTS THE MESSAGE, NOT THE STATUS. A map-less PDF row already
    422s inside buildTaughtPdfMap, so a status-only assertion would have passed
    whether or not the new guard existed at all. Caught before committing.

    m-PREFIX TRAP CLOSED (the plan flagged it; here is what it actually was).
    schTeachClickLetter branched on `field.indexOf('m') === 0`, which claims EVERY
    field key beginning with m. This work was one palette button away from adding
    such a key, and the failure would have been near-undebuggable: clicking a
    column with "mark" armed assigns it to month "ark", a month number that quietly
    matches nothing. Now `/^m\d+$/`. No behaviour change for m1..m12.

    ALSO: parseStoredSlice captured the role in $m[2] and threw it away since it
    was written. Now returned as a THIRD element — two-element list() destructuring
    in the single existing caller is unaffected — so a refusal can say "R4 is the
    header row" in the reviewer's own terms.

    VERIFICATION.
      php artisan test        1208 passed (+7), same 5 pre-existing failures by name
      verify_p6.mjs           16 reads, 0 changed
      BUHI byte-identical     12 months, activities, cumulativeRow=89, gaps unchanged
      node --check on the extracted modal script: clean; Blade compiles.
      FALSIFIED: removing the implied-exclusion line makes
      test_marking_a_row_cumulative_also_excludes_it fail.

    BROWSER CHECK OWED: teach a role on the 6-stage Excel row and confirm the
    readout shows "Not activities=R…"; open a PDF row and confirm the band is
    visible, disabled, and explains itself.

  -----------------------------------------------------------------------------
  2026-09-14 — EXCEL ROW-ROLE WORK, STEP 6 BUILT: THE TEACHING NOW REACHES THE
  READ. THE PLAN'S OWN PLACEMENT WAS WRONG, AND THE TEST PROVES IT.
  -----------------------------------------------------------------------------

    THE PLAN SAID: bind exclusions "before the hardcoded vocabulary skip".
    BUILT INSTEAD: after the TOTAL-row branch. The plan's placement is a real bug,
    and it was caught by writing the constraint down as a test and then deliberately
    building it the planned way to watch it fail:

      skip placed before the TOTAL branch, excludeSigs=['total']:
        contractAmount 0.0  (expected 400,000)

    WHY. $contractTotal prefers the SUM OF ACTIVITY CONTRACT AMOUNTS and falls back
    to the TOTAL row only when that sum is <= 0 (:1669). On a file whose activities
    carry month values but no per-row contract amounts — common — the TOTAL row IS
    the denominator. Skipping earlier means $totalRowContract is never recorded, the
    denominator goes to zero, every month computes as 0, and the final month is then
    FORCED to 100. The result still looks like an S-curve. One tick on one row would
    have silently changed the basis of the entire calculation.
    Placed after the TOTAL branch, the row's figure is banked before the skip runs.
    Still before $isActivity, so the row contributes no month pesos and no activity
    contract amount — which is the whole meaning of "do not sum this row".
    Pinned by test_excluding_the_total_row_does_not_destroy_the_denominator, whose
    fixture is built so the TOTAL row is the ONLY denominator source (on the other
    fixture the activity sum supplies one and the test would prove nothing).

    HOW IT THREADS. Taught roles ride on the $header array returned by
    findHeaderViaMaps, not through new parameters on parse()/buildMonths(). The rule
    path never sets those keys, so `?? []` downstream is today's behaviour exactly —
    which is precisely what makes the two map-path tests written back in STEP 2 the
    proof that an untaught map is bit-identical. That is what step 2 was for.

    CUMULATIVE BINDS IN TWO PLACES, ON PURPOSE. meta['cumulativeRow'] (:1561) and
    readSummaryReference (:1856) each found the row independently. Binding only the
    reader would let the meta name one row while another was actually read. Both now
    take the taught signature first, first-match, and the label sniff is not
    consulted at all once a signature is taught — the whole point is a file where
    the sniff is wrong. A taught signature that does NOT match deliberately yields
    NO reference rather than falling back to the regex: a reviewer who named a row
    and then uploaded a file without it should get nothing, not a different row.

    THE MEASUREMENT THAT MAKES THE FEATURE'S CASE. makeNovelSummaryWorkbook holds
    "Site Establishment Fund" (weight AND contract amount, no summary vocabulary —
    every existing guard reads it as work) and "RUNNING TOTAL %" (month values and a
    description, so the residue rule counts it too):
      untaught            4 activities, contract 600,000, month 1 = 50%
      one row taught      3 activities, contract 400,000, month 1 = 25.01%
      both taught         2 activities, contract 400,000, month 1 = 25%
    The 25.01 has its own test. That stray 0.01 is the un-excluded curve row's
    PRINTED PERCENTAGE leaking into the month's PESO total, and it is the only place
    in the entire output where the error is visible. Nothing else about the untaught
    read looks wrong: monotonic, ends at 100.

    EVERY EXCLUSION IS NAMED IN THE NOTES — matched once, matched several times
    (all excluded; the label is ambiguous in this file and picking one would be a
    coin flip), or matched nothing (the layout moved under the teaching, and silence
    would leave the reviewer believing a row was excluded when it was not).

    VERIFICATION.
      php artisan test     1214 passed (+6), same 5 pre-existing failures by name
      npm run test:js      158 tests, 156 pass, 2 intended blinding fails
      verify_p6.mjs        16 reads, 0 changed
      BUHI byte-identical  12 months, activities, cumulativeRow=89, gaps unchanged
      FALSIFIED: the planned placement fails the denominator test (above).

    THE EXCEL ROW-ROLE PLAN IS NOW COMPLETE, steps 1-6. Browser acceptance is the
    remaining gate, and there is a re-capture wave outstanding from step 3 that must
    happen first — approved layouts from before step 3 no longer match by hash.

  2026-09-15 — HEADER-ROW MEMORY (user-reported: taught R4 shows "none picked yet"
  on reopen, SEGMENT specimen). Display-only fix, no reader change.
    Cause: header_rows was used once to build sig then discarded; prefill only
    restored columns from column_map and defaulted header from [HEADER] tags
    (SEGMENT has none, all [DATA]). DB proof: 106 auto-capture null map +
    107 manual-teach live with correct spans/sig but no headerRows key; later
    106 backfilled by AI assist (same hash) to ai-derived live, 107 gone.
    Fix: teachColumns stores sorted headerRows ints in map; modal restores them
    when valid + present in block, else tagged HEADER, else empty (Excel only;
    PDF branch untouched). Old maps without the key behave as before;
    findHeaderViaMaps ignores it; no MAP_VERSION bump; no hash change so no
    new re-capture wave. Tests: +test_taught_header_rows_are_remembered.
    Suites: ScurveHeaderProposalTest 83/83; JS 158/156 + 2 intended blinding
    fails; full 1215 passed / same 5 pre-existing BY NAME; verify_p6 16 reads
    0 changed; BUHI covered by suite. Browser check owed: teach SEGMENT R4,
    reopen variant shows R4 ticked.

  2026-09-15 — AI-ASSIST HEADER MEMORY (follow-up: AI-derived row also showed
  "none picked yet"). Same display-only key, other producer.
    deriveMapFromAiLayout now stamps headerRows:[R#] — the AI-reported R when
    given, else the inferred label row's kept-ordinal (header always sits in
    the head, so the 40-row AI slice and 15-row stored block number it the
    same). Reader ignores it (matches by sig); old AI maps without it still
    consult. Live row 106 from the prior session keeps working, it just shows
    empty until re-learned. Tests: derive-map asserts headerRows [1];
    inferred-vs-explicit equality still holds.
    BROWSER-CONFIRMED 2026-09-15 (user): AI-derived layout reopens with its
    header ticked. Manual + AI header memory both proven live.

  2026-09-15 — BROWSER RESULTS FOR STEPS 4-5, ONE DISPLAY DEFECT, ONE UX ASK. No code.
    CONFIRMED (user): step 4 header-row nudge flashes when Save is blocked.
    CONFIRMED (user, 6-stage Excel): row pickers render and save. DB row 118
      (manual-teach live) holds excludeSigs ["total","cumulative % achieved"],
      cumulativeSig "total", headerRows [2] — the save path works.
    CONFIRMED (user, PDF row): role band shows disabled with "Excel layouts only —
      a PDF layout finds its cumulative row by shape."
    DEFECT 12 — ROLE PICKS VANISH ON REOPEN. Same class as header-row memory: the
      Excel prefill (modal ~:679-703) restores columns + headerRows but never reads
      excludeSigs/cumulativeSig, so _schTeach.excludedRows / cumulativeRow start
      empty. Display-only — the stored map and the read are unaffected.
      (Fixed the same day — see the next entry.)
    PICK WORTH A SECOND LOOK: row 118 taught Cumulative = R7 TOTAL, not R8
      CUMULATIVE % ACHIEVED. TOTAL carries no month values, so the reader binds to
      it and finds no reference (degrades to "no reference", curve unmoved). The
      desk does not warn when a cumulative pick has nothing under the month columns.
    UX ASK (user): replace the checkbox/radio lists with the column idiom — arm a
      "Header row" / "Not an activity" / "Cumulative row" palette button, then click
      R-badges on the grid. Client-only (same payload). Not ordered yet.

  -----------------------------------------------------------------------------
  2026-09-15 — DEFECT 12 FIXED: ROLE PICKS COME BACK ON REOPEN. ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Committed + pushed:  5e9ad07 header-row memory (and the 21 row-role commits
                           before it) on origin/wip/phase-5-parser-accuracy-premerge.
      This fix:            resources/views/admin/_manage_scurve_headers_modal.blade.php
                           + this plan entry. Blade only; no PHP, no JS file, no route.
                           BROWSER-CONFIRMED and COMMITTED 2026-09-15 as 413f810
                           (pushed later the same day with click-to-pick rows).
      Next ordered work:   click-to-pick rows (UX ASK above) — design notes at the end.

    ROOT CAUSE. The Excel prefill in schTeachColumns() rebuilt the columns and (since
    5e9ad07) the header rows from column_map, but never read excludeSigs or
    cumulativeSig. _schTeach.excludedRows / cumulativeRow therefore started empty, and
    a reopened layout showed "none — the system decides" although the map held the
    picks. Verified against the live DB before fixing: row 118 (manual-teach, live)
    stores excludeSigs ["total","cumulative % achieved"], cumulativeSig "total".

    THE FIX, AND WHY THIS SHAPE
      - New schRowSignature(cells) beside schRowLabel(): a browser twin of
        SCurveImportService::rowSignature() (drop empty + PHP-is_numeric cells, join,
        lowercase, collapse ASCII whitespace, PHP trim). SCH_PHP_NUMERIC holds the
        is_numeric pattern.
      - The prefill re-signs every non-header block row and ticks the rows whose
        signature the map names; the cumulative match also sets cumulativeRow.
      - REJECTED ALTERNATIVE: store R-numbers on the map, the way headerRows does.
        Signature matching was chosen because it (a) stores nothing new — no map key,
        no MAP_VERSION question, no hash change, no re-capture wave; (b) repairs rows
        ALREADY saved (row 118 reopens correctly without re-teaching); (c) shows what
        the reader will actually match, since the reader matches by signature too.
      - ONE ROW PER SIGNATURE, first in block order. The server refuses two picks that
        read the same, so ticking every duplicate would make Save fail on a layout the
        reviewer never touched — exactly defect 7's shape. The reader still excludes
        every matching row at read time; the notes already say "matched several times".
      - Placed AFTER the header-row restore (header rows are never pickable) and in
        its OWN try, so a failure cannot undo the column/header prefill (trap (d) of
        the regression restudy). PDF branch untouched.
      - PARITY IS THE CONTRACT. If schRowSignature and rowSignature ever drift,
        reopening shows fewer ticks (the safe direction — nothing is saved from it),
        but it would be this defect again. Change both together; re-run the probe.

    VERIFIED (probes in C:\temp\opencode — they extract the REAL modal code from the
    Blade, so they test what ships, not a transcription)
      php dump_sigs.php && node verify_roles.mjs
        parity: 33 block rows across 4 stored Excel layouts, 0 mismatches
        row 118 replay -> Not an activity = R7+R8 · Cumulative = R7   (= what was taught)
      php edge_sigs.php && node edge_sigs.mjs
        10 awkward cell shapes (1,200,000 · 5% · " 12 " · 1. · .5 · 1e3 · +5 · tabs ·
        Ñandú · 0x1A · blanks · n/a): 0 differ
      node extract_script.mjs && node --check modal_script.js   -> clean, 0 Blade directives
      php artisan view:clear && view:cache                       -> compiled
      npm run test:js   -> 158 tests, 156 pass + the 2 intended blinding fails
      PHP suite: not re-run — no PHP changed. Last full run 1215 passed / same 5.

    ✅ BROWSER-CONFIRMED 2026-09-15 (user): reopening row 118 reads "Header row R2 ·
      Not an activity R7 + R8 · Cumulative row R7" with both lists ticked — exactly
      what was taught. (The save-without-changes check was suggested; the user
      reported "working" without separately mentioning it.) Committed after this.

    STILL WORTH A LOOK ON ROW 118 (not a code defect): Cumulative = R7 TOTAL was
    probably meant to be R8 CUMULATIVE % ACHIEVED. Re-teach it if so.

    DESIGN NOTES FOR THE NEXT ORDER — click-to-pick rows (no code yet)
      Scope: the one Blade file; the Save payload (header_rows, excluded_rows,
      cumulative_row) does not change, so no PHP, test or route change is expected.
      Carry these from the regression restudy (§13, seventh round part two):
        - palette keys must NOT start with "m" (schTeachClickLetter now uses /^m\d+$/,
          but schTeachFieldLabel and any new dispatch must be checked too);
        - row clicks must not shadow the column-header clicks (the only way to teach
          PDF months);
        - picks live in _schTeach.headerRows / excludedRows / cumulativeRow, never in
          r.role (the grid badge and CUMULATIVE tag read r.role);
        - keep the three row buttons disabled on sourceType 'pdf', with the reason;
        - the step-4 Save nudge targets #teachHeaderBand — repoint it at the new
          "Header row" button when the checkbox list goes;
        - add the warning found above: a Cumulative pick with nothing under the month
          columns reads no reference.
      (All six carried into the build — see the next entry.)

  -----------------------------------------------------------------------------
  2026-09-15 — CLICK-TO-PICK ROWS ✅ BUILT (UNCOMMITTED). ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Status:             BROWSER-CONFIRMED (all 7 steps), COMMITTED and PUSHED
                          2026-09-15 together with 413f810 (defect 12).
      Files:              resources/views/admin/_manage_scurve_headers_modal.blade.php,
                          this plan, AGENTS.md. Blade only — no PHP, JS file, route or
                          migration; the Save payload is byte-for-byte the same shape.
      Nothing owed.       The Excel row-role track is closed end to end.

    WHAT THE USER ASKED FOR (2026-09-15): "make them similar to how to choose the
    columns — click the header button, then click the rows" (same for the role rows).

    WHAT CHANGED ON SCREEN
      - The collapsed "Header row … change" band, the "Not an activity / Cumulative
        row … change" band, and all three checkbox/radio lists are GONE.
      - The palette now has two lines. "Columns": Description … Month N, + month (as
        before). "Rows": [Header row: R2] [Not an activity: R7 + R8] [Cumulative row:
        R7] — each button shows what it holds.
      - Arm a row button, then click an R-number in the grid's left column. While a
        row field is armed the R-numbers are outlined; picked rows are tinted across
        the whole row and tagged HDR (blue) / SKIP (grey) / CURVE (green) beside the
        R badge. The R badge keeps the capture's own role colour.
      - Clicking a column letter with a row field armed (or an R-number with a column
        field armed) assigns nothing and explains why in a toast.
      - PDF rows: "Not an activity" and "Cumulative row" are disabled and the reason is
        printed beside them ("Excel layouts only — a PDF layout finds its cumulative
        row by shape."). "Header row" stays usable, as the old picker was.
      - New ADVISORY line under the Save hint (amber, never blocks Save): a Cumulative
        pick with no values under the assigned month columns says so — the row-118
        case, where TOTAL was picked instead of CUMULATIVE % ACHIEVED.
      - The step-4 Save nudge now ARMS "Header row" and flashes that button (the list
        it used to open no longer exists). Still once per teaching session.

    RULES OF A ROW CLICK (schApplyRowPick — pure, no DOM)
      Header row      toggle; at most two; picking a row as header STEALS it from the
                      role picks (like a column letter moving between fields).
      Not an activity toggle; un-excluding the curve row also clears the curve.
      Cumulative row  single; picking implies Not an activity; clicking it again clears
                      the curve but leaves the row excluded (visible as SKIP) — the old
                      radio "None" behaved the same.
      Refused on the click, each mirroring a refusal teachColumns() already makes on
      Save: a row tagged HEADER; a row the reviewer picked as header; a row with no
      label text (empty signature); a row that reads the same as another role row;
      either role field on a PDF row.

    TRAPS FROM THE REGRESSION RESTUDY, AND HOW EACH WAS HANDLED
      (a) m-prefix: row keys are rowHeader/rowSkip/rowCurve; schTeachFieldLabel was
          still `indexOf('m') === 0` and now uses /^m\d+$/ like schTeachClickLetter.
      (c) picks never touch r.role — they live in _schTeach.headerRows / excludedRows /
          cumulativeRow, and the grid draws tint + tag from those.
      (d) prefill untouched (defect-12 prefill still fills the same three fields).
      (f) column letters (th[data-sch-letter]) and R-numbers (td[data-sch-row]) are
          separate targets with separate handlers; a row click cannot shadow a letter.
      (g) gating uses _schTeach.sourceType, as before.
      Save nudge repointed at the new button (#teachFieldBtn-rowHeader).

    FUNCTIONS (all in the modal's inline script)
      new      SCH_ROW_FIELDS, SCH_ROW_PDF_REASON, schIsRowField, schRowFieldTitle,
               schRowFieldValue, schApplyRowPick (pure), schRowHasMonthValues (pure),
               schTeachClickRow
      changed  schRenderTeachPalette (two lines), schRenderTeachGrid (row targets, tint,
               tags), schTeachClickLetter (row-field guard), schTeachFieldLabel (m-trap),
               schNudgeHeaderPicker (arms instead of opening), schRenderTeachReadout
               (hint wording + advisory), open sequence
      removed  schToggleHeaderPicker, schRenderTeachHeaderRows, schToggleRowPicker,
               schRenderTeachRowRoles; markup ids teachHeaderBand, teachHeaderRows,
               teachRoleBand, teachExcludedRows, teachCumulativeRows and their summaries
               (grep of resources/public/tests/app: no references left)

    VERIFIED
      C:\temp\opencode\verify_rowpick.mjs — extracts the REAL pure functions from the
        Blade and replays clicks against DB row 118's stored block: 14 scenarios pass
        (the intended R8-curve/R7-skip teaching, advisory true/false/null, curve move,
        toggles, HEADER-tag and picked-header refusals, header steal, two-header cap,
        PDF refusal with header still allowed, numbers-only row, same-reading twin,
        key names never month-shaped, unknown row/field no-op). Regex survived.
      C:\temp\opencode\falsify_rowpick.mjs — three guards broken one at a time in a
        COPY of the modal (HEADER refusal, header steal, curve-implies-exclusion):
        all three CAUGHT. Real modal passes again afterwards.
      verify_roles.mjs (defect-12 prefill) still: 41 rows / 5 layouts / 0 mismatches.
      node --check on the extracted script: clean, 0 Blade directives; every id the
      script asks for exists once in the markup. view:clear + view:cache: compiled.
      npm run test:js: 158 / 156 + the 2 intended blinding fails.
      ScurveHeaderProposalTest + SCurveImportTest: 122 passed (no PHP changed).

    ✅ BROWSER-CONFIRMED 2026-09-15 (user): "they are now all working" — all seven
    steps below. Committed and pushed right after this entry was written.
    BROWSER SCRIPT (as run by the user) — the DOM/click half no probe can reach
      1. Excel, 6-stage layout: open Teach. Rows line reads "Header row: R2 · Not an
         activity: R7 + R8 · Cumulative row: R7"; R2 tinted HDR, R7 CURVE, R8 SKIP.
         The amber advisory names R7 TOTAL.
      2. Arm Cumulative row, click R8 → R8 turns CURVE, R7 stays SKIP, advisory clears.
      3. Arm Not an activity, click R2 → toast refuses (tagged HEADER), nothing changes.
      4. With a row field armed click a column letter → toast, no assignment. Arm
         Month 1, click a letter → assigns as before.
      5. Save as variant → saves; reopen → same picks come back.
      6. PDF layout (roof): Rows line shows Header row usable, the two role buttons
         disabled with the reason; month letters still assign.
      7. Capture with no HEADER-tagged row (SEGMENT): assign Description + two months
         → Header row button arms itself and flashes red once; click R4 → Save enables.

  -----------------------------------------------------------------------------
  2026-09-15 — LATENT-DEFECT BATCH ✅ BUILT (UNCOMMITTED). ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Last commit:   238a77c (click-to-pick rows), pushed.
      Uncommitted:   the four fixes below + tests + this entry + AGENTS.md.
      Order given:   user 2026-09-15 "lets go with your suggestion" = (1) this batch,
                     (2) move the teach modal's pure functions under npm run test:js,
                     (3) early-100 guard. Rule 10: report after (1) before starting (2).
      Browser check: not required — no screen changed; see "why no browser run" below.

    FIX A — CONTROLLER CLASS NAME DID NOT MATCH ITS FILE (real Linux deploy bug).
      app/Http/Controllers/Admin/SCurveHeaderProposalController.php declared
      `class ScurveHeaderProposalController`. PSR-4 maps a name to a path letter for
      letter; it only worked because routes/web.php imports the FILE's spelling and PHP
      class names are case-insensitive once loaded. Any reference to the declared name
      would fail to autoload on a case-sensitive filesystem. Declaration renamed to
      SCurveHeaderProposalController (matches file, routes, sibling
      SCurveImportController); three "Called by" docblocks in SCurveImportService fixed.
      Checked: no optimized Composer classmap holds the old name; route:clear run;
      route:list shows all 10 desk routes resolving.

    FIX B — storeAiDerived COULD HIJACK AN EXCEL ROW INTO A PDF ROW.
      It matched on (header_hash, contractor_id) with no type, while collect() scopes
      by source_type precisely so the two never merge — and the PDF ai-derived branch
      then ran update(['source_type' => 'pdf']) on whatever row came back. On a hash
      collision a map-less Excel row received a PDF map and became a PDF row for good.
      NOW: storeAiDerived(..., string $sourceType = 'excel') scopes the match by type
      and stamps it on create; the PDF branch passes 'pdf'; the after-the-fact patch is
      deleted. The Excel caller (SCurveImportController LearnMap) is unchanged — the
      default is its type.
      NEW TEST test_an_ai_derived_pdf_layout_never_hijacks_an_excel_row forces the
      collision through the real endpoint. FALSIFIED: against the old model +
      controller it fails "the Excel row must stay an Excel row".
      TWO FIXTURES UPDATED, and why (not hidden): test_an_ai_numbering_backfills_an_
      unnumbered_capture and test_a_numbered_capture_is_not_overwritten_by_the_ai call
      storeAiDerived directly on PDF rows with four arguments. They describe the PDF
      path, which now names its type, so they pass 'pdf'. With the old signature they
      were silently relying on the unscoped match — the "fixture becomes a spec" lesson
      again.

    FIX C — THE PDF CUMULATIVE TAG COULD STEAL A HEADER OR META TAG.
      scurve-pdf-parser-core.js, both block builders (buildMergedSliceBlock and
      buildPdfSliceBlock): `if (isCumulativeShapedRow(...)) role = 'CUMULATIVE'` ran
      unconditionally. Now `role !== 'HEADER' && role !== 'META' && ...`. NOT `else if`
      — a real cumulative row usually matches isSummaryLabel first, and `else if` would
      kill the SUMMARY -> CUMULATIVE upgrade (the §13 step-3 entry's warning).
      NEW JS TEST "a META line shaped like a running total keeps its tag; the real
      curve row is still promoted" — a title line "PLANNED 25% 60% 100%" above the
      header stays META, the header stays HEADER, "CUMULATIVE % 25% 60% 100%" below is
      still CUMULATIVE. FALSIFIED: with the core stashed it fails "the title-block line
      stays META".
      WHY NO BROWSER RUN: the block the desk renders is BYTE-IDENTICAL on all 9 sample
      PDFs (C:\temp\opencode\block_snapshot.mjs, snapshot saved before the edit,
      compared after: 9 compared, 0 changed — 6 produce blocks, 3 produce none). So no
      screen changes, and no header_hash moves — NO re-capture wave. The roof's block
      still reads R1/R2 META, R3 HEADER, R16 CUMULATIVE.

    FIX D — HOUSEKEEPING.
      - SCurveImportService::isCumulativeLabel() removed: zero callers; its docblock
        (the Step 2a / roof-typo story) folded into isCumulativeRow(), which does the
        work.
      - collect() in BOTH desks (SCurveHeaderProposalController and
        ParserKeywordProposalController) now calls ensureReviewer() instead of an
        inline copy of the same role check. Response identical (403 JSON).
      Still NOT done, deliberately: the policies registered in AppServiceProvider are
      never invoked (enforcement is route middleware + ensureReviewer). Fail-safe;
      wiring authorize() calls is a separate, testable change if ever wanted.

    VERIFIED
      php artisan test         1216 passed (+1) / the same 5 pre-existing BY NAME
                               (DashboardOverdueInspection count badge, 2x EmailDraft,
                               IssueLifecycle default baseline, RiskRegister default
                               risk baseline)
      npm run test:js          159 tests (+1), 157 pass + the 2 intended blinding fails
      verify_p6.mjs            16 reads, 0 changed (reader untouched)
      block_snapshot.mjs       9 sample PDFs, 0 changed (desk block untouched)
      php -l on every touched PHP file; route:clear + route:list.

    ASKED AND ANSWERED (user, 2026-09-15): "should Excel also find the cumulative row
    by shape, like PDF?" — RECOMMENDATION: not as a reader rule; no code.
      - The PDF check's decisive signal is that cells are WRITTEN with "%". The Excel
        reader loads cells with formatting OFF (toArray(null, true, false, true)), so
        "5%" arrives as 5 or 0.05 and that signal does not exist. Without it,
        "rising and ending high" also describes cumulative-days rows (…187),
        cumulative-cost rows (…32M) and month-index rows — the guard would lean on a
        near-100 endpoint alone.
      - Where it would bind is the risky part: the activity-loop skip. A wrong skip is
        the silent failure the row-role work exists to prevent (denominator and every
        month move, curve still ends at 100).
      - Nothing needs it: every real cumulative row in the corpus is already found by
        label (6-stage, BUHI, roof typo), and a novel wording can now be TAUGHT with
        one click — which the PDF side cannot offer. Shape detection is the PDF's
        substitute for teaching, not an extra layer on top of it.
      - Safe variant if ever wanted: use shape only as a SUGGESTION in the teach modal
        ("R8 looks like a running total — mark it?"), never in the reader.
    COMMITTED + PUSHED as cd2804a (2026-09-15).

  -----------------------------------------------------------------------------
  2026-09-15 — ITEM 2: THE TEACH MODAL'S LOGIC IS NOW UNDER npm run test:js
  ✅ BUILT, COMMITTED + PUSHED (2026-09-14 late, its own commit)
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Status:         committed and pushed. Browser evidence in the next entry (the
                      module loads; a PDF teach ran through it end to end).
      Files:          public/js/admin/projects/scurve-teach-core.js (NEW)
                      resources/views/admin/_manage_scurve_headers_modal.blade.php
                      resources/views/layouts/app.blade.php, stafflayouts.blade.php
                      tests/js/scurve-teach-core.test.mjs (NEW)
                      tests/fixtures/scurve_row_signature_cases.json (NEW)
                      tests/Feature/SCurveImportTest.php
      Still unshown:  browser steps 2 (Excel prefill on reopen) and 5 (staff layout).
      Next ordered:   item 3, the early-100 guard.

    WHY. The rules that four acceptance rounds and two defect fixes stabilised —
    the one-letter-per-month prefill (defect 7), the every-page prefill (defect 6),
    the header and row-role memory (defect 12), the row-click rules — lived in the
    modal's inline <script>. Nothing tested them; they were pinned by one-off probes
    in C:\temp\opencode that cut functions out of the Blade TEXT. §13 had already
    said "if a harness ever exists, defect 7's rule is the first thing to move".

    SHAPE CHOSEN, AND THE ALTERNATIVE REJECTED
      Chosen: a pure ESM core, public/js/admin/projects/scurve-teach-core.js, the
        same pattern as scurve-import-core.js / scurve-pdf-parser-core.js — loaded
        with <script type="module">, attaches window.scurveTeachCore, imported
        directly by the test. The test runs the bytes the browser runs.
      Rejected: a test that extracts functions from the Blade by text surgery (what
        the probes did). It tests shipped code too, but breaks on any reformat and
        cannot be imported like a module.
      Loaded by BOTH role layouts right beside `@include('admin._manage_scurve_headers_modal')`,
        filemtime-versioned. Beside the include, not on some pages: the desk is
        layout-wide, so its dependency is too (the 2026-09-13 showDeleteConfirmation
        lesson).

    WHAT MOVED (core export -> modal wrapper that now delegates)
      splitCsv / parseHeaderBlock      -> schSplitCsv / schParseHeaderBlock
      indexToLetter                    -> schIndexToLetter
      rowLabel / rowSignature          -> schRowLabel / schRowSignature
      suggestedMonthsFor               (was an inline IIFE in schTeachColumns)
      defaultHeaderRows                (was inline in schTeachColumns)
      lettersInBand + prefillFromMap   (was the ~120-line inline prefill block)
      ROW_FIELD_KEYS, ROW_PDF_REASON, isRowField, isMonthField
      applyRowPick / rowHasMonthValues -> schApplyRowPick / schRowHasMonthValues
    Behaviour is a straight move — same comparisons, same order, same best-effort
    guards (the prefill keeps separate guards for columns, header memory and role
    memory). The wrappers keep their sch* names, so no call site changed.
    SCH_ROW_FIELDS (button labels + tooltips) stays in the modal: it is UI text. The
    test reads its keys out of the Blade and asserts they equal ROW_FIELD_KEYS.
    schCore() throws a plain "not loaded — hard-refresh" error if the module is
    missing, instead of failing somewhere inside a render. No top-level call into
    the core exists (module scripts run after the classic script is parsed).

    SIGNATURE PARITY IS NOW PINNED FROM BOTH SIDES
      tests/fixtures/scurve_row_signature_cases.json (13 cases) is read by the new JS
      test AND by SCurveImportTest::test_row_signature_matches_the_shared_parity_cases.
      Changing rowSignature in one language without the other turns a suite red.

    TESTS: +29 JS (tests/js/scurve-teach-core.test.mjs), +1 PHP.
      Block parsing (quotes, roles, junk), letters past Z, row labels, signature
      parity; opening state (tagged header default, F6 empty default, suggested
      months for PDF numbered / unnumbered / capped / Excel); Excel prefill (columns,
      header memory, pre-memory maps, invalid R-numbers, one row per signature, a
      header row never ticked as a role, malformed map does not throw); PDF prefill
      (dominant overlap, band-share, paired month, continuation-page graze, every
      page, first-page description); row keys never month-shaped and equal to the
      modal's palette; all fourteen row-click scenarios; the cumulative advisory.
      The 6-stage fixture is the stored block of the SYNTHETIC 6-stage specimen; the
      PDF geometry is transcribed from the roof's measured coverage, no roof text.
      CAUGHT WHILE WRITING (mine, both fixture errors, neither a code defect): a desc
      band spanning A and B was asserted to name B (the rule names the first claimed
      letter, A — as the roof's own read-back does); and a "header never ticked" case
      overrode excludeSigs but inherited the map's cumulativeSig, so R8 was correctly
      ticked. Both fixtures corrected, rule untouched.

    FALSIFIED (C:\temp\opencode\falsify_teach_core.mjs — mutates the core IN PLACE,
    runs the suite, always restores; verified byte-identical afterwards)
      defect 7 any-overlap        -> caught (2 tests)
      defect 6 first page only    -> caught
      defect 12 every duplicate   -> caught
      HEADER-tag refusal removed  -> caught
      signature drift (no lower)  -> caught (5 tests)
      PHP side: mb_strtolower removed from rowSignature -> the PHP parity test fails;
      file restored with git checkout (it had no other edits).
      NOTE, a near-miss worth keeping: the first PHP mutation was done with sed and
      silently matched nothing — the test "passed" against unchanged code. Caught
      because the diff stat was empty. Redone with a node string replace. Same class
      as the regex-through-a-shell lesson: when a falsification passes, first prove
      the mutation happened.

    VERIFIED
      npm run test:js     188 tests (+29), 186 pass + the 2 intended blinding fails
      php artisan test    1217 passed (+1) / the same 5 pre-existing BY NAME
      verify_teach_wiring.mjs  every schCore().<name> the modal uses (13) exists on
                          window.scurveTeachCore; no inline copy of moved logic
                          remains; core rowSignature = PHP on all 25 rows of the 3
                          stored Excel layouts currently in the DB
      node --check on the extracted modal script: clean; view:clear + view:cache OK.
      SUPERSEDED PROBES (outside the repo, kept for history, now stale because they
      cut functions out of the Blade): verify_roles.mjs, verify_rowpick.mjs,
      falsify_rowpick.mjs. The suite replaces all three.

    BROWSER CHECK OWED (user) — the one thing no suite can see is the module loading
      1. Hard-refresh any admin page, open S-Curve Layouts: the list's mini-grids
         render (they parse through the core).
      2. Teach the 6-stage Excel row: palette prefilled (Description, months, Rows
         line "Header row: R2 · Not an activity: R7 + R8 · Cumulative row: R8").
      3. Click an R-number with a row field armed, and a letter with a month armed —
         both still assign.
      4. Open the roof PDF layout: months prefilled one letter each, M6/M7 present.
      5. Repeat step 1 logged in as STAFF (the other layout carries its own tag).
      If the desk shows "Could not open the teaching view", the console will say
      "scurve-teach-core.js is not loaded — hard-refresh the page."

  -----------------------------------------------------------------------------
  2026-09-14 (late) — ROOF PDF READ WRONG THROUGH A MISFIT LAYOUT: FIXED IN THE
  READER, COMMITTED + PUSHED. ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    ⚠ DATE NOTE, READ FIRST. The §13 entries headed "2026-09-15" (header-row memory
    onward) were in fact written on the LOCAL date 2026-09-14 — file mtimes and DB
    created_at (Asia/Manila) prove it: scurve-teach-core.js 23:04, row 123 23:31,
    this entry ~23:40, all 2026-09-14. They are left as written, not rewritten, so the
    commit messages that quote them still line up. Read "2026-09-15" above as
    "2026-09-14, later that evening".

    STATE IN ONE GLANCE
      Status:       committed and pushed 2026-09-14 late, as the commit after ITEM 2.
                    NOTHING in this track is uncommitted.
      Files:        public/js/admin/projects/scurve-pdf-parser-core.js +
                    tests/js/scurve-pdf-parser.test.mjs + this plan + AGENTS.md
                    (+ a pointer in document_parser_pdf_layout_learning_plan.md §9).
      Item 2 browser evidence (user, this session): desk list mini-grids render
        through the core (screenshot); the teach modal opened on a PDF capture with
        the role buttons disabled and their reason; the user then TAUGHT that capture
        (row 123, created 23:31, after the core file existed at 23:04) and the
        re-import read the true curve — so the modal's clicks, save and the PDF path
        all ran through the new core. Not separately shown: Excel prefill (step 2)
        and the staff layout (step 5).
      Optional for the user: the two unshown item-2 steps, and a roof re-import with
                    only row 123 live (the probe says it reads the true curve).
      NEXT ORDERED WORK: item 3, the early-100 guard. Its two constraints are in
                    AGENTS.md (client-side after the P5 cross-check; read
                    pre-sanitizer values via the extractRawPoints idiom).

    THE REPORT (user). With DB row 116 (roof, auto-capture, LIVE) approved, the roof
    PDF imported "with a learned layout" and read
      6.32 / 24.31 / 42.30 / 42.30 / 59.71 / 77.03 / 100
    — month 4 repeated, 99.14 gone, July and January listing no activities. Deleting
    116 made the file miss (capture #122, unnumbered), the user taught it (#123), and
    that read the truth. The complaint: the file was read WRONG instead of not read.

    DIAGNOSIS (read-only; C:\temp\opencode\dump_pdf_layouts.php + probe_roof_maps.mjs,
    the REAL roof PDF through the REAL core with the maps from the DB)
      Rows 114 / 115 / 116 — all created 2026-09-14 19:29-19:32 during testing, all
      soft-deleted since — are ROOF layouts of an OLD SHAPE: ONE page, SEVEN equal
      month bands from 0.379 to the page edge. The real print is column-split: page
      one carries SIX "30 days" labels (months 1-5 plus the empty stub column) at
      0.425 / 0.530 / 0.635 / 0.740 / 0.846 / 0.950 of the page width; months 6-7 are
      on page two.
      Replay reproduced the user's read EXACTLY for all three:
        #114/#115/#116  6.32/24.31/42.3/42.3/59.71/77.03/100   counts 0,4,4,4,4,3,0
        #123 (taught)   6.32/24.31/42.3/59.71/77.03/99.14/100  counts 4,4,4,4,3,4,3
      WHY IT APPLIED: matchMapPage accepts a stored page when its header words match
      (every label reads "30 days", so any print of the family matches) and numbers
      sit under at least two bands. Nothing asked whether the stored bands line up
      with where THIS page prints its labels. Seven bands over six labels: the
      month-4 band 0.652-0.744 contains no label at all, and every band after it
      reads its neighbour's column.
      ALSO FOUND: usableLayoutMap's docblock claimed a SHAPE gate ("single-page
      layouts of a column-split table are ignored outright"). The code only ever
      checked version, kind and a non-empty page list. The comment was describing a
      guard that did not exist — which is part of why this stayed hidden.

    THE FIX — bandsHoldLabels(tokenLines, headerIndex, monthCols), called in
    matchMapPage after the signature test:
      EVERY stored month band must contain the centre of at least one LABEL token
      (a token carrying a letter) on the header line or the line directly below it.
      - "every band has a label", never "every label has a band": the taught roof
        layout skips the stub column on purpose, and must still apply;
      - letter-bearing tokens only: numbers under the header are data, which is
        bandsHoldNumbers' question, and would trivially satisfy the rule;
      - the line below counts (a header split "MONTH" / "1"); lines ABOVE do not —
        the roof prints "187 days" there, inside the misfit month-4 band, and would
        rescue exactly what this exists to catch.
      A band derived from a header, from an AI read or taught from the grid always
      covers its own label, so the rule costs a fitting layout nothing.
      usableLayoutMap's docblock corrected to say what it actually checks.

    RESULT (probe_roof_maps.mjs after the fix)
      #114 / #115 / #116  -> NULL  (the file is honestly unread: capture + AI offer,
                                    exactly what the user expected with no fitting
                                    layout approved)
      #121 / #122         -> NULL  (unnumbered, as before)
      #123                -> 6.32/24.31/42.3/59.71/77.03/99.14/100, counts 4,4,4,4,3,4,3
    Only #123 is live in the DB now, so nothing the user has approved changes.

    TEST +1 JS "a layout whose bands miss the page's own labels does not apply": a
    roof-shaped page (six "30 days" labels, five filled columns); a five-band layout
    that skips the stub still reads (5 months, mapUsed); a seven-equal-band layout —
    the shape of #116 — leaves the file unread.
    FALSIFIED (falsify_labels.mjs): with the bandsHoldLabels call disabled in place,
    the test fails "a layout whose band holds no label must leave the file unread";
    core restored byte-identical and verified.

    VERIFIED
      npm run test:js     189 tests (+1), 187 pass + the 2 intended blinding fails
      verify_p6.mjs       16 reads, 0 changed (every sample PDF, plain AND through its
                          own taught map — the rule refuses nothing that fits)
      block_snapshot.mjs  9 sample PDFs, 0 changed
      PHP: not re-run — no PHP changed since the 1217-pass run of item 2.

    INCIDENT WORTH KNOWING (harness, not product): one verification command piped
    `node --test` into `grep | head` and stalled past the 2-minute limit. Isolating
    each parse with `timeout` showed all three finish in <10 ms — the parser never
    hung. Run node --test to a log file, then grep the file.

    NOT FIXED, DELIBERATELY: HOW a one-page seven-band roof layout got captured and
    approved at 19:29-19:32 is not reconstructed — those rows are deleted, the build
    that produced them is several commits back, and the reader now refuses that
    shape whatever made it. If a fresh capture ever produces one again, that is a
    capture bug to chase from its own evidence.

  -----------------------------------------------------------------------------
  2026-09-15 — THREE FINDINGS FROM A ONE-PAGE ROOF PRINT AND A PRINTED BUHI PDF.
  DIAGNOSIS ONLY, NO CODE. ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Code:          NOTHING changed. Last commit 7b3402e. All three findings below
                     were measured read-only against the real files through the real
                     core (probes listed at the end of this entry).
      Ordered next (user, 2026-09-15), in this order:
        (1) THE SPLIT-DOWN GUARD (finding C) — a PDF printed top-to-bottom-then-
            across must stop reading as a confident baseline. Needs its own
            measurement pass before any rule is chosen (design notes in C).
        (3) PDF DESCRIPTION-BAND TEACHING (findings A + B) — steps 1-2 are buildable;
            step 3 waits for a re-printed BUHI specimen (see "owed").
        (2) FULL SUPPORT FOR SPLIT-DOWN PRINTS — PARKED by the user. Revive only if
            the PMU actually receives large schedules as PDF only. The user will ask
            the client to send the ORIGINAL EXCEL WORKBOOK for bigger S-curve tables
            (confirmed by the user 2026-09-15 — the BUHI Excel already reads exactly).
      Owed by the user:
        - re-print the BUHI workbook with Page Layout -> Print Titles -> rows 10-11
          repeated at top, and Scaling -> Fit All Columns on One Page; save it in
          sampledocs/. That is the specimen step 3 is measured on. Guidance given
          2026-09-15: clear any stale print area first, check Page Break Preview, and
          export with Excel's own PDF export (File -> Export / Save As PDF), NOT
          Microsoft Print to PDF — that driver outlined all text to curves on
          "estimate_only page.pdf" (learning-loop plan, 2026-09-08). Ctrl+A-paste the
          result to confirm a text layer before using it.
          WHY THE DEFAULT PRINT WAS UNCONTROLLABLE (read-only, probe_buhi_pagesetup.php):
          sheet S-CURVE has NO print area, no print titles, scale 100 / fit-to-page off,
          landscape Folio (paper 14) — and its used range runs to HV104 (title-block
          artifacts far right of the table, same as the "A10:IR82" note in the accuracy
          plan). The table itself is A10:AF92 (Month 12 = AE:AF), summary rows 88-92,
          signatures from row 95. So the guidance sets print area A1:AF104 explicitly.
      DONE 2026-09-15 — the ONE-PAGE roof print is saved as
        sampledocs/CSPC-Rehabilitation-of-Roof-at-Academic-Building-II-S-CURVE-pdf2.pdf
        (368,323 bytes, 1 page 792x612, 297 text items, "7 days" and "Commulative"
        both on the page — verified read-only, probe_roof2_pages.mjs). The two-page
        print keeps its original name without the "2". Both roof prints join the
        guard's measurement set, making it 10 PDFs, not 9.
      Rule 10 applies before each item.

    FINDING A — ONE-PAGE ROOF: THE AUTO-CAPTURE SHOWS "DESCRIPTION = A". DISPLAY ONLY.
      REPORT (user): on a roof print with the whole table on one page, the capture's
        teach modal opened with DESC on column A (ITEM NO.), where the names are in B.
        Approved as-is it read the truth: 6.32 / 24.31 / 42.30 / 59.71 / 77.03 /
        99.14 / 100, counts 4,4,4,4,3,4,3, learned-layout + adoption notes. Re-taught
        by hand as Description=B, Contract amount=E, M1..M7=F..L, it read EXACTLY the
        same. This print has no phantom "30 days" column (the two-page print's K).
      WHY IT SAYS A: a derived map stores the description as a BAND (roof: ITEM NO. |
        DESCRIPTION | UNIT | QTY = A..D), not a column. prefillFromMap names the FIRST
        letter the band claims (scurve-teach-core.js :212, lettersInBand(...)[0]).
      WHY A AND B READ THE SAME: the reader uses only the band's RIGHT edge —
        buildRows takes every token with t.x < descLimit, descLimit =
        min(descRight, firstMonthX) (scurve-pdf-parser-core.js :669/:683). descX, the
        LEFT edge, is never read by buildRows. Both bands end where E begins, hence
        identical output — and why every name reads "A FACILITIES FOR ENGINEER".
      VERDICT: not a reading defect, but it misleads the one person the screen is for.
        Folded into item (3) step 1 below.

    FINDING B — "CONTRACT AMOUNT" IS NOT RESTORED ON REOPEN, AND ON PDF IT IS NOT A
    FIELD AT ALL.
      REPORT (user): after teaching Contract amount=E, reopening the layout showed no
        AMT badge. Suspected to be the header-row-memory bug again. It is NOT the same
        bug: header rows were saved-then-forgotten; the contract column is NEVER SAVED.
      WHAT THE CODE DOES: buildTaughtPdfMap (SCurveHeaderProposalController :636-789)
        reads contract / weight / code ONLY as "stops" when widening the taught
        description to a band (:711-729), then discards them. The stored PDF page holds
        descX, descRight, monthCols, pseudoCols, sig, hints — no contract key. The PDF
        reader has no contract column concept either (grep: no contractX/weightX in the
        core); its contract comes from the TOTAL row / off-column fallback (P2-d).
      EVERY COLUMN BUTTON, BOTH FILE TYPES (asked by the user):
        button            EXCEL (SCurveImportService)          PDF
        Description       names + activity residue            right edge of the band only
        Month N           month peso spans                    month bands
        Contract amount   denominator, TOTAL row, skip guard   never stored or read —
                          (:1600, :1644, :1693)                a stop for the desc band
        Weight            isActivity + weight sanity (:1598)   same: a stop only
        Item code         activity sub-label (:1707)           same: a stop only
      => EXCEL KEEPS ALL FOUR. On PDF, three buttons are boundary hints dressed as
         fields.
      THE WIDENING RULE IS ROOF-SHAPED (the user's catch, and correct): the band is
        stretched to the nearest column assigned to another button. On the roof that
        is right because Amount follows QTY. On a BUHI-shaped header — DESCRIPTION C |
        AMOUNT (IN PESOS) D | 3% DISCOUNT E | CONTRACT AMOUNT F | % WEIGHT G | NO. OF
        DAYS H | months I.. — teaching Contract=F stops the band at F, so AMOUNT and
        3% DISCOUNT fall inside it ("Construction of Temporary Field Office
        184,800.00 5,544.00"); only Weight taught -> stops at G; nothing taught -> stops
        at the first month, swallowing D..H. The AUTO path (P2-b: next header label
        after the description) would stop correctly at AMOUNT, so a hand-taught map
        would be WORSE than the automatic one on that layout. Not necessarily cosmetic:
        on the roof the desc band alone moved the curve (2.48/10.8/... vs 6.32/24.31/...,
        §13 2026-09-14) because it changes which rows count as named.
      AGREED DIRECTION FOR ITEM (3) — supersedes the first proposal of the same day
      (remember the contract letter / rename the badge / tooltip):
        step 1  on PDF rows, Description accepts a RANGE of letters (like a month with
                two columns); the band is exactly what was clicked, and reopen shows
                every letter it holds (B+C+D) — which also retires finding A.
        step 2  Contract amount / Weight / Item code DISABLED on PDF rows with the
                reason printed beside them (the row-role button pattern, trap (g):
                gate on _schTeach.sourceType).
        step 3  the single-letter fallback rule — candidate: stop at the first column
                right of the description whose activity-row cells are numbers. NOT
                chosen until measured on the real roof (both prints) and the re-printed
                BUHI. Unmeasured question: roof UNIT holds "LOT" (text) and QTY holds
                "1.00" (numeric) — whether stopping at QTY still reads the true curve.
      CARRY INTO THE BUILD: the server's `desc` field is one letter today, so step 1
        touches teachColumns validation + buildTaughtPdfMap + scurve-teach-core.js +
        the modal. Excel stays byte-for-byte as is. Stored PDF maps hold positions, not
        letters, so every approved layout keeps reading exactly as it does. Validation
        rules must stay sometimes|nullable (trap (b)); no leading-"m" palette keys
        (trap (a)); do not bump PDF_MAP_VERSION (trap (e)).

    FINDING C — THE PRINTED BUHI PDF READS WRONG, WITH NO LEARNED LAYOUT INVOLVED.
      SPECIMEN: sampledocs/Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi-pdf.pdf (git-ignored,
        user-printed from the BUHI workbook). pdf.js sees 12 pages, 792x612 each (the
        user's viewer showed 8 — the count to trust is pdf.js's). The user had kept
        this file out of testing on purpose, for the reason this finding confirms.
      HOW EXCEL SPLIT IT — a GRID, printed down then across:
                         left block (ITEM..DAYS)   Month 1-5   Month 6-10   Month 10-12
        rows, top third  p1 header + names         p4 header   p7 header    p10 header
        rows, middle     p2 names only             p5 numbers  p8 numbers   p11 numbers
        rows, bottom     p3 TOTAL + CUMULATIVE %   p6 numbers  p9 numbers   p12 numbers
        The roof and SEGMENT are split ACROSS only (P3's case). Here the names live on
        pages with NO month header, and Month 10's two half-month columns straddle p7
        and p10. Header heights line up (p1 labels y=434, p4 month labels y=435) —
        the property a future merge would lean on, recorded for item (2).
      WHAT THE READER DOES TODAY (parseSCurvePdf, rules only):
        pages used    p4, p7, p10 only (the pages with a month header). The other nine —
                      every name, the TOTAL row, the printed cumulative row — ignored.
        curve         3.31 / 10.51 / 18.06 / 29.47 / 34.77 / 45.39 / 56.83 / 67.62 /
                      79.63 / 92.03 / 99.18 / 100   (curveSource 'activities')
        Excel truth   M1 1.12 · M3 6.74 · M4 10.59 · M6 21.09 · M7 28.64 · M9 49.73 ·
                      M10 68 · M12 100
        contract      11,530,091.76 (truth 32,588,531.43 — the top third of the rows)
        names         every activity "Activity N"
        summaryRef    []
        warnings      ONE: "pages carry different row counts (14 vs 12) — months were
                      aligned by position. Verify the monthly split before locking."
        => a wrong baseline with a normal-looking preview. Same failure class as the
           2026-09-14 misfit roof layout ("read wrong instead of not read"), reached
           without any learned layout.
      WHAT THE CAPTURE STORES: map with 3 pages (pdf p4/p7/p10), desc band 0 -> 0.12 /
        0.13 / 0.05 (no names on those pages), months 1-5 / 6-10 / 10-12 — month 10 on
        TWO pages, which buildTaughtPdfMap would refuse ("a month lives on one page").
        That refusal is correct for today's reader; this file is its first
        counterexample, parked with item (2). Block: COLUMNS A..AK, R3 [HEADER] = the
        month labels, R4.. [DATA] = bare peso cells, no names anywhere.
      WHY AI ASSIST DOES NOT RESCUE IT: buildSCurvePdfSlices sends at most 2 page
        images (cap 3), table pages first — i.e. p4 and p7: two-thirds of the rows
        missing, no names, no TOTAL. The P5 cross-check cannot fire either (it needs a
        printed cumulative row the reader found). So the honest message leads with the
        original Excel file, and presents AI assist as a weak second option at best.
      DESIGN NOTES FOR THE GUARD (item 1) — NOTHING CHOSEN YET, MEASURE FIRST:
        goal (user's words): show that this is not a reliable extraction.
        outcome options: honestly unread (parseSCurvePdf -> null, so the existing
          failure branch offers capture + AI beside the button) OR a read carrying a
          strong, specific warning. Decide from the measurement; leaning unread, since
          a baseline preview invites locking.
        candidate signals, each to be measured over ALL 10 sample PDFs (the 8
        originals + BUHI + the one-page roof pdf2):
          (a) a page carrying the table's label vocabulary (DESCRIPTION / AMOUNT /
              ITEM / WEIGHT / NO. OF DAYS) but no month header, while month pages exist;
          (b) EVERY activity row nameless (generic "Activity N");
          (c) pages holding numeric cells that no header claimed (p5/p6/p8/p9/p11/p12).
        hard constraints:
          - the 16-read regression (verify_p6.mjs) and block_snapshot.mjs stay 0 changed
            — 6-stage, 10-month, SEGMENT and the roof are column splits whose names sit
            on a month page, and must not trip it;
          - the learned-layout consult path must be checked too, not just the rules;
          - pure core, falsified test (fails with the guard disabled);
          - regex source written with the file editor, never shell-quoted.
        NOT the early-100 guard: that one is about a weak AI answer; this one is about
          the offline reader. The early-100 guard stays queued behind items 1 and 3.

    FINDING D (same day, later) — THE ONE-PAGE BUHI RE-PRINT READS A HALF-MONTH EARLY.
    A SECOND, DIFFERENT SILENT MISREAD. Diagnosis only, no code.
      SPECIMEN: sampledocs/Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi-pdf-1page.pdf
        (user re-print, 504,092 bytes). ONE page 2592x1728, 2,307 text items — the
        whole sheet on one page, every column, the header, names, TOTAL and the summary
        block all present. Usable as the step-3 specimen (it is a stronger one than the
        fit-columns print asked for: no page seams at all).
      WHAT THE RULES READ (no maps): header found, months 1-12, REAL names —
        curve   1.42 / 4.46 / 7.64 / 12.34 / 17.69 / 24.2 / 32.8 / 42.92 / 58.56 /
                76.6 / 93.11 / 100
        truth   M1 1.12 · M3 6.74 · M4 10.59 · M6 21.09 · M7 28.64 · M9 49.73 · M10 68
        counts  12,13,14,20,22,32,37,37,45,45,43,34  (Excel 8,11,12,14,19,20,32,34,38,
                43,41,41 — the PDF lists land about a month EARLY from month 4 on)
        contract 32,346,214 — exactly the truth 32,588,531.43 MINUS 242,317.46, the
                TOTAL row's first month cell. That one number proves the mechanism below.
      MECHANISM (measured, probe_buhi1_bands.mjs): finalizeHeaderBands starts each month
        band AT its month label (core :352, x: m.x) and ends it at the next label. That
        assumes a label sits at its column's left edge — true for the single-column
        prints in the 16-read set. BUHI prints each month as TWO cells under ONE merged
        label, and the label sits over the middle of the pair. So:
          Field Office row: 7,469.00 @741 falls LEFT of M1's band [793-934] -> read by no
          month; M1 gets @809 (its own 2nd cell) + @876 (month 2's 1st cell), and every
          later band takes the second cell of its month plus the first cell of the
          next; where column
          widths change (M5, M9) a band swallows three cells.
          TOTAL row: 242,317.46 @731 is outside every band — the missing contract piece.
        The Excel reader does not have this problem: it reads paired spans [col, col+1]
        starting at the label's own column.
      TWO SMALLER DEFECTS ON THE SAME READ:
        - the day-marker sub-header line (NO. / (IN PESOS) / AMOUNT / DAYS / 15 15 … 5)
          is read as an ACTIVITY named "NO. (IN PESOS)" — its 15s sit in month bands;
        - summaryReference carries 16 entries from FOUR rows (% ACCOMPLISHED fractions
          0.07 / 0.14…, CUMULATIVE % fractions, COST pesos) with duplicates, producing 13
          "the summary was likely computed against a different total" warnings — noisy,
          and their reassuring wording is wrong for a read that is itself shifted.
      WHAT THIS MEANS FOR THE ORDER:
        - the split-down guard (item 1) would NOT catch this: one page, header present,
          real names. Its signals (a)-(c) are all quiet here.
        - the natural fix is in the READER (band placement for multi-cell months under a
          merged label), i.e. the hottest path in the PDF core — the 16-read regression
          + block_snapshot gate applies, and the roof / SEGMENT / 6-stage / 10-month
          single-column prints must stay byte-identical. Not ordered; needs the user's
          decision on scope and order relative to items (1) and (3).
        - step 3's measurement can still use this file for the description band (names
          are right), but any curve comparison on it is meaningless until D is resolved.
      BROWSER-CONFIRMED (user, same day): the import shows exactly the probe's read —
        1.42 / 4.46 / … / 93.11 / 100, 12 import notes of the "summary was likely
        computed against a different total … no action needed" kind, and
        "NO. (IN PESOS)" listed as an activity in every month.

    FINDING D, PART TWO — USER DECISION: D MUST BE LEARNABLE, NOT A NEW RULE. MEASURED
    WHAT THAT TAKES. Still no code.
      THE USER'S FRAMING (confirmed correct): the BUHI Excel and the roof Excel are both
        read by the RULES — no stored layout needed. The user wants the BUHI-shaped PDF
        to become readable through the learning loop (teach / AI-derive -> approve ->
        free reads), not through another hardcoded reader rule.
      WHY IT IS NOT LEARNABLE TODAY — three blockers, each measured:
        1. A LEARNED LAYOUT IS NEVER CONSULTED ON THIS FILE. parseSCurvePdf (core
           :1585-1593) consults liveMaps ONLY when the rules recognise no table on any
           page. Here the rules DO recognise Month 1-12, so an approved layout would sit
           unused forever. By design ("maps extend the reader, they never override it").
           ⇒ the rules must first STAND DOWN on a read that is not trustworthy — which is
             exactly what a guard does. The guard and the learning loop fit together:
             guard says "unreliable", the file then takes the miss path (capture + AI
             offer + learned-layout consult).
        2. THE TEACH GRID CANNOT EXPRESS "THESE TWO CELLS ARE MONTH N". Probe
           probe_buhi1_teach.mjs (rules forced to miss by renaming "Month" in a COPY of
           the items): the pseudo-columns merge neighbouring cells on this dense print —
           M1=G+I M2=J+L M3=M+O M4=O+Q … M9=AC+AE M10=AE+AF: months 3/4, 6/7 and 9/10
           SHARE a letter, so the taught bands overlap and buildTaughtPdfMap refuses
           ("Month 4 starts left of where month 3 ends"). As a sanity check the CAPTURED
           map (label-start bands) reproduces today's shifted read exactly via mapUsed.
        3. EVEN IDEAL MONTH RANGES DO NOT READ THE TRUTH YET. probe_buhi1_ideal.mjs /
           probe_buhi1_contrib.mjs: bands cut halfway between the Field Office row's own
           24 cells (a range no human could draw more precisely) read
           1.2 / 3.81 / 5.54 / 8.94 / … / 26.59 (M7) / … / 100, contract 30,361,134.
           Against the file's OWN TOTAL row (paired cells, which DOES give 1.12 / 6.74 /
           10.59 exactly) the reader's month pesos are off by up to half a month:
             M2 -13,663 · M3 -498,155 · M4 -226,318 · M7 -351,820 · M9 -563,756 ·
             M10 -894,963 · M11 +362,473 · M12 +387,520
           e.g. "a. Footing" contributes to M2 and M4 but not M3. So cells on OTHER rows
           do not sit where the Field Office row's cells sit. CAUSE NOT YET KNOWN — right-
           aligned numbers of different widths, merged cells, or per-row x drift are the
           suspects. This must be understood before ANY layout shape is designed, because
           it decides what a learned layout has to store.
      WHAT THE FILE ITSELF OFFERS (the lever for the guard): the TOTAL row pairs reproduce
        the Excel truth to the cent (M1 364,603.91 -> 1.12%, M3 cumulative 2,195,216.46
        -> 6.74%, M4 3,452,126.91 -> 10.59%). A read whose month totals do not reconcile
        with the file's own TOTAL row (today: 242,317.46 outside every band, contract
        32,346,214 vs the printed 32,588,531.43) is detectably untrustworthy — a SELF-
        CHECK against the document, not a BUHI-shaped reader rule.
      REVISED ORDER (proposed to the user 2026-09-15, awaiting confirmation):
        (1) GUARD, both shapes: split-down (finding C) and "the read does not reconcile
            with the file's own TOTAL row" (finding D). Effect: the rules stand down, the
            file takes the miss path. Measure on all 10 PDFs; 16-read regression and
            block_snapshot 0 changed.
        (2) RESTUDY D AS A LEARNING PROBLEM, measurement only: why ideal bands still lose
            pesos (blocker 3); whether pseudo-column detection can separate the cells
            (blocker 2); the layout shape that reads the truth; whether AI-derive
            (PDF-3) can produce it.
        (3) DESCRIPTION-BAND TEACHING (findings A + B) — same teach-grid code as (2), so
            the two may merge after (2)'s measurements.
      NEW PROBES: probe_buhi1_teach.mjs, probe_buhi1_ideal.mjs, probe_buhi1_contrib.mjs.
      USER CONFIRMED the revised order (1) guard -> (2) D restudy -> (3) desc band, and
      ordered the guard's measurement pass.

  -----------------------------------------------------------------------------
  2026-09-15 — GUARD MEASUREMENT PASS ✅ DONE (read-only, no code). ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Code:        still NOTHING changed (last commit 7b3402e).
      Result:      two signals, each quiet on every correct read and firing only on the
                   specimen it exists for. Proposed spec below — awaiting build order.
      Probes:      C:\temp\opencode\guard_measure.mjs (round 1, per-page),
                   guard_measure2.mjs (round 2, merged + live DB maps),
                   dump_live_pdf_maps.php (read-only DB dump -> live_pdf_maps.json).
                   Both measure through guard_core_spy.mjs, a probe-only COPY of the core
                   with its internals re-exported (collectPdfPages) — the repo core is
                   untouched.

    WHAT WAS MEASURED — 11 PDFs (the 8 of the 16-read set + roof pdf2 + BUHI 12-page +
    BUHI 1-page), each read up to five ways: rules plain; rules with a taught month word
    ["SEGMENT 1"] (the 16-read pairing); through the LIVE DATABASE layouts (#124 SEGMENT
    auto-capture, #128 the user's hand-taught roof pdf2 — the only two live PDF rows);
    through the file's own capture map; and for both roofs through the AI-derived map.
    45 reads in total.

    ROUND 1 FOUND A TRAP, WORTH KEEPING: a per-page TOTAL-row check flagged the CORRECT
      two-page roof read (page 1 holds months 1-5 of the TOTAL row: 1,725,080.17 vs the
      printed 2,239,599.72). Months 6-7 are on page 2 on a label-less continuation line.
      A reconciliation must judge the MERGED table — continuation lines paired by their
      offset from each page's own header line (the P3 property), exactly as the reader
      merges. Round 1's "gap cells" signal (numbers between the left block and month 1)
      also fired on both roofs (the right-aligned Amount column drifts into the gap) —
      REJECTED.

    ROUND 2 RESULTS (every read; "—" = abstains)
      read                                      a&b      TOTAL reconciliation (merged)
      CONSCHEDSAVINGS ×2  plain/word/liveDB      no       — (weekly path, no TOTAL)
      CONSCHEDSAVINGS ×2  own capture map*       YES      —
      ROOF 2-page         plain/word/liveDB      — (NULL read)
      ROOF 2-page         AI map                 no       MATCH 2,239,599.73 vs .72 (pages 1+2)
      ROOF pdf2 1-page    liveDB (#128 taught)   no       MATCH 2,239,599.73 vs .72
      ROOF pdf2 1-page    capture / AI map       no       MATCH
      10-month            all four reads         no       — (TOTAL row has no month cells)
      6-stage             all four reads         no       — (same)
      SEGMENT             word / liveDB #124 / capture   no  — (same); plain = NULL
      scurvepdfsample2, test_page_6              — (NULL reads)
      BUHI 12-page        all four reads         YES      — (TOTAL is on p3, a page with
                                                            no month header)
      BUHI 1-page         all four reads         no       MISMATCH 32,346,214 vs printed
                                                          32,588,531.43 — and exactly
                                                          32,346,214 + 242,317.46 (the
                                                          left-out cell) = 32,588,531.43
      * not reachable in the app: CONSCHEDSAVINGS reads through the weekly path and never
        captures; forcing its capture map onto it yields 0/0/0/0/0/100/100/100, so "YES"
        there is a TRUE positive on a garbage read, not a false alarm.
      FALSE POSITIVES ON CORRECT READS: ZERO for both signals.

    PROPOSED GUARD SPEC (for the build order — nothing built)
      SIGNAL 1, split-down (finding C):  a page that carries >=3 distinct left-block labels
        on one line (ITEM / DESCRIPTION / AMOUNT / CONTRACT / % WEIGHT / NO. OF / DAYS /
        UNIT / QTY …) but NO month header, while the read found month pages elsewhere,
        AND every activity in the read is nameless ("Activity N"). Two independent facts
        are required on purpose: either alone is plausible on a legitimate print.
      SIGNAL 2, does-not-reconcile (finding D):  the read's TOTAL row — found on a month
        page by its label left of the first band, continued onto other month pages by
        header-relative height — has month cells, the row prints at least one figure left
        of the first band, and NO such figure equals the sum of the month cells (tolerance
        max(1, 0.1%)). Abstains when there is no TOTAL row, no month cells in it (10-month,
        6-stage, SEGMENT), or no printed figure. The "left-out cell" identity is recorded
        as the explanatory detail, not as the trigger (the general mismatch is the rule).
      APPLIES TO EVERY monthly read — rules AND learned layouts — so an approved layout that
        still does not reconcile is refused too. That is what makes the file's own TOTAL
        row the arbiter for item (2): a learned BUHI layout is only trusted once it
        reconciles. The weekly path is untouched (no bands, no TOTAL row measured).
      OUTCOME (proposed): the read is REFUSED, not merely warned — a baseline preview
        invites locking. Design points the build must settle, each with a test:
        - FLOW: a refused RULES read must fall through to the learned-layout pass (today
          maps run only when the rules found nothing — core :1587). Without this, item (2)
          can never work. Then the weekly fallback; confirm it returns null on both BUHI
          prints rather than a weekly misread.
        - REASON: parseSCurvePdf returns bare null today. The refusal needs a reason the
          UI can print ("this PDF's monthly figures do not add up to its own TOTAL row —
          import the original Excel file; AI assist may also be incomplete" / "this
          schedule is printed across pages that separate the names from the months").
          Shape to be decided (e.g. a refusal field the wrapper stashes), additive only.
        - OTHER CALLERS of parseSCurvePdf: crossCheckPdfAgainstFile (P5) and the PDF-3
          self-check replay derivePdfLayoutFromAi. A guard refusal must make both abstain
          (P5: the AI answer stays, marked unverified; PDF-3: nothing taught) — never
          throw, never swap in a refused reading.
        - CAPTURE: on the miss path the wrapper will capture BUHI-1page's label-start map.
          Approving it must still read nothing (the guard applies to map reads — measured:
          the capture-map read MISMATCHes too). Check the desk does not badge it as usable.
      REGRESSION GATES FOR THE BUILD: verify_p6.mjs 16 reads 0 changed; block_snapshot.mjs
        0 changed; guard_measure2.mjs re-run shows the two BUHI prints refused and every
        other read unchanged; JS tests falsified (each fails with its signal disabled);
        blinding stays ON (2 intended JS fails).
      KNOWN LIMITS, stated so nobody over-trusts it: signal 2 cannot see a BUHI-shaped PDF
        whose TOTAL row carries no month cells, or whose printed total is missing; signal 1
        cannot see a split-down print whose name pages lack the label line. Both are
        guards against these two measured failure shapes, not a general correctness proof.
      (Count correction: the pass was 43 reads, not 45 — the two-page roof's own capture is
      unnumbered, so it has no capture read.)

  -----------------------------------------------------------------------------
  2026-09-15 — READ GUARD ✅ BUILT (code, UNCOMMITTED). ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    STATE IN ONE GLANCE
      Ordered:     user "yes proceed with the guard".
      Status:      built, suite-green, falsified, regression-clean. NOT browser-proven.
                   Not committed (no commit order given).
      Files:       public/js/admin/projects/scurve-pdf-parser-core.js   (the guard)
                   public/js/admin/projects/scurve-pdf-parser.js        (reason stash)
                   public/js/admin/projects/scurve-import-ui.js         (message + AI note)
                   tests/js/scurve-pdf-parser.test.mjs                  (+6 tests)
                   this plan + AGENTS.md
      No PHP, Blade, route or migration touched; script tags are already $jsv-versioned.
      Next after the browser check: item (2), the measurement-only restudy of D.

    WHAT SHIPPED — core (pure, node-tested)
      - readRefusal(pageResults, labelOnlyPages, out) — the two measured signals, exactly
        as specified in the measurement-pass entry above:
          'split-down'     a page with >=3 distinct left-block labels on one line and NO
                           month header (recorded by collectPdfPages, which now returns
                           labelOnlyPages) AND every activity nameless;
          'total-mismatch' totalRowReconciliation(): the TOTAL row found on a month page
                           by its label left of the first band; its month cells summed
                           with the reader's own band rule; continued onto pages holding
                           OTHER months (disjoint month indices only — a page repeating
                           the same months is a row continuation) at the same header-
                           relative height (±2.5); refused when no figure the row prints
                           left of the months equals the sum (tolerance max(1, 0.1%));
                           abstains with no TOTAL row / no month cells / no printed figure.
        Each refusal is { code, message, detail }; the message is reviewer-facing and names
        the figures ("add up to 32,346,214.00, but its own TOTAL row prints 32,588,531.43").
      - collectPdfPages pageResults now also carry tokenLines + headerIndex (read only by the
        guard; mergePages and the month builder ignore them).
      - parseSCurvePdf body split into readPageResults(pass, mapId, options) → { out } |
        { refusal } | { fallback }. Flow:
          rules read -> out: return it (unchanged)
                     -> fallback (no rows / buildMonthsFromRows refused): weekly (unchanged)
                     -> REFUSED: remember the refusal and CONTINUE to the learned layouts
          maps (when liveMaps exist) -> out: return it (mapUsed note as before)
                                     -> refused: remember
          weekly reader -> a result wins; otherwise, if a refusal was recorded, call
                           opts.onRefusal(refusal) and return null.
        ⚠ BEHAVIOUR CHANGE, INTENDED: a refused rules read now falls through to learned
        layouts (before, maps ran only when the rules found no table). This is the change
        that makes item (2) possible at all. Maps still never override a rules read that
        is NOT refused — "rules win outright" test untouched and green.
      - opts.onRefusal is new and optional; omitted = no reporting. debug gains
        { t: 'refused', viaMap, code, detail }.

    WHAT SHIPPED — browser side
      scurve-pdf-parser.js: passes onRefusal, stashes window._scurvePdfRefusal (reset per
        parse, null whenever a reading comes back).
      scurve-import-ui.js: importPDFSCurve throws the refusal's message + "No baseline was
        produced from this PDF — please import the original Excel (.xlsx) S-curve file
        instead." (err.pdfRefusal set); new scurvePdfFailureNote(err) gives both catch
        branches (wizard + detail) the AI-offer wording: "The offline reading of this PDF was
        not reliable, so no baseline was produced. AI assist only sees 2 pages, so on a large
        table it may also be incomplete — the original Excel file is the dependable source."
        A plain miss keeps the old wording exactly. Capture still posts on a refusal (it is
        what item (2) will teach from).

    VERIFIED
      verify_guard.mjs (43 reads, committed core vs working tree):
        BUHI 12-page ×4 reads   wrong curve -> NULL, refusal 'split-down'
        BUHI 1-page  ×4 reads   wrong curve -> NULL, refusal 'total-mismatch' naming
                                32,346,214.00 vs 32,588,531.43
        every other read        IDENTICAL (curve, names, desc, contract, warnings,
                                curveSource, mapUsed) — incl. live DB #124 and #128
        ONE UNPREDICTED CHANGE, an improvement: CONSCHEDSAVINGS ×2 forced through its own
          capture map used to return 0/0/0/0/0/100/100/100 (map pass ran before the weekly
          reader); that map read is now refused, so the file falls through to the weekly
          reader and returns its true 36.7/68.85/91.16/100. Not reachable in the app (the
          weekly path reads it and it never captures) — recorded, not hidden.
        The weekly reader returns null on both BUHI prints (checked by the same run).
      verify_p6.mjs           16 reads, 0 changed; roof via AI map still 6.32 … 100, M7 = 3,
                              PDF-3 self-check PASSES months 1..7.
      block_snapshot.mjs      9 PDFs, 0 changed (capture/desk untouched, no re-capture wave).
      probe_guard_ai_callers  on both BUHI prints crossCheckPdfAgainstFile -> null and the
                              PDF-3 self-check -> null (abstain, no throw).
      probe_ui_refusal.cjs    the REAL scurve-import-ui.js in a node vm: refused -> reason
                              message + pdfRefusal + reliable-note; plain miss -> old message
                              and "The PDF table was not recognized."; a read -> returned.
      TESTS +6 (tests/js/scurve-pdf-parser.test.mjs, section "Read guard"):
        split-down refused with its reason · the conjunction (months page alone reads;
        label page + named table reads) · total-mismatch refused naming both figures ·
        a reconciling or uncomparable TOTAL row reads · column-split TOTAL reconciled
        across pages (sum 300 needs the page-2 cell) and refused when it does not add up ·
        a refused rules read falls through to a learned layout that adds up (mapUsed, id 6,
        31.58/68.42/100) while the capture that repeats the mistake is refused too.
      FALSIFIED (falsify_guard.mjs, mutates the real core in place, restores, verifies the
        sha256): split-down disabled / total-mismatch disabled / TOTAL judged page by page /
        no fall-through to maps — ALL FOUR CAUGHT by their named tests, core byte-identical
        after each restore.
      SUITES: npm run test:js 195 tests, 193 pass + the 2 intended blinding fails (F-B1,
        L3 hardcoded ABC). PHP not re-run — no PHP touched (last full run 1217 / same 5).
      CAUGHT WHILE WRITING THE TESTS (fixture, not code): a first two-page TOTAL fixture read
        NULL on the COMMITTED core as well — the reader takes the contract from page 1's
        TOTAL row alone (the roof's known 1,725,080.17 residual), so peso activities over
        four months overshot it and buildMonthsFromRows refused. Rebuilt on the suite's
        roof-shaped continuation fixture (percent cells + printed cumulative row), which is
        the shape the trap actually came from.

    BROWSER CHECK OWED (user)
      1. Import Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi-pdf-1page.pdf (detail Baseline tab or
         wizard): a red toast naming 32,346,214.00 vs 32,588,531.43 and the Excel advice; NO
         setup modal; the amber AI offer carries the "not reliable … 2 pages" note.
      2. Same with the 12-page BUHI print: the "separate pages" message.
      3. Roof pdf2 (live #128) and the 6-stage / 10-month PDFs still import exactly as before.
      4. Optional: the BUHI 1-page capture row appears in S-Curve Layouts; approving it must
         still refuse on re-import (same reason) — expected, it repeats the misread.
      Hard-refresh first (Ctrl+F5).

    PROBES (C:\temp\opencode, outside the repo — read-only)
      probe_buhi_pdf.mjs     pages + rules read + capture map + desk block
                             (writes buhi_items.json, buhi_capture_map.json)
      probe_buhi_pages.mjs   per-page label/header tokens with x,y + reader debug
                             (which pages got a header, the names it produced)
      probe_pdf_generic.mjs  "<sampledocs file name>" — pages, rules read, capture
      probe_buhi1_bands.mjs  month bands vs. the x of every cell on three lines
      probe_roof2_pages.mjs  one-page roof print: page count + text layer
      probe_buhi_pagesetup.php  the BUHI workbook's page setup (print area, titles)
      Run to a log file, then read the log (node --test / grep stall lesson).

  -----------------------------------------------------------------------------
  2026-09-15 — READ GUARD: BROWSER CHECK BY THE USER + A "ROOF STOPPED WORKING"
  REPORT, DIAGNOSED. No code changed. ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    BROWSER RESULTS (user, two runs; screenshots of the second)
      1. BUHI 1-page  ✅ red toast "add up to 32,346,214.00, but its own TOTAL row prints
                      32,588,531.43 …" + Excel advice; no setup modal; amber AI offer
                      with the "not reliable … 2 pages" note.
      2. BUHI 12-page ✅ "prints the activity names and the monthly columns on separate
                      pages …" toast + the same offer.
      3. 6-stage / 10-month / roof pdf2 ✅ confirmed unchanged.
      4. Approved BUHI 1-page capture ✅ still refused on re-import (first run).
      NEW REPORTS, each diagnosed below: (a) the two-page roof now refuses; (b) AI assist
      on BUHI 1-page fails "The PDF pages could not be rendered to images"; (c) the
      BUHI 1-page teach grid "has no cumulative row (R18)"; (d) teaching BUHI months
      needed three letters per month and the first save was refused.

    (a) TWO-PAGE ROOF REFUSED — THE GUARD IS RIGHT; THE MANUAL TEACH IS ONE COLUMN OFF.
      Live DB now (read-only dump): #134 live auto-capture (roof pdf2, 1 page, M1-7),
        #135 proposed auto-capture (two-page roof, UNNUMBERED, seen 5x — never promoted,
        by design :172-177), #136 live manual-teach (two-page roof). The layouts that
        read the two-page roof correctly before (#123 hand-taught, AI-derived) are no
        longer in the table; plain rules read the two-page roof as NULL, as always.
      #136 taught page 1 as M1..M5 = G..K and page 2 as M6 = L, M7 = M. In its own grid
        F is Month 1 (16%/16%/20%/6%/0%, TOTAL cell 141,466.26) and K is the page's
        empty sixth "30 days" column. So every page-1 month sits one column right.
      probe_roof2page_guard.mjs, two-page roof through #136:
        COMMITTED core (no guard)  24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 99.14 / 100,
                                   counts 4,4,4,3,0,4,3, contract 1,583,613.91 — a
                                   month-shifted WRONG baseline that would have imported.
        GUARD                      NULL, total-mismatch: months 2,098,133.47 vs printed
                                   2,239,599.72; 2,098,133.47 + 141,466.26 (the M1 cell
                                   left outside every band) = 2,239,599.73.
      probe_roof2page_fixteach.mjs, the same #136 with page 1 moved to F..J:
        committed AND guard read 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100,
        counts 4,4,4,4,3,4,3, no refusal. The guard passes a correct teach.
      ⇒ first live catch of the guard: it stopped a mis-taught layout from importing a
        wrong curve. But the MESSAGE misleads on this path: it tells the user to import the
        Excel when the real fix is to re-teach the layout. Proposed (not built): when the
        refusal came from a learned layout (debug viaMap), say so and point at S-Curve
        Layouts; and consider an "adds up" check on the teach modal (taught month cells of
        the TOTAL row vs its printed total) so the slip is caught at save, not at import.

    (b) AI ASSIST "COULD NOT BE RENDERED TO IMAGES" ON BUHI 1-PAGE — NOT THE GUARD.
      buildSCurvePdfSlices (scurve-pdf-parser.js :176-178) renders at scale 2.08 and
      SKIPS any page whose longer side exceeds 4000 px. BUHI 1-page is 2592x1728 pt ->
      5391 px, and it is the only page, so zero slices. Pre-existing; the guard only made
      the offer appear. Candidate fix (not built): scale DOWN to fit 4000 instead of
      skipping (~1.54 here). Whether a 39-column sheet stays legible at that size is
      unmeasured.

    (c) BUHI 1-PAGE GRID "NO CUMULATIVE ROW" — THE ROW IS THERE, BUT IT DOES NOT LOOK LIKE ONE.
      Capture #132 R18 = "CUMULATIVE % ACCOMPLISHED" with 0.07 / 0.20 / 0.48 / 0.97 at
      O / W / AB / AJ — fractions, quarterly, right of the visible screenshot. The
      CUMULATIVE tag is by shape (percent cells, ends >= 50), so fractions are tagged
      SUMMARY. Also: #132's block is 4006 chars — the 4000-char store limit cut R20
      mid-cell. That limit is the known open item (latent-defect batch note).

    (d) THREE LETTERS PER MONTH ON BUHI — blocker 2 of finding D, measured earlier; the
      pseudo-columns do not match the two printed cells per month. Multi-column months ARE
      accepted; the refusal is when neighbouring months share or overlap a letter. Even a
      saved BUHI teach stays refused by the guard until blocker 3 is understood — that is
      step (2), the restudy.

    PROBES: probe_roof2page_guard.mjs, probe_roof2page_fixteach.mjs, dump_rows_135_136.php,
      dump_row_132.php (C:\temp\opencode, read-only).

    USER FOLLOW-UP, same day: the roof slip is confirmed and fixed on their side — they had
      added a month column for the split duplicate and left out the real month 1. The
      re-taught layout reads again. No code needed.

  -----------------------------------------------------------------------------
  2026-09-15 — AI ASSIST SHRINK-TO-FIT ✅ BUILT (code, UNCOMMITTED, not browser-proven).
  ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    ORDERED: user, "lets go with the shrink to fit", plus a warning that the page was
      shrunk and accuracy may be low, pointing at the Excel file.

    WHY SHRINK, NOT A BIGGER CAP (researched before building)
      - Gemini 3 gives every image a FIXED token budget whatever its pixel size (media
        resolution low 280 / medium 560 / high 1120 / ultra_high 2240 tokens; Google:
        for PDFs "quality typically saturates at medium"). A 5391 px render reaches the
        model with about the same detail as a 4000 px one — raising the cap only makes
        the upload bigger. The parser sets no media_resolution (default).
      - The 4000 px ceiling came from normal-doc AI-3 as a browser-canvas guard, not a
        model limit. Size budgets are not the constraint: server 10 MB per image / 30 MB
        total, PHP post_max_size 40M, Gemini 20 MB inline per request.
      - So: shrink to fit, keep the guard. Expect weak AI answers on dense one-page
        sheets anyway (39 columns x ~90 rows into ~1120 tokens). Tiling one page into
        2-3 overlapping images (each gets its own budget) is the idea that could help;
        PARKED — it changes what the prompt sees (month header on the top tile only)
        and needs measuring. Also note: an AI answer on a guard-refused file is
        UNVERIFIED — the P5 cross-check abstains on refused files.

    WHAT SHIPPED
      core   pdfSliceRenderScale(width, height) -> { scale, shrunk, dpi } | null:
               min(2.08, 4000 / longer side). pdfSliceShrinkNote(shrunk) -> reviewer note
               naming the lowest DPI, or null. Both exported and on window.
      slicer buildSCurvePdfSlices renders at that scale instead of skipping; returns
               `shrunk: [{page, dpi}]` for pages that actually rode the request. The 4000
               px check stays as a backstop (e.g. core missing -> old behaviour).
      UI     _trySCurvePdfAiAssist: on an AI answer the note joins the import notes —
               EXCEPT when P5 swapped in the file's own months (they did not come from the
               image, so the note would be untrue). On an AI failure the note is appended
               to the error toast / in-modal result.
      WORDING DECISION: the note points at the Excel file only. The user suggested also
        "a better PDF in smaller size"; left out because it would not help — a smaller
        paper size of the same one-page table packs the same text into the same image
        budget (it only hides the note), and splitting it across pages top-to-bottom is
        exactly the shape the read guard refuses. Easy to add if the user still wants it.

    VERIFIED
      probe_slice_sizes.mjs   all 19 sample PDFs: ONLY BUHI 1-page changes (p0 2592x1728,
                              was skipped -> now 4000x2666 px at ~111 DPI). Every other
                              page renders exactly as before.
      tests +4                (scurve-pdf-parser.test.mjs, "shrink-to-fit"): ordinary page
                              keeps 150 DPI; BUHI size shrinks to <=4000 and >=3999 px,
                              111 DPI, portrait same, 1923/1924 pt boundary; unusable size
                              -> null; note null / one page / two pages naming lowest DPI.
      falsify_shrink.mjs      3 mutations (no shrink, shrunk never reported, highest DPI
                              named) — ALL CAUGHT, core byte-identical after each restore.
      probe_ui_shrink.cjs     REAL scurve-import-ui.js in a node vm: shrunk+answer -> note in
                              notes; shrunk+file overruled -> no note; shrunk+AI failed ->
                              "AI busy. <note>"; not shrunk -> no note either way.
      verify_p6.mjs           16 reads, 0 changed.   block_snapshot.mjs 9 PDFs, 0 changed.
      npm run test:js         199 tests, 197 pass + the 2 intended blinding fails.
      No PHP, Blade or route touched.

    BROWSER CHECK OWED (user; Ctrl+F5 first)
      1. Import the one-page BUHI PDF -> refused as before -> click Try AI assist. The
         "could not be rendered to images" error must be gone: the AI is actually called.
      2. If it answers: the setup modal's import notes carry "…shrunk to about 111 DPI (the
         usual is 150)… import the original Excel". Check the months against the Excel
         truth (M1 1.12 · M3 6.74 · M4 10.59 · M6 21.09 · M7 28.64 · M9 49.73 · M10 68 ·
         M12 100) — a poor answer is EXPECTED and is evidence for the tiling question, not
         a defect of this change. Do not lock it.
      3. If it fails (quota / cannot read): the red message ends with the same shrink note.
      4. Roof two-page AI assist (or cross-check link) still behaves as before, with NO
         shrink note.

    PROBES: probe_slice_sizes.mjs, falsify_shrink.mjs, probe_ui_shrink.cjs.

    FOLLOW-UP SAME DAY (user) — two wording changes built, one check measured.
      User's first browser look: AI assist on BUHI 1-page now CALLS the AI (the render
        error is gone) but the service answered 503 all day, so the answer path is still
        untested. Their toast showed the shrink note appended to "The AI service could not
        be reached" — reading "these months may be inaccurate" with no months present.
      1. SHRINK NOTE WORDING: pdfSliceShrinkNote(shrunk, { failed: true }) gives a failure
         variant ("…so even if a retry succeeds, small figures may be misread"). Both
         variants now add the user's second choice after the Excel: "a PDF split
         left-to-right onto two pages, with every row and the header rows repeated on
         each page". Left-to-right with every row on each page = the column-split shape
         the reader merges (roof, 6-stage, 10-month); a top-to-bottom split is what the
         guard refuses. That a two-page BUHI print reads better is REASONED (each AI image
         gets its own budget; the offline reader handles column splits), not measured on
         BUHI.
      2. LEARNED-LAYOUT REFUSAL WORDING (proposal 1 from the roof diagnosis): the core's
         refusal now carries viaMap (+ mapId). readPageResults stamps it; a map refusal
         already overrides the rules refusal in parseSCurvePdf, so the reported one is the
         layout's. importPDFSCurve words it: "This reading used a layout approved in
         S-Curve Layouts for this contractor, so that layout is the likely cause — open it
         there, check that every month sits on its own column (and that a month split
         across two pages is not counted twice), then import again. … the original Excel
         … is also a dependable alternative." Rules refusals keep the old wording. The
         desk shows no row ids, so the message names the desk, not "#136".
      VERIFIED: tests extended (rules refusal viaMap false / mapId undefined; layout
        refusal viaMap true / mapId 5; failure wording, Excel-before-two-pages order) —
        suite 199 tests, 197 pass + 2 intended blinding; PDF file 85/85. verify_p6 16/0,
        block_snapshot 9/0. probe_roof2page_guard.mjs on the real #136: debug refused
        viaMap true. probe_ui_viamap.cjs (real UI + real core helpers in a vm): both
        refusal wordings and both shrink-note wordings exactly as above.

      3. "ADDS UP" CHECK AT TEACH SAVE — MEASURED, NOT BUILT (needs the user's design call).
         Candidate: using only the stored block text the modal already has, find the TOTAL
         row, sum every number in the cells of the taught month letters, and require a
         figure printed LEFT of the first taught month to equal it (max(1, 0.1%)) — the
         same rule as the import guard, on the grid instead of the page.
         probe_teach_addsup.mjs on real stored blocks:
           #136 two-page roof as first taught (G..K, L, M)   DOES NOT ADD UP 2,593,306.47
                                                             vs 2,239,599.72
           #136 corrected (F..J, L, M)                       ADDS UP (gap 0.01)
           #136 split month counted twice (M6 = K+L)         DOES NOT ADD UP (+495,173.01)
           #136 month 7 forgotten                            DOES NOT ADD UP (19,345.72 short)
           #134 roof pdf2 one page (F..L)                    ADDS UP (gap 0.01)
           #132 BUHI 1-page, 3 letters/month from "Month 1"  DOES NOT ADD UP (242,317.43 short —
                                                             the H cell, same as the import guard)
           #132 BUHI 1-page, every TOTAL cell H..AM assigned ADDS UP (gap 0.03)
         Findings: zero wrong verdicts on 7 scenarios. Untaught numeric cells between months
           CANNOT be a trigger — the corrected roof legitimately leaves K ("495,173.", the
           clipped page-1 copy of month 6) untaught. NECESSARY, NOT SUFFICIENT: it catches a
           missing, extra or doubled column, not cells shuffled between months — BUHI
           "adds up" with every cell assigned while the import still loses pesos per month
           (finding D blocker 3). The import guard stays the arbiter.
         Open design questions for the user: warn with "save anyway" vs refuse; PDF only vs
           Excel too; where the TOTAL row is not in the block (4000-char truncation) the
           check must abstain.

    DECIDED (user, same day) — NEXT BUILD, NOT STARTED (user: "do not implement for now"):
      A. TEACH-SAVE "ADDS UP" CHECK
         - WARN with "Save anyway", never refuse. The import guard stays the hard stop.
         - PDF layouts ONLY (Excel reads contract column + TOTAL row itself).
         - Uses the TOTAL row = the MONEY row (per-month peso cells + the contract figure
           printed left of the months), not the cumulative % row: pesos are exact to the
           cent, a running % row ends at 100 whatever columns are taught, and BUHI prints
           its cumulative row only as quarterly fractions.
         - NO usable TOTAL row -> a visible "Not checked" line, NOT silence (silence would
           look like a pass). Two wordings: no TOTAL row with monthly amounts in this grid
           (10-month / 6-stage / SEGMENT shape) vs. the grid was cut before its TOTAL row
           (block at the 4000-char store limit).
         - Answered for the record: with no TOTAL row the import guard's total-mismatch
           signal abstains too (split-down does not need one). Refusing such files would
           block the correct 10-month / 6-stage / SEGMENT reads, so abstaining is right;
           those files rely on the reader's own refusals, the summary-row notes, the weak-
           read AI offer and the reviewer.
      B. PAGE-EDGE MARKING IN THE PDF TEACH GRID
         - Measured (probe_roof_clipped_cell.mjs): the two-page roof's page 1 is 792 pt wide
           and its TOTAL-row month-6 item is literally "495,173." at x 764.3 -> xEnd 793.9;
           "2,220,253." and "180" likewise run past the edge. The ".83" is NOT in page 1's
           text layer; page 2 prints the whole column (495,173.83). So it is in the FILE, not
           a reader defect, and PDF-only (an Excel grid is built from cell values).
         - Mark a cell whose token ends past the page's width (dimmed + "cut off at page
           edge — probably repeated on the next page") so a reviewer leaves column K untaught.
           Exact, not heuristic: the page width is known. Display only — reader output and
           the 16-read regression must not move; block_snapshot will change only if the mark
           is stored in the block (decide at build; prefer render-time).
      Build order when ordered: A then B, each falsified + probe-checked, one browser pass.
      COMMITTED BEFORE THE BUILD: dfb0974 (read guard + shrink-to-fit + this decision log).

  -----------------------------------------------------------------------------
  2026-09-15 — A (TEACH-SAVE ADDS-UP CHECK) + B (PAGE-EDGE MARKING) ✅ BUILT (code,
  UNCOMMITTED, not browser-proven). ⬅ HANDOFF POINT
  -----------------------------------------------------------------------------

    ORDERED: user, "start the implementation" (after dfb0974).

    A — WHAT SHIPPED
      scurve-teach-core.js (pure, node-tested):
        cellNumbers(cell)          every number in a grid cell ("₱ 495,173.83 ₱", "495,173.")
        taughtTotalCheck(t)        null (not PDF / <2 months) | adds-up | mismatch {sum,
                                   printed, gap, culprit} | unchecked {reason}. TOTAL row =
                                   non-HEADER/META row with a TOTAL label LEFT of the first
                                   taught month (the guard's rule); sum of every number under
                                   the taught letters vs a figure printed left of them,
                                   max(1, 0.1%). culprit = ONE column explaining the whole gap
                                   (untaught column holding the shortfall / taught column
                                   holding the excess), flagged edge when the grid marks it cut.
                                   Reasons: no-total | truncated (block >= 4000 chars) |
                                   no-month-amounts | no-printed-total.
        taughtTotalMessage(check)  the wording, tested with the numbers.
      _manage_scurve_headers_modal.blade.php:
        #teachTotalCheck under the readout. Live: green "Adds up…" or grey "Not checked…";
        a mismatch is NOT shown live (every half-taught state would be one). schSaveTeaching:
        first click on a mismatch shows the amber warning, relabels Save "Save anyway" and
        does not save; a second click with the SAME months saves; any month change withdraws
        the warning (saveWarnKey). PDF rows only; Excel untouched. Never blocks — the check
        is wrapped so an exception cannot stop a save.

    B — WHAT SHIPPED
      scurve-pdf-parser-core.js: derivePdfLayout(items, maxRows, { pageBoxes }) and
        derivePdfLayoutFromAi(…, maxRows, { pageBoxes }) — optional; each sheet carries its
        page's visible x-edges; a token whose text starts left of x0 or ends right of x1
        (± 0.5 pt) marks its cell. withEdgeLine() inserts "EDGE: R14:K,…" right under the
        legend (so the 4000-char cut cannot drop it; capped at 60 entries) — ONLY when a
        shown cell is cut, so every other block is byte-identical. The map never changes.
      scurve-pdf-parser.js: collects page.view per page (crop box, same user space as text
        items), passes it to the capture, stashes window._scurvePdfPageBoxes.
      scurve-import-ui.js: scurveHarvestPdfLayout passes the stash to the AI-derived capture.
      scurve-teach-core.js: parseHeaderBlock returns edgeCells ("14:K"); isEdgeCell().
      modal grid: a marked cell renders faded + italic + a "cut" badge with a tooltip; a note
        above the grid explains the mark once.
      Reader untouched: parseSCurvePdf never sees boxes.

    MEASURED BEFORE BUILDING B (probe_edge_tokens.mjs, all 19 sample PDFs): every text item
      past a page edge is genuinely cut text — roof two-page "495,173.", "2,220,253.", a cut
      title; BUHI 12-page "6,87", "4,67" and cut titles (both edges); CONSCHEDSAVINGS dates
      running off an A3 page. No item ends within 3 pt inside an edge anywhere.

    VERIFIED
      probe_edge_blocks.mjs (real PDFs through the real core, with vs without boxes):
        MARKED  roof two-page capture + AI-derived   "EDGE: R14:K"
        MARKED  BUHI 12-page capture                 "EDGE: R1:A,R2:L,R2:P" (cut titles)
        same    every other sample (incl. roof pdf2, BUHI 1-page, 10-month, 6-stage,
                SEGMENT, CONSCHEDSAVINGS) — and on the marked ones the REST of the block and
                the map are byte-identical.
        Adds-up on the roof's new grid: corrected F..J,L,M adds up (0.01); G..K,L,M
        353,706.75 too much; M6=K+L names "Column K (495,173.) … cut off at the page edge";
        month 7 forgotten names "Column M holds 19,345.73 … no month is on it".
      probe_teach_modal_addsup.mjs — the REAL modal script in a node vm with the real cores
        and the roof capture rebuilt with boxes: grid shows the note + 1 cut badge on
        495,173.; one-column-off -> 1st click 0 requests + "Save anyway", 2nd click saves;
        reopened + corrected months -> warning withdrawn, "Adds up", saves on 1st click;
        M6=K+L warning names the cut copy; Excel row -> no line, saves on 1st click.
      verify_teach_wiring.mjs  every schCore() call present (incl. isEdgeCell,
                               taughtTotalCheck, taughtTotalMessage); PHP parity 25/0.
      TESTS: JS +14 (teach-core 9: EDGE parsing, cellNumbers, adds-up, one-column-off,
        doubled month -> cut culprit, forgotten month -> untaught culprit, all four
        "Not checked" reasons, Excel/half-taught -> null, TOTAL label under a month is a
        value; PDF core 5: right edge, left edge, nothing cut / no boxes / flush = identical,
        two-page run-on letter, AI-derived). PHP +1 (ScurveHeaderProposalTest: a block with
        an EDGE line teaches to the IDENTICAL map) — that class 85/85.
      falsify_addsup_edge.mjs  8 mutations (4 per feature) — ALL CAUGHT, sources
                               byte-identical after each restore.
      npm run test:js          213 tests, 211 pass + the 2 intended blinding fails.
      verify_p6.mjs 16/0 · block_snapshot.mjs 9/0 (it builds without boxes, as designed).
      view:clear + view:cache OK.
      php artisan test (full)  1218 passed / the same 5 pre-existing failures BY NAME
                               (1 DashboardOverdueInspection, 2 EmailDraft, 2 default-baseline).

    EXPECTED ON FIRST USE (not a defect): the two files with cut text — the two-page roof
      and the BUHI 12-page — get a NEW block (the EDGE line changes header_hash), so their
      next failed import adds ONE new proposed capture row. Approve/teach from the new one
      (it shows the marks), reject the old. Live layouts (#136 etc.) keep reading — maps
      store positions, not blocks. Old rows never show marks.

    BROWSER CHECK OWED (user; Ctrl+F5)
      1. Import the two-page roof PDF (it refuses/misses as today) -> S-Curve Layouts: a new
         proposed auto-capture. Teach: the note above the grid; R14 column K shows
         "495,173." faded with a "cut" badge.
      2. Teach M1..M5 = G..K, M6 = L, M7 = M (the original slip) -> Save: amber warning
         "…2,593,306.47… 2,239,599.72 — 353,706.75 too much…", button "Save anyway",
         nothing saved. Change any month -> warning gone.
      3. Teach F..J, L, M -> green "Adds up…" -> Save saves on the first click.
      4. Optional: M6 = K+L -> the warning names column K as the cut copy.
      5. An Excel layout's Teach: no check line, Save as before.
    PROBES: probe_edge_tokens.mjs, probe_edge_blocks.mjs, probe_teach_modal_addsup.mjs,
      falsify_addsup_edge.mjs.

    BROWSER RESULTS (user, same day, new two-page roof capture)
      1 ✅ grid: R14 column K shows "495,173." faded with the "cut" badge (screenshot).
      3 ✅ taught Headers R3 · Description=A · Contract=E · M1-5=F..J · M6=L · M7=M ->
           "Adds up: the months you taught total 2,239,599.73 in the TOTAL row (R14),
           matching the 2,239,599.72 it prints."
      2 ≈  the warning reported was the M6=K+L one ("2,734,772.73 … 495,173.01 too much.
           Column K (495,173.) … cut off at the page edge …"), identical to step 4 — so the
           teaching used for step 2 double-counted month 6 rather than the G..K slip. The
           wording is exactly right for that teaching; the G..K text ("353,706.75 too much",
           no column named) is pinned by the JS test and the modal probe, not seen in the
           browser. "Save anyway" relabel / nothing saved / warning withdrawn on a month
           change: not reported explicitly.
      4 ✅ M6=K+L warning names column K as the cut copy.
      5 ✅ Excel layout teach unchanged.
      Note for item (3) of the queue: the reviewer still picks Description=A on this grid
        (finding A — the band is A..D and only its right edge is read), unchanged by this work.

 ===============================================================================
  12. CONVERSATION CONTINUATION GUIDE
===============================================================================

  1. Read §0 and §1 — the measurement in §1 is the whole justification; do not
     redesign the column rule without re-running probe_gridfidelity.mjs.
  2. Ask for an explicit order before building (Rule 10).
  3. Nothing here may change reader output: re-run the 16-read regression and compare.
  4. Log results here before committing; no merge to main and no push without
     explicit user words.

===============================================================================
  END OF PLAN
===============================================================================

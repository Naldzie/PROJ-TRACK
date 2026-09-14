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
#         🔄 THE SAME SWEEP ON THE EXCEL PATH IS STILL OPEN. Rounds five and six found
#         three more defects there, fixed in 293b302 and 3ce2f92. Round seven
#         (2026-09-14) is a RESTUDY ONLY, no code: the Excel path never emits
#         [CUMULATIVE], the auto-tagger is wrong on real files three different ways, and
#         what is worth teaching is "not an activity" + "the curve", never "this is a
#         data row". Plan and measurements: §13, the 2026-09-14 (seventh round) entry.
#         NOTHING FROM IT IS BUILT — the build is a separate order (Rule 10).
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

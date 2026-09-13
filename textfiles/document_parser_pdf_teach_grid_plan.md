# PDF TEACH GRID: MAKE THE RENDERED TABLE MATCH THE DOCUMENT (PDF-4 STAGE 2)
# Parent refs: textfiles/document_parser_pdf_layout_learning_plan.md (PDF-1..PDF-4, the
#              capture/consult/teach track this continues — read its §4 and the
#              2026-09-13 §9 entries first)
#              textfiles/document_parser_excel_learning_loop_plan.md (Phase-A — the teach
#              UI being reused)
# Date: September 13, 2026
# Status: ✅ BUILT 2026-09-13 (code, UNCOMMITTED per user) — §2 through §7 all done.
#         Reader output unchanged (16-read regression byte-identical). NOT browser-proven:
#         the acceptance run is §10, and expect the one-time re-capture wave of §8.
#         Build record + the three measured attempts at the column rule: §13.
# Branch: phase-5-parser-accuracy (Rule 12 — no merge to main without explicit confirmation)

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

    §10 HAS BEEN RUN (2026-09-14). The grid passed; THREE defects surfaced, one of
    them a regression §2 introduced. Read the 2026-09-14 entry in §13 before
    touching the teach path — it carries the measurements, not just the verdicts.

    The delete-confirmation change is NOT part of this plan — it is a separate
    blocking bug found while testing it (§13, round two) and it rides in the same
    uncommitted batch. Say so if the work is split into two commits.

  STATE OF PROOF
    Suites green: PHP 1185 / the same 7 pre-existing BY NAME; JS 157 tests, 155
    passed + the 2 intended blinding fails. Reader regression 16 reads, 0 changed.
    NOT BROWSER-PROVEN — the user will run the detailed test. The script is §10.

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
  REMAINING: the browser acceptance run in §10. Rule 10 applies before anything new.

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
    npm run test:js        -> 157 tests, 155 passed + 2 intended blinding fails
    php artisan test       -> 1185 passed / the same 7 pre-existing failures
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

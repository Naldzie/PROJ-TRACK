# PDF TEACH GRID: MAKE THE RENDERED TABLE MATCH THE DOCUMENT (PDF-4 STAGE 2)
# Parent refs: textfiles/document_parser_pdf_layout_learning_plan.md (PDF-1..PDF-4, the
#              capture/consult/teach track this continues — read its §4 and the
#              2026-09-13 §9 entries first)
#              textfiles/document_parser_excel_learning_loop_plan.md (Phase-A — the teach
#              UI being reused)
# Date: September 13, 2026
# Status: ⬜ PLANNED — approved by the user 2026-09-13, no code yet.
#         The PDF-4 teach-path BUG FIX is separate and already BUILT (see the parent's §9).
# Branch: phase-5-parser-accuracy (Rule 12 — no merge to main without explicit confirmation)

===============================================================================
  0. READ THIS FIRST (handoff in 60 seconds)
===============================================================================

  WHAT THIS IS
    The teach desk shows a reviewer a reconstructed table and asks them to point at
    its columns. That reconstruction is currently NOT an accurate picture of the
    document: whole columns are missing, one label is split across two rows, the
    summary block is cut off, only the first printed page is drawn, and the S-curve
    chart's own numbers appear as if they were cells.
    None of this affects an IMPORT — the reader parses the real file, and the roof
    imports correctly today. It affects the human who has to decide whether to trust
    a layout, and it is why the roof cannot be taught at all.

  WHY IT MATTERS NOW
    Only TWO real contractor files exist for testing (the roof and BUHI). The roof is
    the only multi-page PDF specimen, so making it teachable is also what makes the
    teach path testable end to end.

  ORDER OF WORK: §2 column detection → §3 page merge → §4 summary rows → §5 chart
  exclusion → §6 modal layout → §7 ambiguous geometry. Each provable on the roof.
  Rule 10 applies: inform the user at each step.

  HARD RULES
    - Blinding stays ON (L3_TEST_BLIND_ABC = true). JS showing 2 failures is green.
    - NOTHING here may change what the READER extracts. The 16-read sample regression
      (C:\temp\opencode\verify_p6.mjs) must stay byte-identical.
    - Sample files live in git-ignored sampledocs/ — reference by name, never commit.
    - Log behavioural changes here before committing (Rule 11).

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
  2. COLUMN DETECTION FROM THE WHOLE BLOCK  ⬜
===============================================================================

  Cluster token x-positions across EVERY row in the block, not just the header line.

  THE WRINKLE, and it is the part to get right: right-aligned numeric columns drift.
  The amount column above appears at 257.1 / 262.0 / 263.3 / 267.5 — the same logical
  column, four x values. Clustering on x alone over-splits it.

  RULE: cluster on x with a small tolerance, then MERGE adjacent clusters whenever
  merging puts no two tokens FROM THE SAME ROW into one cell. That is checkable
  against the data rather than tuned, and it correctly keeps the "₱" symbol as its
  own narrow column, because ₱ and its amount DO co-occur on the TOTAL row.

  pseudoCols keeps its {letter, x, xEnd} shape, so buildTaughtPdfMap (already built)
  needs no change. columnLetter() keeps naming them.

===============================================================================
  3. MERGE THE PAGES INTO ONE GRID  ⬜
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
  4. SUMMARY-AWARE ROW BUDGET  ⬜
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
  5. KEEP CHART FURNITURE OUT OF THE GRID  ⬜
===============================================================================

  Reuse P6's chartScaleTokens in buildPdfSliceBlock. Measured at ZERO false positives
  across all 8 sample PDFs (71 vertical numeric stacks examined, exactly one matched),
  and it is the same definition the reader already uses — one answer to "this is a
  picture, not a cell", rather than two that can drift.

===============================================================================
  6. TEACH MODAL LAYOUT  ⬜  (user-selected pain points, 2026-09-13)
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
  7. AMBIGUOUS CAPTURES KEEP THEIR GEOMETRY  ⬜
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

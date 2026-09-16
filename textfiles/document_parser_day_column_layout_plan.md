# DAY-COLUMN S-CURVE LAYOUTS (ONE COLUMN PER CALENDAR DAY)
# Parent refs: textfiles/document_parser_excel_learning_loop_plan.md (E1 — maps, teaching, learn-from-AI)
#              textfiles/document_parser_pdf_teach_grid_plan.md (the teach desk)
#              textfiles/local_llm_parser_plan.md (why the AI tier cannot carry this alone)
# Date: September 16, 2026
# Status: ✅ OPTION (A) DATE-ROW RULE BUILT on branch phase-21-scurve-date-row (UNCOMMITTED,
#         suite-green, falsified; browser import owed by the user). Both CONSCHED rebuilds
#         now read 18.24 / 58.46 / 91.16 / 100 on first upload; every other workbook in
#         sampledocs reads byte-identically. Options (B)-(E) not built. See §6.
# Branch: phase-21-scurve-date-row (off develop 5f5136b; Rule 12)

===============================================================================
  0. READ THIS FIRST
===============================================================================

  THE QUESTION (user, 2026-09-16): a contractor schedule like CONSCHEDSAVINGS.pdf has
  one column PER DAY under calendar-month labels, plus a second table that groups the
  same days into weeks and months. We only have its PDF. If the original Excel arrives
  one day, can the system read it — by rules, by teaching, or by the AI teaching it?

  THE ANSWER, MEASURED: NO, on all three paths, today. A faithful Excel rebuild of the
  file is refused by the rules, cannot be taught by hand, cannot be taught by the AI,
  and is too big for the AI to even see whole. Detail in §3.

  THE FIXTURES ARE EXACT, NOT GUESSED. Every activity's day span was read off the PDF
  and then CHECKED: with day cell = amount / span-days, the spans reproduce the
  author's 12 printed weekly totals and 4 monthly totals to the cent. The user
  confirmed the "##" cells are daily pesos; the measurement agrees (the rows printed
  as "0" also carry values — excluding them breaks every week).

===============================================================================
  1. THE FILE
===============================================================================

  CONSCHEDSAVINGS.pdf (2 pages; "CONSCHEDSAVINGS (1).pdf" is byte-identical)
    Construction of Four-Storey Academic Building (College of Health and Sciences)-SAVINGS
    20 activities, contract ₱6,495,049.29 (the amounts sum to .32 — the author's own
    rounding; the rebuild keeps both, as the file does).
    90 day columns, Dec 9 2024 .. Mar 8 2025, under "#1".."#90", merged month names
    (DECEMBER 23 days · JANUARY 31 · FEBRUARY 28 · MARCH 8), and a date per column.
    Header labels NO. / DESCRIPTION / UNIT / QUANTITY / UNIT PRICE / AMOUNT / %.
    Second table (no row labels): daily total, running total, then WEEK 1..12 blocks
    (%, cum %, pesos, cum pesos, days, cum days) and a MONTH block of the same shape.

  TRUTH (author's own monthly block, reproduced by the rebuild)
    cumulative %   18.24 / 58.46 / 91.16 / 100.00
    monthly pesos  1,184,934.17 / 2,612,388.35 / 2,123,845.81 / 573,880.96

  SPAN READING — one trap worth remembering: cells printed as a single "#" sit ~4 pt
  right of "##" cells, so reading their x as-is put Conduits and Grounding one day late
  (78 instead of 77). The weekly totals caught it (weeks 11/12 off by ±12,492.18);
  the single-change search found nothing until the offset was corrected.

===============================================================================
  2. FIXTURES (git-ignored sampledocs/, generator outside the repo)
===============================================================================

  sampledocs/SCurve-Test-CONSCHED-Daily-values.xlsx     every cell a stored number
  sampledocs/SCurve-Test-CONSCHED-Daily-formulas.xlsx   day cells, weights, totals and
                                                        both summary tables are formulas
  Layout: title rows 1-9; header rows 10 (#N) / 11 (merged month names) / 12 (dates,
  real Excel dates formatted "mmmm d, yyyy"); labels A..G merged down 10-12; activities
  rows 14-40 with PART/section rows between; CONTRACT AMOUNT row 41; second table from
  row 43; H..CS = days 1..90.
  Self-check (both variants): week cum% 3.33 / 9.63 / 18.24 / 26.86 …, month cum%
  18.24 / 58.46 / 91.16 / 100 — identical to the printed file.

  C:\temp\opencode (read-only probes / generator)
    probe_consched_text.mjs      pdf.js text dump -> consched_text.log
    probe_consched_spans.mjs     span check vs weekly totals -> consched_spans.json
    make_consched_fixture.php    builds both workbooks from consched_spans.json
    probe_consched_reader.php    runs the live reader -> consched_reader.log
    probe_consched_selfcheck.php fixture self-check

===============================================================================
  3. MEASUREMENT — WHAT TODAY'S CODE DOES (both variants behave the same)
===============================================================================

  RULES — REFUSED
    parse(): "No S-curve table found…"; deriveAutoColumnMap(): null.
    Why (from reading the code; not traced line by line):
      - The month names sit on row 11 in MERGED cells whose top-left cell is also under
        a "#N" cell in row 10, so the two-row header merge yields "#1 DECEMBER",
        "#24 JANUARY" — matchMonthLabel wants the bare word.
      - Even matched, matchMonthLabel maps names to CALENDAR numbers (DECEMBER=12,
        JANUARY=1). A project starting in December would be keyed 12, 1, 2, 3 — not
        project months 1..4 — and ksort would put January first.
      - The date row is plain numbers (45635…) to the reader; nothing groups columns by
        date.

  TEACHING BY HAND — CANNOT BE EXPRESSED
    teachColumns validates months.* max:2 letters — an Excel month is a LIST OF EXACT
    COLUMNS that are summed (buildMonths), not a range. December needs 23, January 31.
    buildTaughtMap also refuses any column index > 79 (CB); this file runs to CS (96).

  TEACHING BY THE AI (E1-B learn-from-AI) — CANNOT BE EXPRESSED EITHER
    The AI reply is sanitized to at most 4 letters per month, and the same
    buildTaughtMap gates apply. Even a perfect AI answer cannot become a layout.

  THE AI READING IT ONCE — BLIND TO PART OF THE FILE
    serializeForAi: 41 lines, 12,816 chars (values) / 13,000 (formulas); the prompt
    cuts the sheet at 12,000 chars, so the tail is lost. Each row is also cut at 80
    columns (A..CB), so days 74-90 — late February and ALL of March — are never shown.
    The 40-row budget ends at the daily-total row: the week and month tables are never
    shown either. No row is tagged HEADER (the rule detector found none), so the model
    gets 41 rows of [DATA]. It would be adding ~30 cells per activity per month from a
    sheet with the last 17 days missing.

===============================================================================
  4. OPTIONS (PROPOSED — needs an order)
===============================================================================

  (A) DATE-ROW RULE — recommended first.  ✅ BUILT 2026-09-16 (user: "lets go with your
      recommendation" — automatic, fallback-only, strict). Detail in §6.
      When a header block holds a row of real dates (or date-formatted numbers) over the
      value columns, group those columns by calendar month IN ORDER OF APPEARANCE and
      number them 1..N. Month names become a cross-check, not the key. No teaching, no
      AI: every day- or week-column contractor file reads on first upload.
      Must decide: what counts as a date row (≥ N consecutive ascending dates, one per
      column); partial first/last months (Dec 9-31, Mar 1-8) are just shorter groups;
      the second table's own date row must not win the header pick (it has no
      DESCRIPTION column — betterHeader should already prefer the first).
  (B) CALENDAR NAMES -> PROJECT ORDER.  ⬜
      Map month names by position, not by calendar number, whenever they are not
      already 1..N in order. Small, and useful without (A) for monthly layouts that
      start mid-year.
  (C) EXCEL MONTH AS A RANGE in maps (first..last column), as PDF already does. Lets a
      reviewer and the AI teach what (A) misses. Touches the map format (MAP_VERSION),
      teachColumns validation, the desk, and the AI sanitizer.
  (D) LIFT THE CAPS: the 80-column map/slice limit and the 12-month limit
      (buildTaughtMap, teachColumns months max:12). A 4-storey building can outrun 12
      months; a day-column file outruns 80 columns in under 3 months.
  (E) FOR THE AI SLICE: summarize day columns before sending (e.g. send per-month
      column sums once (A) knows the months) instead of raw cells. Only matters if the
      AI tier comes back (local_llm_parser_plan.md).

  WHAT MUST NOT CHANGE: every existing corpus read. Any build re-runs verify_p6 (16
  reads), block_snapshot (9 PDFs), the PHP S-curve tests and the Excel corpus probes.

===============================================================================
  5. OPEN QUESTIONS
===============================================================================

  - The PDF of this file is a separate problem: its day cells print as "##", so the PDF
    carries NO activity figures at all. Only the author's summary block is readable;
    summary adoption would be the only honest PDF read. Not measured yet.
  - Is the real workbook formula-driven (likely — the "0" cells suggest formulas with
    a display format) and are the day cells truly amount/span-days? The rebuild matches
    every printed total that way, which is strong but indirect evidence. The original
    Excel from the contractor would settle it.

===============================================================================
  6. SESSION LOG
===============================================================================

  2026-09-16 — measurement (this file). No app code. Fixtures built and self-checked;
  live reader refused both; teach and AI-teach paths shown unable to express the
  layout; AI slice shown truncated (12,000 chars, 80 columns, 40 rows).

  2026-09-16 — OPTION (A) DATE-ROW RULE ✅ BUILT (UNCOMMITTED; branch
  phase-21-scurve-date-row). Rules vs teaching, as settled with the user: a rule is
  developer code for a pattern MANY contractors share, proven against the corpus;
  teaching is the everyone-channel for one contractor's layout. A date row is the
  former.

    WHAT CHANGED — app/Services/SCurveImportService.php only
      findHeader            passes a label detector to findHeaderWith, so the date pass
                            exists on the MAIN READ PATH ONLY. labelSliceRows and
                            deriveAutoColumnMap are untouched, so AI slices and capture
                            hashes are unchanged (the snapshot's sliceHash proves it).
      findHeaderWith        new optional $detectLabels; the date pass runs ONLY when both
                            label passes found nothing. Every file that read before reads
                            the same header.
      detectHeaderLabels    the label half of detectHeaderRow, split out unchanged (the
                            label checks never depended on the month checks).
      findDateRowHeader     a row with a clear date run + a DESCRIPTION label on that row
                            or up to 3 rows above, left of the first date column. Months =
                            calendar months in order of appearance, numbered 1..N; each
                            spans every column dated inside it. "NO." in the first column
                            puts the one-column-right code guess on the description, so
                            the code is read under the label itself (date path only).
      dateColumnGroups      strict: ≥3 consecutive date cells, strictly ascending, every
                            step 1..31 days, ≥2 calendar months; longest run wins.
      cellDate              whole Excel serials in 2000..2099, or text "December 9, 2024"
                            / "Dec 9, 2024" / "2024-12-09".
      finalizeHeader        uses explicit spans when the detection carries them.
      buildMonths           a description-less row whose text says "CONTRACT AMOUNT"
                            beside a contract figure is the TOTAL row. Without this the
                            CONSCHED total row follows an activity, so the R2a orphan-
                            subtotal branch added the contract to itself and HALVED the
                            curve (found by reading the code before the first run).
    No limit was needed for columns or months: the read path never had the 80-column or
    12-month caps (those live in the map builder, the AI slice and the teach endpoint).

    RESULT
      CONSCHED values + formulas   18.24 / 58.46 / 91.16 / 100, counts 11,9,16,9 (= the
                                   spans), 20 activities, item codes correct, no warnings,
                                   curveSource 'activities'. contractAmount 6,495,049.32 —
                                   the sum of the amounts; the file prints .29 (§1).
      every other sampledocs xlsx  BYTE-IDENTICAL result, auto-map and slice hash
                                   (snapshot_excel_reads.php before/after, 8 files incl.
                                   the 4 that only read through learned layouts).

    VERIFICATION
      php artisan test --filter=SCurveImportTest   43 passed (+3 new tests)
      falsified by mutation (mutate_daterow.mjs), EIGHT mutations, each red:
        date pass disabled · date pass always wins · contract-amount total label removed ·
        code column not moved back · step cap 31 -> 400 · one month accepted ·
        two-cell run accepted · fractional serials accepted. File restored byte-exact.
      php artisan test       1222 passed / the same 5 pre-existing failures BY NAME.
      No JS, Blade, route or PDF code touched (the PDF regression gates are not at risk).

    BROWSER CHECK OWED (user)
      Import sampledocs/SCurve-Test-CONSCHED-Daily-values.xlsx as a baseline S-curve:
      4 months 18.24 / 58.46 / 91.16 / 100, activity lists per month, no notes.
      Then the formulas variant: identical. Then one existing Excel (BUHI or roof): as
      before.

    BROWSER RESULT (user, same day) ✅ PASSED — both rebuilds imported live.
      formulas  18.24 / 58.46 / 91.16 / 100.00, 11 / 9 / 16 / 9 activities
      values    identical
      Month names on screen read July … October 2025: the baseline labels months from
      the PROJECT's start date, not from the file's own dates (Dec 2024 … Mar 2025).
      Expected for a test project; worth knowing that nothing compares the file's
      dates with the project start (a possible future heads-up, not built).
      Step 3 (an existing Excel still reading as before) — not reported yet; the corpus
      snapshot already shows it byte-identical.

    NOT DONE
      (B) calendar names in project order for LABEL headers (the date path already
      numbers by appearance); (C) month-as-range teaching; (D) cap lifts for maps/teach/
      slice; (E) AI slice summarising. The PDF of CONSCHED still has no readable activity
      figures (§5).

    PROBES (C:\temp\opencode): snapshot_excel_reads.php -> excel_reads_before/after.json,
    mutate_daterow.mjs, SCurveImportService_daterow_real.php (pre-mutation copy),
    php_daterow.log.
